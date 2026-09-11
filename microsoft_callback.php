<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_helper.php';

$oauthRequest = pgpConsumeMicrosoftOauthRequest((string)($_GET['state'] ?? ''));
if ($oauthRequest === null) {
    pgpRedirectToLogin('No se pudo validar la respuesta de Microsoft. Intenta nuevamente.');
}

if (!empty($_GET['error'])) {
    pgpRedirectToLogin('Microsoft canceló o rechazó el inicio de sesión. Intenta nuevamente.');
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

$tokenHttpCode = 0;
$tokenResponse = '';
try {
    $candidateSecrets = array_values(array_unique(array_filter([
        (string)$config['client_secret'],
        (string)($config['client_secret_previous'] ?? ''),
    ], static fn(string $secret): bool => trim($secret) !== '')));
    foreach ($candidateSecrets as $candidateSecret) {
        [$tokenHttpCode, $tokenResponse] = pgpHttpPostForm($tokenUrl, [
            'client_id' => $config['client_id'],
            'client_secret' => $candidateSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => pgpMicrosoftRedirectUri($config),
        ]);
        if ($tokenHttpCode >= 200 && $tokenHttpCode < 300) {
            break;
        }
    }
} catch (Throwable $e) {
    pgpRedirectToLogin(pgpPublicException($e, 'auth.microsoft.token', 'No fue posible contactar a Microsoft.'));
}

$tokenJson = json_decode($tokenResponse, true);
if (!is_array($tokenJson) || $tokenHttpCode < 200 || $tokenHttpCode >= 300 || empty($tokenJson['access_token'])) {
    pgpRedirectToLogin('Microsoft no pudo completar la autenticación. Intenta nuevamente.');
}

try {
    $claims = pgpValidateMicrosoftIdToken(
        (string)($tokenJson['id_token'] ?? ''),
        $config,
        (string)$oauthRequest['nonce']
    );
} catch (Throwable $e) {
    pgpClearMicrosoftTokens();
    pgpRedirectToLogin(pgpPublicException($e, 'auth.microsoft.id_token', 'Microsoft devolvió una identidad que no pudo validarse.'));
}

pgpStoreMicrosoftTokens($tokenJson);
$profile = pgpMicrosoftProfileFromVerifiedClaims($claims);
$email = pgpMicrosoftProfileEmail($profile);

if ($email === '') {
    try {
        [$meHttpCode, $meResponse] = pgpHttpGetJson(
            'https://graph.microsoft.com/v1.0/me?$select=mail,userPrincipalName,displayName,id',
            (string)$tokenJson['access_token']
        );
    } catch (Throwable $e) {
        pgpClearMicrosoftTokens();
        pgpRedirectToLogin(pgpPublicException($e, 'auth.microsoft.profile', 'No fue posible consultar el perfil de Microsoft.'));
    }

    $meJson = json_decode($meResponse, true);
    if (!is_array($meJson) || $meHttpCode < 200 || $meHttpCode >= 300) {
        pgpClearMicrosoftTokens();
        pgpRedirectToLogin('Microsoft no pudo entregar el perfil de la cuenta. Intenta nuevamente.');
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
    pgpRedirectToLogin('La cuenta Microsoft no está habilitada en Portal GP.');
}

pgpLoginUserRecord($conn, $user, 'microsoft');
header('Location: index.php');
exit();
