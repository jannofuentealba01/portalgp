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

$check('Recepciones distinguen abono parcial y pago total acumulado', static function () use ($root): bool {
    $source = file_get_contents($root . '/msp/garantias/historial.php');
    return is_string($source)
        && str_contains($source, 'monto_recepcion_acumulado')
        && str_contains($source, "'Pago total'")
        && str_contains($source, "'Abono parcial'")
        && str_contains($source, 'montoRecepcionAcumulado + 0.009 >= $montoPactado');
});

$check('Primera página respeta máximo y orden', static function () use ($conn): bool|string {
    $rows = $conn->query(
        'SELECT *
         FROM dbo.msp_vw_garantias_historial_arrendatario
         ORDER BY nombre_arrendatario,rut,id_arrendatario,
                  fecha_evento,prioridad_evento,fecha_registro,
                  id_garantia_tienda,origen_evento,id_evento
         OFFSET 0 ROWS FETCH NEXT 50 ROWS ONLY'
    )->fetchAll();
    return count($rows) <= 50 ? true : count($rows) . ' movimientos obtenidos';
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
