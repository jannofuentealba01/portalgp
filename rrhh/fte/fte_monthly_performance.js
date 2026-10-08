(function () {
  'use strict';
  // Local, non-nominal measurements. No telemetry, storage or extra requests.
  function start(node, startedAt) {
    const record = {status: 'running', request_seconds: null, json_seconds: null,
      render_seconds: null, screen_seconds: null, visible_frames_confirmed: false,
      server_timing: {}, coverage: null};
    const now = () => performance.now();
    let headersAt = null, parsedAt = null, published = '';
    const publish = () => {
      try {
        if (published && node.dataset.ftePerformance !== published) return;
        published = JSON.stringify(record);
        node.dataset.ftePerformance = published;
      } catch (_) { /* Measurement must not change the calculation or UI. */ }
    };
    publish();
    return Object.freeze({
      response(response) {
        headersAt = now(); record.request_seconds = (headersAt - startedAt) / 1000;
        try {
          const header = response.headers && response.headers.get('Server-Timing') || '';
          const allowed = new Set(['auth','roster_calendar','vacations','licences','absences','attendance','calculation','serialization','fte_total']);
          for (const item of header.split(',')) {
            const match = item.trim().match(/^([a-z_]+);dur=(\d+(?:\.\d+)?)$/);
            if (match && allowed.has(match[1])) record.server_timing[match[1]] = Number(match[2]) / 1000;
          }
        } catch (_) {}
        publish();
      },
      parsed(data) {
        parsedAt = now(); record.json_seconds = headersAt === null ? null : (parsedAt - headersAt) / 1000;
        const d = data && data.attendance_diagnostics || {};
        record.coverage = {requested: (data && data.data_quality && data.data_quality.workers_requested) ?? null,
          recovered: d.successful ?? null, failed: d.failed ?? null, unmatched: d.unmatched ?? null,
          strategy: ['resilient_monthly_batch','concurrent_individual','batch','individual'].includes(d.strategy) ? d.strategy : null};
        publish();
      },
      rendered() {
        record.render_seconds = parsedAt === null ? null : (now() - parsedAt) / 1000;
        record.status = 'rendered'; publish();
        if (typeof window.requestAnimationFrame !== 'function' || document.visibilityState !== 'visible') return;
        window.requestAnimationFrame(() => window.requestAnimationFrame(() => {
          if (document.visibilityState !== 'visible') return;
          record.screen_seconds = (now() - startedAt) / 1000;
          record.visible_frames_confirmed = true; record.status = 'complete'; publish();
        }));
      },
      failed() { record.status = 'error'; publish(); },
    });
  }
  window.FteMonthlyPerformance = Object.freeze({start});
}());
