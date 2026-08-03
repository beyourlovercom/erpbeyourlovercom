---
type: byl-erp-work-package
project: BYL ERP
work_package: ERP-ARCH-MIGRATION-001E-B
status: merged-pass-complete-closed
standard_id: LARGE-PROJECT-EXEC-001
standard_version: 1.1
---

# ERP-ARCH-MIGRATION-001E-B — Browser E2E Foundation

## Work Package

```text
ID: ERP-ARCH-MIGRATION-001E-B
TITLE: Browser E2E Foundation
STATUS: MERGED / PASS / COMPLETE / CLOSED
PR: #21
ACCEPTED_HEAD: 3b67586cafd2b4af3e248e3079719cb73b4e4163
MAIN_SQUASH_COMMIT: 4b9fbb0deacdfc02439fb947398b87c148602c75
BASELINE: main@05290700afc94153378c0ca4fafe0c69eab80544
PARENT_PACKAGE: ERP-ARCH-MIGRATION-001E
PREDECESSOR: ERP-ARCH-MIGRATION-001E-A MERGED / PASS
MENU_IMPACT: NO
APPROVED_MENU_CHECK: PASS
APPLICATION_CODE_CHANGE: NO
DEPENDENCY_CHANGE: YES / exact @playwright/test@1.62.1 only
PACKAGE_LOCK_CHANGE: YES / exact dependency resolution only
BROWSER_INSTALL: YES / Chromium Headless Shell in repository-external versioned cache
WORKFLOW_CHANGE: NO
MIGRATION_FILE_CHANGE: NO
MIGRATION_EXECUTION: DISPOSABLE EXTERNAL SQLITE ONLY
PERSISTENT_DATABASE_CHANGE: NO
TEST_USER_CREATION: DISPOSABLE SYNTHETIC USERS ONLY
UAT: NO
DEPLOYMENT: NO
GLOBAL_ARCHITECTURE_GATE: FAIL
```

## CURRENT CLOSED STATUS

PR #21 is MERGED / PASS / COMPLETE / CLOSED. Accepted Head: `3b67586cafd2b4af3e248e3079719cb73b4e4163`; Main Squash Commit: `4b9fbb0deacdfc02439fb947398b87c148602c75`. The original implementation baseline remains `main@05290700afc94153378c0ca4fafe0c69eab80544`; the later main commit is not this package's original baseline. Current Owner Gates have no pending 001E-B authorization item. 001F is ACTIVE / PREFLIGHT REVIEWED; UAT and deployment are NO.

## HISTORICAL RECORD — Preflight, Authorization, Review and Remediation

The following Preflight, Owner Gate, Review and Remediation material is historical evidence and does not describe the current package state.

## Purpose and Architecture Position

001E-B will establish a deterministic real-browser test harness after the accepted Shared UI foundation and before 001E-C adds authentication, permission and App Shell scenarios.

```text
001E-A MERGED / PASS
→ 001E-B Browser E2E Foundation MERGED / PASS / CLOSED
→ 001E-C MERGED / PASS / CLOSED
→ 001F ACTIVE / PREFLIGHT REVIEWED
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

Independent-review remediation re-ran the exact read-only Registry queries against `https://registry.npmjs.org/`:

```text
NPM_QUERY_TIME_UTC: 2026-08-03T06:25:50Z
REGISTRY: https://registry.npmjs.org/
PACKAGE_METADATA_VERSION: 1.62.1
DIST_TAG_LATEST: 1.62.1
ENGINES_NODE: >=20
REGISTRY_LAST_MODIFIED: 2026-08-03T06:18:52.127Z
PLAYWRIGHT_1_62_1_PUBLISHED_AT: 2026-07-30T16:36:55.324Z
PLAYWRIGHT_1_62_1_EXACT_RESOLUTION: PASS / "1.62.1"
PLAYWRIGHT_1_62_0_PUBLISHED_AT: 2026-07-24T21:57:02.325Z
PLAYWRIGHT_1_62_0_EXACT_RESOLUTION: PASS / "1.62.0"
FINAL_PROPOSED_PLAYWRIGHT_VERSION: exact @playwright/test@1.62.1
```

The official Microsoft Playwright GitHub release independently records `v1.62.1` as published at `2026-07-30T16:35:55Z`: <https://github.com/microsoft/playwright/releases/tag/v1.62.1>. A public documentation page that still displays `1.62.0` is a separately deployed documentation snapshot or release presentation and can lag a patch release. It is not package-resolution evidence. The npm Registry `latest` tag, exact-version endpoint and official GitHub release agree that `1.62.1` is published and resolvable. No dependency or browser binary was installed while collecting this evidence.

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

The runner compares the resolved SQLite path with `BYL_E2E_RUN_ROOT`, requires a new empty regular file and rejects URLs, sockets, network hosts and the repository database path. The Owner granted disposable E2E Migration execution for this implementation, and the existing Migrations ran only against that validated file. No Migration file changed.

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

`tests/e2e/support/artifact-scan.mjs` implements a fail-closed scanner. Before any future upload it must:

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

## Implemented npm Scripts and Commands

Implemented `package.json` additions:

```text
test:e2e                 node tests/e2e/support/run-e2e.mjs
test:e2e:contracts       node --test tests/e2e/support/artifact-scan.test.mjs
test:e2e:direct          playwright test --project=chromium
typecheck:e2e            tsc --noEmit -p tsconfig.e2e.json
```

Validated local sequence under the granted gates:

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

## Exact Implemented File Delta

Bounded 001E-B implementation:

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

Still excluded and separately gated:

```text
ADD .github/workflows/browser-e2e.yml
```

No `.gitignore` delta was required because all runtime/auth/artifact paths are repository-external. No application, CSS, menu, Vite config, PHP application, route, Migration or database configuration file changed.

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

## Implementation Evidence

```text
AUTHORIZED_BASE: main@05290700afc94153378c0ca4fafe0c69eab80544
DEPENDENCY: exact @playwright/test@1.62.1
PACKAGE_LOCK: bounded npm lockfile v3 resolution
BROWSER: Google Chrome for Testing 151.0.7922.34 / Chromium Headless Shell revision 1234
BROWSER_CACHE: /Users/a/Library/Caches/byl-erp/playwright/1.62.1
RUN_ROOT: fresh owner-only operating-system temporary directory per run
RUN_ROOT_OUTSIDE_REPOSITORY: PASS
DATABASE: fresh external database/e2e.sqlite per run
DISPOSABLE_MIGRATION: PASS / existing Migrations only
SYNTHETIC_USERS: 3 / active super-admin, active unauthorized, inactive
LOCAL_ADMIN_BYPASS: false
PROJECTS: chromium only
WORKERS: 1
REUSE_EXISTING_SERVER: false
SCREENSHOT_TRACE_VIDEO: off / off / off
STORAGE_STATE: not generated
EXTERNAL_BROWSER_REQUESTS: 0
FOUNDATION_SMOKE: PASS / 1 test
ENVIRONMENT_AND_ARTIFACT_CONTRACTS: PASS / 15 tests
CLEANUP: PASS on success, failure, timeout and signal
WORKFLOW_CHANGE: NO
```

Validation at the implementation Head before publication:

```text
COMPOSER_VALIDATE: PASS
PHP_TESTS: PASS / 134 tests / 1322 assertions
FRONTEND_TESTS: PASS / 12 files / 71 tests
E2E_CONTRACT_TESTS: PASS / 15 tests
E2E_FOUNDATION_SMOKE: PASS / 1 Chromium test
APPLICATION_TYPECHECK: PASS
E2E_TYPECHECK: PASS
BUILD: PASS
AUTHORIZED_PHP_FILE_PINT: PASS
APPROVED_MENU_BASELINE: PASS / 4 tests / 213 assertions
REPOSITORY_WIDE_PINT: PRE-EXISTING BASELINE FAILURES ONLY / no changed PHP file failure
```

The first real run exposed two test-harness integration defects without touching application behavior: the PHP bootstrap needed Composer autoload before Laravel boot, and the smoke selectors needed to match the current password control and Inertia v3 page payload contract. Both were corrected within the approved E2E support/test files; subsequent Foundation Smoke passed with the external runtime removed.

## PR #21 Independent Review Remediation

```text
INDEPENDENT_REVIEW_RESULT: CHANGES_REQUIRED
REVIEWED_HEAD: 6a4bc9647885040e3caaf1fcbb6fe40aaa63d2cc
REMEDIATION_SCOPE: four bounded E2E safety contracts
LARAVEL_EFFECTIVE_CONFIG_GATE: PASS
PRE_MIGRATION_DATABASE_IDENTITY: PASS
POST_BOOT_DATABASE_IDENTITY: PASS
CONFIG_CACHE_ISOLATION: PASS / all cache paths external; active config, route and event caches rejected
PRE_ABORTED_SIGNAL: PASS / no child spawn
LOCAL_PLAYWRIGHT_CLI: PASS / repository-installed @playwright/test 1.62.1 only
NPM_NETWORK_FALLBACK: PROHIBITED / runtime uses the resolved local CLI through the current Node executable
COOKIE_SCAN: PASS / cookie, setCookie and sessionCookie fixtures
ZIP_TOTAL_BUDGET: PASS / aggregate and nested declared-size budgets enforced before inflate
MIGRATION_FILE_CHANGE: NO
REAL_DATABASE_CHANGE: NO
```

Before Migration, the runner now boots Laravel through the same E2E environment in a read-only runtime-preflight mode. It verifies the effective `testing` environment, `sqlite` default, configured SQLite path, PDO `main` database identity, disabled local-admin bypass and external Bootstrap cache paths. Provisioning repeats the effective PDO identity check after Migration and before inserting synthetic users. Contract fixtures prove stale config cache, MySQL, nonfresh shared SQLite and the repository SQLite path fail closed without changing the repository database.

## PR #21 Clean-clone Remediation

```text
INDEPENDENT_REVIEW_RESULT: CHANGES_REQUIRED
REVIEWED_HEAD: d3f3cc6edf237eabdf424a7019c3f8580192c3d1
ONLY_BLOCKER: contract test depended on ignored local database/database.sqlite
PHP_REPOSITORY_PATH_FIXTURE: tracked composer.json
FIXTURE_PRECHECK: size and mtimeMs recorded
FAIL_CLOSED_STAGE: raw repository-path boundary before Laravel connection or Migration
FIXTURE_POSTCHECK: size and mtimeMs unchanged
JAVASCRIPT_LOGICAL_PATH_CONTRACT: database/database.sqlite remains rejected without existence check
UNTRACKED_SQLITE_DEPENDENCY: REMOVED
DATABASE_SQLITE_CREATE_DELETE_MODIFY: NO
CLEAN_CLONE_CONTRACT: PASS
```

The test suite no longer stats, opens or otherwise depends on `database/database.sqlite`. The PHP boundary test uses tracked `composer.json`, which is guaranteed in a clean clone, and confirms the file is rejected before Laravel database boot while its size and modification time remain identical. The JavaScript path contract still submits the logical repository SQLite path directly to the path guard; its assertion does not require that file to exist.

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

## HISTORICAL RECORD — Owner Gates

```text
DEPENDENCY_CHANGE_AUTHORIZATION: GRANTED / exact @playwright/test@1.62.1 only
PACKAGE_LOCK_CHANGE_AUTHORIZATION: GRANTED / exact dependency resolution only
BROWSER_INSTALL_AUTHORIZATION: GRANTED / external Chromium cache only
WORKFLOW_CHANGE_AUTHORIZATION: REQUIRED / NOT GRANTED
DISPOSABLE_E2E_MIGRATION_EXECUTION_AUTHORIZATION: GRANTED / fresh external SQLite only
IMPLEMENTATION_AUTHORIZATION: GRANTED / bounded local 001E-B foundation only
MERGE_AUTHORIZATION: NOT GRANTED (HISTORICAL)
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
CURRENT_TRACK_PHASE_MAP: 001A MERGED / PASS → 001B MERGED / PASS → 001C MERGED / PASS → 001D COMPLETE / PASS → 001E-A MERGED / PASS → 001E-B MERGED / PASS / CLOSED → 001E-C MERGED / PASS / CLOSED → 001F ACTIVE / PREFLIGHT REVIEWED
COMPLETED_PHASES: 001A, 001B, 001C, 001D, 001E-A, 001E-B, 001E-C
CURRENT_PHASE: 001F Architecture Re-audit
DOCUMENTED_PACKAGE: ERP-ARCH-MIGRATION-001E-B
DOCUMENTED_PACKAGE_STATUS: MERGED / PASS / COMPLETE / CLOSED
CURRENT_ACTIVE_PACKAGE: ERP-ARCH-MIGRATION-001F-A
CURRENT_ACTIVE_PACKAGE_STATUS: IMPLEMENTED / PENDING INDEPENDENT REVIEW
WAITING_PHASES: 001F-B through 001F-F
BASELINE: main@05290700afc94153378c0ca4fafe0c69eab80544
GITHUB_STATE: PR #21 MERGED
GLOBAL_GATE: FAIL
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: 001F-A independent review; 001F-B through 001F-F separate authorization
EVIDENCE_AS_OF: 2026-08-03; local implementation, disposable SQLite Migration, Chromium smoke and regression validation
```

## ROADMAP HOURS

```text
CURRENT_PACKAGE_ESTIMATE: 12–18h; planning value 15h; historical accepted implementation estimate
KNOWN_CURRENT_TRACK_REMAINING: 12–24h; planning value 18h for proposed 001F-B through 001F-F; 001E is complete
ARCHITECTURE_GATE_REMAINING: 12–24h planning estimate for 001F-B through 001F-F; actual hours unknown with evidence
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
ESTIMATE_BASIS: completed bounded local 001E-B implementation; independent review and merge excluded
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
MIGRATION: disposable external SQLite execution included and completed; persistent environments excluded
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```

## Verdict

```text
FINAL_STATUS: ERP_ARCH_MIGRATION_001E_B_MERGED_PASS_COMPLETE_CLOSED
```
