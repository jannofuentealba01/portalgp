<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

function msp2ResolveLocalesRedirectFromPost(): string
{
    $default = 'locales/index.php';
    $redirectTo = trim((string) ($_POST['redirect_to'] ?? ''));
    if ($redirectTo === '') {
        return $default;
    }

    $parts = parse_url($redirectTo);
    if (!is_array($parts)) {
        return $default;
    }

    $path = ltrim((string) ($parts['path'] ?? ''), '/');
    if ($path !== $default) {
        return $default;
    }

    $queryRaw = (string) ($parts['query'] ?? '');
    if ($queryRaw === '') {
        return $path;
    }

    $query = [];
    parse_str($queryRaw, $query);
    if (!is_array($query) || $query === []) {
        return $path;
    }

    $sanitized = [];

    if (isset($query['filtroTexto']) && is_scalar($query['filtroTexto'])) {
        $filtroTexto = msp2NormalizeText((string) $query['filtroTexto']);
        if ($filtroTexto !== '') {
            $sanitized['filtroTexto'] = $filtroTexto;
        }
    }

    if (isset($query['filtroEstado']) && is_scalar($query['filtroEstado'])) {
        $filtroEstadoRaw = trim((string) $query['filtroEstado']);
        if (ctype_digit($filtroEstadoRaw)) {
            $filtroEstado = (int) $filtroEstadoRaw;
            if ($filtroEstado > 0) {
                $sanitized['filtroEstado'] = $filtroEstado;
            }
        }
    }

    if (isset($query['lineas']) && is_scalar($query['lineas'])) {
        $lineas = (int) $query['lineas'];
        if (in_array($lineas, [10, 25, 50, 100, 200], true)) {
            $sanitized['lineas'] = $lineas;
        }
    }

    if (isset($query['pagina']) && is_scalar($query['pagina'])) {
        $paginaRaw = trim((string) $query['pagina']);
        if (ctype_digit($paginaRaw)) {
            $sanitized['pagina'] = max(1, (int) $paginaRaw);
        }
    }

    if ($sanitized === []) {
        return $path;
    }

    return $path . '?' . http_build_query($sanitized);
}

function msp2RedirectLocalesFromPost(): never
{
    msp2Redirect(msp2ResolveLocalesRedirectFromPost());
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2RedirectLocalesFromPost();
}

$idLocal = filter_input(INPUT_POST, 'id_local', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if ($idLocal === false || $idLocal === null) {
    msp2SetFlash('warning', 'El local indicado no es válido.');
    msp2RedirectLocalesFromPost();
}

try {
    $dependencias = [];

    if (msp2TableExists($conn, 'msp_ocupacion_locales')) {
        $stmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_ocupacion_locales WHERE id_local = :id_local');
        $stmt->bindValue(':id_local', $idLocal, PDO::PARAM_INT);
        $stmt->execute();
        $ocupaciones = (int) $stmt->fetchColumn();

        if ($ocupaciones > 0) {
            $dependencias[] = $ocupaciones . ' ocupación(es)';
        }
    }

    if (msp2TableExists($conn, 'msp_medidores')) {
        $medidoresStmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_medidores WHERE id_local = :id_local');
        $medidoresStmt->bindValue(':id_local', $idLocal, PDO::PARAM_INT);
        $medidoresStmt->execute();
        $medidores = (int) $medidoresStmt->fetchColumn();

        if ($medidores > 0) {
            $dependencias[] = $medidores . ' medidor(es)';
        }
    }

    if (msp2TableExists($conn, 'msp_garantias')) {
        $garantiasStmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_garantias WHERE id_local = :id_local');
        $garantiasStmt->bindValue(':id_local', $idLocal, PDO::PARAM_INT);
        $garantiasStmt->execute();
        $garantias = (int) $garantiasStmt->fetchColumn();

        if ($garantias > 0) {
            $dependencias[] = $garantias . ' garantía(s)';
        }
    }

    if (msp2TableExists($conn, 'msp_cargos_salida')) {
        $cargosStmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_cargos_salida WHERE id_local = :id_local');
        $cargosStmt->bindValue(':id_local', $idLocal, PDO::PARAM_INT);
        $cargosStmt->execute();
        $cargos = (int) $cargosStmt->fetchColumn();

        if ($cargos > 0) {
            $dependencias[] = $cargos . ' cargo(s) de salida';
        }
    }

    if ($dependencias !== []) {
        msp2SetFlash(
            'warning',
            'No puedes eliminar el local porque tiene registros asociados: '
            . implode(', ', $dependencias)
            . '. Regulariza o anula esos registros antes de eliminarlo.'
        );
        msp2RedirectLocalesFromPost();
    }

    $deleteStmt = $conn->prepare('DELETE FROM dbo.msp_locales WHERE id_local = :id_local');
    $deleteStmt->bindValue(':id_local', $idLocal, PDO::PARAM_INT);
    $deleteStmt->execute();

    if ($deleteStmt->rowCount() === 0) {
        msp2SetFlash('warning', 'El local que intentas eliminar ya no existe.');
        msp2RedirectLocalesFromPost();
    }

    msp2SetFlash('success', 'El local fue eliminado correctamente.');
} catch (PDOException $exception) {
    msp2SetFlash('danger', 'No fue posible eliminar el local. Revisa dependencias o la estructura de la base.');
}

msp2RedirectLocalesFromPost();
