'use strict';

const {chromium} = require('playwright');
const fs = require('fs');
const path = require('path');

const sessionId = String(process.env.MSP_QA_SESSION_ID || '').trim();
const baseUrl = String(process.env.MSP_QA_BASE_URL || 'http://localhost/portalgp').replace(/\/$/, '');
const artifactDir = String(process.env.MSP_QA_ARTIFACT_DIR || '').trim();
const browserPath = [
    String(process.env.MSP_QA_BROWSER_PATH || '').trim(),
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
].find((candidate) => candidate && fs.existsSync(candidate));

if (!sessionId) {
    throw new Error('Define MSP_QA_SESSION_ID con una sesión local habilitada.');
}
if (artifactDir) fs.mkdirSync(artifactDir, {recursive: true});

const routes = [
    {name: 'contrato-multilocal', url: '/msp/contratos/ficha.php?id_contrato_arriendo=1', stress: true},
    {name: 'contratos', url: '/msp/contratos/index.php', stress: true},
    {name: 'arrendatarios', url: '/msp/arrendatarios/index.php', stress: true},
    {name: 'tiendas', url: '/msp/tiendas/index.php', stress: true},
    {name: 'locales', url: '/msp/locales/index.php', stress: true},
    {name: 'garantias-muchos', url: '/msp/garantias/index.php?q=comercial', stress: true, expectManyRows: true},
    {name: 'pagos', url: '/msp/pagos/index.php', stress: true},
    {name: 'documentos', url: '/msp/documentos_cobro/index.php', stress: true},
    {name: 'trazabilidad', url: '/msp/reportes/trazabilidad.php'},
    {name: 'operacion-mensual', url: '/msp/cobros/operacion_mensual.php'},
    {name: 'control-diario', url: '/msp/control_diario/index.php'},
    {name: 'cierre', url: '/msp/cierre/index.php', stress: true},
    {name: 'garantias-vacio', url: '/msp/garantias/index.php?q=__SIN_COINCIDENCIAS_VISUALES_92817__', expectEmpty: true},
];

const viewports = process.env.MSP_QA_FAST === '1'
    ? [{width: 1366, height: 900}, {width: 390, height: 844}]
    : [
        {width: 1920, height: 1000},
        {width: 1366, height: 900},
        {width: 1024, height: 900},
        {width: 768, height: 900},
        {width: 390, height: 844},
    ];

(async () => {
    const browser = await chromium.launch({
        headless: true,
        ...(browserPath ? {executablePath: browserPath} : {}),
    });
    const context = await browser.newContext({locale: 'es-CL', ignoreHTTPSErrors: true});
    await context.addCookies([{
        name: 'PHPSESSID',
        value: sessionId,
        domain: 'localhost',
        path: '/',
        httpOnly: true,
        sameSite: 'Lax',
    }]);

    const failures = [];
    let checks = 0;

    for (const viewport of viewports) {
        const page = await context.newPage({viewport});
        const pageErrors = [];
        page.on('pageerror', (error) => pageErrors.push(error.message));

        for (const route of routes) {
            checks += 1;
            const prefix = `${viewport.width}px ${route.name}`;
            let response;
            try {
                response = await page.goto(`${baseUrl}${route.url}`, {waitUntil: 'domcontentloaded', timeout: 30000});
                await page.waitForTimeout(220);
            } catch (error) {
                failures.push(`${prefix}: no fue posible abrir la vista (${error.message}).`);
                continue;
            }

            if (!response || response.status() >= 400) {
                failures.push(`${prefix}: HTTP ${response?.status() || 'sin respuesta'}.`);
                continue;
            }

            const baseResult = await page.evaluate(({expectEmpty, expectManyRows}) => {
                const isVisible = (element) => {
                    if (!(element instanceof HTMLElement)) return false;
                    const style = getComputedStyle(element);
                    const rect = element.getBoundingClientRect();
                    return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
                };
                const main = document.querySelector('main');
                const ordinaryTables = Array.from(document.querySelectorAll('main table:not(.gp-table-matrix)')).filter(isVisible);
                const matrixTables = Array.from(document.querySelectorAll('main table.gp-table-matrix')).filter(isVisible);
                const wrappersWithUnexpectedScroll = window.innerWidth >= 1200
                    ? ordinaryTables.map((table) => table.closest('.gp-table-shell, .table-responsive'))
                        .filter(Boolean)
                        .filter((wrapper) => wrapper.scrollWidth - wrapper.clientWidth > 2).length
                    : 0;
                const unlabeledCells = window.innerWidth < 768
                    ? ordinaryTables.flatMap((table) => Array.from(table.querySelectorAll('tbody > tr:not(.gp-table-empty-row) > td')))
                        .filter((cell) => isVisible(cell) && !String(cell.dataset.gpLabel || '').trim()).length
                    : 0;
                const rows = ordinaryTables.reduce(
                    (total, table) => total + Array.from(table.tBodies).reduce((sum, body) => sum + body.rows.length, 0),
                    0
                );
                const hasEmptyMessage = /sin\s+(resultados|coincidencias|registros|datos)|no\s+se\s+encontr/i.test(main?.innerText || '');
                const bodyClassReady = document.body.classList.contains('gp-module-msp');
                const styleHrefs = Array.from(document.querySelectorAll('link[rel="stylesheet"]')).map((link) => link.getAttribute('href') || '');

                return {
                    login: Boolean(document.querySelector('form[action*="login"]')),
                    bodyClassReady,
                    hasScreenCss: styleHrefs.some((href) => href.includes('/msp/assets/screen.css')),
                    hasPrintCss: Array.from(document.querySelectorAll('link[rel="stylesheet"][media="print"]'))
                        .some((link) => (link.getAttribute('href') || '').includes('/msp/assets/print.css')),
                    documentOverflow: document.documentElement.scrollWidth - window.innerWidth,
                    mainOverflow: main ? main.scrollWidth - main.clientWidth : 0,
                    wrappersWithUnexpectedScroll,
                    unlabeledCells,
                    matrixWithoutRegion: matrixTables.filter((table) => {
                        const wrapper = table.closest('.gp-table-matrix-wrap');
                        return !wrapper || wrapper.getAttribute('role') !== 'region' || wrapper.tabIndex !== 0;
                    }).length,
                    emptyMismatch: Boolean(expectEmpty) && !hasEmptyMessage && rows > 0,
                    manyRowsMismatch: Boolean(expectManyRows) && rows < 12,
                };
            }, {expectEmpty: route.expectEmpty, expectManyRows: route.expectManyRows});

            if (baseResult.login) failures.push(`${prefix}: la sesión no fue aceptada.`);
            if (!baseResult.bodyClassReady) failures.push(`${prefix}: no se aplicó el ámbito visual MSP.`);
            if (!baseResult.hasScreenCss) failures.push(`${prefix}: falta screen.css.`);
            if (!baseResult.hasPrintCss) failures.push(`${prefix}: falta print.css aislado.`);
            if (baseResult.documentOverflow > 2) failures.push(`${prefix}: la página desborda ${baseResult.documentOverflow}px.`);
            if (baseResult.mainOverflow > 2) failures.push(`${prefix}: el contenido desborda ${baseResult.mainOverflow}px.`);
            if (baseResult.wrappersWithUnexpectedScroll > 0) failures.push(`${prefix}: ${baseResult.wrappersWithUnexpectedScroll} tabla(s) comunes requieren scroll lateral en escritorio.`);
            if (baseResult.unlabeledCells > 0) failures.push(`${prefix}: ${baseResult.unlabeledCells} celda(s) móviles no tienen etiqueta.`);
            if (baseResult.matrixWithoutRegion > 0) failures.push(`${prefix}: ${baseResult.matrixWithoutRegion} matriz(ces) no son regiones navegables.`);
            if (baseResult.emptyMismatch) failures.push(`${prefix}: el estado vacío no se presenta correctamente.`);
            if (baseResult.manyRowsMismatch) failures.push(`${prefix}: no se obtuvo una tabla extensa para la regresión.`);

            if (route.stress) {
                const stressResult = await page.evaluate(() => {
                    const isVisible = (element) => {
                        if (!(element instanceof HTMLElement)) return false;
                        const style = getComputedStyle(element);
                        const rect = element.getBoundingClientRect();
                        return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
                    };
                    const table = Array.from(document.querySelectorAll('main table:not(.gp-table-matrix)')).find(isVisible);
                    if (!table) return {skipped: true};
                    const row = Array.from(table.querySelectorAll('tbody > tr')).find((candidate) =>
                        isVisible(candidate) && candidate.cells.length >= 3 && !candidate.classList.contains('gp-table-empty-row')
                    );
                    if (!row) return {skipped: true};

                    const values = [
                        'COMERCIAL INDUSTRIAL DE SERVICIOS Y DISTRIBUCIÓN PATAGONIA EXTREMADAMENTE LARGA SpA',
                        'A-1 / A-2 / A-3a / B-100 / C-208 / LOCAL COMERCIAL DE DOS NIVELES',
                        'REF-TRANSFERENCIA-2026-OBSERVACION-MUY-EXTENSA-SIN-CORTES-12345678901234567890',
                        '$ 999.999.999.999',
                    ];
                    const cells = Array.from(row.cells).filter((cell) => cell.dataset.gpColumnKind !== 'actions');
                    cells.slice(0, values.length).forEach((cell, index) => {
                        const probe = document.createElement('span');
                        probe.className = 'msp-visual-stress-probe';
                        probe.textContent = values[index];
                        cell.replaceChildren(probe);
                    });

                    const cellOverflow = cells.filter((cell) => cell.scrollWidth - cell.clientWidth > 2).length;
                    const invadingText = Array.from(row.querySelectorAll('.msp-visual-stress-probe')).filter((probe) => {
                        const cell = probe.closest('td, th');
                        if (!cell) return true;
                        const content = probe.getBoundingClientRect();
                        const boundary = cell.getBoundingClientRect();
                        return content.left < boundary.left - 2 || content.right > boundary.right + 2;
                    }).length;
                    const main = document.querySelector('main');
                    return {
                        skipped: false,
                        cellOverflow,
                        invadingText,
                        documentOverflow: document.documentElement.scrollWidth - window.innerWidth,
                        mainOverflow: main ? main.scrollWidth - main.clientWidth : 0,
                    };
                });

                if (!stressResult.skipped) {
                    if (stressResult.cellOverflow > 0) failures.push(`${prefix}: datos extremos desbordan ${stressResult.cellOverflow} celda(s).`);
                    if (stressResult.invadingText > 0) failures.push(`${prefix}: ${stressResult.invadingText} texto(s) invaden otra columna.`);
                    if (stressResult.documentOverflow > 2 || stressResult.mainOverflow > 2) failures.push(`${prefix}: los datos extremos rompen el ancho de la vista.`);
                }
            }

            if (viewport.width === 1366) {
                await page.evaluate(() => {
                    document.body.setAttribute('tabindex', '-1');
                    document.body.focus();
                });
                let focusedMainControl = false;
                for (let index = 0; index < 80; index += 1) {
                    await page.keyboard.press('Tab');
                    const focus = await page.evaluate(() => {
                        const element = document.activeElement;
                        if (!(element instanceof HTMLElement) || !element.closest('main')) return null;
                        const style = getComputedStyle(element);
                        return {
                            tag: element.tagName,
                            outlineWidth: parseFloat(style.outlineWidth) || 0,
                            outlineStyle: style.outlineStyle,
                            boxShadow: style.boxShadow,
                        };
                    });
                    if (!focus) continue;
                    focusedMainControl = true;
                    const hasFocusIndicator = (focus.outlineWidth >= 2 && focus.outlineStyle !== 'none')
                        || (focus.boxShadow && focus.boxShadow !== 'none');
                    if (!hasFocusIndicator) failures.push(`${prefix}: ${focus.tag} no muestra foco visible con teclado.`);
                    break;
                }
                if (!focusedMainControl) failures.push(`${prefix}: no fue posible navegar por teclado hasta el contenido principal.`);

                const details = page.locator('main details').first();
                if (await details.count()) {
                    await details.evaluate((element) => element.setAttribute('open', ''));
                    await page.keyboard.press('Escape');
                    const stayedOpen = await details.evaluate((element) => element.hasAttribute('open'));
                    if (stayedOpen && await details.evaluate((element) => element.matches('.gp-row-actions, .gp-inline-upload'))) {
                        failures.push(`${prefix}: Escape no cerró el menú desplegable.`);
                    }
                }
            }

            if (artifactDir && failures.some((failure) => failure.startsWith(prefix))) {
                await page.screenshot({
                    path: path.join(artifactDir, `${viewport.width}-${route.name}.png`),
                    fullPage: true,
                });
            }
        }

        if (pageErrors.length) failures.push(`${viewport.width}px: errores JavaScript: ${[...new Set(pageErrors)].join(' | ')}`);
        await page.close();
    }

    await browser.close();

    if (failures.length) {
        console.error(`FAIL: ${failures.length} hallazgo(s) en ${checks} escenarios visuales.`);
        failures.forEach((failure) => console.error(`- ${failure}`));
        process.exit(1);
    }

    console.log(`PASS: ${checks} escenarios visuales; contenido extremo, vacío, múltiple, teclado, foco y overflow verificados.`);
})().catch((error) => {
    console.error(`FAIL: ${error instanceof Error ? error.message : String(error)}`);
    process.exit(1);
});
