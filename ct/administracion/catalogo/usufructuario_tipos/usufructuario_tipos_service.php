<?php
declare(strict_types=1);

require_once __DIR__ . '/usufructuario_tipos_repository.php';

function ctCatalogoUsufructuarioTiposNormalizeNombre(string $raw): string
{
    $nombre = ctNormalizeText($raw);
    if ($nombre === '') {
        throw new RuntimeException('Debes ingresar el nombre del tipo de usufructuario.');
    }
    $len = function_exists('mb_strlen') ? mb_strlen($nombre) : strlen($nombre);
    if ($len > 120) {
        throw new RuntimeException('El nombre excede el máximo de 120 caracteres.');
    }
    return $nombre;
}

function ctCatalogoUsufructuarioTiposRedirect(): never
{
    header('Location: ' . ctUrl('administracion/catalogo/usufructuario_tipos/index.php'));
    exit();
}

function ctCatalogoUsufructuarioTiposHandlePost(PDO $conn, array $post): never
{
    $accion = trim((string) ($post['accion'] ?? ''));

    try {
        if ($accion === 'crear') {
            $nombre = ctCatalogoUsufructuarioTiposNormalizeNombre((string) ($post['nombre'] ?? ''));
            if (ctCatalogoUsufructuarioTiposRepoExistsByNombre($conn, $nombre)) {
                throw new RuntimeException('Ya existe un tipo de usufructuario con ese nombre.');
            }
            ctCatalogoUsufructuarioTiposRepoInsert($conn, $nombre);
            ctSetFlash('success', 'Tipo de usufructuario creado correctamente.');
            ctCatalogoUsufructuarioTiposRedirect();
        }

        if ($accion === 'editar') {
            $id = is_numeric((string) ($post['id'] ?? '')) ? (int) $post['id'] : 0;
            if ($id <= 0 || !ctCatalogoUsufructuarioTiposRepoExistsById($conn, $id)) {
                throw new RuntimeException('El tipo de usufructuario no existe.');
            }
            $nombre = ctCatalogoUsufructuarioTiposNormalizeNombre((string) ($post['nombre'] ?? ''));
            if (ctCatalogoUsufructuarioTiposRepoExistsByNombre($conn, $nombre, $id)) {
                throw new RuntimeException('Ya existe otro tipo de usufructuario con ese nombre.');
            }
            ctCatalogoUsufructuarioTiposRepoUpdate($conn, $id, $nombre);
            ctSetFlash('success', 'Tipo de usufructuario actualizado correctamente.');
            ctCatalogoUsufructuarioTiposRedirect();
        }

        if ($accion === 'toggle') {
            $id = is_numeric((string) ($post['id'] ?? '')) ? (int) $post['id'] : 0;
            if ($id <= 0 || !ctCatalogoUsufructuarioTiposRepoExistsById($conn, $id)) {
                throw new RuntimeException('El tipo de usufructuario no existe.');
            }
            ctCatalogoUsufructuarioTiposRepoToggleActivo($conn, $id);
            ctSetFlash('success', 'Estado del tipo de usufructuario actualizado.');
            ctCatalogoUsufructuarioTiposRedirect();
        }

        throw new RuntimeException('Acción no reconocida.');
    } catch (Throwable $e) {
        ctSetFlash('danger', trim((string) $e->getMessage()) ?: 'No fue posible procesar la solicitud.');
        ctCatalogoUsufructuarioTiposRedirect();
    }
}

function ctCatalogoUsufructuarioTiposFetchData(PDO $conn): array
{
    try {
        return ['error' => null, 'rows' => ctCatalogoUsufructuarioTiposRepoList($conn)];
    } catch (Throwable $e) {
        return ['error' => 'No fue posible cargar tipos de usufructuario.', 'rows' => []];
    }
}
