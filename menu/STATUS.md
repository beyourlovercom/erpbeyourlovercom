# BYL ERP Menu Project Status

```text
STATUS: TEMPORARILY CLOSED / CURRENTLY COMPLETE
CLOSED_AT: 2026-08-03
BASELINE_VERSION: 1.0
LEVEL_1_COUNT: 11
LEVEL_2_COUNT: 0
ACTIVE_MENU_IMPLEMENTATION: NO
MENU_ROADMAP_REMAINING: 0 CODEX
```

## Closure decision

The current Menu project is temporarily closed.

Completed scope:

- Level-1 navigation UI and approved Emoji;
- Account Dock integration;
- 11 Owner-approved Level-1 menu entries;
- Level-1 and Level-2 governance baseline;
- runtime-to-approved-baseline automated contract;
- mandatory Owner approval gate for governed menu changes.

The current baseline intentionally contains zero Level-2 menu entries. No complete Level-2 structure will be inferred or implemented before the corresponding business module is designed.

## Reopen trigger

Menu work must be reopened only when an approved business-module task requires a governed Level-1 or Level-2 change, including:

- adding, deleting or renaming a Level-2 menu;
- changing its parent, order, slug, URL, route, permission or function scope;
- changing an existing Level-1 governed value.

The future task must create a new bounded Menu Change Work Package and obtain explicit Owner authorization.

## Required atomic update

An authorized future menu change must update together:

1. `menu/APPROVED_MENU.json`;
2. `config/erp_shell_navigation.php` or the then-current runtime menu source;
3. `tests/Feature/ApprovedMenuBaselineTest.php` or the then-current corresponding contract;
4. the applicable Menu Change Work Package or change record.

Partial updates are prohibited.

## Current exclusions

This closure does not close or complete the business modules represented by the menu. Logistics, Sync, Customers, KOL, Inventory, Procurement, Products, Guides, Accounting and Settings continue under their own Roadmaps and Work Packages.

It also does not make Browser E2E work part of the Menu Roadmap. ERP-ARCH-MIGRATION-001E-B and 001E-C remain Architecture Migration work.
