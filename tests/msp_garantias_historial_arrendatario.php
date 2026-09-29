<?php
declare(strict_types=1);

/*
 * Verificacion de solo lectura para el historial general de garantias.
 * No inserta, actualiza ni elimina datos.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once dirname(__DIR__) . '/db.php';

$checks = [];
$check = static function (string $name, callable $callback) use (&$checks): void {
    try {
        $result = $callback();
        $checks[] = [$name, $result === true, $result === true ? 'OK' : (string) $result];
    } catch (Throwable $exception) {
        $checks[] = [$name, false, $exception->getMessage()];
    }
};

$viewExists = static function (string $view) use ($conn): bool {
    $statement = $conn->prepare("SELECT CASE WHEN OBJECT_ID(:view,'V') IS NULL THEN 0 ELSE 1 END");
    $statement->execute([':view' => 'dbo.' . $view]);
    return (bool) $statement->fetchColumn();
};

$check('Vista canonica de eventos', static fn(): bool => $viewExists('msp_vw_garantias_historial_eventos'));
$check('Vista agrupable por arrendatario', static fn(): bool => $viewExists('msp_vw_garantias_historial_arrendatario'));

$check('Una fila por registro fisico', static function () use ($conn): bool|string {
    $expected = (int) $conn->query(
        'SELECT
            (SELECT COUNT(*) FROM dbo.msp_garantia_recepciones)
          + (SELECT COUNT(*) FROM dbo.msp_movimientos_garantia)
          + (SELECT COUNT(*) FROM dbo.msp_garantia_reversas)'
    )->fetchColumn();
    $actual = (int) $conn->query('SELECT COUNT(*) FROM dbo.msp_vw_garantias_historial_eventos')->fetchColumn();
    return $actual === $expected ? true : "esperadas $expected, obtenidas $actual";
});

$check('Origenes sin duplicidad', static function () use ($conn): bool|string {
    $duplicates = (int) $conn->query(
        'SELECT COUNT(*) FROM (
            SELECT origen_evento,id_evento
            FROM dbo.msp_vw_garantias_historial_eventos
            GROUP BY origen_evento,id_evento
            HAVING COUNT(*)<>1
        ) d'
    )->fetchColumn();
    return $duplicates === 0 ? true : "$duplicates origenes duplicados";
});

$check('Todas las reversas permanecen visibles', static function () use ($conn): bool|string {
    $expected = (int) $conn->query('SELECT COUNT(*) FROM dbo.msp_garantia_reversas')->fetchColumn();
    $actual = (int) $conn->query(
        "SELECT COUNT(*)
         FROM dbo.msp_vw_garantias_historial_eventos
         WHERE origen_evento=N'REVERSA'"
    )->fetchColumn();
    return $actual === $expected ? true : "esperadas $expected, obtenidas $actual";
});

$check('Reversas no duplican el efecto financiero', static function () use ($conn): bool|string {
    $invalid = (int) $conn->query(
        "SELECT COUNT(*)
         FROM dbo.msp_vw_garantias_historial_eventos
         WHERE origen_evento=N'REVERSA'
           AND (
                (tipo_origen_reversa=N'RECEPCION' AND (
                    ABS(impacto_disponible+monto_operacion)>0.009
                    OR ABS(impacto_reservado)>0.009
                    OR ABS(impacto_total+monto_operacion)>0.009
                ))
                OR
                (tipo_origen_reversa IN(N'DEVOLUCION',N'APLICACION') AND (
                    ABS(impacto_disponible)>0.009
                    OR ABS(impacto_reservado)>0.009
                    OR ABS(impacto_total)>0.009
                ))
           )"
    )->fetchColumn();
    return $invalid === 0 ? true : "$invalid reversas con efecto duplicado";
});

$check('Todos los eventos tienen identidad comercial', static function () use ($conn): bool|string {
    $orphans = (int) $conn->query(
        'SELECT COUNT(*)
         FROM dbo.msp_vw_garantias_historial_arrendatario
         WHERE id_arrendatario IS NULL
            OR id_tienda IS NULL
            OR id_contrato_arriendo IS NULL
            OR id_garantia_tienda IS NULL'
    )->fetchColumn();
    return $orphans === 0 ? true : "$orphans eventos sin identidad completa";
});

$check('Saldo disponible conciliado', static function () use ($conn): bool|string {
    $differences = (int) $conn->query(
        'WITH ultimo AS (
            SELECT h.*,
                   ROW_NUMBER() OVER (
                       PARTITION BY h.id_garantia_tienda
                       ORDER BY h.fecha_evento DESC,h.prioridad_evento DESC,
                                h.fecha_registro DESC,h.origen_evento DESC,h.id_evento DESC
                   ) AS fila
            FROM dbo.msp_vw_garantias_historial_arrendatario h
        )
        SELECT COUNT(*)
        FROM ultimo u
        INNER JOIN dbo.msp_vw_garantias_tienda_resumen r
            ON r.id_garantia_tienda=u.id_garantia_tienda
        WHERE u.fila=1
          AND ABS(u.saldo_disponible_garantia-r.saldo_disponible)>0.009'
    )->fetchColumn();
    return $differences === 0 ? true : "$differences garantias con diferencia";
});

$check('Saldo reservado conciliado', static function () use ($conn): bool|string {
    $differences = (int) $conn->query(
        'WITH ultimo AS (
            SELECT h.*,
                   ROW_NUMBER() OVER (
                       PARTITION BY h.id_garantia_tienda
                       ORDER BY h.fecha_evento DESC,h.prioridad_evento DESC,
                                h.fecha_registro DESC,h.origen_evento DESC,h.id_evento DESC
                   ) AS fila
            FROM dbo.msp_vw_garantias_historial_arrendatario h
        )
        SELECT COUNT(*)
        FROM ultimo u
        INNER JOIN dbo.msp_vw_garantias_tienda_resumen r
            ON r.id_garantia_tienda=u.id_garantia_tienda
        WHERE u.fila=1
          AND ABS(u.saldo_reservado_garantia-r.monto_reservado)>0.009'
    )->fetchColumn();
    return $differences === 0 ? true : "$differences garantias con diferencia";
});

$check('Saldo total conciliado', static function () use ($conn): bool|string {
    $differences = (int) $conn->query(
        'WITH ultimo AS (
            SELECT h.*,
                   ROW_NUMBER() OVER (
                       PARTITION BY h.id_garantia_tienda
                       ORDER BY h.fecha_evento DESC,h.prioridad_evento DESC,
                                h.fecha_registro DESC,h.origen_evento DESC,h.id_evento DESC
                   ) AS fila
            FROM dbo.msp_vw_garantias_historial_arrendatario h
        )
        SELECT COUNT(*)
        FROM ultimo u
        INNER JOIN dbo.msp_vw_garantias_tienda_resumen r
            ON r.id_garantia_tienda=u.id_garantia_tienda
        WHERE u.fila=1
          AND ABS(u.saldo_total_garantia-(r.saldo_disponible+r.monto_reservado))>0.009'
    )->fetchColumn();
    return $differences === 0 ? true : "$differences garantias con diferencia";
});

$check('Contratos especiales conservan su trazabilidad', static function () use ($conn): bool|string {
    $statement = $conn->query(
        'SELECT r.id_contrato_arriendo,r.id_garantia_tienda
         FROM dbo.msp_vw_garantias_tienda_resumen r
         WHERE r.id_contrato_arriendo IN(1,49,73)'
    );
    $missing = [];
    foreach ($statement as $row) {
        $source = $conn->prepare(
            'SELECT
                (SELECT COUNT(*) FROM dbo.msp_garantia_recepciones WHERE id_garantia_tienda=:g1)
              + (SELECT COUNT(*) FROM dbo.msp_movimientos_garantia WHERE id_garantia_tienda=:g2)
              + (SELECT COUNT(*) FROM dbo.msp_garantia_reversas WHERE id_garantia_tienda=:g3)'
        );
        $source->execute([
            ':g1' => (int) $row['id_garantia_tienda'],
            ':g2' => (int) $row['id_garantia_tienda'],
            ':g3' => (int) $row['id_garantia_tienda'],
        ]);
        $expected = (int) $source->fetchColumn();
        $history = $conn->prepare(
            'SELECT COUNT(*)
             FROM dbo.msp_vw_garantias_historial_arrendatario
             WHERE id_garantia_tienda=:garantia'
        );
        $history->execute([':garantia' => (int) $row['id_garantia_tienda']]);
        if ((int) $history->fetchColumn() !== $expected) {
            $missing[] = '#' . (int) $row['id_contrato_arriendo'];
        }
    }
    return $missing === [] ? true : 'diferencias en contratos ' . implode(', ', $missing);
});

$failed = 0;
foreach ($checks as [$name, $ok, $message]) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $name . ' - ' . $message . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}
echo 'Resultado: ' . (count($checks) - $failed) . '/' . count($checks) . " pruebas correctas.\n";
exit($failed === 0 ? 0 : 1);
