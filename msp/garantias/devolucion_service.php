<?php
declare(strict_types=1);

/** Consume todos los resultados: evita cursores activos entre operaciones de la transaccion. */
function msp2DevolucionQuery(PDO $conn, string $sql, array $params = []): array
{
    $statement = $conn->prepare($sql);
    $statement->execute($params);
    $rows = [];
    do {
        if ($statement->columnCount() > 0) {
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        }
    } while ($statement->nextRowset());
    $statement->closeCursor();
    return $rows;
}

function msp2DevolucionAdminDisponible(PDO $conn): bool
{
    $row = msp2DevolucionQuery($conn, "SELECT CASE WHEN
        OBJECT_ID(N'dbo.msp_garantia_devolucion_solicitudes',N'U') IS NOT NULL
        AND OBJECT_ID(N'dbo.msp_garantia_devolucion_caja_admin',N'U') IS NOT NULL
        AND OBJECT_ID(N'dbo.msp_garantia_devolucion_registrar_caja_admin',N'P') IS NOT NULL
        AND OBJECT_ID(N'dbo.msp_vw_garantia_devolucion_caja_admin',N'V') IS NOT NULL
        AND OBJECT_ID(N'dbo.msp_garantia_tienda_devolver_operativa',N'P') IS NOT NULL
        AND OBJECT_ID(N'dbo.msp_garantia_caja_admin_validar_periodo',N'P') IS NOT NULL
        AND CHARINDEX(N'MSP_CAJA_ADMIN_8_9',OBJECT_DEFINITION(OBJECT_ID(N'dbo.msp_garantia_tienda_devolver_operativa')))>0
        AND CHARINDEX(N'MSP_CAJA_ADMIN_8_9',OBJECT_DEFINITION(OBJECT_ID(N'dbo.msp_garantia_tienda_revertir_operacion')))>0
        AND EXISTS(SELECT 1 FROM sys.triggers WHERE object_id=OBJECT_ID(N'dbo.TR_msp_garantia_reversas_integridad') AND is_disabled=0)
        AND EXISTS(SELECT 1 FROM sys.triggers WHERE object_id=OBJECT_ID(N'dbo.TR_msp_acc_tesoreria_garantias') AND is_disabled=0)
        AND EXISTS(SELECT 1 FROM sys.triggers WHERE object_id=OBJECT_ID(N'dbo.TR_msp_acc_garantia_reversas') AND is_disabled=0)
        AND EXISTS(SELECT 1 FROM sys.triggers WHERE object_id=OBJECT_ID(N'dbo.TR_msp_dev_solicitud_integridad') AND is_disabled=0)
        AND EXISTS(SELECT 1 FROM sys.triggers WHERE object_id=OBJECT_ID(N'dbo.TR_msp_dev_caja_admin_integridad') AND is_disabled=0)
        THEN 1 ELSE 0 END AS disponible")[0];
    return (int) $row['disponible'] === 1;
}

/** Servicio interno. El endpoint debe validar sesion, permisos, CSRF y normalizar entradas. */
function msp2RegistrarDevolucionCompleta(PDO $conn, array $data): array
{
    if ($conn->inTransaction()) {
        throw new RuntimeException('La devolución debe comenzar sin otra transacción activa.');
    }
    $keys = ['id_solicitud', 'id_garantia_tienda', 'id_cuenta_tesoreria', 'id_cuenta_caja_admin',
        'fecha_devolucion', 'monto_devolucion', 'forma_devolucion', 'medio_devolucion', 'beneficiario',
        'rut_beneficiario', 'banco_destino', 'cuenta_destino', 'referencia_transferencia',
        'observaciones', 'motivo_autorizacion', 'id_usuario'];
    $canonical = [];
    foreach ($keys as $key) {
        if (!array_key_exists($key, $data)) {
            throw new InvalidArgumentException('Faltan datos de la solicitud de devolución.');
        }
        $canonical[$key] = $data[$key];
    }
    if (!preg_match('/^[0-9a-f]{32}$/D', (string) $data['id_solicitud']) || (int) $data['id_usuario'] <= 0
        || !in_array($data['forma_devolucion'], ['PARCIAL', 'TOTAL'], true)
        || !in_array($data['medio_devolucion'], ['EFECTIVO', 'TRANSFERENCIA'], true)) {
        throw new InvalidArgumentException('La solicitud de devolución no es válida. Recarga la pantalla.');
    }
    if ($data['medio_devolucion'] === 'TRANSFERENCIA' && (int) $data['id_cuenta_caja_admin'] <= 0) {
        throw new InvalidArgumentException('Selecciona la caja donde se dejará el registro administrativo.');
    }
    if ($data['medio_devolucion'] === 'EFECTIVO' && $data['id_cuenta_caja_admin'] !== null) {
        throw new InvalidArgumentException('Una devolución en efectivo no necesita otra pareja administrativa.');
    }
    if (!msp2DevolucionAdminDisponible($conn)) {
        throw new RuntimeException('Falta instalar la integración de devoluciones y caja administrativa. No se registró ningún movimiento.');
    }
    $hash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

    $conn->beginTransaction();
    try {
        // Serializa tambien solicitudes simultaneas en conexiones/usuarios distintos.
        $lock = msp2DevolucionQuery($conn, "DECLARE @resultado INT;
            EXEC @resultado=sys.sp_getapplock @Resource=:recurso,@LockMode=N'Exclusive',
                @LockOwner=N'Transaction',@LockTimeout=10000;
            SELECT @resultado AS resultado", [':recurso' => 'MSP:DEVOLUCION:' . $data['id_solicitud']])[0];
        if ((int) $lock['resultado'] < 0) {
            throw new RuntimeException('Esta solicitud está siendo procesada. Reintenta con la misma solicitud.');
        }
        $existing = msp2DevolucionQuery($conn, 'SELECT s.hash_datos,s.id_usuario,d.id_devolucion_garantia,
            d.id_movimiento_garantia,d.estado_devolucion FROM dbo.msp_garantia_devolucion_solicitudes s
            JOIN dbo.msp_garantia_devoluciones d ON d.id_devolucion_garantia=s.id_devolucion_garantia
            WHERE s.id_solicitud=:solicitud', [':solicitud' => $data['id_solicitud']]);
        if ($existing !== []) {
            $row = $existing[0];
            if ((int) $row['id_usuario'] !== (int) $data['id_usuario'] || !hash_equals($row['hash_datos'], $hash)) {
                throw new DomainException('Esta solicitud ya fue utilizada con otros datos. Recarga la pantalla antes de iniciar otra devolución.');
            }
            if ($row['estado_devolucion'] !== 'EMITIDA') {
                throw new DomainException('Esta solicitud corresponde a una devolución anulada. No se emitirá nuevamente.');
            }
            $conn->commit();
            return $row + ['reutilizada' => true];
        }

        if ($data['forma_devolucion'] === 'TOTAL') {
            // El importe enviado debe seguir siendo el saldo total disponible bajo bloqueo.
            // Nunca se aumenta ni disminuye silenciosamente el importe confirmado por el usuario.
            msp2DevolucionQuery($conn, "DECLARE @garantia INT=:garantia,@monto DECIMAL(18,2)=:monto;
                IF NOT EXISTS(SELECT 1 FROM dbo.msp_garantias_tienda WITH(UPDLOCK,HOLDLOCK)
                    WHERE id_garantia_tienda=@garantia AND estado_garantia<>6)
                    THROW 53923,N'La garantia ya no esta disponible.',1;
                IF NOT EXISTS(SELECT 1 FROM dbo.msp_vw_garantias_tienda_resumen
                    WHERE id_garantia_tienda=@garantia AND monto_disponible=@monto AND monto_reservado=0)
                    THROW 53924,N'El saldo total cambio. Recarga la pantalla y confirma el importe actualizado.',1;",
                [':garantia' => $data['id_garantia_tienda'], ':monto' => $data['monto_devolucion']]);
        }

        $result = msp2DevolucionQuery($conn, 'EXEC dbo.msp_garantia_tienda_devolver_operativa
            @id_garantia_tienda=:garantia,@id_cuenta_tesoreria=:origen,@fecha_devolucion=:fecha,
            @monto_devolucion=:monto,@medio_devolucion=:medio,@beneficiario=:beneficiario,
            @rut_beneficiario=:rut,@banco_destino=:banco,@cuenta_destino=:destino,
            @referencia_transferencia=:referencia,@numero_cheque=NULL,@fecha_cheque=NULL,
            @observaciones=:observaciones,@id_usuario=:usuario,@motivo_autorizacion=:motivo,@id_usuario_autoriza=:autoriza',
            [':garantia' => $data['id_garantia_tienda'], ':origen' => $data['id_cuenta_tesoreria'],
                ':fecha' => $data['fecha_devolucion'], ':monto' => $data['monto_devolucion'],
                ':medio' => $data['medio_devolucion'], ':beneficiario' => $data['beneficiario'],
                ':rut' => $data['rut_beneficiario'], ':banco' => $data['banco_destino'], ':destino' => $data['cuenta_destino'],
                ':referencia' => $data['referencia_transferencia'], ':observaciones' => $data['observaciones'],
                ':usuario' => $data['id_usuario'], ':motivo' => $data['motivo_autorizacion'], ':autoriza' => $data['id_usuario']])[0] ?? [];
        $idRefund = (int) ($result['id_devolucion_garantia'] ?? 0);
        if ($idRefund <= 0) {
            throw new RuntimeException('No se confirmó el identificador de la devolución. No se guardará parcialmente.');
        }
        if ($data['medio_devolucion'] === 'TRANSFERENCIA') {
            msp2DevolucionQuery($conn, 'EXEC dbo.msp_garantia_devolucion_registrar_caja_admin
                @id_devolucion_garantia=:devolucion,@id_cuenta_caja=:caja,@id_usuario=:usuario',
                [':devolucion' => $idRefund, ':caja' => $data['id_cuenta_caja_admin'], ':usuario' => $data['id_usuario']]);
        }
        msp2DevolucionQuery($conn, 'INSERT dbo.msp_garantia_devolucion_solicitudes
            (id_solicitud,hash_datos,id_usuario,id_devolucion_garantia) VALUES(:solicitud,:hash,:usuario,:devolucion)',
            [':solicitud' => $data['id_solicitud'], ':hash' => $hash, ':usuario' => $data['id_usuario'], ':devolucion' => $idRefund]);
        $conn->commit();
        return $result + ['reutilizada' => false];
    } catch (Throwable $error) {
        try {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
        } catch (Throwable $rollbackError) {
            // No ocultar la causa inicial si el SP ya revirtio toda la transaccion.
            if (function_exists('pgpLogException')) {
                pgpLogException($rollbackError, 'msp.garantias.devolucion.rollback');
            }
        }
        throw $error;
    }
}
