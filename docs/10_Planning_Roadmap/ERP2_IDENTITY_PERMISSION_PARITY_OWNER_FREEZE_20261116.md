# ERP2 — Owner-frozen account and permission parity contract

**Owner decision: 2026-11-16. Scope: ERP2 Vben migration, PR #766 (Draft), Dev verification only.**
**Priority: P0 release-blocking.** Do not start remaining P1 work until this identity/permission parity gate closes. No Production login/credential mutation, merge or deployment is authorized by this contract.

## 2026-11-16 Owner-authorized original ERP identity-only bridge — delivered, live parity HOLD

**Authorization boundary:** Owner approved an ERP2 **Dev-only** identity verification bridge that may authenticate against original ERP and read **only the signed-in employee ID, email, saved role/permission mode and effective permission keys**. No Production orders, customer details, fulfillment, writes, credential resetting, main merge or Production deployment are authorized. The previously shared common temporary password is not committed, stored or logged.

**New Draft PR files:** `apps/erp-siteops-vben/runtime/p0-production-identity-bridge.cjs` and `p0-production-identity-bridge.test.cjs`. A server-side in-memory cookie jar isolates original Laravel session cookies from the ERP2 Dev browser, which receives only an opaque HttpOnly SameSite=Strict short-lived bridge cookie. Upstream routes are fixed to original ERP `/login`, `/account/profile`, `/_erp2/identity`, and `/logout`; business routes are never proxied. Login and identity are independently checked for matching original numeric user ID and email; live rights are re-read on each identity request; unknown or contradictory rights are denied. The bridge is loopback-only and **login is disabled by default** unless an explicitly authorized effective-permission source is present.

**Observed upstream source contract:** Original ERP `GET /account/profile` currently redirects unauthenticated requests to login (HTTP 302); original ERP `GET /_erp2/identity` currently responds HTTP **404**. The original Inertia shell provides a user identity and filtered navigation but **does not expose actual saved role keys / permission_mode / all effective permission keys**, so menu similarity cannot serve as an ACL substitute. A permissions source is missing; the new bridge correctly fails closed. **No original Production endpoint was added or deployed.**

**Isolated 007windows Dev verification:** source tests **3/3 PASS** on workspace and again **3/3 PASS** on 007windows. Independent `127.0.0.1:18788` Dev-only preview is running without restarting existing ports 18786/18787 or Chrome/READY devices. Observed health `identityEnabled:false`, `productionOrders:false`, `storage:memory-only`; `GET /login` = **503 HOLD**, `GET /orders` = **405**, unauthenticated `GET /_erp2/identity` = **401**. This tests security gating, not six-person live parity. No Production order/customer endpoint was accessed.

**P0 decision: HOLD, not closed.** Six original ERP employees were authenticated/audited before this bridge, but **0/6 real original-ERP-to-ERP2 Dev employee sessions** have passed the complete ACL, row-scope and revocation matrix. Closing P0 requires an approved live, read-only effective-permission source: either (A) a separately authorized, minimal original ERP Production identity endpoint (a **new Production deployment requiring separate Owner approval**) or (B) an authorized read-only Production identity/ACL database connector with no business data scope. After that, enable Dev-only login, run six separate sessions, including forbidden access, logout and revocation, collect redacted evidence, and complete Owner Dev UAT. Do not silently substitute the Production audit snapshot or synthetic roles.

## 2026-11-16 execution update — source-controlled Dev P0, still HOLD

This section reports observed outcomes rather than treating an Owner authorization or synthetic test as evidence of parity.

| Gate | Actual result | P0 decision |
|---|---|---|
| Read-only original Production authentication | **6/6 genuine employees PASS** for login, matching original user ID/email, permitted orders, denied user administration; all remote sessions logged out and ephemeral jars cleared | Audit reference only, not ERP2 Dev parity |
| Canonical Dev API contract | Original Laravel Dev-only identity endpoint implemented; isolated PHPUnit 4 tests / 24 assertions (runner emitted 4 warnings) | Contract check, not live employee pass |
| Vben genuine Laravel login/logout | Added same-origin CSRF/session workflow and disabled local JWT for canonical mode | Source implemented, pending live Dev session |
| Rights drift / data isolation | Fingerprint on canonical user ID, mode, saved role keys, effective permission keys and menu paths; different authority evicts ERP hot snapshots and forbidden tabs; canonical Orders clears rows before each fresh GET | Source implemented; live revocation test pending |
| 007windows source/tooling | Vue TypeScript **PASS**, Vitest **10/10 PASS**, separate Vite canonical build **PASS** | Engineering check passed |
| 007windows isolated gateway | New loopback-only localhost port **18787**; static UI and health **200**, Node gateway **2/2 security tests PASS**, only pinned Mac Dev backend allowed, order writes **405**, simulated Owner API disabled | Preview ready but upstream unavailable |
| Mac Dev Laravel session | Isolated Mac tailnet port **18931** process no longer listening (local Mac curl failed); no genuine six employees in its in-memory SQLite | **HOLD** |
| Six employee ERP1↔ERP2 allow/deny/revocation | **0/6 genuine Dev cross-system sessions accepted so far** | **P0 HOLD** |

**Credential safety lock:** Canonical gateway rejects staff credential POST with HTTP 503 unless an operator deliberately sets the isolated Dev-ready flag after genuine authority provisioning; 007 server currently logs staff login HOLD. This keeps the Owner's temporary shared password out of the empty SQLite test server.

**Current branch:** PR #766 (Draft). New Dev-only files include p0-canonical-proxy.cjs and p0-canonical-proxy.test.cjs; preview binds to 007 loopback only and cannot connect to Production. Existing 007 port 18786 Owner fixture preview and original Chrome/READY devices were not restarted.

**Remaining blocked acceptance:** create/provide a legitimate original Laravel Dev authority capable of authenticating the exact six existing ERP employee IDs and reading their saved permissions, without exporting Production credentials, password hashes or customer orders into the isolated fixture. A staging replica or a separately scoped identity federation is an infrastructure choice requiring explicit Owner authorization. Recovering an empty SQLite PHP test server alone does **not** satisfy this requirement.

**Next sequence:** (1) Owner chooses approved real-Dev staff authority; (2) connect that authorized Laravel backend to 007 port 18787; (3) execute six identity/permission/menu/order allow/deny matrix; (4) revoke one permission and verify cache/tab invalidation plus logout and session expiry; (5) browser UAT, redacted audit, then P0 PASS. No Production merge/deploy implied.

## Decision: same employee, same effective authority

ERP2 is a new front end for **the existing ERP identity and authorization system**; it must not become a second permission system.

1. **One actual identity:** Vita, Liang, Dede, Emma, Guts and Hiko must resolve to the *same canonical ERP user ID* in old ERP and ERP2. No replacement `@local.test` / `@local.invalid` fixture account, "acting as" role selector, email-only surrogate, independent staff registration, or second password database may count as parity.
2. **One authority source:** determine effective permissions with the original Laravel ERP server's persisted user role assignments and `permission_mode`. `custom` reads user-specific `permission_user` grants; `role` reads the **persisted** role-permission relationships. `super_admin` is handled by original ERP authorization. `RolePreset` source defaults are not a substitute for actual database permissions.
3. **Enforce on server:** every ERP2 list, search, filter, count, pagination, detail, direct URL, edit, export and business action uses the *same authenticated user* and existing ERP permission/record-scope checks. Hidden UI is not security. Forbidden requests must return 401/403 without leaking rows, counts, fields or the existence of restricted orders.
4. **Menus and actions:** first- and second-level menus, buttons and fields represent server-effective permissions and record scope. They must never be granted solely because a Vben token, local role selector or cached menu says `staff`, `kol`, `manager` or `owner`. Unknown permissions/identity fail closed.
5. **Session coherence:** logout, session expiry, permission edits, role changes and user disablement in original ERP propagate to ERP2; old cached tabs, pinned pages and hot snapshots may not retain or expose previously authorized data after the change. Do not put sensitive authorization state into local-storage authority.
6. **Owner VI:** Step 2 Owner visual UAT was **PASS** on 2026-11-16. It closes *visual acceptance only*, not real-staff parity.

## Current verified gap (not yet resolved)

PR #766's Vben preview still has its own `loginApi` token and four-role (`owner/staff/kol/manager`) simulated gateway selector. Six **named isolated Dev fixtures** pass basic sessions and example order scopes, but those fixtures are *not* canonical ERP employee IDs with actual saved roles and custom overrides. Their 6/6 technical result **cannot** close this P0 gate. The existing `r2c-real-staff-dev-uat.cjs` only tests original Laravel Dev preflight and cannot itself claim cross-system equivalence.

## Read-only Production authority verified — 2026-11-16

The Owner authorized an identity/ACL read of **all 20 active Production accounts**. No passwords, cookies, tokens or hashes were exported. The six focus accounts have **persisted role-mode** permissions (no custom-mode overrides). This snapshot is an audit baseline, **not a static ERP2 authorization table**; Laravel must always recompute permissions from the currently logged-in database user.

| Employee | Original ERP user ID | Persisted role key | Effective permission count | Current ERP2 isolated Dev fixture ID | Parity |
|---|---:|---|---:|---:|---|
| Vita | 18 | `role_preset_operations` | 20 | 5 | Mismatch |
| Liang | 17 | `role_preset_logistics` | 9 | 6 | Mismatch |
| Dede | 19 | `role_preset_logistics` | 9 | 7 | Mismatch |
| Emma | 3 | `role_preset_kol_manager` | 13 | 8 | Mismatch |
| Guts | 2 | `role_preset_kol_manager` | 13 | 9 | Mismatch |
| Hiko | 6 | `role_preset_kol_specialist` | 9 | 10 | Mismatch |

Source: original ERP Production Laravel ORM using read-only retrieval of user, role and permission relationships. **6/6 Dev fixtures are incompatible** with the real identities/permissions. Synthetic Dev accounts must remain labelled and isolated; they cannot be used to pass staff UAT.

## Dev integration committed on PR #766

- Dev-only `GET /_erp2/identity` uses the existing Laravel **web session** and the authenticated `User`. It returns only own original user ID, display identity, saved role keys, mode, effective permission keys and `ErpShellNavigation::for(user)`. No user ID/role input, no credentials, no Production endpoint.
- The endpoint rejects local admin bypass and inactive accounts, sends `no-store` headers, and respects persisted `permission_mode` (including super-admin semantics). It introduces **no write route**.
- Vben can opt into `VITE_ERP_IDENTITY_MODE=laravel` **only in a same-origin Dev setup**; it ignores local Vben authorization tokens/role fixtures, refreshes Laravel rights on navigation and blocks unauthorized direct ERP2 routes.
- In that mode, the Orders master calls existing same-origin Laravel `GET /orders` rather than the four-role synthetic gateway. The old 007 visual/fixture mode remains unchanged.
- PHPUnit `CanonicalIdentityContractTest`: 4 tests / 24 assertions passing on isolated US Mac mini worktree with in-memory SQLite (session denied, real ID/right, custom overrides, permission revocation). This is **engineering validation, not six-person live-session acceptance**.
- **P0 remains HOLD** until the Dev origin serves the actual ERP session, six real staff identities are available and end-to-end allow/deny cases pass. No merge/Production deploy.

## Six-person acceptance matrix — verify against live Dev authority, not inferred roles

| Employee | Expected work scope (business reference only) | Mandatory parity evidence |
|---|---|---|
| Vita | Operations, order sync/allocation | Same ERP ID, exact persisted effective keys, menus/buttons, authorized order set, allocation/action success and forbidden-action denial |
| Liang | Domestic fulfillment/logistics | Same ERP ID/keys; domestic visibility and shipping operations; denied unrelated overseas/admin operations |
| Dede | Overseas review/fulfillment | Same ERP ID/keys; overseas review and shipping; denied unrelated domestic/admin operations |
| Emma | **Production role is KOL manager**, not specialist | Same ERP ID/keys; verify current manager scope and deny out-of-authority orders; do not downgrade to remembered specialist role |
| Guts | KOL manager | Same ERP ID/keys; authorized team orders; out-of-authority records denied |
| Hiko | KOL specialist/test | Same ERP ID/keys; own influencer orders only; other specialist's order denied in list/search/direct URL/export |

The business scope is a hypothesis to test against **current persisted ERP rights**. If historical memory disagrees with actual ERP user permissions, **existing ERP runtime wins**. A genuine changed role must be reported as drift, not silently normalized.

## Closure procedure and evidence

1. With a legitimately authenticated **original ERP Dev administrator**, read canonical user IDs, active status, `permission_mode`, persisted role assignments, user-specific grants and current effective permission keys **without altering them**. Gather representative non-Production orders for each person's allow/deny scope. Store sensitive audit data only in protected local files, never Git or chat.
2. Implement or verify ERP2's signed-in identity binding to the canonical user record and route business requests through Laravel's existing per-user authorization. Eliminate simulation for employee-mode login; retain fixtures only in explicitly isolated tests.
3. Login separately as all six genuine Dev employees on 007windows (or approved machine) and compare ERP1 vs ERP2: canonical ID, effective permissions, entire two-level menu, field/action visibility, server responses, page totals, search/filter, order detail/direct URL and exports. Validate both positive and negative cases. Audit role/custom mode separately and perform a permission-revocation/logout/cache invalidation exercise.
4. Record a **per-employee pass/fail matrix** with old ERP and ERP2 responses and safe redacted evidence. Any missing account/session, mismatched ID/key/scope, or unauthorized data leak = **P0 HOLD**.
5. Only after 6/6 exact parity and negative authorization checks pass may this gate be marked **PASS** and P1 resume. PR #766 stays Draft; merge/Production requires separate Owner authorization.

## Explicit non-goals

- Do not reset genuine employee/Production passwords to `password`, duplicate Production accounts into an isolated fixture, or copy Production cookies/data into Git.
- Do not change existing ERP permissions merely to make ERP2 pass.
- Do not assert role-menu similarity or six invented user records as complete account/permission parity.
