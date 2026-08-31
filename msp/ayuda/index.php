<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

$flash = msp2PullFlash();
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Ayuda</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>

<main class="gp-main p-4">
    <div class="container" style="max-width: 980px;">
        <?php msp2RenderFlash($flash); ?>

        <section class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div>
                        <h1 class="h4 mb-1">Centro de Ayuda MSP</h1>
                        <p class="text-muted mb-0">Guía rápida, preguntas frecuentes y tutorial interactivo.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a class="btn btn-outline-secondary" href="<?php echo msp2Escape(msp2Url('msp_menu.php')); ?>">
                            <i class="bi bi-grid-3x3-gap me-1" aria-hidden="true"></i>Ir al Menú
                        </a>
                        <a class="btn btn-primary" href="<?php echo msp2Escape(msp2Url('msp_menu.php?tour=1')); ?>">
                            <i class="bi bi-play-circle me-1" aria-hidden="true"></i>Iniciar Tutorial
                        </a>
                        <a class="btn btn-outline-primary" href="<?php echo msp2Escape(msp2Url('pagos/simulacion_masiva.php?tour=1')); ?>">
                            <i class="bi bi-play-circle me-1" aria-hidden="true"></i>Tutorial Pago Masivo
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <section class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h5">Inicio rápido</h2>
                <ol class="mb-0">
                    <li>Abre el <strong>Menú MSP</strong> y ubica tu área: Administración, Facturación, Cobranza o Reportes.</li>
                    <li>En <strong>Facturación</strong>, usa “Generar documento de cobro” para la operación mensual.</li>
                    <li>En <strong>Cobranza</strong>, registra pagos y aplica ajustes manuales cuando corresponda.</li>
                    <li>Revisa <strong>Reportes</strong> para validar cierres, deudores y consumos.</li>
                </ol>
            </div>
        </section>

        <section class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h5">Referencias visuales sugeridas</h2>
                <p class="text-muted mb-2">Para reforzar capacitación interna, se recomienda agregar capturas de estas pantallas:</p>
                <ul class="mb-0">
                    <li>Menú MSP completo con explicación por sección.</li>
                    <li>Flujo de operación mensual en facturación (pasos principales).</li>
                    <li>Registro de pago y ajustes manuales en cobranza.</li>
                </ul>
            </div>
        </section>

        <section class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h5">Preguntas frecuentes (FAQ)</h2>
                <div class="accordion" id="faq_msp">
                    <div class="accordion-item">
                        <h3 class="accordion-header" id="faq_h_1">
                            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq_c_1" aria-expanded="true" aria-controls="faq_c_1">
                                ¿Cómo vuelvo a ver el tutorial del menú?
                            </button>
                        </h3>
                        <div id="faq_c_1" class="accordion-collapse collapse show" aria-labelledby="faq_h_1" data-bs-parent="#faq_msp">
                            <div class="accordion-body">
                                Haz clic en <strong>Ver tutorial</strong> en el Menú MSP o entra desde esta página con “Iniciar Tutorial”.
                            </div>
                        </div>
                    </div>
                    <div class="accordion-item">
                        <h3 class="accordion-header" id="faq_h_2">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq_c_2" aria-expanded="false" aria-controls="faq_c_2">
                                ¿Cómo abro la ayuda desde cualquier pantalla?
                            </button>
                        </h3>
                        <div id="faq_c_2" class="accordion-collapse collapse" aria-labelledby="faq_h_2" data-bs-parent="#faq_msp">
                            <div class="accordion-body">
                                Usa el botón <strong>Ayuda</strong> en el encabezado del módulo MSP.
                            </div>
                        </div>
                    </div>
                    <div class="accordion-item">
                        <h3 class="accordion-header" id="faq_h_3">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq_c_3" aria-expanded="false" aria-controls="faq_c_3">
                                ¿Qué hago si no veo un módulo?
                            </button>
                        </h3>
                        <div id="faq_c_3" class="accordion-collapse collapse" aria-labelledby="faq_h_3" data-bs-parent="#faq_msp">
                            <div class="accordion-body">
                                Revisa tus permisos de usuario. Si el acceso sigue faltando, solicita habilitación al administrador del sistema.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
