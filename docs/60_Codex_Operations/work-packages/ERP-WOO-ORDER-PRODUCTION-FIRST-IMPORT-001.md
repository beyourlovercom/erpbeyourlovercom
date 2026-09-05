# ERP-WOO-ORDER-PRODUCTION-FIRST-IMPORT-001

Status: READY_FOR_PRODUCTION / OWNER PASS REQUIRED
Execution provider: GPT_WEB + Remote Desktop Commander
Production write authorization: NOT GRANTED
Real Woo order write: NOT EXECUTED

## Objective
Prepare and execute the first real WooCommerce order import into canonical ERP Orders with the smallest possible Production blast radius.

This is a Production data gate, not a Woo Order OS rebuild.

## Reconciled baseline — 2026-09-05
- Repository: `beyourlovercom/erpbeyourlovercom`.
- Lane branch: `feature/erp-woo-order-production-first-import-001`.
- Lane worktree: `/Users/a/AI/007/worktrees/erp-woo-order-production-first-import-001`.
- Original handoff baseline `6f854b9fa0b1e144fd8455a3e05ca2147839d8bf` advanced during execution.
- Current reconciled `origin/main`: `a470b13a1b89077b1da9461587541647b35fc1bb` (Product OS Final Closure / Real Data Readiness #192).
- Main has advanced through Inventory PR #188 and Product OS Final Closure PR #192; Lane B was rebased on exact current main before this readiness transition.
- Lane B contains documentation/runbook evidence only; no Woo/Orders application code has been modified.
- Production is a bounded composition and is not assumed to equal current `main`.

## Frozen scope
1. Verify existing Production Woo inbound runtime contract.
2. Freeze secure Production credential provisioning and rollback procedure.
3. Freeze a read-only Woo API probe and one-order source inspection procedure.
4. After Lane A readiness and explicit Owner Production authorization, create a Production DB backup and import exactly one real Woo order.
5. Re-import the same Woo order and prove idempotency.
6. After canary PASS, import one explicit small historical date window and reconcile source vs ERP counts.
7. Prepare full-history chunking strategy; do not auto-run full history from this gate.

## Explicit exclusions
- No Woo inbound redesign.
- No Product, Inventory, Logistics, Sync shared implementation, menu or global Roadmap change.
- No Product auto-creation.
- No fuzzy SKU or Customer identity matching.
- No Woo Customer Master matching/backfill in this lane.
- No full historical import before Lane A readiness and successful canary/small-window evidence.
- No Production secret value in Git, chat, logs, tests or audit payloads.

## B1 — Runtime contract verification
### Production runtime
Actual current Production pointer on the US Mac:
`/Users/a/AI/production/erp.beyourlover.com/current`
→ `releases/purchase-os-v1-loginfix-20260904-235807-a36e9d`

Public/runtime evidence:
- `/up` = HTTP 200.
- unauthenticated `/sync/woocommerce` = HTTP 302 to authentication flow.
- Production route list contains all five expected Woo inbound routes:
  - `woocommerce.inbound.index`
  - `woocommerce.inbound.probe`
  - `woocommerce.inbound.single`
  - `woocommerce.inbound.incremental`
  - `woocommerce.inbound.historical`

### Existing Woo inbound behavior
Current implementation supports:
- read-only connection probe;
- single provider-order import;
- incremental import with overlap-safe modified timestamp cursor;
- explicit historical date-range import;
- Woo pagination at 100 rows/page;
- hard safety limit of 100 pages / 10,000 rows per one historical invocation;
- idempotent identity by `(provider, connection_key, reference)`;
- canonical Order / OrderAddress / OrderItem / external-reference persistence;
- Woo currency/status/order number/provider modification timestamp;
- Product resolution by strong Woo product/variation identity then canonical SKU/SKU Alias;
- unresolved SKU preserved explicitly without Product auto-creation;
- inbound create/update audit records.

### Exact-revision focused verification
US verification worktree was created detached at exact `main@1d7cd0e`.
- Vite production build: PASS.
- `tests/Feature/WooCommerceInboundTest.php`: PASS, 9 tests / 65 assertions / zero failures.
- Initial page-test failure before the build was only missing `public/build/manifest.json`; after building the exact revision, the full focused suite passed.

### Global Architecture baseline note
`tests/Architecture/ModuleArchitectureTest.php` currently fails on exact unmodified `main@1d7cd0e` because frozen module/migration file-set snapshots lag already-merged KOL / Notification / Procurement / Logistics / other mainline additions.

This failure exists before any Lane B tracked-file change and is not a Woo inbound regression. Lane B must not repair those cross-lane architecture snapshots.

Marker:
`GLOBAL_ARCHITECTURE_BASELINE_DRIFT = PRE_EXISTING / OUT_OF_LANE_B_SCOPE`

## Current Production credential presence
Presence only; values were never rendered:
- `WOO_BASE_URL` = MISSING
- `WOO_CONSUMER_KEY` = MISSING
- `WOO_CONSUMER_SECRET` = MISSING
- `WOO_CONNECTION_KEY` = SET
- `WOO_TIMEOUT_SECONDS` = SET

Production release `.env` is a symlink to:
`/Users/a/AI/production/erp.beyourlover.com/shared/.env.production`

Laravel config cache is currently absent; runtime config presence matches the environment presence above.

A bounded search of existing canonical local/US ERP environment files found no reusable real Woo URL/key/secret. `.env.example` files contain placeholders only.

## B2 — Frozen credential provisioning plan
### Required credential
Use a dedicated WooCommerce REST API credential with **Read** permission only for the BeYourLover WooCommerce store.

Required Production values:
- `WOO_BASE_URL`
- `WOO_CONSUMER_KEY`
- `WOO_CONSUMER_SECRET`

Existing Production values remain authoritative unless a verified conflict exists:
- `WOO_CONNECTION_KEY`
- `WOO_TIMEOUT_SECONDS`

### Secret handling
- Never paste the Consumer Key or Consumer Secret into chat.
- Never pass secret values as visible shell arguments.
- Secret entry must occur through a local hidden-input/secure credential step on the US Production host.
- Never commit Production credentials.
- Never copy an unrelated broad-scope Woo key merely because one is available elsewhere.

### Exact Production mutation boundary after Owner `pass`
1. Freeze the exact target store URL and Read-only Woo REST key.
2. Create mode-600 rollback copy:
   `shared/.env.production.pre-woo-first-import-<timestamp>`.
3. Update only `WOO_BASE_URL`, `WOO_CONSUMER_KEY`, `WOO_CONSUMER_SECRET`; preserve the existing connection key and timeout unless separately proven incorrect.
4. Preserve `.env.production` mode 600.
5. If a Laravel config cache exists at execution time, clear/rebuild it through canonical Artisan commands; currently no config cache exists.
6. Verify only `SET/MISSING` state through Laravel config, never values.
7. Verify `/up` remains HTTP 200.

### Reversible rollback
If configuration validation/probe fails:
1. restore the pre-change `.env.production` snapshot;
2. clear any config cache created during the operation;
3. verify the three Woo secret-bearing fields return to their prior presence state;
4. verify `/up` HTTP 200;
5. do not execute any order import.

## B3 — Read-only probe and canary selection
After credential provisioning and before any ERP order write:
1. Execute existing Woo `probe()` contract. It performs Woo REST `GET /wc/v3/orders?per_page=1` and persists nothing.
2. Require HTTP success from Woo.
3. Fetch one recent order through the existing Woo gateway with `per_page=1`, `status=any`, newest-first ordering.
4. Print/store only bounded non-secret canary metadata needed for the gate: provider order ID, Woo order number, status, currency, created/modified time and line-item count. Do not expose customer PII in chat evidence.
5. Confirm the chosen provider ID has no existing ERP external reference for the active Woo connection key.

`B3_DB_MUTATION = NONE`

## Customer identity boundary discovered during reconciliation
Customer OS was added after the original Woo inbound implementation.

Current Woo inbound:
- persists recipient name, phone and shipping/billing address snapshot into `order_addresses`;
- does not persist Woo billing email in the Order snapshot;
- does not set `orders.customer_id`;
- does not create or infer Customer Master identity.

Customer V1 governance explicitly permits historical `orders.customer_id = NULL` and excludes Woo identity matching/backfill/fuzzy identity inference.

Therefore Lane B canary must verify the **order recipient/contact/address snapshot**, not claim a Customer Master linkage that does not exist.

Marker:
`WOO_CUSTOMER_MASTER_LINKAGE = DEFERRED / NOT_A_LANE_B_BLOCKER / CUSTOMER_ID_NULL_IS_VALID`

Any future deterministic Woo Customer identity integration must be a separate Customer↔Order integration Work Package and must not be smuggled into this Production gate.

## Lane A dependency
Required before the first real Woo order write:
`LANE_A = READY_FOR_PRODUCTION_INTEGRATION`

Lane A is now complete and merged:
- merged main: `a470b13a1b89077b1da9461587541647b35fc1bb`
- readiness marker: `LANE_A = READY_FOR_PRODUCTION_INTEGRATION` = PRESENT
- `PRODUCT_OS_REAL_DATA_READINESS = PASS`

Production Product identity read-only evidence at readiness transition:
- canonical Products: 247
- Products with SKU: 247
- SKU Aliases: 6
- Woo Product external references: 310
- open SKU reconciliations: 0
- existing Woo Order external references: 0

Production currently has the final Order-side resolver path (Woo external reference → legacy primary Woo ID compatibility → canonical SKU/SKU Alias), plus the required alias/reconciliation/external-reference tables. `product_provider_snapshots` is not present in Production, but that M1.6 surface is not required for Order item identity resolution.

For the first canary, every source line item must be proven resolvable against current Production Product identity before the write. Any unresolved item aborts the canary before persistence.

Reason: unresolved Product/SKU is technically importable, but the first formal historical import should not intentionally create avoidable reconciliation debt.

## B4 — Frozen one-order Production canary
Execute only after BOTH:
1. `LANE_A = READY_FOR_PRODUCTION_INTEGRATION`
2. Owner returns explicit `pass` for this frozen Production operation.

### Step 1 — Production DB backup
Use the canonical Backup engine, database-only type, before the real order write.
- Create `BackupType::Database` run.
- Execute it synchronously for this gate.
- Require final state `ready` and exactly one tracked encrypted artifact before proceeding.
- Record run UUID/checksum evidence without exposing encryption material.

If backup is not `ready`, STOP. No Woo write.

### Step 2 — Pre-import evidence
For the selected provider order ID:
- source metadata captured from B3;
- ERP external-reference count for `(woocommerce, connection_key, provider_order_id)` = 0;
- capture pre-import canonical Order count for reconciliation only.

### Step 3 — Import exactly one order
Use the existing `WooCommerceInboundService::importOne()` contract with an existing active Production administrator/operator as audit actor.

Expected summary:
- `seen = 1`
- `created = 1`
- `updated = 0`
- `failed = 0`

### Step 4 — Canonical verification
Verify exactly one canonical Order and exactly one Woo external reference.
Verify:
- provider reference / connection key identity;
- Woo order number → `external_order_number`;
- ERP Order identity allocated once;
- order kind = customer;
- source type = woocommerce;
- normalized status;
- currency;
- order date / provider modified timestamp;
- recipient name / phone / address snapshot;
- item count, quantity, unit price and line revenue;
- Product mapping for every item, or explicit unresolved `product_id = NULL` + preserved raw SKU + Product reconciliation evidence;
- `order.inbound_created` audit record;
- `orders.customer_id` expected to remain NULL under the current frozen Customer contract unless a separately approved integration has superseded it before execution.

### Step 5 — Idempotency re-import
Re-import the same Woo provider order ID.
Expected summary:
- `seen = 1`
- `created = 0`
- `updated = 1`
- `failed = 0`

Require:
- total canonical Order count for this provider reference remains 1;
- external-reference count remains 1;
- ERP Order ID unchanged;
- source snapshot may refresh, but no duplicate Order/Item identity is created;
- `order.inbound_updated` audit record exists.

If any invariant fails, STOP before historical import.

## B5 — Small historical window
Only after B4 PASS.

Default first window: the single calendar day containing the canary order. If that day is abnormally large, freeze a smaller explicit safe date after inspecting source counts.

For the frozen window record:
- source Woo count;
- ERP `seen`;
- created;
- updated;
- failed;
- pages;
- unresolved Product item count;
- each failure class and affected provider reference.

Acceptance:
- `failed = 0`, or every nonzero failure is individually understood and explicitly resolved before widening;
- imported provider references are unique under `(woocommerce, connection_key, reference)`;
- source count reconciles to ERP seen count for the exact window;
- unresolved Product mappings are zero for the intended first formal historical run after Lane A closure, unless explicitly accepted by Owner as a bounded exception.

## B6 — Full historical readiness
Do not execute as one unbounded request.

After B5 PASS:
1. read source historical min/max order dates;
2. divide history into explicit non-overlapping date chunks sized safely below the existing 10,000-row invocation ceiling;
3. run chunks oldest → newest;
4. persist per-chunk source/seen/created/updated/failed/pages/unresolved evidence;
5. stop on any unexplained failure class or count mismatch;
6. run final provider-reference uniqueness and aggregate count reconciliation.

Full history remains a separately frozen Production data operation; do not silently auto-run it from development preparation.

## Current gate state
`B1_RUNTIME_CONTRACT = PASS`
`B2_CREDENTIAL_MUTATION_PLAN = PASS / READ_ONLY_INSTALLED`
`B3_READ_ONLY_PROBE_PLAN = PASS / HTTP_200`
`WOO_FOCUSED_TESTS = PASS`
`PRODUCTION_CREDENTIALS = PASS / READ_ONLY`
`LANE_A_DEPENDENCY = PASS`
`REAL_ORDER_WRITE = PASS / ONE_ORDER_CANARY`
`SMALL_WINDOW_IMPORT = PASS / 2026-09-05_UTC`
`FULL_HISTORY_IMPORT = NOT_EXECUTED / SEPARATE_PRODUCTION_OPERATION`

Current stop marker:
`LANE_B = FIRST_IMPORT_GATE_PASS / FULL_HISTORY_NOT_EXECUTED`

## Production execution evidence after Owner `pass` — 2026-09-05
- Owner explicitly authorized the frozen Production First Import Gate with `pass`.
- Production health before mutation: `/up` HTTP 200; `orders=0`; Woo order external references `=0`; failed jobs `=0`.
- Current bounded Production release: `inventory-real-data-v1-rc-20260905-a36e9d-plus-4902bca`.
- Production Product identity readiness observed: `products=247`; `products_with_sku=247`; Woo Product external references `=310`; SKU Aliases `=6`; open SKU reconciliations `=0`.
- Canonical Production database-only backup created before any Woo order write.
- Backup run: `#11`; run UUID `ece3cdfa-7a50-4c51-8ecc-5d7ffcc655f1`; state `ready`; exactly one encrypted artifact.
- Backup artifact: `byl-erp-database-20260905-111356.tar.zst.enc`; size `1464888` bytes.
- Backup SHA-256: `d95600f7c034ba65c850519779f24d929653e7ca91f30ead5c8547c99eba9f2c`; live file checksum verification `PASS`.
- No Woo credential values were found in existing controlled ERP environment/config files.
- Existing WordPress Connector signed channel does not expose Woo Order read or Woo REST-key provisioning; it cannot replace the required Woo Read-only credential.
- A direct cross-device secret-transfer attempt was rejected by platform safety controls and was not bypassed.
- WooCommerce REST API Keys admin page was opened on the Owner China Mac for the required Owner-only credential creation action.

Current blocker:
`PRODUCTION_WOO_READONLY_CREDENTIAL = PASS / OWNER_ENTERED_SECURELY`

The Owner credential gate was cleared without rendering Woo secret values into chat or repository history. Production Woo read-only probe returned HTTP 200 before any order write.

## Next exact gate
Owner returns:
`pass`

That single bounded authorization permits only this frozen sequence:
1. create/securely install the dedicated Read-only Woo REST credential into Production;
2. verify credential presence without rendering values;
3. execute the existing read-only Woo probe;
4. select one recent source order read-only;
5. prove every line item resolves against current Production Product identity; if any item is unresolved, STOP with no order write;
6. create and verify a Production database-only encrypted backup;
7. import exactly that one canary order;
8. verify canonical Order/address/items/external-reference/audit invariants;
9. re-import the same order and prove idempotency;
10. if canary PASS, execute only the frozen small historical window and reconcile exact counts.

Full historical import remains excluded from this `pass`.


## Production first-import completion evidence — 2026-09-05
- Dedicated Woo REST credential was entered by Owner through the Production-host local terminal flow; secret values were never rendered into chat or committed.
- Production Woo credential presence: URL/key/secret/connection-key/timeout all `SET`.
- Read-only Woo probe: `ok=true`, HTTP `200`.
- Production moved to bounded Product OS release `product-os-m15-m16-bounded-20260905-041413-98405b11` before canary execution; `/up` remained HTTP `200`.
- Read-only candidate scan checked the 10 newest Woo orders. Every line item in all 10 candidates resolved to a Production Product; canary Woo order `40198` had two mapped items and no unresolved Product identity.
- Pre-canary Woo order external-reference count for provider reference `40198`: `0`.
- One-order canary first import: `seen=1`, `created=1`, `updated=0`, `failed=0`, `errors=[]`.
- Canonical canary ERP order ID: `007erp-0905-c1`; source `woocommerce`; kind `customer`; status `pending_shipment`; currency `CAD`; address persisted; 2 items persisted; one Woo external reference persisted.
- Canary item Product bindings: ERP Product `1350` / SKU `gift`; ERP Product `1352` / SKU `VA617A-GREEN`.
- `customer_id = null` remains expected under the frozen Customer identity boundary; no implicit Customer Master linking was introduced.
- Source order had no phone value; ERP phone remained empty by source truth, not by import loss.
- Same-order idempotency re-import: `seen=1`, `created=0`, `updated=1`, `failed=0`; ERP order ID unchanged; provider-reference count remained `1`; canonical order count remained `1`.
- Audit evidence includes `order.inbound_created` followed by `order.inbound_updated` for the same canonical order.
- Frozen small historical window: `2026-09-05T00:00:00Z` through `2026-09-05T23:59:59Z`.
- Read-only small-window preflight: 10 Woo orders / 15 items / 1 page / 100% Product mapping / 0 unmatched items.
- Small-window import: `seen=10`, `created=9`, `updated=1`, `failed=0`, `pages=1`, `errors=[]`.
- Post-window reconciliation: Woo canonical orders `10`; Woo external references `10`; duplicate refs `0`; Woo items with `product_id = null` `0`; open SKU reconciliations `0`; failed jobs `0`.
- Production health after import: `/up` HTTP `200`.

Acceptance result:
`WOO_PRODUCTION_FIRST_IMPORT_GATE = PASS`

`WOO_ONE_ORDER_CANARY = PASS`

`WOO_IDEMPOTENCY = PASS`

`WOO_SMALL_WINDOW_RECONCILIATION = PASS`

`FULL_HISTORICAL_IMPORT = NOT_EXECUTED / REQUIRES_SEPARATE_FROZEN_PRODUCTION_OPERATION`
