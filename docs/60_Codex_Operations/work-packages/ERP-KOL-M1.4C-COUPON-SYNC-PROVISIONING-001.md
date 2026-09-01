# ERP-KOL-M1.4C-COUPON-SYNC-PROVISIONING-001

Status: LIVE_PROVIDER_UAT_PASS
Base authority: `main@1f60a7815c51b2b5ce9de6991e43f4dd52267cab`
Execution: ONE WP / ONE BRANCH / ONE WORKTREE / ONE PR
Branch: `feature/erp-kol-m1-4c-coupon-sync-provisioning-001`

## Goal
Deliver the canonical ERP Coupon operating surface on top of the merged M1.4A Connector and M1.4B Affiliate/KOL authority.

## Frozen scope
- Sync every WooCommerce Coupon into an ERP mirror, including coupons with no Affiliate/KOL link.
- Add Coupon mode to `/kol/affiliatewp`; Influencer mode remains unchanged.
- Normalize code as trim + case-insensitive comparison.
- Every provider Coupon ID is mirrored even when historical provider data contains duplicate or invalid codes.
- Duplicate non-empty normalized codes are preserved as separate Coupon rows and marked `provider_code_conflict`; ERP never silently chooses a winner.
- Blank/invalid provider codes are mirrored and marked `provider_invalid_code`.
- Provider-only/custom discount types are mirrored and marked `provider_unsupported_discount_type`; ERP provisioning remains restricted to the strict three supported types.
- ERP creation still hard-blocks any normalized code already present in the local mirror or live WooCommerce.
- One KOL may own multiple Coupon Codes.
- Before create: check ERP mirror first, then live `/coupons/check` provider truth.
- Conflict is hard-blocked; existing provider/ERP owner facts are surfaced, never overwritten.
- Create only against an authoritative Affiliate mirror already bound to the selected KOL.
- Successful create requires provider read-back confirming Coupon ID, code, Affiliate binding, discount facts and expiry.
- Persist one stable idempotency key per ERP Coupon provision attempt/retry identity.

## Strict Andy Core v1.5.3 contract
`POST /coupons/check` body is exactly `{code}`.

`POST /coupons/provision` body is exactly:
- `erp_kol_id`
- `affiliate_id`
- `code`
- `discount_type` = `percent|fixed_cart|fixed_product`
- `amount` = canonical non-negative decimal, percent <= 100
- `expiry` = ISO-8601 timestamp or null

Success read-back fields: `coupon_id`, `code`, `normalized_code`, `affiliate_id`, `discount_type`, `amount`, `date_expires`.

## Data ownership
- WooCommerce = Coupon provider truth.
- AffiliateWP = Affiliate provider truth.
- ERP = KOL master, synchronized Coupon operating mirror, conflict/provision state and audit surface.
- M1.4C must not implement Referral, Commission or Payout persistence/workflow.

## Authority
Reuse the existing `kol.affiliates.manage` Manager/Admin boundary for M1.4C Coupon provider mutations and full Coupon sync. `kol.view` users may view the workspace but cannot mutate provider truth.

## Acceptance gates
1. `M1.4C_CONTRACT_FROZEN`: strict provider payload/read-back and ownership frozen.
2. `M1.4C_COUPON_MIRROR_READY`: full paged Coupon snapshot sync, exact provider identity, safe missing reconciliation.
3. `M1.4C_COUPON_PROVISION_READY`: local+live conflict check, stable idempotency, exact Affiliate/KOL binding, verified read-back.
4. `M1.4C_COUPON_UI_READY`: Coupon tab enabled in `/kol/affiliatewp`, all coupons visible, state-aware create/conflict UX.
5. `M1.4C_LIVE_PROVIDER_UAT`: real Local WooCommerce read + one reversible create/replay/cleanup.
6. `M1.4C_OWNER_UAT_READY`: focused regression, typecheck/build, clean PR, explicit Owner UAT.
7. Merge requires explicit Owner authorization. Production deployment is a separate gate.

## Out of scope
- Referral / commission sync (M1.4D).
- Commission payout workflow (M1.4E).
- Approval Center / dashboard expansion (M1.4F).
- Production deployment, tag or release.

`M1.4C_CONTRACT_FROZEN = PASS`

## Checkpoint evidence — 2026-09-01
- `M1.4C_COUPON_MIRROR_READY = PASS`
- `M1.4C_COUPON_PROVISION_READY = PASS`
- `M1.4C_COUPON_UI_READY = PASS`
- Coupon focused regression: 13 scenarios / 59 assertions / exit 0.
- M1.4C + M1.4B + shared Connector focused regression: 133 warning-marked tests / 478 assertions / exit 0. Warning markers are the repository's existing test-bootstrap warnings; there are no assertion failures.
- Frontend AffiliateWP workspace: 10/10 Vitest PASS.
- `npm run typecheck = PASS`.
- `npm run build = PASS`.
- Fresh SQLite migration rehearsal = PASS; `(connection_key,coupon_id)` is unique while `(connection_key,normalized_code)` is a non-unique lookup index so real provider duplicate-code conflicts can be mirrored without data loss.
- Architecture frozen-set updates are limited to this WP's new KOL files/routes/migration/frontend. `M1.4C_KOL_ARCHITECTURE_VIOLATIONS = 0`.
- Existing main baseline debt remains 12 non-KOL `APP-001` violations in Audit/Customers/Logistics/Shell/Support and is not changed by this WP.
- `M1.4C_LIVE_PROVIDER_UAT = NOT_RUN` at this checkpoint; run only after the Draft PR exists.

## Live provider discovery — 2026-09-01
- Real Local WooCommerce contains 2,827 non-trash Coupon rows before the final UAT Coupon is created.
- Existing provider truth includes 93 duplicate normalized-code groups, 2 blank-code Coupons, and 7 `wbte_sc_bogo` custom discount-type Coupons.
- M1.4C therefore mirrors these historical provider anomalies explicitly instead of rejecting the full snapshot or mutating WordPress data.
- ERP create/provision contract remains unchanged and strict: only `percent`, `fixed_cart`, `fixed_product`; local + live duplicate check remains mandatory.
- Diagnostic UAT Coupons #39911, #39912 and #39913 were each identity-checked, permanently deleted and verified absent after their respective diagnostic runs.
- The third diagnostic run reached the provider's fixed 60 requests/minute rate limit only after repeated diagnostic/full-snapshot calls; the rate limit was not bypassed or changed.
- Final minimal live path was rerun in a fresh provider rate window and passed.

## M1.4C Live Provider UAT — PASS
- `M1.4C_LIVE_PROVIDER_UAT = PASS`.
- Real Andy Core health: PASS, contract version `1`.
- Real WooCommerce Coupon read: PASS; first signed snapshot page returned 50 real Coupon items.
- Real active Affiliate used: `affiliate_id=1`.
- Reversible Coupon created through `CouponProvisioningService`: provider Coupon `#39914`.
- Exact same six-field payload replayed with the same persisted idempotency key: PASS; provider returned the same Coupon ID.
- Live `/coupons/check` read-back confirmed the exact Coupon ID + Affiliate binding: PASS.
- Cleanup identity check + permanent delete of Coupon `#39914`: PASS.
- Post-clean signed `/coupons/check` confirmed the UAT code is available again: PASS.
- No Connector secret was committed or printed into the WP/PR; temporary credential transfer, SQLite DB and UAT scripts are cleanup-only artifacts.
