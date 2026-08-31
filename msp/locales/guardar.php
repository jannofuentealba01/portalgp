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
$cdoLocal = msp2NormalizeLocalCode($_POST['cdo_local'] ?? null);
$descLocal = msp2NormalizeText($_POST['desc_local'] ?? null);
$idEstadoLocal = filter_input(INPUT_POST, 'id_estado_local', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

[$metrosValidos, $metrosCuadrados] = msp2NormalizeDecimalInput($_POST['metros_cuadrados'] ?? null, 2);
[$arriendoValido, $valorArriendoUf] = msp2NormalizeDecimalInput($_POST['valor_arriendo_uf'] ?? null, 4);

if ($cdoLocal === '') {
    msp2SetFlash('warning', 'Debes ingresar el código del local.');
    msp2RedirectLocalesFromPost();
}

if (mb_strlen($cdoLocal) > 20 || ($descLocal !== '' && mb_strlen($descLocal) > 200)) {
    msp2SetFlash('warning', 'El código o la descripción superan el largo permitido.');
    msp2RedirectLocalesFromPost();
}

if ($idEstadoLocal === false || $idEstadoLocal === null) {
    msp2SetFlash('warning', 'Debes seleccionar un estado válido para el local.');
    msp2RedirectLocalesFromPost();
}

if (!$metrosValidos || $metrosCuadrados === null || (float) $metrosCuadrados < 0) {
    msp2SetFlash('warning', 'Los metros cuadrados deben ser un número válido igual o mayor a cero.');
    msp2RedirectLocalesFromPost();
}

if (!$arriendoValido || $valorArriendoUf === null) {
    msp2SetFlash('warning', 'El arriendo UF referencial debe ser un número válido igual o mayor a cero.');
    msp2RedirectLocalesFromPost();
}

try {
    if ($idLocal !== false && $idLocal !== null) {
        $existsStmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_locales WHERE id_local = :id_local');
        $existsStmt->bindValue(':id_local', $idLocal, PDO::PARAM_INT);
        $existsStmt->execute();

        if ((int) $existsStmt->fetchColumn() === 0) {
            msp2SetFlash('warning', 'El local que intentas editar ya no existe.');
            msp2RedirectLocalesFromPost();
        }
    }

    $estadoStmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_estado_locales WHERE id_estado_local = :id_estado_local');
    $estadoStmt->bindValue(':id_estado_local', $idEstadoLocal, PDO::PARAM_INT);
    $estadoStmt->execute();

    if ((int) $estadoStmt->fetchColumn() === 0) {
        msp2SetFlash('warning', 'El estado seleccionado no existe en el catálogo.');
        msp2RedirectLocalesFromPost();
    }

    $cdoLocalKey = msp2LocalCodeKey($cdoLocal);
    $duplicateSql = 'SELECT COUNT(*) FROM dbo.msp_locales WHERE UPPER(LTRIM(RTRIM(cdo_local))) = :cdo_local_key';
    if ($idLocal !== false && $idLocal !== null) {
        $duplicateSql .= ' AND id_local <> :id_local';
    }

    $duplicateStmt = $conn->prepare($duplicateSql);
    $duplicateStmt->bindValue(':cdo_local_key', $cdoLocalKey, PDO::PARAM_STR);

    if ($idLocal !== false && $idLocal !== null) {
        $duplicateStmt->bindValue(':id_local', $idLocal, PDO::PARAM_INT);
    }

    $duplicateStmt->execute();

    if ((int) $duplicateStmt->fetchColumn() > 0) {
        msp2SetFlash('warning', 'Ya existe un local con ese código.');
        msp2RedirectLocalesFromPost();
    }

    if ($idLocal !== false && $idLocal !== null) {
        $stmt = $conn->prepare(
            'UPDATE dbo.msp_locales
             SET cdo_local = :cdo_local,
                 desc_local = :desc_local,
                 metros_cuadrados = :metros_cuadrados,
                 valor_arriendo_uf = :valor_arriendo_uf,
                 id_estado_local = :id_estado_local
             WHERE id_local = :id_local'
        );
        $stmt->bindValue(':id_local', $idLocal, PDO::PARAM_INT);
    } else {
        $stmt = $conn->prepare(
            'INSERT INTO dbo.msp_locales
                (cdo_local, desc_local, metros_cuadrados, valor_arriendo_uf, id_estado_local)
             VALUES
                (:cdo_local, :desc_local, :metros_cuadrados, :valor_arriendo_uf, :id_estado_local)'
        );
    }

    $stmt->bindValue(':cdo_local', $cdoLocal, PDO::PARAM_STR);
    $stmt->bindValue(':desc_local', $descLocal, PDO::PARAM_STR);
    $stmt->bindValue(':metros_cuadrados', $metrosCuadrados, PDO::PARAM_STR);
    $stmt->bindValue(':valor_arriendo_uf', $valorArriendoUf, PDO::PARAM_STR);
    $stmt->bindValue(':id_estado_local', $idEstadoLocal, PDO::PARAM_INT);
    $stmt->execute();

    msp2SetFlash('success', $idLocal ? 'El local fue actualizado correctamente.' : 'El local fue creado correctamente.');
} catch (PDOException $exception) {
    msp2SetFlash('danger', 'No fue posible guardar el local. Revisa la estructura de la base o intenta nuevamente.');
}

msp2RedirectLocalesFromPost();
