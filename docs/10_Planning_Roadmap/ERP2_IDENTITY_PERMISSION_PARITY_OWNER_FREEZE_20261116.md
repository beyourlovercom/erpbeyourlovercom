# ERP2 — Owner-frozen account and permission parity contract

**Owner decision: 2026-11-16. Scope: ERP2 Vben migration, PR #766 (Draft), Dev verification only.**
**Priority: P0 release-blocking.** Do not start remaining P1 work until this identity/permission parity gate closes. No Production login/credential mutation, merge or deployment is authorized by this contract.

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

## Six-person acceptance matrix — verify against live Dev authority, not inferred roles

| Employee | Expected work scope (business reference only) | Mandatory parity evidence |
|---|---|---|
| Vita | Operations, order sync/allocation | Same ERP ID, exact persisted effective keys, menus/buttons, authorized order set, allocation/action success and forbidden-action denial |
| Liang | Domestic fulfillment/logistics | Same ERP ID/keys; domestic visibility and shipping operations; denied unrelated overseas/admin operations |
| Dede | Overseas review/fulfillment | Same ERP ID/keys; overseas review and shipping; denied unrelated domestic/admin operations |
| Emma | KOL specialist | Same ERP ID/keys; own influencer orders only; other specialist's order denied in list/search/direct URL/export |
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
