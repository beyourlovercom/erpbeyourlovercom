# ERP Global Final SKU Resolution V2

Status: OPEN — implementation candidate; production release and ERP2 browser parity not certified.
Provider: GPT_WEB / US Mac mini dedicated worktree. Risk: HIGH (outbound SKU identity).

## Contract
- One resolver for all outbound paths. In verified mode resolve against actual shipment warehouse and current product identity, regardless of materialized final_sku being blank or stale. FACTORY uses canonical product SKU unless a verified override exists. Overseas requires verified physical mapping; never substitute unverified source SKU. Preserve gift and existing shipping/stock/review/duplicate gates.
- Read-only export never mutates order items. Authorized pending-order business writes may materialize final_sku and resolved_fulfillment_sku atomically and audit the change; never rewrite source SKU, shipped/tracked/archived/historical orders or orders with provider attempt evidence.
- Whole-batch download isolates unresolved orders; selected-order download is exact or rejects. Expose exported/blocked counts and first five blocked order details. Keep the ERP1 page and feedback island on error or partial success.

## Production read-only evidence (2026-11-27)
187 assigned unshipped orders / 283 item lines; 46 missing and 3 stale final SKU lines are resolvable; 74 lines lack a verified mapping. Factory: 11 missing, 0 stale, 0 unresolved. MorePlus: 30/3/6; US-SM: 1/0/59; US-NY: 1/0/0; CA: 2/0/5; DE: 0/0/4; US Amazon: 1/0/0. This is an inventory of pending records, not a certification of shipping eligibility. Order 007-1118-k8198 item 79444 reproduces the original failure (VA617A, final_sku null). No production writes performed.

## Gates
R1 read-only audit complete; R2 resolver implemented; R3 write-path healing implemented; R4 production inventory complete, bulk application not performed (requires separate reviewed plan, backup, provider evidence and authorization); R5 ERP1 batch isolation and feedback implemented, ERP2 adapter and actual browser parity remain open; R6 local regression and exact-head GitHub CI required; R7 controlled dual-runtime deployment and release record remain open. Do not mark live repair or claim verified mapping for the unresolved 74 lines. No independent hotfix release. ERP2 reuses canonical Laravel business rules rather than implementing a second SKU engine.
