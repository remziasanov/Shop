# CLAUDE.md — Shop (Laravel 13 + Filament 5, hosting Beget)

Single source of requirements: `docs/TZ.html`. Priority on conflicts: section 2 (Beget limits) → section 3 (stack) → everything else.
Progress tracker: `docs/PROGRESS.md` (continue from the first unchecked stage). Decisions log: `docs/DECISIONS.md`. Dev environment: `docs/DEV_ENV.md`.

## Stack
- PHP 8.3 (must also run on 8.4/8.5), Laravel 13, Filament 5 (Livewire 4), Blade + Tailwind CSS 4 + Alpine.js for the storefront.
- MySQL 8.0 in production (code must also work on MySQL 5.7: no CTE, no window functions), SQLite in-memory for tests.
- spatie/laravel-settings (+ Filament plugin), spatie/simple-excel, intervention/image 3 (GD), Pest, Pint, Larastan level 5.
- Version 2.0 (stage 15, PDF export): `barryvdh/laravel-dompdf` is an allowed package (D-079); if the wrapper does not support Laravel 13, use `dompdf/dompdf` directly.
- Packages only from TZ section 3. Any new package requires a justified entry in `docs/DECISIONS.md`.
- Version 2.1 (stages 16–18, TZ part III: mobile, PWA, Telegram bot and Mini App): no new packages; Telegram Bot API via the Laravel HTTP client, webhook only (no `getUpdates`, no daemons); the Mini App is the same storefront in a session-based "Telegram mode" (D-100…D-105, decisions of v2.1 use numbers D-100+).

## Hard constraints (TZ section 2)
- Shared hosting: no Redis/Memcached, no daemons, Supervisor, Docker, Octane, WebSockets, no Node on server, no SPA/Inertia, no Elasticsearch/Meilisearch.
- `CACHE_STORE=file`, `SESSION_DRIVER=file`, `QUEUE_CONNECTION=database`. Background work = cron `schedule:run` + `queue:work --stop-when-empty --max-time=50`. Every job ≤ 40 s, heavy work in chunks.
- Frontend built by Vite in CI; `public/build` ships with the release.
- Money: `decimal(12,2)`, arithmetic via integer kopecks or bcmath — never float.
- Stock changes only through `StockService` inside a transaction with `lockForUpdate()`.
- No secrets or real personal data in the repo, seeders or logs.

## Commands
```bash
composer install && npm ci          # dependencies
php artisan test                    # Pest on SQLite in-memory
vendor/bin/pint --test              # code style (vendor/bin/pint to fix)
vendor/bin/phpstan analyse          # Larastan level 5
npm run build                       # Vite build
service mysql start                 # sandbox MySQL (db shop / user shop / pass shop; tests: shop_test)
DB_CONNECTION=mysql php artisan migrate:fresh --seed   # MySQL portability check
php artisan shop:create-admin       # first admin
php artisan shop:doctor             # environment report
php artisan shop:demo               # demo catalog (stage 1+)
```

## Conventions
- `declare(strict_types=1);` in every PHP file. PHP enums for all enumerations (stored as varchar).
- Business logic lives in `app/Services/*` (DI, unit-tested) — never in Blade, controllers or Filament resources.
- Validation via Form Requests / Filament rules; no mass-assignment holes (`$fillable` explicit).
- All UI texts via `lang/ru` translation files (`__('shop.…')`). Code, comments, commits — English.
- Migrations: portable syntax only (SQLite + MySQL 5.7/8.0), utf8mb4, explicit foreign keys, reversible `down()`.
- Roles: until stage 8 `users.role` (`admin`, `manager`) + Laravel policies; no role packages. From stage 8 (TZ v2.0, D-074) staff roles are configurable: `roles` / `role_permissions` tables, checks only via `PermissionService`, `users.role` is deprecated and removed by the stage 8 migration. Default Manager matrix — TZ 6.13 / 25.4. Customers live in a separate `customers` table with the `customer` guard (D-071), never in `users`.
- Settings: `app/Settings/*` (spatie/laravel-settings), defaults in settings migrations.
- Tests: Pest, `RefreshDatabase`, factories for every model. Each requirement ID gets at least one test where testable.
- Commits: Conventional Commits in English, mention requirement IDs where relevant (e.g. `feat(catalog): category tree (AD-30)`).

## Structure
See TZ 3.1: `app/Enums`, `app/Models`, `app/Services/{Pricing,Sales,Cart,Orders,Stock,Import,Export,Media,Notifications}`, `app/Jobs`, `app/Settings`, `app/Filament`, `app/Http/Controllers/Storefront`, `app/Livewire`, `app/Console/Commands`, `resources/views/storefront`, `examples/`, `.github/workflows/{ci,deploy}.yml`.

## Workflow for Claude sessions

### Model roles
- **Orchestrator (Opus)**: reads the TZ, plans, splits a stage into tasks, makes architecture decisions, reviews every agent's diff, integrates, runs final checks, commits and drives the PR. Does not write large code itself.
- **implementer** (`.claude/agents/implementer.md`, Sonnet): complex tasks — domain services (PriceCalculator, SalesModeResolver, StockService, OrderService, import), migrations and models with relations, Filament resources with logic, Livewire storefront components, business-logic tests, CI/CD workflows.
- **helper** (`.claude/agents/helper.md`, Haiku): simple tasks — `lang/ru` translations, enums, factories and seeders, simple Blade templates, docs, Pint fixes, code search.
- **reviewer** (`.claude/agents/reviewer.md`, Opus, read-only + Bash): reviews each PR; also debugging when Sonnet failed twice.

### Delegation rules
- Every task states: goal, files, TZ requirement IDs, section 2 constraints, which tests must pass. The agent runs the relevant tests and returns the changed files list and test results.
- Run agents in parallel only on disjoint files; otherwise sequentially.
- After each agent: inspect `git diff`, run tests. Haiku failed → hand to Sonnet; Sonnet failed twice → Opus or orchestrator.
- Always pass `model` explicitly in Agent calls.

### Stage cycle (TZ section 13: stages 0–7, 8–15, 16–18)
1. `git checkout main && git pull`, new branch `stage-N-<short>`.
2. List the stage tasks in `docs/PROGRESS.md`, delegate, integrate.
3. Checks (all green): `php artisan test`; `vendor/bin/pint --test`; `vendor/bin/phpstan analyse`; `npm run build`; `php artisan migrate:fresh --seed` on MySQL.
4. Tick items in `docs/PROGRESS.md`; Conventional Commits; push. **Never push to main directly.**
5. Open PR into main via REST: `gh api repos/remziasanov/Shop/pulls -f title=... -f head=... -f base=main -F body=@pr.md` (GraphQL `gh pr` is blocked). Title = stage number and name; body = requirement IDs done, decisions, manual check steps.
6. Review by a separate Opus agent: give it the PR number, `git diff main...HEAD`, stage requirement IDs; it reads TZ/CLAUDE.md itself and runs the tests, does not edit code. Checks: requirement coverage, Beget limits (section 2), security (NF-10…15), SQLite/MySQL migration portability, no secrets, test and code quality. Verdict APPROVE / REQUEST_CHANGES, findings marked blocker / major / minor with file:line. Post as a PR comment: `gh api repos/remziasanov/Shop/issues/<N>/comments -F body=@review.md`.
7. Fix blocker and major via agents and re-review — max 3 rounds. Minor findings go to "Техдолг" in `docs/PROGRESS.md`.
8. Wait for CI: `gh api repos/remziasanov/Shop/commits/<sha>/check-runs`, poll every 30–60 s, max 20 min. Red → fix and go back to step 3. If no checks appear (Actions not running) — note it in the PR and rely on local checks.
9. Merge: `gh api -X PUT repos/remziasanov/Shop/pulls/<N>/merge -f merge_method=squash`, then `git checkout main && git pull`.
10. Next stage.

### Merge rules
- Do not merge if after 3 review rounds blocker/major remain, CI is red, or tests fail — stop and report.
- If GitHub refuses the merge for permission reasons — continue the next stage from the previous stage branch (PR still targets main) and list PRs in merge order in the report.
- No force push, no branch deletion, no repo settings changes, no deploys to Beget.
