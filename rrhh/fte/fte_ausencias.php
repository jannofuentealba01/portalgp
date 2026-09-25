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
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Ausencias FTE</title>
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
    .fte-panel { border: 1px solid rgba(15, 76, 129, 0.16); border-radius: 12px; background: #fff; box-shadow: 0 8px 22px rgba(8, 33, 71, 0.08); }
    .fte-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .absence-table thead th {
      background: #0f4c81 !important;
      color: #fff !important;
      border-color: #0b3f75 !important;
      font-size: 0.78rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      position: sticky;
      top: 0;
      z-index: 1;
    }
    .absence-table-wrap { max-height: 68vh; overflow: auto; }
    .ceco-stack { display: grid; gap: 0.12rem; justify-items: center; line-height: 1.25; }
    .ceco-stack-code { color: #64748b; font-size: 0.82rem; font-weight: 700; }
    .ceco-stack-desc { color: #0f172a; font-weight: 800; }
    .reason-badge { display: inline-flex; align-items: center; border-radius: 999px; padding: 0.25rem 0.6rem; font-size: 0.78rem; font-weight: 800; }
    .reason-vacaciones { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
    .reason-licencia { background: #e0f2fe; color: #075985; border: 1px solid #7dd3fc; }
    .reason-no-informada { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
    .warning-chip { border: 1px solid #f5c16c; background: #fff7e6; color: #7a4b00; border-radius: 999px; padding: 0.35rem 0.7rem; font-size: 0.85rem; }
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
      .absence-table-wrap { max-height: none; overflow: visible; }
      .fte-table-card table,
      .fte-table-card thead,
      .fte-table-card tbody,
      .fte-table-card th,
      .fte-table-card td,
      .fte-table-card tr { display: block; width: 100%; }
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
        grid-template-columns: minmax(106px, 34%) 1fr;
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
      .fte-table-card tbody td.table-empty { display: block; text-align: center !important; }
      .fte-table-card tbody td.table-empty::before { display: none; content: ''; }
      .fte-table-card .ceco-stack { justify-items: center; text-align: center; }
    }
    @media (max-width: 768px) {
      .menu-hero { padding: 2.4rem 0.9rem 2.7rem; }
      .menu-hero .hero-title { font-size: 1.15rem; }
      .menu-hero .hero-lead { font-size: 0.8rem; padding: 0 0.5rem; }
    }
    @media (max-width: 560px) {
      .fte-panel { border-radius: 10px; }
      .fte-panel.p-3 { padding: 0.75rem !important; }
      #absenceSearch { max-width: none !important; width: 100%; }
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
          <h1 class="hero-title">Ausencias FTE</h1>
          <p class="hero-lead">Ausencias del rango cargado, cruzadas con vacaciones y licencias informadas en Buk.</p>
        </div>
      </section>

      <div class="fte-tabs">
        <a class="fte-tab" href="fte_mensual.php">Vista mensual</a>
        <a class="fte-tab" href="fte_dashboard.php">Resumen y detalle</a>
        <a class="fte-tab" href="fte_grafico_asistencia.php">Grafico asistencia diaria</a>
        <a class="fte-tab is-active" href="fte_ausencias.php">Ausencias</a>
      </div>

      <div id="warnings" class="d-flex flex-wrap gap-2 mb-3"></div>
      <div id="loading" class="alert alert-info d-none">Consultando razones de ausencia en Buk.</div>
      <div id="errorBox" class="alert alert-danger d-none"></div>

      <div class="fte-panel p-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
          <div>
            <h2 class="h5 mb-1">Detalle de ausencias</h2>
            <div class="text-muted small" id="rangeText">Usa la informacion ya cargada en Resumen y detalle.</div>
          </div>
          <input class="form-control form-control-sm" id="absenceSearch" placeholder="Buscar persona, RUT, CECO o razon" style="max-width: 300px;">
        </div>
        <div class="table-responsive fte-table-wrap fte-table-card absence-table-wrap">
          <table class="table table-sm align-middle absence-table">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Persona</th>
                <th>CECO</th>
                <th>Razon</th>
                <th>Detalle Buk</th>
              </tr>
            </thead>
            <tbody id="absenceBody">
              <tr><td colspan="5" class="text-muted table-empty">Sin datos cargados.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </div>
</main>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
<script<?= function_exists('pgpCspNonceAttribute') ? pgpCspNonceAttribute() : '' ?> src="fte_ui.js"></script>
<script<?= function_exists('pgpCspNonceAttribute') ? pgpCspNonceAttribute() : '' ?>>
(function(){
  const loading = document.getElementById('loading');
  const errorBox = document.getElementById('errorBox');
  const warnings = document.getElementById('warnings');
  const body = document.getElementById('absenceBody');
  const search = document.getElementById('absenceSearch');
  const FTE_STATE_KEY = 'patagual.fte.dashboardState.v1';
  const FTE_CACHE_TTL_MS = 15 * 60 * 1000;
  let absenceRows = [];

  function restoreDashboardPayload(){
    try {
      const cached = JSON.parse(sessionStorage.getItem(`${FTE_STATE_KEY}.payload`) || '{}');
      const savedAt = Date.parse(cached.saved_at || '');
      return cached && cached.data && Number.isFinite(savedAt) && Date.now() - savedAt <= FTE_CACHE_TTL_MS ? cached : null;
    } catch (error) {
      return null;
    }
  }

  function showError(message){
    errorBox.textContent = message || 'Ocurrio un error.';
    errorBox.classList.remove('d-none');
  }

  function clearError(){
    errorBox.classList.add('d-none');
    errorBox.textContent = '';
  }

  function setBusy(value){
    loading.classList.toggle('d-none', !value);
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

  function reasonBadge(row){
    const kind = row.reason_kind || 'No informada';
    const cls = kind === 'Vacaciones'
      ? 'reason-vacaciones'
      : (kind === 'Licencia medica' ? 'reason-licencia' : 'reason-no-informada');
    return FteUi.element('span', kind, `reason-badge ${cls}`);
  }

  function buildParams(data){
    const params = new URLSearchParams();
    params.set('action', 'absences');
    params.set('from_date', data.from_date || '');
    params.set('to_date', data.to_date || '');
    (data.requested_cost_center_codes || []).forEach((code) => params.append('cost_center_code[]', code));
    return params;
  }

  async function fetchReasons(data){
    const response = await fetch(`fte_api.php?${buildParams(data)}`, { credentials: 'same-origin' });
    const payload = await response.json();
    if (!payload.ok) throw new Error(payload.error || 'No se pudieron consultar las ausencias en Buk.');
    return payload.data || {};
  }

  function buildRows(data, reasonData){
    const reasons = reasonData.reasons || {};
    return (data.person_days || [])
      .filter((row) => ['SIN_MARCACION', 'MARCACION_INCOMPLETA'].includes(row.attendance_status || ''))
      .map((row) => {
        const id = row.normalized_identifier || row.identifier || '';
        const reason = reasons[id] && reasons[id][row.date] ? reasons[id][row.date] : null;
        const justifiedStatus = reason && reason.kind === 'Vacaciones'
          ? { code: 'VACACIONES', label: 'Vacaciones' }
          : (reason && reason.kind === 'Licencia medica'
            ? { code: 'LICENCIA', label: 'Licencia medica' }
            : null);
        return {
          ...row,
          attendance_status: justifiedStatus ? justifiedStatus.code : row.attendance_status,
          attendance_status_label: justifiedStatus ? justifiedStatus.label : row.attendance_status_label,
          attendance_requires_review: justifiedStatus ? false : row.attendance_requires_review,
          reason_kind: reason ? reason.kind : 'No informada',
          reason_detail: reason ? reason.reason : 'No informada',
          reason_from: reason ? reason.from : '',
          reason_to: reason ? reason.to : '',
        };
      });
  }

  function renderRows(){
    const q = (search.value || '').trim().toLowerCase();
    const rows = absenceRows.filter((row) => {
      if (!q) return true;
      return [row.date, row.person_name, row.identifier, row.cost_center_code, row.cost_center_name, row.reason_kind, row.reason_detail]
        .join(' ')
        .toLowerCase()
        .includes(q);
    }).slice(0, 1500);
    FteUi.clear(body);
    if (!rows.length) {
      FteUi.emptyRow(body, 5, 'Sin ausencias para mostrar.');
      return;
    }
    rows.forEach((row) => {
      const range = row.reason_from || row.reason_to ? `${row.reason_from || '-'} al ${row.reason_to || '-'}` : '';
      const tr = document.createElement('tr');
      FteUi.textCell(tr, row.date || '', 'Fecha');
      FteUi.personCell(tr, row.person_name || row.identifier || '', row.identifier || '');
      FteUi.cecoCell(tr, row, 'CECO');
      const reasonCell = FteUi.textCell(tr, '', 'Razon');
      reasonCell.appendChild(reasonBadge(row));
      const detailCell = FteUi.textCell(tr, '', 'Detalle Buk');
      detailCell.appendChild(FteUi.element('strong', row.reason_detail || row.reason_kind || 'No informada'));
      if (range) {
        detailCell.appendChild(document.createElement('br'));
        detailCell.appendChild(FteUi.element('small', range, 'text-muted'));
      }
      body.appendChild(tr);
    });
  }

  async function loadAbsences(){
    clearError();
    const cached = restoreDashboardPayload();
    if (!cached || !cached.data) {
      showError('No hay datos precargados. Vuelve a Resumen y detalle, carga la informacion y entra nuevamente a Ausencias.');
      return;
    }
    const data = cached.data;
    document.getElementById('rangeText').textContent = `Rango ${data.from_date || '-'} al ${data.to_date || '-'} · datos del dashboard cargados ${new Date(cached.saved_at || data.generated_at).toLocaleString('es-CL')}`;
    setBusy(true);
    try {
      const reasonData = await fetchReasons(data);
      renderWarnings([...(data.warnings || []), ...(reasonData.warnings || [])]);
      absenceRows = buildRows(data, reasonData);
      renderRows();
    } catch (error) {
      showError(error.message);
    } finally {
      setBusy(false);
    }
  }

  search.addEventListener('input', renderRows);
  loadAbsences();
})();
</script>
</body>
</html>
