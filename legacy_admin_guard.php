<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/permisos.php';
$legacyUserId = (int) ($_SESSION['usuario']['id'] ?? 0);
if ($legacyUserId <= 0) { header('Location: /portalgp/login.php'); exit; }
if (!tienePermiso($legacyUserId, 'Administrar Usuarios')) { http_response_code(403); echo 'Acceso no autorizado.'; exit; }
