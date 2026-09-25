<?php
declare(strict_types=1);

$mspPendingSnapshot = function_exists('msp2PendingNotificationSnapshot')
    ? msp2PendingNotificationSnapshot()
    : ['available' => false, 'total' => 0, 'critical' => 0, 'items' => []];
$mspPendingAvailable = (bool) ($mspPendingSnapshot['available'] ?? false);
$mspPendingTotal = max(0, (int) ($mspPendingSnapshot['total'] ?? 0));
$mspPendingCritical = max(0, (int) ($mspPendingSnapshot['critical'] ?? 0));
$mspPendingItems = is_array($mspPendingSnapshot['items'] ?? null) ? $mspPendingSnapshot['items'] : [];

$mspPendingTargetUrl = static function (mixed $value): string {
    $path = trim((string) $value);
    if (
        $path === ''
        || preg_match('#^(?:https?:)?//#i', $path) === 1
        || str_contains($path, '..')
    ) {
        return '#';
    }
    if (str_starts_with($path, '/portalgp/msp/')) {
        $url = $path;
    } elseif (preg_match('~^[A-Za-z0-9_./-]+\.php(?:[?#].*)?$~', $path) === 1) {
        $url = msp2Url($path);
    } else {
        return '#';
    }
    return msp2WithPendingReturn($url, 'pendientes/index.php');
};
?>
<li class="gp-pending-nav">
    <details class="gp-pending-details">
        <summary class="gp-nav-link gp-pending-summary" aria-label="Abrir pendientes">
            <i class="bi bi-bell" aria-hidden="true"></i>
            <span class="gp-pending-summary-text">Pendientes</span>
            <?php if (!$mspPendingAvailable): ?>
                <span class="gp-pending-badge gp-pending-badge-unavailable" title="No fue posible actualizar los pendientes">!</span>
            <?php elseif ($mspPendingTotal > 0): ?>
                <span class="gp-pending-badge"><?php echo $mspPendingTotal > 99 ? '99+' : $mspPendingTotal; ?></span>
            <?php endif; ?>
        </summary>
        <div class="gp-pending-panel">
            <div class="gp-pending-panel-head">
                <strong>Pendientes MSP</strong>
                <?php if ($mspPendingAvailable): ?>
                    <span><?php echo $mspPendingCritical; ?> crítico<?php echo $mspPendingCritical === 1 ? '' : 's'; ?></span>
                <?php else: ?>
                    <span>No disponible</span>
                <?php endif; ?>
            </div>

            <?php if (!$mspPendingAvailable): ?>
                <p class="gp-pending-empty">No fue posible actualizar la bandeja. Puedes abrirla para reintentar.</p>
            <?php elseif ($mspPendingItems === []): ?>
                <p class="gp-pending-empty">No hay pendientes visibles para tus permisos.</p>
            <?php else: ?>
                <div class="gp-pending-items">
                    <?php foreach ($mspPendingItems as $mspPendingItem): ?>
                        <?php
                        $mspPriority = strtoupper((string) ($mspPendingItem['prioridad'] ?? 'NORMAL'));
                        $mspTarget = $mspPendingTargetUrl($mspPendingItem['url_accion'] ?? '');
                        ?>
                        <a class="gp-pending-item" href="<?php echo msp2Escape($mspTarget); ?>">
                            <span class="gp-pending-dot gp-pending-dot-<?php echo msp2Escape(strtolower($mspPriority)); ?>" aria-hidden="true"></span>
                            <span>
                                <strong><?php echo msp2Escape((string) ($mspPendingItem['titulo'] ?? 'Pendiente')); ?></strong>
                                <small><?php echo msp2Escape((string) ($mspPendingItem['descripcion'] ?? '')); ?></small>
                            </span>
                            <i class="bi bi-chevron-right" aria-hidden="true"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <a class="gp-pending-all" href="<?php echo msp2Escape(msp2Url('pendientes/index.php')); ?>">
                Ver bandeja completa <i class="bi bi-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
    </details>
</li>
