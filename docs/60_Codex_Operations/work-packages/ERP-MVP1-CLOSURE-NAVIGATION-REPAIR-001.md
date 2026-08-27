---
type: work-package
project: BYL ERP
work_package_id: ERP-MVP1-CLOSURE-NAVIGATION-REPAIR-001
title: MVP1 Closure / First-Level Navigation Repair
status: ACTIVE / OWNER AUTHORIZED
baseline: main@c0a09baf43397454f16026041c8507b16a53dd26
branch: feature/erp-mvp1-closure-navigation-repair-001
draft_pr: TBD
updated: 2026-08-27
---

# ERP-MVP1-CLOSURE-NAVIGATION-REPAIR-001

## 1. Objective

Close the MVP1 application-shell gap before WooCommerce automation work starts.
Every first-level navigation item must open a formal, usable default page rather than `Shell/Placeholder`.

## 2. Frozen rule

```text
FIRST-LEVEL MENU
→ HTTP 200 for authorized user
→ formal Inertia/business page
→ never Shell/Placeholder
→ at least one truthful module overview or canonical business surface
```

If a module already owns a real Dashboard/Index, reuse it. If it does not, provide a formal module Overview using permission-filtered real navigation entries. Do not invent business records or fake automation.

## 3. Audited first-level navigation

Existing roots at baseline:

```text
Dashboard / Logistics / Sync / Customers / KOL / Inventory
Procurement / Products / Guides / Accounting / Settings
```

Audit result:
- native real root already exists: Dashboard, Logistics, Sync, Inventory, Products;
- formal root missing and currently placeholder-driven: Customers, KOL, Procurement, Guides, Accounting, Settings;
- no canonical all-orders root exists; Customer/KOL order lists are split views only.

## 4. Required repair

### 4.1 Canonical all-orders page

Add `/orders` as a first-level Order surface backed by the canonical Order table.
It must combine Customer + KOL fulfillment orders and retain search/source/status filters, pagination and CSV export.
Customer/KOL list pages remain filtered views and their existing detail/create routes remain canonical.

### 4.2 Formal module overviews

Provide formal first-level content for:

```text
/customers
/kol
/procurement
/guides
/accounting
/settings
```

Each overview uses only permission-visible configured children and truthful module copy. Existing real child routes are reused.

### 4.3 Root placeholder elimination

All first-level entries in `erp_shell_navigation` are formal (`placeholder=false`).
Legacy/prototype child navigation remains available where already authorized, but must not take ownership of a formal first-level root.

## 5. Explicit exclusions

- no WooCommerce inbound/outbound automation;
- no Reservation / allocation / carrier intelligence;
- no new Procurement business engine;
- no fabricated Guide content or accounting records;
- no redesign of existing Dashboard, Logistics, Sync, Inventory or Product business pages;
- no Production deployment.

## 6. Acceptance gates

- every first-level root renders a non-placeholder formal page for an authorized user;
- `/orders` returns both Customer and KOL orders from the same canonical Order model;
- existing `/customers/orders` and `/kol/orders` behavior remains valid;
- unauthorized root access remains forbidden;
- legacy prototype-only access semantics remain unchanged where previously supported;
- architecture tests, focused feature tests, full PHPUnit, Vitest, typecheck, build and `git diff --check` pass;
- US Mac is the default UAT host.
