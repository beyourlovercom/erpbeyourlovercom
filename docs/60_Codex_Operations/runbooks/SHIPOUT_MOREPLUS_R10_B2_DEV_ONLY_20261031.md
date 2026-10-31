# ShipOut/MorePlus R10-B2 — Production single-order Canary Dev-only acceptance

**Related:** [Issue #584](https://github.com/beyourlovercom/erpbeyourlovercom/issues/584) • R9 PR #580 • R10-A PR #585 • R10-B1 PR #586

## Scope and nonauthorization

- R10-B2 delivers **isolated backend code and offline tests** for an owner-approved single Production ShipOut outbound request. It does **not** authorize or perform an API request, release, database mutation, inventory change, shipping record update, Woo/AfterShip sync, production toggles, or startup of any job.
- Current active Production remains **R9 dark code-only**, `SHIPOUT_PRODUCTION_READ_ENABLED=false`, `SHIPOUT_PRODUCTION_DISPATCH_ENABLED=false`, `SHIPOUT_PRODUCTION_TRACKING_APPLY_ENABLED=false`; R10-B1 `production_canary_execute_enabled` additionally defaults false.
- No web route, scheduler, queue worker, public UI, or CLI write command is created. R10-C requires **independent exact-order Owner PASS**, controlled Production deployment authorization for write-capable code, and a separately reviewed execution entrypoint.

## Provider fixed contract

- Official [ShipOut order API](https://open.shipout.com/portal/zh/api/order.html): `POST /open-api/oms/order/batchSubmitAsync`; `sysServiceId=9` for warehouse-selected shipping service; `carrierId=9`; only one `shipmentForm`, one provider order, one warehouse.
- Only endpoint: `https://open.shipout.com/api/open-api/oms/order/batchSubmitAsync`, signed with existing `ShipOutSignature`, strict HTTPS, redirect disabled, connectTimeout 5s, total 15s, no auto retry.
- Any async `result=OK`, matching `sid=1`, matching `orderNO` and nonempty `orderId` means **accepted for processing**, never `shipped`.
- `ORDER/queryListV2` GET is the only supported duplicate-check/readback path in R10-B2. It is fixed, PII-redacted, no recipient or address output. A pending/non-visible async order is **not** permission to resend.
- Under the official API contract, `shippedTime` is UTC as a source time; operator display `Asia/Shanghai`; Tracking Apply is separately gated and not included.

## R10-B2 isolated implementation

1. **Read-only candidate preflight:** requires production environment + production READ gate + exact configured org `57893`, warehouse `1265864414750314497`, numeric ERP ID allowlist, matching allowed warehouse reference, US-LA pending Dede-approved USPS order, US address, blank tracking, ship date in a bounded 7-day Los Angeles local window.
2. Pure `ShipOutOutboundPayloadFactory` now accepts an optional explicit allowed-mode override `['9']`, preserving existing Sandbox defaults. Production always uses mode 9 and carrier ID 9; modes 6/10 and label uploads remain disabled.
3. **Fresh provider product/stock GET** via the R9 production inventory auditor (bounded all-page retrieval, verified org/warehouse, active product catalog status, `availableQuantity` not `standardQuantity`, no unverified SKU). The first Canary additionally requires the **exact ERP item physical SKU** (case differences permitted) and positive stock. No different color/package/model alias substitution.
4. Read-before-write `orderPage` must return precisely zero provider records and `total=0`. Missing/untrusted provider responses fail closed.
5. The read-only preview exposes a sanitized order ID, warehouse reference, provider SKU+qty, mode 9 and SHA-256 payload fingerprint. The actual customer address exists only inside the internal raw payload; never returned in the preview.
6. Owner gate requires an explicit separate flag, exact order ID and reference, matching fingerprint, typed confirmation `SUBMIT_SHIPOUT_ORDER_<ERP-ID>`, required credentials and expiry no later than one hour in UTC. **All gates default OFF**.
7. **One-shot ledger:** before any network POST, insert `shipout_dispatch_attempts` with unique `order_id` and `order_no`, status `in_flight`, payload hash, warehouse. Immediately before HTTP, transport atomically changes status to `sent_once`. Any subsequent attempt is denied regardless of outcome. Provider timeout/unrecognized result => `uncertain`, manual reconciliation, **never auto retry**.
8. Explicit provider validation failure => `rejected`; verified async OK => `acknowledged` and provider order ID; both remain irreversible for automatic retry.
9. **R10-B2 readback:** `ShipOutProductionCanaryReconcileService::inspect()` requires a persisted prior attempt and exact ERP warehouse reference; it queries signed provider order list. Results are `NOT_YET_VISIBLE_MANUAL_REVIEW` or `ORDER_VISIBLE_MANUAL_REVIEW_ONLY`; wrong provider order ID blocks. Both `shipped=false` and `tracking_apply=false` and **no changes to ERP/Woo/AfterShip**.
10. No external provider network requests or Production data are used in tests: Laravel `Http::fake` + `Http::preventStrayRequests` with synthetic identity and shipping data.

## Independent Owner release / first-order gates

- `R10-B2_DEPLOYED_TO_PRODUCTION=NO`.
- `R10_REAL_FIRST_POST_AUTHORIZED=NO`.
- `R10_C_ELIGIBLE_ORDER=NONE` based on prior read-only snapshot. ERP order `6826` contains `VA617A-PINK x1`; ShipOut product catalog lists `VA617A-Pink` but historic stock snapshot has no matching available physical stock. `VA617A` and `VA617A-Green` are **not** substitutes.
- New real order candidate must be selected/reviewed; owner approves **exact ERP ID, order reference, warehouse, SKU+qty, date, mode, payload fingerprint**, after a fresh stock/provider duplicate scan.
- Any Production deploy with new POST-capable code requires its own Owner release approval and rollback plan; Owner's earlier conditional R9 code-only PASS does not authorize R10-B2 Production installation.
- First real POST must be separately approved immediately before execution and observed only once. The provider can asynchronously accept the request without creating a shipped package; do not auto-complete ERP.
- No attempt to rotate/transfer/test provider Production credentials in this Dev-only scope.

## Test and CI evidence

- Base GitHub main when worktree created: `be91e9478de8bf4c20b27179337850cc50847e41` (R10-B1 merged).
- New focused PHPUnit: one signed simulated submit, verified hash, duplicate provider order, provider-stock exact-SKU and positive availability, color alias rejection, cross-warehouse, missing flags, prior attempt, concurrent insertion, timeout/unknown, explicit rejection, malformed async ACK, no repeat, provider ID reconciliation, no side effects.
- Existing R9–R10 tests remain in the exact-head GitHub workflow and the newly added R10-B2 PHPUnit test is now included. Any new source SHA requires rerun.
- Before moving this branch to review/merge record local focused counts, PHP lint/Pint, exact SHA CI and Production OFF readback.

## Local UAT checkpoint (US Mac / Dev-only)

- Full ShipOut targeted regression **116 passed, 422 assertions, 1 Local Woo/Vita E2E skipped** because independent Local Woo environment is not present.
- Pint **12 named files PASS**, PHP syntax of legacy payload factory PASS, `git diff --check` PASS.
- HTTP calls in R10-B2 tests were all Laravel `Http::fake` and `Http::preventStrayRequests`, not real ShipOut production requests.
- Successful simulated async ACK persisted a single irreversible attempt and left ERP shipment status pending with empty Tracking. Subsequent duplicate dispatch returned fail-closed; simulated timeout persisted `uncertain` with manual reconciliation required.
- An independently simulated concurrent insert during provider duplicate GET blocked the other submit before POST; direct transport without a prior persisted lock was blocked.
- Readback scenarios: provider order invisible after ACK and subsequently visible with matching provider ID, both with `shipped=false`, `tracking_apply=false`; mismatch remains blocked.
- Exact color `VA617A-PINK` cannot be replaced by generic `VA617A` even if that SKU is manually verified to the same ERP product; case-only `VA617A-Pink` may be recognized if independently verified and real available stock positive.
- The Production runtime is NOT upgraded. Before any future Production deployment, refresh latest main, complete exact PR SHA GitHub CI, perform separate Owner release pass, and stage immutable rollback.
