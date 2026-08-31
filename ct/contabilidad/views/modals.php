<?php
declare(strict_types=1);
$today = date('Y-m-d');
?>

<div class="modal fade" id="ct-modal-registrar-tasacion" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post">
                <?php ctCsrfField(); ?>
                <input type="hidden" name="accion" value="registrar_tasacion">
                <div class="modal-header">
                    <h2 class="modal-title h5 mb-0">Registrar tasación</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="ct-tasacion-id-terreno">Terreno</label>
                            <select class="form-select ct-control-input" id="ct-tasacion-id-terreno" name="id_terreno" required>
                                <option value="">Seleccionar...</option>
                                <?php foreach ($terrenosSelector as $terrenoOpt): ?>
                                    <?php
                                    $idOpt = (int) ($terrenoOpt['id_terreno'] ?? 0);
                                    $rolOpt = trim((string) ($terrenoOpt['rol_asignado'] ?? ''));
                                    $superficieOpt = is_numeric((string) ($terrenoOpt['superficie_m2'] ?? null))
                                        ? (float) $terrenoOpt['superficie_m2']
                                        : 0.0;
                                    if ($idOpt <= 0) {
                                        continue;
                                    }
                                    $label = ($rolOpt !== '' ? $rolOpt : 'Sin rol') . ' (#' . $idOpt . ')';
                                    ?>
                                    <option value="<?php echo $idOpt; ?>" data-superficie-m2="<?php echo ctEscape((string) $superficieOpt); ?>">
                                        <?php echo ctEscape($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text" id="ct-tasacion-superficie-info">Superficie: selecciona un terreno.</div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="ct-tasacion-tipo">Tipo tasación</label>
                            <select class="form-select ct-control-input" id="ct-tasacion-tipo" name="id_tipo_tasacion" required>
                                <option value="">Seleccionar...</option>
                                <?php foreach ($tiposTasacion as $tipo): ?>
                                    <?php
                                    $idTipo = (int) ($tipo['id_tipo_tasacion'] ?? 0);
                                    $nombreTipo = trim((string) ($tipo['nombre'] ?? ''));
                                    if ($idTipo <= 0 || $nombreTipo === '') {
                                        continue;
                                    }
                                    ?>
                                    <option value="<?php echo $idTipo; ?>"><?php echo ctEscape($nombreTipo); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="ct-tasacion-fecha">Fecha tasación</label>
                            <input type="date" class="form-control ct-control-input" id="ct-tasacion-fecha" name="fecha_tasacion" value="<?php echo ctEscape($today); ?>" required>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="ct-tasacion-valor-total">Valor total (UF)</label>
                            <input type="number" step="0.01" min="0.01" class="form-control ct-control-input" id="ct-tasacion-valor-total" name="valor_total_uf">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="ct-tasacion-valor-m2">Valor UF/m²</label>
                            <input type="number" step="0.0001" min="0.0001" class="form-control ct-control-input" id="ct-tasacion-valor-m2" name="valor_uf_m2">
                            <div class="form-text">Completa uno de los dos valores y el sistema calculará el otro según la superficie.</div>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="ct-tasacion-vigente-desde">Vigente desde</label>
                            <input type="date" class="form-control ct-control-input" id="ct-tasacion-vigente-desde" name="vigente_desde">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="ct-tasacion-vigente-hasta">Vigente hasta</label>
                            <input type="date" class="form-control ct-control-input" id="ct-tasacion-vigente-hasta" name="vigente_hasta">
                        </div>
                        <div class="col-12 col-md-4 d-flex align-items-end">
                            <div class="form-check mt-3">
                                <input class="form-check-input" type="checkbox" value="1" id="ct-tasacion-referencial" name="es_referencial">
                                <label class="form-check-label" for="ct-tasacion-referencial">Marcar como referencial</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar tasación</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="ct-modal-registrar-venta" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post">
                <?php ctCsrfField(); ?>
                <input type="hidden" name="accion" value="registrar_venta">
                <div class="modal-header">
                    <h2 class="modal-title h5 mb-0">Registrar venta</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2 mb-3">
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="ct-venta-id-terreno">Terreno</label>
                            <select class="form-select ct-control-input" id="ct-venta-id-terreno" name="id_terreno" required>
                                <option value="">Seleccionar...</option>
                                <?php foreach ($terrenosSelector as $terrenoOpt): ?>
                                    <?php
                                    $idOpt = (int) ($terrenoOpt['id_terreno'] ?? 0);
                                    $rolOpt = trim((string) ($terrenoOpt['rol_asignado'] ?? ''));
                                    $superficieOpt = is_numeric((string) ($terrenoOpt['superficie_m2'] ?? null))
                                        ? (float) $terrenoOpt['superficie_m2']
                                        : 0.0;
                                    if ($idOpt <= 0) {
                                        continue;
                                    }
                                    $label = ($rolOpt !== '' ? $rolOpt : 'Sin rol') . ' (#' . $idOpt . ')';
                                    ?>
                                    <option value="<?php echo $idOpt; ?>" data-superficie-m2="<?php echo ctEscape((string) $superficieOpt); ?>">
                                        <?php echo ctEscape($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text" id="ct-venta-superficie-info">Superficie: selecciona un terreno.</div>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="ct-venta-fecha">Fecha venta</label>
                            <input type="date" class="form-control ct-control-input" id="ct-venta-fecha" name="fecha_venta" value="<?php echo ctEscape($today); ?>" required>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="ct-venta-tasacion-ref">Tasación referencial (opcional)</label>
                            <select class="form-select ct-control-input" id="ct-venta-tasacion-ref" name="id_tasacion_referencial">
                                <option value="">Sin referencia</option>
                                <?php foreach ($tasacionesSelector as $tasacion): ?>
                                    <?php
                                    $idTas = (int) ($tasacion['id_tasacion'] ?? 0);
                                    $rolTas = trim((string) ($tasacion['rol_asignado'] ?? ''));
                                    if ($idTas <= 0) {
                                        continue;
                                    }
                                    $labelTas = '#'.$idTas.' | '.($rolTas !== '' ? $rolTas : 'Sin rol').' | '.ctComercialFormatDate((string) ($tasacion['fecha_tasacion'] ?? '')).' | UF '.ctComercialFormatUf($tasacion['valor_total_uf'] ?? 0);
                                    ?>
                                    <option value="<?php echo $idTas; ?>"><?php echo ctEscape($labelTas); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="ct-venta-valor-total">Valor total (UF)</label>
                            <input type="number" step="0.01" min="0.01" class="form-control ct-control-input" id="ct-venta-valor-total" name="valor_total_uf">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="ct-venta-valor-m2">Valor venta UF/m²</label>
                            <input type="number" step="0.0001" min="0.0001" class="form-control ct-control-input" id="ct-venta-valor-m2" name="valor_venta_uf_m2">
                            <div class="form-text">Completa uno de los dos valores y el sistema calculará el otro según la superficie.</div>
                        </div>
                    </div>

                    <div class="border rounded p-2">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h3 class="h6 mb-0">Compradores y porcentajes</h3>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="ct-venta-add-comprador">
                                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Agregar comprador
                            </button>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                <tr>
                                    <th>Tercero</th>
                                    <th>%</th>
                                    <th>Rol en venta</th>
                                    <th class="text-center">Quitar</th>
                                </tr>
                                </thead>
                                <tbody id="ct-venta-compradores-body">
                                <tr class="ct-venta-comprador-row">
                                    <td>
                                        <select class="form-select form-select-sm" name="venta_id_tercero[]" required>
                                            <option value="">Seleccionar...</option>
                                            <?php foreach ($tercerosSelector as $tercero): ?>
                                                <?php
                                                $idTercero = (int) ($tercero['id_tercero'] ?? 0);
                                                $nombreTercero = trim((string) ($tercero['nombre_razon_social'] ?? ''));
                                                if ($idTercero <= 0 || $nombreTercero === '') {
                                                    continue;
                                                }
                                                ?>
                                                <option value="<?php echo $idTercero; ?>"><?php echo ctEscape($nombreTercero); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input type="number" step="0.01" min="0.01" max="100" class="form-control form-control-sm" name="venta_porcentaje[]" required></td>
                                    <td><input type="text" maxlength="30" class="form-control form-control-sm" name="venta_rol[]" placeholder="Comprador / Cesionario"></td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-outline-danger js-remove-comprador" title="Quitar fila">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar venta</button>
                </div>
            </form>
        </div>
    </div>
</div>
