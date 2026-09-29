<?php
declare(strict_types=1);

/* Pruebas de solo lectura para la pantalla del historial de garantías. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once dirname(__DIR__) . '/db.php';

$root = dirname(__DIR__);
$checks = [];
$check = static function (string $name, callable $callback) use (&$checks): void {
    try {
        $result = $callback();
        $checks[] = [$name, $result === true, $result === true ? 'OK' : (string) $result];
    } catch (Throwable $exception) {
        $checks[] = [$name, false, $exception->getMessage()];
    }
};

$check('Selector de control e historial', static fn(): bool => is_file($root . '/msp/garantias/control_historial.php'));
$check('Pantalla del historial', static fn(): bool => is_file($root . '/msp/garantias/historial.php'));
$check('Estilos específicos del historial', static fn(): bool => is_file($root . '/msp/assets/views/garantias--historial.css'));

$check('Acceso principal actualizado', static function () use ($root): bool {
    $source = file_get_contents($root . '/msp/garantias/index.php');
    return is_string($source)
        && str_contains($source, 'Control e historial de garantías')
        && str_contains($source, "garantias/control_historial.php");
});

$check('Selector contiene las dos opciones', static function () use ($root): bool {
    $source = file_get_contents($root . '/msp/garantias/control_historial.php');
    return is_string($source)
        && str_contains($source, 'Historial de garantías')
        && str_contains($source, 'Control de garantías')
        && str_contains($source, "garantias/historial.php")
        && str_contains($source, "garantias/reporte.php");
});

$check('Límite fijo de 50 movimientos', static function () use ($root): bool {
    $source = file_get_contents($root . '/msp/garantias/historial.php');
    return is_string($source)
        && str_contains($source, 'MSP2_GARANTIAS_HISTORIAL_POR_PAGINA = 50')
        && str_contains($source, 'OFFSET :offset ROWS FETCH NEXT :limite ROWS ONLY');
});

$check('Historial estrictamente de solo lectura', static function () use ($root): bool {
    $source = file_get_contents($root . '/msp/garantias/historial.php');
    return is_string($source)
        && !str_contains($source, 'method="post"')
        && !str_contains($source, '$_POST')
        && !preg_match('/\b(INSERT|UPDATE|DELETE)\s+dbo\./i', $source);
});

$check('Historial usa orden financiero compacto', static function () use ($root): bool {
    $source = file_get_contents($root . '/msp/garantias/historial.php');
    $styles = file_get_contents($root . '/msp/assets/views/garantias--historial.css');
    return is_string($source)
        && is_string($styles)
        && str_contains($source, '<th>Tienda / contrato</th>')
        && str_contains($source, '<th class="text-end">Recepción</th>')
        && str_contains($source, '<th>Saldo de garantía</th>')
        && str_contains($source, '<th class="text-end">Egreso</th>')
        && str_contains($source, 'msp-guarantee-ledger-pair')
        && str_contains($styles, 'white-space: nowrap;');
});

$check('Historial no muestra leyendas narrativas', static function () use ($root): bool {
    $source = file_get_contents($root . '/msp/garantias/historial.php');
    return is_string($source)
        && !str_contains($source, '$movimiento[\'concepto\']')
        && !str_contains($source, '$movimiento[\'observaciones\']')
        && !str_contains($source, 'Monto trasladado:');
});

$check('Historial muestra movimientos en una sola tabla continua', static function () use ($root): bool {
    $source = file_get_contents($root . '/msp/garantias/historial.php');
    return is_string($source)
        && substr_count($source, '<thead class="table-light">') === 1
        && !str_contains($source, 'movimiento(s)</span>')
        && !str_contains($source, '<div class="card-header')
        && !str_contains($source, 'El historial de este arrendatario continúa');
});

$check('Encabezado del historial no se superpone a los movimientos', static function () use ($root): bool {
    $source = file_get_contents($root . '/msp/garantias/historial.php');
    $styles = file_get_contents($root . '/msp/assets/views/garantias--historial.css');
    $tableSystem = file_get_contents($root . '/msp/assets/table_system.js');
    return is_string($source)
        && is_string($styles)
        && is_string($tableSystem)
        && str_contains($source, 'data-gp-table-sticky="false"')
        && str_contains($tableSystem, "table.dataset.gpTableSticky !== 'false'")
        && str_contains($styles, '.gp-module-msp table.msp-guarantee-ledger-table.gp-table-sticky thead th')
        && str_contains($styles, 'position: static !important;')
        && str_contains($styles, 'top: auto !important;');
});

$check('Historial se ordena naturalmente por local antes de la fecha', static function () use ($root): bool {
    $source = file_get_contents($root . '/msp/garantias/historial.php');
    return is_string($source)
        && str_contains($source, 'AS local_orden(local_orden)')
        && str_contains($source, "WHEN local_orden LIKE N\\'[A-Za-z]%-%[0-9]%\\' THEN 0")
        && str_contains($source, "WHEN local_orden LIKE N\\'[0-9]%\\' THEN 1")
        && str_contains($source, 'TRY_CONVERT(INT,LEFT(')
        && str_contains($source, 'foreach ($movimientos as $movimiento)')
        && !str_contains($source, 'foreach ($grupos as $grupo)')
        && strpos($source, 'locales COLLATE Latin1_General_100_CI_AI') < strpos($source, 'fecha_evento,prioridad_evento');
});

$check('Recepciones distinguen abono parcial y pago total acumulado', static function () use ($root): bool {
    $source = file_get_contents($root . '/msp/garantias/historial.php');
    return is_string($source)
        && str_contains($source, 'monto_recepcion_acumulado')
        && str_contains($source, "'Pago total'")
        && str_contains($source, "'Abono parcial'")
        && str_contains($source, 'montoRecepcionAcumulado + 0.009 >= $montoPactado');
});

$check('Buscador admite coincidencias parciales sin distinguir acentos', static function () use ($root): bool {
    $source = file_get_contents($root . '/msp/garantias/historial.php');
    return is_string($source)
        && str_contains($source, 'msp2GarantiasHistorialPatronBusqueda')
        && str_contains($source, 'Latin1_General_100_CI_AI LIKE :q_nombre')
        && str_contains($source, "ESCAPE N\\'~\\'")
        && str_contains($source, 'type="search"')
        && str_contains($source, 'coincidencias parciales');
});

$check('Buscador parcial encuentra coincidencias reales', static function () use ($conn): bool|string {
    $candidate = $conn->query(
        "SELECT TOP 1 nombre_arrendatario
         FROM dbo.msp_vw_garantias_historial_arrendatario
         WHERE LEN(LTRIM(RTRIM(ISNULL(nombre_arrendatario,N''))))>=5
         ORDER BY nombre_arrendatario"
    )->fetchColumn();
    if (!is_string($candidate) || trim($candidate) === '') {
        return true;
    }

    $fragment = mb_substr(trim($candidate), 0, 5, 'UTF-8');
    $statement = $conn->prepare(
        "SELECT COUNT(*)
         FROM dbo.msp_vw_garantias_historial_arrendatario
         WHERE ISNULL(nombre_arrendatario,N'') COLLATE Latin1_General_100_CI_AI
               LIKE :fragment ESCAPE N'~'"
    );
    $statement->execute([':fragment' => '%' . $fragment . '%']);
    $matches = (int) $statement->fetchColumn();

    return $matches > 0 ? true : 'No se encontraron coincidencias para el fragmento ' . $fragment;
});

$check('Primera página respeta máximo y orden natural por local', static function () use ($conn): bool|string {
    $rows = $conn->query(
        'WITH movimientos AS (
            SELECT h.*,
                   LTRIM(RTRIM(LEFT(
                       local_base.local_base,
                       CHARINDEX(N\'/\',local_base.local_base+N\'/\')-1
                   ))) AS local_orden
            FROM dbo.msp_vw_garantias_historial_arrendatario h
            CROSS APPLY (VALUES (
                COALESCE(
                    NULLIF(LTRIM(RTRIM(h.locales)),N\'\'),
                    NULLIF(LTRIM(RTRIM(h.local_destino)),N\'\'),
                    NULLIF(LTRIM(RTRIM(h.tienda)),N\'\'),
                    NULLIF(LTRIM(RTRIM(h.nombre_arrendatario)),N\'\'),
                    N\'\'
                )
            )) AS local_base(local_base)
         )
         SELECT * FROM movimientos
         ORDER BY
             CASE
                 WHEN local_orden LIKE N\'[A-Za-z]%-%[0-9]%\' THEN 0
                 WHEN local_orden LIKE N\'[0-9]%\' THEN 1
                 ELSE 2
             END,
             CASE
                 WHEN local_orden LIKE N\'[A-Za-z]%-%[0-9]%\'
                 THEN LEFT(local_orden,CHARINDEX(N\'-\',local_orden)-1)
                 ELSE N\'\'
             END COLLATE Latin1_General_100_CI_AI,
             CASE
                 WHEN local_orden LIKE N\'[A-Za-z]%-%[0-9]%\' THEN
                     TRY_CONVERT(INT,LEFT(
                         SUBSTRING(local_orden,CHARINDEX(N\'-\',local_orden)+1,100),
                         PATINDEX(N\'%[^0-9]%\',SUBSTRING(local_orden,CHARINDEX(N\'-\',local_orden)+1,100)+N\'X\')-1
                     ))
                 WHEN local_orden LIKE N\'[0-9]%\' THEN
                     TRY_CONVERT(INT,LEFT(local_orden,PATINDEX(N\'%[^0-9]%\',local_orden+N\'X\')-1))
                 ELSE 2147483647
             END,
             CASE
                 WHEN local_orden LIKE N\'[A-Za-z]%-%[0-9]%\' OR local_orden LIKE N\'[0-9]%\' THEN N\'\'
                 ELSE local_orden
             END COLLATE Latin1_General_100_CI_AI,
             local_orden COLLATE Latin1_General_100_CI_AI,
             locales COLLATE Latin1_General_100_CI_AI,
             fecha_evento,prioridad_evento,fecha_registro,
             nombre_arrendatario,rut,id_arrendatario,
             id_garantia_tienda,origen_evento,id_evento
         OFFSET 0 ROWS FETCH NEXT 50 ROWS ONLY'
    )->fetchAll();
    if (count($rows) > 50) {
        return count($rows) . ' movimientos obtenidos';
    }

    $locals = array_values(array_unique(array_map(
        static fn(array $row): string => (string) $row['local_orden'],
        $rows
    )));
    $aIndex = array_search('A-1', $locals, true);
    $numericIndex = array_search('95', $locals, true);
    return $aIndex === false || $numericIndex === false || $aIndex < $numericIndex
        ? true
        : 'Los locales alfanuméricos no quedaron antes de los locales numéricos.';
});

$failed = 0;
foreach ($checks as [$name, $ok, $message]) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $name . ' - ' . $message . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}
echo 'Resultado: ' . (count($checks) - $failed) . '/' . count($checks) . " pruebas correctas.\n";
exit($failed === 0 ? 0 : 1);
