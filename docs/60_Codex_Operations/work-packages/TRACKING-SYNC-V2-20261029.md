---
type: approved-work-package
project: BYL ERP
status: R0_complete_R1_pending
issue: https://github.com/beyourlovercom/erpbeyourlovercom/issues/536
owner_authorization: approved_2026_10_29
baseline_main: ff11378f381f7a039c0ea06ea6c429bf396ec938
execution_provider: CLAUDE_CODE
production_release: requires_separate_owner_pass
---

# Tracking Sync V2 Engineering Contract

## Purpose
Woo-origin ERP shipments: tracking immediate send after valid shipment/edits,
single/bulk manual push from original domestic/oversea shipping lists,
bounded 30-minute automatic retries, Woo/AfterShip real end-to-end proof.
Preserve existing PR 533 Woo-to-ERP inbound half-hour scheduler.

## R0 read-only production proof
- Production current: woo-order-30m-pr533-on-pr534-20261029-ff11378f
- Existing Tracking Gateway and SyncOutbox safely enqueue, claim, retry, verify.
- Existing immediate writeback in DomesticTrackingReturnService,
  OverseaTrackingReturnService and OverseaManualShipmentService.
- Generic markShipped / updateTracking only persist Shipment + pending marker.
- Existing all-pending / selected / retry endpoints require global sync.operate;
  Vita Operations has only sync.view. Must add narrowly scoped permission.
- No half-hour tracking retry schedule.
- One historical tracking outbox PENDING (2/3 attempts, write failed),
  one due retry and one eligible order at audit time. NEVER auto-drain legacy
  rows at first scheduler enablement without approval.
- Existing gateway uses AfterShip-related Woo meta and verifies Woo readback,
  NOT proof that actual AfterShip plugin/UI ingested Tracking.

## Estimated Roadmap 12 to 19 engineering hours
- R0 (1–1.5h): Baseline, flags, backlog inspection; COMPLETE read-only
- R1 (2–3h): Generic shipment completion & tracking edit immediate dispatch
- R2 (2–3h): 30-minute bounded cron, atomic outbox claim and retry, legacy gate
- R3 (2–3h): Domestic/oversea fulfillment list manual single/bulk CTA
- R4 (1.5–2.5h): Status UX, reasons, scoped Vita/logistics authorization
- R5 (2–4h): Genuine Dev Woo / AfterShip integration tests and UAT
- R6 (1.5–2h): CI, Owner UAT, Production approval, canary and rollback
- If Woo metadata is incompatible with installed AfterShip plugin,
  additional scoped connector work estimated +2–4h (separate assessment).

## Non-negotiable implementation constraints
1. ONE_WORK_PACKAGE_ONE_BRANCH_ONE_PR. Only one provider edits worktree;
   GitHub main is truth. Complex cross-module implementation by US Claude Code,
   bounded exact fixes may be done by GPT + self-hosted DC.
2. Only after successful DB commit enqueue and dispatch Tracking. Do not run
   external HTTP inside transaction. Do not guess line items from SKUs.
3. Shipment shipped, correct Woo origin, explicit carrier+slug, tracking number,
   shipped_at and verified Woo line identities are required.
4. Immediate and periodic retry each behind independently controllable
   configuration; initial Production periodic flag OFF until legacy review.
5. 30-minute cron with onOneServer/withoutOverlapping, bounded batch size,
   per-order isolation, outbox idempotence; no duplicate Tracking or overwriting
   conflicting Woo remote values.
6. Manual action on existing domestic / overseas fulfillment tables, with scoped
   role checks. Vita permission must not imply global sync.operate.
7. Show pending, processing, verified, waiting retry, failed, conflict status
   with precise reasons. Admin UI desktop only.
8. Dev actual AfterShip plugin intake required; Woo meta readback alone is not
   final acceptance. Include two natural 30-minute tick observations on release.
9. PR merge permitted under repo governance after CI, but formal Production
   deployment/write and real data migration require separate explicit Owner PASS.
10. Preserve PR 534 and all later Production overlays; record exact rollback
    release and never restart READY computers or Local ERP services.

## Delivery gates
R0 closed: baseline/issue/branch/spec.
R1-R4 require code, tests and controlled UI; R5 true Dev integration.
R6 requires owner visual PASS and independent Production authorization.
