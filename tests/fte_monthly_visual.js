'use strict';

const { chromium } = require('playwright');
const fs = require('fs');

const sessionId = String(process.env.FTE_QA_SESSION_ID || '').trim();
const baseUrl = String(process.env.FTE_QA_BASE_URL || 'http://localhost/portalgp').replace(/\/$/, '');
const browserPath = [
  String(process.env.FTE_QA_BROWSER_PATH || '').trim(),
  'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
  'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
].find((candidate) => candidate && fs.existsSync(candidate));

if (!sessionId) {
  throw new Error('Define FTE_QA_SESSION_ID con una sesion local habilitada.');
}

(async () => {
  const browser = await chromium.launch({
    headless: true,
    ...(browserPath ? { executablePath: browserPath } : {}),
  });
  const context = await browser.newContext({
    locale: 'es-CL',
    viewport: { width: 1366, height: 900 },
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
  const pageErrors = [];
  page.on('pageerror', (error) => pageErrors.push(error.message));

  const response = await page.goto(`${baseUrl}/rrhh/fte/fte_mensual.php`, {
    waitUntil: 'domcontentloaded',
    timeout: 30000,
  });
  if (!response || response.status() >= 400 || page.url().includes('/login.php')) {
    throw new Error(`No fue posible abrir la vista mensual autenticada (HTTP ${response?.status() || 0}).`);
  }

  await page.locator('#period').fill('2026-08');
  await page.locator('#includeAttendance').check();
  const startedAt = Date.now();
  await page.locator('#monthlyForm button[type="submit"]').click();
  await page.waitForFunction(() => {
    const results = document.getElementById('results');
    const error = document.getElementById('errorBox');
    return (results && !results.classList.contains('d-none'))
      || (error && !error.classList.contains('d-none'));
  }, null, { timeout: 600000 });
  const elapsedSeconds = Number(((Date.now() - startedAt) / 1000).toFixed(3));

  const errorText = await page.locator('#errorBox').evaluate((element) => (
    element.classList.contains('d-none') ? '' : element.textContent.trim()
  ));
  if (errorText) {
    throw new Error(errorText);
  }

  const viewportResults = [];
  for (const width of [1920, 1366, 1024]) {
    await page.setViewportSize({ width, height: 900 });
    await page.waitForTimeout(100);
    viewportResults.push(await page.evaluate((viewportWidth) => {
      const results = document.getElementById('results');
      const wrappers = Array.from(results.querySelectorAll('.table-responsive'));
      return {
        width: viewportWidth,
        document_overflow_px: Math.max(0, document.documentElement.scrollWidth - window.innerWidth),
        table_overflow_px: wrappers.map((wrapper) => Math.max(0, wrapper.scrollWidth - wrapper.clientWidth)),
      };
    }, width));
  }

  const summary = await page.evaluate(() => ({
    kpis: Array.from(document.querySelectorAll('[data-kpi]')).map((element) => element.textContent.trim()),
    cost_center_rows: document.querySelectorAll('#monthlyBody tr').length,
    coverage_rows: document.querySelectorAll('#coverageBody tr').length,
    ranking_cards: document.querySelectorAll('#rankings .ranking-card').length,
    quality_cards: document.querySelectorAll('[data-quality]').length,
    quality_status: document.getElementById('qualityStatus')?.textContent.trim() || '',
    result_status: document.getElementById('resultStatusBadge')?.textContent.trim() || '',
    warning_count: document.querySelectorAll('#warnings .alert').length,
    result_visible: !document.getElementById('results').classList.contains('d-none'),
  }));

  const failures = [];
  if (!summary.result_visible) failures.push('el resultado mensual no quedo visible');
  if (summary.kpis.length !== 8 || summary.kpis.some((value) => value === '' || value === '-')) failures.push('los KPI no se renderizaron completamente');
  if (summary.cost_center_rows < 1) failures.push('no se renderizaron filas por CECO');
  if (summary.coverage_rows < 6) failures.push('la cobertura de fuentes esta incompleta');
  if (summary.ranking_cards !== 5) failures.push('los rankings mensuales estan incompletos');
  if (summary.quality_cards !== 5 || !summary.quality_status) failures.push('la calidad de informacion no se renderizo completamente');
  if (!summary.result_status.toLowerCase().includes('preliminar')) failures.push('el resultado no quedo identificado como preliminar');
  if (pageErrors.length) failures.push(`errores JavaScript: ${pageErrors.join('; ')}`);
  for (const viewport of viewportResults) {
    if (viewport.document_overflow_px > 2) failures.push(`${viewport.width}px presenta desborde horizontal del documento`);
    if (viewport.table_overflow_px.some((overflow) => overflow > 2)) failures.push(`${viewport.width}px presenta una tabla mensual con scroll horizontal`);
  }

  await browser.close();
  process.stdout.write(`${JSON.stringify({
    scope: 'fte_monthly_live_visual',
    period: '2026-08',
    elapsed_seconds: elapsedSeconds,
    summary,
    viewports: viewportResults,
    failures,
  }, null, 2)}\n`);
  process.exit(failures.length ? 2 : 0);
})().catch((error) => {
  process.stderr.write(`FTE monthly visual failed: ${error.message}\n`);
  process.exit(1);
});
