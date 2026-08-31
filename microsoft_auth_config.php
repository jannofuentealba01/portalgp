<?php
declare(strict_types=1);

/**
 * Configuración base segura para Microsoft Entra ID.
 *
 * Define las variables MS_ENTRA_* en el entorno o crea
 * microsoft_auth_config.local.php para configurar el equipo local.
 * El archivo local está excluido del repositorio.
 */
return [
    'tenant_id' => '',
    'client_id' => '',
    'client_secret' => '',
    'redirect_uri' => '',
    'allowed_domains' => [],
];
