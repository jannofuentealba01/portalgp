<?php
declare(strict_types=1);

/**
 * Acepta únicamente destinos internos conocidos del flujo de pago por contrato.
 */
function msp2PagoContratoSafeReturnTo(mixed $value): string
{
    $returnTo = trim((string) $value);
    if ($returnTo === '') {
        return '';
    }

    $allowedPatterns = [
        '#^cobranza/gestionar\.php\?id_contrato=\d+(?:&return_to=[A-Za-z0-9_\-\.\[%\]=&]*)?$#',
        '#^arrendatarios/ficha\.php\?id_arrendatario=\d+$#',
        '#^contratos/ficha\.php\?id_contrato_arriendo=\d+(?:&return_to=cierre(?:%2F|/)index\.php(?:%3F[A-Za-z0-9_\-\.\[\]%=&]*)?)?$#i',
        '#^pendientes/index\.php(?:\?[A-Za-z0-9_\-\.\[\]%=&]*)?$#i',
    ];

    foreach ($allowedPatterns as $pattern) {
        if (preg_match($pattern, $returnTo) === 1) {
            return $returnTo;
        }
    }

    return '';
}
