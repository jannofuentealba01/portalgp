<?php
declare(strict_types=1);

final class PoolDocumentosPeriodoService
{
    public static function isAvailable(PDO $conn): bool
    {
        return msp2TableExists($conn, 'msp_pool_documentos_periodo')
            && msp2TableExists($conn, 'msp_documentos_cobro');
    }

    public static function syncPeriodo(PDO $conn, string $periodoFacturacion): array
    {
        if (!self::isAvailable($conn)) {
            return [
                'disponible' => false,
                'pool_total' => 0,
                'pool_pendientes' => 0,
                'pool_documentados' => 0,
                'pool_loteados' => 0,
            ];
        }

        self::syncBase($conn, $periodoFacturacion);
        self::refreshReadiness($conn, $periodoFacturacion);
        self::bindDocumentos($conn, $periodoFacturacion);
        self::normalizeLoteStageConsistency($conn, $periodoFacturacion);

        return self::fetchPoolStats($conn, $periodoFacturacion);
    }

    public static function fetchPoolStats(PDO $conn, string $periodoFacturacion): array
    {
        if (!self::isAvailable($conn)) {
            return [
                'disponible' => false,
                'pool_total' => 0,
                'pool_pendientes' => 0,
                'pool_documentados' => 0,
                'pool_loteados' => 0,
            ];
        }

        $stmt = $conn->prepare(
            "SELECT
                COUNT(*) AS pool_total,
                SUM(CASE WHEN estado_pool IN (1,2) THEN 1 ELSE 0 END) AS pool_pendientes,
                SUM(CASE WHEN estado_pool = 3 THEN 1 ELSE 0 END) AS pool_documentados,
                SUM(CASE WHEN estado_pool = 4 THEN 1 ELSE 0 END) AS pool_loteados
             FROM dbo.msp_pool_documentos_periodo
             WHERE periodo_facturacion = :periodo"
        );
        $stmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch() ?: [];

        return [
            'disponible' => true,
            'pool_total' => (int) ($row['pool_total'] ?? 0),
            'pool_pendientes' => (int) ($row['pool_pendientes'] ?? 0),
            'pool_documentados' => (int) ($row['pool_documentados'] ?? 0),
            'pool_loteados' => (int) ($row['pool_loteados'] ?? 0),
        ];
    }

    public static function fetchSegmentationDiagnostics(PDO $conn, string $periodoFacturacion): array
    {
        if (!self::isAvailable($conn)) {
            return [
                'disponible' => false,
                'combinaciones' => [],
                'etapas' => [],
                'pendientes_pool' => [],
            ];
        }

        $combStmt = $conn->prepare(
            "SELECT
                CASE
                    WHEN p.requiere_luz = 1 AND p.requiere_gas = 1 AND p.requiere_agua = 1 THEN N'LUZ+GAS+AGUA'
                    WHEN p.requiere_luz = 1 AND p.requiere_gas = 1 AND p.requiere_agua = 0 THEN N'LUZ+GAS'
                    WHEN p.requiere_luz = 1 AND p.requiere_gas = 0 AND p.requiere_agua = 1 THEN N'LUZ+AGUA'
                    WHEN p.requiere_luz = 0 AND p.requiere_gas = 1 AND p.requiere_agua = 1 THEN N'GAS+AGUA'
                    WHEN p.requiere_luz = 1 AND p.requiere_gas = 0 AND p.requiere_agua = 0 THEN N'LUZ'
                    WHEN p.requiere_luz = 0 AND p.requiere_gas = 1 AND p.requiere_agua = 0 THEN N'GAS'
                    WHEN p.requiere_luz = 0 AND p.requiere_gas = 0 AND p.requiere_agua = 1 THEN N'AGUA'
                    ELSE N'SIN_SERVICIO'
                END AS combinacion,
                COUNT(*) AS total,
                SUM(CASE WHEN p.id_documento_cobro IS NOT NULL THEN 1 ELSE 0 END) AS documentados,
                SUM(CASE WHEN p.estado_pool = 4 THEN 1 ELSE 0 END) AS loteados,
                SUM(CASE WHEN p.ready_luz = 1 THEN 1 ELSE 0 END) AS ready_luz,
                SUM(CASE WHEN p.ready_gas = 1 THEN 1 ELSE 0 END) AS ready_gas,
                SUM(CASE WHEN p.ready_agua = 1 THEN 1 ELSE 0 END) AS ready_agua
             FROM dbo.msp_pool_documentos_periodo p
             WHERE p.periodo_facturacion = :periodo
             GROUP BY
                CASE
                    WHEN p.requiere_luz = 1 AND p.requiere_gas = 1 AND p.requiere_agua = 1 THEN N'LUZ+GAS+AGUA'
                    WHEN p.requiere_luz = 1 AND p.requiere_gas = 1 AND p.requiere_agua = 0 THEN N'LUZ+GAS'
                    WHEN p.requiere_luz = 1 AND p.requiere_gas = 0 AND p.requiere_agua = 1 THEN N'LUZ+AGUA'
                    WHEN p.requiere_luz = 0 AND p.requiere_gas = 1 AND p.requiere_agua = 1 THEN N'GAS+AGUA'
                    WHEN p.requiere_luz = 1 AND p.requiere_gas = 0 AND p.requiere_agua = 0 THEN N'LUZ'
                    WHEN p.requiere_luz = 0 AND p.requiere_gas = 1 AND p.requiere_agua = 0 THEN N'GAS'
                    WHEN p.requiere_luz = 0 AND p.requiere_gas = 0 AND p.requiere_agua = 1 THEN N'AGUA'
                    ELSE N'SIN_SERVICIO'
                END
             ORDER BY total DESC, combinacion ASC"
        );
        $combStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $combStmt->execute();
        $combinaciones = $combStmt->fetchAll() ?: [];

        $stageStmt = $conn->prepare(
            "SELECT
                etapa,
                COUNT(*) AS total,
                SUM(CASE WHEN ready_target = 1 THEN 1 ELSE 0 END) AS ready,
                SUM(CASE WHEN ready_target = 1 AND id_documento_cobro IS NOT NULL THEN 1 ELSE 0 END) AS ready_documentados,
                SUM(CASE WHEN ready_target = 1 AND estado_pool = 4 THEN 1 ELSE 0 END) AS ready_loteados
             FROM (
                SELECT
                    p.id_pool_documento,
                    p.id_documento_cobro,
                    p.estado_pool,
                    CASE
                        WHEN p.requiere_agua = 1 THEN N'AGUA'
                        WHEN p.requiere_gas = 1 THEN N'GAS'
                        WHEN p.requiere_luz = 1 THEN N'LUZ'
                        ELSE N'OTROS'
                    END AS etapa,
                    CASE
                        WHEN p.requiere_agua = 1 THEN p.ready_agua
                        WHEN p.requiere_gas = 1 THEN p.ready_gas
                        WHEN p.requiere_luz = 1 THEN p.ready_luz
                        ELSE CASE WHEN p.id_documento_cobro IS NOT NULL THEN 1 ELSE 0 END
                    END AS ready_target
                FROM dbo.msp_pool_documentos_periodo p
                WHERE p.periodo_facturacion = :periodo_stage
             ) x
             GROUP BY etapa
             ORDER BY CASE etapa WHEN N'LUZ' THEN 1 WHEN N'GAS' THEN 2 WHEN N'AGUA' THEN 3 ELSE 9 END"
        );
        $stageStmt->bindValue(':periodo_stage', $periodoFacturacion, PDO::PARAM_STR);
        $stageStmt->execute();
        $etapas = $stageStmt->fetchAll() ?: [];

        $pendientesStmt = $conn->prepare(
            "SELECT TOP 400
                p.id_tienda,
                a.id_arrendatario,
                COALESCE(
                    NULLIF(LTRIM(RTRIM(a.nombre_locatario)), ''),
                    NULLIF(LTRIM(RTRIM(a.nombre_representante)), ''),
                    NULLIF(LTRIM(RTRIM(a.rut)), ''),
                    CONCAT('Arrendatario #', a.id_arrendatario)
                ) AS nombre_arrendatario,
                LTRIM(RTRIM(a.rut)) AS rut_arrendatario,
                NULLIF(LTRIM(RTRIM(t.nombre_comercial)), '') AS nombre_tienda,
                MAX(CASE WHEN p.requiere_luz = 1 THEN 1 ELSE 0 END) AS requiere_luz,
                MAX(CASE WHEN p.requiere_gas = 1 THEN 1 ELSE 0 END) AS requiere_gas,
                MAX(CASE WHEN p.requiere_agua = 1 THEN 1 ELSE 0 END) AS requiere_agua,
                MAX(CASE WHEN p.ready_luz = 1 THEN 1 ELSE 0 END) AS ready_luz,
                MAX(CASE WHEN p.ready_gas = 1 THEN 1 ELSE 0 END) AS ready_gas,
                MAX(CASE WHEN p.ready_agua = 1 THEN 1 ELSE 0 END) AS ready_agua,
                COUNT(*) AS total_pool_rows,
                SUM(CASE WHEN p.id_documento_cobro IS NOT NULL THEN 1 ELSE 0 END) AS total_documentados
             FROM dbo.msp_pool_documentos_periodo p
             INNER JOIN dbo.msp_tiendas t
                ON t.id_tienda = p.id_tienda
             LEFT JOIN dbo.msp_contratos_arriendo ca
                ON ca.id_contrato_arriendo = p.id_contrato_arriendo
             LEFT JOIN dbo.msp_arrendatarios a
                ON a.id_arrendatario = COALESCE(ca.id_arrendatario, t.id_arrendatario)
             WHERE p.periodo_facturacion = :periodo_pend
             GROUP BY
                p.id_tienda,
                a.id_arrendatario,
                a.nombre_locatario,
                a.nombre_representante,
                a.rut,
                t.nombre_comercial
             HAVING
                MAX(CASE WHEN p.requiere_luz = 1 AND p.ready_luz <> 1 THEN 1 ELSE 0 END) = 1
                OR MAX(CASE WHEN p.requiere_gas = 1 AND p.ready_gas <> 1 THEN 1 ELSE 0 END) = 1
                OR MAX(CASE WHEN p.requiere_agua = 1 AND p.ready_agua <> 1 THEN 1 ELSE 0 END) = 1
             ORDER BY
                nombre_arrendatario ASC,
                nombre_tienda ASC,
                p.id_tienda ASC"
        );
        $pendientesStmt->bindValue(':periodo_pend', $periodoFacturacion, PDO::PARAM_STR);
        $pendientesStmt->execute();
        $pendientesRaw = $pendientesStmt->fetchAll() ?: [];

        $pendientesPool = [];
        $tiendaIdsPendientes = [];
        foreach ($pendientesRaw as $rowPend) {
            $idTienda = (int) ($rowPend['id_tienda'] ?? 0);
            if ($idTienda <= 0) {
                continue;
            }
            $tiendaIdsPendientes[$idTienda] = true;

            $requiereLuz = (int) ($rowPend['requiere_luz'] ?? 0) === 1;
            $requiereGas = (int) ($rowPend['requiere_gas'] ?? 0) === 1;
            $requiereAgua = (int) ($rowPend['requiere_agua'] ?? 0) === 1;
            $readyLuz = (int) ($rowPend['ready_luz'] ?? 0) === 1;
            $readyGas = (int) ($rowPend['ready_gas'] ?? 0) === 1;
            $readyAgua = (int) ($rowPend['ready_agua'] ?? 0) === 1;

            $servicios = [];
            if ($requiereLuz) {
                $servicios[] = 'LUZ';
            }
            if ($requiereGas) {
                $servicios[] = 'GAS';
            }
            if ($requiereAgua) {
                $servicios[] = 'AGUA';
            }

            $faltantes = [];
            if ($requiereLuz && !$readyLuz) {
                $faltantes[] = 'LUZ';
            }
            if ($requiereGas && !$readyGas) {
                $faltantes[] = 'GAS';
            }
            if ($requiereAgua && !$readyAgua) {
                $faltantes[] = 'AGUA';
            }

            $pendientesPool[] = [
                'id_tienda' => $idTienda,
                'id_arrendatario' => (int) ($rowPend['id_arrendatario'] ?? 0),
                'nombre_arrendatario' => (string) ($rowPend['nombre_arrendatario'] ?? ''),
                'rut_arrendatario' => trim((string) ($rowPend['rut_arrendatario'] ?? '')),
                'nombre_tienda' => (string) ($rowPend['nombre_tienda'] ?? ''),
                'combinacion' => $servicios === [] ? 'SIN_SERVICIO' : implode('+', $servicios),
                'faltantes' => $faltantes,
                'total_pool_rows' => (int) ($rowPend['total_pool_rows'] ?? 0),
                'total_documentados' => (int) ($rowPend['total_documentados'] ?? 0),
                'locales' => [],
            ];
        }

        if ($tiendaIdsPendientes !== []) {
            $tiendaIds = array_map('intval', array_keys($tiendaIdsPendientes));
            sort($tiendaIds, SORT_NUMERIC);
            $placeholders = [];
            foreach ($tiendaIds as $idx => $idTiendaPend) {
                $placeholders[] = ':id_tienda_' . $idx;
            }

            $sqlLocales = "DECLARE @periodo DATE = :periodo_locales;
                SELECT
                    ca.id_tienda,
                    l.cdo_local
                FROM dbo.msp_contrato_locales cl
                INNER JOIN dbo.msp_contratos_arriendo ca
                    ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
                INNER JOIN dbo.msp_locales l
                    ON l.id_local = cl.id_local
                WHERE ca.id_tienda IN (" . implode(', ', $placeholders) . ")
                  AND cl.estado_relacion IN (1,2)
                  AND cl.fecha_inicio <= EOMONTH(@periodo)
                  AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= @periodo)
                  AND ca.fecha_inicio <= EOMONTH(@periodo)
                  AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= @periodo)
                  AND ca.estado_contrato IN (1,2,3)
                ORDER BY ca.id_tienda ASC, " . msp2LocalCodeNaturalOrderSql('l.cdo_local');
            $localesStmt = $conn->prepare($sqlLocales);
            $localesStmt->bindValue(':periodo_locales', $periodoFacturacion, PDO::PARAM_STR);
            foreach ($tiendaIds as $idx => $idTiendaPend) {
                $localesStmt->bindValue(':id_tienda_' . $idx, $idTiendaPend, PDO::PARAM_INT);
            }
            $localesStmt->execute();

            $localesByTienda = [];
            while (($rowLoc = $localesStmt->fetch()) !== false) {
                $idTiendaLoc = (int) ($rowLoc['id_tienda'] ?? 0);
                $codLocal = msp2NormalizeLocalCode((string) ($rowLoc['cdo_local'] ?? ''));
                if ($idTiendaLoc <= 0 || $codLocal === '') {
                    continue;
                }
                if (!isset($localesByTienda[$idTiendaLoc])) {
                    $localesByTienda[$idTiendaLoc] = [];
                }
                $localesByTienda[$idTiendaLoc][$codLocal] = true;
            }

            foreach ($pendientesPool as $idx => $pendRow) {
                $idTienda = (int) ($pendRow['id_tienda'] ?? 0);
                if ($idTienda <= 0 || !isset($localesByTienda[$idTienda])) {
                    continue;
                }
                $locales = array_keys($localesByTienda[$idTienda]);
                usort($locales, static fn (string $a, string $b): int => msp2CompareLocalCode($a, $b));
                $pendientesPool[$idx]['locales'] = $locales;
            }
        }

        usort($pendientesPool, static function (array $a, array $b): int {
            $localesA = is_array($a['locales'] ?? null) ? $a['locales'] : [];
            $localesB = is_array($b['locales'] ?? null) ? $b['locales'] : [];
            $firstA = msp2NormalizeLocalCode((string) ($localesA[0] ?? ''));
            $firstB = msp2NormalizeLocalCode((string) ($localesB[0] ?? ''));

            if ($firstA !== '' && $firstB !== '') {
                $cmp = msp2CompareLocalCode($firstA, $firstB);
                if ($cmp !== 0) {
                    return $cmp;
                }
            } elseif ($firstA !== '') {
                return -1;
            } elseif ($firstB !== '') {
                return 1;
            }

            $cmpArr = strcasecmp((string) ($a['nombre_arrendatario'] ?? ''), (string) ($b['nombre_arrendatario'] ?? ''));
            if ($cmpArr !== 0) {
                return $cmpArr;
            }

            return ((int) ($a['id_tienda'] ?? 0)) <=> ((int) ($b['id_tienda'] ?? 0));
        });

        return [
            'disponible' => true,
            'combinaciones' => $combinaciones,
            'etapas' => $etapas,
            'pendientes_pool' => $pendientesPool,
        ];
    }

    public static function fetchSummaryByStage(PDO $conn, string $periodoFacturacion, string $etapa): array
    {
        $etapa = strtoupper(trim($etapa));
        if (!in_array($etapa, ['LUZ', 'GAS', 'AGUA'], true)) {
            return [
                'etapa' => $etapa,
                'arrendatarios' => 0,
                'documentos' => 0,
                'tiene_candidatos' => false,
            ];
        }

        $candidatos = self::fetchCandidatesByStage($conn, $periodoFacturacion, $etapa);
        $arrendatarios = count($candidatos);
        $documentos = 0;
        foreach ($candidatos as $cand) {
            $docs = is_array($cand['docs'] ?? null) ? $cand['docs'] : [];
            $documentos += count($docs);
        }

        return [
            'etapa' => $etapa,
            'arrendatarios' => $arrendatarios,
            'documentos' => $documentos,
            'tiene_candidatos' => $arrendatarios > 0 && $documentos > 0,
        ];
    }

    public static function fetchCandidatesByStage(PDO $conn, string $periodoFacturacion, string $etapa): array
    {
        if (!self::isAvailable($conn)) {
            return [];
        }

        $etapa = strtoupper(trim($etapa));
        if (!in_array($etapa, ['LUZ', 'GAS', 'AGUA'], true)) {
            return [];
        }

        $correoTableExiste = msp2TableExists($conn, 'msp_arrendatarios_correos');
        $correoSelect = $correoTableExiste
            ? 'MAX(CASE WHEN ac.es_principal = 1 THEN ac.correo END) AS correo_principal'
            : "'' AS correo_principal";
        $correoJoin = $correoTableExiste
            ? 'LEFT JOIN dbo.msp_arrendatarios_correos ac ON ac.id_arrendatario = a.id_arrendatario'
            : '';

        $readyPredicate = match ($etapa) {
            'LUZ' => '(p.ready_luz = 1 AND p.requiere_luz = 1 AND p.requiere_gas = 0 AND p.requiere_agua = 0)',
            'GAS' => '(p.ready_gas = 1 AND p.requiere_gas = 1 AND p.requiere_agua = 0)',
            'AGUA' => '(p.ready_agua = 1 AND p.requiere_agua = 1)',
            default => '1 = 0',
        };

        $sql = "SELECT
                a.id_arrendatario,
                COALESCE(
                    NULLIF(LTRIM(RTRIM(a.nombre_locatario)), ''),
                    NULLIF(LTRIM(RTRIM(a.nombre_representante)), ''),
                    NULLIF(LTRIM(RTRIM(a.rut)), ''),
                    CONCAT('Arrendatario #', a.id_arrendatario)
                ) AS nombre_arrendatario,
                LTRIM(RTRIM(a.rut)) AS rut,
                $correoSelect,
                p.id_documento_cobro
            FROM dbo.msp_pool_documentos_periodo p
            INNER JOIN dbo.msp_tiendas t
                ON t.id_tienda = p.id_tienda
            INNER JOIN dbo.msp_documentos_cobro dc
                ON dc.id_documento_cobro = p.id_documento_cobro
            LEFT JOIN dbo.msp_contratos_arriendo ca_doc
                ON ca_doc.id_contrato_arriendo = dc.id_contrato_arriendo
            INNER JOIN dbo.msp_arrendatarios a
                ON a.id_arrendatario = COALESCE(ca_doc.id_arrendatario, t.id_arrendatario)
            LEFT JOIN dbo.msp_envio_lote_documentos eld
                ON eld.id_documento_cobro = p.id_documento_cobro
            LEFT JOIN dbo.msp_envio_lote_destinatarios ed
                ON ed.id_lote_destinatario = eld.id_lote_destinatario
            LEFT JOIN dbo.msp_envio_lotes_programados el
                ON el.id_lote_envio = ed.id_lote_envio
               AND el.periodo_facturacion = :periodo_lote
               AND el.estado_lote <> :estado_cancelado
            $correoJoin
            WHERE p.periodo_facturacion = :periodo_pool
              AND p.estado_pool IN (1,2,3)
              AND p.id_documento_cobro IS NOT NULL
              AND el.id_lote_envio IS NULL
              AND dc.estado_documento <> 5
              AND ($readyPredicate)
            GROUP BY
                a.id_arrendatario,
                a.nombre_locatario,
                a.nombre_representante,
                a.rut,
                p.id_documento_cobro
            ORDER BY nombre_arrendatario ASC, p.id_documento_cobro ASC";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':periodo_lote', $periodoFacturacion, PDO::PARAM_STR);
        $stmt->bindValue(':periodo_pool', $periodoFacturacion, PDO::PARAM_STR);
        $stmt->bindValue(':estado_cancelado', 5, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];
        if ($rows === []) {
            return [];
        }

        $byArr = [];
        foreach ($rows as $row) {
            $arrId = (int) ($row['id_arrendatario'] ?? 0);
            $docId = (int) ($row['id_documento_cobro'] ?? 0);
            if ($arrId <= 0 || $docId <= 0) {
                continue;
            }

            if (!isset($byArr[$arrId])) {
                $byArr[$arrId] = [
                    'id_arrendatario' => $arrId,
                    'nombre_arrendatario' => (string) ($row['nombre_arrendatario'] ?? ''),
                    'rut' => (string) ($row['rut'] ?? ''),
                    'correo_principal' => trim((string) ($row['correo_principal'] ?? '')),
                    'docs' => [],
                ];
            }

            if (!in_array($docId, $byArr[$arrId]['docs'], true)) {
                $byArr[$arrId]['docs'][] = $docId;
            }
        }

        return array_values($byArr);
    }

    /**
     * @return int[]
     */
    public static function fetchTargetTiendasForStageMaterialization(PDO $conn, string $periodoFacturacion, string $etapa): array
    {
        if (!self::isAvailable($conn)) {
            return [];
        }

        $etapa = strtoupper(trim($etapa));
        if (!in_array($etapa, ['LUZ', 'GAS', 'AGUA'], true)) {
            return [];
        }

        $readyPredicate = match ($etapa) {
            'LUZ' => '(p.ready_luz = 1 AND p.requiere_luz = 1 AND p.requiere_gas = 0 AND p.requiere_agua = 0)',
            'GAS' => '(p.ready_gas = 1 AND p.requiere_gas = 1 AND p.requiere_agua = 0)',
            'AGUA' => '(p.ready_agua = 1 AND p.requiere_agua = 1)',
            default => '1 = 0',
        };

        $stmt = $conn->prepare(
            "SELECT DISTINCT p.id_tienda
             FROM dbo.msp_pool_documentos_periodo p
             WHERE p.periodo_facturacion = :periodo
               AND p.estado_pool IN (1,2,3)
               AND ($readyPredicate)
             ORDER BY p.id_tienda ASC"
        );
        $stmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];

        $ids = [];
        foreach ($rows as $value) {
            $idTienda = (int) $value;
            if ($idTienda > 0) {
                $ids[$idTienda] = true;
            }
        }
        $result = array_map('intval', array_keys($ids));
        sort($result, SORT_NUMERIC);
        return $result;
    }

    /**
     * @return int[]
     */
    public static function fetchTargetTiendasSinServicio(PDO $conn, string $periodoFacturacion): array
    {
        if (!self::isAvailable($conn)) {
            return [];
        }

        $stmt = $conn->prepare(
            "SELECT DISTINCT p.id_tienda
             FROM dbo.msp_pool_documentos_periodo p
             WHERE p.periodo_facturacion = :periodo
               AND p.estado_pool IN (1,2,3)
               AND p.requiere_luz = 0
               AND p.requiere_gas = 0
               AND p.requiere_agua = 0
             ORDER BY p.id_tienda ASC"
        );
        $stmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];

        $ids = [];
        foreach ($rows as $value) {
            $idTienda = (int) $value;
            if ($idTienda > 0) {
                $ids[$idTienda] = true;
            }
        }
        $result = array_map('intval', array_keys($ids));
        sort($result, SORT_NUMERIC);
        return $result;
    }

    public static function fetchSinServicioStats(PDO $conn, string $periodoFacturacion): array
    {
        $base = [
            'disponible' => false,
            'tiendas_objetivo' => 0,
            'tiendas_documentadas' => 0,
            'tiendas_pendientes' => 0,
        ];
        if (!self::isAvailable($conn)) {
            return $base;
        }

        $stmt = $conn->prepare(
            "SELECT
                COUNT(DISTINCT p.id_tienda) AS tiendas_objetivo,
                COUNT(DISTINCT CASE WHEN p.id_documento_cobro IS NOT NULL THEN p.id_tienda END) AS tiendas_documentadas
             FROM dbo.msp_pool_documentos_periodo p
             WHERE p.periodo_facturacion = :periodo
               AND p.estado_pool IN (1,2,3)
               AND p.requiere_luz = 0
               AND p.requiere_gas = 0
               AND p.requiere_agua = 0"
        );
        $stmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch() ?: [];

        $target = (int) ($row['tiendas_objetivo'] ?? 0);
        $documentadas = (int) ($row['tiendas_documentadas'] ?? 0);
        return [
            'disponible' => true,
            'tiendas_objetivo' => $target,
            'tiendas_documentadas' => $documentadas,
            'tiendas_pendientes' => max(0, $target - $documentadas),
        ];
    }

    public static function markLoteadoByLote(PDO $conn, int $idLote): int
    {
        if ($idLote <= 0 || !self::isAvailable($conn)) {
            return 0;
        }

        $stmt = $conn->prepare(
            'UPDATE p
             SET
                p.estado_pool = 4,
                p.id_lote_envio_ultimo = :id_lote_set,
                p.updated_at = SYSDATETIME()
             FROM dbo.msp_pool_documentos_periodo p
             INNER JOIN dbo.msp_envio_lote_documentos eld
                ON eld.id_documento_cobro = p.id_documento_cobro
             INNER JOIN dbo.msp_envio_lote_destinatarios d
                ON d.id_lote_destinatario = eld.id_lote_destinatario
             WHERE d.id_lote_envio = :id_lote_filter'
        );
        $stmt->bindValue(':id_lote_set', $idLote, PDO::PARAM_INT);
        $stmt->bindValue(':id_lote_filter', $idLote, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->rowCount();
    }

    public static function releaseLoteByLote(PDO $conn, int $idLote): int
    {
        if ($idLote <= 0 || !self::isAvailable($conn)) {
            return 0;
        }

        $stmt = $conn->prepare(
            'UPDATE p
             SET
                p.estado_pool = CASE WHEN p.id_documento_cobro IS NOT NULL THEN 3 ELSE 2 END,
                p.id_lote_envio_ultimo = NULL,
                p.updated_at = SYSDATETIME()
             FROM dbo.msp_pool_documentos_periodo p
             WHERE p.id_lote_envio_ultimo = :id_lote
               AND p.estado_pool = 4'
        );
        $stmt->bindValue(':id_lote', $idLote, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->rowCount();
    }

    public static function releaseLoteByPeriodo(PDO $conn, string $periodoFacturacion): int
    {
        if (!self::isAvailable($conn)) {
            return 0;
        }

        $stmt = $conn->prepare(
            'UPDATE p
             SET
                p.estado_pool = CASE WHEN p.id_documento_cobro IS NOT NULL THEN 3 ELSE 2 END,
                p.id_lote_envio_ultimo = NULL,
                p.updated_at = SYSDATETIME()
             FROM dbo.msp_pool_documentos_periodo p
             WHERE p.periodo_facturacion = :periodo
               AND p.estado_pool = 4'
        );
        $stmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $stmt->execute();
        return (int) $stmt->rowCount();
    }

    private static function syncBase(PDO $conn, string $periodoFacturacion): void
    {
        $stmt = $conn->prepare(
            "DECLARE @periodo DATE = :periodo;
             ;WITH contratos_periodo AS (
                SELECT
                    ca.id_tienda,
                    ca.id_contrato_arriendo,
                    CASE
                        WHEN ca.fecha_termino_efectiva IS NOT NULL
                         AND ca.fecha_termino_efectiva < @periodo
                         AND DATEADD(MONTH, 1, DATEFROMPARTS(YEAR(ca.fecha_termino_efectiva), MONTH(ca.fecha_termino_efectiva), 1)) = @periodo
                        THEN 1
                        ELSE 0
                    END AS es_liquidacion
                FROM dbo.msp_contratos_arriendo ca
                WHERE ca.fecha_inicio <= EOMONTH(@periodo)
                  AND (
                        ca.fecha_termino_efectiva IS NULL
                        OR ca.fecha_termino_efectiva >= @periodo
                        OR DATEADD(MONTH, 1, DATEFROMPARTS(YEAR(ca.fecha_termino_efectiva), MONTH(ca.fecha_termino_efectiva), 1)) = @periodo
                  )
                  AND ca.estado_contrato IN (1,2,3,4)
             ),
             base AS (
                SELECT
                    @periodo AS periodo_facturacion,
                    cp.id_tienda,
                    cp.id_contrato_arriendo,
                    MAX(CASE WHEN UPPER(ts.codigo_servicio) = N'LUZ' THEN 1 ELSE 0 END) AS requiere_luz,
                    MAX(CASE WHEN UPPER(ts.codigo_servicio) = N'GAS' THEN 1 ELSE 0 END) AS requiere_gas,
                    MAX(CASE WHEN UPPER(ts.codigo_servicio) = N'AGUA' THEN 1 ELSE 0 END) AS requiere_agua
                FROM contratos_periodo cp
                LEFT JOIN dbo.msp_contrato_locales cl
                    ON cl.id_contrato_arriendo = cp.id_contrato_arriendo
                   AND cl.estado_relacion IN (1,2)
                   AND cl.fecha_inicio <= EOMONTH(@periodo)
                   AND (
                        cl.fecha_termino IS NULL
                        OR cl.fecha_termino >= @periodo
                        OR cp.es_liquidacion = 1
                   )
                LEFT JOIN dbo.msp_medidores m
                    ON m.id_local = cl.id_local
                   AND m.estado_medidor IN (1,2)
                   AND (m.fecha_instalacion IS NULL OR m.fecha_instalacion <= EOMONTH(@periodo))
                   AND (m.fecha_retiro IS NULL OR m.fecha_retiro >= DATEADD(MONTH, -1, @periodo))
                LEFT JOIN dbo.msp_tipos_servicio ts
                    ON ts.id_tipo_servicio = m.id_tipo_servicio
                GROUP BY cp.id_tienda, cp.id_contrato_arriendo
             )
             MERGE dbo.msp_pool_documentos_periodo AS target
             USING (
                SELECT
                    b.periodo_facturacion,
                    b.id_tienda,
                    b.id_contrato_arriendo,
                    CAST(ISNULL(b.requiere_luz, 0) AS BIT) AS requiere_luz,
                    CAST(ISNULL(b.requiere_gas, 0) AS BIT) AS requiere_gas,
                    CAST(ISNULL(b.requiere_agua, 0) AS BIT) AS requiere_agua,
                    CASE
                        WHEN ISNULL(b.requiere_luz, 0) = 1 AND ISNULL(b.requiere_gas, 0) = 1 AND ISNULL(b.requiere_agua, 0) = 1 THEN N'LUZ_GAS_AGUA'
                        WHEN ISNULL(b.requiere_luz, 0) = 1 AND ISNULL(b.requiere_gas, 0) = 1 THEN N'LUZ_GAS'
                        WHEN ISNULL(b.requiere_luz, 0) = 1 AND ISNULL(b.requiere_agua, 0) = 1 THEN N'LUZ_AGUA'
                        ELSE N'LUZ'
                    END AS perfil_servicios
                FROM base b
             ) AS source
             ON target.periodo_facturacion = source.periodo_facturacion
             AND target.id_tienda = source.id_tienda
             AND target.id_contrato_arriendo = source.id_contrato_arriendo
             WHEN MATCHED THEN
                UPDATE SET
                    target.requiere_luz = source.requiere_luz,
                    target.requiere_gas = source.requiere_gas,
                    target.requiere_agua = source.requiere_agua,
                    target.perfil_servicios = source.perfil_servicios,
                    target.estado_pool = CASE WHEN target.estado_pool = 5 THEN 1 ELSE target.estado_pool END,
                    target.updated_at = SYSDATETIME()
             WHEN NOT MATCHED BY TARGET THEN
                INSERT (
                    periodo_facturacion,
                    id_tienda,
                    id_contrato_arriendo,
                    estado_pool,
                    perfil_servicios,
                    requiere_luz,
                    requiere_gas,
                    requiere_agua,
                    motivo_pendiente,
                    created_at,
                    updated_at
                )
                VALUES (
                    source.periodo_facturacion,
                    source.id_tienda,
                    source.id_contrato_arriendo,
                    1,
                    source.perfil_servicios,
                    source.requiere_luz,
                    source.requiere_gas,
                    source.requiere_agua,
                    N'Pendiente de generación de cobros/lecturas para etapa.',
                    SYSDATETIME(),
                    SYSDATETIME()
                )
             WHEN NOT MATCHED BY SOURCE AND target.periodo_facturacion = @periodo AND target.estado_pool IN (1,2,3) THEN
                UPDATE SET
                    target.estado_pool = 5,
                    target.motivo_pendiente = N'Fuera del universo activo del periodo.',
                    target.updated_at = SYSDATETIME();"
        );
        $stmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $stmt->execute();
    }

    private static function refreshReadiness(PDO $conn, string $periodoFacturacion): void
    {
        $stmt = $conn->prepare(
            "DECLARE @periodo DATE = :periodo;
             ;WITH lecturas_por_tienda AS (
                SELECT
                    map.id_tienda,
                    map.id_contrato_arriendo,
                    MAX(CASE WHEN UPPER(ts.codigo_servicio) = N'LUZ' THEN 1 ELSE 0 END) AS tiene_luz,
                    MAX(CASE WHEN UPPER(ts.codigo_servicio) = N'GAS' THEN 1 ELSE 0 END) AS tiene_gas,
                    MAX(CASE WHEN UPPER(ts.codigo_servicio) = N'AGUA' THEN 1 ELSE 0 END) AS tiene_agua
                FROM dbo.msp_cobros_servicios cs
                INNER JOIN dbo.msp_lecturas_medidores lm
                    ON lm.id_lectura = cs.id_lectura
                INNER JOIN dbo.msp_procesos_cobro_servicio p
                    ON p.id_proceso_cobro = lm.id_proceso_cobro
                INNER JOIN dbo.msp_cierre_mensual cm
                    ON cm.id_cierre_mensual = p.id_cierre_mensual
                INNER JOIN dbo.msp_tipos_servicio ts
                    ON ts.id_tipo_servicio = p.id_tipo_servicio
                INNER JOIN dbo.msp_medidores m
                    ON m.id_medidor = lm.id_medidor
                OUTER APPLY (
                    SELECT TOP (1)
                        ca.id_tienda,
                        ca.id_contrato_arriendo,
                        CASE
                            WHEN ca.fecha_termino_efectiva IS NOT NULL
                             AND ca.fecha_termino_efectiva < @periodo
                             AND DATEADD(MONTH, 1, DATEFROMPARTS(YEAR(ca.fecha_termino_efectiva), MONTH(ca.fecha_termino_efectiva), 1)) = @periodo
                            THEN 1
                            ELSE 0
                        END AS es_liquidacion
                    FROM dbo.msp_contrato_locales cl
                    INNER JOIN dbo.msp_contratos_arriendo ca
                        ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
                    WHERE cl.id_local = m.id_local
                      AND cl.estado_relacion IN (1,2)
                      AND cl.fecha_inicio <= EOMONTH(@periodo)
                      AND (
                            cl.fecha_termino IS NULL
                            OR cl.fecha_termino >= @periodo
                            OR DATEADD(MONTH, 1, DATEFROMPARTS(YEAR(ISNULL(cl.fecha_termino, ca.fecha_termino_efectiva)), MONTH(ISNULL(cl.fecha_termino, ca.fecha_termino_efectiva)), 1)) = @periodo
                      )
                      AND ca.fecha_inicio <= EOMONTH(@periodo)
                      AND (
                            ca.fecha_termino_efectiva IS NULL
                            OR ca.fecha_termino_efectiva >= @periodo
                            OR DATEADD(MONTH, 1, DATEFROMPARTS(YEAR(ca.fecha_termino_efectiva), MONTH(ca.fecha_termino_efectiva), 1)) = @periodo
                      )
                      AND ca.estado_contrato IN (1,2,3,4)
                    ORDER BY
                        CASE
                            WHEN ca.fecha_termino_efectiva IS NOT NULL
                             AND ca.fecha_termino_efectiva < @periodo
                             AND DATEADD(MONTH, 1, DATEFROMPARTS(YEAR(ca.fecha_termino_efectiva), MONTH(ca.fecha_termino_efectiva), 1)) = @periodo
                            THEN 0
                            ELSE 1
                        END,
                        ca.fecha_inicio DESC,
                        ca.id_contrato_arriendo DESC
                ) map
                WHERE cm.periodo_facturacion = @periodo
                  AND p.estado_proceso <> 4
                  AND map.id_tienda IS NOT NULL
                GROUP BY map.id_tienda, map.id_contrato_arriendo
             )
             UPDATE p
             SET
                p.tiene_luz = CAST(ISNULL(lpt.tiene_luz, 0) AS BIT),
                p.tiene_gas = CAST(ISNULL(lpt.tiene_gas, 0) AS BIT),
                p.tiene_agua = CAST(ISNULL(lpt.tiene_agua, 0) AS BIT),
                p.ready_luz = CASE
                    WHEN p.requiere_luz = 1 AND ISNULL(lpt.tiene_luz, 0) = 1 THEN 1
                    ELSE 0
                END,
                p.ready_gas = CASE
                    WHEN p.requiere_luz = 1 AND ISNULL(lpt.tiene_luz, 0) = 1
                     AND p.requiere_gas = 1 AND ISNULL(lpt.tiene_gas, 0) = 1
                    THEN 1
                    ELSE 0
                END,
                p.ready_agua = CASE
                    WHEN p.requiere_luz = 1 AND ISNULL(lpt.tiene_luz, 0) = 1
                     AND p.requiere_agua = 1 AND ISNULL(lpt.tiene_agua, 0) = 1
                    THEN 1
                    ELSE 0
                END,
                p.estado_pool = CASE
                    WHEN p.estado_pool IN (4,5) THEN p.estado_pool
                    WHEN p.id_documento_cobro IS NOT NULL THEN 3
                    WHEN p.requiere_luz = 1 AND ISNULL(lpt.tiene_luz, 0) = 1 THEN 2
                    ELSE 1
                END,
                p.motivo_pendiente = CASE
                    WHEN p.estado_pool IN (4,5) THEN p.motivo_pendiente
                    WHEN p.requiere_luz = 1 AND ISNULL(lpt.tiene_luz, 0) = 0 THEN N'Faltan lecturas/cobros de LUZ.'
                    WHEN p.requiere_gas = 1 AND ISNULL(lpt.tiene_gas, 0) = 0 THEN N'Faltan lecturas/cobros de GAS.'
                    WHEN p.requiere_agua = 1 AND ISNULL(lpt.tiene_agua, 0) = 0 THEN N'Faltan lecturas/cobros de AGUA.'
                    WHEN p.id_documento_cobro IS NULL THEN N'Listo para materializar documento.'
                    ELSE NULL
                END,
                p.updated_at = SYSDATETIME()
             FROM dbo.msp_pool_documentos_periodo p
             LEFT JOIN lecturas_por_tienda lpt
                ON lpt.id_tienda = p.id_tienda
               AND lpt.id_contrato_arriendo = p.id_contrato_arriendo
             WHERE p.periodo_facturacion = @periodo"
        );
        $stmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $stmt->execute();
    }

    private static function bindDocumentos(PDO $conn, string $periodoFacturacion): void
    {
        $bindPoolStmt = $conn->prepare(
            "UPDATE p
             SET
                p.id_documento_cobro = dc.id_documento_cobro,
                p.estado_pool = CASE
                    WHEN p.estado_pool IN (4,5) THEN p.estado_pool
                    WHEN dc.id_documento_cobro IS NOT NULL THEN 3
                    WHEN p.estado_pool = 3 THEN 2
                    ELSE p.estado_pool
                END,
                p.motivo_pendiente = CASE
                    WHEN dc.id_documento_cobro IS NOT NULL THEN NULL
                    WHEN p.motivo_pendiente IS NULL OR LTRIM(RTRIM(p.motivo_pendiente)) = '' THEN N'Listo para materializar documento.'
                    ELSE p.motivo_pendiente
                END,
                p.updated_at = SYSDATETIME()
             FROM dbo.msp_pool_documentos_periodo p
             LEFT JOIN dbo.msp_documentos_cobro dc
                ON dc.id_tienda = p.id_tienda
               AND dc.id_contrato_arriendo = p.id_contrato_arriendo
               AND dc.periodo_facturacion = p.periodo_facturacion
               AND dc.estado_documento <> 5
             WHERE p.periodo_facturacion = :periodo"
        );
        $bindPoolStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $bindPoolStmt->execute();

        $bindDocStmt = $conn->prepare(
            "UPDATE dc
             SET dc.id_pool_documento = p.id_pool_documento
             FROM dbo.msp_documentos_cobro dc
             INNER JOIN dbo.msp_pool_documentos_periodo p
                ON p.id_tienda = dc.id_tienda
               AND p.id_contrato_arriendo = dc.id_contrato_arriendo
               AND p.periodo_facturacion = dc.periodo_facturacion
             WHERE dc.periodo_facturacion = :periodo
               AND dc.estado_documento <> 5
               AND (dc.id_pool_documento IS NULL OR dc.id_pool_documento <> p.id_pool_documento)"
        );
        $bindDocStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $bindDocStmt->execute();
    }

    private static function normalizeLoteStageConsistency(PDO $conn, string $periodoFacturacion): void
    {
        if (!msp2TableExists($conn, 'msp_envio_lotes_programados')) {
            return;
        }

        $stmt = $conn->prepare(
            "UPDATE p
             SET
                p.estado_pool = CASE WHEN p.id_documento_cobro IS NOT NULL THEN 3 ELSE 2 END,
                p.id_lote_envio_ultimo = NULL,
                p.updated_at = SYSDATETIME(),
                p.motivo_pendiente = CASE
                    WHEN p.id_documento_cobro IS NOT NULL THEN NULL
                    ELSE N'Listo para materializar documento.'
                END
             FROM dbo.msp_pool_documentos_periodo p
             LEFT JOIN dbo.msp_envio_lotes_programados l
                ON l.id_lote_envio = p.id_lote_envio_ultimo
               AND l.periodo_facturacion = p.periodo_facturacion
             WHERE p.periodo_facturacion = :periodo
               AND p.estado_pool = 4
               AND (
                    l.id_lote_envio IS NULL
                    OR l.estado_lote = 5
                    OR (
                        (p.requiere_luz = 1 OR p.requiere_gas = 1 OR p.requiere_agua = 1)
                        AND UPPER(LTRIM(RTRIM(ISNULL(l.codigo_servicio, N'')))) <>
                            CASE
                                WHEN p.requiere_agua = 1 THEN N'AGUA'
                                WHEN p.requiere_gas = 1 THEN N'GAS'
                                ELSE N'LUZ'
                            END
                    )
               )"
        );
        $stmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $stmt->execute();
    }
}
