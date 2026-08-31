(() => {
    const init = () => {
        const toasts = Array.from(document.querySelectorAll('[data-msp-flash-toast="1"]'));
        if (toasts.length === 0) {
            return;
        }

        const successBurst = document.querySelector('[data-msp-success-burst="1"]');

        toasts.forEach((toastEl) => {
            const delayMsRaw = Number.parseInt(toastEl.getAttribute('data-delay-ms') || '3000', 10);
            const delayMs = Number.isFinite(delayMsRaw) && delayMsRaw > 0 ? delayMsRaw : 3000;
            const flashType = String(toastEl.getAttribute('data-flash-type') || '').trim().toLowerCase();

            if (
                flashType === 'success'
                && successBurst instanceof HTMLElement
                && !successBurst.classList.contains('is-active')
            ) {
                successBurst.classList.add('is-active');
                window.setTimeout(() => {
                    successBurst.remove();
                }, 1300);
            }

            if (!window.bootstrap || !window.bootstrap.Toast) {
                toastEl.classList.add('show');
                window.setTimeout(() => {
                    toastEl.remove();
                }, delayMs);
                return;
            }

            const instance = window.bootstrap.Toast.getOrCreateInstance(toastEl, {
                autohide: true,
                delay: delayMs,
            });
            instance.show();
        });
    };

    window.setTimeout(init, 0);
})();
