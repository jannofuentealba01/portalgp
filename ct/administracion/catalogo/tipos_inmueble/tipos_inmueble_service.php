<?php
declare(strict_types=1);

require_once __DIR__ . '/tipos_inmueble_repository.php';

function ctCatalogoTiposInmuebleNormalizeNombre(string $raw): string
{
    $nombre = ctNormalizeText($raw);
    if ($nombre === '') {
        throw new RuntimeException('Debes ingresar el nombre del tipo de inmueble.');
    }
    $len = function_exists('mb_strlen') ? mb_strlen($nombre) : strlen($nombre);
    if ($len > 120) {
        throw new RuntimeException('El nombre excede el máximo de 120 caracteres.');
    }
    return $nombre;
}

function ctCatalogoTiposInmuebleRedirect(): never
{
    header('Location: ' . ctUrl('administracion/catalogo/tipos_inmueble/index.php'));
    exit();
}

function ctCatalogoTiposInmuebleHandlePost(PDO $conn, array $post): never
{
    $accion = trim((string) ($post['accion'] ?? ''));

    try {
        if ($accion === 'crear') {
            $nombre = ctCatalogoTiposInmuebleNormalizeNombre((string) ($post['nombre'] ?? ''));
            if (ctCatalogoTiposInmuebleRepoExistsByNombre($conn, $nombre)) {
                throw new RuntimeException('Ya existe un tipo de inmueble con ese nombre.');
            }
            ctCatalogoTiposInmuebleRepoInsert($conn, $nombre);
            ctSetFlash('success', 'Tipo de inmueble creado correctamente.');
            ctCatalogoTiposInmuebleRedirect();
        }

        if ($accion === 'editar') {
            $id = is_numeric((string) ($post['id'] ?? '')) ? (int) $post['id'] : 0;
            if ($id <= 0 || !ctCatalogoTiposInmuebleRepoExistsById($conn, $id)) {
                throw new RuntimeException('El tipo de inmueble no existe.');
            }
            $nombre = ctCatalogoTiposInmuebleNormalizeNombre((string) ($post['nombre'] ?? ''));
            if (ctCatalogoTiposInmuebleRepoExistsByNombre($conn, $nombre, $id)) {
                throw new RuntimeException('Ya existe otro tipo de inmueble con ese nombre.');
            }
            ctCatalogoTiposInmuebleRepoUpdate($conn, $id, $nombre);
            ctSetFlash('success', 'Tipo de inmueble actualizado correctamente.');
            ctCatalogoTiposInmuebleRedirect();
        }

        if ($accion === 'toggle') {
            $id = is_numeric((string) ($post['id'] ?? '')) ? (int) $post['id'] : 0;
            if ($id <= 0 || !ctCatalogoTiposInmuebleRepoExistsById($conn, $id)) {
                throw new RuntimeException('El tipo de inmueble no existe.');
            }
            ctCatalogoTiposInmuebleRepoToggleActivo($conn, $id);
            ctSetFlash('success', 'Estado del tipo de inmueble actualizado.');
            ctCatalogoTiposInmuebleRedirect();
        }

        throw new RuntimeException('Acción no reconocida.');
    } catch (Throwable $e) {
        ctSetFlash('danger', trim((string) $e->getMessage()) ?: 'No fue posible procesar la solicitud.');
        ctCatalogoTiposInmuebleRedirect();
    }
}

function ctCatalogoTiposInmuebleFetchData(PDO $conn): array
{
    try {
        return ['error' => null, 'rows' => ctCatalogoTiposInmuebleRepoList($conn)];
    } catch (Throwable $e) {
        return ['error' => 'No fue posible cargar tipos de inmueble.', 'rows' => []];
    }
}
