<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
msp2RequireAccess();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('tesoreria/control_diario.php');
}

$nombre = msp2NormalizeText((string) ($_POST['nombre_cuenta'] ?? ''));
$banco = msp2NormalizeText((string) ($_POST['banco'] ?? ''));
$numero = msp2NormalizeText((string) ($_POST['numero_cuenta'] ?? ''));
if ($nombre === '' || $banco === '' || $numero === '' || mb_strlen($nombre) > 150 || mb_strlen($banco) > 120 || mb_strlen($numero) > 80) {
    msp2SetFlash('warning', 'Completa correctamente los datos de la cuenta bancaria.');
    msp2Redirect('tesoreria/control_diario.php');
}

$codigo = 'BANCO_' . strtoupper(substr(hash('sha256', $banco . '|' . $numero), 0, 12));
try {
    $stmt = $conn->prepare(
        'INSERT INTO dbo.msp_tesoreria_cuentas (codigo_cuenta,nombre_cuenta,tipo_cuenta,banco,numero_cuenta)
         SELECT :codigo,:nombre,N\'BANCO\',:banco,:numero
         WHERE NOT EXISTS (SELECT 1 FROM dbo.msp_tesoreria_cuentas WHERE tipo_cuenta=N\'BANCO\' AND banco=:banco2 AND numero_cuenta=:numero2)'
    );
    $stmt->execute([':codigo'=>$codigo, ':nombre'=>$nombre, ':banco'=>$banco, ':numero'=>$numero, ':banco2'=>$banco, ':numero2'=>$numero]);
    msp2SetFlash($stmt->rowCount() > 0 ? 'success' : 'info', $stmt->rowCount() > 0 ? 'Cuenta bancaria agregada.' : 'La cuenta bancaria ya estaba registrada.');
} catch (Throwable $exception) {
    msp2SetFlash('danger', 'No fue posible guardar la cuenta bancaria.');
}
msp2Redirect('tesoreria/control_diario.php');
