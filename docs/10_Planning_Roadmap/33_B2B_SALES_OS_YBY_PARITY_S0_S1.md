# BYL B2B Sales OS — YBY parity S0/S1

Status: ISOLATED FEATURE CANDIDATE / LOCAL TEST REQUIRED / NOT PROD
Source: `beyourlovercom/erpybyworldcom` main `bfb2244b21a831015913d04a8565f0c83d8240ac`.
Target base: `beyourlovercom/erpbeyourlovercom` Draft PR #473 head `c4301c9bcefaef2d52a0a679a09f25477cd1cad0`.
Gap tracking: #475.

## Why port the sales funnel
YBY already ships `SalesOpportunityService` (discovery→qualified→solution→quoted→negotiating, won/lost), YBY Sales/Index.vue, Sales/Show.vue roadmap, quote versions, follow-ups, receipt truth and explicit sales attribution. BYL's previously accepted B2B Case M1–M5 **never ported Sales OS**. This S1 branch reuses the YBY Sales/Index Vue markup/CSS and stage semantics, and adds a BYL-specific B2B persistence adapter rather than pretending the Case alone is an Opportunity.

## S0 data contract
| YBY Sales dependency | BYL B2B adapter |
|---|---|
| `business_project_id` | No cross-project foreign key for this single B2B workspace |
| `companies` | `b2b_organizations` |
| `customers` as business contact | `b2b_contacts`, must belong to organization |
| legacy `cases` | `b2b_cases`, case→opportunity unique & idempotent |
| `sales_opportunities` | `b2b_sales_opportunities` |
| `sales_opportunity_stage_events` | `b2b_sales_stage_events` (append-only, restrict FK delete) |
| stage probabilities | YBY defaults: 10%,30%,50%,65%,80%; Won 100%, Lost 0% |
| quote/payment truth | S2/S3, **not delivered in S1** |

## Permission/ownership contract
- Read `b2b.view`; write `b2b.operate`. No implicit C-end customers access.
- Non-manager sees/writes only own Opportunities; company must be personally owned or contain one of their Cases.
- Manager `b2b.view_all` sees all; selecting another owner requires `b2b.assign` and destination active user with B2B view+operate rights.
- Case must belong to selected Organization, be open, and be accessible to operator. Same Case cannot create a second Opportunity. Submitted contact must belong to that B2B Organization.
- Actual money: `expected_value_micros` is expected *sales pipeline*, NOT recognized Revenue, not booked receipt, and cannot be silently written to Accounting.

## S1 accepted technical delivery target
- Keep exact YBY five active stages and source Sales list KPI, first-class Opportunity model distinct from inbound Case.
- Add dedicated `/b2b/sales` second-level menu and opportunity route, authenticated show and POST/PATCH, with owner-scope tests.
- Append every changed stage to independent stage events. Source roadmap UI adapted for BYL company/Case; mobile cards preserve key sales facts.
- Isolated SQLite migration up/down dry-run, typecheck, Vite build, PHPUnit B2B regression and actual nonproduction Chrome PC/390px UAT.
- No quote/receipt, attachment, external channel, mail, WordPress, real customer import, main merge, production deploy, release or payment changes.
- **S2**: port YBY roadmap follow-up+attachments and versioned Quotes; **S3**: reconcile YBY receipts/receivable facts with BYL Accounting without double-booking; **S4**: Owner physical M14 and complete parity UAT.

## Design reuse statement
`resources/js/Pages/B2B/Sales/Index.vue` is based on and adapted from YBY `resources/js/Pages/Sales/Index.vue`, including KPI/pipeline visual vocabulary and the YBY Sales section stylesheet. Other YBY modules such as C-end Customer or BusinessProject are not copied.
