<?php
declare(strict_types=1);

require_once __DIR__ . '/estados_comerciales_repository.php';

function ctCatalogoEstadosComercialesNormalizeNombre(string $raw): string
{
    $nombre = ctNormalizeText($raw);
    if ($nombre === '') {
        throw new RuntimeException('Debes ingresar el nombre del estado comercial.');
    }
    $len = function_exists('mb_strlen') ? mb_strlen($nombre) : strlen($nombre);
    if ($len > 120) {
        throw new RuntimeException('El nombre excede el máximo de 120 caracteres.');
    }
    return $nombre;
}

function ctCatalogoEstadosComercialesRedirect(): never
{
    header('Location: ' . ctUrl('administracion/catalogo/estados_comerciales/index.php'));
    exit();
}

function ctCatalogoEstadosComercialesHandlePost(PDO $conn, array $post): never
{
    $accion = trim((string) ($post['accion'] ?? ''));

    try {
        if ($accion === 'crear') {
            $nombre = ctCatalogoEstadosComercialesNormalizeNombre((string) ($post['nombre'] ?? ''));
            if (ctCatalogoEstadosComercialesRepoExistsByNombre($conn, $nombre)) {
                throw new RuntimeException('Ya existe un estado comercial con ese nombre.');
            }
            ctCatalogoEstadosComercialesRepoInsert($conn, $nombre);
            ctSetFlash('success', 'Estado comercial creado correctamente.');
            ctCatalogoEstadosComercialesRedirect();
        }

        if ($accion === 'editar') {
            $id = is_numeric((string) ($post['id'] ?? '')) ? (int) $post['id'] : 0;
            if ($id <= 0 || !ctCatalogoEstadosComercialesRepoExistsById($conn, $id)) {
                throw new RuntimeException('El estado comercial no existe.');
            }
            $nombre = ctCatalogoEstadosComercialesNormalizeNombre((string) ($post['nombre'] ?? ''));
            if (ctCatalogoEstadosComercialesRepoExistsByNombre($conn, $nombre, $id)) {
                throw new RuntimeException('Ya existe otro estado comercial con ese nombre.');
            }
            ctCatalogoEstadosComercialesRepoUpdate($conn, $id, $nombre);
            ctSetFlash('success', 'Estado comercial actualizado correctamente.');
            ctCatalogoEstadosComercialesRedirect();
        }

        if ($accion === 'eliminar') {
            $id = is_numeric((string) ($post['id'] ?? '')) ? (int) $post['id'] : 0;
            if ($id <= 0 || !ctCatalogoEstadosComercialesRepoExistsById($conn, $id)) {
                throw new RuntimeException('El estado comercial no existe.');
            }
            if (ctCatalogoEstadosComercialesRepoTerrenosCount($conn, $id) > 0) {
                throw new RuntimeException('No puedes eliminar el estado comercial porque tiene terrenos asociados.');
            }
            ctCatalogoEstadosComercialesRepoDelete($conn, $id);
            ctSetFlash('success', 'Estado comercial eliminado correctamente.');
            ctCatalogoEstadosComercialesRedirect();
        }

        throw new RuntimeException('Acción no reconocida.');
    } catch (Throwable $e) {
        ctSetFlash('danger', trim((string) $e->getMessage()) ?: 'No fue posible procesar la solicitud.');
        ctCatalogoEstadosComercialesRedirect();
    }
}

function ctCatalogoEstadosComercialesFetchData(PDO $conn): array
{
    try {
        return ['error' => null, 'rows' => ctCatalogoEstadosComercialesRepoList($conn)];
    } catch (Throwable $e) {
        return ['error' => 'No fue posible cargar estados comerciales.', 'rows' => []];
    }
}
