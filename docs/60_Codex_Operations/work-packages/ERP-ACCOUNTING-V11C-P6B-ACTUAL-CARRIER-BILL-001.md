# ERP-ACCOUNTING-V11C-P6B-ACTUAL-CARRIER-BILL-001

**Status:** IMPLEMENTATION COMPLETE / MERGE CANDIDATE / PRODUCTION NOT AUTHORIZED
**Date:** 2026-09-14
**Owner scope:** Accounting V1.1C-P6 — Last-mile Cost, phase P6B Actual Carrier Bill Import / Replacement
**EXECUTION_PROVIDER:** GPT_WEB
**VERIFICATION_TIER:** HIGH_RISK
**UI_UAT:** NOT REQUIRED — backend-only
**PRODUCTION_ACTIVATION:** NOT AUTHORIZED

## Objective
Build a bounded, auditable carrier-bill ingestion path that writes canonical actual shipment billing evidence into `shipment_carrier_billing`, then materializes that evidence into Finance as `last_mile_cost` with `provider_import / confirmed`, replacing weaker P6A estimates when and only when exact evidence is complete.

P6B owns actual-bill import and actual-last-mile replacement only. P6C later owns continuous-delta integration. P6B must not invent carrier prices, create Shipments, or treat operational shipment spreadsheets as billing evidence.

## Baseline / Dependencies
- Issued baseline: `origin/main@d9d3964d0a11d991e4837a96ebf44e33f857c96c`.
- P6A dependency: PR #291 merged as `ed9eeee88921a1f0a65e259398b9fd2f2e0783bd`; P6A is Production accepted.
- C1 is Production accepted; P6B must not alter its scheduler.
- Current Production release at preflight: `logistics-uat-correction-pr296-20260913-201031-d9d3964d`.
- DEPENDENCY_TYPE: MERGED_PR.
- DEPENDENCY_STATUS: SATISFIED.
- PARALLEL_SAFE: YES, subject to exact-file overlap check before delivery.

## Canonical Truth Boundaries
- Shipment / carrier / tracking truth remains Logistics-owned.
- `shipment_carrier_billing` is the canonical actual carrier-billing evidence projection for one Shipment.
- Finance owns `order_cost_entries.last_mile_cost`; it consumes Logistics billing evidence and never becomes the billing source of truth.
- P6A `estimated_reference / estimated` is weaker than P6B `provider_import / confirmed`.
- Existing stronger manual/actual facts must never be silently overwritten.
- Historical Logistics Orders remain excluded from Finance materialization.

## Source Classification
The previously supplied `速达数据.xls` and `纬狮数据.xlsx` are not accepted as actual carrier bills.
- Prior inspection of `速达数据.xls`: 2,416 rows; roughly 2,089 rows report exactly 0.500 kg and there is no explicit settled/actual billing-cost field suitable for confirmed Finance posting.
- Prior inspection of 纬狮 data: 882 rows; 696 rows use 1×1×1 placeholder dimensions. It can support shipment/measurement reconciliation, not confirmed carrier billing by itself.
- Existing Library files with names such as `天运 20260101~0131账单.xlsx` are candidate real billing sources, but current file retrieval cannot expose their parsed rows; no provider-specific column mapping may be guessed.

## Frozen Import Contract
1. P6B v1 accepts a canonical carrier-bill file in CSV or XLSX. Legacy binary XLS is not supported by the canonical parser.
2. Required normalized facts per bill line: `tracking_number`, `actual_cost`, `currency`.
3. Optional normalized facts: `chargeable_weight_kg`, `billing_document_number`, `provider_reference`, `billed_at`, `delivery_status`, `carrier`, `charge_description`.
4. Provider is an explicit import argument and is preserved in every evidence record.
5. Provider-specific adapters may be added only from inspected real source headers; no guessed Speeda/Weship/DHL columns.
6. Canonical matching is exact normalized `tracking_number` → canonical Shipment. No Shipment is created by the importer.
7. Zero Shipment matches, multiple matches, blank tracking, invalid amount/currency, conflicting duplicate evidence, or conflicting existing billing are review blockers, never implicit matches.
8. Multiple source lines for one tracking number may be grouped only when currency and identity evidence are compatible; line amounts are summed and every contributing source row remains in raw evidence.
9. Conflicting currencies or materially conflicting chargeable weights within one tracking group block the group.
10. Existing identical applied billing is idempotent/noop. Existing different billing for the same Shipment is review/blocked; never silent overwrite.
11. Import evidence preserves original filename, SHA-256, provider, parser version, source row number, raw row, normalized row, row hash, group plan identity and Shipment match.
12. A dry-run is pure read-only and creates no batch, row, Shipment billing, Finance entry or FX snapshot.
13. Production import apply is fail-closed by a process-local feature flag plus exact source SHA, plan SHA and expected counts.

## Durable Import Evidence
Add Logistics-owned import evidence tables:
- `carrier_billing_import_batches`
- `carrier_billing_import_rows`

Batch minimum fields: provider, original filename, source checksum, parser version, plan checksum, status, total/ready/review/skip counts, applied timestamp and actor/system metadata.

Row minimum fields: batch id, source row number, tracking number, matched shipment id, resulting billing id, decision, reason code, normalized monetary/weight/reference facts, row hash, raw data and normalized data.

The applied batch is immutable evidence. Reapplying the same provider + source checksum is idempotent/noop unless a separately approved repair package exists.

## Actual Billing Write Contract
- One canonical `shipment_carrier_billing` row per Shipment remains enforced by the existing unique `shipment_id` constraint.
- Ready groups create billing with `data_source=carrier_bill_import:<provider>` and `raw_evidence` containing batch/source checksum/plan/source-row identities.
- `actual_cost` remains in the carrier bill currency; do not mutate source money into Order currency inside Logistics.

## Finance Replacement Contract
1. Add a dedicated `previewCarrierBillingGate()` / `applyCarrierBillingGate()` path that touches only `last_mile_cost`.
2. Actual carrier billing candidate is `provenance=provider_import`, `status=confirmed`.
3. Stable source identity remains billing-specific: `carrier-billing:v1:shipment:<shipment_id>:billing:<billing_id>`.
4. Same-currency actual billing posts directly in Order transaction currency.
5. Cross-currency actual billing must use existing `AccountingFxSnapshotBridgeService` to convert source bill currency into Order transaction currency and freeze posting FX evidence.
6. The original carrier-bill amount/currency stay preserved in Logistics and candidate metadata; Finance stores transaction-currency amount plus frozen USD base snapshot as today’s accounting contract requires.
7. A P6B provider-import candidate may replace a weaker P6A `estimated_reference` row.
8. `actual`, `confirmed_manual`, or `allocated_actual` existing facts remain protected and block replacement.
9. A different existing `provider_import` source identity remains blocked for review; no silent provider-bill replacement.
10. Materialization refreshes Finance completeness after actual replacement.

## CLI / Production Gates
Add a dry-run-first Logistics import command, expected shape:
`logistics:import-carrier-bill {file} --provider=<provider> [--apply] [exact guards]`.

Add a dry-run-first Finance command, expected shape:
`finance:materialize-actual-last-mile [--apply] [exact guards]`.

Production Logistics apply requires process-local `ERP_CARRIER_BILL_IMPORT_PRODUCTION_APPLY_ENABLED=true` plus exact source checksum, plan checksum and ready/review/skip counts.
Production Finance apply requires process-local `ERP_ACCOUNTING_ACTUAL_LAST_MILE_PRODUCTION_APPLY_ENABLED=true` plus exact seen/apply/noop/blocked/FX-freeze counts.
Neither flag is persisted or enabled by merge/deploy.
No scheduler is added in P6B.

## Implementation Surface
- Logistics canonical CSV/XLSX carrier-bill reader with bounded file-size / ZIP-entry / XML safety limits consistent with existing ERP import readers.
- Logistics normalized bill-line contract / grouping / matching service.
- Logistics import batch + row models and migration.
- Logistics dry-run/apply command with exact guards and audit evidence.
- Finance `CostMaterializationService` actual-last-mile FX support and dedicated preview/apply gate.
- Finance dry-run/apply command with exact guards.
- Service-provider wiring and focused tests.
- Architecture migration hash updates required by the new migration/files.

## Acceptance Criteria
- Valid canonical CSV and XLSX parse to the same normalized contract.
- Unsafe/invalid XLSX, unsupported XLS, missing required columns, invalid ISO currency, malformed money, or empty tracking fail closed before writes.
- Exact tracking match is mandatory; unmatched/ambiguous rows are review blockers.
- Duplicate charge lines may aggregate only under the frozen compatibility rules and preserve all raw source rows.
- Import dry-run reports source SHA, plan SHA, total/ready/review/skip and causes zero DB writes.
- Import apply rejects any checksum/count drift before writes and writes only ready billing groups.
- Reapplying identical source is idempotent/noop.
- Conflicting pre-existing Shipment billing is never overwritten.
- Actual same-currency last-mile materializes confirmed provider_import.
- Actual cross-currency last-mile uses the existing Accounting FX bridge and freezes transaction/posting evidence.
- Existing P6A estimate is replaced by actual confirmed billing, not duplicated.
- Existing stronger actual/manual facts remain protected.
- Finance actual dry-run is zero-write; exact guards fail closed on drift.
- Historical/non-customer Orders are excluded.
- No scheduler, UI, menu, permission or route change.

## Validation Contract
- Focused Logistics carrier-billing importer tests: CSV, XLSX, unsafe XLSX, duplicate/grouping, unmatched/ambiguous Shipment, existing-billing conflict, idempotency, Production guard.
- Focused Finance actual-last-mile tests: same-currency, cross-currency FX, estimate replacement, strong-fact protection, historical exclusion, dry-run purity, guard drift.
- Existing `CostMaterializationTest`, `AccountingFxSnapshotBridgeService` tests, P6A last-mile tests and `OrderAnalysisService` regression.
- Logistics Shipment/billing relation regression.
- Migration fresh-up / rollback / MySQL runtime verification.
- Command discovery; PHP lint; `git diff --check`.
- Architecture/Menu/AppShell exact-baseline regression delta; no new failure is allowed.
- Full Finance + relevant Logistics regression on the US Mac merge-candidate revision.

## Explicit Non-Goals
- No Production carrier-bill import or Production Finance write in this Work Package implementation phase.
- No persistent Production feature-flag enablement.
- No P6C continuous-delta/scheduler integration.
- No provider-specific Speeda/Weship/DHL parser until real source headers are inspected.
- No legacy `.xls` parser.
- No Shipment creation, tracking repair, warehouse allocation or carrier routing changes.
- No rate-table invention or estimate recalculation changes.
- No warehouse cost (P7), KOL commission (P8), or loss/other cost (P9).
- No UI, menu, permission or route additions.

## Production Safety
Code deploy and real-data apply remain separate Owner gates. Before any Production import/apply: fresh backup/checksum, exact source SHA, exact plan SHA/counts, target-state snapshot, dry-run, bounded apply, post-write reconciliation and idempotency verification are mandatory.

## Preflight Evidence
At 2026-09-14 preflight on current Production:
- canonical Shipments: 16
- `shipment_carrier_billing`: 0
- Finance `last_mile_cost`: 3
- P6A `estimated_reference` last-mile rows: 3
- current Production release: `logistics-uat-correction-pr296-20260913-201031-d9d3964d`

Existing code already provides:
- one-to-one `ShipmentCarrierBilling` model/table;
- `EloquentCarrierBillingEvidenceReader`;
- Finance carrier-billing candidate materialization and source identity;
- P6A estimate suppression when complete actual billing exists;
- Accounting FX snapshot bridge used by other cross-currency cost materialization paths.

Missing capability is bounded actual-bill ingestion plus cross-currency actual carrier billing and its independent Production apply gate.

## Source Conflicts
`AI/CURRENT_CONTEXT.md` remains stale relative to the current Accounting track; GitHub `origin/main`, current code and Production read-only evidence govern mutable facts. Library metadata proves candidate billing workbooks exist, but current parsed file access does not expose their columns; provider-specific mapping remains intentionally unimplemented rather than guessed.

## Provider Handoff Evidence
- Initial US Codex CLI implementation attempt exited before mutation because the local Codex account reported a usage-limit hard stop.
- Exact post-exit worktree check: `HEAD=a8f04a554f5e52e43f68bfbaa8b62fc885f41496`, `git status --short` empty, `git diff --stat` empty.
- Provider reassigned serially to `GPT_WEB` because architecture/business behavior are frozen and the bounded implementation can be completed directly on the US Mac. Claude is not invoked merely as a quota substitute.
- ONE_ACTIVE_IMPLEMENTATION_PROVIDER_PER_WORKTREE remains satisfied.

## Final Work Log
- Preflight / source classification / existing-model audit / contract definition: 0.65h effective.
- Core implementation — durable import evidence, CSV/XLSX reader, importer, guarded CLI, actual-last-mile FX/replacement: 1.45h effective.
- Remediation / focused validation — idempotent replay, Application/Infrastructure boundary correction, fail-closed source cases: 0.70h effective.
- High-risk verification / latest-main rebase / final evidence: 0.55h effective.
- **Actual effective engineering time:** 3.35h.
- Planning value: 6.25h; variance: **-2.90h / -46.4%**.
- Variance reason: existing ShipmentCarrierBilling, Accounting FX bridge, CostMaterialization and audit primitives reduced new surface; remediation remained bounded and no provider-specific adapter was guessed or built.

## Merge-candidate Verification Evidence
- Merge candidate rebased without conflict onto `origin/main@392747ef2dcd53440b3385a4429798994f9e612a`; intervening main drift touched only `ShipmentService.php` and two delivery-evidence tests, with zero P6B file overlap.
- Final Finance + relevant Logistics regression including PR #298 delivery-evidence coverage: **128 tests / 1,066 assertions PASS; 1 MySQL-only test skipped**.
- Logistics carrier-billing focused: **5 tests / 38 assertions PASS** including CSV/XLSX equivalence, replay idempotency, exact Production guards, invalid currency/money/date/tracking fail-closed behavior and legacy `.xls` rejection.
- Finance actual-last-mile focused: **4 tests / 34 assertions PASS** including same-currency actualization, cross-currency FX freeze, P6A estimate replacement and stronger-fact protection.
- P6A last-mile regression remains PASS and all three commands are registered: `logistics:import-carrier-bill`, `finance:materialize-actual-last-mile`, `finance:materialize-last-mile-estimates`.
- P6B migration on US local MySQL: isolated **apply → schema verify → rollback PASS**. Broader `migrate:fresh` remains independently blocked before P6B by the pre-existing Traffic migration index-name SQLSTATE 1059 issue; P6B introduces no new MySQL migration failure.
- Latest-main Architecture exact comparison: baseline and P6B both **8 tests / 131 assertions / identical 3 failures**; regression delta = 0.
- Latest-main Menu/AppShell exact comparison: baseline and P6B both **44 tests / 1,711 assertions / identical 4 failures**; regression delta = 0.
- New Application-layer architecture was manually corrected so carrier-bill persistence/orchestration lives under Logistics Infrastructure; Application retains parser/normalization only.
- PHP lint for touched PHP files PASS; `git diff --check` PASS; no scheduler, UI, menu, permission, route or persistent Production feature-flag change.
- Production carrier-bill import and Finance actual-last-mile apply remain **NOT AUTHORIZED / NOT EXECUTED**.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate [COMPLETE] → MVP1 Existing Operations / Tencent Docs Replacement → ERP-INQUIRY-M1 → Milestone Remote Release → MVP1.1 WooCommerce Inbound → MVP1.2 Tracking Outbound → MVP1.3 Sync Orchestration → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion [CURRENT] → Shopify and Additional Integrations → Production Hardening / Expansion
CURRENT_TRACK_PHASE_MAP: V1.1B Revenue Evidence Woo [COMPLETE] → V1.1C-P1 Product Cost Source [COMPLETE] → P2 Product Cost Materialization [COMPLETE] → P3 Fixed Cost Rules [COMPLETE] → P4 Transaction FX Snapshot [COMPLETE] → P5 Payment Fee [COMPLETE] → C1 Continuous Delta [COMPLETE] → P6A Estimated Last-mile [PRODUCTION ACCEPTED] → P6B Actual Carrier Bill Import/Replacement [CURRENT] → P6C Continuous Delta Integration → P7 Warehouse Cost → P8 KOL Commission → P9 Loss / Other Costs → V1.1C Final Reconciliation / Closure Gate → V1.1 Final Profit Readiness
COMPLETED_PHASES: V1.1B, P1, P2, P3, P4, P5, C1, P6A
CURRENT_PHASE: V1.1C-P6 Last-mile Cost / P6B Actual Carrier Bill Import / Replacement
CURRENT_WORK_PACKAGE: ERP-ACCOUNTING-V11C-P6B-ACTUAL-CARRIER-BILL-001
PACKAGE_STATUS: IMPLEMENTATION COMPLETE / MERGE CANDIDATE / PRODUCTION NOT AUTHORIZED
WAITING_PHASES: P6C, P7, P8, P9, V1.1C Final Reconciliation / Closure Gate, V1.1 Final Profit Readiness
BASELINE: issued from origin/main@d9d3964d0a11d991e4837a96ebf44e33f857c96c; merge candidate rebased cleanly onto origin/main@392747ef2dcd53440b3385a4429798994f9e612a with zero P6B file overlap in intervening PR #298 drift
GITHUB_STATE: branch feature/erp-accounting-v11c-p6b-actual-carrier-bill-001; Draft PR #297; local merge-candidate head a9bc2cdbeceb8d9957cc5eaf26d5f239d99b5ca0 before final evidence commit
SOURCE_CONFLICTS: AI/CURRENT_CONTEXT.md stale versus current Accounting track; Library candidate billing workbook columns unavailable to parser inspection, therefore provider-specific adapters are intentionally deferred
GLOBAL_GATE: BYL_ERP_CONTINUOUS_OWNER_AUTHORIZATION_V1 active; irreversible Production data write and Production deploy remain hard Owner gates
PRODUCTION_DEPLOYMENT_STATUS: NO — P6B code not deployed and no P6B Production data write authorized
NEXT_DEPENDENCY: push final merge-candidate evidence to PR #297, run GitHub checks, merge under continuous Owner authorization if no code regression; Production code deploy remains a separate hard Owner gate
EVIDENCE_AS_OF: 2026-09-14

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: planning value 6.25h / range 4.5–9.0h; final actual effective engineering time 3.35h
KNOWN_CURRENT_TRACK_REMAINING: P6C/P7/P8/P9 remain known packages; exact non-overlapping remaining hours UNKNOWN WITH EVIDENCE pending each package preflight
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE — no separate current architecture package estimate accepted for this Accounting track
MVP1_TOTAL_ESTIMATE: Historical program estimate not safely applicable to current Accounting expansion; UNKNOWN WITH EVIDENCE
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE in current merged context
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE in current merged context
MVP1_REMAINING: UNKNOWN WITH EVIDENCE — current Accounting work is post-original MVP accounting expansion
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE; cumulative horizons are not summed
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE — later packages require separate preflight estimates
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE — no complete accepted cross-program Work Log ledger was found in current preflight
ESTIMATE_CONFIDENCE: MEDIUM
ESTIMATE_AS_OF: 2026-09-14
ESTIMATE_METHOD: exact main/Production preflight; bounded Logistics import evidence + Finance FX/replacement + migration and high-risk US verification; provider-specific source adapters and Production apply excluded

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 1.0h planning value; range 0.75–1.25h
CORE_EXECUTION: 2.75h planning value; range 2.0–4.0h
TEST_AND_VALIDATION: 1.75h planning value; range 1.25–2.5h
REMEDIATION_BUFFER: 0.75h planning value; range 0.5–1.25h
ESTIMATED_EFFECTIVE_HOURS: 6.25h
ESTIMATE_RANGE: 4.5–9.0h
ESTIMATE_BASIS: frozen backend-only P6B using existing ShipmentCarrierBilling / Finance materializer / Accounting FX primitives, plus one Logistics evidence migration, canonical CSV/XLSX parser, guarded import/materialization commands and high-risk verification
OWNER_REVIEW: EXCLUDED
MERGE: INCLUDED
MIGRATION: INCLUDED
MIGRATION_FILE_AUTHORING: INCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
