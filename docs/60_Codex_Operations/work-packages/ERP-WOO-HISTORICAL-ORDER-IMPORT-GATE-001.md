# ERP-WOO-HISTORICAL-ORDER-IMPORT-GATE-001

Status: READY_FOR_PRODUCTION / OWNER PASS REQUIRED

## Objective
Import the bounded WooCommerce historical order corpus into Production using the existing Woo inbound contract. Do not redesign Orders, Products, Customer, Logistics, or Woo identity semantics.

## Canonical baseline
- Repository: `beyourlovercom/erpbeyourlovercom`
- Branch: `feature/erp-woo-historical-order-import-gate-001`
- Baseline: `main@fc7c1160c607385b4da0a8d8be460caf1ac61cc9`
- First Import Gate: CLOSED / PRODUCTION ACCEPTED via PR #190.
- Production Woo read-only credential: configured; secret values remain outside Git/chat.
- Production Woo probe: HTTP 200.
- Existing Production Woo Orders before historical execution: 10.
- Existing Production Woo external refs before historical execution: 10.

## Frozen historical boundary
Historical execution MUST NOT include the active current-day window.

- Earliest Woo order: `2025-02-03T23:12:00`
- Frozen historical cutoff: `2026-09-04T23:59:59Z`
- Historical source order count through cutoff: **6,047**
- Current-day `2026-09-05 UTC` source orders already imported by First Import Gate: **10**
- Full scanned Woo corpus at gate preparation: **6,057** orders / **7,894** line items.

New orders on or after `2026-09-05` belong to incremental sync, not this historical operation.
## Read-only full-history scan evidence
A full Woo source scan was executed without creating ERP Orders and without calling SKU reconciliation observation writes.

- Source orders scanned: **6,057**
- Source line items scanned: **7,894**
- Items resolved to canonical Product: **7,876**
- Items unresolved: **18** across **18** orders
- Product mapping coverage: **99.772%**
- Resolution path: 7,876 via Woo Product external reference; 0 legacy Woo ID; 0 SKU/Alias fallback.
- Existing imported source orders discovered during scan: **10**
- Not-yet-imported source orders at scan time: **6,047**

Source status distribution across the scanned corpus:
- completed: 5,134
- failed: 361
- pending: 253
- processing: 149
- cancelled: 78
- refunded: 76
- on-hold: 6

All statuses are historical transaction truth and remain in scope because the existing historical importer intentionally requests Woo `status=any`.
## Bounded historical Product exception
The 18 unresolved historical line items are source-identity gaps, not current Product OS reconciliation failures.

Observed classes:
- `TOYCOD CARLA`: historical Woo line items with `product_id=0`, no variation ID, no SKU.
- `BEYOURLOVER FATIMA - PINK`: historical Woo line items with `product_id=0`, no variation ID, no SKU.
- `Sticker Design`: seven historical line items referencing Woo Product ID `24875`, no SKU, and no current Product external reference.

The existing Woo normalizer preserves each line item's `name` as `product_summary`, plus quantity and unit price. Therefore importing these records with `product_id = null` preserves historical transaction truth without inventing identity.

Forbidden remediation inside this gate:
- no Product creation for these historical rows;
- no name-based fuzzy mapping;
- no synthetic SKU;
- no Product external-reference fabrication;
- no change to resolver precedence.

Acceptance exception requested from Owner:
`HISTORICAL_NULL_PRODUCT_EXCEPTION = 18 ITEMS / EXPLICITLY BOUNDED`

Because all 18 source SKUs are blank, the current importer will not create SKU reconciliation observations for them.
## Frozen monthly execution chunks
Use calendar-month chunks because every observed month is far below the existing 10,000-order invocation ceiling. The final month stops at the frozen cutoff.

| Chunk | Date range UTC | Source orders |
| --- | --- | ---: |
| H01 | 2025-02-03 → 2025-02-28 | 10 |
| H02 | 2025-03-01 → 2025-03-31 | 108 |
| H03 | 2025-04-01 → 2025-04-30 | 82 |
| H04 | 2025-05-01 → 2025-05-31 | 137 |
| H05 | 2025-06-01 → 2025-06-30 | 337 |
| H06 | 2025-07-01 → 2025-07-31 | 228 |
| H07 | 2025-08-01 → 2025-08-31 | 478 |
| H08 | 2025-09-01 → 2025-09-30 | 446 |
| H09 | 2025-10-01 → 2025-10-31 | 465 |
| H10 | 2025-11-01 → 2025-11-30 | 470 |
| H11 | 2025-12-01 → 2025-12-31 | 415 |
| H12 | 2026-01-01 → 2026-01-31 | 375 |
| H13 | 2026-02-01 → 2026-02-28 | 322 |
| H14 | 2026-03-01 → 2026-03-31 | 305 |
| H15 | 2026-04-01 → 2026-04-30 | 242 || H16 | 2026-05-01 → 2026-05-31 | 345 |
| H17 | 2026-06-01 → 2026-06-30 | 293 |
| H18 | 2026-07-01 → 2026-07-31 | 453 |
| H19 | 2026-08-01 → 2026-08-31 | 461 |
| H20 | 2026-09-01 → 2026-09-04 | 75 |

Frozen historical total: **6,047** orders.

## Production execution sequence after Owner `pass`
1. Recheck Production `/up`, Woo probe, current Woo Order/ref counts, duplicate refs, failed jobs, and open SKU reconciliations.
2. Create and verify a fresh Production database-only encrypted backup before H01.
3. Execute H01 → H20 strictly oldest to newest using the existing `importHistorical(from, to)` contract.
4. After every chunk record source count, `seen`, `created`, `updated`, `failed`, `pages`, total ERP Woo refs, duplicate refs, null-Product item count, open SKU reconciliations, and failed jobs.
5. Require `seen = frozen source count` and `failed = 0` for each chunk.
6. Stop immediately on any count mismatch, unexplained failure, duplicate provider reference, or new unresolved SKU reconciliation.
7. Do not widen or change a date range mid-run without recording a new frozen count.
8. After H20, run final aggregate reconciliation against the fixed cutoff.

Expected aggregate historical result, assuming no pre-existing historical refs before H01:
- `seen = 6,047`
- `created = 6,047`
- `failed = 0`
- historical duplicate refs = 0
- historical null-Product items = exactly 18 bounded exceptions
Final Production expectation immediately after historical reconciliation:
- existing First Import Gate refs: 10
- historical refs: 6,047
- aggregate Woo refs at the frozen snapshot boundary: 6,057
- duplicate Woo refs: 0
- open SKU reconciliations attributable to this operation: 0

Any Woo orders created after the snapshot are outside this aggregate and must be handled by incremental sync.

## Current gate state
`HISTORICAL_SOURCE_SCAN = PASS`

`HISTORICAL_SOURCE_ORDERS = 6047`

`HISTORICAL_SOURCE_ITEMS_FULL_SCAN = 7894`

`HISTORICAL_PRODUCT_MAPPING_COVERAGE = 99.772%`

`HISTORICAL_NULL_PRODUCT_EXCEPTION = 18 ITEMS / OWNER ACCEPTANCE REQUIRED`

`HISTORICAL_CHUNK_PLAN = FROZEN / 20 MONTHLY CHUNKS`

`HISTORICAL_PRODUCTION_WRITE = NOT_EXECUTED`

`WOO_INCREMENTAL_AFTER_CUTOFF = OUTSIDE_THIS_GATE`

Current stop marker:
`WOO_HISTORICAL_ORDER_IMPORT_GATE = READY_FOR_PRODUCTION / OWNER PASS REQUIRED`

A new Owner `pass` authorizes only the frozen H01 → H20 historical operation, including acceptance of the explicit 18-item null-Product historical exception. It does not authorize any Product identity fabrication or unrelated Production changes.
## Production execution after Owner pass — partial stop at H09

Owner authorized the frozen historical import gate. A fresh Production database-only backup was created before historical writes:
- backup run `#13`
- UUID `51a05d04-47e7-4847-a2bd-bd923c9ca593`
- state `ready`
- exactly one encrypted artifact
- live SHA-256 verification `PASS`

H01 through H08 passed exactly: source count = seen = created, `updated=0`, `failed=0`, duplicate Woo refs `=0`, SKU reconciliations `=0`, failed jobs `=0`. After H08, Production contained `1836` Woo refs including the 10 current-day First Import Gate orders, and `15` bounded null-Product historical items.

H09 (`2025-10-01` through `2025-10-31`) stopped the gate:
- source / seen: `465`
- created: `462`
- failed: `3`
- Production Woo refs after stop: `2298`
- duplicate refs: `0`
- SKU reconciliations: `0`
- failed jobs: `0`

The exact failed Woo IDs are `29486`, `29660`, `29662`. All three are `created_via=admin`, have zero `line_items`, and are intentionally rejected by the existing Order normalizer (`WooCommerce order <id> has no line items.`).

Classification:
- `29486`: fee-only admin shell, USD 10.00, no product line.
- `29660`: resend-shipping admin shell, USD 10.00, references original Woo order `29217`.
- `29662`: resend-shipping admin shell, USD 10.00, references original Woo order `29091`.
- Original product orders `29217` and `29091` each have one real line item and are already present in ERP.

Do not synthesize an Order item, SKU, Product identity, or fabricated external reference for these three rows. They are not representable as canonical product Orders under the frozen inbound contract.

New explicit gate:
`HISTORICAL_NON_PRODUCT_ADMIN_EXCEPTION = 3 SOURCE ROWS / OWNER ACCEPTANCE REQUIRED`

If Owner accepts this exception, historical acceptance is redefined as:
- frozen source rows through cutoff: `6047`
- canonical product Orders expected from historical import: `6044`
- excluded non-product admin shells: exactly `3` (`29486`, `29660`, `29662`)
- plus current-day First Import Gate refs: `10`
- expected canonical Woo refs after historical closure: `6054`, unless a separately authorized incremental import has added newer refs.

Current stop marker:
`WOO_HISTORICAL_ORDER_IMPORT_GATE = PAUSED_AT_H09 / WAITING_OWNER_ACCEPTANCE_OF_3_NON_PRODUCT_ADMIN_SHELLS`

H10 and later chunks have not executed.

## H10-H20 pre-scan after Owner acceptance of first 3 admin shells
Owner accepted the H09 non-product admin-shell exception with `pass`.

Before resuming writes, a read-only pre-scan was executed for `2025-11-01T00:00:00Z` through `2026-09-04T23:59:59Z` using the frozen Woo Order normalizer against all remaining 3,756 source rows.

Result:
- remaining source rows: `3756`;
- pages: `38`;
- normalizer failures: `26`;
- every failure is `InvalidArgumentException: WooCommerce order <id> has no line items.`;
- every failed source row is `created_via=admin` and `line_items=0`;
- no other normalizer failure class was observed.

Monthly zero-line admin-shell counts:
- 2025-11: `1`
- 2025-12: `0`
- 2026-01: `2`
- 2026-02: `1`
- 2026-03: `3`
- 2026-04: `0`
- 2026-05: `6`
- 2026-06: `7`
- 2026-07: `2`
- 2026-08: `4`
- 2026-09-01..04: `0`
Exact additional zero-line Woo IDs:
`30964, 32899, 33209, 33799, 34183, 34464, 34983, 36497, 36513, 36632, 36735, 37060, 37128, 37293, 37487, 37521, 37688, 37974, 37987, 37998, 38464, 38820, 39430, 39804, 39976, 39978`.

These 26 rows are the same structural class as the 3 H09 exceptions already accepted: non-product admin shells with zero canonical product line items. No synthetic Product, fake SKU, guessed product identity, or synthetic line item is permitted.

Combined historical non-product admin-shell set:
- H09 accepted: `3`;
- H10-H20 pre-scan: `26`;
- total explicit excluded source rows: `29`.

Revised canonical historical expectation:
- frozen Woo historical source rows: `6047`;
- explicit non-product admin-shell exclusions: `29`;
- canonical historical product Orders expected: `6018`;
- First Import Gate canonical Woo Orders already present: `10`;
- expected canonical Woo refs after historical closure: `6028`, unless a separately authorized incremental import adds newer refs before final reconciliation.

Current Production remains paused after H09 with `2298` Woo refs, duplicate refs `0`, SKU reconciliation `0`, failed jobs `0` at the H09 stop evidence point.

New hard gate:
`HISTORICAL_NON_PRODUCT_ADMIN_EXCEPTION = 29 SOURCE ROWS TOTAL / 26 ADDITIONAL OWNER ACCEPTANCE REQUIRED`

Current marker:
`WOO_HISTORICAL_ORDER_IMPORT_GATE = PAUSED_AFTER_H09 / H10-H20_PRE_SCAN_COMPLETE / WAITING_OWNER_ACCEPTANCE_OF_26_ADDITIONAL_NON_PRODUCT_ADMIN_SHELLS`

## Resume after Owner acceptance of all 29 admin-shell exclusions — schema stop at H15
Owner accepted the complete 29-row zero-line non-product admin-shell exclusion set with `pass`.

A fresh midpoint Production database-only backup was created before resuming historical writes:
- backup run `#16`
- UUID `baceb2dd-ee0f-440f-82d0-c623e3a055ca`
- state `ready`
- exactly one encrypted artifact
- live checksum verification `PASS`

H10 through H14 then passed under the frozen exclusion-aware reconciliation contract:
- H10 2025-11: source `470`, created `469`, expected exclusion `30964`, unexpected failures `0`
- H11 2025-12: source `415`, created `415`, failures `0`
- H12 2026-01: source `375`, created `373`, expected exclusions `32899, 33209`, unexpected failures `0`
- H13 2026-02: source `322`, created `321`, expected exclusion `33799`, unexpected failures `0`
- H14 2026-03: source `305`, created `302`, expected exclusions `34183, 34464, 34983`, unexpected failures `0`

At H15 (`2026-04`) the gate stopped on a new canonical-order failure:
- source / seen: `242`
- created: `241`
- failed: `1`
- failed Woo ID: `35260`
- duplicate refs: `0`
- open SKU reconciliations: `0`
- failed jobs: `0`
- Production Woo refs after stop: `4419`
- bounded null-Product items persisted so far: `17`

Woo `35260` is a normal checkout product order, not an admin shell. Its product identity is healthy: Woo product `26152`, SKU `VF644A`, and all resolver paths point to ERP Product `1338`.

The failure is a lossless snapshot capacity issue only: normalized `address_line_2` is `268` characters while Production `order_addresses.address_line_2` remains `varchar(255)`. The transaction rolled back and provider ref `35260` was not created.

A read-only schema-length scan of all `1869` source orders from `2026-04-01` through `2026-09-04` found exactly one schema overflow: Woo `35260`, `address_line_2`, length `268`, limit `255`. No other Order, address, item name, SKU, phone, city, postal-code, or external-order-number field exceeded the current schema.

Bounded fix prepared on branch:
- migration `2026_09_05_170500_expand_order_address_line_2_for_woo_history.php`
- only change: `order_addresses.address_line_2` from `varchar(255)` to `varchar(512)`
- no truncation, no Product resolver change, no normalizer guessing
- rollback refuses to shrink while values longer than 255 exist, preventing silent data loss
- regression test adds a 300-character Woo address-line-2 snapshot case
- patch commit: `24c33119d888671e9e2873f3eefaa39fbb13df5b`

Isolated runtime verification on the Production host passed using a temporary SQLite database and the real Woo inbound service:
- all migrations including the new expansion migration: PASS
- one fake Woo order with 300-character `address_2`: `seen=1 / created=1 / failed=0`
- stored `address_line_2` length: `300`
- stored address equals source exactly: `true`
- Product mapping: PASS
- Woo external reference count: `1`

Production remains unchanged at this stop: `/up=200`, `woo_refs=4419`, duplicate refs `0`, open SKU reconciliations `0`, failed jobs `0`, and `address_line_2` is still `varchar(255)`.

New hard gate:
`HISTORICAL_ADDRESS_SNAPSHOT_CAPACITY_FIX = CODE_READY / ISOLATED_RUNTIME_PASS / PRODUCTION_SCHEMA_NOT_CHANGED`

Current marker:
`WOO_HISTORICAL_ORDER_IMPORT_GATE = PAUSED_AT_H15 / WAITING_OWNER_SCHEMA_DEPLOY_PASS`

A new Owner `pass` authorizes only the bounded schema/deploy continuation: create another fresh DB backup, deploy this branch candidate, apply the single 255→512 migration, verify Production schema and health, import Woo `35260`, then continue H16→H20 under the already accepted 29-row admin-shell exclusion contract and perform final aggregate reconciliation.

## Production schema deploy and H16-H20 closure
Owner authorized the bounded Production schema deploy with `pass`.

Fresh pre-schema backup:
- backup run `#18`
- UUID `747b2ae8-a5c7-4e88-a69d-53480771d49f`
- state `ready`
- one encrypted artifact
- live SHA-256 verification `PASS`

The first deploy attempt used an unscoped `migrate --force` and exposed pre-existing Production migration-ledger drift: two unrelated Packaging migrations (`2026_09_03_120000`, `2026_09_03_121000`) were still pending and executed before a third Packaging migration failed on a MySQL identifier-length limit. The release symlink was automatically restored before any target migration ran.

Recovery was bounded and data-preserving:
- the four Packaging tables created by that attempt were verified to contain exactly `0` rows;
- only those four empty tables were dropped;
- only the two newly-added Packaging migration ledger rows were removed;
- `order_addresses.address_line_2` remained `varchar(255)`;
- `/up` remained HTTP `200`.

The target migration was then executed path-scoped only:
`2026_09_05_170500_expand_order_address_line_2_for_woo_history`

Result:
- `order_addresses.address_line_2 = varchar(512)`;
- target migration ledger count = `1`;
- unrelated Packaging migration ledger counts remain `0`;
- `/up = 200`.
Woo `35260` was then imported successfully:
- `seen=1 / created=1 / updated=0 / failed=0`;
- Product `1338`, SKU `VF644A`;
- persisted `address_line_2` length `268`;
- SHA-256 equality against the Woo source snapshot `PASS`;
- provider ref count `1`.

H16-H20 all passed under the already accepted admin-shell exclusions:
- H16: `345 seen / 339 created / 6 accepted exclusions`;
- H17: `293 / 286 / 7 exclusions`;
- H18: `453 / 451 / 2 exclusions`;
- H19: `461 / 457 / 4 exclusions`;
- H20: `75 / 75 / 0 failures`.

Final set-level reconciliation re-fetched the frozen Woo historical corpus through `2026-09-04T23:59:59Z`:
- source IDs: `6047` across `61` pages;
- source read errors: `0`;
- explicit admin-shell exclusions: `29`;
- expected historical canonical IDs: `6018`;
- missing historical refs: `0`;
- excluded refs present: `0`;
- First Import Gate refs present: exact expected `10`;
- unexpected extra refs: `0`;
- total canonical Woo refs: `6028`;
- duplicate refs: `0`;
- bounded null-Product items: `18`;
- open SKU reconciliations: `0`;
- failed jobs: `0`;
- `order.inbound_created` audits: `6028`;
- `order.inbound_updated` audits: `2` (idempotency evidence);
- `/up = 200`.

Final marker:
`WOO_HISTORICAL_ORDER_IMPORT_GATE = PASS / PRODUCTION_ACCEPTED`

`WOO_HISTORICAL_CANONICAL_ORDERS = 6018`

`WOO_TOTAL_CANONICAL_REFS_AFTER_FIRST_IMPORT_PLUS_HISTORY = 6028`
