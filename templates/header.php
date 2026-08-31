<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['usuario'])) {
    header('Location: /portalgp/login.php');
    exit();
}

if (defined('GP_MAIN_HEADER_RENDERED')) {
    return;
}
define('GP_MAIN_HEADER_RENDERED', true);

$gpCurrentPage = basename($_SERVER['PHP_SELF'] ?? '');
$gpRequestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
$gpIsMsp2Route = str_contains($gpRequestUri, '/portalgp/msp/');
$gpStylesPath = dirname(__DIR__) . '/styles.css';
$gpStylesVersion = is_file($gpStylesPath) ? (string) filemtime($gpStylesPath) : '1';
?>
<link rel="stylesheet" href="/portalgp/styles.css?v=<?php echo rawurlencode($gpStylesVersion); ?>">
<?php if ($gpIsMsp2Route && function_exists('msp2QuickAccessSections')): ?>
    <style>
        .gp-nav-button {
            background: transparent;
            cursor: pointer;
            font-family: inherit;
        }
    </style>
<?php endif; ?>

<header class="gp-header" id="gp-main-header">
    <div class="gp-header-inner">
        <nav aria-label="Navegacion principal">
            <ul class="gp-nav-list">
                <?php if ($gpIsMsp2Route && function_exists('msp2QuickAccessSections')): ?>
                    <li>
                        <button type="button" class="gp-nav-link gp-nav-button" data-bs-toggle="offcanvas" data-bs-target="#mspQuickAccessOffcanvas" aria-controls="mspQuickAccessOffcanvas">
                            Accesos MSP
                        </button>
                    </li>
                <?php endif; ?>
                <li>
                    <a href="/portalgp/index.php" class="gp-nav-link <?php echo $gpCurrentPage === 'index.php' ? 'gp-nav-link-active' : ''; ?>" <?php echo $gpCurrentPage === 'index.php' ? 'aria-current="page"' : ''; ?>>
                        Inicio
                    </a>
                </li>
                <li>
                    <a href="/portalgp/profile.php" class="gp-nav-link <?php echo $gpCurrentPage === 'profile.php' ? 'gp-nav-link-active' : ''; ?>" <?php echo $gpCurrentPage === 'profile.php' ? 'aria-current="page"' : ''; ?>>
                        Mi perfil
                    </a>
                </li>
                <li>
                    <a href="/portalgp/logout.php" class="gp-nav-link">Cerrar sesi&oacute;n</a>
                </li>
            </ul>
        </nav>
    </div>
</header>

<script>
(function () {
    function modalVisible() {
        return document.querySelector('.modal.show, .modal-backdrop.show') !== null;
    }

    function isPlainLeftClick(event) {
        return event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey;
    }

    document.addEventListener('click', function (event) {
        if (!isPlainLeftClick(event) || modalVisible()) {
            return;
        }

        var header = document.getElementById('gp-main-header');
        if (!header || header.contains(event.target)) {
            return;
        }

        var x = event.clientX;
        var y = event.clientY;
        var links = header.querySelectorAll('a.gp-nav-link[href]');

        for (var i = 0; i < links.length; i += 1) {
            var rect = links[i].getBoundingClientRect();
            var inside = x >= rect.left && x <= rect.right && y >= rect.top && y <= rect.bottom;

            if (inside) {
                event.preventDefault();
                window.location.href = links[i].href;
                return;
            }
        }
    }, true);
})();
</script>

<?php if ($gpIsMsp2Route): ?>
    <script src="/portalgp/msp/assets/modal_form_feedback.js" defer></script>
<?php endif; ?>

<?php require_once __DIR__ . '/components/page_navigation.php'; ?>
