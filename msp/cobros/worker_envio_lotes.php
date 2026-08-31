<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Forbidden\n";
    exit(1);
}

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/documentos_cobro/vale_lib.php';
require_once __DIR__ . '/mail_templates/vale_cobro_email.php';
require_once __DIR__ . '/support/OperacionMensualCommon.php';
require_once __DIR__ . '/services/EnvioLotesProgramadosService.php';

if (!isset($conn) || !$conn instanceof PDO) {
    fwrite(STDERR, "No fue posible inicializar la conexión a base de datos.\n");
    exit(1);
}

if (!EnvioLotesProgramadosService::isAvailable($conn)) {
    fwrite(STDERR, "Las tablas de lotes programados no están disponibles. Ejecuta el patch de DB primero.\n");
    exit(2);
}

$options = getopt('', ['max-lotes::', 'batch-size::', 'worker-id::']);
$maxLotes = isset($options['max-lotes']) ? (int) $options['max-lotes'] : 3;
$batchSize = isset($options['batch-size']) ? (int) $options['batch-size'] : null;
$workerId = isset($options['worker-id']) ? trim((string) $options['worker-id']) : '';
if ($workerId === '') {
    $workerId = 'cli@' . gethostname();
}

try {
    $res = EnvioLotesProgramadosService::processDueLotes($conn, $maxLotes, $batchSize, $workerId);

    $line = 'Worker envío lotes: lotes=' . (int) ($res['lotes_procesados'] ?? 0)
        . ' | enviados=' . (int) ($res['destinatarios_enviados'] ?? 0)
        . ' | fallidos=' . (int) ($res['destinatarios_fallidos'] ?? 0)
        . ' | omitidos=' . (int) ($res['destinatarios_omitidos'] ?? 0);
    fwrite(STDOUT, $line . PHP_EOL);

    $detalles = is_array($res['detalles'] ?? null) ? $res['detalles'] : [];
    $periodosProcesados = [];
    $periodoByLoteStmt = $conn->prepare(
        'SELECT TOP (1) periodo_facturacion
         FROM dbo.msp_envio_lotes_programados
         WHERE id_lote_envio = :id_lote'
    );
    foreach ($detalles as $detalle) {
        if (!is_array($detalle)) {
            continue;
        }
        $idLote = (int) ($detalle['id_lote_envio'] ?? 0);
        $msg = (string) ($detalle['message'] ?? '');
        if ($idLote > 0) {
            $periodoByLoteStmt->bindValue(':id_lote', $idLote, PDO::PARAM_INT);
            $periodoByLoteStmt->execute();
            $periodo = trim((string) ($periodoByLoteStmt->fetchColumn() ?: ''));
            if ($periodo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodo) === 1) {
                $periodosProcesados[$periodo] = true;
            }
            fwrite(STDOUT, ' - Lote #' . $idLote . ': ' . $msg . PHP_EOL);
        }
    }

    foreach (array_keys($periodosProcesados) as $periodoFacturacion) {
        $autoClose = omTryAutoClosePeriodoIfReady($conn, (string) $periodoFacturacion, 'worker_envio_lotes');
        if ((bool) ($autoClose['changed'] ?? false)) {
            fwrite(STDOUT, ' - Cierre automático aplicado para período ' . $periodoFacturacion . PHP_EOL);
        }
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error worker envío lotes: ' . $e->getMessage() . PHP_EOL);
    exit(3);
}
