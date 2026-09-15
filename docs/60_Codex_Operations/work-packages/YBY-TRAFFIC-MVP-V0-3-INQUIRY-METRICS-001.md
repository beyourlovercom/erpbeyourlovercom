# YBY-TRAFFIC-MVP-V0-3-INQUIRY-METRICS-001

## 0. Execution Prompt
Implement a bounded Traffic analytics projection for YBY inquiry signals without creating or mutating canonical Inquiry/Case truth. Keep all Google Ads access read-only. Default CLI mode is dry-run; Production apply/deploy require separate Owner gates.

## 1. Baseline
- Repository: `beyourlovercom/erpbeyourlovercom`
- Baseline: `main@154684e3d77acba52c14e7de6a73a665a9f13ff9`
- Branch: `feature/yby-traffic-inquiry-metrics-v0-3`
- Previous package: V0.2 merged/deployed; Production has account `502-024-8458`, 3 campaigns and 18 daily traffic rows for 2026-09-08..13.

## 2. Canonical truth boundary
ERP Inquiry M1.0 architecture is Owner-approved, but implementation status on current main is M1.1 Company COMPLETE, M1.2 Contact PAUSED, M1.3 Case WAITING. Therefore V0.3 MUST NOT pretend canonical Case rows exist and MUST NOT create a competing inquiries/cases table.

`traffic_inquiry_daily_metrics` remains analytics projection only. Later canonical `InquiryMetricsQuery` from implemented Case truth may replace the source without changing Dashboard semantics.

## 3. MVP source decision
For Release A, use provider-side Google Ads inquiry conversion signals as the bounded proxy source because they already exist and are measurable today:
- `YBY | WP | 表单Lead提交-V2607` → `google_ads_form_lead`
- `YBY | WP | WA聊天留言-V2607` → `google_ads_whatsapp_lead`

Validated source evidence for 2026-09-08..13: 27 total conversions = 26 form leads + 1 WhatsApp lead. This is provider signal evidence, not canonical Customer/Case truth.

## 4. Qualification semantics
`qualified_inquiries` MUST remain 0 in this package and summary must expose `qualification_state=unavailable_until_case_truth`. Do not infer qualification from ad conversion data, Customer creation, order creation, or any other proxy.

V0.4 Boss Dashboard must render qualified inquiry as unavailable until canonical Case qualification is connected; it must not present zero as a measured business result.

## 5. Data behavior
Sync creates one leaf projection row per business date × configured source type, including explicit zero rows. This prevents stale nonzero metrics when provider attribution later revises a day to zero.

Natural key remains `(business_project_id, metric_date, source_type)`. Apply is idempotent. Duplicate source/date rows from the reader block before mutation. Fractional provider conversion counts block rather than being rounded into fake inquiry counts.

## 6. CLI
`php artisan traffic:inquiry-metrics:sync --project=yby-irrigation --account=502-024-8458 --from=YYYY-MM-DD --to=YYYY-MM-DD --dry-run`

`--apply` is explicit and separate. Missing mode defaults to dry-run. `--dry-run` + `--apply` is blocked.

## 7. Required summary
Return deterministic JSON containing mode, project/account/date range, source types, daily rows, total inquiry signals, qualified count, applied rows, qualification state and blockers.

## 8. Guards
- project must exist;
- matching V0.2 Google Ads source account must already exist;
- source account must belong to project;
- dates bounded/valid;
- source type machine-safe and <=32 chars;
- inquiry values whole non-negative integers;
- DB apply transaction-wrapped;
- Google Ads mutate endpoints forbidden.

## 9. Explicit exclusions
No canonical Case/Contact implementation. No Customer truth mutation. No Opportunity/Order creation. No qualified-lead inference. No Campaign writes. No scheduling. No UI/sidebar/menu. No Boss Dashboard. No Production apply or deploy without separate Owner approval.

## 10. Acceptance
Focused adapter/service/command tests PASS; existing V0.1/V0.2 Traffic tests PASS; MySQL compatibility/provider tests PASS; `git diff --check` PASS. Pre-existing ModuleArchitecture baseline drift remains evidence-only unless this package introduces additional drift.

## 11. First real gate
After code is merge-ready, run Google Ads Inquiry Metrics Dry-Run for project `yby-irrigation`, account `502-024-8458`, date 2026-09-08..13. Expected source evidence: 2 source types, 12 daily projection rows, 27 inquiry signals, 0 qualified inquiries, 0 DB writes.

## 12. Next package
After V0.3 merge + approved Production apply/deploy: `YBY-TRAFFIC-MVP-V0-4-BOSS-DASHBOARD-001`.
