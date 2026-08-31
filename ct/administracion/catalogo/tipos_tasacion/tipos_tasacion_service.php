<?php
declare(strict_types=1);

require_once __DIR__ . '/tipos_tasacion_repository.php';

function ctCatalogoTiposTasacionNormalizeNombre(string $raw): string
{
    $nombre = ctNormalizeText($raw);
    if ($nombre === '') {
        throw new RuntimeException('Debes ingresar el nombre del tipo de tasacion.');
    }
    $len = function_exists('mb_strlen') ? mb_strlen($nombre) : strlen($nombre);
    if ($len > 120) {
        throw new RuntimeException('El nombre excede el máximo de 120 caracteres.');
    }
    return $nombre;
}

function ctCatalogoTiposTasacionRedirect(): never
{
    header('Location: ' . ctUrl('administracion/catalogo/tipos_tasacion/index.php'));
    exit();
}

function ctCatalogoTiposTasacionHandlePost(PDO $conn, array $post): never
{
    $accion = trim((string) ($post['accion'] ?? ''));

    try {
        if ($accion === 'crear') {
            $nombre = ctCatalogoTiposTasacionNormalizeNombre((string) ($post['nombre'] ?? ''));
            if (ctCatalogoTiposTasacionRepoExistsByNombre($conn, $nombre)) {
                throw new RuntimeException('Ya existe un tipo de tasacion con ese nombre.');
            }
            ctCatalogoTiposTasacionRepoInsert($conn, $nombre);
            ctSetFlash('success', 'Tipo de tasacion creado correctamente.');
            ctCatalogoTiposTasacionRedirect();
        }

        if ($accion === 'editar') {
            $id = is_numeric((string) ($post['id'] ?? '')) ? (int) $post['id'] : 0;
            if ($id <= 0 || !ctCatalogoTiposTasacionRepoExistsById($conn, $id)) {
                throw new RuntimeException('El tipo de tasacion no existe.');
            }
            $nombre = ctCatalogoTiposTasacionNormalizeNombre((string) ($post['nombre'] ?? ''));
            if (ctCatalogoTiposTasacionRepoExistsByNombre($conn, $nombre, $id)) {
                throw new RuntimeException('Ya existe otro tipo de tasacion con ese nombre.');
            }
            ctCatalogoTiposTasacionRepoUpdate($conn, $id, $nombre);
            ctSetFlash('success', 'Tipo de tasacion actualizado correctamente.');
            ctCatalogoTiposTasacionRedirect();
        }

        if ($accion === 'eliminar') {
            $id = is_numeric((string) ($post['id'] ?? '')) ? (int) $post['id'] : 0;
            if ($id <= 0 || !ctCatalogoTiposTasacionRepoExistsById($conn, $id)) {
                throw new RuntimeException('El tipo de tasacion no existe.');
            }
            if (ctCatalogoTiposTasacionRepoTasacionesCount($conn, $id) > 0) {
                throw new RuntimeException('No puedes eliminar el tipo de tasacion porque tiene tasaciones asociadas.');
            }
            ctCatalogoTiposTasacionRepoDelete($conn, $id);
            ctSetFlash('success', 'Tipo de tasacion eliminado correctamente.');
            ctCatalogoTiposTasacionRedirect();
        }

        throw new RuntimeException('Acción no reconocida.');
    } catch (Throwable $e) {
        ctSetFlash('danger', trim((string) $e->getMessage()) ?: 'No fue posible procesar la solicitud.');
        ctCatalogoTiposTasacionRedirect();
    }
}

function ctCatalogoTiposTasacionFetchData(PDO $conn): array
{
    try {
        return ['error' => null, 'rows' => ctCatalogoTiposTasacionRepoList($conn)];
    } catch (Throwable $e) {
        return ['error' => 'No fue posible cargar tipos de tasacion.', 'rows' => []];
    }
}
