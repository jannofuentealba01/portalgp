<?php
declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/msp/control_diario/index.php');
$styles = file_get_contents(dirname(__DIR__) . '/msp/assets/views/control_diario--index.css');
if (!is_string($source) || !is_string($styles)) {
    throw new RuntimeException('No fue posible leer Control Diario.');
}

$assertions = [
    'UF base expone el valor completo' => preg_match('/class="rent-cell-value"\s+title="<\?php echo msp2Escape\(\'UF base:/s', $source) === 1,
    'electricidad expone el monto completo' => preg_match('/class="electricity-cell-value"\s+title="<\?php echo msp2Escape\(\'Electricidad:/s', $source) === 1,
    'gas expone el monto completo' => preg_match('/class="gas-cell-value"\s+title="<\?php echo msp2Escape\(\'Gas:/s', $source) === 1,
    'agua expone el monto completo' => preg_match('/class="water-cell-value"\s+title="<\?php echo msp2Escape\(\'Agua:/s', $source) === 1,
    'valores completos tienen descripción accesible' => substr_count($source, 'aria-label="<?php echo msp2Escape(') >= 4,
    'el lápiz de electricidad continúa disponible' => str_contains($source, 'class="electricity-edit-btn js-electricity-edit"'),
    'el lápiz de gas continúa disponible' => str_contains($source, 'class="gas-edit-btn js-gas-edit"'),
    'el lápiz de agua continúa disponible' => str_contains($source, 'class="water-edit-btn js-water-edit"'),
    'el lápiz de UF base continúa disponible' => str_contains($source, 'class="rent-edit-btn js-rent-edit"'),
    'todos los lápices usan el mismo estado inicial' => preg_match_all(
        '/\.gas-edit-btn,\s*\.water-edit-btn,\s*\.rent-edit-btn\s*\{\s*opacity:\s*1/s',
        $styles
    ) === 1,
    'todos los lápices aparecen al pasar el cursor' => str_contains($styles, '.electricity-edit-cell:hover .electricity-edit-btn,')
        && str_contains($styles, '.gas-edit-cell:hover .gas-edit-btn,')
        && str_contains($styles, '.water-edit-cell:hover .water-edit-btn,')
        && str_contains($styles, '.rent-edit-cell:hover .rent-edit-btn,'),
];

$failures = array_keys(array_filter($assertions, static fn (bool $passed): bool => !$passed));
if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}

echo 'OK: ' . count($assertions) . " verificaciones de valores editables superadas." . PHP_EOL;
