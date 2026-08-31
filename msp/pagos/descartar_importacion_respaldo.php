<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/respaldo_excel_helper.php';

msp2RequireAccess();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('pagos/index.php');
}

msp2PagosPreviewSessionClear();
msp2SetFlash('success', 'La vista previa de importación fue descartada.');
msp2Redirect('pagos/index.php');
