(() => {
    const init = () => {
        const toasts = Array.from(document.querySelectorAll('[data-ct-flash-toast="1"]'));
        if (toasts.length === 0) {
            return;
        }

        toasts.forEach((toastEl) => {
            const delayMsRaw = Number.parseInt(toastEl.getAttribute('data-delay-ms') || '3000', 10);
            const delayMs = Number.isFinite(delayMsRaw) && delayMsRaw > 0 ? delayMsRaw : 3000;

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

