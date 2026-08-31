<?php
declare(strict_types=1);
?>
<section id="terrenos-historial" class="mt-3 ct-crud-fade-in">
    <div class="ct-terrenos-toolbar ct-crud-toolbar d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <div class="ct-terrenos-toolbar-title ct-crud-toolbar-title small text-muted">Consultor de historial predial</div>
            <div class="ct-terrenos-toolbar-hint ct-crud-toolbar-hint small text-muted">Busca eventos por rol, terreno, comuna, tipo de operación y fechas.</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <div class="btn-group" role="group" aria-label="Vista del historial">
                <a class="btn <?php echo $historialVista === 'eventos' ? 'btn-primary' : 'btn-outline-primary'; ?> ct-crud-btn-main" href="<?php echo ctEscape($historialEventosHref); ?>">
                    <i class="bi bi-clock-history me-1" aria-hidden="true"></i>Eventos
                </a>
                <a class="btn <?php echo $historialVista === 'lista' ? 'btn-primary' : 'btn-outline-primary'; ?> ct-crud-btn-main" href="<?php echo ctEscape($historialListaHref); ?>">
                    <i class="bi bi-list-columns-reverse me-1" aria-hidden="true"></i>Lista simple
                </a>
            </div>
            <a class="btn btn-outline-secondary ct-crud-btn-main" href="<?php echo ctEscape(ctUrl('predial/terrenos/index.php')); ?>">
                <i class="bi bi-grid me-1" aria-hidden="true"></i>Volver a terrenos
            </a>
        </div>
    </div>

    <?php if ($historialError !== null): ?>
        <div class="alert alert-warning mb-3"><?php echo ctEscape($historialError); ?></div>
    <?php endif; ?>

    <form class="ct-terrenos-filtros ct-crud-filters row g-2 align-items-end mb-3" method="get" id="ct-historial-filtros-form">
        <?php if ($historialVista === 'lista'): ?>
            <input type="hidden" name="vista" value="lista">
        <?php endif; ?>
        <div class="col-12 col-md-3">
            <?php
            ctRenderSearchableSelectField([
                'wrapper_class' => '',
                'label' => 'Rol asignado',
                'input_name' => 'rol',
                'input_id' => 'ct-hf-rol',
                'picker_id' => 'ct-hf-rol-picker',
                'button_id' => 'ct-hf-rol-btn',
                'filter_id' => 'ct-hf-rol-filter',
                'list_id' => 'ct-hf-rol-list',
                'error_id' => 'ct-hf-rol-error',
                'error_message' => 'Debes seleccionar un rol válido.',
                'button_placeholder' => 'Todos',
                'filter_placeholder' => 'Buscar rol...',
                'value' => $filtroRol,
                'options' => $rolOptions,
            ]);
            ?>
        </div>

        <div class="col-12 col-md-2">
            <label class="form-label small text-muted" for="ct-hf-id-terreno">ID terreno</label>
            <input class="form-control ct-control-input" id="ct-hf-id-terreno" type="number" min="1" step="1" name="id_terreno" value="<?php echo $filtroIdTerreno > 0 ? ctEscape((string) $filtroIdTerreno) : ''; ?>" placeholder="ID">
        </div>

        <div class="col-12 col-md-2">
            <label class="form-label small text-muted" for="ct-hf-comuna">Comuna</label>
            <select class="form-select ct-control-input" id="ct-hf-comuna" name="id_comuna">
                <option value="">Todas</option>
                <?php foreach ($comunas as $comuna): ?>
                    <?php
                    $idComuna = (int) ($comuna['id_comuna'] ?? 0);
                    $nombreComuna = trim((string) ($comuna['nombre'] ?? ''));
                    if ($idComuna <= 0 || $nombreComuna === '') {
                        continue;
                    }
                    ?>
                    <option value="<?php echo ctEscape((string) $idComuna); ?>" <?php echo $idComuna === $filtroComuna ? 'selected' : ''; ?>>
                        <?php echo ctEscape($nombreComuna); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-12 col-md-2">
            <label class="form-label small text-muted" for="ct-hf-tipo-op">Tipo operación</label>
            <select class="form-select ct-control-input" id="ct-hf-tipo-op" name="tipo_operacion">
                <option value="">Todas</option>
                <?php foreach ($tiposOperacion as $tipoOperacion): ?>
                    <?php $tipoUpper = strtoupper(trim((string) $tipoOperacion)); ?>
                    <?php if ($tipoUpper === '') continue; ?>
                    <option value="<?php echo ctEscape($tipoUpper); ?>" <?php echo $tipoUpper === $filtroTipoOperacion ? 'selected' : ''; ?>>
                        <?php echo ctEscape(ctTerrenosFormatOperacionLabel($tipoUpper)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-6 col-md-1">
            <label class="form-label small text-muted" for="ct-hf-fecha-desde">Desde</label>
            <input class="form-control ct-control-input" id="ct-hf-fecha-desde" type="date" name="fecha_desde" value="<?php echo ctEscape($fechaDesde); ?>">
        </div>

        <div class="col-6 col-md-1">
            <label class="form-label small text-muted" for="ct-hf-fecha-hasta">Hasta</label>
            <input class="form-control ct-control-input" id="ct-hf-fecha-hasta" type="date" name="fecha_hasta" value="<?php echo ctEscape($fechaHasta); ?>">
        </div>

        <div class="col-6 col-md-1">
            <label class="form-label small text-muted" for="ct-hf-lineas">Líneas</label>
            <select class="form-select ct-control-input" id="ct-hf-lineas" name="lineas">
                <?php foreach ($lineasPermitidas as $lineas): ?>
                    <option value="<?php echo ctEscape((string) $lineas); ?>" <?php echo ((int) $lineas === (int) $lineasPorPagina) ? 'selected' : ''; ?>>
                        <?php echo ctEscape((string) $lineas); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-6 col-md-1 d-grid">
            <button type="submit" class="btn btn-outline-primary ct-crud-filter-submit">
                <i class="bi bi-funnel me-1" aria-hidden="true"></i>Filtrar
            </button>
        </div>

        <div class="col-6 col-md-1 d-grid">
            <a class="btn btn-outline-secondary ct-crud-filter-submit" href="<?php echo ctEscape($historialLimpiarHref); ?>">
                Limpiar
            </a>
        </div>
    </form>

    <div class="border rounded p-3 bg-white">
        <div class="table-responsive">
            <?php if ($historialVista === 'lista'): ?>
            <table class="table table-sm align-middle mb-0 ct-terrenos-table ct-crud-table">
                <thead>
                <tr>
                    <th>OPERACION</th>
                    <th>DOC</th>
                    <th>Lote Origen</th>
                    <th>Lote Resultado</th>
                    <th class="text-end">Valor</th>
                    <th class="text-end">m^2</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($historialRows === []): ?>
                    <tr><td colspan="6" class="text-muted text-center py-4">Sin operaciones para los filtros seleccionados.</td></tr>
                <?php else: ?>
                    <?php foreach ($historialRows as $row): ?>
                        <?php
                        $tipoOperacionRow = trim((string) ($row['tipo_operacion'] ?? ''));
                        $origenesRow = $historialSimpleLotes($row, 'origen');
                        $resultadosRow = $historialSimpleLotes($row, 'resultado');
                        ?>
                        <tr>
                            <td>
                                <div><?php echo ctEscape(ctTerrenosFormatOperacionLabel($tipoOperacionRow)); ?></div>
                                <div class="small text-muted"><?php echo ctEscape(ctTerrenosFormatDate((string) ($row['fecha_evento'] ?? ''))); ?></div>
                            </td>
                            <td class="small"><?php echo ctEscape($historialSimpleDoc($row)); ?></td>
                            <td class="small">
                                <?php if ($origenesRow === []): ?>
                                    <span class="text-muted">-</span>
                                <?php else: ?>
                                    <?php foreach ($origenesRow as $lote): ?>
                                        <div><?php echo ctEscape($lote); ?></div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <?php if ($resultadosRow === []): ?>
                                    <span class="text-muted">-</span>
                                <?php else: ?>
                                    <?php foreach ($resultadosRow as $lote): ?>
                                        <div><?php echo ctEscape($lote); ?></div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td class="small text-end text-nowrap"><?php echo ctEscape($historialSimpleValor($row)); ?></td>
                            <td class="small text-end text-nowrap"><?php echo ctEscape($historialSimpleSuperficie($row)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            <?php else: ?>
            <table class="table table-sm align-middle mb-0 ct-terrenos-table ct-crud-table">
                <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Terreno</th>
                    <th>Comuna</th>
                    <th>Evento</th>
                    <th>Detalle</th>
                    <th>Operación</th>
                    <th>Usuario</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($historialRows === []): ?>
                    <tr><td colspan="7" class="text-muted text-center py-4">Sin eventos para los filtros seleccionados.</td></tr>
                <?php else: ?>
                    <?php foreach ($historialRows as $row): ?>
                        <?php
                        $idTerrenoRow = (int) ($row['id_terreno'] ?? 0);
                        $rolAsignadoRow = trim((string) ($row['rol_asignado'] ?? ''));
                        $idOperacionRow = (int) ($row['id_operacion'] ?? 0);
                        $tipoOperacionRow = trim((string) ($row['tipo_operacion'] ?? ''));
                        $idUsuarioRow = (int) ($row['id_usuario'] ?? 0);
                        $eventoLabel = $historialEventoLabel($row);
                        $eventoClass = $historialEventoClass($row);
                        $detalleEvento = $historialDetalle($row);
                        ?>
                        <tr>
                            <td class="small text-nowrap"><?php echo ctEscape(ctTerrenosFormatDateTime((string) ($row['fecha_evento'] ?? ''))); ?></td>
                            <td>
                                <div>#<?php echo ctEscape((string) $idTerrenoRow); ?></div>
                                <div class="small text-muted"><?php echo ctEscape($rolAsignadoRow !== '' ? $rolAsignadoRow : '-'); ?></div>
                            </td>
                            <td><?php echo ctEscape(ctTerrenosDisplayValue((string) ($row['comuna_nombre'] ?? ''))); ?></td>
                            <td>
                                <span class="ct-historial-chip <?php echo ctEscape($eventoClass); ?>"><?php echo ctEscape($eventoLabel); ?></span>
                            </td>
                            <td class="small"><?php echo ctEscape($detalleEvento); ?></td>
                            <td class="small">
                                <?php if ($idOperacionRow > 0): ?>
                                    #<?php echo ctEscape((string) $idOperacionRow); ?>
                                    <?php if ($tipoOperacionRow !== ''): ?>
                                        <div class="text-muted"><?php echo ctEscape(ctTerrenosFormatOperacionLabel($tipoOperacionRow)); ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <?php echo $idUsuarioRow > 0 ? ctEscape('#' . (string) $idUsuarioRow) : '<span class="text-muted">-</span>'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
            <div class="small text-muted">
                Total: <strong><?php echo number_format((int) $totalRegistros, 0, ',', '.'); ?></strong>
                | Página <strong><?php echo (int) $paginaActual; ?></strong> de <strong><?php echo (int) $totalPaginas; ?></strong>
            </div>

            <?php if ($totalPaginas > 1): ?>
                <nav aria-label="Paginación de historial predial">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?php echo $paginaActual <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo ctEscape(ctTerrenosBuildQuery($queryBase, ['pagina' => max(1, ((int) $paginaActual) - 1)])); ?>" aria-label="Anterior">&laquo;</a>
                        </li>
                        <?php foreach ($paginationItems as $item): ?>
                            <?php if ($item['page'] === null): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php else: ?>
                                <li class="page-item <?php echo $item['active'] ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo ctEscape(ctTerrenosBuildQuery($queryBase, ['pagina' => $item['page']])); ?>"><?php echo ctEscape($item['label']); ?></a>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <li class="page-item <?php echo $paginaActual >= $totalPaginas ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo ctEscape(ctTerrenosBuildQuery($queryBase, ['pagina' => min((int) $totalPaginas, ((int) $paginaActual) + 1)])); ?>" aria-label="Siguiente">&raquo;</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</section>
