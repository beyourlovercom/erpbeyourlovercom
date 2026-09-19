# ERP Historical Fulfillment Reconciliation HFR-7 — Evidence Boundary — 2026-09-19

## Status

READ-ONLY DISCOVERY CLOSED / EXTERNAL EVIDENCE REQUIRED

## Starting point

HFR-5 and HFR-6 are already CLOSED / OWNER UAT PASS and must not be replayed.

HFR-5 created 197 exact historical customer Shipments. HFR-6 created another 530. HFR-6 idempotency proof ended at:

- READY: 0
- REVIEW: 3,627
- SKIP: 1,727

The remaining 3,627 REVIEW candidates are intentionally fail-closed.

## Highest-yield remaining blocker

The latest frozen HFR-6 discovery breakdown identified:

- missing approved historical warehouse evidence: 2,966 candidates;
- missing approved carrier/service evidence: 106 candidates.

Therefore any HFR-7 evidence-recovery package should prioritize row-level historical warehouse evidence, not carrier/service expansion.

## Existing exact evidence contract

HFR matching remains exact-only. Approved HFR-5 warehouse aliases were source-backed row-level evidence, including:

- `USA 美西仓` -> `US-LA`
- `美东仓` -> `US-NY`
- `Factory 国内仓` -> `FACTORY`
- `Canada 加拿大仓` -> `CA`

`Europe 欧洲仓`, external-procurement warehouses and unknown labels remained unresolved. Current routing policy or current Warehouse Master must not be used to retroactively infer historical warehouse truth.

In particular, HFR-7 must NOT assume:

- `Europe 欧洲仓` -> `DE`;
- an unspecified West Coast label -> `US-SM`;
- a current destination/routing rule proves an old warehouse;
- KOL evidence proves customer-order fulfillment.

## Evidence recovery search — 2026-09-19

The following locations were searched without Production mutation:

- HFR-5 worktree;
- HFR-6 post-merge worktree;
- US Mac Downloads/Desktop/Documents/AI trees;
- macOS Spotlight using frozen historical warehouse strings;
- ChatGPT Library semantic/title search.

Available evidence retained on US Mac:

- `/Users/a/Downloads/hfr5-us-warehouse-service-evidence.json`
  - version `hfr5-us-warehouse-service-evidence-v1`;
  - 675 resolved tracking/service records;
  - 0 conflicts;
  - 15 source invoice files represented.

Not found:

- the original Tencent historical customer logistics/warehouse source used by HFR-5;
- another exact row-level customer warehouse source capable of resolving the dominant 2,966 warehouse-evidence gaps.

Spotlight returned source-code/docs and `0902-网红订单.xlsx` variants for some warehouse terms. Those files are KOL evidence and remain prohibited for customer Shipment promotion under the frozen HFR isolation contract.

ChatGPT Library contains the canonical HFR handoff but not the Tencent raw warehouse source.

## Decision

No HFR-7 Production write is authorized or useful at this evidence state.

Do not regenerate speculative READY candidates. The next HFR mutation may proceed only after receiving a new exact historical customer warehouse source, such as the original Tencent logistics export or another row-level source containing exact customer Order identity + exact tracking + source-backed warehouse.

When such evidence becomes available:

1. regenerate current Woo/HFR candidates from current Production state;
2. adapt only exact source facts;
3. run HFR-3 fresh dry-run;
4. produce new source SHA / scan SHA / plan SHA / READY-REVIEW-SKIP counts;
5. stop before Production apply for an exact Data Write Gate.

## Accounting relationship

The Accounting completeness audit found 3,718 revenue-ready Orders without canonical Shipment as of 2026-09-19. This is a broader current population than the frozen HFR-6 REVIEW=3,627 set and must not be treated as the same manifest without a fresh candidate regeneration.

HFR-7 therefore remains an evidence-coverage dependency for historical First Mile / Handling / Last Mile truth, but Finance must not manufacture Shipment or cost facts to improve completeness metrics.

## Safety

- no Production data write;
- no schema/runtime code change;
- no Woo write/sync;
- no Shipment/ShipmentAttempt mutation;
- no Finance mutation;
- no KOL-to-customer promotion;
- no current-routing historical inference;
- no ERP Changelog update.
