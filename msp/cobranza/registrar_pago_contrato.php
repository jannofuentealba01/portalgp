<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/mail_helper.php';
require_once dirname(__DIR__) . '/templates/components/searchable_select.php';
require_once __DIR__ . '/pago_contrato_import_helper.php';

msp2RequireAccess();

function rpcFmtRut(?string $rut): string
{
    $value = strtoupper(trim((string) $rut));
    if ($value === '') {
        return '';
    }

    $value = str_replace(['.', ' '], '', $value);
    if (!str_contains($value, '-')) {
        return $value;
    }

    [$num, $dv] = explode('-', $value, 2);
    $num = preg_replace('/\D+/', '', $num ?? '');
    $dv = strtoupper(trim((string) $dv));
    if ($num === '' || $dv === '') {
        return $value;
    }

    return number_format((int) $num, 0, '', '.') . '-' . $dv;
}

function rpcFmtMoney(float $value): string
{
    return '$ ' . number_format($value, 2, ',', '.');
}

function rpcFmtFecha(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }
    $value = substr($value, 0, 10);
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if ($date === false) {
        return $value;
    }
    return $date->format('d-m-Y');
}

function rpcFetchContratosConDeuda(PDO $conn): array
{
    $hasFechaTerminoEfectiva = msp2ColumnExists($conn, 'msp_contratos_arriendo', 'fecha_termino_efectiva');
    $hasFechaTerminoLocal = msp2ColumnExists($conn, 'msp_contrato_locales', 'fecha_termino');
    $hasContratoLocales = msp2TableExists($conn, 'msp_contrato_locales');

    $condicionTerminoContrato = $hasFechaTerminoEfectiva
        ? '(ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= dc.periodo_facturacion)'
        : '1 = 1';
    $condicionTerminoLocal = $hasFechaTerminoLocal
        ? '(cl.fecha_termino IS NULL OR cl.fecha_termino >= dc.periodo_facturacion)'
        : '1 = 1';
    $condicionExisteLocal = $hasContratoLocales
        ? "AND EXISTS (
                SELECT 1
                FROM dbo.msp_contrato_locales cl
                WHERE cl.id_contrato_arriendo = ca.id_contrato_arriendo
                  AND cl.estado_relacion IN (1,2)
                  AND cl.fecha_inicio <= EOMONTH(dc.periodo_facturacion)
                  AND $condicionTerminoLocal
            )"
        : '';

    $sql = "SELECT
                contrato_ref.id_contrato_arriendo,
                c.id_arrendatario,
                a.rut,
                COALESCE(NULLIF(a.nombre_locatario, ''), NULLIF(a.nombre_representante, ''), a.rut) AS nombre_arrendatario,
                COUNT(*) AS documentos_pendientes,
                ROUND(SUM(dc.saldo_pendiente), 2) AS saldo_total,
                MIN(CONVERT(CHAR(10), dc.periodo_facturacion, 126)) AS primer_periodo,
                MAX(CONVERT(CHAR(10), dc.periodo_facturacion, 126)) AS ultimo_periodo
            FROM dbo.msp_documentos_cobro dc
            INNER JOIN dbo.msp_tiendas t
                ON t.id_tienda = dc.id_tienda
            OUTER APPLY (
                SELECT TOP 1
                    ca.id_contrato_arriendo
                FROM dbo.msp_contratos_arriendo ca
                WHERE ca.id_tienda = dc.id_tienda
                  AND ca.fecha_inicio <= EOMONTH(dc.periodo_facturacion)
                  AND $condicionTerminoContrato
                  AND ca.estado_contrato IN (1,2,3)
                  $condicionExisteLocal
                ORDER BY ca.fecha_inicio DESC, ca.id_contrato_arriendo DESC
            ) contrato_vigente
            CROSS APPLY (
                SELECT COALESCE(dc.id_contrato_arriendo, contrato_vigente.id_contrato_arriendo) AS id_contrato_arriendo
            ) contrato_ref
            INNER JOIN dbo.msp_contratos_arriendo c
                ON c.id_contrato_arriendo = contrato_ref.id_contrato_arriendo
            INNER JOIN dbo.msp_arrendatarios a
                ON a.id_arrendatario = c.id_arrendatario
            WHERE dc.estado_documento IN (2,3)
              AND dc.saldo_pendiente > 0
              AND contrato_ref.id_contrato_arriendo IS NOT NULL
            GROUP BY
                contrato_ref.id_contrato_arriendo,
                c.id_arrendatario,
                a.rut,
                COALESCE(NULLIF(a.nombre_locatario, ''), NULLIF(a.nombre_representante, ''), a.rut)
            ORDER BY
                COALESCE(NULLIF(a.nombre_locatario, ''), NULLIF(a.nombre_representante, ''), a.rut) ASC,
                contrato_ref.id_contrato_arriendo ASC";

    $stmt = $conn->query($sql);
    return $stmt ? ($stmt->fetchAll() ?: []) : [];
}

function rpcFetchLocalesByContrato(PDO $conn, array $contratoIds): array
{
    if ($contratoIds === [] || !msp2TableExists($conn, 'msp_contrato_locales') || !msp2TableExists($conn, 'msp_locales')) {
        return [];
    }

    $contratoIds = array_values(array_unique(array_filter(array_map('intval', $contratoIds), static fn(int $id): bool => $id > 0)));
    if ($contratoIds === []) {
        return [];
    }

    $placeholders = [];
    foreach ($contratoIds as $i => $idContrato) {
        $placeholders[] = ':id_' . $i;
    }

    $sql = "SELECT
                cl.id_contrato_arriendo,
                l.cdo_local
            FROM dbo.msp_contrato_locales cl
            INNER JOIN dbo.msp_locales l
                ON l.id_local = cl.id_local
            WHERE cl.id_contrato_arriendo IN (" . implode(', ', $placeholders) . ")
              AND cl.estado_relacion IN (1,2)
            ORDER BY cl.id_contrato_arriendo ASC, " . msp2LocalCodeNaturalOrderSql('l.cdo_local');

    $stmt = $conn->prepare($sql);
    foreach ($contratoIds as $i => $idContrato) {
        $stmt->bindValue(':id_' . $i, $idContrato, PDO::PARAM_INT);
    }
    $stmt->execute();

    $map = [];
    while (($row = $stmt->fetch()) !== false) {
        $idContrato = (int) ($row['id_contrato_arriendo'] ?? 0);
        $localCode = trim((string) ($row['cdo_local'] ?? ''));
        if ($idContrato <= 0 || $localCode === '') {
            continue;
        }
        if (!isset($map[$idContrato])) {
            $map[$idContrato] = [];
        }
        if (!in_array($localCode, $map[$idContrato], true)) {
            $map[$idContrato][] = $localCode;
        }
    }

    foreach ($map as $idContrato => $locales) {
        usort($locales, static fn(string $a, string $b): int => msp2CompareLocalCode($a, $b));
        $map[$idContrato] = $locales;
    }

    return $map;
}

function rpcFetchDocumentosDeudaContrato(PDO $conn, int $idContratoArriendo): array
{
    $hasFechaTerminoEfectiva = msp2ColumnExists($conn, 'msp_contratos_arriendo', 'fecha_termino_efectiva');
    $hasFechaTerminoLocal = msp2ColumnExists($conn, 'msp_contrato_locales', 'fecha_termino');
    $hasContratoLocales = msp2TableExists($conn, 'msp_contrato_locales');

    $condicionTerminoContrato = $hasFechaTerminoEfectiva
        ? '(ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= dc.periodo_facturacion)'
        : '1 = 1';
    $condicionTerminoLocal = $hasFechaTerminoLocal
        ? '(cl.fecha_termino IS NULL OR cl.fecha_termino >= dc.periodo_facturacion)'
        : '1 = 1';
    $condicionExisteLocal = $hasContratoLocales
        ? "AND EXISTS (
                SELECT 1
                FROM dbo.msp_contrato_locales cl
                WHERE cl.id_contrato_arriendo = ca.id_contrato_arriendo
                  AND cl.estado_relacion IN (1,2)
                  AND cl.fecha_inicio <= EOMONTH(dc.periodo_facturacion)
                  AND $condicionTerminoLocal
            )"
        : '';

    $sql = "SELECT
                dc.id_documento_cobro,
                COALESCE(NULLIF(dc.numero_documento, ''), CONCAT(N'DOC-', dc.id_documento_cobro)) AS numero_documento,
                CONVERT(CHAR(10), dc.periodo_facturacion, 126) AS periodo_facturacion,
                CONVERT(CHAR(10), dc.fecha_vencimiento, 126) AS fecha_vencimiento,
                dc.saldo_pendiente,
                COALESCE(NULLIF(t.nombre_comercial, ''), CONCAT(N'Tienda #', t.id_tienda)) AS nombre_tienda
            FROM dbo.msp_documentos_cobro dc
            INNER JOIN dbo.msp_tiendas t
                ON t.id_tienda = dc.id_tienda
            OUTER APPLY (
                SELECT TOP 1
                    ca.id_contrato_arriendo
                FROM dbo.msp_contratos_arriendo ca
                WHERE ca.id_tienda = dc.id_tienda
                  AND ca.fecha_inicio <= EOMONTH(dc.periodo_facturacion)
                  AND $condicionTerminoContrato
                  AND ca.estado_contrato IN (1,2,3)
                  $condicionExisteLocal
                ORDER BY ca.fecha_inicio DESC, ca.id_contrato_arriendo DESC
            ) contrato_vigente
            WHERE dc.estado_documento IN (2,3)
              AND dc.saldo_pendiente > 0
              AND COALESCE(dc.id_contrato_arriendo, contrato_vigente.id_contrato_arriendo) = :id_contrato_arriendo
            ORDER BY
                dc.periodo_facturacion ASC,
                ISNULL(dc.fecha_vencimiento, dc.periodo_facturacion) ASC,
                dc.id_documento_cobro ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll() ?: [];
}

function rpcPdfDownloadLabel(array $item): string
{
    $type = trim((string) ($item['type'] ?? ''));
    $pagoData = is_array($item['pago_data'] ?? null) ? $item['pago_data'] : [];
    $arrData = is_array($item['arr_data'] ?? null) ? $item['arr_data'] : [];
    $docData = is_array($item['doc_data'] ?? null) ? $item['doc_data'] : [];

    $labelParts = [
        $type === 'comprobante_gastos' ? 'Comprobante de gastos' : 'Vale de pago',
    ];
    foreach ([
        (string) ($docData['periodo_ym'] ?? ''),
        (string) ($arrData['nombre_arrendatario'] ?? ''),
        (string) ($docData['locales_contrato'] ?? ''),
        (string) ($docData['numero_documento'] ?? ''),
    ] as $part) {
        $part = trim($part);
        if ($part !== '') {
            $labelParts[] = $part;
        }
    }

    $idPago = (int) ($pagoData['id_pago'] ?? 0);
    if ($idPago > 0) {
        $labelParts[] = 'Pago #' . $idPago;
    }

    return implode(' | ', $labelParts);
}

$flash = msp2PullFlash();
$toastFlash = null;
if (is_array($flash)) {
    $toastFlash = $flash;
    $flash = null;
}

$loadError = null;
$contratosConDeuda = [];
$localesByContrato = [];
$arrendatarioOptions = [];
$contratoOptions = [];
$documentosDeuda = [];
$saldoFavorDisponible = 0.0;
$tablaBancosExiste = false;
$bancosDisponibles = [];
$contextoContratoDirecto = null;
$bloquearContextoContrato = false;

$idArrendatario = filter_input(INPUT_GET, 'id_arrendatario', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$idContratoArriendo = filter_input(INPUT_GET, 'id_contrato_arriendo', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$idContratoCorto = filter_input(INPUT_GET, 'id_contrato', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if (($idContratoArriendo === false || $idContratoArriendo === null) && $idContratoCorto !== false && $idContratoCorto !== null) {
    $idContratoArriendo = $idContratoCorto;
}
$bloquearContextoContrato = (string) ($_GET['contexto_contrato'] ?? '') === '1'
    && $idContratoArriendo !== false
    && $idContratoArriendo !== null;
$returnTo = trim((string) ($_GET['return_to'] ?? ''));
if ($returnTo !== '' && preg_match('#^cobranza/gestionar\.php\?id_contrato=\d+(?:&return_to=[A-Za-z0-9_\-\.\[%\]=&]*)?$#', $returnTo) !== 1) {
    $returnTo = '';
}

if (($idArrendatario === false || $idArrendatario === null) && $idContratoArriendo !== false && $idContratoArriendo !== null) {
    try {
        $stmtContextoContrato = $conn->prepare('SELECT id_arrendatario FROM dbo.msp_contratos_arriendo WHERE id_contrato_arriendo=:id');
        $stmtContextoContrato->execute([':id' => (int) $idContratoArriendo]);
        $idArrendatarioInferido = (int) ($stmtContextoContrato->fetchColumn() ?: 0);
        if ($idArrendatarioInferido > 0) {
            $idArrendatario = $idArrendatarioInferido;
        }
    } catch (Throwable) {
        // La validación normal del módulo mostrará el contexto faltante.
    }
}

try {
    $requiredTables = [
        'msp_documentos_cobro',
        'msp_pagos',
        'msp_contratos_arriendo',
        'msp_arrendatarios',
        'msp_tiendas',
    ];
    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            throw new RuntimeException('Falta la tabla requerida `' . $tableName . '`.');
        }
    }
    $tablaBancosExiste = msp2TableExists($conn, 'msp_bancos');

    $contratosConDeuda = rpcFetchContratosConDeuda($conn);
    $localesByContrato = rpcFetchLocalesByContrato(
        $conn,
        array_map(static fn(array $row): int => (int) ($row['id_contrato_arriendo'] ?? 0), $contratosConDeuda)
    );
    if ($bloquearContextoContrato) {
        $stmtContextoDirecto = $conn->prepare(
            "SELECT c.id_contrato_arriendo, c.id_arrendatario, a.rut,
                    COALESCE(NULLIF(a.nombre_locatario, ''), NULLIF(a.nombre_representante, ''), a.rut) AS nombre_arrendatario
             FROM dbo.msp_contratos_arriendo c
             INNER JOIN dbo.msp_arrendatarios a ON a.id_arrendatario = c.id_arrendatario
             WHERE c.id_contrato_arriendo = :id_contrato"
        );
        $stmtContextoDirecto->execute([':id_contrato' => (int) $idContratoArriendo]);
        $contextoContratoDirecto = $stmtContextoDirecto->fetch() ?: null;
        if (!is_array($contextoContratoDirecto)) {
            $bloquearContextoContrato = false;
        } else {
            $idArrendatario = (int) ($contextoContratoDirecto['id_arrendatario'] ?? 0);
            $localesByContrato += rpcFetchLocalesByContrato($conn, [(int) $idContratoArriendo]);
        }
    }
    if ($tablaBancosExiste) {
        $stmtBancos = $conn->query(
            "SELECT id_banco, nombre_banco, codigo_banco
             FROM dbo.msp_bancos
             WHERE activo = 1
             ORDER BY nombre_banco ASC"
        );
        $bancosDisponibles = $stmtBancos ? ($stmtBancos->fetchAll() ?: []) : [];
    }

    $contratosByArrendatario = [];
    foreach ($contratosConDeuda as $row) {
        $arrId = (int) ($row['id_arrendatario'] ?? 0);
        $contratoId = (int) ($row['id_contrato_arriendo'] ?? 0);
        if ($arrId <= 0 || $contratoId <= 0) {
            continue;
        }
        if (!isset($contratosByArrendatario[$arrId])) {
            $contratosByArrendatario[$arrId] = [];
        }
        $contratosByArrendatario[$arrId][] = $row;
    }

    $arrendatariosSeen = [];
    foreach ($contratosConDeuda as $row) {
        $arrId = (int) ($row['id_arrendatario'] ?? 0);
        if ($arrId <= 0 || isset($arrendatariosSeen[$arrId])) {
            continue;
        }
        $arrendatariosSeen[$arrId] = true;
        $rut = rpcFmtRut((string) ($row['rut'] ?? ''));
        $nombre = trim((string) ($row['nombre_arrendatario'] ?? ''));

        $localesArr = [];
        foreach ($contratosByArrendatario[$arrId] ?? [] as $cRow) {
            $cId = (int) ($cRow['id_contrato_arriendo'] ?? 0);
            foreach ($localesByContrato[$cId] ?? [] as $codigoLocal) {
                if (!in_array($codigoLocal, $localesArr, true)) {
                    $localesArr[] = $codigoLocal;
                }
            }
        }
        usort($localesArr, static fn(string $a, string $b): int => msp2CompareLocalCode($a, $b));
        $localesLabel = $localesArr !== [] ? (' (' . implode(', ', $localesArr) . ')') : '';

        $label = '(' . $rut . ') ' . $nombre . $localesLabel;
        $arrendatarioOptions[] = [
            'value' => (string) $arrId,
            'label' => $label,
            'search' => mb_strtolower($rut . ' ' . $nombre . ' ' . implode(' ', $localesArr), 'UTF-8'),
        ];
    }

    if (is_array($contextoContratoDirecto)) {
        $arrIdContexto = (int) ($contextoContratoDirecto['id_arrendatario'] ?? 0);
        if ($arrIdContexto > 0 && !isset($arrendatariosSeen[$arrIdContexto])) {
            $rutContexto = rpcFmtRut((string) ($contextoContratoDirecto['rut'] ?? ''));
            $nombreContexto = trim((string) ($contextoContratoDirecto['nombre_arrendatario'] ?? ''));
            $localesContexto = $localesByContrato[(int) $idContratoArriendo] ?? [];
            $arrendatarioOptions[] = [
                'value' => (string) $arrIdContexto,
                'label' => '(' . $rutContexto . ') ' . $nombreContexto . ($localesContexto !== [] ? ' (' . implode(', ', $localesContexto) . ')' : ''),
                'search' => mb_strtolower($rutContexto . ' ' . $nombreContexto . ' ' . implode(' ', $localesContexto), 'UTF-8'),
            ];
        }
    }

    usort(
        $arrendatarioOptions,
        static function (array $a, array $b) use ($contratosByArrendatario, $localesByContrato): int {
            $arrIdA = (int) ($a['value'] ?? 0);
            $arrIdB = (int) ($b['value'] ?? 0);

            $collectLocales = static function (int $arrId) use ($contratosByArrendatario, $localesByContrato): array {
                $locales = [];
                foreach ($contratosByArrendatario[$arrId] ?? [] as $rowContrato) {
                    $contratoId = (int) ($rowContrato['id_contrato_arriendo'] ?? 0);
                    foreach ($localesByContrato[$contratoId] ?? [] as $codigoLocal) {
                        if (!in_array($codigoLocal, $locales, true)) {
                            $locales[] = $codigoLocal;
                        }
                    }
                }
                if ($locales !== []) {
                    usort($locales, static fn(string $x, string $y): int => msp2CompareLocalCode($x, $y));
                }
                return $locales;
            };

            $localesA = $collectLocales($arrIdA);
            $localesB = $collectLocales($arrIdB);
            $aTieneLocales = $localesA !== [];
            $bTieneLocales = $localesB !== [];
            if ($aTieneLocales !== $bTieneLocales) {
                return $aTieneLocales ? -1 : 1;
            }

            if ($aTieneLocales && $bTieneLocales) {
                $minLen = min(count($localesA), count($localesB));
                for ($i = 0; $i < $minLen; $i++) {
                    $cmpLocal = msp2CompareLocalCode((string) $localesA[$i], (string) $localesB[$i]);
                    if ($cmpLocal !== 0) {
                        return $cmpLocal;
                    }
                }

                $cmpCantidad = count($localesA) <=> count($localesB);
                if ($cmpCantidad !== 0) {
                    return $cmpCantidad;
                }
            }

            return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
        }
    );

    if ($idArrendatario !== false && $idArrendatario !== null && isset($contratosByArrendatario[(int) $idArrendatario])) {
        $contratosOrdenados = $contratosByArrendatario[(int) $idArrendatario];
        usort(
            $contratosOrdenados,
            static function (array $a, array $b) use ($localesByContrato): int {
                $idContratoA = (int) ($a['id_contrato_arriendo'] ?? 0);
                $idContratoB = (int) ($b['id_contrato_arriendo'] ?? 0);
                $localesA = $localesByContrato[$idContratoA] ?? [];
                $localesB = $localesByContrato[$idContratoB] ?? [];

                $aTieneLocales = $localesA !== [];
                $bTieneLocales = $localesB !== [];
                if ($aTieneLocales !== $bTieneLocales) {
                    return $aTieneLocales ? -1 : 1;
                }

                if ($aTieneLocales && $bTieneLocales) {
                    $minLen = min(count($localesA), count($localesB));
                    for ($i = 0; $i < $minLen; $i++) {
                        $cmpLocal = msp2CompareLocalCode((string) $localesA[$i], (string) $localesB[$i]);
                        if ($cmpLocal !== 0) {
                            return $cmpLocal;
                        }
                    }

                    $cmpCantidad = count($localesA) <=> count($localesB);
                    if ($cmpCantidad !== 0) {
                        return $cmpCantidad;
                    }
                }

                return $idContratoA <=> $idContratoB;
            }
        );

        foreach ($contratosOrdenados as $row) {
            $contratoId = (int) ($row['id_contrato_arriendo'] ?? 0);
            if ($contratoId <= 0) {
                continue;
            }

            $locales = $localesByContrato[$contratoId] ?? [];
            $localesLabel = $locales !== [] ? (' (' . implode(', ', $locales) . ')') : '';
            $docsPend = (int) ($row['documentos_pendientes'] ?? 0);
            $saldoTotal = round((float) ($row['saldo_total'] ?? 0), 2);
            $primerPeriodo = substr((string) ($row['primer_periodo'] ?? ''), 0, 7);
            $ultimoPeriodo = substr((string) ($row['ultimo_periodo'] ?? ''), 0, 7);
            $rangoPeriodo = $primerPeriodo !== '' && $ultimoPeriodo !== ''
                ? ($primerPeriodo === $ultimoPeriodo ? $primerPeriodo : ($primerPeriodo . ' a ' . $ultimoPeriodo))
                : '-';

            $label = '#'
                . $contratoId
                . $localesLabel
                . ' | Docs: ' . number_format($docsPend, 0, ',', '.')
                . ' | Saldo: ' . rpcFmtMoney($saldoTotal)
                . ' | ' . $rangoPeriodo;

            $contratoOptions[] = [
                'value' => (string) $contratoId,
                'label' => $label,
                'search' => mb_strtolower(
                    (string) $contratoId . ' ' . implode(' ', $locales) . ' ' . $rangoPeriodo . ' ' . number_format($saldoTotal, 2, '.', ''),
                    'UTF-8'
                ),
            ];
        }
    } elseif ($idArrendatario !== false && $idArrendatario !== null && !$bloquearContextoContrato) {
        $idContratoArriendo = null;
    }

    if ($bloquearContextoContrato && is_array($contextoContratoDirecto) && $idContratoArriendo !== false && $idContratoArriendo !== null) {
        $contratoEstaEnOpciones = false;
        foreach ($contratoOptions as $option) {
            if ((int) ($option['value'] ?? 0) === (int) $idContratoArriendo) {
                $contratoEstaEnOpciones = true;
                break;
            }
        }
        if (!$contratoEstaEnOpciones) {
            $localesContexto = $localesByContrato[(int) $idContratoArriendo] ?? [];
            $contratoOptions[] = [
                'value' => (string) (int) $idContratoArriendo,
                'label' => '#' . (int) $idContratoArriendo . ($localesContexto !== [] ? ' (' . implode(', ', $localesContexto) . ')' : '') . ' | Sin deuda pendiente',
                'search' => (string) (int) $idContratoArriendo . ' ' . implode(' ', $localesContexto),
            ];
        }
    }

    $contratoValido = false;
    if ($idContratoArriendo !== false && $idContratoArriendo !== null) {
        foreach ($contratoOptions as $option) {
            if ((int) $option['value'] === (int) $idContratoArriendo) {
                $contratoValido = true;
                break;
            }
        }
    }

    if ($contratoValido) {
        $documentosDeuda = rpcFetchDocumentosDeudaContrato($conn, (int) $idContratoArriendo);
        if (msp2TableExists($conn, 'msp_saldos_favor_tienda')) {
            $stmtSaldoFavor = $conn->prepare(
                'SELECT ISNULL(sf.saldo_disponible,0)
                 FROM dbo.msp_contratos_arriendo c
                 LEFT JOIN dbo.msp_saldos_favor_tienda sf ON sf.id_tienda=c.id_tienda
                 WHERE c.id_contrato_arriendo=:id_contrato'
            );
            $stmtSaldoFavor->execute([':id_contrato' => (int) $idContratoArriendo]);
            $saldoFavorDisponible = round((float) ($stmtSaldoFavor->fetchColumn() ?: 0), 2);
        }
    } else {
        $idContratoArriendo = null;
    }
} catch (Throwable $exception) {
    $loadError = 'No fue posible cargar el módulo de pago por contrato.';
}

$totalDeudaContrato = array_reduce($documentosDeuda, static function (float $carry, array $row): float {
    return round($carry + round((float) ($row['saldo_pendiente'] ?? 0), 2), 2);
}, 0.0);
$importAdminEnabled = rpcPagoContratoImportIsAdminUser($conn);
$importPreview = rpcPagoContratoImportPreviewRead();
$importPreviewRows = is_array($importPreview) ? (array) ($importPreview['rows'] ?? []) : [];
$importPreviewSummary = is_array($importPreview) ? rpcPagoContratoImportSummary($importPreview) : null;
if (!$importAdminEnabled) {
    if (is_array($importPreview)) {
        rpcPagoContratoImportPreviewClear();
    }
    $importPreview = null;
    $importPreviewRows = [];
    $importPreviewSummary = null;
}
$queryBase = $_GET;
unset($queryBase['pdf_download_token']);
$correoDemoConfigRaw = trim((string) (mspMailConfig()['demo']['to'] ?? ''));
$correoDemoConfig = filter_var($correoDemoConfigRaw, FILTER_VALIDATE_EMAIL) !== false ? $correoDemoConfigRaw : '';
$modoCorreoDemoActivo = $correoDemoConfig !== '';
$envioArrendatariosHabilitado = msp2MailTenantDeliveryEnabled($conn);
$localesContratoSeleccionado = [];
if ($idContratoArriendo !== false && $idContratoArriendo !== null) {
    $localesContratoSeleccionado = $localesByContrato[(int) $idContratoArriendo] ?? [];
}
$localesContratoSeleccionadoJson = json_encode(
    array_values(array_filter($localesContratoSeleccionado, static fn($value): bool => trim((string) $value) !== '')),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
);
if (!is_string($localesContratoSeleccionadoJson) || $localesContratoSeleccionadoJson === '') {
    $localesContratoSeleccionadoJson = '[]';
}
$documentosJson = json_encode(array_map(static function (array $row): array {
    return [
        'id_documento_cobro' => (int) ($row['id_documento_cobro'] ?? 0),
        'numero_documento' => (string) ($row['numero_documento'] ?? ''),
        'periodo_facturacion' => (string) ($row['periodo_facturacion'] ?? ''),
        'fecha_vencimiento' => (string) ($row['fecha_vencimiento'] ?? ''),
        'saldo_pendiente' => round((float) ($row['saldo_pendiente'] ?? 0), 2),
        'nombre_tienda' => (string) ($row['nombre_tienda'] ?? ''),
    ];
}, $documentosDeuda), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
if (!is_string($documentosJson)) {
    $documentosJson = '[]';
}

$pdfDownloadToken = trim((string) ($_GET['pdf_download_token'] ?? ''));
$pdfDownloadUrls = [];
if ($pdfDownloadToken !== '' && preg_match('/^[a-f0-9]{32}$/', $pdfDownloadToken) === 1) {
    $sessionKey = 'msp2_pago_contrato_pdf_downloads';
    $store = $_SESSION[$sessionKey] ?? [];
    if (is_array($store)) {
        $now = time();
        foreach ($store as $token => $batch) {
            if (!is_array($batch) || (int) ($batch['expires_at'] ?? 0) < $now) {
                unset($_SESSION[$sessionKey][$token]);
            }
        }
        $batch = $_SESSION[$sessionKey][$pdfDownloadToken] ?? null;
        $items = is_array($batch['items'] ?? null) ? $batch['items'] : [];
        if (is_array($batch) && (int) ($batch['expires_at'] ?? 0) >= $now && $items !== []) {
            foreach (array_values($items) as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $pdfDownloadUrls[] = [
                    'url' => msp2Url('pagos/descargar_pago_contrato_pdf.php?' . http_build_query([
                        'token' => $pdfDownloadToken,
                        'i' => $index,
                    ])),
                    'label' => rpcPdfDownloadLabel($item),
                ];
            }
        }
    }
}
$pdfDownloadUrlsJson = json_encode($pdfDownloadUrls, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
if (!is_string($pdfDownloadUrlsJson)) {
    $pdfDownloadUrlsJson = '[]';
}

$fechaPagoMinima = '2025-12-31';
$fechaPagoMaxima = date('Y-m-d');
$fechaPagoDefault = $fechaPagoMaxima >= $fechaPagoMinima ? $fechaPagoMaxima : $fechaPagoMinima;
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Pago por Contrato</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
    <style>
        .pc-required-mark {
            color: #dc3545;
            margin-left: 0.15rem;
        }
        .pc-optional-mark {
            color: #6c757d;
            font-size: 0.85em;
            font-weight: 400;
            margin-left: 0.2rem;
        }
        .pc-success-flight {
            position: fixed;
            top: 1rem;
            left: 0;
            width: 100%;
            z-index: 2100;
            pointer-events: none;
            overflow: hidden;
        }
        .pc-success-flight__plane {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            color: #0b3ea8;
            background: rgba(255, 255, 255, 0.96);
            border: 1px solid #bfdbfe;
            border-radius: 999px;
            box-shadow: 0 12px 24px rgba(30, 64, 175, 0.22);
            padding: 0.45rem 0.8rem;
            font-size: 0.95rem;
            font-weight: 600;
            transform: translateX(-120%);
            animation: pc-success-flight-move 1.9s cubic-bezier(.2,.7,.2,1) forwards;
        }
        .pc-success-flight__icon {
            font-size: 1.05rem;
            transform: rotate(-14deg);
        }
        @keyframes pc-success-flight-move {
            0% { transform: translateX(-120%); opacity: 0; }
            12% { opacity: 1; }
            55% { transform: translateX(36vw); opacity: 1; }
            100% { transform: translateX(110vw); opacity: 0; }
        }
        .msp-mail-sending-overlay {
            position: fixed;
            inset: 0;
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(1.5px);
        }
        .msp-mail-sending-box {
            min-width: 250px;
            max-width: 92vw;
            border-radius: 0.85rem;
            border: 1px solid #dbe4f0;
            background: #fff;
            box-shadow: 0 16px 42px rgba(15, 23, 42, 0.18);
            padding: 1rem 1.15rem;
            text-align: center;
        }
        .msp-mail-sending-plane {
            display: inline-block;
            font-size: 1.65rem;
            color: #1d4ed8;
            animation: msp-mail-plane-fly 1.2s ease-in-out infinite;
            transform-origin: center;
        }
        .msp-mail-sending-text {
            margin-top: 0.4rem;
            color: #1f2937;
            font-weight: 600;
            font-size: 0.95rem;
        }
        @keyframes msp-mail-plane-fly {
            0% { transform: translateX(-10px) translateY(2px) rotate(-16deg); opacity: .72; }
            45% { transform: translateX(10px) translateY(-3px) rotate(12deg); opacity: 1; }
            100% { transform: translateX(-10px) translateY(2px) rotate(-16deg); opacity: .72; }
        }
        body.msp-mail-sending-open {
            overflow: hidden;
        }
        #pc_banco_dropdown_btn {
            color: #1f2937;
            background: #fff;
            border-color: #ced4da;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            padding-right: 2rem;
        }
        #pc_banco_dropdown_btn.show,
        #pc_banco_picker.show #pc_banco_dropdown_btn,
        #pc_banco_dropdown_btn:focus,
        #pc_banco_dropdown_btn:focus-visible {
            color: #1f2937;
            background: #fff;
            border-color: #86b7fe;
        }
        #pc_banco_picker .dropdown-menu {
            z-index: 2000;
        }
        #pc_banco_dropdown_list .list-group-item {
            display: block;
            font-size: 0.98rem;
            line-height: 1.3;
            padding: 0.5rem 0.75rem;
            min-height: 2.2rem;
            color: #1f2937;
            background: #fff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        #pc_banco_dropdown_list .list-group-item.active,
        #pc_banco_dropdown_list .list-group-item:active {
            color: #fff;
            background-color: #0d6efd;
            border-color: #0d6efd;
        }
        #form_pago_contrato.pc-banco-dropdown-open {
            overflow: visible;
        }
        #form_pago_contrato.pc-banco-dropdown-open .modal-body {
            overflow-y: visible;
        }
    </style>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/driver.js@1.3.6/dist/driver.css">
    <?php msp2RenderSearchableSelectAssets(); ?>
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<?php msp2RenderCsrfAutoFieldScript(); ?>
<main class="gp-main d-flex align-items-center justify-content-center p-4">
    <div class="box-container-wide" data-tour="rpc-root">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <a href="<?php echo msp2Escape($returnTo !== '' ? msp2Url($returnTo) : msp2Url('msp_menu.php')); ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i><?php echo $returnTo !== '' ? 'Volver a Gestión de Cobranza' : 'Volver a MSP'; ?>
            </a>
            <div class="d-flex gap-2">
                <a href="<?php echo msp2Escape(msp2Url('pagos/archivos_pdf.php')); ?>" class="btn btn-outline-dark btn-sm">
                    <i class="bi bi-archive me-1" aria-hidden="true"></i>Respaldo PDFs
                </a>
                <button type="button" class="btn btn-success btn-sm" id="rpc_start_demo_btn" data-tour="rpc-start-demo">
                    <i class="bi bi-magic me-1" aria-hidden="true"></i>Demo guiada
                </button>
            </div>
        </div>

        <p class="section-kicker text-center">MSP / Cobranza</p>
        <h1 class="form-title text-center mb-2">Pago por contrato</h1>
        <p class="text-muted text-center mb-4">Ingresa un pago único y el sistema lo distribuye por antigüedad entre documentos pendientes del contrato.</p>

        <?php include dirname(__DIR__) . '/templates/components/flash_toast.php'; ?>
        <?php if (is_array($toastFlash) && (($toastFlash['type'] ?? '') === 'success')): ?>
            <div class="pc-success-flight" id="pc_success_flight" aria-hidden="true">
                <div class="pc-success-flight__plane">
                    <i class="bi bi-send-fill pc-success-flight__icon"></i>
                    <span>Pago registrado con éxito</span>
                    <i class="bi bi-check-circle-fill text-success"></i>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($importAdminEnabled): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                        <div>
                            <h2 class="h6 mb-1">Importar pagos por contrato</h2>
                            <p class="text-muted mb-0 small">Columnas requeridas: Arrendatario, Contrato, Monto, Fecha, Medio de pago. Opcionales: Ref, Banco (si cheque).</p>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="<?php echo msp2Escape(msp2Url('cobranza/plantilla_pagos_contrato.php')); ?>" class="btn btn-success btn-sm">
                                <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Descargar plantilla Excel
                            </a>
                        <?php if (is_array($importPreview)): ?>
                            <form method="post" action="<?php echo msp2Escape(msp2Url('cobranza/descartar_importacion_pagos_contrato.php')); ?>">
                                <button type="submit" class="btn btn-outline-secondary btn-sm">
                                    <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Descartar preview
                                </button>
                            </form>
                        <?php endif; ?>
                        </div>
                    </div>

                    <form method="post" action="<?php echo msp2Escape(msp2Url('cobranza/importar_pagos_contrato.php')); ?>" enctype="multipart/form-data" class="row g-2 align-items-end">
                        <input type="hidden" name="volver_query" value="<?php echo msp2Escape(http_build_query($queryBase)); ?>">
                        <div class="col-12 col-lg-8">
                            <label for="rpc_excel_file" class="form-label">Archivo de importación</label>
                            <input type="file" class="form-control" id="rpc_excel_file" name="excel_file" accept=".xlsx,.xls,.csv" required>
                        </div>
                        <div class="col-12 col-lg-4">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-upload me-1" aria-hidden="true"></i>Previsualizar importación
                            </button>
                        </div>
                    </form>

                    <?php if (is_array($importPreview) && is_array($importPreviewSummary)): ?>
                        <hr class="my-3">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                            <div class="small text-muted">
                                Archivo: <strong><?php echo msp2Escape((string) ($importPreview['original_name'] ?? 'importacion.xlsx')); ?></strong><br>
                                Filas OK: <strong><?php echo (int) ($importPreviewSummary['ok_rows'] ?? 0); ?></strong> |
                                Errores: <strong><?php echo (int) ($importPreviewSummary['error_rows'] ?? 0); ?></strong>
                            </div>
                            <div class="small text-muted">
                                Total filas OK: <strong><?php echo msp2Escape(rpcFmtMoney((float) ($importPreviewSummary['total_monto'] ?? 0))); ?></strong>
                            </div>
                        </div>
                        <?php if ($importPreviewRows !== []): ?>
                            <div class="table-responsive mb-2">
                                <table class="table table-sm table-bordered align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Estado</th>
                                            <th>Fila</th>
                                            <th>Arrendatario</th>
                                            <th>Contrato</th>
                                            <th>Fecha</th>
                                            <th class="text-end">Monto</th>
                                            <th>Medio</th>
                                            <th>Resultado</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($importPreviewRows as $row): ?>
                                            <tr>
                                                <td>
                                                    <span class="badge <?php echo (($row['status'] ?? '') === 'OK') ? 'text-bg-success' : 'text-bg-warning'; ?>">
                                                        <?php echo msp2Escape((string) ($row['status'] ?? 'ERROR')); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo (int) ($row['row_number'] ?? 0); ?></td>
                                                <td><?php echo msp2Escape((string) ($row['arrendatario_raw'] ?? '')); ?></td>
                                                <td>#<?php echo (int) ($row['id_contrato_arriendo'] ?? 0); ?></td>
                                                <td><?php echo msp2Escape(rpcFmtFecha((string) ($row['fecha_pago'] ?? ''))); ?></td>
                                                <td class="text-end"><?php echo msp2Escape(rpcFmtMoney((float) ($row['monto_pagado'] ?? 0))); ?></td>
                                                <td><?php echo msp2Escape((string) ($row['medio_pago'] ?? '')); ?></td>
                                                <td>
                                                    <?php if (($row['status'] ?? '') === 'OK'): ?>
                                                        <span class="text-success">Listo para importar</span>
                                                    <?php else: ?>
                                                        <span class="text-warning"><?php echo msp2Escape((string) ($row['error'] ?? 'Error de validación.')); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                        <div class="d-flex flex-wrap gap-2">
                            <form method="post" action="<?php echo msp2Escape(msp2Url('cobranza/confirmar_importacion_pagos_contrato.php')); ?>">
                                <button type="submit" class="btn btn-success" <?php echo ((int) ($importPreviewSummary['ok_rows'] ?? 0) <= 0) ? 'disabled' : ''; ?>>
                                    <i class="bi bi-check-circle me-1" aria-hidden="true"></i>Confirmar importación
                                </button>
                            </form>
                            <form method="post" action="<?php echo msp2Escape(msp2Url('cobranza/descartar_importacion_pagos_contrato.php')); ?>">
                                <button type="submit" class="btn btn-outline-secondary">
                                    <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Descartar
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($loadError !== null): ?>
            <div class="alert alert-danger"><?php echo msp2Escape($loadError); ?></div>
        <?php endif; ?>

        <div class="card mb-3">
            <div class="card-body">
                <?php if ($contratosConDeuda === [] && !$bloquearContextoContrato): ?>
                    <div class="alert alert-info mb-0">No hay contratos con deuda pendiente para operar.</div>
                <?php else: ?>
                    <form method="get" id="form_pago_contrato_filtro" class="row g-3 align-items-end" data-tour="rpc-filtro">
                        <?php
                        if ($bloquearContextoContrato && is_array($contextoContratoDirecto)):
                            $arrendatarioBloqueadoLabel = '(' . rpcFmtRut((string) ($contextoContratoDirecto['rut'] ?? '')) . ') ' . trim((string) ($contextoContratoDirecto['nombre_arrendatario'] ?? ''));
                            $localesBloqueados = $localesByContrato[(int) $idContratoArriendo] ?? [];
                            $contratoBloqueadoLabel = '#' . (int) $idContratoArriendo . ($localesBloqueados !== [] ? ' (' . implode(', ', $localesBloqueados) . ')' : '');
                        ?>
                            <input type="hidden" id="id_arrendatario" name="id_arrendatario" value="<?php echo (int) $idArrendatario; ?>">
                            <input type="hidden" id="id_contrato_arriendo" name="id_contrato_arriendo" value="<?php echo (int) $idContratoArriendo; ?>">
                            <input type="hidden" name="contexto_contrato" value="1">
                            <div class="col-12 col-lg-6"><label class="form-label" for="arrendatario_bloqueado">Arrendatario</label><select id="arrendatario_bloqueado" class="form-select" disabled><option><?php echo msp2Escape($arrendatarioBloqueadoLabel); ?></option></select><div class="form-text">Preseleccionado desde la ficha del contrato.</div></div>
                            <div class="col-12 col-lg-6"><label class="form-label" for="contrato_bloqueado">Contrato</label><select id="contrato_bloqueado" class="form-select" disabled><option><?php echo msp2Escape($contratoBloqueadoLabel); ?></option></select></div>
                        <?php else:
                        msp2RenderSearchableSelectField([
                            'wrapper_class' => 'col-12 col-lg-6',
                            'label' => 'Arrendatario',
                            'input_name' => 'id_arrendatario',
                            'input_id' => 'id_arrendatario',
                            'picker_id' => 'arrendatario_picker_pago_contrato',
                            'button_id' => 'arrendatario_dropdown_btn_pago_contrato',
                            'filter_id' => 'arrendatario_dropdown_filter_pago_contrato',
                            'list_id' => 'arrendatario_dropdown_list_pago_contrato',
                            'error_id' => 'arrendatario_error_pago_contrato',
                            'error_message' => 'Debes seleccionar un arrendatario.',
                            'button_placeholder' => 'Selecciona un arrendatario...',
                            'filter_placeholder' => 'Buscar por nombre, RUT o locales',
                            'empty_message' => 'No hay arrendatarios con deuda.',
                            'required' => false,
                            'value' => ($idArrendatario !== false && $idArrendatario !== null) ? (string) (int) $idArrendatario : '',
                            'options' => $arrendatarioOptions,
                        ]);
                        msp2RenderSearchableSelectField([
                            'wrapper_class' => 'col-12 col-lg-6',
                            'label' => 'Contrato con deuda',
                            'input_name' => 'id_contrato_arriendo',
                            'input_id' => 'id_contrato_arriendo',
                            'picker_id' => 'contrato_picker_pago_contrato',
                            'button_id' => 'contrato_dropdown_btn_pago_contrato',
                            'filter_id' => 'contrato_dropdown_filter_pago_contrato',
                            'list_id' => 'contrato_dropdown_list_pago_contrato',
                            'error_id' => 'contrato_error_pago_contrato',
                            'error_message' => 'Debes seleccionar un contrato.',
                            'button_placeholder' => (($idArrendatario !== false && $idArrendatario !== null) ? 'Selecciona un contrato...' : 'Primero selecciona arrendatario'),
                            'filter_placeholder' => 'Buscar por contrato, locales o período',
                            'empty_message' => 'No hay contratos con deuda para este arrendatario.',
                            'required' => false,
                            'value' => ($idContratoArriendo !== false && $idContratoArriendo !== null) ? (string) (int) $idContratoArriendo : '',
                            'options' => $contratoOptions,
                        ]);
                        endif;
                    ?>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($idArrendatario !== false && $idArrendatario !== null && $contratoOptions === [] && $contratosConDeuda !== []): ?>
            <div class="alert alert-warning">El arrendatario seleccionado no tiene contratos con deuda pendiente.</div>
        <?php endif; ?>

        <?php if ($idContratoArriendo !== false && $idContratoArriendo !== null): ?>
            <?php if ($documentosDeuda === []): ?>
                <div class="alert alert-warning"><?php echo $bloquearContextoContrato ? 'El arrendatario seleccionado no tiene contratos con deuda pendiente. El contrato indicado no registra documentos pendientes.' : 'El contrato seleccionado no tiene documentos pendientes al momento de cargar.'; ?></div>
            <?php else: ?>
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                            <h2 class="h6 mb-0">Deuda pendiente del contrato #<?php echo (int) $idContratoArriendo; ?></h2>
                            <div class="d-flex gap-2">
                                <button
                                    type="button"
                                    class="btn btn-success btn-sm"
                                    id="rpc_open_modal_btn"
                                    data-tour="rpc-open-modal"
                                    data-bs-toggle="modal"
                                    data-bs-target="#modalPagoContrato">
                                    <i class="bi bi-cash-coin me-1" aria-hidden="true"></i>Registrar pago
                                </button>
                            </div>
                        </div>
                        <div class="small text-muted mb-3">Orden de aplicación automático: período más antiguo, luego vencimiento, luego ID de documento.</div>
                        <div class="alert <?php echo $saldoFavorDisponible > 0.005 ? 'alert-success' : 'alert-light border'; ?> d-flex flex-wrap justify-content-between align-items-center gap-3">
                            <div>
                                <div class="fw-semibold"><i class="bi bi-wallet2 me-1" aria-hidden="true"></i>Saldo a favor disponible: <?php echo msp2Escape(rpcFmtMoney($saldoFavorDisponible)); ?></div>
                                <div class="small">Puede aplicarse ahora a la deuda del contrato, comenzando por el documento más antiguo.</div>
                            </div>
                            <form method="post" action="<?php echo msp2Escape(msp2Url('pagos/aplicar_saldo_favor_contrato.php')); ?>" class="d-flex flex-wrap align-items-end gap-2">
                                <?php msp2CsrfField(); ?>
                                <input type="hidden" name="id_arrendatario" value="<?php echo (int) $idArrendatario; ?>">
                                <input type="hidden" name="id_contrato_arriendo" value="<?php echo (int) $idContratoArriendo; ?>">
                                <input type="hidden" name="volver_query" value="<?php echo msp2Escape(http_build_query($queryBase)); ?>">
                                <div>
                                    <label for="rpc_monto_saldo_favor" class="form-label small mb-1">Monto a utilizar</label>
                                    <input type="number" class="form-control form-control-sm" id="rpc_monto_saldo_favor" name="monto_saldo_favor"
                                           min="0.01" max="<?php echo msp2Escape(number_format(min($saldoFavorDisponible, $totalDeudaContrato), 2, '.', '')); ?>" step="0.01"
                                           value="<?php echo msp2Escape(number_format(min($saldoFavorDisponible, $totalDeudaContrato), 2, '.', '')); ?>"
                                           <?php echo $saldoFavorDisponible > 0.005 ? 'required' : 'disabled'; ?>>
                                </div>
                                <button type="submit" class="btn btn-success btn-sm" <?php echo $saldoFavorDisponible > 0.005 ? '' : 'disabled'; ?>
                                        onclick="return confirm('¿Aplicar el saldo a favor a los documentos pendientes de este contrato?');">
                                    Aplicar saldo a favor
                                </button>
                            </form>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle text-center mb-0">
                                <thead class="table-light">
                                <tr>
                                    <th style="width: 90px;">Doc</th>
                                    <th class="text-start">Tienda</th>
                                    <th style="width: 130px;">Número</th>
                                    <th style="width: 120px;">Período</th>
                                    <th style="width: 120px;">Venc.</th>
                                    <th style="width: 130px;" class="text-end">Saldo</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($documentosDeuda as $doc): ?>
                                    <?php
                                    $docId = (int) ($doc['id_documento_cobro'] ?? 0);
                                    $periodoDocRaw = (string) ($doc['periodo_facturacion'] ?? '');
                                    $periodoDocYm = preg_match('/^\d{4}-\d{2}/', $periodoDocRaw) === 1
                                        ? substr($periodoDocRaw, 0, 7)
                                        : '';
                                    $docPortalUrl = msp2Url(
                                        'documentos_cobro/index.php?' . http_build_query([
                                            'id_arrendatario' => $idArrendatario !== false && $idArrendatario !== null ? (int) $idArrendatario : 0,
                                            'filtroPeriodo' => $periodoDocYm,
                                        ])
                                    );
                                    ?>
                                    <tr>
                                        <td>#<?php echo $docId; ?></td>
                                        <td class="text-start"><?php echo msp2Escape((string) ($doc['nombre_tienda'] ?? '-')); ?></td>
                                        <td>
                                            <a href="<?php echo msp2Escape($docPortalUrl); ?>" target="_blank" rel="noopener">
                                                <?php echo msp2Escape((string) ($doc['numero_documento'] ?? '')); ?>
                                            </a>
                                        </td>
                                        <td><?php echo msp2Escape(substr((string) ($doc['periodo_facturacion'] ?? ''), 0, 7)); ?></td>
                                        <td><?php echo msp2Escape(rpcFmtFecha((string) ($doc['fecha_vencimiento'] ?? ''))); ?></td>
                                        <td class="text-end fw-semibold"><?php echo msp2Escape(rpcFmtMoney((float) ($doc['saldo_pendiente'] ?? 0))); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<div class="modal fade" id="modalPagoContrato" tabindex="-1" aria-hidden="true" data-tour="rpc-modal">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('pagos/guardar_pago_contrato.php')); ?>" id="form_pago_contrato"
              style="border-radius:var(--gp-radius-lg,12px);">
            <input type="hidden" name="id_arrendatario" value="<?php echo ($idArrendatario !== false && $idArrendatario !== null) ? (int) $idArrendatario : 0; ?>">
            <input type="hidden" name="id_contrato_arriendo" value="<?php echo ($idContratoArriendo !== false && $idContratoArriendo !== null) ? (int) $idContratoArriendo : 0; ?>">
            <input type="hidden" name="volver_query" value="<?php echo msp2Escape(http_build_query($queryBase)); ?>">
            <input type="hidden" name="return_to" value="<?php echo msp2Escape($returnTo); ?>">
            <input type="hidden" name="monto_pagado" id="pc_monto_pagado">
            <input type="hidden" name="enviar_comprobante" value="1">
            <input type="hidden" name="descargar_pdfs_pago" value="0">
            <input type="hidden" name="demo_email_confirmado" value="">
            <input type="hidden" name="demo_email_override" value="">

            <div class="modal-header" style="background:var(--color-surface,#fff);border-bottom:1px solid var(--color-border,#e5e7eb);">
                <div>
                    <h2 class="modal-title fs-5 mb-0">Registrar pago</h2>
                    <div class="small text-muted" style="margin-top:2px;">
                        #<?php echo (int) $idContratoArriendo; ?> | Saldo contrato: <?php echo msp2Escape(rpcFmtMoney($totalDeudaContrato)); ?>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <div class="modal-body" style="background:var(--color-bg,#f9fafb);">
                <div class="small text-muted mb-3">
                    <span class="pc-required-mark">*</span> Campo obligatorio
                    <span class="mx-1">|</span>
                    <span class="pc-optional-mark">(opcional)</span> Campo no obligatorio
                </div>
                <div class="alert alert-light border d-flex align-items-start gap-2 py-2 mb-3">
                    <input class="form-check-input mt-1" type="checkbox" id="pc_descargar_pdfs_pago" name="descargar_pdfs_pago" value="1" checked>
                    <div>
                        <label class="form-check-label fw-semibold" for="pc_descargar_pdfs_pago">Descargar PDFs al guardar</label>
                        <div class="small text-muted">Se preparará un vale de pago por documento afectado y comprobante de gastos para documentos saldados.</div>
                    </div>
                </div>
                <div class="row g-2 mb-3 align-items-end">
                    <div class="col-sm-4" data-tour="rpc-monto">
                        <label for="pc_monto_pagado_view" class="form-label mb-1 small fw-bold text-success">
                            <i class="bi bi-cash-coin me-1" aria-hidden="true"></i>Monto pagado
                            <span class="pc-required-mark">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text fw-bold" style="background:#f0fdf4;border-color:#16a34a;color:#15803d;">$</span>
                            <input type="text" inputmode="decimal" class="form-control fw-bold" id="pc_monto_pagado_view"
                                   placeholder="0,00" required autocomplete="off"
                                   style="font-size:1.25rem;border-color:#16a34a;box-shadow:0 0 0 1px #bbf7d0;color:#15803d;">
                        </div>
                    </div>
                    <div class="col-sm-3" data-tour="rpc-fecha-pago">
                        <label for="pc_fecha_pago" class="form-label mb-1 small fw-semibold">
                            Fecha pago
                            <span class="pc-required-mark">*</span>
                        </label>
                        <input
                            type="date"
                            class="form-control form-control-sm"
                            id="pc_fecha_pago"
                            name="fecha_pago"
                            value="<?php echo msp2Escape($fechaPagoDefault); ?>"
                            min="<?php echo msp2Escape($fechaPagoMinima); ?>"
                            max="<?php echo msp2Escape($fechaPagoMaxima); ?>"
                            required>
                    </div>
                    <div class="col-sm-3" data-tour="rpc-medio-pago">
                        <label for="pc_medio_pago" class="form-label mb-1 small fw-semibold">
                            Medio de pago
                            <span class="pc-required-mark">*</span>
                        </label>
                        <select id="pc_medio_pago" name="medio_pago" class="form-select form-select-sm" required>
                            <option value="">Selecciona…</option>
                            <?php foreach (['Transferencia', 'Efectivo', 'Cheque'] as $mp): ?>
                                <option value="<?php echo msp2Escape($mp); ?>"><?php echo msp2Escape($mp); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-2" data-tour="rpc-referencia">
                        <label for="pc_referencia_pago" class="form-label mb-1 small fw-semibold" id="pc_referencia_label">
                            Referencia
                            <span class="pc-optional-mark">(opcional)</span>
                        </label>
                        <input type="text" class="form-control form-control-sm" id="pc_referencia_pago" name="referencia_pago" maxlength="100" placeholder="N° operación">
                    </div>
                </div>

                <div class="row g-2 mb-3 align-items-end d-none" id="pc_cheque_wrap">
                    <?php if ($tablaBancosExiste): ?>
                        <div class="col-sm-4">
                            <label class="form-label mb-1 small fw-semibold">
                                Banco
                                <span class="pc-optional-mark">(requerido con cheque)</span>
                            </label>
                            <input type="hidden" id="pc_id_banco_cheque" name="id_banco_cheque">
                            <input type="hidden" id="pc_banco_cheque" name="banco_cheque">
                            <div class="dropdown w-100" id="pc_banco_picker">
                                <button
                                    class="btn btn-outline-secondary btn-sm dropdown-toggle w-100 text-start"
                                    type="button"
                                    id="pc_banco_dropdown_btn"
                                    data-bs-toggle="dropdown"
                                    data-bs-auto-close="outside"
                                    aria-expanded="false">
                                    Selecciona banco...
                                </button>
                                <div class="dropdown-menu p-2 w-100">
                                    <input
                                        type="text"
                                        id="pc_banco_dropdown_filter"
                                        class="form-control form-control-sm mb-2"
                                        placeholder="Buscar banco...">
                                    <div class="list-group list-group-flush overflow-auto" id="pc_banco_dropdown_list" style="max-height: 220px;">
                                        <?php if ($bancosDisponibles === []): ?>
                                            <div class="small text-muted px-2 py-1">No hay bancos activos.</div>
                                        <?php else: ?>
                                            <?php foreach ($bancosDisponibles as $banco): ?>
                                                <?php
                                                $idBanco = (int) ($banco['id_banco'] ?? 0);
                                                if ($idBanco <= 0) {
                                                    continue;
                                                }
                                                $nombreBanco = trim((string) ($banco['nombre_banco'] ?? ''));
                                                $codigoBanco = trim((string) ($banco['codigo_banco'] ?? ''));
                                                $labelBanco = $codigoBanco !== '' ? ($nombreBanco . ' (' . $codigoBanco . ')') : $nombreBanco;
                                                $searchBanco = mb_strtolower($labelBanco, 'UTF-8');
                                                ?>
                                                <button
                                                    type="button"
                                                    class="list-group-item list-group-item-action js-pc-banco-option"
                                                    data-value="<?php echo $idBanco; ?>"
                                                    data-label="<?php echo msp2Escape($labelBanco); ?>"
                                                    data-banco-nombre="<?php echo msp2Escape($nombreBanco); ?>"
                                                    data-search="<?php echo msp2Escape($searchBanco); ?>">
                                                    <?php echo msp2Escape($labelBanco); ?>
                                                </button>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="invalid-feedback d-block d-none" id="pc_banco_picker_error">Debes seleccionar un banco.</div>
                        </div>
                    <?php else: ?>
                        <div class="col-sm-4">
                            <label for="pc_banco_cheque" class="form-label mb-1 small fw-semibold">
                                Banco
                                <span class="pc-optional-mark">(requerido con cheque)</span>
                            </label>
                            <input type="text" class="form-control form-control-sm" id="pc_banco_cheque" name="banco_cheque"
                                   maxlength="100" placeholder="Banco emisor">
                        </div>
                    <?php endif; ?>
                </div>

                <div style="border-radius:10px;overflow:hidden;border:1px solid var(--color-border,#e5e7eb);background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.06);" data-tour="rpc-preview">
                    <table class="table align-middle mb-0" style="font-size:.92rem;">
                        <thead class="table-light">
                        <tr>
                            <th class="text-start ps-3" style="font-weight:600;color:#374151;border-bottom:1px solid var(--color-border,#e5e7eb);">Documento</th>
                            <th class="text-end" style="width:140px;font-weight:600;color:#374151;border-bottom:1px solid var(--color-border,#e5e7eb);">Saldo</th>
                            <th class="text-end" style="width:150px;font-weight:600;color:#374151;border-bottom:1px solid var(--color-border,#e5e7eb);">Se aplica</th>
                        </tr>
                        </thead>
                        <tbody id="pc_preview_body">
                        <tr><td colspan="3" class="text-center text-muted py-3">Ingresa el monto para simular la distribución.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div id="pc_preview_summary" class="small text-success mt-3"></div>
            </div>

            <div class="modal-footer" style="background:var(--color-surface,#fff);border-top:1px solid var(--color-border,#e5e7eb);">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success" id="pc_submit_btn" data-tour="rpc-submit-btn" disabled>Guardar pago</button>
            </div>
        </form>
    </div>
</div>

<div
    class="modal fade"
    id="modalConfirmarComprobantePagoContrato"
    tabindex="-1"
    aria-hidden="true"
    data-demo-enabled="<?php echo $modoCorreoDemoActivo ? '1' : '0'; ?>"
    data-demo-default="<?php echo msp2Escape($correoDemoConfig); ?>">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5">Enviar comprobante</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">¿Quieres enviar el comprobante de pago por correo al guardar?</p>

                <div id="confirmar_comprobante_demo_wrap" class="<?php echo $modoCorreoDemoActivo ? '' : 'd-none'; ?>">
                    <label for="confirmar_comprobante_demo_email" class="form-label">Correo destino demo</label>
                    <input
                        type="email"
                        class="form-control"
                        id="confirmar_comprobante_demo_email"
                        value="<?php echo msp2Escape($correoDemoConfig); ?>"
                        placeholder="correo@demo.cl">
                    <div id="confirmar_comprobante_demo_error" class="small text-danger mt-2 d-none">Ingresa un correo válido para enviar el comprobante.</div>
                    <?php if ($modoCorreoDemoActivo): ?>
                        <div class="small text-muted mt-2">Modo demo activo. Correo por defecto: <strong><?php echo msp2Escape($correoDemoConfig); ?></strong></div>
                    <?php endif; ?>
                </div>

                <div id="confirmar_comprobante_real_info" class="small text-muted <?php echo $modoCorreoDemoActivo ? 'd-none' : ''; ?>">
                    <?php if ($envioArrendatariosHabilitado): ?>
                        Se intentará enviar al correo principal del arrendatario (si existe un correo válido).
                    <?php else: ?>
                        El envío real a arrendatarios está bloqueado desde Configuración Correos.
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" id="confirmar_comprobante_omitir_btn">Guardar sin enviar</button>
                <button
                    type="button"
                    class="btn btn-success"
                    id="confirmar_comprobante_enviar_btn"
                    <?php echo (!$modoCorreoDemoActivo && !$envioArrendatariosHabilitado) ? 'disabled' : ''; ?>>
                    Enviar y guardar
                </button>
            </div>
        </div>
    </div>
</div>

<?php if ($pdfDownloadUrls !== []): ?>
<div class="modal fade" id="modalDescargarPdfsPagoContrato" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5">PDFs listos</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Se prepararon <?php echo count($pdfDownloadUrls); ?> PDF(s) para descargar en este equipo.</p>
                <div class="small text-muted mb-3">El navegador usará su carpeta de descargas configurada.</div>
                <div class="list-group small">
                    <?php foreach ($pdfDownloadUrls as $pdfLink): ?>
                        <a class="list-group-item list-group-item-action" href="<?php echo msp2Escape((string) ($pdfLink['url'] ?? '#')); ?>">
                            <i class="bi bi-file-earmark-pdf me-1 text-danger" aria-hidden="true"></i>
                            <?php echo msp2Escape((string) ($pdfLink['label'] ?? 'PDF de pago')); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
                <button type="button" class="btn btn-success" id="pc_descargar_pdfs_btn">
                    <i class="bi bi-download me-1" aria-hidden="true"></i>Descargar PDFs
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
    const formFiltro = document.getElementById('form_pago_contrato_filtro');
    const arrInput = document.getElementById('id_arrendatario');
    const contratoInput = document.getElementById('id_contrato_arriendo');
    const docsData = <?php echo $documentosJson; ?>;
    const localesContrato = <?php echo $localesContratoSeleccionadoJson; ?>;
    const previewBody = document.getElementById('pc_preview_body');
    const previewSummary = document.getElementById('pc_preview_summary');
    const montoInput = document.getElementById('pc_monto_pagado');
    const montoInputView = document.getElementById('pc_monto_pagado_view');
    const medioPagoSelect = document.getElementById('pc_medio_pago');
    const fechaPagoInput = document.getElementById('pc_fecha_pago');
    const referenciaLabel = document.getElementById('pc_referencia_label');
    const referenciaInput = document.getElementById('pc_referencia_pago');
    const chequeWrap = document.getElementById('pc_cheque_wrap');
    const idBancoChequeInp = document.getElementById('pc_id_banco_cheque');
    const bancoChequeInp = document.getElementById('pc_banco_cheque');
    const bancoDropdownBtn = document.getElementById('pc_banco_dropdown_btn');
    const bancoDropdownFilter = document.getElementById('pc_banco_dropdown_filter');
    const bancoDropdownList = document.getElementById('pc_banco_dropdown_list');
    const bancoPicker = document.getElementById('pc_banco_picker');
    const bancoPickerError = document.getElementById('pc_banco_picker_error');
    const submitBtn = document.getElementById('pc_submit_btn');
    const formPagoContrato = document.getElementById('form_pago_contrato');
    const modalConfirmarComprobante = document.getElementById('modalConfirmarComprobantePagoContrato');
    const confirmarComprobanteEnviarBtn = document.getElementById('confirmar_comprobante_enviar_btn');
    const confirmarComprobanteOmitirBtn = document.getElementById('confirmar_comprobante_omitir_btn');
    const confirmarComprobanteDemoWrap = document.getElementById('confirmar_comprobante_demo_wrap');
    const confirmarComprobanteRealInfo = document.getElementById('confirmar_comprobante_real_info');
    const confirmarComprobanteDemoEmail = document.getElementById('confirmar_comprobante_demo_email');
    const confirmarComprobanteDemoError = document.getElementById('confirmar_comprobante_demo_error');
    const pdfDownloadUrls = <?php echo $pdfDownloadUrlsJson; ?>;
    const modalDescargarPdfs = document.getElementById('modalDescargarPdfsPagoContrato');
    const descargarPdfsBtn = document.getElementById('pc_descargar_pdfs_btn');
    const fechaPagoMin = '<?php echo msp2Escape($fechaPagoMinima); ?>';
    const fechaPagoMax = '<?php echo msp2Escape($fechaPagoMaxima); ?>';
    const confirmarComprobanteDemoEnabled = !!(
        modalConfirmarComprobante
        && modalConfirmarComprobante.dataset.demoEnabled === '1'
        && window.bootstrap
    );
    let comprobanteFormPendiente = null;
    const SENDING_OVERLAY_ID = 'msp-mail-sending-overlay';

    const descargarPdfsPagoContrato = () => {
        if (!Array.isArray(pdfDownloadUrls) || pdfDownloadUrls.length === 0) {
            return;
        }
        if (descargarPdfsBtn instanceof HTMLButtonElement) {
            descargarPdfsBtn.disabled = true;
            descargarPdfsBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Descargando...';
        }
        pdfDownloadUrls.forEach((item, index) => {
            const url = item && typeof item.url === 'string' ? item.url : '';
            if (url === '') {
                return;
            }
            window.setTimeout(() => {
                const frame = document.createElement('iframe');
                frame.src = url;
                frame.style.display = 'none';
                frame.setAttribute('aria-hidden', 'true');
                document.body.appendChild(frame);
                window.setTimeout(() => frame.remove(), 60000);
            }, index * 350);
        });
    };

    const showMailSendingOverlay = (form) => {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        if (form.dataset.mailSubmitting === '1') {
            return;
        }
        form.dataset.mailSubmitting = '1';
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((node) => {
            if ('disabled' in node) {
                node.disabled = true;
            }
        });

        let overlay = document.getElementById(SENDING_OVERLAY_ID);
        if (!(overlay instanceof HTMLDivElement)) {
            overlay = document.createElement('div');
            overlay.id = SENDING_OVERLAY_ID;
            overlay.className = 'msp-mail-sending-overlay';
            overlay.innerHTML = ''
                + '<div class="msp-mail-sending-box" role="status" aria-live="polite" aria-atomic="true">'
                + '<i class="bi bi-send-fill msp-mail-sending-plane" aria-hidden="true"></i>'
                + '<div class="msp-mail-sending-text">Enviando correo...</div>'
                + '</div>';
            document.body.appendChild(overlay);
        }
        overlay.classList.remove('d-none');
        document.body.classList.add('msp-mail-sending-open');
    };

    if (arrInput instanceof HTMLInputElement && formFiltro instanceof HTMLFormElement) {
        arrInput.addEventListener('change', () => {
            if (contratoInput instanceof HTMLInputElement) {
                contratoInput.value = '';
            }
            formFiltro.requestSubmit();
        });
    }
    if (contratoInput instanceof HTMLInputElement && formFiltro instanceof HTMLFormElement) {
        contratoInput.addEventListener('change', () => {
            formFiltro.requestSubmit();
        });
    }

    const fmt = (n) => '$ ' + Number(n || 0).toLocaleString('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const parse = (value) => {
        const n = Number.parseFloat(String(value || '').replace(',', '.'));
        return Number.isFinite(n) ? Math.round(n * 100) / 100 : 0;
    };
    const parseMontoInput = (value) => {
        if (typeof value !== 'string') {
            return 0;
        }
        let normalized = value.trim().replace(/\s+/g, '');
        if (normalized === '') {
            return 0;
        }
        const hasComma = normalized.includes(',');
        const hasDot = normalized.includes('.');
        if (hasComma && hasDot) {
            if (normalized.lastIndexOf(',') > normalized.lastIndexOf('.')) {
                normalized = normalized.replace(/\./g, '').replace(',', '.');
            } else {
                normalized = normalized.replace(/,/g, '');
            }
        } else if (hasComma) {
            normalized = normalized.replace(',', '.');
        }
        const parsed = Number.parseFloat(normalized.replace(/[^0-9.-]/g, ''));
        return Number.isFinite(parsed) ? Math.round(parsed * 100) / 100 : 0;
    };
    const formatMontoInput = (value) => Number(value || 0).toLocaleString('es-CL', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
    const fmtIsoDate = (value) => {
        const [year, month, day] = String(value || '').split('-');
        if (!year || !month || !day) {
            return value;
        }
        return day + '-' + month + '-' + year;
    };
    const validarFechaPago = () => {
        if (!(fechaPagoInput instanceof HTMLInputElement)) {
            return true;
        }
        const value = fechaPagoInput.value.trim();
        fechaPagoInput.setCustomValidity('');
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
            fechaPagoInput.setCustomValidity('Debes ingresar una fecha de pago válida.');
            return false;
        }
        if (value < fechaPagoMin || value > fechaPagoMax) {
            fechaPagoInput.setCustomValidity(
                'La fecha de pago debe estar entre ' + fmtIsoDate(fechaPagoMin) + ' y ' + fmtIsoDate(fechaPagoMax) + '.'
            );
            return false;
        }
        return true;
    };
    const monthNames = {
        '01': 'Enero',
        '02': 'Febrero',
        '03': 'Marzo',
        '04': 'Abril',
        '05': 'Mayo',
        '06': 'Junio',
        '07': 'Julio',
        '08': 'Agosto',
        '09': 'Septiembre',
        '10': 'Octubre',
        '11': 'Noviembre',
        '12': 'Diciembre',
    };
    const bancoPickerReady = idBancoChequeInp instanceof HTMLInputElement
        && bancoChequeInp instanceof HTMLInputElement
        && bancoDropdownBtn instanceof HTMLButtonElement
        && bancoDropdownFilter instanceof HTMLInputElement
        && bancoDropdownList instanceof HTMLDivElement
        && bancoPicker instanceof HTMLDivElement;

    const clearBancoCheque = () => {
        if (idBancoChequeInp instanceof HTMLInputElement) {
            idBancoChequeInp.value = '';
        }
        if (bancoChequeInp instanceof HTMLInputElement) {
            bancoChequeInp.value = '';
        }
        if (bancoDropdownBtn instanceof HTMLButtonElement) {
            bancoDropdownBtn.textContent = 'Selecciona banco...';
            bancoDropdownBtn.title = '';
            bancoDropdownBtn.classList.remove('is-invalid');
        }
        if (bancoPickerError instanceof HTMLDivElement) {
            bancoPickerError.classList.add('d-none');
        }
        if (bancoDropdownList instanceof HTMLDivElement) {
            bancoDropdownList.querySelectorAll('.js-pc-banco-option').forEach((item) => item.classList.remove('active'));
        }
    };

    const syncChequeFields = () => {
        const esCheque = medioPagoSelect instanceof HTMLSelectElement
            && medioPagoSelect.value.trim().toUpperCase() === 'CHEQUE';
        if (chequeWrap instanceof HTMLDivElement) {
            chequeWrap.classList.toggle('d-none', !esCheque);
        }
        if (referenciaLabel instanceof HTMLLabelElement) {
            referenciaLabel.innerHTML = esCheque
                ? 'N° cheque <span class="pc-required-mark">*</span>'
                : 'Referencia <span class="pc-optional-mark">(opcional)</span>';
        }
        if (referenciaInput instanceof HTMLInputElement) {
            referenciaInput.placeholder = esCheque ? 'N° cheque' : 'N° operación';
            referenciaInput.required = esCheque;
        }
        if (bancoPickerReady) {
            bancoChequeInp.required = esCheque;
        } else if (bancoChequeInp instanceof HTMLInputElement) {
            bancoChequeInp.required = esCheque;
        }
        if (!esCheque) {
            clearBancoCheque();
        }
    };

    const escapeHtml = (value) => String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    const buildDocumentoLabel = (doc) => {
        const periodo = String(doc.periodo_facturacion || '').slice(0, 7);
        const year = periodo.slice(0, 4);
        const month = periodo.slice(5, 7);
        const monthLabel = monthNames[month] || periodo;
        return year !== '' ? ('Arriendo ' + monthLabel + ', ' + year) : 'Arriendo';
    };

    const renderPreview = () => {
        if (!(previewBody instanceof HTMLTableSectionElement) || !(previewSummary instanceof HTMLDivElement)) {
            return;
        }
        const monto = montoInput instanceof HTMLInputElement ? parse(montoInput.value) : 0;
        if (!Array.isArray(docsData) || docsData.length === 0) {
            previewBody.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-3">No hay documentos pendientes.</td></tr>';
            previewSummary.textContent = '';
            if (submitBtn instanceof HTMLButtonElement) {
                submitBtn.disabled = true;
            }
            return;
        }
        if (monto <= 0) {
            previewBody.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-3">Ingresa el monto para simular la distribución.</td></tr>';
            previewSummary.textContent = '';
            if (submitBtn instanceof HTMLButtonElement) {
                submitBtn.disabled = true;
            }
            return;
        }

        let restante = Math.round(monto * 100) / 100;
        let totalAplicado = 0;
        let totalExcedente = 0;
        const rows = [];

        docsData.forEach((doc, index) => {
            const saldo = parse(doc.saldo_pendiente);
            if (restante <= 0 || saldo <= 0) {
                return;
            }

            const isLast = index === docsData.length - 1;
            const aplicar = Math.round((isLast ? restante : Math.min(restante, saldo)) * 100) / 100;
            const aplicadoDoc = Math.round(Math.min(aplicar, saldo) * 100) / 100;
            const excedenteDoc = Math.round(Math.max(0, aplicar - saldo) * 100) / 100;

            restante = Math.round((restante - (aplicadoDoc + excedenteDoc)) * 100) / 100;
            if (restante < 0) {
                restante = 0;
            }
            totalAplicado = Math.round((totalAplicado + aplicadoDoc) * 100) / 100;
            totalExcedente = Math.round((totalExcedente + excedenteDoc) * 100) / 100;

            const detalle = buildDocumentoLabel(doc);

            rows.push(
                '<tr>'
                + '<td class="text-start ps-3">' + escapeHtml(detalle) + '</td>'
                + '<td class="text-end">' + fmt(saldo) + '</td>'
                + '<td class="text-end fw-semibold">' + fmt(aplicar) + '</td>'
                + '</tr>'
            );
        });

        previewBody.innerHTML = rows.length > 0
            ? rows.join('')
            : '<tr><td colspan="3" class="text-center text-muted py-3">No se pudo simular la distribución.</td></tr>';

        let summary = 'Aplicado a deuda: ' + fmt(totalAplicado);
        if (totalExcedente > 0.005) {
            summary += ' | Excedente a saldo a favor: ' + fmt(totalExcedente);
        }
        previewSummary.textContent = summary;

        if (submitBtn instanceof HTMLButtonElement) {
            submitBtn.disabled = !(totalAplicado > 0 || totalExcedente > 0);
        }
    };

    const limpiarFlagsComprobante = (form) => {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        const enviarInput = form.querySelector('input[name="enviar_comprobante"]');
        const demoConfirmadoInput = form.querySelector('input[name="demo_email_confirmado"]');
        const demoOverrideInput = form.querySelector('input[name="demo_email_override"]');
        if (enviarInput instanceof HTMLInputElement) {
            enviarInput.value = '1';
        }
        if (demoConfirmadoInput instanceof HTMLInputElement) {
            demoConfirmadoInput.value = '';
        }
        if (demoOverrideInput instanceof HTMLInputElement) {
            demoOverrideInput.value = '';
        }
        form.dataset.confirmacionComprobante = '0';
    };

    const decidirEnvioComprobante = (form, enviar, correoDemo = '') => {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        const enviarInput = form.querySelector('input[name="enviar_comprobante"]');
        const demoConfirmadoInput = form.querySelector('input[name="demo_email_confirmado"]');
        const demoOverrideInput = form.querySelector('input[name="demo_email_override"]');
        if (enviarInput instanceof HTMLInputElement) {
            enviarInput.value = enviar ? '1' : '0';
        }
        if (demoConfirmadoInput instanceof HTMLInputElement) {
            demoConfirmadoInput.value = enviar && confirmarComprobanteDemoEnabled ? '1' : '';
        }
        if (demoOverrideInput instanceof HTMLInputElement) {
            demoOverrideInput.value = enviar && confirmarComprobanteDemoEnabled ? correoDemo : '';
        }
        form.dataset.confirmacionComprobante = '1';
    };

    const solicitarConfirmacionComprobante = (form) => {
        if (!(form instanceof HTMLFormElement) || !modalConfirmarComprobante || !window.bootstrap) {
            return false;
        }
        comprobanteFormPendiente = form;
        if (confirmarComprobanteDemoError instanceof HTMLDivElement) {
            confirmarComprobanteDemoError.classList.add('d-none');
        }
        if (confirmarComprobanteDemoWrap) {
            confirmarComprobanteDemoWrap.classList.toggle('d-none', !confirmarComprobanteDemoEnabled);
        }
        if (confirmarComprobanteRealInfo) {
            confirmarComprobanteRealInfo.classList.toggle('d-none', confirmarComprobanteDemoEnabled);
        }
        if (confirmarComprobanteDemoEnabled && confirmarComprobanteDemoEmail instanceof HTMLInputElement) {
            const overrideActual = form.querySelector('input[name="demo_email_override"]');
            const correoDefault = (modalConfirmarComprobante.dataset.demoDefault || '').trim();
            const correoInicial = overrideActual instanceof HTMLInputElement && overrideActual.value.trim() !== ''
                ? overrideActual.value.trim()
                : correoDefault;
            confirmarComprobanteDemoEmail.value = correoInicial;
            confirmarComprobanteDemoEmail.focus();
            confirmarComprobanteDemoEmail.select();
        }

        window.bootstrap.Modal.getOrCreateInstance(modalConfirmarComprobante).show();
        return true;
    };

    if (montoInputView instanceof HTMLInputElement && montoInput instanceof HTMLInputElement) {
        const saldoSugerido = <?php echo json_encode(round($totalDeudaContrato, 2)); ?>;
        if (<?php echo $returnTo !== '' ? 'true' : 'false'; ?> && saldoSugerido > 0 && montoInputView.value.trim() === '') {
            montoInputView.value = formatMontoInput(saldoSugerido);
            montoInput.value = Number(saldoSugerido).toFixed(2);
            renderPreview();
        }
        montoInputView.addEventListener('input', () => {
            const monto = parseMontoInput(montoInputView.value);
            montoInput.value = monto > 0 ? monto.toFixed(2) : '';
            renderPreview();
        });
        montoInputView.addEventListener('blur', () => {
            const monto = parseMontoInput(montoInputView.value);
            if (monto > 0) {
                montoInputView.value = formatMontoInput(monto);
            } else {
                montoInputView.value = '';
            }
        });
    }

    if (medioPagoSelect instanceof HTMLSelectElement) {
        medioPagoSelect.addEventListener('change', syncChequeFields);
    }
    if (fechaPagoInput instanceof HTMLInputElement) {
        fechaPagoInput.addEventListener('input', () => {
            validarFechaPago();
        });
        fechaPagoInput.addEventListener('change', () => {
            validarFechaPago();
        });
    }

    if (bancoPickerReady) {
        const bancoDropdown = window.bootstrap ? window.bootstrap.Dropdown.getOrCreateInstance(bancoDropdownBtn) : null;
        const bancoOptions = Array.from(bancoDropdownList.querySelectorAll('.js-pc-banco-option'));
        const filterBancoOptions = () => {
            const term = bancoDropdownFilter.value.trim().toLowerCase();
            bancoOptions.forEach((option) => {
                const hayMatch = term === '' || String(option.dataset.search || '').includes(term);
                option.classList.toggle('d-none', !hayMatch);
            });
        };
        bancoOptions.forEach((option) => {
            option.addEventListener('click', () => {
                idBancoChequeInp.value = option.dataset.value || '';
                bancoChequeInp.value = option.dataset.bancoNombre || '';
                bancoDropdownBtn.textContent = option.dataset.label || 'Selecciona banco...';
                bancoDropdownBtn.title = option.dataset.label || '';
                bancoDropdownBtn.classList.remove('is-invalid');
                if (bancoPickerError instanceof HTMLDivElement) {
                    bancoPickerError.classList.add('d-none');
                }
                bancoOptions.forEach((item) => item.classList.remove('active'));
                option.classList.add('active');
                if (bancoDropdown) {
                    bancoDropdown.hide();
                }
            });
        });
        bancoDropdownFilter.addEventListener('input', filterBancoOptions);
        bancoPicker.addEventListener('shown.bs.dropdown', () => {
            if (formPagoContrato instanceof HTMLFormElement) {
                formPagoContrato.classList.add('pc-banco-dropdown-open');
            }
            bancoDropdownFilter.focus();
            bancoDropdownFilter.select();
            filterBancoOptions();
        });
        bancoPicker.addEventListener('hidden.bs.dropdown', () => {
            if (formPagoContrato instanceof HTMLFormElement) {
                formPagoContrato.classList.remove('pc-banco-dropdown-open');
            }
        });
    }

    if (formPagoContrato instanceof HTMLFormElement) {
        limpiarFlagsComprobante(formPagoContrato);
        formPagoContrato.addEventListener('submit', (event) => {
            if (formPagoContrato.dataset.confirmacionComprobante !== '1') {
                event.preventDefault();
                const confirmado = solicitarConfirmacionComprobante(formPagoContrato);
                if (!confirmado) {
                    decidirEnvioComprobante(formPagoContrato, true);
                    formPagoContrato.requestSubmit();
                }
                return;
            }
            const monto = montoInput instanceof HTMLInputElement ? parse(montoInput.value) : 0;
            if (monto <= 0) {
                event.preventDefault();
                if (montoInputView instanceof HTMLInputElement) {
                    montoInputView.focus();
                }
                return;
            }
            if (medioPagoSelect instanceof HTMLSelectElement && medioPagoSelect.value.trim() === '') {
                event.preventDefault();
                medioPagoSelect.focus();
                return;
            }
            if (!validarFechaPago()) {
                event.preventDefault();
                if (fechaPagoInput instanceof HTMLInputElement) {
                    fechaPagoInput.reportValidity();
                    fechaPagoInput.focus();
                }
                return;
            }

            const esCheque = medioPagoSelect instanceof HTMLSelectElement
                && medioPagoSelect.value.trim().toUpperCase() === 'CHEQUE';
            if (esCheque) {
                const bancoCheque = bancoChequeInp instanceof HTMLInputElement ? bancoChequeInp.value.trim() : '';
                const bancoInvalido = bancoPickerReady ? idBancoChequeInp.value.trim() === '' : bancoCheque === '';
                if (referenciaInput instanceof HTMLInputElement && referenciaInput.value.trim() === '') {
                    event.preventDefault();
                    referenciaInput.focus();
                    return;
                }
                if (bancoInvalido) {
                    event.preventDefault();
                    if (bancoPickerReady && bancoDropdownBtn instanceof HTMLButtonElement) {
                        bancoDropdownBtn.classList.add('is-invalid');
                        if (bancoPickerError instanceof HTMLDivElement) {
                            bancoPickerError.classList.remove('d-none');
                        }
                        bancoDropdownBtn.focus();
                    } else if (bancoChequeInp instanceof HTMLInputElement) {
                        bancoChequeInp.focus();
                    }
                }
            }
            const enviarComprobanteInput = formPagoContrato.querySelector('input[name="enviar_comprobante"]');
            const enviaraCorreo = !(enviarComprobanteInput instanceof HTMLInputElement) || enviarComprobanteInput.value !== '0';
            if (enviaraCorreo) {
                showMailSendingOverlay(formPagoContrato);
            }
        });
    }

    if (confirmarComprobanteOmitirBtn instanceof HTMLButtonElement) {
        confirmarComprobanteOmitirBtn.addEventListener('click', () => {
            if (!(comprobanteFormPendiente instanceof HTMLFormElement) || !modalConfirmarComprobante || !window.bootstrap) {
                return;
            }
            decidirEnvioComprobante(comprobanteFormPendiente, false);
            window.bootstrap.Modal.getOrCreateInstance(modalConfirmarComprobante).hide();
            comprobanteFormPendiente.requestSubmit();
        });
    }

    if (confirmarComprobanteEnviarBtn instanceof HTMLButtonElement) {
        confirmarComprobanteEnviarBtn.addEventListener('click', () => {
            if (!(comprobanteFormPendiente instanceof HTMLFormElement) || !modalConfirmarComprobante || !window.bootstrap) {
                return;
            }
            let correoDemo = '';
            if (confirmarComprobanteDemoEnabled) {
                if (!(confirmarComprobanteDemoEmail instanceof HTMLInputElement)) {
                    return;
                }
                correoDemo = confirmarComprobanteDemoEmail.value.trim();
                const correoValido = confirmarComprobanteDemoEmail.checkValidity() && correoDemo !== '';
                if (!correoValido) {
                    if (confirmarComprobanteDemoError instanceof HTMLDivElement) {
                        confirmarComprobanteDemoError.classList.remove('d-none');
                    }
                    confirmarComprobanteDemoEmail.focus();
                    return;
                }
            }

            decidirEnvioComprobante(comprobanteFormPendiente, true, correoDemo);
            window.bootstrap.Modal.getOrCreateInstance(modalConfirmarComprobante).hide();
            comprobanteFormPendiente.requestSubmit();
        });
    }

    if (modalConfirmarComprobante) {
        modalConfirmarComprobante.addEventListener('hidden.bs.modal', () => {
            if (confirmarComprobanteDemoError instanceof HTMLDivElement) {
                confirmarComprobanteDemoError.classList.add('d-none');
            }
            comprobanteFormPendiente = null;
        });
    }

    if (descargarPdfsBtn instanceof HTMLButtonElement) {
        descargarPdfsBtn.addEventListener('click', descargarPdfsPagoContrato);
    }

    if (
        Array.isArray(pdfDownloadUrls)
        && pdfDownloadUrls.length > 0
        && modalDescargarPdfs instanceof HTMLElement
        && window.bootstrap
    ) {
        window.bootstrap.Modal.getOrCreateInstance(modalDescargarPdfs).show();
    }

    syncChequeFields();
    renderPreview();
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/driver.js@1.3.6/dist/driver.js.iife.js"></script>
<script src="<?php echo msp2Escape(msp2Url('assets/msp_tour_registrar_pago_contrato.js')); ?>"></script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
