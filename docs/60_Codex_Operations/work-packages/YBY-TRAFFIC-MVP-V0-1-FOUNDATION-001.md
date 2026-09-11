---
type: work-package
project: YBY Business OS on BYL ERP framework
work_package_id: YBY-TRAFFIC-MVP-V0-1-FOUNDATION-001
title: YBY Traffic MVP V0.1 — Multi-Project + Traffic Data Foundation
status: OWNER APPROVED / READY FOR IMPLEMENTATION
execution_provider: CLAUDE_CODE
design_authority: GPT_WEB
verification_tier: HIGH_RISK
milestone: YBY-TRAFFIC-MVP-V0.1
baseline: main@e333c9a4e58b661b813206d3cb85bcd8932788d6
branch: feature/yby-traffic-mvp-v0-1
updated: 2026-09-11
---

# YBY-TRAFFIC-MVP-V0-1-FOUNDATION-001

## 1. Objective

Implement only the backend/data foundation required for the first YBY Business OS release:

```text
Business Project
  ↓
Project Sites
  ↓
Traffic Source Account
  ↓
Campaign
  ↓
Daily Ad Metrics

Business Project
  ↓
Daily Inquiry Metrics Projection

Business Project
  ↓
Landing Page Catalog
```

This package intentionally contains **no new owner-facing UI**. It therefore does not trigger the pre-development visual approval gate. A later Dashboard package must complete the visual proposal → Owner PASS gate before tracked UI implementation.

The first operational target after this package is a read-only Boss Dashboard that compares Google Ads spend with inquiry volume week-over-week and month-over-month for YBY Irrigation.

## 2. Owner-approved product architecture

The Owner approved the YBY Business OS direction on 2026-09-11:

- multiple independent business projects;
- each project has its own traffic, products, inquiries and sales operation;
- customer/company identity is global across projects;
- first projects include YBY Irrigation, YBY Bottle and YBY World;
- YBY Irrigation currently includes `ybyirrigation.com` and `yby-irrigation.com`;
- Traffic OS will later cover Google Ads, SEO, Meta/Facebook, TikTok, YouTube, keywords and landing pages;
- first release is Google Ads × Inquiry Boss Dashboard;
- `localerp.ybyworld.com` is the target local/UAT identity for the YBY Business OS runtime;
- Production deployment is NOT authorized by this package.

## 3. Existing canonical truth that must be preserved

This repository already contains canonical Customer and Company truth and an Owner-approved Inquiry architecture contract.

Therefore this package MUST NOT create a second truth for:

```text
Company
Customer
Contact
Case / Inquiry
Order
Product
```

Existing `companies` and `customers` remain canonical where applicable.

The frozen Inquiry contract identifies future `Case` as the business opportunity/inquiry truth. This package must not create a competing `inquiries` table.

For the Boss Dashboard, inquiry volume is stored only as a **daily analytics projection**, not as customer/inquiry business truth. Later Inquiry/Case implementation may become the projection source without changing Dashboard semantics.

## 4. Canonical module ownership

Create two bounded modules:

```text
app/Modules/BusinessProjects/
app/Modules/Traffic/
```

### BusinessProjects owns

- BusinessProject
- BusinessProjectSite
- project/site lookup contracts

### Traffic owns

- TrafficSourceAccount
- TrafficCampaign
- TrafficDailyMetric
- TrafficInquiryDailyMetric (analytics projection only)
- LandingPage

No Traffic model becomes Customer, Case, Order or Product truth.

Cross-module access must follow repository Application contract/query boundaries. Do not import another module's Infrastructure model from new Domain/Application code.

## 5. Frozen schema contract

### 5.1 `business_projects`

```text
id BIGINT PK
project_key VARCHAR(64) UNIQUE NOT NULL
name VARCHAR(191) NOT NULL
status VARCHAR(32) NOT NULL DEFAULT 'active'
archived_at TIMESTAMP NULL
created_at / updated_at
```

Initial allowed status semantics:

```text
active | paused | archived
```

Do not add tenant/billing/financial semantics in this package.

### 5.2 `business_project_sites`

```text
id BIGINT PK
business_project_id BIGINT NOT NULL → business_projects
site_key VARCHAR(64) NOT NULL
domain_normalized VARCHAR(191) NOT NULL
base_url VARCHAR(500) NOT NULL
status VARCHAR(32) NOT NULL DEFAULT 'active'
is_primary BOOLEAN NOT NULL DEFAULT false
created_at / updated_at
UNIQUE(business_project_id, site_key)
UNIQUE(domain_normalized)
INDEX(business_project_id, status)
```

Domain normalization:

```text
lowercase host
remove one trailing dot
no path
no fuzzy matching
```

### 5.3 `traffic_source_accounts`

```text
id BIGINT PK
business_project_id BIGINT NOT NULL → business_projects
provider VARCHAR(32) NOT NULL
external_account_id VARCHAR(191) NOT NULL
name VARCHAR(191) NULL
currency_code CHAR(3) NULL
account_timezone VARCHAR(64) NULL
status VARCHAR(32) NOT NULL DEFAULT 'active'
last_synced_at TIMESTAMP NULL
created_at / updated_at
UNIQUE(provider, external_account_id)
INDEX(business_project_id, provider, status)
```

Initial provider vocabulary recognizes `google_ads`; future providers are not implemented here.

No API key, OAuth refresh token, password or other secret may be persisted in this table.

### 5.4 `traffic_campaigns`

```text
id BIGINT PK
traffic_source_account_id BIGINT NOT NULL → traffic_source_accounts
external_campaign_id VARCHAR(191) NOT NULL
name VARCHAR(255) NOT NULL
status VARCHAR(32) NULL
advertising_channel_type VARCHAR(64) NULL
first_seen_at TIMESTAMP NULL
last_seen_at TIMESTAMP NULL
created_at / updated_at
UNIQUE(traffic_source_account_id, external_campaign_id)
INDEX(traffic_source_account_id, status)
```

### 5.5 `traffic_daily_metrics`

```text
id BIGINT PK
traffic_campaign_id BIGINT NOT NULL → traffic_campaigns
metric_date DATE NOT NULL
impressions BIGINT UNSIGNED NOT NULL DEFAULT 0
clicks BIGINT UNSIGNED NOT NULL DEFAULT 0
cost_micros BIGINT UNSIGNED NOT NULL DEFAULT 0
provider_conversions DECIMAL(14,4) NOT NULL DEFAULT 0
created_at / updated_at
UNIQUE(traffic_campaign_id, metric_date)
INDEX(metric_date)
```

Money truth remains integer micros from the provider. Do not convert to floating-point currency storage.

### 5.6 `traffic_inquiry_daily_metrics`

This is explicitly an analytics/read-model projection, NOT canonical Inquiry truth.

```text
id BIGINT PK
business_project_id BIGINT NOT NULL → business_projects
metric_date DATE NOT NULL
source_type VARCHAR(32) NOT NULL
inquiries INT UNSIGNED NOT NULL DEFAULT 0
qualified_inquiries INT UNSIGNED NOT NULL DEFAULT 0
created_at / updated_at
UNIQUE(business_project_id, metric_date, source_type)
INDEX(metric_date, source_type)
```

Initial `source_type` values may include:

```text
google_ads | seo | direct | other
```

No customer identity, message body, contact details or fake Inquiry record is stored here.

### 5.7 `landing_pages`

```text
id BIGINT PK
business_project_id BIGINT NOT NULL → business_projects
business_project_site_id BIGINT NULL → business_project_sites
page_key VARCHAR(127) NOT NULL
name VARCHAR(255) NOT NULL
canonical_url VARCHAR(500) NOT NULL
path VARCHAR(500) NULL
status VARCHAR(32) NOT NULL DEFAULT 'active'
created_at / updated_at
UNIQUE(business_project_id, page_key)
INDEX(business_project_id, status)
```

This package does not implement page publishing, page editing, SEO metadata, A/B tests, products, keywords or campaign attribution linkage.

## 6. Initial project/site seed contract

Local/testing only seed data may create:

```text
BusinessProject
project_key = yby-irrigation
name = YBY Irrigation
status = active

Sites
- site_key = ybyirrigation-com
  domain = ybyirrigation.com
  base_url = https://ybyirrigation.com
  is_primary = true

- site_key = yby-irrigation-com
  domain = yby-irrigation.com
  base_url = https://yby-irrigation.com
  is_primary = false
```

Do not seed Production automatically.

YBY Bottle and YBY World are architecture-ready but need not receive seed rows in this package.

## 7. Application contracts

Provide bounded service/query contracts sufficient for later ingestion/dashboard packages to:

```text
FindBusinessProjectByKey
ListProjectSites
UpsertTrafficSourceAccount
UpsertTrafficCampaign
UpsertTrafficDailyMetric
UpsertTrafficInquiryDailyMetric
ListProjectDailyTrafficMetrics(date range)
ListProjectDailyInquiryMetrics(date range)
Register/List LandingPage
```

Exact class names may follow repository conventions, but semantics above are frozen.

Upserts must be idempotent on the declared natural unique keys.

## 8. Explicit exclusions

FORBIDDEN in this package:

```text
new Company/Customer/Contact/Inquiry/Case truth
Customer merge logic
sales assignment
Opportunity / quotation / won-lost pipeline
Google Ads OAuth or API calls
Windsor integration
Meta/Facebook
TikTok
YouTube
SEO/GSC/GA4 ingestion
WhatsApp
OKKI / Alibaba
Product OS changes
Landing Page editor/publisher
owner-facing Dashboard/UI
menu changes
Production deployment
Production database mutation
real API credentials/secrets
```

## 9. Allowed implementation surface

Claude may modify only bounded files needed under:

```text
app/Modules/BusinessProjects/**
app/Modules/Traffic/**
app/Modules/ModuleManifest.php
database/migrations/*business_project*
database/migrations/*traffic_*
database/migrations/*landing_page*
database/seeders/** only for local/testing-safe YBY project seed
tests/** for this package
docs/60_Codex_Operations/** for package/work log evidence
architecture/hash/file-set assertions only when required by authorized new files
```

No owner-facing Vue page/navigation work is authorized.

If another product file is required, stop and report the exact file/reason to GPT Web.

## 10. Acceptance criteria

- migrations match the frozen schema and run on MySQL-compatible Laravel schema APIs;
- migration rollback is clean on a disposable local/test database;
- `BusinessProjects` and `Traffic` modules follow the modular-monolith conventions;
- YBY Irrigation + two sites can be seeded in local/testing without Production auto-seed;
- source-account/campaign/daily-metric upserts are idempotent;
- inquiry daily metrics remain analytics projections only;
- money is stored as provider micros, not float;
- no secrets are stored in business tables;
- no duplicate Company/Customer/Inquiry truth is introduced;
- no UI/menu changes occur;
- architecture/module tests remain green;
- full relevant repository tests/typecheck/build are green or failures are evidenced as pre-existing/out-of-scope.

## 11. Required implementation-host verification

Run on the US implementation host:

```text
focused BusinessProjects tests
focused Traffic tests
migration fresh/rollback smoke on safe local/test DB
Architecture/module tests
php artisan test (or full safe suite under repository policy)
npm test when affected shared code requires it
npm run typecheck
npm run build
git diff --check
git status --short
```

No Production runtime or database access.

## 12. Delivery contract

```text
WORK_PACKAGE = YBY-TRAFFIC-MVP-V0-1-FOUNDATION-001
BASELINE = origin/main@e333c9a4e58b661b813206d3cb85bcd8932788d6
BRANCH = feature/yby-traffic-mvp-v0-1
WORKTREE = /Users/a/AI/007/worktrees/yby-traffic-mvp-v0-1
IMPLEMENTATION_HOST = US Mac
EXECUTION_PROVIDER = CLAUDE_CODE
ONE_ACTIVE_IMPLEMENTATION_PROVIDER_PER_WORKTREE = CLAUDE_CODE
PR = one lifecycle Draft PR
OWNER_UI_UAT = NOT REQUIRED (backend/data-only package)
PRODUCTION_DEPLOYMENT = NOT AUTHORIZED
```

After this package merges, refresh from latest `origin/main` before opening the next sequential package.

## 13. Next package after merge

```text
YBY-TRAFFIC-MVP-V0-2-GOOGLE-ADS-READ-001
```

Expected scope:

- Google Ads read-only connector;
- account/campaign/day metric ingestion;
- historical backfill;
- scheduled sync;
- no ad mutation.

Owner credential/API authorization remains a hard gate when needed.

PROJECT POSITION
PROGRAM_ROADMAP: Existing BYL ERP roadmap remains canonical for BYL Production; Owner authorized a new parallel YBY Business OS program on 2026-09-11 using the ERP framework, beginning with Traffic MVP and separate local/UAT identity.
CURRENT_TRACK_PHASE_MAP: YBY Traffic MVP V0.1 Foundation → Google Ads Read → Inquiry Metrics → Boss Dashboard → Attribution → Inquiry OS → Customer OS → Product OS → Sales Pipeline → Expanded Traffic/Landing Page OS.
COMPLETED_PHASES: Product architecture discussion and Owner scope approval on 2026-09-11.
CURRENT_PHASE: YBY Traffic MVP V0.1 Foundation.
CURRENT_WORK_PACKAGE: YBY-TRAFFIC-MVP-V0-1-FOUNDATION-001.
PACKAGE_STATUS: OWNER APPROVED / READY FOR IMPLEMENTATION.
WAITING_PHASES: Google Ads Read; Inquiry Metrics; Boss Dashboard; Attribution; downstream YBY Business OS phases.
BASELINE: origin/main@e333c9a4e58b661b813206d3cb85bcd8932788d6.
GITHUB_STATE: Dedicated local branch/worktree created from latest fetched origin/main; Draft PR not yet opened.
SOURCE_CONFLICTS: Main BYL roadmap documentation does not yet enumerate the newly Owner-approved YBY Business OS parallel program; this Work Package records the 2026-09-11 Owner authorization and does not alter BYL Production scope.
GLOBAL_GATE: Preserve BYL canonical truths; YBY Production release remains separate and unauthorized.
PRODUCTION_DEPLOYMENT_STATUS: NO — package forbids Production deployment and Production DB mutation.
NEXT_DEPENDENCY: Successful merge of this foundation before sequential Google Ads connector work.
EVIDENCE_AS_OF: 2026-09-11.

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 3–5 effective engineering hours; planning value 4h.
KNOWN_CURRENT_TRACK_REMAINING: Google Ads Read 2–4h; Inquiry Metrics 2–3h; Boss Dashboard 3–4h; Attribution 4–6h, excluding external credential/approval waiting.
ARCHITECTURE_GATE_REMAINING: 0h inside this package; product architecture frozen above.
MVP1_TOTAL_ESTIMATE: Release A total 13–21 effective engineering hours from foundation through attribution.
MVP1_PLANNING_BASELINE: 17h.
MVP1_MAXIMUM_ENVELOPE: 21h excluding external credential/approval waiting.
MVP1_REMAINING: 13–21h before execution; update from accepted actual Work Logs only.
POST_MVP_KNOWN_INCREMENT: Inquiry OS / Customer OS / Product OS / Sales Pipeline are later bounded packages and not included here.
LONG_TERM_CUMULATIVE_RANGE: YBY Business OS mature roadmap remains staged; no unsafe arithmetic across overlapping horizons.
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE — later channels and Customer Intelligence depend on external APIs and future frozen scope.
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE — no accepted Work Log for this new package yet.
ESTIMATE_CONFIDENCE: MEDIUM-HIGH for foundation; lower downstream due external integrations.
ESTIMATE_AS_OF: 2026-09-11.
ESTIMATE_METHOD: bounded schema/module/test decomposition against existing Laravel modular-monolith conventions.

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 0.5–1.0h; planning value 0.75h.
CORE_EXECUTION: 1.5–2.5h; planning value 2.0h.
TEST_AND_VALIDATION: 0.75–1.0h; planning value 0.75h.
REMEDIATION_BUFFER: 0.25–0.75h; planning value 0.5h.
ESTIMATED_EFFECTIVE_HOURS: 4.0h.
ESTIMATE_RANGE: 3–5h.
ESTIMATE_BASIS: new bounded schema + two modules + services/models/tests, no UI, no external API.
OWNER_REVIEW: EXCLUDED
MERGE: INCLUDED
MIGRATION: INCLUDED
MIGRATION_FILE_AUTHORING: INCLUDED
MIGRATION_EXECUTION: EXCLUDED for Production; INCLUDED only on disposable local/test DB
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
