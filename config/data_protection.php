<?php
declare(strict_types=1);

/*
 * Clasificación operativa. Los plazos son una línea base interna conservadora;
 * antes de automatizar eliminaciones deben validarse con asesoría legal/contable.
 */
return [
    'policy_version' => '2026-09-08',
    'legal_hold_overrides_deletion' => true,
    'automatic_deletion_enabled' => false,
    'categories' => [
        'authentication' => [
            'examples' => ['password_hash', 'intentos de acceso', 'sesiones', 'tokens OAuth'],
            'classification' => 'restringido',
            'access' => ['Sistema', 'Administración de seguridad'],
            'retention' => ['tokens_oauth' => 'duración de la sesión', 'intentos_y_logs' => '90 días'],
            'protection' => ['hash unidireccional para contraseñas', 'TLS', 'cookies seguras', 'sin tokens en logs'],
        ],
        'identity_contact' => [
            'examples' => ['RUT', 'nombre', 'correo', 'teléfono', 'dirección'],
            'classification' => 'confidencial',
            'access' => ['Personal autorizado según permiso funcional'],
            'retention' => ['baseline' => '10 años desde el cierre de la relación, sujeto a validación legal'],
            'protection' => ['TLS en tránsito', 'cifrado de base/volumen en producción', 'auditoría de accesos'],
        ],
        'financial_contractual' => [
            'examples' => ['contratos', 'pagos', 'garantías', 'cuentas bancarias', 'documentos de cobro', 'comprobantes'],
            'classification' => 'restringido',
            'access' => ['MSP Cobranza', 'MSP Tesoreria', 'MSP Cierre Mensual', 'MSP Reportes'],
            'retention' => ['baseline' => '10 años desde el cierre, sujeto a validación legal y contable'],
            'protection' => ['TLS en tránsito', 'cifrado de base/volumen en producción', 'trazabilidad', 'mínimo privilegio'],
        ],
        'uploaded_files' => [
            'examples' => ['PDF', 'planillas importadas', 'respaldos de pago', 'adjuntos de garantía'],
            'classification' => 'restringido',
            'access' => ['Descarga autenticada y permiso funcional'],
            'retention' => ['temporales' => '24 horas', 'documentos confirmados' => 'igual al expediente asociado'],
            'protection' => ['fuera de rutas públicas cuando aplique', 'validación de tipo/tamaño', 'nombre aleatorio'],
        ],
        'technical_logs' => [
            'examples' => ['errores', 'eventos de seguridad', 'diagnósticos'],
            'classification' => 'interno',
            'access' => ['Administración técnica'],
            'retention' => ['baseline' => '90 días'],
            'protection' => ['sin secretos, tokens ni datos financieros completos', 'acceso restringido'],
        ],
    ],
    'production_requirements' => [
        'database_transport_encryption' => true,
        'validate_sql_server_certificate' => true,
        'database_or_volume_encryption_at_rest' => true,
        'encrypted_backups' => true,
        'external_secret_store' => true,
        'quarterly_access_review' => true,
    ],
];
