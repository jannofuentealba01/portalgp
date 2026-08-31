<?php
declare(strict_types=1);
?>
<section id="terreno-ficha" class="mt-3 ct-crud-fade-in">
    <div class="ct-terrenos-toolbar ct-crud-toolbar d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <div class="ct-terrenos-toolbar-title ct-crud-toolbar-title small text-muted">Ficha de terreno</div>
            <div class="ct-terrenos-toolbar-hint ct-crud-toolbar-hint small text-muted">Consulta propietarios vigentes e historial del terreno.</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-secondary ct-crud-btn-main" href="<?php echo ctEscape($volverHref); ?>">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a terrenos
            </a>
        </div>
    </div>

    <?php if ($fichaError !== null): ?>
        <div class="alert alert-warning mb-3"><?php echo ctEscape($fichaError); ?></div>
    <?php elseif (is_array($terreno)): ?>
        <?php
        $trazabilidadOperaciones = is_array($trazabilidad['operaciones'] ?? null) ? $trazabilidad['operaciones'] : [];
        $trazabilidadOperacionMap = [];
        foreach ($trazabilidadOperaciones as $opRow) {
            $idOp = (int) ($opRow['id_operacion'] ?? 0);
            if ($idOp > 0) {
                $trazabilidadOperacionMap[$idOp] = $opRow;
            }
        }
        $buildFichaTerrenoHref = static function (int $idTerreno, string $volverLimpio): string {
            $query = [
                'id_terreno' => $idTerreno > 0 ? (string) $idTerreno : '',
                'volver' => $volverLimpio,
            ];
            $query = array_filter($query, static fn($value) => $value !== '' && $value !== null);
            $qs = http_build_query($query);
            return ctUrl('predial/terrenos/terreno.php') . ($qs !== '' ? ('?' . $qs) : '');
        };
        $fichaHistorialListaDoc = static function (array $row): string {
            $documento = trim((string) ($row['documento_fuente'] ?? ''));
            if ($documento !== '') {
                return $documento;
            }

            $fuente = strtoupper(trim((string) ($row['fuente'] ?? '')));
            if ($fuente === 'TASACION') {
                return 'Tasación #' . (string) ((int) ($row['id_tasacion'] ?? 0));
            }
            if ($fuente === 'VENTA') {
                return 'Venta #' . (string) ((int) ($row['id_venta'] ?? 0));
            }

            $idOperacion = (int) ($row['id_operacion'] ?? 0);
            return $idOperacion > 0 ? ('Operación #' . (string) $idOperacion) : '-';
        };
        $fichaHistorialListaTerrenoLabel = static function (array $row): string {
            $idTerrenoRow = (int) ($row['id_terreno'] ?? $row['id_terreno_directo'] ?? 0);
            $rol = trim((string) ($row['rol_asignado'] ?? $row['rol_directo'] ?? ''));
            if ($idTerrenoRow <= 0) {
                return $rol !== '' ? $rol : '-';
            }
            return '#' . (string) $idTerrenoRow . ($rol !== '' ? (' (' . $rol . ')') : '');
        };
        $fichaHistorialListaLotes = static function (array $row, string $tipo) use ($fichaHistorialListaTerrenoLabel): array {
            $tipo = strtolower(trim($tipo));
            $fuente = strtoupper(trim((string) ($row['fuente'] ?? '')));
            if ($fuente === 'TASACION' || $fuente === 'VENTA') {
                return $tipo === 'resultado' ? [$fichaHistorialListaTerrenoLabel($row)] : [];
            }

            $lotes = [];
            $participantes = is_array($row['participantes'] ?? null) ? $row['participantes'] : [];
            foreach ($participantes as $participante) {
                $rolOperacion = strtoupper(trim((string) ($participante['rol_en_operacion'] ?? '')));
                $isOrigen = $rolOperacion === 'ORIGEN';
                $isResultado = in_array($rolOperacion, ['RESULTADO', 'ADQUIRIDO'], true);
                if ($tipo === 'origen' && !$isOrigen) {
                    continue;
                }
                if ($tipo === 'resultado' && !$isResultado) {
                    continue;
                }

                $label = $fichaHistorialListaTerrenoLabel($participante);
                if ($label !== '-') {
                    $lotes[$label] = true;
                }
            }

            return array_keys($lotes);
        };
        $fichaHistorialListaValor = static function (array $row): string {
            $valor = $row['valor_total_uf'] ?? null;
            if (!is_numeric((string) $valor) || (float) $valor <= 0) {
                return '-';
            }
            if (function_exists('ctComercialFormatUf')) {
                return 'UF ' . ctComercialFormatUf($valor);
            }
            return 'UF ' . number_format((float) $valor, 2, ',', '.');
        };
        $fichaHistorialListaSuperficie = static function (array $row) use ($fichaHistorialListaLotes): string {
            $fuente = strtoupper(trim((string) ($row['fuente'] ?? '')));
            if ($fuente === 'TASACION' || $fuente === 'VENTA') {
                $superficie = $row['superficie_directa'] ?? null;
                return is_numeric((string) $superficie) && (float) $superficie > 0
                    ? ctTerrenosFormatSuperficie((float) $superficie)
                    : '-';
            }

            $resultadoLabels = $fichaHistorialListaLotes($row, 'resultado');
            $usarResultados = $resultadoLabels !== [];
            $total = 0.0;
            foreach ((is_array($row['participantes'] ?? null) ? $row['participantes'] : []) as $participante) {
                $rolOperacion = strtoupper(trim((string) ($participante['rol_en_operacion'] ?? '')));
                if ($usarResultados && !in_array($rolOperacion, ['RESULTADO', 'ADQUIRIDO'], true)) {
                    continue;
                }
                if (!$usarResultados && $rolOperacion !== 'ORIGEN') {
                    continue;
                }
                $superficie = $participante['superficie_m2'] ?? null;
                if (is_numeric((string) $superficie)) {
                    $total += (float) $superficie;
                }
            }

            return $total > 0 ? ctTerrenosFormatSuperficie($total) : '-';
        };
        ?>
        <div class="row g-3">
            <div class="col-12 col-lg-5">
                <div class="border rounded bg-white p-3 h-100">
                    <h2 class="h6 mb-3">Datos del terreno</h2>
                    <dl class="row mb-0 ct-terreno-ficha-dl">
                        <dt class="col-5">ID</dt>
                        <dd class="col-7">#<?php echo ctEscape((string) ((int) ($terreno['id_terreno'] ?? 0))); ?></dd>

                        <dt class="col-5">Rol asignado</dt>
                        <dd class="col-7"><?php echo ctEscape((string) ($terreno['rol_asignado'] ?? '-')); ?></dd>

                        <dt class="col-5">Rol matriz</dt>
                        <dd class="col-7"><?php echo ctEscape(ctTerrenosDisplayValue((string) ($terreno['rol_matriz'] ?? ''))); ?></dd>

                        <dt class="col-5">Identificación</dt>
                        <dd class="col-7"><?php echo ctEscape(ctTerrenosDisplayValue((string) ($terreno['identificacion_propiedad'] ?? ''))); ?></dd>

                        <dt class="col-5">Superficie</dt>
                        <dd class="col-7"><?php echo ctEscape(ctTerrenosFormatSuperficie((float) ($terreno['superficie_m2'] ?? 0))); ?> m²</dd>

                        <dt class="col-5">Comuna</dt>
                        <dd class="col-7"><?php echo ctEscape(ctTerrenosDisplayValue((string) ($terreno['comuna_nombre'] ?? ''))); ?></dd>

                        <dt class="col-5">Estado</dt>
                        <dd class="col-7"><?php echo ctEscape(ctTerrenosDisplayValue((string) ($terreno['estado_predial_nombre'] ?? ''))); ?></dd>

                        <dt class="col-5">Estado comercial</dt>
                        <dd class="col-7"><?php echo ctEscape(ctTerrenosDisplayValue((string) ($terreno['estado_comercial_nombre'] ?? ''))); ?></dd>

                        <dt class="col-5">Tipo inmueble</dt>
                        <dd class="col-7"><?php echo ctEscape(ctTerrenosDisplayValue((string) ($terreno['tipo_inmueble_nombre'] ?? ''))); ?></dd>
                    </dl>
                </div>
            </div>

            <div class="col-12 col-lg-7">
                <div class="border rounded bg-white p-3 h-100">
                    <h2 class="h6 mb-3">Propietarios vigentes</h2>
                    <?php if ($titularesVigentes === []): ?>
                        <div class="ct-historial-empty">No hay propietarios vigentes para este terreno.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0 ct-crud-table">
                                <thead>
                                <tr>
                                    <th>Propietario</th>
                                    <th>RUT</th>
                                    <th class="text-end">% derecho</th>
                                    <th>Vigente desde</th>
                                    <th>Vigente hasta</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($titularesVigentes as $titular): ?>
                                    <?php
                                    $nombreTitular = trim((string) ($titular['tercero_nombre'] ?? ''));
                                    $rutTitular = trim((string) ($titular['tercero_rut'] ?? ''));
                                    ?>
                                    <tr>
                                        <td><?php echo ctEscape($nombreTitular !== '' ? $nombreTitular : 'Titular sin nombre'); ?></td>
                                        <td><?php echo ctEscape($rutTitular !== '' ? $rutTitular : '-'); ?></td>
                                        <td class="text-end"><?php echo ctEscape(number_format((float) ($titular['porcentaje_derecho'] ?? 0), 2, '.', '')); ?>%</td>
                                        <td><?php echo ctEscape(ctTerrenosFormatDate((string) ($titular['vigente_desde'] ?? ''))); ?></td>
                                        <td><?php echo ctEscape(ctTerrenosFormatDate((string) ($titular['vigente_hasta'] ?? ''))); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="border rounded bg-white p-3 mt-3">
            <h2 class="h6 mb-3">Historial del terreno</h2>
            <?php if ($eventos === []): ?>
                <div class="ct-historial-empty">Sin eventos de historial para este terreno.</div>
            <?php else: ?>
                <div class="ct-historial-timeline">
                    <?php foreach ($eventos as $evento): ?>
                        <?php
                        $tipo = trim((string) ($evento['tipo'] ?? ''));
                        $fecha = trim((string) ($evento['fecha_formateada'] ?? ''));
                        $fechaRaw = trim((string) ($evento['fecha'] ?? ''));
                        $titulo = trim((string) ($evento['titulo'] ?? 'Evento'));
                        $detalle = trim((string) ($evento['detalle'] ?? ''));
                        $lineas = is_array($evento['lineas'] ?? null) ? $evento['lineas'] : [];
                        $fechaOperacionRaw = trim((string) ($evento['fecha_operacion'] ?? ''));
                        $fechaOperacionFmt = trim((string) ($evento['fecha_operacion_formateada'] ?? ''));
                        $fechaRegistroRaw = trim((string) ($evento['fecha_registro'] ?? ''));
                        $fechaRegistroFmt = trim((string) ($evento['fecha_registro_formateada'] ?? ''));
                        $usuarioLabel = trim((string) ($evento['usuario_label'] ?? ''));
                        $idOperacionEvento = (int) ($evento['id_operacion'] ?? 0);
                        $trazabilidadOperacion = $idOperacionEvento > 0 && isset($trazabilidadOperacionMap[$idOperacionEvento])
                            ? $trazabilidadOperacionMap[$idOperacionEvento]
                            : null;
                        $tipoOperacionTrazabilidad = strtoupper(trim((string) ($trazabilidadOperacion['tipo_operacion'] ?? '')));
                        $participantesOperacion = is_array($trazabilidadOperacion['participantes'] ?? null)
                            ? $trazabilidadOperacion['participantes']
                            : [];
                        $mostrarCadenaOperacion = $participantesOperacion !== []
                            && in_array($tipoOperacionTrazabilidad, ['SUBDIVISION', 'FUSION'], true);
                        $fechaSolo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaRaw) === 1;
                        ?>
                        <article class="ct-historial-evento">
                            <div class="ct-historial-evento-meta">
                                <?php if (strtolower($tipo) !== 'operacion'): ?>
                                    <?php if ($fechaSolo): ?>
                                        <span
                                            class="ct-historial-fecha"
                                            data-ct-local-date="<?php echo ctEscape($fechaRaw); ?>"
                                        ><?php echo ctEscape($fecha !== '' ? $fecha : '-'); ?></span>
                                    <?php else: ?>
                                        <span
                                            class="ct-historial-fecha"
                                            data-ct-local-datetime="<?php echo ctEscape($fechaRaw); ?>"
                                        ><?php echo ctEscape($fecha !== '' ? $fecha : '-'); ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <div class="ct-historial-titulo"><?php echo ctEscape($titulo !== '' ? $titulo : 'Evento'); ?></div>
                            <?php if ($detalle !== ''): ?>
                                <div class="ct-historial-detalle"><?php echo ctEscape($detalle); ?></div>
                            <?php endif; ?>
                            <?php if ($lineas !== []): ?>
                                <ul class="ct-historial-lineas">
                                    <?php foreach ($lineas as $linea): ?>
                                        <?php $lineaTexto = trim((string) $linea); ?>
                                        <?php if ($lineaTexto === '') continue; ?>
                                        <li><?php echo ctEscape($lineaTexto); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <?php if ($mostrarCadenaOperacion): ?>
                                <?php
                                $participantesOrigen = [];
                                $participantesResultado = [];
                                $origenEnFilas = $tipoOperacionTrazabilidad === 'FUSION';
                                foreach ($participantesOperacion as $participanteOp) {
                                    $rolOp = strtoupper(trim((string) ($participanteOp['rol_en_operacion'] ?? '')));
                                    if ($rolOp === 'ORIGEN') {
                                        $participantesOrigen[] = $participanteOp;
                                    } elseif ($rolOp === 'RESULTADO') {
                                        $participantesResultado[] = $participanteOp;
                                    }
                                }
                                ?>
                                <div class="row g-2">
                                    <div class="col-12 col-lg-6">
                                        <div class="border rounded p-2 h-100">
                                            <div class="small fw-semibold text-muted mb-2">Origen</div>
                                            <?php if ($participantesOrigen === []): ?>
                                                <div class="small text-muted">Sin terrenos origen.</div>
                                            <?php else: ?>
                                                <div class="<?php echo $origenEnFilas ? 'd-grid gap-2' : 'd-flex flex-wrap gap-2'; ?>">
                                                    <?php foreach ($participantesOrigen as $participante): ?>
                                                        <?php
                                                        $idParticipante = (int) ($participante['id_terreno'] ?? 0);
                                                        if ($idParticipante <= 0) {
                                                            continue;
                                                        }
                                                        $rolAsignadoP = trim((string) ($participante['rol_asignado'] ?? ''));
                                                        $superficieP = (float) ($participante['superficie_m2'] ?? 0);
                                                        $labelParticipante = $rolAsignadoP !== '' ? $rolAsignadoP : 'Terreno sin rol';
                                                        if ($superficieP > 0) {
                                                            $labelParticipante .= ' - ' . ctTerrenosFormatSuperficie($superficieP) . ' m²';
                                                        }
                                                        $esActual = !empty($participante['es_actual']);
                                                        $hrefParticipante = $buildFichaTerrenoHref($idParticipante, (string) ($volverLimpio ?? ''));
                                                        $origenBtnClass = $origenEnFilas ? 'btn btn-sm text-start w-100 ' : 'btn btn-sm ';
                                                        $origenBtnClass .= $esActual ? 'btn-primary' : 'btn-outline-secondary';
                                                        ?>
                                                        <a class="<?php echo ctEscape($origenBtnClass); ?>" href="<?php echo ctEscape($hrefParticipante); ?>">
                                                            <?php echo ctEscape($labelParticipante); ?>
                                                            <?php if ($esActual): ?>
                                                                <span class="badge bg-light text-dark border ms-1">Actual</span>
                                                            <?php endif; ?>
                                                        </a>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-12 col-lg-6">
                                        <div class="border rounded p-2 h-100">
                                            <div class="small fw-semibold text-muted mb-2">Resultado</div>
                                            <?php if ($participantesResultado === []): ?>
                                                <div class="small text-muted">Sin terrenos resultado.</div>
                                            <?php else: ?>
                                                <div class="d-grid gap-2">
                                                    <?php foreach ($participantesResultado as $participante): ?>
                                                        <?php
                                                        $idParticipante = (int) ($participante['id_terreno'] ?? 0);
                                                        if ($idParticipante <= 0) {
                                                            continue;
                                                        }
                                                        $rolAsignadoP = trim((string) ($participante['rol_asignado'] ?? ''));
                                                        $superficieP = (float) ($participante['superficie_m2'] ?? 0);
                                                        $labelParticipante = $rolAsignadoP !== '' ? $rolAsignadoP : 'Terreno sin rol';
                                                        if ($superficieP > 0) {
                                                            $labelParticipante .= ' - ' . ctTerrenosFormatSuperficie($superficieP) . ' m²';
                                                        }
                                                        $esActual = !empty($participante['es_actual']);
                                                        $hrefParticipante = $buildFichaTerrenoHref($idParticipante, (string) ($volverLimpio ?? ''));
                                                        ?>
                                                        <a class="btn btn-sm text-start w-100 <?php echo $esActual ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="<?php echo ctEscape($hrefParticipante); ?>">
                                                            <?php echo ctEscape($labelParticipante); ?>
                                                            <?php if ($esActual): ?>
                                                                <span class="badge bg-light text-dark border ms-1">Actual</span>
                                                            <?php endif; ?>
                                                        </a>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if (strtolower($tipo) === 'operacion' && ($usuarioLabel !== '' || $fechaOperacionFmt !== '' || $fechaRegistroFmt !== '')): ?>
                                <div class="d-flex justify-content-end mt-2">
                                    <div class="small text-muted text-end">
                                        <?php if ($usuarioLabel !== ''): ?>
                                            <div><?php echo ctEscape($usuarioLabel); ?></div>
                                        <?php endif; ?>
                                        <?php if ($fechaRegistroFmt !== ''): ?>
                                            <div>
                                                <span data-ct-local-datetime="<?php echo ctEscape($fechaRegistroRaw); ?>">
                                                    <?php echo ctEscape($fechaRegistroFmt); ?>
                                                </span>
                                            </div>
                                        <?php elseif ($fechaOperacionFmt !== ''): ?>
                                            <div>
                                                <span data-ct-local-date="<?php echo ctEscape($fechaOperacionRaw); ?>">
                                                    <?php echo ctEscape($fechaOperacionFmt); ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="border rounded bg-white p-3 mt-3">
            <h2 class="h6 mb-3">Historial del terreno - lista</h2>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 ct-crud-table">
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
                    <?php if ($historialLista === []): ?>
                        <tr><td colspan="6" class="text-muted text-center py-4">Sin operaciones para este terreno.</td></tr>
                    <?php else: ?>
                        <?php foreach ($historialLista as $rowLista): ?>
                            <?php
                            $tipoOperacionLista = trim((string) ($rowLista['tipo_operacion'] ?? ''));
                            $origenesLista = $fichaHistorialListaLotes($rowLista, 'origen');
                            $resultadosLista = $fichaHistorialListaLotes($rowLista, 'resultado');
                            ?>
                            <tr>
                                <td>
                                    <div><?php echo ctEscape(ctTerrenosFormatOperacionLabel($tipoOperacionLista)); ?></div>
                                    <div class="small text-muted"><?php echo ctEscape(ctTerrenosFormatDate((string) ($rowLista['fecha_evento'] ?? ''))); ?></div>
                                </td>
                                <td class="small"><?php echo ctEscape($fichaHistorialListaDoc($rowLista)); ?></td>
                                <td class="small">
                                    <?php if ($origenesLista === []): ?>
                                        <span class="text-muted">-</span>
                                    <?php else: ?>
                                        <?php foreach ($origenesLista as $loteOrigenLista): ?>
                                            <div><?php echo ctEscape($loteOrigenLista); ?></div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="small">
                                    <?php if ($resultadosLista === []): ?>
                                        <span class="text-muted">-</span>
                                    <?php else: ?>
                                        <?php foreach ($resultadosLista as $loteResultadoLista): ?>
                                            <div><?php echo ctEscape($loteResultadoLista); ?></div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-end text-nowrap"><?php echo ctEscape($fichaHistorialListaValor($rowLista)); ?></td>
                                <td class="small text-end text-nowrap"><?php echo ctEscape($fichaHistorialListaSuperficie($rowLista)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</section>
