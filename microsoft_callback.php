<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_helper.php';

if (!pgpConsumeMicrosoftOauthState((string)($_GET['state'] ?? ''))) {
    pgpRedirectToLogin('No se pudo validar la respuesta de Microsoft. Intenta nuevamente.');
}

if (!empty($_GET['error'])) {
    $errorDescription = trim((string)($_GET['error_description'] ?? $_GET['error']));
    pgpRedirectToLogin('Microsoft devolvió un error: ' . $errorDescription);
}

$code = trim((string)($_GET['code'] ?? ''));
if ($code === '') {
    pgpRedirectToLogin('Microsoft no devolvió un código de autorización.');
}

$config = pgpMicrosoftAuthConfig();
if ($config['tenant_id'] === '' || $config['client_id'] === '' || $config['client_secret'] === '') {
    pgpRedirectToLogin('Microsoft no está configurado. Revisa tenant, client y secret.');
}

$tokenUrl = 'https://login.microsoftonline.com/' . rawurlencode($config['tenant_id']) . '/oauth2/v2.0/token';

try {
    [$tokenHttpCode, $tokenResponse] = pgpHttpPostForm($tokenUrl, [
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'code' => $code,
        'grant_type' => 'authorization_code',
        'redirect_uri' => pgpMicrosoftRedirectUri($config),
    ]);
} catch (Throwable $e) {
    pgpRedirectToLogin($e->getMessage());
}

$tokenJson = json_decode($tokenResponse, true);
if (!is_array($tokenJson) || $tokenHttpCode < 200 || $tokenHttpCode >= 300 || empty($tokenJson['access_token'])) {
    $detail = is_array($tokenJson) ? ($tokenJson['error_description'] ?? $tokenJson['error'] ?? 'No se pudo obtener token.') : 'Respuesta inválida al solicitar token.';
    pgpRedirectToLogin('Falló autenticación Microsoft: ' . $detail);
}

pgpStoreMicrosoftTokens($tokenJson);

$profile = pgpMicrosoftProfileFromIdToken($tokenJson, $config);
$email = pgpMicrosoftProfileEmail($profile);

if ($email === '') {
    try {
        [$meHttpCode, $meResponse] = pgpHttpGetJson(
            'https://graph.microsoft.com/v1.0/me?$select=mail,userPrincipalName,displayName,id',
            (string)$tokenJson['access_token']
        );
    } catch (Throwable $e) {
        pgpClearMicrosoftTokens();
        pgpRedirectToLogin($e->getMessage());
    }

    $meJson = json_decode($meResponse, true);
    if (!is_array($meJson) || $meHttpCode < 200 || $meHttpCode >= 300) {
        pgpClearMicrosoftTokens();
        $detail = is_array($meJson) ? ($meJson['error']['message'] ?? 'No se pudo leer el perfil del usuario.') : 'Respuesta inválida de Microsoft Graph.';
        pgpRedirectToLogin('Falló lectura de perfil Microsoft: ' . $detail);
    }

    $profile = $meJson;
    $email = pgpMicrosoftProfileEmail($profile);
}

if ($email === '') {
    pgpClearMicrosoftTokens();
    pgpRedirectToLogin('Tu cuenta Microsoft no entregó un correo usable para el login.');
}

if (!pgpIsAllowedMicrosoftEmail($email, $config)) {
    pgpClearMicrosoftTokens();
    pgpRedirectToLogin('Tu cuenta Microsoft no pertenece a un dominio autorizado.');
}

$user = pgpFindEnabledUserByEmail($conn, $email);
if (!$user) {
    pgpClearMicrosoftTokens();
    pgpRedirectToLogin('El correo ' . $email . ' no está habilitado en Portal GP.');
}

pgpLoginUserRecord($conn, $user, 'microsoft');
header('Location: index.php');
exit();
