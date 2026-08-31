<?php
declare(strict_types=1);

require_once __DIR__ . '/entidades_financieras_repository.php';

function ctCatalogoEntidadesFinancierasNormalizeNombre(string $raw): string
{
    $nombre = ctNormalizeText($raw);
    if ($nombre === '') {
        throw new RuntimeException('Debes ingresar el nombre de la entidad financiera.');
    }
    $len = function_exists('mb_strlen') ? mb_strlen($nombre) : strlen($nombre);
    if ($len > 120) {
        throw new RuntimeException('El nombre excede el máximo de 120 caracteres.');
    }
    return $nombre;
}

function ctCatalogoEntidadesFinancierasRedirect(): never
{
    header('Location: ' . ctUrl('administracion/catalogo/entidades_financieras/index.php'));
    exit();
}

function ctCatalogoEntidadesFinancierasHandlePost(PDO $conn, array $post): never
{
    $accion = trim((string) ($post['accion'] ?? ''));

    try {
        if ($accion === 'crear') {
            $nombre = ctCatalogoEntidadesFinancierasNormalizeNombre((string) ($post['nombre'] ?? ''));
            if (ctCatalogoEntidadesFinancierasRepoExistsByNombre($conn, $nombre)) {
                throw new RuntimeException('Ya existe una entidad financiera con ese nombre.');
            }
            ctCatalogoEntidadesFinancierasRepoInsert($conn, $nombre);
            ctSetFlash('success', 'Entidad financiera creada correctamente.');
            ctCatalogoEntidadesFinancierasRedirect();
        }

        if ($accion === 'editar') {
            $id = is_numeric((string) ($post['id'] ?? '')) ? (int) $post['id'] : 0;
            if ($id <= 0 || !ctCatalogoEntidadesFinancierasRepoExistsById($conn, $id)) {
                throw new RuntimeException('La entidad financiera no existe.');
            }
            $nombre = ctCatalogoEntidadesFinancierasNormalizeNombre((string) ($post['nombre'] ?? ''));
            if (ctCatalogoEntidadesFinancierasRepoExistsByNombre($conn, $nombre, $id)) {
                throw new RuntimeException('Ya existe otra entidad financiera con ese nombre.');
            }
            ctCatalogoEntidadesFinancierasRepoUpdate($conn, $id, $nombre);
            ctSetFlash('success', 'Entidad financiera actualizada correctamente.');
            ctCatalogoEntidadesFinancierasRedirect();
        }

        if ($accion === 'toggle') {
            $id = is_numeric((string) ($post['id'] ?? '')) ? (int) $post['id'] : 0;
            if ($id <= 0 || !ctCatalogoEntidadesFinancierasRepoExistsById($conn, $id)) {
                throw new RuntimeException('La entidad financiera no existe.');
            }
            ctCatalogoEntidadesFinancierasRepoToggleActivo($conn, $id);
            ctSetFlash('success', 'Estado de la entidad financiera actualizado.');
            ctCatalogoEntidadesFinancierasRedirect();
        }

        throw new RuntimeException('Acción no reconocida.');
    } catch (Throwable $e) {
        ctSetFlash('danger', trim((string) $e->getMessage()) ?: 'No fue posible procesar la solicitud.');
        ctCatalogoEntidadesFinancierasRedirect();
    }
}

function ctCatalogoEntidadesFinancierasFetchData(PDO $conn): array
{
    try {
        return ['error' => null, 'rows' => ctCatalogoEntidadesFinancierasRepoList($conn)];
    } catch (Throwable $e) {
        return ['error' => 'No fue posible cargar entidades financieras.', 'rows' => []];
    }
}
