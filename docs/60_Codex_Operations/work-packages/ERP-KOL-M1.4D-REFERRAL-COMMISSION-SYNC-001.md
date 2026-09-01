# ERP-KOL-M1.4D-REFERRAL-COMMISSION-SYNC-001

Status: IN_PROGRESS
Base authority: `main@62dc05d8fc8ae89376e8df8ccf4227c60a1cce43`
Execution: ONE WP / ONE BRANCH / ONE WORKTREE / ONE PR
Branch: `feature/erp-kol-m1-4d-referral-commission-sync-001`

## Goal
Deliver the canonical ERP Referral / Commission read model on top of the merged AffiliateWP Connector and exact Affiliate→KOL identity mirror.

## Frozen ownership
- AffiliateWP = Referral provider truth.
- ERP Order = canonical order truth; M1.4D references it and never creates `kol_orders`.
- ERP KOL = canonical influencer truth.
- Referral `amount` is the provider commission fact; M1.4D does not create a second commission ledger.
- `payout_id` is mirrored provider evidence only. Payout mutation/accounting workflow belongs to M1.4E.

## Andy Core v1.5.3 Referral contract
Each signed `snapshot/referrals` item exposes exactly:
- `referral_id`
- `affiliate_id`
- `reference`
- `description`
- `amount`
- `currency`
- `status`
- `context`
- `date`
- `payout_id`

## Real Local provider audit — 2026-09-01
- Referral rows: 3,299.
- Statuses: paid 1,548; unpaid 834; failed 598; rejected 223; pending 95; draft 1.
- Currency: USD for all 3,299 rows.
- Context: WooCommerce for all 3,299 rows.
- All 3,299 references are present and numeric.
- 3,275 references resolve to a currently existing Local WooCommerce order; 24 are historical/unresolved.
- 2,709 distinct order references; 288 references have multiple Referral rows. One Order→many Referrals is therefore valid.
- 1,549 rows have non-zero `payout_id`; one of them is still provider status `unpaid`, so payout identity must never be inferred from referral status.
- 429 distinct Affiliate IDs.

## Projection rules
- Exact provider identity is `(connection_key, referral_id)`.
- Resolve KOL only through exact `(connection_key, affiliate_id)` in `kol_affiliate_accounts`; never by email/name.
- Resolve Order only through canonical `order_external_references` with provider `woocommerce`, same connection key and exact Referral `reference`.
- Multiple Referral rows may reference the same canonical Order.
- Unresolved Affiliate/KOL or Order is retained and surfaced; provider truth is not discarded.
- A complete full paged snapshot may mark unseen mirror rows missing; partial/failed fetches may not mutate reconciliation state.

## Sync semantics
Andy Core currently filters `updated_after` against Referral `date`, not a provider modification timestamp. Referral status/payout changes can therefore occur without becoming visible to a date-based incremental cursor. M1.4D authoritative reconciliation uses a complete paged snapshot and does not claim reliable incremental status sync.

## Workspace
Replace the `/kol/commissions` placeholder with a real read-only Referral / Commission workspace:
- totals and commission amounts by provider status;
- Referral ID, KOL/Affiliate, canonical Order resolution, amount/currency, status, referral date and payout evidence;
- Manager/Admin manual full sync using the existing `kol.affiliates.manage` provider-truth boundary;
- `kol.view` can read; normal KOL scope applies, and unresolved provider rows are visible only to all-scope managers.

## Acceptance gates
1. `M1.4D_CONTRACT_FROZEN` — exact snapshot/ownership/order projection frozen.
2. `M1.4D_REFERRAL_MIRROR_READY` — safe full snapshot + exact provider identity + reconciliation.
3. `M1.4D_ORDER_ATTRIBUTION_READY` — exact canonical Order projection, including one Order→many Referrals.
4. `M1.4D_COMMISSION_WORKSPACE_READY` — real `/kol/commissions` UI + scoped read + manager sync.
5. `M1.4D_LOCAL_UAT_READY` — deterministic fake-provider UAT and regression gates.
6. `M1.4D_LIVE_PROVIDER_UAT` — real Local signed Referral read + full mirror reconciliation.
7. Merge requires explicit Owner authorization; Production deployment is a separate gate.

## Out of scope
- Payout create/complete/write-back or accounting settlement (M1.4E).
- Referral/provider mutation.
- New KOL order entity or duplicate order ledger.
- Guessing KOL/Order identity.

`M1.4D_CONTRACT_FROZEN = PASS`

## Foundation gate evidence — 2026-09-01

- `M1.4D_CONTRACT_FROZEN = PASS`
- Real Local AffiliateWP audit: 3,299 referrals; WooCommerce context 100%; USD 100%.
- Exact Woo order-reference resolution: 3,275 resolved; 24 historical unresolved retained.
- One-order/multi-referral reality confirmed: 288 order references have multiple referrals.
- Gateway snapshot page size set to provider-supported `limit=100` to keep a 3,299-row full snapshot below the 60 req/min connector rate limit.
- M1.4D focused backend: 7 scenarios / 48 assertions / PASS.
- M1.4D frontend: 3 tests / PASS.
- Shared Gateway + Referral/Coupon/Affiliate regression: 403 assertions / exit 0.
- Typecheck: PASS.
- Build: PASS.
- Fresh migration: PASS.
- `git diff --check`: PASS.
- Architecture: existing baseline only (`1 failed / 1 risky / 6 passed / 487 assertions`).
- Existing APP-001 debt: 12 non-KOL violations; M1.4D KOL APP-001 additions: 0.
- Base authority: `main@62dc05d8fc8ae89376e8df8ccf4227c60a1cce43`; no main drift at checkpoint gate.

## Checkpoint gate

- Foundation implementation is ready for checkpoint + Draft PR.
- Live Provider Referral full-sync UAT remains NOT RUN and is required before Ready-for-Review / merge authorization.
- Payout write-back remains outside M1.4D and is NOT STARTED.

## Live Provider UAT — 2026-09-01

Authority: US Mac local ERP + Windows `localdev.beyourlover.com` AffiliateWP.

- Connector health: PASS; contract version `1`.
- Real provider total: `3,299` Referral rows.
- Final full sync: `3,299` synced / `0` marked missing / `3,299` present.
- Status parity: paid `1,548`; unpaid `834`; failed `598`; rejected `223`; pending `95`; draft `1`.
- Payout ID present: `1,549`, matching provider truth.
- Immediate repeat correctly hit Connector `60 requests / 60s` rate limit without mutating mirror.
- Repeat after rate-limit window: `3,299` synced / `0` marked missing / `3,299` rows / `3,299` distinct Referral IDs.
- `LIVE_FULL_REFERRAL_SYNC = PASS`.
- `LIVE_PROVIDER_PARITY = PASS`.
- `LIVE_IDEMPOTENCY = PASS`.
- `NO_DUPLICATE_REFERRAL = PASS`.
- Temporary User #2 role/permission cleanup: PASS; final roles exactly `1,4`.
- Temporary worktree `.env` removed.
- Local WordPress Connector restored to exact v1.5.3 base hash after UAT.

Live UAT exposed a server-side Connector dependency: AffiliateWP excludes internal `failed`/`draft` statuses when `status` is omitted. The compatibility fix is isolated in YBY-Core PR #28 (`ANDY-CORE-ERP-REFERRAL-SNAPSHOT-ALL-STATUSES-001`). M1.4D must not merge/deploy ahead of that Connector dependency being formally delivered.
