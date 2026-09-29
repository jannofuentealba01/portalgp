<?php
declare(strict_types=1);

/**
 * Identifica movimientos que vuelven inmutable un documento de cobro.
 * Los artefactos preliminares sin movimiento real no bloquean correcciones.
 */
final class DocumentoProteccionService
{
    /**
     * @return array<int,array{tabla:string,columna:string,condicion:string,label:string,columnas:array<int,string>}>
     */
    public static function definiciones(PDO $conn): array
    {
        $definiciones = [
            [
                'tabla' => 'msp_pagos',
                'columna' => 'id_documento_cobro',
                'condicion' => 'dep.estado_pago=1',
                'label' => 'pagos registrados',
                'columnas' => ['estado_pago'],
            ],
            [
                'tabla' => 'msp_saldo_favor_periodo_aplicaciones',
                'columna' => 'id_documento_cobro',
                'condicion' => 'dep.estado_aplicacion=1 AND dep.monto_aplicado>0',
                'label' => 'saldo a favor aplicado',
                'columnas' => ['estado_aplicacion', 'monto_aplicado'],
            ],
            [
                'tabla' => 'msp_garantia_documento_aplicaciones',
                'columna' => 'id_documento_cobro',
                'condicion' => 'dep.monto_aplicado>0',
                'label' => 'garantía aplicada',
                'columnas' => ['monto_aplicado'],
            ],
            [
                'tabla' => 'msp_movimientos_garantia',
                'columna' => 'id_documento_cobro',
                'condicion' => 'dep.monto_movimiento>0',
                'label' => 'movimientos de garantía',
                'columnas' => ['monto_movimiento'],
            ],
            [
                'tabla' => 'msp_envio_lote_documentos',
                'columna' => 'id_documento_cobro',
                'condicion' => '1=1',
                'label' => 'historial de envío',
                'columnas' => [],
            ],
            [
                'tabla' => 'msp_pago_contrato_operacion_detalle',
                'columna' => 'id_documento_cobro',
                'condicion' => 'dep.monto_aplicado>0',
                'label' => 'operaciones de pago',
                'columnas' => ['monto_aplicado'],
            ],
            [
                'tabla' => 'msp_pago_contrato_archivos',
                'columna' => 'id_documento_cobro',
                'condicion' => "dep.id_pago>0 AND UPPER(COALESCE(dep.estado_archivo,N''))<>N'FALTANTE'",
                'label' => 'respaldos de pago',
                'columnas' => ['id_pago', 'estado_archivo'],
            ],
        ];

        return array_values(array_filter(
            $definiciones,
            static function (array $definicion) use ($conn): bool {
                if (!msp2TableExists($conn, $definicion['tabla'])
                    || !msp2ColumnExists($conn, $definicion['tabla'], $definicion['columna'])) {
                    return false;
                }
                foreach ($definicion['columnas'] as $columna) {
                    if (!msp2ColumnExists($conn, $definicion['tabla'], $columna)) {
                        return false;
                    }
                }
                return true;
            }
        ));
    }

    /** @return array<int,string> */
    public static function fuentesSql(PDO $conn): array
    {
        $fuentes = [];
        foreach (self::definiciones($conn) as $definicion) {
            $fuentes[] = 'SELECT dep.' . $definicion['columna']
                . ' AS id_documento_cobro FROM dbo.' . $definicion['tabla'] . ' dep'
                . ' WHERE dep.' . $definicion['columna'] . ' IS NOT NULL'
                . ' AND (' . $definicion['condicion'] . ')';
        }
        return $fuentes;
    }

    /** @return array<int,string> */
    public static function listar(PDO $conn, int $idDocumento): array
    {
        if ($idDocumento <= 0) {
            return [];
        }

        $encontradas = [];
        foreach (self::definiciones($conn) as $definicion) {
            $sql = 'SELECT TOP(1) 1 FROM dbo.' . $definicion['tabla'] . ' dep'
                . ' WHERE dep.' . $definicion['columna'] . '=:documento'
                . ' AND (' . $definicion['condicion'] . ')';
            $stmt = $conn->prepare($sql);
            $stmt->execute([':documento' => $idDocumento]);
            if ($stmt->fetchColumn() !== false) {
                $encontradas[] = $definicion['label'];
            }
        }
        return array_values(array_unique($encontradas));
    }
}
