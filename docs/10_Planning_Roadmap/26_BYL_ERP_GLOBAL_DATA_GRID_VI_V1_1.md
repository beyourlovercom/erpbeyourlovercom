# BYL ERP Global Data Grid VI V1.1

Status: OWNER APPROVED / GLOBAL UI CONTRACT / COMPLETE / FROZEN / CLOSED
Rule ID: `BYL_GLOBAL_DATA_GRID_VI_V1_1`
Canonical source locator: `beyourlovercom/erpbeyourlovercom@main:docs/10_Planning_Roadmap/26_BYL_ERP_GLOBAL_DATA_GRID_VI_V1_1.md`
Reference implementation: `/customers/groups`
Reference namespace: `grid.customers.groups.v1`

## 1. Purpose

Global Data Grid VI is the canonical pattern for ERP primary business lists and data workspaces. It standardizes dense list presentation without permanently consuming extra vertical space and is designed to be reusable by Customers, Orders, Products, Inventory, KOL, Support, B2B, Warehouse and future modules.

This contract is peer-level with the ERP Form Design Standard and Page Header Action Zone. New primary list workspaces should use this contract unless a documented exception applies.

## 2. Visual contract

- Keep the default table visually compact.
- The fixed identity column stays at the left edge.
- The row-action area stays at the right edge.
- Configurable data columns occupy the middle region.
- The table-header far-right `…` is the canonical Column Settings trigger.
- Column Settings is hidden until requested; no permanent settings toolbar is added.
- Tables may scroll horizontally when visible columns exceed the viewport.
- Simple embedded tables with only a few stable columns may remain Simple Tables.

## 3. Column contract

Every configurable column must be code-defined. The database stores user choices, not column definitions.

A column definition may declare: `key`, label, source/authority, data type, formatter, default visibility, hideability, sort/filter capability, width, alignment, pinning and required permission.

Column data belongs to one of four authority classes:

1. Direct — canonical field on the resource.
2. Relation — field projected from an explicit canonical relation.
3. Aggregate — count/sum/AOV/latest/etc. calculated from canonical related records.
4. Virtual — display-only formatting or composition; never new business truth.

Grid workspaces must not duplicate Order, Shipment, Inventory, Finance or other canonical facts into the resource table merely to make a list easier to render.

## 4. Query contract

Primary business grids use server-side pagination, filtering and sorting when data volume can grow materially. Filter/sort fields are registry-whitelisted; the frontend must never pass arbitrary SQL columns.

Aggregate/relation columns must be obtained with batch queries, joins, eager loading, subqueries or grouped projections. Per-row lazy loading / N+1 query patterns are prohibited.

## 5. Account preference authority

Personal Grid layout is an account preference, not a device preference.

Canonical storage is the single generic `user_ui_preferences` table with unique key `(user_id, namespace)`. Do not create Customer/Grid/Order-specific preference tables.

Versioned namespace format: `grid.{resource}.{view}.v{version}`.

Preference payload stores only UI choices such as ordered column keys and visibility. Business definitions, labels, SQL/query mapping and permissions remain in code.

Browser storage is cache/migration fallback only. Server preference is authoritative across devices. Existing local-only settings may be uploaded once when the authenticated account has no server preference.

A Preference Sanitizer must intersect stored keys with the current allowed column registry, remove duplicates/unknown columns, re-add new defaults where required and apply current permissions before rendering.

A saved preference can never restore a column the current account is not authorized to view.

## 6. Minimal database rule

V1.1 adds only `user_ui_preferences`. No grid row cache, copied business fields, per-module preference tables or database-backed column registry is permitted.

Future Saved Views, if approved, may use one generic cross-resource table rather than module-specific view tables.

## 7. Shared implementation contract

Shared frontend behavior belongs in a framework-level primitive/composable such as `useAccountGridPreferences`; resource pages provide only namespace, column definitions, rows/query state and row actions.

The shared layer must not contain Customer-, Order-, Product- or KOL-specific business semantics. Different technology stacks may reimplement the adapter, but should preserve the same Visual, Column, Query and Preference contracts.

## 8. Current V1.1 capabilities

- Account-synced column visibility.
- Account-synced drag reorder.
- `…` Column Settings trigger.
- Restore defaults.
- Left identity / right action boundaries.
- Horizontal overflow.
- Versioned preference namespaces.
- Server authority with browser cache fallback.
- Preference sanitization.
- Permission-aware available columns.

Column resize, column groups/presets, bulk actions and Saved/Shared Views are later opt-in upgrades and are not required for V1.1 compliance.

## 9. Reference implementation

`/customers/groups` is the V1.1 reference implementation. New list upgrades should preserve its interaction model while using the target module's own canonical data authority and permissions.

## 10. Cross-project portability contract

`BYL_GLOBAL_DATA_GRID_VI_V1_1` is a portable contract, not an ERP-only implementation package. Another repository or product may adopt it without sharing BYL ERP's framework or source tree.

A project invokes the contract by explicitly referencing the Rule ID in its `AGENTS.md`, work package, architecture specification, or Owner instruction. After invocation, the project must preserve the same four contracts: Visual, Column, Query and Preference.
For cross-repository use, include the canonical source locator above or vendor a pinned copy of this contract into the target repository. A vendored copy must retain the Rule ID and source/version reference so later upgrades are explicit rather than silent.

Portable semantics are mandatory; implementation mechanics are replaceable. Vue composables, Laravel routes/models and the literal `user_ui_preferences` table name are BYL ERP implementations, not cross-project requirements. React, Svelte, native apps or another backend may implement equivalent adapters.

A conforming target project must still keep one generic per-account UI-preference authority, versioned grid namespaces, permission-aware sanitization and browser storage as cache only. Per-module preference databases and copied business truth remain prohibited.

If the target project has no authenticated account/identity authority, it may reuse the Visual/Column/Query portions, but must not claim full `BYL_GLOBAL_DATA_GRID_VI_V1_1` compliance until an Owner-approved equivalent identity/preference authority exists.

## 11. Invocation examples

A target project's governance may activate the rule with a statement such as:

`UI_RULE: BYL_GLOBAL_DATA_GRID_VI_V1_1`

`RULE_SOURCE: beyourlovercom/erpbeyourlovercom@main:docs/10_Planning_Roadmap/26_BYL_ERP_GLOBAL_DATA_GRID_VI_V1_1.md`

or:

`This project's primary business grids adopt BYL_GLOBAL_DATA_GRID_VI_V1_1.`

Once invoked, project-specific resource adapters remain responsible for their own canonical data authority, permissions, query implementation and row actions.

Local Owner-approved project rules remain authoritative for explicit conflicts. Conflicts must be surfaced for Owner resolution instead of silently weakening or overriding either contract.
