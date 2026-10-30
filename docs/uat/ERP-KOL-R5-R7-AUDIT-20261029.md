# ERP KOL R5–R7 — evidence gates

- R5: Chrome reached the www AffiliateWP administration page for a known linked affiliate (ERP KOL 1 / Affiliate 878), with the title `Affiliates ‹ BeYourLover® — WordPress`. Read-only observation only. The exact edit form could not be inspected; R5 full edit-form UAT remains open.
- R6: planned profile -> preselected KOL order -> order detail -> KOL profile closure. Preselection must be scoped server-side; no implicit order creation.
- R7 baseline: 888 historical KOL fulfillment orders, all without explicit `orders.kol_id`, and no formal collaboration `order_id` links as of the last verified production readback. Do not treat affiliate sales referrals, matching names, email, shipping addresses, or counts as authoritative proof of fulfillment ownership. Results require real read-only database audit.
- No production updates/backfills or WordPress changes authorized by this audit.

## R6 real Chrome isolated UAT — 2026-10-30
- US Mac mini localhost:18281, `APP_ENV=local`, isolated SQLite, synthetic KOL #1 and product SKU `PKG-UAT-001`.
- Authenticated Chrome: profile #1 -> new order page with KOL #1 automatically preselected -> saved synthetic fulfillment order #8 (`KOL202610303NBM007`, `orders.kol_id=1`) -> order detail -> KOL profile #1. No production order was created.
- Targeted PHP regression: 6/6 PASS, 108 assertions; targeted frontend 103/103 PASS; Vite build PASS.
- Owner production visual UAT and live production order Canary are separate gates.

## R7 production read-only evidence audit — 2026-10-30
- All SQL reads ran inside `START TRANSACTION READ ONLY` and `ROLLBACK`; 0 production order/KOL/AffiliateWP mutations.
- 888 KOL fulfillment orders, 888 with `orders.kol_id IS NULL`.
- Primary formal collaboration `order_id` evidence: 0. Direct affiliate referral `order_id` evidence for KOL fulfillment orders: 0.
- **Additional authoritative historical-source linkage located:** `logistics_historical_import_rows.source_order_no` exactly matches `orders.external_order_number` with a resolved `kol_id`.
- **886/888 exact candidates**, all one-to-one by byte-exact order reference and valid KOL master ID; no candidate with multiple KOLs, duplicate source matches, ambiguous matching across order kinds, or missing KOL master.
- Source import batch #1 status: `committed` (1077 imported rows); 886 candidate rows from that batch.
- **765 logistics-source Ready candidates:** historical classification `exact_tracking_and_kol_match` and matching linked `logistics_historical_evidences.evidence_hash` / source-row ID / KOL ID.
- **121 logistics-source Review candidates:** 68 multiple_tracking_candidates; 50 suspicious_compound_tracking; 2 replacement_or_transfer_ambiguity; 1 carrier_requires_review. Do not auto-approve or backfill these.
- **2 unmatched ERP record IDs**: 7496, 7521. Require source reconciliation or human evidence.
- Source Ready means *import logistics evidence ready*, **not** permission to write `orders.kol_id`. All 886 require a preview/approval gate before R8; Review requires closer investigation.
- ID-only local CSV (NOT committed to GitHub): `/Users/a/ai/uat/erp-kol-r7-audit-20261030/production_kol_order_evidence_v2.csv` (889 lines incl header), SHA256 `654daafac67cf3b3955c97682394fc83dbf1390d7c80ec21d5eae9dce0828b21`.
- Source-specific CLI now classifies evidence from committed history imports, requires valid KOL and matching independent historical evidence hash for ready candidates, checks reference uniqueness across order kinds and does not modify database rows.

## R5 live WordPress read-only limitation
- A China Mac Chrome tab directly opened `https://www.beyourlover.com/wp-admin/admin.php?page=affiliate-wp-affiliates&affiliate_id=878&action=edit_affiliate`; the observed title was `Affiliates ‹ BeYourLover® — WordPress`, no login redirect.
- Existing browser UI automation did not reliably expose the editor form fields. Specific account detail edit-view visibility remains an Owner manual visual gate. No affiliate edits made.

## Release decision
- PR #555 remains Draft, **not** authorized to merge or deploy.
- R6 isolated functional UAT and R7 live production *read-only* scan are completed. R5 editor-form Owner visual PASS remains pending.
- R8 backfill is separate scope and needs exact-count preview, explicit Owner approval, backup, batch audit and rollback.
