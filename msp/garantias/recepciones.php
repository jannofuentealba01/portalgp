<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
msp2RequireAccess();

$flash = msp2PullFlash();
$error = null;
$garantias = [];
$cuentasBanco = [];
$recepciones = [];
$archivosByRecepcion = [];
$totales = ['pactado'=>0.0,'recibido'=>0.0,'pendiente'=>0.0];
$idContratoPreseleccionado = filter_input(INPUT_GET, 'id_contrato_arriendo', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;

function msp2GarFmtMonto(mixed $value): string { return '$ ' . number_format((float) $value, 0, ',', '.'); }
function msp2GarFmtFecha(mixed $value): string {
    $raw = trim((string) $value); if ($raw === '') return '-';
    $date = DateTimeImmutable::createFromFormat('Y-m-d', substr($raw, 0, 10));
    return $date ? $date->format('d-m-Y') : $raw;
}

try {
    foreach (['msp_garantia_recepciones','msp_tesoreria_cuentas','msp_tesoreria_movimientos','msp_vw_garantias_control_recepcion'] as $required) {
        if (!msp2TableExists($conn, $required)) {
            throw new RuntimeException('Falta el prerrequisito de garantías/tesorería. Ejecuta msp/db/patch_garantias_tesoreria_base.sql.');
        }
    }

    // La garantía se pacta por contrato, aunque el legado conserve una fila por local.
    // Agrupamos aquí para que la recepción se registre una sola vez por tienda/contrato.
    $garantias = $conn->query(
        'SELECT MIN(g.id_garantia) AS id_garantia, g.id_contrato_arriendo,
                SUM(g.monto_inicial) AS monto_pactado,
                SUM(ISNULL(recibido.monto_recibido,0)) AS monto_recibido,
                SUM(CASE WHEN g.monto_inicial-ISNULL(recibido.monto_recibido,0)>0
                         THEN g.monto_inicial-ISNULL(recibido.monto_recibido,0) ELSE 0 END) AS monto_por_recibir,
                a.nombre_locatario,a.rut,t.nombre_comercial,
                COUNT(*) AS locales_garantia,
                STRING_AGG(l.cdo_local, N\' / \') WITHIN GROUP (ORDER BY l.cdo_local) AS locales
         FROM dbo.msp_garantias g
         INNER JOIN dbo.msp_contratos_arriendo c ON c.id_contrato_arriendo=g.id_contrato_arriendo
         INNER JOIN dbo.msp_arrendatarios a ON a.id_arrendatario=c.id_arrendatario
         INNER JOIN dbo.msp_tiendas t ON t.id_tienda=c.id_tienda
         INNER JOIN dbo.msp_locales l ON l.id_local=g.id_local
         OUTER APPLY (SELECT SUM(r.monto_recibido) AS monto_recibido
                      FROM dbo.msp_garantia_recepciones r
                      WHERE r.id_garantia=g.id_garantia AND r.estado_recepcion=N\'CONFIRMADA\') recibido
         WHERE g.estado_garantia<>6 AND c.estado_contrato<>5
         GROUP BY g.id_contrato_arriendo,a.nombre_locatario,a.rut,t.nombre_comercial
         ORDER BY a.nombre_locatario,t.nombre_comercial'
    )->fetchAll() ?: [];

    $cuentasBanco = $conn->query(
        'SELECT id_cuenta_tesoreria,nombre_cuenta,banco,numero_cuenta FROM dbo.msp_tesoreria_cuentas WHERE tipo_cuenta=N\'BANCO\' AND activo=1 ORDER BY banco,nombre_cuenta'
    )->fetchAll() ?: [];

    $recepciones = $conn->query(
        'SELECT TOP (100) r.id_recepcion_garantia,r.fecha_recepcion,r.monto_recibido,r.medio_recepcion,r.referencia,r.banco_emisor,r.numero_cheque,r.estado_recepcion,
                g.id_garantia,c.id_contrato_arriendo,a.nombre_locatario,t.nombre_comercial,tc.nombre_cuenta
         FROM dbo.msp_garantia_recepciones r
         INNER JOIN dbo.msp_garantias g ON g.id_garantia=r.id_garantia
         INNER JOIN dbo.msp_contratos_arriendo c ON c.id_contrato_arriendo=g.id_contrato_arriendo
         INNER JOIN dbo.msp_arrendatarios a ON a.id_arrendatario=c.id_arrendatario
         INNER JOIN dbo.msp_tiendas t ON t.id_tienda=c.id_tienda
         LEFT JOIN dbo.msp_tesoreria_movimientos tm ON tm.id_recepcion_garantia=r.id_recepcion_garantia AND tm.estado_movimiento=N\'VIGENTE\'
         LEFT JOIN dbo.msp_tesoreria_cuentas tc ON tc.id_cuenta_tesoreria=tm.id_cuenta_tesoreria
         ORDER BY r.fecha_recepcion DESC,r.id_recepcion_garantia DESC'
    )->fetchAll() ?: [];
    $stmtArchivos = $conn->query("SELECT id_garantia_archivo,id_recepcion_garantia,nombre_archivo FROM dbo.msp_garantia_archivos WHERE id_recepcion_garantia IS NOT NULL AND estado_archivo=N'ACTIVO' ORDER BY id_garantia_archivo DESC");
    foreach (($stmtArchivos ? $stmtArchivos->fetchAll() : []) as $archivo) {
        $archivosByRecepcion[(int)$archivo['id_recepcion_garantia']][]=$archivo;
    }

    $rowTotales = $conn->query('SELECT SUM(monto_pactado) pactado,SUM(monto_recibido) recibido,SUM(CASE WHEN monto_por_recibir>0 THEN monto_por_recibir ELSE 0 END) pendiente FROM dbo.msp_vw_garantias_control_recepcion')->fetch() ?: [];
    $totales = ['pactado'=>(float)($rowTotales['pactado']??0),'recibido'=>(float)($rowTotales['recibido']??0),'pendiente'=>(float)($rowTotales['pendiente']??0)];
} catch (Throwable $exception) {
    $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'No fue posible cargar el módulo de recepción de garantías.';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Recepción de garantías | MSP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .recepcion-card .card-header {
            padding: .55rem .8rem;
        }
        .recepcion-card .card-body {
            padding: .7rem .8rem .75rem;
        }
        #formRecepcion {
            --bs-gutter-y: .55rem;
            --bs-gutter-x: .75rem;
        }
        #formRecepcion .form-label {
            margin-bottom: .2rem;
            font-size: .88rem;
            font-weight: 600;
        }
        #formRecepcion .form-control,
        #formRecepcion .form-select,
        #formRecepcion .input-group-text {
            min-height: 36px;
            padding-top: .34rem;
            padding-bottom: .34rem;
            font-size: .88rem;
        }
        #formRecepcion .form-text {
            margin-top: .15rem;
            font-size: .75rem;
            line-height: 1.25;
        }
        #formRecepcion textarea.form-control {
            min-height: 38px;
            resize: vertical;
        }
    </style>
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark"><div class="container-fluid"><a class="navbar-brand" href="<?php echo msp2Escape(msp2Url('garantias/index.php')); ?>">MSP / Garantías</a><div class="d-flex gap-2"><a class="btn btn-outline-light btn-sm" href="<?php echo msp2Escape(msp2Url('garantias/index.php')); ?>"><i class="bi bi-arrow-left me-1"></i>Volver a Garantías</a><a class="btn btn-outline-light btn-sm" href="<?php echo msp2Escape(msp2Url('contratos/index.php')); ?>">Contratos</a></div></div></nav>
<main class="container-fluid py-4 px-lg-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h1 class="h3 mb-1">Recepción de garantías</h1></div></div>
    <?php if (is_array($flash)): ?><div class="alert alert-<?php echo msp2Escape((string)($flash['type']??'info')); ?> alert-dismissible fade show"><?php echo msp2Escape((string)($flash['message']??'')); ?><button class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php if ($error !== null): ?><div class="alert alert-danger"><?php echo msp2Escape($error); ?></div><?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="card h-100"><div class="card-body"><div class="text-muted small">Garantía pactada</div><div class="h4 mb-0"><?php echo msp2Escape(msp2GarFmtMonto($totales['pactado'])); ?></div></div></div></div>
        <div class="col-md-4"><div class="card h-100 border-success"><div class="card-body"><div class="text-muted small">Recibido confirmado</div><div class="h4 text-success mb-0"><?php echo msp2Escape(msp2GarFmtMonto($totales['recibido'])); ?></div></div></div></div>
        <div class="col-md-4"><div class="card h-100 border-warning"><div class="card-body"><div class="text-muted small">Pendiente de recepción</div><div class="h4 text-warning-emphasis mb-0"><?php echo msp2Escape(msp2GarFmtMonto($totales['pendiente'])); ?></div></div></div></div>
    </div>

    <div class="row g-4">
        <div class="col-12">
            <div class="card shadow-sm recepcion-card"><div class="card-header fw-semibold">Registrar recepción</div><div class="card-body">
                <form method="post" action="<?php echo msp2Escape(msp2Url('garantias/registrar_recepcion.php')); ?>" class="row g-3" id="formRecepcion">
                    <?php msp2CsrfField(); ?>
                    <div class="col-12 col-lg-6"><label class="form-label" for="buscar_garantia">Buscar tienda, arrendatario, RUT o contrato</label><div class="input-group"><span class="input-group-text"><i class="bi bi-search"></i></span><input type="search" id="buscar_garantia" class="form-control" placeholder="Ejemplo: ivo" autocomplete="off"></div><div id="resultadosBusquedaGarantia" class="list-group mt-2 d-none"></div></div>
                    <div class="col-12 col-lg-6"><label class="form-label">Tienda / contrato</label><select name="id_contrato_arriendo" id="id_contrato_arriendo" class="form-select" required><option value=""></option><?php foreach ($garantias as $g): $completa=(float)$g['monto_pactado']>0 && (float)$g['monto_por_recibir']<=0; ?><option value="<?php echo (int)$g['id_contrato_arriendo']; ?>" data-id-garantia="<?php echo (int)$g['id_garantia']; ?>" data-pactado="<?php echo msp2Escape((string)$g['monto_pactado']); ?>" data-recibido="<?php echo msp2Escape((string)$g['monto_recibido']); ?>" data-pendiente="<?php echo msp2Escape((string)$g['monto_por_recibir']); ?>" data-search="<?php echo msp2Escape(($g['nombre_comercial']??'').' '.($g['nombre_locatario']??'').' '.($g['rut']??'').' contrato '.$g['id_contrato_arriendo']); ?>" <?php echo $completa?'disabled':''; ?> <?php echo !$completa && (int)$g['id_contrato_arriendo']===$idContratoPreseleccionado?'selected':''; ?>><?php echo msp2Escape(($g['nombre_comercial']??$g['nombre_locatario']??'Tienda').' · Contrato #'.$g['id_contrato_arriendo'].' · '.($completa?'Garantía completa':((float)$g['monto_pactado']>0?'Pendiente '.msp2GarFmtMonto($g['monto_por_recibir']):'Monto pactado por definir'))); ?></option><?php endforeach; ?></select><div id="ayudaPendiente" class="form-text"><?php echo $garantias===[]?'No existen garantías registradas.':''; ?></div><div id="sinCoincidencias" class="alert alert-warning py-2 mt-2 d-none mb-0">No se encontraron tiendas que coincidan con la búsqueda.</div></div>
                    <div class="col-12 d-none" id="resumenGarantiaSeleccionada"><div class="alert alert-info mb-0"><div class="row g-2 text-center"><div class="col-md-4"><div class="small text-muted">Garantía pactada</div><div class="h5 mb-0" id="resumenPactado">$ 0</div></div><div class="col-md-4"><div class="small text-muted">Recibido anteriormente</div><div class="h5 mb-0" id="resumenRecibido">$ 0</div></div><div class="col-md-4"><div class="small text-muted">Máximo pendiente por recibir</div><div class="h5 mb-0 text-primary" id="resumenPendiente">$ 0</div></div></div></div></div>
                    <div class="col-md-4 d-none" id="campoMontoPactado"><label class="form-label">Monto pactado de la garantía</label><input type="number" name="monto_pactado" id="monto_pactado" min="0.01" step="0.01" class="form-control"><div class="form-text">Este contrato tiene la garantía creada en $0. Define aquí el total acordado.</div></div>
                    <div class="col-md-3"><label class="form-label">Forma de recepción</label><select name="modalidad_recepcion" id="modalidad_recepcion" class="form-select" required><option value="ABONO">Abono parcial</option><option value="TOTAL">Pagar total pendiente</option></select></div>
                    <div class="col-md-3"><label class="form-label">Fecha recepción</label><input type="date" name="fecha_recepcion" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
                    <div class="col-md-3"><label class="form-label">Monto recibido</label><input type="number" name="monto_recibido" id="monto_recibido" min="0.01" step="0.01" class="form-control" required><div id="ayudaMonto" class="form-text"></div></div>
                    <div class="col-md-3"><label class="form-label">Medio</label><select name="medio_recepcion" id="medio_recepcion" class="form-select" required><option value="EFECTIVO">Efectivo</option><option value="TRANSFERENCIA">Transferencia</option><option value="CHEQUE">Cheque</option></select></div>
                    <div class="col-md-6 campo-transferencia d-none"><label class="form-label">Cuenta bancaria destino</label><select name="id_cuenta_banco" class="form-select"><option value="">Seleccionar cuenta</option><?php foreach($cuentasBanco as $c): ?><option value="<?php echo (int)$c['id_cuenta_tesoreria']; ?>"><?php echo msp2Escape(($c['banco']??'').' · '.($c['nombre_cuenta']??'').' · '.($c['numero_cuenta']??'')); ?></option><?php endforeach; ?></select><?php if($cuentasBanco===[]): ?><div class="form-text text-danger">Agrega primero una cuenta bancaria.</div><?php endif; ?></div>
                    <div class="col-md-6 campo-referencia d-none"><label class="form-label">Referencia transferencia</label><input name="referencia" maxlength="200" class="form-control"></div>
                    <div class="col-md-4 campo-cheque d-none"><label class="form-label">Banco emisor</label><input name="banco_emisor" maxlength="120" class="form-control"></div>
                    <div class="col-md-4 campo-cheque d-none"><label class="form-label">Número cheque</label><input name="numero_cheque" maxlength="80" class="form-control"></div>
                    <div class="col-md-4 campo-cheque d-none"><label class="form-label">Fecha cheque</label><input type="date" name="fecha_cheque" class="form-control"></div>
                    <div class="col-12"><label class="form-label">Observaciones</label><textarea name="observaciones" maxlength="500" rows="1" class="form-control"></textarea></div>
                    <div class="col-12 text-end"><button class="btn btn-success" <?php echo $error!==null?'disabled':''; ?>><i class="bi bi-check-circle me-1"></i>Confirmar recepción</button></div>
                </form>
            </div></div>
        </div>
    </div>

    <div class="card shadow-sm mt-4"><div class="card-header fw-semibold">Últimas recepciones</div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Fecha</th><th>Arrendatario / tienda</th><th>Medio</th><th>Destino</th><th>Referencia</th><th class="text-end">Monto</th><th>Documentos</th><th>Estado</th></tr></thead><tbody><?php if($recepciones===[]): ?><tr><td colspan="8" class="text-center text-muted py-4">No existen recepciones registradas.</td></tr><?php endif; ?><?php foreach($recepciones as $r): $archivosRec=$archivosByRecepcion[(int)$r['id_recepcion_garantia']]??[]; ?><tr><td><?php echo msp2Escape(msp2GarFmtFecha($r['fecha_recepcion'])); ?></td><td><div class="fw-semibold"><?php echo msp2Escape((string)$r['nombre_locatario']); ?></div><div class="small text-muted">Contrato #<?php echo (int)$r['id_contrato_arriendo']; ?> · <?php echo msp2Escape((string)$r['nombre_comercial']); ?></div></td><td><?php echo msp2Escape((string)$r['medio_recepcion']); ?></td><td><?php echo msp2Escape((string)($r['nombre_cuenta']??'-')); ?></td><td><?php echo msp2Escape((string)($r['referencia']??$r['numero_cheque']??'-')); ?></td><td class="text-end fw-semibold"><?php echo msp2Escape(msp2GarFmtMonto($r['monto_recibido'])); ?></td><td style="min-width:260px"><div class="d-flex flex-wrap gap-1 mb-1"><a class="btn btn-outline-primary btn-sm" target="_blank" href="<?php echo msp2Escape(msp2Url('garantias/comprobante.php?tipo=RECEPCION&id='.(int)$r['id_recepcion_garantia']));?>">Comprobante</a><?php foreach($archivosRec as $a):?><a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url('garantias/descargar_archivo.php?id='.(int)$a['id_garantia_archivo']));?>" title="<?php echo msp2Escape((string)$a['nombre_archivo']);?>">Respaldo #<?php echo (int)$a['id_garantia_archivo'];?></a><?php endforeach;?></div><form method="post" enctype="multipart/form-data" action="<?php echo msp2Escape(msp2Url('garantias/subir_archivo.php'));?>" class="d-flex gap-1"><?php msp2CsrfField();?><input type="hidden" name="origen" value="RECEPCION"><input type="hidden" name="id_recepcion_garantia" value="<?php echo (int)$r['id_recepcion_garantia'];?>"><input type="file" name="archivo" accept=".pdf,.jpg,.jpeg,.png" class="form-control form-control-sm" required><button class="btn btn-success btn-sm">Subir</button></form></td><td><span class="badge text-bg-<?php echo ($r['estado_recepcion']??'')==='CONFIRMADA'?'success':'secondary'; ?>"><?php echo msp2Escape((string)$r['estado_recepcion']); ?></span></td></tr><?php endforeach; ?></tbody></table></div></div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(()=>{
    const medio=document.getElementById('medio_recepcion');
    const garantia=document.getElementById('id_contrato_arriendo');
    const modalidad=document.getElementById('modalidad_recepcion');
    const monto=document.getElementById('monto_recibido');
    const pactado=document.getElementById('monto_pactado');
    const campoPactado=document.getElementById('campoMontoPactado');
    const resumen=document.getElementById('resumenGarantiaSeleccionada');
    const rPactado=document.getElementById('resumenPactado');
    const rRecibido=document.getElementById('resumenRecibido');
    const rPendiente=document.getElementById('resumenPendiente');
    const ayudaPendiente=document.getElementById('ayudaPendiente');
    const ayudaMonto=document.getElementById('ayudaMonto');
    const buscador=document.getElementById('buscar_garantia');
    const resultadosBusqueda=document.getElementById('resultadosBusquedaGarantia');
    const sinCoincidencias=document.getElementById('sinCoincidencias');
    const opciones=[...garantia.querySelectorAll('option[value]')];
    const money=value=>'$ '+Number(value||0).toLocaleString('es-CL',{minimumFractionDigits:0,maximumFractionDigits:2});
    const normalizar=value=>String(value||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim();
    function buscarGarantias(){
        const consulta=normalizar(buscador.value);
        resultadosBusqueda.replaceChildren();
        resultadosBusqueda.classList.toggle('d-none',consulta.length<2);
        sinCoincidencias.classList.add('d-none');
        if(consulta.length<2) return;
        const coincidencias=opciones.map(option=>{
            const texto=normalizar(option.dataset.search||option.textContent);
            const palabras=texto.split(/\s+/);
            let relevancia=99;
            if(texto===consulta) relevancia=0;
            else if(texto.startsWith(consulta)) relevancia=1;
            else if(palabras.some(palabra=>palabra.startsWith(consulta))) relevancia=2;
            else if(texto.includes(consulta)) relevancia=3;
            return {option,relevancia};
        }).filter(item=>item.relevancia<99).sort((a,b)=>a.relevancia-b.relevancia||a.option.text.localeCompare(b.option.text,'es')).slice(0,12);
        sinCoincidencias.classList.toggle('d-none',coincidencias.length>0);
        coincidencias.forEach(({option})=>{
            const button=document.createElement('button');
            button.type='button';
            button.className='list-group-item list-group-item-action d-flex justify-content-between align-items-center';
            button.disabled=option.disabled;
            const nombre=document.createElement('span');
            nombre.textContent=option.textContent;
            button.append(nombre);
            if(option.disabled){const estado=document.createElement('span');estado.className='badge text-bg-success ms-2';estado.textContent='Completa';button.append(estado);}
            button.addEventListener('click',()=>{garantia.value=option.value;buscador.value=option.textContent.split(' · Contrato')[0];resultadosBusqueda.classList.add('d-none');actualizarGarantia();});
            resultadosBusqueda.append(button);
        });
    }
    function actualizarMedio(){
        const value=medio.value;
        document.querySelectorAll('.campo-transferencia,.campo-referencia').forEach(e=>e.classList.toggle('d-none',value!=='TRANSFERENCIA'));
        document.querySelectorAll('.campo-cheque').forEach(e=>e.classList.toggle('d-none',value!=='CHEQUE'));
    }
    function actualizarMonto(){
        const option=garantia.selectedOptions[0];
        const recibido=Number(option?.dataset.recibido||0);
        const pactadoGuardado=Number(option?.dataset.pactado||0);
        const pactadoManual=Number(pactado.value||0);
        const total=pactadoGuardado>0?pactadoGuardado:pactadoManual;
        const pendiente=pactadoGuardado>0?Number(option?.dataset.pendiente||0):Math.max(0,total-recibido);
        const seleccionada=!!option?.value;
        const totalDefinido=total>0;
        const pagoTotal=modalidad.value==='TOTAL';
        monto.readOnly=pagoTotal&&seleccionada&&totalDefinido;
        if(pagoTotal&&seleccionada&&totalDefinido) monto.value=pendiente.toFixed(2);
        else if(monto.readOnly===false&&modalidad.dataset.anterior==='TOTAL') monto.value='';
        monto.max=seleccionada&&totalDefinido?String(pendiente):'';
        if(seleccionada&&pactadoGuardado<=0){rPactado.textContent=money(total);rPendiente.textContent=totalDefinido?money(pendiente):'Por definir';}
        ayudaMonto.textContent=pagoTotal?'El sistema completa automáticamente todo el saldo pendiente.':'';
        modalidad.dataset.anterior=modalidad.value;
    }
    function actualizarGarantia(){
        const option=garantia.selectedOptions[0];
        const pendiente=Number(option?.dataset.pendiente||0);
        const total=Number(option?.dataset.pactado||0);
        const recibido=Number(option?.dataset.recibido||0);
        const seleccionada=!!option?.value;
        const sinMonto=seleccionada&&total<=0;
        campoPactado.classList.toggle('d-none',!sinMonto);
        pactado.required=sinMonto;
        pactado.disabled=!sinMonto;
        resumen.classList.toggle('d-none',!seleccionada);
        rPactado.textContent=money(total);
        rRecibido.textContent=money(recibido);
        rPendiente.textContent=sinMonto?'Por definir':money(pendiente);
        ayudaPendiente.textContent=!seleccionada?'':sinMonto?'Debes indicar el monto total pactado.':'La garantía corresponde al contrato completo, no a un local individual.';
        actualizarMonto();
    }
    medio.addEventListener('change',actualizarMedio);
    garantia.addEventListener('change',actualizarGarantia);
    modalidad.addEventListener('change',actualizarMonto);
    pactado.addEventListener('input',actualizarMonto);
    buscador.addEventListener('input',buscarGarantias);
    actualizarMedio();
    actualizarGarantia();
})();
</script>
<?php msp2RenderCsrfAutoFieldScript(); ?>
</body></html>
