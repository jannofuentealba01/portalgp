<?php
declare(strict_types=1);

final class CorreccionesService
{
    public static function listar(PDO $conn, array $filtros = []): array
    {
        if (!msp2TableExists($conn, 'msp_correcciones')) {
            return [];
        }

        $where = [];
        $params = [];

        $estado = trim((string) ($filtros['estado'] ?? ''));
        if ($estado !== '') {
            $where[] = 'c.estado_correccion = :estado';
            $params[':estado'] = $estado;
        }

        $idContrato = (int) ($filtros['id_contrato_arriendo'] ?? 0);
        if ($idContrato > 0) {
            $where[] = 'c.id_contrato_arriendo = :id_contrato';
            $params[':id_contrato'] = $idContrato;
        }

        $codigo = trim((string) ($filtros['codigo_operacion'] ?? ''));
        if ($codigo !== '') {
            $where[] = 'CONVERT(NVARCHAR(36), c.codigo_operacion) = :codigo';
            $params[':codigo'] = $codigo;
        }

        $sql = 'SELECT TOP (100) c.* FROM dbo.msp_correcciones c';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY c.id_correccion DESC';

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function analizarPorContrato(PDO $conn, int $idContratoArriendo): array
    {
        if ($idContratoArriendo <= 0) {
            throw new RuntimeException('Debes indicar un contrato válido.');
        }

        $contrato = self::fetchContrato($conn, $idContratoArriendo);
        if ($contrato === null) {
            throw new RuntimeException('El contrato indicado no existe.');
        }

        $estadoContrato = (int) ($contrato['estado_contrato'] ?? 0);
        $idTienda = (int) ($contrato['id_tienda'] ?? 0);
        $dependencias = self::fetchDependencias($conn, $idContratoArriendo, $idTienda);

        $nivel = self::clasificar($estadoContrato, $dependencias);

        return [
            'contrato' => $contrato,
            'dependencias' => $dependencias,
            'clasificacion' => $nivel,
        ];
    }

    public static function crearSolicitud(PDO $conn, array $data, int $usuarioSolicitante): int
    {
        if (!msp2TableExists($conn, 'msp_correcciones')) {
            throw new RuntimeException('No existe la tabla de correcciones.');
        }

        $tipo = strtoupper(trim((string) ($data['tipo_correccion'] ?? '')));
        $modulo = trim((string) ($data['modulo_origen'] ?? ''));
        $entidad = trim((string) ($data['entidad_afectada'] ?? ''));
        $motivo = trim((string) ($data['motivo'] ?? ''));
        if ($tipo === '' || $modulo === '' || $entidad === '' || $motivo === '') {
            throw new RuntimeException('Faltan datos obligatorios para registrar la corrección.');
        }

        $estado = strtoupper(trim((string) ($data['estado_correccion'] ?? 'BORRADOR')));
        $nivel = strtoupper(trim((string) ($data['nivel_correcion'] ?? 'REVISION')));
        $periodo = trim((string) ($data['periodo_facturacion'] ?? ''));
        $fechaPeriodo = null;
        if ($periodo !== '' && preg_match('/^\d{4}-\d{2}$/', $periodo)) {
            $fechaPeriodo = $periodo . '-01';
        }

        $stmt = $conn->prepare(
            'INSERT INTO dbo.msp_correcciones (
                tipo_correccion, modulo_origen, periodo_facturacion, id_contrato_arriendo, id_tienda, id_local,
                entidad_afectada, id_registro_origen, estado_correccion, nivel_correcion,
                valor_anterior, valor_nuevo, motivo, resultado_analisis, usuario_solicitante
            ) VALUES (
                :tipo_correccion, :modulo_origen, :periodo_facturacion, :id_contrato_arriendo, :id_tienda, :id_local,
                :entidad_afectada, :id_registro_origen, :estado_correccion, :nivel_correcion,
                :valor_anterior, :valor_nuevo, :motivo, :resultado_analisis, :usuario_solicitante
            )'
        );
        $stmt->execute([
            ':tipo_correccion' => $tipo,
            ':modulo_origen' => $modulo,
            ':periodo_facturacion' => $fechaPeriodo,
            ':id_contrato_arriendo' => ((int) ($data['id_contrato_arriendo'] ?? 0)) ?: null,
            ':id_tienda' => ((int) ($data['id_tienda'] ?? 0)) ?: null,
            ':id_local' => ((int) ($data['id_local'] ?? 0)) ?: null,
            ':entidad_afectada' => $entidad,
            ':id_registro_origen' => ((int) ($data['id_registro_origen'] ?? 0)) ?: null,
            ':estado_correccion' => $estado,
            ':nivel_correcion' => $nivel,
            ':valor_anterior' => isset($data['valor_anterior']) ? json_encode($data['valor_anterior'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ':valor_nuevo' => isset($data['valor_nuevo']) ? json_encode($data['valor_nuevo'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ':motivo' => $motivo,
            ':resultado_analisis' => isset($data['resultado_analisis']) ? json_encode($data['resultado_analisis'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ':usuario_solicitante' => $usuarioSolicitante,
        ]);

        $id = (int) $conn->lastInsertId();
        self::registrarEvento($conn, $id, 'SOLICITUD', 'Corrección registrada.', null, $estado, $usuarioSolicitante, ['tipo' => $tipo, 'modulo' => $modulo]);
        return $id;
    }

    public static function cambiarEstado(PDO $conn, int $idCorreccion, string $estadoNuevo, int $usuario, string $detalle = '', ?array $payload = null): void
    {
        if ($idCorreccion <= 0) {
            throw new RuntimeException('Corrección inválida.');
        }
        if (!msp2TableExists($conn, 'msp_correcciones')) {
            throw new RuntimeException('No existe la tabla de correcciones.');
        }

        $stmt = $conn->prepare('SELECT estado_correccion FROM dbo.msp_correcciones WHERE id_correccion = :id');
        $stmt->execute([':id' => $idCorreccion]);
        $estadoActual = (string) ($stmt->fetchColumn() ?: '');
        if ($estadoActual === '') {
            throw new RuntimeException('La corrección no existe.');
        }

        $estadoDestino = strtoupper(trim($estadoNuevo));
        $transiciones = [
            'BORRADOR' => ['ANALIZADA', 'PENDIENTE_APROBACION', 'APROBADA', 'RECHAZADA', 'CANCELADA'],
            'ANALIZADA' => ['PENDIENTE_APROBACION', 'APROBADA', 'RECHAZADA', 'CANCELADA'],
            'PENDIENTE_APROBACION' => ['APROBADA', 'RECHAZADA', 'CANCELADA'],
            'APROBADA' => ['EJECUTANDO', 'CANCELADA', 'ERROR'],
            'EJECUTANDO' => ['EJECUTADA', 'ERROR'],
            'ERROR' => ['PENDIENTE_APROBACION', 'APROBADA', 'CANCELADA'],
        ];
        if ($estadoDestino !== $estadoActual && !in_array($estadoDestino, $transiciones[$estadoActual] ?? [], true)) {
            throw new RuntimeException('La corrección no puede pasar de '.$estadoActual.' a '.$estadoDestino.'.');
        }

        $upd = $conn->prepare(
            'UPDATE dbo.msp_correcciones
             SET estado_correccion = :estado, fecha_actualizacion = SYSDATETIME(),
                 usuario_aprobador = CASE WHEN :marca_aprobado_usuario = 1 THEN :usuario_aprobador ELSE usuario_aprobador END,
                 fecha_aprobacion = CASE WHEN :marca_aprobado_fecha = 1 THEN SYSDATETIME() ELSE fecha_aprobacion END,
                 usuario_ejecutor = CASE WHEN :marca_ejecutado_usuario = 1 THEN :usuario_ejecutor ELSE usuario_ejecutor END,
                 fecha_ejecucion = CASE WHEN :marca_ejecutado_fecha = 1 THEN SYSDATETIME() ELSE fecha_ejecucion END
             WHERE id_correccion = :id'
        );
        $upd->execute([
            ':estado' => $estadoDestino,
            ':marca_aprobado_usuario' => $estadoDestino === 'APROBADA' ? 1 : 0,
            ':usuario_aprobador' => $usuario,
            ':marca_aprobado_fecha' => $estadoDestino === 'APROBADA' ? 1 : 0,
            ':marca_ejecutado_usuario' => $estadoDestino === 'EJECUTADA' ? 1 : 0,
            ':usuario_ejecutor' => $usuario,
            ':marca_ejecutado_fecha' => $estadoDestino === 'EJECUTADA' ? 1 : 0,
            ':id' => $idCorreccion,
        ]);

        self::registrarEvento(
            $conn,
            $idCorreccion,
            'ESTADO',
            $detalle !== '' ? $detalle : ('Estado ' . strtoupper(trim($estadoNuevo))),
            $estadoActual,
            $estadoDestino,
            $usuario,
            $payload
        );
    }

    /**
     * Ejecuta la primera estrategia segura de corrección: lectura sin efectos
     * financieros posteriores. Nunca modifica documentos, pagos ni asientos.
     */
    public static function ejecutar(PDO $conn, int $idCorreccion, int $usuario): array
    {
        $corr = self::obtener($conn, $idCorreccion);
        if ($corr === null) {
            throw new RuntimeException('La corrección no existe.');
        }
        if (strtoupper((string) ($corr['estado_correccion'] ?? '')) !== 'APROBADA') {
            throw new RuntimeException('Solo se puede ejecutar una corrección aprobada.');
        }
        if (strtoupper((string) ($corr['nivel_correcion'] ?? '')) !== 'EDICION_SIMPLE') {
            throw new RuntimeException('Esta corrección requiere una estrategia financiera controlada y todavía no puede ejecutarse automáticamente.');
        }
        $tipo = strtoupper((string) ($corr['tipo_correccion'] ?? ''));
        if ($tipo === 'CARGO') { return self::ejecutarCargoSimple($conn, $corr, $usuario); }
        if ($tipo === 'ARRIENDO_PERIODO') { return self::ejecutarArriendoSimple($conn, $corr, $usuario); }
        if ($tipo !== 'LECTURA') { throw new RuntimeException('La corrección seleccionada no tiene una estrategia de ejecución segura.'); }
        $idLectura = (int) ($corr['id_registro_origen'] ?? 0);
        $idContrato = (int) ($corr['id_contrato_arriendo'] ?? 0);
        if ($idLectura <= 0 || $idContrato <= 0) {
            throw new RuntimeException('La corrección de lectura requiere ID de lectura y contrato.');
        }
        if (!msp2TableExists($conn, 'msp_lecturas_medidores') || !msp2TableExists($conn, 'msp_medidores') || !msp2TableExists($conn, 'msp_contrato_locales')) {
            throw new RuntimeException('No están disponibles las tablas necesarias para corregir la lectura.');
        }

        $nuevo = self::valorLectura($corr['valor_nuevo'] ?? null);
        if ($nuevo === null || $nuevo < 0) {
            throw new RuntimeException('Indica un valor nuevo válido, por ejemplo: lectura_actual=1234.');
        }

        $stmt = $conn->prepare(
            'SELECT TOP(1) lm.id_lectura,lm.lectura_anterior,lm.lectura_actual,lm.consumo_informado,lm.periodo_facturacion,
                    m.id_medidor,cl.id_contrato_arriendo,cs.id_cobro_servicio,cs.monto_total monto_cobro_anterior,
                    dd.id_documento_cobro
             FROM dbo.msp_lecturas_medidores lm
             INNER JOIN dbo.msp_medidores m ON m.id_medidor=lm.id_medidor
             INNER JOIN dbo.msp_contrato_locales cl ON cl.id_local=m.id_local
             LEFT JOIN dbo.msp_cobros_servicios cs ON cs.id_lectura=lm.id_lectura
             LEFT JOIN dbo.msp_documentos_cobro_detalle dd ON dd.id_cobro_servicio=cs.id_cobro_servicio
             WHERE lm.id_lectura=:id_lectura AND cl.id_contrato_arriendo=:id_contrato'
        );
        $stmt->execute([':id_lectura' => $idLectura, ':id_contrato' => $idContrato]);
        $lectura = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($lectura === false) {
            throw new RuntimeException('La lectura no pertenece al contrato indicado.');
        }
        if ((int)($lectura['id_documento_cobro'] ?? 0) > 0) {
            throw new RuntimeException('La lectura fue incorporada a un documento después del análisis. Vuelve a analizar la corrección.');
        }
        $lecturaEsperada = self::valorCampo($corr['valor_anterior'] ?? null, 'lectura_actual');
        if ($lecturaEsperada !== null && abs((float)$lectura['lectura_actual'] - $lecturaEsperada) > 0.0001) {
            throw new RuntimeException('La lectura cambió después del análisis. Vuelve a analizar la corrección.');
        }
        $anterior = $lectura['lectura_anterior'] !== null ? (float) $lectura['lectura_anterior'] : null;
        if ($anterior !== null && $nuevo < $anterior) {
            throw new RuntimeException('La lectura nueva no puede ser menor que la lectura anterior.');
        }
        $qSiguiente=$conn->prepare('SELECT TOP(1) lectura_actual FROM dbo.msp_lecturas_medidores WHERE id_medidor=:m AND periodo_facturacion>:p ORDER BY periodo_facturacion,id_lectura');
        $qSiguiente->execute([':m'=>(int)$lectura['id_medidor'], ':p'=>(string)$lectura['periodo_facturacion']]);
        $siguiente=$qSiguiente->fetchColumn();
        if ($siguiente !== false && $siguiente !== null && $nuevo > (float)$siguiente) {
            throw new RuntimeException('La lectura nueva no puede superar la lectura del período siguiente.');
        }
        $consumo = $anterior === null ? null : round($nuevo - $anterior, 4);

        $transaccionPropia = !$conn->inTransaction();
        if ($transaccionPropia) { $conn->beginTransaction(); }
        try {
            $claim = $conn->prepare("UPDATE dbo.msp_correcciones SET estado_correccion='EJECUTANDO', estrategia_ejecucion='LECTURA', fecha_actualizacion=SYSDATETIME() WHERE id_correccion=:id AND estado_correccion='APROBADA'");
            $claim->execute([':id' => $idCorreccion]);
            if ($claim->rowCount() !== 1) {
                throw new RuntimeException('La corrección ya está siendo ejecutada o fue ejecutada anteriormente.');
            }
            $upd = $conn->prepare(
                'UPDATE dbo.msp_lecturas_medidores
                 SET lectura_actual=:lectura_actual,
                     consumo_informado=:consumo_informado,
                     fecha_actualizacion=SYSDATETIME()
                 WHERE id_lectura=:id_lectura'
            );
            $upd->bindValue(':lectura_actual', $nuevo);
            if ($consumo === null) {
                $upd->bindValue(':consumo_informado', null, PDO::PARAM_NULL);
            } else {
                $upd->bindValue(':consumo_informado', $consumo);
            }
            $upd->bindValue(':id_lectura', $idLectura, PDO::PARAM_INT);
            $upd->execute();
            if ((int) ($lectura['id_cobro_servicio'] ?? 0) > 0) {
                $recalculo = $conn->prepare(
                    "UPDATE cs SET
                        consumo_cobrado=:consumo,
                        subtotal_variable=ROUND(CASE
                            WHEN ts.codigo_servicio=N'LUZ' THEN :consumo_luz*ISNULL(pl.valor_kwh,0)
                            WHEN ts.codigo_servicio=N'GAS' THEN :consumo_gas*ISNULL(pg.factor,0)*ISNULL(pg.valor_litro,0)
                            WHEN ts.codigo_servicio=N'AGUA' THEN :consumo_agua*((ISNULL(pa.servicio_agua_potable,0)+ISNULL(pa.servicio_alcantarillado,0)+ISNULL(pa.tratamiento_aguas_servidas,0)+ISNULL(pa.sobreconsumo,0)+ISNULL(pa.interes_pf_plazo,0))/NULLIF(pa.divisor,0))
                            ELSE 0 END,2),
                        cargo_fijo=ROUND(CASE WHEN ts.codigo_servicio=N'AGUA' THEN ISNULL(pa.cargo_fijo,0)/NULLIF(pa.divisor,0) ELSE 0 END,2),
                        monto_total=ROUND(CASE
                            WHEN ts.codigo_servicio=N'LUZ' THEN :consumo_luz_total*ISNULL(pl.valor_kwh,0)
                            WHEN ts.codigo_servicio=N'GAS' THEN :consumo_gas_total*ISNULL(pg.factor,0)*ISNULL(pg.valor_litro,0)
                            WHEN ts.codigo_servicio=N'AGUA' THEN :consumo_agua_total*((ISNULL(pa.servicio_agua_potable,0)+ISNULL(pa.servicio_alcantarillado,0)+ISNULL(pa.tratamiento_aguas_servidas,0)+ISNULL(pa.sobreconsumo,0)+ISNULL(pa.interes_pf_plazo,0))/NULLIF(pa.divisor,0))+ISNULL(pa.cargo_fijo,0)/NULLIF(pa.divisor,0)
                            ELSE 0 END,2),
                        detalle_calculo=N'Corrección selectiva de lectura',fecha_calculo=SYSDATETIME()
                     FROM dbo.msp_cobros_servicios cs
                     INNER JOIN dbo.msp_lecturas_medidores lm ON lm.id_lectura=cs.id_lectura
                     INNER JOIN dbo.msp_procesos_cobro_servicio p ON p.id_proceso_cobro=lm.id_proceso_cobro
                     INNER JOIN dbo.msp_tipos_servicio ts ON ts.id_tipo_servicio=p.id_tipo_servicio
                     LEFT JOIN dbo.msp_proceso_cobro_luz pl ON pl.id_proceso_cobro=p.id_proceso_cobro
                     LEFT JOIN dbo.msp_proceso_cobro_gas pg ON pg.id_proceso_cobro=p.id_proceso_cobro
                     LEFT JOIN dbo.msp_proceso_cobro_agua pa ON pa.id_proceso_cobro=p.id_proceso_cobro
                     WHERE cs.id_cobro_servicio=:id_cobro"
                );
                $recalculo->execute([
                    ':consumo'=>$consumo ?? 0, ':consumo_luz'=>$consumo ?? 0, ':consumo_gas'=>$consumo ?? 0,
                    ':consumo_agua'=>$consumo ?? 0, ':consumo_luz_total'=>$consumo ?? 0,
                    ':consumo_gas_total'=>$consumo ?? 0, ':consumo_agua_total'=>$consumo ?? 0,
                    ':id_cobro'=>(int)$lectura['id_cobro_servicio'],
                ]);
            }
            if (msp2TableExists($conn, 'msp_correcciones_impactos')) {
                $impact = $conn->prepare("INSERT dbo.msp_correcciones_impactos(id_correccion,tipo_entidad,id_registro,accion_prevista,valor_anterior,valor_nuevo,es_financiero) VALUES(:i,N'LECTURA',:r,N'UPDATE',:a,:n,0)");
                $impact->execute([':i' => $idCorreccion, ':r' => $idLectura, ':a' => (string) $lectura['lectura_actual'], ':n' => (string) $nuevo]);
                if ((int) ($lectura['id_cobro_servicio'] ?? 0) > 0) {
                    $impactCobro = $conn->prepare("INSERT dbo.msp_correcciones_impactos(id_correccion,tipo_entidad,id_registro,accion_prevista,valor_anterior,valor_nuevo,es_financiero) VALUES(:i,N'COBRO_SERVICIO',:r,N'RECALCULO',:a,:n,0)");
                    $impactCobro->execute([':i' => $idCorreccion, ':r' => (int)$lectura['id_cobro_servicio'], ':a' => (string)($lectura['monto_cobro_anterior'] ?? ''), ':n' => 'Recalculado']);
                }
            }
            self::cambiarEstado($conn, $idCorreccion, 'EJECUTADA', $usuario, 'Lectura corregida sin efectos financieros posteriores.', [
                'estrategia' => 'LECTURA_SIN_DOCUMENTO',
                'id_lectura' => $idLectura,
                'lectura_anterior' => (float) $lectura['lectura_actual'],
                'lectura_nueva' => $nuevo,
                'consumo_recalculado' => $consumo,
            ]);
            if ($transaccionPropia) { $conn->commit(); }
        } catch (Throwable $e) {
            if ($transaccionPropia && $conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }

        return ['id_lectura' => $idLectura, 'lectura_nueva' => $nuevo, 'consumo_recalculado' => $consumo];
    }

    private static function ejecutarCargoSimple(PDO $conn, array $corr, int $usuario): array
    {
        $idCorreccion = (int) ($corr['id_correccion'] ?? 0);
        $idCargo = (int) ($corr['id_registro_origen'] ?? 0);
        $idContrato = (int) ($corr['id_contrato_arriendo'] ?? 0);
        $nuevo = self::valorLectura($corr['valor_nuevo'] ?? null);
        if ($idCargo <= 0 || $idContrato <= 0 || $nuevo === null || $nuevo <= 0) {
            throw new RuntimeException('La corrección de cargo no contiene un monto válido.');
        }
        $q = $conn->prepare(
            'SELECT ccl.id_cargo_contrato_local,ccl.monto_cargo,ccl.estado_cargo,ccl.id_documento_cobro,
                    ccl.monto_aplicado_garantia,ccl.monto_pagado_directo
             FROM dbo.msp_cargos_contrato_local ccl
             INNER JOIN dbo.msp_contrato_locales cl ON cl.id_contrato_local=ccl.id_contrato_local
             WHERE ccl.id_cargo_contrato_local=:cargo AND cl.id_contrato_arriendo=:contrato'
        );
        $q->execute([':cargo'=>$idCargo, ':contrato'=>$idContrato]);
        $cargo = $q->fetch(PDO::FETCH_ASSOC);
        if (!$cargo) { throw new RuntimeException('El cargo ya no existe o no pertenece al contrato.'); }
        $montoEsperado=self::valorCampo($corr['valor_anterior'] ?? null,'monto_cargo');
        if ($montoEsperado !== null && abs((float)$cargo['monto_cargo']-$montoEsperado)>0.01) {
            throw new RuntimeException('El cargo cambió después del análisis. Vuelve a analizar la corrección.');
        }
        if ((int)($cargo['estado_cargo'] ?? 0) !== 1 || (int)($cargo['id_documento_cobro'] ?? 0) > 0
            || (float)($cargo['monto_aplicado_garantia'] ?? 0) > 0 || (float)($cargo['monto_pagado_directo'] ?? 0) > 0) {
            throw new RuntimeException('El cargo cambió después del análisis o ya tiene efectos financieros. Vuelve a analizarlo.');
        }
        $transaccionPropia = !$conn->inTransaction();
        if ($transaccionPropia) { $conn->beginTransaction(); }
        try {
            $claim=$conn->prepare("UPDATE dbo.msp_correcciones SET estado_correccion=N'EJECUTANDO',estrategia_ejecucion=N'CARGO_SIMPLE',fecha_actualizacion=SYSDATETIME() WHERE id_correccion=:id AND estado_correccion=N'APROBADA'");
            $claim->execute([':id'=>$idCorreccion]);
            if ($claim->rowCount() !== 1) { throw new RuntimeException('La corrección ya fue ejecutada o está siendo procesada.'); }
            $upd=$conn->prepare('UPDATE dbo.msp_cargos_contrato_local SET monto_cargo=:monto WHERE id_cargo_contrato_local=:id');
            $upd->execute([':monto'=>$nuevo, ':id'=>$idCargo]);
            if (msp2TableExists($conn, 'msp_correcciones_impactos')) {
                $impact=$conn->prepare("INSERT dbo.msp_correcciones_impactos(id_correccion,tipo_entidad,id_registro,accion_prevista,valor_anterior,valor_nuevo,es_financiero) VALUES(:c,N'CARGO',:r,N'UPDATE',:a,:n,0)");
                $impact->execute([':c'=>$idCorreccion, ':r'=>$idCargo, ':a'=>(string)$cargo['monto_cargo'], ':n'=>(string)$nuevo]);
            }
            self::cambiarEstado($conn,$idCorreccion,'EJECUTADA',$usuario,'Cargo pendiente corregido sin alterar documentos ni pagos.');
            if ($transaccionPropia) { $conn->commit(); }
        } catch (Throwable $e) {
            if ($transaccionPropia && $conn->inTransaction()) { $conn->rollBack(); }
            throw $e;
        }
        return ['id_cargo'=>$idCargo,'monto_anterior'=>(float)$cargo['monto_cargo'],'monto_nuevo'=>$nuevo];
    }

    private static function ejecutarArriendoSimple(PDO $conn, array $corr, int $usuario): array
    {
        $idCorreccion = (int) ($corr['id_correccion'] ?? 0);
        $idSnapshot = (int) ($corr['id_registro_origen'] ?? 0);
        $idContrato = (int) ($corr['id_contrato_arriendo'] ?? 0);
        $nuevo = self::valorLectura($corr['valor_nuevo'] ?? null);
        if ($idSnapshot <= 0 || $idContrato <= 0 || $nuevo === null || $nuevo <= 0) {
            throw new RuntimeException('La corrección de arriendo no contiene un monto válido.');
        }
        $q=$conn->prepare('SELECT * FROM dbo.msp_arriendo_local_snapshot_periodo WHERE id_snapshot_arriendo=:s AND id_contrato_arriendo=:c');
        $q->execute([':s'=>$idSnapshot, ':c'=>$idContrato]);
        $snapshot=$q->fetch(PDO::FETCH_ASSOC);
        if (!$snapshot) { throw new RuntimeException('El arriendo mensual ya no existe o no pertenece al contrato.'); }
        $montoEsperado=self::valorCampo($corr['valor_anterior'] ?? null,'monto_neto_clp');
        if ($montoEsperado !== null && abs((float)$snapshot['monto_neto_clp']-$montoEsperado)>0.01) {
            throw new RuntimeException('El arriendo mensual cambió después del análisis. Vuelve a analizar la corrección.');
        }
        $qDoc=$conn->prepare('SELECT COUNT(*) FROM dbo.msp_documentos_cobro WHERE id_contrato_arriendo=:c AND periodo_facturacion=:p');
        $qDoc->execute([':c'=>$idContrato, ':p'=>(string)$snapshot['periodo_facturacion']]);
        if ((int)$qDoc->fetchColumn() > 0) {
            throw new RuntimeException('El arriendo ya tiene documento. Requiere regeneración o ajuste financiero controlado.');
        }
        $netoAnterior=(float)$snapshot['monto_neto_clp'];
        $tasaIva=$netoAnterior > 0 ? ((float)$snapshot['monto_iva_clp']/$netoAnterior) : 0.19;
        $iva=round($nuevo*$tasaIva,2); $total=round($nuevo+$iva,2);
        $transaccionPropia = !$conn->inTransaction();
        if ($transaccionPropia) { $conn->beginTransaction(); }
        try {
            $claim=$conn->prepare("UPDATE dbo.msp_correcciones SET estado_correccion=N'EJECUTANDO',estrategia_ejecucion=N'ARRIENDO_PERIODO_SIMPLE',fecha_actualizacion=SYSDATETIME() WHERE id_correccion=:id AND estado_correccion=N'APROBADA'");
            $claim->execute([':id'=>$idCorreccion]);
            if ($claim->rowCount() !== 1) { throw new RuntimeException('La corrección ya fue ejecutada o está siendo procesada.'); }
            if (msp2TableExists($conn, 'msp_arriendo_ajustes_periodo')) {
                $desactivar=$conn->prepare('UPDATE dbo.msp_arriendo_ajustes_periodo SET estado_ajuste=0 WHERE id_contrato_local=:cl AND periodo_facturacion=:p AND estado_ajuste=1');
                $desactivar->execute([':cl'=>(int)$snapshot['id_contrato_local'], ':p'=>(string)$snapshot['periodo_facturacion']]);
                $ins=$conn->prepare('INSERT dbo.msp_arriendo_ajustes_periodo(id_contrato_local,periodo_facturacion,monto_correcto_clp,motivo,id_correccion,usuario_registro) VALUES(:cl,:p,:m,:motivo,:c,:u)');
                $ins->execute([':cl'=>(int)$snapshot['id_contrato_local'], ':p'=>(string)$snapshot['periodo_facturacion'], ':m'=>$nuevo, ':motivo'=>(string)$corr['motivo'], ':c'=>$idCorreccion, ':u'=>$usuario]);
            }
            $upd=$conn->prepare("UPDATE dbo.msp_arriendo_local_snapshot_periodo SET monto_neto_clp=:n,monto_iva_clp=:i,monto_total_clp=:t,fuente_calculo=N'CORRECCION_SELECTIVA',fecha_actualizacion=SYSDATETIME() WHERE id_snapshot_arriendo=:s");
            $upd->execute([':n'=>$nuevo, ':i'=>$iva, ':t'=>$total, ':s'=>$idSnapshot]);
            if (msp2TableExists($conn, 'msp_correcciones_impactos')) {
                $impact=$conn->prepare("INSERT dbo.msp_correcciones_impactos(id_correccion,tipo_entidad,id_registro,accion_prevista,valor_anterior,valor_nuevo,es_financiero) VALUES(:c,N'ARRIENDO_SNAPSHOT',:r,N'UPDATE',:a,:n,0)");
                $impact->execute([':c'=>$idCorreccion, ':r'=>$idSnapshot, ':a'=>(string)$netoAnterior, ':n'=>(string)$nuevo]);
            }
            self::cambiarEstado($conn,$idCorreccion,'EJECUTADA',$usuario,'Arriendo mensual corregido sin modificar la regla contractual ni otros períodos.');
            if ($transaccionPropia) { $conn->commit(); }
        } catch (Throwable $e) {
            if ($transaccionPropia && $conn->inTransaction()) { $conn->rollBack(); }
            throw $e;
        }
        return ['id_snapshot'=>$idSnapshot,'monto_anterior'=>$netoAnterior,'monto_nuevo'=>$nuevo];
    }

    private static function valorLectura(mixed $raw): ?float
    {
        $texto = trim((string) $raw);
        if ($texto === '') {
            return null;
        }
        $decoded = json_decode($texto, true);
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }
        if (is_array($decoded)) {
            foreach (['lectura_actual', 'valor', 'value'] as $key) {
                if (isset($decoded[$key]) && is_numeric($decoded[$key])) {
                    return (float) $decoded[$key];
                }
            }
        }
        if (preg_match('/lectura_actual\s*[:=]\s*([0-9]+(?:[\.,][0-9]+)?)/iu', $texto, $match) === 1) {
            return (float) str_replace(',', '.', $match[1]);
        }
        return is_numeric(str_replace(',', '.', $texto)) ? (float) str_replace(',', '.', $texto) : null;
    }

    private static function valorCampo(mixed $raw, string $campo): ?float
    {
        $texto=trim((string)$raw);
        if ($texto==='') { return null; }
        $decoded=json_decode($texto,true);
        if (is_string($decoded)) { $texto=$decoded; }
        elseif (is_array($decoded) && isset($decoded[$campo]) && is_numeric($decoded[$campo])) { return (float)$decoded[$campo]; }
        if (preg_match('/'.preg_quote($campo,'/').'\s*[:=]\s*([0-9]+(?:[\.,][0-9]+)?)/iu',$texto,$match)===1) {
            return (float)str_replace(',','.',$match[1]);
        }
        return null;
    }

    public static function registrarEvento(PDO $conn, int $idCorreccion, string $tipo, string $detalle, ?string $estadoAnterior, ?string $estadoNuevo, int $usuario, ?array $payload = null): void
    {
        if (!msp2TableExists($conn, 'msp_correcciones_eventos')) {
            return;
        }

        $stmt = $conn->prepare(
            'INSERT INTO dbo.msp_correcciones_eventos
                (id_correccion, tipo_evento, detalle, estado_anterior, estado_nuevo, payload_json, usuario_evento)
             VALUES
                (:id_correccion, :tipo_evento, :detalle, :estado_anterior, :estado_nuevo, :payload_json, :usuario_evento)'
        );
        $stmt->execute([
            ':id_correccion' => $idCorreccion,
            ':tipo_evento' => strtoupper(trim($tipo)),
            ':detalle' => $detalle,
            ':estado_anterior' => $estadoAnterior,
            ':estado_nuevo' => $estadoNuevo,
            ':payload_json' => $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ':usuario_evento' => $usuario,
        ]);
    }

    public static function obtener(PDO $conn, int $idCorreccion): ?array
    {
        if (!msp2TableExists($conn, 'msp_correcciones')) {
            return null;
        }

        $stmt = $conn->prepare('SELECT * FROM dbo.msp_correcciones WHERE id_correccion = :id');
        $stmt->execute([':id' => $idCorreccion]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        if (msp2TableExists($conn, 'msp_correcciones_eventos')) {
            $ev = $conn->prepare('SELECT * FROM dbo.msp_correcciones_eventos WHERE id_correccion = :id ORDER BY fecha_evento DESC, id_evento_correcion DESC');
            $ev->execute([':id' => $idCorreccion]);
            $row['eventos'] = $ev->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        return $row;
    }

    private static function fetchContrato(PDO $conn, int $idContratoArriendo): ?array
    {
        if (!msp2TableExists($conn, 'msp_contratos_arriendo')) {
            return null;
        }

        $stmt = $conn->prepare(
            'SELECT c.id_contrato_arriendo, c.id_tienda, c.id_arrendatario, c.fecha_inicio, c.fecha_termino_pactada,
                    c.fecha_termino_efectiva, c.estado_contrato, t.nombre_comercial, a.nombre_locatario, a.rut
             FROM dbo.msp_contratos_arriendo c
             INNER JOIN dbo.msp_tiendas t ON t.id_tienda = c.id_tienda
             INNER JOIN dbo.msp_arrendatarios a ON a.id_arrendatario = c.id_arrendatario
             WHERE c.id_contrato_arriendo = :id'
        );
        $stmt->bindValue(':id', $idContratoArriendo, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    private static function fetchDependencias(PDO $conn, int $idContratoArriendo, int $idTienda): array
    {
        $result = [
            'locales' => [],
            'documentos' => [],
            'pagos' => [],
            'garantias' => [],
            'cargos' => [],
            'tesoreria' => [],
            'contabilidad' => [],
            'cierre_mensual' => [],
            'alertas' => [],
        ];

        if (msp2TableExists($conn, 'msp_contrato_locales') && msp2TableExists($conn, 'msp_locales')) {
            $stmt = $conn->prepare(
                'SELECT cl.id_contrato_local, cl.id_local, cl.fecha_inicio, cl.fecha_termino, cl.estado_relacion, l.cdo_local
                 FROM dbo.msp_contrato_locales cl
                 INNER JOIN dbo.msp_locales l ON l.id_local = cl.id_local
                 WHERE cl.id_contrato_arriendo = :id_contrato
                 ORDER BY ' . msp2LocalCodeNaturalOrderSql('l.cdo_local')
            );
            $stmt->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
            $stmt->execute();
            $result['locales'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        if (msp2TableExists($conn, 'msp_documentos_cobro')) {
            $stmt = $conn->prepare(
                'SELECT TOP (25) dc.id_documento_cobro, dc.numero_documento, dc.periodo_facturacion, dc.estado_documento,
                        dc.monto_total, dc.saldo_pendiente, dc.fecha_vencimiento
                 FROM dbo.msp_documentos_cobro dc
                 WHERE dc.id_contrato_arriendo = :id_contrato
                 ORDER BY dc.periodo_facturacion DESC, dc.id_documento_cobro DESC'
            );
            $stmt->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
            $stmt->execute();
            $result['documentos'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        if (msp2TableExists($conn, 'msp_pagos')) {
            $stmt = $conn->prepare(
                'SELECT TOP (25) p.id_pago, p.fecha_pago, p.monto_pagado, p.estado_pago, p.id_documento_cobro, dc.numero_documento
                 FROM dbo.msp_pagos p
                 INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro = p.id_documento_cobro
                 WHERE dc.id_contrato_arriendo = :id_contrato
                 ORDER BY p.fecha_pago DESC, p.id_pago DESC'
            );
            $stmt->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
            $stmt->execute();
            $result['pagos'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        if (msp2TableExists($conn, 'msp_garantias') && msp2TableExists($conn, 'msp_vw_garantias_control_integral')) {
            $stmt = $conn->prepare(
                'SELECT g.id_garantia, g.id_local, l.cdo_local,
                        gr.monto_pactado, gr.monto_recibido, gr.monto_pendiente_recepcion,
                        gr.monto_reservado, gr.monto_aplicado, gr.monto_devuelto, gr.monto_disponible,
                        g.estado_garantia
                 FROM dbo.msp_garantias g
                 INNER JOIN dbo.msp_vw_garantias_control_integral gr ON gr.id_garantia = g.id_garantia
                 INNER JOIN dbo.msp_locales l ON l.id_local = g.id_local
                 WHERE g.id_contrato_arriendo = :id_contrato
                 ORDER BY l.cdo_local ASC'
            );
            $stmt->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
            $stmt->execute();
            $result['garantias'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        if (msp2TableExists($conn, 'msp_cargos_contrato_local')) {
            $stmt = $conn->prepare(
                'SELECT TOP (100) ccl.id_cargo_contrato_local, ccl.monto_cargo, ccl.estado_cargo, ccl.fecha_cargo,
                 ccl.periodo_referencia, ccl.id_documento_cobro, ccl.monto_aplicado_garantia,
                 ccl.monto_pagado_directo, ccl.descripcion_cargo,
                 cl.id_contrato_local, cl.id_local, l.cdo_local
                 FROM dbo.msp_cargos_contrato_local ccl
                 INNER JOIN dbo.msp_contrato_locales cl ON cl.id_contrato_local = ccl.id_contrato_local
                 INNER JOIN dbo.msp_locales l ON l.id_local = cl.id_local
                 WHERE cl.id_contrato_arriendo = :id_contrato
                 ORDER BY ccl.fecha_cargo DESC, ccl.id_cargo_contrato_local DESC'
            );
            $stmt->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
            $stmt->execute();
            $result['cargos'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        if (msp2TableExists($conn, 'msp_tesoreria_movimientos')) {
            $stmt = $conn->prepare(
                'SELECT TOP (20) tm.id_movimiento_tesoreria, tm.fecha_movimiento, tm.monto, tm.naturaleza, tm.estado_movimiento,
                        tm.conciliado, tm.id_conciliacion_tesoreria
                 FROM dbo.msp_tesoreria_movimientos tm
                 WHERE EXISTS (
                    SELECT 1
                    FROM dbo.msp_garantia_recepciones gr
                    INNER JOIN dbo.msp_garantias g ON g.id_garantia = gr.id_garantia
                    WHERE gr.id_recepcion_garantia = tm.id_recepcion_garantia
                      AND g.id_contrato_arriendo = :id_contrato_recepcion
                 ) OR EXISTS (
                    SELECT 1
                    FROM dbo.msp_movimientos_garantia mg
                    INNER JOIN dbo.msp_garantias g ON g.id_garantia = mg.id_garantia
                    WHERE mg.id_movimiento_garantia = tm.id_movimiento_garantia
                      AND g.id_contrato_arriendo = :id_contrato_movimiento
                 )
                 ORDER BY tm.fecha_movimiento DESC, tm.id_movimiento_tesoreria DESC'
            );
            $stmt->bindValue(':id_contrato_recepcion', $idContratoArriendo, PDO::PARAM_INT);
            $stmt->bindValue(':id_contrato_movimiento', $idContratoArriendo, PDO::PARAM_INT);
            $stmt->execute();
            $result['tesoreria'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        if (msp2TableExists($conn, 'msp_acc_asientos') && msp2TableExists($conn, 'msp_acc_asientos_detalle')) {
            $stmt = $conn->prepare(
                'SELECT TOP (20) a.id_asiento_contable, a.fecha_contable, a.estado_asiento, a.id_tipo_movimiento, a.id_asiento_reversado,
                        d.id_asiento_detalle, d.debe, d.haber
                 FROM dbo.msp_acc_asientos a
                 INNER JOIN dbo.msp_acc_asientos_detalle d ON d.id_asiento_contable = a.id_asiento_contable
                 WHERE d.id_documento_cobro IN (
                        SELECT dc.id_documento_cobro
                        FROM dbo.msp_documentos_cobro dc
                        WHERE dc.id_contrato_arriendo = :id_contrato_documento
                    ) OR d.id_garantia IN (
                        SELECT g.id_garantia
                        FROM dbo.msp_garantias g
                        WHERE g.id_contrato_arriendo = :id_contrato_garantia
                    )
                 ORDER BY a.fecha_contable DESC, a.id_asiento_contable DESC, d.id_asiento_detalle DESC'
            );
            $stmt->bindValue(':id_contrato_documento', $idContratoArriendo, PDO::PARAM_INT);
            $stmt->bindValue(':id_contrato_garantia', $idContratoArriendo, PDO::PARAM_INT);
            $stmt->execute();
            $result['contabilidad'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        if (msp2TableExists($conn, 'msp_cierre_mensual')) {
            $stmt = $conn->prepare(
                'SELECT TOP (12) id_cierre_mensual, periodo_facturacion, estado_cierre, valor_uf, fecha_valor_uf, fecha_registro
                 FROM dbo.msp_cierre_mensual
                 WHERE periodo_facturacion >= DATEADD(MONTH, -12, CONVERT(date, SYSDATETIME()))
                 ORDER BY periodo_facturacion DESC, id_cierre_mensual DESC'
            );
            $stmt->execute();
            $result['cierre_mensual'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        if (($idTienda > 0) && msp2TableExists($conn, 'msp_documentos_cobro')) {
            $stmt = $conn->prepare(
                'SELECT COUNT(*) AS total_docs,
                        ISNULL(SUM(CASE WHEN estado_documento IN (2,3) THEN saldo_pendiente ELSE 0 END), 0) AS saldo_pendiente
                 FROM dbo.msp_documentos_cobro
                 WHERE id_tienda = :id_tienda
                   AND id_contrato_arriendo = :id_contrato'
            );
            $stmt->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
            $stmt->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if ((float) ($row['saldo_pendiente'] ?? 0) > 0) {
                $result['alertas'][] = 'Existe saldo pendiente asociado al contrato.';
            }
        }

        return $result;
    }

    private static function clasificar(int $estadoContrato, array $dependencias): array
    {
        $documentos = is_array($dependencias['documentos'] ?? null) ? $dependencias['documentos'] : [];
        $pagos = is_array($dependencias['pagos'] ?? null) ? $dependencias['pagos'] : [];
        $garantias = is_array($dependencias['garantias'] ?? null) ? $dependencias['garantias'] : [];
        $tesoreria = is_array($dependencias['tesoreria'] ?? null) ? $dependencias['tesoreria'] : [];
        $contabilidad = is_array($dependencias['contabilidad'] ?? null) ? $dependencias['contabilidad'] : [];

        $tieneDocumento = count($documentos) > 0;
        $tienePago = count($pagos) > 0;
        $tieneGarantia = count($garantias) > 0;
        $tieneTesoreria = count($tesoreria) > 0;
        $tieneContabilidad = count($contabilidad) > 0;
        $estadoAvanzado = in_array($estadoContrato, [3, 4, 5], true);

        // La existencia de contabilidad, tesorería o un cierre avanzado tiene
        // prioridad absoluta: exige autorización y eventual reversa controlada.
        if ($tieneTesoreria || $tieneContabilidad || $estadoAvanzado) {
            return [
                'nivel' => 'AUTORIZACION',
                'label' => 'Requiere autorización',
                'detalle' => 'Hay tesorería, contabilidad o un estado contractual avanzado que obliga a un flujo de autorización y reversa controlada.',
            ];
        }

        if (!$tieneDocumento && !$tienePago && !$tieneGarantia && !$tieneTesoreria && !$tieneContabilidad) {
            return [
                'nivel' => 'EDICION_SIMPLE',
                'label' => 'Corrección simple',
                'detalle' => 'No se detectan documentos, pagos ni efectos financieros posteriores.',
            ];
        }

        if ($tieneDocumento && !$tienePago && !$tieneGarantia && !$tieneTesoreria && !$tieneContabilidad) {
            return [
                'nivel' => 'REGENERACION_CONTROLADA',
                'label' => 'Regeneración controlada',
                'detalle' => 'Existe documento afectado pero todavía no hay pagos ni contabilidad asociada.',
            ];
        }

        if ($tienePago || $tieneGarantia) {
            return [
                'nivel' => 'AJUSTE_FINANCIERO',
                'label' => 'Ajuste financiero',
                'detalle' => 'Ya existen pagos, garantías o efectos posteriores que no deben borrarse.',
            ];
        }

        return [
            'nivel' => 'REVISION',
            'label' => 'Revisión manual',
            'detalle' => 'La corrección no puede clasificarse con seguridad sin revisar dependencias puntuales.',
        ];
    }
}
