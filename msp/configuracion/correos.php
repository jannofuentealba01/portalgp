<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/mail_helper.php';

msp2RequireAccess();

$descripcion = 'Control global para permitir o bloquear correos reales enviados a arrendatarios.';
$idUsuario = isset($_SESSION['usuario']['id']) ? (int) $_SESSION['usuario']['id'] : null;
$fraseConfirmacion = 'MSP/ENVIO';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $accion = trim((string) ($_POST['accion'] ?? ''));
    $nuevoValor = $accion === 'habilitar' ? '1' : '0';
    $confirmacion = trim((string) ($_POST['confirmacion_envio_correo'] ?? ''));

    if ($confirmacion !== $fraseConfirmacion) {
        msp2SetFlash('warning', 'Debes confirmar el cambio escribiendo MSP/ENVIO.');
        msp2Redirect('configuracion/correos.php');
    }

    try {
        msp2ConfiguracionSet($conn, 'mail_arrendatarios_habilitado', $nuevoValor, $descripcion, $idUsuario);
        msp2SetFlash(
            'success',
            $nuevoValor === '1'
                ? 'El envío real a correos de arrendatarios quedó habilitado.'
                : 'El envío real a correos de arrendatarios quedó deshabilitado.'
        );
    } catch (Throwable) {
        msp2SetFlash('danger', 'No fue posible actualizar la configuración de correos.');
    }

    msp2Redirect('configuracion/correos.php');
}

$flash = msp2PullFlash();
$loadError = null;
$estadoHabilitado = false;
$correoDemoConfigRaw = trim((string) (mspMailConfig()['demo']['to'] ?? ''));
$correoDemoConfig = filter_var($correoDemoConfigRaw, FILTER_VALIDATE_EMAIL) !== false ? $correoDemoConfigRaw : '';
$ultimaActualizacion = null;

try {
    msp2EnsureConfiguracionTable($conn);
    $estadoHabilitado = msp2MailTenantDeliveryEnabled($conn);

    $stmt = $conn->prepare(
        'SELECT TOP 1 fecha_actualizacion, id_usuario_actualizacion
         FROM dbo.msp_configuracion
         WHERE clave = :clave'
    );
    $stmt->bindValue(':clave', 'mail_arrendatarios_habilitado', PDO::PARAM_STR);
    $stmt->execute();
    $ultimaActualizacion = $stmt->fetch() ?: null;
} catch (Throwable) {
    $loadError = 'No fue posible cargar o crear la configuración de correos.';
}
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Configuración Correos</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>

<main class="gp-main py-4">
    <div class="container" style="max-width: 980px;">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <a href="<?php echo msp2Escape(msp2Url('msp_menu.php')); ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver al menú MSP
            </a>
            <span class="section-kicker">MSP / Configuración</span>
        </div>

        <?php msp2RenderFlash($flash); ?>

        <section class="box-container">
            <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
                <div>
                    <h1 class="h4 mb-1">Configuración de correos</h1>
                    <p class="text-muted mb-0">Controla el envío real a correos de arrendatarios.</p>
                </div>
                <span class="badge <?php echo $estadoHabilitado ? 'text-bg-success' : 'text-bg-secondary'; ?> fs-6">
                    <?php echo $estadoHabilitado ? 'Envío real habilitado' : 'Envío real bloqueado'; ?>
                </span>
            </div>

            <?php if ($loadError !== null): ?>
                <div class="alert alert-danger mb-0">
                    <?php echo msp2Escape($loadError); ?>
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <div class="col-12 col-lg-7">
                        <div class="border rounded p-3 h-100 bg-white">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <i class="bi bi-envelope-check text-primary" aria-hidden="true"></i>
                                <strong>Correos a arrendatarios</strong>
                            </div>
                            <p class="text-muted mb-3">
                                Cuando está deshabilitado, MSP no envía comprobantes ni lotes programados al correo real del arrendatario.
                            </p>
                            <form method="post" class="d-flex flex-wrap gap-2 js-mail-config-form">
                                <?php msp2CsrfField(); ?>
                                <input type="hidden" name="confirmacion_envio_correo" value="">
                                <?php if ($estadoHabilitado): ?>
                                    <input type="hidden" name="accion" value="deshabilitar">
                                    <button type="submit" class="btn btn-outline-danger" data-mail-config-action-label="deshabilitar el envío real">
                                        <i class="bi bi-envelope-slash me-1" aria-hidden="true"></i>Deshabilitar envío real
                                    </button>
                                <?php else: ?>
                                    <input type="hidden" name="accion" value="habilitar">
                                    <button type="submit" class="btn btn-success" data-mail-config-action-label="habilitar el envío real">
                                        <i class="bi bi-envelope-check me-1" aria-hidden="true"></i>Habilitar envío real
                                    </button>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                    <div class="col-12 col-lg-5">
                        <div class="border rounded p-3 h-100 bg-white">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <i class="bi bi-send text-primary" aria-hidden="true"></i>
                                <strong>Modo demo</strong>
                            </div>
                            <?php if ($correoDemoConfig !== ''): ?>
                                <p class="mb-1">Disponible</p>
                                <small class="text-muted">Destino por defecto: <?php echo msp2Escape($correoDemoConfig); ?></small>
                            <?php else: ?>
                                <p class="mb-1">Sin correo demo configurado</p>
                                <small class="text-muted">Configura <code>MAIL_DEMO_TO</code> o <code>msp/config/mail.php</code>.</small>
                            <?php endif; ?>
                            <hr>
                            <small class="text-muted">
                                El modo demo puede enviar aunque el envío real esté bloqueado.
                            </small>
                        </div>
                    </div>
                </div>

                <?php if (is_array($ultimaActualizacion)): ?>
                    <div class="small text-muted mt-3">
                        Última actualización:
                        <?php echo msp2Escape((string) ($ultimaActualizacion['fecha_actualizacion'] ?? '-')); ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
</main>

<div class="modal fade" id="modalConfirmarCambioCorreo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5">Confirmar cambio de correos</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2" id="mail_config_confirm_text">
                    Para continuar, escribe la palabra de confirmación.
                </p>
                <label for="mail_config_confirm_phrase" class="form-label">Confirmación requerida</label>
                <input
                    type="text"
                    class="form-control"
                    id="mail_config_confirm_phrase"
                    autocomplete="off"
                    spellcheck="false"
                    placeholder="<?php echo msp2Escape($fraseConfirmacion); ?>">
                <div class="small text-muted mt-2">
                    Frase exacta: <strong><?php echo msp2Escape($fraseConfirmacion); ?></strong>
                </div>
                <div class="small text-danger mt-2 d-none" id="mail_config_confirm_error">
                    La confirmación no coincide.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="mail_config_confirm_submit">Confirmar cambio</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const requiredPhrase = <?php echo json_encode($fraseConfirmacion, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const form = document.querySelector('.js-mail-config-form');
    const modalElement = document.getElementById('modalConfirmarCambioCorreo');
    const input = document.getElementById('mail_config_confirm_phrase');
    const error = document.getElementById('mail_config_confirm_error');
    const confirmButton = document.getElementById('mail_config_confirm_submit');
    const text = document.getElementById('mail_config_confirm_text');
    let pendingForm = null;

    if (!form || !modalElement || !input || !confirmButton || !window.bootstrap) {
        return;
    }

    const modal = window.bootstrap.Modal.getOrCreateInstance(modalElement);

    form.addEventListener('submit', function (event) {
        if (form.dataset.mailConfigConfirmed === '1') {
            return;
        }

        event.preventDefault();
        pendingForm = form;
        const submitter = event.submitter instanceof HTMLElement ? event.submitter : null;
        const actionLabel = submitter ? (submitter.dataset.mailConfigActionLabel || 'cambiar la configuración') : 'cambiar la configuración';

        if (text) {
            text.textContent = 'Para ' + actionLabel + ', escribe la frase de confirmación exacta.';
        }
        if (error) {
            error.classList.add('d-none');
        }
        input.value = '';
        modal.show();
    });

    modalElement.addEventListener('shown.bs.modal', function () {
        input.focus();
    });

    confirmButton.addEventListener('click', function () {
        if (!pendingForm) {
            return;
        }

        if (input.value.trim() !== requiredPhrase) {
            if (error) {
                error.classList.remove('d-none');
            }
            input.focus();
            input.select();
            return;
        }

        const hidden = pendingForm.querySelector('input[name="confirmacion_envio_correo"]');
        if (hidden instanceof HTMLInputElement) {
            hidden.value = requiredPhrase;
        }
        pendingForm.dataset.mailConfigConfirmed = '1';
        modal.hide();
        pendingForm.submit();
    });
})();
</script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
