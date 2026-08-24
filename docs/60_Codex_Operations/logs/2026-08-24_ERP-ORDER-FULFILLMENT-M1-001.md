---
type: work-log
project: BYL ERP
work_package_id: ERP-ORDER-FULFILLMENT-M1-001
date: 2026-08-24
status: CONTRACT FORMALIZATION / NO PRODUCT IMPLEMENTATION
---

# Work Log — ERP-ORDER-FULFILLMENT-M1-001

## 2026-08-24 — Owner design decisions frozen / Draft delivery preparation

```text
BASELINE_MAIN: 6ac25f2168dea7e8cd9e8f4c49c10752642a44df
BRANCH: feature/erp-order-fulfillment-m1-001
DESIGN_AUTHORITY: GPT_WEB + OWNER
IMPLEMENTATION_PROVIDER: US_MAC_CLAUDE_CODE
TEST_PROVIDER: CHINA_MAC_CODEX_CLI
GITHUB: UNIQUE_SOURCE_OF_TRUTH
PRODUCT_IMPLEMENTATION: NOT_STARTED / NOT_AUTHORIZED
MIGRATION_EXECUTION: NO
REMOTE_DEPLOYMENT: NO
MERGE_AUTHORIZATION: NO
```

Owner approved the canonical Order / Finance / KOL business semantics, including:

```text
CANONICAL_ORDER_TRUTH = orders.id
PRIMARY_UI_ORDER_NUMBER = external_order_number
INTERNAL_ORDER_NUMBER = generated / unique / immutable / hidden by default
ORDER_KIND = customer | kol_fulfillment
AFFILIATE_ATTRIBUTED_CONSUMER_ORDER = customer + attribution
BASE_CURRENCY = USD
AFFILIATEWP_PAID = actually paid for V1
V1_SHIPMENT = 1 order : 1 shipment
DEFAULT_SHARED_ITEM_COST_ALLOCATION = sales-value proportion unless real item/weight data is available
```

Real Owner-provided WooCommerce and AffiliateWP exports were reviewed outside the repository for design validation. No customer export, AffiliateWP export, credential, token, cookie or production data is committed to the repository.

The current product priority is revised to Unified Manual Order → Logistics / Fulfillment. ERP-INQUIRY-M1.1 Company is already merged; ERP-INQUIRY-M1.2 Contact is PAUSED, not cancelled, and resumes after this package unless Owner changes priority again.

Historical Order/Fulfillment Human UAT findings targeted for exact revalidation are `MVP1-UAT-DEFECT-002`, `MVP1-UAT-DEFECT-003`, `MVP1-UAT-DEFECT-004` and `MVP1-UAT-FINDING-005`. None is closed by documentation alone.

Estimate remains `UNKNOWN WITH EVIDENCE` until the implementation preflight confirms exact migration/backfill behavior, legacy `source_type` data shape, current permission/data-scope integration and affected runtime surfaces. No estimate is fabricated from chat elapsed time.
