---
id: ERP-FRESH-INSTALL-MYSQL-INDEX-HOTFIX-001
title: ERP Fresh Install — MySQL Composite Index Name Hotfix
status: IMPLEMENTATION_COMPLETE / VERIFIED / READY_FOR_GIT_DELIVERY
branch: fix/erp-fresh-install-index-hotfix-001
base: origin/main@2529b83f2d6e7e0e235bf88ab704a7c70d52c7a4
EXECUTION_PROVIDER: GPT_WEB_US_MAC_DC
VERIFICATION_TIER: HIGH_RISK
implementation_authorization: OWNER_NEXT_STEP_2026-09-02
git_delivery: OWNER_AUTHORIZED_2026-09-02
merge: NOT_AUTHORIZED
production_migration: NOT_AUTHORIZED
shared_uat_mutation: NOT_AUTHORIZED
---

## Problem
Fresh MySQL migration failed in Backup Integration UAT because Laravel generated the index name `warehouse_inventory_lots_warehouse_id_product_id_stock_status_index` (67 chars), exceeding MySQL's 64-character identifier limit.

## Frozen Fix
- Preserve the same table, columns, order, and index semantics.
- Set the explicit index name to `wh_inv_lots_wh_product_status_idx`.
- Update only the frozen migration SHA in `ModuleArchitectureTest`.
- Add a regression test that verifies the explicit name exists and is <=64 characters.

## Verification Evidence
- Permanent migration SHA: `2d4048d7ccc8ddbe916fdf6b2cf5158ba30585ddc6083ff0e106359e2e004098`.
- This SHA exactly matches the UAT-only patch that completed a fresh MySQL install with `69` migrations Ran and `0` Pending in `byl_erp_backup_dr_uat`.
- Regression test: `MigrationIndexNameTest` = 1/1 PASS, 3 assertions.
- Latest-main auto-index scan: 0 generated index names over 64 characters.
- No migration files changed between M1.5 merge `3618983` and current base `2529b83`.
- PHP lint and `git diff --check`: PASS.

## Architecture Evidence
- Hotfix modifies only the frozen SHA for the affected migration.
- Existing mainline APP-001 debt remains 14 non-hotfix violations.
- Current `origin/main@2529b83` also contains an inherited FE-001 frozen-hash drift for `GlobalFeedbackIsland.test.ts` from PR #155; this WP does not absorb that unrelated change.
- The migration's actual SHA and Architecture expected SHA are identical after this hotfix.

## Non-Goals
- No schema column/data-model changes.
- No Production or shared-UAT migration execution.
- No Backup publication/retention/restore actions.
- No unrelated Architecture baseline repair.
