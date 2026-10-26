# US-SM XLWMS Inventory — R6 first write and R7 recurring sync controls

**Status:** release preparation only. No Production OMS credentials, approved first-write checksum or R6 applied Production snapshot is recorded. This document is NOT authorization to modify real inventory. Owner must review the live read-only discrepancy before first use.

## R6 — first Production inventory write (one time)

1. Complete secure Production credential provisioning for the **correct** US-SM API warehouse. Verify warehouse code, signed product/catalog pagination and good-stock `stockType=0`; do not send signed requests or credentials to logs.
2. Complete Product OS unified SKU Alias physical-identity review before any first write. Legacy `smgj_pi` source alone does not establish manual approval. Existing known vendor aliases are `C549A → VC549A` and `VM467 → VM467A`; each must be individually approved or block all.
3. Deploy and prove Product OS alias contract, inventory availability/reservation coverage gate, V2 lot-safe service and this approval guard; repeat Production read-only `xlwms:sync-inventory --dry-run` with V2 enabled, confirm 17/17 matched or current live truth (not necessarily historical 17), zero blocked, dated lot plan and unreturned ERP-only quantity preserved/quarantined.
4. New encrypted same-day Production backup and its hash/authentication checks; save previous release pointer. Existing #55 is an earlier preflight backup, and a later backup may be necessary.
5. Record **exact** current read-only snapshot SHA-256 checksum with reviewer, batch date, 17 SKU quantities, lot plan and anomalies. Set `XLWMS_INVENTORY_FIRST_APPLY_APPROVED_CHECKSUM` only to the exact reviewed checksum (64-character hex). Without this value the first `--apply` is **blocked**, even with both V2 and apply flags on.
6. Temporarily enable both `XLWMS_LOT_RECONCILIATION_V2_ENABLED=true` and `XLWMS_INVENTORY_APPLY_ENABLED=true` for the **one audited command invocation**, not permanent shared env, run `xlwms:sync-inventory --apply` once and audit all warehouse/product QOH, historical dated lots, unknown-age shadow records, no automatic allocation of unverified stock, other warehouses, no OMS outbound and no Woo changes. Repeating the same source must never double-adjust.

## R7 — recurring automatic sync (only after first R6 applied snapshot)

1. Keep schedule and apply flags OFF until R6 acceptance. Once R6 is signed, enable exact 10-minute cadence with `XLWMS_INVENTORY_SYNC_SCHEDULE_ENABLED=true`, `XLWMS_INVENTORY_SYNC_SCHEDULE_MINUTES=10`, V2 true and apply true.
2. The V2 guard compares against the most recent **applied** V2 snapshot: remote matched SKU list AND ERP product identities must be identical. Any new, disappearing or reassigned warehouse-stock SKU immediately blocks all writes.
3. Per-product **absolute delta >5 units** or cumulative **sum of absolute deltas >15 units** blocks the entire batch by default. Both may be configured lower, never negative. The threshold applies only after a successfully reviewed initial R6 applied snapshot and cannot authorize an initial write.
4. Unmatched provider SKUs, conflicting reservations/locked stock, historical lot consumption/aging or batch-linked FIFO lot changes also block the entire transaction. Any blocked apply updates the snapshot to **blocked** and reports machine-readable reason; never reports success with an untouched QOH.
5. All absent-in-OMS stock rows remain physically preserved but receive zero automatic allocation after a confirmed V2 snapshot. Undated added provider stock stays in the unverified-age shadow, never enters a dated aging lot.
6. Reconcile status/blocked counts, recurring timing, unique checksum, number of applied events, next scheduler invocation and source-freshness every operating day. Revert scheduler/apply flags OFF if any anomaly. Do NOT automatically roll back a previously completed inventory adjustment; correct under audited business process.
7. **R7 is not complete until a real Production scheduled run has succeeded after R6 and its no-duplicate and source-truth behavior is observed.** Merely adding the code or turning on a timer is insufficient.

## Evidence / non-goals

US Mac focused test includes first apply checksum deny/allow, repeat idempotence, small automatic delta pass, overlimit stock delta block, provider coverage drift block, PI alias matching and standard V2 lot reconciliation; full result is recorded in the owning PR.

This package changes no Product OS UI, no OMS shipment/order API, no warehouse outside US-SM, no marketing, no currency and no finance charges. No external paid services are added.
