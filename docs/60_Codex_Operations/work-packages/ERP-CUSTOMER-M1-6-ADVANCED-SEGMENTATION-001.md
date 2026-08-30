---
type: work-package
project: BYL ERP
work_package_id: ERP-CUSTOMER-M1-6-ADVANCED-SEGMENTATION-001
status: MERGE_AUTHORIZED
execution_provider: US_CODEX_CLI
verification_tier: DAILY
baseline: origin/main@fd52a4451753abe92c28b52539630af83609b439
owner_uat_required: YES
git_delivery_authorized: NO
production_deployment_authorized: NO
---

# Customer M1.6 — Advanced Segmentation

## Entry state

Customer M1.5 PR #128 is merged and US canonical `main` is synchronized to `fd52a4451753abe92c28b52539630af83609b439`.
M1.6 has an isolated US worktree and branch. No implementation, commit, push, PR, merge or deployment has occurred for M1.6.

## Canonical inherited contract

Only explicit `customers.id → orders.customer_id` relations may drive purchase behavior.
A valid purchase is `order_kind=customer` and `order_status != cancelled`.
Order, Order Item, Shipment, Subscriber and Support remain their own source authorities; Customer segmentation is a read/query projection only.
## Repository-supported M1.6 scope

M1.2 explicitly deferred these advanced system segments:
- high value / 高价值客户
- high frequency / 高频客户
- active / 活跃客户
- dormant / 沉睡客户
- churn risk / 流失风险客户
- VIP / VIP 客户

M1.3/M1.4 integrations also make Subscriber/Support facts available, but no repository contract says M1.6 must create marketing-consent or support-status segments. They remain excluded from the first M1.6 package to avoid mixing behavioral segmentation with provider-conflicting consent/support state.

## Proposed behavior model

The six M1.6 segments are dynamic computed views, not Customer columns and not persisted group membership.
Segments may overlap intentionally; for example a VIP customer may also be active and high-frequency.
Existing M1.2/M1.5 segments remain unchanged.
## Owner-approved segmentation policy

Owner approved the following deterministic M1.6 policy in chat on 2026-08-30:
- `active_customer`: at least 1 valid purchase; latest valid purchase is within 90 days inclusive.
- `churn_risk`: at least 2 valid purchases; latest valid purchase is 91–180 days ago inclusive.
- `dormant_customer`: at least 1 valid purchase; latest valid purchase is more than 180 days ago.
- `high_frequency`: at least 3 valid purchases during the trailing 365 days inclusive.
- `high_value`: lifetime valid-order revenue reaches at least one fixed per-currency threshold below.
- `vip`: both `high_frequency` and `high_value` are true.

Fixed lifetime-value thresholds:
- USD >= 500
- EUR >= 450
- GBP >= 400
- CAD >= 700
- AUD >= 750
- CNY >= 3500

High-value membership is satisfied when any one supported currency bucket reaches its own threshold. Different currencies are never summed or converted for segment membership. Unsupported currencies do not independently qualify high-value. Live ECB FX is forbidden for canonical segment membership.
## Planned implementation

1. Extract advanced segment predicates from `CustomerService` into a bounded Customer-owned segmentation policy/query component so the service does not become a large conditional block.
2. Compute recency/frequency/value directly from valid Customer Orders and Order Items; no copied facts on `customers`.
3. Extend the Customer Groups shortcut rail and Customer List group select with the six M1.6 system segments.
4. Keep the current horizontally scrolling shortcut rail; do not add a second navigation hierarchy.
5. Preserve server pagination and compose advanced segments with existing search/country/source/archive filters.
6. Keep Global Data Grid VI V1.1 preferences unchanged.
7. Add deterministic boundary-date and cancelled-order tests.
8. Add multi-currency value tests after the Owner freezes the value-threshold map.

## Explicit exclusions

- M1.7 Manual Groups / saved manual membership.
- Customer schema expansion or stored segment flags.
- AI scoring, predictive churn, ML, RFM score persistence or background jobs.
- Subscriber consent classification from conflicting providers.
- Support ticket status segments.
- Production deployment, import/export, KOL, Marketing or Inventory changes.
## Acceptance / verification plan

Backend coverage must prove:
- exact 90 / 91 / 180 / 181-day boundaries using frozen test time;
- cancelled Orders never affect recency, frequency or value;
- KOL/non-customer Orders never affect Customer segments;
- high-frequency trailing-window behavior;
- multi-currency value isolation under the approved threshold map;
- VIP equals the intersection of high-value and high-frequency;
- existing M1.2 and M1.5 segments regress unchanged.

Frontend coverage must prove:
- all new segment labels exist in Customer Groups and Customer List select;
- active segment state survives filter composition;
- current header/shortcut horizontal-scroll layout does not regress.

Final gates: focused PHP, focused Vitest, typecheck, fresh build, `git diff --check`, architecture/provider classification, then fresh US-local authenticated Owner UAT with automatic login.
## Execution declaration

WORK_PACKAGE_ID: ERP-CUSTOMER-M1-6-ADVANCED-SEGMENTATION-001
BRANCH: feature/erp-customer-m1-6-advanced-segmentation-001
WORKTREE: /Users/a/AI/007/worktrees/erp-customer-m1-6-advanced-segmentation-001
BASELINE: origin/main@fd52a4451753abe92c28b52539630af83609b439
HOST: US Mac / `as-Mac-mini.local`
DEPENDENCY: Customer M1.5 PR #128 merged — SATISFIED
IMPLEMENTATION_STATUS: COMPLETE / OWNER_UAT_READY.
GIT_DELIVERY_AUTHORIZATION: YES
MERGE_AUTHORIZATION: YES
PRODUCTION_DEPLOYMENT_AUTHORIZATION: NO

## Estimate

Planning/preflight: 0.5h.
Core implementation after policy freeze: 2–3h.
Focused verification/remediation: 1–1.5h.
US-local UAT preparation: 0.5h.
Planning range: approximately 4–5.5 effective hours, excluding Owner review and Git delivery.
## Authorization

OWNER_POLICY_APPROVAL: PASS on 2026-08-30.
OWNER_IMPLEMENTATION_AUTHORIZATION: GRANTED by current chat directive to proceed immediately.
GIT_DELIVERY_AUTHORIZATION: GRANTED — Owner explicitly authorized commit / push / PR on 2026-08-30.
MERGE_AUTHORIZATION: GRANTED — Owner explicitly authorized merge PR #129 on 2026-08-30.
PRODUCTION_DEPLOYMENT_AUTHORIZATION: NOT GRANTED.

## Owner UAT readiness — 2026-08-30

IMPLEMENTATION_STATUS: COMPLETE / OWNER_UAT_READY.
OWNER_UAT_STATUS: PASS — Owner approved in chat on 2026-08-30.
GIT_DELIVERY_AUTHORIZATION: GRANTED — Owner explicitly authorized commit / push / PR on 2026-08-30.
MERGE_AUTHORIZATION: GRANTED — Owner explicitly authorized merge PR #129 on 2026-08-30.
PRODUCTION_DEPLOYMENT_AUTHORIZATION: NOT GRANTED.

Automated evidence:
- Customer backend: 46 passed / 517 assertions.
- Customer frontend: 2 files / 18 tests passed.
- `npm run typecheck`: PASS.
- fresh `npm run build`: PASS.
- `ModuleProviderTest`: 8 passed / 39 assertions.
- M1.6-owned architecture checks: 3 passed / 20 assertions.
- full ModuleArchitecture retains unrelated pre-existing baseline drift; no M1.6-owned failure.
- `git diff --check`: PASS; `public/hot`: absent.

US-local Owner UAT:
- URL: `http://127.0.0.1:18118/customers/groups?group=vip`.
- disposable SQLite only; no production data.
- local constrained admin bypass: `admin@local.test`; fresh no-cookie requests resolve authenticated HTTP 200 without manual login.
- fixtures: 9 synthetic M1.6 customers.
- active: 5 = Active + High Frequency + High Value + VIP + Cross Currency.
- churn risk: 1 = Churn Risk.
- dormant: 1 = Dormant.
- high frequency: 2 = High Frequency + VIP.
- high value: 2 = High Value + VIP.
- VIP: 1 = VIP only.
- Cross Currency USD 490 + EUR 440 does not qualify high value.
- cancelled-only and KOL-fulfillment-only noise fixtures do not qualify advanced Customer segments.

NEXT_GATE: Merge PR #129 using exact-head guard. Production deployment remains separately unauthorized.

OWNER_UAT_PASS_RECORDED: 2026-08-30.
PACKAGE_STATUS: OWNER_UAT_PASS / GIT_DELIVERY_COMPLETE / MERGE_AUTHORIZED / PRODUCTION_DEPLOYMENT_NOT_AUTHORIZED.
