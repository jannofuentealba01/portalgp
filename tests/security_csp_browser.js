'use strict';

const {chromium} = require('playwright');
const fs = require('fs');

const sessionId = String(process.env.MSP_QA_SESSION_ID || '').trim();
const baseUrl = String(process.env.MSP_QA_BASE_URL || 'http://localhost/portalgp').replace(/\/$/, '');
const sessionCookieName = String(process.env.MSP_QA_SESSION_COOKIE_NAME || 'PHPSESSID').trim();
const browserPath = [
    String(process.env.MSP_QA_BROWSER_PATH || '').trim(),
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
].find((candidate) => candidate && fs.existsSync(candidate));

if (!sessionId) {
    throw new Error('Define MSP_QA_SESSION_ID con una sesión local habilitada.');
}

const routes = [
    '/profile.php',
    '/sistema/gestion/usuarios.php',
    '/ct/index.php',
    '/ct/solicitudes/index.php',
    '/ct/predial/terrenos/index.php',
    '/rrhh/fte/fte_dashboard.php',
    '/rrhh/fte/fte_mensual.php',
    '/rrhh/fte/fte_grafico_asistencia.php',
    '/rrhh/fte/fte_ausencias.php',
    '/index.php',
    '/msp/msp_menu.php',
    '/msp/dashboard/index.php',
    '/msp/contratos/index.php',
    '/msp/contratos/ficha.php?id_contrato_arriendo=1',
    '/msp/arrendatarios/index.php',
    '/msp/locales/index.php',
    '/msp/garantias/index.php?q=comercial',
    '/msp/garantias/recepciones.php',
    '/msp/garantias/aplicaciones.php',
    '/msp/garantias/devoluciones.php',
    '/msp/pagos/index.php',
    '/msp/pagos/simulacion_masiva.php',
    '/msp/cobranza/registrar_pago.php',
    '/msp/cobranza/registrar_pago_contrato.php',
    '/msp/cobranza/gestionar.php?id_contrato=7',
    '/msp/cobranza/cargos_extra.php',
    '/msp/cobros/operacion_mensual.php',
    '/msp/cobros/operacion_individual.php',
    '/msp/documentos_cobro/index.php',
    '/msp/documentos_tienda/index.php',
    '/msp/tiendas/index.php',
    '/msp/catalogos/bancos.php',
    '/msp/reportes/trazabilidad.php',
    '/msp/reportes/consumo_agua.php',
    '/msp/contabilidad/aging.php',
    '/msp/configuracion/correos.php',
    '/msp/tesoreria/control_diario.php',
    '/msp/tesoreria/conciliacion.php',
    '/msp/control_diario/index.php',
    '/msp/cierre_mensual/index.php',
    '/msp/cierre/index.php',
];

const isCspViolation = (text) => (
    /content security policy|refused to (?:apply|execute|load)|violates the following/i.test(text)
);

(async () => {
    const browser = await chromium.launch({
        headless: true,
        ...(browserPath ? {executablePath: browserPath} : {}),
    });
    const failures = [];
    let checks = 0;

    const anonymousContext = await browser.newContext({locale: 'es-CL', ignoreHTTPSErrors: true});
    const loginPage = await anonymousContext.newPage({viewport: {width: 1366, height: 900}});
    const loginViolations = [];
    loginPage.on('console', (message) => {
        if (isCspViolation(message.text())) loginViolations.push(message.text());
    });
    try {
        const response = await loginPage.goto(`${baseUrl}/login.php`, {
            waitUntil: 'networkidle',
            timeout: 60000,
        });
        checks += 1;
        const headers = response ? await response.allHeaders() : {};
        const policy = String(headers['content-security-policy'] || '');
        const missingNonce = await loginPage.locator('script:not([nonce]), style:not([nonce])').count();
        if (!response || response.status() >= 400 || !policy || policy.includes("'unsafe-inline'") || missingNonce > 0) {
            failures.push('/login.php: CSP estricta o nonce incompleto');
        }
        if (loginViolations.length > 0) failures.push(`/login.php: ${loginViolations[0]}`);
    } catch (error) {
        failures.push(`/login.php: ${error.message}`);
    } finally {
        await loginPage.close();
        await anonymousContext.close();
    }

    const context = await browser.newContext({locale: 'es-CL', ignoreHTTPSErrors: true});
    await context.addCookies([{
        name: sessionCookieName,
        value: sessionId,
        url: new URL(baseUrl).origin,
        httpOnly: true,
        sameSite: 'Lax',
    }]);

    const discoveryPage = await context.newPage();
    try {
        await discoveryPage.goto(`${baseUrl}/ct/solicitudes/index.php`, {
            waitUntil: 'networkidle',
            timeout: 60000,
        });
        const detailHref = await discoveryPage
            .locator('a[href*="solicitudes_ficha"],a[href*="solicitudes/ficha"],a[href*="solicitud_id"],a[href*="id_solicitud"]')
            .first()
            .getAttribute('href')
            .catch(() => null);
        if (detailHref) {
            const detailUrl = new URL(detailHref, discoveryPage.url());
            routes.push(detailUrl.pathname.replace(/^\/portalgp/, '') + detailUrl.search);
        }
    } finally {
        await discoveryPage.close();
    }

    for (const route of routes) {
        const page = await context.newPage({viewport: {width: 1366, height: 900}});
        const violations = [];
        page.on('console', (message) => {
            if (isCspViolation(message.text())) violations.push(message.text());
        });

        try {
            const response = await page.goto(`${baseUrl}${route}`, {
                waitUntil: 'networkidle',
                timeout: 60000,
            });
            checks += 1;
            if (!response || response.status() >= 400) {
                failures.push(`${route}: HTTP ${response?.status() || 'sin respuesta'}`);
                continue;
            }
            const headers = await response.allHeaders();
            const policy = String(headers['content-security-policy'] || '');
            if (!policy || policy.includes("'unsafe-inline'")) {
                failures.push(`${route}: CSP ausente o todavía permisiva`);
            }
            const missingNonce = await page.locator('script:not([nonce]), style:not([nonce])').count();
            if (missingNonce > 0) {
                failures.push(`${route}: ${missingNonce} script/style sin nonce`);
            }

            if (route.includes('/contratos/ficha.php')) {
                const select = page.locator('#lineas_timeline');
                if (await select.count() && await select.locator('option').count() > 1) {
                    await Promise.all([
                        page.waitForNavigation({waitUntil: 'domcontentloaded', timeout: 10000}),
                        select.selectOption({index: 1}),
                    ]);
                }
            }
            if (route.includes('/arrendatarios/index.php')) {
                const modalButton = page.locator('[data-bs-target="#modalCrearArrendatario"]').first();
                if (await modalButton.count()) {
                    await modalButton.click({force: true});
                    await page.waitForTimeout(100);
                }
            }
            if (route.includes('/dashboard/index.php')) {
                const collapseButton = page.locator('[data-bs-target="#detalleMensualDashboard"]').first();
                if (await collapseButton.count()) {
                    await collapseButton.click({force: true});
                    await page.waitForTimeout(100);
                }
            }
            if (route === '/msp/msp_menu.php') {
                const tourButton = page.locator('#mspStartMenuTour');
                if (await tourButton.count()) {
                    await tourButton.click({force: true});
                    await page.waitForTimeout(250);
                    await page.keyboard.press('Escape');
                }
            }
            if (route.includes('/pagos/simulacion_masiva.php')) {
                const tourButton = page.locator('#mspStartPagoMasivoTour');
                if (await tourButton.count()) {
                    await tourButton.click({force: true});
                    await page.waitForTimeout(250);
                    await page.keyboard.press('Escape');
                }
            }
            if (route.includes('/garantias/devoluciones.php')) {
                const confirmationButton = page.locator('button[onclick*="confirm"]').first();
                if (await confirmationButton.count() && await confirmationButton.isEnabled()) {
                    page.once('dialog', (dialog) => dialog.dismiss());
                    await confirmationButton.click();
                    await page.waitForTimeout(100);
                }
            }
            await page.waitForTimeout(350);
            if (violations.length > 0) {
                failures.push(`${route}: ${violations[0]}`);
            }
        } catch (error) {
            failures.push(`${route}: ${error.message}`);
        } finally {
            await page.close();
        }
    }

    await context.close();
    await browser.close();
    if (failures.length > 0) {
        throw new Error(`CSP browser FAIL\n${failures.join('\n')}`);
    }
    console.log(`PASS security_csp_browser (${checks}/${checks} vistas sin violaciones CSP).`);
})().catch((error) => {
    console.error(error.message);
    process.exit(1);
});
