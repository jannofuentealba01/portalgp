<?php
declare(strict_types=1);
?>
<section class="mt-3 ct-crud-fade-in" id="ct-comercial">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h2 class="h4 mb-0">Comercial y ventas</h2>
        <div class="d-flex gap-2">
            <button class="btn btn-primary ct-crud-btn-main" type="button" data-bs-toggle="modal" data-bs-target="#ct-modal-registrar-tasacion">
                <i class="bi bi-graph-up-arrow me-1" aria-hidden="true"></i>Registrar tasación
            </button>
            <button class="btn btn-outline-primary ct-crud-btn-main" type="button" data-bs-toggle="modal" data-bs-target="#ct-modal-registrar-venta">
                <i class="bi bi-bag-check me-1" aria-hidden="true"></i>Registrar venta
            </button>
        </div>
    </div>

    <form class="ct-crud-filters row g-2 align-items-end mb-3" method="get">
        <div class="col-12 col-lg-5">
            <label class="form-label small text-muted" for="ct-comercial-filtro-texto">Buscar</label>
            <input
                class="form-control ct-control-input"
                id="ct-comercial-filtro-texto"
                name="filtroTexto"
                value="<?php echo ctEscape($filtroTexto); ?>"
                placeholder="Rol, matriz, identificación o propietario"
            >
        </div>
        <div class="col-12 col-lg-3">
            <label class="form-label small text-muted" for="ct-comercial-filtro-estado">Estado comercial</label>
            <select class="form-select ct-control-input" id="ct-comercial-filtro-estado" name="filtroEstadoComercial">
                <option value="">Todos</option>
                <?php foreach ($estadosComerciales as $estado): ?>
                    <?php
                    $idEstado = (int) ($estado['id_estado_comercial'] ?? 0);
                    $nombreEstado = trim((string) ($estado['nombre'] ?? ''));
                    if ($idEstado <= 0 || $nombreEstado === '') {
                        continue;
                    }
                    ?>
                    <option value="<?php echo $idEstado; ?>"<?php echo $filtroEstadoComercial === $idEstado ? ' selected' : ''; ?>>
                        <?php echo ctEscape($nombreEstado); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-lg-2">
            <label class="form-label small text-muted" for="ct-comercial-lineas">Líneas</label>
            <select class="form-select ct-control-input" id="ct-comercial-lineas" name="lineas">
                <?php foreach ($lineasPermitidas as $linea): ?>
                    <option value="<?php echo (int) $linea; ?>"<?php echo $lineas === (int) $linea ? ' selected' : ''; ?>>
                        <?php echo (int) $linea; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-lg-2 d-grid d-lg-flex justify-content-lg-end gap-2">
            <button type="submit" class="btn btn-outline-primary ct-crud-filter-submit w-100 w-lg-auto">
                <i class="bi bi-funnel me-1" aria-hidden="true"></i>Filtrar
            </button>
            <a href="?" class="btn btn-outline-secondary ct-crud-filter-submit w-100 w-lg-auto">
                <i class="bi bi-eraser me-1" aria-hidden="true"></i>Limpiar
            </a>
        </div>
    </form>

    <div class="border rounded p-3 bg-white">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 ct-crud-table">
                <thead>
                <tr>
                    <th><a class="link-dark text-decoration-none" href="<?php echo ctEscape(ctComercialSortLink('id_terreno', $queryBase, $orden, $dir)); ?>">ID <i class="bi <?php echo ctEscape(ctComercialSortIcon('id_terreno', $orden, $dir)); ?>"></i></a></th>
                    <th><a class="link-dark text-decoration-none" href="<?php echo ctEscape(ctComercialSortLink('rol_asignado', $queryBase, $orden, $dir)); ?>">Rol <i class="bi <?php echo ctEscape(ctComercialSortIcon('rol_asignado', $orden, $dir)); ?>"></i></a></th>
                    <th>Comuna</th>
                    <th><a class="link-dark text-decoration-none" href="<?php echo ctEscape(ctComercialSortLink('estado_comercial', $queryBase, $orden, $dir)); ?>">Estado comercial <i class="bi <?php echo ctEscape(ctComercialSortIcon('estado_comercial', $orden, $dir)); ?>"></i></a></th>
                    <th><a class="link-dark text-decoration-none" href="<?php echo ctEscape(ctComercialSortLink('ultima_tasacion', $queryBase, $orden, $dir)); ?>">Última tasación <i class="bi <?php echo ctEscape(ctComercialSortIcon('ultima_tasacion', $orden, $dir)); ?>"></i></a></th>
                    <th><a class="link-dark text-decoration-none" href="<?php echo ctEscape(ctComercialSortLink('ultima_venta', $queryBase, $orden, $dir)); ?>">Última venta <i class="bi <?php echo ctEscape(ctComercialSortIcon('ultima_venta', $orden, $dir)); ?>"></i></a></th>
                    <th class="text-center">Acciones</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Sin terrenos para mostrar.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $idTerreno = (int) ($row['id_terreno'] ?? 0);
                        $rolAsignado = trim((string) ($row['rol_asignado'] ?? ''));
                        $ultimaTasacionFecha = trim((string) ($row['ultima_tasacion_fecha'] ?? ''));
                        $ultimaVentaFecha = trim((string) ($row['ultima_venta_fecha'] ?? ''));
                        ?>
                        <tr>
                            <td><?php echo $idTerreno; ?></td>
                            <td>
                                <div class="fw-semibold"><?php echo ctEscape($rolAsignado !== '' ? $rolAsignado : '-'); ?></div>
                                <div class="small text-muted"><?php echo ctEscape(trim((string) ($row['propietario_principal'] ?? '')) ?: 'Sin propietario vigente'); ?></div>
                            </td>
                            <td><?php echo ctEscape((string) ($row['comuna_nombre'] ?? '-')); ?></td>
                            <td><?php echo ctEscape((string) ($row['estado_comercial_nombre'] ?? '-')); ?></td>
                            <td>
                                <?php if ($ultimaTasacionFecha !== ''): ?>
                                    <div><?php echo ctEscape(ctComercialFormatDate($ultimaTasacionFecha)); ?></div>
                                    <div class="small text-muted">UF <?php echo ctEscape(ctComercialFormatUf($row['ultima_tasacion_valor_total_uf'] ?? 0)); ?></div>
                                <?php else: ?>
                                    <span class="text-muted">Sin tasación</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($ultimaVentaFecha !== ''): ?>
                                    <div><?php echo ctEscape(ctComercialFormatDate($ultimaVentaFecha)); ?></div>
                                    <div class="small text-muted">UF <?php echo ctEscape(ctComercialFormatUf($row['ultima_venta_valor_total_uf'] ?? 0)); ?></div>
                                <?php else: ?>
                                    <span class="text-muted">Sin venta</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="ct-crud-actions d-inline-flex gap-1">
                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary btn-sm js-open-modal-tasacion"
                                        data-id-terreno="<?php echo $idTerreno; ?>"
                                        data-rol="<?php echo ctEscape($rolAsignado); ?>"
                                        data-bs-toggle="modal"
                                        data-bs-target="#ct-modal-registrar-tasacion"
                                        title="Registrar tasación"
                                    >
                                        <i class="bi bi-graph-up" aria-hidden="true"></i>
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary btn-sm js-open-modal-venta"
                                        data-id-terreno="<?php echo $idTerreno; ?>"
                                        data-rol="<?php echo ctEscape($rolAsignado); ?>"
                                        data-bs-toggle="modal"
                                        data-bs-target="#ct-modal-registrar-venta"
                                        title="Registrar venta"
                                    >
                                        <i class="bi bi-bag-check" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-3 small text-muted">
            <div>Total: <?php echo $totalRegistros; ?> | Página <?php echo $paginaActual; ?> de <?php echo $totalPaginas; ?></div>
            <div class="d-flex gap-1">
                <?php if ($paginaActual > 1): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?php echo ctEscape(ctComercialBuildQuery($queryBase, ['pagina' => $paginaActual - 1])); ?>">Anterior</a>
                <?php endif; ?>
                <?php if ($paginaActual < $totalPaginas): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?php echo ctEscape(ctComercialBuildQuery($queryBase, ['pagina' => $paginaActual + 1])); ?>">Siguiente</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
