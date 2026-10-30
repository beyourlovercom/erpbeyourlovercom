# ShipOut / MorePlus R9 Production Read-only Candidate — 2026-10-30

## 0. Non-negotiable boundary
- Candidate is built only in US Mac isolated worktree: `/Users/a/AI/uat/shipout-r9/work/erp`.
- Base main SHA: `f8fa4954e42756d27dcc901c85790c43bc34e1f4`.
- GitHub branch exists: `feature/shipout-r9-production-readonly-20261030`; local changes are **NOT** pushed or merged.
- No real ShipOut API credentials, personal data or production inventory snapshots in Git or this document.
- No Production release, database migration, API POST, customer order change or Woo/AfterShip sync is authorized.
- The existing ShipOut sandbox transport is unchanged and still forbidden in production.
- All production READ / DISPATCH / APPLY flags remain OFF until separately authorized.

## 1. Candidate files
- `config/shipout.php` (production namespaced flags and blank-by-default credentials).
- `app/Modules/Logistics/Infrastructure/ShipOut/ShipOutProductionReadOnlyGateway.php` (fixed production URL, GET-only warehouse/product/stock).
- `app/Modules/Logistics/Infrastructure/ShipOut/ShipOutProductionInventoryAudit.php` (bounded snapshot, exact warehouse/organization, ERP verified SKU mapping).
- `tests/Feature/Logistics/ShipOutProductionReadOnlyGatewayTest.php` (11 tests).
- `tests/Feature/Logistics/ShipOutProductionInventoryAuditTest.php` (3 tests).

## 2. Official API contract
- Production prefix: `https://open.shipout.com/api/`; sandbox: `https://opendev.shipout.com/api/`.
- Header: developer `appKey`, customer `Authorization: bearer ...`, `timestamp` (ms), `version=1.0.0`, uppercase MD5 `sign` of `path + timestamp + version + secret` as documented.
- Read-only endpoints: `/open-api/oms/info/warehouse/list`, `/open-api/oms/product/queryList`, `/open-api/oms/stock/list`.
- Stock authorization requires real `clientOrgId`; warehouse is explicitly pinned to `warehouseId`.
- Actual dispatch preflight must use `availableQuantity` rather than total quantity.

## 3. Security and regression
- Production reader throws in nonproduction runtime, or when READ is OFF, or with missing credentials.
- Only fixed GET paths; HTTPS verification ON; redirects OFF; no retry; 5s connect / 15s total timeout.
- Provider error text, response body, credentials and customer PII are never returned in exceptions.
- Warehouse must exist in an authenticated warehouse list before the inventory audit proceeds.
- All product and stock pages are bounded to 10 pages x 100 records and must have complete, stable totals.
- Duplicate SKUs, wrong-warehouse stock and absent available quantities fail closed.
- All unmatched provider SKUs are flagged **unresolved**, never auto-approved.
- Historical sandbox modes 6/9/10 and Dede/Vita flow remain independent.

## 4. Test evidence
- PHP syntax lint: PASS. Latest main rebase base: f8fa4954; no overlap with new XLWMS files.
- Laravel Pint: local candidate files formatted.
- Targeted current-main sandbox + new production tests: **68 passed, 254 assertions** after Owner-provisional timezone preview regression (local UAT worktree, base f8fa4954).
- Real production authentication: **PASS via Owner local GET**, HTTP 200 and warehouse identity 1265864414750314497 (MOREPLUS).
- Real warehouse, products and `availableQuantity`: **read-only verified** as above. `clientOrgId=57893` is Owner-confirmed and matched successful stock query. No vendor-agent statement was provided. ERP Verified SKU Alias comparison has been performed read-only: 42/45 accepted, 3 held pending identity evidence/classification.
- Initial pre-rebase full local suite: **NOT GREEN** (1,949 passed, 303 failed, 7 warnings, 8 skipped, 1 deprecated). Observed unrelated isolated-environment failures include missing SQLite logistics_carriers and missing frontend build; architecture frozen file set was already inconsistent with current checkout. Diagnose upstream CI separately; do not call release ready.
- Partial Draft PR #580 head d8fd33a had a passing GitHub workflow, but that commit did NOT contain configuration or new test files, therefore it was NOT a complete R9 CI pass. Config was later committed on the same Draft PR. Tests remain missing from GitHub due to a safety gate; do not call the PR complete or mergeable by QA. Confirm exact-head CI only after all files are delivered and reviewed.
- No new migration; no provider POST endpoint implemented in this candidate.

## 4A. Real production R9-A evidence (Owner local output, 2026-10-30)
- Production gateway GET `/open-api/oms/info/warehouse/list`: `HTTP_STATUS=200`, `API_AUTH_PASS=True`.
- One warehouse exactly: `warehouseId=1265864414750314497`, name `MOREPLUS`; list validity PASS.
- Source: Owner-executed local-only signed request using protected AppKey, AppSecret and customer Bearer. No secret value recorded.
- Identity confirmation applies to this ONE warehouse only. `clientOrgId` subsequently Owner-confirmed as 57893, no additional vendor-agent statement.
- Production GET stock query with `clientOrgId=57893` from JWT `orgId` candidate returned `result=OK` (independent provider customer-ID confirmation still PENDING).
- R9-C owner-only snapshot: product SKU 69 (69 unique, status 1 reported on all), stock SKU 45 (45 unique), all stock SKUs present in product catalog, all 45 stock SKUs have positive availableQuantity, total available 1,999 units.
- Source: `/Users/a/AI/uat/shipout-r9/production-readonly-inventory-snapshot.json` (0600; local-only, no secrets).
- ERP canonical SKU and verified aliases NOT yet compared to the Production ERP database; catalog match is NOT ERP SKU verification.
- Owner explicitly confirms `clientOrgId=57893` for MOREPLUS. This is OWNER-CONFIRMED, independently accepted for the signed stock GET; not a ShipOut agent statement.
- Official ShipOut API standard explicitly defines all **time-type fields as UTC**; date-only fields have no inherent timezone (https://open.shipout.com/portal/zh/documentation/standard.html). Owner asks to show the timestamp in `Asia/Shanghai` for Chinese staff; that is a DISPLAY preference, not the source timezone. Concrete `shippedTime` sample validation is pending. `production_shipped_time_timezone_verified=false`, all Tracking Apply/Dispatch flags OFF.
- Preview example: provider naive `2026-10-30 10:00:00` parsed as documented UTC => China operator display `2026-10-30 18:00:00 Asia/Shanghai`; this is a conversion unit test, not proof of a real provider payload.
- R9 configuration: `production_shipped_time_source_timezone=UTC`, `production_shipped_time_display_timezone_preview=Asia/Shanghai`, `production_shipped_time_timezone_verified=false`, `production_tracking_apply_enabled=false`. No real tracking time apply before a live sample verifies provider contract consistency.
- Production credential and identity config defaults remain blank, and all production API flags OFF. Owner has confirmed `clientOrgId=57893` for later binding; no Production env was changed.
- R9-C local-only inventory helper available at `/Users/a/AI/uat/shipout-r9/verify_production_inventory.py`; fake-provider E2E PASS. Real product+stock GET verified by Owner local output; no outbound API POST.
- No shipment API POST; no Production runtime config changes.

## 4B. Production ERP Verified SKU Alias independent read-only audit (2026-10-31)
- Owner-approved clientOrgId `57893`; warehouse `1265864414750314497`; 45 live stock SKUs; read from protected 0600 ShipOut snapshot.
- Independent inspection used current Production ERP `VerifiedOmsProductIdentityResolver` in a DB-server READ ONLY transaction, then ROLLBACK. No ORM save, stock import, POST or new order. The existing Production release was not modified.
- Results: 42 canonical SKUs resolved, 3 unresolved; 0 duplicate ERP product IDs among 42 verified.
- Blocked SKU 1: ShipOut `BY489A` (available 702) currently aliases ERP `BY489A-Lubes`; alias active but `oms_verified_at` and `oms_identity_evidence` absent. DO NOT auto-approve.
- Blocked SKU 2: ShipOut `BA561A` (available 7) currently aliases ERP `VA561A`; alias active but `oms_verified_at` and `oms_identity_evidence` absent. DO NOT auto-approve.
- Blocked SKU 3: ShipOut `Logo Sign` (available 1), no ERP canonical or alias identity. Determine if this is a non-shippable warehouse ancillary item; do not assume it is a product or create Product automatically.
- Available stock counts are provider-reported, NOT yet accepted to overwrite ERP stock. No API order submission permitted for any unverified SKU.
- Pending: separate physical goods/evidence/owner verification for the two Alias rows, and explicit classification of `Logo Sign`; do not silently upgrade mapping based on matching name alone.

## 5. Live R9-A/R9-C close gates
1. Owner-only helper (not to be executed by remote MCP): `python3 /Users/a/AI/uat/shipout-r9/verify_production_readonly.py`. This prints no secret values and calls only production warehouse GET. An authorized local-only procedure must validate stored AppKey, AppSecret and customer Token against production using GET only; do not expose secrets in logs.
2. Capture only redacted HTTP result, verified organization, warehouse ID and inventory counts.
3. Bind verified production warehouse and organization IDs to an audited, protected runtime, without enabling dispatch/apply.
4. Confirm real product status field behavior, SKU alias mapping, available quantity, and full pagination.
5. ShipOut official standard states UTC for time fields; verify at least one real `orderShipments[].shippedTime` sample behaves as documented before any live Tracking Apply, otherwise stop and request provider clarification.
6. Review all discrepancies with Owner; unresolved identity or missing SKU = hard stop.

## 6. R9-E release and rollback conditions
- GitHub connector file write previously hit an OpenAI safety check. Do not bypass it by using another channel to push the blocked change.
- Require authorized GitHub branch file write, complete changed-file review and exact HEAD CI before requesting release.
- Production deploy requires separate Owner **pass** with explicit release SHA, backup and rollback marker.
- Deploy the reviewed version dark, all flags OFF; readback `/up`, version and production env boolean-only gates.
- No data migration for this candidate. Roll back to the recorded prior release if health/regressions fail.
- Do not drop existing shipment attempt or label tables.
- Preserve existing MorePlus XLSX manual flow and 3SM logic.
- First real outbound Canary is **R10**, a separate exact-order Owner approval; no automatic retry.

## 7. Supporting upstream docs
- https://open.shipout.com/portal/zh/documentation/introduction.html
- https://open.shipout.com/portal/api/stock