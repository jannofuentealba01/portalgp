<?php
declare(strict_types=1);

if (!function_exists('ctMenuSections')) {
    function ctMenuSections(): array
    {
        $hasModule = static function (string $relativePath): bool {
            $target = dirname(__DIR__) . '/' . ltrim($relativePath, '/');
            return is_file($target);
        };

        /*
         * Modo desarrollo:
         * Mantiene visible solo lo activo en este momento.
         * Para habilitar un item, agrega su clave a este arreglo.
         */
        $activeItems = [
            'predial.terrenos' => true,
            'predial.solicitudes' => true,
            'terceros.gestion' => true,
            'administracion.catalogo' => true,
            'contabilidad.tasaciones' => true,
            'contabilidad.ventas' => true,
            'contabilidad.estados' => true,
            'reportes.dbml' => true,
            'reportes.sql' => true,
        ];

        $isActive = static function (string $key) use ($activeItems): bool {
            return isset($activeItems[$key]) && $activeItems[$key] === true;
        };

        return [
            [
                'id' => 'terrenos',
                'label' => 'Terrenos',
                'icon' => 'bi-map-fill',
                'items' => [
                    [
                        'label' => 'Terrenos',
                        'caption' => 'Inventario de terrenos.',
                        'icon' => 'bi-pin-map-fill',
                        'href' => ctUrl('predial/index.php'),
                        'enabled' => $hasModule('predial/index.php') && $isActive('predial.terrenos'),
                    ],
                    [
                        'label' => 'Solicitudes',
                        'caption' => 'Borradores de adquisición y revisión por áreas.',
                        'icon' => 'bi-journal-check',
                        'href' => ctUrl('solicitudes/index.php'),
                        'enabled' => $hasModule('solicitudes/index.php') && $isActive('predial.solicitudes'),
                    ],
                    // [
                    //     'label' => 'Trazabilidad Predial',
                    //     'caption' => 'Operaciones prediales, historial y titularidad.',
                    //     'icon' => 'bi-diagram-3-fill',
                    //     'href' => ctUrl('predial/index.php#trazabilidad'),
                    //     'enabled' => $hasModule('predial/index.php') && $isActive('predial.trazabilidad'),
                    // ],
                ],
            ],
            // [
            //     'id' => 'terceros',
            //     'label' => 'Personas y Terceros',
            //     'icon' => 'bi-people-fill',
            //     'items' => [
            //         [
            //             'label' => 'Terceros',
            //             'caption' => 'Gestión de personas naturales y jurídicas relacionadas al sistema.',
            //             'icon' => 'bi-person-vcard-fill',
            //             'href' => ctUrl('predial/terceros/index.php'),
            //             'enabled' => $hasModule('predial/terceros/index.php') && $isActive('terceros.gestion'),
            //         ],
            //     ],
            // ],
            [
                'id' => 'administracion',
                'label' => 'Administración',
                'icon' => 'bi-sliders2-vertical',
                'items' => [
                    [
                        'label' => 'Catálogos',
                        'caption' => 'Mantenedores base del sistema.',
                        'icon' => 'bi-collection-fill',
                        'href' => ctUrl('administracion/catalogo/index.php'),
                        'enabled' => $hasModule('administracion/catalogo/index.php') && $isActive('administracion.catalogo'),
                    ],
                    [
                        'label' => 'Terceros',
                        'caption' => 'Gestión de personas naturales y jurídicas relacionadas al sistema.',
                        'icon' => 'bi-person-vcard-fill',
                        'href' => ctUrl('predial/terceros/index.php'),
                        'enabled' => $hasModule('predial/terceros/index.php') && $isActive('terceros.gestion'),
                    ],
                ],
            ],
            [
                'id' => 'construccion',
                'label' => 'Construccion',
                'icon' => 'bi-building-fill-gear',
                'items' => [
                    [
                        'label' => 'Proyectos',
                        'caption' => 'Control de proyectos constructivos asociados a terrenos.',
                        'icon' => 'bi-clipboard2-check-fill',
                        'href' => ctUrl('construccion/index.php'),
                        'enabled' => $hasModule('construccion/index.php') && $isActive('construccion.proyectos'),
                    ],
                    [
                        'label' => 'Construcciones',
                        'caption' => 'Superficies, tipologías y relación con proyecto.',
                        'icon' => 'bi-houses-fill',
                        'href' => ctUrl('construccion/index.php#construcciones'),
                        'enabled' => $hasModule('construccion/index.php') && $isActive('construccion.construcciones'),
                    ],
                    [
                        'label' => 'Permisos y Recepciones',
                        'caption' => 'Control documental de etapas constructivas y legales.',
                        'icon' => 'bi-file-earmark-ruled-fill',
                        'href' => ctUrl('construccion/index.php#permisos'),
                        'enabled' => $hasModule('construccion/index.php') && $isActive('construccion.permisos'),
                    ],
                ],
            ],
            [
                'id' => 'tributaria',
                'label' => 'Tributario',
                'icon' => 'bi-receipt-cutoff',
                'items' => [
                    [
                        'label' => 'Avalúos',
                        'caption' => 'Registro anual y valores de referencia tributaria.',
                        'icon' => 'bi-currency-dollar',
                        'href' => ctUrl('tributaria/index.php'),
                        'enabled' => $hasModule('tributaria/index.php') && $isActive('tributaria.avaluos'),
                    ],
                    [
                        'label' => 'Contribuciones',
                        'caption' => 'Cuotas, períodos, estados y conciliación de pago.',
                        'icon' => 'bi-cash-coin',
                        'href' => ctUrl('tributaria/index.php#contribuciones'),
                        'enabled' => $hasModule('tributaria/index.php') && $isActive('tributaria.contribuciones'),
                    ],
                    [
                        'label' => 'SII y Exenciones',
                        'caption' => 'Estados SII, destino y condición de rol.',
                        'icon' => 'bi-building-check',
                        'href' => ctUrl('tributaria/index.php#sii'),
                        'enabled' => $hasModule('tributaria/index.php') && $isActive('tributaria.sii'),
                    ],
                ],
            ],
            [
                'id' => 'contabilidad',
                'label' => 'Comercial y Ventas',
                'icon' => 'bi-graph-up-arrow',
                'items' => [
                    [
                        'label' => 'Tasaciones',
                        'caption' => 'Valoraciones comerciales y financieras del activo.',
                        'icon' => 'bi-bar-chart-line-fill',
                        'href' => ctUrl('contabilidad/index.php'),
                        'enabled' => $hasModule('contabilidad/index.php') && $isActive('contabilidad.tasaciones'),
                    ],
                    [
                        'label' => 'Ventas',
                        'caption' => 'Venta de terrenos, terceros y porcentajes asociados.',
                        'icon' => 'bi-bag-check-fill',
                        'href' => ctUrl('contabilidad/index.php#ventas'),
                        'enabled' => $hasModule('contabilidad/index.php') && $isActive('contabilidad.ventas'),
                    ],
                    [
                        'label' => 'Estados Comerciales',
                        'caption' => 'Seguimiento del ciclo comercial del terreno.',
                        'icon' => 'bi-kanban-fill',
                        'href' => ctUrl('contabilidad/index.php#estados'),
                        'enabled' => $hasModule('contabilidad/index.php') && $isActive('contabilidad.estados'),
                    ],
                ],
            ],
            [
                'id' => 'reportes',
                'label' => 'Reportes y BD',
                'icon' => 'bi-file-earmark-bar-graph-fill',
                'items' => [
                    [
                        'label' => 'Reportes CT',
                        'caption' => 'Consultas y reportes transversales del módulo.',
                        'icon' => 'bi-clipboard-data-fill',
                        'href' => ctUrl('reportes/index.php'),
                        'enabled' => $hasModule('reportes/index.php') && $isActive('reportes.ct'),
                    ],
                    [
                        'label' => 'Modelo DBML',
                        'caption' => 'Diagrama y estructura del modelo CT.',
                        'icon' => 'bi-bezier2',
                        'href' => ctUrl('docs/modulo_terrenos.dbml'),
                        'enabled' => $isActive('reportes.dbml'),
                    ],
                    [
                        'label' => 'Scripts SQL',
                        'caption' => 'Core, integridad y consultas del módulo.',
                        'icon' => 'bi-filetype-sql',
                        'href' => ctUrl('db/README.md'),
                        'enabled' => $isActive('reportes.sql'),
                    ],
                ],
            ],
        ];
    }
}
