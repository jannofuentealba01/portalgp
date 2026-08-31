<?php
declare(strict_types=1);

require_once __DIR__ . '/terceros_repository.php';
require_once __DIR__ . '/terceros_import_service.php';

function ctTercerosAllowedLines(): array
{
    return [10, 25, 50, 100];
}

function ctTercerosAllowedSort(): array
{
    return [
        'id_tercero' => 'id_tercero',
        'tipo_persona' => 'tipo_persona',
        'rut' => 'rut',
        'nombre_razon_social' => 'nombre_razon_social',
    ];
}

function ctTercerosParseQuery(array $query): array
{
    $allowedLines = ctTercerosAllowedLines();
    $allowedSort = ctTercerosAllowedSort();

    $lineasPorPagina = isset($query['lineas']) && is_numeric((string) $query['lineas']) ? (int) $query['lineas'] : 25;
    if (!in_array($lineasPorPagina, $allowedLines, true)) {
        $lineasPorPagina = 25;
    }

    $paginaActual = isset($query['pagina']) && is_numeric((string) $query['pagina']) ? max(1, (int) $query['pagina']) : 1;
    $filtroNombre = ctNormalizeText((string) ($query['filtroNombre'] ?? ''));
    $filtroRut = ctTercerosNormalizeRutLoose((string) ($query['filtroRut'] ?? ''));
    $filtroRutDisplay = ctTercerosFormatRutDisplay($filtroRut);
    $filtroTipo = trim((string) ($query['filtroTipo'] ?? ''));
    $filtroRelacion = strtoupper(trim((string) ($query['filtroRelacion'] ?? '')));
    $orden = trim((string) ($query['orden'] ?? 'id_tercero'));
    $direccion = strtolower(trim((string) ($query['dir'] ?? 'desc')));
    $vista = trim((string) ($query['vista'] ?? 'tabla'));

    if (!isset($allowedSort[$orden])) {
        $orden = 'id_tercero';
    }
    if ($direccion !== 'asc' && $direccion !== 'desc') {
        $direccion = 'desc';
    }
    if ($vista !== 'tabla' && $vista !== 'cards') {
        $vista = 'tabla';
    }
    if ($filtroRelacion !== 'P' && $filtroRelacion !== 'C') {
        $filtroRelacion = '';
    }

    $queryBase = [
        'filtroNombre' => $filtroNombre,
        'filtroRut' => $filtroRut,
        'filtroTipo' => $filtroTipo,
        'filtroRelacion' => $filtroRelacion,
        'lineas' => $lineasPorPagina,
        'orden' => $orden,
        'dir' => $direccion,
        'vista' => $vista,
    ];

    return [
        'lineasPermitidas' => $allowedLines,
        'sortPermitidos' => $allowedSort,
        'lineasPorPagina' => $lineasPorPagina,
        'paginaActual' => $paginaActual,
        'filtroNombre' => $filtroNombre,
        'filtroRut' => $filtroRut,
        'filtroRutDisplay' => $filtroRutDisplay,
        'filtroTipo' => $filtroTipo,
        'filtroRelacion' => $filtroRelacion,
        'orden' => $orden,
        'direccion' => $direccion,
        'vista' => $vista,
        'queryBase' => $queryBase,
    ];
}

function ctTercerosBuildQuery(array $base, array $override = []): string
{
    $merged = array_merge($base, $override);
    foreach ($merged as $key => $value) {
        if ($value === '' || $value === null) {
            unset($merged[$key]);
        }
    }

    $qs = http_build_query($merged);
    return $qs === '' ? '' : ('?' . $qs);
}

function ctTercerosRedirectAfterPost(array $base): never
{
    $qs = http_build_query(array_filter($base, static fn($v) => $v !== '' && $v !== null));
    header('Location: ' . ($qs !== '' ? ('?' . $qs) : ''));
    exit();
}

function ctTercerosNormalizeWritePayload(array $post): array
{
    $tipo = strtoupper(trim((string) ($post['tipo_persona'] ?? '')));
    $rut = ctTercerosNormalizeRut((string) ($post['rut'] ?? ''));
    $nombre = ctNormalizeText((string) ($post['nombre_razon_social'] ?? ''));

    return [
        'tipo' => $tipo,
        'rut' => $rut,
        'nombre' => $nombre,
    ];
}

function ctTercerosNormalizeRut(string $rawRut): ?string
{
    $normalized = strtoupper(ctNormalizeText($rawRut));
    if ($normalized === '') {
        return null;
    }

    $clean = preg_replace('/[^0-9K]/', '', $normalized);
    if (!is_string($clean) || strlen($clean) < 2) {
        throw new RuntimeException('RUT inválido. Debe tener al menos cuerpo y dígito verificador.');
    }

    $dv = substr($clean, -1);
    $body = substr($clean, 0, -1);
    if ($body === '' || !preg_match('/^\d+$/', $body)) {
        throw new RuntimeException('RUT inválido. Formato esperado: XXXXXXXX-X');
    }

    return $body . '-' . $dv;
}

function ctTercerosNormalizeRutLoose(string $rawRut): string
{
    $normalized = strtoupper(ctNormalizeText($rawRut));
    if ($normalized === '') {
        return '';
    }

    $clean = preg_replace('/[^0-9K]/', '', $normalized);
    if (!is_string($clean) || $clean === '') {
        return '';
    }
    if (strlen($clean) === 1) {
        return $clean;
    }

    return substr($clean, 0, -1) . '-' . substr($clean, -1);
}

function ctTercerosFormatRutDisplay(?string $rut): string
{
    $value = strtoupper(trim((string) $rut));
    if ($value === '') {
        return '';
    }

    $clean = preg_replace('/[^0-9K]/', '', $value);
    if (!is_string($clean) || $clean === '') {
        return $value;
    }
    if (strlen($clean) === 1) {
        return $clean;
    }

    $dv = substr($clean, -1);
    $body = substr($clean, 0, -1);
    if ($body === '') {
        return $dv;
    }

    $reversed = strrev($body);
    $chunks = str_split($reversed, 3);
    $dotted = strrev(implode('.', $chunks));

    return $dotted . '-' . $dv;
}

function ctTercerosHandlePost(PDO $conn, array $post, array $files, array $queryBase): never
{
    $accion = trim((string) ($post['accion'] ?? ''));

    try {
        if ($accion === 'preview_importacion_terceros') {
            $defaultTipo = ctTercerosImportNormalizeDefaultTipo((string) ($post['tipo_persona_default'] ?? ''));
            $preview = ctTercerosImportBuildPreviewFromUpload(
                $conn,
                (array) ($files['archivo_importacion'] ?? []),
                $defaultTipo
            );
            ctTercerosImportSavePreview($preview, true);

            $summary = (array) ($preview['summary'] ?? []);
            $ready = (int) ($summary['ready'] ?? 0);
            $errors = (int) ($summary['errors'] ?? 0);

            if ($errors > 0) {
                ctSetFlash('warning', 'Preview generada. Hay filas con error que debes corregir antes de importar.');
            } elseif ($ready > 0) {
                ctSetFlash('success', 'Preview generada. Archivo listo para importar.');
            } else {
                ctSetFlash('warning', 'Preview generada, pero no hay filas listas para importar.');
            }

            ctTercerosRedirectAfterPost($queryBase);
        }

        if ($accion === 'descartar_importacion_terceros') {
            ctTercerosImportClearPreview();
            ctSetFlash('info', 'Preview de importación descartada.');
            ctTercerosRedirectAfterPost($queryBase);
        }

        if ($accion === 'confirmar_importacion_terceros') {
            $preview = ctTercerosImportGetPreview();
            if (!is_array($preview)) {
                throw new RuntimeException('No hay una importación pendiente para confirmar.');
            }

            $importId = trim((string) ($post['import_id'] ?? ''));
            if ($importId === '' || $importId !== (string) ($preview['id'] ?? '')) {
                throw new RuntimeException('La vista previa expiró o no coincide. Vuelve a cargar el archivo.');
            }

            $postedRows = $post['preview_rows'] ?? null;
            if (!is_array($postedRows)) {
                throw new RuntimeException('No se recibieron filas para validar.');
            }
            $defaultTipo = ctTercerosImportNormalizeDefaultTipo(
                (string) ($post['tipo_persona_default'] ?? (string) ($preview['default_tipo_persona'] ?? ''))
            );

            $rows = ctTercerosImportRowsFromPostedPreview($postedRows);
            $rows = ctTercerosImportOverlayPreviewMetadata($rows, (array) ($preview['rows'] ?? []));
            $validated = ctTercerosImportApplyValidation($conn, $rows, $defaultTipo);

            $preview['rows'] = $validated['rows'];
            $preview['summary'] = $validated['summary'];
            $preview['default_tipo_persona'] = $defaultTipo ?? '';
            ctTercerosImportSavePreview($preview, true);

            $summary = (array) $validated['summary'];
            $selected = (int) ($summary['selected'] ?? 0);
            $errors = (int) ($summary['errors'] ?? 0);
            $ready = (int) ($summary['ready'] ?? 0);

            if ($selected === 0) {
                throw new RuntimeException('Debes seleccionar al menos una fila para importar.');
            }
            if ($errors > 0 || $ready === 0) {
                ctSetFlash('warning', 'Aún hay filas con error en la preview. Corrígelas o desmárcalas para continuar.');
                ctTercerosRedirectAfterPost($queryBase);
            }

            $rowsToPersist = ctTercerosImportRowsReadyToInsert((array) $validated['rows']);
            if ($rowsToPersist === []) {
                throw new RuntimeException('No hay filas válidas para importar.');
            }

            $createdCount = 0;
            $updatedCount = 0;
            $unchangedCount = 0;
            $conn->beginTransaction();
            try {
                foreach ($rowsToPersist as $row) {
                    $rut = (string) ($row['rut'] ?? '');
                    $operation = strtolower(trim((string) ($row['operation'] ?? 'create')));
                    $existingId = max(0, (int) ($row['existing_id'] ?? 0));
                    if ($operation === 'update' && $existingId > 0) {
                        if (($row['no_change'] ?? false) === true) {
                            $unchangedCount++;
                            continue;
                        }

                        $existingRut = (string) ($row['existing_rut'] ?? '');
                        $preserveExistingRut = (($row['preserve_existing_rut'] ?? false) === true);
                        $rutToPersist = null;
                        if ($rut !== '') {
                            $rutToPersist = $rut;
                        } elseif ($preserveExistingRut && $existingRut !== '') {
                            $rutToPersist = $existingRut;
                        }

                        ctTercerosRepoUpdate(
                            $conn,
                            $existingId,
                            (string) ($row['tipo_persona'] ?? ''),
                            $rutToPersist,
                            (string) ($row['nombre_razon_social'] ?? '')
                        );
                        $updatedCount++;
                    } else {
                        ctTercerosRepoInsert(
                            $conn,
                            (string) ($row['tipo_persona'] ?? ''),
                            $rut !== '' ? $rut : null,
                            (string) ($row['nombre_razon_social'] ?? '')
                        );
                        $createdCount++;
                    }
                }
                $conn->commit();
            } catch (Throwable $exception) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                throw $exception;
            }

            ctTercerosImportClearPreview();
            ctSetFlash(
                'success',
                'Importación finalizada. Creados: ' . $createdCount
                . '. Actualizados: ' . $updatedCount
                . '. Sin cambios: ' . $unchangedCount . '.'
            );
            $queryBase['pagina'] = 1;
            ctTercerosRedirectAfterPost($queryBase);
        }

        if ($accion === 'crear_tercero') {
            $payload = ctTercerosNormalizeWritePayload($post);
            if (($payload['tipo'] !== 'N' && $payload['tipo'] !== 'J') || $payload['nombre'] === '') {
                throw new RuntimeException('Debes completar tipo y nombre/razón social.');
            }
            if ($payload['tipo'] === 'J' && ctTercerosRepoExistsRazonSocialJuridica($conn, $payload['nombre'])) {
                throw new RuntimeException('Ya existe una persona jurídica con esa razón social.');
            }

            ctTercerosRepoInsert($conn, $payload['tipo'], $payload['rut'], $payload['nombre']);
            ctSetFlash('success', 'Tercero creado correctamente.');
            $queryBase['pagina'] = 1;
            ctTercerosRedirectAfterPost($queryBase);
        }

        if ($accion === 'editar_tercero') {
            $id = (int) ($post['id_tercero'] ?? 0);
            $payload = ctTercerosNormalizeWritePayload($post);
            if ($id <= 0 || ($payload['tipo'] !== 'N' && $payload['tipo'] !== 'J') || $payload['nombre'] === '') {
                throw new RuntimeException('Datos inválidos para actualizar tercero.');
            }
            if ($payload['tipo'] === 'J' && ctTercerosRepoExistsRazonSocialJuridica($conn, $payload['nombre'], $id)) {
                throw new RuntimeException('Ya existe otra persona jurídica con esa razón social.');
            }

            ctTercerosRepoUpdate($conn, $id, $payload['tipo'], $payload['rut'], $payload['nombre']);
            ctSetFlash('success', 'Tercero actualizado correctamente.');
            ctTercerosRedirectAfterPost($queryBase);
        }

        if ($accion === 'eliminar_tercero') {
            $id = (int) ($post['id_tercero'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('ID de tercero inválido.');
            }

            ctTercerosRepoDelete($conn, $id);
            ctSetFlash('success', 'Tercero eliminado correctamente.');
            ctTercerosRedirectAfterPost($queryBase);
        }

        ctSetFlash('warning', 'Acción no reconocida.');
        ctTercerosRedirectAfterPost($queryBase);
    } catch (Throwable $exception) {
        $errorMessage = $exception->getMessage();
        if (stripos($errorMessage, "No se puede insertar el valor NULL en la columna 'rut'") !== false) {
            $errorMessage = 'La base aún tiene ct_tercero.rut como NOT NULL. Ejecuta db/50_ct_integridad.sql (o db/core_ct.sql) para dejar RUT opcional.';
        }
        if (stripos($errorMessage, 'UX_ct_tercero_razon_social_juridica') !== false) {
            $errorMessage = 'Ya existe una persona jurídica con esa razón social.';
        }

        ctSetFlash('danger', $errorMessage);
        ctTercerosRedirectAfterPost($queryBase);
    }
}

function ctTercerosBuildPaginationItems(int $paginaActual, int $totalPaginas): array
{
    if ($totalPaginas <= 1) {
        return [];
    }

    $paginationItems = [];
    $start = max(1, $paginaActual - 2);
    $end = min($totalPaginas, $paginaActual + 2);

    if ($start > 1) {
        $paginationItems[] = ['label' => '1', 'page' => 1, 'active' => false];
        if ($start > 2) {
            $paginationItems[] = ['label' => '...', 'page' => null, 'active' => false];
        }
    }

    for ($page = $start; $page <= $end; $page++) {
        $paginationItems[] = ['label' => (string) $page, 'page' => $page, 'active' => $page === $paginaActual];
    }

    if ($end < $totalPaginas) {
        if ($end < $totalPaginas - 1) {
            $paginationItems[] = ['label' => '...', 'page' => null, 'active' => false];
        }
        $paginationItems[] = ['label' => (string) $totalPaginas, 'page' => $totalPaginas, 'active' => false];
    }

    return $paginationItems;
}

function ctTercerosFetchPage(PDO $conn, array $state): array
{
    $terceros = [];
    $tercerosError = null;
    $totalRegistros = 0;
    $totalPaginas = 1;
    $paginaActual = (int) $state['paginaActual'];
    $lineasPorPagina = (int) $state['lineasPorPagina'];

    try {
        $filtros = [
            'filtroNombre' => $state['filtroNombre'],
            'filtroRut' => $state['filtroRut'],
            'filtroTipo' => $state['filtroTipo'],
            'filtroRelacion' => $state['filtroRelacion'],
        ];

        $totalRegistros = ctTercerosRepoCount($conn, $filtros);
        $totalPaginas = max(1, (int) ceil($totalRegistros / $lineasPorPagina));
        $paginaActual = min($paginaActual, $totalPaginas);
        $offset = ($paginaActual - 1) * $lineasPorPagina;

        $orderSql = $state['sortPermitidos'][$state['orden']] . ' ' . strtoupper((string) $state['direccion']);
        $terceros = ctTercerosRepoList($conn, $filtros, $orderSql, $offset, $lineasPorPagina);
    } catch (Throwable $exception) {
        $tercerosError = 'No fue posible cargar terceros desde la base de datos.';
    }

    return [
        'terceros' => $terceros,
        'tercerosError' => $tercerosError,
        'totalRegistros' => $totalRegistros,
        'totalPaginas' => $totalPaginas,
        'paginaActual' => $paginaActual,
        'paginationItems' => ctTercerosBuildPaginationItems($paginaActual, $totalPaginas),
    ];
}

function ctTercerosSortLink(string $col, array $queryBase, string $currentCol, string $currentDir): string
{
    $nextDir = ($col === $currentCol && $currentDir === 'asc') ? 'desc' : 'asc';
    $query = array_merge($queryBase, ['orden' => $col, 'dir' => $nextDir, 'pagina' => 1]);
    return '?' . http_build_query(array_filter($query, static fn($v) => $v !== '' && $v !== null));
}

function ctTercerosSortIcon(string $col, string $currentCol, string $currentDir): string
{
    if ($col !== $currentCol) {
        return 'bi-arrow-down-up';
    }
    return $currentDir === 'asc' ? 'bi-sort-up' : 'bi-sort-down';
}

function ctTercerosTipoPersonaLabel(?string $tipo): string
{
    $value = strtoupper(trim((string) $tipo));
    if ($value === 'N') {
        return 'Persona Natural';
    }
    if ($value === 'J') {
        return 'Persona Jurídica';
    }
    return $value;
}
