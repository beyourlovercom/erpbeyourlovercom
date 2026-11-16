# ERP2 original ERP self-only identity projection — approved Production gate

- **Work Package:** `ERP2-P0-ORIGINAL-IDENTITY-READONLY-20261116`
- **Owner authorization:** 2026-11-16, strictly limited to one authenticated read-only Production identity/ACL projection for ERP2 Dev verification.
- **Execution provider:** `GPT_WEB`
- **Verification tier:** `HIGH_RISK`
- **Repository:** `beyourlovercom/erpbeyourlovercom`; new dedicated PR against `main`.
- **Dependency:** ERP2 consumer Draft PR #766 remains unmerged. This package does not incorporate its experimental pages, fixture credentials or order APIs.

## Allowed surface and non-goals

- Adds **only** authenticated `GET /_erp2/identity` to the original ERP. No writable route, migration, schema change, cookie/token minting or account/permission mutation.
- The server derives identity exclusively from Laravel's current web-session user. A caller-supplied `user_id`, email or role parameter cannot select another employee.
- Output is fixed to `{source, user:{id,name,email}, permissionMode, roleKeys, effectivePermissionKeys, navigation}`. Roles and grants are read from persisted original ERP relations on each request; `super_admin` follows the original privilege semantics. Navigation uses the original authorized shell menu. No order, customer, shipment, tracking, or finance records are returned or proxied.
- Local administrator bypass is explicitly rejected. Inactive user/session expiry must fail. Auth middleware remains the original Laravel guard; no new cross-origin CORS permissions are introduced.
- Every response is marked private/no-store and noindex. Production logs and GitHub must never contain employee passwords, auth cookies or keys. Existing shared temporary password is not part of the implementation.
- **Forbidden:** deploying ERP2 PR #766 as part of this release, altering any Production business data, changing staff rights, restarting machines/Chrome, or merging unrelated pending PRs.

## Candidate test and exact-revision checklist

| Gate | Expected |
|---|---|
| PHP syntax / route-list | Exactly one `GET|HEAD _erp2/identity` |
| Identity endpoint PHPUnit | Guest denied, principal spoof ignored, role mode, custom mode, live revocation, super admin, disabled user |
| Identity module regression | Existing user/role/permissions tests remain PASS |
| Vite Production build | `npm run build` PASS; `ERP_WORKSPACE_TABS_BUILD_PASS` for existing 30/3 tabs |
| Release diff | Exactly controller, Identity route registration, tests and this evidence; no user, order, data or UI mutations |
| Production preflight | Confirm current symlink SHA, backup/rollback target, current `main` relation and no pending unwanted release changes |
| Production smoke | Authenticated current-user JSON/no-store, unauthenticated denial, no order payload; check existing ERP login/dashboard and tabs |
| ERP2 consumer | Separate Draft PR #766 updates source discriminator to Production identity contract; six real Dev sessions remain a separate P0 gate |

## Release and rollback

Release may occur only after successful full candidate tests and the **specific Owner 2026-11-16 Production authorization**, with an exact-commit immutable release. Preserve existing shared `.env` and storage and all approved frontend assets. Build tabs on the final candidate (never copy an older tabs-off bundle). Before activation, record the previously active `current` symlink and maintain an intact rollback target. No schema migrations are necessary. If any smoke or role projection check fails, restore that exact previous symlink and verify the original login/order/dashboard paths; never repair by changing real staff permissions.

## Execution evidence — 2026-11-16

- Isolated US Mac worktree `/Users/a/AI/007/erp2-identity-readonly-prod-20261116` fetched the exact GitHub branch. It does not modify the primary checkout or ERP2 Draft PR.
- Initial test found existing Inertia middleware replaces the `Vary` header with `X-Inertia`; strict `Cache-Control: no-store` remains the cache boundary. Test was corrected to assert the real behavior.
- `OriginalErpIdentityEndpointTest` **5/5 PASS, 25 assertions**; entire Identity feature directory **45/45 PASS, 440 assertions**.
- PHP syntax for route and controller PASS; `artisan route:list --path=_erp2/identity` confirms one GET route.
- `npm run build` **PASS** including **`ERP_WORKSPACE_TABS_BUILD_PASS`** (`AuthenticatedLayout-RA223ZAN.js`, 30-tab bundle).
- **At this document snapshot, GitHub merge and Production release are not yet claimed.** Actual deployment and post-deploy HTTP evidence must be appended if performed.

## Mandatory closure footer

### PROJECT POSITION

PROGRAM_ROADMAP: Architecture → Existing Operations / Tencent Docs replacement → ERP2 identity parity P0 → ERP2 remaining migration and UAT; precise cross-program revised hours UNKNOWN WITH EVIDENCE (historical `AI/CURRENT_CONTEXT.md` predates November migration).
CURRENT_TRACK_PHASE_MAP: P0 original self-only identity endpoint (CURRENT) → 6-employee ERP1↔ERP2 session/ACL/revocation matrix (WAITING) → ERP2 broader page migration (WAITING).
COMPLETED_PHASES: ERP2 Dev identity bridge Draft PR #766; Production endpoint code and isolated tests.
CURRENT_PHASE: P0 identity authority source.
CURRENT_WORK_PACKAGE: ERP2-P0-ORIGINAL-IDENTITY-READONLY-20261116.
PACKAGE_STATUS: RELEASE_CANDIDATE_TESTED; Production status requires actual symlink and HTTP evidence.
WAITING_PHASES: Live six-employee parity; ERP2 P1 migration.
BASELINE: GitHub `main` at branch creation 2026-11-16 (`46232746fcd15a03ca1aa69f413a83f2a92db53e`); final PR head and Production active SHA must be read at release time.
GITHUB_STATE: Dedicated PR, ERP2 #766 remains Draft and unmerged.
SOURCE_CONFLICTS: Historical context/docs are older than current Production; GitHub/runtime wins.
GLOBAL_GATE: P0 real-staff parity remains HOLD until 6/6 direct verification.
PRODUCTION_DEPLOYMENT_STATUS: NO at candidate test time; report actual post-release evidence separately.
NEXT_DEPENDENCY: Complete exact-SHA merge/release verification, then ERP2 Draft bridge source contract and six staff sessions.
EVIDENCE_AS_OF: 2026-11-16 (HK time).

### ROADMAP HOURS

CURRENT_PACKAGE_ESTIMATE: 2–4 effective engineering hours (identity endpoint, tests, release and smoke).
KNOWN_CURRENT_TRACK_REMAINING: 10–17 hours for ERP2 Dev six-person full P0 matrix after this package, contingent on usable sessions.
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE (no updated merged November program ledger).
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE (historical roadmap not current).
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE.
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE.
MVP1_REMAINING: UNKNOWN WITH EVIDENCE.
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE.
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE.
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE.
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE (unreconciled work log).
ESTIMATE_CONFIDENCE: MEDIUM, small source footprint; release infrastructure needs preflight.
ESTIMATE_AS_OF: 2026-11-16.
ESTIMATE_METHOD: Bounded controller+route+tests, existing build gates and one controlled Production release.

### ESTIMATED CODEX HOURS

PREFLIGHT_AND_SCOPE: 0.5–1h.
CORE_EXECUTION: 0.5–1h.
TEST_AND_VALIDATION: 0.5–1h.
REMEDIATION_BUFFER: 0.5–1h.
ESTIMATED_EFFECTIVE_HOURS: 3h planning value.
ESTIMATE_RANGE: 2–4h.
ESTIMATE_BASIS: One read-only route, existing Laravel auth/ACL, isolated tests and exact-SHA release.
OWNER_REVIEW: EXCLUDED (Owner's review time).
MERGE: INCLUDED subject to checks.
MIGRATION: EXCLUDED (no schema change).
UAT: INCLUDED (automated and authenticated smoke; six-person P0 Owner UAT excluded).
DEPLOYMENT: INCLUDED under Owner's 2026-11-16 authorization.
