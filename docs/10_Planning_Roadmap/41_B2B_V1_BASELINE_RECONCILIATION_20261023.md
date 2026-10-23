# BYL B2B Trade V1 — Owner baseline reconciliation and release hold

Date: 2026-10-23. This is a REVIEW PROPOSAL only, not Owner approval, Finance approval, production release, or CI PASS.

## Full repository regression on equivalent isolated environments
Both worktrees had independent Composer installs, Vite production builds, PHP 8.3.33 and PHPUnit testing against disposable SQLite in-memory databases.

| Source | Tests | Assertions | Errors | Failures |
|---|---:|---:|---:|---:|
| Clean GitHub main 60ccafc2848a9453cdfe018268517ca7ca7324fb | 1891 | 16863 | 15 | 29 |
| B2B V1 consolidated Draft #483 HEAD 782c36fb11ab84263b7ac2cf153db34aa02a2aff | 1971 | 17665 | 15 | 29 |

Error identities: 15/15 identical. Failure identities: 28/29 identical; the remaining is the renamed legacy 13-root/independent B2B 14-root approved-menu assertion, which belongs to the same mismatch class. Candidate adds 80 tests and 802 assertions with no additional failed category, but this DOES NOT make full CI pass.

## Frozen Owner menu Issue #474: proposal for separate explicit review

- Approved menu remains unchanged, OWNER_APPROVED v2.7. Do NOT silently reapprove.
- Marketing runtime contains one already-existing Traffic landing entry at /marketing/traffic, named traffic.boss-dashboard, permitted by marketing.view. It precedes existing Subscribers, Newsletter, Campaigns, Automations and Promotions; approved list omits Traffic.
- KOL has same business routes and permissions, but runtime places logistics at sort 30 and the following items at 40-140; the approved snapshot contains sort 25 then 30-130.
- Accounting runtime places B2B cash at sort 30 and Loss Orders at sort 40, rather than 25/30 in frozen snapshot.
- Accounting Loss Orders route /accounting/order-costs?profit_status=loss is a query-filtered alias; basename of the full URL incorrectly incorporates its query into the slug. Preserve the owner slug loss-orders and validate the parsed URL path separately.
- Require Owner to choose whether runtime placement is intended. No Traffic page or other business behavior shall be removed to satisfy a test.

## Frozen Architecture manifest

The ModuleArchitectureTest exact file/route/migration/hash inventory is already out of sync in clean main. Reconcile only through a reviewed source inventory with explicit diffs; preserve the existing strict assertions and architecture-layer dependency constraints. An automatic manifest rewrite was rejected by the platform safety check; it was NOT executed or retried through a bypass. This report does not rewrite the frozen manifest.

## Isolated non-promoted legacy test fixture diagnostic

The original 15 errors include two unit files that query logistics_carriers without database migration and four ProductExternalReferences test cases that use nonnumeric Woo external order numbers despite canonical numeric business-number rules.

In a temporary experiment, adding RefreshDatabase to the two unit test classes and substituting valid numeric test-only Woo business numbers gave: 37 targeted tests / 322 assertions, 2 latent frozen-logistics assertion failures. The full experimental RC suite had 1971 tests / 17789 assertions, 2 errors / 31 failures. Thus 13 original errors have demonstrably fixable fixture causes, but the test-only experiment was REVERTED, not promoted as green CI.

The two latent owner-policy conflicts needing separate Logistics Owner decision:
1. Mexico Weship route has service=null in frozen config but active carrier catalog supplies Standard in a migrated test DB.
2. Frozen FedEx display alias is FedEx / 联邦, but active Carrier catalog returns FedEx / 联邦快递.

The two residual errors are outside B2B: unsupported warehouse import route response assertion shape, and ShipmentService active carrier service validation in an Inventory aging test. No production carrier name, routing, money, Woo identity or active DB was altered.

## Owner V2.8 menu approval — 2026-10-23

The Owner explicitly replied **pass** to the proposal to preserve the current Marketing, KOL and Accounting menu positions. The V2.8 update is a **frozen-source baseline revision, not an operational menu change**:

- `menu/APPROVED_MENU.json` updated to `version=2.8`, `status=OWNER_APPROVED`, `approved_at=2026-10-23`, `approved_head=49ce2543453ec54761ac8e45d3c665b668491ca1`, the exact source HEAD whose existing runtime positions were inspected before approval.
- The *already running* `marketing-traffic` now appears in the approved snapshot and its existing following siblings are renumbered 20–60.
- KOL existing visual order unchanged, with canonical 10-unit sort offsets across 14 children.
- Accounting existing visual order unchanged: overview (10), order costs (20), independent B2B cash (30), loss-orders shortcut (40).
- `accounting-loss-orders` has its exact existing query-filter URL and its specifically approved `loss-orders` alias. New `approvedSlugForUrl` validates the URL pathname for all other entries and recognizes **only** this exact code/URL alias (no general mismatch bypass).
- Frozen menu integrity tests remain active and strict; an additional V2.8 sequence test explicitly validates the approved Marketing/KOL/Accounting order and loss-orders URL.
- Existing `config/erp_shell_navigation.php`, PHP routes, database migrations and business permissions are **not changed**. GitHub main and both independent S4/S6 environments remain unmodified.
- This approval applies to the menu alone. It does **not** waive frozen Architecture manifests, other module legacy tests, hosted CI, Owner physical M14 or Finance approval.

**V2.8 Menu Owner gate:** approved and technical tests PASS. **Overall B2B V1 Production gate:** still HOLD.

## Release checklist — still HOLD

Menu V2.8 approved and tests pass; module owner still reconciles frozen Architecture list without disabling CI; Logistics/Orders owners resolve legacy fixtures/behavior and complete full repo tests; GitHub hosted checks pass; authenticated role-browser and physical M14 Owner UAT passes; Finance owner signs off source-of-truth, FX/refunds and cash accounting; exact-head merge and production DB/deploy get separate approvals.

Remain Draft: consolidated #483. Separate S4 Dev and S6 read-only RC services remain unchanged and available. New Trade and Finance writes default OFF; no GitHub main, live ERP or payment changes were made.
