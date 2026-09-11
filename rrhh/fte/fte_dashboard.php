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
  <title>FTE GeoVictoria + Buk</title>
  <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-5.3.3/css/bootstrap.min.css">
  <link rel="stylesheet" href="../../styles.css">
  <style>
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
    .fte-panel { border: 1px solid rgba(15, 76, 129, 0.16); border-radius: 12px; background: #fff; box-shadow: 0 8px 22px rgba(8, 33, 71, 0.08); }
    .fte-toolbar { background: #f6f9ff; border: 1px solid rgba(15, 76, 129, 0.14); border-radius: 12px; padding: 1rem; }
    .fte-filters-grid {
      display: grid;
      grid-template-columns: repeat(6, minmax(0, 1fr));
      gap: 14px;
      align-items: end;
      width: 100%;
    }
    .fte-filter-item { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
    .fte-filter-item .form-label {
      margin: 0;
      font-size: 10px;
      letter-spacing: 1.1px;
      text-transform: uppercase;
      color: #64748b;
      font-weight: 800;
    }
    .fte-filter-item .form-control,
    .fte-filter-item .btn {
      min-height: 38px;
      font-size: 12px;
      padding: 8px 10px;
    }
    .fte-ceco-filter { grid-column: 1 / -1; }
    .ceco-collapse-toggle {
      width: 100%;
      border: 1px solid #0b3f75;
      background: #0f4c81;
      border-radius: 10px;
      padding: 0.75rem 0.9rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-weight: 800;
      color: #fff;
      box-shadow: 0 6px 14px rgba(15, 76, 129, 0.18);
    }
    .ceco-collapse-toggle:hover { background: #0b3f75; }
    .ceco-collapse-toggle .toggle-meta { color: rgba(255, 255, 255, 0.82); font-size: 0.86rem; font-weight: 700; }
    .ceco-collapse-toggle .toggle-icon { transition: transform 0.18s ease; }
    .ceco-collapse-toggle[aria-expanded="true"] .toggle-icon { transform: rotate(180deg); }
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
    .table-sticky thead th { position: sticky; top: 0; z-index: 1; background: #f8fafc; }
    .person-table thead th {
      background: #0f4c81 !important;
      color: #fff !important;
      border-color: #0b3f75 !important;
      font-size: 0.78rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .summary-row { cursor: pointer; }
    .summary-row:hover td { background: #eef6ff; }
    .summary-row.is-active td { background: #dbeafe; font-weight: 700; }
    .ceco-stack { display: grid; gap: 0.12rem; justify-items: center; line-height: 1.25; }
    .ceco-stack-code { color: #64748b; font-size: 0.82rem; font-weight: 700; }
    .ceco-stack-desc { color: #0f172a; font-weight: 800; }
    .fte-detail-panel { display: flex; flex-direction: column; min-height: 0; }
    .fte-detail-head { flex: 0 0 auto; }
    .person-table { max-height: 520px; overflow: auto; }
    .fte-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .ceco-option { display: flex; gap: 0.5rem; align-items: flex-start; padding: 0.25rem 0; }
    .ceco-list { max-height: 260px; overflow: auto; border: 1px solid #dee2e6; border-radius: 0.5rem; padding: 0.6rem 0.8rem; background: #fff; }
    .warning-chip { border: 1px solid #f5c16c; background: #fff7e6; color: #7a4b00; border-radius: 999px; padding: 0.35rem 0.7rem; font-size: 0.85rem; }
    @media (max-width: 1280px) {
      .fte-filters-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
      .fte-filter-actions { grid-column: span 3; }
    }
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
      .fte-filters-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .fte-filter-actions { grid-column: span 2; }
      .fte-tabs { flex-wrap: wrap; }
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
        grid-template-columns: minmax(96px, 34%) 1fr;
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
      .fte-table-card .ceco-stack {
        justify-items: center;
        text-align: center;
      }
      .person-table { max-height: none; overflow: visible; }
      .table-sticky thead th { position: static; }
    }
    @media (max-width: 768px) {
      .menu-hero { padding: 2.4rem 0.9rem 2.7rem; }
      .menu-hero .hero-title { font-size: 1.15rem; }
      .menu-hero .hero-lead { font-size: 0.8rem; padding: 0 0.5rem; }
    }
    @media (max-width: 560px) {
      .fte-filters-grid { grid-template-columns: 1fr; }
      .fte-filter-actions { grid-column: auto; }
      .fte-toolbar { padding: 0.75rem; }
      .fte-panel { border-radius: 10px; }
      .fte-panel.p-3 { padding: 0.75rem !important; }
      #personSearch { max-width: none !important; width: 100%; }
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
          <h1 class="hero-title">FTE Buk / GeoVictoria</h1>
          <p class="hero-lead">Asistencia, horas netas, horas extra y FTE por centro de costo.</p>
        </div>
      </section>

      <div class="fte-tabs">
        <a class="fte-tab" href="fte_mensual.php">Vista mensual</a>
        <a class="fte-tab is-active" href="fte_dashboard.php">Resumen y detalle</a>
        <a class="fte-tab" id="tabGraficoAsistencia" href="fte_grafico_asistencia.php">Grafico asistencia diaria</a>
        <a class="fte-tab" id="tabAusencias" href="fte_ausencias.php">Ausencias</a>
      </div>

      <div class="fte-toolbar mb-3">
        <form id="fteFilters" class="fte-filters-grid">
          <div class="fte-filter-item">
            <label class="form-label" for="from_date">Desde</label>
            <input class="form-control" type="date" id="from_date" name="from_date" value="<?= fte_h($defaultFrom) ?>">
          </div>
          <div class="fte-filter-item">
            <label class="form-label" for="to_date">Hasta</label>
            <input class="form-control" type="date" id="to_date" name="to_date" value="<?= fte_h($defaultTo) ?>">
          </div>
          <div class="fte-filter-item">
            <label class="form-label" for="daily_hours">Jornada base</label>
            <input class="form-control" type="number" min="1" max="24" step="0.25" id="daily_hours" name="daily_hours" value="8">
          </div>
          <div class="fte-filter-item">
            <label class="form-label" for="start_time">Inicio jornada</label>
            <input class="form-control" type="time" id="start_time" name="start_time" value="08:00">
          </div>
          <div class="fte-filter-item">
            <label class="form-label" for="lunch_minutes">Colacion min.</label>
            <input class="form-control" type="number" min="0" max="240" step="5" id="lunch_minutes" name="lunch_minutes" value="60">
          </div>
          <div class="fte-filter-item fte-filter-actions">
            <button class="btn btn-primary" type="submit" id="btnLoad">Actualizar</button>
          </div>
          <div class="fte-ceco-filter">
            <button
              class="ceco-collapse-toggle"
              type="button"
              data-bs-toggle="collapse"
              data-bs-target="#cecoCollapse"
              aria-expanded="false"
              aria-controls="cecoCollapse"
            >
              <span>Centros de costo Buk</span>
              <span class="d-inline-flex align-items-center gap-2">
                <span class="toggle-meta" id="cecoSelectionMeta">0 seleccionados</span>
                <span class="toggle-icon">⌄</span>
              </span>
            </button>
            <div class="collapse mt-2" id="cecoCollapse">
              <div id="cecoList" class="ceco-list text-muted">Cargando centros de costo...</div>
              <div class="d-flex justify-content-end gap-2 mt-3">
                <button class="btn btn-secondary" type="button" id="btnCancelCecos">Cancelar</button>
                <button class="btn btn-success" type="button" id="btnSaveCecos">Guardar seleccion</button>
              </div>
            </div>
          </div>
        </form>
      </div>

      <div id="warnings" class="d-flex flex-wrap gap-2 mb-3"></div>

      <div id="loading" class="alert alert-info d-none">Consultando datos. GeoVictoria puede tardar si hay muchos trabajadores.</div>
      <div id="errorBox" class="alert alert-danger d-none"></div>

      <div class="row g-3">
        <div class="col-lg-5">
          <div class="fte-panel p-3 h-100" id="summaryPanel">
            <h2 class="h5">Resumen por CECO</h2>
            <div class="table-responsive fte-table-wrap fte-table-card">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th>CECO</th>
                    <th class="text-end">Personas</th>
                    <th class="text-end">Pres.</th>
                    <th class="text-end">Horas</th>
                    <th class="text-end">FTE</th>
                  </tr>
                </thead>
                <tbody id="summaryBody">
                  <tr><td colspan="5" class="text-muted table-empty">Sin datos.</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <div class="col-lg-7">
          <div class="fte-panel p-3 h-100 fte-detail-panel" id="detailPanel">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2 fte-detail-head" id="detailHead">
              <h2 class="h5 mb-0">Detalle persona / dia <span class="text-muted small" id="activeCecoLabel"></span></h2>
              <input class="form-control form-control-sm" id="personSearch" placeholder="Buscar persona, RUT o CECO" style="max-width: 260px;">
            </div>
            <div class="table-responsive person-table table-sticky fte-table-wrap fte-table-card">
              <table class="table table-sm align-middle">
                <thead>
                  <tr>
                    <th>Fecha</th>
                    <th>Persona</th>
                    <th>CECO</th>
                    <th>Estado</th>
                    <th class="text-end">Entrada</th>
                    <th class="text-end">Salida</th>
                    <th class="text-end">Neto</th>
                    <th class="text-end">Extra</th>
                  </tr>
                </thead>
                <tbody id="personBody">
                  <tr><td colspan="8" class="text-muted table-empty">Selecciona al menos un CECO y actualiza.</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>
</main>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
<script src="fte_ui.js"></script>
<script>
(function(){
  const apiUrl = 'fte_api.php';
  const cecoList = document.getElementById('cecoList');
  const cecoCollapse = document.getElementById('cecoCollapse');
  const cecoCollapseToggle = document.querySelector('.ceco-collapse-toggle');
  const form = document.getElementById('fteFilters');
  const loading = document.getElementById('loading');
  const errorBox = document.getElementById('errorBox');
  const warnings = document.getElementById('warnings');
  const personSearch = document.getElementById('personSearch');
  const cecoSelectionMeta = document.getElementById('cecoSelectionMeta');
  let lastPersonRows = [];
  let activeDetailCeco = '';

  const fmtInt = new Intl.NumberFormat('es-CL', { maximumFractionDigits: 0 });
  const fmtDec = new Intl.NumberFormat('es-CL', { maximumFractionDigits: 1 });
  const fmtTwo = new Intl.NumberFormat('es-CL', { maximumFractionDigits: 2 });
  let savedCecoSelection = [];
  const FTE_STATE_KEY = 'patagual.fte.dashboardState.v1';

  function selectedCodes(){
    return Array.from(document.querySelectorAll('input[name="cost_center_code[]"]:checked')).map((input) => input.value);
  }

  function updateCecoMeta(){
    const selected = selectedCodes().length;
    const total = document.querySelectorAll('input[name="cost_center_code[]"]').length;
    const allBox = document.getElementById('cecoSelectAll');
    if (allBox) {
      allBox.checked = total > 0 && selected === total;
      allBox.indeterminate = selected > 0 && selected < total;
    }
    const saved = savedCecoSelection.length;
    cecoSelectionMeta.textContent = cecoCollapse.classList.contains('show')
      ? `${fmtInt.format(selected)} de ${fmtInt.format(total)} seleccionados`
      : `${fmtInt.format(saved)} guardados`;
  }

  function setCecoExpanded(expanded){
    cecoCollapse.classList.toggle('show', expanded);
    cecoCollapseToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    updateCecoMeta();
  }

  function applySavedCecoSelection(){
    const saved = new Set(savedCecoSelection);
    document.querySelectorAll('input[name="cost_center_code[]"]').forEach((input) => {
      input.checked = saved.has(input.value);
    });
    updateCecoMeta();
  }

  function params(action){
    const fd = new FormData(form);
    fd.delete('cost_center_code[]');
    selectedCodes().forEach((code) => fd.append('cost_center_code[]', code));
    fd.set('action', action);
    return new URLSearchParams(fd);
  }

  function saveDashboardState(){
    sessionStorage.setItem(FTE_STATE_KEY, JSON.stringify({
      from_date: document.getElementById('from_date').value,
      to_date: document.getElementById('to_date').value,
      daily_hours: document.getElementById('daily_hours').value,
      start_time: document.getElementById('start_time').value,
      lunch_minutes: document.getElementById('lunch_minutes').value,
      cost_center_codes: savedCecoSelection,
    }));
  }

  function saveDashboardPayload(data){
    sessionStorage.setItem(`${FTE_STATE_KEY}.payload`, JSON.stringify({
      saved_at: new Date().toISOString(),
      data,
    }));
  }

  function restoreDashboardState(){
    try {
      const state = JSON.parse(sessionStorage.getItem(FTE_STATE_KEY) || '{}');
      ['from_date', 'to_date', 'daily_hours', 'start_time', 'lunch_minutes'].forEach((key) => {
        if (state[key] && document.getElementById(key)) document.getElementById(key).value = state[key];
      });
      savedCecoSelection = Array.isArray(state.cost_center_codes) ? state.cost_center_codes : [];
    } catch (error) {
      savedCecoSelection = [];
    }
  }

  function setBusy(value){
    loading.classList.toggle('d-none', !value);
    document.getElementById('btnLoad').disabled = value;
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

  async function loadCostCenters(){
    clearError();
    try {
      const response = await fetch(`${apiUrl}?${params('cost_centers')}`, { credentials: 'same-origin' });
      const payload = await response.json();
      if (!payload.ok) throw new Error(payload.error || 'No se pudieron cargar los CECO.');
      FteUi.clear(cecoList);
      if (!payload.cost_centers.length) {
        cecoList.textContent = 'No se encontraron centros de costo en Buk.';
        return;
      }
      const allLabel = document.createElement('label');
      allLabel.className = 'ceco-option border-bottom pb-2 mb-2';
      const allInput = document.createElement('input');
      allInput.className = 'form-check-input mt-1';
      allInput.type = 'checkbox';
      allInput.id = 'cecoSelectAll';
      const allText = document.createElement('span');
      allText.appendChild(FteUi.element('strong', 'Seleccionar todo'));
      allText.appendChild(document.createElement('br'));
      allText.appendChild(FteUi.element('small', 'Marca o desmarca todos los centros de costo', 'text-muted'));
      allLabel.append(allInput, allText);
      cecoList.appendChild(allLabel);
      document.getElementById('cecoSelectAll').addEventListener('change', function(){
        document.querySelectorAll('input[name="cost_center_code[]"]').forEach((input) => {
          input.checked = this.checked;
        });
        updateCecoMeta();
      });
      payload.cost_centers.forEach((item, index) => {
        const id = `ceco_${index}`;
        const label = document.createElement('label');
        label.className = 'ceco-option';
        const input = document.createElement('input');
        input.className = 'form-check-input mt-1';
        input.type = 'checkbox';
        input.name = 'cost_center_code[]';
        input.id = id;
        input.value = String(item.cost_center_code || '');
        const text = document.createElement('span');
        text.appendChild(FteUi.element('strong', cecoLabel(item)));
        text.appendChild(document.createElement('br'));
        text.appendChild(FteUi.element('small', `${fmtInt.format(item.people_count || 0)} personas`, 'text-muted'));
        label.append(input, text);
        cecoList.appendChild(label);
      });
      cecoList.querySelectorAll('input[name="cost_center_code[]"]').forEach((input) => {
        input.addEventListener('change', updateCecoMeta);
      });
      if (!savedCecoSelection.length) {
        savedCecoSelection = payload.cost_centers.map((item) => item.cost_center_code).filter(Boolean);
        saveDashboardState();
      }
      applySavedCecoSelection();
      updateCecoMeta();
      renderWarnings(payload.warnings);
    } catch (error) {
      cecoList.textContent = 'No se pudieron cargar los centros de costo.';
      showError(error.message);
    }
  }

  function renderSummary(data){
    const body = document.getElementById('summaryBody');
    FteUi.clear(body);
    if (!data.summaries.length) {
      FteUi.emptyRow(body, 5, 'Sin datos para los filtros.');
      return;
    }
    data.summaries.sort((a, b) => String(a.cost_center_code).localeCompare(String(b.cost_center_code)));
    const allTr = document.createElement('tr');
    allTr.className = `summary-row ${activeDetailCeco === '' ? 'is-active' : ''}`;
    allTr.dataset.ceco = '';
    const allCell = FteUi.textCell(allTr, '', 'CECO');
    allCell.appendChild(FteUi.element('strong', 'Todos los centros de costo'));
    FteUi.textCell(allTr, fmtInt.format(data.people.length || 0), 'Personas', 'text-end');
    FteUi.textCell(allTr, fmtInt.format(data.person_days.filter((row) => row.present).length || 0), 'Pres.', 'text-end');
    FteUi.textCell(allTr, fmtDec.format(data.person_days.reduce((sum, row) => sum + Number(row.net_hours || 0), 0)), 'Horas', 'text-end');
    FteUi.textCell(allTr, '-', 'FTE', 'text-end');
    allTr.addEventListener('click', function(){
      activeDetailCeco = '';
      renderSummary({ ...data });
      renderPeople();
    });
    body.appendChild(allTr);
    data.summaries.forEach((row) => {
      const tr = document.createElement('tr');
      tr.className = `summary-row ${activeDetailCeco === row.cost_center_code ? 'is-active' : ''}`;
      tr.dataset.ceco = row.cost_center_code;
      FteUi.cecoCell(tr, row, 'CECO');
      FteUi.textCell(tr, fmtInt.format(row.people_count || 0), 'Personas', 'text-end');
      FteUi.textCell(tr, fmtInt.format(row.present_days || 0), 'Pres.', 'text-end');
      FteUi.textCell(tr, fmtDec.format(row.net_hours || 0), 'Horas', 'text-end');
      FteUi.textCell(tr, fmtTwo.format(row.avg_fte || 0), 'FTE', 'text-end');
      tr.addEventListener('click', function(){
        activeDetailCeco = row.cost_center_code;
        renderSummary({ ...data });
        renderPeople();
      });
      body.appendChild(tr);
    });
  }

  function renderPeople(){
    const body = document.getElementById('personBody');
    const q = (personSearch.value || '').trim().toLowerCase();
    const rows = lastPersonRows.filter((row) => {
      if (activeDetailCeco && row.cost_center_code !== activeDetailCeco) return false;
      if (!q) return true;
      return [row.person_name, row.identifier, row.cost_center_code, row.cost_center_name, row.date, row.attendance_status_label]
        .join(' ')
        .toLowerCase()
        .includes(q);
    }).slice(0, 1000);
    FteUi.clear(body);
    const activeLabel = document.getElementById('activeCecoLabel');
    if (activeLabel) {
      const activeRow = lastPersonRows.find((row) => row.cost_center_code === activeDetailCeco);
      activeLabel.textContent = activeDetailCeco && activeRow
        ? `- ${activeRow.cost_center_code} ${activeRow.cost_center_name || ''}`.trim()
        : '';
    }
    if (!rows.length) {
      FteUi.emptyRow(body, 8, 'Sin registros para mostrar.');
      return;
    }
    rows.forEach((row) => {
      const tr = document.createElement('tr');
      FteUi.textCell(tr, row.date || '', 'Fecha');
      FteUi.personCell(tr, row.person_name || row.identifier || '', row.identifier || '');
      FteUi.cecoCell(tr, row, 'CECO');
      FteUi.textCell(tr, row.attendance_status_label || row.attendance_status || '-', 'Estado');
      FteUi.textCell(tr, row.first_entry_clock || '-', 'Entrada', 'text-end');
      FteUi.textCell(tr, row.last_exit_clock || '-', 'Salida', 'text-end');
      FteUi.textCell(tr, fmtDec.format(row.net_hours || 0), 'Neto', 'text-end');
      FteUi.textCell(tr, fmtDec.format(row.extra_hours || 0), 'Extra', 'text-end');
      body.appendChild(tr);
    });
    syncDetailHeight();
  }

  function syncDetailHeight(){
    const summaryPanel = document.getElementById('summaryPanel');
    const detailPanel = document.getElementById('detailPanel');
    const detailHead = document.getElementById('detailHead');
    const tableWrap = document.querySelector('.person-table');
    if (!summaryPanel || !detailPanel || !detailHead || !tableWrap) return;

    if (window.innerWidth <= 900) {
      tableWrap.style.maxHeight = '';
      tableWrap.style.height = '';
      return;
    }

    const panelStyles = window.getComputedStyle(detailPanel);
    const paddingTop = parseFloat(panelStyles.paddingTop) || 0;
    const paddingBottom = parseFloat(panelStyles.paddingBottom) || 0;
    const headStyles = window.getComputedStyle(detailHead);
    const headMarginBottom = parseFloat(headStyles.marginBottom) || 0;
    const targetHeight = summaryPanel.offsetHeight - paddingTop - paddingBottom - detailHead.offsetHeight - headMarginBottom;
    const finalHeight = Math.max(360, targetHeight);
    tableWrap.style.maxHeight = `${finalHeight}px`;
    tableWrap.style.height = `${finalHeight}px`;
  }

  async function loadDashboard(){
    clearError();
    if (!selectedCodes().length) {
      showError('Selecciona al menos un centro de costo.');
      return;
    }
    setBusy(true);
    try {
      const response = await fetch(`${apiUrl}?${params('dashboard')}`, { credentials: 'same-origin' });
      const payload = await response.json();
      if (!payload.ok) throw new Error(payload.error || 'No se pudo cargar el dashboard.');
      const data = payload.data;
      if (activeDetailCeco && !data.summaries.some((row) => row.cost_center_code === activeDetailCeco)) {
        activeDetailCeco = '';
      }
      renderWarnings(data.warnings);
      renderSummary(data);
      lastPersonRows = data.person_days || [];
      renderPeople();
      saveDashboardPayload(data);
    } catch (error) {
      showError(error.message);
    } finally {
      setBusy(false);
    }
  }

  document.getElementById('btnCancelCecos').addEventListener('click', function(){
    applySavedCecoSelection();
    setCecoExpanded(false);
  });
  document.getElementById('btnSaveCecos').addEventListener('click', function(){
    savedCecoSelection = selectedCodes();
    saveDashboardState();
    updateCecoMeta();
    setCecoExpanded(false);
    loadDashboard();
  });
  cecoCollapseToggle.addEventListener('click', function(){
    setCecoExpanded(!cecoCollapse.classList.contains('show'));
  });
  form.addEventListener('submit', function(event){
    event.preventDefault();
    savedCecoSelection = selectedCodes();
    saveDashboardState();
    loadDashboard();
  });
  document.getElementById('tabGraficoAsistencia').addEventListener('click', function(){
    if (selectedCodes().length) {
      savedCecoSelection = selectedCodes();
    }
    saveDashboardState();
  });
  document.getElementById('tabAusencias').addEventListener('click', function(){
    if (selectedCodes().length) {
      savedCecoSelection = selectedCodes();
    }
    saveDashboardState();
  });
  personSearch.addEventListener('input', renderPeople);
  window.addEventListener('resize', syncDetailHeight);
  restoreDashboardState();
  loadCostCenters();
})();
</script>
</body>
</html>
