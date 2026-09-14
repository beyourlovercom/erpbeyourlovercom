---
type: work-package
project: YBY Business OS
work_package_id: YBY-TRAFFIC-MVP-V0-2-GOOGLE-ADS-READ-001
title: YBY Traffic MVP V0.2 — Google Ads Read-only Ingestion
status: OWNER APPROVED / READY FOR IMPLEMENTATION
execution_provider: CLAUDE_CODE
design_authority: GPT_WEB
verification_tier: HIGH_RISK
baseline: main@5d4997874775cd5295ff932ac1d85da3de2e9a3f
branch: feature/yby-traffic-google-ads-read-v0-2
updated: 2026-09-14
---

# YBY-TRAFFIC-MVP-V0-2-GOOGLE-ADS-READ-001

## 1. Objective

Build the read-only ingestion path that maps real YBY Irrigation Google Ads account/campaign/day metrics into the Traffic foundation merged by PR #292.

Canonical chain:

```text
Google Ads account
  ↓
GoogleAdsReadClient
  ↓
normalized account / campaign / day rows
  ↓
TrafficService
  ↓
traffic_source_accounts
traffic_campaigns
traffic_daily_metrics
```

This package is backend/data only. It does not create the Boss Dashboard UI and does not mutate Google Ads.

## 2. Baseline / prior gate

PR #292 merged under Owner-approved CI infrastructure exception.

Current GitHub main at package creation:

```text
5d4997874775cd5295ff932ac1d85da3de2e9a3f
Merge PR #292: YBY Traffic MVP V0.1 foundation
```

V0.1 already provides canonical tables/services for BusinessProjects + Traffic. Reuse them; do not create parallel tables.

## 3. Real account validation evidence

A live read-only validation through the user's already-connected Google Ads connector confirmed:

```text
account_id       = 502-024-8458
account_name     = 亿碧源-灌溉
currency         = CNY
timezone         = Asia/Shanghai
provider         = google_ads
project_key      = yby-irrigation
```

Observed enabled SEARCH campaigns include:

```text
21455972299  灌溉系统-肯尼亚7.9
24023703280  YBY-Search-IRR-SOLUTION-Tanzania
24220509988  IRR-搜索-批发-TZ
```

The live source exposes the exact fields needed by V0.2:

```text
account_id
account_name
account_currency_code
account_time_zone
date
campaign_id
campaign_name
campaign_status
advertising_channel_type
impressions
clicks
cost_micros
conversions
```

It also exposes YBY-specific conversion fields useful to the later Inquiry/Attribution package:

```text
conversions_yby_wp_表单lead提交_v2607
conversions_yby_wp_wa聊天留言_v2607
```

These custom conversion fields are evidence for later mapping only; V0.2 canonical traffic storage continues to use provider_conversions and must not create Inquiry truth.

## 4. Important connector boundary

The ChatGPT-connected Windsor/Google Ads tool is valid for live source verification, but it is NOT an application runtime dependency.

Forbidden architecture:

```text
ERP runtime → ChatGPT plugin
```

V0.2 must implement a repository-owned read client boundary that can run independently in the ERP runtime.

Preferred long-term provider is direct Google Ads API. The code must remain provider-client swappable so a temporary Windsor HTTP adapter can be added later without changing Traffic domain/application contracts.

## 5. New Application contract

Add a read-only source contract under Traffic Application, for example:

```php
interface GoogleAdsReadClient
{
    public function fetchAccount(string $externalAccountId): array;

    public function fetchCampaignDailyMetrics(
        string $externalAccountId,
        string $dateFrom,
        string $dateTo,
    ): iterable;
}
```

Exact DTO/class names may follow repository conventions, but the returned normalized semantics are frozen:

### Account row

```text
external_account_id
name
currency_code
account_timezone
```

### Campaign/day row

```text
external_campaign_id
campaign_name
campaign_status
advertising_channel_type
metric_date
impressions
clicks
cost_micros
provider_conversions
```

`cost_micros` must stay integer micros end-to-end.

## 6. Direct Google Ads API implementation boundary

Implement a read-only Infrastructure adapter for Google Ads REST/API behind the Application contract.

Secrets/config must be read from environment/config only, never persisted in Traffic business tables.

Expected configuration may include the minimum required Google Ads/OAuth values, following the official client/auth flow used by the chosen PHP implementation. Do not commit secrets or real tokens.

Credential absence must fail safely with a machine-readable status such as:

```text
credentials_missing
```

and must not partially mutate Traffic tables.

No Google Ads write/mutate endpoint is authorized.

## 7. Sync service

Add a bounded sync application service, conceptually:

```text
GoogleAdsTrafficSyncService
```

Inputs:

```text
project_key
external_account_id
date_from
date_to
mode = dry_run | apply
```

Behavior:

1. Resolve BusinessProject by project_key.
2. Fetch/normalize account metadata.
3. Upsert `traffic_source_accounts` with provider=`google_ads`.
4. Fetch campaign/day rows.
5. Upsert campaigns idempotently.
6. Upsert daily metrics idempotently.
7. Return deterministic summary counts.

Expected summary shape:

```json
{
  "mode": "dry_run",
  "project_key": "yby-irrigation",
  "external_account_id": "502-024-8458",
  "date_from": "YYYY-MM-DD",
  "date_to": "YYYY-MM-DD",
  "campaigns_seen": 0,
  "daily_rows_seen": 0,
  "accounts_applied": 0,
  "campaigns_applied": 0,
  "daily_rows_applied": 0,
  "blocked": []
}
```

Dry-run must make zero DB writes.

Apply must be idempotent for the same account/campaign/date natural keys.

## 8. CLI / operational entrypoint

Provide one explicit console command using repository conventions, e.g.:

```text
php artisan traffic:google-ads:sync \
  --project=yby-irrigation \
  --account=502-024-8458 \
  --from=2026-09-01 \
  --to=2026-09-14 \
  --dry-run
```

Apply requires explicit `--apply` or equivalent; absence defaults to dry-run.

Command must print deterministic JSON for evidence collection.

Do not schedule Production execution in this package.

## 9. Historical backfill behavior

The implementation must support bounded date ranges so later we can backfill historical Google Ads daily data.

V0.2 acceptance only requires the mechanism plus test coverage. Real historical backfill is a separate data gate after credentials/runtime are available.

Never use one unbounded `all time` fetch.

## 10. Validation fixture based on real account semantics

Focused tests should include source-fake fixtures reflecting the validated YBY account and the three observed campaigns, without storing secrets.

Fixture account:

```text
502-024-8458 / 亿碧源-灌溉 / CNY / Asia/Shanghai
```

Fixture rows must cover:

- multiple campaigns on the same day;
- the same campaign on multiple days;
- zero-cost / zero-click day;
- non-zero conversions;
- integer `cost_micros`;
- repeated apply yielding no duplicate natural-key rows.

## 11. Required tests

At minimum:

- GoogleAdsReadClient fake contract mapping;
- credentials-missing safe failure for real adapter;
- dry-run produces zero writes;
- apply creates/updates account metadata;
- campaign upsert idempotency;
- campaign/day metric upsert idempotency;
- integer cost_micros preserved;
- date range is forwarded correctly;
- project/account mismatch blocks before mutation;
- malformed/negative metrics block before mutation;
- transaction rollback on mid-sync failure;
- command JSON summary is deterministic;
- existing Traffic V0.1 tests remain green.

## 12. Explicit exclusions

FORBIDDEN in this package:

```text
Google Ads campaign/ad/keyword writes
budget changes
bid changes
create/pause/enable campaign actions
Windsor scheduled export task creation
Boss Dashboard UI
sidebar/menu changes
Inquiry truth / Case creation
Customer truth
Opportunity / Sales assignment
Landing Page editor
SEO / GA4 / Search Console
Meta / TikTok / YouTube
WhatsApp / OKKI / Alibaba
Production deployment
Production DB apply
real credential commit
```

## 13. Allowed implementation surface

Prefer only:

```text
app/Modules/Traffic/Application/**
app/Modules/Traffic/Infrastructure/**
app/Modules/Traffic/Providers/**
app/Modules/Traffic/Experience/Console/** if repository convention requires it
tests/Feature/**Traffic**
tests/Architecture/** only minimal authorized new-file/provider registration
docs/60_Codex_Operations/logs/**
config/** only if a bounded secret-free Google Ads config file is required
```

If unrelated AppShell/Menu/Product/Customer files are needed, stop and report the exact reason.

## 14. Verification gate

On an authorized implementation host:

```text
focused V0.2 tests
existing Traffic V0.1 tests
module/provider architecture tests
migration/regression-safe test suite as appropriate
npm typecheck/build only if shared/frontend surface is touched
git diff --check
git status --short
```

Pre-existing unrelated CI/test drift must be recorded, not silently absorbed into this package.

## 15. Real Data Gate after code merge-ready

Before any real DB apply, run read-only source validation for exactly:

```text
project = yby-irrigation
account = 502-024-8458
```

First real gate is:

```text
Google Ads Read Dry-Run
```

It should confirm account metadata and campaign/day row counts without writing Traffic tables.

Only after dry-run evidence matches the known account/campaign semantics may a separate Owner-approved real data apply be requested.

## 16. Delivery contract

```text
WORK_PACKAGE = YBY-TRAFFIC-MVP-V0-2-GOOGLE-ADS-READ-001
BASELINE = main@5d4997874775cd5295ff932ac1d85da3de2e9a3f
BRANCH = feature/yby-traffic-google-ads-read-v0-2
PR = one Draft PR lifecycle
OWNER_UI_UAT = NOT REQUIRED (backend/data-only)
GOOGLE_ADS_WRITES = FORBIDDEN
PRODUCTION_DEPLOYMENT = NOT AUTHORIZED
PRODUCTION_DATA_APPLY = NOT AUTHORIZED
```

## 17. Next package after V0.2

After read-only ingestion is merged and real dry-run proves source correctness:

```text
YBY-TRAFFIC-MVP-V0-3-INQUIRY-METRICS-001
```

Then:

```text
YBY-TRAFFIC-MVP-V0-4-BOSS-DASHBOARD-001
```

The Dashboard package requires visual proposal → Owner PASS before tracked UI implementation.

PROJECT POSITION
CURRENT_PHASE: YBY Traffic MVP V0.2 Google Ads Read-only Ingestion.
COMPLETED_PHASES: V0.1 Foundation merged via PR #292.
CURRENT_WORK_PACKAGE: YBY-TRAFFIC-MVP-V0-2-GOOGLE-ADS-READ-001.
NEXT_DEPENDENCY: implementation host online + repository reconciliation; real credential authorization only when the direct source adapter reaches live dry-run.
GLOBAL_GATE: read-only only; no Google Ads writes; no Production mutation.
ESTIMATE_RANGE: 2–4 effective engineering hours excluding external credential approval/waiting.
EVIDENCE_AS_OF: 2026-09-14.
