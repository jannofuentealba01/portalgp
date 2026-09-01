<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/permisos.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function msp2PermissionExists(string $permission): bool
{
    static $cache = [];
    if (array_key_exists($permission, $cache)) {
        return $cache[$permission];
    }
    try {
        $stmt = $GLOBALS['conn']->prepare('SELECT COUNT(*) FROM dbo.cr_permisos WHERE nombre_permiso = :permiso');
        $stmt->execute([':permiso' => $permission]);
        return $cache[$permission] = (int) $stmt->fetchColumn() > 0;
    } catch (Throwable) {
        return $cache[$permission] = false;
    }
}

function msp2CurrentUserHasPermission(string $permission): bool
{
    static $cache = [];
    $idUsuario = (int) ($_SESSION['usuario']['id'] ?? 0);
    if ($idUsuario <= 0) {
        return false;
    }
    $cacheKey = $idUsuario . '|' . $permission;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    if (tienePermiso($idUsuario, $permission)) {
        return $cache[$cacheKey] = true;
    }
    // Compatibilidad antes de instalar el patch: cuando el permiso específico
    // aún no existe, conserva temporalmente el acceso MSP anterior.
    return $cache[$cacheKey] = str_starts_with($permission, 'MSP ')
        && $permission !== 'MSP Arriendos'
        && !msp2PermissionExists($permission)
        && tienePermiso($idUsuario, 'MSP Arriendos');
}

function msp2FunctionalPermissions(): array
{
    return ['MSP Operacion', 'MSP Cobranza', 'MSP Cierre Mensual', 'MSP Reportes', 'MSP Configuracion'];
}

function msp2PermissionForCurrentRoute(): string
{
    $route = strtolower(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')));

    if (str_ends_with($route, '/msp/msp_menu.php') || str_ends_with($route, '/msp/ayuda/index.php')) {
        return '';
    }
    if (str_contains($route, '/msp/configuracion/')
        || str_ends_with($route, '/msp/catalogo_menu.php')
        || preg_match('#/msp/(rubros|comunas|estados_arrendatarios|estados_tiendas|estados_locales)/#', $route) === 1
        || preg_match('#/msp/catalogos/(feriados|bancos)\.php$#', $route) === 1
        || str_ends_with($route, '/msp/cobros/reglas_cobro_auto.php')) {
        return 'MSP Configuracion';
    }
    if (str_contains($route, '/msp/cierre_mensual/')) {
        return 'MSP Cierre Mensual';
    }
    if (str_contains($route, '/msp/dashboard/')
        || str_contains($route, '/msp/reportes/')
        || str_contains($route, '/msp/contabilidad/')
        || preg_match('#/msp/garantias/(reporte|exportar_reporte)\.php$#', $route) === 1
        || preg_match('#/msp/cobros/reporte_consumo_(agua|gas|electrico)\.php$#', $route) === 1) {
        return 'MSP Reportes';
    }
    if (str_contains($route, '/msp/cobranza/')
        || str_contains($route, '/msp/pagos/')
        || str_contains($route, '/msp/documentos_cobro/')
        || str_contains($route, '/msp/garantias/')
        || str_contains($route, '/msp/deuda_garantia/')) {
        return 'MSP Cobranza';
    }
    return 'MSP Operacion';
}

function msp2RequireAnyAccess(array $permissions): void
{
    if (!isset($_SESSION['usuario']['id'])) {
        echo "<script>alert('Debes iniciar sesión.'); window.location.href = '/portalgp/login.php';</script>";
        exit();
    }
    foreach ($permissions as $permission) {
        if (is_string($permission) && msp2CurrentUserHasPermission($permission)) {
            msp2RequireValidCsrfToken();
            return;
        }
    }
    echo "<script>alert('No tienes permiso para esta sección.'); window.location.href = '/portalgp/msp/msp_menu.php';</script>";
    exit();
}

function msp2RequireAccess(?string $permission = null): void
{
    if (!isset($_SESSION['usuario']['id'])) {
        echo "<script>alert('Debes iniciar sesión.'); window.location.href = '/portalgp/login.php';</script>";
        exit();
    }

    $permission = $permission ?? msp2PermissionForCurrentRoute();
    if ($permission === '') {
        msp2RequireAnyAccess(array_merge(msp2FunctionalPermissions(), ['MSP Arriendos']));
        return;
    }
    msp2RequireAnyAccess([$permission]);
}

function msp2Url(string $path = ''): string
{
    $base = '/portalgp/msp';

    if ($path === '') {
        return $base . '/msp_menu.php';
    }

    return $base . '/' . ltrim($path, '/');
}

function msp2ModuleAvailable(string $relativePath): bool
{
    return is_file(__DIR__ . '/' . ltrim($relativePath, '/'));
}

function msp2PendingBadgeCount(): int
{
    static $count = null;
    if (is_int($count)) {
        return $count;
    }
    try {
        require_once __DIR__ . '/services/PendientesService.php';
        $service = new PendientesService($GLOBALS['conn']);
        $count = (int) ($service->resumen()['total'] ?? 0);
    } catch (Throwable) {
        $count = 0;
    }
    return $count;
}

function msp2DescuentosArriendoEnabled(): bool
{
    return false;
}

function msp2QuickAccessMainSections(): array
{
    return [
        [
            'id' => 'alta',
            'label' => 'Gestión comercial y alta',
            'accent' => 'sect-admin',
            'icon' => 'bi-person-plus-fill',
            'items' => [
                [
                    'label' => 'Gestionar Arrendatarios',
                    'icon' => 'bi-people-fill',
                    'href' => msp2Url('arrendatarios/index.php'),
                    'enabled' => true,
                    'permission' => 'MSP Operacion',
                ],
                [
                    'label' => 'Locales y tiendas disponibles',
                    'icon' => 'bi-shop',
                    'href' => msp2Url('locales_tiendas/index.php'),
                    'enabled' => true,
                    'permission' => 'MSP Operacion',
                ],
                [
                    'label' => 'Contratos y asociación',
                    'icon' => 'bi-file-earmark-text',
                    'href' => msp2Url('contratos/index.php'),
                    'enabled' => true,
                    'permission' => 'MSP Operacion',
                ],
                [
                    'label' => 'Garantías',
                    'icon' => 'bi-shield-check',
                    'href' => msp2Url('garantias/index.php'),
                    'enabled' => msp2ModuleAvailable('garantias/index.php'),
                    'permission' => 'MSP Cobranza',
                ],
            ],
        ],
        [
            'id' => 'operacion',
            'label' => 'Operación mensual',
            'accent' => 'sect-facturacion',
            'icon' => 'bi-receipt-cutoff',
            'items' => [
                [
                    'label' => 'Bandeja de pendientes',
                    'icon' => 'bi-inbox-fill',
                    'href' => msp2Url('pendientes/index.php'),
                    'enabled' => msp2ModuleAvailable('pendientes/index.php'),
                    'badge' => msp2PendingBadgeCount(),
                    'permission' => 'MSP Operacion',
                ],
                [
                    'label' => 'Medidores y lecturas',
                    'icon' => 'bi-speedometer2',
                    'href' => msp2Url('catalogos/medidores.php'),
                    'enabled' => true,
                    'permission' => 'MSP Operacion',
                ],
                [
                    'label' => 'Generar documento de cobro',
                    'icon' => 'bi-lightning-charge-fill',
                    'href' => msp2Url('cobros/operacion_mensual.php'),
                    'enabled' => true,
                    'permission' => 'MSP Operacion',
                ],
                [
                    'label' => 'Correcciones selectivas',
                    'icon' => 'bi-funnel',
                    'href' => msp2Url('correcciones/index.php'),
                    'enabled' => true,
                    'permission' => 'MSP Operacion',
                ],
                [
                    'label' => 'Control diario',
                    'icon' => 'bi-table',
                    'href' => msp2Url('control_diario/index.php'),
                    'enabled' => true,
                    'permission' => 'MSP Operacion',
                ],
                [
                    'label' => 'Cierre mensual',
                    'icon' => 'bi-calendar-check',
                    'href' => msp2Url('cierre_mensual/index.php'),
                    'enabled' => msp2ModuleAvailable('cierre_mensual/index.php'),
                    'permission' => 'MSP Cierre Mensual',
                ],
            ],
        ],
        [
            'id' => 'cobranza',
            'label' => 'Cobranza y tesorería',
            'accent' => 'sect-cobranza',
            'icon' => 'bi-cash-stack',
            'items' => [
                [
                    'label' => 'Documentos de cobro',
                    'icon' => 'bi-receipt',
                    'href' => msp2Url('documentos_cobro/index.php'),
                    'enabled' => true,
                    'permission' => 'MSP Cobranza',
                ],
                [
                    'label' => 'Registrar pago',
                    'icon' => 'bi-cash-coin',
                    'href' => msp2Url('cobranza/registrar_pago_contrato.php'),
                    'enabled' => true,
                    'permission' => 'MSP Cobranza',
                ],
                [
                    'label' => 'Ajustes de cobranza',
                    'icon' => 'bi-sliders2',
                    'href' => msp2Url('cobranza/ajustes.php'),
                    'enabled' => true,
                    'permission' => 'MSP Cobranza',
                ],
                [
                    'label' => 'Respaldo PDFs',
                    'icon' => 'bi-archive',
                    'href' => msp2Url('pagos/archivos_pdf.php'),
                    'enabled' => true,
                    'permission' => 'MSP Cobranza',
                ],
            ],
        ],
        [
            'id' => 'cierre',
            'label' => 'Cierre y salida',
            'accent' => 'sect-cierre',
            'icon' => 'bi-box-arrow-right',
            'items' => [
                [
                    'label' => 'Término y cierre de contratos',
                    'icon' => 'bi-door-closed-fill',
                    'href' => msp2Url('cierre/index.php'),
                    'enabled' => true,
                    'permission' => 'MSP Operacion',
                ],
                [
                    'label' => 'Deudores exarrendatarios',
                    'icon' => 'bi-person-x-fill',
                    'href' => msp2Url('cobranza/deudores_exarrendatarios.php'),
                    'enabled' => true,
                    'permission' => 'MSP Cobranza',
                ],
            ],
        ],
        [
            'id' => 'reportes',
            'label' => 'Reportes y control',
            'accent' => 'sect-reportes',
            'icon' => 'bi-clipboard-data',
            'items' => [
                [
                    'label' => 'Dashboard',
                    'icon' => 'bi-speedometer2',
                    'href' => msp2Url('dashboard/index.php'),
                    'enabled' => true,
                    'permission' => 'MSP Reportes',
                ],
                [
                    'label' => 'Libro Diario',
                    'icon' => 'bi-journal-text',
                    'href' => msp2Url('contabilidad/libro.php'),
                    'enabled' => true,
                    'permission' => 'MSP Reportes',
                ],
                [
                    'label' => 'Aging de Deudores',
                    'icon' => 'bi-hourglass-split',
                    'href' => msp2Url('contabilidad/aging.php'),
                    'enabled' => true,
                    'permission' => 'MSP Reportes',
                ],
                [
                    'label' => 'Trazabilidad de cobros',
                    'icon' => 'bi-list-check',
                    'href' => msp2Url('reportes/trazabilidad.php'),
                    'enabled' => msp2ModuleAvailable('reportes/trazabilidad.php'),
                    'permission' => 'MSP Reportes',
                ],
            ],
        ],
        [
            'id' => 'configuracion',
            'label' => 'Configuración',
            'accent' => 'sect-catalogos',
            'icon' => 'bi-sliders',
            'items' => [
                [
                    'label' => 'Catálogos maestros',
                    'icon' => 'bi-collection-fill',
                    'href' => msp2Url('catalogo_menu.php'),
                    'enabled' => true,
                    'permission' => 'MSP Configuracion',
                ],
                [
                    'label' => 'Configuración de correos',
                    'icon' => 'bi-envelope-check',
                    'href' => msp2Url('configuracion/correos.php'),
                    'enabled' => true,
                    'permission' => 'MSP Configuracion',
                ],
            ],
        ],
    ];
}

function msp2QuickAccessMenuSections(): array
{
    $sections = msp2QuickAccessMainSections();

    foreach ($sections as &$section) {
        if ((string) ($section['id'] ?? '') !== 'cobranza') {
            continue;
        }

        $normalizedItems = [];
        foreach ((array) ($section['items'] ?? []) as $item) {
            $href = (string) ($item['href'] ?? '');

            if (str_contains($href, '/cobranza/registrar_pago.php')) {
                continue;
            }

            if (str_contains($href, '/cobranza/registrar_pago_contrato.php')) {
                $item['label'] = 'Registrar pago';
            }

            $normalizedItems[] = $item;
        }

        $section['items'] = $normalizedItems;
    }
    unset($section);

    foreach ($sections as &$section) {
        $section['items'] = array_values(array_filter(
            (array) ($section['items'] ?? []),
            static function (array $item): bool {
                $permission = (string) ($item['permission'] ?? '');
                return $permission === '' || msp2CurrentUserHasPermission($permission);
            }
        ));
    }
    unset($section);

    $sections = array_values(array_filter(
        $sections,
        static fn (array $section): bool => (array) ($section['items'] ?? []) !== []
    ));

    return $sections;
}

function msp2QuickAccessCatalogItems(): array
{
    return [
        [
            'label' => 'Rubros',
            'caption' => 'Gestiona el catálogo de rubros comerciales para tiendas.',
            'icon' => 'bi-tags-fill',
            'href' => msp2Url('rubros/index.php'),
            'enabled' => true,
        ],
        [
            'label' => 'Comunas',
            'caption' => 'Gestiona el catálogo de comunas para arrendatarios.',
            'icon' => 'bi-geo-alt-fill',
            'href' => msp2Url('comunas/index.php'),
            'enabled' => true,
        ],
        [
            'label' => 'Estados Arrendatarios',
            'caption' => 'Gestiona los estados operativos de arrendatarios.',
            'icon' => 'bi-person-check-fill',
            'href' => msp2Url('estados_arrendatarios/index.php'),
            'enabled' => true,
        ],
        [
            'label' => 'Estados de Tiendas',
            'caption' => 'Gestiona el catálogo de estados operativos de tiendas.',
            'icon' => 'bi-clipboard-check-fill',
            'href' => msp2Url('estados_tiendas/index.php'),
            'enabled' => true,
        ],
        [
            'label' => 'Estados de Locales',
            'caption' => 'Gestiona el catálogo de estados operativos de locales.',
            'icon' => 'bi-door-open-fill',
            'href' => msp2Url('estados_locales/index.php'),
            'enabled' => true,
        ],
        [
            'label' => 'Feriados',
            'caption' => 'Mantén el calendario de feriados para vencimientos.',
            'icon' => 'bi-calendar-event',
            'href' => msp2Url('catalogos/feriados.php'),
            'enabled' => true,
        ],
        [
            'label' => 'Bancos',
            'caption' => 'Catálogo de bancos para pagos con cheque en cobranza.',
            'icon' => 'bi-bank',
            'href' => msp2Url('catalogos/bancos.php'),
            'enabled' => true,
        ],
        [
            'label' => 'Reglas Cobro Auto',
            'caption' => 'CRUD de reglas automáticas (mora diaria y futuros cargos).',
            'icon' => 'bi-sliders',
            'href' => msp2Url('cobros/reglas_cobro_auto.php'),
            'enabled' => true,
        ],
    ];
}

function msp2QuickAccessSections(): array
{
    $sections = msp2QuickAccessMenuSections();
    if (msp2CurrentUserHasPermission('MSP Configuracion')) {
        $sections[] = [
            'id' => 'catalogos',
            'label' => 'Catálogos maestros',
            'accent' => 'sect-catalogos',
            'icon' => 'bi-collection-fill',
            'items' => msp2QuickAccessCatalogItems(),
        ];
    }

    return $sections;
}

function msp2CsrfToken(): string
{
    $token = $_SESSION['msp2_csrf_token'] ?? null;
    if (!is_string($token) || strlen($token) < 32) {
        $token = bin2hex(random_bytes(32));
        $_SESSION['msp2_csrf_token'] = $token;
    }

    return $token;
}

function msp2CsrfVerify(?string $providedToken): bool
{
    if (!is_string($providedToken) || $providedToken === '') {
        return false;
    }

    return hash_equals(msp2CsrfToken(), $providedToken);
}

function msp2IsAjaxRequest(): bool
{
    $requestedWith = strtolower(trim((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));
    if ($requestedWith === 'xmlhttprequest') {
        return true;
    }

    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    return str_contains($accept, 'application/json');
}

function msp2AbortInvalidCsrf(): never
{
    if (msp2IsAjaxRequest()) {
        http_response_code(419);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'ok' => false,
            'message' => 'La sesión de seguridad expiró. Recarga la página e intenta nuevamente.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    msp2SetFlash('warning', 'La sesión de seguridad expiró. Recarga la pantalla e intenta nuevamente.');
    msp2Redirect();
}

function msp2RequireValidCsrfToken(): void
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        return;
    }

    $token = trim((string) ($_POST['_csrf'] ?? ''));

    if ($token === '') {
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $token = is_string($headerToken) ? trim($headerToken) : '';
    }

    if (!msp2CsrfVerify($token)) {
        msp2AbortInvalidCsrf();
    }
}

function msp2CsrfField(): void
{
    echo '<input type="hidden" name="_csrf" value="' . msp2Escape(msp2CsrfToken()) . '">';
}

function msp2RenderCsrfAutoFieldScript(): void
{
    static $rendered = false;

    if ($rendered) {
        return;
    }

    $rendered = true;
    $tokenJson = json_encode(msp2CsrfToken(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if (!is_string($tokenJson)) {
        return;
    }

    echo '<script>(function(){'
        . 'const token=' . $tokenJson . ';'
        . 'const ensure=function(form){'
        . 'if(!(form instanceof HTMLFormElement)){return;}'
        . 'const method=(form.getAttribute("method")||"get").toLowerCase();'
        . 'if(method!=="post"){return;}'
        . 'let csrfInput=form.querySelector(\'input[name="_csrf"]\');'
        . 'if(!csrfInput){csrfInput=document.createElement("input");csrfInput.type="hidden";csrfInput.name="_csrf";form.appendChild(csrfInput);}'
        . 'csrfInput.value=token;'
        . '};'
        . 'const scan=function(){document.querySelectorAll("form").forEach(ensure);};'
        . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",scan,{once:true});}else{scan();}'
        . 'document.addEventListener("submit",function(event){ensure(event.target);},true);'
        . 'window.msp2CsrfToken=token;'
        . '})();</script>';
}

function msp2SignedToken(string $scope, array $claims): string
{
    if (!isset($_SESSION['usuario']['id'])) {
        throw new RuntimeException('No hay sesión activa para firmar enlaces.');
    }

    ksort($claims);
    $payload = $scope . '|' . json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $secret = 'msp2|' . session_id() . '|' . (string) $_SESSION['usuario']['id'];

    return hash_hmac('sha256', $payload, $secret);
}

function msp2BuildSignedUrl(string $path, array $params, string $scope, int $ttlSeconds = 300): string
{
    $expiresAt = time() + max(60, $ttlSeconds);
    $params['exp'] = $expiresAt;
    $params['sig'] = msp2SignedToken($scope, $params);

    return msp2Url($path . '?' . http_build_query($params));
}

function msp2VerifySignedParams(string $scope, array $params): bool
{
    if (!isset($params['exp'], $params['sig'])) {
        return false;
    }

    $expiresAt = filter_var($params['exp'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    if ($expiresAt === false || $expiresAt < time()) {
        return false;
    }

    $signature = (string) $params['sig'];
    unset($params['sig']);

    $expected = msp2SignedToken($scope, $params);
    return hash_equals($expected, $signature);
}

function msp2Redirect(string $path = ''): never
{
    header('Location: ' . msp2Url($path));
    exit();
}

function msp2SetFlash(string $type, string $message, array $meta = []): void
{
    $payload = [
        'type' => $type,
        'message' => $message,
    ];

    if ($meta !== []) {
        $payload['meta'] = $meta;
    }

    $_SESSION['msp2_flash'] = $payload;
}

function msp2PullFlash(): ?array
{
    if (!isset($_SESSION['msp2_flash'])) {
        return null;
    }

    $flash = $_SESSION['msp2_flash'];
    unset($_SESSION['msp2_flash']);

    return $flash;
}

function msp2RenderFlash(?array $flash): void
{
    msp2RenderCsrfAutoFieldScript();

    if ($flash === null) {
        return;
    }

    include dirname(__DIR__) . '/templates/flash.php';
}

function msp2NormalizeText(?string $value): string
{
    $normalized = preg_replace('/\s+/u', ' ', trim((string) $value));

    return $normalized ?? '';
}

function msp2NormalizeLocalCode(?string $value): string
{
    $code = msp2NormalizeText($value);

    if ($code === '') {
        return '';
    }

    if (preg_match('/^\d+$/', $code) === 1) {
        return $code;
    }

    if (preg_match('/^([A-Za-z])-([0-9]+)([A-Za-z])$/', $code, $matches) === 1) {
        return strtoupper($matches[1]) . '-' . $matches[2] . strtolower($matches[3]);
    }

    if (preg_match('/^([A-Za-z])-([0-9]+)$/', $code, $matches) === 1) {
        return strtoupper($matches[1]) . '-' . $matches[2];
    }

    return $code;
}

function msp2LocalCodeKey(?string $value): string
{
    $code = msp2NormalizeLocalCode($value);
    if ($code === '') {
        return '';
    }

    return strtoupper($code);
}

function msp2LocalCodeNaturalOrderSql(string $columnExpr): string
{
    // Orden natural para códigos como:
    // A-1, A-2, A-3A, ... | B, C, ... | 89, 90, ... | GYM, MODULAR, ...
    $codeExpr = "UPPER(LTRIM(RTRIM({$columnExpr})))";
    $afterDashExpr = "SUBSTRING({$codeExpr}, 3, 100)";
    $numericTokenExpr = "(CASE
        WHEN RIGHT({$afterDashExpr}, 1) LIKE '[A-Z]' AND LEN({$afterDashExpr}) > 1
            THEN LEFT({$afterDashExpr}, LEN({$afterDashExpr}) - 1)
        ELSE {$afterDashExpr}
    END)";
    $numericValueExpr = "TRY_CONVERT(INT, {$numericTokenExpr})";
    $suffixExpr = "(CASE
        WHEN RIGHT({$afterDashExpr}, 1) LIKE '[A-Z]' AND LEN({$afterDashExpr}) > 1
            THEN RIGHT({$afterDashExpr}, 1)
        ELSE ''
    END)";

    $isAlphaNumberLocalExpr = "(SUBSTRING({$codeExpr}, 2, 1) = '-' AND LEFT({$codeExpr}, 1) LIKE '[A-Z]' AND {$numericValueExpr} IS NOT NULL)";
    $isSingleLetterExpr = "(LEN({$codeExpr}) = 1 AND {$codeExpr} LIKE '[A-Z]')";
    $isNumericExpr = "({$codeExpr} <> '' AND {$codeExpr} NOT LIKE '%[^0-9]%')";
    $namedTailRankExpr = "(CASE
        WHEN {$codeExpr} = 'PELUQUERIA' THEN 0
        WHEN {$codeExpr} = 'GYM' THEN 1
        WHEN {$codeExpr} = 'OBRA' THEN 2
        WHEN {$codeExpr} = 'MODULAR' THEN 3
        WHEN {$codeExpr} LIKE 'ESPACIO%' THEN 4
        ELSE NULL
    END)";

    return implode(",\n", [
        "CASE
            WHEN {$isAlphaNumberLocalExpr} THEN 0
            WHEN {$isSingleLetterExpr} THEN 1
            WHEN {$isNumericExpr} THEN 2
            WHEN {$namedTailRankExpr} IS NOT NULL THEN 3
            ELSE 4
        END",
        "CASE WHEN {$namedTailRankExpr} IS NOT NULL THEN {$namedTailRankExpr} END",
        "CASE WHEN {$isAlphaNumberLocalExpr} THEN LEFT({$codeExpr}, 1) END",
        "CASE WHEN {$isAlphaNumberLocalExpr} THEN {$numericValueExpr} END",
        "CASE WHEN {$isAlphaNumberLocalExpr} THEN {$suffixExpr} END",
        "CASE WHEN {$isSingleLetterExpr} THEN {$codeExpr} END",
        "CASE WHEN {$isNumericExpr} THEN TRY_CONVERT(INT, {$codeExpr}) END",
        $codeExpr,
    ]);
}


function msp2LocalCodeSortTuple(string $raw): array
{
    $code = strtoupper(msp2NormalizeLocalCode($raw));
    if ($code === '') {
        return [5, 999, '', 999999, '', $code];
    }

    if (preg_match('/^([A-Z])-([0-9]+)([A-Z]?)$/', $code, $m) === 1) {
        $block = $m[1];
        $num = (int) $m[2];
        $suffix = $m[3] ?? '';
        return [0, ord($block), $block, $num, $suffix, $code];
    }

    if (preg_match('/^[A-Z]$/', $code) === 1) {
        return [1, ord($code), $code, 0, '', $code];
    }

    if (preg_match('/^[0-9]+$/', $code) === 1) {
        return [2, (int) $code, '', (int) $code, '', $code];
    }

    $namedRank = match (true) {
        $code === 'PELUQUERIA' => 0,
        $code === 'GYM' => 1,
        $code === 'OBRA' => 2,
        $code === 'MODULAR' => 3,
        str_starts_with($code, 'ESPACIO') => 4,
        default => 999,
    };
    if ($namedRank !== 999) {
        return [3, $namedRank, '', 0, '', $code];
    }

    return [4, 999, '', 999999, '', $code];
}

function msp2CompareLocalCode(string $a, string $b): int
{
    $ka = msp2LocalCodeSortTuple($a);
    $kb = msp2LocalCodeSortTuple($b);
    $len = min(count($ka), count($kb));
    for ($i = 0; $i < $len; $i++) {
        if ($ka[$i] === $kb[$i]) {
            continue;
        }
        return $ka[$i] <=> $kb[$i];
    }
    return count($ka) <=> count($kb);
}
function msp2Escape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function msp2RutSanitize(?string $value): string
{
    $rut = strtoupper(trim((string) $value));
    $rut = preg_replace('/[^0-9K]/', '', $rut);

    return $rut ?? '';
}

function msp2RutIsValid(string $body, string $dv): bool
{
    if ($body === '' || !ctype_digit($body)) {
        return false;
    }

    $sum = 0;
    $multiplier = 2;

    for ($i = strlen($body) - 1; $i >= 0; $i--) {
        $sum += ((int) $body[$i]) * $multiplier;
        $multiplier = $multiplier === 7 ? 2 : $multiplier + 1;
    }

    $remainder = 11 - ($sum % 11);
    $expectedDv = match ($remainder) {
        11 => '0',
        10 => 'K',
        default => (string) $remainder,
    };

    return strtoupper($dv) === $expectedDv;
}

function msp2RutNormalizeDb(?string $value): ?string
{
    $rut = msp2RutSanitize($value);

    if (strlen($rut) < 2) {
        return null;
    }

    $dv = substr($rut, -1);
    $body = substr($rut, 0, -1);

    if (!msp2RutIsValid($body, $dv)) {
        return null;
    }

    return $body . '-' . strtoupper($dv);
}

function msp2RutFormatDisplay(?string $value): string
{
    $rut = msp2RutSanitize($value);

    if (strlen($rut) < 2) {
        return trim((string) $value);
    }

    $dv = strtoupper(substr($rut, -1));
    $body = substr($rut, 0, -1);

    if ($body === '' || !ctype_digit($body)) {
        return trim((string) $value);
    }

    $formattedBody = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $body);

    return ($formattedBody ?? $body) . '-' . $dv;
}

function msp2TableExists(PDO $conn, string $tableName, string $schema = 'dbo'): bool
{
    static $cache = [];
    $cacheKey = strtolower($schema . '.' . $tableName);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $stmt = $conn->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = :schema
           AND TABLE_NAME = :table_name'
    );
    $stmt->bindValue(':schema', $schema, PDO::PARAM_STR);
    $stmt->bindValue(':table_name', $tableName, PDO::PARAM_STR);
    $stmt->execute();

    $exists = (int) $stmt->fetchColumn() > 0;
    $cache[$cacheKey] = $exists;
    return $exists;
}

/**
 * Mantiene la derivación histórica alineada con el saldo operativo real.
 * Los importes originales de la liquidación no se modifican porque constituyen
 * la fotografía del momento del cierre.
 */
function msp2SyncHistoricalDebt(PDO $conn, int $idContratoArriendo): void
{
    if ($idContratoArriendo <= 0 || !msp2TableExists($conn, 'msp_deudas_historicas')) {
        return;
    }

    $saldoCargosSql = 'CAST(0 AS DECIMAL(18,2))';
    if (msp2TableExists($conn, 'msp_cargos_contrato_local') && msp2TableExists($conn, 'msp_contrato_locales')) {
        $saldoCargosSql = "ISNULL((
            SELECT SUM(CASE
                WHEN ccl.estado_cargo IN (1,2) AND ccl.id_documento_cobro IS NULL
                THEN CASE
                    WHEN ISNULL(ccl.monto_cargo,0)-ISNULL(ccl.monto_aplicado_garantia,0)-ISNULL(ccl.monto_pagado_directo,0)>0
                    THEN ISNULL(ccl.monto_cargo,0)-ISNULL(ccl.monto_aplicado_garantia,0)-ISNULL(ccl.monto_pagado_directo,0)
                    ELSE 0
                END
                ELSE 0
            END)
            FROM dbo.msp_cargos_contrato_local ccl
            INNER JOIN dbo.msp_contrato_locales cl ON cl.id_contrato_local=ccl.id_contrato_local
            WHERE cl.id_contrato_arriendo=:id_contrato_cargos
        ),0)";
    }

    $sql = "UPDATE dh
            SET saldo_residual=saldo.saldo_actual,
                estado_deuda=CASE WHEN saldo.saldo_actual<=0.005 THEN N'SALDADA' ELSE N'ACTIVA' END,
                fecha_actualizacion=SYSDATETIME()
            FROM dbo.msp_deudas_historicas dh
            CROSS APPLY (
                SELECT CAST(
                    ISNULL((SELECT SUM(CASE WHEN dc.estado_documento IN (2,3) AND dc.saldo_pendiente>0
                                           THEN dc.saldo_pendiente ELSE 0 END)
                            FROM dbo.msp_documentos_cobro dc
                            WHERE dc.id_contrato_arriendo=:id_contrato_documentos),0)
                    + {$saldoCargosSql}
                    AS DECIMAL(18,2)
                ) AS saldo_actual
            ) saldo
            WHERE dh.id_contrato_arriendo=:id_contrato_deuda
              AND dh.estado_deuda=N'ACTIVA'";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id_contrato_documentos', $idContratoArriendo, PDO::PARAM_INT);
    if (str_contains($sql, ':id_contrato_cargos')) {
        $stmt->bindValue(':id_contrato_cargos', $idContratoArriendo, PDO::PARAM_INT);
    }
    $stmt->bindValue(':id_contrato_deuda', $idContratoArriendo, PDO::PARAM_INT);
    $stmt->execute();
}

function msp2SyncHistoricalDebtByDocument(PDO $conn, int $idDocumentoCobro): void
{
    if ($idDocumentoCobro <= 0 || !msp2TableExists($conn, 'msp_documentos_cobro')) {
        return;
    }

    $stmt = $conn->prepare(
        'SELECT id_contrato_arriendo
         FROM dbo.msp_documentos_cobro
         WHERE id_documento_cobro=:id_documento_cobro'
    );
    $stmt->bindValue(':id_documento_cobro', $idDocumentoCobro, PDO::PARAM_INT);
    $stmt->execute();
    $idContratoArriendo = (int) ($stmt->fetchColumn() ?: 0);
    if ($idContratoArriendo > 0) {
        msp2SyncHistoricalDebt($conn, $idContratoArriendo);
    }
}

/**
 * Regla comercial única para mostrar/imputar pagos por concepto.
 * En ejecución normal se lee desde SQL Server; el mapa local solo mantiene
 * compatibilidad con instalaciones antiguas donde aún no se aplicó el parche.
 */
function msp2PagoPrioridadImputacion(?string $codigoItem): int
{
    static $prioridades = null;

    if ($prioridades === null) {
        $prioridades = [
            'ARRIENDO' => 10,
            'SERVICIO_LUZ' => 20,
            'SERVICIO_GAS' => 30,
            'SERVICIO_AGUA' => 40,
            'MULTA' => 50,
            'DANO' => 60,
            'AJUSTE' => 70,
        ];

        $conn = $GLOBALS['conn'] ?? null;
        if ($conn instanceof PDO) {
            try {
                if (msp2TableExists($conn, 'msp_prioridades_imputacion_pago')) {
                    $rows = $conn->query('SELECT codigo_item,prioridad FROM dbo.msp_prioridades_imputacion_pago WHERE activo=1')->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    foreach ($rows as $row) {
                        $codigo = strtoupper(trim((string) ($row['codigo_item'] ?? '')));
                        $prioridad = (int) ($row['prioridad'] ?? 0);
                        if ($codigo !== '' && $prioridad > 0) {
                            $prioridades[$codigo] = $prioridad;
                        }
                    }
                }
            } catch (Throwable) {
                // El mapa seguro anterior permite abrir pantallas durante una actualización de esquema.
            }
        }
    }

    return $prioridades[strtoupper(trim((string) $codigoItem))] ?? 80;
}

function msp2ProcedureExists(PDO $conn, string $procedureName, string $schema = 'dbo'): bool
{
    static $cache = [];
    $cacheKey = strtolower($schema . '.' . $procedureName);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $stmt = $conn->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.ROUTINES
         WHERE ROUTINE_SCHEMA = :schema
           AND ROUTINE_NAME = :routine_name
           AND ROUTINE_TYPE = :routine_type'
    );
    $stmt->bindValue(':schema', $schema, PDO::PARAM_STR);
    $stmt->bindValue(':routine_name', $procedureName, PDO::PARAM_STR);
    $stmt->bindValue(':routine_type', 'PROCEDURE', PDO::PARAM_STR);
    $stmt->execute();

    $exists = (int) $stmt->fetchColumn() > 0;
    $cache[$cacheKey] = $exists;
    return $exists;
}

function msp2ColumnExists(PDO $conn, string $tableName, string $columnName, string $schema = 'dbo'): bool
{
    static $cache = [];
    $cacheKey = strtolower($schema . '.' . $tableName . '.' . $columnName);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $stmt = $conn->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = :schema
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    $stmt->bindValue(':schema', $schema, PDO::PARAM_STR);
    $stmt->bindValue(':table_name', $tableName, PDO::PARAM_STR);
    $stmt->bindValue(':column_name', $columnName, PDO::PARAM_STR);
    $stmt->execute();

    $exists = (int) $stmt->fetchColumn() > 0;
    $cache[$cacheKey] = $exists;
    return $exists;
}

function msp2EnsureConfiguracionTable(PDO $conn): void
{
    $conn->exec(
        "IF OBJECT_ID(N'dbo.msp_configuracion', N'U') IS NULL
        BEGIN
            CREATE TABLE dbo.msp_configuracion (
                clave NVARCHAR(120) NOT NULL CONSTRAINT PK_msp_configuracion PRIMARY KEY,
                valor NVARCHAR(4000) NULL,
                descripcion NVARCHAR(500) NULL,
                fecha_actualizacion DATETIME2(0) NOT NULL CONSTRAINT DF_msp_configuracion_fecha_actualizacion DEFAULT (SYSDATETIME()),
                id_usuario_actualizacion INT NULL
            );
        END"
    );
}

function msp2ConfiguracionGet(PDO $conn, string $clave, ?string $default = null): ?string
{
    $exists = (int) $conn->query("SELECT CASE WHEN OBJECT_ID(N'dbo.msp_configuracion', N'U') IS NULL THEN 0 ELSE 1 END")->fetchColumn();
    if ($exists !== 1) {
        return $default;
    }

    $stmt = $conn->prepare('SELECT valor FROM dbo.msp_configuracion WHERE clave = :clave');
    $stmt->bindValue(':clave', $clave, PDO::PARAM_STR);
    $stmt->execute();
    $value = $stmt->fetchColumn();

    return $value === false ? $default : (string) $value;
}

function msp2ConfiguracionSet(PDO $conn, string $clave, string $valor, string $descripcion = '', ?int $idUsuario = null): void
{
    msp2EnsureConfiguracionTable($conn);

    $stmt = $conn->prepare(
        'MERGE dbo.msp_configuracion AS target
         USING (SELECT :clave AS clave) AS source
            ON target.clave = source.clave
         WHEN MATCHED THEN
            UPDATE SET
                valor = :valor_update,
                descripcion = :descripcion_update,
                fecha_actualizacion = SYSDATETIME(),
                id_usuario_actualizacion = :id_usuario_update
         WHEN NOT MATCHED THEN
            INSERT (clave, valor, descripcion, id_usuario_actualizacion)
            VALUES (:clave_insert, :valor_insert, :descripcion_insert, :id_usuario_insert);'
    );
    $stmt->bindValue(':clave', $clave, PDO::PARAM_STR);
    $stmt->bindValue(':valor_update', $valor, PDO::PARAM_STR);
    $stmt->bindValue(':descripcion_update', $descripcion, PDO::PARAM_STR);
    $stmt->bindValue(':id_usuario_update', $idUsuario, $idUsuario !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->bindValue(':clave_insert', $clave, PDO::PARAM_STR);
    $stmt->bindValue(':valor_insert', $valor, PDO::PARAM_STR);
    $stmt->bindValue(':descripcion_insert', $descripcion, PDO::PARAM_STR);
    $stmt->bindValue(':id_usuario_insert', $idUsuario, $idUsuario !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->execute();
}

function msp2MailTenantDeliveryEnabled(PDO $conn): bool
{
    return msp2ConfiguracionGet($conn, 'mail_arrendatarios_habilitado', '0') === '1';
}

function msp2NormalizeDecimalInput(?string $value, int $scale = 6): array
{
    $raw = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', trim((string) $value));
    $raw = is_string($raw) ? $raw : '';

    if ($raw === '') {
        return [true, null];
    }

    $normalized = $raw;
    $lastComma = strrpos($normalized, ',');
    $lastDot = strrpos($normalized, '.');
    $decimalSeparator = null;
    $thousandSeparator = null;

    if ($lastComma !== false && $lastDot !== false) {
        if ($lastComma > $lastDot) {
            $decimalSeparator = ',';
            $thousandSeparator = '.';
        } else {
            $decimalSeparator = '.';
            $thousandSeparator = ',';
        }
    } elseif (substr_count($normalized, ',') > 1 && preg_match('/^\d{1,3}(,\d{3})+$/', $normalized) === 1) {
        $thousandSeparator = ',';
    } elseif (substr_count($normalized, '.') > 1 && preg_match('/^\d{1,3}(\.\d{3})+$/', $normalized) === 1) {
        $thousandSeparator = '.';
    } elseif ($lastComma !== false) {
        $decimalSeparator = ',';
    } elseif ($lastDot !== false) {
        $decimalSeparator = '.';
    }

    if ($thousandSeparator !== null) {
        $normalized = str_replace($thousandSeparator, '', $normalized);
    }

    if ($decimalSeparator !== null && $decimalSeparator !== '.') {
        $normalized = str_replace($decimalSeparator, '.', $normalized);
    }

    if (preg_match('/^\d+(?:\.\d+)?$/', $normalized) !== 1) {
        return [false, null];
    }

    $number = (float) $normalized;

    if ($number < 0) {
        return [false, null];
    }

    return [true, number_format($number, $scale, '.', '')];
}

function msp2FormatoDecimal(mixed $value, int $decimals = 2, string $prefix = ''): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    return $prefix . number_format((float) $value, $decimals, ',', '.');
}

function msp2ParseIniSizeToBytes(string $value): int
{
    $normalized = strtolower(trim($value));
    if ($normalized === '') {
        return 0;
    }

    if (preg_match('/^\d+$/', $normalized) === 1) {
        return (int) $normalized;
    }

    if (preg_match('/^([0-9]*\.?[0-9]+)\s*([kmgt])$/', $normalized, $matches) !== 1) {
        return 0;
    }

    $number = (float) $matches[1];
    $unit = $matches[2];

    if ($number <= 0) {
        return 0;
    }

    $multiplier = match ($unit) {
        'k' => 1024,
        'm' => 1024 * 1024,
        'g' => 1024 * 1024 * 1024,
        't' => 1024 * 1024 * 1024 * 1024,
        default => 1,
    };

    return (int) floor($number * $multiplier);
}

function msp2ResolveUploadMaxBytes(int $defaultBytes = 10485760): int
{
    $defaultLimit = $defaultBytes > 0 ? $defaultBytes : 10485760;
    $uploadIni = msp2ParseIniSizeToBytes((string) ini_get('upload_max_filesize'));
    $postIni = msp2ParseIniSizeToBytes((string) ini_get('post_max_size'));

    $limits = [];
    foreach ([$uploadIni, $postIni, $defaultLimit] as $limit) {
        if ($limit > 0) {
            $limits[] = $limit;
        }
    }

    if ($limits === []) {
        return $defaultLimit;
    }

    return min($limits);
}

function msp2FormatBytes(int $bytes): string
{
    $normalizedBytes = max(0, $bytes);
    if ($normalizedBytes >= 1024 * 1024) {
        return number_format($normalizedBytes / (1024 * 1024), 1, ',', '.') . ' MB';
    }

    if ($normalizedBytes >= 1024) {
        return number_format($normalizedBytes / 1024, 1, ',', '.') . ' KB';
    }

    return $normalizedBytes . ' B';
}

function msp2ImportUploadMaxBytes(): int
{
    return 1024 * 1024; // 1 MB
}

function msp2ValidateSpreadsheetUpload(mixed $file, ?int $maxBytes = null): array
{
    if (!is_array($file)) {
        return [false, 'Debes seleccionar un archivo válido para importar.', null];
    }

    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        $message = match ($uploadError) {
            UPLOAD_ERR_NO_FILE => 'Debes seleccionar un archivo válido para importar.',
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo supera el tamaño máximo permitido para la carga.',
            UPLOAD_ERR_PARTIAL => 'La carga del archivo quedó incompleta. Intenta nuevamente.',
            UPLOAD_ERR_NO_TMP_DIR => 'No hay un directorio temporal disponible para la carga.',
            UPLOAD_ERR_CANT_WRITE => 'No se pudo escribir el archivo cargado en disco.',
            UPLOAD_ERR_EXTENSION => 'Una extensión de PHP bloqueó la carga del archivo.',
            default => 'No fue posible procesar el archivo cargado.',
        };

        return [false, $message, null];
    }

    $originalName = trim((string) ($file['name'] ?? ''));
    $tmpName = (string) ($file['tmp_name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
        return [false, 'El archivo debe tener formato XLSX, XLS o CSV.', null];
    }

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return [false, 'La carga del archivo no es válida. Intenta nuevamente.', null];
    }

    $reportedSize = $file['size'] ?? null;
    $size = is_numeric($reportedSize) ? (int) $reportedSize : 0;
    if ($size <= 0) {
        $statSize = @filesize($tmpName);
        if (is_int($statSize) && $statSize > 0) {
            $size = $statSize;
        }
    }

    if ($size <= 0) {
        return [false, 'El archivo cargado está vacío o no pudo validarse.', null];
    }

    $maxAllowedBytes = $maxBytes ?? msp2ResolveUploadMaxBytes(10 * 1024 * 1024);
    if ($maxAllowedBytes > 0 && $size > $maxAllowedBytes) {
        return [false, 'El archivo supera el tamaño máximo permitido (' . msp2FormatBytes($maxAllowedBytes) . ').', null];
    }

    if (!function_exists('finfo_open')) {
        return [false, 'No se puede validar el tipo de archivo (extensión `fileinfo` no disponible).', null];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        return [false, 'No se puede validar el tipo MIME del archivo cargado.', null];
    }

    $mimeType = strtolower((string) finfo_file($finfo, $tmpName));
    finfo_close($finfo);

    if ($mimeType === '') {
        return [false, 'No fue posible determinar el tipo MIME del archivo cargado.', null];
    }

    $allowedMimesByExtension = [
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/x-zip-compressed',
        ],
        'xls' => [
            'application/vnd.ms-excel',
            'application/x-cdf',
            'application/x-ole-storage',
            'application/vnd.ms-office',
            'application/octet-stream',
        ],
        'csv' => [
            'text/csv',
            'text/plain',
            'application/csv',
            'application/vnd.ms-excel',
            'text/comma-separated-values',
        ],
    ];

    $allowedMimes = $allowedMimesByExtension[$extension] ?? [];
    if (!in_array($mimeType, $allowedMimes, true)) {
        return [false, 'El tipo MIME del archivo no es permitido (`' . $mimeType . '`).', null];
    }

    return [true, '', [
        'name' => $originalName !== '' ? $originalName : ('importacion.' . $extension),
        'tmp_name' => $tmpName,
        'extension' => $extension,
        'size' => $size,
        'mime' => $mimeType,
    ]];
}

function msp2NormalizeLookupKey(?string $value): string
{
    $text = mb_strtolower(trim((string) $value), 'UTF-8');
    $text = strtr($text, [
        'á' => 'a',
        'é' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ú' => 'u',
        'ü' => 'u',
        'ñ' => 'n',
    ]);
    $text = preg_replace('/[^a-z0-9]+/', '_', $text);

    return trim((string) $text, '_');
}

function msp2LoadSpreadsheetLibrary(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $previousLevel = error_reporting();
    error_reporting($previousLevel & ~E_DEPRECATED & ~E_USER_DEPRECATED);

    try {
        require_once dirname(__DIR__) . '/vendor/autoload.php';
    } finally {
        error_reporting($previousLevel);
    }

    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class, false)) {
        msp2RegisterSpreadsheetFallbackAutoloaders();
    }

    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        throw new RuntimeException('No fue posible cargar PhpSpreadsheet desde vendor/autoload.php ni desde el fallback interno.');
    }

    msp2ConfigureSpreadsheetValueBinder();
    $loaded = true;
}

function msp2ShouldIgnoreSpreadsheetWarning(int $severity, string $message, string $file): bool
{
    if (($severity & E_WARNING) === 0) {
        return false;
    }

    if (!str_contains($message, 'Trying to access array offset on value of type ')) {
        return false;
    }

    $normalizedFile = str_replace('\\', '/', $file);

    if (str_contains($normalizedFile, 'PhpSpreadsheet/Reader/Xlsx/Styles.php')) {
        return true;
    }

    if (str_contains($normalizedFile, 'PhpSpreadsheet/Cell/DefaultValueBinder.php')) {
        return true;
    }

    return false;
}

function msp2WithSpreadsheetCompatibility(callable $callback)
{
    $previousLevel = error_reporting();
    error_reporting($previousLevel & ~E_DEPRECATED & ~E_USER_DEPRECATED);

    set_error_handler(
        static function (int $severity, string $message, string $file): bool {
            return msp2ShouldIgnoreSpreadsheetWarning($severity, $message, $file);
        }
    );

    try {
        return $callback();
    } finally {
        restore_error_handler();
        error_reporting($previousLevel);
    }
}

function msp2ConfigureSpreadsheetValueBinder(): void
{
    static $configured = false;

    if ($configured) {
        return;
    }

    if (
        !class_exists(\PhpOffice\PhpSpreadsheet\Cell\Cell::class)
        || !class_exists(\PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder::class)
        || !class_exists(\PhpOffice\PhpSpreadsheet\Cell\DataType::class)
    ) {
        return;
    }

    $binder = new class extends \PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder {
        public static function dataTypeForValue($pValue)
        {
            if ($pValue === null) {
                return \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NULL;
            }

            if ($pValue === '') {
                return \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING;
            }

            if ($pValue instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                return \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_INLINE;
            }

            if (is_string($pValue) && $pValue !== '' && $pValue[0] === '=' && strlen($pValue) > 1) {
                return \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_FORMULA;
            }

            if (is_bool($pValue)) {
                return \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_BOOL;
            }

            if (is_float($pValue) || is_int($pValue)) {
                return \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC;
            }

            if (is_string($pValue) && preg_match('/^[\+\-]?(\d+\\.?\d*|\d*\\.?\d+)([Ee][\-\+]?[0-2]?\d{1,3})?$/', $pValue) === 1) {
                $trimmedValue = ltrim($pValue, '+-');
                if ($trimmedValue !== '' && $trimmedValue[0] === '0' && strlen($trimmedValue) > 1 && $trimmedValue[1] !== '.') {
                    return \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING;
                }

                if (strpos($pValue, '.') === false && $pValue > PHP_INT_MAX) {
                    return \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING;
                }

                return \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC;
            }

            if (is_string($pValue)) {
                $errorCodes = \PhpOffice\PhpSpreadsheet\Cell\DataType::getErrorCodes();
                if (isset($errorCodes[$pValue])) {
                    return \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_ERROR;
                }
            }

            return \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING;
        }
    };

    \PhpOffice\PhpSpreadsheet\Cell\Cell::setValueBinder($binder);
    $configured = true;
}

function msp2ReadSpreadsheetRows(
    string $uploadTmpPath,
    bool $calculateFormulas = true,
    bool $formatData = true,
    bool $returnCellRef = false,
    bool $readDataOnly = true
): array {
    return msp2WithSpreadsheetCompatibility(static function () use (
        $uploadTmpPath,
        $calculateFormulas,
        $formatData,
        $returnCellRef,
        $readDataOnly
    ): array {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($uploadTmpPath);

        if ($readDataOnly && method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }

        $spreadsheet = $reader->load($uploadTmpPath);

        try {
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, $calculateFormulas, $formatData, $returnCellRef);
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }

        return is_array($rows ?? null) ? $rows : [];
    });
}

function msp2SaveSpreadsheetXlsx(object $writer, string $outputPath): void
{
    msp2WithSpreadsheetCompatibility(static function () use ($writer, $outputPath): void {
        $writer->save($outputPath);
    });
}

function msp2RegisterPsr4Fallback(string $prefix, string $baseDirectory): void
{
    static $registered = [];

    if (isset($registered[$prefix])) {
        return;
    }

    $normalizedBaseDirectory = rtrim($baseDirectory, '/\\') . DIRECTORY_SEPARATOR;

    spl_autoload_register(static function (string $class) use ($prefix, $normalizedBaseDirectory): void {
        $prefixLength = strlen($prefix);

        if (strncmp($class, $prefix, $prefixLength) !== 0) {
            return;
        }

        $relativeClass = substr($class, $prefixLength);
        $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';
        $target = $normalizedBaseDirectory . $relativePath;

        if (is_file($target)) {
            require_once $target;
        }
    });

    $registered[$prefix] = true;
}

function msp2RequirePhpFiles(array $patterns): void
{
    static $loaded = [];

    foreach ($patterns as $pattern) {
        if (isset($loaded[$pattern])) {
            continue;
        }

        $matches = glob($pattern);

        if ($matches === false) {
            $loaded[$pattern] = true;
            continue;
        }

        sort($matches);

        foreach ($matches as $file) {
            if (is_file($file)) {
                require_once $file;
            }
        }

        $loaded[$pattern] = true;
    }
}

function msp2RegisterSpreadsheetFallbackAutoloaders(): void
{
    $vendorDirectory = dirname(__DIR__) . '/vendor';

    msp2RegisterPsr4Fallback(
        'PhpOffice\\PhpSpreadsheet\\',
        $vendorDirectory . '/phpoffice/phpspreadsheet/src/PhpSpreadsheet'
    );
    msp2RegisterPsr4Fallback(
        'Psr\\SimpleCache\\',
        $vendorDirectory . '/psr/simple-cache/src'
    );
    msp2RegisterPsr4Fallback(
        'Complex\\',
        $vendorDirectory . '/markbaker/complex/classes/src'
    );
    msp2RegisterPsr4Fallback(
        'Matrix\\',
        $vendorDirectory . '/markbaker/matrix/classes/src'
    );

    msp2RequirePhpFiles([
        $vendorDirectory . '/markbaker/complex/classes/src/functions/*.php',
        $vendorDirectory . '/markbaker/complex/classes/src/operations/*.php',
        $vendorDirectory . '/markbaker/matrix/classes/src/Functions/*.php',
        $vendorDirectory . '/markbaker/matrix/classes/src/Operations/*.php',
    ]);
}

function msp2EstadoLocalIdByNombre(PDO $conn, string $nombreEstado): ?int
{
    $stmt = $conn->prepare(
        'SELECT TOP 1 id_estado_local
         FROM dbo.msp_estado_locales
         WHERE LTRIM(RTRIM(LOWER(desc_estado))) = LTRIM(RTRIM(LOWER(:desc_estado)))
         ORDER BY id_estado_local ASC'
    );
    $stmt->bindValue(':desc_estado', $nombreEstado, PDO::PARAM_STR);
    $stmt->execute();

    $result = $stmt->fetchColumn();

    return $result === false ? null : (int) $result;
}

function msp2EstadoDisponibleId(PDO $conn): ?int
{
    return msp2EstadoLocalIdByNombre($conn, 'Disponible');
}

function msp2EstadoOcupadoId(PDO $conn): ?int
{
    $candidatos = ['En arriendo', 'Ocupado', 'Arrendado'];

    foreach ($candidatos as $estado) {
        $id = msp2EstadoLocalIdByNombre($conn, $estado);
        if ($id !== null) {
            return $id;
        }
    }

    return null;
}

function msp2SyncLocalStatuses(PDO $conn, array $localIds): void
{
    $cleanIds = [];
    foreach ($localIds as $localId) {
        $id = (int) $localId;
        if ($id > 0) {
            $cleanIds[] = $id;
        }
    }

    $cleanIds = array_values(array_unique($cleanIds));
    if ($cleanIds === []) {
        return;
    }

    $idEstadoOcupado = msp2EstadoOcupadoId($conn);
    $idEstadoDisponible = msp2EstadoDisponibleId($conn);

    if ($idEstadoDisponible === null) {
        $idEstadoDisponible = msp2EstadoLocalIdByNombre($conn, 'Libre');
    }

    if ($idEstadoOcupado === null || $idEstadoDisponible === null) {
        return;
    }

    $hoy = (new DateTimeImmutable('today'))->format('Y-m-d');

    foreach (array_chunk($cleanIds, 500) as $chunk) {
        $placeholders = [];
        foreach ($chunk as $index => $_localId) {
            $placeholders[] = ':id_' . $index;
        }

        $selectSql =
            'SELECT DISTINCT ol.id_local
             FROM dbo.msp_ocupacion_locales ol
             WHERE ol.id_local IN (' . implode(', ', $placeholders) . ')
               AND ol.fecha_inicio <= :hoy_inicio
               AND (ol.fecha_termino IS NULL OR ol.fecha_termino >= :hoy_termino)';

        $selectStmt = $conn->prepare($selectSql);
        foreach ($chunk as $index => $localId) {
            $selectStmt->bindValue(':id_' . $index, (int) $localId, PDO::PARAM_INT);
        }
        $selectStmt->bindValue(':hoy_inicio', $hoy, PDO::PARAM_STR);
        $selectStmt->bindValue(':hoy_termino', $hoy, PDO::PARAM_STR);
        $selectStmt->execute();

        $ocupados = [];
        while (($idLocal = $selectStmt->fetchColumn()) !== false) {
            $ocupados[] = (int) $idLocal;
        }

        $ocupados = array_values(array_unique($ocupados));
        $disponibles = array_values(array_diff($chunk, $ocupados));

        if ($ocupados !== []) {
            $ocupadosPlaceholders = [];
            foreach ($ocupados as $index => $_localId) {
                $ocupadosPlaceholders[] = ':id_oc_' . $index;
            }

            $updateOcupadosStmt = $conn->prepare(
                'UPDATE dbo.msp_locales
                 SET id_estado_local = :id_estado
                 WHERE id_local IN (' . implode(', ', $ocupadosPlaceholders) . ')'
            );
            $updateOcupadosStmt->bindValue(':id_estado', $idEstadoOcupado, PDO::PARAM_INT);
            foreach ($ocupados as $index => $localId) {
                $updateOcupadosStmt->bindValue(':id_oc_' . $index, (int) $localId, PDO::PARAM_INT);
            }
            $updateOcupadosStmt->execute();
        }

        if ($disponibles !== []) {
            $dispPlaceholders = [];
            foreach ($disponibles as $index => $_localId) {
                $dispPlaceholders[] = ':id_di_' . $index;
            }

            $updateDisponiblesStmt = $conn->prepare(
                'UPDATE dbo.msp_locales
                 SET id_estado_local = :id_estado
                 WHERE id_local IN (' . implode(', ', $dispPlaceholders) . ')'
            );
            $updateDisponiblesStmt->bindValue(':id_estado', $idEstadoDisponible, PDO::PARAM_INT);
            foreach ($disponibles as $index => $localId) {
                $updateDisponiblesStmt->bindValue(':id_di_' . $index, (int) $localId, PDO::PARAM_INT);
            }
            $updateDisponiblesStmt->execute();
        }
    }
}
