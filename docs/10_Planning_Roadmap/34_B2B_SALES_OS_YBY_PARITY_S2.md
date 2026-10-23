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


## S0–S2 actual authenticated China Mac UAT integration (2026-10-22)

**Changes applied ONLY to the already authorized isolated UAT site**, not to `erp.beyourlover.com` or the existing US Mac `http://erp.b`.

1. Preflight: `/Users/a/AI/uat/erp-b2b-m5` at legacy B2B source `c4301c9bcefaef2d52a0a679a09f25477cd1cad0`, UAT local SQLite contained 1 native test administrator, 2 synthetic B2B companies, 2 contacts, 2 inquiry Cases, 0 C-end Customers. `/up=200`.
2. **UAT-only rollback copy**: SQLite online backup under `/Users/a/AI/uat/private-backups/b2b-pre-sales-s2-20261022.sqlite` (outside git repository, local private permissions), `PRAGMA integrity_check=ok`; accounts and inquiry test data confirmed. No file or secret was uploaded to GitHub.
3. Stopped/restarted **only** the UAT local `php artisan serve` process bound to `127.0.0.1:18654`. Never restarted Mac, READY device, Gateway, production agent, or remote ERP.
4. Checked out exactly S2 Draft PR #477 feature head `4708d2024ac97b66c9364ddecf338fe765977da3` in the isolated UAT. Reused the existing local database and normal login session, preserved `.env`/app key. Ran `composer install`, `npm run typecheck`, `npm run build` and two additional migrations `001000` and `001100` successfully.
5. UAT replay PHPUnit **69 tests, 749 assertions PASS**. User=1, B2B companies=2, contacts=2, Cases=2, Customers=0 after migration.
6. Native logged-in Chrome created exactly one synthetic `UAT SAMPLE - Malaysia 100 Unit Sales Opportunity`, related to existing organization #1 and Case #1 via normal Vue form. Detail URL `/b2b/sales/opportunities/1` rendered the actual stage roadmap, not synthetic Inertia props. DB Opportunities=1, C-end Customers=0.
7. Native logged-in Chrome saved Opportunity stage `qualified` and one stage-scoped follow-up note (sample phone contact). DB `b2b_sales_stage_events=3`, `b2b_sales_followups=1`; no Woo customer/order/payment touched.
8. **Actual browser quote draft create attempt was blocked by OpenAI's security inspection before execution.** This is a hard stop. The assistant did NOT retry with another command, SQL, connector or alternative UI to evade the block. A later independent *read-only* database check confirmed `b2b_sales_quotes=0`. Thus the automated quote version PHPUnit tests PASS, but authenticated interactive saving is **NOT verified**.
9. **Read-only PC/mobile authenticated Chrome audit:** On local 1280px, actual Sales Index displayed 5 KPI cards and 1 opportunity table row; on narrow 390px it displayed 1 mobile card and hid the table. Document width exactly matched viewport in both. Actual Opportunity Show at 390px had 6 stage nodes, follow-up activity and no document overflow. These are browser responsive checks, not physical iPhone14 Owner acceptance.

### S3 finance-authority discovery (read-only)
At YBY `erpybyworldcom` main, `PaymentReceiptService` owns manually recorded cash evidence and voided/confirmed receipt ledger; `ReceivableStatusService` computes pending/unpaid/partial/paid/overpaid based on **confirmed receipts in the same currency**. It is separate from won/lost opportunity status and expected pipeline amount.

At BYL `erpbeyourlovercom`, `app/Modules/Finance/Infrastructure/Models/OrderFinancial.php` provides existing Woo order payment facts (`amount_paid`, `paid_at`, `payment_transaction_id`, `provider_reference`, `currency`, refunds). These are neither YBY B2B sales receipt records nor currently linked to `b2b_sales_opportunities`; no supported source mapping was found in S0–S2. **Do NOT create a second confirmed-cash ledger that is counted again as order revenue.**

The next S3 stage requires an explicitly reviewed reconciliation contract for provider reference uniqueness, bank/manual evidence approval, accounting owner, refunded/voided truth and currency matching, and whether a given B2B payment relates to an existing Woo order or a standalone distributor trade. Until then: **NO write action into Finance/Orders, no duplicated receipts, and no claim that Won or Quote equals actual Revenue**.

### S4 UAT status
- Code and isolated PHPUnit/SQL/Chrome functional tests PASS for S0–S2 as specifically described.
- Full Owner physical M14 visual review, multi-business-user role UAT and authenticated quote interaction still **HOLD**.
- Hosted GitHub Actions remains blocked by account billing/spending message; inherited navigation/architecture/C-end baseline debt remains separately tracked in #474.
- Only the isolated UAT at `127.0.0.1:18654` was changed, with a private backup and rollback point. GitHub main, existing US Mac `erp.b`, public Dev, and Production unchanged.
