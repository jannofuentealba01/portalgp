<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

function msp2GuardarArriendoReglasRedirect(int $idContratoArriendo): never
{
    $query = $idContratoArriendo > 0 ? ('?id_contrato_arriendo=' . $idContratoArriendo) : '';
    msp2Redirect('contratos/arriendo_reglas.php' . $query);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('contratos/index.php');
}

$idContratoArriendo = filter_input(INPUT_POST, 'id_contrato_arriendo', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($idContratoArriendo === false || $idContratoArriendo === null) {
    msp2SetFlash('warning', 'Debes indicar un contrato válido.');
    msp2Redirect('contratos/index.php');
}

$rowsPayload = $_POST['rows'] ?? null;
if (!is_array($rowsPayload) || $rowsPayload === []) {
    msp2SetFlash('warning', 'No se recibieron locales para actualizar.');
    msp2GuardarArriendoReglasRedirect((int) $idContratoArriendo);
}

try {
    $requiredTables = [
        'msp_contratos_arriendo',
        'msp_contrato_locales',
        'msp_locales',
        'msp_contrato_local_arriendo_regla',
        'msp_tipo_modalidad_arriendo',
        'msp_descuento_arriendo',
        'msp_descuento_arriendo_contrato_local',
    ];
    $missingTables = [];
    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            $missingTables[] = $tableName;
        }
    }
    if ($missingTables !== []) {
        throw new RuntimeException('Faltan tablas para guardar cobro por local: `' . implode('`, `', $missingTables) . '`.');
    }

    $contratoExisteStmt = $conn->prepare('SELECT TOP (1) 1 FROM dbo.msp_contratos_arriendo WHERE id_contrato_arriendo = :id_contrato_arriendo');
    $contratoExisteStmt->bindValue(':id_contrato_arriendo', (int) $idContratoArriendo, PDO::PARAM_INT);
    $contratoExisteStmt->execute();
    if ($contratoExisteStmt->fetchColumn() === false) {
        throw new RuntimeException('No existe el contrato indicado.');
    }

    $modalidadesStmt = $conn->query(
        "SELECT id_modalidad_arriendo, codigo_modalidad
         FROM dbo.msp_tipo_modalidad_arriendo
         WHERE activo = 1"
    );
    $modalidadIdByCode = [];
    while (($rowModalidad = $modalidadesStmt->fetch()) !== false) {
        $codigo = strtoupper(trim((string) ($rowModalidad['codigo_modalidad'] ?? '')));
        $idModalidad = (int) ($rowModalidad['id_modalidad_arriendo'] ?? 0);
        if ($codigo !== '' && $idModalidad > 0) {
            $modalidadIdByCode[$codigo] = $idModalidad;
        }
    }
    foreach (['UF_ESTATICO', 'CLP_FIJO'] as $codigoReq) {
        if (!isset($modalidadIdByCode[$codigoReq])) {
            throw new RuntimeException('Catálogo de modalidad incompleto. Falta `' . $codigoReq . '`.');
        }
    }

    $contratoLocalInfoStmt = $conn->prepare(
        "SELECT
            cl.id_contrato_local,
            cl.fecha_inicio,
            cl.fecha_termino,
            UPPER(LTRIM(RTRIM(ISNULL(l.cdo_local, N'')))) AS cdo_local_key
         FROM dbo.msp_contrato_locales cl
         INNER JOIN dbo.msp_locales l
            ON l.id_local = cl.id_local
         WHERE cl.id_contrato_arriendo = :id_contrato_arriendo
           AND cl.estado_relacion IN (1,2)"
    );
    $contratoLocalInfoStmt->bindValue(':id_contrato_arriendo', (int) $idContratoArriendo, PDO::PARAM_INT);
    $contratoLocalInfoStmt->execute();
    $contratoLocalInfoById = [];
    while (($rowCl = $contratoLocalInfoStmt->fetch()) !== false) {
        $idContratoLocal = (int) ($rowCl['id_contrato_local'] ?? 0);
        if ($idContratoLocal <= 0) {
            continue;
        }
        $contratoLocalInfoById[$idContratoLocal] = [
            'fecha_inicio' => (string) ($rowCl['fecha_inicio'] ?? ''),
            'fecha_termino' => isset($rowCl['fecha_termino']) ? (string) $rowCl['fecha_termino'] : null,
            'cdo_local_key' => strtoupper(trim((string) ($rowCl['cdo_local_key'] ?? ''))),
        ];
    }
    if ($contratoLocalInfoById === []) {
        throw new RuntimeException('El contrato no tiene locales activos para actualizar.');
    }

    $findReglaStmt = $conn->prepare(
        "SELECT TOP (1)
            id_regla_arriendo,
            fecha_inicio,
            fecha_termino
         FROM dbo.msp_contrato_local_arriendo_regla
         WHERE id_contrato_local = :id_contrato_local
           AND es_default = 1
           AND estado_regla = 1
         ORDER BY prioridad DESC, id_regla_arriendo DESC"
    );

    $updateReglaStmt = $conn->prepare(
        "UPDATE dbo.msp_contrato_local_arriendo_regla
         SET
            fecha_inicio = :fecha_inicio,
            fecha_termino = :fecha_termino,
            id_modalidad_arriendo = :id_modalidad_arriendo,
            valor_base_uf = :valor_base_uf,
            valor_base_clp = :valor_base_clp,
            id_tipo_descuento_arriendo = NULL,
            descuento_mensual_clp = 0,
            codigo_grupo_modalidad = :codigo_grupo_modalidad,
            prioridad = 100,
            observaciones = :observaciones,
            fecha_actualizacion = SYSDATETIME()
         WHERE id_regla_arriendo = :id_regla_arriendo"
    );

    $insertReglaStmt = $conn->prepare(
        "INSERT INTO dbo.msp_contrato_local_arriendo_regla (
            id_contrato_local,
            fecha_inicio,
            fecha_termino,
            id_modalidad_arriendo,
            valor_base_uf,
            valor_base_clp,
            id_tipo_descuento_arriendo,
            descuento_mensual_clp,
            codigo_grupo_modalidad,
            prioridad,
            estado_regla,
            es_default,
            observaciones
         ) VALUES (
            :id_contrato_local,
            :fecha_inicio,
            :fecha_termino,
            :id_modalidad_arriendo,
            :valor_base_uf,
            :valor_base_clp,
            NULL,
            0,
            :codigo_grupo_modalidad,
            100,
            1,
            1,
            :observaciones
         )"
    );

    $descuentoMetaStmt = $conn->prepare(
        "SELECT TOP (1)
            id_descuento_arriendo,
            CONVERT(CHAR(10), periodo_desde, 126) AS periodo_desde,
            CONVERT(CHAR(10), periodo_hasta, 126) AS periodo_hasta
         FROM dbo.msp_descuento_arriendo
         WHERE id_descuento_arriendo = :id_descuento_arriendo
           AND estado_descuento = 1"
    );

    $descuentosActivosStmt = $conn->prepare(
        "SELECT id_descuento_arriendo
         FROM dbo.msp_descuento_arriendo_contrato_local
         WHERE id_contrato_local = :id_contrato_local
           AND estado_asignacion = 1"
    );

    $desactivarDescuentoStmt = $conn->prepare(
        "UPDATE dbo.msp_descuento_arriendo_contrato_local
         SET
            estado_asignacion = 2,
            fecha_desasignacion = SYSDATETIME(),
            observaciones = COALESCE(NULLIF(observaciones, N''), N'Desasignación automática por edición de cobro por local.')
         WHERE id_contrato_local = :id_contrato_local
           AND id_descuento_arriendo = :id_descuento_arriendo
           AND estado_asignacion = 1"
    );

    $insertAsignacionDescuentoStmt = $conn->prepare(
        "INSERT INTO dbo.msp_descuento_arriendo_contrato_local (
            id_descuento_arriendo,
            id_contrato_local,
            estado_asignacion,
            observaciones
         ) VALUES (
            :id_descuento_arriendo,
            :id_contrato_local,
            1,
            :observaciones
         )"
    );

    $conn->beginTransaction();

    $actualizados = 0;
    $insertados = 0;
    $omitidos = 0;
    $descuentosAsignados = 0;
    $descuentosQuitados = 0;

    foreach ($rowsPayload as $rowKey => $rowData) {
        if (!is_array($rowData)) {
            continue;
        }

        $idContratoLocal = is_numeric((string) $rowKey) ? (int) $rowKey : 0;
        if ($idContratoLocal <= 0 && isset($rowData['id_contrato_local']) && is_numeric((string) $rowData['id_contrato_local'])) {
            $idContratoLocal = (int) $rowData['id_contrato_local'];
        }
        if ($idContratoLocal <= 0) {
            continue;
        }

        if (!isset($contratoLocalInfoById[$idContratoLocal])) {
            throw new RuntimeException('El contrato-local #' . $idContratoLocal . ' no pertenece al contrato seleccionado.');
        }

        $modalidadCode = strtoupper(trim((string) ($rowData['modalidad'] ?? '')));
        if (!in_array($modalidadCode, ['UF_ESTATICO', 'CLP_FIJO'], true)
            || !isset($modalidadIdByCode[$modalidadCode])) {
            throw new RuntimeException('Solo se permite arriendo mensual fijo en UF o pesos para contrato-local #' . $idContratoLocal . '.');
        }
        $idModalidad = (int) $modalidadIdByCode[$modalidadCode];

        [$okUf, $valorUf] = msp2NormalizeDecimalInput(trim((string) ($rowData['valor_base_uf'] ?? '')), 6);
        if (!$okUf) {
            throw new RuntimeException('Valor UF inválido para contrato-local #' . $idContratoLocal . '.');
        }

        [$okClp, $valorClp] = msp2NormalizeDecimalInput(trim((string) ($rowData['valor_base_clp'] ?? '')), 2);
        if (!$okClp) {
            throw new RuntimeException('Valor CLP inválido para contrato-local #' . $idContratoLocal . '.');
        }

        $valorBaseUf = null;
        $valorBaseClp = null;
        if ($modalidadCode === 'UF_ESTATICO') {
            if ($valorUf === null) {
                throw new RuntimeException('UF_ESTATICO requiere valor base UF en contrato-local #' . $idContratoLocal . '.');
            }
            $valorBaseUf = $valorUf;
        } elseif ($modalidadCode === 'CLP_FIJO') {
            if ($valorClp === null) {
                throw new RuntimeException('CLP_FIJO requiere valor base CLP en contrato-local #' . $idContratoLocal . '.');
            }
            $valorBaseClp = $valorClp;
        }

        $codigoGrupoModalidad = $modalidadCode === 'CLP_FIJO' ? 'CLP_FIJO_CONTRATO' : null;

        $fechaInicioRegla = (string) ($contratoLocalInfoById[$idContratoLocal]['fecha_inicio'] ?? '');
        $fechaTerminoRegla = $contratoLocalInfoById[$idContratoLocal]['fecha_termino'] ?? null;

        $findReglaStmt->bindValue(':id_contrato_local', $idContratoLocal, PDO::PARAM_INT);
        $findReglaStmt->execute();
        $reglaRow = $findReglaStmt->fetch();

        if ($reglaRow !== false) {
            $idRegla = (int) ($reglaRow['id_regla_arriendo'] ?? 0);
            if ($idRegla <= 0) {
                $omitidos++;
            } else {
                $fechaInicioExistente = trim((string) ($reglaRow['fecha_inicio'] ?? ''));
                $fechaTerminoExistente = isset($reglaRow['fecha_termino']) ? trim((string) $reglaRow['fecha_termino']) : null;
                if ($fechaInicioExistente !== '') {
                    $fechaInicioRegla = $fechaInicioExistente;
                }
                $fechaTerminoRegla = $fechaTerminoExistente !== '' ? $fechaTerminoExistente : null;

                $updateReglaStmt->bindValue(':id_regla_arriendo', $idRegla, PDO::PARAM_INT);
                $updateReglaStmt->bindValue(':fecha_inicio', $fechaInicioRegla, PDO::PARAM_STR);
                $updateReglaStmt->bindValue(':fecha_termino', $fechaTerminoRegla, $fechaTerminoRegla !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $updateReglaStmt->bindValue(':id_modalidad_arriendo', $idModalidad, PDO::PARAM_INT);
                $updateReglaStmt->bindValue(':valor_base_uf', $valorBaseUf, $valorBaseUf !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $updateReglaStmt->bindValue(':valor_base_clp', $valorBaseClp, $valorBaseClp !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $updateReglaStmt->bindValue(':codigo_grupo_modalidad', $codigoGrupoModalidad, $codigoGrupoModalidad !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $updateReglaStmt->bindValue(':observaciones', 'Edición manual desde contratos/arriendo_reglas.php', PDO::PARAM_STR);
                $updateReglaStmt->execute();
                $actualizados++;
            }
        } else {
            $insertReglaStmt->bindValue(':id_contrato_local', $idContratoLocal, PDO::PARAM_INT);
            $insertReglaStmt->bindValue(':fecha_inicio', $fechaInicioRegla, PDO::PARAM_STR);
            $insertReglaStmt->bindValue(':fecha_termino', $fechaTerminoRegla, $fechaTerminoRegla !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insertReglaStmt->bindValue(':id_modalidad_arriendo', $idModalidad, PDO::PARAM_INT);
            $insertReglaStmt->bindValue(':valor_base_uf', $valorBaseUf, $valorBaseUf !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insertReglaStmt->bindValue(':valor_base_clp', $valorBaseClp, $valorBaseClp !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insertReglaStmt->bindValue(':codigo_grupo_modalidad', $codigoGrupoModalidad, $codigoGrupoModalidad !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insertReglaStmt->bindValue(':observaciones', 'Edición manual desde contratos/arriendo_reglas.php', PDO::PARAM_STR);
            $insertReglaStmt->execute();
            $insertados++;
        }

        $targetIds = [];
        if (isset($rowData['ids_descuento_arriendo']) && is_array($rowData['ids_descuento_arriendo'])) {
            foreach ($rowData['ids_descuento_arriendo'] as $idDescuentoRaw) {
                $idDescuentoParsed = filter_var($idDescuentoRaw, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]);
                if ($idDescuentoParsed !== false && $idDescuentoParsed !== null) {
                    $targetIds[(int) $idDescuentoParsed] = (int) $idDescuentoParsed;
                }
            }
        } else {
            $idDescuentoSingle = filter_var($rowData['id_descuento_arriendo'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($idDescuentoSingle !== false && $idDescuentoSingle !== null) {
                $targetIds[(int) $idDescuentoSingle] = (int) $idDescuentoSingle;
            }
        }
        $targetIds = array_values(array_map('intval', $targetIds));
        sort($targetIds, SORT_NUMERIC);

        $rangosDescuento = [];
        foreach ($targetIds as $idDescuentoTarget) {
            $descuentoMetaStmt->bindValue(':id_descuento_arriendo', $idDescuentoTarget, PDO::PARAM_INT);
            $descuentoMetaStmt->execute();
            $rowMeta = $descuentoMetaStmt->fetch();
            if ($rowMeta === false) {
                throw new RuntimeException('El descuento #' . $idDescuentoTarget . ' no existe o está inactivo (contrato-local #' . $idContratoLocal . ').');
            }

            $desde = substr((string) ($rowMeta['periodo_desde'] ?? ''), 0, 10);
            $hastaRaw = substr((string) ($rowMeta['periodo_hasta'] ?? ''), 0, 10);
            $hasta = $hastaRaw !== '' ? $hastaRaw : null;
            if ($desde === '') {
                throw new RuntimeException('El descuento #' . $idDescuentoTarget . ' no tiene período desde válido.');
            }
            $rangosDescuento[] = [
                'id' => $idDescuentoTarget,
                'desde' => $desde,
                'hasta' => $hasta,
            ];
        }

        $rangosCount = count($rangosDescuento);
        for ($i = 0; $i < $rangosCount; $i++) {
            $startA = strtotime((string) $rangosDescuento[$i]['desde']);
            $endA = $rangosDescuento[$i]['hasta'] !== null ? strtotime((string) $rangosDescuento[$i]['hasta']) : null;
            if ($startA === false || ($rangosDescuento[$i]['hasta'] !== null && $endA === false)) {
                throw new RuntimeException('Hay descuentos con vigencia inválida en contrato-local #' . $idContratoLocal . '.');
            }
            $endAValue = $endA !== null ? $endA : PHP_INT_MAX;
            for ($j = $i + 1; $j < $rangosCount; $j++) {
                $startB = strtotime((string) $rangosDescuento[$j]['desde']);
                $endB = $rangosDescuento[$j]['hasta'] !== null ? strtotime((string) $rangosDescuento[$j]['hasta']) : null;
                if ($startB === false || ($rangosDescuento[$j]['hasta'] !== null && $endB === false)) {
                    throw new RuntimeException('Hay descuentos con vigencia inválida en contrato-local #' . $idContratoLocal . '.');
                }
                $endBValue = $endB !== null ? $endB : PHP_INT_MAX;

                if ($startA <= $endBValue && $startB <= $endAValue) {
                    throw new RuntimeException(
                        'Los descuentos #' . (int) $rangosDescuento[$i]['id'] . ' y #' . (int) $rangosDescuento[$j]['id']
                        . ' se solapan en vigencia para contrato-local #' . $idContratoLocal . '.'
                    );
                }
            }
        }

        $descuentosActivosStmt->bindValue(':id_contrato_local', $idContratoLocal, PDO::PARAM_INT);
        $descuentosActivosStmt->execute();
        $activeIds = [];
        while (($activeId = $descuentosActivosStmt->fetchColumn()) !== false) {
            $activeIds[] = (int) $activeId;
        }
        $activeIds = array_values(array_unique(array_filter($activeIds, static fn (int $id): bool => $id > 0)));
        sort($activeIds);

        if ($activeIds !== $targetIds) {
            $idsToDeactivate = array_values(array_diff($activeIds, $targetIds));
            $idsToAdd = array_values(array_diff($targetIds, $activeIds));

            foreach ($idsToDeactivate as $idDescDeactivate) {
                $desactivarDescuentoStmt->bindValue(':id_contrato_local', $idContratoLocal, PDO::PARAM_INT);
                $desactivarDescuentoStmt->bindValue(':id_descuento_arriendo', (int) $idDescDeactivate, PDO::PARAM_INT);
                $desactivarDescuentoStmt->execute();
                $descuentosQuitados++;
            }

            foreach ($idsToAdd as $idDescAdd) {
                $insertAsignacionDescuentoStmt->bindValue(':id_descuento_arriendo', (int) $idDescAdd, PDO::PARAM_INT);
                $insertAsignacionDescuentoStmt->bindValue(':id_contrato_local', $idContratoLocal, PDO::PARAM_INT);
                $insertAsignacionDescuentoStmt->bindValue(':observaciones', 'Asignación desde contratos/arriendo_reglas.php', PDO::PARAM_STR);
                $insertAsignacionDescuentoStmt->execute();
                $descuentosAsignados++;
            }
        }
    }

    $conn->commit();

    $partes = [];
    if ($actualizados > 0) {
        $partes[] = 'reglas actualizadas: ' . $actualizados;
    }
    if ($insertados > 0) {
        $partes[] = 'reglas creadas: ' . $insertados;
    }
    if ($descuentosAsignados > 0) {
        $partes[] = 'descuentos asignados: ' . $descuentosAsignados;
    }
    if ($descuentosQuitados > 0) {
        $partes[] = 'descuentos desasignados: ' . $descuentosQuitados;
    }
    if ($omitidos > 0) {
        $partes[] = 'omitidos: ' . $omitidos;
    }
    if ($partes === []) {
        msp2SetFlash('warning', 'No se detectaron cambios para guardar.');
    } else {
        msp2SetFlash('success', 'Cobro por local guardado (' . implode(', ', $partes) . ').');
    }
} catch (Throwable $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    if ($exception instanceof RuntimeException) {
        msp2SetFlash('danger', $exception->getMessage());
    } else {
        msp2SetFlash('danger', 'No fue posible guardar cobro por local.');
    }
}

msp2GuardarArriendoReglasRedirect((int) $idContratoArriendo);
