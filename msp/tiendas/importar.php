<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();
msp2SetFlash(
    'warning',
    'Importar Tiendas está deprecado. Usa Importar Contratos para mantener contrato, ocupación y garantía consistentes.'
);
msp2Redirect('contratos/index.php');
