# BYL ERP Approved Menu Baseline

`menu/APPROVED_MENU.json` is the Owner-approved governance baseline for level-1 and level-2 menus. `config/erp_shell_navigation.php` remains the Laravel runtime menu configuration. The JSON file is not loaded at runtime.

This baseline does not govern level-3 pages, detail pages, tabs, filters or saved views. It does not introduce a registry, schema service, tree builder, Artisan command, CI workflow or admin editor.

## Current lifecycle state

```text
MENU_PROJECT: TEMPORARILY CLOSED / CURRENTLY COMPLETE
LEVEL_1_MENU: 11 / OWNER APPROVED
LEVEL_2_MENU: 0 / INTENTIONALLY DEFERRED
ACTIVE_MENU_IMPLEMENTATION: NO
MENU_ROADMAP_REMAINING: 0 CODEX
```

The current Menu project is temporarily closed after the accepted Level-1 UI, Account Dock and Menu Baseline work. The absence of approved Level-2 items is deliberate and does not require speculative implementation.

A future task that adds, removes, renames, reorders or otherwise changes a Level-2 menu must reopen Menu work through a new, bounded Menu Change Work Package. That package must identify the affected business module and obtain explicit Owner authorization before changing any governed value.

See `menu/STATUS.md` for the closure record and reopen trigger.

## Mandatory read gate

Before any task changes or may change one of the following, Codex must read in order:

1. `/AGENTS.md`
2. `/menu/README.md`
3. `/menu/APPROVED_MENU.json`
4. `/menu/STATUS.md`

Governed changes include:

- adding, deleting or renaming a level-1 or level-2 menu;
- changing a level-1 menu slug;
- changing a level-2 menu slug;
- changing level-1 or level-2 order, Emoji, URL, route or permission;
- moving a level-2 item to another parent;
- changing governed menu function scope, completion status or progress.

## Reporting and stop contracts

When a task has no governed menu impact, continue and report:

```text
MENU_IMPACT: NO
APPROVED_MENU_CHECK: PASS
```

When a task would affect the baseline but has no explicit Owner authorization, do not modify the runtime menu or baseline. Stop and report:

```text
MENU_IMPACT: YES
APPROVED_MENU_CHECK: CHANGES_REQUIRED
AFFECTED_MENU_CODES:
CURRENT_APPROVED_VALUE:
PROPOSED_VALUE:
REASON:
OWNER_APPROVAL_REQUIRED: YES
RESULT: MENU_BASELINE_CHANGE_REQUIRES_OWNER_APPROVAL
```

When explicit Owner menu-change authorization exists, the same change must update together:

1. `menu/APPROVED_MENU.json`;
2. the runtime menu configuration;
3. the corresponding automated test;
4. the Work Package or change record.

Partial updates are prohibited.
