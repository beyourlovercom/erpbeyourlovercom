# BYL B2B Sales OS S2 — YBY Quote + Follow-up + Won/Lost parity

Status: FEATURE SOURCE AND ISOLATED TEST PASS / INTEGRATED UAT HOLD / NO MAIN MERGE
Based on YBY main source `bfb2244b21a831015913d04a8565f0c83d8240ac`; layered on S0/S1 Draft PR #476. Owner migration gap #475.

## Source contract copied/adapted, not a new sales process
- YBY `SalesOpportunityService::addFollowUp`: separate per-stage note/call/email/WhatsApp/meeting log, next-plan/time, immutable follow-up history and private attachments. BYL port uses `b2b_sales_followups` and `b2b_sales_followup_attachments`, scoped to B2B Opportunity, maximum 3 files x 5MB, permitted PDF/PNG/JPG/DOCX, no outward transmission, Storage::disk('local') only, owner-scoped downloads.
- YBY `createQuote`: quote v1,v2 immutable drafts, subtotal/discount/tax/total in **micro-units**, automatically move active Opportunity to `quoted` with source `quote` stage event, never POST to Woo or create invoice/mail. BYL port uses `b2b_sales_quotes`; quote management requires `b2b.quotes_manage` in addition to B2B view+operate.
- YBY `markWon/markLost/reopen`: record final stage and reason; Won=100% probability, Lost=0; only Lost can reopen with explicit reason and selected stage; no overwriting previous stage history. BYL port keeps Case and B2C Customers independent.
- YBY final roadmap node (Won/Lost) appended to five active stages; source S1 test updated accordingly.
- Distinct `b2b_sales_*` tables and foreign keys; old `companies`, `customers`, `cases`, `business_project_id` are not copied. Deletes restricted to keep quote/follow-up audit.

## Strong guarantees
1. Won is a **sales decision**, never actual cash. No receipt or Accounting/Orders write occurs in S2.
2. Quote is **internal draft**, never an email, and `quoted` stage alone is not proof of a delivered external quote.
3. Every S2 write/read/attachment download inherits scoped `b2b.view` and owner; write needs `b2b.operate`; new Quote needs `b2b.quotes_manage`.
4. Private attachment access checks actual opportunity→follow-up→attachment foreign key chain before local disk download.
5. Stage events and quote versions are append-only snapshots with DB uniqueness for each opportunity/version.

## Verified isolated on China Mac
- PHP lint files PASS; `npm run typecheck` PASS, `npm run build` PASS (chunk size advisory only).
- Full focused B2B + AppShell + ModuleProvider PHPUnit: **69 tests / 749 assertions PASS**; 5 new S2 tests 46 assertions PASS. No C-end database writes.
- Isolated SQLite `migrate:fresh`, S2 `migrate:rollback --step=1`, and `migrate` all PASS; quote, follow-up, private attachment and Opportunity tables exist and are empty.
- Actual Vue components mounted with **synthetic Inertia fixture only** in local Vite + Chrome at desktop 1280×800 and narrow 390×844 for Sales Index and Show (4 checks), both no JS page errors and no document horizontal overflow; six-stage roadmap displayed.
- This is **not** logged-in live ERP Owner UAT or physical iPhone14 validation.

## Next gated S3 scope
Inspect BYL `Accounting / Orders` authoritative money facts and YBY `PaymentReceiptService / ReceivableStatusService`. Propose **read-only reconciliation** before any write path. A sales Won/Quote may update expected/receivable *projection*, but only already-confirmed financial events may count as real paid revenue. Never create a duplicate receipt ledger or transfer actual bank/payment data without separate contract and test. Real customer, Orders and Accounting changes are NOT authorized.

## Release gates
S0–S2 branches remain Draft; don't merge GitHub main, sign, deploy Dev or Prod, send mail, create real orders/payments, import customers, or alter existing isolated China Mac B2B Owner-UAT instance `http://127.0.0.1:18654`. Preserve old PR #473 and its already-accepted Case UAT. CI billing restriction and inherited unrelated baseline drift tracked in #474. Owner physical M14 + true Sales S0–S3 UAT needed before production.
