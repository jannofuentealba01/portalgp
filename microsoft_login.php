<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_helper.php';

if (!empty($_SESSION['usuario'])) {
    header('Location: index.php');
    exit();
}

$config = pgpMicrosoftAuthConfig();
if ($config['tenant_id'] === '' || $config['client_id'] === '' || $config['client_secret'] === '') {
    pgpRedirectToLogin('Microsoft no está configurado. Revisa tenant, client y secret.');
}

$state = bin2hex(random_bytes(16));
$_SESSION['ms_oauth_state'] = $state;

header('Location: ' . pgpMicrosoftAuthorizeUrl($config, $state));
exit();
