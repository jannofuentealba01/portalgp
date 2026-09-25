(() => {
  const root = document.querySelector('.msp-portal-home');
  if (!root) {
    return;
  }

  const panels = Array.from(root.querySelectorAll('[data-portal-panel]'));
  const sectionButtons = Array.from(root.querySelectorAll('[data-portal-section-target]'));
  const menuTrigger = root.querySelector('.msp-portal-menu-trigger');

  function sectionExists(sectionId) {
    return panels.some((panel) => panel.dataset.portalPanel === sectionId);
  }

  function activateSection(sectionId, updateUrl = true) {
    const safeSection = sectionExists(sectionId) ? sectionId : 'inicio';
    panels.forEach((panel) => {
      panel.hidden = panel.dataset.portalPanel !== safeSection;
    });
    sectionButtons.forEach((button) => {
      const active = button.dataset.portalSectionTarget === safeSection
        && button.classList.contains('msp-portal-nav-item');
      button.classList.toggle('is-active', active);
      if (active) {
        button.setAttribute('aria-current', 'page');
      } else {
        button.removeAttribute('aria-current');
      }
    });
    root.classList.remove('is-sidebar-open');
    if (menuTrigger) {
      menuTrigger.setAttribute('aria-expanded', 'false');
    }
    if (updateUrl && window.history && window.history.replaceState) {
      const url = new URL(window.location.href);
      url.hash = safeSection === 'inicio' ? '' : 'area=' + encodeURIComponent(safeSection);
      window.history.replaceState(null, '', url);
    }
  }

  root.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-portal-section-target]');
    if (!trigger || !root.contains(trigger)) {
      return;
    }
    activateSection(trigger.dataset.portalSectionTarget || 'inicio');
  });

  if (menuTrigger) {
    menuTrigger.addEventListener('click', () => {
      const open = root.classList.toggle('is-sidebar-open');
      menuTrigger.setAttribute('aria-expanded', String(open));
    });
  }

  const match = window.location.hash.match(/^#area=([A-Za-z0-9_-]+)$/);
  activateSection(match ? decodeURIComponent(match[1]) : 'inicio', false);
})();
