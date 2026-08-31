<?php
declare(strict_types=1);

final class ImportacionLecturasService
{
    public static function prepararLecturasDirectas(
        PDO $conn,
        string $codigoServicio,
        int $idTipoServicio,
        string $periodoFacturacion,
        array $seedRows,
        array $defaults
    ): int {
        if ($idTipoServicio <= 0 || $periodoFacturacion === '') {
            throw new RuntimeException('Datos invalidos para preparar lectura directa.');
        }

        if ($seedRows === []) {
            throw new RuntimeException('No hay medidores activos para preparar lectura directa en ' . $codigoServicio . '.');
        }

        [$idCierre, $idProcesoCobro] = self::resolverProcesoCobro($conn, $codigoServicio, $idTipoServicio, $periodoFacturacion, true);

        $existingStmt = $conn->prepare(
            'SELECT id_medidor
             FROM dbo.msp_lecturas_medidores
             WHERE id_proceso_cobro = :id_proceso'
        );
        $existingStmt->bindValue(':id_proceso', $idProcesoCobro, PDO::PARAM_INT);
        $existingStmt->execute();

        $existingMedidores = [];
        while (($existingId = $existingStmt->fetchColumn()) !== false) {
            $existingMedidores[(int) $existingId] = true;
        }

        $insertStmt = $conn->prepare(
            'INSERT INTO dbo.msp_lecturas_medidores
                (id_proceso_cobro, id_medidor, id_origen_lectura, periodo_facturacion, fecha_desde_consumo, fecha_hasta_consumo, fecha_lectura, lectura_anterior, lectura_actual, consumo_informado, observaciones)
             VALUES
                (:id_proceso, :id_medidor, 1, :periodo, NULL, :fecha_hasta, :fecha_lectura, :lectura_anterior, :lectura_actual, NULL, :observaciones)'
        );

        $insertadas = 0;
        $conn->beginTransaction();
        try {
            foreach ($seedRows as $seedRow) {
                if (!is_array($seedRow)) {
                    continue;
                }

                $idMedidor = (int) ($seedRow['id_medidor'] ?? 0);
                if ($idMedidor <= 0 || isset($existingMedidores[$idMedidor])) {
                    continue;
                }

                $lecturaAnterior = $seedRow['lectura_anterior_real'] ?? null;
                if ($lecturaAnterior === null || $lecturaAnterior === '') {
                    $lecturaAnterior = $seedRow['valor_inicial'] ?? null;
                }
                if ($lecturaAnterior === null || $lecturaAnterior === '' || !is_numeric((string) $lecturaAnterior)) {
                    $lecturaAnterior = 0;
                }

                $lecturaAnteriorValue = (string) (int) round((float) $lecturaAnterior);

                $insertStmt->bindValue(':id_proceso', $idProcesoCobro, PDO::PARAM_INT);
                $insertStmt->bindValue(':id_medidor', $idMedidor, PDO::PARAM_INT);
                $insertStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
                $insertStmt->bindValue(':fecha_hasta', (string) ($defaults['fecha_hasta_consumo'] ?? ''), PDO::PARAM_STR);
                $insertStmt->bindValue(':fecha_lectura', (string) ($defaults['fecha_lectura'] ?? ''), PDO::PARAM_STR);
                $insertStmt->bindValue(':lectura_anterior', $lecturaAnteriorValue, PDO::PARAM_STR);
                $insertStmt->bindValue(':lectura_actual', $lecturaAnteriorValue, PDO::PARAM_STR);
                $insertStmt->bindValue(':observaciones', null, PDO::PARAM_NULL);
                $insertStmt->execute();
                $insertadas++;
            }

            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }

        return $insertadas;
    }

    public static function actualizarLecturasDirectas(
        PDO $conn,
        string $codigoServicio,
        int $idTipoServicio,
        string $periodoFacturacion,
        array $lecturasActuales
    ): int {
        if ($idTipoServicio <= 0 || $periodoFacturacion === '') {
            throw new RuntimeException('Datos invalidos para actualizar lecturas directas.');
        }

        [, $idProcesoCobro] = self::resolverProcesoCobro($conn, $codigoServicio, $idTipoServicio, $periodoFacturacion, false);

        $lecturasStmt = $conn->prepare(
            'SELECT id_lectura, lectura_anterior, lectura_actual
             FROM dbo.msp_lecturas_medidores
             WHERE id_proceso_cobro = :id_proceso'
        );
        $lecturasStmt->bindValue(':id_proceso', $idProcesoCobro, PDO::PARAM_INT);
        $lecturasStmt->execute();

        $lecturasProceso = [];
        while (($lecturaRow = $lecturasStmt->fetch()) !== false) {
            $lecturasProceso[(int) ($lecturaRow['id_lectura'] ?? 0)] = $lecturaRow;
        }

        if ($lecturasProceso === []) {
            throw new RuntimeException('No existen lecturas registradas para actualizar en ' . $codigoServicio . '.');
        }

        $errores = [];
        $updates = [];
        foreach ($lecturasActuales as $idLecturaRaw => $lecturaActualRaw) {
            if (!ctype_digit((string) $idLecturaRaw)) {
                continue;
            }

            $idLectura = (int) $idLecturaRaw;
            if (!isset($lecturasProceso[$idLectura])) {
                continue;
            }

            [$okActual, $lecturaActual] = self::integerInput((string) $lecturaActualRaw, true);
            if (!$okActual || $lecturaActual === null) {
                $errores[] = self::formatLecturaFieldError(
                    'Lectura #' . $idLectura,
                    'lectura_actual',
                    'invalida (debe ser entero)'
                );
                continue;
            }

            $lecturaAnterior = $lecturasProceso[$idLectura]['lectura_anterior'] ?? null;
            if ($lecturaAnterior !== null && is_numeric((string) $lecturaAnterior) && (float) $lecturaActual < (float) $lecturaAnterior) {
                $errores[] = self::formatLecturaFieldError(
                    'Lectura #' . $idLectura,
                    'lectura_actual',
                    'no puede ser menor a lectura_anterior'
                );
                continue;
            }

            $updates[$idLectura] = $lecturaActual;
        }

        if ($updates === []) {
            throw new RuntimeException('No hay lecturas válidas para actualizar.');
        }

        if ($errores !== []) {
            throw new RuntimeException(implode(' | ', array_slice($errores, 0, 6)));
        }

        $updateStmt = $conn->prepare(
            'UPDATE dbo.msp_lecturas_medidores
             SET lectura_actual = :lectura_actual,
                 fecha_actualizacion = SYSDATETIME()
             WHERE id_lectura = :id_lectura
               AND id_proceso_cobro = :id_proceso'
        );

        $conn->beginTransaction();
        try {
            foreach ($updates as $idLectura => $lecturaActual) {
                $updateStmt->bindValue(':lectura_actual', $lecturaActual, PDO::PARAM_STR);
                $updateStmt->bindValue(':id_lectura', $idLectura, PDO::PARAM_INT);
                $updateStmt->bindValue(':id_proceso', $idProcesoCobro, PDO::PARAM_INT);
                $updateStmt->execute();
            }
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }

        return count($updates);
    }

    public static function confirmarImportacion(
        PDO $conn,
        string $codigoServicio,
        int $idTipoServicio,
        string $periodoFacturacion,
        array $validRows,
        string $originalName,
        int $reemplazarLecturas,
        string $usuarioCarga
    ): array {
        self::assertImportacionContextoValido(
            $idTipoServicio,
            $periodoFacturacion,
            'La previsualizacion no es valida. Vuelve a cargar el Excel.'
        );
        if ($validRows === []) {
            throw new RuntimeException('La previsualizacion no es valida. Vuelve a cargar el Excel.');
        }

        [, $idProcesoCobro] = self::resolverProcesoCobro($conn, $codigoServicio, $idTipoServicio, $periodoFacturacion, false);

        self::assertReemplazoPermitido($conn, $idProcesoCobro, $codigoServicio, $reemplazarLecturas);

        $usuarioCargaNorm = msp2NormalizeText($usuarioCarga);
        if ($usuarioCargaNorm === '') {
            $usuarioCargaNorm = 'sistema';
        }

        $conn->beginTransaction();
        try {
            $lotStmt = $conn->prepare(
                'INSERT INTO dbo.msp_import_lotes
                    (periodo_facturacion, id_tipo_servicio, nombre_archivo, usuario_carga)
                 VALUES
                    (:periodo, :id_tipo, :archivo, :usuario)'
            );
            $lotStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
            $lotStmt->bindValue(':id_tipo', $idTipoServicio, PDO::PARAM_INT);
            $lotStmt->bindValue(':archivo', mb_substr($originalName, 0, 255, 'UTF-8'), PDO::PARAM_STR);
            $lotStmt->bindValue(':usuario', mb_substr($usuarioCargaNorm, 0, 100, 'UTF-8'), PDO::PARAM_STR);
            $lotStmt->execute();
            $idLote = (int) $conn->lastInsertId();
            if ($idLote <= 0) {
                throw new RuntimeException('No se pudo crear el lote de importacion.');
            }

            $insRowStmt = $conn->prepare(
                'INSERT INTO dbo.msp_import_lecturas
                    (id_lote, fila_origen, cod_local, codigo_medidor, lectura_anterior, lectura_actual, fecha_hasta_consumo, fecha_lectura, observaciones)
                 VALUES
                    (:id_lote, :fila, :local, :medidor, :ant, :act, :fhc, :fl, :obs)'
            );

            foreach ($validRows as $r) {
                if (!is_array($r)) {
                    continue;
                }

                $insRowStmt->bindValue(':id_lote', $idLote, PDO::PARAM_INT);
                $insRowStmt->bindValue(':fila', (int) ($r['fila_origen'] ?? 0), PDO::PARAM_INT);
                $insRowStmt->bindValue(':local', (string) ($r['cod_local'] ?? ''), PDO::PARAM_STR);
                $insRowStmt->bindValue(':medidor', (string) ($r['codigo_medidor'] ?? ''), PDO::PARAM_STR);
                $lecturaAnterior = $r['lectura_anterior'] ?? null;
                $insRowStmt->bindValue(':ant', $lecturaAnterior, $lecturaAnterior !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $insRowStmt->bindValue(':act', (string) ($r['lectura_actual'] ?? ''), PDO::PARAM_STR);
                $insRowStmt->bindValue(':fhc', (string) ($r['fecha_hasta_consumo'] ?? ''), PDO::PARAM_STR);
                $insRowStmt->bindValue(':fl', (string) ($r['fecha_lectura'] ?? ''), PDO::PARAM_STR);
                $obs = msp2NormalizeText((string) ($r['observaciones'] ?? ''));
                $insRowStmt->bindValue(':obs', $obs !== '' ? $obs : null, $obs !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $insRowStmt->execute();
            }

            $aplicarStmt = $conn->prepare(
                'DECLARE @out INT;
                 EXEC dbo.msp_importar_lecturas_lote
                    @id_lote = :id_lote,
                    @reemplazar = :rep,
                    @lecturas_insertadas = @out OUTPUT;
                 SELECT @out AS lecturas_insertadas;'
            );
            $aplicarStmt->bindValue(':id_lote', $idLote, PDO::PARAM_INT);
            $aplicarStmt->bindValue(':rep', $reemplazarLecturas, PDO::PARAM_INT);
            $aplicarStmt->execute();
            $resLecturas = self::fetchFirstRowsetRow($aplicarStmt);
            $lecturasInsertadas = (int) ($resLecturas['lecturas_insertadas'] ?? 0);

            $conn->commit();
            return [
                'id_lote' => $idLote,
                'lecturas_insertadas' => $lecturasInsertadas,
            ];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    public static function previsualizarImportacion(
        PDO $conn,
        string $codigoServicio,
        int $idTipoServicio,
        string $periodoYm,
        string $periodoFacturacion,
        string $uploadTmpPath,
        string $originalName,
        int $reemplazarLecturas
    ): array {
        self::assertImportacionContextoValido(
            $idTipoServicio,
            $periodoFacturacion,
            'No se encontro el tipo de servicio para importar.'
        );

        [, $idProcesoCobro, $fechaEmision] = self::resolverProcesoCobro($conn, $codigoServicio, $idTipoServicio, $periodoFacturacion, true);

        self::assertReemplazoPermitido($conn, $idProcesoCobro, $codigoServicio, $reemplazarLecturas);

        $medStmt = $conn->prepare(
            'SELECT
                UPPER(LTRIM(RTRIM(loc.cdo_local))) AS cod_local,
                UPPER(LTRIM(RTRIM(m.codigo_medidor))) AS codigo_medidor,
                LTRIM(RTRIM(m.alias_medidor)) AS alias_medidor
             FROM dbo.msp_medidores m
             INNER JOIN dbo.msp_locales loc ON loc.id_local = m.id_local
             WHERE m.id_tipo_servicio = :id_tipo
               AND m.estado_medidor = 1
               AND m.fecha_retiro IS NULL'
        );
        $medStmt->bindValue(':id_tipo', $idTipoServicio, PDO::PARAM_INT);
        $medStmt->execute();

        $medidoresByCode = [];
        $medidoresByAlias = [];
        $medidoresByLocal = [];
        while (($med = $medStmt->fetch()) !== false) {
            $codLocal = omNormalizeCode((string) ($med['cod_local'] ?? ''));
            $codigoMedidor = omNormalizeCode((string) ($med['codigo_medidor'] ?? ''));
            $alias = msp2NormalizeLookupKey((string) ($med['alias_medidor'] ?? ''));

            if ($codLocal === '' || $codigoMedidor === '') {
                continue;
            }

            $payload = [
                'cod_local' => $codLocal,
                'codigo_medidor' => $codigoMedidor,
            ];
            $medidoresByCode[$codigoMedidor] = $payload;
            $medidoresByLocal[$codLocal] ??= [];
            $medidoresByLocal[$codLocal][$codigoMedidor] = $payload;

            if ($alias !== '') {
                $medidoresByAlias[$codLocal . '|' . $alias] = $payload;
            }
        }

        if ($medidoresByCode === []) {
            throw new RuntimeException('No hay medidores activos para el servicio ' . $codigoServicio . '.');
        }

        $fechaMedicionProceso = substr($fechaEmision, 0, 10);
        $readingDefaults = omResolveServiceReadingDefaults(
            $codigoServicio,
            $periodoFacturacion,
            $fechaMedicionProceso !== '' ? $fechaMedicionProceso : null
        );
        $fechaHastaDefault = (string) ($readingDefaults['fecha_hasta_consumo'] ?? '');
        $fechaLecturaDefault = (string) ($readingDefaults['fecha_lectura'] ?? '');

        $rows = msp2ReadSpreadsheetRows($uploadTmpPath, false, true, false, true);

        if ($rows === [] || !isset($rows[0]) || !is_array($rows[0])) {
            throw new RuntimeException('La planilla no contiene datos.');
        }

        $headers = [];
        foreach ($rows[0] as $index => $headerValue) {
            $normalized = msp2NormalizeLookupKey(omCellToString($headerValue));
            if ($normalized !== '') {
                $headers[$normalized] = $index;
            }
        }

        $colCodLocal = omFindColumn($headers, ['cod_local', 'cdo_local', 'codigo_local', 'local']);
        $colCodigoMedidor = omFindColumn($headers, ['codigo_medidor', 'cod_medidor', 'medidor']);
        $colAliasMedidor = omFindColumn($headers, ['alias_medidor', 'alias', 'nombre_medidor']);
        $colLecturaActual = omFindColumn($headers, ['lectura_actual', 'valor_actual', 'valor', 'medicion_actual']);
        $colLecturaAnterior = omFindColumn($headers, ['lectura_anterior', 'valor_anterior', 'medicion_anterior']);
        if ($colLecturaActual === null) {
            $colLecturaActual = omFindColumnPrefix($headers, ['lectura_actual', 'valor_actual', 'medicion_actual']);
        }
        if ($colLecturaAnterior === null) {
            $colLecturaAnterior = omFindColumnPrefix($headers, ['lectura_anterior', 'valor_anterior', 'medicion_anterior']);
        }
        $colFechaHasta = omFindColumn($headers, ['fecha_hasta_consumo', 'fecha_hasta', 'fecha_hasta_periodo']);
        $colFechaLectura = omFindColumn($headers, ['fecha_lectura', 'fecha_medicion']);
        $colObs = omFindColumn($headers, ['observaciones', 'obs']);

        if ($colCodLocal === null || $colLecturaActual === null) {
            throw new RuntimeException('La plantilla debe incluir al menos `cod_local` y `lectura_actual`.');
        }

        if ($colCodigoMedidor === null && $colAliasMedidor === null) {
            throw new RuntimeException('La plantilla debe incluir `codigo_medidor` o `alias_medidor`.');
        }

        $errores = [];
        $validRows = [];
        $seenCodigo = [];

        for ($i = 1, $totalRows = count($rows); $i < $totalRows; $i++) {
            $row = $rows[$i];
            if (!is_array($row)) {
                continue;
            }

            $linea = $i + 1;
            $codLocalRaw = omCellToString($row[$colCodLocal] ?? null);
            $codigoMedidorRaw = $colCodigoMedidor !== null ? omCellToString($row[$colCodigoMedidor] ?? null) : '';
            $aliasMedidorRaw = $colAliasMedidor !== null ? omCellToString($row[$colAliasMedidor] ?? null) : '';
            $lecturaActualRaw = omCellToString($row[$colLecturaActual] ?? null);
            $lecturaAnteriorRaw = $colLecturaAnterior !== null ? omCellToString($row[$colLecturaAnterior] ?? null) : '';
            $fechaHastaRaw = $colFechaHasta !== null ? ($row[$colFechaHasta] ?? null) : null;
            $fechaLecturaRaw = $colFechaLectura !== null ? ($row[$colFechaLectura] ?? null) : null;
            $obsRaw = $colObs !== null ? omCellToString($row[$colObs] ?? null) : '';

            if ($codLocalRaw === '' && $codigoMedidorRaw === '' && $aliasMedidorRaw === '' && $lecturaActualRaw === '' && $lecturaAnteriorRaw === '' && $obsRaw === '') {
                continue;
            }

            $codLocal = omNormalizeCode($codLocalRaw);
            if ($codLocal === '') {
                $errores[] = 'Fila ' . $linea . ': cod_local vacio.';
                continue;
            }

            $medidor = null;
            $codigoMedidor = omNormalizeCode($codigoMedidorRaw);
            $resueltoPorCodigoExacto = false;
            if ($codigoMedidor !== '' && isset($medidoresByCode[$codigoMedidor])) {
                $medidor = $medidoresByCode[$codigoMedidor];
                $resueltoPorCodigoExacto = true;
            }

            if ($medidor === null) {
                $aliasKey = msp2NormalizeLookupKey($aliasMedidorRaw);
                if ($aliasKey !== '' && isset($medidoresByAlias[$codLocal . '|' . $aliasKey])) {
                    $medidor = $medidoresByAlias[$codLocal . '|' . $aliasKey];
                }
            }

            // Las filas agregadas bajo la tabla de la plantilla también son válidas.
            // Si el código escrito no coincide exactamente, podemos resolver el
            // medidor por local siempre que exista uno solo para este servicio.
            if ($medidor === null && isset($medidoresByLocal[$codLocal]) && count($medidoresByLocal[$codLocal]) === 1) {
                $medidor = reset($medidoresByLocal[$codLocal]);
            }

            if ($medidor === null) {
                $cantidadMedidoresLocal = isset($medidoresByLocal[$codLocal])
                    ? count($medidoresByLocal[$codLocal])
                    : 0;
                if ($cantidadMedidoresLocal > 1) {
                    $errores[] = 'Fila ' . $linea . ': el local `' . $codLocal
                        . '` tiene varios medidores de ' . $codigoServicio
                        . '; indica un codigo_medidor registrado.';
                } else {
                    $codigoDetalle = $codigoMedidor !== '' ? ' (`' . $codigoMedidor . '`)' : '';
                    $errores[] = 'Fila ' . $linea . ': el local `' . $codLocal
                        . '` no tiene un medidor activo de ' . $codigoServicio
                        . ' reconocido' . $codigoDetalle
                        . '. Registra o habilita el medidor en Gestion de locales antes de importar.';
                }
                continue;
            }

            if ($resueltoPorCodigoExacto && $medidor['cod_local'] !== $codLocal) {
                // El código de medidor es el identificador inequívoco. Esto permite
                // importar etiquetas agrupadas del control histórico (por ejemplo,
                // "B-11 B-12") usando el local canónico asociado al medidor.
                $codLocal = (string) $medidor['cod_local'];
            } elseif ($medidor['cod_local'] !== $codLocal) {
                $errores[] = 'Fila ' . $linea . ': el medidor no corresponde al local `' . $codLocal . '`.';
                continue;
            }

            [$okActual, $lecturaActual] = self::integerInput($lecturaActualRaw, true);
            if (!$okActual || $lecturaActual === null) {
                $errores[] = self::formatLecturaFieldError(
                    'Fila ' . $linea,
                    'lectura_actual',
                    'invalida (debe ser entero)'
                );
                continue;
            }

            [$okAnterior, $lecturaAnterior] = self::integerInput($lecturaAnteriorRaw, false);
            if (!$okAnterior) {
                $errores[] = self::formatLecturaFieldError(
                    'Fila ' . $linea,
                    'lectura_anterior',
                    'invalida (debe ser entero)'
                );
                continue;
            }

            if ($lecturaAnterior !== null && (float) $lecturaAnterior > (float) $lecturaActual) {
                $errores[] = self::formatLecturaFieldError(
                    'Fila ' . $linea,
                    'lectura_actual',
                    'no puede ser menor a lectura_anterior'
                );
                continue;
            }

            if (in_array($codigoServicio, ['AGUA', 'LUZ', 'GAS'], true)) {
                $fechaHasta = $fechaHastaDefault;
                $fechaLectura = $fechaLecturaDefault;
            } else {
                [$okFechaHasta, $fechaHasta] = omParseSpreadsheetDate($fechaHastaRaw, $fechaHastaDefault);
                [$okFechaLectura, $fechaLectura] = omParseSpreadsheetDate($fechaLecturaRaw, $fechaLecturaDefault);
                if (!$okFechaHasta || !$okFechaLectura || $fechaHasta === null || $fechaLectura === null) {
                    $errores[] = 'Fila ' . $linea . ': fecha_hasta_consumo o fecha_lectura invalida.';
                    continue;
                }
            }
            if ($fechaLectura < $fechaHasta) {
                $errores[] = 'Fila ' . $linea . ': fecha_lectura debe ser mayor o igual a fecha_hasta_consumo.';
                continue;
            }

            $finalCodigo = (string) $medidor['codigo_medidor'];
            if (isset($seenCodigo[$finalCodigo])) {
                $errores[] = self::formatLecturaError(
                    'Fila ' . $linea,
                    'medidor `' . $finalCodigo . '` repetido en la planilla'
                );
                continue;
            }
            $seenCodigo[$finalCodigo] = true;

            $validRows[] = [
                'fila_origen' => $linea,
                'cod_local' => $codLocal,
                'codigo_medidor' => $finalCodigo,
                'lectura_anterior' => $lecturaAnterior,
                'lectura_actual' => $lecturaActual,
                'fecha_hasta_consumo' => $fechaHasta,
                'fecha_lectura' => $fechaLectura,
                'observaciones' => mb_substr(msp2NormalizeText($obsRaw), 0, 500, 'UTF-8'),
            ];
        }

        if ($validRows === []) {
            throw new RuntimeException('No se encontraron filas validas para previsualizar.');
        }

        if ($errores !== []) {
            $muestraErrores = array_slice($errores, 0, 8);
            $sufijo = count($errores) > count($muestraErrores) ? ' (+' . (count($errores) - count($muestraErrores)) . ' errores)' : '';
            throw new RuntimeException('La plantilla tiene errores: ' . implode(' | ', $muestraErrores) . $sufijo);
        }

        if (count($validRows) > 25000) {
            throw new RuntimeException('La previsualizacion supera el maximo permitido de 25.000 filas.');
        }

        return [
            'periodo_ym' => $periodoYm,
            'codigo_servicio' => $codigoServicio,
            'original_name' => $originalName,
            'reemplazar' => $reemplazarLecturas,
            'valid_rows' => $validRows,
            'created_at' => time(),
        ];
    }

    private static function resolverProcesoCobro(
        PDO $conn,
        string $codigoServicio,
        int $idTipoServicio,
        string $periodoFacturacion,
        bool $requiereFechaEmision
    ): array {
        $cierreStmt = $conn->prepare('SELECT id_cierre_mensual FROM dbo.msp_cierre_mensual WHERE periodo_facturacion = :periodo');
        $cierreStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $cierreStmt->execute();
        $idCierre = (int) ($cierreStmt->fetchColumn() ?: 0);
        if ($idCierre <= 0) {
            throw new RuntimeException('Debes guardar primero el cierre mensual del periodo.');
        }

        $procSql = $requiereFechaEmision
            ? 'SELECT id_proceso_cobro, fecha_emision_origen
               FROM dbo.msp_procesos_cobro_servicio
               WHERE id_cierre_mensual = :id_cierre
                 AND id_tipo_servicio = :id_tipo'
            : 'SELECT id_proceso_cobro
               FROM dbo.msp_procesos_cobro_servicio
               WHERE id_cierre_mensual = :id_cierre
                 AND id_tipo_servicio = :id_tipo';

        $procStmt = $conn->prepare($procSql);
        $procStmt->bindValue(':id_cierre', $idCierre, PDO::PARAM_INT);
        $procStmt->bindValue(':id_tipo', $idTipoServicio, PDO::PARAM_INT);
        $procStmt->execute();

        if ($requiereFechaEmision) {
            $procRow = $procStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $idProcesoCobro = (int) ($procRow['id_proceso_cobro'] ?? 0);
            if ($idProcesoCobro <= 0) {
                throw new RuntimeException('Debes guardar primero los parametros de ' . $codigoServicio . ' para crear el proceso.');
            }
            return [$idCierre, $idProcesoCobro, (string) ($procRow['fecha_emision_origen'] ?? '')];
        }

        $idProcesoCobro = (int) ($procStmt->fetchColumn() ?: 0);
        if ($idProcesoCobro <= 0) {
            throw new RuntimeException('No existe proceso activo para actualizar lecturas de ' . $codigoServicio . '.');
        }

        return [$idCierre, $idProcesoCobro];
    }

    private static function assertImportacionContextoValido(
        int $idTipoServicio,
        string $periodoFacturacion,
        string $errorMessage
    ): void {
        if ($idTipoServicio <= 0 || $periodoFacturacion === '') {
            throw new RuntimeException($errorMessage);
        }
    }

    private static function assertReemplazoPermitido(
        PDO $conn,
        int $idProcesoCobro,
        string $codigoServicio,
        int $reemplazarLecturas
    ): void {
        $existingStmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_lecturas_medidores WHERE id_proceso_cobro = :id');
        $existingStmt->bindValue(':id', $idProcesoCobro, PDO::PARAM_INT);
        $existingStmt->execute();
        $lecturasExistentes = (int) $existingStmt->fetchColumn();
        if ($lecturasExistentes > 0 && $reemplazarLecturas !== 1) {
            throw new RuntimeException(
                'Ya existen lecturas cargadas para '
                . $codigoServicio
                . ' en este periodo. Marca "Reemplazar lecturas existentes" para continuar.'
            );
        }
    }

    private static function formatLecturaError(string $contexto, string $detalle): string
    {
        return $contexto . ': ' . $detalle . '.';
    }

    private static function formatLecturaFieldError(string $contexto, string $campo, string $detalle): string
    {
        return self::formatLecturaError($contexto, $campo . ' ' . $detalle);
    }

    private static function integerInput(?string $raw, bool $required = false): array
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return [$required ? false : true, null];
        }

        if (!preg_match('/^\d+$/', $value)) {
            return [false, null];
        }

        return [true, $value];
    }

    private static function fetchFirstRowsetRow(PDOStatement $stmt): array|false
    {
        do {
            if ($stmt->columnCount() > 0) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row !== false) {
                    return $row;
                }
            }
        } while ($stmt->nextRowset());

        return false;
    }
}
