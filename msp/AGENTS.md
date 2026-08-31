# Repository Guidelines

## Project Structure & Module Organization
- Core entry points live at the repository root (`index.php`, `msp_menu.php`, `bootstrap.php`).
- Business modules are folder-based (for example `contratos/`, `cobros/`, `tiendas/`, `pagos/`), typically with `index.php`, `guardar.php`, `eliminar.php`, and import helpers.
- Shared UI fragments are in `templates/` and `templates/components/`; client-side helpers are in `assets/`.
- Database schema and incremental updates are in `db/` (`msp_instalar_core.sql`, `patch_*.sql`).
- Configuration templates are in `config/` (`mail.example.php`, `holidays/`). Do not commit local secrets in `config/mail.php`.

## Build, Test, and Development Commands
- `php -l <file.php>`: lint a single PHP file.
- `find . -name "*.php" -print0 | xargs -0 -n1 php -l`: lint all PHP files before opening a PR.
- `sqlcmd -S <server> -d <db> -E -b -i db/msp_instalar_core.sql`: install/update DB objects for a clean environment.
- `sqlcmd -S <server> -d <db> -E -b -i db/msp_limpiar.sql`: reset DB in development only.
- Use WAMP/Apache path `/portalgp/msp` for local runtime; this module depends on parent files (`../db.php`, `../permisos.php`).

## Coding Style & Naming Conventions
- Follow existing PHP style: `declare(strict_types=1);`, 4-space indentation, and early-return guards.
- Keep helper names with `msp2` prefix (`msp2RequireAccess`, `msp2SetFlash`) and action files in lowercase snake_case (`guardar_cargo.php`).
- Keep module actions focused: one endpoint/action per file.

## Testing Guidelines
- No automated test suite is currently committed; validation is lint + manual flow checks.
- For each change, test the affected module UI, POST actions, flash messages, and permission/CSRF behavior.
- For DB changes, add a new `db/patch_<feature>.sql` and test it on a copy of production-like data.

## Commit & Pull Request Guidelines
- Follow Conventional Commits seen in history, e.g. `feat(msp): ...`.
- Recommended format: `<type>(msp/<module>): <short summary>` where type is `feat`, `fix`, `refactor`, `chore`, or `docs`.
- Commit policy for day-to-day work:
  - Create one commit per solution or bugfix (atomic scope).
  - Do not mix unrelated modules in the same commit.
  - If a task has DB + PHP changes, keep them in one commit only when they are inseparable; otherwise split by concern.
  - Prefer multiple small commits over one large mixed commit.
  - Before creating a commit, ask: `¿Comiteamos este cambio ahora o seguimos iterando para revisar flujo/casos?`
  - Only commit after explicit confirmation from the user in the current thread.
- PRs should include: scope, impacted folders/files, DB scripts added/ordered, manual test evidence, and screenshots for UI changes.
