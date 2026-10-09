# ERP Staff Access — Read-only Dev Preflight (2026-10-09)

## Scope and gate

Feature sources merged into GitHub main by PR #437, #439, #441, and the synthetic KOL role-browser tests by PR #443. This does **not** verify existing employee DB grants. Existing `role_preset_*` rows are persisted: `UserService::ensureRole()` writes preset permissions only when a role is newly created, deliberately preserving edited existing templates. Users in `permission_mode=custom` use their individual grants.

A separate, confirmed ERP Dev runtime with isolated database and employee **Dev** identities is required before actual Emma / manager UAT. The WordPress/Woo Dev host `dev.beyourlover.com` is not an ERP deployment target. The Production ERP URL `erp.beyourlover.com` is out of scope.

## Read-only check

Run in a verified isolated ERP **Dev**, after confirming the exact GitHub head, Dev host and DB target. The environment variable `APP_ENV` must actually be `local`, `testing` or `staging` and must exactly match the explicit option:

```sh
php artisan identity:staff-access-audit --expected-environment=staging --json
```

The command fails closed for `production`, an unexpected environment or missing confirmation. It only performs SELECT-style queries and prints **aggregate counts and permission keys**: no employee names, emails, IDs, password hashes, contact details or credentials. Output statuses: `pass`, `review_required`, or `blocked`. Neither `pass` nor a green CI changes persisted grants.

Preflight checks include these groups:

| Role | Required effective permissions |
| --- | --- |
| KOL specialist | `kol.view`, `orders.view`, `orders.create`, `products.view` |
| KOL manager | specialist set plus `kol.views.manage` |
| Logistics | `logistics.view`, `products.view` |
| Operations | `logistics.view`, `kol.view`, `kol.views.manage`, `products.view` |

Checks examine existing role-template `permission_role` values and effective grants for active assignees including custom-mode users; the command never adds, syncs, deletes or backfills grants. KOL specialists use the **scoped read-only** `/kol/logistics` page and **must not** acquire generic Logistics access solely for tracking visibility.

## Real-employee Dev Owner UAT (separately gated)

1. Identify a legitimate separate ERP Dev URL and isolated Dev database. Verify the runtime resolves to the intended exact GitHub main commit and there is a tested rollback. Do not repoint production.
2. Ensure Emma specialist and manager Dev identities exist in that **Dev database**. Never copy Production password hashes, addresses, shipments, real orders or API tokens into a test database.
3. Run the aggregate audit. For any `review_required` status, obtain Owner permission for Dev-only role-template/custom-grant changes via the existing audited permissions UI. Take a before/after record **without exposing PII** and rerun the audit. No auto-repair is allowed.
4. Login as each genuine authorized Dev employee through the actual ERP UI and verify: SKU search, Products read visibility, Emma own KOL order + scoped tracking only, manager all KOL team orders but no general customer orders, no generic Logistics operation for KOL specialists, and M14 (390px) mobile layout.
5. Owner records separate final Dev PASS. Only then request independent Production deployment authorization with backup, rollback, DB/schema review, stored-role permission review and an explicitly non-fulfillable manual KOL canary.

## Current verified limits

China Mac does not presently expose an independent live ERP Dev endpoint or isolated real-employee Dev DB. Only original Valet `erp` -> old ERP checkout was found. Existing synthetic Chrome/Chromium UAT is PASS, but real Emma or manager login is **not** established. GitHub main merge does not automatically update the running Production source or persisted permissions.
