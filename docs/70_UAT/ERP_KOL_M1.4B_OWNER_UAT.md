# ERP KOL M1.4B — Provisioning UI Owner-UAT

Status: LOCAL FAKE-PROVIDER BROWSER OWNER UAT PASS / LIVE-PROVIDER COMPATIBILITY NOT RUN

## Boundary
- Canonical surface: `/kol/affiliatewp` Influencer only.
- This UAT uses synthetic data and `LocalUatAffiliateWpGateway` only.
- `ANDY_CORE_UAT_FAKE=true` is accepted only in `local` / `testing` environments.
- Never use Production DB, Production APP_ENV, real connector credentials or real AffiliateWP mutations for this fixture.
- Fake-provider UAT is **not** live-provider compatibility PASS. The Andy Core v1.5.3 live compatibility gate remains separate.
- Coupon remains disabled and belongs to M1.4C.

## Local fixture
Set local-only passwords in `.env` without committing them:

```text
UAT_KOL_MANAGER_PASSWORD=<local-only>
UAT_KOL_SPECIALIST_PASSWORD=<local-only>
ANDY_CORE_UAT_FAKE=true
```

Then on the dedicated local UAT database:

```bash
php artisan migrate --force
php artisan db:seed --class=Database\\Seeders\\KolM14BOwnerUatSeeder --force
php artisan optimize:clear
```

Fixture users:
- `kol.manager@local.test` — KOL Manager permissions.
- `kol.specialist@local.test` — KOL Specialist permissions.

Fixture states are reset every time the dedicated seeder runs.

## Owner checklist
| ID | Role | Scenario | Expected result | Actual | PASS/FAIL |
|---|---|---|---|---|---|
| KOL-14B-01 | Manager | Open `/kol/affiliatewp` | Approved KOL Provisioning panel is visible; Coupon disabled | Provisioning panel visible; Coupon disabled with `M1.4C 交付` title | PASS |
| KOL-14B-02 | Manager | `UAT M1.4B Ready Success` → Provision | POST uses existing `/kol/affiliatewp/provision`; row becomes `active`; Affiliate ID + WP User ID appear; action becomes disabled | POST 302; row became `active`; Affiliate/WP User IDs visible; action disabled | PASS |
| KOL-14B-03 | Manager | `UAT M1.4B Provider Error` → Provision | Visible error feedback; state `error`; action changes to `安全重试` | `PROVIDER_UNAVAILABLE`; state `error`; `安全重试` visible | PASS |
| KOL-14B-04 | Manager | Retry Provider Error | Same ERP row/idempotency identity is reused; no second Affiliate row | Same account row count stayed 1; persisted `provision_idempotency_key` unchanged | PASS |
| KOL-14B-05 | Manager | `UAT M1.4B Conflict` → Provision | Visible conflict feedback; state `conflict`; safe retry remains available | `AFFILIATE_ID_CONFLICT`; state `conflict`; safe retry visible | PASS |
| KOL-14B-06 | Manager | `UAT M1.4B Readback` → Provision | Visible read-back warning; state `readback_mismatch`; safe retry remains available | State `readback_mismatch`; safe retry visible | PASS |
| KOL-14B-07 | Manager | Inspect `UAT M1.4B Active` | State `active`; Provision action visible but disabled | `active`; Provision visible-disabled | PASS |
| KOL-14B-08 | Manager | Inspect `UAT M1.4B External Bound` | Existing external Affiliate/provider status shown; Provision disabled; provider truth not changed | Affiliate 830002 / WP User 730002 / provider `pending` preserved; Provision disabled | PASS |
| KOL-14B-09 | Manager | Inspect `UAT M1.4B In Progress` | State `in_progress`; action disabled with refresh guidance | `in_progress`; action disabled | PASS |
| KOL-14B-10 | Manager | Inspect `UAT M1.4B Pending Approval` | Not offered in approved provisioning panel | Pending fixture absent from provisioning panel | PASS |
| KOL-14B-11 | Specialist | Login and open `/kol/affiliatewp` | Own approved KOL state visible; Provision capability visible but disabled with permission tooltip | Only own approved KOL visible; Provision visible-disabled; permission tooltip present | PASS |
| KOL-14B-12 | Manager | Click `立即同步` while fake enabled | Synthetic external Affiliate snapshot loads; no live WordPress/AffiliateWP request occurs | UI sync POST 302; `UAT External Pending/Active` synthetic rows loaded | PASS |
| KOL-14B-13 | Manager | Inspect mirror table | Provider status, Provision status, sync state, last error feedback are separately visible | Provider / Provision / sync columns separate; error feedback visible | PASS |
| KOL-14B-14 | Any | Inspect Coupon tab | Disabled; title remains `M1.4C 交付`; no Coupon implementation present | Coupon disabled; title remains `M1.4C 交付` | PASS |

## Fake-provider scenarios
The local fake uses only synthetic `example.test` identities:
- `uat-m14b-ready@example.test` → verified `active` success.
- `uat-m14b-provider-error@example.test` → retryable `PROVIDER_UNAVAILABLE`.
- `uat-m14b-conflict@example.test` → `AFFILIATE_ID_CONFLICT`.
- `uat-m14b-readback@example.test` → `readback_mismatch` because provider status is intentionally non-active.

## Owner conclusion
```text
FAKE_PROVIDER_OWNER_UAT: PASS
LIVE_PROVIDER_COMPATIBILITY: NOT_RUN
OWNER_DECISION: LIVE-PROVIDER COMPATIBILITY PENDING / MERGE NOT AUTHORIZED
AUTOMATED_TESTS_DO_NOT_EQUAL_LIVE_PROVIDER_PASS: YES
```
