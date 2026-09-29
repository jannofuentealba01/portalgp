<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
msp2RequireAccess();

$flash = msp2PullFlash();
$error = null;
$garantias = [];
$recepciones = [];
$archivosByRecepcion = [];
$totales = ['pactado'=>0.0,'recibido'=>0.0,'pendiente'=>0.0];
$idGarantiaLegacy = filter_input(INPUT_GET, 'id_garantia', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
$idGarantiaPreseleccionada = filter_input(INPUT_GET, 'id_garantia_tienda', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
$idContratoPreseleccionado = filter_input(INPUT_GET, 'id_contrato_arriendo', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
$returnTo = trim((string) ($_GET['return_to'] ?? ''));
if ($returnTo === '' || preg_match('#^pendientes/index\.php(?:\?[A-Za-z0-9_\-\.\[\]%=&]*)?$#', $returnTo) !== 1) {
    $returnTo = 'garantias/index.php';
}
$returnLabel = str_starts_with($returnTo, 'pendientes/index.php') ? 'Volver a pendientes' : 'Volver a Garantías';

function msp2GarFmtMonto(mixed $value): string { return '$ ' . number_format((float) $value, 0, ',', '.'); }
function msp2GarFmtFecha(mixed $value): string {
    $raw = trim((string) $value); if ($raw === '') return '-';
    $date = DateTimeImmutable::createFromFormat('Y-m-d', substr($raw, 0, 10));
    return $date ? $date->format('d-m-Y') : $raw;
}

try {
    foreach (['msp_garantia_recepciones','msp_tesoreria_cuentas','msp_tesoreria_movimientos','msp_vw_garantias_tienda_resumen'] as $required) {
        if (!msp2TableExists($conn, $required)) {
            throw new RuntimeException('Falta el prerrequisito de garantías/tesorería. Ejecuta msp/db/patch_garantias_tesoreria_base.sql.');
        }
    }

    if ($idGarantiaPreseleccionada <= 0 && $idGarantiaLegacy > 0) {
        $stmtCanonical = $conn->prepare('SELECT id_garantia_tienda FROM dbo.msp_garantias WHERE id_garantia=:id');
        $stmtCanonical->execute([':id'=>$idGarantiaLegacy]);
        $idGarantiaPreseleccionada = (int) ($stmtCanonical->fetchColumn() ?: 0);
    }

    // Una fila por contrato-tienda; los locales se muestran como alcance, no como fondos separados.
    $garantias = $conn->query(
        'SELECT r.id_garantia_tienda,r.id_contrato_arriendo,r.monto_pactado,r.monto_recibido,
                r.monto_pendiente_recepcion AS monto_por_recibir,r.nombre_locatario,r.rut,
                r.nombre_comercial,r.locales
         FROM dbo.msp_vw_garantias_tienda_resumen r
         INNER JOIN dbo.msp_contratos_arriendo c ON c.id_contrato_arriendo=r.id_contrato_arriendo
         WHERE r.estado_garantia<>6 AND c.estado_contrato<>5
         ORDER BY r.nombre_locatario,r.nombre_comercial,r.id_garantia_tienda'
    )->fetchAll() ?: [];

    if ($idGarantiaPreseleccionada <= 0 && $idContratoPreseleccionado > 0) {
        foreach ($garantias as $garantiaDisponible) {
            $perteneceContrato = (int) ($garantiaDisponible['id_contrato_arriendo'] ?? 0) === $idContratoPreseleccionado;
            $requiereGestion = (float) ($garantiaDisponible['monto_pactado'] ?? 0) <= 0
                || (float) ($garantiaDisponible['monto_por_recibir'] ?? 0) > 0;
            if ($perteneceContrato && $requiereGestion) {
                $idGarantiaPreseleccionada = (int) $garantiaDisponible['id_garantia_tienda'];
                break;
            }
        }
    }

    $recepciones = $conn->query(
        'SELECT TOP (100) r.id_recepcion_garantia,r.fecha_recepcion,r.monto_recibido,r.medio_recepcion,r.referencia,r.banco_emisor,r.numero_cheque,r.estado_recepcion,
                r.id_garantia_tienda,gr.id_contrato_arriendo,gr.nombre_locatario,gr.rut,gr.nombre_comercial,gr.locales,tc.nombre_cuenta
         FROM dbo.msp_garantia_recepciones r
         INNER JOIN dbo.msp_vw_garantias_tienda_resumen gr ON gr.id_garantia_tienda=r.id_garantia_tienda
         LEFT JOIN dbo.msp_tesoreria_movimientos tm ON tm.id_recepcion_garantia=r.id_recepcion_garantia AND tm.estado_movimiento=N\'VIGENTE\'
         LEFT JOIN dbo.msp_tesoreria_cuentas tc ON tc.id_cuenta_tesoreria=tm.id_cuenta_tesoreria
         ORDER BY r.fecha_recepcion DESC,r.id_recepcion_garantia DESC'
    )->fetchAll() ?: [];
    $stmtArchivos = $conn->query("SELECT id_garantia_archivo,id_recepcion_garantia,nombre_archivo FROM dbo.msp_garantia_archivos WHERE id_recepcion_garantia IS NOT NULL AND estado_archivo=N'ACTIVO' ORDER BY id_garantia_archivo DESC");
    foreach (($stmtArchivos ? $stmtArchivos->fetchAll() : []) as $archivo) {
        $archivosByRecepcion[(int)$archivo['id_recepcion_garantia']][]=$archivo;
    }

    $rowTotales = $conn->query('SELECT SUM(monto_pactado) pactado,SUM(monto_recibido) recibido,SUM(CASE WHEN monto_pendiente_recepcion>0 THEN monto_pendiente_recepcion ELSE 0 END) pendiente FROM dbo.msp_vw_garantias_tienda_resumen')->fetch() ?: [];
    $totales = ['pactado'=>(float)($rowTotales['pactado']??0),'recibido'=>(float)($rowTotales['recibido']??0),'pendiente'=>(float)($rowTotales['pendiente']??0)];
} catch (Throwable $exception) {
    $error = pgpPublicOrBusinessException($exception, 'msp.garantias.recepciones', 'No fue posible cargar el módulo de recepción de garantías.');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Recepción de garantías | MSP</title>
    <link href="/portalgp/assets/vendor/bootstrap-5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="/portalgp/assets/vendor/bootstrap-icons-1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="/portalgp/styles.css?v=<?php echo rawurlencode((string) filemtime(dirname(__DIR__, 2) . '/styles.css')); ?>">
</head>
<body class="gp-layout gp-module-msp bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main container-fluid py-3 px-lg-4">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3" data-gp-commandbar>
        <a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url($returnTo)); ?>">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i><?php echo msp2Escape($returnLabel); ?>
        </a>
        <div><h1 class="h3 mb-0">Recepción de garantías</h1></div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url('contratos/index.php')); ?>">
                <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Contratos
            </a>
        </div>
    </header>
    <?php if (is_array($flash)): ?><div class="alert alert-<?php echo msp2Escape((string)($flash['type']??'info')); ?> alert-dismissible fade show"><?php echo msp2Escape((string)($flash['message']??'')); ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar mensaje" title="Cerrar mensaje"></button></div><?php endif; ?>
    <?php if ($error !== null): ?><div class="alert alert-danger"><?php echo msp2Escape($error); ?></div><?php endif; ?>

    <section class="gp-indicator-strip mb-3" aria-label="Resumen de recepción de garantías">
        <div class="gp-indicator"><span class="gp-indicator-label">Garantía pactada</span><strong class="gp-indicator-value"><?php echo msp2Escape(msp2GarFmtMonto($totales['pactado'])); ?></strong></div>
        <div class="gp-indicator"><span class="gp-indicator-label">Recibido confirmado</span><strong class="gp-indicator-value text-success"><?php echo msp2Escape(msp2GarFmtMonto($totales['recibido'])); ?></strong></div>
        <div class="gp-indicator"><span class="gp-indicator-label">Pendiente de recepción</span><strong class="gp-indicator-value text-warning-emphasis"><?php echo msp2Escape(msp2GarFmtMonto($totales['pendiente'])); ?></strong></div>
    </section>

    <div class="row g-4">
        <div class="col-12">
            <div class="card shadow-sm recepcion-card"><div class="card-header fw-semibold">Registrar recepción</div><div class="card-body">
                <form method="post" action="<?php echo msp2Escape(msp2Url('garantias/registrar_recepcion.php')); ?>" class="row g-3" id="formRecepcion">
                    <?php msp2CsrfField(); ?>
                    <div class="col-12 col-lg-6"><label class="form-label" for="buscar_garantia">Buscar tienda, arrendatario, RUT o contrato</label><div class="input-group"><span class="input-group-text"><i class="bi bi-search"></i></span><input type="search" id="buscar_garantia" class="form-control" placeholder="Ejemplo: ivo" autocomplete="off"></div><div id="resultadosBusquedaGarantia" class="list-group mt-2 d-none"></div></div>
                    <div class="col-12 col-lg-6"><label class="form-label" for="id_garantia">Garantía / tienda / contrato</label><select name="id_garantia_tienda" id="id_garantia" class="form-select" required><option value=""></option><?php foreach ($garantias as $g): $completa=(float)$g['monto_pactado']>0 && (float)$g['monto_por_recibir']<=0; ?><option value="<?php echo (int)$g['id_garantia_tienda']; ?>" data-id-contrato="<?php echo (int)$g['id_contrato_arriendo']; ?>" data-pactado="<?php echo msp2Escape((string)$g['monto_pactado']); ?>" data-recibido="<?php echo msp2Escape((string)$g['monto_recibido']); ?>" data-pendiente="<?php echo msp2Escape((string)$g['monto_por_recibir']); ?>" data-search="<?php echo msp2Escape(($g['nombre_comercial']??'').' '.($g['nombre_locatario']??'').' '.($g['rut']??'').' contrato '.$g['id_contrato_arriendo'].' locales '.($g['locales']??'')); ?>" <?php echo $completa?'disabled':''; ?> <?php echo !$completa && (int)$g['id_garantia_tienda']===$idGarantiaPreseleccionada?'selected':''; ?>><?php echo msp2Escape(($g['nombre_locatario']??$g['nombre_comercial']??'Arrendatario').' · Contrato '.$g['id_contrato_arriendo'].' · Locales '.($g['locales']??'-').' · '.($completa?'Garantía completa':((float)$g['monto_pactado']>0?'Pendiente '.msp2GarFmtMonto($g['monto_por_recibir']):'Monto pactado por definir'))); ?></option><?php endforeach; ?></select><div id="ayudaPendiente" class="form-text"><?php echo $garantias===[]?'No existen garantías registradas.':''; ?></div><div id="sinCoincidencias" class="alert alert-warning py-2 mt-2 d-none mb-0">No se encontraron garantías que coincidan con la búsqueda.</div></div>
                    <div class="col-12 d-none gp-operation-summary" id="resumenGarantiaSeleccionada"><div class="row g-2 text-center"><div class="col-md-4"><div class="small text-muted">Garantía pactada</div><div class="h5 mb-0" id="resumenPactado">$ 0</div></div><div class="col-md-4"><div class="small text-muted">Recibido anteriormente</div><div class="h5 mb-0" id="resumenRecibido">$ 0</div></div><div class="col-md-4"><div class="small text-muted">Máximo pendiente por recibir</div><div class="h5 mb-0 text-primary" id="resumenPendiente">$ 0</div></div></div></div>
                    <div class="col-md-4 d-none" id="campoMontoPactado"><label class="form-label">Monto pactado de la garantía</label><input type="number" name="monto_pactado" id="monto_pactado" min="0.01" step="0.01" class="form-control"><div class="form-text">Este contrato tiene la garantía creada en $0. Define aquí el total acordado.</div></div>
                    <div class="col-md-3"><label class="form-label">Forma de recepción</label><select name="modalidad_recepcion" id="modalidad_recepcion" class="form-select" required><option value="ABONO">Abono parcial</option><option value="TOTAL">Pagar total pendiente</option></select></div>
                    <div class="col-md-3"><label class="form-label">Fecha recepción</label><input type="date" name="fecha_recepcion" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
                    <div class="col-md-3"><label class="form-label">Monto recibido</label><input type="number" name="monto_recibido" id="monto_recibido" min="0.01" step="0.01" class="form-control" required><div id="ayudaMonto" class="form-text"></div></div>
                    <div class="col-md-3"><label class="form-label">Medio</label><select name="medio_recepcion" id="medio_recepcion" class="form-select" required><option value="EFECTIVO">Efectivo</option><option value="TRANSFERENCIA">Transferencia</option><option value="CHEQUE">Cheque</option></select></div>
                    <div class="col-md-4 campo-cheque d-none"><label class="form-label">Banco emisor <span class="text-muted fw-normal">(opcional)</span></label><input name="banco_emisor" maxlength="120" class="form-control"></div>
                    <div class="col-md-4 campo-cheque d-none"><label class="form-label">Número cheque <span class="text-muted fw-normal">(opcional)</span></label><input name="numero_cheque" maxlength="80" class="form-control"></div>
                    <div class="col-md-4 campo-cheque d-none"><label class="form-label">Fecha cheque <span class="text-muted fw-normal">(opcional)</span></label><input type="date" name="fecha_cheque" class="form-control"></div>
                    <div class="col-12"><label class="form-label">Observaciones</label><textarea name="observaciones" maxlength="500" rows="1" class="form-control"></textarea><div class="form-text">Opcional: registra la referencia de la transferencia o cualquier antecedente relevante de la recepción.</div></div>
                    <div class="col-12 text-end"><button class="btn btn-success" <?php echo $error!==null?'disabled':''; ?>><i class="bi bi-check-circle me-1"></i>Confirmar recepción</button></div>
                </form>
            </div></div>
        </div>
    </div>

    <div class="card shadow-sm mt-3">
        <div class="card-header fw-semibold">Últimas recepciones</div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 gp-table-compact gp-table-mobile-cards msp-guarantee-receipts-table">
                <thead class="table-light"><tr><th>Fecha</th><th>Arrendatario / tienda</th><th>Medio / destino</th><th>Referencia</th><th>Monto / estado</th><th>Documentos</th></tr></thead>
                <tbody>
                <?php if($recepciones===[]): ?><tr class="gp-table-empty-row"><td colspan="6" class="gp-table-empty-cell">No existen recepciones registradas.</td></tr><?php endif; ?>
                <?php foreach($recepciones as $r): $archivosRec=$archivosByRecepcion[(int)$r['id_recepcion_garantia']]??[]; ?>
                    <tr>
                        <td data-gp-label="Fecha"><?php echo msp2Escape(msp2GarFmtFecha($r['fecha_recepcion'])); ?></td>
                        <td data-gp-label="Arrendatario / tienda"><div class="fw-semibold"><?php echo msp2Escape((string)$r['nombre_locatario']); ?></div><div class="small text-muted"><?php echo msp2Escape((string)$r['rut']); ?> · Contrato #<?php echo (int)$r['id_contrato_arriendo']; ?></div><div class="small text-muted"><?php echo msp2Escape((string)$r['nombre_comercial']); ?> · Locales <?php echo msp2Escape((string)$r['locales']); ?></div></td>
                        <td data-gp-label="Medio / destino"><div class="fw-semibold"><?php echo msp2Escape((string)$r['medio_recepcion']); ?></div><div class="small text-muted"><?php echo msp2Escape((string)($r['nombre_cuenta']??'Sin cuenta asociada')); ?></div></td>
                        <td data-gp-label="Referencia"><?php echo msp2Escape((string)($r['referencia']?:($r['numero_cheque']?:'-'))); ?></td>
                        <td data-gp-label="Monto / estado"><div class="fw-semibold garantia-importe-principal"><?php echo msp2Escape(msp2GarFmtMonto($r['monto_recibido'])); ?></div><span class="badge text-bg-<?php echo ($r['estado_recepcion']??'')==='CONFIRMADA'?'success':'secondary'; ?>"><?php echo msp2Escape((string)$r['estado_recepcion']); ?></span></td>
                        <td data-gp-label="Documentos"><div class="garantia-respaldos-list"><a class="btn btn-outline-primary btn-sm" target="_blank" href="<?php echo msp2Escape(msp2Url('garantias/comprobante.php?tipo=RECEPCION&id='.(int)$r['id_recepcion_garantia']));?>">Comprobante</a><?php foreach($archivosRec as $a):?><a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url('garantias/descargar_archivo.php?id='.(int)$a['id_garantia_archivo']));?>" title="<?php echo msp2Escape((string)$a['nombre_archivo']);?>">Respaldo #<?php echo (int)$a['id_garantia_archivo'];?></a><?php endforeach;?><button type="button" class="btn btn-success btn-sm js-adjuntar-recepcion" data-bs-toggle="modal" data-bs-target="#modalAdjuntarRecepcion" data-recepcion="<?php echo (int)$r['id_recepcion_garantia']; ?>" data-descripcion="<?php echo msp2Escape((string)$r['nombre_locatario'].' · '.(string)$r['nombre_comercial']); ?>">Adjuntar</button></div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="modal fade" id="modalAdjuntarRecepcion" tabindex="-1" aria-labelledby="modalAdjuntarRecepcionTitulo" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
            <form method="post" enctype="multipart/form-data" action="<?php echo msp2Escape(msp2Url('garantias/subir_archivo.php'));?>">
                <?php msp2CsrfField(); ?>
                <div class="modal-header"><h2 class="modal-title fs-5" id="modalAdjuntarRecepcionTitulo">Adjuntar respaldo</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                <div class="modal-body"><p class="small text-muted mb-2" id="modalAdjuntarRecepcionDescripcion"></p><input type="hidden" name="origen" value="RECEPCION"><input type="hidden" name="id_recepcion_garantia" id="modalRecepcionId"><label for="modalRecepcionArchivo" class="form-label">Archivo PDF o imagen</label><input type="file" id="modalRecepcionArchivo" name="archivo" accept=".pdf,.jpg,.jpeg,.png" class="form-control" required></div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-success btn-sm">Subir respaldo</button></div>
            </form>
        </div></div>
    </div>
</main>
<script<?= function_exists('pgpCspNonceAttribute') ? pgpCspNonceAttribute() : '' ?> src="/portalgp/assets/vendor/bootstrap-5.3.0/js/bootstrap.bundle.min.js"></script>
<script<?= function_exists('pgpCspNonceAttribute') ? pgpCspNonceAttribute() : '' ?>>
(()=>{
    const medio=document.getElementById('medio_recepcion');
    const garantia=document.getElementById('id_garantia');
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
        ayudaPendiente.textContent=!seleccionada?'':sinMonto?'Debes indicar el monto pactado para esta garantía.':'La operación se registrará únicamente en la garantía y el local seleccionados.';
        actualizarMonto();
    }
    medio.addEventListener('change',actualizarMedio);
    garantia.addEventListener('change',actualizarGarantia);
    modalidad.addEventListener('change',actualizarMonto);
    pactado.addEventListener('input',actualizarMonto);
    buscador.addEventListener('input',buscarGarantias);
    if(garantia.value){
        buscador.value=garantia.selectedOptions[0]?.textContent.split(' · Contrato')[0]||'';
    }
    actualizarMedio();
    actualizarGarantia();
})();
</script>
<script<?= function_exists('pgpCspNonceAttribute') ? pgpCspNonceAttribute() : '' ?>>
(()=>{
    const idInput=document.getElementById('modalRecepcionId');
    const description=document.getElementById('modalAdjuntarRecepcionDescripcion');
    document.querySelectorAll('.js-adjuntar-recepcion').forEach(button=>{
        button.addEventListener('click',()=>{
            idInput.value=button.dataset.recepcion||'';
            description.textContent=button.dataset.descripcion||'';
        });
    });
})();
</script>
<?php msp2RenderCsrfAutoFieldScript(); ?>
<?php require_once dirname(__DIR__, 2) . '/templates/components/page_navigation.php'; ?>
</body></html>
