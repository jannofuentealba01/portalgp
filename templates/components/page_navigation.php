<?php
declare(strict_types=1);

if (defined('GP_PAGE_NAVIGATION_RENDERED')) {
    return;
}
define('GP_PAGE_NAVIGATION_RENDERED', true);

/**
 * Resolve an internal MSP return target without accepting external URLs or
 * paths outside the module.
 *
 * @return array{url:string,label:string,value:string}|null
 */
function gpPageNavigationContextReturn(): ?array
{
    $raw = $_GET['return_to'] ?? null;
    if (!is_string($raw)) {
        return null;
    }

    $raw = html_entity_decode(trim($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($raw === '' || strlen($raw) > 1000 || preg_match('/[\\x00-\\x1F\\x7F\\\\]/', $raw) === 1) {
        return null;
    }

    $parts = parse_url($raw);
    if ($parts === false
        || isset($parts['scheme'])
        || isset($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['fragment'])) {
        return null;
    }

    $relativePath = rawurldecode(ltrim((string) ($parts['path'] ?? ''), '/'));
    foreach (['portalgp/msp/', 'msp/'] as $prefix) {
        if (str_starts_with($relativePath, $prefix)) {
            $relativePath = substr($relativePath, strlen($prefix));
            break;
        }
    }

    if ($relativePath === ''
        || preg_match('#^(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\\.php$#', $relativePath) !== 1) {
        return null;
    }

    $mspRoot = realpath(dirname(__DIR__, 2) . '/msp');
    $target = $mspRoot === false
        ? false
        : realpath($mspRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
    if ($mspRoot === false
        || $target === false
        || !is_file($target)
        || ($target !== $mspRoot && !str_starts_with($target, $mspRoot . DIRECTORY_SEPARATOR))) {
        return null;
    }

    $query = [];
    if (isset($parts['query']) && $parts['query'] !== '') {
        parse_str((string) $parts['query'], $parsedQuery);
        foreach ($parsedQuery as $key => $value) {
            if (!is_string($key)
                || preg_match('/^[A-Za-z0-9_-]+$/', $key) !== 1
                || (!is_string($value) && !is_numeric($value) && !is_bool($value))) {
                continue;
            }
            $query[$key] = mb_substr((string) $value, 0, 300, 'UTF-8');
        }
    }

    $encodedPath = implode('/', array_map('rawurlencode', explode('/', $relativePath)));
    $queryString = $query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $value = $relativePath . $queryString;

    $labels = [
        'pendientes/index.php' => 'Volver a pendientes',
        'cierre/index.php' => 'Volver a término y cierre',
        'garantias/index.php' => 'Volver a Garantías',
        'contratos/index.php' => 'Volver a contratos',
        'contratos/ficha.php' => 'Volver a ficha',
        'cobranza/gestionar.php' => 'Volver a Gestión de Cobranza',
        'documentos_tienda/index.php' => 'Volver al lote',
    ];
    $sectionLabels = [
        'arrendatarios' => 'arrendatarios',
        'catalogos' => 'catálogos',
        'cobranza' => 'Cobranza',
        'cobros' => 'Cobros',
        'contabilidad' => 'Contabilidad',
        'contratos' => 'contratos',
        'documentos_cobro' => 'Documentos de cobro',
        'documentos_tienda' => 'Documentos por tienda',
        'garantias' => 'Garantías',
        'locales' => 'locales',
        'pagos' => 'Pagos',
        'pendientes' => 'pendientes',
        'tesoreria' => 'Tesorería',
        'tiendas' => 'tiendas',
    ];
    $section = explode('/', $relativePath, 2)[0] ?? '';
    $label = $labels[$relativePath]
        ?? ('Volver a ' . ($sectionLabels[$section] ?? ucfirst(str_replace('_', ' ', $section))));

    return [
        'url' => '/portalgp/msp/' . $encodedPath . $queryString,
        'label' => $label,
        'value' => $value,
    ];
}

/**
 * Central navigation metadata for page-level back links.
 *
 * @return array{current:string,parent_url:string,parent_label:string,return_url:string,return_label:string,return_to:string}
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
    $contextReturn = null;

    if (str_starts_with($current, 'msp/')) {
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
        if ($moduleRelative === 'msp_menu.php') {
            $parentUrl = '/portalgp/index.php';
            $parentLabel = 'Volver al menú principal';
        } elseif ($moduleRelative === 'catalogo_menu.php') {
            $parentUrl = '/portalgp/msp/msp_menu.php';
            $parentLabel = 'Volver al menú MSP';
        } elseif (($segments[0] ?? '') === 'catalogos') {
            $parentUrl = '/portalgp/msp/catalogo_menu.php';
            $parentLabel = 'Volver a catálogos';
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
        } else {
            $parentUrl = '/portalgp/msp/msp_menu.php';
            $parentLabel = 'Volver al menú MSP';
        }
        $contextReturn = gpPageNavigationContextReturn();
        if ($contextReturn !== null
            && strtok($contextReturn['value'], '?') === $moduleRelative) {
            $contextReturn = null;
        }
    } elseif (str_starts_with($current, 'ct/')) {
        $moduleRelative = substr($current, 3);
        $segments = array_values(array_filter(explode('/', $moduleRelative), static fn (string $part): bool => $part !== ''));
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
        } else {
            $parentUrl = '/portalgp/ct/ct_menu.php';
            $parentLabel = 'Volver al menú CT';
        }
    } elseif (str_starts_with($current, 'sistema/gestion/')) {
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
        'return_url' => (string) ($contextReturn['url'] ?? ''),
        'return_label' => (string) ($contextReturn['label'] ?? ''),
        'return_to' => (string) ($contextReturn['value'] ?? ''),
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
        if (typeof metadata.current === 'string' && metadata.current.startsWith('msp/')) {
            document.body.classList.add('gp-module-msp');
            document.body.classList.add(`gp-page-${metadata.current.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')}`);
        }

        const removeRedundantContext = () => {
            main.querySelectorAll([
                '.gp-breadcrumbs',
                '.breadcrumb',
                '.section-kicker',
                '.gp-section-hero__kicker',
                '.cc-crumb',
                '[data-gp-redundant-context]'
            ].join(',')).forEach((node) => {
                if (!node.closest('.modal, [role="dialog"]')) {
                    node.remove();
                }
            });
            main.querySelectorAll('p').forEach((node) => {
                const text = (node.textContent || '').replace(/\s+/g, ' ').trim();
                if (/^MSP\s*(?:\/|›|$)/i.test(text)
                    && !node.closest('.modal, [role="dialog"]')) {
                    node.remove();
                }
            });
        };
        removeRedundantContext();

        const contextReturnUrl = typeof metadata.return_url === 'string' ? metadata.return_url.trim() : '';
        const contextReturnLabel = typeof metadata.return_label === 'string' ? metadata.return_label.trim() : '';
        const contextReturnValue = typeof metadata.return_to === 'string' ? metadata.return_to.trim() : '';

        if (contextReturnValue !== '') {
            main.querySelectorAll('form').forEach((form) => {
                if (!(form instanceof HTMLFormElement)
                    || !['get', 'post'].includes(form.method.toLowerCase())) {
                    return;
                }
                let input = form.querySelector('input[name="return_to"]');
                if (!(input instanceof HTMLInputElement)) {
                    input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'return_to';
                    form.appendChild(input);
                }
                input.value = contextReturnValue;
            });
        }

        let candidates = Array.from(main.querySelectorAll('a[href]')).filter((link) => {
            if (link.closest('.modal, [role="dialog"], .offcanvas, nav[aria-label*="agin"]')) {
                return false;
            }
            const text = (link.textContent || '').replace(/\s+/g, ' ').trim();
            return link.hasAttribute('data-gp-page-back') || /^(?:←\s*)?(?:volver|regresar|atrás|atras)(?:\s|$)/i.test(text);
        });

        if (contextReturnUrl !== '') {
            const contextCandidate = candidates.find((link) => {
                try {
                    const candidateUrl = new URL(link.href, window.location.origin);
                    const returnUrl = new URL(contextReturnUrl, window.location.origin);
                    return candidateUrl.pathname === returnUrl.pathname && candidateUrl.search === returnUrl.search;
                } catch (error) {
                    return false;
                }
            }) || candidates[0] || document.createElement('a');
            contextCandidate.href = contextReturnUrl;
            contextCandidate.textContent = contextReturnLabel || 'Volver';
            contextCandidate.setAttribute('data-gp-page-back-primary', 'context');
            candidates = [contextCandidate, ...candidates.filter((link) => link !== contextCandidate)];
        }

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
            const key = link.href;
            if (seen.has(key)) {
                link.remove();
                return;
            }
            seen.add(key);
            link.className = 'btn btn-outline-secondary btn-sm gp-page-return';
            link.removeAttribute('style');
            link.setAttribute('data-gp-page-back', 'true');
            const linkLabel = (link.textContent || '').replace(/^\s*←\s*/, '').replace(/\s+/g, ' ').trim() || 'Volver';
            link.setAttribute('title', linkLabel);
            link.setAttribute('aria-label', linkLabel);

            const icon = document.createElement('i');
            icon.className = 'bi bi-arrow-left gp-page-back__icon';
            icon.setAttribute('aria-hidden', 'true');
            const label = document.createElement('span');
            label.className = 'gp-page-back__label';
            label.textContent = linkLabel;
            link.replaceChildren(icon, label);
            uniqueCandidates.push(link);
        });

        const primaryCandidate = uniqueCandidates.find((link) => link.hasAttribute('data-gp-page-back-primary'))
            || uniqueCandidates[0];
        if (!primaryCandidate) {
            return;
        }
        primaryCandidate.classList.add('gp-page-back');
        uniqueCandidates.filter((link) => link !== primaryCandidate).forEach((link) => {
            link.classList.add('gp-page-return--secondary');
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

        const pageHeading = Array.from(main.querySelectorAll('h1')).find((heading) =>
            !heading.closest('.modal, [role="dialog"]')
        ) || null;
        let structuredHeader = findStructuredHeader(primaryCandidate);
        if (!structuredHeader && pageHeading) {
            const inferredHeader = pageHeading.closest('[data-gp-commandbar], header, .gp-section-hero, .msp-management-page-header, .d-flex');
            if (inferredHeader && inferredHeader !== main) {
                structuredHeader = inferredHeader;
            } else {
                structuredHeader = document.createElement('header');
                structuredHeader.setAttribute('data-gp-commandbar', 'inferred');
                pageHeading.before(structuredHeader);
                structuredHeader.appendChild(pageHeading);
            }
        }
        const oldParent = primaryCandidate.parentElement;
        slot.appendChild(primaryCandidate);

        if (structuredHeader) {
            structuredHeader.classList.add('gp-page-commandbar');
            structuredHeader.setAttribute('data-gp-commandbar-ready', 'true');
            slot.classList.add('gp-page-navigation--inline');
            structuredHeader.prepend(slot);

            if (oldParent && oldParent !== structuredHeader && !structuredHeader.contains(oldParent)) {
                const remaining = Array.from(oldParent.childNodes).filter((node) => {
                    return node.nodeType !== Node.TEXT_NODE || (node.textContent || '').trim() !== '';
                });
                if (remaining.length > 0) {
                    let actionHost = Array.from(structuredHeader.children).find((child) => child.classList.contains('gp-page-commandbar__actions'));
                    if (!actionHost) {
                        actionHost = document.createElement('div');
                        actionHost.className = 'gp-page-commandbar__actions';
                        structuredHeader.appendChild(actionHost);
                    }
                    remaining.forEach((node) => actionHost.appendChild(node));
                }
                let emptyNode = oldParent;
                while (emptyNode && emptyNode !== main && emptyNode !== structuredHeader) {
                    const parent = emptyNode.parentElement;
                    if (emptyNode.children.length === 0 && (emptyNode.textContent || '').trim() === '') {
                        emptyNode.remove();
                        emptyNode = parent;
                    } else {
                        break;
                    }
                }
            }
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
            return;
        }

        const directContent = Array.from(main.children).find((node) =>
            node instanceof HTMLElement && !['SCRIPT', 'STYLE'].includes(node.tagName)
        );
        const mountPoint = main.classList.contains('d-flex') && directContent ? directContent : main;
        mountPoint.classList.add('gp-page-navigation-host');
        slot.classList.add('gp-page-navigation--overlay');
        mountPoint.prepend(slot);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPageNavigation, { once: true });
    } else {
        initPageNavigation();
    }
})();
</script>
<script>
(() => {
    const initMspFormSystem = () => {
        if (!document.body.classList.contains('gp-module-msp')) {
            return;
        }

        const main = document.querySelector('main');
        if (!main) {
            return;
        }

        main.querySelectorAll('form').forEach((form) => {
            if (!(form instanceof HTMLFormElement)
                || form.closest('.modal, [role="dialog"], .offcanvas')
                || form.dataset.gpFilter === 'off'
                || form.method.toLowerCase() !== 'get') {
                return;
            }

            const controls = Array.from(form.elements).filter((control) => {
                if (!(control instanceof HTMLInputElement || control instanceof HTMLSelectElement)) {
                    return false;
                }
                return control.type !== 'hidden' && control.type !== 'submit' && control.type !== 'button';
            });
            if (controls.length >= 2 || controls.some((control) => control instanceof HTMLInputElement && control.type === 'search')) {
                form.classList.add('gp-filter-bar');
            }

            const secondaryFields = Array.from(form.querySelectorAll('.gp-secondary-filter-field'));
            if (secondaryFields.length === 0 || form.dataset.gpSecondaryReady === 'true') {
                return;
            }
            form.dataset.gpSecondaryReady = 'true';

            const hasActiveValue = secondaryFields.some((field) => Array.from(field.querySelectorAll('input, select')).some((control) => {
                if (control instanceof HTMLInputElement && (control.type === 'checkbox' || control.type === 'radio')) {
                    return control.checked !== (control.dataset.gpDefault === 'checked');
                }
                if (!(control instanceof HTMLInputElement || control instanceof HTMLSelectElement)) {
                    return false;
                }
                const defaultValue = control.dataset.gpDefault ?? '';
                return String(control.value ?? '') !== defaultValue;
            }));

            let expanded = hasActiveValue;
            const actions = form.querySelector('[data-gp-filter-actions]') || form;
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-outline-secondary gp-more-filters-toggle';
            button.innerHTML = '<i class="bi bi-sliders" aria-hidden="true"></i><span>Más filtros</span>';

            const render = () => {
                secondaryFields.forEach((field) => { field.hidden = !expanded; });
                button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                const label = button.querySelector('span');
                if (label) label.textContent = expanded ? 'Menos filtros' : 'Más filtros';
            };
            button.addEventListener('click', () => {
                expanded = !expanded;
                render();
                if (expanded) {
                    secondaryFields[0]?.querySelector('input, select, button')?.focus();
                }
            });
            actions.prepend(button);
            render();
        });

        main.querySelectorAll('.alert').forEach((alert) => {
            if (!alert.hasAttribute('role')) {
                alert.setAttribute('role', alert.classList.contains('alert-danger') || alert.classList.contains('alert-warning') ? 'alert' : 'status');
            }
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMspFormSystem, { once: true });
    } else {
        initMspFormSystem();
    }
})();
</script>
