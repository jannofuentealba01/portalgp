<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

$redirectQueryRaw = trim((string) ($_POST['volver_query'] ?? ''));
$redirectTarget = 'documentos_cobro/index.php';
if ($redirectQueryRaw !== '' && preg_match('/^[A-Za-z0-9_\-\.\[\]%=&]*$/', $redirectQueryRaw) === 1) {
    $redirectTarget .= '?' . $redirectQueryRaw;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    msp2Redirect($redirectTarget);
}

$idDocumento = filter_input(INPUT_POST, 'id_documento_cobro', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($idDocumento === false || $idDocumento === null) {
    msp2SetFlash('warning', 'Debes seleccionar un documento válido para condonar cargos.');
    msp2Redirect($redirectTarget);
}

$motivoCondonacion = mb_substr(msp2NormalizeText((string) ($_POST['motivo_condonacion'] ?? '')), 0, 500, 'UTF-8');
if ($motivoCondonacion === '') {
    msp2SetFlash('warning', 'Debes ingresar el motivo de la condonación.');
    msp2Redirect($redirectTarget);
}

$idsCargosInput = $_POST['ids_cargo_salida'] ?? [];
if (!is_array($idsCargosInput)) {
    $idsCargosInput = [];
}
$idsCargos = [];
foreach ($idsCargosInput as $idCargoRaw) {
    $idCargo = filter_var($idCargoRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($idCargo !== false && $idCargo !== null) {
        $idsCargos[(int) $idCargo] = true;
    }
}
$idsCargos = array_map('intval', array_keys($idsCargos));
if ($idsCargos === []) {
    msp2SetFlash('warning', 'Debes seleccionar al menos un cargo para condonar.');
    msp2Redirect($redirectTarget);
}

$usuarioId = isset($_SESSION['usuario']['id']) ? (int) $_SESSION['usuario']['id'] : 0;

try {
    if (!msp2TableExists($conn, 'msp_cargos_salida') || !msp2TableExists($conn, 'msp_tipos_cargo_salida')) {
        throw new RuntimeException('No existe configuración de cargos extra para aplicar condonación.');
    }

    $stmtDoc = $conn->prepare(
        'SELECT TOP 1 id_documento_cobro, estado_documento
         FROM dbo.msp_documentos_cobro
         WHERE id_documento_cobro = :id_documento'
    );
    $stmtDoc->bindValue(':id_documento', (int) $idDocumento, PDO::PARAM_INT);
    $stmtDoc->execute();
    $docRow = $stmtDoc->fetch();
    if ($docRow === false) {
        throw new RuntimeException('No existe el documento seleccionado.');
    }
    if ((int) ($docRow['estado_documento'] ?? 0) === 5) {
        throw new RuntimeException('No puedes condonar cargos sobre un documento anulado.');
    }

    $stmtItems = $conn->query(
        "SELECT codigo_item, id_tipo_item_documento
         FROM dbo.msp_tipo_item_documento
         WHERE codigo_item IN (N'MULTA', N'DANO', N'AJUSTE')"
    );
    $itemIds = [];
    while (($rowItem = $stmtItems->fetch()) !== false) {
        $codigo = strtoupper(trim((string) ($rowItem['codigo_item'] ?? '')));
        $idItem = (int) ($rowItem['id_tipo_item_documento'] ?? 0);
        if ($codigo !== '' && $idItem > 0) {
            $itemIds[$codigo] = $idItem;
        }
    }

    $cargoPlaceholders = [];
    foreach ($idsCargos as $index => $idCargo) {
        $cargoPlaceholders[] = ':id_cargo_' . $index;
    }

    $stmtCargos = $conn->prepare(
        "SELECT
            cs.id_cargo_salida,
            cs.monto_cargo,
            cs.descripcion_cargo,
            tc.codigo_tipo_cargo,
            loc.cdo_local
         FROM dbo.msp_cargos_salida cs
         INNER JOIN dbo.msp_tipos_cargo_salida tc
            ON tc.id_tipo_cargo_salida = cs.id_tipo_cargo_salida
         LEFT JOIN dbo.msp_locales loc
            ON loc.id_local = cs.id_local
         WHERE cs.id_documento_cobro = :id_documento
           AND cs.id_cargo_salida IN (" . implode(', ', $cargoPlaceholders) . ")
           AND cs.estado_cargo = 3
           AND cs.monto_cargo > 0
           AND UPPER(LTRIM(RTRIM(ISNULL(tc.codigo_tipo_cargo, N'')))) IN (N'MULTA', N'DANOS', N'OTRO')
         ORDER BY cs.id_cargo_salida ASC"
    );
    $stmtCargos->bindValue(':id_documento', (int) $idDocumento, PDO::PARAM_INT);
    foreach ($idsCargos as $index => $idCargo) {
        $stmtCargos->bindValue(':id_cargo_' . $index, $idCargo, PDO::PARAM_INT);
    }
    $stmtCargos->execute();
    $cargos = $stmtCargos->fetchAll() ?: [];
    if ($cargos === []) {
        throw new RuntimeException('Este documento no tiene cargos extra condonables.');
    }

    $conn->beginTransaction();

    $stmtZeroDetalle = $conn->prepare(
        "UPDATE TOP (1) dbo.msp_documentos_cobro_detalle
         SET
            valor_unitario = 0,
            subtotal = 0
         WHERE id_documento_cobro = :id_documento
           AND id_tipo_item_documento = :id_tipo_item
           AND descripcion_item = :descripcion_item
           AND ROUND(subtotal, 2) = ROUND(:monto_cargo, 2)
           AND subtotal > 0"
    );

    $stmtCancelarCargo = $conn->prepare(
        "DECLARE @motivo NVARCHAR(500) = :motivo;
         DECLARE @motivo_usuario NVARCHAR(500) = :motivo_usuario;
         UPDATE dbo.msp_cargos_salida
         SET
            estado_cargo = 5,
            observaciones = CASE
                WHEN observaciones IS NULL OR LTRIM(RTRIM(observaciones)) = '' THEN @motivo_usuario
                ELSE CONCAT(observaciones, N' | ', @motivo_usuario)
            END
         WHERE id_cargo_salida = :id_cargo
           AND id_documento_cobro = :id_documento
           AND estado_cargo = 3"
    );

    $detallesAjustados = 0;
    $cargosCondonados = 0;
    foreach ($cargos as $cargo) {
        $codigoTipoCargo = strtoupper(trim((string) ($cargo['codigo_tipo_cargo'] ?? '')));
        $idTipoItem = 0;
        if ($codigoTipoCargo === 'MULTA') {
            $idTipoItem = (int) ($itemIds['MULTA'] ?? $itemIds['AJUSTE'] ?? $itemIds['DANO'] ?? 0);
        } elseif ($codigoTipoCargo === 'DANOS') {
            $idTipoItem = (int) ($itemIds['DANO'] ?? $itemIds['AJUSTE'] ?? $itemIds['MULTA'] ?? 0);
        } else {
            $idTipoItem = (int) ($itemIds['AJUSTE'] ?? $itemIds['MULTA'] ?? $itemIds['DANO'] ?? 0);
        }

        if ($idTipoItem > 0) {
            $localCode = trim((string) ($cargo['cdo_local'] ?? ''));
            $descripcionCargo = trim((string) ($cargo['descripcion_cargo'] ?? ''));
            $descripcionBase = match ($codigoTipoCargo) {
                'MULTA' => 'Multa',
                'DANOS' => 'Daños/Reparaciones',
                default => 'Otro cargo',
            };
            if ($localCode !== '') {
                $descripcionBase .= ' local ' . $localCode;
            }
            $descripcionItem = $descripcionBase . ': ' . mb_substr($descripcionCargo, 0, 240, 'UTF-8');

            $stmtZeroDetalle->bindValue(':id_documento', (int) $idDocumento, PDO::PARAM_INT);
            $stmtZeroDetalle->bindValue(':id_tipo_item', $idTipoItem, PDO::PARAM_INT);
            $stmtZeroDetalle->bindValue(':descripcion_item', $descripcionItem, PDO::PARAM_STR);
            $stmtZeroDetalle->bindValue(':monto_cargo', (string) round((float) ($cargo['monto_cargo'] ?? 0), 2), PDO::PARAM_STR);
            $stmtZeroDetalle->execute();
            $detallesAjustados += max(0, (int) $stmtZeroDetalle->rowCount());
        }

        $motivoUsuario = 'Condonado [' . (new DateTimeImmutable('now'))->format('Y-m-d H:i:s') . '] por usuario #' . $usuarioId . ': ' . $motivoCondonacion;
        $stmtCancelarCargo->bindValue(':motivo', $motivoCondonacion, PDO::PARAM_STR);
        $stmtCancelarCargo->bindValue(':motivo_usuario', $motivoUsuario, PDO::PARAM_STR);
        $stmtCancelarCargo->bindValue(':id_cargo', (int) ($cargo['id_cargo_salida'] ?? 0), PDO::PARAM_INT);
        $stmtCancelarCargo->bindValue(':id_documento', (int) $idDocumento, PDO::PARAM_INT);
        $stmtCancelarCargo->execute();
        $cargosCondonados += max(0, (int) $stmtCancelarCargo->rowCount());
    }

    if ($cargosCondonados <= 0) {
        throw new RuntimeException('No fue posible condonar cargos del documento (ya fueron modificados por otro usuario).');
    }

    $stmtRecalc = $conn->prepare(
        "WITH resumen AS (
            SELECT
                dcd.id_documento_cobro,
                SUM(CASE WHEN tid.codigo_item = N'ARRIENDO' THEN dcd.subtotal ELSE 0 END) AS subtotal_arriendo,
                SUM(CASE WHEN tid.codigo_item <> N'ARRIENDO' THEN dcd.subtotal ELSE 0 END) AS subtotal_servicios
            FROM dbo.msp_documentos_cobro_detalle dcd
            INNER JOIN dbo.msp_tipo_item_documento tid
                ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
            WHERE dcd.id_documento_cobro = :id_documento
            GROUP BY dcd.id_documento_cobro
        ),
        pagos AS (
            SELECT
                p.id_documento_cobro,
                SUM(CASE WHEN p.estado_pago = 1 THEN p.monto_pagado ELSE 0 END) AS total_pagado
            FROM dbo.msp_pagos p
            WHERE p.id_documento_cobro = :id_documento_pagos
            GROUP BY p.id_documento_cobro
        )
        UPDATE dc
        SET
            dc.subtotal_arriendo = ROUND(ISNULL(r.subtotal_arriendo, 0), 2),
            dc.subtotal_servicios = ROUND(ISNULL(r.subtotal_servicios, 0), 2),
            dc.monto_total = ROUND((ISNULL(r.subtotal_arriendo, 0) * 1.19) + ISNULL(r.subtotal_servicios, 0), 2),
            dc.saldo_pendiente = CASE
                WHEN dc.estado_documento = 5 THEN dc.saldo_pendiente
                ELSE ROUND(
                    CASE
                        WHEN (((ISNULL(r.subtotal_arriendo, 0) * 1.19) + ISNULL(r.subtotal_servicios, 0)) - ISNULL(pg.total_pagado, 0)) < 0
                            THEN 0
                        ELSE (((ISNULL(r.subtotal_arriendo, 0) * 1.19) + ISNULL(r.subtotal_servicios, 0)) - ISNULL(pg.total_pagado, 0))
                    END,
                    2
                )
            END,
            dc.estado_documento = CASE
                WHEN dc.estado_documento = 5 THEN 5
                WHEN ISNULL(pg.total_pagado, 0) <= 0 THEN 2
                WHEN ISNULL(pg.total_pagado, 0) < ((ISNULL(r.subtotal_arriendo, 0) * 1.19) + ISNULL(r.subtotal_servicios, 0)) THEN 3
                ELSE 4
            END
        FROM dbo.msp_documentos_cobro dc
        LEFT JOIN resumen r
            ON r.id_documento_cobro = dc.id_documento_cobro
        LEFT JOIN pagos pg
            ON pg.id_documento_cobro = dc.id_documento_cobro
        WHERE dc.id_documento_cobro = :id_documento_update"
    );
    $stmtRecalc->bindValue(':id_documento', (int) $idDocumento, PDO::PARAM_INT);
    $stmtRecalc->bindValue(':id_documento_pagos', (int) $idDocumento, PDO::PARAM_INT);
    $stmtRecalc->bindValue(':id_documento_update', (int) $idDocumento, PDO::PARAM_INT);
    $stmtRecalc->execute();

    $conn->commit();

    msp2SetFlash(
        'success',
        'Condonación aplicada en documento #' . (int) $idDocumento
        . ': cargos condonados ' . $cargosCondonados . '/' . count($idsCargos)
        . ', detalles ajustados ' . $detallesAjustados . '.'
    );
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    msp2SetFlash(
        'danger',
        $e instanceof RuntimeException
            ? $e->getMessage()
            : 'No fue posible condonar cargos extra del documento.'
    );
}

msp2Redirect($redirectTarget);
