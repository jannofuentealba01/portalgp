<?php
declare(strict_types=1);

require_once __DIR__ . '/estados_prediales_repository.php';

function ctCatalogoEstadosPredialesNormalizeNombre(string $raw): string
{
    $nombre = ctNormalizeText($raw);
    if ($nombre === '') {
        throw new RuntimeException('Debes ingresar el nombre del estado predial.');
    }
    $len = function_exists('mb_strlen') ? mb_strlen($nombre) : strlen($nombre);
    if ($len > 120) {
        throw new RuntimeException('El nombre excede el máximo de 120 caracteres.');
    }
    return $nombre;
}

function ctCatalogoEstadosPredialesRedirect(): never
{
    header('Location: ' . ctUrl('administracion/catalogo/estados_prediales/index.php'));
    exit();
}

function ctCatalogoEstadosPredialesHandlePost(PDO $conn, array $post): never
{
    $accion = trim((string) ($post['accion'] ?? ''));

    try {
        if ($accion === 'crear') {
            $nombre = ctCatalogoEstadosPredialesNormalizeNombre((string) ($post['nombre'] ?? ''));
            if (ctCatalogoEstadosPredialesRepoExistsByNombre($conn, $nombre)) {
                throw new RuntimeException('Ya existe un estado predial con ese nombre.');
            }
            ctCatalogoEstadosPredialesRepoInsert($conn, $nombre);
            ctSetFlash('success', 'Estado predial creado correctamente.');
            ctCatalogoEstadosPredialesRedirect();
        }

        if ($accion === 'editar') {
            $id = is_numeric((string) ($post['id'] ?? '')) ? (int) $post['id'] : 0;
            if ($id <= 0 || !ctCatalogoEstadosPredialesRepoExistsById($conn, $id)) {
                throw new RuntimeException('El estado predial no existe.');
            }
            $nombre = ctCatalogoEstadosPredialesNormalizeNombre((string) ($post['nombre'] ?? ''));
            if (ctCatalogoEstadosPredialesRepoExistsByNombre($conn, $nombre, $id)) {
                throw new RuntimeException('Ya existe otro estado predial con ese nombre.');
            }
            ctCatalogoEstadosPredialesRepoUpdate($conn, $id, $nombre);
            ctSetFlash('success', 'Estado predial actualizado correctamente.');
            ctCatalogoEstadosPredialesRedirect();
        }

        if ($accion === 'eliminar') {
            $id = is_numeric((string) ($post['id'] ?? '')) ? (int) $post['id'] : 0;
            if ($id <= 0 || !ctCatalogoEstadosPredialesRepoExistsById($conn, $id)) {
                throw new RuntimeException('El estado predial no existe.');
            }
            if (ctCatalogoEstadosPredialesRepoTerrenosCount($conn, $id) > 0) {
                throw new RuntimeException('No puedes eliminar el estado predial porque tiene terrenos asociados.');
            }
            ctCatalogoEstadosPredialesRepoDelete($conn, $id);
            ctSetFlash('success', 'Estado predial eliminado correctamente.');
            ctCatalogoEstadosPredialesRedirect();
        }

        throw new RuntimeException('Acción no reconocida.');
    } catch (Throwable $e) {
        ctSetFlash('danger', trim((string) $e->getMessage()) ?: 'No fue posible procesar la solicitud.');
        ctCatalogoEstadosPredialesRedirect();
    }
}

function ctCatalogoEstadosPredialesFetchData(PDO $conn): array
{
    try {
        return ['error' => null, 'rows' => ctCatalogoEstadosPredialesRepoList($conn)];
    } catch (Throwable $e) {
        return ['error' => 'No fue posible cargar estados prediales.', 'rows' => []];
    }
}
