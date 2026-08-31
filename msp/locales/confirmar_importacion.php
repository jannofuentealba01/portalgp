<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('locales/index.php');
}

$token = trim((string) ($_POST['token'] ?? ''));
$preview = $_SESSION['msp2_locales_import_preview'] ?? null;

if ($token === '' || !is_array($preview) || !isset($preview['token']) || !is_string($preview['token']) || !hash_equals($preview['token'], $token)) {
    msp2SetFlash('warning', 'La vista previa ya no es válida. Vuelve a cargar el archivo.');
    msp2Redirect('locales/index.php');
}

$createdAt = isset($preview['created_at']) ? (int) $preview['created_at'] : 0;
if ($createdAt <= 0 || (time() - $createdAt) > 1800) {
    unset($_SESSION['msp2_locales_import_preview']);
    msp2SetFlash('warning', 'La vista previa expiró (30 min). Vuelve a cargar el archivo.');
    msp2Redirect('locales/index.php');
}

$rows = $preview['rows'] ?? null;
$fileName = (string) ($preview['file_name'] ?? 'archivo');

if (!is_array($rows) || $rows === []) {
    unset($_SESSION['msp2_locales_import_preview']);
    msp2SetFlash('warning', 'No hay filas válidas para importar.');
    msp2Redirect('locales/index.php');
}

if (!msp2TableExists($conn, 'msp_locales') || !msp2TableExists($conn, 'msp_estado_locales')) {
    msp2SetFlash('warning', 'Faltan tablas base (`msp_locales` o `msp_estado_locales`).');
    msp2Redirect('locales/index.php');
}

$findStmt = $conn->prepare('SELECT TOP 1 id_local FROM dbo.msp_locales WHERE UPPER(LTRIM(RTRIM(cdo_local))) = :cdo_local_key');

$insertStmt = $conn->prepare(
    'INSERT INTO dbo.msp_locales
        (cdo_local, desc_local, metros_cuadrados, valor_arriendo_uf, id_estado_local)
     VALUES
        (:cdo_local, :desc_local, :metros_cuadrados, :valor_arriendo_uf, :id_estado_local)'
);

$updateStmt = $conn->prepare(
    'UPDATE dbo.msp_locales
     SET desc_local = :desc_local,
         metros_cuadrados = :metros_cuadrados,
         valor_arriendo_uf = :valor_arriendo_uf,
         id_estado_local = :id_estado_local
     WHERE id_local = :id_local'
);

$insertados = 0;
$actualizados = 0;
$sinCambios = 0;

try {
    $conn->beginTransaction();

    foreach ($rows as $row) {
        if (!is_array($row)) {
            throw new RuntimeException('Formato de fila inválido en lote de importación.');
        }

        $cdoLocal = msp2NormalizeLocalCode((string) ($row['cdo_local'] ?? ''));
        $cdoLocalKey = msp2LocalCodeKey($cdoLocal);
        $descLocal = msp2NormalizeText((string) ($row['desc_local'] ?? ''));
        $metros = (string) ($row['metros_cuadrados'] ?? '');
        $arriendo = (string) ($row['valor_arriendo_uf'] ?? '');
        $idEstadoLocal = (int) ($row['id_estado_local'] ?? 0);
        $action = strtoupper(trim((string) ($row['action'] ?? '')));

        if ($cdoLocal === '' || $descLocal === '' || $metros === '' || $arriendo === '' || $idEstadoLocal <= 0) {
            throw new RuntimeException('Fila inválida durante confirmación.');
        }

        $findStmt->bindValue(':cdo_local_key', $cdoLocalKey, PDO::PARAM_STR);
        $findStmt->execute();
        $idLocal = $findStmt->fetchColumn();

        if ($idLocal !== false) {
            if ($action === 'SIN_CAMBIOS') {
                $sinCambios++;
                continue;
            }

            $updateStmt->bindValue(':id_local', (int) $idLocal, PDO::PARAM_INT);
            $updateStmt->bindValue(':desc_local', $descLocal, PDO::PARAM_STR);
            $updateStmt->bindValue(':metros_cuadrados', $metros, PDO::PARAM_STR);
            $updateStmt->bindValue(':valor_arriendo_uf', $arriendo, PDO::PARAM_STR);
            $updateStmt->bindValue(':id_estado_local', $idEstadoLocal, PDO::PARAM_INT);
            $updateStmt->execute();
            $actualizados++;
        } else {
            $insertStmt->bindValue(':cdo_local', $cdoLocal, PDO::PARAM_STR);
            $insertStmt->bindValue(':desc_local', $descLocal, PDO::PARAM_STR);
            $insertStmt->bindValue(':metros_cuadrados', $metros, PDO::PARAM_STR);
            $insertStmt->bindValue(':valor_arriendo_uf', $arriendo, PDO::PARAM_STR);
            $insertStmt->bindValue(':id_estado_local', $idEstadoLocal, PDO::PARAM_INT);
            $insertStmt->execute();
            $insertados++;
        }
    }

    $conn->commit();
    unset($_SESSION['msp2_locales_import_preview']);

    msp2SetFlash(
        'success',
        'Importación completada desde `' . $fileName . '`: ' . $insertados . ' locales creados, ' . $actualizados . ' actualizados y ' . $sinCambios . ' sin cambios.'
    );
} catch (Throwable $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    msp2SetFlash('danger', 'No fue posible confirmar la importación. Se revirtió toda la transacción.');
}

msp2Redirect('locales/index.php');
