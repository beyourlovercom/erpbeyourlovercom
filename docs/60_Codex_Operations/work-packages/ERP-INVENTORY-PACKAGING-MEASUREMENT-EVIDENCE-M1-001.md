# ERP-INVENTORY-PACKAGING-MEASUREMENT-EVIDENCE-M1-001

Status: `WAITING_OWNER_UAT / FACTORY_INPUT_PAGE`

Owner pre-development visual proposal: `PASS`, 2026-09-03. Factory input redesign visual proposal: `PASS`, 2026-09-04. Current bounded implementation provider: `GPT + US RDC`.

Branch: `feature/erp-inventory-packaging-measurement-evidence-m1-001`
Worktree: `/Users/a/AI/007/worktrees/erp-inventory-packaging-measurement-evidence-m1-001`
Baseline: `origin/main@ec725cef57762f7ddbdf067bfcfaf1bbce9a2f78`

## Frozen scope

Inventory-owned packaging and measurement evidence history: canonical `product_id` relation, optional existing warehouse, draft/submitted/approved/rejected workflow, private local image evidence, factory submitter workspace, internal review workspace, authenticated media route, audit events, and one approved Inventory level-2 menu entry `包装与测量`.

Product master fields, Product360, ProductWeightEvidence adoption, WooCommerce sync, procurement, supplier data, pricing, finance, GMC/Google Ads, production deployment, and real-data migrations are explicitly out of scope.

## Acceptance criteria

- Factory operators need only `inventory.packaging.submit`; they can create/edit/upload only their own drafts and cannot mutate submitted, approved, or rejected history.
- Submission requires all three dimensions, packaged weight, measured time, at least one dimension/measurement image, and at least one weight image. A separate finished-packaging photo is no longer required.
- Images are JPEG/PNG/WEBP only, <= 10 MiB each, stored on private/local disk under `inventory/packaging-evidence/{record}/{kind}`.
- Reviewers with `inventory.packaging.review`, and read users with `inventory.view`, can review/read all records; media is never public-hotlinked.
- Review captures reviewer and time; audit authority is reused; no Product truth is changed.
- Existing Inventory/Product/weight-evidence regressions remain green.

## Permissions and security

Added `inventory.packaging.submit` and `inventory.packaging.review` to the authorization enum/catalog and local-only UAT seeding. The minimal factory role has only submit capability. Controller ownership checks are independent of UI visibility. Media responses require authenticated read authorization and use `private, no-store` headers.

## Migration contract

`2026_09_03_120000_create_packaging_measurement_evidence_tables.php` creates `packaging_measurement_records` and `packaging_measurement_media` with the frozen foreign keys/indexes. Down migration drops media before records. It has not been run against Production.

## UAT scenarios

1. Log in as factory operator, open `/inventory/packaging`, create a draft from canonical product search, save, upload dimension/measurement and weight images, and verify no pricing/Woo/finance fields appear.
2. Attempt incomplete submit and verify validation; complete dimensions/time and submit.
3. As admin/reviewer, filter submitted records, open detail, inspect grouped private gallery, approve or reject with note, and verify history metadata.
4. Verify factory cannot open another operator's record/media and cannot edit any non-draft record.
5. Verify menu shows only the authorized Packaging & Measurement child for factory and full Inventory menu remains unchanged for admin.

## Local UAT handoff

Use a fresh local/testing database and `php artisan migrate:fresh --seed --seeder=LocalUatSeeder` (never Production). Credentials: `admin@local.test` / `password`; factory: `factory@example.test` / `password`. Start the normal local app at `http://erp.b`, then open `/inventory/packaging` in the factory session and `/inventory/packaging` in the admin session. Synthetic fixtures and image files must remain local-only and must not be committed.

Git delivery: `WAITING_OWNER_UAT / FACTORY_INPUT_PAGE`. Owner passed the reviewer/detail page on 2026-09-03, then requested explicit live review of the factory data-entry/upload page before Git delivery. Commit/push/PR/merge remains paused. Packaging Production deployment remains prohibited.


## Owner UAT remediation — product-specific factory packaging SOP

Owner UAT added a required usability extension on 2026-09-03: evidence remains grouped with its matching measurement, and the same detail page must also expose the selected product's factory packaging instructions so different products can use different packaging methods.

Implemented in the current uncommitted WP:
- Added `product_packaging_sops`, keyed one-to-one by `product_id`; no global/default SOP is leaked to products without an active record.
- SOP carries method, scenario, materials, ordered steps, tips, examples and an optional version label.
- Packaging detail renders the product SOP below evidence cards, with desktop multi-column steps and a single-column mobile flow.
- Local UAT product `PKG-UAT-001` represents `压缩枕头 / Compressed Pillow` with a `气泡袋包裹` SOP and six factory steps.
- Focused feature coverage verifies product-specific isolation; responsive browser UAT verifies 390px width without horizontal overflow.

This remediation adds no new level-1/level-2 menu item and no new permission.

Owner reviewer/detail live-page UAT: `PASS`, 2026-09-03. Overall WP remains `WAITING_OWNER_UAT` until the factory input/upload page is explicitly reviewed and passed. Packaging Production deployment remains prohibited.


## Owner UAT remediation — preset methods + per-product illustration

Owner visual PASS on 2026-09-04 expands the factory input experience within the same Packaging M1 business scope:
- Factory selects one of four code-defined preset packaging methods: `原包装套快递袋直接发`, `加纸箱`, `文件气泡袋`, `气泡袋裁剪`.
- Each preset exposes its own default illustration and step tutorial; selecting a preset changes the guide shown on the form.
- `packaging_measurement_records.packaging_method_key` preserves which method was actually used for that evidence record.
- A product may own one active Product Packaging SOP carrying `preset_key`, default illustration, optional product-specific rendered large illustration, ordered steps, tips and materials.
- Factory can switch between `默认示意图` and `产品专属渲染图` when the selected product/method has a rendered guide.
- If no preset is suitable, the factory may enter `定制包装方案`; product-specific steps are shown when configured instead of leaking another product's guide.
- Review/detail resolves the guide from the record's selected method. A record selecting `加纸箱` cannot display the product's unrelated `气泡袋裁剪` rendered guide.
- Measurement, weight and completed-package uploads remain grouped beside their matching data. Native file inputs are visually replaced by ERP upload dropzones; authenticated private media authority is unchanged.
- Mobile keeps the same task order, 390px no-horizontal-overflow requirement, and the global upper-right/top Page Header Action Zone rule.

UAT fixture `PKG-UAT-001 · 压缩枕头 / Compressed Pillow` uses `气泡袋裁剪` and includes both the default scheme illustration and a product-specific rendered large guide.

Current gate: implementation verified locally; desktop + 390px factory pages auto-opened at `http://127.0.0.1:18124/inventory/packaging/1/edit`; awaiting Owner live-page PASS. No commit/push/PR/merge/Production deployment yet.


## Owner UAT remediation — two-photo factory workflow

Owner feedback on 2026-09-04 simplified the per-record factory task. The operational order is now Product/Factory → Packaging dimensions + measurement photo → Packaged weight + scale photo → Notes → Packaging method reference. Packaging method selection/tutorial is reference-only and intentionally placed at the bottom so it does not compete with the two required evidence tasks.

The separate finished-packaging evidence card is removed from the factory form and reviewer evidence grid. Submission now requires `measurement` + `weight` media only; legacy `packaging` media remains readable for historical compatibility and no destructive migration is introduced. `packaging_method_key` remains optional metadata/reference and never blocks submission, including `custom` when no custom SOP is configured.

## Owner UAT remediation — admin / operations packaging reference management

Owner UAT identified a missing interaction loop: factory users could read product packaging references, but admin/operations users had no governed UI to upload the product-specific real packaging images that factory users should follow.

Implemented in the current uncommitted WP:
- Product360 now exposes a `包装方案` action for `products.operate` users; no new level-1/level-2 menu entry is added.
- `/products/{product}/packaging` is the product-owned admin/operations workspace for selecting the reference preset and maintaining product-specific packaging media.
- Admin/operations may upload/replace one product-specific main illustration, upload multiple real packaging case images, and optionally override the product default illustration.
- Uploaded packaging-reference media is JPEG/PNG/WEBP only, <=10 MiB each, stored on private Laravel `local` disk and served only through an authenticated permission-aware route.
- Factory users cannot enter the management page or mutate reference media; `inventory.packaging.submit` users may only read the authenticated media from their packaging workspace.
- Product-specific uploaded media overrides seeded/default illustration URLs for factory display; real case images appear in the factory reference area.
- Factory per-order evidence remains exactly two primary photo groups: measurement image + weight image. Packaging reference media is product master guidance and never becomes a per-order submission gate.

## Owner UAT remediation — global preset catalog + sparse product overrides

Owner approved the resource-minimal architecture on 2026-09-04. This section supersedes the earlier per-product SOP duplication model for the current implementation.

- The four preset packaging methods are one global code/config catalog (`config/packaging_presets.php`) resolved through `PackagingPresetCatalog`; there is no `packaging_presets` database table and no per-product copy of template materials/steps/tips/default images.
- Product-specific state is now sparse `product_packaging_profiles`: normally only `product_id + preset_key` plus optional scenario/version are stored. `custom_materials`, `custom_steps`, and `custom_tips` remain nullable overrides for rare fully custom products.
- Product-specific media is stored separately in `product_packaging_profile_media`; rows exist only for actually uploaded default override, rendered/main illustration, or real case images.
- Effective factory guidance is resolved at read time as `global preset + product override`. Changing a global preset immediately affects every product referencing that preset without rewriting product rows.
- A product with no configured packaging profile creates no packaging-profile row. Factory per-order measurement can still choose a reference preset independently because packaging guidance is non-blocking reference metadata.
- Current UAT proof: exactly 4 global presets, 1 profile row for `PKG-UAT-001`, `custom_materials/custom_steps/custom_tips = NULL`, 3 actually uploaded product media rows, and no `packaging_presets` table.
- Migration fresh and explicit last-two-migration rollback/reapply both pass on disposable SQLite.

Current UAT remains `http://127.0.0.1:18124`: admin Product360 packaging management and factory Packaging Measurement views are open with the same UI behavior, but now backed by the sparse global-template model. Git delivery remains paused pending Owner confirmation; Packaging Production deployment remains prohibited.

## Owner UAT remediation — Product packaging standard → Order / Shipment bridge

Owner requested that one approved Product packaging/weight standard be reusable by every Order Item that resolves to the same canonical `product_id`, while historical fulfillment must not drift when the Product standard changes later.

Implemented in the current uncommitted WP:
- An approved Packaging Measurement Record can be explicitly adopted as the Product's `current_measurement_record_id`; adoption never overwrites Product physical `weight_kg` / dimensions.
- Pending Orders resolve packaging dimensions, packaged weight and packaging method through `order_items.product_id` → Product Packaging Profile → current approved measurement.
- The first fulfillment capture creates `shipment_item_packaging_snapshots`, preserving the exact packaging reference used by that Order Item.
- Once captured, the snapshot is not rewritten by later Product packaging-standard changes; reopen/new fulfillment for changed Order Items can capture a new reference without mutating old Product evidence.
- Order detail exposes `当前产品包装标准` before fulfillment and `履约快照` after capture.
- Logistics payload exposes the same per-item packaging reference for downstream fulfillment use.
- Multi-item Orders retain one reference snapshot per Order Item; this is product-packaging reference data, not yet a final combined-parcel dimension calculator.

Focused verification: Packaging + Manual Fulfillment tests pass (14 tests / 173 assertions); desktop and 390px browser UAT show no console/page errors or horizontal overflow. No commit/push/PR/merge/Production deployment yet.

## Owner final usability refinement — Order → Packaging traceability links

After Owner PASS, the Order `包装与重量参考` card was refined with permission-aware direct navigation. Users with `products.operate` can jump from an Order Item to `/products/{product}/packaging`; users with packaging evidence read authority can jump from the referenced measurement to `/inventory/packaging/{measurement}`. Restricted order viewers continue to see the read-only source label without being offered links they cannot authorize.
