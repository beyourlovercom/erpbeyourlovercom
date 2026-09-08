# ERP-PRODUCTION-STABILITY-V1-P3-001

## Scope

Global 5xx / performance sweep after Production Stability P0–P2. This package removes the confirmed authenticated-shell permission-query storm without changing permission semantics, business workflows, schema, or persistence. Production access in this package is read-only only; merge and deployment remain separate Owner gates.

## Baseline and evidence

- Base: `origin/main@408a6d1f352434ca9039efda5e33ae2de04653bc`.
- Branch: `fix/erp-production-stability-v1-p3-001`.
- Production P2 request correlation is active; the new Nginx log format has observed 14 requests with **0 5xx** at sweep time.
- Historical access-log sampling contains old 500s, including `/inventory/packaging`; its matching PHP-FPM error was a 30-second timeout in Eloquent relationship handling on an older release.
- Production performance warnings show an authenticated global floor around 328–386 queries on multiple unrelated routes.
- A read-only Production split probe isolated `ErpShellNavigation::for(user #1)` at **310 queries / ~98 ms wall**, while Notification Center summary used 2 queries.
- Root cause: every `Gate` check repeatedly called `User::hasPermission()`, and each call re-queried roles / permissions; shell navigation performs many such checks for every authenticated Inertia page.

## Implementation

1. Resolve each User instance's effective permission state once, preserving super-admin, role-mode, and custom-mode behavior.
2. Reuse the in-memory effective permission set for subsequent Gate checks during the request.
3. Add a navigation query-budget regression test so menu growth cannot silently reintroduce per-permission SQL.
4. Keep Notification Center and navigation authorization rules unchanged.

## Production-equivalent read-only benchmark

Using the live Production database without switching releases or writing business data:

- Current shell navigation: **310 queries**.
- Candidate shell navigation: **4 queries**, 3.43 ms SQL, 14.06 ms wall.
- Current combined `shell navigation + notifications + /inventory/packaging index projection`: **330 queries**, 61.7 ms SQL, 110.3 ms wall on the latest observed Production release.
- Candidate combined projection: **12 queries**, 6.9 ms SQL, 29.94 ms wall using the final candidate file against the same live database.
- Query reduction for the combined projection: **318 / 96.4%**.
- Candidate `/inventory/packaging` index returned an `Inertia\Response` in ~14 ms and did not reproduce the historical timeout.

## Acceptance gates

- Role-mode, custom-mode, super-admin, local-admin-bypass, and navigation authorization regression tests pass.
- Packaging evidence / scan workflows pass.
- Shell-navigation permission resolution is bounded to <=4 SQL queries in the focused regression test.
- Production-equivalent read-only candidate benchmark confirms the global query-floor reduction.
- No MySQL-incompatible SQL pattern was found by the bounded source scan used in this package.
- No full-suite PASS claim unless explicitly run.
- No schema migration, Production deployment, credential action, or external-service mutation in this package without a separate Owner gate.

## Scope boundary

The historical `/inventory/packaging` 500 is evidence of an old 30-second timeout, not proof of a current deterministic defect. P3 removes the confirmed global permission-query storm that could amplify every authenticated page. Any remaining route-specific performance hotspot after this global fix belongs in a separate bounded follow-up rather than broadening this package.