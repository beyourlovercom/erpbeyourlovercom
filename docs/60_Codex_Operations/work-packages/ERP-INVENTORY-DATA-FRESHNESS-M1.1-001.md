# ERP-INVENTORY-DATA-FRESHNESS-M1.1-001

Status: READY_FOR_GIT_DELIVERY / OWNER AUTHORIZED IMPLEMENTATION

## Authority
- Repo: `beyourlovercom/erpbeyourlovercom`
- Base: `origin/main@f65f663528241e7933f0dbd4568fb041f25488f3`
- Branch: `feature/erp-inventory-data-freshness-m1-1-001`
- Worktree: `/Users/a/AI/007/worktrees/erp-inventory-data-freshness-m1-1-001`

## Problem
Inventory currently renders a warehouse with no inventory evidence as numeric zero.
That conflates `unknown / never imported` with a confirmed real zero and is unsafe for later fulfillment automation.

## Scope
1. Add warehouse inventory data-state projection.
2. Preserve confirmed zero as numeric `0`.
3. Render never-imported inventory as `— / 库存未导入`.
4. Preserve manual inventory as known manual data.
5. Correct US-LA / US-NY warehouse display-name truth through a new forward migration.

## Data-state contract
- `confirmed`: at least one confirmed full snapshot exists; later partial syncs do not remove the full baseline.
- `partial_only`: confirmed partial data exists but no confirmed full baseline exists.
- `manual`: inventory rows exist without confirmed batch history.
- `never_imported`: no confirmed batch and no inventory rows.

`can_auto_allocate = true` only for `confirmed` in this WP.
No stale-age threshold is invented here; expose the last evidence timestamp only.

## UI contract
- Dashboard total is labeled as recorded/imported inventory, not implied global truth.
- Never-imported warehouse quantity/product count render as `—`.
- Warehouse cards/list show data state and latest evidence time.
- A real confirmed zero remains `0`.

## Warehouse truth correction
- `US-LA` → `美西洛杉矶仓`
- `US-NY` → `美东纽约仓`
- Do not change id/code/slug or inventory relations.

## Verification evidence
- Inventory focused PHP: 42 tests / 197 assertions / PASS.
- Freshness contract: 6 tests / 29 assertions / PASS.
- Inventory Vue focused: 3 tests / PASS.
- `npm run typecheck`: PASS.
- `npm run build`: PASS; production Vite manifest generated.
- MIG-001 / FE-001 architecture compatibility evidence: 463 assertions / 0 assertion failures; existing test-harness Risky flag only.
- `git diff --check`: PASS.
- Secret/Tailnet scan across all 9 changed or new files: PASS.
- Backup module touch: NONE.
- localerp data/runtime mutation: NONE.

## Upstream baseline reds
- APP-001 reports 12 pre-existing Application → Infrastructure dependencies.
  Reproduced independently on clean detached `origin/main@9ac9b76`; not caused by this WP.
- Full Vitest on latest base: 59 files / 406 tests; 57 files and 403 tests pass; the same 3 upstream failures remain in `Products/Edit.test.ts` and `Orders/Create.test.ts`.
  The same 3 failures were reproduced independently on clean detached `origin/main@9ac9b76`; not caused by this WP.
- Inventory-owned Vitest tests remain green.

## Current gate
`READY_FOR_GIT_DELIVERY`

No commit, push, PR, merge, localerp deploy, or Production mutation has been performed by this WP yet.

## Rebase evidence
- Rebasing target advanced during development from `9ac9b76` to `f65f663` via Backup Engine PR #148.
- Inventory business files had zero upstream overlap.
- Architecture baseline overlap was resolved by retaining upstream Backup entries and recalculating this WP entries.
- Inventory migration timestamp moved from `220000` to `223000` to avoid collision with the Backup Engine migration.

## Latest-base full regression
- Base: `origin/main@f65f663528241e7933f0dbd4568fb041f25488f3`.
- Full PHPUnit: 832 tests / 7095 assertions; one failure only: pre-existing APP-001 with the same 12 historical Application → Infrastructure dependencies; 2 skipped; 1 existing Risky.
- Full Vitest: 59 test files / 406 tests; 57 files / 403 tests pass; exactly 3 known upstream non-Inventory failures remain.
- Inventory focused PHP: 42 tests / 197 assertions / PASS.
- Inventory focused Vue: 3 tests / PASS.
- Typecheck and production Vite build: PASS.
- Final static gate: `git diff --check`, conflict-marker scan, PHP lint, secret/Tailnet scan, Backup-scope scan, localerp-runtime scan = PASS.
