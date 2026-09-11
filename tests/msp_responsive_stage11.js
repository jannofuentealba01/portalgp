'use strict';

const { chromium } = require('playwright');
const fs = require('fs');

const sessionId = String(process.env.MSP_QA_SESSION_ID || '').trim();
const baseUrl = String(process.env.MSP_QA_BASE_URL || 'http://localhost/portalgp').replace(/\/$/, '');
const browserPath = [
    String(process.env.MSP_QA_BROWSER_PATH || '').trim(),
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
].find((candidate) => candidate !== '' && fs.existsSync(candidate));
const screenshotDir = String(process.env.MSP_QA_SCREENSHOT_DIR || '').trim();
if (screenshotDir) fs.mkdirSync(screenshotDir, {recursive: true});

if (!sessionId) {
    throw new Error('Define MSP_QA_SESSION_ID con una sesión local habilitada antes de ejecutar esta prueba.');
}

const routes = [
    '/msp/dashboard/index.php',
    '/msp/contratos/index.php',
    '/msp/arrendatarios/index.php',
    '/msp/tiendas/index.php',
    '/msp/locales/index.php',
    '/msp/cierre/index.php',
    '/msp/cobranza/gestionar.php?id_contrato=7',
    '/msp/pagos/index.php',
    '/msp/documentos_cobro/index.php',
    '/msp/garantias/index.php?q=comercial',
    '/msp/garantias/recepciones.php',
    '/msp/reportes/trazabilidad.php',
    '/msp/cobros/operacion_mensual.php',
    '/msp/control_diario/index.php',
    '/msp/contabilidad/aging.php',
];

const viewportChecks = [1920, 1440, 1366, 1024, 768, 390].map((width) => ({
    label: `${width}px`,
    width,
    height: width <= 768 ? 900 : 1000,
    routes,
}));

const zoomRoutes = [
    '/msp/dashboard/index.php',
    '/msp/contratos/index.php',
    '/msp/cierre/index.php',
    '/msp/pagos/index.php',
    '/msp/garantias/index.php?q=comercial',
    '/msp/reportes/trazabilidad.php',
];

const zoomChecks = [1.25, 1.5].map((zoom) => ({
    label: `${Math.round(zoom * 100)}%`,
    width: Math.floor(1440 / zoom),
    height: Math.floor(1000 / zoom),
    routes: zoomRoutes,
}));
const scenarios = process.env.MSP_QA_FAST === '1'
    ? [
        {label: '768px', width: 768, height: 900, routes: ['/msp/cierre/index.php', '/msp/pagos/index.php', '/msp/reportes/trazabilidad.php', '/msp/control_diario/index.php']},
        {label: '390px', width: 390, height: 900, routes: ['/msp/locales/index.php', '/msp/control_diario/index.php']},
    ]
    : [...viewportChecks, ...zoomChecks];

(async () => {
const browser = await chromium.launch({
    headless: true,
    ...(browserPath ? {executablePath: browserPath} : {}),
});
const context = await browser.newContext({
    locale: 'es-CL',
    ignoreHTTPSErrors: true,
});
await context.addCookies([{
    name: 'PHPSESSID',
    value: sessionId,
    domain: 'localhost',
    path: '/',
    httpOnly: true,
    sameSite: 'Lax',
}]);

const page = await context.newPage();
const failures = [];
let checks = 0;

page.on('pageerror', (error) => {
    failures.push(`JavaScript: ${error.message}`);
});

for (const scenario of scenarios) {
    await page.setViewportSize({width: scenario.width, height: scenario.height});

    for (const route of scenario.routes) {
        checks += 1;
        const response = await page.goto(`${baseUrl}${route}`, {
            waitUntil: 'domcontentloaded',
            timeout: 30000,
        });
        await page.waitForTimeout(180);
        const prefix = `${scenario.label} ${route}`;

        const result = await page.evaluate(() => {
            const visible = (element) => {
                if (!(element instanceof HTMLElement)) return false;
                const style = getComputedStyle(element);
                const rect = element.getBoundingClientRect();
                return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
            };
            const documentOverflow = document.documentElement.scrollWidth - window.innerWidth;
            const main = document.querySelector('main');
            const mainOverflow = main ? main.scrollWidth - main.clientWidth : 0;
            const overflowSources = main && mainOverflow > 2
                ? Array.from(main.querySelectorAll('*')).map((element) => {
                    const rect = element.getBoundingClientRect();
                    return {
                        tag: element.tagName.toLowerCase(),
                        classes: String(element.className || '').trim().replace(/\s+/g, '.'),
                        right: Math.round(rect.right - main.getBoundingClientRect().right),
                        scroll: Math.round(element.scrollWidth - element.clientWidth),
                    };
                }).filter((item) => item.right > 2 || item.scroll > 2)
                    .sort((left, right) => Math.max(right.right, right.scroll) - Math.max(left.right, left.scroll))
                    .slice(0, 4)
                : [];
            const heading = Array.from(document.querySelectorAll('main h1')).find(visible) || null;
            const tables = Array.from(document.querySelectorAll('main table')).filter(visible);
            const ordinaryTables = tables.filter((table) => !table.classList.contains('gp-table-matrix'));
            const matrixTables = tables.filter((table) => table.classList.contains('gp-table-matrix'));
            const unlabeledMobileCells = window.innerWidth <= 767
                ? ordinaryTables.flatMap((table) => Array.from(table.querySelectorAll('tbody > tr:not(.gp-table-empty-row) > td')))
                    .filter((cell) => visible(cell) && !String(cell.dataset.gpLabel || '').trim()).length
                : 0;
            const unpreparedTables = ordinaryTables.filter((table) => table.dataset.gpTableReady !== 'true').length;
            const tabletTablesWithoutDetail = window.innerWidth >= 768 && window.innerWidth <= 1024
                ? ordinaryTables.filter((table) => {
                    const secondary = String(table.dataset.gpTabletSecondary || '').trim();
                    if (!secondary) return false;
                    const dataRows = Array.from(table.querySelectorAll('tbody > tr:not(.gp-table-empty-row)'));
                    return dataRows.length > 0 && !table.querySelector('.gp-tablet-details');
                }).length
                : 0;
            const matrixWithoutRegion = matrixTables.filter((table) => {
                const wrapper = table.closest('.gp-table-matrix-wrap');
                return !wrapper || wrapper.getAttribute('role') !== 'region' || wrapper.tabIndex !== 0;
            }).length;

            return {
                url: location.pathname + location.search,
                loginVisible: Boolean(document.querySelector('form[action*="login"]')),
                documentOverflow,
                mainOverflow,
                overflowSources,
                headingVisible: Boolean(heading),
                unpreparedTables,
                unlabeledMobileCells,
                tabletTablesWithoutDetail,
                matrixWithoutRegion,
            };
        });

        if (scenario.width >= 768 && scenario.width <= 1024) {
            const tabletToggle = page.locator('details.gp-tablet-details > summary:visible').first();
            if (await tabletToggle.count()) {
                await tabletToggle.click();
                const tabletDetail = await page.locator('details.gp-tablet-details[open] .gp-tablet-details__list:visible').first().evaluate((list) => {
                    const rect = list.getBoundingClientRect();
                    return {
                        visible: rect.width > 0 && rect.height > 0,
                        withinViewport: rect.left >= -2 && rect.right <= window.innerWidth + 2,
                        bounds: {left: Math.round(rect.left), right: Math.round(rect.right), viewport: window.innerWidth},
                    };
                });
                if (!tabletDetail.visible || !tabletDetail.withinViewport) {
                    failures.push(`${prefix}: Ver detalle no se despliega dentro del viewport (${JSON.stringify(tabletDetail.bounds)})`);
                }
                await tabletToggle.click();
            }
        }

        if (screenshotDir) {
            const safeRoute = route.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '');
            await page.screenshot({
                path: `${screenshotDir}/${scenario.label.replace('%', 'pct')}-${safeRoute}.png`,
                fullPage: process.env.MSP_QA_FULL_PAGE === '1',
            });
        }

        if (!response || response.status() >= 400) failures.push(`${prefix}: HTTP ${response?.status() || 'sin respuesta'}`);
        if (result.loginVisible) failures.push(`${prefix}: la sesión no fue aceptada`);
        if (!result.headingVisible) failures.push(`${prefix}: no hay título principal visible`);
        if (result.documentOverflow > 2) failures.push(`${prefix}: documento desborda ${result.documentOverflow}px`);
        if (result.mainOverflow > 2) failures.push(`${prefix}: contenido principal desborda ${result.mainOverflow}px (${JSON.stringify(result.overflowSources)})`);
        if (result.unpreparedTables > 0) failures.push(`${prefix}: ${result.unpreparedTables} tabla(s) sin adaptar`);
        if (result.unlabeledMobileCells > 0) failures.push(`${prefix}: ${result.unlabeledMobileCells} celda(s) móviles sin etiqueta`);
        if (result.tabletTablesWithoutDetail > 0) failures.push(`${prefix}: ${result.tabletTablesWithoutDetail} tabla(s) densas sin Ver detalle`);
        if (result.matrixWithoutRegion > 0) failures.push(`${prefix}: ${result.matrixWithoutRegion} matriz(ces) sin navegación accesible`);
    }
}

await browser.close();

if (failures.length > 0) {
    console.error(`FAIL: ${failures.length} hallazgo(s) en ${checks} comprobaciones de página.`);
    failures.forEach((failure) => console.error(`- ${failure}`));
    process.exit(1);
}

console.log(`PASS: ${checks} comprobaciones de página (${[...new Set(scenarios.map(({label}) => label))].join(', ')}).`);
})().catch((error) => {
    console.error(`FAIL: ${error instanceof Error ? error.message : String(error)}`);
    process.exit(1);
});
