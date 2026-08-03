---
type: byl-erp-work-package
project: BYL ERP
work_package: ERP-ARCH-MIGRATION-001E-B
status: preflight-complete-pending-independent-review
standard_id: LARGE-PROJECT-EXEC-001
standard_version: 1.1
---

# ERP-ARCH-MIGRATION-001E-B — Browser E2E Foundation

## Work Package

```text
ID: ERP-ARCH-MIGRATION-001E-B
TITLE: Browser E2E Foundation
STATUS: PREFLIGHT COMPLETE / PENDING INDEPENDENT REVIEW / IMPLEMENTATION NOT AUTHORIZED
BASELINE: main@493f32ce7bf1be4368a7e9f97de9ecf9f9c9c9d8
PARENT_PACKAGE: ERP-ARCH-MIGRATION-001E
PREDECESSOR: ERP-ARCH-MIGRATION-001E-A MERGED / PASS
MENU_IMPACT: NO
APPROVED_MENU_CHECK: PASS
APPLICATION_CODE_CHANGE: NO
DEPENDENCY_CHANGE: NO
PACKAGE_LOCK_CHANGE: NO
BROWSER_INSTALL: NO
WORKFLOW_CHANGE: NO
MIGRATION: NO
DATABASE_CHANGE: NO
TEST_USER_CREATION: NO
UAT: NO
DEPLOYMENT: NO
GLOBAL_ARCHITECTURE_GATE: FAIL
```

This document freezes the proposed 001E-B implementation boundary. It does not grant any implementation or execution gate.

## Purpose and Architecture Position

001E-B will establish a deterministic real-browser test harness after the accepted Shared UI foundation and before 001E-C adds authentication, permission and App Shell scenarios.

```text
001E-A MERGED / PASS
→ 001E-B Browser E2E Foundation PREFLIGHT COMPLETE / IMPLEMENTATION NOT AUTHORIZED
→ 001E-C WAITING
→ 001F WAITING
```

001E-B owns infrastructure and one non-authenticated smoke proof only. It does not own the 001E-C authentication and authorization scenario suite.

## Audited Toolchain

Evidence collected read-only from the authorized baseline on 2026-08-03:

```text
NODE: v26.5.0
NPM: 11.17.0
PHP_FRAMEWORK: Laravel Framework 13.23.0
VITE_INSTALLED: 8.2.0
TYPESCRIPT_INSTALLED: 6.0.3
VITEST_INSTALLED: 4.1.10
LARAVEL_VITE_PLUGIN_INSTALLED: 3.1.3
PACKAGE_LOCK_VERSION: 3
PACKAGE_JSON_ENGINES: ABSENT
PLAYWRIGHT_PACKAGE: NOT INSTALLED
PLAYWRIGHT_CONFIG: ABSENT
TESTS_E2E_DIRECTORY: ABSENT
GITHUB_ACTIONS_DIRECTORY: ABSENT
CURRENT_BROWSER_E2E: NONE
```

`package.json` and `package-lock.json` were clean and unchanged during this Preflight. `npm ls @playwright/test playwright --depth=0` returned an empty top-level dependency tree.

## Proposed Playwright Version

```text
PROPOSED_DEV_DEPENDENCY: @playwright/test@1.62.1
VERSION_POLICY: exact pin, no caret or tilde
NPM_LATEST_EVIDENCE_AS_OF_2026-08-03: 1.62.1
PACKAGE_ENGINE: Node >=20
REPOSITORY_NODE: 26.5.0
OFFICIAL_NODE_SUPPORT: latest 22.x, 24.x or 26.x
COMPATIBILITY_CONCLUSION: COMPATIBLE WITH CURRENT NODE / INSTALLATION NOT PERFORMED
RECHECK_GATE: npm dist-tag and official system requirements must be rechecked at the authorized implementation Head
```

Official evidence:

- <https://playwright.dev/docs/intro>
- <https://playwright.dev/docs/browsers>
- npm read-only metadata: `npm view @playwright/test version engines dist-tags --json`

The first implementation uses `@playwright/test`, not the lower-level `playwright` library or Vitest Browser Mode.

## Browser Scope and Binary Policy

```text
INITIAL_PROJECTS: chromium only
FIREFOX: DEFERRED
WEBKIT: DEFERRED
BRANDED_CHROME_OR_EDGE: NOT USED
LOCAL_INSTALL_COMMAND_PROPOSAL: PLAYWRIGHT_BROWSERS_PATH=<external-versioned-cache> npx playwright install chromium
CI_INSTALL_COMMAND_PROPOSAL: npx playwright install --with-deps chromium
BROWSER_DOWNLOAD_EXECUTED_IN_PREFLIGHT: NO
```

Chromium-only is sufficient for the foundation smoke proof and reduces download/time surface. Cross-browser expansion belongs to a later separately accepted matrix. Local binaries use a versioned cache outside the repository, for example `${XDG_CACHE_HOME:-$HOME/Library/Caches}/byl-erp/playwright/1.62.1`; no browser path is written to Git. Initial CI does not cache browser binaries because the official Playwright CI guidance says browser caching is generally not recommended; only Chromium is installed per run. See <https://playwright.dev/docs/ci> and <https://playwright.dev/docs/browsers>.

## Repository-external Runtime Boundary

Every run must create one exclusive directory outside the repository:

```text
BYL_E2E_RUN_ROOT=<mktemp directory outside repository>
├── database/e2e.sqlite
├── auth/
├── artifacts/
├── report/
├── logs/
├── pids/
└── secrets/runtime.env
```

Rules:

- resolve the real path and reject any directory inside the Git worktree;
- require a fresh empty directory with owner-only permissions;
- never reuse Dev, Staging, Production or shared test state;
- never write storage state, screenshots, traces, videos, reports, credentials or the database into the repository;
- register cleanup before setup and delete the run root on success, failure, signal or server-start timeout;
- refuse to continue if cleanup ownership or path validation cannot be proven.

## Laravel and Vite Lifecycle

The initial foundation uses a build-first lifecycle rather than a persistent Vite development server:

1. validate environment and external paths;
2. run `npm run build` as a bounded Vite process and wait for exit;
3. prepare the separately authorized disposable database and synthetic fixtures;
4. let Playwright `webServer` own `php artisan serve --host=127.0.0.1 --port=<reserved-port>`;
5. set `reuseExistingServer: false` locally and in CI so an unrelated process cannot satisfy readiness;
6. wait for an explicit local readiness URL;
7. stop Laravel on completion or failure and then clean the external run root.

Vite is therefore build-only in 001E-B; no persistent Vite WebServer and no `public/hot` marker are used. A later live-HMR E2E mode would require a separate `vite.config.js`/Laravel hot-file design and is excluded. Playwright supports owned WebServer lifecycle and multiple servers, but the single Laravel server is the safer frozen baseline: <https://playwright.dev/docs/test-webserver>.

## Disposable Database Strategy

```text
PRIMARY_STRATEGY: fresh repository-external SQLite file per run
DB_CONNECTION: sqlite
DB_DATABASE: <BYL_E2E_RUN_ROOT>/database/e2e.sqlite
SESSION_DRIVER: database
CACHE_STORE: array
QUEUE_CONNECTION: sync
APP_ENV: testing
ERP_LOCAL_ADMIN_BYPASS: false
SHARED_DATABASE_ALLOWED: NO
MYSQL: optional future isolated parity run only with separate authorization
```

The runner must compare the resolved SQLite path with `BYL_E2E_RUN_ROOT`, require a nonexistent or empty file and reject URLs, sockets, network hosts and the repository database path. Migration execution is necessary to create the disposable schema but remains blocked until the Owner separately grants `DISPOSABLE_E2E_MIGRATION_EXECUTION_AUTHORIZATION`. No Migration file change is proposed.

## Synthetic Test Users

The implementation proposes a test-only PHP bootstrap at `tests/e2e/support/provision-users.php` that:

- boots the current application only after the external SQLite safety gate passes;
- requires `APP_ENV=testing`, `BYL_E2E_DISPOSABLE=true` and the validated external database path;
- creates only synthetic active-authorized, active-unauthorized and inactive users needed by later 001E-C;
- creates required role/permission fixtures inside the disposable database only;
- receives generated per-run passwords through inherited environment variables, never CLI arguments;
- hashes passwords with Laravel `Hash`; never prints secrets, hashes, Session values or full records;
- refuses to run against MySQL, a repository database, a nonempty shared database or a non-testing environment.

Destruction is whole-run cleanup: stop servers, delete the disposable SQLite file and delete `BYL_E2E_RUN_ROOT`. No user is created during this Preflight.

## Session, Cookie, CSRF and Password Boundaries

- force `ERP_LOCAL_ADMIN_BYPASS=false` so E2E tests exercise real Fortify behavior;
- use generated synthetic credentials scoped to one disposable run;
- never log passwords or confirmation values;
- never persist cookies, Session IDs, CSRF tokens, authorization headers or storage state outside the external run root;
- never place secrets in command-line arguments, screenshots, test titles, attachments or reporter annotations;
- default each test to a fresh browser context; storage-state reuse is prohibited in 001E-B;
- block all external network requests; only loopback Laravel/assets are allowed;
- ensure logs redact `Cookie`, `Set-Cookie`, `Authorization`, CSRF fields, passwords and request bodies.

Official Playwright guidance warns that storage-state files can contain sensitive cookies and headers capable of impersonation: <https://playwright.dev/docs/auth>.

## Artifact Policy

Initial frozen configuration:

```text
SCREENSHOT: off
TRACE: off
VIDEO: off
HTML_REPORT_OPEN: never
OUTPUT_DIR: <BYL_E2E_RUN_ROOT>/artifacts
REPORT_DIR: <BYL_E2E_RUN_ROOT>/report
CI_ARTIFACT_UPLOAD: NONE
AUTHENTICATION_SCREENSHOT_UPLOAD: PROHIBITED
AUTHENTICATION_TRACE_UPLOAD: PROHIBITED
AUTHENTICATION_VIDEO_UPLOAD: PROHIBITED
STORAGE_STATE_UPLOAD: PROHIBITED
```

The stricter initial policy replaces the parent's provisional retain-on-failure idea until sanitization is proven. Trace Viewer exposes request headers, response headers and request/response bodies, so authentication traces are sensitive by design. See <https://playwright.dev/docs/trace-viewer>.

## Artifact Sensitive-data Scan Contract

`tests/e2e/support/artifact-scan.mjs` is proposed as a fail-closed scanner. Before any future upload it must:

1. resolve and confine every candidate to the external artifact root;
2. reject symlinks, device files, sockets, unknown extensions and oversized files;
3. recursively scan filenames and decoded text for password/confirmation, authorization, bearer, cookie, set-cookie, session, XSRF/CSRF, app/database keys, emails outside the approved synthetic domain and configured secret values;
4. inspect ZIP entries rather than trusting archive filenames; inability to inspect an archive is FAIL;
5. reject screenshots/videos for authentication-tagged tests because deterministic pixel masking is not yet implemented;
6. reject storage-state, HAR, database, `.env`, log and runtime-secret files unconditionally;
7. write only a count/hash manifest with no matched secret text;
8. return nonzero on any match, unreadable candidate or unsupported format;
9. require independent tests with synthetic canary secrets in plain text, JSON, HTML report data and ZIP entries.

Passing the scanner is necessary but not sufficient for upload. Upload policy requires a later Owner decision after independent review. Initial 001E-B CI uploads nothing.

## Proposed `tests/e2e` Structure

```text
tests/e2e/
├── README.md
├── foundation/
│   └── smoke.spec.ts
└── support/
    ├── artifact-scan.mjs
    ├── artifact-scan.test.mjs
    ├── e2e-environment.mjs
    ├── provision-users.php
    └── run-e2e.mjs
```

The smoke test proves only loopback Laravel/Vite-built assets/Inertia integration, the public login page, no browser console error and no external request. Authentication scenarios are deferred to 001E-C.

## `playwright.config.ts` Contract

The future config must:

- require validated `BYL_E2E_RUN_ROOT` and `BYL_E2E_BASE_URL`;
- use `testDir: tests/e2e`, one `chromium` project and deterministic desktop viewport;
- use `workers: 1` and `fullyParallel: false` initially;
- use zero retries locally and at most one retry in separately authorized CI;
- use `forbidOnly: true` in CI;
- use external output/report directories;
- set screenshot/trace/video to `off`;
- set HTML reporter `open: never` plus line reporter;
- set `reuseExistingServer: false`;
- start only the loopback Laravel server after the runner completes build/database preparation;
- reject non-loopback base URLs and all external requests;
- never load real `.env` secrets into reporter metadata.

## Proposed npm Scripts and Commands

Proposed `package.json` additions, not yet authorized:

```text
test:e2e                 node tests/e2e/support/run-e2e.mjs
test:e2e:contracts       node --test tests/e2e/support/artifact-scan.test.mjs
test:e2e:direct          playwright test --project=chromium
typecheck:e2e            tsc --noEmit -p tsconfig.e2e.json
```

Proposed local sequence after all gates are granted:

```text
npm ci
PLAYWRIGHT_BROWSERS_PATH=<external-versioned-cache> npx playwright install chromium
npm run test:e2e:contracts
npm run typecheck:e2e
npm run test:e2e
```

`npm init playwright` is prohibited because it can create unreviewed example tests and Workflow files.

## Candidate GitHub Actions Design

No Workflow is authorized or created. Candidate path: `.github/workflows/browser-e2e.yml`.

Candidate job:

1. Ubuntu runner; checkout exact reviewed commit;
2. set up supported Node 26 and project PHP version;
3. `composer install` and `npm ci`;
4. install only Chromium with `npx playwright install --with-deps chromium`;
5. create an exclusive external temp root and ephemeral environment;
6. run existing PHP/Vitest/typecheck/build gates;
7. run E2E contract scan and Chromium foundation smoke;
8. upload no E2E artifacts in the initial implementation;
9. always clean servers, database, secrets and temporary files.

Browser binaries are not cached initially. Workflow concurrency cancels superseded PR runs, permissions are read-only, external network is blocked where runner controls allow, and secrets are not exposed to forked PRs.

```text
WORKFLOW_REQUIRED_FOR_LOCAL_IMPLEMENTATION: NO
WORKFLOW_REQUIRED_FOR_MERGE_GATE: OWNER DECISION
WORKFLOW_CHANGE_AUTHORIZATION: REQUIRED / NOT GRANTED
```

## Exact Proposed File Delta

Base 001E-B implementation, all requiring separate authorization:

```text
MODIFY package.json
MODIFY package-lock.json
ADD playwright.config.ts
ADD tsconfig.e2e.json
ADD tests/e2e/README.md
ADD tests/e2e/foundation/smoke.spec.ts
ADD tests/e2e/support/artifact-scan.mjs
ADD tests/e2e/support/artifact-scan.test.mjs
ADD tests/e2e/support/e2e-environment.mjs
ADD tests/e2e/support/provision-users.php
ADD tests/e2e/support/run-e2e.mjs
```

Optional, separately gated:

```text
ADD .github/workflows/browser-e2e.yml
```

No `.gitignore` delta is required because all runtime/auth/artifact paths are repository-external. No application, CSS, menu, Vite config, PHP application, route, Migration or database configuration file is proposed.

## Test Matrix

| Contract | Evidence required |
|---|---|
| Environment fail closed | missing run root, repo-contained path, non-loopback URL, real/shared DB and real environment all fail before server/database action |
| Cleanup | success, test failure, server timeout and signal each stop processes and remove the external run root |
| Artifact scanner | clean fixture passes; credential/header/cookie/session/CSRF/canary/ZIP/symlink/unknown-file fixtures fail without echoing the secret |
| Database guard | only new external SQLite accepted; repository SQLite, MySQL and nonempty shared targets rejected |
| User bootstrap guard | only testing + disposable marker + validated DB accepted; no secret output |
| Web lifecycle | build succeeds, Laravel readiness succeeds, occupied port fails, unrelated existing server is never reused |
| Browser scope | only Chromium project exists; Firefox/WebKit are absent |
| Smoke | `/login` renders with expected title/fields, assets load, console has no error and external requests are zero |
| Artifact policy | screenshot/trace/video off; output/report paths external; CI upload absent |
| Existing regression | Composer validation, full PHP, full Vitest, typecheck, build and diff check pass |

001E-C later owns valid/invalid/inactive login, logout Session invalidation, permissions, 403/404, Shell interaction and authenticated accessibility flows.

## Rollback

- revert the single bounded 001E-B implementation commit;
- remove the exact dependency and lock-file entries plus the listed config/support/test files;
- if separately created, revert the Workflow independently;
- stop any remaining loopback process and delete only the validated external run root and versioned browser cache selected by the Owner;
- no application, menu, schema or persistent data rollback exists.

## Risks

- a weak path check could run migrations against a shared database;
- Vite/Laravel lifecycle races can create false readiness;
- retries can hide state leakage or flakiness;
- traces, videos, screenshots, HTML reports and storage state can expose credentials or Sessions;
- ZIP scanners can miss compressed secrets unless entry inspection fails closed;
- Node/Playwright version drift can change browser binaries and lock-file delta;
- Chromium-only cannot prove Firefox/WebKit parity;
- local-only E2E does not protect merges without a separately authorized Workflow;
- macOS local success cannot substitute for an independently reviewed Linux CI result.

## Acceptance Criteria

- exact approved dependency and lock delta only;
- Chromium-only project and external runtime boundary;
- disposable-database safety gates execute before Migration or provisioning;
- browser/server/cleanup and artifact scan contracts are automated;
- foundation smoke passes without authentication or external traffic;
- no auth artifacts are uploaded;
- no application, menu, route, Migration file, business feature, 001E-C or 001F change;
- all separately required Owner gates are recorded and enforced.

## Owner Gates

```text
DEPENDENCY_CHANGE_AUTHORIZATION: REQUIRED / NOT GRANTED
PACKAGE_LOCK_CHANGE_AUTHORIZATION: REQUIRED / NOT GRANTED
BROWSER_INSTALL_AUTHORIZATION: REQUIRED / NOT GRANTED
WORKFLOW_CHANGE_AUTHORIZATION: REQUIRED / NOT GRANTED
DISPOSABLE_E2E_MIGRATION_EXECUTION_AUTHORIZATION: REQUIRED / NOT GRANTED
IMPLEMENTATION_AUTHORIZATION: NOT GRANTED
MERGE_AUTHORIZATION: NOT GRANTED
UAT_AUTHORIZATION: NOT GRANTED
DEPLOYMENT_AUTHORIZATION: NOT GRANTED
```

## Confirmed Implementation Estimate

```text
PREFLIGHT_AND_SCOPE: 1–2h; planning value 1.5h
CORE_IMPLEMENTATION: 5–7h; planning value 6h
TEST_AND_VALIDATION: 4–6h; planning value 5h
REMEDIATION_BUFFER: 2–3h; planning value 2.5h
ESTIMATED_EFFECTIVE_HOURS: 15h
ESTIMATE_RANGE: 12–18 effective hours
ESTIMATE_BASIS: exact dependency/config/support/file boundary; isolated lifecycle and artifact-security contracts; Chromium only
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
DISPOSABLE_MIGRATION_EXECUTION: INCLUDED ONLY AFTER SEPARATE AUTHORIZATION
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```

## PROJECT POSITION

```text
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate → MVP1 Manual Fulfillment / Tencent Docs Replacement → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Customer / Lead Pilot → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Dev UAT and Production Hardening
CURRENT_TRACK_PHASE_MAP: 001A MERGED / PASS → 001B MERGED / PASS → 001C MERGED / PASS → 001D COMPLETE / PASS → 001E-A MERGED / PASS → 001E-B PREFLIGHT COMPLETE / PENDING REVIEW / IMPLEMENTATION NOT AUTHORIZED → 001E-C WAITING → 001F WAITING
COMPLETED_PHASES: 001A, 001B, 001C, 001D, 001E-A
CURRENT_PHASE: 001E-B Browser E2E Foundation Preflight review
CURRENT_WORK_PACKAGE: ERP-ARCH-MIGRATION-001E-B
PACKAGE_STATUS: PREFLIGHT COMPLETE / PENDING INDEPENDENT REVIEW / IMPLEMENTATION NOT AUTHORIZED
WAITING_PHASES: 001E-B implementation gates, 001E-C, 001F
BASELINE: main@493f32ce7bf1be4368a7e9f97de9ecf9f9c9c9d8
GITHUB_STATE: PR #14, #15, #16 and #17 MERGED; governance Draft PR pending creation
GLOBAL_GATE: FAIL
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: independent Preflight review, then separate Owner authorization for every frozen 001E-B gate
EVIDENCE_AS_OF: 2026-08-03; repository/toolchain audit, npm read-only metadata and official Playwright documentation
```

## ROADMAP HOURS

```text
CURRENT_PACKAGE_ESTIMATE: 12–18h; planning value 15h; confirmed future 001E-B implementation estimate, not authorized
KNOWN_CURRENT_TRACK_REMAINING: 24–38h; planning value 31h for non-overlapping 001E-B/C parent allocation; excludes unknown 001F
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE; 001E-C and unestimated 001F remain incomplete
MVP1_TOTAL_ESTIMATE: 122–190h total estimate
MVP1_PLANNING_BASELINE: 166h total planning value
MVP1_MAXIMUM_ENVELOPE: 192h maximum total envelope
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; accepted actual ledger and architecture/MVP overlap map remain incomplete
POST_MVP_KNOWN_INCREMENT: approximately 68–120h incremental beyond MVP1
LONG_TERM_CUMULATIVE_RANGE: 235–402h through 1,819–3,146h; cumulative non-additive horizons
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; project-wide ledger remains incomplete
ESTIMATE_CONFIDENCE: MEDIUM for frozen Chromium-only 001E-B scope; LOW for Architecture Gate remaining
ESTIMATE_AS_OF: 2026-08-03
ESTIMATE_METHOD: exact local dependency/toolchain evidence, file delta, lifecycle/security contracts and non-overlapping parent allocation
```

## ESTIMATED CODEX HOURS

```text
PREFLIGHT_AND_SCOPE: 1–2h; planning value 1.5h
CORE_EXECUTION: 5–7h; planning value 6h
TEST_AND_VALIDATION: 4–6h; planning value 5h
REMEDIATION_BUFFER: 2–3h; planning value 2.5h
ESTIMATED_EFFECTIVE_HOURS: 15h
ESTIMATE_RANGE: 12–18 effective hours
ESTIMATE_BASIS: confirmed future 001E-B implementation estimate; no implementation authorization granted
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED FROM THIS PREFLIGHT; disposable execution requires separate authorization
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```

## Verdict

```text
FINAL_STATUS: ERP_ARCH_MIGRATION_001E_B_PREFLIGHT_COMPLETE_PENDING_INDEPENDENT_REVIEW
```
