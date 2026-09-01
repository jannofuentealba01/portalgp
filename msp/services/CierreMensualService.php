<?php
declare(strict_types=1);

final class CierreMensualService
{
    public const BORRADOR = 1;
    public const CALCULADO = 2;
    public const CERRADO = 3;
    public const ANULADO = 4;
    public const REVISADO = 5;

    public function __construct(private PDO $conn)
    {
    }

    public static function estados(): array
    {
        return [
            self::BORRADOR => 'Borrador',
            self::CALCULADO => 'Calculado',
            self::REVISADO => 'Revisado',
            self::CERRADO => 'Cerrado',
            self::ANULADO => 'Anulado',
        ];
    }

    public static function etiqueta(int $estado): string
    {
        return self::estados()[$estado] ?? 'Desconocido';
    }

    public static function destinosPermitidos(int $estado): array
    {
        return match ($estado) {
            self::BORRADOR => [self::CALCULADO],
            self::CALCULADO => [self::REVISADO, self::BORRADOR],
            self::REVISADO => [self::CERRADO, self::BORRADOR],
            self::CERRADO, self::ANULADO => [self::BORRADOR],
            default => [],
        };
    }

    public function transicionar(int $idCierre, int $estadoEsperado, int $estadoDestino, string $motivo, ?int $idUsuario): void
    {
        if (!in_array($estadoDestino, self::destinosPermitidos($estadoEsperado), true)) {
            throw new RuntimeException('La transición solicitada no está permitida.');
        }
        if (!msp2ProcedureExists($this->conn, 'msp_cierre_mensual_transicionar')) {
            throw new RuntimeException('Falta instalar la protección de transiciones del cierre mensual.');
        }
        try {
            $stmt = $this->conn->prepare(
                'EXEC dbo.msp_cierre_mensual_transicionar
                    @id_cierre_mensual=:id,
                    @estado_esperado=:origen,
                    @estado_destino=:destino,
                    @motivo=:motivo,
                    @id_usuario=:usuario'
            );
            $stmt->bindValue(':id', $idCierre, PDO::PARAM_INT);
            $stmt->bindValue(':origen', $estadoEsperado, PDO::PARAM_INT);
            $stmt->bindValue(':destino', $estadoDestino, PDO::PARAM_INT);
            $stmt->bindValue(':motivo', $motivo !== '' ? $motivo : null, $motivo !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':usuario', $idUsuario, $idUsuario !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->execute();
            $stmt->closeCursor();
        } catch (PDOException $exception) {
            $message = $exception->getMessage();
            if (preg_match('/\[(?:Microsoft|ODBC).*?\]\s*(.+?)(?:\s*\(|$)/s', $message, $match) === 1) {
                $message = trim($match[1]);
            }
            throw new RuntimeException($message !== '' ? $message : 'No fue posible cambiar el estado del período.', 0, $exception);
        }
    }
}
