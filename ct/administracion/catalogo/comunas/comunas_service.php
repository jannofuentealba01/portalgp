<?php
declare(strict_types=1);

require_once __DIR__ . '/comunas_repository.php';

function ctCatalogoComunasNormalizeNombre(string $raw): string
{
    $nombre = ctNormalizeText($raw);
    if ($nombre === '') {
        throw new RuntimeException('Debes ingresar el nombre de la comuna.');
    }
    $len = function_exists('mb_strlen') ? mb_strlen($nombre) : strlen($nombre);
    if ($len > 120) {
        throw new RuntimeException('El nombre excede el máximo de 120 caracteres.');
    }
    return $nombre;
}

function ctCatalogoComunasRedirect(): never
{
    header('Location: ' . ctUrl('administracion/catalogo/comunas/index.php'));
    exit();
}

function ctCatalogoComunasHandlePost(PDO $conn, array $post): never
{
    $accion = trim((string) ($post['accion'] ?? ''));

    try {
        if ($accion === 'crear') {
            $nombre = ctCatalogoComunasNormalizeNombre((string) ($post['nombre'] ?? ''));
            if (ctCatalogoComunasRepoExistsByNombre($conn, $nombre)) {
                throw new RuntimeException('Ya existe una comuna con ese nombre.');
            }
            ctCatalogoComunasRepoInsert($conn, $nombre);
            ctSetFlash('success', 'Comuna creada correctamente.');
            ctCatalogoComunasRedirect();
        }

        if ($accion === 'editar') {
            $id = is_numeric((string) ($post['id'] ?? '')) ? (int) $post['id'] : 0;
            if ($id <= 0 || !ctCatalogoComunasRepoExistsById($conn, $id)) {
                throw new RuntimeException('La comuna no existe.');
            }
            $nombre = ctCatalogoComunasNormalizeNombre((string) ($post['nombre'] ?? ''));
            if (ctCatalogoComunasRepoExistsByNombre($conn, $nombre, $id)) {
                throw new RuntimeException('Ya existe otra comuna con ese nombre.');
            }
            ctCatalogoComunasRepoUpdate($conn, $id, $nombre);
            ctSetFlash('success', 'Comuna actualizada correctamente.');
            ctCatalogoComunasRedirect();
        }

        if ($accion === 'eliminar') {
            $id = is_numeric((string) ($post['id'] ?? '')) ? (int) $post['id'] : 0;
            if ($id <= 0 || !ctCatalogoComunasRepoExistsById($conn, $id)) {
                throw new RuntimeException('La comuna no existe.');
            }
            if (ctCatalogoComunasRepoTerrenosCount($conn, $id) > 0) {
                throw new RuntimeException('No puedes eliminar la comuna porque tiene terrenos asociados.');
            }
            ctCatalogoComunasRepoDelete($conn, $id);
            ctSetFlash('success', 'Comuna eliminada correctamente.');
            ctCatalogoComunasRedirect();
        }

        throw new RuntimeException('Acción no reconocida.');
    } catch (Throwable $e) {
        ctSetFlash('danger', trim((string) $e->getMessage()) ?: 'No fue posible procesar la solicitud.');
        ctCatalogoComunasRedirect();
    }
}

function ctCatalogoComunasFetchData(PDO $conn): array
{
    try {
        return ['error' => null, 'rows' => ctCatalogoComunasRepoList($conn)];
    } catch (Throwable $e) {
        return ['error' => 'No fue posible cargar comunas.', 'rows' => []];
    }
}
