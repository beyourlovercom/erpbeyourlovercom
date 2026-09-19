# Accounting Continuous Delta MySQL Fixture Normalization — 2026-09-19

Status: IMPLEMENTED / LOCAL MYSQL 8.4 FULL GATE PASS

## Scope

Close Roadmap Reconciliation V3 P0-2 by making the complete `AccountingContinuousDeltaTest` trustworthy on MySQL SYSTEM/PDT environments without changing Production business behavior.

## Reproduced baseline

Isolated MySQL:
- version: 8.4.11
- `@@system_time_zone=PDT`
- `@@session.time_zone=SYSTEM`
- full `AccountingContinuousDeltaTest`: 7 failed / 11 warnings / 64 assertions

The seven failures resolved to the same fixture contract mismatch: test fixtures used UTC-looking timestamps while the MySQL test session was still `SYSTEM`, then the candidate query correctly normalized its session to `+00:00`.

## Fix

1. On MySQL only, `AccountingContinuousDeltaTest::setUp()` normalizes the test connection to `+00:00` before fixture creation.
2. The dedicated CandidateStore timezone test still explicitly sets the session back to `SYSTEM` before calling the service, so it continues to verify runtime normalization.
3. The runtime-failure fixture no longer drops `order_cost_entries`. It uses a deterministic throwing `ProductCostEvidenceReader` instead.
   - avoids MySQL DDL implicit commit;
   - avoids breaking Laravel `RefreshDatabase` savepoints;
   - preserves the intended assertion that runtime failure does not advance the Continuous Delta checkpoint.
4. `ERP Production Stability V1 CI` now triggers when `AccountingContinuousDeltaTest.php` changes and its MySQL 8 runtime job runs the complete Continuous Delta test together with the existing critical-query gate.

## Local verification

SQLite:
- `AccountingContinuousDeltaTest`: 18 tests / 94 assertions / 0 failures.

Isolated MySQL 8.4.11 / SYSTEM=PDT:
- `AccountingContinuousDeltaTest`: 18 tests / 95 assertions / 0 failures.
- dedicated runtime session-normalization test remains PASS.
- exact combined Production Stability MySQL command: 19 tests / 95 assertions / 0 failures.

Finance regression on SQLite:
- 15 Finance test files;
- 134 warnings + 4 pass;
- 976 assertions;
- 0 failures.

Validation:
- Pint: PASS.
- workflow YAML parse using Ruby/Psych: PASS.
- `git diff --check`: PASS.
- warnings are existing test-environment `file_get_contents(.../.env)` warnings, not failures.

## Safety

- test/CI-only change;
- no application runtime logic change;
- no schema or migration change;
- no Production deployment required;
- no Production data write;
- no ERP Changelog update.
