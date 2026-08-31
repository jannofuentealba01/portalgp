<?php
declare(strict_types=1);

function ctMailEnvString(string $key): string
{
    $value = getenv($key);
    return is_string($value) ? trim($value) : '';
}

function ctMailEnvBool(string $key, bool $default): bool
{
    $raw = ctMailEnvString($key);
    if ($raw === '') {
        return $default;
    }
    $normalized = strtolower($raw);
    if (in_array($normalized, ['1', 'true', 'yes', 'si', 'on'], true)) {
        return true;
    }
    if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }
    return $default;
}

function ctMailEnvDomainList(string $key): array
{
    $raw = ctMailEnvString($key);
    if ($raw === '') {
        return [];
    }
    $parts = preg_split('/[,\s;]+/', $raw) ?: [];
    $domains = [];
    foreach ($parts as $part) {
        $domain = strtolower(trim((string) $part));
        $domain = ltrim($domain, '@');
        if ($domain === '' || !str_contains($domain, '.')) {
            continue;
        }
        $domains[$domain] = true;
    }
    return array_keys($domains);
}

function ctMailConfig(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $blockedDomains = ctMailEnvDomainList('CT_MAIL_BLOCKED_DOMAINS');
    if ($blockedDomains === []) {
        $blockedDomains = ['grupopatagual.cl'];
    }

    $config = [
        'enabled' => ctMailEnvBool('CT_MAIL_ENABLED', true),
        'smtp' => [
            'host' => ctMailEnvString('CT_MAIL_SMTP_HOST'),
            'port' => ctMailEnvString('CT_MAIL_SMTP_PORT'),
            'secure' => ctMailEnvString('CT_MAIL_SMTP_SECURE'),
            'user' => ctMailEnvString('CT_MAIL_SMTP_USER'),
            'pass' => ctMailEnvString('CT_MAIL_SMTP_PASS'),
            'from_address' => ctMailEnvString('CT_MAIL_FROM_ADDRESS'),
            'from_name' => ctMailEnvString('CT_MAIL_FROM_NAME'),
        ],
        'demo' => [
            'to' => ctMailEnvString('CT_MAIL_DEMO_TO'),
        ],
        'blocked_domains' => $blockedDomains,
    ];

    $configPath = __DIR__ . '/config/mail.php';
    if (!is_file($configPath)) {
        return $config;
    }

    $loaded = require $configPath;
    if (!is_array($loaded)) {
        return $config;
    }

    if (array_key_exists('enabled', $loaded)) {
        $config['enabled'] = (bool) $loaded['enabled'];
    }

    $smtpLoaded = $loaded['smtp'] ?? null;
    if (is_array($smtpLoaded)) {
        foreach (['host', 'port', 'secure', 'user', 'pass', 'from_address', 'from_name'] as $key) {
            if (!array_key_exists($key, $smtpLoaded)) {
                continue;
            }
            $value = trim((string) $smtpLoaded[$key]);
            if ($value !== '') {
                $config['smtp'][$key] = $value;
            }
        }
    }

    $demoLoaded = $loaded['demo'] ?? null;
    if (is_array($demoLoaded) && array_key_exists('to', $demoLoaded)) {
        $demoTo = trim((string) $demoLoaded['to']);
        if ($demoTo !== '') {
            $config['demo']['to'] = $demoTo;
        }
    }

    if (array_key_exists('blocked_domains', $loaded) && is_array($loaded['blocked_domains'])) {
        $blocked = [];
        foreach ($loaded['blocked_domains'] as $domainRaw) {
            $domain = strtolower(trim((string) $domainRaw));
            $domain = ltrim($domain, '@');
            if ($domain === '' || !str_contains($domain, '.')) {
                continue;
            }
            $blocked[$domain] = true;
        }
        if ($blocked !== []) {
            $config['blocked_domains'] = array_keys($blocked);
        }
    }

    return $config;
}

function ctMailRequireLibrary(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoloadPath)) {
        throw new RuntimeException('No se encontró vendor/autoload.php para cargar PHPMailer.');
    }

    require_once $autoloadPath;

    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        $vendorBase = dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src';
        $exceptionPath = $vendorBase . '/Exception.php';
        $mailerPath = $vendorBase . '/PHPMailer.php';
        $smtpPath = $vendorBase . '/SMTP.php';

        if (is_file($exceptionPath) && is_file($mailerPath) && is_file($smtpPath)) {
            require_once $exceptionPath;
            require_once $mailerPath;
            require_once $smtpPath;
        }
    }

    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        throw new RuntimeException('PHPMailer no está disponible en el proyecto.');
    }

    $loaded = true;
}

function ctMailBuildSmtp(): \PHPMailer\PHPMailer\PHPMailer
{
    ctMailRequireLibrary();

    $cfg = ctMailConfig();
    if (!(bool) ($cfg['enabled'] ?? true)) {
        throw new RuntimeException('El envío de correos CT está deshabilitado por configuración.');
    }

    $smtpConfig = is_array($cfg['smtp'] ?? null) ? $cfg['smtp'] : [];

    $host = trim((string) ($smtpConfig['host'] ?? ''));
    $portRaw = trim((string) ($smtpConfig['port'] ?? ''));
    $username = trim((string) ($smtpConfig['user'] ?? ''));
    $password = trim((string) ($smtpConfig['pass'] ?? ''));
    $secureRaw = strtolower(trim((string) ($smtpConfig['secure'] ?? '')));
    $fromAddress = trim((string) ($smtpConfig['from_address'] ?? ''));
    $fromName = trim((string) ($smtpConfig['from_name'] ?? ''));

    if ($host === '' || $username === '' || $password === '') {
        throw new RuntimeException('Falta configuración SMTP de CT. Revisa ct/config/mail.php o variables CT_MAIL_SMTP_*.');
    }

    $encryption = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    if ($secureRaw !== '') {
        if (in_array($secureRaw, ['tls', 'starttls'], true)) {
            $encryption = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } elseif (in_array($secureRaw, ['ssl', 'smtps'], true)) {
            $encryption = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } else {
            throw new RuntimeException('CT_MAIL_SMTP_SECURE inválido. Usa `tls` o `ssl`.');
        }
    }

    $port = $portRaw !== '' && ctype_digit($portRaw) ? (int) $portRaw : 0;
    if ($port <= 0) {
        $port = $encryption === \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS ? 465 : 587;
    }

    if ($fromAddress === '') {
        $fromAddress = $username;
    }
    if (filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('CT_MAIL_FROM_ADDRESS no tiene formato de correo válido.');
    }
    if ($fromName === '') {
        $fromName = 'CT Solicitudes';
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $host;
    $mail->Port = $port;
    $mail->SMTPAuth = true;
    $mail->Username = $username;
    $mail->Password = $password;
    $mail->SMTPSecure = $encryption;
    $mail->CharSet = 'UTF-8';
    $mail->setFrom($fromAddress, $fromName);
    $mail->Timeout = 20;

    return $mail;
}
