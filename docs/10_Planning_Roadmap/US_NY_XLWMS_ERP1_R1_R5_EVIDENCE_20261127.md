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
