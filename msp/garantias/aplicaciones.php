<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
msp2RequireAccess();

$flash = msp2PullFlash();
$error = null;
$idContratoFiltro = filter_input(INPUT_GET, 'id_contrato_arriendo', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$idContratoFiltro = ($idContratoFiltro === false || $idContratoFiltro === null) ? 0 : (int) $idContratoFiltro;
$q = msp2NormalizeText((string)($_GET['q'] ?? ''));
if ($idContratoFiltro > 0 && $q === '') {
    $q = (string) $idContratoFiltro;
}
$cargos = [];
$documentos = [];
$historial = [];

function gaMonto(mixed $value): string
{
    return '$ ' . number_format((float) $value, 2, ',', '.');
}

try {
    foreach (['msp_cargos_salida','msp_cargos_contrato_local','msp_garantias','msp_garantia_recepciones','msp_movimientos_garantia','msp_vw_garantias_resumen'] as $table) {
        if (!msp2TableExists($conn, $table)) {
            throw new RuntimeException('Falta completar la instalación del módulo de cargos y garantías.');
        }
    }

    $cargos = $conn->query(
        "SELECT cs.id_cargo_salida,ccl.id_cargo_contrato_local,cs.fecha_cargo,cs.descripcion_cargo,cs.monto_cargo,cs.estado_cargo,
                g.id_garantia,g.id_contrato_arriendo,a.nombre_locatario,a.rut,l.cdo_local,
                gr.saldo_disponible,gr.saldo_reservado,
                rec.monto_recibido,
                ISNULL(mov.reservado,0) reservado_cargo,ISNULL(mov.liberado,0) liberado_cargo,
                ISNULL(mov.aplicado_disponible,0) aplicado_disponible,
                ISNULL(mov.aplicado_reservado,0) aplicado_reservado,
                ISNULL(dev.monto_devuelto,0) monto_devuelto
         FROM dbo.msp_cargos_salida cs
         INNER JOIN dbo.msp_cargos_contrato_local ccl ON ccl.id_cargo_salida_legacy=cs.id_cargo_salida
         INNER JOIN dbo.msp_garantias g ON g.id_contrato_arriendo=cs.id_contrato_arriendo AND g.id_local=cs.id_local AND g.estado_garantia<>6
         INNER JOIN dbo.msp_contratos_arriendo c ON c.id_contrato_arriendo=g.id_contrato_arriendo
         INNER JOIN dbo.msp_arrendatarios a ON a.id_arrendatario=c.id_arrendatario
         INNER JOIN dbo.msp_locales l ON l.id_local=g.id_local
         INNER JOIN dbo.msp_vw_garantias_resumen gr ON gr.id_garantia=g.id_garantia
         CROSS APPLY(SELECT ISNULL(SUM(r.monto_recibido),0) monto_recibido FROM dbo.msp_garantia_recepciones r WHERE r.id_garantia=g.id_garantia AND r.estado_recepcion=N'CONFIRMADA') rec
         OUTER APPLY(
             SELECT
                SUM(CASE WHEN tm.codigo_movimiento=N'RESERVA' THEN mg.monto_movimiento ELSE 0 END) reservado,
                SUM(CASE WHEN tm.codigo_movimiento=N'LIBERACION_RESERVA' THEN mg.monto_movimiento ELSE 0 END) liberado,
                SUM(CASE WHEN tm.codigo_movimiento=N'APLICACION_CARGO' AND mg.fondo_origen='D' THEN mg.monto_movimiento ELSE 0 END) aplicado_disponible,
                SUM(CASE WHEN tm.codigo_movimiento=N'APLICACION_CARGO' AND mg.fondo_origen='R' THEN mg.monto_movimiento ELSE 0 END) aplicado_reservado
             FROM dbo.msp_movimientos_garantia mg
             INNER JOIN dbo.msp_tipos_movimiento_garantia tm ON tm.id_tipo_movimiento_garantia=mg.id_tipo_movimiento_garantia
             WHERE mg.id_garantia=g.id_garantia AND (mg.id_cargo_salida=cs.id_cargo_salida OR mg.id_cargo_contrato_local=ccl.id_cargo_contrato_local)
         ) mov
         OUTER APPLY(
             SELECT SUM(mg.monto_movimiento) monto_devuelto
             FROM dbo.msp_movimientos_garantia mg
             INNER JOIN dbo.msp_tipos_movimiento_garantia tm ON tm.id_tipo_movimiento_garantia=mg.id_tipo_movimiento_garantia
             WHERE mg.id_garantia=g.id_garantia AND tm.codigo_movimiento=N'DEVOLUCION'
         ) dev
         WHERE cs.estado_cargo IN(1,2)
         ORDER BY a.nombre_locatario,l.cdo_local,cs.fecha_cargo,cs.id_cargo_salida"
    )->fetchAll() ?: [];

    foreach ($cargos as &$cargo) {
        $aplicado = (float)$cargo['aplicado_disponible'] + (float)$cargo['aplicado_reservado'];
        $reservaNeta = max(0, (float)$cargo['reservado_cargo'] - (float)$cargo['liberado_cargo'] - (float)$cargo['aplicado_reservado']);
        $pendiente = max(0, (float)$cargo['monto_cargo'] - $aplicado);
        $realDisponible = max(0, (float)$cargo['monto_recibido'] - $aplicado - (float)$cargo['monto_devuelto'] - $reservaNeta);
        $cargo['aplicado_cargo'] = $aplicado;
        $cargo['reserva_neta_cargo'] = $reservaNeta;
        $cargo['pendiente_cargo'] = $pendiente;
        $cargo['max_reservar'] = min((float)$cargo['saldo_disponible'], $realDisponible, max(0, $pendiente - $reservaNeta));
        $cargo['max_aplicar_disponible'] = min((float)$cargo['saldo_disponible'], $realDisponible, $pendiente);
        $cargo['max_reserva'] = min($reservaNeta, $pendiente);
    }
    unset($cargo);

    if ($q === '') {
        $cargos = [];
    } else {
        $needle = mb_strtolower($q, 'UTF-8');
        $cargos = array_values(array_filter($cargos, static function(array $cargo) use ($needle): bool {
            $searchable = mb_strtolower(implode(' ', [
                (string)($cargo['nombre_locatario']??''),(string)($cargo['rut']??''),
                (string)($cargo['id_contrato_arriendo']??''),(string)($cargo['cdo_local']??'')
            ]), 'UTF-8');
            return str_contains($searchable, $needle);
        }));
    }
    if ($idContratoFiltro > 0) {
        $cargos = array_values(array_filter($cargos, static fn(array $cargo): bool => (int) ($cargo['id_contrato_arriendo'] ?? 0) === $idContratoFiltro));
    }

    if ($q !== '') {
        $stmtDocumentos = $conn->prepare(
            "SELECT g.id_garantia,g.id_contrato_arriendo,a.nombre_locatario,a.rut,l.cdo_local,
                    dc.id_documento_cobro,dc.numero_documento,dc.periodo_facturacion,dc.fecha_vencimiento,dc.saldo_pendiente,
                    base.id_tipo_item_documento,base.codigo_item,base.nombre_item,
                    CAST(CASE WHEN base.monto_total-ISNULL(pag.aplicado,0)>0 THEN base.monto_total-ISNULL(pag.aplicado,0) ELSE 0 END AS DECIMAL(18,2)) saldo_concepto,
                    rec.monto_recibido,
                    CAST(CASE WHEN rec.monto_recibido-ISNULL(mov.aplicado_devuelto,0)-ISNULL(mov.reserva_neta,0)>0 THEN rec.monto_recibido-ISNULL(mov.aplicado_devuelto,0)-ISNULL(mov.reserva_neta,0) ELSE 0 END AS DECIMAL(18,2)) garantia_disponible_real
             FROM dbo.msp_garantias g
             INNER JOIN dbo.msp_contratos_arriendo c ON c.id_contrato_arriendo=g.id_contrato_arriendo
             INNER JOIN dbo.msp_arrendatarios a ON a.id_arrendatario=c.id_arrendatario
             INNER JOIN dbo.msp_locales l ON l.id_local=g.id_local
             INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_contrato_arriendo=g.id_contrato_arriendo AND dc.estado_documento IN(2,3) AND dc.saldo_pendiente>0
             INNER JOIN (
                SELECT d.id_documento_cobro,d.id_tipo_item_documento,t.codigo_item,t.nombre_item,
                       SUM(d.subtotal)+CASE WHEN t.codigo_item=N'ARRIENDO' THEN CASE WHEN doc.monto_total-doc.subtotal_arriendo-doc.subtotal_servicios>0 THEN doc.monto_total-doc.subtotal_arriendo-doc.subtotal_servicios ELSE 0 END ELSE 0 END monto_total
                FROM dbo.msp_documentos_cobro_detalle d
                INNER JOIN dbo.msp_tipo_item_documento t ON t.id_tipo_item_documento=d.id_tipo_item_documento
                INNER JOIN dbo.msp_documentos_cobro doc ON doc.id_documento_cobro=d.id_documento_cobro
                GROUP BY d.id_documento_cobro,d.id_tipo_item_documento,t.codigo_item,t.nombre_item,doc.monto_total,doc.subtotal_arriendo,doc.subtotal_servicios
             ) base ON base.id_documento_cobro=dc.id_documento_cobro
             OUTER APPLY(SELECT SUM(pdc.monto_aplicado) aplicado FROM dbo.msp_pagos_detalle_concepto pdc INNER JOIN dbo.msp_pagos p ON p.id_pago=pdc.id_pago WHERE pdc.id_documento_cobro=dc.id_documento_cobro AND pdc.id_tipo_item_documento=base.id_tipo_item_documento AND p.estado_pago=1) pag
             CROSS APPLY(SELECT ISNULL(SUM(r.monto_recibido),0) monto_recibido FROM dbo.msp_garantia_recepciones r WHERE r.id_garantia=g.id_garantia AND r.estado_recepcion=N'CONFIRMADA') rec
             OUTER APPLY(
                SELECT
                  SUM(CASE WHEN tm.codigo_movimiento IN(N'APLICACION_CARGO',N'DEVOLUCION') THEN mg.monto_movimiento ELSE 0 END) aplicado_devuelto,
                  SUM(CASE WHEN tm.codigo_movimiento=N'RESERVA' THEN mg.monto_movimiento WHEN tm.codigo_movimiento=N'LIBERACION_RESERVA' THEN -mg.monto_movimiento WHEN tm.codigo_movimiento=N'APLICACION_CARGO' AND mg.fondo_origen='R' THEN -mg.monto_movimiento ELSE 0 END) reserva_neta
                FROM dbo.msp_movimientos_garantia mg INNER JOIN dbo.msp_tipos_movimiento_garantia tm ON tm.id_tipo_movimiento_garantia=mg.id_tipo_movimiento_garantia WHERE mg.id_garantia=g.id_garantia
             ) mov
              WHERE g.estado_garantia<>6 AND rec.monto_recibido>0
                " . ($idContratoFiltro > 0 ? 'AND g.id_contrato_arriendo = :contrato_filtro' : '') . "
               AND (SELECT COUNT(*) FROM dbo.msp_contrato_locales clx
                    WHERE clx.id_contrato_arriendo=g.id_contrato_arriendo
                      AND clx.estado_relacion IN(1,2)
                      AND clx.fecha_inicio<=EOMONTH(dc.fecha_emision)
                      AND (clx.fecha_termino IS NULL OR clx.fecha_termino>=dc.fecha_emision))=1
               AND (a.nombre_locatario LIKE :q1 OR a.rut LIKE :q2 OR CAST(g.id_contrato_arriendo AS NVARCHAR(20)) LIKE :q3 OR l.cdo_local LIKE :q4)
               AND base.codigo_item IN(N'ARRIENDO',N'SERVICIO_LUZ',N'SERVICIO_AGUA',N'SERVICIO_GAS')\n               AND base.monto_total-ISNULL(pag.aplicado,0)>0
             ORDER BY a.nombre_locatario,dc.periodo_facturacion,dc.id_documento_cobro,base.codigo_item,l.cdo_local"
        );
        foreach([':q1',':q2',':q3',':q4'] as $param) $stmtDocumentos->bindValue($param,'%'.$q.'%',PDO::PARAM_STR);
        if ($idContratoFiltro > 0) {
            $stmtDocumentos->bindValue(':contrato_filtro', $idContratoFiltro, PDO::PARAM_INT);
        }
        $stmtDocumentos->execute();
        $documentos=$stmtDocumentos->fetchAll()?:[];
    }

    $historial = $conn->query(
         "SELECT TOP(100) mg.id_movimiento_garantia,mg.fecha_movimiento,mg.monto_movimiento,mg.fondo_origen,mg.observaciones,
                 tm.codigo_movimiento,tm.nombre_movimiento,g.id_contrato_arriendo,a.nombre_locatario,l.cdo_local,
                COALESCE(cs.id_cargo_salida,ccl.id_cargo_salida_legacy) id_cargo_salida,mg.id_documento_cobro,
                COALESCE(cs.descripcion_cargo,ccl.descripcion_cargo,CONCAT(N'Documento ',dc.numero_documento,N' · ',ti.nombre_item)) descripcion_cargo
         FROM dbo.msp_movimientos_garantia mg
         INNER JOIN dbo.msp_tipos_movimiento_garantia tm ON tm.id_tipo_movimiento_garantia=mg.id_tipo_movimiento_garantia
         INNER JOIN dbo.msp_garantias g ON g.id_garantia=mg.id_garantia
         INNER JOIN dbo.msp_contratos_arriendo c ON c.id_contrato_arriendo=g.id_contrato_arriendo
         INNER JOIN dbo.msp_arrendatarios a ON a.id_arrendatario=c.id_arrendatario
         INNER JOIN dbo.msp_locales l ON l.id_local=g.id_local
         LEFT JOIN dbo.msp_cargos_salida cs ON cs.id_cargo_salida=mg.id_cargo_salida
         LEFT JOIN dbo.msp_cargos_contrato_local ccl ON ccl.id_cargo_contrato_local=mg.id_cargo_contrato_local
         LEFT JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro=mg.id_documento_cobro
         LEFT JOIN dbo.msp_garantia_documento_aplicaciones gda ON gda.id_movimiento_garantia=mg.id_movimiento_garantia
         LEFT JOIN dbo.msp_tipo_item_documento ti ON ti.id_tipo_item_documento=gda.id_tipo_item_documento
         WHERE tm.codigo_movimiento IN(N'RESERVA',N'LIBERACION_RESERVA',N'APLICACION_CARGO')
     ORDER BY mg.fecha_movimiento DESC,mg.id_movimiento_garantia DESC"
    )->fetchAll() ?: [];
    if ($idContratoFiltro > 0) {
        $historial = array_values(array_filter($historial, static fn(array $row): bool => (int) ($row['id_contrato_arriendo'] ?? 0) === $idContratoFiltro));
    }
} catch (Throwable $exception) {
    $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'No fue posible cargar la aplicación de garantías.';
}
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Aplicación de garantías | MSP</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="/portalgp/styles.css"></head>
<body class="gp-layout bg-light"><?php include dirname(__DIR__,2).'/templates/header.php'; ?>
<main class="gp-main container-fluid py-4 px-lg-4">
<div class="d-flex flex-wrap justify-content-between gap-2 mb-3"><div><h1 class="h3">Aplicar garantía a deuda</h1></div><a class="btn btn-outline-dark btn-sm align-self-start" href="<?php echo msp2Escape(msp2Url('garantias/index.php'));?>"><i class="bi bi-arrow-left me-1"></i>Volver a Garantías</a></div>
<?php if(is_array($flash)):?><div class="alert alert-<?php echo msp2Escape((string)($flash['type']??'info'));?>"><?php echo msp2Escape((string)($flash['message']??''));?></div><?php endif;?>
<?php if($error):?><div class="alert alert-danger"><?php echo msp2Escape($error);?></div><?php endif;?>

<div class="card shadow-sm mb-4"><div class="card-body"><form method="get" class="row g-2 align-items-end"><input type="hidden" name="id_contrato_arriendo" value="<?php echo $idContratoFiltro > 0 ? (int) $idContratoFiltro : ''; ?>"><div class="col-lg-10"><label class="form-label fw-semibold">Buscar garantía</label><input type="search" name="q" class="form-control" value="<?php echo msp2Escape($q);?>" placeholder="Arrendatario, RUT, contrato o local"><?php if ($idContratoFiltro > 0): ?><div class="form-text"><i class="bi bi-link-45deg me-1"></i>Contrato precargado: <strong>#<?php echo (int) $idContratoFiltro; ?></strong>. Los resultados quedan limitados a este contrato.</div><?php endif; ?></div><div class="col-lg-2 d-grid"><button class="btn btn-dark"><i class="bi bi-search me-1"></i>Buscar</button></div></form></div></div>

<?php if($q!==''):?>
<div class="card shadow-sm mb-4"><div class="card-header fw-semibold">¿A qué desea aplicar la garantía?</div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Arrendatario / garantía</th><th>Documento</th><th>Destino</th><th class="text-end">Deuda concepto</th><th class="text-end">Garantía disponible</th><th style="min-width:300px">Aplicar</th></tr></thead><tbody>
<?php if($documentos===[]):?><tr><td colspan="6" class="text-center text-muted py-4">No se encontraron documentos pendientes compatibles con garantías recibidas.</td></tr><?php endif;?>
<?php foreach($documentos as $d):$maxDoc=min((float)$d['saldo_concepto'],(float)$d['saldo_pendiente'],(float)$d['garantia_disponible_real']);?>
<tr><td><div class="fw-semibold"><?php echo msp2Escape((string)$d['nombre_locatario']);?></div><div class="small text-muted"><?php echo msp2Escape((string)$d['rut']);?> · Garantía #<?php echo (int)$d['id_garantia'];?> · Local <?php echo msp2Escape((string)$d['cdo_local']);?></div></td><td><div class="fw-semibold">#<?php echo (int)$d['id_documento_cobro'];?> · <?php echo msp2Escape((string)($d['numero_documento']??'Sin número'));?></div><div class="small text-muted">Periodo <?php echo msp2Escape(substr((string)$d['periodo_facturacion'],0,7));?> · Saldo total <?php echo msp2Escape(gaMonto($d['saldo_pendiente']));?></div></td><td><span class="badge text-bg-secondary"><?php echo msp2Escape((string)$d['nombre_item']);?></span></td><td class="text-end fw-semibold"><?php echo msp2Escape(gaMonto($d['saldo_concepto']));?></td><td class="text-end fw-semibold"><?php echo msp2Escape(gaMonto($d['garantia_disponible_real']));?></td><td><form method="post" action="<?php echo msp2Escape(msp2Url('garantias/aplicar_documento.php'));?>" class="row g-2"><?php msp2CsrfField();?><input type="hidden" name="q" value="<?php echo msp2Escape($q);?>"><input type="hidden" name="id_contrato_arriendo" value="<?php echo $idContratoFiltro > 0 ? (int)$idContratoFiltro : ''; ?>"><input type="hidden" name="id_garantia" value="<?php echo (int)$d['id_garantia'];?>"><input type="hidden" name="id_documento_cobro" value="<?php echo (int)$d['id_documento_cobro'];?>"><input type="hidden" name="id_tipo_item_documento" value="<?php echo (int)$d['id_tipo_item_documento'];?>"><div class="col-6"><input type="number" name="monto_aplicar" class="form-control form-control-sm" min="0.01" max="<?php echo msp2Escape((string)$maxDoc);?>" step="0.01" placeholder="Máx. <?php echo msp2Escape(gaMonto($maxDoc));?>" required></div><div class="col-6"><input type="date" name="fecha_aplicacion" class="form-control form-control-sm" value="<?php echo date('Y-m-d');?>" required></div><div class="col-8"><input name="observaciones" maxlength="500" class="form-control form-control-sm" placeholder="Motivo / autorización"></div><div class="col-4 d-grid"><button class="btn btn-warning btn-sm" <?php echo $maxDoc<=0?'disabled':'';?> onclick="return confirm('¿Aplicar garantía a esta deuda? La operación afectará cobranza y contabilidad.');">Aplicar</button></div></form></td></tr>
<?php endforeach;?></tbody></table></div></div>
<?php endif;?>

<div class="card shadow-sm"><div class="card-header fw-semibold">Cargos adicionales compatibles</div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Arrendatario / local</th><th>Cargo</th><th>Garantía</th><th>Situación del cargo</th><th style="min-width:330px">Operación</th></tr></thead><tbody>
<?php if($cargos===[]):?><tr><td colspan="5" class="text-center text-muted py-4"><?php echo $q===''?'Busca un arrendatario para consultar sus cargos.':'No existen cargos adicionales pendientes compatibles con garantías recibidas.';?></td></tr><?php endif;?>
<?php foreach($cargos as $c):?>
<tr><td><div class="fw-semibold"><?php echo msp2Escape((string)$c['nombre_locatario']);?></div><div class="small text-muted">Contrato #<?php echo (int)$c['id_contrato_arriendo'];?> · Local <?php echo msp2Escape((string)$c['cdo_local']);?></div></td>
<td><div class="fw-semibold">Cargo #<?php echo (int)$c['id_cargo_salida'];?> · <?php echo msp2Escape((string)$c['descripcion_cargo']);?></div><div class="small text-muted"><?php echo msp2Escape(substr((string)$c['fecha_cargo'],0,10));?> · Total <?php echo msp2Escape(gaMonto($c['monto_cargo']));?></div></td>
<td><div class="small">Recibida: <strong><?php echo msp2Escape(gaMonto($c['monto_recibido']));?></strong></div><div class="small">Disponible contable: <strong><?php echo msp2Escape(gaMonto($c['saldo_disponible']));?></strong></div><div class="small">Reservada total: <strong><?php echo msp2Escape(gaMonto($c['saldo_reservado']));?></strong></div></td>
<td><div class="small">Pendiente: <strong><?php echo msp2Escape(gaMonto($c['pendiente_cargo']));?></strong></div><div class="small">Reservado aquí: <strong><?php echo msp2Escape(gaMonto($c['reserva_neta_cargo']));?></strong></div><div class="small">Aplicado: <strong><?php echo msp2Escape(gaMonto($c['aplicado_cargo']));?></strong></div></td>
<td><form method="post" action="<?php echo msp2Escape(msp2Url('contratos/movimiento_garantia_cargo.php'));?>" class="row g-2 form-operacion"><?php msp2CsrfField();?><input type="hidden" name="redirect_to" value="<?php echo msp2Escape('garantias/aplicaciones.php' . ($idContratoFiltro > 0 ? '?id_contrato_arriendo=' . (int)$idContratoFiltro : '')); ?>"><input type="hidden" name="id_cargo_salida" value="<?php echo (int)$c['id_cargo_salida'];?>"><input type="hidden" name="id_garantia" value="<?php echo (int)$c['id_garantia'];?>">
<div class="col-7"><select name="accion_garantia" class="form-select form-select-sm accion" required data-reservar="<?php echo msp2Escape((string)$c['max_reservar']);?>" data-disponible="<?php echo msp2Escape((string)$c['max_aplicar_disponible']);?>" data-reservado="<?php echo msp2Escape((string)$c['max_reserva']);?>"><option value="">Seleccionar acción</option><option value="RESERVAR" <?php echo $c['max_reservar']<=0?'disabled':'';?>>Reservar garantía</option><option value="APLICAR_DESDE_DISPONIBLE" <?php echo $c['max_aplicar_disponible']<=0?'disabled':'';?>>Aplicar desde disponible</option><option value="APLICAR_DESDE_RESERVADO" <?php echo $c['max_reserva']<=0?'disabled':'';?>>Aplicar desde reservado</option><option value="LIBERAR_RESERVA" <?php echo $c['max_reserva']<=0?'disabled':'';?>>Liberar reserva</option></select></div>
<div class="col-5"><input type="number" name="monto_movimiento" class="form-control form-control-sm monto" min="0.01" step="0.01" placeholder="Monto" required></div><div class="col-12"><input name="observaciones" maxlength="500" class="form-control form-control-sm" placeholder="Motivo u observación"><div class="form-text limite"></div></div><div class="col-12 d-grid"><button class="btn btn-warning btn-sm" onclick="return confirm('¿Confirmas esta operación sobre la garantía?');">Registrar operación</button></div></form></td></tr>
<?php endforeach;?></tbody></table></div></div>

<details class="card shadow-sm mt-4"><summary class="card-header fw-semibold" style="cursor:pointer">Historial de operaciones (<?php echo count($historial); ?>)</summary><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead class="table-light"><tr><th>Fecha</th><th>Arrendatario / local</th><th>Origen de deuda</th><th>Operación</th><th>Fondo</th><th class="text-end">Monto</th><th>Observaciones</th></tr></thead><tbody><?php if($historial===[]):?><tr><td colspan="7" class="text-center text-muted py-3">Sin operaciones registradas.</td></tr><?php endif;?><?php foreach($historial as $h):?><tr><td><?php echo msp2Escape(substr((string)$h['fecha_movimiento'],0,10));?></td><td><?php echo msp2Escape((string)$h['nombre_locatario']);?><div class="small text-muted">Local <?php echo msp2Escape((string)$h['cdo_local']);?></div></td><td><?php echo $h['id_documento_cobro']?'Documento #'.(int)$h['id_documento_cobro']:'Cargo #'.(int)$h['id_cargo_salida'];?> · <?php echo msp2Escape((string)$h['descripcion_cargo']);?></td><td><?php echo msp2Escape((string)$h['nombre_movimiento']);?></td><td><?php echo $h['fondo_origen']==='R'?'Reservado':($h['fondo_origen']==='D'?'Disponible':'-');?></td><td class="text-end fw-semibold"><?php echo msp2Escape(gaMonto($h['monto_movimiento']));?></td><td><?php echo msp2Escape((string)($h['observaciones']??'-'));?></td></tr><?php endforeach;?></tbody></table></div></details>
</main><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script><script>(()=>{document.querySelectorAll('.form-operacion').forEach(f=>{const a=f.querySelector('.accion'),m=f.querySelector('.monto'),l=f.querySelector('.limite');const sync=()=>{let max=0;if(a.value==='RESERVAR')max=Number(a.dataset.reservar||0);else if(a.value==='APLICAR_DESDE_DISPONIBLE')max=Number(a.dataset.disponible||0);else max=Number(a.dataset.reservado||0);m.max=max>0?String(max):'';l.textContent=max>0?'Máximo para esta acción: $ '+max.toLocaleString('es-CL'):'';};a.addEventListener('change',sync);sync();});})();</script></body></html>




