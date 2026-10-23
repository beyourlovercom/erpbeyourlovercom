# BYL ERP — B2B Trade Order V1 FINAL integration review candidate (RC2)

Date: 2026-10-23. **Draft review only, NO Production GO and NO main merge.**

## Intent: one integration vehicle
Branch: feature/b2b-trade-v1-final-rc2-20261023, main-targeted Draft PR. This branch consolidates the complete original B2B draft chain #473 and #476-#482, release safety #483, Owner-approved V2.8 menu #484 and the tested stacked CI/domain-safety fixes through #496. The historical stacked PRs are preserved ONLY for evidence and source review; **do not merge both this consolidated PR and its ancestors**.

Target GitHub main at candidate creation: 60ccafc2848a9453cdfe018268517ca7ca7324fb. This candidate branch is directly descended from that baseline and includes all listed fixes.

## Owner/business scope
- B2B Organization, Contact, Opportunity, Follow-up, Quote, independent B2B Trade Order (not Woo), private evidence, two-person review and separate Finance-owned manually attested B2B cash/refund events.
- Warehouse/Accounting Woo consumer order tables do not become B2B Trade Order records. Existing provider imports and finances are not mutated by synthetic UAT.
- The two B2B Trade and Finance cash write controls are separately default OFF in all non-testing environments: B2B_TRADE_WRITES_ENABLED=false, B2B_FINANCE_CASH_WRITES_ENABLED=false. The test environment is isolated and synthetic only.
- Finance manual bank attestation is NOT live bank API verification, automatic FX conversion, Woo order financial posting, nor P&L/tax recognition. Separate Finance Owner approval is mandatory before any live cash/refund write.
- Existing live order payment/reopen/roles are not weakened; the integrated fixes include accurate shipment reopen audit, exact two-permission Woo product sync UI, required 4PX shipping/packaging cost evidence, and fresh fail-closed Woo payment proof under batched externalReference projection.
- Existing runtime ERP menus unchanged; approved V2.8 menu snapshot only. Owner pass for menu is NOT broad Production release authorization.

## Exact isolated engineering evidence
- PHPUnit on US Mac mini, PHP 8.3.33, disposable SQLite in-memory, distinct Composer install: **1,974 tests, 18,239 assertions, 0 runtime errors, 5 failing assertions, 6 skipped**. No blanket skips or golden-list rewriting. This is NOT full CI PASS.
- Remaining five: 3 frozen Architecture inventory drift tests (#474, module-owner review required); 2 frozen Logistics authority tests (#486, Mexico Weship service null vs current catalog Standard and FedEx bilingual Chinese display label mismatch). Do NOT silently decide these business authorities.
- TypeScript typecheck PASS; Vite production build PASS.
- Full regression coverage includes fail-closed stale Woo cached-paid but fresh pending/unpaid negative check; active vs deactivated carrier requests; one batched external references read on wait-stock page; authenticated Laravel role simulation; repeat shipment reopen audit before=shipped and after=pending.
- Separate private S4 and S6 isolated browser/Dev UAT: /up HTTP 200; /login HTTP 200; unauthenticated B2B Trade and Finance pages HTTP 302 to login; real US Mac Chrome PC 1280x800 + M14 viewport 375x812 read-only guest tests 2/2 PASS; 0 real users, B2B trades, cash events, Woo orders or financials in the S6 isolated SQLite. These are NOT actual signed-in multi-account role-browser UAT and NOT physical iPhone M14 Owner PASS.
- Latest GitHub hosted PR checks: none reported; do not claim Actions/hosted CI PASS. Device READY agents not restarted.

## Strict no-go gates in release order
1. Module Architecture owner approves the 3 exact frozen global file/migration/hash mismatches with explicit reviewed diff; Logistics Owner approves the 2 active catalog/frozen routing and display conflicts. Do not bulk regenerate frozen baselines or disable tests.
2. Full local and hosted GitHub CI PASS on the final HEAD, including PHP, TS, Vite, architecture rules, payment negative coverage, no excluded tests.
3. Actual signed-in least-privilege Seller, Manager, evidence reviewer, 2 Finance roles and non-B2B user browser verification on isolated private Dev; Owner final PC and physical M14 visual PASS. Never bypass credential security checks.
4. Finance Owner signs off manual bank statement source and double-control, independent reviewer, refunds/chargebacks, FX/currency, separate ledger accounting and P&L boundaries, before enabling Finance writes anywhere.
5. Owner explicitly approves exact HEAD GitHub main merge of THIS ONE consolidated PR only. Require current main SHA recheck, no duplicate stacked merges.
6. Independent Owner approval before Production migration and deployment; verified backup and rollback, staged disabled-by-default flags first, read-only health smoke, then separate trade-only writes pilot, then Finance-only activation only with its own permission and Finance signoff. Never conduct real bank/order/payment operation automatically.

**Current decision: HOLD** — candidate complete and isolated read-only Dev verified, but global hosted CI, Owner signed-in/physical M14 and Finance/Architecture/Logistics decisions not complete. Keep main, Production ERP, actual finance ledgers and deployed order data unchanged.
