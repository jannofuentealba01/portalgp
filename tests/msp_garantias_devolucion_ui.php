<?php
declare(strict_types=1);

// Render aislado de las plantillas con fixtures. No crea sesiones ni conecta a SQL.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo CLI.');
}
function msp2Escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function msp2Url(string $value): string { return '#vista-de-prueba'; }
function msp2CsrfField(): void { echo '<input type="hidden" name="_csrf" value="PRUEBA">'; }
function msp2RenderCsrfAutoFieldScript(): void {}
function gdMonto(mixed $value): string { return '$ ' . number_format((float) $value, 0, ',', '.'); }
function tdMonto(mixed $value): string { return '$ ' . number_format((float) $value, 2, ',', '.'); }
function gfMonto(mixed $value): string { return '$ ' . number_format((float) $value, 0, ',', '.'); }
require_once dirname(__DIR__) . '/msp/garantias/caja_admin_historial.php';
function uiEnsure(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function renderFixture(string $file, array $variables): string
{
    $source = file_get_contents($file);
    $html = substr($source, strpos($source, '<!doctype html>'));
    $html = str_replace("include dirname(__DIR__, 2) . '/templates/header.php';", '', $html);
    extract($variables, EXTR_SKIP);
    ob_start();
    eval('?>' . $html); // Solo plantilla del repositorio; nunca contenido externo/HTTP.
    return ob_get_clean();
}
function fixtureDom(string $html): DOMXPath
{
    $document = new DOMDocument();
    @$document->loadHTML('<?xml encoding="UTF-8">' . $html);
    return new DOMXPath($document);
}
$account = ['id_cuenta_tesoreria' => 1, 'tipo_cuenta' => 'CAJA', 'nombre_cuenta' => 'Caja de prueba',
    'banco' => '', 'numero_cuenta' => '', 'moneda' => 'CLP', 'saldo_actual' => '1000.00', 'ultima_fecha_movimiento' => '2026-10-06'];
$bank = array_replace($account, ['id_cuenta_tesoreria' => 7, 'tipo_cuenta' => 'BANCO', 'nombre_cuenta' => 'Banco de prueba']);
$view = ['flash' => null, 'error' => null, 'garantias' => [], 'cuentas' => [$account, $bank], 'historial' => [],
    'cajasRegistro' => [$account], 'integracionDisponible' => true, 'reintento' => [], 'idSolicitud' => str_repeat('a', 32),
    'idGarantiaSeleccionada' => 0, 'campo' => static fn(string $key, string $default = ''): string => msp2Escape($default)];
$path = dirname(__DIR__) . '/msp/garantias/devoluciones.php';
$xpath = fixtureDom(renderFixture($path, $view));
uiEnsure($xpath->query('//*[@id="gd_forma"]/option')->length === 2, 'Faltan parcial y total.');
uiEnsure($xpath->query('//*[@id="gd_medio"]/option')->length === 2, 'Faltan caja y banco.');
uiEnsure($xpath->query('//*[@id="gd_caja_admin"]')->length === 1, 'Falta la caja administrativa.');
uiEnsure($xpath->query('//input[@name="id_solicitud"]')->item(0)->getAttribute('value') === str_repeat('a', 32), 'Falta la clave del envio.');
uiEnsure($xpath->query('//*[@id="gd_emitir" and @disabled]')->length === 0, 'El boton instalado no esta habilitado.');
$view['integracionDisponible'] = false;
$xpath = fixtureDom(renderFixture($path, $view));
uiEnsure($xpath->query('//*[@id="gd_emitir" and @disabled]')->length === 1, 'Debe bloquearse si faltan migraciones.');
echo "OK: Formulario: origen, parcial/total, clave, caja administrativa y bloqueo sin migraciones.\n";

$actual = ['nombre_cuenta' => 'Banco de prueba', 'tipo_cuenta' => 'BANCO', 'tipo_movimiento' => 'DEVOLUCION_GARANTIA',
    'medio_pago' => 'TRANSFERENCIA', 'referencia' => 'PRUEBA', 'id_devolucion_garantia' => 123, 'monto' => '530000.00',
    'naturaleza' => 'S', 'estado_movimiento' => 'VIGENTE'];
$in = array_replace($actual, ['nombre_cuenta' => 'Caja de prueba', 'tipo_cuenta' => 'CAJA', 'es_administrativo' => 1,
    'banco_origen' => 'Banco de prueba', 'naturaleza' => 'E']);
$out = array_replace($in, ['naturaleza' => 'S']);
$html = renderFixture(dirname(__DIR__) . '/msp/tesoreria/control_diario.php', ['flash' => null, 'error' => null,
    'fecha' => '2026-10-06', 'entradaDia' => 0, 'salidaDia' => 530000, 'cuentas' => [$account, $bank],
    'cajas' => [$account], 'bancos' => [$bank], 'depositos' => [], 'administrativos' => [$in, $out], 'registros' => [$actual, $in, $out]]);
$xpath = fixtureDom($html);
uiEnsure($xpath->query('//tr[contains(@class,"td-movement-admin")]')->length === 2, 'Falta la pareja visible.');
uiEnsure(substr_count($html, 'Efecto en efectivo: $ 0,00') === 2 && substr_count($html, 'Devolución #123') === 3, 'Falta el efecto cero o el enlace comun.');
uiEnsure(str_contains($html, 'Entrada administrativa') && str_contains($html, 'Salida administrativa')
    && str_contains($html, 'Sin movimiento de efectivo') && str_contains($html, 'Movimiento real'), 'No se distinguen los movimientos.');
uiEnsure($xpath->query('//td[contains(@class,"text-nowrap")]')->length === 3, 'Los importes deben mantenerse en una linea.');
echo "OK: Tesoreria: 3 lineas vinculadas, pareja administrativa diferenciada e importes sin salto.\n";

$source = file_get_contents(dirname(__DIR__) . '/msp/tesoreria/control_diario.php');
uiEnsure(str_contains($source, 'foreach ($movimientos as $movimiento)') && str_contains($source, 'array_merge($movimientos, $administrativos)')
    && strpos($source, '$entradaDia = 0.0') < strpos($source, '$registros = array_merge'), 'Los indicadores deben calcularse antes de unir lineas administrativas.');
echo "OK: Indicadores se calculan exclusivamente con movimientos financieros reales.\n";

$pair = [
    ['naturaleza' => 'E', 'monto' => '530000.00', 'estado_movimiento' => 'ANULADO', 'caja_registro' => 'Caja <prueba>', 'id_devolucion_garantia' => 123],
    ['naturaleza' => 'S', 'monto' => '530000.00', 'estado_movimiento' => 'ANULADO', 'caja_registro' => 'Caja <prueba>', 'id_devolucion_garantia' => 123],
];
$movement = ['id_origen' => 321, 'fecha' => '2026-10-06', 'tipo' => 'DEVOLUCION', 'concepto' => 'Devolucion',
    'monto' => '530000.00', 'signo' => '-', 'estado' => 'REVERTIDA', 'id_documento' => null, 'id_cargo' => null,
    'medio' => 'TRANSFERENCIA', 'cuenta' => 'Banco de prueba', 'referencia' => 'Prueba', 'observaciones' => null];
$html = renderFixture(dirname(__DIR__) . '/msp/garantias/ficha.php', ['id' => 1, 'error' => null,
    'resumen' => ['nombre_locatario' => 'PRUEBA', 'id_contrato_arriendo' => 1, 'locales' => 'A-1', 'monto_pactado' => 530000,
        'monto_recibido' => 530000, 'monto_reservado' => 0, 'monto_aplicado' => 0, 'monto_devuelto' => 0, 'monto_disponible' => 530000],
    'movimientos' => [$movement, array_replace($movement, ['id_origen' => 1, 'tipo' => 'REVERSA_DEVOLUCION', 'estado' => 'REGISTRADA', 'signo' => '—'])],
    'cajaAdministrativa' => [321 => $pair]]);
$xpath = fixtureDom($html);
uiEnsure($xpath->query('//tbody/tr')->length === 2 && $xpath->query('//details')->length === 1, 'La pareja duplico eventos de la ficha.');
uiEnsure(str_contains($html, 'REVERSA_DEVOLUCION') && substr_count($html, 'ANULADO') === 2
    && str_contains($html, 'Caja &lt;prueba&gt;') && str_contains($html, 'Efecto en caja: $ 0,00'), 'Falta reversa, estados, escape o efecto cero.');
echo "OK: Ficha: reversa y pareja visibles, sin filas financieras duplicadas ni HTML sin escapar.\n";
