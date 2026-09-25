<?php
require __DIR__ . '/../../db.php';
require __DIR__ . '/../../permisos.php';
require __DIR__ . '/fte_lib.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$config = fte_load_config();
if (!isset($_SESSION['usuario'])) {
    header('Location: /portalgp/login.php');
    exit;
}

$permisoRequerido = (string)($config['permission'] ?? 'Ver Dashboard FTE');
if (!function_exists('tienePermiso') || !tienePermiso($_SESSION['usuario']['id'], $permisoRequerido)) {
    header('Location: /portalgp/index.php');
    exit;
}

include __DIR__ . '/../../templates/header.php';
[$defaultFrom, $defaultTo] = fte_default_range();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Grafico asistencia diaria FTE</title>
  <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-5.3.3/css/bootstrap.min.css">
  <link rel="stylesheet" href="../../styles.css">
  <style<?= function_exists('pgpCspNonceAttribute') ? pgpCspNonceAttribute() : '' ?>>
    body.ph-portal.no-sidebar .content-wrapper { padding-top: 0.35rem !important; }
    .portal-shell { padding-top: 0 !important; overflow: visible !important; }
    .fte-content-main { min-width: 0; display: flex; flex-direction: column; gap: 14px; }
    .fte-content-main > .menu-hero { margin-bottom: 0; padding: 14px 18px 10px; }
    .menu-hero { display: block; text-align: center; position: relative; padding: 2.2rem 2.4rem 2.8rem; margin-bottom: 1.2rem; }
    .menu-hero .hero-title { font-size: 1.6rem; }
    .menu-hero .hero-lead { font-size: 0.9rem; margin-top: 0.25rem; }
    .menu-hero .hero-center { position: static; transform: none; width: auto; margin: 0 auto;}
    .menu-hero .back-row { position: absolute; top: 0.35rem; left: 1rem; margin: 0; z-index: 2; }
    .hero-actions-row .back-link {
      display: inline-flex !important; align-items: center !important; text-decoration: none !important;
      padding: 0.5rem 0.9rem !important; font-weight: 700 !important; font-size: 0.92rem !important;
      color: #fff !important; border-radius: 999px !important; border: 1px solid rgba(255, 255, 255, 0.45) !important;
      background-color: rgba(255, 255, 255, 0.16) !important;
    }
    .fte-tabs { display: flex; justify-content: center; gap: 0.65rem; margin: 0 0 0.9rem; }
    .fte-tab {
      border: 1px solid rgba(186, 203, 222, 0.82);
      background: rgba(241, 246, 252, 0.92);
      color: #536a84;
      text-decoration: none;
      border-radius: 999px;
      padding: 10px 18px;
      font-weight: 700;
      font-size: 13.5px;
      line-height: 1.1;
      position: relative;
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.62);
      backdrop-filter: blur(10px);
      transition: all 0.22s ease;
    }
    .fte-tab.is-active {
      background: linear-gradient(135deg, rgba(13,27,42,0.08) 0%, rgba(43,154,243,0.16) 62%, rgba(34,201,142,0.07) 100%);
      color: #0d5ea8;
      border-color: rgba(86,117,153,0.22);
      box-shadow: 0 8px 18px rgba(43,154,243,0.12), inset 0 1px 0 rgba(255,255,255,0.62);
    }
    .fte-tab.is-active::after {
      content: '';
      position: absolute;
      bottom: 5px;
      left: 50%;
      transform: translateX(-50%);
      width: 24px;
      height: 3px;
      background: #2b9af3;
      border-radius: 2px;
    }
    .fte-tab:hover {
      color: #0d1b2a;
      background: rgba(246,249,253,0.98);
      border-color: rgba(120,145,173,0.28);
      transform: translateY(-1px);
    }
    .fte-toolbar { background: #f6f9ff; border: 1px solid rgba(15, 76, 129, 0.14); border-radius: 12px; padding: 1rem; }
    .fte-panel { border: 1px solid rgba(15, 76, 129, 0.16); border-radius: 12px; background: #fff; box-shadow: 0 8px 22px rgba(8, 33, 71, 0.08); }
    .ceco-collapse-toggle {
      width: 100%; border: 1px solid #0b3f75; background: #0f4c81; border-radius: 10px;
      padding: 0.75rem 0.9rem; display: flex; justify-content: space-between; align-items: center;
      font-weight: 800; color: #fff; box-shadow: 0 6px 14px rgba(15, 76, 129, 0.18);
    }
    .ceco-collapse-toggle:hover { background: #0b3f75; }
    .ceco-collapse-toggle .toggle-meta { color: rgba(255, 255, 255, 0.82); font-size: 0.86rem; font-weight: 700; }
    .ceco-collapse-toggle .toggle-icon { transition: transform 0.18s ease; }
    .ceco-collapse-toggle[aria-expanded="true"] .toggle-icon { transform: rotate(180deg); }
    .ceco-list { max-height: 260px; overflow: auto; border: 1px solid #dee2e6; border-radius: 0.5rem; padding: 0.6rem 0.8rem; background: #fff; }
    .ceco-option { display: flex; gap: 0.5rem; align-items: flex-start; padding: 0.25rem 0; }
    .chart-wrap { min-height: 430px; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .chart-wrap canvas { min-width: 760px; }
    .warning-chip { border: 1px solid #f5c16c; background: #fff7e6; color: #7a4b00; border-radius: 999px; padding: 0.35rem 0.7rem; font-size: 0.85rem; }
    .fte-summary-table thead th {
      background: #0f4c81 !important;
      color: #fff !important;
      border-color: #0b3f75 !important;
      font-size: 0.78rem;
      text-transform: uppercase;
    }
    .ceco-cell { line-height: 1.2; }
    .ceco-code { display: block; color: #64748b; font-size: 0.82rem; font-weight: 800; }
    .ceco-desc { display: block; color: #0f172a; font-weight: 800; }
    .fte-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    @media (max-width: 900px) {
      .fte-content-main .menu-hero .back-row {
        position: static;
        margin: 0 0 10px;
        display: flex;
        justify-content: center;
      }
      .menu-hero .hero-center {
        position: static;
        transform: none;
        width: auto;
      }
      .fte-tabs { flex-wrap: wrap; }
      .chart-wrap { min-height: 390px; }
      .chart-wrap canvas { min-width: 900px; }
      .fte-table-card table,
      .fte-table-card thead,
      .fte-table-card tbody,
      .fte-table-card th,
      .fte-table-card td,
      .fte-table-card tr {
        display: block;
        width: 100%;
      }
      .fte-table-card thead { display: none; }
      .fte-table-card tbody tr {
        border: 1px solid #d7e3f0;
        border-radius: 10px;
        background: #fff;
        box-shadow: 0 6px 16px rgba(8, 33, 71, 0.08);
        margin-bottom: 10px;
        overflow: hidden;
      }
      .fte-table-card tbody td {
        display: grid;
        grid-template-columns: minmax(116px, 36%) 1fr;
        gap: 10px;
        align-items: center;
        border: 0 !important;
        border-bottom: 1px solid #edf2f7 !important;
        padding: 8px 10px !important;
        text-align: left !important;
      }
      .fte-table-card tbody td:last-child { border-bottom: 0 !important; }
      .fte-table-card tbody td::before {
        content: attr(data-label);
        color: #64748b;
        font-size: 10px;
        font-weight: 900;
        letter-spacing: 0.08em;
        text-transform: uppercase;
      }
      .fte-table-card tbody td.table-empty {
        display: block;
        text-align: center !important;
      }
      .fte-table-card tbody td.table-empty::before { display: none; content: ''; }
      .fte-table-card .ceco-cell {
        display: block;
        text-align: center;
      }
    }
    @media (max-width: 768px) {
      .menu-hero { padding: 2.4rem 0.9rem 2.7rem; }
      .menu-hero .hero-title { font-size: 1.15rem; }
      .menu-hero .hero-lead { font-size: 0.8rem; padding: 0 0.5rem; }
      .chart-wrap canvas { min-width: 640px; }
    }
    @media (max-width: 560px) {
      .fte-panel { border-radius: 10px; }
      .fte-panel.p-3 { padding: 0.75rem !important; }
      .chart-wrap { min-height: 360px; }
      .chart-wrap canvas { min-width: 760px; }
    }
  </style>
</head>
<body class="ph-portal no-sidebar">
<main class="content-wrapper">
  <div class="portal-shell">
    <section class="form-card fte-content-main">
      <section class="hero-strip menu-hero hero-actions-row">
        <div class="back-row">
          <a href="/portalgp/index.php" class="back-link">&larr; Volver al menu</a>
        </div>
        <div class="hero-center">
          <h1 class="hero-title">Grafico asistencia diaria</h1>
          <p class="hero-lead">Visualiza asistencia por dia y centro de costo solo cuando entras a esta pestaña.</p>
        </div>
      </section>

      <div class="fte-tabs">
        <a class="fte-tab" href="fte_mensual.php">Vista mensual</a>
        <a class="fte-tab" href="fte_dashboard.php">Resumen y detalle</a>
        <a class="fte-tab is-active" href="fte_grafico_asistencia.php">Grafico asistencia diaria</a>
        <a class="fte-tab" href="fte_ausencias.php">Ausencias</a>
      </div>

      <div id="warnings" class="d-flex flex-wrap gap-2 mb-3"></div>
      <div id="loading" class="alert alert-info d-none">Consultando asistencia para el grafico.</div>
      <div id="errorBox" class="alert alert-danger d-none"></div>

      <div class="fte-panel p-3 mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
          <div>
            <h2 class="h5 mb-1">Asistencia promedio por centro de costo</h2>
            <div class="text-muted small" id="chartSubtitle">Barras: promedio diario de presentes. Linea: dotacion seleccionada.</div>
          </div>
          <span class="text-muted small" id="generatedAt"></span>
        </div>
        <div class="chart-wrap">
          <canvas id="attendanceChart" height="160"></canvas>
        </div>
      </div>

      <div class="fte-panel p-3">
        <h2 class="h5 mb-3">Resumen por centro de costo</h2>
        <div class="table-responsive fte-table-wrap fte-table-card">
          <table class="table table-sm table-bordered align-middle mb-0 fte-summary-table">
            <thead class="table-light">
              <tr>
                <th>Centro de costo</th>
                <th class="text-end">Personas</th>
                <th class="text-end">Asistencias</th>
                <th class="text-end">Prom. presentes/dia</th>
              </tr>
            </thead>
            <tbody id="cecoSummaryBody">
              <tr><td colspan="4" class="text-muted table-empty">Sin datos cargados.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </div>
</main>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
<script<?= function_exists('pgpCspNonceAttribute') ? pgpCspNonceAttribute() : '' ?> src="/portalgp/assets/vendor/chart.js-4.4.3/chart.umd.min.js"></script>
<script<?= function_exists('pgpCspNonceAttribute') ? pgpCspNonceAttribute() : '' ?> src="fte_ui.js"></script>
<script<?= function_exists('pgpCspNonceAttribute') ? pgpCspNonceAttribute() : '' ?>>
(function(){
  const loading = document.getElementById('loading');
  const errorBox = document.getElementById('errorBox');
  const warnings = document.getElementById('warnings');
  const fmtInt = new Intl.NumberFormat('es-CL', { maximumFractionDigits: 0 });
  const fmtDec = new Intl.NumberFormat('es-CL', { maximumFractionDigits: 1 });
  let chart = null;
  const FTE_STATE_KEY = 'patagual.fte.dashboardState.v1';
  const FTE_CACHE_TTL_MS = 15 * 60 * 1000;

  function restoreDashboardPayload(){
    try {
      const cached = JSON.parse(sessionStorage.getItem(`${FTE_STATE_KEY}.payload`) || '{}');
      const savedAt = Date.parse(cached.saved_at || '');
      return cached && cached.data && Number.isFinite(savedAt) && Date.now() - savedAt <= FTE_CACHE_TTL_MS ? cached : null;
    } catch (error) {
      return null;
    }
  }

  function setBusy(value){
    loading.classList.toggle('d-none', !value);
  }

  function showError(message){
    errorBox.textContent = message || 'Ocurrio un error.';
    errorBox.classList.remove('d-none');
  }

  function clearError(){
    errorBox.classList.add('d-none');
    errorBox.textContent = '';
  }

  function renderWarnings(items){
    FteUi.clear(warnings);
    (items || []).forEach((item) => {
      const span = document.createElement('span');
      span.className = 'warning-chip';
      span.textContent = item;
      warnings.appendChild(span);
    });
  }

  function cecoLabel(item){
    const code = item.cost_center_code || 'SIN CECO';
    return item.cost_center_name && item.cost_center_name !== code
      ? `${item.cost_center_name} (${code})`
      : code;
  }

  function dayCount(data){
    if (Array.isArray(data.cost_center_days) && data.cost_center_days.length) {
      return Array.from(new Set(data.cost_center_days.map((row) => row.date).filter(Boolean))).length;
    }
    if (data.from_date && data.to_date) {
      const from = new Date(`${data.from_date}T00:00:00`);
      const to = new Date(`${data.to_date}T00:00:00`);
      if (!Number.isNaN(from.getTime()) && !Number.isNaN(to.getTime()) && to >= from) {
        return Math.floor((to - from) / 86400000) + 1;
      }
    }
    return 0;
  }

  function renderCecoSummary(data){
    const body = document.getElementById('cecoSummaryBody');
    const days = dayCount(data);
    const rows = [...(data.summaries || [])].sort((a, b) => String(a.cost_center_code).localeCompare(String(b.cost_center_code)));
    FteUi.clear(body);
    if (!rows.length) {
      FteUi.emptyRow(body, 4, 'Sin datos cargados.');
      return;
    }
    rows.forEach((row) => {
      const tr = document.createElement('tr');
      const avg = days ? Number(row.present_days || 0) / days : 0;
      FteUi.cecoCell(tr, row, 'Centro de costo', 'graph');
      FteUi.textCell(tr, fmtInt.format(row.people_count || 0), 'Personas', 'text-end');
      FteUi.textCell(tr, fmtInt.format(row.present_days || 0), 'Asistencias', 'text-end');
      FteUi.textCell(tr, fmtDec.format(avg), 'Prom. presentes/dia', 'text-end');
      body.appendChild(tr);
    });
  }

  function renderChart(data){
    const colors = [
      '#2563eb', '#16a34a', '#f97316', '#7c3aed', '#dc2626',
      '#0891b2', '#ca8a04', '#db2777', '#0f766e', '#4f46e5',
      '#65a30d', '#ea580c', '#9333ea', '#0284c7', '#be123c',
      '#475569', '#14b8a6', '#a16207', '#c026d3', '#1d4ed8',
      '#15803d', '#b45309', '#6d28d9', '#e11d48', '#0369a1'
    ];
    const days = dayCount(data) || 1;
    function fullCecoLabel(row){
      const desc = row.name && row.name !== row.code ? row.name : '';
      return desc ? `${row.code} - ${desc}` : row.code;
    }
    const cecoRows = [...(data.summaries || [])]
      .map((row) => ({
        code: row.cost_center_code || 'SIN CECO',
        name: row.cost_center_name || row.cost_center_code || 'SIN CECO',
        people: Number(row.people_count || 0),
        avgPresent: Number(row.present_days || 0) / days,
      }))
      .sort((a, b) => b.avgPresent - a.avgPresent || String(a.code).localeCompare(String(b.code)));
    const labels = cecoRows.map((row) => fullCecoLabel(row));
    const avgPresentValues = cecoRows.map((row) => row.avgPresent);
    const peopleValues = cecoRows.map((row) => row.people);
    renderCecoSummary(data);
    document.getElementById('chartSubtitle').textContent = `Promedio de personas presentes por dia versus dotacion del CECO. Rango ${data.from_date || '-'} al ${data.to_date || '-'}.`;
    if (chart) chart.destroy();
    const canvas = document.getElementById('attendanceChart');
    const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 1200;
    const baseWidth = viewportWidth <= 560 ? 760 : (viewportWidth <= 900 ? 900 : 1120);
    canvas.style.width = `${Math.max(baseWidth, cecoRows.length * 72)}px`;
    canvas.style.height = viewportWidth <= 560 ? '440px' : '560px';
    chart = new Chart(canvas, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          {
            type: 'bar',
            label: 'Promedio presentes/dia',
            data: avgPresentValues,
            backgroundColor: cecoRows.map((row, index) => colors[index % colors.length]),
            borderColor: cecoRows.map((row, index) => colors[index % colors.length]),
            borderWidth: 1,
            borderRadius: 5,
            maxBarThickness: 34,
          },
          {
            type: 'line',
            label: 'Dotacion CECO',
            data: peopleValues,
            borderColor: '#334155',
            backgroundColor: '#334155',
            borderWidth: 2,
            tension: 0.18,
            pointRadius: 3,
            pointHoverRadius: 5,
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          x: {
            ticks: {
              autoSkip: false,
              maxRotation: 45,
              minRotation: 45,
              font: { size: 11, weight: '700' }
            }
          },
          y: {
            beginAtZero: true,
            title: { display: true, text: 'Cantidad de personas' },
            ticks: { precision: 0 }
          }
        },
        plugins: {
          legend: { position: 'bottom' },
          tooltip: {
            callbacks: {
              title: (items) => {
                if (!items.length) return '';
                const row = cecoRows[items[0].dataIndex];
                return row ? `${row.code} - ${row.name}` : items[0].label;
              },
              label: (item) => `${item.dataset.label}: ${fmtDec.format(item.parsed.y || 0)}`
            }
          }
        }
      }
    });
  }

  async function loadChart(){
    clearError();
    const cached = restoreDashboardPayload();
    if (cached && cached.data) {
      renderWarnings(cached.data.warnings);
      renderChart(cached.data);
      document.getElementById('generatedAt').textContent = `Datos cargados en dashboard ${new Date(cached.saved_at || cached.data.generated_at).toLocaleString('es-CL')}`;
      return;
    }
    showError('No hay datos precargados. Vuelve a Resumen y detalle, carga la informacion y abre nuevamente el grafico.');
  }

  loadChart();
})();
</script>
</body>
</html>
