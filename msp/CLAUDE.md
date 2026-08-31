# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**MSP - Mercado San Pedro** is a PHP/SQL Server web application for commercial property management and billing. It handles tenant contracts, utility billing (electricity, water, gas), payment collection, and accounting for a shopping center. It is a module within a larger **Portal Grupo Patagual** system.

## Commands

```bash
# Lint a single PHP file
php -l <file.php>

# Lint all PHP files (run before committing)
find . -name "*.php" -print0 | xargs -0 -n1 php -l

# Install/update DB objects (clean environment)
sqlcmd -S <server> -d PORTALGP -E -b -i db/msp_instalar_core.sql

# Reset DB (development only)
sqlcmd -S <server> -d PORTALGP -E -b -i db/msp_limpiar.sql
```

Local runtime via WAMP/Apache at `/portalgp/msp`. The module depends on parent-level files: `../db.php` (PDO SQL Server connection) and `../permisos.php` (RBAC).

There is no automated test suite. Validation is lint + manual flow checks covering: module UI, POST actions, flash messages, and permission/CSRF behavior.

## Architecture

### Module Structure

Modules are folder-based under the repo root. Each module typically contains `index.php` (list/filter), `guardar.php` (create/update), `eliminar.php` (delete), and any import helpers.

**Core modules:**
- `contratos/` — Contract lifecycle: create, update, close, guarantee/deposit handling
- `cobros/` — Monthly and individual billing; document generation (largest module)
- `cobranza/` — Payment recording, extra charges, credit balance adjustments
- `documentos_cobro/` — Billing document list and status tracking
- `contabilidad/` — Aged receivables and general ledger reports
- `control_diario/` — Monthly operational grid (rent + utilities + status per tenant)
- `dashboard/` — KPI metrics and collection analytics

**Supporting CRUD modules:** `arrendatarios/`, `locales/`, `tiendas/`, `medidores/`, `pagos/`

**Catalog/lookup modules:** `comunas/`, `rubros/`, `estados_arrendatarios/`, `estados_locales/`, `estados_tiendas/`

### Key Files

- `bootstrap.php` — All shared helpers and security functions (prefixed `msp2*`)
- `templates/` — Shared UI fragments (`header.php`, `footer.php`, `flash.php`)
- `templates/components/` — Reusable components (`searchable_select.php`, `confirm_action_modal.php`)
- `assets/` — Vanilla JS utilities (toast notifications, CSRF auto-injection, confirmation dialogs)
- `db/` — Schema installation (`msp_instalar_core.sql`), incremental patches (`patch_*.sql`)
- `config/mail.example.php` — SMTP config template; never commit `config/mail.php`

### Service Layer (cobros/)

The `cobros/` module uses an object-oriented service layer:
- `cobros/services/OperacionMensualService.php` — Monthly billing generation
- `cobros/services/DocumentosCobroService.php` — Billing document generation
- `cobros/services/EnvioLotesProgramadosService.php` — Scheduled batch email sending

### Bootstrap Helpers Reference

Security: `msp2RequireAccess($perm)`, `msp2CsrfToken()`, `msp2CsrfVerify($token)`, `msp2CsrfField()`, `msp2SignedToken($scope, $claims)`, `msp2VerifySignedParams($scope, $params)`

Routing/UI: `msp2Url($path)`, `msp2Redirect($path)`, `msp2SetFlash($type, $msg)`, `msp2RenderFlash($flash)`, `msp2RenderCsrfAutoFieldScript()`

Formatting: `msp2Escape($value)`, `msp2FormatoDecimal($value, $decimals)`, `msp2NormalizeText($value)`, `msp2LocalCodeNaturalOrderSql($column)` (sorts codes like "A-1", "B-2a" naturally)

Chilean RUT: `msp2RutSanitize($v)`, `msp2RutIsValid($body, $dv)`, `msp2RutNormalizeDb($v)`, `msp2RutFormatDisplay($v)`

DB utilities: `msp2TableExists()`, `msp2ColumnExists()`, `msp2ProcedureExists()`

### Frontend Stack

Bootstrap 5.3.0 + Bootstrap Icons 1.10.5 (CDN). No frontend build system.

### AJAX Endpoints

Detect with `msp2IsAjaxRequest()` (checks `X-Requested-With: XMLHttpRequest` or `Accept: application/json`). Return JSON with appropriate HTTP status codes (e.g., 419 for CSRF failures).

## Coding Conventions

- `declare(strict_types=1);`, 4-space indentation, early-return guards
- Helper functions use `msp2` prefix; action files use `lowercase_snake_case.php`
- One endpoint/action per file; keep module actions focused
- DB changes require a new `db/patch_<feature>.sql` file

## Commit Guidelines

Format: `<type>(msp/<module>): <short summary>` — types: `feat`, `fix`, `refactor`, `chore`, `docs`

- One commit per solution or bugfix (atomic scope)
- Do not mix unrelated modules in the same commit
- DB + PHP changes in one commit only when inseparable; otherwise split by concern
- **Only commit after explicit confirmation from the user**
