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
$defaultPeriod = (new DateTimeImmutable('first day of last month'))->format('Y-m');
include __DIR__ . '/../../templates/header.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>FTE mensual preliminar</title>
  <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-5.3.3/css/bootstrap.min.css">
  <link rel="stylesheet" href="../../styles.css">
  <style>
    body.ph-portal.no-sidebar .content-wrapper{padding-top:.35rem!important}.portal-shell{padding-top:0!important}.monthly-main{display:flex;flex-direction:column;gap:14px}.monthly-main>.menu-hero{margin:0;padding:14px 18px 10px}.menu-hero{text-align:center;position:relative}.menu-hero .back-row{position:absolute;top:.35rem;left:1rem}.menu-hero .back-link{display:inline-flex;text-decoration:none;padding:.5rem .9rem;font-weight:700;color:#fff;border-radius:999px;border:1px solid rgba(255,255,255,.45);background:rgba(255,255,255,.16)}
    .fte-tabs{display:flex;justify-content:center;gap:.65rem;flex-wrap:wrap}.fte-tab{border:1px solid #bacbde;background:#f1f6fc;color:#536a84;text-decoration:none;border-radius:999px;padding:10px 18px;font-weight:700;font-size:13.5px}.fte-tab.is-active{background:linear-gradient(135deg,rgba(13,27,42,.08),rgba(43,154,243,.16),rgba(34,201,142,.07));color:#0d5ea8}
    .monthly-panel{border:1px solid rgba(15,76,129,.16);border-radius:12px;background:#fff;box-shadow:0 8px 22px rgba(8,33,71,.08)}.monthly-toolbar{background:#f6f9ff;padding:1rem}.kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.kpi{padding:1rem}.kpi-label{font-size:.72rem;letter-spacing:.06em;text-transform:uppercase;color:#64748b;font-weight:800}.kpi-value{font-size:1.6rem;color:#0f4c81;font-weight:800}.preliminary-note{border-left:4px solid #d97706;background:#fff7ed;color:#7c2d12}.table thead th{background:#0f4c81;color:#fff;white-space:nowrap;font-size:.77rem;text-transform:uppercase}.coverage-ok{color:#137333;font-weight:700}.coverage-pending{color:#9a6700;font-weight:700}.ranking-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}.ranking-card{padding:.85rem}.ranking-card strong{display:block;color:#0f4c81}.loading-box{padding:1rem;background:#eaf4ff;color:#0b3f75;border-radius:10px}
    @media(max-width:1100px){.kpi-grid{grid-template-columns:repeat(2,1fr)}.ranking-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:650px){.kpi-grid,.ranking-grid{grid-template-columns:1fr}.menu-hero .back-row{position:static;margin-bottom:8px}.monthly-toolbar .row>*{width:100%}}
  </style>
</head>
<body class="ph-portal no-sidebar">
<main class="content-wrapper"><div class="portal-shell"><section class="form-card monthly-main">
  <section class="hero-strip menu-hero">
    <div class="back-row"><a href="/portalgp/index.php" class="back-link">&larr; Volver al menu</a></div>
    <h1 class="hero-title">FTE mensual</h1>
    <p class="hero-lead">Resumen gerencial mensual por centro de costo.</p>
  </section>
  <nav class="fte-tabs" aria-label="Vistas FTE">
    <a class="fte-tab is-active" href="fte_mensual.php">Vista mensual</a>
    <a class="fte-tab" href="fte_dashboard.php">Resumen y detalle</a>
    <a class="fte-tab" href="fte_grafico_asistencia.php">Grafico asistencia diaria</a>
    <a class="fte-tab" href="fte_ausencias.php">Ausencias</a>
  </nav>
  <div class="monthly-panel monthly-toolbar">
    <form id="monthlyForm" class="row g-3 align-items-end">
      <div class="col-md-3"><label class="form-label fw-bold" for="period">Mes</label><input class="form-control" type="month" id="period" value="<?= fte_h($defaultPeriod) ?>" required></div>
      <div class="col-md-4"><label class="form-label fw-bold" for="ceco">Centro de costo</label><select class="form-select" id="ceco"><option value="">Todos los CECO</option></select></div>
      <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" id="includeAttendance"><label class="form-check-label" for="includeAttendance">Incorporar GeoVictoria</label></div><small class="text-muted">Con todos los CECO puede tardar varios minutos.</small></div>
      <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Calcular mes</button></div>
    </form>
  </div>
  <div class="preliminary-note p-3 rounded"><strong>Resultado preliminar.</strong> Usa fuentes disponibles y muestra por separado los componentes que RR.HH. todavia debe confirmar. No corresponde a un cierre oficial.</div>
  <div id="loading" class="loading-box d-none">Calculando el mes y consultando las fuentes seleccionadas...</div>
  <div id="errorBox" class="alert alert-danger d-none"></div><div id="warnings"></div>
  <section id="results" class="d-none d-flex flex-column gap-3">
    <div class="kpi-grid">
      <div class="monthly-panel kpi"><div class="kpi-label">Dotacion real</div><div class="kpi-value" data-kpi="headcount">-</div></div>
      <div class="monthly-panel kpi"><div class="kpi-label">FTE calculado</div><div class="kpi-value" data-kpi="fte">-</div></div>
      <div class="monthly-panel kpi"><div class="kpi-label">Brecha Dotacion/FTE</div><div class="kpi-value" data-kpi="gap">-</div></div>
      <div class="monthly-panel kpi"><div class="kpi-label">FTE / Dotacion</div><div class="kpi-value" data-kpi="ratio">-</div></div>
      <div class="monthly-panel kpi"><div class="kpi-label">Horas teoricas</div><div class="kpi-value" data-kpi="theoretical">-</div></div>
      <div class="monthly-panel kpi"><div class="kpi-label">Horas extra</div><div class="kpi-value" data-kpi="overtime">-</div></div>
      <div class="monthly-panel kpi"><div class="kpi-label">Horas no disponibles</div><div class="kpi-value" data-kpi="lost">-</div></div>
      <div class="monthly-panel kpi"><div class="kpi-label">Tasa total</div><div class="kpi-value" data-kpi="rate">-</div></div>
    </div>
    <div class="ranking-grid" id="rankings"></div>
    <div class="monthly-panel p-3"><h2 class="h5">Resultado por CECO</h2><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>CECO</th><th class="text-end">Dotacion</th><th class="text-end">FTE</th><th class="text-end">Brecha</th><th class="text-end">FTE/Dot.</th><th class="text-end">H. teoricas</th><th class="text-end">H. extra</th><th class="text-end">H. no disp.</th><th class="text-end">Tasa</th></tr></thead><tbody id="monthlyBody"></tbody></table></div></div>
    <div class="monthly-panel p-3"><h2 class="h5">Cobertura de fuentes</h2><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Componente</th><th>Estado</th><th>Fuente</th></tr></thead><tbody id="coverageBody"></tbody></table></div></div>
  </section>
</section></div></main>
<?php include __DIR__ . '/../../templates/footer.php'; ?>
<script src="fte_ui.js"></script>
<script>
(function(){
  const form=document.getElementById('monthlyForm'),period=document.getElementById('period'),ceco=document.getElementById('ceco'),includeAttendance=document.getElementById('includeAttendance'),loading=document.getElementById('loading'),errorBox=document.getElementById('errorBox'),warnings=document.getElementById('warnings'),results=document.getElementById('results'),body=document.getElementById('monthlyBody'),coverageBody=document.getElementById('coverageBody'),rankings=document.getElementById('rankings');
  const dec=new Intl.NumberFormat('es-CL',{maximumFractionDigits:2}),intFmt=new Intl.NumberFormat('es-CL',{maximumFractionDigits:0}),pct=new Intl.NumberFormat('es-CL',{style:'percent',maximumFractionDigits:1});
  const value=(v,formatter=dec)=>v===null||v===undefined?'-':formatter.format(v);
  async function loadCecos(){try{const response=await fetch('fte_api.php?action=cost_centers',{credentials:'same-origin'}),payload=await response.json();if(!payload.ok)return;(payload.cost_centers||[]).forEach(row=>{const option=document.createElement('option');option.value=row.cost_center_code||'';option.textContent=`${row.cost_center_code||''} ${row.cost_center_name||''}`.trim();ceco.appendChild(option);});}catch(e){}}
  function cell(tr,text,cls=''){return FteUi.textCell(tr,text,'',cls)}
  function setKpi(key,text){document.querySelector(`[data-kpi="${key}"]`).textContent=text}
  function render(data){
    const totals=data.totals||{};setKpi('headcount',value(totals.headcount,intFmt));setKpi('fte',value(totals.fte));setKpi('gap',value(totals.headcount_fte_gap));setKpi('ratio',value(totals.fte_to_headcount_rate,pct));setKpi('theoretical',value(totals.theoretical_headcount_hours));setKpi('overtime',value(totals.authorized_overtime_hours));setKpi('lost',value(totals.lost_hours));setKpi('rate',value(totals.unavailable_hours_rate,pct));
    FteUi.clear(body);(data.cost_centers||[]).forEach(row=>{const tr=document.createElement('tr');cell(tr,`${row.cost_center_code} ${row.cost_center_name||''}`.trim());cell(tr,value(row.headcount,intFmt),'text-end');cell(tr,value(row.fte),'text-end');cell(tr,value(row.headcount_fte_gap),'text-end');cell(tr,value(row.fte_to_headcount_rate,pct),'text-end');cell(tr,value(row.theoretical_headcount_hours),'text-end');cell(tr,value(row.authorized_overtime_hours),'text-end');cell(tr,value(row.lost_hours),'text-end');cell(tr,value(row.unavailable_hours_rate,pct),'text-end');body.appendChild(tr)});if(!(data.cost_centers||[]).length)FteUi.emptyRow(body,9,'No hay CECO con dotacion para el periodo.');
    FteUi.clear(coverageBody);(data.source_coverage||[]).forEach(row=>{const tr=document.createElement('tr');cell(tr,row.component||'');const state=cell(tr,(row.status||'').replaceAll('_',' '));state.className=(row.status||'').includes('AUTOMATICO')?'coverage-ok':'coverage-pending';cell(tr,row.source||'');coverageBody.appendChild(tr)});
    const labels={largest_gap:'Mayor brecha',largest_unavailable_rate:'Mayor tasa',most_overtime:'Mas horas extra',most_licence:'Mas licencias',most_vacation:'Mas vacaciones'};FteUi.clear(rankings);Object.entries(labels).forEach(([key,label])=>{const row=(data.rankings||{})[key],card=FteUi.element('div','', 'monthly-panel ranking-card'),title=FteUi.element('span',label,'kpi-label'),name=FteUi.element('strong',row?`${row.cost_center_code} ${row.cost_center_name||''}`.trim():'Sin datos'),formatted=row?(key==='largest_unavailable_rate'?value(row.value,pct):`${value(row.value)}${key.startsWith('most_')?' h':''}`):'-',number=FteUi.element('span',formatted,'text-muted');card.append(title,name,number);rankings.appendChild(card)});
    FteUi.clear(warnings);(data.warnings||[]).forEach(message=>{const alert=FteUi.element('div',message,'alert alert-warning py-2 mb-2');warnings.appendChild(alert)});results.classList.remove('d-none');
  }
  form.addEventListener('submit',async(event)=>{event.preventDefault();errorBox.classList.add('d-none');warnings.textContent='';results.classList.add('d-none');loading.classList.remove('d-none');const params=new URLSearchParams({action:'monthly',period:period.value,include_attendance:includeAttendance.checked?'1':'0'});if(ceco.value)params.append('cost_center_code[]',ceco.value);try{const response=await fetch(`fte_api.php?${params}`,{credentials:'same-origin'}),payload=await response.json();if(!payload.ok)throw new Error(payload.error||'No fue posible calcular el mes.');render(payload.data||{});}catch(error){errorBox.textContent=error.message||'No fue posible calcular el mes.';errorBox.classList.remove('d-none');}finally{loading.classList.add('d-none')}});
  loadCecos();
})();
</script>
</body></html>
