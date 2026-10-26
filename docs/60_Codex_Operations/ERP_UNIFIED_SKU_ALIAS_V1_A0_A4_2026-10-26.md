# Product OS · Unified SKU Alias V1 (A0–A4)
2026-10-26 · Dev-only work package · DRAFT / NO PRODUCTION AUTHORIZATION

## A0 audit (GitHub is the authority)
- main at work-package start: 0438960d01d772053fe42ba30352f8a87d724740.
- Existing main has ProductSkuAlias, SkuAliasService, SkuIdentityResolver, match_key unique index, alias CRUD routes, Product360's read-only "SKU 别名" tab and permission/audit.
- #501/#504/#510/#511/#512 remain Draft in a dependent OMS PR chain. #508 is a distinct warehouse-mapping Draft and is NOT approved for integration.
- OMS chain diverges from current main; preserve recent Inventory OS inline editing and inbound review changes. No Product360 changes should be developed against the stale #512 head.
- No main merge, Stable release, production ERP/Woo modification or OMS write authorized by this work package.

## A1 current backend implementation
- Reuse global product_sku_aliases and existing identity resolver. Do not create a second warehouse-specific mapping table.
- Add is_active (legacy default true) and nullable OMS manual-verification timestamp, verifier and evidence. Migration never auto-verifies imported aliases.
- Inactive aliases remain uniquely reserved, are not resolved by identity lookup and cannot be dispatched.
- Activation after deactivation DOES NOT restore old OMS verification; only an authenticated products.operate actor can attest physical equivalence with evidence. Log activation/verification in Audit.
- "Verified alias" only means that the alias denotes the same product identity. It is NOT a warehouse stock reservation, OMS catalog approval, or dispatch authorization.

## A2 Product360 implemented (OWNER VISUAL PASS + OWNER DEV UAT PASS, 2026-10-26)
Owner explicitly approved the rendered proposal. Implemented on existing Product360 SKU 别名 tab, no new menu:
- Hero: ERP canonical SKU (read-only, stable product identity).
- Inline table: Alias SKU | source | reference/evidence | active/inactive | OMS verified or pending | updated by/on | actions.
- Inline create form with alias/source/reference/notes; explicit same-physical-product attestation, server-side collision errors in place, confirmation on disable/delete and evidence review; products.operate only.
- Explicit notice: same model/color/spec/packaging only; a substitute color/model must use order line replacement.
- Existing products.view role sees a read-only table.
- Owner approved the rendered proposal BEFORE UI implementation and gave a second pass after the live isolated US-Mac Product360 tab was opened. See PR #515 A2 Owner Dev UAT comment.

## A3 pure OMS candidate selector (foundation only, NOT live wired)
New VerifiedOmsSkuSelector consumes:
1) canonical SKU plus only active, manually verified aliases of the resolved ERP product_id;
2) COMPLETE normalized read-only product catalog for the EXACT selected OMS warehouse;
3) approval_status=2, stock in same warehouse, documentary physical-identity confirmation.
Block on incomplete pagination, missing fields, timeout/unknown, 0 hits, >1 hits, wrong warehouse, unknown approval, unknown/insufficient stock, or unverified physical identity.
Only one validated OMS SKU may be chosen, never an arbitrary first result, fuzzy match or highest-stock candidate.
The existing OMS gateway only validates a provided SKU's presence/approval; it does NOT yet prove that the Product pagelist response exposes all required warehouse-scoped physical-equivalence and stock fields. Until that adapter contract is verified, DO NOT wire the selector into SingleOrderPreview/Dispatch and DO NOT claim actual OMS automatic alias dispatch support.
Avoid #508 entirely. Keep #510 duplicate guard, isSubmit authorization, attempt/payload hash unchanged.

## A4 tests/gates
- Candidate unit contract cases: canonical/alias, legacy unverified, inactive, double hit, missing catalog, wrong warehouse, status, spec, unknown stock and insufficient stock.
- Alias feature cases: migration-safe legacy default, permission, activation/verification Audit, disabled match behavior, no retroactive approval.
- Must run: PHPUnit tests, migration + SQLite rollback, frontend typecheck/build and regressions for products/Inventory/OMS once integration branch and US dev environment are available.
- OMS source examples VP757A and VP757B are different physical products and MUST NOT be auto-aliased.
- Never call outboundOrder/create (including live isSubmit=1); do not resubmit TEST-XLWMS-001 or C41704-007.

## Open gates at this draft revision
1. Both OWNER_VISUAL_PASS and OWNER_DEV_UAT_PASS received for A2. Keep the A2 behavior frozen in later OMS wiring.
2. BYL self-hosted official OAuth MCP on US Mac mini is READY. Separate testing worktree / local SQLite is used; no production database, no production write. Dev Owner live UAT not yet granted.
3. Need a verified, complete OMS product/stock adapter contract and merge-safe integration into #512 descendants. Any missing warehouse stock or physical evidence must fail closed.
4. A2 interactive routes and tests accepted; A3 OMS integration still incomplete. A4 broad regression has 5 preexisting main failures (same files) and zero new failures on A1 subset. Keep PR DRAFT / NOT MERGE READY.

## Rollback strategy
- No live deployment. Revert this isolated branch/PR without modifying existing Product OS data.
- If future Dev migration is rolled back, it drops only new flags/verification columns; pre-existing alias rows, SKU identity, quantities, Woo and accounting stay unchanged.


## A3-S1 executed after A2 Owner UAT PASS
- Inventory/lot branch truth from GitHub: feature/xlwms-inventory-api-sync-20261026 and feature/xlwms-lot-reconciliation-v2-20261026 contain strict read-only paginated OMS product and integrated inventory polling, and a warehouse-scoped good-stock snapshot, with lot/unknown-age reconciliation. Both are separate unmerged implementations; do NOT modify their worktrees from SKU Alias V1.
- Product catalog is account-level (does not require whCode), while integratedInventory/pageOpen must return the exact OMS target whCode and stockType=0. A catalog-only SKU with no corresponding inventory ledger has UNKNOWN stock, not zero stock.
- Add pure Product OS VerifiedOmsCatalogApproval: exact unique SKU plus approveStatus explicitly 2 from complete catalog; absent status is NOT approval.
- Add pure Product OS VerifiedOmsSnapshotPreflight: validate ERP warehouse ID, OMS whCode, validated snapshot status applied, V2 lot reconciliation complete, zero blocked/unmatched, exhaustive row count, exact stock arithmetic, and unambiguous aliases. Verify last successful read within 120 seconds, no future timestamps, and aggregate same-product order lines before stock check. Only manually approved active aliases are candidates. Catalog-only without stock cannot ship.
- Read_only and blocked/unknown/old snapshots intentionally fail closed. This is stricter than a read-only inventory probe and does NOT mean real stock has been applied.
- Independent vendor permission, channel, duplicate OMS order, payload hash, one-order allowlist, no-auto-retry and isSubmit safety gates remain necessary. No Production OMS adapter/dispatch wiring was added to PR #515.
- Unresolved dependency: inventory sync accepts historic smgj_pi PI-source verification for alias-to-product resolution. It must adopt unified Product OS manually verified aliases before new snapshot adapter can plug into OMS dispatch. Never use the old per-warehouse #508 mapping.
- Next step: a separate stacked Draft PR AFTER both streams have a merge-safe common base and verifier evidence. Call preflight after read-only duplicate lookup and before outboundOrder/create. Revalidate live read-only stock/catalog approval, unchanged payload hash/quantities/resolved OMS SKU, and preserve durable dispatch attempt on block.
- Never enable inventory apply, live dispatch, Production order/Woo writes, or submit OMS test orders under this work package.

A3-S1 pure contract tests: VerifiedOmsCatalogApprovalTest and VerifiedOmsSnapshotPreflightTest. A3-S2 real dispatch wiring remains blocked by independent stream integration; PR #515 remains Draft.
