<?php
declare(strict_types=1);

/**
 * Valida los destinos internos desde los que se abre una aplicación de garantía.
 */
function msp2GarantiaAplicacionSafeReturnTo(mixed $value): string
{
    $returnTo = trim((string) $value);
    if ($returnTo === '') {
        return '';
    }

    $allowedPatterns = [
        '#^arrendatarios/ficha\.php\?id_arrendatario=\d+$#',
        '#^contratos/ficha\.php\?id_contrato_arriendo=\d+$#',
        '#^contratos/liquidacion_final\.php\?id_contrato_arriendo=\d+$#',
        '#^cobranza/gestionar\.php\?id_contrato=\d+(?:&return_to=[A-Za-z0-9_\-\.\[%\]=&]*)?$#',
    ];

    foreach ($allowedPatterns as $pattern) {
        if (preg_match($pattern, $returnTo) === 1) {
            return $returnTo;
        }
    }

    return '';
}
