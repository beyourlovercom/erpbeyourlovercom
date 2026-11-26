# ERP KOL Just-in-Time Binding V1
Owner approved R0-R4, 2026-11-26. Production release is out of scope.
EXECUTION_PROVIDER: GPT_WEB
VERIFICATION_TIER: MILESTONE_RELEASE
Baseline a591e069; isolated worktree erp-kol-jit-binding-20261126.
Scope: existing KOL create/preflight endpoint and Vue form, strict provider identity verification, atomic local binding, existing coupon/referral association, preserve owner scope. Never create or modify provider credentials for existing accounts. No bulk import, no production writes, no shared runtime changes.
Acceptance: existing account plus exact login/email and live WP/Affiliate ID agreement -> ERP profile, link existing provider IDs and coupons/referrals; no provider mutation. Already bound, conflicting email, duplicate, unavailable provider -> no writes. New signup path stays unchanged. When both IDs exist but no local mirror, verify live email, exact username and both provider IDs via bounded authoritative read-only snapshots; persist ONLY requested account and linked coupons in the same transaction. Existing-only WP fails closed: existing connector cannot prove the username of a WP-only account or safely create exactly one missing Affiliate with a no-new-user policy.
Verification: focused HTTP positive/negative tests, existing create/duplicate regressions, frontend tests, typecheck/build and isolated Owner UAT before release. ERP2 consumes same Laravel behavior; separate UI integration/coordination required.

## Candidate verification
- Production read-only identity evidence for dustypepsi: WP 655 / Affiliate 260 / Coupon 17506. No production writes.
- KOL feature suite 366 tests, 1986 assertions PASS (SQLite in-memory).
- Create.vue Vitest 38 PASS; TypeScript PASS; production build and Workspace Tabs P0 verifier PASS.
- Isolated synthetic SQLite local runtime on US Mac mini, fake AffiliateWP gateway, loopback only: /login HTTP 200. Automated browser login/submission invocation blocked by client security check; actual browser UAT and Owner acceptance remain pending.
- No deployment, no real account claim, no shared ERP2 Dev switch; no credentials or isolated DB tracked.
