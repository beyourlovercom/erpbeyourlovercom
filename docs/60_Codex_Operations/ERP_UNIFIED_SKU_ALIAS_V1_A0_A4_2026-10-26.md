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

## A2 Product360 approved design (OWNER VISUAL PASS; DEV OWNER UAT PENDING)
Owner explicitly approved the rendered proposal. Implemented on existing Product360 SKU 别名 tab, no new menu:
- Hero: ERP canonical SKU (read-only, stable product identity).
- Inline table: Alias SKU | source | reference/evidence | active/inactive | OMS verified or pending | updated by/on | actions.
- Inline create form with alias/source/reference/notes; explicit same-physical-product attestation, server-side collision errors in place, confirmation on disable/delete and evidence review; products.operate only.
- Explicit notice: same model/color/spec/packaging only; a substitute color/model must use order line replacement.
- Existing products.view role sees a read-only table.
- Owner must approve representative rendered proposal BEFORE tracked UI implementation, and inspect the opened live Local/UAT Product360 page AFTER implementation. No silent substitution of screenshots or automated tests for Owner UAT.

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
1. OWNER_VISUAL_PASS received. A2 UI implemented locally. OWNER_DEV_UAT_PASS still REQUIRED before merge; must open live page in US Dev for Owner inspection.
2. BYL self-hosted official OAuth MCP on US Mac mini is READY. Separate testing worktree / local SQLite is used; no production database, no production write. Dev Owner live UAT not yet granted.
3. Need a verified, complete OMS product/stock adapter contract and merge-safe integration into #512 descendants. Any missing warehouse stock or physical evidence must fail closed.
4. A2 interactive routes and tests added; A3 OMS integration still incomplete. A4 broad regression has 5 preexisting main failures (same files) and zero new failures on A1 subset. Keep PR DRAFT / NOT MERGE READY.

## Rollback strategy
- No live deployment. Revert this isolated branch/PR without modifying existing Product OS data.
- If future Dev migration is rolled back, it drops only new flags/verification columns; pre-existing alias rows, SKU identity, quantities, Woo and accounting stay unchanged.
