# XLWMS single order — minimal duplicate and feedback gate

Owner instruction: retain existing single-order outbound chain, patch only minimum duplicate guard/results, defer R1-R7 full sync. Draft child of PR #504; not Production release.

## Root cause
OMS manually created C41704-007 / OBS0272610230RS and already shipped VP757B, while ERP 7679 remains pending with VP757A. Previous duplicate guard queried thirdOrderNo or platformOrderNo; it could miss manual OMS referOrderNo.

## Changes
- XlwmsOrderGateway.findExisting uses separate exact detail identifiers and bounded pageList read-only scan checking referOrderNo and third/platform alias fields. No fuzzy matching.
- Fail closed on unsupported pagination responses, network failure or exhausted limit of 50 x 100 rows.
- SingleOrderDispatch saves OMS matched reference field, existing order number, error code, and checked timestamp, returning Chinese-readable status_label.
- Before-create failures use blocked_preflight_error. After-create ambiguity uses uncertain_manual_reconcile. Neither permits blind retry.
- The historical C41704-007 must NEVER be recreated. No production/Woo/OMS modifications or auto sync.
- No visual page change pending existing UI approval; returned JSON and attempt preview support later inline display.

## Validation
68 PHP tests / 552 assertions PASS, TypeScript typecheck PASS, Vite build PASS in isolated US Mac mini worktree with independent Composer bootstrap.
API doc confirms outboundOrder/pageList endpoint. Live authenticated response fields are not verified; unsupported shape prohibits create.
Parent Actions blocked at account payment/spending gate, no CI green. No actual outbound request and no Production deploy.
