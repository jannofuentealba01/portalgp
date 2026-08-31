<?php
declare(strict_types=1);

final class CobranzaGestionService
{
    public function __construct(private PDO $conn)
    {
        foreach (['msp_cobranza_gestiones','msp_cobranza_compromisos','msp_cobranza_avisos','msp_cobranza_casos'] as $table) {
            if (!msp2TableExists($conn, $table)) {
                throw new RuntimeException('Falta instalar la gestión operacional de cobranza.');
            }
        }
    }

    public function catalogos(): array
    {
        return [
            'tipos' => $this->conn->query('SELECT * FROM dbo.msp_cobranza_tipos_gestion WHERE activo=1 ORDER BY orden,nombre')->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'resultados' => $this->conn->query('SELECT * FROM dbo.msp_cobranza_resultados_gestion WHERE activo=1 ORDER BY orden,nombre')->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'plantillas' => $this->conn->query('SELECT * FROM dbo.msp_cobranza_plantillas_aviso WHERE activo=1 ORDER BY orden,nombre')->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ];
    }

    public function registrarGestion(int $contrato, int $arrendatario, int $tipo, int $resultado, string $fecha, string $persona, string $observacion, ?string $seguimiento, int $usuario): int
    {
        $this->validarContrato($contrato, $arrendatario);
        $fechaGestion = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $fecha);
        if (!$fechaGestion || $fechaGestion->format('Y-m-d\TH:i') !== $fecha || $fechaGestion > new DateTimeImmutable('+5 minutes')) {
            throw new RuntimeException('La fecha y hora de la gestión no es válida.');
        }
        $observacion = $this->texto($observacion, 1500, true, 'Debes registrar una observación de la gestión.');
        $persona = $this->texto($persona, 150, false);
        $seguimiento = $this->fecha($seguimiento, true);
        $catalogo = $this->conn->prepare(
            'SELECT r.estado_operacional_sugerido FROM dbo.msp_cobranza_tipos_gestion t
             CROSS JOIN dbo.msp_cobranza_resultados_gestion r
             WHERE t.id_tipo_gestion=:tipo AND t.activo=1 AND r.id_resultado_gestion=:resultado AND r.activo=1'
        );
        $catalogo->execute([':tipo'=>$tipo, ':resultado'=>$resultado]);
        $estado = $catalogo->fetchColumn();
        if ($estado === false) throw new RuntimeException('El tipo o resultado de gestión no está disponible.');
        $stmt = $this->conn->prepare(
            'INSERT dbo.msp_cobranza_gestiones(id_contrato_arriendo,id_arrendatario,fecha_gestion,id_tipo_gestion,id_resultado_gestion,persona_contactada,observacion,proxima_fecha_seguimiento,id_usuario)
             OUTPUT INSERTED.id_gestion_cobranza VALUES(:contrato,:arrendatario,:fecha,:tipo,:resultado,:persona,:observacion,:seguimiento,:usuario)'
        );
        $stmt->execute([':contrato'=>$contrato,':arrendatario'=>$arrendatario,':fecha'=>$fechaGestion->format('Y-m-d H:i:s'),':tipo'=>$tipo,':resultado'=>$resultado,':persona'=>$persona,':observacion'=>$observacion,':seguimiento'=>$seguimiento,':usuario'=>$usuario]);
        $id=(int)$stmt->fetchColumn();
        $this->actualizarCaso($contrato, (string)$estado);
        return $id;
    }

    public function registrarCompromiso(int $contrato, int $arrendatario, float $monto, string $fecha, string $observacion, int $usuario): int
    {
        $this->validarContrato($contrato, $arrendatario);
        $fecha = $this->fecha($fecha, false) ?? '';
        if ($fecha < date('Y-m-d')) throw new RuntimeException('La fecha comprometida no puede estar en el pasado.');
        if ($monto <= 0) throw new RuntimeException('El monto comprometido debe ser mayor a cero.');
        $saldo = $this->saldoContrato($contrato);
        if ($saldo <= .005) throw new RuntimeException('El contrato no tiene deuda pendiente para comprometer.');
        if ($monto > $saldo + .009) throw new RuntimeException('El monto comprometido supera la deuda pendiente actual.');
        $observacion = $this->texto($observacion, 1000, false);
        $this->conn->beginTransaction();
        try {
            $this->conn->prepare("UPDATE dbo.msp_cobranza_compromisos SET estado=N'CANCELADO',fecha_cancelacion=SYSDATETIME(),motivo_cancelacion=N'Reemplazado por un nuevo compromiso' WHERE id_contrato_arriendo=:id AND estado IN(N'PENDIENTE',N'CUMPLIDO_PARCIAL')")->execute([':id'=>$contrato]);
            $stmt=$this->conn->prepare('INSERT dbo.msp_cobranza_compromisos(id_contrato_arriendo,id_arrendatario,monto_comprometido,fecha_comprometida,observacion,id_usuario_creador) OUTPUT INSERTED.id_compromiso_pago VALUES(:contrato,:arrendatario,:monto,:fecha,:observacion,:usuario)');
            $stmt->execute([':contrato'=>$contrato,':arrendatario'=>$arrendatario,':monto'=>round($monto,2),':fecha'=>$fecha,':observacion'=>$observacion,':usuario'=>$usuario]);
            $id=(int)$stmt->fetchColumn();
            $this->actualizarCaso($contrato,'COMPROMISO_PAGO');
            $this->conn->commit();
            return $id;
        } catch(Throwable $e) { if($this->conn->inTransaction())$this->conn->rollBack(); throw $e; }
    }

    public function cancelarCompromiso(int $id, string $motivo, int $usuario): void
    {
        $motivo=$this->texto($motivo,500,true,'Debes indicar el motivo de cancelación.');
        $stmt=$this->conn->prepare("UPDATE dbo.msp_cobranza_compromisos SET estado=N'CANCELADO',fecha_cancelacion=SYSDATETIME(),motivo_cancelacion=:motivo WHERE id_compromiso_pago=:id AND estado IN(N'PENDIENTE',N'CUMPLIDO_PARCIAL',N'INCUMPLIDO'); SELECT id_contrato_arriendo FROM dbo.msp_cobranza_compromisos WHERE id_compromiso_pago=:id2");
        $stmt->execute([':motivo'=>$motivo,':id'=>$id,':id2'=>$id]);
        $stmt->nextRowset();
        $contrato=(int)($stmt->fetchColumn()?:0);
        if($contrato<=0)throw new RuntimeException('El compromiso no existe o no puede cancelarse.');
        $this->actualizarCaso($contrato,'EN_GESTION');
    }

    public function crearAviso(int $contrato, int $plantilla, int $usuario): int
    {
        require_once __DIR__ . '/CobranzaContratoService.php';
        $data=(new CobranzaContratoService($this->conn))->obtener($contrato);
        $resumen=$data['resumen']; $c=$data['contrato'];
        if((float)$resumen['deuda_total']<=.005)throw new RuntimeException('No corresponde generar un aviso: el contrato está al día.');
        $stmt=$this->conn->prepare('SELECT * FROM dbo.msp_cobranza_plantillas_aviso WHERE id_plantilla_aviso=:id AND activo=1');$stmt->execute([':id'=>$plantilla]);$p=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$p)throw new RuntimeException('La plantilla seleccionada no está disponible.');
        $reemplazos=['{{ARRENDATARIO}}'=>(string)$c['nombre_locatario'],'{{CONTRATO}}'=>(string)$contrato,'{{DEUDA_TOTAL}}'=>number_format((float)$resumen['deuda_total'],2,',','.'),'{{DEUDA_VENCIDA}}'=>number_format((float)$resumen['deuda_vencida'],2,',','.'),'{{MORA_MAXIMA}}'=>(string)$resumen['mora_maxima']];
        $cuerpo=strtr((string)$p['cuerpo'],$reemplazos);
        $stmt=$this->conn->prepare('INSERT dbo.msp_cobranza_avisos(id_contrato_arriendo,id_arrendatario,id_plantilla_aviso,asunto_snapshot,cuerpo_snapshot,deuda_vencida_snapshot,mora_maxima_snapshot,id_usuario_generador) OUTPUT INSERTED.id_aviso_cobranza VALUES(:contrato,:arrendatario,:plantilla,:asunto,:cuerpo,:deuda,:mora,:usuario)');
        $stmt->execute([':contrato'=>$contrato,':arrendatario'=>(int)$c['id_arrendatario'],':plantilla'=>$plantilla,':asunto'=>$p['asunto'],':cuerpo'=>$cuerpo,':deuda'=>(float)$resumen['deuda_vencida'],':mora'=>(int)$resumen['mora_maxima'],':usuario'=>$usuario]);
        return (int)$stmt->fetchColumn();
    }

    public function registrarEnvioAviso(int $idAviso, string $medio, string $observacion, int $usuario): int
    {
        $medio=strtoupper(trim($medio));
        if(!in_array($medio,['CORREO','WHATSAPP','CARTA','PERSONAL','OTRO'],true))throw new RuntimeException('El medio de entrega no es válido.');
        $observacion=$this->texto($observacion,1000,false);
        $this->conn->beginTransaction();
        try {
            $stmt=$this->conn->prepare("UPDATE dbo.msp_cobranza_avisos SET estado=N'ENVIADO',fecha_envio=SYSDATETIME(),medio_envio=:medio,observacion_envio=:obs,id_usuario_envio=:usuario OUTPUT INSERTED.id_contrato_arriendo,INSERTED.id_arrendatario WHERE id_aviso_cobranza=:id AND estado=N'GENERADO'");
            $stmt->execute([':medio'=>$medio,':obs'=>$observacion,':usuario'=>$usuario,':id'=>$idAviso]);$aviso=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$aviso)throw new RuntimeException('El aviso no existe o ya fue procesado.');
            $tipo=(int)$this->conn->query("SELECT id_tipo_gestion FROM dbo.msp_cobranza_tipos_gestion WHERE codigo=N'".($medio==='CORREO'?'CORREO':($medio==='WHATSAPP'?'WHATSAPP':'CARTA'))."'")->fetchColumn();
            $resultado=(int)$this->conn->query("SELECT id_resultado_gestion FROM dbo.msp_cobranza_resultados_gestion WHERE codigo=N'CONTACTADO'")->fetchColumn();
            $g=$this->conn->prepare("INSERT dbo.msp_cobranza_gestiones(id_contrato_arriendo,id_arrendatario,fecha_gestion,id_tipo_gestion,id_resultado_gestion,observacion,id_usuario,origen,id_aviso_cobranza) OUTPUT INSERTED.id_gestion_cobranza VALUES(:c,:a,SYSDATETIME(),:t,:r,:o,:u,N'AVISO',:aviso)");
            $g->execute([':c'=>(int)$aviso['id_contrato_arriendo'],':a'=>(int)$aviso['id_arrendatario'],':t'=>$tipo,':r'=>$resultado,':o'=>'Aviso de cobranza entregado por '.$medio.($observacion?' '.$observacion:''),':u'=>$usuario,':aviso'=>$idAviso]);
            $id=(int)$g->fetchColumn();$this->actualizarCaso((int)$aviso['id_contrato_arriendo'],'CONTACTADO');$this->conn->commit();return $id;
        }catch(Throwable $e){if($this->conn->inTransaction())$this->conn->rollBack();throw $e;}
    }

    public function datosContrato(int $contrato): array
    {
        $this->evaluarCompromisos($contrato);
        $this->actualizarCasoAutomatico($contrato);
        $gest=$this->conn->prepare('SELECT g.*,t.nombre tipo_nombre,r.nombre resultado_nombre,u.nombre_completo usuario_nombre FROM dbo.msp_cobranza_gestiones g JOIN dbo.msp_cobranza_tipos_gestion t ON t.id_tipo_gestion=g.id_tipo_gestion JOIN dbo.msp_cobranza_resultados_gestion r ON r.id_resultado_gestion=g.id_resultado_gestion JOIN dbo.cr_usuarios u ON u.id=g.id_usuario WHERE g.id_contrato_arriendo=:id ORDER BY g.fecha_gestion DESC,g.id_gestion_cobranza DESC');$gest->execute([':id'=>$contrato]);
        $comp=$this->conn->prepare('SELECT c.*,u.nombre_completo usuario_nombre FROM dbo.msp_cobranza_compromisos c JOIN dbo.cr_usuarios u ON u.id=c.id_usuario_creador WHERE c.id_contrato_arriendo=:id ORDER BY c.fecha_creacion DESC,c.id_compromiso_pago DESC');$comp->execute([':id'=>$contrato]);
        $avis=$this->conn->prepare('SELECT a.*,p.nombre plantilla_nombre,ug.nombre_completo usuario_generador_nombre,ue.nombre_completo usuario_envio_nombre FROM dbo.msp_cobranza_avisos a JOIN dbo.msp_cobranza_plantillas_aviso p ON p.id_plantilla_aviso=a.id_plantilla_aviso JOIN dbo.cr_usuarios ug ON ug.id=a.id_usuario_generador LEFT JOIN dbo.cr_usuarios ue ON ue.id=a.id_usuario_envio WHERE a.id_contrato_arriendo=:id ORDER BY a.fecha_generacion DESC,a.id_aviso_cobranza DESC');$avis->execute([':id'=>$contrato]);
        $caso=$this->conn->prepare('SELECT * FROM dbo.msp_cobranza_casos WHERE id_contrato_arriendo=:id');$caso->execute([':id'=>$contrato]);
        return ['gestiones'=>$gest->fetchAll(PDO::FETCH_ASSOC)?:[],'compromisos'=>$comp->fetchAll(PDO::FETCH_ASSOC)?:[],'avisos'=>$avis->fetchAll(PDO::FETCH_ASSOC)?:[],'caso'=>$caso->fetch(PDO::FETCH_ASSOC)?:null];
    }

    public function evaluarCompromisos(?int $contrato=null): void
    {
        $sql="SELECT c.id_compromiso_pago,c.id_contrato_arriendo,c.fecha_creacion,c.fecha_comprometida,c.monto_comprometido,c.estado FROM dbo.msp_cobranza_compromisos c WHERE c.estado IN(N'PENDIENTE',N'CUMPLIDO_PARCIAL',N'INCUMPLIDO')".($contrato?' AND c.id_contrato_arriendo=:id':'');
        $stmt=$this->conn->prepare($sql);$stmt->execute($contrato?[':id'=>$contrato]:[]);
        $pagos=$this->conn->prepare("SELECT ISNULL(SUM(p.monto_pagado),0) FROM dbo.msp_pagos p JOIN dbo.msp_documentos_cobro d ON d.id_documento_cobro=p.id_documento_cobro OUTER APPLY(SELECT TOP(1) ca.id_contrato_arriendo FROM dbo.msp_contratos_arriendo ca WHERE ca.id_tienda=d.id_tienda AND ca.fecha_inicio<=EOMONTH(d.periodo_facturacion) AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva>=d.periodo_facturacion) ORDER BY ca.fecha_inicio DESC,ca.id_contrato_arriendo DESC) cv WHERE COALESCE(d.id_contrato_arriendo,cv.id_contrato_arriendo)=:contrato AND p.estado_pago=1 AND p.fecha_pago BETWEEN :desde AND :hasta");
        $upd=$this->conn->prepare('UPDATE dbo.msp_cobranza_compromisos SET estado=:estado,monto_pagado_evaluado=:pagado,fecha_ultima_evaluacion=SYSDATETIME() WHERE id_compromiso_pago=:id');
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $c){$desde=substr((string)$c['fecha_creacion'],0,10);$hasta=min(date('Y-m-d'),substr((string)$c['fecha_comprometida'],0,10));$pagos->execute([':contrato'=>(int)$c['id_contrato_arriendo'],':desde'=>$desde,':hasta'=>$hasta]);$pagado=round((float)$pagos->fetchColumn(),2);$monto=(float)$c['monto_comprometido'];$vencido=(string)$c['fecha_comprometida']<date('Y-m-d');$estado=$pagado+0.009>=$monto?'CUMPLIDO':($pagado>.005?'CUMPLIDO_PARCIAL':($vencido?'INCUMPLIDO':'PENDIENTE'));$upd->execute([':estado'=>$estado,':pagado'=>$pagado,':id'=>(int)$c['id_compromiso_pago']]);}
    }

    private function actualizarCasoAutomatico(int $contrato): void
    {
        $saldo=$this->saldoContrato($contrato);
        if($saldo<=.005){$this->actualizarCaso($contrato,'RESUELTO');return;}
        $stmt=$this->conn->prepare("SELECT COUNT(*) FROM dbo.msp_cobranza_compromisos WHERE id_contrato_arriendo=:id AND estado IN(N'INCUMPLIDO',N'CUMPLIDO_PARCIAL') AND fecha_comprometida<CONVERT(date,SYSDATETIME())");$stmt->execute([':id'=>$contrato]);
        if((int)$stmt->fetchColumn()>0){$this->actualizarCaso($contrato,'ESCALADO');return;}
        $stmt=$this->conn->prepare("SELECT COUNT(*) FROM dbo.msp_cobranza_compromisos WHERE id_contrato_arriendo=:id AND estado IN(N'PENDIENTE',N'CUMPLIDO_PARCIAL') AND fecha_comprometida>=CONVERT(date,SYSDATETIME())");$stmt->execute([':id'=>$contrato]);
        if((int)$stmt->fetchColumn()>0){$this->actualizarCaso($contrato,'COMPROMISO_PAGO');return;}
        $stmt=$this->conn->prepare('SELECT TOP(1) r.estado_operacional_sugerido FROM dbo.msp_cobranza_gestiones g JOIN dbo.msp_cobranza_resultados_gestion r ON r.id_resultado_gestion=g.id_resultado_gestion WHERE g.id_contrato_arriendo=:id ORDER BY g.fecha_gestion DESC,g.id_gestion_cobranza DESC');$stmt->execute([':id'=>$contrato]);$this->actualizarCaso($contrato,(string)($stmt->fetchColumn()?:'SIN_GESTION'));
    }

    private function actualizarCaso(int $contrato,string $estado): void
    {
        $stmt=$this->conn->prepare("UPDATE dbo.msp_cobranza_casos SET estado_operacional=:estado,fecha_resolucion=CASE WHEN :estado2=N'RESUELTO' THEN COALESCE(fecha_resolucion,SYSDATETIME()) ELSE NULL END,fecha_actualizacion=SYSDATETIME() WHERE id_contrato_arriendo=:id; IF @@ROWCOUNT=0 INSERT dbo.msp_cobranza_casos(id_contrato_arriendo,estado_operacional,fecha_resolucion) VALUES(:id2,:estado3,CASE WHEN :estado4=N'RESUELTO' THEN SYSDATETIME() ELSE NULL END)");$stmt->execute([':estado'=>$estado,':estado2'=>$estado,':id'=>$contrato,':id2'=>$contrato,':estado3'=>$estado,':estado4'=>$estado]);
    }

    private function saldoContrato(int $contrato): float
    {
        $stmt=$this->conn->prepare('SELECT ISNULL(SUM(dc.saldo_pendiente),0) FROM dbo.msp_documentos_cobro dc OUTER APPLY(SELECT TOP(1) ca.id_contrato_arriendo FROM dbo.msp_contratos_arriendo ca WHERE ca.id_tienda=dc.id_tienda AND ca.fecha_inicio<=EOMONTH(dc.periodo_facturacion) AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva>=dc.periodo_facturacion) ORDER BY ca.fecha_inicio DESC,ca.id_contrato_arriendo DESC) cv WHERE COALESCE(dc.id_contrato_arriendo,cv.id_contrato_arriendo)=:id AND dc.estado_documento IN(2,3)');$stmt->execute([':id'=>$contrato]);return round((float)$stmt->fetchColumn(),2);
    }
    private function validarContrato(int $contrato,int $arrendatario): void{$stmt=$this->conn->prepare('SELECT COUNT(*) FROM dbo.msp_contratos_arriendo WHERE id_contrato_arriendo=:c AND id_arrendatario=:a AND estado_contrato<>5');$stmt->execute([':c'=>$contrato,':a'=>$arrendatario]);if((int)$stmt->fetchColumn()!==1)throw new RuntimeException('El contrato no corresponde al arrendatario o está anulado.');}
    private function texto(string $v,int $max,bool $req,string $msg=''): ?string{$v=trim($v);if($req&&$v==='')throw new RuntimeException($msg);if(mb_strlen($v)>$max)throw new RuntimeException('Uno de los textos supera el máximo permitido.');return $v!==''?$v:null;}
    private function fecha(?string $v,bool $nullable): ?string{$v=trim((string)$v);if($nullable&&$v==='')return null;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$d||$d->format('Y-m-d')!==$v)throw new RuntimeException('La fecha indicada no es válida.');return $v;}
}
