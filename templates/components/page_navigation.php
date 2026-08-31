<?php
declare(strict_types=1);

if (defined('GP_PAGE_NAVIGATION_RENDERED')) {
    return;
}
define('GP_PAGE_NAVIGATION_RENDERED', true);

/**
 * Central navigation metadata for page-level back links.
 *
 * Existing, context-aware links in each view keep their original destination.
 * The parent below is only used when a page has no explicit back link. The same
 * payload is intentionally suitable for rendering breadcrumbs in a later phase.
 *
 * @return array{current:string,parent_url:string,parent_label:string,breadcrumbs:list<array{label:string,url:string}>}
 */
function gpPageNavigationMetadata(): array
{
    $requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    $path = '/' . ltrim(rawurldecode($requestPath), '/');
    $relative = preg_replace('#^/portalgp/?#', '', $path) ?? '';
    $relative = trim($relative, '/');
    $current = $relative !== '' ? $relative : 'index.php';

    $parentUrl = '';
    $parentLabel = 'Volver';
    $breadcrumbs = [['label' => 'Inicio', 'url' => '/portalgp/index.php']];

    if (str_starts_with($current, 'msp/')) {
        $breadcrumbs = [];
        $mspSectionLabels = [
            'arrendatarios' => 'Arrendatarios',
            'ayuda' => 'Ayuda',
            'cierre_mensual' => 'Cierre mensual',
            'cobranza' => 'Cobranza',
            'cobros' => 'Cobros',
            'configuracion' => 'Configuración',
            'contabilidad' => 'Contabilidad',
            'contratos' => 'Contratos',
            'control_diario' => 'Control diario',
            'correcciones' => 'Correcciones',
            'dashboard' => 'Dashboard',
            'documentos_cobro' => 'Documentos de cobro',
            'garantias' => 'Garantías',
            'locales' => 'Locales',
            'pagos' => 'Pagos',
            'pendientes' => 'Pendientes',
            'reportes' => 'Reportes',
            'tesoreria' => 'Tesorería',
            'tiendas' => 'Tiendas',
        ];
        $moduleRelative = substr($current, 4);
        $segments = array_values(array_filter(explode('/', $moduleRelative), static fn (string $part): bool => $part !== ''));
        $breadcrumbs[] = ['label' => 'MSP', 'url' => '/portalgp/msp/msp_menu.php'];

        if ($moduleRelative === 'msp_menu.php') {
            $parentUrl = '/portalgp/index.php';
            $parentLabel = 'Volver al menú principal';
        } elseif ($moduleRelative === 'catalogo_menu.php') {
            $parentUrl = '/portalgp/msp/msp_menu.php';
            $parentLabel = 'Volver al menú MSP';
        } elseif (($segments[0] ?? '') === 'catalogos') {
            $parentUrl = '/portalgp/msp/catalogo_menu.php';
            $parentLabel = 'Volver a catálogos';
            $breadcrumbs[] = ['label' => 'Catálogos', 'url' => $parentUrl];
        } elseif (count($segments) >= 2) {
            $section = $segments[0];
            $sectionLabel = $mspSectionLabels[$section] ?? ucfirst(str_replace('_', ' ', $section));
            $sectionIndex = dirname(__DIR__, 2) . '/msp/' . $section . '/index.php';
            $sectionUrl = is_file($sectionIndex)
                ? '/portalgp/msp/' . rawurlencode($section) . '/index.php'
                : '';
            $isSectionIndex = ($segments[1] ?? '') === 'index.php';
            $parentUrl = $isSectionIndex || $sectionUrl === '' ? '/portalgp/msp/msp_menu.php' : $sectionUrl;
            $parentLabel = $isSectionIndex || $sectionUrl === '' ? 'Volver al menú MSP' : 'Volver a ' . $sectionLabel;
            $breadcrumbs[] = ['label' => $sectionLabel, 'url' => $sectionUrl];
        } else {
            $parentUrl = '/portalgp/msp/msp_menu.php';
            $parentLabel = 'Volver al menú MSP';
        }
    } elseif (str_starts_with($current, 'ct/')) {
        $moduleRelative = substr($current, 3);
        $segments = array_values(array_filter(explode('/', $moduleRelative), static fn (string $part): bool => $part !== ''));
        $breadcrumbs[] = ['label' => 'CT', 'url' => '/portalgp/ct/ct_menu.php'];

        if ($moduleRelative === 'ct_menu.php' || $moduleRelative === 'index.php') {
            $parentUrl = '/portalgp/index.php';
            $parentLabel = 'Volver al menú principal';
        } elseif (count($segments) >= 2) {
            $section = $segments[0];
            $sectionIndex = dirname(__DIR__, 2) . '/ct/' . $section . '/index.php';
            $parentUrl = is_file($sectionIndex)
                ? '/portalgp/ct/' . rawurlencode($section) . '/index.php'
                : '/portalgp/ct/ct_menu.php';
            $parentLabel = is_file($sectionIndex) ? 'Volver a ' . ucfirst(str_replace('_', ' ', $section)) : 'Volver al menú CT';
            $breadcrumbs[] = ['label' => ucfirst(str_replace('_', ' ', $section)), 'url' => $parentUrl];
        } else {
            $parentUrl = '/portalgp/ct/ct_menu.php';
            $parentLabel = 'Volver al menú CT';
        }
    } elseif (str_starts_with($current, 'sistema/gestion/')) {
        $breadcrumbs[] = ['label' => 'Gestión del sistema', 'url' => '/portalgp/sistema/gestion/index.php'];
        $parentUrl = $current === 'sistema/gestion/index.php'
            ? '/portalgp/index.php'
            : '/portalgp/sistema/gestion/index.php';
        $parentLabel = $current === 'sistema/gestion/index.php'
            ? 'Volver al menú principal'
            : 'Volver a gestión del sistema';
    } elseif ($current !== 'index.php' && $current !== 'login.php') {
        $parentUrl = '/portalgp/index.php';
        $parentLabel = 'Volver al menú principal';
    }

    return [
        'current' => $current,
        'parent_url' => $parentUrl,
        'parent_label' => $parentLabel,
        'breadcrumbs' => $breadcrumbs,
    ];
}

$gpPageNavigation = gpPageNavigationMetadata();
?>
<script type="application/json" id="gp-page-navigation-data"><?php
echo json_encode($gpPageNavigation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
?></script>
<script>
(() => {
    const initPageNavigation = () => {
        const dataNode = document.getElementById('gp-page-navigation-data');
        const main = document.querySelector('main');
        if (!dataNode || !main || main.dataset.gpPageNavigationReady === 'true') {
            return;
        }

        let metadata;
        try {
            metadata = JSON.parse(dataNode.textContent || '{}');
        } catch (error) {
            return;
        }

        main.dataset.gpPageNavigationReady = 'true';
        main.dataset.gpBreadcrumbs = JSON.stringify(metadata.breadcrumbs || []);
        if (typeof metadata.current === 'string' && metadata.current.startsWith('msp/')) {
            document.body.classList.add('gp-module-msp');
            document.body.classList.add(`gp-page-${metadata.current.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')}`);
        }

        const renderBreadcrumbs = (heading) => {
            if (!document.body.classList.contains('gp-module-msp') || !heading || heading.closest('.modal, [role="dialog"]')) {
                return;
            }

            const source = Array.isArray(metadata.breadcrumbs) ? metadata.breadcrumbs : [];
            const items = source.map((item) => ({
                label: String(item.label || '').trim(),
                url: String(item.url || '').trim(),
                current: false
            })).filter((item) => item.label !== '');
            const currentPath = `/portalgp/${String(metadata.current || '').replace(/^\/+/, '')}`;
            const currentLabel = (heading.textContent || '').replace(/\s+/g, ' ').trim();
            const matchingIndex = items.findIndex((item) => {
                try {
                    return item.url !== '' && new URL(item.url, window.location.origin).pathname === currentPath;
                } catch (error) {
                    return false;
                }
            });

            if (matchingIndex >= 0) {
                items.splice(matchingIndex + 1);
                items[matchingIndex].label = currentLabel || items[matchingIndex].label;
                items[matchingIndex].url = '';
                items[matchingIndex].current = true;
            } else if (currentLabel !== '') {
                items.push({ label: currentLabel, url: '', current: true });
            }

            const deduped = items.filter((item, index) => index === 0 || item.label !== items[index - 1].label || item.url !== items[index - 1].url);
            if (deduped.length < 2) {
                return;
            }

            const nav = document.createElement('nav');
            nav.className = 'gp-breadcrumbs';
            nav.setAttribute('aria-label', 'Ruta de navegación');
            const list = document.createElement('ol');
            deduped.forEach((item) => {
                const listItem = document.createElement('li');
                if (item.url !== '' && !item.current) {
                    const link = document.createElement('a');
                    link.href = item.url;
                    link.textContent = item.label;
                    listItem.appendChild(link);
                } else {
                    const current = document.createElement('span');
                    current.textContent = item.label;
                    if (item.current) {
                        current.setAttribute('aria-current', 'page');
                    }
                    listItem.appendChild(current);
                }
                list.appendChild(listItem);
            });
            nav.appendChild(list);

            const titleContainer = heading.parentElement;
            titleContainer?.querySelectorAll(':scope > .section-kicker').forEach((kicker) => {
                kicker.classList.add('gp-breadcrumbs-replaced-kicker');
            });
            heading.before(nav);
        };

        const candidates = Array.from(main.querySelectorAll('a[href]')).filter((link) => {
            if (link.closest('.modal, [role="dialog"], .offcanvas, nav[aria-label*="agin"]')) {
                return false;
            }
            const text = (link.textContent || '').replace(/\s+/g, ' ').trim();
            return link.hasAttribute('data-gp-page-back') || /^(?:←\s*)?(?:volver|regresar|atrás|atras)(?:\s|$)/i.test(text);
        });

        if (candidates.length === 0 && metadata.parent_url) {
            const fallback = document.createElement('a');
            fallback.href = metadata.parent_url;
            fallback.textContent = metadata.parent_label || 'Volver';
            fallback.setAttribute('data-gp-page-back', 'fallback');
            candidates.push(fallback);
        }

        if (candidates.length === 0) {
            return;
        }

        const slot = document.createElement('nav');
        slot.className = 'gp-page-navigation';
        slot.setAttribute('aria-label', 'Navegación de regreso');
        slot.dataset.gpBreadcrumbReady = 'true';

        const seen = new Set();
        const uniqueCandidates = [];
        candidates.forEach((link) => {
            const key = `${link.href}|${(link.textContent || '').trim()}`;
            if (seen.has(key)) {
                link.remove();
                return;
            }
            seen.add(key);
            link.className = 'btn btn-outline-secondary btn-sm gp-page-back';
            link.removeAttribute('style');
            link.setAttribute('data-gp-page-back', 'true');

            let icon = link.querySelector('i');
            if (!icon) {
                icon = document.createElement('i');
                link.prepend(icon);
            }
            icon.className = 'bi bi-arrow-left gp-page-back__icon';
            icon.setAttribute('aria-hidden', 'true');
            uniqueCandidates.push(link);
        });

        const findStructuredHeader = (link) => {
            let node = link.parentElement;
            while (node && node !== main) {
                if (node.querySelector('h1') && (
                    node.hasAttribute('data-gp-commandbar') ||
                    node.tagName === 'HEADER' ||
                    node.classList.contains('gp-section-hero') ||
                    node.classList.contains('msp-management-page-header') ||
                    node.classList.contains('d-flex')
                )) {
                    return node;
                }
                node = node.parentElement;
            }
            return null;
        };

        const structuredHeader = uniqueCandidates.map(findStructuredHeader).find(Boolean) || null;
        const oldParents = uniqueCandidates.map((link) => link.parentElement);
        uniqueCandidates.forEach((link) => slot.appendChild(link));

        if (structuredHeader) {
            structuredHeader.classList.add('gp-page-commandbar');
            slot.classList.add('gp-page-navigation--inline');
            structuredHeader.prepend(slot);

            oldParents.forEach((parent) => {
                if (parent && parent !== structuredHeader && parent.children.length === 0 && (parent.textContent || '').trim() === '') {
                    parent.remove();
                }
            });
            let titleHeading = null;
            Array.from(structuredHeader.children).forEach((child) => {
                if (child === slot) {
                    return;
                }
                if (child.matches('h1') || child.querySelector('h1')) {
                    titleHeading = child.matches('h1') ? child : child.querySelector('h1');
                    if (child.matches('h1')) {
                        const titleWrapper = document.createElement('div');
                        titleWrapper.className = 'gp-page-commandbar__title';
                        child.replaceWith(titleWrapper);
                        titleWrapper.appendChild(child);
                    } else {
                        child.classList.add('gp-page-commandbar__title');
                    }
                } else {
                    child.classList.add('gp-page-commandbar__actions');
                }
            });
            renderBreadcrumbs(titleHeading);
            return;
        }

        const directContent = Array.from(main.children).find((node) =>
            node instanceof HTMLElement && !['SCRIPT', 'STYLE'].includes(node.tagName)
        );
        const mountPoint = main.classList.contains('d-flex') && directContent ? directContent : main;
        mountPoint.classList.add('gp-page-navigation-host');
        slot.classList.add('gp-page-navigation--overlay');
        mountPoint.prepend(slot);
        renderBreadcrumbs(main.querySelector('h1'));
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPageNavigation, { once: true });
    } else {
        initPageNavigation();
    }
})();
</script>
