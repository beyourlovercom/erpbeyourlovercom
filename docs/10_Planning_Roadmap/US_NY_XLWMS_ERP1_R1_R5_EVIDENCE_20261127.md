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
