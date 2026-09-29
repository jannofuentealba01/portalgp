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
    'el modal de electricidad mantiene visible su pie' => preg_match(
        '/id="electricityCorrectionModal".*?modal-dialog-scrollable[^>]*>\s*<form class="modal-content"/s',
        $source
    ) === 1,
    'electricidad permite autorizar ajustes financieros protegidos' => preg_match(
        '/\$puedeAplicar\s*=\s*in_array\(\$nivelCorreccion.*?\[\'AUTORIZACION\',\'AJUSTE_FINANCIERO\'\]/s',
        $source
    ) === 1,
    'electricidad explica que conserva los movimientos financieros' => str_contains(
        $source,
        'se versionará el documento y se aplicará solamente la diferencia eléctrica.'
    ),
    'el lápiz de gas continúa disponible' => str_contains($source, 'class="gas-edit-btn js-gas-edit"'),
    'el modal de gas mantiene visible su pie' => preg_match(
        '/id="gasCorrectionModal".*?modal-dialog-scrollable[^>]*>\s*<form class="modal-content"/s',
        $source
    ) === 1,
    'gas permite autorizar ajustes financieros protegidos' => str_contains(
        $source,
        "in_array(\$nivelCorreccion, ['AUTORIZACION','AJUSTE_FINANCIERO'], true)"
    ),
    'gas explica que conserva los movimientos financieros' => str_contains(
        $source,
        'Se versionará el documento, se corregirá el gas y se aplicará solamente la diferencia financiera.'
    ),
    'el lápiz de agua continúa disponible' => str_contains($source, 'class="water-edit-btn js-water-edit"'),
    'el modal de agua mantiene visible su pie' => preg_match(
        '/id="waterCorrectionModal".*?modal-dialog-scrollable[^>]*>\s*<form class="modal-content"/s',
        $source
    ) === 1,
    'agua permite autorizar ajustes financieros protegidos' => str_contains(
        $source,
        'Se versionará el documento, se corregirá el agua y se aplicará solamente la diferencia financiera.'
    ),
    'agua explica que conserva los movimientos financieros' => str_contains(
        $source,
        'El documento está protegido y tu usuario no posee permiso de cierre mensual o configuración para autorizar el ajuste financiero de agua.'
    ),
    'el lápiz de UF base continúa disponible' => str_contains($source, 'class="rent-edit-btn js-rent-edit"'),
    'el modal UF mantiene visible su pie en pantallas de altura limitada' => preg_match(
        '/id="rentCorrectionModal".*?modal-dialog-scrollable[^>]*>\s*<form class="modal-content"/s',
        $source
    ) === 1,
    'UF base permite autorizar ajustes financieros protegidos' => str_contains(
        $source,
        "in_array(\$nivelCorreccionRent, ['AUTORIZACION','AJUSTE_FINANCIERO'], true)"
    ),
    'UF base explica que conserva los movimientos financieros' => str_contains(
        $source,
        'Se versionará el documento, se corregirá la UF Base y se aplicará solamente la diferencia financiera.'
    ),
    'UF base ya no presenta el ajuste financiero como una acción imposible' => !str_contains(
        $source,
        'La UF no puede sobrescribirse; corresponde un ajuste financiero.'
    ),
    'todos los lápices usan el mismo estado inicial' => preg_match_all(
        '/\.gas-edit-btn,\s*\.water-edit-btn,\s*\.rent-edit-btn\s*\{\s*opacity:\s*1/s',
        $styles
    ) === 1,
    'todos los lápices aparecen al pasar el cursor' => str_contains($styles, '.electricity-edit-cell:hover .electricity-edit-btn,')
        && str_contains($styles, '.gas-edit-cell:hover .gas-edit-btn,')
        && str_contains($styles, '.water-edit-cell:hover .water-edit-btn,')
        && str_contains($styles, '.rent-edit-cell:hover .rent-edit-btn,'),
    'gas y agua usan ancho compacto' => str_contains($styles, '.control-grid.month-single-mode .gas-col,')
        && str_contains($styles, 'width: 84px;')
        && str_contains($source, 'class="js-month-col gas-col"')
        && str_contains($source, 'class="js-month-col agua-col"'),
    'reserva usa ancho compacto' => str_contains($styles, '.control-grid.month-single-mode .reserva-col,')
        && str_contains($styles, 'width: 76px;')
        && str_contains($source, 'class="js-month-col reserva-col"'),
    'locales y arrendatario tienen una sola separación' => str_contains($styles, '.control-grid .sticky-col-local {')
        && str_contains($styles, 'box-shadow: none;')
        && str_contains($styles, 'border-left: 1px solid var(--color-border);'),
];

$failures = array_keys(array_filter($assertions, static fn (bool $passed): bool => !$passed));
if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}

echo 'OK: ' . count($assertions) . " verificaciones de valores editables superadas." . PHP_EOL;
