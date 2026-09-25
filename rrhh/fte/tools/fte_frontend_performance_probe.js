'use strict';

/**
 * Sonda local y sintetica del costo de serializacion/renderizado del dashboard FTE.
 * No consulta Buk ni GeoVictoria y no usa datos personales.
 *
 * Ejecutar con el Node y NODE_PATH provistos por Codex workspace dependencies.
 */

const { performance } = require('node:perf_hooks');
const fs = require('node:fs');
const { chromium } = require('playwright');

const workers = Math.max(1, Number(process.argv[2] || 226));
const days = Math.max(1, Number(process.argv[3] || 31));
const visibleRows = Math.min(1000, workers * days);

function median(values) {
  const sorted = [...values].sort((a, b) => a - b);
  const middle = Math.floor(sorted.length / 2);
  return sorted.length % 2 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2;
}

function buildPayload() {
  const people = [];
  const personDays = [];
  for (let worker = 1; worker <= workers; worker += 1) {
    const alias = `W${String(worker).padStart(3, '0')}`;
    const ceco = `CECO-${String((worker % 20) + 1).padStart(2, '0')}`;
    people.push({
      identifier: alias,
      normalized_identifier: alias,
      person_name: `Trabajador anonimizado ${alias}`,
      cost_center_code: ceco,
      cost_center_name: `Centro de costo ${ceco}`,
      status: 'activo',
      jobs: [],
    });
    for (let day = 1; day <= days; day += 1) {
      personDays.push({
        date: `2026-08-${String(day).padStart(2, '0')}`,
        identifier: alias,
        normalized_identifier: alias,
        person_name: `Trabajador anonimizado ${alias}`,
        cost_center_code: ceco,
        cost_center_name: `Centro de costo ${ceco}`,
        present: true,
        attendance_status: 'TRABAJADO',
        attendance_status_label: 'Trabajado',
        attendance_requires_review: false,
        is_unjustified_absence: false,
        first_entry: '2026-08-04T08:00:00-04:00',
        last_exit: '2026-08-04T17:30:00-04:00',
        first_entry_clock: '08:00',
        last_exit_clock: '17:30',
        net_hours: 8.5,
        extra_hours: 0.5,
        fte_day: 1.062,
        geovictoria_worked_hours: 8.5,
        authorized_overtime_hours: 0.5,
        delay_hours: 0,
        early_leave_hours: 0,
        non_worked_hours: 0,
      });
    }
  }
  return {
    from_date: '2026-08-01',
    to_date: '2026-08-31',
    generated_at: new Date().toISOString(),
    warnings: [],
    summaries: [],
    cost_center_days: [],
    people,
    person_days: personDays,
  };
}

(async () => {
  const payload = buildPayload();
  const stringifySamples = [];
  let payloadText = '';
  for (let i = 0; i < 5; i += 1) {
    const start = performance.now();
    payloadText = JSON.stringify(payload);
    stringifySamples.push(performance.now() - start);
  }

  const browserCandidates = [
    process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH,
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
  ].filter(Boolean);
  const executablePath = browserCandidates.find((candidate) => fs.existsSync(candidate));
  const browser = await chromium.launch({ headless: true, ...(executablePath ? { executablePath } : {}) });
  const page = await browser.newPage();
  await page.goto('http://localhost/portalgp/login.php', { waitUntil: 'domcontentloaded' });
  const samples = [];
  for (let i = 0; i < 5; i += 1) {
    samples.push(await page.evaluate(({ payloadText: text, visibleRows: limit }) => {
      document.body.innerHTML = '<input id="search"><table><tbody id="rows"></tbody></table>';

      let start = performance.now();
      const parsed = JSON.parse(text);
      const parseMs = performance.now() - start;

      let storageMs = null;
      let storageOk = true;
      start = performance.now();
      try {
        sessionStorage.setItem('fte.synthetic.performance.probe', text);
        storageMs = performance.now() - start;
        sessionStorage.removeItem('fte.synthetic.performance.probe');
      } catch (error) {
        storageOk = false;
        storageMs = performance.now() - start;
      }

      start = performance.now();
      const selected = parsed.person_days.filter(() => true).slice(0, limit);
      const filterMs = performance.now() - start;

      const tbody = document.getElementById('rows');
      const fragment = document.createDocumentFragment();
      start = performance.now();
      selected.forEach((row) => {
        const tr = document.createElement('tr');
        [
          row.date,
          row.person_name,
          row.cost_center_code,
          row.attendance_status_label,
          row.first_entry_clock,
          row.last_exit_clock,
          row.net_hours,
          row.extra_hours,
        ].forEach((value) => {
          const td = document.createElement('td');
          td.textContent = String(value ?? '');
          tr.appendChild(td);
        });
        fragment.appendChild(tr);
      });
      tbody.appendChild(fragment);
      const domBuildMs = performance.now() - start;

      start = performance.now();
      void tbody.offsetHeight;
      const forcedLayoutMs = performance.now() - start;

      return { parseMs, storageMs, storageOk, filterMs, domBuildMs, forcedLayoutMs };
    }, { payloadText, visibleRows }));
  }
  await browser.close();

  const metricMedian = (key) => Number(median(samples.map((sample) => sample[key])).toFixed(3));
  process.stdout.write(`${JSON.stringify({
    scope: 'synthetic_local_browser_probe',
    workers,
    days,
    generated_person_day_rows: payload.person_days.length,
    visible_rows_matching_current_dashboard_cap: visibleRows,
    json_bytes: Buffer.byteLength(payloadText, 'utf8'),
    samples: 5,
    medians_ms: {
      node_json_stringify: Number(median(stringifySamples).toFixed(3)),
      browser_json_parse: metricMedian('parseMs'),
      browser_session_storage_write: metricMedian('storageMs'),
      browser_filter_and_slice: metricMedian('filterMs'),
      browser_dom_build_1000_rows: metricMedian('domBuildMs'),
      browser_forced_layout: metricMedian('forcedLayoutMs'),
    },
    session_storage_succeeded_all_samples: samples.every((sample) => sample.storageOk),
    limitation: 'Carga sintetica representativa; no sustituye una medicion del navegador del usuario con el payload productivo.',
  }, null, 2)}\n`);
})().catch((error) => {
  process.stderr.write(`Probe failed: ${error.message}\n`);
  process.exit(1);
});
