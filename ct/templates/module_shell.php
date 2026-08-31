<?php
declare(strict_types=1);

$pageTitle = isset($pageTitle) ? trim((string) $pageTitle) : 'CT';
$pageSubtitle = isset($pageSubtitle) ? trim((string) $pageSubtitle) : '';
$pageDescription = isset($pageDescription) ? trim((string) $pageDescription) : '';
$highlights = isset($highlights) && is_array($highlights) ? $highlights : [];
$pageBodyHtml = isset($pageBodyHtml) ? (string) $pageBodyHtml : '';
$hidePageHeading = isset($hidePageHeading) ? (bool) $hidePageHeading : false;
$showMainMenuButton = isset($showMainMenuButton) ? (bool) $showMainMenuButton : true;
$showCtMenuBackButton = isset($showCtMenuBackButton) ? (bool) $showCtMenuBackButton : true;
$flashMode = isset($flashMode) ? trim((string) $flashMode) : 'alert';
$pageMaxWidth = isset($pageMaxWidth) && is_numeric($pageMaxWidth) ? (int) $pageMaxWidth : 1180;
if ($pageMaxWidth < 960) {
    $pageMaxWidth = 960;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo ctEscape($pageTitle); ?> | CT</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
    <link rel="stylesheet" href="/portalgp/ct/assets/ct_forms.css">
    <link rel="stylesheet" href="/portalgp/ct/assets/ct_crud.css">
    <style>
        .ct-module-shell {
            width: min(<?php echo (int) $pageMaxWidth; ?>px, 96vw);
            margin: 0 auto;
            padding: 20px;
        }
    </style>
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<?php ctRenderCsrfAutoFieldScript(); ?>

<main class="gp-main d-flex align-items-center justify-content-center py-4">
    <div class="ct-module-shell">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <?php if ($showCtMenuBackButton): ?>
                <a href="<?php echo ctEscape(ctUrl('ct_menu.php')); ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver al menú CT
                </a>
            <?php endif; ?>
            <?php if ($showMainMenuButton): ?>
                <a href="/portalgp/index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-grid-3x3-gap me-1" aria-hidden="true"></i>Menú principal
                </a>
            <?php endif; ?>
        </div>

        <?php
        $flash = ctPullFlash();
        if ($flashMode === 'toast') {
            $toastFlash = $flash;
            include __DIR__ . '/components/flash_toast.php';
        } else {
            ctRenderFlash($flash);
        }
        ?>

        <div class="card shadow-sm border-0">
            <div class="card-body">
                <?php if (!$hidePageHeading): ?>
                    <h1 class="h4 mb-1"><?php echo ctEscape($pageTitle); ?></h1>
                    <?php if ($pageSubtitle !== ''): ?>
                        <p class="text-muted mb-3"><?php echo ctEscape($pageSubtitle); ?></p>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($pageDescription !== ''): ?>
                    <div class="alert alert-info mb-3">
                        <?php echo ctEscape($pageDescription); ?>
                    </div>
                <?php endif; ?>

                <?php if ($pageBodyHtml !== ''): ?>
                    <?php echo $pageBodyHtml; ?>
                <?php elseif ($highlights !== []): ?>
                    <div class="row g-2">
                        <?php foreach ($highlights as $item): ?>
                            <?php
                            $label = trim((string) ($item['label'] ?? ''));
                            $value = trim((string) ($item['value'] ?? ''));
                            if ($label === '' && $value === '') {
                                continue;
                            }
                            ?>
                            <div class="col-12 col-md-4">
                                <div class="border rounded p-2 h-100 bg-light">
                                    <?php if ($label !== ''): ?>
                                        <div class="small text-muted"><?php echo ctEscape($label); ?></div>
                                    <?php endif; ?>
                                    <?php if ($value !== ''): ?>
                                        <div class="fw-semibold"><?php echo ctEscape($value); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
