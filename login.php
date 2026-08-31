<?php $loginError = isset($_GET['login_error']) ? trim((string)$_GET['login_error']) : ''; ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal GP | Iniciar sesión</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        :root {
            --auth-shadow: none;
            --auth-overlay-start: rgba(8, 40, 75, 0.62);
            --auth-overlay-end: rgba(8, 40, 75, 0.32);
            --auth-card-shadow: none;
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            font-family: "Segoe UI", "Helvetica Neue", Arial, sans-serif;
            background-color: var(--color-bg);
            color: var(--color-text);
        }

        .auth-shell {
            width: min(1080px, 100%);
            min-height: 640px;
            display: grid;
            grid-template-columns: 56% 44%;
            border-radius: 26px;
            overflow: hidden;
            background: var(--color-surface);
            border: 1px solid rgba(215, 222, 232, 0.58);
            box-shadow: var(--auth-shadow);
            animation: reveal 0.45s ease-out;
            position: relative;
        }

        .hero-panel {
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            padding: 22px;
            color: #f8fbff;
            background: transparent;
            overflow: hidden;
            border-top-left-radius: 26px;
            border-bottom-left-radius: 26px;
            border-right: none;
        }

        .hero-media-card {
            position: absolute;
            inset: 10px;
            border-radius: 18px;
            border: none;
            overflow: hidden;
            box-shadow: none;
        }

        .hero-media-card::before {
            content: "";
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(165deg, var(--auth-overlay-start) 5%, var(--auth-overlay-end) 60%),
                url("assets/img_login.webp");
            background-size: cover;
            background-position: center;
            border-radius: inherit;
            transform: translateZ(0);
        }

        .hero-media-card::after {
            content: none;
        }

        .hero-brand {
            position: relative;
            z-index: 3;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 0;
            max-width: 100%;
        }

        .hero-brand-icon-wrap {
            width: 68px;
            height: 68px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
        }

        .hero-brand-icon {
            width: 68px;
            height: 68px;
            object-fit: contain;
            display: block;
            filter: brightness(0) invert(1) drop-shadow(0 2px 6px rgba(0, 0, 0, 0.3));
        }

        .hero-brand-text {
            width: min(220px, 54vw);
            height: auto;
            display: block;
            filter: brightness(0) invert(1) drop-shadow(0 2px 6px rgba(0, 0, 0, 0.3));
        }

        .hero-content h1 {
            margin: 0 0 12px;
            font-size: clamp(1.8rem, 2.8vw, 2.5rem);
            line-height: 1.14;
            font-weight: 600;
            letter-spacing: -0.02em;
            max-width: 12ch;
        }

        .hero-content p {
            margin: 0;
            font-size: 1rem;
            line-height: 1.6;
            max-width: 34ch;
            color: rgba(248, 251, 255, 0.92);
        }

        .hero-metrics {
            display: flex;
            gap: 0;
            flex-wrap: wrap;
        }

        .metric-pill {
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, 0.22);
            background: rgba(8, 40, 75, 0.45);
            padding: 9px 13px;
            font-size: 0.83rem;
            font-weight: 600;
            color: #f4f8ff;
            backdrop-filter: blur(4px);
            white-space: nowrap;
        }

        .form-panel {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 44px 36px;
            background: transparent;
            position: relative;
            z-index: 3;
        }

        .login-form {
            width: min(420px, 100%);
            background: var(--color-surface);
            border-radius: 26px;
            border: 1px solid rgba(215, 222, 232, 0.62);
            box-shadow: var(--auth-card-shadow);
            padding: 34px 30px;
        }

        .form-topline {
            display: inline-block;
            color: var(--color-text-muted);
            font-size: 0.86rem;
            margin-bottom: 8px;
        }

        .form-title {
            margin: 0 0 8px;
            color: var(--color-primary);
            font-size: clamp(1.75rem, 2.8vw, 2rem);
            line-height: 1.2;
            font-weight: 600;
        }

        .form-subtitle {
            margin: 0 0 24px;
            color: var(--color-text-muted);
            font-size: 0.95rem;
        }

        .field-group {
            margin-bottom: 15px;
        }

        .field-label {
            display: block;
            margin-bottom: 7px;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--color-text);
        }

        .field-input,
        .password-wrap input {
            width: 100%;
            border: 1px solid var(--color-border);
            background: var(--color-surface);
            border-radius: 8px;
            padding: 12px 13px;
            font-size: 0.97rem;
            color: var(--color-text);
            transition: border-color 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease;
        }

        .field-input::placeholder,
        .password-wrap input::placeholder {
            color: #7c889b;
        }

        .field-input:focus,
        .password-wrap input:focus {
            outline: none;
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px rgba(11, 58, 110, 0.16);
            background: #ffffff;
        }

        .password-wrap {
            position: relative;
        }

        .toggle-password {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: var(--color-primary);
            font-size: 0.8rem;
            font-weight: 700;
            cursor: pointer;
            padding: 6px 8px;
            border-radius: 8px;
        }

        .toggle-password:focus-visible {
            outline: 2px solid rgba(11, 58, 110, 0.28);
            outline-offset: 2px;
        }

        .form-row {
            margin: 4px 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }

        .remember {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            color: var(--color-text);
        }

        .remember input {
            width: 16px;
            height: 16px;
            accent-color: var(--color-primary);
        }

        .form-link {
            color: var(--color-primary);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .form-link:hover {
            text-decoration: underline;
        }

        .btn-login {
            width: 100%;
            border: 1px solid var(--color-primary);
            border-radius: 8px;
            background: var(--color-primary);
            color: var(--color-text-on-primary);
            font-size: 0.97rem;
            font-weight: 600;
            padding: 12px 14px;
            cursor: pointer;
            transition: background-color 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
            box-shadow: none;
        }

        .btn-login:hover {
            background: var(--color-primary-hover);
            border-color: var(--color-primary-hover);
            box-shadow: none;
        }

        .btn-login:focus-visible {
            outline: 3px solid var(--color-focus);
            outline-offset: 2px;
        }

        .login-alert {
            margin: 0 0 18px;
            padding: 11px 13px;
            border: 1px solid rgba(167, 31, 31, 0.24);
            border-radius: 10px;
            background: #fff1f1;
            color: #8c1d1d;
            font-size: 0.91rem;
        }

        .login-divider {
            margin: 18px 0;
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--color-text-muted);
            font-size: 0.84rem;
        }

        .login-divider::before,
        .login-divider::after {
            content: "";
            height: 1px;
            flex: 1;
            background: var(--color-border);
        }

        .btn-microsoft {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 12px 14px;
            border: 1px solid var(--color-border);
            border-radius: 8px;
            background: #fff;
            color: #1f1f1f;
            text-decoration: none;
            font-size: 0.97rem;
            font-weight: 600;
            transition: background-color 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
        }

        .btn-microsoft:hover {
            background: #f5f5f5;
            border-color: #b8c0cc;
        }

        .ms-mark {
            width: 18px;
            height: 18px;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            grid-template-rows: repeat(2, 1fr);
            gap: 2px;
            flex: 0 0 auto;
        }

        .ms-mark span {
            display: block;
        }

        .ms-red { background: #f25022; }
        .ms-green { background: #7fba00; }
        .ms-blue { background: #00a4ef; }
        .ms-yellow { background: #ffb900; }

        .secondary-actions {
            margin-top: 20px;
            display: flex;
            gap: 0;
        }

        .ghost-btn {
            flex: 1;
            border: 1px solid var(--color-border);
            background: var(--color-surface);
            color: var(--color-text-muted);
            border-radius: 8px;
            padding: 10px 11px;
            font-size: 0.86rem;
            font-weight: 600;
        }

        .ghost-btn:hover {
            background: var(--color-surface-soft);
            color: var(--color-text);
        }

        @media (max-width: 980px) {
            .auth-shell {
                grid-template-columns: 1fr;
                min-height: 0;
            }

            .hero-panel {
                min-height: 300px;
                padding: 24px;
                border-right: none;
                border-bottom: 1px solid rgba(215, 222, 232, 0.72);
            }

            .hero-content h1 {
                max-width: 18ch;
            }

            .form-panel {
                padding: 18px 22px 26px;
            }

            .hero-brand {
                max-width: 100%;
            }
        }

        @media (max-width: 560px) {
            body {
                padding: 12px;
            }

            .hero-media-card {
                inset: 10px;
            }

            .secondary-actions {
                flex-direction: column;
            }

            .metric-pill {
                width: 100%;
                text-align: center;
            }

            .login-form {
                padding: 28px 20px;
                border-radius: 18px;
            }
        }

        @keyframes reveal {
            from {
                opacity: 0;
                transform: translateY(12px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body>
    <main class="auth-shell">
        <section class="hero-panel" aria-hidden="true">
            <div class="hero-media-card"></div>
            <div class="hero-brand">
                <span class="hero-brand-icon-wrap">
                    <img class="hero-brand-icon" src="assets/logo_gp1.png" alt="Logo GP">
                </span>
                <img class="hero-brand-text" src="assets/logo_gp2.png" alt="Grupo Patagual">
            </div>

        </section>

        <section class="form-panel">
            <form class="login-form" action="procesar_login.php" method="post">
                <span class="form-topline">Acceso interno · Portal GP</span>
                <h2 class="form-title">Bienvenido nuevamente</h2>
                <p class="form-subtitle">Ingresa tus credenciales para iniciar sesión.</p>
                <?php if ($loginError !== ''): ?>
                    <div class="login-alert"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <div class="field-group">
                    <label class="field-label" for="username">Nombre de usuario</label>
                    <input
                        class="field-input"
                        type="text"
                        id="username"
                        name="username"
                        autocomplete="username"
                        placeholder="ejemplo.usuario"
                        required
                    >
                </div>

                <div class="field-group">
                    <label class="field-label" for="password">Contraseña</label>
                    <div class="password-wrap">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            autocomplete="current-password"
                            placeholder="••••••••"
                            required
                        >
                        <button class="toggle-password" type="button" aria-label="Mostrar u ocultar contraseña">Mostrar</button>
                    </div>
                </div>

                <!-- <div class="form-row">
                    <label class="remember">
                        <input type="checkbox" name="remember_me" value="1">
                        <span>Recordarme</span>
                    </label>
                    <a class="form-link" href="#" onclick="return false;">¿Olvidaste tu contraseña?</a>
                </div> -->

                <button class="btn-login" type="submit">Iniciar sesión</button>
                <div class="login-divider"><span>o</span></div>
                <a class="btn-microsoft" href="microsoft_login.php">
                    <span class="ms-mark" aria-hidden="true">
                        <span class="ms-red"></span>
                        <span class="ms-green"></span>
                        <span class="ms-blue"></span>
                        <span class="ms-yellow"></span>
                    </span>
                    <span>Iniciar con Microsoft</span>
                </a>

                <!-- <div class="secondary-actions" aria-hidden="true">
                    <button class="ghost-btn" type="button">Google</button>
                    <button class="ghost-btn" type="button">Microsoft</button>
                </div> -->
            </form>
        </section>
    </main>

    <script>
        (function () {
            const toggle = document.querySelector(".toggle-password");
            const passwordInput = document.getElementById("password");

            if (!toggle || !passwordInput) {
                return;
            }

            toggle.addEventListener("click", function () {
                const isPassword = passwordInput.getAttribute("type") === "password";
                passwordInput.setAttribute("type", isPassword ? "text" : "password");
                toggle.textContent = isPassword ? "Ocultar" : "Mostrar";
            });
        })();
    </script>
</body>
</html>
