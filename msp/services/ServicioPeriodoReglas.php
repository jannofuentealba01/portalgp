<?php
declare(strict_types=1);

final class ServicioPeriodoReglas
{
    public static function desfaseMeses(string $codigoServicio): ?int
    {
        return match (strtoupper(trim($codigoServicio))) {
            'LUZ', 'GAS' => 1,
            'AGUA' => 2,
            default => null,
        };
    }

    public static function ventanaMedicion(string $codigoServicio, string $periodoFacturacion): ?array
    {
        $periodoDate = DateTimeImmutable::createFromFormat('Y-m-d', $periodoFacturacion);
        if ($periodoDate === false || $periodoDate->format('Y-m-d') !== $periodoFacturacion) {
            return null;
        }

        $codigo = strtoupper(trim($codigoServicio));
        $desfase = self::desfaseMeses($codigo);
        if ($desfase === null) {
            return null;
        }

        $targetMonth = $periodoDate->modify('-' . $desfase . ' months');
        $baseMaxDateObj = $targetMonth->modify('last day of this month');
        if ($baseMaxDateObj === false) {
            return null;
        }

        $baseMaxDate = $baseMaxDateObj->format('Y-m-d');
        $maxDate = $baseMaxDate;
        if ($codigo === 'GAS') {
            $gasMaxDateObj = $baseMaxDateObj->modify('+5 days');
            if ($gasMaxDateObj === false) {
                return null;
            }
            $maxDate = $gasMaxDateObj->format('Y-m-d');
        }

        return [
            'servicio' => $codigo,
            'periodo_ym' => $targetMonth->format('Y-m'),
            'min' => $targetMonth->format('Y-m-01'),
            'max' => $maxDate,
            'default' => $baseMaxDate,
        ];
    }

    public static function periodoEmisionSugerido(string $codigoServicio, string $fechaTermino): string
    {
        $desfase = self::desfaseMeses($codigoServicio);
        if ($desfase === null) {
            return '';
        }

        try {
            $fecha = new DateTimeImmutable($fechaTermino);
        } catch (Throwable) {
            return '';
        }

        return $fecha
            ->modify('first day of this month')
            ->modify('+' . $desfase . ' months')
            ->format('Y-m');
    }
}
