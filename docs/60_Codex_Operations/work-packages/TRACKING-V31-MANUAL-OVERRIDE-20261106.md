# Tracking V3.1 — Processing-only Woo AfterShip Sync + ERP 2.2 Shared

Date: 2026-11-06 Asia/Taipei | Issue #635 | Draft PR #637
Branch: feat/manual-woo-tracking-override-20261106

## Owner correction (SUPERSEDES earlier requirements)
- Owner did NOT authorize changing refunded, cancelled, failed, on-hold, pending, archived, or any other non-processing Woo order into completed. Prior interpretation of any status referred to ERP fulfillment status ONLY.
- Woo mother processing: existing per-row Sync Woo Tracking button may sync this fulfillment's Tracking into the mother Woo order's AfterShip; verify Tracking and then change Woo processing -> completed; verify remote Completed.
- Woo mother completed: ONLY when already completed by this exact manual V3.1 parent workflow, a linked A/B/C child may append its Tracking without any Woo order status mutation. This ensures B/C can be notified after A completed the mother. Do not permit arbitrary completed Woo orders to be rewritten.
- Any other Woo mother status, including refunded, cancelled, failed, on-hold, pending, is strictly blocked BEFORE Tracking write, with clear operator feedback. HTTP Woo gateway independently retains fail-closed protected statuses.
- ERP success postcondition: after verified remote AfterShip + Woo Completed, update the SELECTED ERP order's mutable workflow status to shared (2.2 Shared / 已通知). Record workflow_revision, history and audit. Never update other A/B/C child statuses or canonical shipment status. Root ERP shared changes only when root itself is the selected order. Existing split mother canonical fulfillment remains truthful.
- On any failed Tracking/status write or missing Woo identity, ERP Shared must NOT be recorded. Archived ERP orders must first be restored before changing their ERP workflow annotation.
- Only single-row force manual sync allowed. Batch sync remains old strict shipped/carrier/shipped_at rule, no protected state overrides or historical Outbox replay.

## Implementation and guards
- Existing Overseas and Domestic row actions use manual_override=true and show as long as ERP Tracking exists; upstream permissions remain restricted to formal logistics/operations/admin roles. Existing 30-minute automatic sync and retry are not changed.
- WooCommerceManualTrackingOverrideService resolves a credible Woo root reference, sends selected child package to real Woo mother, uses AfterShip order-level packages when Woo line identity unavailable; never guesses SKU. Preserve existing packages, idempotently re-send same Tracking.
- HTTP gateway allows validated order-level package line_items=[] but keeps original default protected Woo status guards; removed all explicit bypass parameters from contract, gateway, and test doubles.
- Auto WooCommerceOrderStatusOutboundService honors parent woo_manual_force_completed marker so next partial shipment does not revert completed to processing.
- Manual verified marker and SHA-256 Tracking fingerprint (in sync note) plus completed manual_sync guard list badge accuracy. If ERP Tracking changes, show pending until reverified, including if pending shipment draft changed.
- Persist root marker, selected child manual sync, selected child's verified marker, and selected workflow_status=shared / revision/history/audit inside ONE local DB transaction AFTER external Woo writes and verification.
- OrderWorkflowStatusService::setAfterVerifiedWebsiteTracking handles system/operator audit and idempotent workflow revisions, preserving auxiliary labels. No direct canonical order_status mutation.

## Tested
- New owner-corrected tests: non-processing Woo statuses (cancelled/refunded/failed/on-hold/pending/unrelated completed) cannot write Tracking or ERP Shared; Woo Tracking conflict or failed Woo status write never marks ERP Shared. Legacy gateway protected-state tests still pass.
- Existing local WordPress (WooCommerce 11.2.1 + AfterShip 1.18.5), with external HTTP/mail/webhooks blocked, mother #29: pending A and B, two preserved AfterShip packages, Woo Completed, selected A/B ERP status=shared, ERP mother status unchanged, native customer template displays both. E2E 1 test / 22 assertions PASS.
- Other UAT: tracking-only missing carrier uses AfterShip provider other, manually completed mother, idempotent repeat; no SKU guessing.
- Owner-corrected targeted Feature regression 123 tests / 1,088 assertions, zero failed, one intentionally skipped opt-in LOCAL test; explicit LOCAL Woo/AfterShip integration mother #29 passed 22 assertions.
- Preexisting full Feature suite failures: previous 2,195 tests / 6 errors / 55 failures, 10 opt-in skipped; pristine GitHub main vs branch identical 5 errors / 27 failures / 608 assertions on 87 representative failing tests. Do not misstate full suite PASS.
- npm ci / Vue TypeScript / Vite build PASS; owner-corrected source rechecked with vue-tsc and Pint PASS.

## Release gate
- Issue #635 and PR #637 remain open/Draft; no main merge, no Production deploy, no WWW writes from this correction.
- Previous PR #637 source allowed force-changing Woo refunded/cancelled; that logic is explicitly replaced in next commit, and PR title/body must be corrected.
- CI on owner-corrected source commit daa9f69e: ERP Production Stability, Orders+KOL Cross-Module, Order Trash Gate, WordPress Connector, KOL Scoped Logistics all SUCCESS. Final documentation-only commit requires exact-head CI recheck. Remaining: controlled Dev/Production single eligible processing real customer order Canary, remote AfterShip + Woo readback, then authorize release. No batch processing of historical orders.

## R3.5 — WooCommerce Customer Note shipment notification

Owner directly confirmed: WWW WooCommerce Customer Note emails are already enabled, and a customer-visible order note is mailed to the customer. Therefore use the existing WooCommerce REST order-notes API; no new email delivery integration/plugin.

### Operational contract
1. Explicit single-order manual "同步官网物流" writes package A/B/C to the real Woo mother AfterShip and verifies remote Woo Completed.
2. **A package and unsplit first shipment: NEVER call Customer Note.** The first Woo \`processing\` -> \`completed\` event is the sole customer email trigger. **Only B/C/D+ split packages arriving on an already-completed verified V3.1 Woo mother** create a customer-visible Woo REST order note with \`customer_note=true\` and GET readback. If B happens to ship first while Woo is still \`processing\`, it uses Completed email ONLY (no second message). No SKU/product description in any email.
3. Before creating a note, scan existing customer-visible Woo notes for the exact stable normalized message. Repeat clicks and retries reuse the existing note (no second POST) even when the ERP state was not updated because of a prior timeout. Bound pagination to a safe maximum; any ambiguity fails closed.
4. **ERP 2.2 Shared / 已通知**: A/first shipment only requires verified AfterShip Tracking and Woo \`completed\`; no Customer Note is expected, so its absence is NOT failure. B/C/D+ follow-up shipments require verified AfterShip and existing Woo Completed **plus a verified Customer Note**, then ERP Shared. Only follow-up notes have note IDs in audit. Failed eligible B+ Customer Note must NOT mark its ERP child Shared.
5. After verifying Woo Completed, persist root \`woo_manual_force_completed\` marker. B+ follow-up Customer Note retries on this mother are allowed without resetting Woo status. A never calls Customer Note, even when retried on a completed mother.
6. Never send Woo Customer Notes for cancelled, refunded, failed, on-hold or other non-eligible statuses. Automatic 30-minute Tracking outbound and its historic queue remain unchanged by this R3.5 change.
7. No guarantee of inbox delivery is implied. Verified Woo note creation means Woo accepted the customer email handoff. WWW actual send and inbox delivery should be checked using a safe dedicated Canary after code review and approval.
8. **Owner 2026-11-06 update: duplicate first-package notification ELIMINATED.** A creates zero Customer Notes, and the Woo Completed email is relied on. Later B+ creates one Customer Note per verified new package; it does not toggle Woo status again. If a non-A suffix is the FIRST shipment, it also omits Customer Note to prevent a duplicate Completed email.

### Evidence
- New separate WooCommerceCustomerNoteGateway interface and HttpWooCommerceCustomerNoteGateway; existing Woo Tracking interface/automatic callers not modified by note feature. Provider registration in existing SyncServiceProvider.
- New isolated HTTP mock gateway tests: note create + readback; repeat customer note reuses existing remote note with zero POST; Woo non-completed status aborts; unverified POST cannot claim success.
- Controller tests: Customer Note retry after failure leaves ERP Pending then succeeds; repeat after success creates zero duplicate notes.
- Corrected targeted regression **130 tests / 1,123 assertions PASS, 1 opt-in skipped**. Covers A no note, B-first no duplicate, C follow-up one note, B note failure retry, Woo status guards.
- Fresh independent LOCAL WooCommerce + AfterShip mother #32: A/B Tracking both preserved, Woo Completed, both ERP Shared; Woo native Customer Note check: **A=0, B=1, total=1**; B repeat no additional note; real local E2E **1 test / 28 assertions PASS**. Local fixture blocks outbound email/webhooks, so actual inbox delivery was not verified. Older mother #31 with two notes was PRE-correction and is superseded.
- Production/WWW NOT modified, PR remains Draft. AfterShip Cloud and actual WWW email delivery not verified.
