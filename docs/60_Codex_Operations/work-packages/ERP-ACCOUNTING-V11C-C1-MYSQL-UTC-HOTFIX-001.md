# ERP-ACCOUNTING-V11C-C1-MYSQL-UTC-HOTFIX-001

## Status
- Scope: C1 candidate-window timezone hotfix only.
- Baseline: `origin/main@710f0aaffdfb65dd258f91bb6e41a5e35d796376`.
- Production activation/write: NOT AUTHORIZED.

## Production Evidence
- Laravel/PHP timezone: UTC.
- MySQL session/global timezone: SYSTEM; DB `NOW()` is UTC-7 on the US Production host.
- `orders.updated_at`, `order_external_references.updated_at`, and `order_financials.updated_at` are MySQL TIMESTAMP columns.
- Woo inbound already executes `SET time_zone = '+00:00'` in `OrderService::upsertInbound()`.
- C1 scheduler starts in a separate DB session and omitted that normalization, so a UTC 3-hour candidate window returned zero while Woo/full Accounting counts had increased.

## Fix Contract
- In `EloquentAccountingContinuousDeltaCandidateStore::candidates()`, when the driver is MySQL, set only the current DB session timezone to `+00:00` before timestamp predicates.
- Do not change global MySQL timezone, Laravel app timezone, schema, stored timestamps, Accounting rules, scheduler cadence, or Production env.
- Other DB drivers remain unchanged.

## Verification
- Existing C1 focused and Finance regression must remain green.
- MySQL-only test asserts the candidate query changes the current session from SYSTEM to +00:00; SQLite explicitly skips this environment-specific assertion.
- Production code-only deploy, if later Owner-approved, must keep C1 scheduler disabled and prove a read-only C1 dry-run can see recent Woo changes.

## Final Verification Evidence
- C1 focused SQLite: 12 tests / 60 assertions / 1 intentional MySQL-only skip / PASS.
- Isolated local MySQL: 1 test / 1 assertion / PASS; session changes from SYSTEM to +00:00.
- Finance + Woo scheduler regression: 79 tests / 626 assertions / 1 intentional MySQL-only skip / PASS.
- Architecture/menu exact-main comparison: baseline 6 inherited failures; hotfix 6 same failures; regression delta 0.
- Production read-only contrast: default SYSTEM-session C1 preview returned 0 candidates; same deployed code with only process-local session set to +00:00 returned 2 recent candidates, including payment_fee apply=2.
- PHP lint and `git diff --check`: PASS.
- Production business writes during diagnosis/verification: NONE.
