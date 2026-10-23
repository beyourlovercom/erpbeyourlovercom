# BYL ERP — B2B Trade Order V1 controlled release candidate

Date: 2026-10-23. State: **Candidate in independent isolated GitHub feature branch; NOT merged/deployed to Production.**

## What Owner authorized
Owner agreed to advance B2B Trade Order V1 through S4 real integration, S5 GitHub integration, S6 Dev acceptance and controlled release preparation. **No real production merge or financial posting has been performed.** Previous S4 and initial Dev-only UAT acceptance are preserved; production still requires exact SHA and explicit gated release approval.

## Source-of-truth and integration chain
Repo: beyourlovercom/erpbeyourlovercom. Main at candidate creation `60ccafc2848a9453cdfe018268517ca7ca7324fb`. This release candidate consolidates eight stacked Draft PRs into a **single reviewable main-targeted Draft**, preserving their incremental PRs:
- #473 B2B Business OS;
- #476 Sales pipeline;
- #477 follow-ups, quote versions and Won/Lost;
- #478 read-only finance fact rules;
- #479 Woo/Trade attribution rejection and integer exact money;
- #480 independent Trade Order, two-person business approval, private receipts;
- #481 separate Finance manual bank statement attestation and refund tracking;
- #482 seven-identity HTTP UAT, Dev-only setup, evidence and audit.

`feature/b2b-trade-v1-release-candidate-20261023` is based directly on the exact #482 tip `bda97d950647d22d35d15d0bffdddfa5ff585de1`, with focused release security added. Existing S4 Dev server runs its prior code and uses its **own** isolated SQLite. This candidate exists in separate `/Users/a/AI/uat/b2b-trade-v1-rc-20261023` Git worktree, isolated from active server and Production.

## Go-live blast-radius guard: OFF unless separately enabled
New **config/b2b_trade_release.php** has two separate env-backed flags, **both false by default** in every non-testing environment:
- `B2B_TRADE_WRITES_ENABLED=false`: blocks new Trade Order drafts, approvals, evidence submissions and review writes with HTTP 503 on the server side.
- `B2B_FINANCE_CASH_WRITES_ENABLED=false`: blocks actual Finance-side manual cash confirmation/refund event writes independently, also with HTTP 503.

Flags do not implicitly enable each other. Dedicated `B2BTradeReleaseGuard` checks at the application-service write boundary rather than relying on a cosmetic UI toggle. Tests run in `APP_ENV=testing`; the new automated checks explicitly simulate production environment and verify 503 on disallowed writes, as well as independent activation. Note this protects the **new Trade Order and Finance cash write services**; existing legacy Sales/Case functions remain under their established role gates and are not implicitly part of this kill switch.

Suggested controlled activation gates after Owner/Finance sign-off:
1. Read-only deployment, flags OFF, no data migration until exact-head approval; verify login, owner-scoped reads, navigation, no errors.
2. Enable `B2B_TRADE_WRITES_ENABLED=true` ONLY after trade test role + signed-in browser UAT; Finance cash remains OFF. Check one artificial Trade test order in an isolated non-production database; never issue a real order/payment.
3. Enable `B2B_FINANCE_CASH_WRITES_ENABLED=true` ONLY with Finance Owner-approved settlement-policy and source-of-truth, independent treasury reviewer and rollback procedure, and separately authorized production change. If any step fails, set flags back to false, clear/rebuild Laravel config cache, halt mutations; preserve append-only records rather than deleting.
4. Production SQL migrations remain **separately authorized** and must use pre-migration backups, constraint/volume review and idempotent deployment. No automatic migration or environmental flag writes in this Draft.

## Tests performed on isolated US Mac mini
- The RC was checked out separately; `composer install --no-scripts` completed with an independent vendor autoloader; TypeScript and Vite built successfully. Unbuilt test checkout Vite errors were resolved by building the RC artifact and were **not domain failures**.
- B2B/Identity-Shell/ModuleProvider/B2B-unit regression: **109 PHPUnit tests / 1,066 assertions PASS**, including 3 new independent production write-gate tests. Prior S4 HTTP 106/1,059 remains valid on the predecessor commit.
- Historical complete frozen-menu and ModuleArchitecture cross-module baseline run on the parent code: **13 tests / 1,530 assertions, 6 failures**. These failures include pre-existing #474 owner menu baselines, unapproved Marketing/Accounting menu drift and frozen global source/migration lists. **Do not waive, delete, skip or describe them as PASS.** RC additions do not solve the unrelated historical baseline; re-run before merge. No GitHub hosted CI checks currently recorded for branch #482 due to account restrictions.
- 12 actual Chrome/Vue synthetic props visual checks at PC1280/mobile390, and separate live private Dev login checks are historical isolated S4 results. **Authenticated multi-account real Chrome sign-in and Owner physical iPhone 14 PASS are not complete**. Five test identities have appropriate least-privilege roles in an isolated Dev-only database, but security controls blocked automated credential-driven login; no workaround attempted.

## Required pre-production hold
- Real authenticated browser UAT across seller, manager, document reviewer and two Finance users; no password exchange/bypass; physical M14 Owner acceptance.
- Full repository CI / baseline #474 resolution by owning module teams, not a blanket test exclusion.
- Finance Owner sign-off on manual statement verification, duplicate payment accounting, bank/provider identity, refunds/chargebacks, FX/currency conversion, P&L/tax reporting integration, and non-duplicated Woo cash.
- Explicit GitHub **exact-head** merge approval with all checks green; independent Dev deployment and final Production deployment/migration permission. No real funds/production business writes in candidate work.

**Status verdict:** S5 candidate source and scoped security tests ready for review, but **NO Production GO**. Freeze further feature scope; complete Owner and global CI gates. Any issue discovered must generate a focused, testable fix and new exact HEAD.
