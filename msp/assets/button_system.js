(() => {
    'use strict';

    const variantClasses = [
        'btn-primary', 'btn-outline-primary',
        'btn-secondary', 'btn-outline-secondary',
        'btn-success', 'btn-outline-success',
        'btn-warning', 'btn-outline-warning',
        'btn-danger', 'btn-outline-danger',
        'btn-info', 'btn-outline-info',
        'btn-dark', 'btn-outline-dark'
    ];

    const normalize = (value) => String(value || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();

    const isOutlined = (button) => variantClasses.some((className) =>
        className.startsWith('btn-outline-') && button.classList.contains(className)
    );

    const isTableAction = (button) => Boolean(button.closest('table, .gp-row-actions__menu'));

    const setVariant = (button, tone, forceOutline = null) => {
        const outline = forceOutline ?? (isOutlined(button) || isTableAction(button));
        variantClasses.forEach((className) => button.classList.remove(className));
        button.classList.add(`btn-${outline ? 'outline-' : ''}${tone}`);
        button.dataset.gpActionTone = tone;
    };

    const classifyTone = (button) => {
        const label = normalize([
            button.textContent,
            button.getAttribute('aria-label'),
            button.getAttribute('title'),
            button.dataset.confirmTitle,
            button.dataset.confirmMessage,
            button.getAttribute('name'),
            button.getAttribute('value')
        ].filter(Boolean).join(' '));

        if (button.matches('[data-bs-dismiss="modal"], [data-bs-dismiss="alert"]')
            || /^(volver|cancelar|limpiar|imprimir|ayuda|cerrar)$/.test(label)) {
            return 'secondary';
        }
        if (/(eliminar|anular|revertir|borrar|revocar|desactivar|quitar)/.test(label)) {
            return 'danger';
        }
        if (/(reabrir|reapertura|volver a borrador|\bborrador\b|iniciar termino|registrar termino|terminar contrato|cierre definitivo|cerrar definitivamente|cerrar contrato|cerrar periodo|cerrar mes|cerrar lote|cerrar caja)/.test(label)) {
            return 'warning';
        }
        if (/(registrar pago|confirmar pago|\bpagar\b|confirmar recepcion|registrar recepcion|recibir garantia|confirmar aplicacion|aplicar saldo|aplicar garantia|usar saldo|confirmar deposito|\bdepositar\b|\bconciliar\b)/.test(label)) {
            return 'success';
        }
        if (/(guardar|buscar|filtrar|continuar|siguiente|confirmar|crear|nuevo|editar|actualizar|procesar|generar|cargar|importar|enviar|reenviar|tomar en revision|ingresar al control|seguimiento|gestionar)/.test(label)) {
            return 'primary';
        }
        if (/(volver|cancelar|limpiar|imprimir|ayuda|descargar|ver|consultar|desglose|detalle|respaldo)/.test(label)) {
            return 'secondary';
        }

        if (button.matches('.btn-danger, .btn-outline-danger')) return 'primary';
        if (button.matches('.btn-warning, .btn-outline-warning')) return 'primary';
        if (button.matches('.btn-success, .btn-outline-success')) return 'primary';
        if (button.matches('.btn-dark, .btn-outline-dark, .btn-info, .btn-outline-info')) return 'primary';
        if (button.matches('.btn-secondary, .btn-outline-secondary')) return 'secondary';
        return null;
    };

    const iconLabel = (button) => {
        const icon = button.querySelector('i[class*="bi-"]');
        if (!icon) return '';
        const classes = Array.from(icon.classList).join(' ');
        const labels = [
            [/bi-(trash|x-octagon)/, 'Eliminar'],
            [/bi-(pencil|pen)/, 'Editar'],
            [/bi-(eye|search)/, 'Ver detalle'],
            [/bi-(download|file-earmark-arrow-down)/, 'Descargar'],
            [/bi-arrow-left/, 'Volver'],
            [/bi-(arrow-counterclockwise|arrow-repeat)/, 'Revertir'],
            [/bi-(plus|plus-circle)/, 'Agregar'],
            [/bi-(check|check-circle)/, 'Confirmar'],
            [/bi-(three-dots|list)/, 'Más acciones'],
            [/bi-(paperclip|upload)/, 'Adjuntar'],
            [/bi-printer/, 'Imprimir'],
            [/bi-play/, 'Ejecutar'],
            [/bi-eye-slash/, 'No disponible']
        ];
        return labels.find(([pattern]) => pattern.test(classes))?.[1] || 'Acción';
    };

    const makeIconAccessible = (button) => {
        const visibleText = normalize(button.textContent);
        const iconOnly = button.classList.contains('btn-close')
            || (visibleText === '' && Boolean(button.querySelector('i, svg, [class*="icon"]')));
        if (!iconOnly) return;

        const label = button.getAttribute('aria-label')
            || button.getAttribute('title')
            || button.dataset.bsTitle
            || (button.classList.contains('btn-close') ? 'Cerrar' : iconLabel(button));
        button.classList.add('gp-icon-button');
        button.setAttribute('aria-label', label);
        if (!button.hasAttribute('title')) button.setAttribute('title', label);
    };

    const prepareButton = (button) => {
        if (!(button instanceof HTMLElement) || button.dataset.gpButtonReady === 'true') return;
        button.dataset.gpButtonReady = 'true';

        makeIconAccessible(button);
        if (!button.classList.contains('btn')) return;

        if (isTableAction(button)) {
            button.classList.add('btn-sm', 'gp-table-action-button');
        }

        const explicitTone = normalize(button.dataset.gpButtonTone);
        const tone = ['primary', 'secondary', 'success', 'warning', 'danger'].includes(explicitTone)
            ? explicitTone
            : classifyTone(button);
        if (!tone) return;

        const forceOutline = tone === 'secondary'
            ? true
            : (isTableAction(button) ? true : null);
        setVariant(button, tone, forceOutline);
    };

    const prepare = (root) => {
        if (!(root instanceof Element || root instanceof Document)) return;
        if (root instanceof Element && root.matches('button, a.btn')) prepareButton(root);
        root.querySelectorAll('button, a.btn').forEach(prepareButton);
    };

    const init = () => {
        if (!window.location.pathname.startsWith('/portalgp/msp/')) return;
        prepare(document);

        if (document.body && 'MutationObserver' in window) {
            const observer = new MutationObserver((mutations) => {
                mutations.forEach((mutation) => mutation.addedNodes.forEach((node) => {
                    if (node instanceof Element) prepare(node);
                }));
            });
            observer.observe(document.body, {childList: true, subtree: true});
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, {once: true});
    } else {
        init();
    }
})();
