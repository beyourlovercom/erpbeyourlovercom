# ERP Allocation Guidance V1.2 — Owner-approved bounded remediation

- Work Package: ERP-ALLOCATION-GUIDANCE-V1.2-20261104
- Scope authority: Owner approved V1.2 visual plan and requested repair, 2026-11-04
- Implementation provider: GPT_WEB + US Mac BYL DC, dedicated worktree only
- Verification tier: DAILY (read-only list projection; no schema / API / lifecycle change)
- GitHub base: beyourlovercom/erpbeyourlovercom @ origin/main, exact head recorded in PR
- Target page: Logistics / FulfillmentAllocation.vue

## Root cause
Existing FulfillmentAllocationDecisionService permits the official cost-ranked system
recommendation only when comparison evidence is complete and every plan has ready
cost/currency. Missing weight, packaging, rates or FX can therefore make the
list say "需要人工判断" despite a regional priority or known warehouse inventory.

## Frozen implementation
1. Keep the server-side complete-cost recommendation and submission permissions unchanged.
2. Add a strictly read-only suggestion projection built from returned order items,
   canonical warehouse codes, confirmed stock snapshot and server-approved candidate plans.
3. Show complete-cost recommendation, credible warehouse candidate, multi-warehouse choice,
   unverified inventory, confirmed priority-warehouse shortage or actual manual exception.
4. Show specific reasons and keep gift, rejected, incomplete SKU and uncertain US region gated.
5. Keep modal and Vita manual confirmation; optional row "确认分仓" opens existing modal only.
6. Tab counts are page-scoped; never claim a local filter exhausts server pagination.

## Verification / deployment boundary
- Vitest targeted: 21 / 21 PASS; TypeScript typecheck PASS; Vite build PASS.
- Full frontend: 705 PASS / 5 known baseline failures in two untouched Inventory suites.
- The same five failures reproduced at unmodified base a1a2b215.
- No Production mutation, shipment creation, inventory reservation, data migration,
  API dispatch, or automatic change to warehouse eligibility.
- Merge, UAT acceptance and Production release remain separate Owner gates.
