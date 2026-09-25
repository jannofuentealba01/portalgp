<?php
declare(strict_types=1);

/**
 * Construye una fotografía mensual auditable por trabajador y CECO.
 * No consulta la base de datos ni APIs; recibe la nómina Buk ya normalizada.
 */
function fte_headcount_snapshot_build(array $people, int $year, int $month, array $calendar): array
{
    if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
        throw new InvalidArgumentException('El periodo de la fotografia de dotacion no es valido.');
    }

    $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $last = $first->modify('last day of this month');
    $workdayByDate = [];
    foreach (($calendar['days'] ?? []) as $day) {
        if (is_array($day) && isset($day['date'])) {
            $workdayByDate[(string)$day['date']] = (float)($day['theoretical_hours'] ?? 0) > 0;
        }
    }

    $rows = [];
    $uniquePeople = [];
    $endOfMonthPeople = [];
    $personCalendarDays = 0;
    $personWorkdays = 0;
    $unassignedPersonDays = 0;
    $calendarDayCount = 0;
    $workdayCount = 0;

    for ($date = $first; $date <= $last; $date = $date->modify('+1 day')) {
        $dateKey = $date->format('Y-m-d');
        $isWorkday = $workdayByDate[$dateKey] ?? ((int)$date->format('N') <= 5);
        $calendarDayCount++;
        if ($isWorkday) {
            $workdayCount++;
        }

        foreach ($people as $person) {
            if (!is_array($person) || !fte_headcount_person_active_on($person, $dateKey)) {
                continue;
            }
            $identifier = fte_normalize_identifier($person['normalized_identifier'] ?? $person['identifier'] ?? '');
            if ($identifier === '') {
                continue;
            }
            $job = fte_headcount_job_on_date($person, $dateKey);
            $code = fte_normalize_cost_center($job['cost_center_code'] ?? '');
            $name = trim((string)($job['cost_center_name'] ?? ''));
            if ($code === '') {
                $code = 'SIN_CECO';
                $unassignedPersonDays++;
            }
            $key = $identifier . '|' . $code;
            if (!isset($rows[$key])) {
                $rows[$key] = [
                    'normalized_identifier' => $identifier,
                    'identifier' => trim((string)($person['identifier'] ?? '')),
                    'person_name' => trim((string)($person['person_name'] ?? '')) ?: $identifier,
                    'buk_employee_id' => trim((string)($person['buk_employee_id'] ?? '')),
                    'cost_center_code' => $code,
                    'cost_center_name' => $name,
                    'active_any_time' => true,
                    'active_at_month_end' => false,
                    'first_active_date' => $dateKey,
                    'last_active_date' => $dateKey,
                    'calendar_days' => 0,
                    'workdays' => 0,
                    'active_dates' => [],
                    'job_ids' => [],
                ];
            }
            if ($rows[$key]['cost_center_name'] === '' && $name !== '') {
                $rows[$key]['cost_center_name'] = $name;
            }
            $rows[$key]['last_active_date'] = $dateKey;
            $rows[$key]['calendar_days']++;
            $rows[$key]['active_dates'][] = $dateKey;
            if ($isWorkday) {
                $rows[$key]['workdays']++;
            }
            $jobId = $job['job_id'] ?? null;
            if ($jobId !== null && $jobId !== '') {
                $rows[$key]['job_ids'][(string)$jobId] = true;
            }
            if ($dateKey === $last->format('Y-m-d')) {
                $rows[$key]['active_at_month_end'] = true;
                $endOfMonthPeople[$identifier] = true;
            }
            $uniquePeople[$identifier] = true;
            $personCalendarDays++;
            if ($isWorkday) {
                $personWorkdays++;
            }
        }
    }

    foreach ($rows as &$row) {
        $row['job_ids'] = array_keys($row['job_ids']);
        sort($row['job_ids'], SORT_NATURAL);
    }
    unset($row);
    ksort($rows, SORT_NATURAL);
    $rows = array_values($rows);

    $snapshot = [
        'period' => $first->format('Y-m'),
        'period_date' => $first->format('Y-m-d'),
        'month_start' => $first->format('Y-m-d'),
        'month_end' => $last->format('Y-m-d'),
        'source' => 'BUK_API',
        'counting_rule' => 'monthly_unique_active_any_time_by_cost_center',
        'total_unique_people' => count($uniquePeople),
        'total_cost_center_assignments' => count($rows),
        'end_of_month_headcount' => count($endOfMonthPeople),
        'average_calendar_day_headcount' => $calendarDayCount > 0 ? $personCalendarDays / $calendarDayCount : null,
        'average_workday_headcount' => $workdayCount > 0 ? $personWorkdays / $workdayCount : null,
        'person_calendar_days' => $personCalendarDays,
        'person_workdays' => $personWorkdays,
        'unassigned_person_days' => $unassignedPersonDays,
        'rows' => $rows,
    ];
    $hashSource = $snapshot;
    unset($hashSource['hash']);
    $snapshot['hash'] = hash('sha256', json_encode(
        $hashSource,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
    ));
    return $snapshot;
}

function fte_headcount_snapshot_tables_ready(PDO $conn): bool
{
    $stmt = $conn->query(
        "SELECT CASE WHEN OBJECT_ID(N'dbo.fte_dotacion_snapshots', N'U') IS NOT NULL
                       AND OBJECT_ID(N'dbo.fte_dotacion_snapshot_trabajadores', N'U') IS NOT NULL
                     THEN 1 ELSE 0 END"
    );
    return (int)$stmt->fetchColumn() === 1;
}

function fte_headcount_snapshot_status(PDO $conn, string $period): array
{
    if (!preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $period)) {
        throw new InvalidArgumentException('El periodo de la fotografia no es valido.');
    }
    if (!fte_headcount_snapshot_tables_ready($conn)) {
        return ['available' => false, 'exists' => false, 'period' => $period];
    }
    $stmt = $conn->prepare(
        "SELECT TOP (1)
            s.id_fte_dotacion_snapshot,
            CONVERT(char(7), s.periodo, 120) AS periodo,
            s.version_snapshot,
            s.estado_snapshot,
            s.fuente,
            s.total_personas_unicas,
            s.total_asignaciones_ceco,
            s.dotacion_cierre,
            CAST(s.promedio_dia_calendario AS float) AS promedio_dia_calendario,
            CAST(s.promedio_dia_habil AS float) AS promedio_dia_habil,
            s.persona_dias_calendario,
            s.persona_dias_habiles,
            s.dias_sin_ceco,
            s.hash_snapshot,
            s.fecha_fuente,
            s.fecha_creacion,
            s.fecha_aprobacion,
            s.observacion_aprobacion,
            COALESCE(NULLIF(u.nombre_completo, N''), u.UserName) AS usuario_creacion,
            COALESCE(NULLIF(ua.nombre_completo, N''), ua.UserName) AS usuario_aprobacion,
            (SELECT COUNT(*) FROM dbo.fte_dotacion_snapshot_trabajadores d
              WHERE d.id_fte_dotacion_snapshot = s.id_fte_dotacion_snapshot) AS filas_trabajador_ceco
         FROM dbo.fte_dotacion_snapshots s
         INNER JOIN dbo.cr_usuarios u ON u.id = s.id_usuario_creacion
         LEFT JOIN dbo.cr_usuarios ua ON ua.id = s.id_usuario_aprobacion
         WHERE s.periodo = CONVERT(date, :periodo + '-01', 23)
           AND s.es_vigente = 1
         ORDER BY s.version_snapshot DESC"
    );
    $stmt->execute([':periodo' => $period]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['available' => true, 'exists' => false, 'period' => $period];
    }
    return [
        'available' => true,
        'exists' => true,
        'id' => (int)$row['id_fte_dotacion_snapshot'],
        'period' => (string)$row['periodo'],
        'version' => (int)$row['version_snapshot'],
        'status' => (string)$row['estado_snapshot'],
        'source' => (string)$row['fuente'],
        'total_unique_people' => (int)$row['total_personas_unicas'],
        'total_cost_center_assignments' => (int)$row['total_asignaciones_ceco'],
        'end_of_month_headcount' => (int)$row['dotacion_cierre'],
        'average_calendar_day_headcount' => $row['promedio_dia_calendario'] !== null ? (float)$row['promedio_dia_calendario'] : null,
        'average_workday_headcount' => $row['promedio_dia_habil'] !== null ? (float)$row['promedio_dia_habil'] : null,
        'person_calendar_days' => (int)$row['persona_dias_calendario'],
        'person_workdays' => (int)$row['persona_dias_habiles'],
        'unassigned_person_days' => (int)$row['dias_sin_ceco'],
        'detail_rows' => (int)$row['filas_trabajador_ceco'],
        'hash' => (string)$row['hash_snapshot'],
        'source_at' => (string)$row['fecha_fuente'],
        'created_at' => (string)$row['fecha_creacion'],
        'created_by' => (string)$row['usuario_creacion'],
        'approved_at' => $row['fecha_aprobacion'] !== null ? (string)$row['fecha_aprobacion'] : null,
        'approved_by' => $row['usuario_aprobacion'] !== null ? (string)$row['usuario_aprobacion'] : null,
        'approval_note' => $row['observacion_aprobacion'] !== null ? (string)$row['observacion_aprobacion'] : null,
        'is_official' => (string)$row['estado_snapshot'] === 'APROBADO',
        'can_update' => (string)$row['estado_snapshot'] === 'BORRADOR',
    ];
}

function fte_headcount_snapshot_details(PDO $conn, string $period): array
{
    $status = fte_headcount_snapshot_status($conn, $period);
    if (empty($status['exists'])) {
        return ['snapshot' => $status, 'rows' => []];
    }
    $stmt = $conn->prepare(
        "SELECT
            d.identificador_snapshot,
            d.nombre_snapshot,
            d.buk_employee_id,
            d.codigo_ceco,
            d.nombre_ceco,
            d.vigente_cierre,
            CONVERT(char(10), d.primer_dia_vigente, 23) AS primer_dia_vigente,
            CONVERT(char(10), d.ultimo_dia_vigente, 23) AS ultimo_dia_vigente,
            d.dias_calendario,
            d.dias_habiles
         FROM dbo.fte_dotacion_snapshot_trabajadores d
         WHERE d.id_fte_dotacion_snapshot = :snapshot
         ORDER BY d.codigo_ceco, d.nombre_snapshot, d.identificador_normalizado"
    );
    $stmt->execute([':snapshot' => (int)$status['id']]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = [
            'identifier' => (string)($row['identificador_snapshot'] ?? ''),
            'person_name' => (string)($row['nombre_snapshot'] ?? ''),
            'buk_employee_id' => (string)($row['buk_employee_id'] ?? ''),
            'cost_center_code' => (string)($row['codigo_ceco'] ?? ''),
            'cost_center_name' => (string)($row['nombre_ceco'] ?? ''),
            'active_at_month_end' => (bool)($row['vigente_cierre'] ?? false),
            'first_active_date' => (string)($row['primer_dia_vigente'] ?? ''),
            'last_active_date' => (string)($row['ultimo_dia_vigente'] ?? ''),
            'calendar_days' => (int)($row['dias_calendario'] ?? 0),
            'workdays' => (int)($row['dias_habiles'] ?? 0),
        ];
    }
    return ['snapshot' => $status, 'rows' => $rows];
}
function fte_headcount_snapshot_save(PDO $conn, array $snapshot, int $userId): array
{
    if ($userId <= 0) {
        throw new InvalidArgumentException('No fue posible identificar al usuario que guarda la fotografia.');
    }
    if (!fte_headcount_snapshot_tables_ready($conn)) {
        throw new RuntimeException('La estructura de fotografias FTE no esta instalada.');
    }
    $period = trim((string)($snapshot['period'] ?? ''));
    $hash = trim((string)($snapshot['hash'] ?? ''));
    $rows = $snapshot['rows'] ?? null;
    if (!preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $period)
        || !preg_match('/^[a-f0-9]{64}$/', $hash)
        || !is_array($rows)) {
        throw new InvalidArgumentException('La fotografia mensual no tiene una estructura valida.');
    }

    $ownsTransaction = !$conn->inTransaction();
    if ($ownsTransaction) {
        $conn->beginTransaction();
    }
    try {
        $lock = $conn->prepare(
            "DECLARE @result int;
             EXEC @result = sys.sp_getapplock
                @Resource = :resource,
                @LockMode = 'Exclusive',
                @LockOwner = 'Transaction',
                @LockTimeout = 10000;
             SELECT @result AS lock_result;"
        );
        $lock->execute([':resource' => 'fte_dotacion_snapshot_' . $period]);
        if ((int)$lock->fetchColumn() < 0) {
            throw new RuntimeException('No fue posible bloquear el periodo para guardar la fotografia.');
        }

        $currentStmt = $conn->prepare(
            "SELECT TOP (1) id_fte_dotacion_snapshot, version_snapshot, estado_snapshot, hash_snapshot
             FROM dbo.fte_dotacion_snapshots WITH (UPDLOCK, HOLDLOCK)
             WHERE periodo = CONVERT(date, :periodo + '-01', 23) AND es_vigente = 1
             ORDER BY version_snapshot DESC"
        );
        $currentStmt->execute([':periodo' => $period]);
        $current = $currentStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($current && (string)$current['estado_snapshot'] === 'APROBADO') {
            throw new DomainException('La fotografia mensual ya esta aprobada y no puede reemplazarse.');
        }
        if ($current && hash_equals((string)$current['hash_snapshot'], $hash)) {
            if ($ownsTransaction) {
                $conn->commit();
            }
            $status = fte_headcount_snapshot_status($conn, $period);
            $status['created'] = false;
            $status['unchanged'] = true;
            return $status;
        }

        $versionStmt = $conn->prepare(
            "SELECT ISNULL(MAX(version_snapshot), 0) + 1
             FROM dbo.fte_dotacion_snapshots WITH (UPDLOCK, HOLDLOCK)
             WHERE periodo = CONVERT(date, :periodo + '-01', 23)"
        );
        $versionStmt->execute([':periodo' => $period]);
        $version = (int)$versionStmt->fetchColumn();

        if ($current) {
            $replace = $conn->prepare(
                "UPDATE dbo.fte_dotacion_snapshots
                    SET es_vigente = 0, estado_snapshot = N'REEMPLAZADO'
                  WHERE id_fte_dotacion_snapshot = :id AND estado_snapshot = N'BORRADOR'"
            );
            $replace->execute([':id' => (int)$current['id_fte_dotacion_snapshot']]);
        }

        $insert = $conn->prepare(
            "INSERT INTO dbo.fte_dotacion_snapshots
                (periodo, version_snapshot, estado_snapshot, es_vigente, fuente, regla_dotacion, fecha_fuente,
                 total_personas_unicas, total_asignaciones_ceco, dotacion_cierre,
                 promedio_dia_calendario, promedio_dia_habil, persona_dias_calendario,
                 persona_dias_habiles, dias_sin_ceco, hash_snapshot, id_usuario_creacion)
             OUTPUT INSERTED.id_fte_dotacion_snapshot
             VALUES
                (CONVERT(date, :periodo + '-01', 23), :version, N'BORRADOR', 1, :fuente, N'monthly_unique_active_any_time_by_cost_center', SYSDATETIME(),
                 :total_personas, :total_asignaciones, :dotacion_cierre,
                 :promedio_calendario, :promedio_habil, :persona_dias_calendario,
                 :persona_dias_habiles, :dias_sin_ceco, :hash_snapshot, :id_usuario)"
        );
        $insert->execute([
            ':periodo' => $period,
            ':version' => $version,
            ':fuente' => (string)($snapshot['source'] ?? 'BUK_API'),
            ':total_personas' => (int)($snapshot['total_unique_people'] ?? 0),
            ':total_asignaciones' => (int)($snapshot['total_cost_center_assignments'] ?? count($rows)),
            ':dotacion_cierre' => (int)($snapshot['end_of_month_headcount'] ?? 0),
            ':promedio_calendario' => $snapshot['average_calendar_day_headcount'] ?? null,
            ':promedio_habil' => $snapshot['average_workday_headcount'] ?? null,
            ':persona_dias_calendario' => (int)($snapshot['person_calendar_days'] ?? 0),
            ':persona_dias_habiles' => (int)($snapshot['person_workdays'] ?? 0),
            ':dias_sin_ceco' => (int)($snapshot['unassigned_person_days'] ?? 0),
            ':hash_snapshot' => $hash,
            ':id_usuario' => $userId,
        ]);
        $snapshotId = (int)$insert->fetchColumn();

        $insertDetail = $conn->prepare(
            "INSERT INTO dbo.fte_dotacion_snapshot_trabajadores
                (id_fte_dotacion_snapshot, identificador_normalizado, identificador_snapshot,
                 nombre_snapshot, buk_employee_id, codigo_ceco, nombre_ceco,
                 vigente_algun_dia, vigente_cierre, primer_dia_vigente, ultimo_dia_vigente,
                 dias_calendario, dias_habiles, fechas_vigentes_json, job_ids_json)
             VALUES
                (:snapshot, :identificador_normalizado, :identificador_snapshot,
                 :nombre_snapshot, :buk_employee_id, :codigo_ceco, :nombre_ceco,
                 1, :vigente_cierre, :primer_dia, :ultimo_dia,
                 :dias_calendario, :dias_habiles, :fechas_json, :jobs_json)"
        );
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('La fotografia contiene una fila de trabajador invalida.');
            }
            $insertDetail->execute([
                ':snapshot' => $snapshotId,
                ':identificador_normalizado' => (string)($row['normalized_identifier'] ?? ''),
                ':identificador_snapshot' => ($row['identifier'] ?? '') !== '' ? (string)$row['identifier'] : null,
                ':nombre_snapshot' => (string)($row['person_name'] ?? ''),
                ':buk_employee_id' => ($row['buk_employee_id'] ?? '') !== '' ? (string)$row['buk_employee_id'] : null,
                ':codigo_ceco' => (string)($row['cost_center_code'] ?? 'SIN_CECO'),
                ':nombre_ceco' => ($row['cost_center_name'] ?? '') !== '' ? (string)$row['cost_center_name'] : null,
                ':vigente_cierre' => !empty($row['active_at_month_end']) ? 1 : 0,
                ':primer_dia' => (string)($row['first_active_date'] ?? ''),
                ':ultimo_dia' => (string)($row['last_active_date'] ?? ''),
                ':dias_calendario' => (int)($row['calendar_days'] ?? 0),
                ':dias_habiles' => (int)($row['workdays'] ?? 0),
                ':fechas_json' => json_encode($row['active_dates'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ':jobs_json' => json_encode($row['job_ids'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ]);
        }
        if ($ownsTransaction) {
            $conn->commit();
        }
        $status = fte_headcount_snapshot_status($conn, $period);
        $status['created'] = true;
        $status['unchanged'] = false;
        return $status;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $exception;
    }
}

/**
 * Aprueba y congela la fotografia vigente del periodo.
 * La dotacion de control obliga a que la aprobacion sea una conciliacion explicita.
 */
function fte_headcount_snapshot_approve(
    PDO $conn,
    string $period,
    int $userId,
    int $expectedHeadcount,
    string $note = ''
): array {
    if (!preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $period)) {
        throw new InvalidArgumentException('El periodo de la fotografia no es valido.');
    }
    if ($userId <= 0) {
        throw new InvalidArgumentException('No fue posible identificar al usuario que aprueba la fotografia.');
    }
    if ($expectedHeadcount < 0) {
        throw new InvalidArgumentException('La dotacion de control debe ser un numero entero mayor o igual a cero.');
    }
    $note = trim($note);
    $noteLength = function_exists('mb_strlen') ? mb_strlen($note, 'UTF-8') : strlen($note);
    if ($noteLength > 500) {
        throw new InvalidArgumentException('La observacion de aprobacion no puede superar 500 caracteres.');
    }
    if (!fte_headcount_snapshot_tables_ready($conn)) {
        throw new RuntimeException('La estructura de fotografias FTE no esta instalada.');
    }

    $ownsTransaction = !$conn->inTransaction();
    if ($ownsTransaction) {
        $conn->beginTransaction();
    }
    try {
        $lock = $conn->prepare(
            "DECLARE @result int;
             EXEC @result = sys.sp_getapplock
                @Resource = :resource,
                @LockMode = 'Exclusive',
                @LockOwner = 'Transaction',
                @LockTimeout = 10000;
             SELECT @result AS lock_result;"
        );
        $lock->execute([':resource' => 'fte_dotacion_snapshot_' . $period]);
        if ((int)$lock->fetchColumn() < 0) {
            throw new RuntimeException('No fue posible bloquear el periodo para aprobar la fotografia.');
        }

        $currentStmt = $conn->prepare(
            "SELECT TOP (1)
                s.id_fte_dotacion_snapshot,
                s.estado_snapshot,
                s.total_personas_unicas,
                s.total_asignaciones_ceco,
                s.dias_sin_ceco,
                (SELECT COUNT(*) FROM dbo.fte_dotacion_snapshot_trabajadores d
                  WHERE d.id_fte_dotacion_snapshot = s.id_fte_dotacion_snapshot) AS filas_detalle
             FROM dbo.fte_dotacion_snapshots s WITH (UPDLOCK, HOLDLOCK)
             WHERE s.periodo = CONVERT(date, :periodo + '-01', 23)
               AND s.es_vigente = 1
             ORDER BY s.version_snapshot DESC"
        );
        $currentStmt->execute([':periodo' => $period]);
        $current = $currentStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$current) {
            throw new DomainException('Primero guarda la fotografia mensual antes de aprobarla.');
        }

        $storedHeadcount = (int)$current['total_personas_unicas'];
        if ($expectedHeadcount !== $storedHeadcount) {
            throw new DomainException(sprintf(
                'La dotacion de control (%d) no coincide con las %d personas unicas de la fotografia.',
                $expectedHeadcount,
                $storedHeadcount
            ));
        }
        if ((string)$current['estado_snapshot'] === 'APROBADO') {
            if ($ownsTransaction) {
                $conn->commit();
            }
            $status = fte_headcount_snapshot_status($conn, $period);
            $status['approved'] = false;
            $status['unchanged'] = true;
            return $status;
        }
        if ((string)$current['estado_snapshot'] !== 'BORRADOR') {
            throw new DomainException('Solo una fotografia vigente en borrador puede aprobarse.');
        }
        if ($storedHeadcount <= 0) {
            throw new DomainException('No se puede aprobar una fotografia sin trabajadores.');
        }
        if ((int)$current['total_asignaciones_ceco'] !== (int)$current['filas_detalle']) {
            throw new DomainException('El detalle trabajador-CECO no coincide con el total guardado. Vuelve a generar el borrador.');
        }
        if ((int)$current['dias_sin_ceco'] > 0) {
            throw new DomainException('Existen dias de trabajadores sin CECO. Corrige la asignacion antes de aprobar.');
        }

        $approve = $conn->prepare(
            "UPDATE dbo.fte_dotacion_snapshots
                SET estado_snapshot = N'APROBADO',
                    id_usuario_aprobacion = :usuario,
                    fecha_aprobacion = SYSDATETIME(),
                    observacion_aprobacion = :observacion
              WHERE id_fte_dotacion_snapshot = :id
                AND estado_snapshot = N'BORRADOR'
                AND es_vigente = 1"
        );
        $approve->execute([
            ':usuario' => $userId,
            ':observacion' => $note !== '' ? $note : null,
            ':id' => (int)$current['id_fte_dotacion_snapshot'],
        ]);
        if ($approve->rowCount() !== 1) {
            throw new RuntimeException('La fotografia cambio mientras se aprobaba. Recarga el periodo e intenta nuevamente.');
        }
        if ($ownsTransaction) {
            $conn->commit();
        }
        $status = fte_headcount_snapshot_status($conn, $period);
        $status['approved'] = true;
        $status['unchanged'] = false;
        return $status;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $exception;
    }
}

/**
 * Carga solo la version vigente aprobada. Un borrador nunca cambia el calculo oficial.
 */
function fte_headcount_snapshot_load_approved(PDO $conn, string $period): ?array
{
    $status = fte_headcount_snapshot_status($conn, $period);
    if (empty($status['exists']) || (string)($status['status'] ?? '') !== 'APROBADO') {
        return null;
    }
    $stmt = $conn->prepare(
        "SELECT
            d.identificador_normalizado,
            d.identificador_snapshot,
            d.nombre_snapshot,
            d.buk_employee_id,
            d.codigo_ceco,
            d.nombre_ceco,
            d.vigente_cierre,
            CONVERT(char(10), d.primer_dia_vigente, 23) AS primer_dia_vigente,
            CONVERT(char(10), d.ultimo_dia_vigente, 23) AS ultimo_dia_vigente,
            d.dias_calendario,
            d.dias_habiles,
            d.fechas_vigentes_json,
            d.job_ids_json
         FROM dbo.fte_dotacion_snapshot_trabajadores d
         WHERE d.id_fte_dotacion_snapshot = :snapshot
         ORDER BY d.codigo_ceco, d.nombre_snapshot, d.identificador_normalizado"
    );
    $stmt->execute([':snapshot' => (int)$status['id']]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $dates = json_decode((string)$row['fechas_vigentes_json'], true, 512, JSON_THROW_ON_ERROR);
        $jobIds = $row['job_ids_json'] !== null
            ? json_decode((string)$row['job_ids_json'], true, 512, JSON_THROW_ON_ERROR)
            : [];
        if (!is_array($dates) || !is_array($jobIds)) {
            throw new RuntimeException('El detalle aprobado contiene una trazabilidad JSON invalida.');
        }
        $rows[] = [
            'normalized_identifier' => (string)$row['identificador_normalizado'],
            'identifier' => (string)($row['identificador_snapshot'] ?? ''),
            'person_name' => (string)$row['nombre_snapshot'],
            'buk_employee_id' => (string)($row['buk_employee_id'] ?? ''),
            'cost_center_code' => (string)$row['codigo_ceco'],
            'cost_center_name' => (string)($row['nombre_ceco'] ?? ''),
            'active_at_month_end' => (bool)$row['vigente_cierre'],
            'first_active_date' => (string)$row['primer_dia_vigente'],
            'last_active_date' => (string)$row['ultimo_dia_vigente'],
            'calendar_days' => (int)$row['dias_calendario'],
            'workdays' => (int)$row['dias_habiles'],
            'active_dates' => array_values($dates),
            'job_ids' => array_values($jobIds),
        ];
    }
    if (count($rows) !== (int)$status['detail_rows']) {
        throw new RuntimeException('La fotografia aprobada no coincide con su total de detalle.');
    }
    return ['snapshot' => $status, 'rows' => $rows];
}

/**
 * Convierte el detalle congelado en las mismas entradas que consume el informe mensual.
 */
function fte_headcount_snapshot_report_inputs(array $approved, array $calendar): array
{
    $status = $approved['snapshot'] ?? [];
    $period = trim((string)($status['period'] ?? ''));
    if ((string)($status['status'] ?? '') !== 'APROBADO'
        || !preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $period, $match)) {
        throw new InvalidArgumentException('La fotografia entregada al informe no esta aprobada.');
    }
    $first = new DateTimeImmutable($period . '-01');
    $last = $first->modify('last day of this month');
    $monthEnd = $last->format('Y-m-d');
    $workdayByDate = [];
    foreach (($calendar['days'] ?? []) as $day) {
        if (is_array($day) && isset($day['date'])) {
            $workdayByDate[(string)$day['date']] = (float)($day['theoretical_hours'] ?? 0) > 0;
        }
    }

    $datePeople = [];
    $dateCecoPeople = [];
    $personDateCeco = [];
    $centerData = [];
    $peopleData = [];
    $uniqueAny = [];
    foreach (($approved['rows'] ?? []) as $rowIndex => $row) {
        if (!is_array($row)) {
            throw new RuntimeException('La fotografia aprobada contiene una fila invalida.');
        }
        $identifier = fte_normalize_identifier($row['normalized_identifier'] ?? $row['identifier'] ?? '');
        $code = fte_normalize_cost_center($row['cost_center_code'] ?? '');
        if ($identifier === '') {
            throw new RuntimeException('La fotografia aprobada contiene un trabajador sin identificador.');
        }
        if ($code === '') {
            $code = 'SIN_CECO';
        }
        $name = trim((string)($row['cost_center_name'] ?? ''));
        $dates = [];
        foreach (($row['active_dates'] ?? []) as $rawDate) {
            $date = fte_headcount_date($rawDate);
            if ($date !== null && str_starts_with($date, $period . '-')) {
                $dates[$date] = true;
            }
        }
        $dates = array_keys($dates);
        sort($dates, SORT_STRING);
        if (!$dates) {
            throw new RuntimeException('La fotografia aprobada contiene un trabajador sin dias vigentes.');
        }

        if (!isset($peopleData[$identifier])) {
            $peopleData[$identifier] = [
                'normalized_identifier' => $identifier,
                'identifier' => trim((string)($row['identifier'] ?? '')),
                'person_name' => trim((string)($row['person_name'] ?? '')) ?: $identifier,
                'buk_employee_id' => trim((string)($row['buk_employee_id'] ?? '')),
                'dates' => [],
                'jobs' => [],
            ];
        }
        if ($peopleData[$identifier]['identifier'] === '' && trim((string)($row['identifier'] ?? '')) !== '') {
            $peopleData[$identifier]['identifier'] = trim((string)$row['identifier']);
        }
        if ($peopleData[$identifier]['buk_employee_id'] === '' && trim((string)($row['buk_employee_id'] ?? '')) !== '') {
            $peopleData[$identifier]['buk_employee_id'] = trim((string)$row['buk_employee_id']);
        }

        $segmentStart = null;
        $segmentPrevious = null;
        foreach ($dates as $date) {
            if (isset($personDateCeco[$identifier][$date]) && $personDateCeco[$identifier][$date] !== $code) {
                throw new RuntimeException(sprintf(
                    'La fotografia aprobada asigna a %s a mas de un CECO el %s.',
                    $identifier,
                    $date
                ));
            }
            $personDateCeco[$identifier][$date] = $code;
            $datePeople[$date][$identifier] = true;
            $dateCecoPeople[$date][$code][$identifier] = true;
            $peopleData[$identifier]['dates'][$date] = true;
            $uniqueAny[$identifier] = true;
            if (!isset($centerData[$code])) {
                $centerData[$code] = [
                    'cost_center_code' => $code,
                    'cost_center_name' => $name,
                    'dates' => [],
                    'active_any_time_identifiers' => [],
                    'end_of_month_identifiers' => [],
                ];
            }
            if ($centerData[$code]['cost_center_name'] === '' && $name !== '') {
                $centerData[$code]['cost_center_name'] = $name;
            }
            $centerData[$code]['dates'][$date][$identifier] = true;
            $centerData[$code]['active_any_time_identifiers'][$identifier] = true;
            if ($date === $monthEnd) {
                $centerData[$code]['end_of_month_identifiers'][$identifier] = true;
            }

            if ($segmentStart === null) {
                $segmentStart = $date;
            } elseif ($segmentPrevious !== null
                && (new DateTimeImmutable($segmentPrevious))->modify('+1 day')->format('Y-m-d') !== $date) {
                $peopleData[$identifier]['jobs'][] = [
                    'job_id' => ($rowIndex + 1) . '-' . (count($peopleData[$identifier]['jobs']) + 1),
                    'start_date' => $segmentStart,
                    'end_date' => $segmentPrevious,
                    'cost_center_code' => $code,
                    'cost_center_name' => $name,
                ];
                $segmentStart = $date;
            }
            $segmentPrevious = $date;
        }
        if ($segmentStart !== null && $segmentPrevious !== null) {
            $peopleData[$identifier]['jobs'][] = [
                'job_id' => ($rowIndex + 1) . '-' . (count($peopleData[$identifier]['jobs']) + 1),
                'start_date' => $segmentStart,
                'end_date' => $segmentPrevious,
                'cost_center_code' => $code,
                'cost_center_name' => $name,
            ];
        }
    }

    $daily = [];
    $personCalendarDays = 0;
    $personWorkdays = 0;
    $unassignedPersonDays = 0;
    for ($date = $first; $date <= $last; $date = $date->modify('+1 day')) {
        $dateKey = $date->format('Y-m-d');
        $isWorkday = $workdayByDate[$dateKey] ?? ((int)$date->format('N') <= 5);
        $counts = [];
        foreach (($dateCecoPeople[$dateKey] ?? []) as $code => $identifiers) {
            $counts[$code] = count($identifiers);
            if ($code === 'SIN_CECO') {
                $unassignedPersonDays += count($identifiers);
            }
        }
        ksort($counts, SORT_NATURAL);
        $dayPeople = count($datePeople[$dateKey] ?? []);
        $personCalendarDays += $dayPeople;
        if ($isWorkday) {
            $personWorkdays += $dayPeople;
        }
        $daily[] = [
            'date' => $dateKey,
            'is_workday' => $isWorkday,
            'total_headcount' => $dayPeople,
            'headcount_by_cost_center' => $counts,
        ];
    }

    $calendarDayCount = count($daily);
    $workdayCount = count(array_filter($daily, static fn(array $day): bool => $day['is_workday']));
    $centers = [];
    foreach ($centerData as $code => $center) {
        $centerCalendarDays = 0;
        $centerWorkdays = 0;
        foreach ($center['dates'] as $date => $identifiers) {
            $count = count($identifiers);
            $centerCalendarDays += $count;
            if ($workdayByDate[$date] ?? ((int)(new DateTimeImmutable($date))->format('N') <= 5)) {
                $centerWorkdays += $count;
            }
        }
        $centers[] = [
            'cost_center_code' => $code,
            'cost_center_name' => $center['cost_center_name'],
            'person_calendar_days' => $centerCalendarDays,
            'person_workdays' => $centerWorkdays,
            'active_any_time_headcount' => count($center['active_any_time_identifiers']),
            'end_of_month_headcount' => count($center['end_of_month_identifiers']),
            'average_calendar_day_headcount' => $calendarDayCount > 0 ? $centerCalendarDays / $calendarDayCount : null,
            'average_workday_headcount' => $workdayCount > 0 ? $centerWorkdays / $workdayCount : null,
        ];
    }
    usort($centers, static fn(array $left, array $right): int => strnatcasecmp($left['cost_center_code'], $right['cost_center_code']));

    $people = [];
    foreach ($peopleData as $identifier => $person) {
        $dates = array_keys($person['dates']);
        sort($dates, SORT_STRING);
        usort($person['jobs'], static fn(array $left, array $right): int => strcmp($left['start_date'], $right['start_date']));
        $people[] = [
            'normalized_identifier' => $identifier,
            'identifier' => $person['identifier'],
            'person_name' => $person['person_name'],
            'buk_employee_id' => $person['buk_employee_id'],
            // Una fotografia antigua de dotacion conserva dias asignados al
            // CECO, no las fechas contractuales de ingreso y termino. Usar
            // esos extremos como fechas laborales inventaria movimientos.
            'active_since' => null,
            'active_until' => null,
            'employment_start_source' => 'Fotografia de dotacion sin fecha laboral',
            'employment_end_source' => 'Fotografia de dotacion sin fecha laboral',
            'employment_dates_verified' => false,
            'employment_periods' => [],
            'active' => in_array($monthEnd, $dates, true),
            'jobs' => $person['jobs'],
        ];
    }
    usort($people, static fn(array $left, array $right): int => strnatcasecmp($left['person_name'], $right['person_name']));

    if (count($uniqueAny) !== (int)($status['total_unique_people'] ?? -1)) {
        throw new RuntimeException('La fotografia aprobada no coincide con su total de personas unicas.');
    }
    if ($unassignedPersonDays !== (int)($status['unassigned_person_days'] ?? -1)) {
        throw new RuntimeException('La fotografia aprobada no coincide con su total de dias sin CECO.');
    }
    $lastDay = $daily ? $daily[count($daily) - 1] : ['total_headcount' => 0];
    return [
        'people' => $people,
        'headcount' => [
            'period' => $period,
            'month_start' => $first->format('Y-m-d'),
            'month_end' => $monthEnd,
            'unique_people_active_any_time' => count($uniqueAny),
            'end_of_month_headcount' => (int)$lastDay['total_headcount'],
            'average_calendar_day_headcount' => $calendarDayCount > 0 ? $personCalendarDays / $calendarDayCount : null,
            'average_workday_headcount' => $workdayCount > 0 ? $personWorkdays / $workdayCount : null,
            'person_calendar_days' => $personCalendarDays,
            'person_workdays' => $personWorkdays,
            'unassigned_person_days' => $unassignedPersonDays,
            'cost_centers' => $centers,
            'daily' => $daily,
            'warnings' => [],
            'available_headcount_rules' => [
                'end_of_month_headcount',
                'unique_people_active_any_time',
                'average_calendar_day_headcount',
                'average_workday_headcount',
            ],
        ],
    ];
}
