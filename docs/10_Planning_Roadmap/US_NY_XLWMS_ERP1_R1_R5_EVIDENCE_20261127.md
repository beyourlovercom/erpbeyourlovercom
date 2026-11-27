# ERP1 US-NY XLWMS R1-R5 evidence (2026-11-27 Beijing)

Scope: ERP1 only. ERP2 no deploy, no modifications to ERP2-specific code.
Source baseline: origin/main d646cdd7; independent branch feature/erp1-us-ny-xlwms-r0-r1-20261127.
Live read-only calls from US Mac mini: signature, warehouse options, product/pagelist, integratedInventory/pageOpen, logistics/channel/list, outboundOrder/pageList and exact order readback. No secrets or customer data in this evidence.

- Warehouse US01 present in account options (3 warehouse options).
- Product catalogue: 30, 3 complete pages. Good stock ledger: 25, 3 complete pages.
- Physical 945, available 941, locked 4, invalid stock rows 0; 5 catalogue-only entries never implicitly zero.
- Channels: USPS-NJ, UPS-NJ, GOFO-NJ, Upload_Shipping_Label, No_Shipping_Service. Returned whCodes empty; first 10 historic list records all US01 / USPS-NJ. Other channel dispatch remains unverified.
- Account outbound list: 811. Exact existing US01 order lookup PASS, code 2, tracking present.
- Actual ERP production warehouse mappings, quantities and pending order set not audited in this run. No inventory apply or order create sent to OMS/production.

Candidate:
- Encrypted warehouse-scoped interactive provisioning supports US-NY / US01.
- Shared strict parser, NY-specific verified mapping, independent Dev apply gate/checksum, V2 isolation.
- Complete duplicate scan (up to 300 pages with bounded timeout), NY payload preflight with exact mapping and historical USPS channel; stricter identity for API-confirmed order readback.
- Production NY apply and OMS create remain fail-closed. No new UI batch execution / tracking-apply implementation yet; no actual ERP1 page UAT.
- Automated verification: 141 tests / 829 assertions PASS on disposable SQLite, with fake provider for write path tests.
- Required next: actual ERP1 read-only mapping comparison, safe credential provisioning in trusted terminal, real Dev page, NY independent durable batch execution and tracking staging; then separately approved live-order canary and ERP1-only release.

## Production read-only audit / Owner one-order canary authorization
- OMS login: https://oms.xlwms.com/. Account email supplied by Owner; no login/password or email access used.
- ERP1 active release: erp-product-master-v3-pr889-d646cdd7-20261127. Warehouse US-NY ID 2. No encrypted production XLWMS credential binding exists.
- Production DB inspected in READ ONLY transaction. 25 OMS stock rows: 14 existing verified warehouse mappings, 11 unresolved; nine unresolved have exact active canonical identities and zero OMS stock; two require separate physical identity evidence (6971796990292, TL278B-Black). Barcode 6971796990292 (499 units) has no exact ERP barcode_69 or GTIN match; existing alias unverified. No mapping or inventory write.
- Major differences: BY489A ERP 615 / OMS 102; MF414A 64 / 0; VA617A 254 / 210 (207 available); MW35 3 / 0; TL278A 7 / 0.
- Eight pending_shipment rows in US-NY, two with tracking already present; six without tracking. Four VA617A quantity-one orders are eligible for operations and address-complete but ALL workflow pending_review, without approved overseas warehouse review.
- Candidate selected: CY30767-007 (8403), USPS Priority Mail, VA617A x1. Full 82-page account scan of 811 OMS outbound records complete, no exact third/platform/reference match for any of four candidates. This is timestamped read-only evidence, not a permanent duplicate clearance; recheck immediately before create.
- Candidate and other three pending-review orders must not be submitted until normal operator approval. Other two tracking-free orders lack actual OMS stock. Owner's one-order live test authorization acknowledged; no actual OMS create, no production writes or production release. Production credential binding, first inventory apply, separate safe dispatch execution/UI and tracking apply remain pending.
- Candidate patch: exact production service name USPS Priority Mail mapped to USPS-NJ, selected-order batch preflight rejects wrong warehouse, pending review, existing attempts and stale/unapplied inventory; no provider writes. ERP2 untouched.

## Approved order recheck
- Owner reports review completed; Production readback confirms CY30767-007 / id 8403, workflow sending, review approved for US-NY ID 2, reviewer user ID 19 (active logistics role), shipment pending, VA617A x1, exact final SKU VA617A, USPS Priority Mail, no tracking or prior attempt.
- Production release still d646cdd7; encrypted NY credential binding missing. Review actor identity is not assumed to be the remote executor.
- Single-order canary service added: independent default-off exact order/authorized actor configuration, durable attempt claim, complete duplicate scan, live channel/catalog and stock preflight, final payload fingerprint recheck, one create, exact identity readback, uncertainty terminal state. HTTP fake test demonstrates one create despite unrelated unmapped SKU and no duplicate on retry, while shipment/Woo remains pending. Production unchanged.
- 143 targeted tests / 844 assertions pass locally. No actual CREATE issued. Before live canary: deploy accepted candidate through ERP1-only release gate, bind US01 encrypted credentials via existing trusted interactive terminal, explicitly authenticate the actual logistics operator and run exact canary. Do not bypass via ad hoc provider POST or impersonate reviewer. Batch UI/automatic tracking apply and actual production inventory sync remain open.

## Owner authorizes merge/release and one live order
- CLI exact order and confirmation gate added; system operator is audited as system, reviewer remains actual user 19. Default-off, process-local system authorization and exact order ID; no web route for CLI.
- Signed identity readback checks all three references and warehouse. Tracking staging accepts numeric domestic USPS 20–34 digits only, preserves existing conflicting number, requires pending shipment and approved review, does not mark shipped or sync Woo.
- Tested full CLI path with HTTP fake, wrong confirmation no network, duplicate retry blocked, identity mismatch and tracking overwrite blocked. 144 focused tests / 878 assertions PASS.
- Automatic local-file credential import action blocked by platform security check; not implemented or retried. Use existing trusted interactive credential provisioning command after accepted ERP1 release. No production credential binding, real CREATE or tracking writes in this run.
- Prior PR head 1dad9184 all four CI checks PASS; latest head requires fresh exact-head CI before merge.
