<?php
declare(strict_types=1);

/**
 * Helper compartido de correo para el módulo MSP.
 *
 * Provee:
 *   - mspMailEnvString()       → lee una variable de entorno como string
 *   - mspMailConfig()          → carga config SMTP desde mail.php o env vars
 *   - mspMailRequireLibrary()  → carga PHPMailer (vendor/autoload.php)
 *   - mspMailBuildSmtp()       → factory: devuelve PHPMailer configurado con SMTP
 *
 * Este archivo NO envía correos por sí mismo; es usado por:
 *   - cobros/operacion_mensual.php  (demo de cobro mensual)
 *   - pagos/guardar.php             (vale de pago al arrendatario)
 */

function mspMailEnvString(string $key): string
{
    $value = getenv($key);
    return is_string($value) ? trim($value) : '';
}

function mspMailConfig(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $config = [
        'smtp' => [
            'host'         => mspMailEnvString('MAIL_SMTP_HOST'),
            'port'         => mspMailEnvString('MAIL_SMTP_PORT'),
            'secure'       => mspMailEnvString('MAIL_SMTP_SECURE'),
            'user'         => mspMailEnvString('MAIL_SMTP_USER'),
            'pass'         => mspMailEnvString('MAIL_SMTP_PASS'),
            'from_address' => mspMailEnvString('MAIL_FROM_ADDRESS'),
            'from_name'    => mspMailEnvString('MAIL_FROM_NAME'),
        ],
        'demo' => [
            'to' => mspMailEnvString('MAIL_DEMO_TO'),
        ],
    ];

    $configPath = __DIR__ . '/config/mail.php';
    if (!is_file($configPath)) {
        return $config;
    }

    $loaded = require $configPath;
    if (!is_array($loaded)) {
        return $config;
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

    return $config;
}

function mspMailRequireLibrary(): void
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
        $vendorBase    = dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src';
        $exceptionPath = $vendorBase . '/Exception.php';
        $mailerPath    = $vendorBase . '/PHPMailer.php';
        $smtpPath      = $vendorBase . '/SMTP.php';

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

function mspMailBuildSmtp(): \PHPMailer\PHPMailer\PHPMailer
{
    mspMailRequireLibrary();

    $cfg        = mspMailConfig();
    $smtpConfig = is_array($cfg['smtp'] ?? null) ? $cfg['smtp'] : [];

    $host        = trim((string) ($smtpConfig['host'] ?? ''));
    $portRaw     = trim((string) ($smtpConfig['port'] ?? ''));
    $username    = trim((string) ($smtpConfig['user'] ?? ''));
    $password    = trim((string) ($smtpConfig['pass'] ?? ''));
    $secureRaw   = mb_strtolower(trim((string) ($smtpConfig['secure'] ?? '')), 'UTF-8');
    $fromAddress = trim((string) ($smtpConfig['from_address'] ?? ''));
    $fromName    = trim((string) ($smtpConfig['from_name'] ?? ''));

    if ($host === '' || $username === '' || $password === '') {
        throw new RuntimeException('Falta configuración SMTP. Revisa msp/config/mail.php (o variables MAIL_SMTP_*).');
    }

    $encryption = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    if ($secureRaw !== '') {
        if (in_array($secureRaw, ['tls', 'starttls'], true)) {
            $encryption = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } elseif (in_array($secureRaw, ['ssl', 'smtps'], true)) {
            $encryption = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } else {
            throw new RuntimeException('MAIL_SMTP_SECURE inválido. Usa `tls` o `ssl`.');
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
        throw new RuntimeException('MAIL_FROM_ADDRESS no tiene formato de correo válido.');
    }
    if ($fromName === '') {
        $fromName = 'MSP Cobros';
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $host;
    $mail->Port       = $port;
    $mail->SMTPAuth   = true;
    $mail->Username   = $username;
    $mail->Password   = $password;
    $mail->SMTPSecure = $encryption;
    $mail->CharSet    = 'UTF-8';
    $mail->setFrom($fromAddress, $fromName);
    $mail->Timeout    = 20;

    return $mail;
}
