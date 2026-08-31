<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
msp2RequireAccess();

function msp2SaldoContratoRedirect(): never
{
    $returnTo = trim((string) ($_POST['return_to'] ?? ''));
    if ($returnTo !== '' && preg_match('#^cobranza/gestionar\.php\?id_contrato=\d+(?:&return_to=[A-Za-z0-9_\-\.\[%\]=&]*)?$#', $returnTo) === 1) {
        msp2Redirect($returnTo);
    }
    $query = trim((string) ($_POST['volver_query'] ?? ''));
    $target = 'cobranza/registrar_pago_contrato.php';
    if ($query !== '' && preg_match('/^[A-Za-z0-9_\-\.\[\]%=&]*$/', $query) === 1) {
        $target .= '?' . $query;
    }
    msp2Redirect($target);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('cobranza/registrar_pago_contrato.php');
}

$idArrendatario = filter_input(INPUT_POST, 'id_arrendatario', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$idContrato = filter_input(INPUT_POST, 'id_contrato_arriendo', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
[$montoOk, $montoSolicitado] = msp2NormalizeDecimalInput((string) ($_POST['monto_saldo_favor'] ?? ''), 2);
if (!$idArrendatario || !$idContrato || !$montoOk || $montoSolicitado === null || (float) $montoSolicitado <= 0) {
    msp2SetFlash('warning', 'Los datos para aplicar el saldo a favor no son válidos.');
    msp2SaldoContratoRedirect();
}

try {
    if (!msp2ProcedureExists($conn, 'msp_aplicar_saldo_favor_documento') || !msp2TableExists($conn, 'msp_saldos_favor_tienda')) {
        throw new RuntimeException('El módulo de saldo a favor no está instalado completamente.');
    }

    $conn->beginTransaction();
    $stmtContrato = $conn->prepare(
        'SELECT c.id_tienda,ISNULL(sf.saldo_disponible,0) saldo_disponible
         FROM dbo.msp_contratos_arriendo c WITH (UPDLOCK,HOLDLOCK)
         LEFT JOIN dbo.msp_saldos_favor_tienda sf WITH (UPDLOCK,HOLDLOCK) ON sf.id_tienda=c.id_tienda
         WHERE c.id_contrato_arriendo=:contrato AND c.id_arrendatario=:arrendatario AND c.estado_contrato<>5'
    );
    $stmtContrato->execute([':contrato'=>(int)$idContrato, ':arrendatario'=>(int)$idArrendatario]);
    $contrato = $stmtContrato->fetch();
    if (!$contrato) {
        throw new RuntimeException('El contrato no corresponde al arrendatario o está anulado.');
    }
    $saldoDisponible = round((float) $contrato['saldo_disponible'], 2);
    if ($saldoDisponible <= 0.005) {
        throw new RuntimeException('El contrato no tiene saldo a favor disponible.');
    }
    $montoObjetivo = round((float) $montoSolicitado, 2);
    if ($montoObjetivo > $saldoDisponible + 0.009) {
        throw new RuntimeException('El monto solicitado supera el saldo a favor disponible.');
    }

    $stmtDocs = $conn->prepare(
        'SELECT dc.id_documento_cobro,dc.saldo_pendiente
         FROM dbo.msp_documentos_cobro dc WITH (UPDLOCK,HOLDLOCK)
         OUTER APPLY (
            SELECT TOP 1 ca.id_contrato_arriendo
            FROM dbo.msp_contratos_arriendo ca
            WHERE ca.id_tienda=dc.id_tienda
              AND ca.fecha_inicio<=EOMONTH(dc.periodo_facturacion)
              AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva>=dc.periodo_facturacion)
              AND ca.estado_contrato IN (1,2,3)
            ORDER BY ca.fecha_inicio DESC,ca.id_contrato_arriendo DESC
         ) contrato_vigente
         WHERE dc.id_tienda=:tienda AND dc.estado_documento IN (2,3) AND dc.saldo_pendiente>0
           AND COALESCE(dc.id_contrato_arriendo,contrato_vigente.id_contrato_arriendo)=:contrato
         ORDER BY dc.periodo_facturacion,ISNULL(dc.fecha_vencimiento,dc.periodo_facturacion),dc.id_documento_cobro'
    );
    $stmtDocs->execute([':tienda'=>(int)$contrato['id_tienda'], ':contrato'=>(int)$idContrato]);
    $docs = $stmtDocs->fetchAll() ?: [];
    if ($docs === []) {
        throw new RuntimeException('El contrato no tiene documentos pendientes.');
    }

    $stmtAplicar = $conn->prepare(
        'EXEC dbo.msp_aplicar_saldo_favor_documento
            @id_documento_cobro=:documento,@fecha_pago=:fecha,@monto_aplicar=:monto,@observaciones=:observaciones'
    );
    $restante = $montoObjetivo;
    $aplicado = 0.0;
    $cantidadDocs = 0;
    $fecha = date('Y-m-d');
    foreach ($docs as $doc) {
        if ($restante <= 0.005) break;
        $montoDoc = round(min($restante, (float)$doc['saldo_pendiente']), 2);
        if ($montoDoc <= 0.005) continue;
        $stmtAplicar->execute([
            ':documento'=>(int)$doc['id_documento_cobro'], ':fecha'=>$fecha, ':monto'=>(string)$montoDoc,
            ':observaciones'=>'Aplicación explícita de saldo a favor desde pago por contrato #'.(int)$idContrato,
        ]);
        $resultado = $stmtAplicar->fetch() ?: [];
        $real = round((float)($resultado['monto_aplicado'] ?? $montoDoc), 2);
        $aplicado = round($aplicado + $real, 2);
        $restante = round($restante - $real, 2);
        $cantidadDocs++;
        $stmtAplicar->closeCursor();
    }
    if ($aplicado <= 0.005) {
        throw new RuntimeException('No fue posible aplicar saldo a ningún documento.');
    }
    $conn->commit();
    $mensaje = 'Se aplicaron $ '.number_format($aplicado,2,',','.').' de saldo a favor a '.$cantidadDocs.' documento'.($cantidadDocs===1?'':'s').'.';
    if ($restante > 0.005) $mensaje .= ' Quedaron $ '.number_format($restante,2,',','.').' sin aplicar porque la deuda era menor.';
    msp2SetFlash('success', $mensaje);
} catch (Throwable $exception) {
    if ($conn->inTransaction()) $conn->rollBack();
    msp2SetFlash($exception instanceof RuntimeException ? 'warning' : 'danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'No fue posible aplicar el saldo a favor al contrato.');
}
msp2SaldoContratoRedirect();
