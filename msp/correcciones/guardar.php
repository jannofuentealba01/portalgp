<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/services/CorreccionesService.php';

msp2RequireAccess();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('correcciones/index.php');
}
msp2RequireValidCsrfToken();

function corrValorNumerico(mixed $raw): ?float
{
    $texto = trim((string) $raw);
    if ($texto === '') { return null; }
    $texto = str_replace(['$', ' '], '', $texto);
    if (str_contains($texto, ',') && str_contains($texto, '.')) {
        $texto = str_replace('.', '', $texto);
    }
    $texto = str_replace(',', '.', $texto);
    return is_numeric($texto) ? (float) $texto : null;
}

function corrNivelDocumento(PDO $conn, int $idDocumento): string
{
    if ($idDocumento <= 0) { return 'EDICION_SIMPLE'; }

    if (msp2TableExists($conn, 'msp_documentos_cobro')) {
        $qEstado = $conn->prepare('SELECT estado_documento FROM dbo.msp_documentos_cobro WHERE id_documento_cobro=:d');
        $qEstado->execute([':d' => $idDocumento]);
        $estadoDocumento = $qEstado->fetchColumn();
        if ($estadoDocumento === false || (int) $estadoDocumento === 5) {
            return 'REVISION';
        }
    }

    $dependencias = [
        'msp_pagos' => 'id_documento_cobro',
        'msp_saldo_favor_periodo_aplicaciones' => 'id_documento_cobro',
        'msp_garantia_documento_aplicaciones' => 'id_documento_cobro',
        'msp_movimientos_garantia' => 'id_documento_cobro',
        'msp_envio_lote_documentos' => 'id_documento_cobro',
        'msp_pago_contrato_operacion_detalle' => 'id_documento_cobro',
        'msp_pago_contrato_archivos' => 'id_documento_cobro',
    ];
    foreach ($dependencias as $tabla => $columna) {
        if (!msp2TableExists($conn, $tabla) || !msp2ColumnExists($conn, $tabla, $columna)) {
            continue;
        }
        $qDependencia = $conn->prepare('SELECT TOP(1) 1 FROM dbo.' . $tabla . ' WHERE ' . $columna . '=:d');
        $qDependencia->execute([':d' => $idDocumento]);
        if ($qDependencia->fetchColumn() !== false) {
            return 'AJUSTE_FINANCIERO';
        }
    }

    if (msp2TableExists($conn, 'msp_acc_asientos')) {
        $q = $conn->prepare("SELECT COUNT(*) FROM dbo.msp_acc_asientos WHERE tabla_origen=N'msp_documentos_cobro' AND id_origen=:d AND estado_asiento=1");
        $q->execute([':d' => $idDocumento]);
        if ((int) $q->fetchColumn() > 0) { return 'AUTORIZACION'; }
    }
    return 'REGENERACION_CONTROLADA';
}

$accion = strtoupper(trim((string) ($_POST['accion'] ?? 'crear')));
$usuarioId = (int) ($_SESSION['usuario']['id'] ?? 0);

try {
    if (in_array($accion, ['APROBAR','EJECUTAR','APLICAR_SIMPLE','APLICAR_CONTROLADA'], true)) {
        $idCorreccion = filter_input(INPUT_POST, 'id_correccion', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($idCorreccion === false || $idCorreccion === null) {
            throw new RuntimeException('Corrección inválida.');
        }

        if ($accion === 'APROBAR') {
            CorreccionesService::cambiarEstado($conn, (int) $idCorreccion, 'APROBADA', $usuarioId, 'Corrección aprobada.');
            msp2SetFlash('success', 'Corrección aprobada.');
            msp2Redirect('correcciones/index.php?id_correccion=' . (int) $idCorreccion);
        }

        if ($accion === 'APLICAR_SIMPLE') {
            $correccionSimple = CorreccionesService::obtener($conn, (int)$idCorreccion);
            if (!$correccionSimple || strtoupper((string)($correccionSimple['nivel_correcion'] ?? '')) !== 'EDICION_SIMPLE') {
                throw new RuntimeException('Solo las correcciones simples se pueden aplicar directamente.');
            }
            $estadoSimple = strtoupper((string)($correccionSimple['estado_correccion'] ?? ''));
            if (!in_array($estadoSimple, ['BORRADOR','ANALIZADA','PENDIENTE_APROBACION','ERROR'], true)) {
                throw new RuntimeException('La corrección ya fue procesada o no está disponible para aplicar.');
            }
            $conn->beginTransaction();
            try {
                CorreccionesService::cambiarEstado($conn, (int)$idCorreccion, 'APROBADA', $usuarioId, 'Corrección simple aprobada al confirmar su aplicación.');
                $resultadoEjecucion = CorreccionesService::ejecutar($conn, (int)$idCorreccion, $usuarioId);
                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) { $conn->rollBack(); }
                throw $e;
            }
            $mensajeEjecucion = isset($resultadoEjecucion['id_lectura'])
                ? 'Corrección aplicada: lectura, consumo y cobro asociado actualizados.'
                : (isset($resultadoEjecucion['id_cargo']) ? 'Corrección aplicada: monto del cargo actualizado.' : 'Corrección aplicada: arriendo mensual actualizado.');
            msp2SetFlash('success', $mensajeEjecucion);
            msp2Redirect('correcciones/index.php?id_correccion='.(int)$idCorreccion);
        }

        if ($accion === 'APLICAR_CONTROLADA') {
            $correccionControlada = CorreccionesService::obtener($conn, (int) $idCorreccion);
            if (!$correccionControlada
                || !in_array(strtoupper((string) ($correccionControlada['nivel_correcion'] ?? '')), ['REGENERACION_CONTROLADA','AUTORIZACION'], true)) {
                throw new RuntimeException('La solicitud no corresponde a una corrección controlada disponible.');
            }
            $tipoControlado = strtoupper((string) ($correccionControlada['tipo_correccion'] ?? ''));
            if (!in_array($tipoControlado, ['LECTURA','ARRIENDO_PERIODO'], true)) {
                throw new RuntimeException('La solicitud no corresponde a una corrección controlada disponible.');
            }
            $analisisControlado = json_decode((string) ($correccionControlada['resultado_analisis'] ?? ''), true);
            $registroControlado = is_array($analisisControlado)
                && is_array($analisisControlado['registro_exacto'] ?? null)
                    ? $analisisControlado['registro_exacto']
                    : [];
            $servicioControlado = strtoupper((string) ($registroControlado['servicio'] ?? ''));
            $esArriendoControlado = $tipoControlado === 'ARRIENDO_PERIODO'
                && strtoupper((string) ($registroControlado['unidad_correccion'] ?? '')) === 'UF_BASE';
            if ($tipoControlado === 'LECTURA' && !in_array($servicioControlado, ['LUZ','GAS','AGUA'], true)) {
                throw new RuntimeException('La ejecución controlada está habilitada solamente para lecturas de electricidad, gas y agua.');
            }
            if ($tipoControlado === 'ARRIENDO_PERIODO' && !$esArriendoControlado) {
                throw new RuntimeException('La ejecución controlada de arriendo está habilitada solamente para UF Base.');
            }
            $nombreControlado = $esArriendoControlado ? 'arriendo' : $servicioControlado;

            $nivelControlado = strtoupper((string) ($correccionControlada['nivel_correcion'] ?? ''));
            if ($nivelControlado === 'AUTORIZACION'
                && !msp2CurrentUserHasPermission('MSP Cierre Mensual', 'escritura')
                && !msp2CurrentUserHasPermission('MSP Configuracion', 'escritura')) {
                throw new RuntimeException('La corrección requiere permiso de cierre mensual o configuración para autorizar la reversa contable.');
            }

            $estadoControlado = strtoupper((string) ($correccionControlada['estado_correccion'] ?? ''));
            if (!in_array($estadoControlado, ['BORRADOR','ANALIZADA','PENDIENTE_APROBACION','ERROR','APROBADA'], true)) {
                throw new RuntimeException('La corrección ya fue procesada o no está disponible para aplicar.');
            }

            $conn->beginTransaction();
            try {
                if ($estadoControlado !== 'APROBADA') {
                    CorreccionesService::cambiarEstado(
                        $conn,
                        (int) $idCorreccion,
                        'APROBADA',
                        $usuarioId,
                        $nivelControlado === 'AUTORIZACION'
                            ? 'Corrección de '.$nombreControlado.' y reversa contable autorizadas.'
                            : 'Corrección controlada de '.$nombreControlado.' aprobada.'
                    );
                }
                $resultadoEjecucion = CorreccionesService::ejecutar($conn, (int) $idCorreccion, $usuarioId);
                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) { $conn->rollBack(); }
                throw $e;
            }

            $documentosActualizados = count((array) ($resultadoEjecucion['documentos_actualizados'] ?? []));
            msp2SetFlash(
                'success',
                ($esArriendoControlado ? 'UF Base corregida.' : 'Lectura de '.$servicioControlado.' corregida.')
                    . ' Documentos actualizados: ' . $documentosActualizados . '.'
            );
            msp2Redirect('correcciones/index.php?id_correccion='.(int)$idCorreccion);
        }

        $resultadoEjecucion = CorreccionesService::ejecutar($conn, (int) $idCorreccion, $usuarioId);
        $mensajeEjecucion = isset($resultadoEjecucion['id_lectura'])
            ? 'Corrección ejecutada: lectura, consumo y cobro asociado actualizados.'
            : (isset($resultadoEjecucion['id_cargo'])
                ? 'Corrección ejecutada: monto del cargo actualizado.'
                : 'Corrección ejecutada: arriendo mensual actualizado sin modificar otros períodos.');
        msp2SetFlash('success', $mensajeEjecucion);
        msp2Redirect('correcciones/index.php?id_correccion=' . (int) $idCorreccion);
    }

    $idContrato = filter_input(INPUT_POST, 'id_contrato_arriendo', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    if ($idContrato === false || $idContrato === null) {
        throw new RuntimeException('Debes indicar un contrato válido.');
    }

    $analisis = CorreccionesService::analizarPorContrato($conn, (int) $idContrato);
    $contrato = $analisis['contrato'] ?? [];
    $nivel = (string) ($analisis['clasificacion']['nivel'] ?? 'REVISION');
    $entidad = strtolower(trim((string) ($_POST['entidad_afectada'] ?? '')));
    $tiposPermitidos = ['lectura'=>'LECTURA','cargo'=>'CARGO','arriendo'=>'ARRIENDO_PERIODO'];
    if (!isset($tiposPermitidos[$entidad])) {
        throw new RuntimeException('Selecciona qué operación quieres corregir.');
    }
    $tipo = $tiposPermitidos[$entidad];
    $idRegistroOrigen = (int) ($_POST['id_registro_origen'] ?? 0);
    $idLocalSeleccionado = (int) ($_POST['id_local'] ?? 0);
    $periodoSeleccionado = trim((string) ($_POST['periodo_facturacion'] ?? ''));
    $valorAnterior = $_POST['valor_anterior'] ?? null;
    $valorNuevo = corrValorNumerico($_POST['valor_nuevo'] ?? null);
    if ($valorNuevo === null || $valorNuevo < 0) {
        throw new RuntimeException('Ingresa un valor nuevo válido, igual o mayor que cero.');
    }

    if ($entidad === 'lectura') {
        $servicio = strtoupper(trim((string) ($_POST['servicio'] ?? '')));
        if (!in_array($servicio, ['LUZ','AGUA','GAS'], true) || $idRegistroOrigen <= 0) {
            throw new RuntimeException('Selecciona el servicio y la lectura que quieres corregir.');
        }
        $stmtLectura = $conn->prepare(
            "SELECT TOP(1) lm.id_lectura,lm.lectura_anterior,lm.lectura_actual,lm.consumo_informado,
                    lm.fecha_desde_consumo,lm.fecha_hasta_consumo,lm.fecha_lectura,
                    CONVERT(char(7),lm.periodo_facturacion,126) periodo,m.id_local,UPPER(ts.codigo_servicio) servicio,
                    m.id_medidor,lm.periodo_facturacion,cs.id_cobro_servicio,dd.id_documento_cobro,
                    cm.estado_cierre
             FROM dbo.msp_lecturas_medidores lm
             INNER JOIN dbo.msp_medidores m ON m.id_medidor=lm.id_medidor
             INNER JOIN dbo.msp_tipos_servicio ts ON ts.id_tipo_servicio=m.id_tipo_servicio
             INNER JOIN dbo.msp_contrato_locales cl ON cl.id_local=m.id_local AND cl.id_contrato_arriendo=:contrato
             INNER JOIN dbo.msp_procesos_cobro_servicio pcs ON pcs.id_proceso_cobro=lm.id_proceso_cobro
             INNER JOIN dbo.msp_cierre_mensual cm ON cm.id_cierre_mensual=pcs.id_cierre_mensual
             LEFT JOIN dbo.msp_cobros_servicios cs ON cs.id_lectura=lm.id_lectura
             LEFT JOIN dbo.msp_documentos_cobro_detalle dd ON dd.id_cobro_servicio=cs.id_cobro_servicio
             WHERE lm.id_lectura=:lectura"
        );
        $stmtLectura->execute([':contrato'=>(int)$idContrato, ':lectura'=>$idRegistroOrigen]);
        $lecturaExacta = $stmtLectura->fetch(PDO::FETCH_ASSOC);
        if (!$lecturaExacta || (int)$lecturaExacta['id_local'] !== $idLocalSeleccionado || (string)$lecturaExacta['periodo'] !== $periodoSeleccionado || (string)$lecturaExacta['servicio'] !== $servicio) {
            throw new RuntimeException('La lectura seleccionada no corresponde al contrato, local, período y servicio indicados. Vuelve a seleccionarla.');
        }
        if ($valorNuevo < (float) ($lecturaExacta['lectura_anterior'] ?? 0)) {
            throw new RuntimeException('La nueva lectura no puede ser menor que la lectura anterior del medidor.');
        }
        if ($servicio === 'AGUA') {
            $qSiguiente = $conn->prepare(
                "SELECT TOP(1) lectura_actual
                 FROM dbo.msp_lecturas_medidores
                 WHERE id_medidor=:m
                   AND (
                        COALESCE(fecha_hasta_consumo,fecha_lectura,periodo_facturacion)
                            > COALESCE(:fecha_hasta,:fecha_lectura,:periodo)
                        OR (
                            COALESCE(fecha_hasta_consumo,fecha_lectura,periodo_facturacion)
                                = COALESCE(:fecha_hasta_igual,:fecha_lectura_igual,:periodo_igual)
                            AND id_lectura>:lectura
                        )
                   )
                 ORDER BY COALESCE(fecha_hasta_consumo,fecha_lectura,periodo_facturacion),
                    periodo_facturacion,id_lectura"
            );
            $qSiguiente->execute([
                ':m'=>(int)$lecturaExacta['id_medidor'],
                ':fecha_hasta'=>$lecturaExacta['fecha_hasta_consumo'] ?? null,
                ':fecha_lectura'=>$lecturaExacta['fecha_lectura'] ?? null,
                ':periodo'=>(string)$lecturaExacta['periodo_facturacion'],
                ':fecha_hasta_igual'=>$lecturaExacta['fecha_hasta_consumo'] ?? null,
                ':fecha_lectura_igual'=>$lecturaExacta['fecha_lectura'] ?? null,
                ':periodo_igual'=>(string)$lecturaExacta['periodo_facturacion'],
                ':lectura'=>$idRegistroOrigen,
            ]);
        } else {
            $qSiguiente = $conn->prepare(
                'SELECT TOP(1) lectura_actual
                 FROM dbo.msp_lecturas_medidores
                 WHERE id_medidor=:m AND periodo_facturacion>:p
                 ORDER BY periodo_facturacion,id_lectura'
            );
            $qSiguiente->execute([
                ':m'=>(int)$lecturaExacta['id_medidor'],
                ':p'=>(string)$lecturaExacta['periodo_facturacion'],
            ]);
        }
        $lecturaSiguiente = $qSiguiente->fetchColumn();
        if ($lecturaSiguiente !== false && $lecturaSiguiente !== null && $valorNuevo > (float)$lecturaSiguiente) {
            throw new RuntimeException('La nueva lectura no puede ser mayor que la lectura del período siguiente ('.(string)$lecturaSiguiente.').');
        }
        $valorAnterior = 'lectura_anterior='.(string)$lecturaExacta['lectura_anterior'].'; lectura_actual='.(string)$lecturaExacta['lectura_actual'].'; consumo='.(string)$lecturaExacta['consumo_informado'];
        $idDocumentoExacto = (int)($lecturaExacta['id_documento_cobro'] ?? 0);
        $nivel = corrNivelDocumento($conn, $idDocumentoExacto);
        if ($idDocumentoExacto <= 0
            && in_array((int) ($lecturaExacta['estado_cierre'] ?? 0), [3, 5], true)) {
            $nivel = 'AUTORIZACION';
        }
        $analisis['registro_exacto']=$lecturaExacta;
        $analisis['clasificacion']['nivel']=$nivel;
    } elseif ($entidad === 'cargo') {
        if ($idRegistroOrigen <= 0 || !msp2TableExists($conn, 'msp_cargos_contrato_local')) {
            throw new RuntimeException('Selecciona la multa o cargo que quieres corregir.');
        }
        $qCargo = $conn->prepare(
            "SELECT ccl.*,cl.id_local,cl.id_contrato_arriendo,
                    CONVERT(char(7),COALESCE(ccl.periodo_referencia,ccl.fecha_cargo),126) periodo
             FROM dbo.msp_cargos_contrato_local ccl
             INNER JOIN dbo.msp_contrato_locales cl ON cl.id_contrato_local=ccl.id_contrato_local
             WHERE ccl.id_cargo_contrato_local=:cargo AND cl.id_contrato_arriendo=:contrato"
        );
        $qCargo->execute([':cargo'=>$idRegistroOrigen, ':contrato'=>(int)$idContrato]);
        $cargoExacto = $qCargo->fetch(PDO::FETCH_ASSOC);
        if (!$cargoExacto || (int)$cargoExacto['id_local'] !== $idLocalSeleccionado || (string)$cargoExacto['periodo'] !== $periodoSeleccionado) {
            throw new RuntimeException('La multa o cargo seleccionado no corresponde al contrato, local y período indicados.');
        }
        if ($valorNuevo <= 0) {
            throw new RuntimeException('Para corregir el monto debe ser mayor que cero. Para eliminar un cobro utiliza su acción de anulación o condonación.');
        }
        $valorAnterior = 'monto_cargo='.(string)$cargoExacto['monto_cargo'].'; descripcion='.(string)$cargoExacto['descripcion_cargo'];
        if ((int)($cargoExacto['estado_cargo'] ?? 0) !== 1) {
            $nivel = 'REVISION';
        } elseif ((float)($cargoExacto['monto_aplicado_garantia'] ?? 0) > 0 || (float)($cargoExacto['monto_pagado_directo'] ?? 0) > 0) {
            $nivel = 'AJUSTE_FINANCIERO';
        } else {
            $nivel = corrNivelDocumento($conn, (int)($cargoExacto['id_documento_cobro'] ?? 0));
        }
        $analisis['registro_exacto']=$cargoExacto;
        $analisis['clasificacion']['nivel']=$nivel;
    } elseif ($entidad === 'arriendo') {
        if ($idRegistroOrigen <= 0 || !msp2TableExists($conn, 'msp_arriendo_local_snapshot_periodo')) {
            throw new RuntimeException('Selecciona el arriendo mensual que quieres corregir.');
        }
        $unidadValor = strtoupper(trim((string) ($_POST['unidad_valor'] ?? 'CLP_NETO')));
        $qArriendo = $conn->prepare(
            "SELECT s.*,CONVERT(char(7),s.periodo_facturacion,126) periodo,
                    COALESCE(NULLIF(s.valor_uf_periodo,0),cm.valor_uf) AS valor_uf_calculo,
                    cm.estado_cierre,tm.codigo_modalidad
             FROM dbo.msp_arriendo_local_snapshot_periodo s
             LEFT JOIN dbo.msp_cierre_mensual cm ON cm.periodo_facturacion=s.periodo_facturacion
             INNER JOIN dbo.msp_tipo_modalidad_arriendo tm ON tm.id_modalidad_arriendo=s.id_modalidad_aplicada
             WHERE s.id_snapshot_arriendo=:snapshot
               AND s.id_contrato_arriendo=:contrato
               AND s.estado_snapshot IN(1,2,3)"
        );
        $qArriendo->execute([':snapshot'=>$idRegistroOrigen, ':contrato'=>(int)$idContrato]);
        $arriendoExacto = $qArriendo->fetch(PDO::FETCH_ASSOC);
        if (!$arriendoExacto || (int)$arriendoExacto['id_local'] !== $idLocalSeleccionado || (string)$arriendoExacto['periodo'] !== $periodoSeleccionado) {
            throw new RuntimeException('El arriendo seleccionado no corresponde al contrato, local y período indicados.');
        }
        $qDocumento = $conn->prepare('SELECT TOP(1) id_documento_cobro FROM dbo.msp_documentos_cobro WHERE id_contrato_arriendo=:c AND periodo_facturacion=:p AND estado_documento<>5 ORDER BY id_documento_cobro DESC');
        $qDocumento->execute([':c'=>(int)$idContrato, ':p'=>(string)$arriendoExacto['periodo_facturacion']]);
        $idDocumentoArriendo = (int)($qDocumento->fetchColumn() ?: 0);
        $nivel = corrNivelDocumento($conn, $idDocumentoArriendo);
        if ($nivel === 'REGENERACION_CONTROLADA'
            && in_array((int)($arriendoExacto['estado_cierre'] ?? 0), [3,5], true)) {
            $nivel = 'AUTORIZACION';
        } elseif ($idDocumentoArriendo <= 0
            && in_array((int)($arriendoExacto['estado_cierre'] ?? 0), [3,5], true)) {
            $nivel = 'AUTORIZACION';
        }
        if ($unidadValor === 'UF_BASE') {
            $codigoModalidad = strtoupper(trim((string)($arriendoExacto['codigo_modalidad'] ?? '')));
            if (!in_array($codigoModalidad, ['UF_ESTATICO','DINAMICO_MENSUAL'], true)) {
                throw new RuntimeException('La UF Base solo puede corregirse en modalidades de arriendo expresadas en UF.');
            }
            $valorUfPeriodo = round((float)($arriendoExacto['valor_uf_calculo'] ?? 0), 6);
            if ($valorUfPeriodo <= 0) {
                throw new RuntimeException('El período no tiene un valor UF válido para recalcular el arriendo.');
            }
            $valorUfBaseNuevo = round((float)$valorNuevo, 6);
            if ($valorUfBaseNuevo < 0) {
                throw new RuntimeException('La UF Base corregida debe ser igual o mayor que cero.');
            }
            if ($valorUfBaseNuevo === 0.0 && (string)($_POST['confirmar_cero'] ?? '') !== '1') {
                throw new RuntimeException('Debes confirmar expresamente el arriendo cero para el período.');
            }
            $valorUfBaseAnterior = $valorUfPeriodo > 0
                ? round((float)$arriendoExacto['monto_neto_clp'] / $valorUfPeriodo, 6)
                : round((float)($arriendoExacto['valor_base_uf'] ?? 0), 6);
            if (abs($valorUfBaseNuevo - $valorUfBaseAnterior) < 0.0000005) {
                throw new RuntimeException('La nueva UF Base debe ser diferente del valor actual.');
            }
            $valorNuevo = round($valorUfBaseNuevo * $valorUfPeriodo, 2);
            $arriendoExacto['unidad_correccion'] = 'UF_BASE';
            $arriendoExacto['valor_uf_base_anterior'] = $valorUfBaseAnterior;
            $arriendoExacto['valor_uf_base_nuevo'] = $valorUfBaseNuevo;
            $arriendoExacto['valor_uf_periodo_correccion'] = $valorUfPeriodo;
            $arriendoExacto['cero_autorizado'] = $valorUfBaseNuevo === 0.0 ? 1 : 0;
            $arriendoExacto['id_documento_cobro'] = $idDocumentoArriendo;
        } elseif ($valorNuevo <= 0) {
            throw new RuntimeException('El arriendo corregido debe ser mayor que cero.');
        }
        $valorAnterior = 'monto_neto_clp='.(string)$arriendoExacto['monto_neto_clp']
            .'; monto_total_clp='.(string)$arriendoExacto['monto_total_clp']
            .'; valor_base_uf='.(string)($arriendoExacto['valor_uf_base_anterior'] ?? $arriendoExacto['valor_base_uf'] ?? '');
        $analisis['registro_exacto']=$arriendoExacto;
        $analisis['clasificacion']['nivel']=$nivel;
    }

    $idCorreccion = CorreccionesService::crearSolicitud($conn, [
        'tipo_correccion' => $tipo,
        'modulo_origen' => trim((string) ($_POST['modulo_origen'] ?? 'correcciones/index.php')),
        'periodo_facturacion' => trim((string) ($_POST['periodo_facturacion'] ?? '')),
        'id_contrato_arriendo' => (int) $idContrato,
        'id_tienda' => (int) ($contrato['id_tienda'] ?? 0),
        'id_local' => $idLocalSeleccionado,
        'entidad_afectada' => $entidad,
        'id_registro_origen' => $idRegistroOrigen,
        'estado_correccion' => 'BORRADOR',
        'nivel_correcion' => $nivel,
        'valor_anterior' => $valorAnterior,
        'valor_nuevo' => $valorNuevo,
        'motivo' => trim((string) ($_POST['motivo'] ?? '')),
        'resultado_analisis' => $analisis,
    ], $usuarioId);

    msp2SetFlash('success', 'Solicitud de corrección registrada.');
    msp2Redirect('correcciones/index.php?id_correccion=' . $idCorreccion);
} catch (Throwable $e) {
    pgpLogException($e, 'msp.correcciones.guardar');
    msp2SetFlash($e instanceof RuntimeException ? 'warning' : 'danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible procesar la corrección. Intenta nuevamente o revisa el registro seleccionado.');
    $idContratoRetorno = (int) ($_POST['id_contrato_arriendo'] ?? 0);
    msp2Redirect('correcciones/index.php' . ($idContratoRetorno > 0 ? '?id_contrato_arriendo=' . $idContratoRetorno : ''));
}
