<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/mail_helper.php';
require_once __DIR__ . '/vale_lib.php';
require_once dirname(__DIR__) . '/cobros/mail_templates/vale_cobro_email.php';
require_once dirname(__DIR__) . '/cobros/support/OperacionMensualCommon.php';
require_once dirname(__DIR__) . '/cobros/services/EnvioLotesProgramadosService.php';

msp2RequireAccess();

$redirectQueryRaw = trim((string) ($_POST['volver_query'] ?? ''));
$redirectTarget = 'documentos_cobro/index.php';
if ($redirectQueryRaw !== '' && preg_match('/^[A-Za-z0-9_\-\.\[\]%=&]*$/', $redirectQueryRaw) === 1) {
    $redirectTarget .= '?' . $redirectQueryRaw;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    msp2Redirect($redirectTarget);
}

$idDocumentoReenvio = filter_input(INPUT_POST, 'id_documento_cobro', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($idDocumentoReenvio === false || $idDocumentoReenvio === null) {
    msp2SetFlash('warning', 'Debes seleccionar un documento válido para reenviar.');
    msp2Redirect($redirectTarget);
}

$correoDemoConfigRaw = trim((string) (mspMailConfig()['demo']['to'] ?? ''));
$correoDemoConfig = filter_var($correoDemoConfigRaw, FILTER_VALIDATE_EMAIL) !== false ? $correoDemoConfigRaw : '';
$modoCorreoDemoActivo = $correoDemoConfig !== '';

try {
    if (!EnvioLotesProgramadosService::isAvailable($conn)) {
        throw new RuntimeException('El módulo de lotes programados no está habilitado en este ambiente.');
    }

    $createdByUserId = isset($_SESSION['usuario']['id']) ? (int) $_SESSION['usuario']['id'] : null;
    $modoDestinoReenvio = $modoCorreoDemoActivo ? 'demo' : 'real';
    $demoDestinoReenvio = $modoCorreoDemoActivo ? $correoDemoConfig : null;
    $resLote = EnvioLotesProgramadosService::resendSingleDocumentoNow(
        $conn,
        (int) $idDocumentoReenvio,
        $modoDestinoReenvio,
        $demoDestinoReenvio,
        $createdByUserId
    );

    $idLoteReenvio = (int) ($resLote['id_lote_envio'] ?? 0);
    $periodoReenvio = (string) ($resLote['periodo_facturacion'] ?? '');
    $pendientesReenvio = (int) ($resLote['pendientes'] ?? 0);
    $omitidosReenvio = (int) ($resLote['omitidos'] ?? 0);
    $modoReenvio = strtolower((string) ($resLote['modo_destino'] ?? 'real')) === 'demo' ? 'Demo' : 'Real';
    $codigoServicioReenvio = strtoupper((string) ($resLote['codigo_servicio'] ?? ''));

    if ($pendientesReenvio > 0 && $idLoteReenvio > 0 && $periodoReenvio !== '') {
        $resExec = EnvioLotesProgramadosService::forceProcessLoteNow(
            $conn,
            $idLoteReenvio,
            $periodoReenvio,
            null,
            'web-doc-individual'
        );
        $msg = 'Reenvío ejecutado para documento #' . (int) $idDocumentoReenvio
            . ' | lote #' . $idLoteReenvio
            . ' | servicio: ' . ($codigoServicioReenvio !== '' ? $codigoServicioReenvio : '-')
            . ' | modo: ' . $modoReenvio
            . ' | enviados ' . (int) ($resExec['enviados_batch'] ?? 0)
            . ' | fallidos ' . (int) ($resExec['fallidos_batch'] ?? 0)
            . ' | omitidos ' . (int) ($resExec['omitidos_batch'] ?? 0) . '.';

        $errorEnvio = trim((string) ($resExec['ultimo_error_destinatario'] ?? ''));
        if ($errorEnvio !== '') {
            $msg .= ' Error: ' . $errorEnvio;
        }
        msp2SetFlash('success', $msg);
    } else {
        $msg = 'Se creó lote de reenvío para documento #' . (int) $idDocumentoReenvio
            . ' sin destinatarios pendientes'
            . ' | lote #' . $idLoteReenvio
            . ' | servicio: ' . ($codigoServicioReenvio !== '' ? $codigoServicioReenvio : '-')
            . ' | modo: ' . $modoReenvio
            . ' | omitidos ' . $omitidosReenvio . '.';
        msp2SetFlash('warning', $msg);
    }
} catch (Throwable $e) {
    msp2SetFlash(
        'danger',
        $e instanceof RuntimeException
            ? $e->getMessage()
            : 'No fue posible reenviar el documento de cobro en este momento.'
    );
}

msp2Redirect($redirectTarget);
