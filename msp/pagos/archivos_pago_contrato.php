<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();
msp2Redirect('pagos/archivos_pdf.php');
