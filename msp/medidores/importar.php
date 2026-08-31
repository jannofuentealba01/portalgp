<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

function msp2MedidoresImportRedirectFromPost(): never
{
    $redirectTo = trim((string) ($_POST['redirect_to'] ?? ''));
    $allowed = ['locales/index.php'];

    if (!in_array($redirectTo, $allowed, true)) {
        $redirectTo = 'locales/index.php';
    }

    msp2Redirect($redirectTo);
}

function msp2MedidoresNormalizeTipo(string $raw): string
{
    $normalized = mb_strtoupper(trim($raw), 'UTF-8');
    $normalized = strtr($normalized, [
        'Á' => 'A',
        'É' => 'E',
        'Í' => 'I',
        'Ó' => 'O',
        'Ú' => 'U',
        'Ü' => 'U',
    ]);
    $normalized = preg_replace('/[^A-Z0-9]+/', '', $normalized);

    if ($normalized === null) {
        return '';
    }

    return match ($normalized) {
        'AGUA', 'WATER' => 'AGUA',
        'LUZ', 'ELECTRICIDAD', 'ELECTRICO', 'ELECTRICA', 'ENERGIA' => 'LUZ',
        'GAS', 'GASNATURAL' => 'GAS',
        default => '',
    };
}

function msp2MedidoresImportCellToString(mixed $value): string
{
    if ($value === null) {
        return '';
    }

    return trim((string) $value);
}

function msp2MedidoresImportFindColumn(array $headers, array $aliases): ?int
{
    foreach ($aliases as $alias) {
        if (array_key_exists($alias, $headers)) {
            return (int) $headers[$alias];
        }
    }

    return null;
}

function msp2MedidoresImportParseDate(mixed $value): ?string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }

    $formats = ['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'];
    foreach ($formats as $format) {
        $parsed = DateTimeImmutable::createFromFormat($format, $raw);
        if ($parsed !== false && $parsed->format($format) === $raw) {
            return $parsed->format('Y-m-d');
        }
    }

    if (is_numeric($raw)) {
        try {
            $excelDate = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $raw);
            return $excelDate->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    return null;
}

function msp2MedidoresNormalizeCodigoPart(string $raw, bool $allowHyphen = false): string
{
    $normalized = mb_strtoupper(trim($raw), 'UTF-8');
    $normalized = strtr($normalized, [
        'Á' => 'A',
        'É' => 'E',
        'Í' => 'I',
        'Ó' => 'O',
        'Ú' => 'U',
        'Ü' => 'U',
        'Ñ' => 'N',
    ]);
    $pattern = $allowHyphen ? '/[^A-Z0-9-]+/' : '/[^A-Z0-9]+/';
    $normalized = preg_replace($pattern, '', $normalized) ?? '';
    if ($allowHyphen) {
        $normalized = preg_replace('/-+/', '-', $normalized) ?? $normalized;
        $normalized = trim($normalized, '-');
    }

    return $normalized;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('locales/index.php');
}

try {
    msp2LoadSpreadsheetLibrary();
} catch (Throwable $exception) {
    msp2SetFlash('danger', 'No fue posible cargar la librería de Excel. Ejecuta `composer install` e intenta nuevamente.');
    msp2MedidoresImportRedirectFromPost();
}

[$uploadOk, $uploadError, $uploadMeta] = msp2ValidateSpreadsheetUpload($_FILES['excel_file'] ?? null, msp2ImportUploadMaxBytes());
if (!$uploadOk || !is_array($uploadMeta)) {
    msp2SetFlash('warning', $uploadError !== '' ? $uploadError : 'Debes seleccionar un archivo válido para importar.');
    msp2MedidoresImportRedirectFromPost();
}

$uploadTmpPath = (string) ($uploadMeta['tmp_name'] ?? '');
$fechaMedicionDefaultRaw = trim((string) ($_POST['fecha_medicion_valor_inicial'] ?? ''));
$fechaMedicionDefault = null;

if ($fechaMedicionDefaultRaw !== '') {
    $fechaMedicionDefault = msp2MedidoresImportParseDate($fechaMedicionDefaultRaw);
    if ($fechaMedicionDefault === null) {
        msp2SetFlash('warning', 'La fecha de medición del valor inicial no es válida. Usa formato DD-MM-YYYY o YYYY-MM-DD.');
        msp2MedidoresImportRedirectFromPost();
    }
}

try {
    $tablasRequeridas = ['msp_locales', 'msp_medidores', 'msp_tipos_servicio'];

    foreach ($tablasRequeridas as $tabla) {
        if (!msp2TableExists($conn, $tabla)) {
            msp2SetFlash('warning', 'Falta la tabla `' . $tabla . '`. Ejecuta `msp/db/msp_cobro_servicios.sql`.');
            msp2MedidoresImportRedirectFromPost();
        }
    }

    if (!msp2ColumnExists($conn, 'msp_medidores', 'valor_inicial')) {
        msp2SetFlash('warning', 'La columna `msp_medidores.valor_inicial` no existe. Ejecuta `msp/db/msp_cobro_servicios.sql` actualizado o un ALTER TABLE equivalente.');
        msp2MedidoresImportRedirectFromPost();
    }

    $localesStmt = $conn->query('SELECT id_local, cdo_local FROM dbo.msp_locales');
    $localesByCodigo = [];

    while (($row = $localesStmt->fetch()) !== false) {
        $codigo = msp2NormalizeLocalCode((string) ($row['cdo_local'] ?? ''));
        $codigoKey = msp2LocalCodeKey($codigo);
        $idLocal = (int) ($row['id_local'] ?? 0);

        if ($codigoKey !== '' && $idLocal > 0) {
            $localesByCodigo[$codigoKey] = $idLocal;
        }
    }

    $tiposStmt = $conn->query(
        "SELECT id_tipo_servicio, codigo_servicio
         FROM dbo.msp_tipos_servicio
         WHERE UPPER(codigo_servicio) IN ('AGUA', 'LUZ', 'GAS')"
    );
    $tipoIdByCodigo = [];
    $tipoIdByRaw = [];

    while (($row = $tiposStmt->fetch()) !== false) {
        $idTipo = (int) ($row['id_tipo_servicio'] ?? 0);
        $codigoTipo = strtoupper(trim((string) ($row['codigo_servicio'] ?? '')));

        if ($idTipo <= 0 || $codigoTipo === '') {
            continue;
        }

        $tipoIdByCodigo[$codigoTipo] = $idTipo;
        $tipoIdByRaw[(string) $idTipo] = $idTipo;
    }

    if (count($tipoIdByCodigo) < 3) {
        msp2SetFlash('warning', 'No están configurados todos los tipos AGUA/LUZ/GAS en `msp_tipos_servicio`.');
        msp2MedidoresImportRedirectFromPost();
    }

    $rows = msp2ReadSpreadsheetRows($uploadTmpPath, true, true, false, true);

    if ($rows === [] || !isset($rows[0]) || !is_array($rows[0])) {
        msp2SetFlash('warning', 'La planilla no contiene datos para importar.');
        msp2MedidoresImportRedirectFromPost();
    }

    $headers = [];

    foreach ($rows[0] as $index => $headerValue) {
        $normalized = msp2NormalizeLookupKey(msp2MedidoresImportCellToString($headerValue));

        if ($normalized !== '') {
            $headers[$normalized] = $index;
        }
    }

    $columns = [
        'cdo_local' => msp2MedidoresImportFindColumn($headers, ['cdo_local', 'codigo_local', 'cod_local', 'local', 'codigo']),
        'codigo_medidor' => msp2MedidoresImportFindColumn($headers, ['codigo_medidor', 'cod_medidor', 'medidor', 'codigo']),
        'alias_medidor' => msp2MedidoresImportFindColumn($headers, ['alias_medidor', 'alias', 'nombre_medidor', 'nombre']),
        'tipo_servicio' => msp2MedidoresImportFindColumn($headers, ['tipo_servicio', 'tipo', 'servicio', 'codigo_servicio', 'id_tipo_servicio']),
        'id_temporal' => msp2MedidoresImportFindColumn($headers, ['id_temporal', 'temporal', 'correlativo', 'secuencia', 'numero']),
        'valor_inicial' => msp2MedidoresImportFindColumn($headers, ['valor_inicial', 'lectura_inicial', 'valor', 'inicial']),
        'fecha_medicion_valor_inicial' => msp2MedidoresImportFindColumn($headers, ['fecha_medicion_valor_inicial', 'fecha_medicion', 'fecha_valor_inicial', 'fecha_inicial', 'fecha_instalacion']),
    ];

    foreach (['cdo_local', 'tipo_servicio', 'valor_inicial'] as $requiredColumn) {
        if ($columns[$requiredColumn] === null) {
            msp2SetFlash('warning', 'Falta la columna obligatoria `' . $requiredColumn . '` en la planilla.');
            msp2MedidoresImportRedirectFromPost();
        }
    }

    if ($columns['codigo_medidor'] === null && $columns['id_temporal'] === null) {
        msp2SetFlash('warning', 'Debes incluir `codigo_medidor` o `id_temporal` en la planilla para generar el código del medidor.');
        msp2MedidoresImportRedirectFromPost();
    }

    $estadoMedidor = 1;
    $operations = [];
    $errores = [];
    $aliasEnArchivo = [];
    $codigoEnArchivo = [];

    for ($rowIndex = 1, $rowCount = count($rows); $rowIndex < $rowCount; $rowIndex++) {
        $row = $rows[$rowIndex];

        if (!is_array($row)) {
            continue;
        }

        $numeroLinea = $rowIndex + 1;
        $codLocalRaw = msp2MedidoresImportCellToString($row[$columns['cdo_local']] ?? null);
        $codigoMedidorRaw = $columns['codigo_medidor'] !== null
            ? msp2MedidoresImportCellToString($row[$columns['codigo_medidor']] ?? null)
            : '';
        $aliasMedidorRaw = $columns['alias_medidor'] !== null
            ? msp2MedidoresImportCellToString($row[$columns['alias_medidor']] ?? null)
            : '';
        $tipoRaw = msp2MedidoresImportCellToString($row[$columns['tipo_servicio']] ?? null);
        $idTemporalRaw = $columns['id_temporal'] !== null
            ? msp2MedidoresImportCellToString($row[$columns['id_temporal']] ?? null)
            : '';
        $valorRaw = msp2MedidoresImportCellToString($row[$columns['valor_inicial']] ?? null);
        $fechaMedicionRaw = $columns['fecha_medicion_valor_inicial'] !== null
            ? msp2MedidoresImportCellToString($row[$columns['fecha_medicion_valor_inicial']] ?? null)
            : '';

        if ($codLocalRaw === '' && $codigoMedidorRaw === '' && $aliasMedidorRaw === '' && $tipoRaw === '' && $idTemporalRaw === '' && $valorRaw === '' && $fechaMedicionRaw === '') {
            continue;
        }

        $codLocal = msp2NormalizeLocalCode($codLocalRaw);
        $codLocalKey = msp2LocalCodeKey($codLocal);

        if ($codLocal === '') {
            $errores[] = 'Fila ' . $numeroLinea . ': código de local vacío.';
            continue;
        }

        if (!isset($localesByCodigo[$codLocalKey])) {
            $errores[] = 'Fila ' . $numeroLinea . ': no existe local con código `' . $codLocal . '`.';
            continue;
        }

        $tipoNormalizado = '';
        if ($tipoRaw !== '' && ctype_digit($tipoRaw) && isset($tipoIdByRaw[$tipoRaw])) {
            foreach ($tipoIdByCodigo as $codigoTipo => $idTipo) {
                if ($idTipo === (int) $tipoRaw) {
                    $tipoNormalizado = $codigoTipo;
                    break;
                }
            }
        } else {
            $tipoNormalizado = msp2MedidoresNormalizeTipo($tipoRaw);
        }

        if ($tipoNormalizado === '' || !isset($tipoIdByCodigo[$tipoNormalizado])) {
            $errores[] = 'Fila ' . $numeroLinea . ': tipo inválido `' . $tipoRaw . '`. Usa AGUA, LUZ o GAS.';
            continue;
        }

        $idTemporal = msp2MedidoresNormalizeCodigoPart($idTemporalRaw, false);
        $codigoMedidor = msp2MedidoresNormalizeCodigoPart($codigoMedidorRaw, true);

        if ($codigoMedidor === '') {
            if ($idTemporal === '') {
                $errores[] = 'Fila ' . $numeroLinea . ': debes indicar `codigo_medidor` o `id_temporal` para generar el código.';
                continue;
            }
            $codigoMedidor = $codLocal . '-' . $tipoNormalizado . '-' . $idTemporal;
        }

        $aliasMedidor = msp2NormalizeText($aliasMedidorRaw);
        if ($aliasMedidor === '') {
            $aliasMedidor = $codigoMedidor;
        }

        if ($codigoMedidor === '' || mb_strlen($codigoMedidor) > 100 || mb_strlen($aliasMedidor) > 100) {
            $errores[] = 'Fila ' . $numeroLinea . ': código/alias de medidor inválido.';
            continue;
        }

        if (isset($codigoEnArchivo[$codigoMedidor])) {
            $errores[] = 'Fila ' . $numeroLinea . ': el código de medidor `' . $codigoMedidor . '` está repetido en la planilla.';
            continue;
        }

        $aliasKey = $codLocal . '|' . $tipoNormalizado . '|' . mb_strtolower($aliasMedidor, 'UTF-8');
        if (isset($aliasEnArchivo[$aliasKey])) {
            $errores[] = 'Fila ' . $numeroLinea . ': alias `' . $aliasMedidor . '` repetido para el mismo local y servicio.';
            continue;
        }

        [$valorValido, $valorNormalizado] = msp2NormalizeDecimalInput($valorRaw, 6);
        if (!$valorValido || $valorNormalizado === null) {
            $errores[] = 'Fila ' . $numeroLinea . ': valor inicial inválido.';
            continue;
        }

        $fechaMedicionValorInicial = $fechaMedicionDefault;
        if ($fechaMedicionRaw !== '') {
            $fechaMedicionValorInicial = msp2MedidoresImportParseDate($fechaMedicionRaw);
            if ($fechaMedicionValorInicial === null) {
                $errores[] = 'Fila ' . $numeroLinea . ': fecha de medición de valor inicial inválida.';
                continue;
            }
        }

        $aliasEnArchivo[$aliasKey] = true;
        $codigoEnArchivo[$codigoMedidor] = true;

        $operations[] = [
            'linea' => $numeroLinea,
            'id_local' => (int) $localesByCodigo[$codLocalKey],
            'cod_local' => $codLocal,
            'codigo_medidor' => $codigoMedidor,
            'alias_medidor' => $aliasMedidor,
            'codigo_tipo' => $tipoNormalizado,
            'id_tipo_servicio' => (int) $tipoIdByCodigo[$tipoNormalizado],
            'valor_inicial' => $valorNormalizado,
            'fecha_instalacion' => $fechaMedicionValorInicial,
        ];
    }

    if ($operations === []) {
        $mensaje = 'No se encontraron operaciones válidas para importar.';
        if ($errores !== []) {
            $mensaje .= ' ' . implode(' | ', array_slice($errores, 0, 3));
        }
        msp2SetFlash('warning', $mensaje);
        msp2MedidoresImportRedirectFromPost();
    }

    $stmtByCodigo = $conn->prepare(
        'SELECT TOP 1 id_medidor, id_local, id_tipo_servicio
         FROM dbo.msp_medidores
         WHERE codigo_medidor = :codigo_medidor'
    );

    $stmtAliasCheck = $conn->prepare(
        'SELECT COUNT(*)
         FROM dbo.msp_medidores
         WHERE id_local = :id_local
           AND id_tipo_servicio = :id_tipo_servicio
           AND alias_medidor = :alias_medidor
           AND codigo_medidor <> :codigo_medidor'
    );

    $stmtUpdate = $conn->prepare(
        'UPDATE dbo.msp_medidores
         SET id_local = :id_local,
             id_tipo_servicio = :id_tipo_servicio,
             alias_medidor = :alias_medidor,
             valor_inicial = :valor_inicial,
             fecha_instalacion = COALESCE(:fecha_instalacion, fecha_instalacion),
             fecha_retiro = NULL,
             estado_medidor = :estado_medidor
         WHERE id_medidor = :id_medidor'
    );

    $stmtInsert = $conn->prepare(
        'INSERT INTO dbo.msp_medidores
            (id_local, id_tipo_servicio, codigo_medidor, alias_medidor, numero_serie, valor_inicial, fecha_instalacion, fecha_retiro, estado_medidor)
         VALUES
            (:id_local, :id_tipo_servicio, :codigo_medidor, :alias_medidor, NULL, :valor_inicial, :fecha_instalacion, NULL, :estado_medidor)'
    );

    $insertados = 0;
    $actualizados = 0;
    $rechazados = 0;

    $conn->beginTransaction();

    foreach ($operations as $op) {
        $linea = (int) $op['linea'];
        $idLocal = (int) $op['id_local'];
        $idTipo = (int) $op['id_tipo_servicio'];
        $codigoMedidor = (string) $op['codigo_medidor'];
        $aliasMedidor = (string) $op['alias_medidor'];
        $valorInicial = (string) $op['valor_inicial'];
        $fechaInstalacion = is_string($op['fecha_instalacion'] ?? null) ? (string) $op['fecha_instalacion'] : null;

        $stmtByCodigo->bindValue(':codigo_medidor', $codigoMedidor, PDO::PARAM_STR);
        $stmtByCodigo->execute();
        $byCodigo = $stmtByCodigo->fetch();

        if ($byCodigo !== false) {
            $idMedidorByCode = (int) ($byCodigo['id_medidor'] ?? 0);
            $idLocalByCode = (int) ($byCodigo['id_local'] ?? 0);
            $idTipoByCode = (int) ($byCodigo['id_tipo_servicio'] ?? 0);

            if ($idLocalByCode !== $idLocal || $idTipoByCode !== $idTipo) {
                $rechazados++;
                $errores[] = 'Fila ' . $linea . ': el código de medidor `' . $codigoMedidor . '` ya existe para otro local o servicio.';
                continue;
            }

            if ($idMedidorByCode > 0) {
                $stmtAliasCheck->bindValue(':id_local', $idLocal, PDO::PARAM_INT);
                $stmtAliasCheck->bindValue(':id_tipo_servicio', $idTipo, PDO::PARAM_INT);
                $stmtAliasCheck->bindValue(':alias_medidor', $aliasMedidor, PDO::PARAM_STR);
                $stmtAliasCheck->bindValue(':codigo_medidor', $codigoMedidor, PDO::PARAM_STR);
                $stmtAliasCheck->execute();
                if ((int) $stmtAliasCheck->fetchColumn() > 0) {
                    $rechazados++;
                    $errores[] = 'Fila ' . $linea . ': el alias `' . $aliasMedidor . '` ya existe para el mismo local y servicio.';
                    continue;
                }
                $stmtUpdate->bindValue(':id_local', $idLocal, PDO::PARAM_INT);
                $stmtUpdate->bindValue(':id_tipo_servicio', $idTipo, PDO::PARAM_INT);
                $stmtUpdate->bindValue(':alias_medidor', $aliasMedidor, PDO::PARAM_STR);
                $stmtUpdate->bindValue(':valor_inicial', $valorInicial, PDO::PARAM_STR);
                $stmtUpdate->bindValue(':fecha_instalacion', $fechaInstalacion, $fechaInstalacion !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $stmtUpdate->bindValue(':estado_medidor', $estadoMedidor, PDO::PARAM_INT);
                $stmtUpdate->bindValue(':id_medidor', $idMedidorByCode, PDO::PARAM_INT);
                $stmtUpdate->execute();
                $actualizados++;
                continue;
            }
        }

        $stmtAliasCheck->bindValue(':id_local', $idLocal, PDO::PARAM_INT);
        $stmtAliasCheck->bindValue(':id_tipo_servicio', $idTipo, PDO::PARAM_INT);
        $stmtAliasCheck->bindValue(':alias_medidor', $aliasMedidor, PDO::PARAM_STR);
        $stmtAliasCheck->bindValue(':codigo_medidor', $codigoMedidor, PDO::PARAM_STR);
        $stmtAliasCheck->execute();
        if ((int) $stmtAliasCheck->fetchColumn() > 0) {
            $rechazados++;
            $errores[] = 'Fila ' . $linea . ': el alias `' . $aliasMedidor . '` ya existe para el mismo local y servicio.';
            continue;
        }

        $stmtInsert->bindValue(':id_local', $idLocal, PDO::PARAM_INT);
        $stmtInsert->bindValue(':id_tipo_servicio', $idTipo, PDO::PARAM_INT);
        $stmtInsert->bindValue(':codigo_medidor', $codigoMedidor, PDO::PARAM_STR);
        $stmtInsert->bindValue(':alias_medidor', $aliasMedidor, PDO::PARAM_STR);
        $stmtInsert->bindValue(':valor_inicial', $valorInicial, PDO::PARAM_STR);
        $stmtInsert->bindValue(':fecha_instalacion', $fechaInstalacion, $fechaInstalacion !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmtInsert->bindValue(':estado_medidor', $estadoMedidor, PDO::PARAM_INT);
        $stmtInsert->execute();
        $insertados++;
    }

    $conn->commit();

    $procesados = $insertados + $actualizados;

    if ($procesados > 0 && $rechazados === 0) {
        msp2SetFlash('success', 'Importación completada. Insertados: ' . $insertados . ' | Actualizados: ' . $actualizados . '.');
    } elseif ($procesados > 0) {
        $mensaje = 'Importación parcial. Insertados: ' . $insertados . ' | Actualizados: ' . $actualizados . ' | Rechazados: ' . $rechazados . '.';
        if ($errores !== []) {
            $mensaje .= ' Detalle: ' . implode(' | ', array_slice($errores, 0, 5));
        }
        msp2SetFlash('warning', $mensaje);
    } else {
        $mensaje = 'No se importaron medidores. Rechazados: ' . $rechazados . '.';
        if ($errores !== []) {
            $mensaje .= ' Detalle: ' . implode(' | ', array_slice($errores, 0, 5));
        }
        msp2SetFlash('warning', $mensaje);
    }
} catch (PDOException $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    msp2SetFlash('danger', 'No fue posible importar medidores. Revisa el formato y la estructura de la base.');
}

msp2MedidoresImportRedirectFromPost();
