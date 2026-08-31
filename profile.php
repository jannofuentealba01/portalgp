<?php
declare(strict_types=1);

require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/db.php';

$sessionUser = $_SESSION['usuario'] ?? [];
$userId = (int) ($sessionUser['id'] ?? 0);

$profile = [
    'id' => $userId,
    'UserName' => (string) ($sessionUser['UserName'] ?? ''),
    'nombre_completo' => (string) ($sessionUser['nombre_completo'] ?? ''),
    'correo_electronico' => (string) ($sessionUser['correo_electronico'] ?? ''),
    'url_logo' => (string) ($sessionUser['url_logo'] ?? ''),
    'rol_id' => null,
    'nombre_rol' => '',
];

if ($userId > 0) {
    $hasUrlLogoColumn = (int) $conn->query("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = 'dbo'
          AND TABLE_NAME = 'cr_usuarios'
          AND COLUMN_NAME = 'url_logo'
    ")->fetchColumn() > 0;

    $sql = '
        SELECT TOP 1
            u.id,
            u.UserName,
            u.nombre_completo,
            u.correo_electronico,
            u.rol_id,
            r.nombre_rol,
            ' . ($hasUrlLogoColumn ? 'url_logo' : 'CAST(NULL AS NVARCHAR(500)) AS url_logo') . '
        FROM cr_usuarios u
        LEFT JOIN cr_roles r ON r.id = u.rol_id
        WHERE u.id = :id
    ';
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($row) && $row !== []) {
        $profile = array_merge($profile, $row);
    }
}

$fullName = trim((string) ($profile['nombre_completo'] ?? ''));
$username = trim((string) ($profile['UserName'] ?? ''));
$email = trim((string) ($profile['correo_electronico'] ?? ''));
$urlLogo = trim((string) ($profile['url_logo'] ?? ''));
if ($urlLogo !== '' && filter_var($urlLogo, FILTER_VALIDATE_URL) === false) {
    $urlLogo = '';
}

$rolesText = trim((string) ($profile['nombre_rol'] ?? ''));
if ($rolesText === '') {
    $rolesText = 'Sin rol asignado';
}

$loginSource = trim((string) ($sessionUser['login_source'] ?? 'local'));
$loginSourceLabel = $loginSource === 'microsoft' ? 'Microsoft Entra ID' : 'Local';

$initialSource = $fullName !== '' ? $fullName : $username;
$initial = '?';
if ($initialSource !== '') {
    $firstChar = function_exists('mb_substr') ? mb_substr($initialSource, 0, 1, 'UTF-8') : substr($initialSource, 0, 1);
    $initial = function_exists('mb_strtoupper') ? mb_strtoupper((string) $firstChar, 'UTF-8') : strtoupper((string) $firstChar);
}

$flashMessage = null;
if (isset($_SESSION['mensaje'])) {
    $flashMessage = (string) $_SESSION['mensaje'];
    unset($_SESSION['mensaje']);
}
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perfil de Usuario</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="styles.css">
    <style>
        .gp-id-card {
            display: grid;
            grid-template-columns: 180px minmax(0, 1fr);
            gap: 18px;
            border: 1px solid var(--color-border);
            border-radius: 14px;
            background: #fff;
            padding: 16px;
        }

        .gp-id-photo-wrap {
            width: 180px;
            height: 220px;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--color-border);
            background: #f3f5f8;
        }

        .gp-user-profile-photo,
        .gp-user-profile-fallback {
            width: 100%;
            height: 100%;
            border-radius: 0;
            border: 0;
            flex: 0 0 auto;
        }

        .gp-user-profile-photo {
            object-fit: cover;
            background: #f3f5f8;
            display: block;
        }

        .gp-user-profile-fallback {
            background: linear-gradient(135deg, #0b3a6e, #0f766e);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 58px;
            font-weight: 700;
        }

        .gp-id-main {
            min-width: 0;
        }

        .gp-user-profile-name {
            margin: 0;
            font-size: 24px;
            font-weight: 700;
            line-height: 1.15;
        }

        .gp-user-profile-username {
            color: var(--color-text-muted);
            font-size: 14px;
            margin-top: 3px;
        }

        .gp-id-role {
            margin-top: 10px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 999px;
            padding: 6px 12px;
            background: rgba(11, 58, 110, 0.08);
            color: #0b3a6e;
            font-size: 13px;
            font-weight: 700;
        }

        .gp-user-profile-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin-top: 12px;
        }

        .gp-user-profile-item {
            border: 1px solid var(--color-border);
            border-radius: 10px;
            background: #fff;
            padding: 10px 12px;
        }

        .gp-user-profile-label {
            display: block;
            color: var(--color-text-muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 4px;
        }

        .gp-user-profile-value {
            display: block;
            color: var(--color-text);
            font-size: 15px;
            font-weight: 600;
            word-break: break-word;
        }

        @media (max-width: 767.98px) {
            .gp-id-card {
                grid-template-columns: 1fr;
            }

            .gp-id-photo-wrap {
                width: min(240px, 100%);
                height: 280px;
                margin: 0 auto;
            }

            .gp-user-profile-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="gp-layout">
    <main class="gp-main">
        <div class="box-container-narrow my-3">
            <h1 class="form-title text-center mb-2">Mi perfil</h1>
            <p class="text-muted text-center mb-3">Consulta tu información y actualiza tu contraseña cuando sea necesario.</p>

            <?php if ($flashMessage !== null): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
                </div>
            <?php endif; ?>

            <form action="procesar_actualizar_perfil.php" method="post">
                <input type="hidden" name="id" value="<?php echo htmlspecialchars((string) ($profile['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

                <div class="card mb-3">
                    <div class="card-header fw-bold">Datos de usuario</div>
                    <div class="card-body">
                        <div class="gp-id-card">
                            <div class="gp-id-photo-wrap">
                                <img
                                    id="profile_user_photo"
                                    class="gp-user-profile-photo"
                                    src="<?php echo htmlspecialchars($urlLogo, ENT_QUOTES, 'UTF-8'); ?>"
                                    alt="Foto del usuario"
                                    <?php echo $urlLogo === '' ? 'style="display:none;"' : ''; ?>
                                >
                                <div
                                    id="profile_user_fallback"
                                    class="gp-user-profile-fallback"
                                    aria-hidden="true"
                                    <?php echo $urlLogo !== '' ? 'style="display:none;"' : ''; ?>
                                ><?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                            <div class="gp-id-main">
                                <h2 class="gp-user-profile-name"><?php echo htmlspecialchars($fullName !== '' ? $fullName : 'Nombre no disponible', ENT_QUOTES, 'UTF-8'); ?></h2>
                                <div class="gp-user-profile-username"><?php echo htmlspecialchars($username !== '' ? '@' . $username : '@-', ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="gp-id-role"><?php echo htmlspecialchars($rolesText, ENT_QUOTES, 'UTF-8'); ?></div>

                                <div class="gp-user-profile-grid">
                                    <div class="gp-user-profile-item">
                                        <span class="gp-user-profile-label">Correo electrónico</span>
                                        <span class="gp-user-profile-value"><?php echo htmlspecialchars($email !== '' ? $email : 'Correo no disponible', ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div class="gp-user-profile-item">
                                        <span class="gp-user-profile-label">Fuente de acceso</span>
                                        <span class="gp-user-profile-value"><?php echo htmlspecialchars($loginSourceLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div class="gp-user-profile-item" style="grid-column: 1 / -1;">
                                        <span class="gp-user-profile-label">URL foto/logo</span>
                                        <span class="gp-user-profile-value">
                                            <?php if ($urlLogo !== ''): ?>
                                                <a href="<?php echo htmlspecialchars($urlLogo, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($urlLogo, ENT_QUOTES, 'UTF-8'); ?></a>
                                            <?php else: ?>
                                                Sin URL configurada
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header fw-bold">Cambiar contraseña</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label for="password_actual" class="form-label">Contraseña actual</label>
                                <input type="password" id="password_actual" name="password_actual" class="form-control" placeholder="Obligatoria para guardar cambios" required>
                            </div>
                            <div class="col-12">
                                <label for="nueva_password" class="form-label">Nueva contraseña</label>
                                <input type="password" id="nueva_password" name="nueva_password" class="form-control" placeholder="Dejar vacío para mantener la actual">
                            </div>
                        </div>
                    </div>
                    <div class="card-footer text-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save me-1" aria-hidden="true"></i> Guardar cambios
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </main>

    <script>
        (function () {
            var photoElement = document.getElementById('profile_user_photo');
            var fallbackElement = document.getElementById('profile_user_fallback');
            if (!photoElement || !fallbackElement) {
                return;
            }

            var fallback = function () {
                photoElement.style.display = 'none';
                fallbackElement.style.display = 'inline-flex';
            };

            var src = (photoElement.getAttribute('src') || '').trim();
            if (src === '') {
                fallback();
                return;
            }

            photoElement.addEventListener('error', fallback);
            photoElement.addEventListener('load', function () {
                photoElement.style.display = 'block';
                fallbackElement.style.display = 'none';
            });

            if (photoElement.complete && photoElement.naturalWidth === 0) {
                fallback();
            }
        })();
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php include __DIR__ . '/templates/footer.php'; ?>
</body>
</html>
