<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

function msp2BuildDompdfDebugInfo(string $autoloadPath): string
{
    $lines = [];
    $lines[] = 'Diagnostico Dompdf:';
    $lines[] = '- PHP version: ' . PHP_VERSION;
    $lines[] = '- autoload esperado: ' . $autoloadPath;
    $lines[] = '- autoload existe: ' . (is_file($autoloadPath) ? 'si' : 'no');

    $dompdfLoaded = class_exists(\Dompdf\Dompdf::class, false);
    $lines[] = '- clase Dompdf cargada: ' . ($dompdfLoaded ? 'si' : 'no');

    if ($dompdfLoaded) {
        try {
            $reflection = new ReflectionClass(\Dompdf\Dompdf::class);
            $classFile = (string) $reflection->getFileName();
            $lines[] = '- archivo de clase Dompdf: ' . $classFile;

            $expectedVendorRoot = realpath(dirname(__DIR__, 2) . '/vendor');
            $classRealPath = realpath($classFile);
            if ($expectedVendorRoot !== false && $classRealPath !== false) {
                $isFromExpectedVendor = strpos($classRealPath, $expectedVendorRoot . DIRECTORY_SEPARATOR) === 0
                    || $classRealPath === $expectedVendorRoot;
                $lines[] = '- Dompdf desde vendor esperado: ' . ($isFromExpectedVendor ? 'si' : 'no');
            }
        } catch (Throwable $reflectionError) {
            $lines[] = '- no se pudo inspeccionar ReflectionClass: ' . $reflectionError->getMessage();
        }
    }

    if (class_exists(\Composer\InstalledVersions::class, false)) {
        try {
            if (\Composer\InstalledVersions::isInstalled('dompdf/dompdf')) {
                $prettyVersion = \Composer\InstalledVersions::getPrettyVersion('dompdf/dompdf');
                $reference = \Composer\InstalledVersions::getReference('dompdf/dompdf');
                $lines[] = '- version composer dompdf/dompdf: ' . ($prettyVersion ?? 'desconocida');
                $lines[] = '- referencia composer dompdf/dompdf: ' . ($reference ?? 'desconocida');
            } else {
                $lines[] = '- dompdf/dompdf no aparece instalado en Composer InstalledVersions';
            }
        } catch (Throwable $composerError) {
            $lines[] = '- error leyendo version desde Composer InstalledVersions: ' . $composerError->getMessage();
        }
    } else {
        $lines[] = '- Composer\\InstalledVersions no esta disponible';
    }

    $dompdfVersionFile = dirname(__DIR__, 2) . '/vendor/dompdf/dompdf/VERSION';
    if (is_file($dompdfVersionFile)) {
        $versionFileValue = trim((string) @file_get_contents($dompdfVersionFile));
        $lines[] = '- VERSION file dompdf: ' . ($versionFileValue !== '' ? $versionFileValue : '(vacio)');
    } else {
        $lines[] = '- VERSION file dompdf no encontrado en vendor/dompdf/dompdf/VERSION';
    }

    return implode(PHP_EOL, $lines);
}

function msp2RegisterDompdfFallbackAutoloader(): bool
{
    static $registered = false;
    if ($registered) {
        return true;
    }

    $vendorRoot = dirname(__DIR__, 2) . '/vendor';
    $prefixMap = [
        'Dompdf\\' => $vendorRoot . '/dompdf/dompdf/src/',
        'FontLib\\' => $vendorRoot . '/dompdf/php-font-lib/src/FontLib/',
        'Svg\\' => $vendorRoot . '/dompdf/php-svg-lib/src/Svg/',
        'Masterminds\\' => $vendorRoot . '/masterminds/html5/src/',
        'Sabberworm\\CSS\\' => $vendorRoot . '/sabberworm/php-css-parser/src/',
    ];

    $existingRoots = 0;
    foreach ($prefixMap as $rootPath) {
        if (is_dir($rootPath)) {
            $existingRoots++;
        }
    }
    if ($existingRoots === 0) {
        return false;
    }

    spl_autoload_register(static function (string $class) use ($prefixMap): void {
        foreach ($prefixMap as $prefix => $rootPath) {
            if (strpos($class, $prefix) !== 0) {
                continue;
            }

            $relativeClass = substr($class, strlen($prefix));
            if ($relativeClass === false) {
                return;
            }

            $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';
            $candidate = $rootPath . $relativePath;
            if (is_file($candidate)) {
                require_once $candidate;
            }
            return;
        }
    }, true, true);

    $registered = true;
    return true;
}

$autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!is_file($autoloadPath)) {
    http_response_code(500);
    echo 'No se encontro vendor/autoload.php para cargar Dompdf.' . PHP_EOL
        . msp2BuildDompdfDebugInfo($autoloadPath);
    exit();
}

require_once $autoloadPath;

if (!class_exists(\Dompdf\Dompdf::class)) {
    $fallbackRegistered = msp2RegisterDompdfFallbackAutoloader();
    if ($fallbackRegistered && class_exists(\Dompdf\Dompdf::class)) {
        // Composer no resolvio Dompdf, pero el fallback de namespaces si.
    } else {
        http_response_code(500);
        echo 'Dompdf no esta disponible en el proyecto.' . PHP_EOL
            . msp2BuildDompdfDebugInfo($autoloadPath) . PHP_EOL
            . '- fallback autoloader Dompdf: ' . ($fallbackRegistered ? 'registrado' : 'no registrado');
        exit();
    }
}

if (!class_exists(\Dompdf\Dompdf::class)) {
    http_response_code(500);
    echo 'Dompdf no esta disponible en el proyecto.' . PHP_EOL
        . msp2BuildDompdfDebugInfo($autoloadPath);
    exit();
}

try {
    $dompdfReflection = new ReflectionClass(\Dompdf\Dompdf::class);
    $dompdfClassPath = realpath((string) $dompdfReflection->getFileName()) ?: (string) $dompdfReflection->getFileName();
    $expectedVendorRoot = realpath(dirname(__DIR__, 2) . '/vendor');
    if ($expectedVendorRoot !== false && strpos($dompdfClassPath, $expectedVendorRoot . DIRECTORY_SEPARATOR) !== 0) {
        http_response_code(500);
        echo 'Se detecto una carga de Dompdf desde una ruta distinta al vendor esperado.' . PHP_EOL
            . msp2BuildDompdfDebugInfo($autoloadPath) . PHP_EOL
            . 'Esto suele ocurrir cuando otra version de Dompdf se carga antes en el servidor.';
        exit();
    }
} catch (Throwable $dompdfCheckError) {
    http_response_code(500);
    echo 'No fue posible validar la clase Dompdf cargada.' . PHP_EOL
        . msp2BuildDompdfDebugInfo($autoloadPath) . PHP_EOL
        . 'Detalle tecnico: ' . $dompdfCheckError->getMessage();
    exit();
}

$idDocumento = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$uuidDocumentoRaw = trim((string) ($_GET['uuid'] ?? ''));
$uuidDocumento = null;
if ($uuidDocumentoRaw !== '') {
    if (
        preg_match(
            '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89ABab][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/',
            $uuidDocumentoRaw
        ) !== 1
    ) {
        http_response_code(400);
        echo 'Debes indicar un documento valido.';
        exit();
    }
    $uuidDocumento = strtolower($uuidDocumentoRaw);
}
$expiresAt = filter_input(INPUT_GET, 'exp', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$signature = trim((string) ($_GET['sig'] ?? ''));

if (($idDocumento === false || $idDocumento === null) && $uuidDocumento === null) {
    http_response_code(400);
    echo 'Debes indicar un documento valido.';
    exit();
}

$signedParams = [
    'exp' => $expiresAt,
    'sig' => $signature,
];
if ($uuidDocumento !== null) {
    $signedParams['uuid'] = $uuidDocumento;
} else {
    $signedParams['id'] = $idDocumento;
}

if (
    $expiresAt === false
    || $expiresAt === null
    || $signature === ''
    || !msp2VerifySignedParams('documento_cobro_pdf', $signedParams)
) {
    http_response_code(403);
    echo 'El enlace del PDF no es válido o expiró. Vuelve a abrirlo desde la aplicación.';
    exit();
}

function pdfMonto(mixed $value): string
{
    return '$ ' . number_format((float) $value, 2, ',', '.');
}

function pdfMontoPayable(mixed $value): string
{
    $num = max(0.0, (float) $value);
    $roundedUp = ceil($num);
    return '$ ' . number_format($roundedUp, 0, ',', '.');
}

function pdfFecha(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    $parsed = DateTime::createFromFormat('Y-m-d', substr($value, 0, 10));
    return $parsed ? $parsed->format('d-m-Y') : (string) $value;
}

function pdfPeriodo(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    $parsed = DateTime::createFromFormat('Y-m-d', substr($value, 0, 10));
    return $parsed ? $parsed->format('m-Y') : (string) $value;
}

function pdfPeriodoArchivo(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return 'sin_periodo';
    }

    $parsed = DateTime::createFromFormat('Y-m-d', substr($value, 0, 10));
    if ($parsed) {
        return $parsed->format('Y-m');
    }

    $fallback = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim((string) $value));
    return $fallback !== null && $fallback !== '' ? $fallback : 'sin_periodo';
}

function pdfSlug(?string $value): string
{
    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }

    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($converted !== false) {
        $text = $converted;
    }

    $text = preg_replace('/[^A-Za-z0-9]+/', '_', $text) ?? '';
    $text = trim($text, '_');

    return $text;
}

function pdfLocalLabel(?string $value): ?string
{
    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }

    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($converted !== false) {
        $text = $converted;
    }

    $text = preg_replace('/[^A-Za-z0-9-]+/', '', $text) ?? '';
    return $text !== '' ? $text : null;
}

function pdfBuildFilename(array $documento, array $arriendoDetalles, array $electricidadDetalles, array $gasDetalles = []): string
{
    $periodo = pdfPeriodoArchivo((string) ($documento['periodo_facturacion'] ?? ''));
    $locals = [];

    foreach ($arriendoDetalles as $detalle) {
        $label = pdfLocalLabel((string) ($detalle['cdo_local'] ?? ''));
        if ($label !== null) {
            $locals[$label] = true;
        }
    }

    foreach ($electricidadDetalles as $detalle) {
        $label = pdfLocalLabel((string) ($detalle['cdo_local'] ?? ''));
        if ($label !== null) {
            $locals[$label] = true;
        }
    }

    foreach ($gasDetalles as $detalle) {
        $label = pdfLocalLabel((string) ($detalle['cdo_local'] ?? ''));
        if ($label !== null) {
            $locals[$label] = true;
        }
    }

    $parts = [$periodo];
    $localLabels = array_keys($locals);

    if ($localLabels === []) {
        $parts[] = 'documento_cobro';
    } else {
        $filenameBase = $periodo;
        $extraCount = 0;

        foreach ($localLabels as $index => $label) {
            $candidate = $filenameBase . '_' . $label;
            if (strlen($candidate) > 180) {
                $extraCount = count($localLabels) - $index;
                break;
            }

            $parts[] = $label;
            $filenameBase = $candidate;
        }

        if ($extraCount > 0) {
            $parts[] = 'y_' . $extraCount . '_mas';
        }
    }

    return implode('_', $parts) . '.pdf';
}

try {
    $requiredTables = [
        'msp_documentos_cobro',
        'msp_documentos_cobro_detalle',
        'msp_tiendas',
        'msp_locales',
    ];

    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            throw new RuntimeException('Falta la tabla `' . $tableName . '`.');
        }
    }

    if ($uuidDocumento !== null) {
        if (!msp2ColumnExists($conn, 'msp_documentos_cobro', 'uuid_documento')) {
            throw new RuntimeException('El sistema no tiene habilitado UUID para documentos de cobro.');
        }

        $stmtDocumento = $conn->prepare(
            "SELECT
                dc.id_documento_cobro,
                dc.id_tienda,
                dc.numero_documento,
                dc.periodo_facturacion,
                dc.fecha_emision,
                dc.fecha_vencimiento,
                dc.nombre_arrendatario_snapshot,
                dc.rut_arrendatario_snapshot,
                dc.nombre_tienda_snapshot,
                dc.subtotal_arriendo,
                dc.subtotal_servicios,
                dc.monto_total,
                dc.saldo_pendiente,
                dc.estado_documento,
                dc.observaciones
             FROM dbo.msp_documentos_cobro dc
             WHERE dc.uuid_documento = :uuid"
        );
        $stmtDocumento->bindValue(':uuid', $uuidDocumento, PDO::PARAM_STR);
    } else {
        $stmtDocumento = $conn->prepare(
            "SELECT
                dc.id_documento_cobro,
                dc.id_tienda,
                dc.numero_documento,
                dc.periodo_facturacion,
                dc.fecha_emision,
                dc.fecha_vencimiento,
                dc.nombre_arrendatario_snapshot,
                dc.rut_arrendatario_snapshot,
                dc.nombre_tienda_snapshot,
                dc.subtotal_arriendo,
                dc.subtotal_servicios,
                dc.monto_total,
                dc.saldo_pendiente,
                dc.estado_documento,
                dc.observaciones
             FROM dbo.msp_documentos_cobro dc
             WHERE dc.id_documento_cobro = :id"
        );
        $stmtDocumento->bindValue(':id', $idDocumento, PDO::PARAM_INT);
    }
    $stmtDocumento->execute();
    $documento = $stmtDocumento->fetch();

    if ($documento === false) {
        throw new RuntimeException('El documento solicitado no existe.');
    }

    $stmtArriendo = $conn->prepare(
        "WITH detalle_arriendo AS (
            SELECT
                dcd.orden_item,
                dcd.descripcion_item,
                dcd.subtotal,
                CASE
                    WHEN dcd.descripcion_item LIKE N'Arriendo local %'
                        THEN LTRIM(SUBSTRING(dcd.descripcion_item, LEN(N'Arriendo local ') + 1, 200))
                    WHEN dcd.descripcion_item LIKE N'Arriendo %'
                        THEN LTRIM(SUBSTRING(dcd.descripcion_item, LEN(N'Arriendo ') + 1, 200))
                    ELSE NULL
                END AS cdo_local
            FROM dbo.msp_documentos_cobro_detalle dcd
            INNER JOIN dbo.msp_tipo_item_documento tid
                ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
            WHERE dcd.id_documento_cobro = :id
              AND tid.codigo_item = N'ARRIENDO'
        )
        SELECT
            da.orden_item,
            ISNULL(loc.cdo_local, ISNULL(da.cdo_local, N'-')) AS cdo_local,
            loc.metros_cuadrados,
            da.descripcion_item,
            da.subtotal AS valor_neto
        FROM detalle_arriendo da
        LEFT JOIN dbo.msp_locales loc
            ON loc.cdo_local = da.cdo_local
        ORDER BY da.orden_item ASC"
    );
    $stmtArriendo->bindValue(':id', $idDocumento, PDO::PARAM_INT);
    $stmtArriendo->execute();
    $arriendoDetalles = $stmtArriendo->fetchAll();
    if (
        $arriendoDetalles === []
        && (float) ($documento['subtotal_arriendo'] ?? 0) > 0.005
        && msp2TableExists($conn, 'msp_cierre_mensual')
        && msp2TableExists($conn, 'msp_contratos_arriendo')
        && msp2TableExists($conn, 'msp_contrato_locales')
    ) {
        $stmtArriendoFallback = $conn->prepare(
            "DECLARE @periodo DATE = :periodo;
             SELECT
                ROW_NUMBER() OVER (ORDER BY " . msp2LocalCodeNaturalOrderSql('loc.cdo_local') . ") AS orden_item,
                loc.cdo_local,
                loc.metros_cuadrados,
                CONCAT(N'Arriendo local ', loc.cdo_local) AS descripcion_item,
                CASE
                    WHEN UPPER(LTRIM(RTRIM(loc.cdo_local))) IN (N'OBRA', N'MODULAR') THEN CAST(140000 AS DECIMAL(18,2))
                    ELSE ROUND(ISNULL(loc.valor_arriendo_uf, 0) * ISNULL(cm.valor_uf, 0), 2)
                END AS valor_neto
             FROM dbo.msp_contratos_arriendo ca
             INNER JOIN dbo.msp_contrato_locales cl
                ON cl.id_contrato_arriendo = ca.id_contrato_arriendo
               AND cl.estado_relacion IN (1,2)
               AND cl.fecha_inicio <= EOMONTH(@periodo)
               AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= @periodo)
             INNER JOIN dbo.msp_locales loc
                ON loc.id_local = cl.id_local
             LEFT JOIN dbo.msp_cierre_mensual cm
                ON cm.periodo_facturacion = @periodo
             WHERE ca.id_tienda = :id_tienda
               AND ca.fecha_inicio <= EOMONTH(@periodo)
               AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= @periodo)
               AND ca.estado_contrato IN (1,2,3)"
        );
        $stmtArriendoFallback->bindValue(':periodo', (string) ($documento['periodo_facturacion'] ?? ''), PDO::PARAM_STR);
        $stmtArriendoFallback->bindValue(':id_tienda', (int) ($documento['id_tienda'] ?? 0), PDO::PARAM_INT);
        $stmtArriendoFallback->execute();
        $arriendoFallback = $stmtArriendoFallback->fetchAll() ?: [];
        if ($arriendoFallback !== []) {
            $arriendoDetalles = $arriendoFallback;
        }
    }

    $stmtServicios = $conn->prepare(
        "SELECT
            dcd.orden_item,
            tid.codigo_item,
            dcd.descripcion_item,
            dcd.cantidad,
            dcd.valor_unitario,
            dcd.subtotal
         FROM dbo.msp_documentos_cobro_detalle dcd
         INNER JOIN dbo.msp_tipo_item_documento tid
            ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
         WHERE dcd.id_documento_cobro = :id
           AND tid.codigo_item <> N'ARRIENDO'
         ORDER BY dcd.orden_item ASC, dcd.id_detalle_documento ASC"
    );
    $stmtServicios->bindValue(':id', $idDocumento, PDO::PARAM_INT);
    $stmtServicios->execute();
    $servicioDetalles = $stmtServicios->fetchAll();

    $stmtElectricidad = $conn->prepare(
        "SELECT
            loc.cdo_local,
            lm.lectura_anterior,
            lm.lectura_actual,
            cs.consumo_cobrado,
            ISNULL(pl.valor_kwh, 0) AS valor_kwh,
            cs.monto_total
         FROM dbo.msp_cobros_servicios cs
         INNER JOIN dbo.msp_lecturas_medidores lm
            ON lm.id_lectura = cs.id_lectura
         INNER JOIN dbo.msp_procesos_cobro_servicio p
            ON p.id_proceso_cobro = lm.id_proceso_cobro
         INNER JOIN dbo.msp_tipos_servicio ts
            ON ts.id_tipo_servicio = p.id_tipo_servicio
         INNER JOIN dbo.msp_medidores m
            ON m.id_medidor = lm.id_medidor
         INNER JOIN dbo.msp_locales loc
            ON loc.id_local = m.id_local
         INNER JOIN dbo.msp_ocupacion_locales ol
            ON ol.id_local = loc.id_local
           AND ol.id_tienda = :id_tienda
           AND ol.fecha_inicio <= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura)
           AND (ol.fecha_termino IS NULL OR ol.fecha_termino >= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura))
         LEFT JOIN dbo.msp_proceso_cobro_luz pl
            ON pl.id_proceso_cobro = p.id_proceso_cobro
         WHERE lm.periodo_facturacion = :periodo
           AND ts.codigo_servicio = N'LUZ'
         ORDER BY " . msp2LocalCodeNaturalOrderSql('loc.cdo_local')
    );
    $stmtElectricidad->bindValue(':id_tienda', (int) ($documento['id_tienda'] ?? 0), PDO::PARAM_INT);
    $stmtElectricidad->bindValue(':periodo', (string) ($documento['periodo_facturacion'] ?? ''), PDO::PARAM_STR);
    $stmtElectricidad->execute();
    $electricidadDetalles = $stmtElectricidad->fetchAll();

    $stmtGas = $conn->prepare(
        "SELECT
            loc.cdo_local,
            lm.lectura_anterior,
            lm.lectura_actual,
            cs.consumo_cobrado,
            ISNULL(pg.factor, 0) AS factor,
            ISNULL(pg.valor_litro, 0) AS valor_litro,
            cs.monto_total
         FROM dbo.msp_cobros_servicios cs
         INNER JOIN dbo.msp_lecturas_medidores lm
            ON lm.id_lectura = cs.id_lectura
         INNER JOIN dbo.msp_procesos_cobro_servicio p
            ON p.id_proceso_cobro = lm.id_proceso_cobro
         INNER JOIN dbo.msp_tipos_servicio ts
            ON ts.id_tipo_servicio = p.id_tipo_servicio
         INNER JOIN dbo.msp_medidores m
            ON m.id_medidor = lm.id_medidor
         INNER JOIN dbo.msp_locales loc
            ON loc.id_local = m.id_local
         INNER JOIN dbo.msp_ocupacion_locales ol
            ON ol.id_local = loc.id_local
           AND ol.id_tienda = :id_tienda
           AND ol.fecha_inicio <= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura)
           AND (ol.fecha_termino IS NULL OR ol.fecha_termino >= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura))
         LEFT JOIN dbo.msp_proceso_cobro_gas pg
            ON pg.id_proceso_cobro = p.id_proceso_cobro
         WHERE lm.periodo_facturacion = :periodo
           AND ts.codigo_servicio = N'GAS'
           AND ISNULL(cs.consumo_cobrado, 0) > 0
         ORDER BY " . msp2LocalCodeNaturalOrderSql('loc.cdo_local')
    );
    $stmtGas->bindValue(':id_tienda', (int) ($documento['id_tienda'] ?? 0), PDO::PARAM_INT);
    $stmtGas->bindValue(':periodo', (string) ($documento['periodo_facturacion'] ?? ''), PDO::PARAM_STR);
    $stmtGas->execute();
    $gasDetalles = $stmtGas->fetchAll();

    $stmtAgua = $conn->prepare(
        "SELECT
            loc.cdo_local,
            lm.lectura_anterior,
            lm.lectura_actual,
            cs.consumo_cobrado,
            cs.monto_total,
            cs.parametros_snapshot,
            m.codigo_medidor,
            m.id_medidor
         FROM dbo.msp_cobros_servicios cs
         INNER JOIN dbo.msp_lecturas_medidores lm
            ON lm.id_lectura = cs.id_lectura
         INNER JOIN dbo.msp_procesos_cobro_servicio p
            ON p.id_proceso_cobro = lm.id_proceso_cobro
         INNER JOIN dbo.msp_tipos_servicio ts
            ON ts.id_tipo_servicio = p.id_tipo_servicio
         INNER JOIN dbo.msp_medidores m
            ON m.id_medidor = lm.id_medidor
         INNER JOIN dbo.msp_locales loc
            ON loc.id_local = m.id_local
         INNER JOIN dbo.msp_ocupacion_locales ol
            ON ol.id_local = loc.id_local
           AND ol.id_tienda = :id_tienda
           AND ol.fecha_inicio <= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura)
           AND (ol.fecha_termino IS NULL OR ol.fecha_termino >= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura))
         WHERE lm.periodo_facturacion = :periodo
           AND ts.codigo_servicio = N'AGUA'
         ORDER BY " . msp2LocalCodeNaturalOrderSql('loc.cdo_local')
    );
    $stmtAgua->bindValue(':id_tienda', (int) ($documento['id_tienda'] ?? 0), PDO::PARAM_INT);
    $stmtAgua->bindValue(':periodo', (string) ($documento['periodo_facturacion'] ?? ''), PDO::PARAM_STR);
    $stmtAgua->execute();
    $aguaDetalles = $stmtAgua->fetchAll();

    $pagosHistoricos = [];
    if (msp2TableExists($conn, 'msp_pagos')) {
        $pagosTieneSaldoFavorGenerado = msp2ColumnExists($conn, 'msp_pagos', 'monto_saldo_favor_generado');
        $pagosTieneAplicaSaldoFavor = msp2ColumnExists($conn, 'msp_pagos', 'aplica_desde_saldo_favor');
        $pagoSelectSaldoFavor = $pagosTieneSaldoFavorGenerado
            ? 'p.monto_saldo_favor_generado'
            : 'CAST(0 AS DECIMAL(18,2)) AS monto_saldo_favor_generado';
        $pagoSelectAplicaSaldoFavor = $pagosTieneAplicaSaldoFavor
            ? 'p.aplica_desde_saldo_favor'
            : 'CAST(0 AS BIT) AS aplica_desde_saldo_favor';
        $stmtPagos = $conn->prepare(
            "SELECT
                p.id_pago,
                p.fecha_pago,
                p.monto_pagado,
                p.estado_pago,
                p.fecha_anulacion,
                p.motivo_anulacion,
                p.medio_pago,
                p.referencia_pago,
                p.observaciones,
                $pagoSelectSaldoFavor,
                $pagoSelectAplicaSaldoFavor
             FROM dbo.msp_pagos p
             WHERE p.id_documento_cobro = :id
             ORDER BY p.fecha_pago ASC, p.id_pago ASC"
        );
        $stmtPagos->bindValue(':id', $idDocumento, PDO::PARAM_INT);
        $stmtPagos->execute();
        $pagosHistoricos = $stmtPagos->fetchAll();
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo msp2Escape($e->getMessage());
    exit();
}

$ivaArriendo = round((float) ($documento['subtotal_arriendo'] ?? 0) * 0.19, 2);
$totalEsperado = round((float) ($documento['subtotal_arriendo'] ?? 0) + $ivaArriendo + (float) ($documento['subtotal_servicios'] ?? 0), 2);
$totalElectricidad = 0.0;
foreach ($electricidadDetalles as $electricidad) {
    $totalElectricidad += (float) ($electricidad['monto_total'] ?? 0);
}
$totalElectricidad = round($totalElectricidad, 2);
$totalGas = 0.0;
foreach ($gasDetalles as $gas) {
    $totalGas += (float) ($gas['monto_total'] ?? 0);
}
$totalGas = round($totalGas, 2);
$totalAguaDetalle = 0.0;
foreach ($aguaDetalles as $aguaDetalle) {
    $totalAguaDetalle += (float) ($aguaDetalle['monto_total'] ?? 0);
}
$totalAguaDetalle = round($totalAguaDetalle, 2);
$totalAgua = 0.0;
$totalServiciosLuzDoc = 0.0;
$totalServiciosGasDoc = 0.0;
$otrosCargosDetalles = [];
$totalOtrosCargos = 0.0;
foreach ($servicioDetalles as $detalleServicio) {
    $codigo = strtoupper(trim((string) ($detalleServicio['codigo_item'] ?? '')));
    $subtotalDetalle = (float) ($detalleServicio['subtotal'] ?? 0);
    if ($codigo === 'SERVICIO_AGUA') {
        $totalAgua += $subtotalDetalle;
    } elseif ($codigo === 'SERVICIO_LUZ') {
        $totalServiciosLuzDoc += $subtotalDetalle;
    } elseif ($codigo === 'SERVICIO_GAS') {
        $totalServiciosGasDoc += $subtotalDetalle;
    }
    if (in_array($codigo, ['ARRIENDO', 'SERVICIO_LUZ', 'SERVICIO_GAS', 'SERVICIO_AGUA'], true)) {
        continue;
    }
    $otrosCargosDetalles[] = $detalleServicio;
    $totalOtrosCargos += $subtotalDetalle;
}
$totalAgua = round($totalAgua, 2);
$totalServiciosLuzDoc = round($totalServiciosLuzDoc, 2);
$totalServiciosGasDoc = round($totalServiciosGasDoc, 2);
$totalOtrosCargos = round($totalOtrosCargos, 2);
$pagadoDocumento = 0.0;
foreach ($pagosHistoricos as $pago) {
    if ((int) ($pago['estado_pago'] ?? 0) === 1) {
        $pagadoDocumento += (float) ($pago['monto_pagado'] ?? 0);
    }
}
$pagadoDocumento = round($pagadoDocumento, 2);
$montoTotalDocumento = round((float) ($documento['monto_total'] ?? 0), 2);
$saldoPendienteDocumento = round((float) ($documento['saldo_pendiente'] ?? 0), 2);
$totalPagarRedondeado = max(0.0, ceil($saldoPendienteDocumento));
$estadoLabel = match ((int) ($documento['estado_documento'] ?? 0)) {
    1 => 'Borrador',
    2 => 'Emitido',
    3 => 'Pagado Parcial',
    4 => 'Pagado',
    5 => 'Anulado',
    default => 'Desconocido',
};
$filename = pdfBuildFilename($documento, $arriendoDetalles, $electricidadDetalles, $gasDetalles);

$obraKey = null;
$modularKey = null;
foreach ($arriendoDetalles as $detalle) {
    $localRaw = trim((string) ($detalle['cdo_local'] ?? ''));
    if ($localRaw === '') {
        continue;
    }
    $localNorm = mb_strtoupper($localRaw, 'UTF-8');
    if ($localNorm === 'OBRA') {
        $obraKey = $localRaw;
    } elseif ($localNorm === 'MODULAR') {
        $modularKey = $localRaw;
    }
}
$mergeObraModular = $obraKey !== null && $modularKey !== null;
$arriendoDisplay = [];
if ($mergeObraModular) {
    foreach ($arriendoDetalles as $detalle) {
        $localRaw = trim((string) ($detalle['cdo_local'] ?? ''));
        $localNorm = mb_strtoupper($localRaw, 'UTF-8');
        $key = ($localNorm === 'OBRA' || $localNorm === 'MODULAR') ? 'OBRA/MODULAR' : ($localRaw !== '' ? $localRaw : '-');
        if (!isset($arriendoDisplay[$key])) {
            $arriendoDisplay[$key] = [
                'cdo_local' => $key,
                'metros_cuadrados' => 0.0,
                'valor_neto' => 0.0,
            ];
        }
        $arriendoDisplay[$key]['metros_cuadrados'] += (float) ($detalle['metros_cuadrados'] ?? 0);
        $arriendoDisplay[$key]['valor_neto'] += (float) ($detalle['valor_neto'] ?? 0);
    }
    $arriendoDisplay = array_values($arriendoDisplay);
} else {
    $arriendoDisplay = $arriendoDetalles;
}

$html = '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Documento de Cobro</title>
    <style>
        @page { margin: 28px 24px; }
        body { font-family: "Segoe UI", DejaVu Sans, sans-serif; color: #1f2937; font-size: 12px; line-height: 1.45; }
        .header { border-bottom: 3px solid #0b3a6e; padding-bottom: 14px; margin-bottom: 20px; }
        .header-table { width: 100%; border-collapse: collapse; }
        .header-table td { vertical-align: top; }
        .header-left { padding-right: 16px; }
        .header-right { width: 42%; }
        .header-docbox { border: 1px solid #d7dee8; border-radius: 8px; padding: 10px 12px; background: #f8fafc; }
        .brand-kicker { color: #5b6778; font-size: 10px; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 4px; }
        .title { font-size: 24px; font-weight: bold; margin: 0 0 4px 0; color: #0b3a6e; }
        .muted { color: #5b6778; }
        .header-arrendatario { margin-top: 12px; }
        .meta-strip { width: 100%; border-collapse: collapse; margin-bottom: 18px; border: 1px solid #d7dee8; border-radius: 8px; overflow: hidden; }
        .meta-strip td { vertical-align: top; padding: 10px 12px; background: #ffffff; }
        .meta-strip td + td { border-left: 1px solid #d7dee8; }
        .meta-label { font-size: 10px; text-transform: uppercase; color: #5b6778; letter-spacing: 0.05em; margin-bottom: 6px; }
        .meta-strong { font-size: 14px; font-weight: 600; color: #1f2937; margin-bottom: 2px; }
        .meta-line { font-size: 12px; color: #1f2937; margin-bottom: 2px; }
        .meta-inline { margin-top: 6px; font-size: 11px; color: #5b6778; }
        .meta-inline-state { line-height: 1.2; }
        .box { border: 1px solid #d7dee8; border-radius: 8px; padding: 12px 14px; margin-bottom: 18px; background: #ffffff; }
        .box-title { font-size: 11px; text-transform: uppercase; color: #5b6778; margin-bottom: 8px; letter-spacing: 0.04em; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.items th, table.items td { border: 1px solid #d7dee8; padding: 8px 9px; }
        table.items th { background: #eef2f7; color: #0b3a6e; font-size: 11px; font-weight: 600; text-transform: uppercase; }
        table.items tbody tr:nth-child(even) td { background: #fbfcfe; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .summary { margin-top: 18px; width: 48%; margin-left: auto; border-collapse: collapse; }
        .summary td { padding: 8px 10px; border: 1px solid #d7dee8; }
        .summary tr td:first-child { background: #f8fafc; color: #5b6778; }
        .summary tr:nth-last-child(2) td { border-bottom: 0; }
        .footer { margin-top: 24px; font-size: 10px; color: #5b6778; border-top: 1px solid #d7dee8; padding-top: 10px; }
        .pill {
            display: inline-block;
            padding: 1px 7px;
            border: 1px solid #c7d3e3;
            border-radius: 999px;
            font-size: 10px;
            line-height: 1.15;
            color: #0b3a6e;
            background: #e7eff8;
            vertical-align: middle;
            position: relative;
            top: 3px;
        }
        .section-title { font-size: 12px; text-transform: uppercase; color: #0b3a6e; margin: 0 0 10px 0; letter-spacing: 0.04em; font-weight: 600; }
        .total-row td { background: #f5f7fb; font-weight: bold; color: #1f2937; }
        .payable-row td { background: #0b3a6e; color: #ffffff; font-weight: bold; font-size: 14px; padding-top: 11px; padding-bottom: 11px; border-color: #0b3a6e; border-top: 2px solid #0b3a6e; border-bottom: 2px solid #0b3a6e; border-left: 2px solid #0b3a6e; }
        .small-note { font-size: 10px; color: #5b6778; }
        .cell-note { display: block; margin-top: 3px; font-size: 10px; color: #5b6778; line-height: 1.25; }
    </style>
</head>
<body>
    <div class="header">
        <table class="header-table">
            <tr>
                <td class="header-left">
                    <div class="brand-kicker">MSP Arriendos</div>
                    <div class="title">Documento de Cobro</div>
                    <div class="header-arrendatario">
                        <div class="meta-label">Arrendatario</div>
                        <div class="meta-strong">' . msp2Escape((string) ($documento['nombre_arrendatario_snapshot'] ?? '')) . '</div>
                        <div class="meta-line">' . msp2Escape(msp2RutFormatDisplay((string) ($documento['rut_arrendatario_snapshot'] ?? ''))) . '</div>
                    </div>
                </td>
                <td class="header-right">
                    <div class="header-docbox">
                        <div class="meta-label">Documento</div>
                        <div class="meta-strong">Nro ' . msp2Escape((string) ($documento['numero_documento'] ?? '')) . '</div>
                        <div class="meta-line">Periodo ' . msp2Escape(pdfPeriodo((string) ($documento['periodo_facturacion'] ?? ''))) . '</div>
                        <div class="meta-inline">Emision ' . msp2Escape(pdfFecha((string) ($documento['fecha_emision'] ?? ''))) . '</div>
                        <div class="meta-inline">Vencimiento ' . msp2Escape(pdfFecha((string) ($documento['fecha_vencimiento'] ?? ''))) . '</div>
                        <div class="meta-inline meta-inline-state">Estado <span class="pill">' . msp2Escape($estadoLabel) . '</span></div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="box">
        <div class="section-title">Arriendo</div>
        <table class="items">
            <thead>
                <tr>
                    <th width="22%" class="text-center">Local</th>
                    <th width="20%" class="text-right">m2</th>
                    <th width="26%" class="text-right">Valor Neto</th>
                </tr>
            </thead>
            <tbody>';

if ($arriendoDisplay === []) {
    $html .= '<tr><td colspan="3" class="muted">Sin detalle de arriendo registrado.</td></tr>';
} else {
    foreach ($arriendoDisplay as $detalle) {
        $html .= '<tr>
            <td class="text-center">' . msp2Escape((string) ($detalle['cdo_local'] ?? '-')) . '</td>
            <td class="text-right">' . msp2Escape(number_format((float) ($detalle['metros_cuadrados'] ?? 0), 2, ',', '.')) . '</td>
            <td class="text-right">' . msp2Escape(pdfMonto($detalle['valor_neto'] ?? 0)) . '</td>
        </tr>';
    }
}

$html .= '<tr class="total-row">
            <td colspan="2" class="text-right">IVA arriendo 19%</td>
            <td class="text-right">' . msp2Escape(pdfMonto($ivaArriendo)) . '</td>
        </tr>
        <tr class="total-row">
            <td colspan="2" class="text-right">Total arriendo</td>
            <td class="text-right">' . msp2Escape(pdfMonto(((float) ($documento['subtotal_arriendo'] ?? 0)) + $ivaArriendo)) . '</td>
        </tr>
        </tbody>
        </table>
    </div>';

if ($electricidadDetalles !== []) {
    $html .= '
    <div class="box">
        <div class="section-title">Electricidad</div>
        <table class="items">
            <thead>
                <tr>
                    <th width="16%" class="text-center">Cod. Local</th>
                    <th width="16%" class="text-right">Lect. Anterior</th>
                    <th width="16%" class="text-right">Lect. Actual</th>
                    <th width="14%" class="text-right">Consumo</th>
                    <th width="16%" class="text-right">Valor kWh</th>
                    <th width="18%" class="text-right">Valor Total</th>
                </tr>
            </thead>
            <tbody>';

    foreach ($electricidadDetalles as $detalle) {
        $html .= '<tr>
            <td class="text-center">' . msp2Escape((string) ($detalle['cdo_local'] ?? '-')) . '</td>
            <td class="text-right">' . msp2Escape(number_format((float) ($detalle['lectura_anterior'] ?? 0), 0, ',', '.')) . '</td>
            <td class="text-right">' . msp2Escape(number_format((float) ($detalle['lectura_actual'] ?? 0), 0, ',', '.')) . '</td>
            <td class="text-right">' . msp2Escape(number_format((float) ($detalle['consumo_cobrado'] ?? 0), 0, ',', '.')) . '</td>
            <td class="text-right">' . msp2Escape(number_format((float) ($detalle['valor_kwh'] ?? 0), 2, ',', '.')) . '</td>
            <td class="text-right">' . msp2Escape(pdfMonto($detalle['monto_total'] ?? 0)) . '</td>
        </tr>';
    }

    $html .= '<tr class="total-row">
                <td colspan="5" class="text-right">Total Electricidad</td>
                <td class="text-right">' . msp2Escape(pdfMonto($totalElectricidad)) . '</td>
            </tr>
            </tbody>
        </table>
    </div>';
}

if ($gasDetalles !== []) {
    $html .= '
    <div class="box">
        <div class="section-title">Gas</div>
        <table class="items">
            <thead>
                <tr>
                    <th width="14%" class="text-center">Cod. Local</th>
                    <th width="14%" class="text-right">Lect. Anterior</th>
                    <th width="14%" class="text-right">Lect. Actual</th>
                    <th width="12%" class="text-right">Consumo</th>
                    <th width="14%" class="text-right">Factor</th>
                    <th width="14%" class="text-right">Valor litro</th>
                    <th width="18%" class="text-right">Valor Total</th>
                </tr>
            </thead>
            <tbody>';

    foreach ($gasDetalles as $detalle) {
        $html .= '<tr>
            <td class="text-center">' . msp2Escape((string) ($detalle['cdo_local'] ?? '-')) . '</td>
            <td class="text-right">' . msp2Escape(number_format((float) ($detalle['lectura_anterior'] ?? 0), 0, ',', '.')) . '</td>
            <td class="text-right">' . msp2Escape(number_format((float) ($detalle['lectura_actual'] ?? 0), 0, ',', '.')) . '</td>
            <td class="text-right">' . msp2Escape(number_format((float) ($detalle['consumo_cobrado'] ?? 0), 0, ',', '.')) . '</td>
            <td class="text-right">' . msp2Escape(number_format((float) ($detalle['factor'] ?? 0), 2, ',', '.')) . '</td>
            <td class="text-right">' . msp2Escape(number_format((float) ($detalle['valor_litro'] ?? 0), 2, ',', '.')) . '</td>
            <td class="text-right">' . msp2Escape(pdfMonto($detalle['monto_total'] ?? 0)) . '</td>
        </tr>';
    }

    $html .= '<tr class="total-row">
                <td colspan="6" class="text-right">Total Gas</td>
                <td class="text-right">' . msp2Escape(pdfMonto($totalGas)) . '</td>
            </tr>
            </tbody>
        </table>
    </div>';
}

if ($aguaDetalles !== []) {
    $aguaBreakdowns = [];
    $html .= '
    <div class="box">
        <div class="section-title">Agua</div>
        <table class="items">
            <thead>
                <tr>
                    <th width="20%" class="text-center">Cod. Local</th>
                    <th width="20%" class="text-right">Lect. Anterior</th>
                    <th width="20%" class="text-right">Lect. Actual</th>
                    <th width="20%" class="text-right">Consumo</th>
                    <th width="20%" class="text-right">Valor Total</th>
                </tr>
            </thead>
            <tbody>';

    foreach ($aguaDetalles as $detalle) {
        $snapshot = json_decode((string) ($detalle['parametros_snapshot'] ?? ''), true);
        $sap = null;
        $sal = null;
        $tas = null;
        $divisor = null;
        $cargo = null;
        if (is_array($snapshot)) {
            if (isset($snapshot['servicio_agua_potable']) && is_numeric((string) $snapshot['servicio_agua_potable'])) {
                $sap = (float) $snapshot['servicio_agua_potable'];
            }
            if (isset($snapshot['servicio_alcantarillado']) && is_numeric((string) $snapshot['servicio_alcantarillado'])) {
                $sal = (float) $snapshot['servicio_alcantarillado'];
            }
            if (isset($snapshot['tratamiento_aguas_servidas']) && is_numeric((string) $snapshot['tratamiento_aguas_servidas'])) {
                $tas = (float) $snapshot['tratamiento_aguas_servidas'];
            }
            if (isset($snapshot['divisor']) && is_numeric((string) $snapshot['divisor'])) {
                $divisor = (float) $snapshot['divisor'];
            }
            if (isset($snapshot['cargo_fijo']) && is_numeric((string) $snapshot['cargo_fijo'])) {
                $cargo = (float) $snapshot['cargo_fijo'];
            }
        }
        $medidorLabel = trim((string) ($detalle['codigo_medidor'] ?? ''));
        if ($medidorLabel === '') {
            $fallbackId = trim((string) ($detalle['id_medidor'] ?? ''));
            $medidorLabel = $fallbackId !== '' ? 'Medidor ' . $fallbackId : 'Medidor';
        } else {
            $medidorLabel = 'Medidor ' . $medidorLabel;
        }

        $aguaBreakdowns[] = [
            'medidor' => $medidorLabel,
            'consumo' => is_numeric((string) ($detalle['consumo_cobrado'] ?? '')) ? (float) $detalle['consumo_cobrado'] : 0.0,
            'sap' => $sap,
            'sal' => $sal,
            'tas' => $tas,
            'divisor' => $divisor,
            'cargo_fijo' => $cargo,
        ];

        $html .= '<tr>
            <td class="text-center">' . msp2Escape((string) ($detalle['cdo_local'] ?? '-')) . '</td>
            <td class="text-right">' . msp2Escape(number_format((float) ($detalle['lectura_anterior'] ?? 0), 0, ',', '.')) . '</td>
            <td class="text-right">' . msp2Escape(number_format((float) ($detalle['lectura_actual'] ?? 0), 0, ',', '.')) . '</td>
            <td class="text-right">' . msp2Escape(number_format((float) ($detalle['consumo_cobrado'] ?? 0), 0, ',', '.')) . '</td>
            <td class="text-right">' . msp2Escape(pdfMonto($detalle['monto_total'] ?? 0)) . '</td>
        </tr>';
    }

    $html .= '<tr class="total-row">
                <td colspan="4" class="text-right">Total Agua</td>
                <td class="text-right">' . msp2Escape(pdfMonto($totalAguaDetalle)) . '</td>
            </tr>
            </tbody>
        </table>
    </div>';

    if ($aguaBreakdowns !== []) {
        $html .= '
    <div class="box">
        <div class="section-title">Desglose Agua</div>
        <table class="items">
            <thead>
                <tr>
                    <th width="18%" class="text-right">Consumo</th>
                    <th width="18%" class="text-right">Tarifa</th>
                    <th width="18%" class="text-right">Cons. general</th>
                    <th width="18%" class="text-right">Monto</th>
                    <th width="28%" class="text-center">Detalle</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($aguaBreakdowns as $bd) {
            $bdConsumo = (float) ($bd['consumo'] ?? 0);
            $bdDivisor = $bd['divisor'];
            $bdSap = $bd['sap'];
            $bdSal = $bd['sal'];
            $bdTas = $bd['tas'];
            $bdCargo = $bd['cargo_fijo'];
            $bdTarifaTotal = ($bdSap ?? 0) + ($bdSal ?? 0) + ($bdTas ?? 0);
            $bdVariable = ($bdDivisor !== null && $bdDivisor != 0)
                ? ($bdConsumo * $bdTarifaTotal / $bdDivisor)
                : null;
            $bdTotal = $bdVariable !== null
                ? $bdVariable + ($bdCargo ?? 0)
                : null;

            $html .= '<tr class="total-row">
                    <td colspan="5" class="text-right">' . msp2Escape((string) $bd['medidor']) . '</td>
                </tr>
                <tr>
                    <td class="text-right">-</td>
                    <td class="text-right">-</td>
                    <td class="text-right">-</td>
                    <td class="text-right">' . ($bdCargo !== null ? msp2Escape(pdfMonto($bdCargo)) : '-') . '</td>
                    <td class="text-center">Cargo fijo</td>
                </tr>
                <tr>
                    <td class="text-right">' . msp2Escape(number_format($bdConsumo, 0, ',', '.')) . '</td>
                    <td class="text-right">' . ($bdSap !== null ? msp2Escape(pdfMonto($bdSap)) : '-') . '</td>
                    <td class="text-right">' . ($bdDivisor !== null ? msp2Escape(number_format($bdDivisor, 0, ',', '.')) : '-') . '</td>
                    <td class="text-right">' . ($bdSap !== null && $bdDivisor !== null && $bdDivisor != 0
                        ? msp2Escape(pdfMonto($bdConsumo * $bdSap / $bdDivisor))
                        : '-') . '</td>
                    <td class="text-center">Servicio agua potable</td>
                </tr>
                <tr>
                    <td class="text-right">' . msp2Escape(number_format($bdConsumo, 0, ',', '.')) . '</td>
                    <td class="text-right">' . ($bdSal !== null ? msp2Escape(pdfMonto($bdSal)) : '-') . '</td>
                    <td class="text-right">' . ($bdDivisor !== null ? msp2Escape(number_format($bdDivisor, 0, ',', '.')) : '-') . '</td>
                    <td class="text-right">' . ($bdSal !== null && $bdDivisor !== null && $bdDivisor != 0
                        ? msp2Escape(pdfMonto($bdConsumo * $bdSal / $bdDivisor))
                        : '-') . '</td>
                    <td class="text-center">Servicio alcantarillado</td>
                </tr>
                <tr>
                    <td class="text-right">' . msp2Escape(number_format($bdConsumo, 0, ',', '.')) . '</td>
                    <td class="text-right">' . ($bdTas !== null ? msp2Escape(pdfMonto($bdTas)) : '-') . '</td>
                    <td class="text-right">' . ($bdDivisor !== null ? msp2Escape(number_format($bdDivisor, 0, ',', '.')) : '-') . '</td>
                    <td class="text-right">' . ($bdTas !== null && $bdDivisor !== null && $bdDivisor != 0
                        ? msp2Escape(pdfMonto($bdConsumo * $bdTas / $bdDivisor))
                        : '-') . '</td>
                    <td class="text-center">Tratamiento aguas servidas</td>
                </tr>
                <tr class="total-row">
                    <td colspan="3" class="text-right">Total a pagar</td>
                    <td class="text-right">' . ($bdTotal !== null ? msp2Escape(pdfMonto($bdTotal)) : '-') . '</td>
                    <td class="text-center">&nbsp;</td>
                </tr>';
        }

        $html .= '
            </tbody>
        </table>
    </div>';
    }
}

if ($otrosCargosDetalles !== []) {
    $html .= '
    <div class="box">
        <div class="section-title">Otros Cargos</div>
        <table class="items">
            <thead>
                <tr>
                    <th width="78%">Descripción</th>
                    <th width="22%" class="text-right">Monto</th>
                </tr>
            </thead>
            <tbody>';

    foreach ($otrosCargosDetalles as $detalle) {
        $html .= '<tr>
            <td>' . msp2Escape((string) ($detalle['descripcion_item'] ?? '-')) . '</td>
            <td class="text-right">' . msp2Escape(pdfMonto($detalle['subtotal'] ?? 0)) . '</td>
        </tr>';
    }

    $html .= '<tr class="total-row">
                <td class="text-right">Total Otros Cargos</td>
                <td class="text-right">' . msp2Escape(pdfMonto($totalOtrosCargos)) . '</td>
            </tr>
            </tbody>
        </table>
    </div>';
}

if ($electricidadDetalles === [] && $gasDetalles === [] && $aguaDetalles === [] && $otrosCargosDetalles === [] && (float) ($documento['subtotal_servicios'] ?? 0) > 0) {
    $html .= '
    <div class="box">
        <div class="section-title">Servicios</div>
        <div class="muted">El documento tiene servicios, pero no fue posible reconstruir su detalle para este PDF.</div>
    </div>';
}

$html .= '
    <div class="box">
        <div class="section-title">Historico de abonos</div>';

if ($pagosHistoricos === []) {
    $html .= '<div class="muted">No existen pagos registrados para este documento.</div>';
} else {
    $abonoNumero = 0;
    $html .= '
        <table class="items">
            <thead>
                <tr>
                    <th width="10%" class="text-center">Abono</th>
                    <th width="13%" class="text-center">Fecha</th>
                    <th width="16%" class="text-right">Monto</th>
                    <th width="13%" class="text-center">Situacion</th>
                    <th width="16%">Medio</th>
                    <th width="32%">Referencia / Observacion</th>
                </tr>
            </thead>
            <tbody>';

    foreach ($pagosHistoricos as $pago) {
        $abonoNumero++;
        $estadoPago = (int) ($pago['estado_pago'] ?? 0);
        $estadoPagoLabel = $estadoPago === 2 ? 'Anulado' : 'Aplicado';
        $referenciaParts = [];
        $detalleAnulacionParts = [];
        $referenciaPago = trim((string) ($pago['referencia_pago'] ?? ''));
        $observacionPago = trim((string) ($pago['observaciones'] ?? ''));
        $motivoAnulacion = trim((string) ($pago['motivo_anulacion'] ?? ''));
        $fechaAnulacion = trim((string) ($pago['fecha_anulacion'] ?? ''));
        $saldoFavorGenerado = (float) ($pago['monto_saldo_favor_generado'] ?? 0);
        $aplicaDesdeSaldoFavor = (int) ($pago['aplica_desde_saldo_favor'] ?? 0) === 1;

        if ($referenciaPago !== '') {
            $referenciaParts[] = 'Ref: ' . $referenciaPago;
        }
        if ($observacionPago !== '') {
            $referenciaParts[] = $observacionPago;
        }
        if ($aplicaDesdeSaldoFavor) {
            $referenciaParts[] = 'Aplicado desde saldo a favor tienda';
        }
        if ($saldoFavorGenerado > 0) {
            $referenciaParts[] = 'Generó saldo a favor: ' . pdfMonto($saldoFavorGenerado);
        }
        if ($estadoPago === 2 && $fechaAnulacion !== '') {
            $detalleAnulacionParts[] = 'Anulado el ' . pdfFecha($fechaAnulacion);
        }
        if ($estadoPago === 2 && $motivoAnulacion !== '') {
            $detalleAnulacionParts[] = 'Motivo: ' . $motivoAnulacion;
        }

        $referenciaTexto = $referenciaParts !== [] ? implode(' | ', $referenciaParts) : '-';
        $detalleAnulacionHtml = '';
        if ($detalleAnulacionParts !== []) {
            $detalleAnulacionHtml = '<span class="cell-note">' . msp2Escape(implode(' | ', $detalleAnulacionParts)) . '</span>';
        }

        $html .= '<tr>
            <td class="text-center">' . $abonoNumero . '</td>
            <td class="text-center">' . msp2Escape(pdfFecha((string) ($pago['fecha_pago'] ?? ''))) . '</td>
            <td class="text-right">' . msp2Escape(pdfMonto($pago['monto_pagado'] ?? 0)) . '</td>
            <td class="text-center">' . msp2Escape($estadoPagoLabel) . '</td>
            <td>' . msp2Escape((string) ($pago['medio_pago'] ?? '-')) . '</td>
            <td>' . msp2Escape($referenciaTexto) . $detalleAnulacionHtml . '</td>
        </tr>';
    }

    $html .= '
            </tbody>
        </table>';
}

$html .= '
    </div>';

$html .= '
    <table class="summary">
        <tr>
            <td>Total arriendo</td>
            <td class="text-right">' . msp2Escape(pdfMonto(((float) ($documento['subtotal_arriendo'] ?? 0)) + $ivaArriendo)) . '</td>
        </tr>
        <tr>
            <td>Total electricidad</td>
            <td class="text-right">' . msp2Escape(pdfMonto($totalServiciosLuzDoc > 0 ? $totalServiciosLuzDoc : $totalElectricidad)) . '</td>
        </tr>';

if ($gasDetalles !== [] || $totalServiciosGasDoc > 0) {
    $html .= '
        <tr>
            <td>Total gas</td>
            <td class="text-right">' . msp2Escape(pdfMonto($totalServiciosGasDoc > 0 ? $totalServiciosGasDoc : $totalGas)) . '</td>
        </tr>';
}

if ($totalAgua > 0 || $totalAguaDetalle > 0) {
    $html .= '
        <tr>
            <td>Total agua</td>
            <td class="text-right">' . msp2Escape(pdfMonto($totalAgua > 0 ? $totalAgua : $totalAguaDetalle)) . '</td>
        </tr>';
}

if ($totalOtrosCargos > 0) {
    $html .= '
        <tr>
            <td>Total otros cargos</td>
            <td class="text-right">' . msp2Escape(pdfMonto($totalOtrosCargos)) . '</td>
        </tr>';
}

$html .= '
        <tr>
            <td>Total documento</td>
            <td class="text-right">' . msp2Escape(pdfMonto($montoTotalDocumento)) . '</td>
        </tr>
        <tr>
            <td>Pago acumulado</td>
            <td class="text-right">' . msp2Escape(pdfMonto($pagadoDocumento)) . '</td>
        </tr>
        <tr>
            <td>Saldo pendiente</td>
            <td class="text-right">' . msp2Escape(pdfMonto($saldoPendienteDocumento)) . '</td>
        </tr>
        <tr class="payable-row">
            <td>Total a pagar</td>
            <td class="text-right">' . msp2Escape(pdfMontoPayable($totalPagarRedondeado)) . '</td>
        </tr>
    </table>';

$html .= '
    <div class="footer">
        Documento generado automaticamente el ' . msp2Escape(date('d-m-Y H:i')) . '.
    </div>
</body>
</html>';

try {
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isHtml5ParserEnabled', true);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();

    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');

    $dompdf->stream($filename, ['Attachment' => false]);
    exit();
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Error al generar PDF con Dompdf.' . PHP_EOL
        . msp2BuildDompdfDebugInfo($autoloadPath) . PHP_EOL
        . 'Detalle tecnico: ' . $e->getMessage();
    exit();
}
