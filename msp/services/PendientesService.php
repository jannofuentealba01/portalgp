<?php
declare(strict_types=1);

require_once __DIR__ . '/CobranzaGestionService.php';

/**
 * Motor transversal de pendientes MSP.
 *
 * Lee el estado real de cada dominio. No persiste ni replica estados de negocio.
 * La persistencia de asignación, revisión y posposición se incorpora en la etapa 2.
 */
final class PendientesService
{
    public const PRIORIDAD_CRITICA = 'CRITICA';
    public const PRIORIDAD_ALTA = 'ALTA';
    public const PRIORIDAD_NORMAL = 'NORMAL';
    public const PRIORIDAD_INFORMATIVA = 'INFORMATIVA';

    private const PRIORIDAD_ORDEN = [
        self::PRIORIDAD_CRITICA => 1,
        self::PRIORIDAD_ALTA => 2,
        self::PRIORIDAD_NORMAL => 3,
        self::PRIORIDAD_INFORMATIVA => 4,
    ];

    private PDO $conn;
    private DateTimeImmutable $ahora;
    private array $config;
    private array $diagnosticos = [];

    public function __construct(PDO $conn, ?DateTimeImmutable $ahora = null, array $config = [])
    {
        $this->conn = $conn;
        $this->ahora = $ahora ?? new DateTimeImmutable('now');
        $defaults = [
            'contrato_vence_alta_dias' => 15,
            'contrato_vence_normal_dias' => 30,
            'cobranza_critica_dias' => 90,
            'cobranza_alta_dias' => 30,
            'cobranza_critica_monto' => 1000000,
            'movimiento_sin_conciliar_dias' => 3,
            'cierre_mensual_atraso_dias' => 5,
            'hora_cierre_caja' => '18:00',
        ];
        $this->config = array_merge($defaults, $this->cargarConfiguracion(array_keys($defaults)), $config);
    }

    /** @return array<int,array<string,mixed>> */
    public function buscar(array $filtros = []): array
    {
        $this->diagnosticos = [];
        $pendientes = array_merge(
            $this->colectar('GARANTIAS', fn (): array => $this->consultarGarantias()),
            $this->colectar('OPERACION_MENSUAL', fn (): array => $this->consultarOperacionMensual()),
            $this->colectar('LECTURAS', fn (): array => $this->consultarLecturas()),
            $this->colectar('COBRANZA', fn (): array => $this->consultarCobranza()),
            $this->colectar('TESORERIA', fn (): array => $this->consultarTesoreria()),
            $this->colectar('CONTRATOS', fn (): array => $this->consultarContratos()),
            $this->colectar('CONTRATOS', fn (): array => $this->consultarTerminoLiquidacion()),
            $this->colectar('LOCALES', fn (): array => $this->consultarLocales()),
            $this->colectar('CONTABILIDAD', fn (): array => $this->consultarContabilidad())
        );

        $pendientes = array_values(array_filter(
            $pendientes,
            fn (array $pendiente): bool => $this->cumpleFiltros($pendiente, $filtros)
        ));

        if (($filtros['agrupar'] ?? true) === true) {
            $pendientes = $this->agrupar($pendientes);
        }

        $pendientes = $this->aplicarMetadatos($pendientes, $filtros);

        usort($pendientes, fn (array $a, array $b): int => $this->comparar($a, $b));
        return $pendientes;
    }

    public function resumen(array $filtros = []): array
    {
        $items = $this->buscar($filtros);
        $resumen = [
            'total' => count($items),
            'CRITICA' => 0,
            'ALTA' => 0,
            'NORMAL' => 0,
            'INFORMATIVA' => 0,
            'por_modulo' => [],
        ];
        foreach ($items as $item) {
            $prioridad = (string) ($item['prioridad'] ?? self::PRIORIDAD_NORMAL);
            $modulo = (string) ($item['modulo_origen'] ?? 'OTRO');
            $resumen[$prioridad] = ((int) ($resumen[$prioridad] ?? 0)) + 1;
            $resumen['por_modulo'][$modulo] = ((int) ($resumen['por_modulo'][$modulo] ?? 0)) + 1;
        }
        ksort($resumen['por_modulo']);
        return $resumen;
    }

    /** @return array<int,array{modulo:string,mensaje:string}> */
    public function diagnosticos(): array
    {
        return $this->diagnosticos;
    }

    /** @return array<int,array<string,mixed>> */
    private function consultarGarantias(): array
    {
        if (!$this->table('msp_garantias') || !$this->table('msp_vw_garantias_control_integral')) {
            return [];
        }
        $tieneRecepciones = $this->table('msp_garantia_recepciones');
        $recibidoSql = $tieneRecepciones
            ? "ISNULL((SELECT SUM(r.monto_recibido) FROM dbo.msp_garantia_recepciones r WHERE r.id_garantia=g.id_garantia AND r.estado_recepcion=N'CONFIRMADA'),0)"
            : '0';
        $sql = "SELECT g.id_garantia,g.id_contrato_arriendo,g.id_local,g.fecha_constitucion,
                       g.monto_inicial,gr.monto_disponible,gr.monto_reservado,
                       {$recibidoSql} monto_recibido,
                       a.nombre_locatario,a.rut,l.cdo_local,t.nombre_comercial
                FROM dbo.msp_garantias g
                INNER JOIN dbo.msp_vw_garantias_control_integral gr ON gr.id_garantia=g.id_garantia
                LEFT JOIN dbo.msp_contratos_arriendo c ON c.id_contrato_arriendo=g.id_contrato_arriendo
                LEFT JOIN dbo.msp_arrendatarios a ON a.id_arrendatario=c.id_arrendatario
                LEFT JOIN dbo.msp_tiendas t ON t.id_tienda=c.id_tienda
                LEFT JOIN dbo.msp_locales l ON l.id_local=g.id_local
                WHERE g.estado_garantia<>6
                  AND (g.monto_inicial<=0 OR {$recibidoSql}<>g.monto_inicial OR gr.monto_reservado>0)";
        $items = [];
        foreach ($this->rows($sql) as $row) {
            $pactado = (float) ($row['monto_inicial'] ?? 0);
            $recibido = (float) ($row['monto_recibido'] ?? 0);
            $reservado = (float) ($row['monto_reservado'] ?? 0);
            if ($pactado <= 0) {
                $subtipo = 'SIN_MONTO';
                $prioridad = self::PRIORIDAD_ALTA;
                $titulo = 'Garantía sin monto pactado';
                $descripcion = 'El contrato/local requiere definir el monto de garantía.';
                $accion = 'Definir garantía';
                $url = 'garantias/ficha.php?id_garantia=' . (int) $row['id_garantia'];
            } elseif ($recibido <= 0) {
                $subtipo = 'NO_RECIBIDA';
                $prioridad = self::PRIORIDAD_NORMAL;
                $titulo = 'Garantía pendiente de recepción';
                $descripcion = 'Pactado $' . $this->monto($pactado) . '; aún no registra recepción.';
                $accion = 'Registrar recepción';
                $url = 'garantias/recepciones.php?id_garantia=' . (int) $row['id_garantia'];
            } elseif ($recibido < $pactado) {
                $subtipo = 'RECEPCION_PARCIAL';
                $prioridad = self::PRIORIDAD_ALTA;
                $titulo = 'Recepción de garantía incompleta';
                $descripcion = 'Recibido $' . $this->monto($recibido) . ' de $' . $this->monto($pactado) . '.';
                $accion = 'Completar recepción';
                $url = 'garantias/recepciones.php?id_garantia=' . (int) $row['id_garantia'];
            } elseif ($recibido > $pactado + 0.01) {
                $subtipo = 'RECEPCION_EXCEDIDA';
                $prioridad = self::PRIORIDAD_CRITICA;
                $titulo = 'Recepción de garantía excedida';
                $descripcion = 'El monto recibido supera lo pactado y requiere revisión.';
                $accion = 'Revisar garantía';
                $url = 'garantias/ficha.php?id_garantia=' . (int) $row['id_garantia'];
            } elseif ($reservado > 0) {
                $subtipo = 'SALDO_RESERVADO';
                $prioridad = self::PRIORIDAD_ALTA;
                $titulo = 'Garantía con saldo reservado';
                $descripcion = 'Hay $' . $this->monto($reservado) . ' reservados que requieren resolución.';
                $accion = 'Revisar aplicación';
                $url = 'garantias/ficha.php?id_garantia=' . (int) $row['id_garantia'];
            } else {
                continue;
            }
            $items[] = $this->item('GARANTIA', $subtipo, $prioridad, $titulo, $descripcion, [
                'fecha_origen' => $row['fecha_constitucion'] ?? null,
                'arrendatario' => $row['nombre_locatario'] ?? null,
                'rut' => $row['rut'] ?? null,
                'contrato' => $row['id_contrato_arriendo'] ?? null,
                'local' => $row['cdo_local'] ?? null,
                'tienda' => $row['nombre_comercial'] ?? null,
                'monto' => max(0, $pactado - $recibido),
                'accion_principal' => $accion,
                'url_accion' => $url,
                'entidad_id' => (int) $row['id_garantia'],
            ]);
        }
        return $items;
    }

    /** @return array<int,array<string,mixed>> */
    private function consultarOperacionMensual(): array
    {
        if (!$this->table('msp_cierre_mensual')) {
            return [];
        }
        $items = [];
        $periodoActual = $this->ahora->modify('first day of this month')->format('Y-m-d');
        $existe = (int) $this->scalar('SELECT COUNT(*) FROM dbo.msp_cierre_mensual WHERE periodo_facturacion=:periodo AND estado_cierre<>4', [':periodo' => $periodoActual]);
        if ($existe === 0) {
            $items[] = $this->item('OPERACION_MENSUAL', 'PERIODO_NO_CREADO', self::PRIORIDAD_NORMAL,
                'Período mensual aún no creado', 'No existe la operación mensual de ' . substr($periodoActual, 0, 7) . '.', [
                    'periodo' => substr($periodoActual, 0, 7),
                    'fecha_limite' => $this->ahora->modify('last day of this month')->format('Y-m-d'),
                    'accion_principal' => 'Crear período',
                    'url_accion' => 'cobros/operacion_mensual.php?periodo=' . substr($periodoActual, 0, 7),
                ]);
        }
        foreach ($this->rows(
            'SELECT id_cierre_mensual,periodo_facturacion,valor_uf,estado_cierre,fecha_registro
             FROM dbo.msp_cierre_mensual
             WHERE estado_cierre IN (1,2) AND (valor_uf IS NULL OR valor_uf<=0)'
        ) as $row) {
            $periodo = substr((string) $row['periodo_facturacion'], 0, 7);
            $items[] = $this->item('OPERACION_MENSUAL', 'UF_FALTANTE', self::PRIORIDAD_ALTA,
                'Valor UF pendiente', 'El período ' . $periodo . ' no tiene un valor UF válido.', [
                    'periodo' => $periodo,
                    'fecha_origen' => $row['fecha_registro'] ?? null,
                    'accion_principal' => 'Registrar UF',
                    'url_accion' => 'cobros/operacion_mensual.php?periodo=' . $periodo . '&focus=paso-1#paso-1',
                    'entidad_id' => (int) $row['id_cierre_mensual'],
                ]);
        }
        if ($this->table('msp_pool_documentos_periodo')) {
            $sql = "SELECT periodo_facturacion,COUNT(*) cantidad,
                           SUM(CASE WHEN id_documento_cobro IS NULL THEN 1 ELSE 0 END) sin_documento,
                           MAX(motivo_pendiente) motivo
                    FROM dbo.msp_pool_documentos_periodo
                    WHERE estado_pool=1 AND (id_documento_cobro IS NULL OR motivo_pendiente IS NOT NULL)
                    GROUP BY periodo_facturacion";
            foreach ($this->rows($sql) as $row) {
                $periodo = substr((string) $row['periodo_facturacion'], 0, 7);
                $sinDocumento = (int) ($row['sin_documento'] ?? 0);
                $items[] = $this->item('OPERACION_MENSUAL', 'GENERACION_BLOQUEADA', self::PRIORIDAD_ALTA,
                    'Generación documental pendiente', $sinDocumento . ' caso(s) aún no tienen documento. ' . trim((string) ($row['motivo'] ?? '')), [
                        'periodo' => $periodo,
                        'cantidad' => (int) ($row['cantidad'] ?? 0),
                        'accion_principal' => 'Revisar operación mensual',
                        'url_accion' => 'cobros/operacion_mensual.php?periodo=' . $periodo . '&focus=paso-6#paso-6',
                    ]);
            }
        }
        $diasAtraso = max(0, (int) $this->config['cierre_mensual_atraso_dias']);
        foreach ($this->rows(
            "SELECT id_cierre_mensual,periodo_facturacion,estado_cierre,fecha_registro
             FROM dbo.msp_cierre_mensual
             WHERE estado_cierre=2 AND DATEDIFF(DAY,EOMONTH(periodo_facturacion),CONVERT(date,SYSDATETIME()))>:dias",
            [':dias' => $diasAtraso]
        ) as $row) {
            $periodo = substr((string) $row['periodo_facturacion'], 0, 7);
            $items[] = $this->item('CIERRE_MENSUAL', 'LISTO_SIN_CERRAR', self::PRIORIDAD_ALTA,
                'Período calculado sin cerrar', 'El período ' . $periodo . ' continúa abierto después del plazo esperado.', [
                    'periodo' => $periodo,
                    'fecha_origen' => $row['fecha_registro'] ?? null,
                    'accion_principal' => 'Revisar cierre',
                    'url_accion' => 'cobros/operacion_mensual.php?periodo=' . $periodo . '&focus=paso-6#paso-6',
                    'entidad_id' => (int) $row['id_cierre_mensual'],
                ]);
        }
        return $items;
    }

    /** @return array<int,array<string,mixed>> */
    private function consultarLecturas(): array
    {
        foreach (['msp_procesos_cobro_servicio', 'msp_tipos_servicio', 'msp_medidores', 'msp_lecturas_medidores', 'msp_cierre_mensual'] as $table) {
            if (!$this->table($table)) {
                return [];
            }
        }
        $sql = "SELECT cm.periodo_facturacion,ts.codigo_servicio,p.id_proceso_cobro,
                       COUNT(DISTINCT m.id_medidor) esperadas,COUNT(DISTINCT lm.id_medidor) registradas
                FROM dbo.msp_procesos_cobro_servicio p
                INNER JOIN dbo.msp_cierre_mensual cm ON cm.id_cierre_mensual=p.id_cierre_mensual
                INNER JOIN dbo.msp_tipos_servicio ts ON ts.id_tipo_servicio=p.id_tipo_servicio
                INNER JOIN dbo.msp_medidores m ON m.id_tipo_servicio=p.id_tipo_servicio
                    AND m.estado_medidor=1 AND m.fecha_instalacion<=EOMONTH(cm.periodo_facturacion)
                    AND (m.fecha_retiro IS NULL OR m.fecha_retiro>=cm.periodo_facturacion)
                LEFT JOIN dbo.msp_lecturas_medidores lm ON lm.id_proceso_cobro=p.id_proceso_cobro AND lm.id_medidor=m.id_medidor
                WHERE cm.estado_cierre IN (1,2)
                GROUP BY cm.periodo_facturacion,ts.codigo_servicio,p.id_proceso_cobro
                HAVING COUNT(DISTINCT lm.id_medidor)<COUNT(DISTINCT m.id_medidor)";
        $items = [];
        foreach ($this->rows($sql) as $row) {
            $periodo = substr((string) $row['periodo_facturacion'], 0, 7);
            $servicio = strtoupper((string) $row['codigo_servicio']);
            $esperadas = (int) $row['esperadas'];
            $registradas = (int) $row['registradas'];
            $items[] = $this->item('LECTURAS', 'LECTURAS_FALTANTES_' . $servicio, self::PRIORIDAD_ALTA,
                'Faltan lecturas de ' . $servicio, $registradas . ' de ' . $esperadas . ' lecturas registradas.', [
                    'periodo' => $periodo,
                    'cantidad' => max(0, $esperadas - $registradas),
                    'accion_principal' => 'Completar lecturas',
                    'url_accion' => 'cobros/operacion_mensual.php?periodo=' . $periodo . '&focus=servicio-' . strtolower($servicio) . '#servicio-' . strtolower($servicio),
                    'entidad_id' => (int) $row['id_proceso_cobro'],
                ]);
        }
        return $items;
    }

    /** @return array<int,array<string,mixed>> */
    private function consultarCobranza(): array
    {
        if (!$this->table('msp_documentos_cobro')) {
            return [];
        }
        $items = [];
        $tieneGestion = $this->table('msp_cobranza_compromisos') && $this->table('msp_cobranza_gestiones');
        if ($tieneGestion) {
            (new CobranzaGestionService($this->conn))->evaluarCompromisos();
            $sqlCompromisos = "SELECT cp.id_compromiso_pago,cp.id_contrato_arriendo,cp.monto_comprometido,cp.monto_pagado_evaluado,
                                      cp.fecha_comprometida,cp.estado,a.nombre_locatario,a.rut,t.nombre_comercial
                               FROM dbo.msp_cobranza_compromisos cp
                               JOIN dbo.msp_contratos_arriendo c ON c.id_contrato_arriendo=cp.id_contrato_arriendo
                               JOIN dbo.msp_arrendatarios a ON a.id_arrendatario=c.id_arrendatario
                               JOIN dbo.msp_tiendas t ON t.id_tienda=c.id_tienda
                               WHERE cp.estado IN(N'PENDIENTE',N'CUMPLIDO_PARCIAL',N'INCUMPLIDO')";
            foreach ($this->rows($sqlCompromisos) as $row) {
                $fecha=(string)$row['fecha_comprometida'];
                $incumplido=$row['estado']==='INCUMPLIDO'||($fecha<date('Y-m-d')&&$row['estado']!=='CUMPLIDO');
                $hoy=$fecha===date('Y-m-d');
                $titulo=$incumplido?'Compromiso de pago incumplido':($hoy?'Compromiso de pago para hoy':'Compromiso de pago vigente');
                $items[]=$this->item('COBRANZA',$incumplido?'COMPROMISO_INCUMPLIDO':($hoy?'COMPROMISO_HOY':'COMPROMISO_VIGENTE'),$incumplido?self::PRIORIDAD_CRITICA:($hoy?self::PRIORIDAD_ALTA:self::PRIORIDAD_NORMAL),$titulo,
                    'Comprometido $'.$this->monto((float)$row['monto_comprometido']).'; pagos verificados $'.$this->monto((float)$row['monto_pagado_evaluado']).'.',[ 'fecha_origen'=>$fecha,'fecha_limite'=>$fecha,'arrendatario'=>$row['nombre_locatario'],'rut'=>$row['rut'],'tienda'=>$row['nombre_comercial'],'contrato'=>$row['id_contrato_arriendo'],'monto'=>max(0,(float)$row['monto_comprometido']-(float)$row['monto_pagado_evaluado']),'accion_principal'=>$incumplido?'Gestionar incumplimiento':'Revisar compromiso','url_accion'=>'cobranza/gestionar.php?id_contrato='.(int)$row['id_contrato_arriendo'],'entidad_id'=>(int)$row['id_compromiso_pago']]);
            }
            $sqlSeguimiento="SELECT g.id_gestion_cobranza,g.id_contrato_arriendo,g.proxima_fecha_seguimiento,a.nombre_locatario,a.rut,t.nombre_comercial
                            FROM dbo.msp_cobranza_gestiones g JOIN dbo.msp_contratos_arriendo c ON c.id_contrato_arriendo=g.id_contrato_arriendo
                            JOIN dbo.msp_arrendatarios a ON a.id_arrendatario=c.id_arrendatario JOIN dbo.msp_tiendas t ON t.id_tienda=c.id_tienda
                            WHERE g.proxima_fecha_seguimiento IS NOT NULL AND g.proxima_fecha_seguimiento<=CONVERT(date,SYSDATETIME())
                              AND NOT EXISTS(SELECT 1 FROM dbo.msp_cobranza_gestiones g2 WHERE g2.id_contrato_arriendo=g.id_contrato_arriendo AND g2.fecha_gestion>g.fecha_gestion)
                              AND NOT EXISTS(SELECT 1 FROM dbo.msp_cobranza_compromisos cp WHERE cp.id_contrato_arriendo=g.id_contrato_arriendo AND cp.estado IN(N'PENDIENTE',N'CUMPLIDO_PARCIAL',N'INCUMPLIDO'))";
            foreach($this->rows($sqlSeguimiento) as $row)$items[]=$this->item('COBRANZA','SEGUIMIENTO_VENCIDO',$row['proxima_fecha_seguimiento']<date('Y-m-d')?self::PRIORIDAD_ALTA:self::PRIORIDAD_NORMAL,'Seguimiento de cobranza pendiente','La fecha acordada para retomar este caso ya llegó.', ['fecha_origen'=>$row['proxima_fecha_seguimiento'],'fecha_limite'=>$row['proxima_fecha_seguimiento'],'arrendatario'=>$row['nombre_locatario'],'rut'=>$row['rut'],'tienda'=>$row['nombre_comercial'],'contrato'=>$row['id_contrato_arriendo'],'accion_principal'=>'Registrar seguimiento','url_accion'=>'cobranza/gestionar.php?id_contrato='.(int)$row['id_contrato_arriendo'],'entidad_id'=>(int)$row['id_gestion_cobranza']]);
        }
        $sql = "SELECT COALESCE(dc.id_contrato_arriendo,contrato_vigente.id_contrato_arriendo) id_contrato_arriendo,dc.id_tienda,MAX(dc.nombre_arrendatario_snapshot) arrendatario,
                       MAX(dc.rut_arrendatario_snapshot) rut,MAX(dc.nombre_tienda_snapshot) tienda,
                       SUM(dc.saldo_pendiente) deuda_vencida,COUNT(*) documentos,
                       MAX(DATEDIFF(DAY,dc.fecha_vencimiento,CONVERT(date,SYSDATETIME()))) dias_mora,
                       MIN(dc.fecha_vencimiento) fecha_origen
                FROM dbo.msp_documentos_cobro dc
                OUTER APPLY(
                    SELECT TOP (1) c.id_contrato_arriendo
                    FROM dbo.msp_contratos_arriendo c
                    WHERE c.id_tienda=dc.id_tienda
                      AND c.fecha_inicio<=EOMONTH(dc.periodo_facturacion)
                      AND (c.fecha_termino_efectiva IS NULL OR c.fecha_termino_efectiva>=dc.periodo_facturacion)
                      AND c.estado_contrato IN(1,2,3,4)
                    ORDER BY c.fecha_inicio DESC,c.id_contrato_arriendo DESC
                ) contrato_vigente
                WHERE dc.estado_documento IN (2,3) AND dc.saldo_pendiente>0
                  AND dc.fecha_vencimiento<CONVERT(date,SYSDATETIME())
                  AND COALESCE(dc.id_contrato_arriendo,contrato_vigente.id_contrato_arriendo) IS NOT NULL
                  " . ($tieneGestion ? "AND NOT EXISTS(SELECT 1 FROM dbo.msp_cobranza_compromisos cp WHERE cp.id_contrato_arriendo=COALESCE(dc.id_contrato_arriendo,contrato_vigente.id_contrato_arriendo) AND cp.estado IN(N'PENDIENTE',N'CUMPLIDO_PARCIAL',N'INCUMPLIDO'))" : '') . "
                GROUP BY COALESCE(dc.id_contrato_arriendo,contrato_vigente.id_contrato_arriendo),dc.id_tienda";
        foreach ($this->rows($sql) as $row) {
            $dias = (int) ($row['dias_mora'] ?? 0);
            $monto = (float) ($row['deuda_vencida'] ?? 0);
            $prioridad = ($dias >= (int) $this->config['cobranza_critica_dias'] || $monto >= (float) $this->config['cobranza_critica_monto'])
                ? self::PRIORIDAD_CRITICA
                : ($dias >= (int) $this->config['cobranza_alta_dias'] ? self::PRIORIDAD_ALTA : self::PRIORIDAD_NORMAL);
            $items[] = $this->item('COBRANZA', 'DEUDA_VENCIDA', $prioridad, 'Contrato con deuda vencida',
                (int) $row['documentos'] . ' documento(s), mora máxima de ' . $dias . ' días.', [
                    'fecha_origen' => $row['fecha_origen'] ?? null,
                    'arrendatario' => $row['arrendatario'] ?? null,
                    'rut' => $row['rut'] ?? null,
                    'tienda' => $row['tienda'] ?? null,
                    'contrato' => $row['id_contrato_arriendo'] ?? null,
                    'monto' => $monto,
                    'dias_pendiente' => $dias,
                    'cantidad' => (int) $row['documentos'],
                    'accion_principal' => 'Gestionar cobranza',
                    'url_accion' => 'cobranza/gestionar.php?id_contrato=' . (int) ($row['id_contrato_arriendo'] ?? 0),
                    'entidad_id' => (int) ($row['id_contrato_arriendo'] ?? $row['id_tienda'] ?? 0),
                ]);
        }
        return $items;
    }

    /** @return array<int,array<string,mixed>> */
    private function consultarTesoreria(): array
    {
        $items = [];
        if ($this->table('msp_tesoreria_movimientos') && $this->table('msp_tesoreria_cuentas')) {
            $dias = max(0, (int) $this->config['movimiento_sin_conciliar_dias']);
            $sql = "SELECT c.id_cuenta_tesoreria,c.nombre_cuenta,c.banco,COUNT(*) cantidad,
                           SUM(CASE WHEN m.naturaleza='E' THEN m.monto ELSE -m.monto END) monto,
                           MIN(m.fecha_movimiento) fecha_origen
                    FROM dbo.msp_tesoreria_movimientos m
                    INNER JOIN dbo.msp_tesoreria_cuentas c ON c.id_cuenta_tesoreria=m.id_cuenta_tesoreria
                    WHERE c.tipo_cuenta=N'BANCO' AND m.estado_movimiento=N'VIGENTE' AND m.conciliado=0
                      AND DATEDIFF(DAY,m.fecha_movimiento,CONVERT(date,SYSDATETIME()))>=:dias
                    GROUP BY c.id_cuenta_tesoreria,c.nombre_cuenta,c.banco";
            foreach ($this->rows($sql, [':dias' => $dias]) as $row) {
                $items[] = $this->item('TESORERIA', 'MOVIMIENTOS_SIN_CONCILIAR', self::PRIORIDAD_ALTA,
                    'Movimientos bancarios sin conciliar', (int) $row['cantidad'] . ' movimiento(s) pendientes en ' . $row['nombre_cuenta'] . '.', [
                        'fecha_origen' => $row['fecha_origen'] ?? null,
                        'monto' => abs((float) ($row['monto'] ?? 0)),
                        'cantidad' => (int) $row['cantidad'],
                        'accion_principal' => 'Conciliar',
                        'url_accion' => 'tesoreria/conciliacion.php?cuenta=' . (int) $row['id_cuenta_tesoreria'],
                        'entidad_id' => (int) $row['id_cuenta_tesoreria'],
                    ]);
            }
        }
        if ($this->table('msp_tesoreria_conciliaciones') && $this->table('msp_tesoreria_cuentas')) {
            $sql = "SELECT c.id_conciliacion_tesoreria,c.fecha_desde,c.fecha_hasta,c.diferencia,c.estado_conciliacion,
                           tc.nombre_cuenta
                    FROM dbo.msp_tesoreria_conciliaciones c
                    INNER JOIN dbo.msp_tesoreria_cuentas tc ON tc.id_cuenta_tesoreria=c.id_cuenta_tesoreria
                    WHERE c.estado_conciliacion=N'PENDIENTE' OR ABS(c.diferencia)>0.01";
            foreach ($this->rows($sql) as $row) {
                $items[] = $this->item('TESORERIA', 'CONCILIACION_PENDIENTE', self::PRIORIDAD_CRITICA,
                    'Conciliación bancaria con diferencia', $row['nombre_cuenta'] . ' presenta una diferencia de $' . $this->monto((float) $row['diferencia']) . '.', [
                        'fecha_origen' => $row['fecha_desde'] ?? null,
                        'fecha_limite' => $row['fecha_hasta'] ?? null,
                        'monto' => abs((float) $row['diferencia']),
                        'accion_principal' => 'Revisar conciliación',
                        'url_accion' => 'tesoreria/conciliacion.php',
                        'entidad_id' => (int) $row['id_conciliacion_tesoreria'],
                    ]);
            }
        }
        if ($this->table('msp_tesoreria_cierres_caja') && $this->table('msp_tesoreria_cuentas')) {
            $sql = "SELECT c.id_cierre_caja,c.fecha_cierre,c.saldo_sistema,c.efectivo_contado,c.diferencia,tc.nombre_cuenta
                    FROM dbo.msp_tesoreria_cierres_caja c
                    INNER JOIN dbo.msp_tesoreria_cuentas tc ON tc.id_cuenta_tesoreria=c.id_cuenta_tesoreria
                    WHERE c.estado_cierre=N'CON_DIFERENCIA' OR ABS(c.diferencia)>0.01";
            foreach ($this->rows($sql) as $row) {
                $items[] = $this->item('CAJA', 'CIERRE_CON_DIFERENCIA', self::PRIORIDAD_CRITICA,
                    'Cierre de caja con diferencia', $row['nombre_cuenta'] . ': sistema $' . $this->monto((float) $row['saldo_sistema']) . ', contado $' . $this->monto((float) $row['efectivo_contado']) . '.', [
                        'fecha_origen' => $row['fecha_cierre'] ?? null,
                        'monto' => abs((float) $row['diferencia']),
                        'accion_principal' => 'Revisar cierre de caja',
                        'url_accion' => 'tesoreria/conciliacion.php?fecha_caja=' . substr((string) $row['fecha_cierre'], 0, 10),
                        'entidad_id' => (int) $row['id_cierre_caja'],
                    ]);
            }
        }
        return $items;
    }

    /** @return array<int,array<string,mixed>> */
    private function consultarContratos(): array
    {
        if (!$this->table('msp_contratos_arriendo')) {
            return [];
        }
        $items = [];
        $sql = "SELECT c.id_contrato_arriendo,c.fecha_inicio,c.fecha_termino_pactada,c.estado_contrato,
                       a.nombre_locatario,a.rut,t.nombre_comercial,
                       DATEDIFF(DAY,CONVERT(date,SYSDATETIME()),c.fecha_termino_pactada) dias_restantes
                FROM dbo.msp_contratos_arriendo c
                LEFT JOIN dbo.msp_arrendatarios a ON a.id_arrendatario=c.id_arrendatario
                LEFT JOIN dbo.msp_tiendas t ON t.id_tienda=c.id_tienda
                WHERE c.estado_contrato IN (1,2) AND c.fecha_termino_pactada IS NOT NULL
                  AND c.fecha_termino_pactada<=DATEADD(DAY,CAST(:dias AS INT),CONVERT(date,SYSDATETIME()))";
        foreach ($this->rows($sql, [':dias' => (int) $this->config['contrato_vence_normal_dias']]) as $row) {
            $dias = (int) $row['dias_restantes'];
            if ($dias < 0) {
                $subtipo = 'VENCIDO_ACTIVO';
                $prioridad = self::PRIORIDAD_CRITICA;
                $titulo = 'Contrato vencido aún activo';
            } else {
                $subtipo = 'PROXIMO_VENCER';
                $prioridad = $dias <= (int) $this->config['contrato_vence_alta_dias'] ? self::PRIORIDAD_ALTA : self::PRIORIDAD_NORMAL;
                $titulo = 'Contrato próximo a vencer';
            }
            $items[] = $this->item('CONTRATOS', $subtipo, $prioridad, $titulo,
                $dias < 0 ? 'La fecha pactada venció hace ' . abs($dias) . ' días.' : 'Vence en ' . $dias . ' días.', [
                    'fecha_origen' => $row['fecha_inicio'] ?? null,
                    'fecha_limite' => $row['fecha_termino_pactada'] ?? null,
                    'dias_pendiente' => max(0, -$dias),
                    'arrendatario' => $row['nombre_locatario'] ?? null,
                    'rut' => $row['rut'] ?? null,
                    'tienda' => $row['nombre_comercial'] ?? null,
                    'contrato' => (int) $row['id_contrato_arriendo'],
                    'accion_principal' => 'Revisar contrato',
                    'url_accion' => 'contratos/ficha.php?id_contrato_arriendo=' . (int) $row['id_contrato_arriendo'],
                    'entidad_id' => (int) $row['id_contrato_arriendo'],
                ]);
        }
        if ($this->table('msp_contrato_locales') && $this->table('msp_contrato_local_arriendo_regla') && $this->table('msp_tipo_modalidad_arriendo')) {
            $sqlReglas = "SELECT c.id_contrato_arriendo,a.nombre_locatario,a.rut,t.nombre_comercial,l.cdo_local,cl.id_contrato_local
                          FROM dbo.msp_contrato_locales cl
                          INNER JOIN dbo.msp_contratos_arriendo c ON c.id_contrato_arriendo=cl.id_contrato_arriendo
                          LEFT JOIN dbo.msp_arrendatarios a ON a.id_arrendatario=c.id_arrendatario
                          LEFT JOIN dbo.msp_tiendas t ON t.id_tienda=c.id_tienda
                          LEFT JOIN dbo.msp_locales l ON l.id_local=cl.id_local
                          OUTER APPLY (SELECT TOP(1) r.valor_base_uf,r.valor_base_clp,tm.codigo_modalidad
                                       FROM dbo.msp_contrato_local_arriendo_regla r
                                       JOIN dbo.msp_tipo_modalidad_arriendo tm ON tm.id_modalidad_arriendo=r.id_modalidad_arriendo
                                       WHERE r.id_contrato_local=cl.id_contrato_local AND r.estado_regla=1
                                       ORDER BY r.es_default DESC,r.prioridad DESC,r.id_regla_arriendo DESC) regla
                          WHERE c.estado_contrato IN(1,2) AND cl.estado_relacion=1
                            AND (regla.codigo_modalidad IS NULL
                              OR (regla.codigo_modalidad=N'UF_ESTATICO' AND ISNULL(regla.valor_base_uf,0)<=0)
                              OR (regla.codigo_modalidad=N'CLP_FIJO' AND ISNULL(regla.valor_base_clp,0)<=0))";
            foreach ($this->rows($sqlReglas) as $row) {
                $items[] = $this->item('CONTRATOS', 'ARRIENDO_SIN_REGLA_VALIDA', self::PRIORIDAD_ALTA,
                    'Arriendo no calculable', 'El local ' . ($row['cdo_local'] ?: '-') . ' no tiene una regla fija de arriendo válida.', [
                        'arrendatario' => $row['nombre_locatario'] ?? null,
                        'rut' => $row['rut'] ?? null,
                        'tienda' => $row['nombre_comercial'] ?? null,
                        'contrato' => (int) $row['id_contrato_arriendo'],
                        'local' => $row['cdo_local'] ?? null,
                        'accion_principal' => 'Configurar arriendo',
                        'url_accion' => 'contratos/arriendo_reglas.php?id_contrato_arriendo=' . (int) $row['id_contrato_arriendo'],
                        'entidad_id' => (int) $row['id_contrato_local'],
                    ]);
            }
        }
        return $items;
    }

    /**
     * Expone en la bandeja los contratos que ya iniciaron su término y las
     * devoluciones de garantía que todavía requieren gestión. La bandeja solo
     * deriva el estado de las tablas operativas; la liquidación/devolución se
     * ejecuta en sus módulos especializados.
     *
     * @return array<int,array<string,mixed>>
     */
    private function consultarTerminoLiquidacion(): array
    {
        if (!$this->table('msp_contratos_arriendo') || !$this->table('msp_arrendatarios') || !$this->table('msp_tiendas')) {
            return [];
        }
        $items = [];
        $sql = "SELECT c.id_contrato_arriendo,c.fecha_termino_efectiva,c.fecha_registro,
                       a.nombre_locatario,a.rut,t.nombre_comercial,
                       ISNULL((SELECT COUNT(*) FROM dbo.msp_documentos_cobro dc
                               WHERE dc.id_contrato_arriendo=c.id_contrato_arriendo
                                 AND dc.estado_documento IN(2,3) AND dc.saldo_pendiente>0),0) documentos_pendientes
                FROM dbo.msp_contratos_arriendo c
                LEFT JOIN dbo.msp_arrendatarios a ON a.id_arrendatario=c.id_arrendatario
                LEFT JOIN dbo.msp_tiendas t ON t.id_tienda=c.id_tienda
                WHERE c.estado_contrato=3";
        foreach ($this->rows($sql) as $row) {
            $documentos = (int) ($row['documentos_pendientes'] ?? 0);
            $descripcion = $documentos > 0
                ? 'El contrato está en proceso de cierre y mantiene ' . $documentos . ' documento(s) con saldo pendiente.'
                : 'El contrato está en proceso de cierre y requiere completar la liquidación financiera.';
            $items[] = $this->item('CONTRATOS', $documentos > 0 ? 'LIQUIDACION_DEUDA_PENDIENTE' : 'LIQUIDACION_PENDIENTE', self::PRIORIDAD_ALTA,
                $documentos > 0 ? 'Liquidación con deuda pendiente' : 'Liquidación final pendiente', $descripcion, [
                    'fecha_origen' => $row['fecha_termino_efectiva'] ?? $row['fecha_registro'] ?? null,
                    'arrendatario' => $row['nombre_locatario'] ?? null,
                    'rut' => $row['rut'] ?? null,
                    'tienda' => $row['nombre_comercial'] ?? null,
                    'contrato' => (int) $row['id_contrato_arriendo'],
                    'cantidad' => max(1, $documentos),
                    'accion_principal' => 'Abrir liquidación',
                    'url_accion' => 'contratos/liquidacion_final.php?id_contrato_arriendo=' . (int) $row['id_contrato_arriendo'],
                    'entidad_id' => (int) $row['id_contrato_arriendo'],
                ]);
        }

        if ($this->table('msp_garantias') && $this->table('msp_vw_garantias_control_integral') && $this->table('msp_garantia_devoluciones')) {
            $sql = "SELECT g.id_garantia,g.id_contrato_arriendo,g.id_local,
                           a.nombre_locatario,a.rut,t.nombre_comercial,l.cdo_local,
                           gr.monto_disponible
                    FROM dbo.msp_garantias g
                    INNER JOIN dbo.msp_vw_garantias_control_integral gr ON gr.id_garantia=g.id_garantia
                    INNER JOIN dbo.msp_contratos_arriendo c ON c.id_contrato_arriendo=g.id_contrato_arriendo AND c.estado_contrato=4
                    LEFT JOIN dbo.msp_arrendatarios a ON a.id_arrendatario=c.id_arrendatario
                    LEFT JOIN dbo.msp_tiendas t ON t.id_tienda=c.id_tienda
                    LEFT JOIN dbo.msp_locales l ON l.id_local=g.id_local
                    WHERE g.estado_garantia<>6 AND gr.monto_disponible>0
                      AND NOT EXISTS (SELECT 1 FROM dbo.msp_garantia_devoluciones d
                                      WHERE d.id_garantia=g.id_garantia AND d.estado_devolucion NOT IN(N'ANULADA'))";
            foreach ($this->rows($sql) as $row) {
                $monto = (float) ($row['monto_disponible'] ?? 0);
                $items[] = $this->item('GARANTIA', 'DEVOLUCION_PENDIENTE', self::PRIORIDAD_ALTA,
                    'Devolución de garantía pendiente', 'El contrato terminó y la garantía mantiene $' . $this->monto($monto) . ' disponible para devolver o imputar.', [
                        'arrendatario' => $row['nombre_locatario'] ?? null,
                        'rut' => $row['rut'] ?? null,
                        'tienda' => $row['nombre_comercial'] ?? null,
                        'contrato' => (int) $row['id_contrato_arriendo'],
                        'local' => $row['cdo_local'] ?? null,
                        'monto' => $monto,
                        'accion_principal' => 'Gestionar devolución',
                        'url_accion' => 'garantias/ficha.php?id_garantia=' . (int) $row['id_garantia'],
                        'entidad_id' => (int) $row['id_garantia'],
                    ]);
            }
        }
        return $items;
    }

    /** @return array<int,array<string,mixed>> */
    private function consultarLocales(): array
    {
        foreach (['msp_contrato_locales', 'msp_contratos_arriendo', 'msp_ocupacion_locales', 'msp_locales'] as $table) {
            if (!$this->table($table)) {
                return [];
            }
        }
        $sql = "SELECT c.id_contrato_arriendo,cl.id_contrato_local,l.id_local,l.cdo_local,t.nombre_comercial,
                       a.nombre_locatario,a.rut,cl.fecha_inicio
                FROM dbo.msp_contrato_locales cl
                INNER JOIN dbo.msp_contratos_arriendo c ON c.id_contrato_arriendo=cl.id_contrato_arriendo
                INNER JOIN dbo.msp_locales l ON l.id_local=cl.id_local
                INNER JOIN dbo.msp_tiendas t ON t.id_tienda=c.id_tienda
                LEFT JOIN dbo.msp_arrendatarios a ON a.id_arrendatario=c.id_arrendatario
                WHERE c.estado_contrato IN(1,2) AND cl.estado_relacion=1
                  AND NOT EXISTS (SELECT 1 FROM dbo.msp_ocupacion_locales o
                                  WHERE o.id_tienda=c.id_tienda AND o.id_local=cl.id_local
                                    AND o.fecha_inicio<=CONVERT(date,SYSDATETIME())
                                    AND (o.fecha_termino IS NULL OR o.fecha_termino>=CONVERT(date,SYSDATETIME())))";
        $items = [];
        foreach ($this->rows($sql) as $row) {
            $items[] = $this->item('LOCALES', 'CONTRATO_SIN_OCUPACION', self::PRIORIDAD_CRITICA,
                'Contrato y ocupación inconsistentes', 'El local figura activo en el contrato, pero no tiene ocupación vigente.', [
                    'fecha_origen' => $row['fecha_inicio'] ?? null,
                    'arrendatario' => $row['nombre_locatario'] ?? null,
                    'rut' => $row['rut'] ?? null,
                    'tienda' => $row['nombre_comercial'] ?? null,
                    'contrato' => (int) $row['id_contrato_arriendo'],
                    'local' => $row['cdo_local'] ?? null,
                    'accion_principal' => 'Revisar local',
                    'url_accion' => 'locales/index.php?filtroTexto=' . rawurlencode((string) ($row['cdo_local'] ?? '')),
                    'entidad_id' => (int) $row['id_contrato_local'],
                ]);
        }
        return $items;
    }

    /** @return array<int,array<string,mixed>> */
    private function consultarContabilidad(): array
    {
        if (!$this->table('msp_acc_asientos') || !$this->table('msp_acc_asientos_detalle')) {
            return [];
        }
        $sql = "SELECT a.id_asiento_contable,a.numero_asiento,a.fecha_contable,a.glosa,
                       SUM(d.debe) total_debe,SUM(d.haber) total_haber
                FROM dbo.msp_acc_asientos a
                INNER JOIN dbo.msp_acc_asientos_detalle d ON d.id_asiento_contable=a.id_asiento_contable
                WHERE a.estado_asiento=1
                GROUP BY a.id_asiento_contable,a.numero_asiento,a.fecha_contable,a.glosa
                HAVING ABS(SUM(d.debe)-SUM(d.haber))>0.01";
        $items = [];
        foreach ($this->rows($sql) as $row) {
            $diferencia = abs((float) $row['total_debe'] - (float) $row['total_haber']);
            $items[] = $this->item('CONTABILIDAD', 'ASIENTO_DESCUADRADO', self::PRIORIDAD_CRITICA,
                'Asiento contable descuadrado', 'El asiento ' . ($row['numero_asiento'] ?: '#' . $row['id_asiento_contable']) . ' presenta una diferencia de $' . $this->monto($diferencia) . '.', [
                    'fecha_origen' => $row['fecha_contable'] ?? null,
                    'monto' => $diferencia,
                    'accion_principal' => 'Revisar asiento',
                    'url_accion' => 'contabilidad/libro.php?periodo=' . rawurlencode(substr((string) ($row['fecha_contable'] ?? ''), 0, 7)),
                    'entidad_id' => (int) $row['id_asiento_contable'],
                ]);
        }
        return $items;
    }

    private function item(string $modulo, string $subtipo, string $prioridad, string $titulo, string $descripcion, array $data = []): array
    {
        $fechaOrigen = $this->fecha($data['fecha_origen'] ?? null);
        $diasPendiente = $data['dias_pendiente'] ?? ($fechaOrigen !== null
            ? max(0, (int) (new DateTimeImmutable($fechaOrigen))->diff($this->ahora)->format('%r%a'))
            : null);
        $item = array_merge([
            'id' => $modulo . ':' . $subtipo . ':' . (string) ($data['entidad_id'] ?? sha1($titulo . $descripcion)),
            'tipo' => $modulo,
            'subtipo' => $subtipo,
            'prioridad' => isset(self::PRIORIDAD_ORDEN[$prioridad]) ? $prioridad : self::PRIORIDAD_NORMAL,
            'titulo' => $titulo,
            'descripcion' => trim($descripcion),
            'fecha_origen' => $fechaOrigen,
            'fecha_limite' => $this->fecha($data['fecha_limite'] ?? null),
            'dias_pendiente' => $diasPendiente,
            'arrendatario' => null,
            'rut' => null,
            'tienda' => null,
            'contrato' => null,
            'local' => null,
            'periodo' => null,
            'monto' => null,
            'modulo_origen' => $modulo,
            'accion_principal' => 'Revisar',
            'url_accion' => '',
            'cantidad' => 1,
            'detalles' => [],
            'estado_bandeja' => 'ABIERTO',
            'id_usuario_asignado' => null,
            'usuario_asignado' => null,
            'id_usuario_toma' => null,
            'usuario_toma' => null,
            'pospuesto_hasta' => null,
            'comentario_interno' => null,
        ], $data);
        unset($item['entidad_id']);
        return $item;
    }

    /** @param array<int,array<string,mixed>> $items */
    private function agrupar(array $items): array
    {
        $grupos = [];
        foreach ($items as $item) {
            $esGrupoMasivo = ($item['modulo_origen'] ?? '') === 'GARANTIA';
            $key = $esGrupoMasivo
                ? implode('|', [(string) $item['modulo_origen'], (string) $item['subtipo'], (string) ($item['periodo'] ?? '')])
                : (string) $item['id'];
            if (!isset($grupos[$key])) {
                $grupos[$key] = $item;
                $grupos[$key]['detalles'] = [$item];
                continue;
            }
            $grupos[$key]['cantidad'] = (int) $grupos[$key]['cantidad'] + (int) ($item['cantidad'] ?? 1);
            $grupos[$key]['monto'] = (float) ($grupos[$key]['monto'] ?? 0) + (float) ($item['monto'] ?? 0);
            $grupos[$key]['detalles'][] = $item;
            if (self::PRIORIDAD_ORDEN[$item['prioridad']] < self::PRIORIDAD_ORDEN[$grupos[$key]['prioridad']]) {
                $grupos[$key]['prioridad'] = $item['prioridad'];
            }
            if (($item['fecha_limite'] ?? null) !== null
                && (($grupos[$key]['fecha_limite'] ?? null) === null || $item['fecha_limite'] < $grupos[$key]['fecha_limite'])) {
                $grupos[$key]['fecha_limite'] = $item['fecha_limite'];
            }
        }
        foreach ($grupos as &$grupo) {
            if (count($grupo['detalles']) > 1) {
                $grupo['id'] = 'GRUPO:' . sha1((string) $grupo['modulo_origen'] . (string) $grupo['subtipo'] . (string) ($grupo['periodo'] ?? ''));
                $grupo['arrendatario'] = null;
                $grupo['rut'] = null;
                $grupo['tienda'] = null;
                $grupo['contrato'] = null;
                $grupo['local'] = null;
                $grupo['descripcion'] = count($grupo['detalles']) . ' casos requieren la misma acción. Revisa el detalle del grupo.';
                if (($grupo['modulo_origen'] ?? '') === 'GARANTIA') {
                    $grupo['url_accion'] = 'garantias/index.php?alerta=' . rawurlencode((string) $grupo['subtipo']);
                }
            }
        }
        unset($grupo);
        return array_values($grupos);
    }

    /** @param array<int,array<string,mixed>> $items */
    private function aplicarMetadatos(array $items, array $filtros): array
    {
        if ($items === [] || !$this->table('msp_pendientes_meta')) {
            return $this->filtrarMetadatos($items, $filtros);
        }
        $meta = [];
        foreach (array_chunk(array_values(array_unique(array_column($items, 'id'))), 500) as $chunk) {
            $holders = [];
            $params = [];
            foreach ($chunk as $index => $clave) {
                $holder = ':clave_' . $index;
                $holders[] = $holder;
                $params[$holder] = $clave;
            }
            $sql = 'SELECT m.*,ua.nombre_completo usuario_asignado,ut.nombre_completo usuario_toma
                    FROM dbo.msp_pendientes_meta m
                    LEFT JOIN dbo.cr_usuarios ua ON ua.id=m.id_usuario_asignado
                    LEFT JOIN dbo.cr_usuarios ut ON ut.id=m.id_usuario_toma
                    WHERE m.pendiente_clave IN (' . implode(',', $holders) . ')';
            foreach ($this->rows($sql, $params) as $row) {
                $meta[(string) $row['pendiente_clave']] = $row;
            }
        }
        $hoy = $this->ahora->format('Y-m-d');
        foreach ($items as &$item) {
            $row = $meta[(string) $item['id']] ?? null;
            if (!is_array($row)) {
                continue;
            }
            $estado = strtoupper((string) ($row['estado_revision'] ?? 'ABIERTO'));
            $pospuestoHasta = $this->fecha($row['pospuesto_hasta'] ?? null);
            if ($estado === 'POSPUESTO' && ($pospuestoHasta === null || $pospuestoHasta < $hoy)) {
                $estado = 'ABIERTO';
                $pospuestoHasta = null;
            }
            $item['estado_bandeja'] = $estado;
            $item['id_usuario_asignado'] = isset($row['id_usuario_asignado']) ? (int) $row['id_usuario_asignado'] : null;
            $item['usuario_asignado'] = $row['usuario_asignado'] ?? null;
            $item['id_usuario_toma'] = isset($row['id_usuario_toma']) ? (int) $row['id_usuario_toma'] : null;
            $item['usuario_toma'] = $row['usuario_toma'] ?? null;
            $item['pospuesto_hasta'] = $pospuestoHasta;
            $item['comentario_interno'] = $row['comentario_interno'] ?? null;
        }
        unset($item);
        return $this->filtrarMetadatos($items, $filtros);
    }

    /** @param array<int,array<string,mixed>> $items */
    private function filtrarMetadatos(array $items, array $filtros): array
    {
        $incluirPospuestos = !empty($filtros['incluir_pospuestos']) || strtoupper((string) ($filtros['estado'] ?? '')) === 'POSPUESTO';
        $estadoFiltro = strtoupper(trim((string) ($filtros['estado'] ?? '')));
        $usuarioFiltro = isset($filtros['usuario_id']) ? (int) $filtros['usuario_id'] : 0;
        $misTareas = !empty($filtros['mis_tareas']);
        return array_values(array_filter($items, static function (array $item) use ($incluirPospuestos, $estadoFiltro, $usuarioFiltro, $misTareas): bool {
            $estado = strtoupper((string) ($item['estado_bandeja'] ?? 'ABIERTO'));
            if (!$incluirPospuestos && $estado === 'POSPUESTO') {
                return false;
            }
            if ($estadoFiltro !== '' && $estado !== $estadoFiltro) {
                return false;
            }
            if (($misTareas || $usuarioFiltro > 0) && (int) ($item['id_usuario_asignado'] ?? 0) !== $usuarioFiltro) {
                return false;
            }
            return true;
        }));
    }

    private function cumpleFiltros(array $item, array $filtros): bool
    {
        $equals = [
            'modulo' => 'modulo_origen',
            'prioridad' => 'prioridad',
            'periodo' => 'periodo',
            'contrato' => 'contrato',
            'local' => 'local',
            'arrendatario' => 'arrendatario',
            'tienda' => 'tienda',
        ];
        foreach ($equals as $filtro => $campo) {
            if (isset($filtros[$filtro]) && $filtros[$filtro] !== ''
                && strtoupper((string) $item[$campo]) !== strtoupper((string) $filtros[$filtro])) {
                return false;
            }
        }
        if (!empty($filtros['desde']) && ($item['fecha_origen'] ?? '') < (string) $filtros['desde']) {
            return false;
        }
        if (!empty($filtros['hasta']) && ($item['fecha_origen'] ?? '9999-12-31') > (string) $filtros['hasta']) {
            return false;
        }
        $buscar = mb_strtolower(trim((string) ($filtros['buscar'] ?? '')), 'UTF-8');
        if ($buscar !== '') {
            $texto = mb_strtolower(implode(' ', array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', [
                $item['titulo'], $item['descripcion'], $item['arrendatario'], $item['rut'], $item['tienda'],
                $item['contrato'], $item['local'], $item['periodo'],
            ])), 'UTF-8');
            if (!str_contains($texto, $buscar)) {
                return false;
            }
        }
        return true;
    }

    /** @return array<int,array<string,mixed>> */
    private function colectar(string $modulo, callable $callback): array
    {
        try {
            $resultado = $callback();
            return is_array($resultado) ? $resultado : [];
        } catch (Throwable $exception) {
            $this->diagnosticos[] = [
                'modulo' => $modulo,
                'mensaje' => $exception->getMessage(),
            ];
            return [];
        }
    }

    private function comparar(array $a, array $b): int
    {
        $cmp = (self::PRIORIDAD_ORDEN[$a['prioridad']] ?? 99) <=> (self::PRIORIDAD_ORDEN[$b['prioridad']] ?? 99);
        if ($cmp !== 0) {
            return $cmp;
        }
        $limiteA = (string) ($a['fecha_limite'] ?? '9999-12-31');
        $limiteB = (string) ($b['fecha_limite'] ?? '9999-12-31');
        if (($cmp = $limiteA <=> $limiteB) !== 0) {
            return $cmp;
        }
        if (($cmp = ((int) ($b['dias_pendiente'] ?? 0)) <=> ((int) ($a['dias_pendiente'] ?? 0))) !== 0) {
            return $cmp;
        }
        return ((float) ($b['monto'] ?? 0)) <=> ((float) ($a['monto'] ?? 0));
    }

    private function table(string $table): bool
    {
        return function_exists('msp2TableExists') && msp2TableExists($this->conn, $table);
    }

    private function cargarConfiguracion(array $claves): array
    {
        if (!function_exists('msp2TableExists') || !msp2TableExists($this->conn, 'msp_configuracion')) {
            return [];
        }
        $sqlClaves = array_map(static fn (string $clave): string => 'pendientes.' . $clave, $claves);
        $holders = [];
        $params = [];
        foreach ($sqlClaves as $index => $clave) {
            $holders[] = ':config_' . $index;
            $params[':config_' . $index] = $clave;
        }
        $resultado = [];
        foreach ($this->rows('SELECT clave,valor FROM dbo.msp_configuracion WHERE clave IN (' . implode(',', $holders) . ')', $params) as $row) {
            $clave = str_replace('pendientes.', '', (string) $row['clave']);
            $valor = trim((string) $row['valor']);
            $resultado[$clave] = $clave === 'hora_cierre_caja' ? $valor : (float) $valor;
        }
        return $resultado;
    }

    /** @return array<int,array<string,mixed>> */
    private function rows(string $sql, array $params = []): array
    {
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    private function fecha(mixed $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        return $raw !== '' ? substr($raw, 0, 10) : null;
    }

    private function monto(float $value): string
    {
        return number_format($value, 0, ',', '.');
    }
}
