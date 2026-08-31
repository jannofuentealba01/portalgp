<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

gpGestionRequireModuleAccess();

$flash = gpGestionPullFlash();

$cards = [
    [
        'title' => 'Usuarios',
        'description' => 'Alta, edición, estado y revisión de cuentas del portal.',
        'icon' => 'bi-people-fill',
        'href' => gpGestionBaseUrl('usuarios.php'),
        'enabled' => gpGestionCanAccessUsuarios(),
        'count' => (int) $conn->query('SELECT COUNT(*) FROM cr_usuarios')->fetchColumn(),
        'accent' => 'users',
    ],
    [
        'title' => 'Roles',
        'description' => 'Definición de perfiles operativos y control de asignaciones.',
        'icon' => 'bi-diagram-3-fill',
        'href' => gpGestionBaseUrl('roles.php'),
        'enabled' => gpGestionCanAccessRoles(),
        'count' => (int) $conn->query('SELECT COUNT(*) FROM cr_roles')->fetchColumn(),
        'accent' => 'roles',
    ],
    [
        'title' => 'Permisos',
        'description' => 'Catálogo de permisos y asignación directa a cada rol.',
        'icon' => 'bi-shield-lock-fill',
        'href' => gpGestionBaseUrl('permisos.php'),
        'enabled' => gpGestionCanAccessPermisos(),
        'count' => (int) $conn->query('SELECT COUNT(*) FROM cr_permisos')->fetchColumn(),
        'accent' => 'permisos',
    ],
    [
        'title' => 'Departamentos',
        'description' => 'Agrupa usuarios por estructura organizacional y permite asignación múltiple.',
        'icon' => 'bi-building-fill-gear',
        'href' => gpGestionBaseUrl('departamentos.php'),
        'enabled' => gpGestionCanAccessDepartamentos(),
        'count' => (int) $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'cr_departamentos'")->fetchColumn() > 0
            ? (int) $conn->query('SELECT COUNT(*) FROM cr_departamentos')->fetchColumn()
            : 0,
        'accent' => 'departamentos',
    ],
];
?>
<!DOCTYPE html>
<html lang="es" class="module-menu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión del Sistema</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
    <style>
        .gp-system-shell {
            position: relative;
            overflow: hidden;
        }

        .gp-system-shell::before,
        .gp-system-shell::after {
            content: "";
            position: absolute;
            inset: auto auto 0 0;
            width: 280px;
            height: 280px;
            border-radius: 50%;
            background: none;
            transform: translate(-25%, 28%);
            pointer-events: none;
        }

        .gp-system-shell::after {
            inset: 0 0 auto auto;
            width: 340px;
            height: 340px;
            background: none;
            transform: translate(24%, -34%);
        }

        .gp-system-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            position: relative;
            z-index: 1;
        }

        .gp-system-header {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
        }

        .gp-system-header .menu-title {
            margin: 0;
            grid-column: 2;
            text-align: center;
            font-size: 22px;
        }

        .gp-system-header .btn {
            grid-column: 1;
            justify-self: start;
            padding: 8px 14px;
            font-size: 15px;
        }

        .gp-system-card {
            display: flex;
            flex-direction: column;
            gap: 14px;
            min-height: 210px;
            padding: 20px;
            border: 1px solid rgba(215, 222, 232, 0.9);
            border-radius: 16px;
            background: #ffffff;
            box-shadow: 0 18px 42px rgba(16, 24, 40, 0.08);
            text-decoration: none;
            color: var(--color-text);
            transition: transform 0.26s ease, box-shadow 0.26s ease, border-color 0.26s ease;
            animation: gpCardEntrance 0.5s ease both;
        }

        .gp-system-card:nth-child(2) {
            animation-delay: 0.06s;
        }

        .gp-system-card:nth-child(3) {
            animation-delay: 0.12s;
        }

        .gp-system-card:nth-child(4) {
            animation-delay: 0.18s;
        }

        .gp-system-card:hover {
            transform: translateY(-6px);
            border-color: rgba(11, 58, 110, 0.2);
            box-shadow: 0 24px 52px rgba(16, 24, 40, 0.12);
            color: var(--color-text);
        }

        .gp-system-card-disabled,
        .gp-system-card-disabled:hover {
            cursor: default;
            background: #f3f6fa;
            color: var(--color-text-muted);
            box-shadow: none;
            transform: none;
        }

        .gp-system-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .gp-system-icon {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            background: linear-gradient(135deg, #edf4ff 0%, #dceafb 100%);
            color: var(--color-primary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 21px;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.75);
        }

        .gp-system-card-disabled .gp-system-icon {
            background: #fff;
            color: var(--color-text-muted);
        }

        .gp-system-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 72px;
            padding: 6px 11px;
            border-radius: 999px;
            background: rgba(11, 58, 110, 0.08);
            color: var(--color-primary);
            font-size: 13px;
            font-weight: 700;
        }

        .gp-system-card[data-accent="departamentos"] .gp-system-icon {
            background: linear-gradient(135deg, #effaf7 0%, #d7f3e9 100%);
            color: #0f766e;
        }

        .gp-system-card[data-accent="roles"] .gp-system-icon {
            background: linear-gradient(135deg, #f6f1ff 0%, #ebe2ff 100%);
            color: #6d28d9;
        }

        .gp-system-card[data-accent="permisos"] .gp-system-icon {
            background: linear-gradient(135deg, #fff7eb 0%, #ffe8c7 100%);
            color: #b45309;
        }

        .gp-system-card h2 {
            margin: 0;
            font-size: 18px;
            color: inherit;
        }

        .gp-system-card p {
            margin: 0;
            color: inherit;
            font-size: 15px;
            line-height: 1.4;
        }

        .gp-system-card small {
            color: var(--color-text-muted);
            font-weight: 600;
        }

        .gp-system-highlight {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(11, 58, 110, 0.08);
            color: var(--color-primary);
            font-size: 13px;
            font-weight: 700;
        }

        @keyframes gpCardEntrance {
            from {
                opacity: 0;
                transform: translateY(18px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 991.98px) {
            .gp-system-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 575.98px) {
            .gp-system-header {
                grid-template-columns: 1fr;
            }

            .gp-system-header .menu-title,
            .gp-system-header .btn {
                grid-column: 1;
            }

            .gp-system-header .menu-title {
                text-align: center;
            }
        }
    </style>
</head>
<body>
<main class="flex-shrink-0 d-flex align-items-center">
    <div class="menu-wrapper">
        <section class="menu-panel gp-system-shell">
            <div class="gp-system-header">
                <a
                    href="/portalgp/index.php"
                    class="btn btn-secondary"
                >
                    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>
                    Volver atrás
                </a>
                <h1 class="menu-title">Gestión del Sistema</h1>
            </div>

            <?php if ($flash !== null): ?>
                <div class="alert <?php echo gpGestionFlashClass((string) ($flash['type'] ?? 'info')); ?> mb-4" role="alert">
                    <?php echo gpGestionH($flash['message'] ?? ''); ?>
                </div>
            <?php endif; ?>

            <div class="gp-system-grid">
                <?php foreach ($cards as $card): ?>
                    <?php $cardClass = $card['enabled'] ? 'gp-system-card' : 'gp-system-card gp-system-card-disabled'; ?>
                    <?php if ($card['enabled']): ?>
                        <a href="<?php echo gpGestionH($card['href']); ?>" class="<?php echo $cardClass; ?>" data-accent="<?php echo gpGestionH($card['accent']); ?>">
                    <?php else: ?>
                        <div class="<?php echo $cardClass; ?>" aria-disabled="true" data-accent="<?php echo gpGestionH($card['accent']); ?>">
                    <?php endif; ?>
                        <div class="gp-system-card-head">
                            <span class="gp-system-icon"><i class="bi <?php echo gpGestionH($card['icon']); ?>" aria-hidden="true"></i></span>
                            <span class="gp-system-pill"><?php echo gpGestionH($card['count']); ?></span>
                        </div>
                        <div class="d-flex flex-column gap-2">
                            <h2><?php echo gpGestionH($card['title']); ?></h2>
                            <p><?php echo gpGestionH($card['description']); ?></p>
                        </div>
                    <?php if ($card['enabled']): ?>
                        </a>
                    <?php else: ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include __DIR__ . '/../../templates/footer.php'; ?>
</body>
</html>
