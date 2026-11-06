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
- Previous targeted Feature regression 114 tests / 1,014 assertions PASS (pre-correction). Re-run final correction suite and cite real count.
- Preexisting full Feature suite failures: previous 2,195 tests / 6 errors / 55 failures, 10 opt-in skipped; pristine GitHub main vs branch identical 5 errors / 27 failures / 608 assertions on 87 representative failing tests. Do not misstate full suite PASS.
- npm ci / Vue TypeScript / Vite build PASS (pre-correction; front-end source not changed by correction), re-check if required.

## Release gate
- Issue #635 and PR #637 remain open/Draft; no main merge, no Production deploy, no WWW writes from this correction.
- Previous PR #637 source allowed force-changing Woo refunded/cancelled; that logic is explicitly replaced in next commit, and PR title/body must be corrected.
- CI review exact new HEAD; single eligible processing real customer order Canary, remote AfterShip + Woo readback, then authorize release. No batch processing of historical orders.
