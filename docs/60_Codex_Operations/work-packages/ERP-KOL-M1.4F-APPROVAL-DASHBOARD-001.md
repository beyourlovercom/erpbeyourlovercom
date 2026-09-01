# ERP-KOL-M1.4F-APPROVAL-DASHBOARD-001

Status: ENGINEERING_GATE_PASS_DRAFT_PR_PENDING
Base: `main@4f2bc7fb51030e7acc231173d1b2d66bd28eca79`
Branch: `feature/erp-kol-m1-4f-manager-approval-dashboard-001`

## Objective

Complete the KOL V1 operating surface with module-separated Manager approval workspaces and role-aware weekly/monthly dashboards, while preserving the canonical KOL, Collaboration, Content, AffiliateWP Referral, and payout-request facts already delivered by M1.4B-M1.4E.

## Frozen Approval Boundary

The Manager approval system is Manager-only and reuses three existing business queues. The canonical approval logic remains shared, while the UI is separated by business module:

- KOL approval: reuse the M1.4B `KolApprovalService` and existing KOL approval projection/history.
- Paid Collaboration approval: ERP-owned approval projection/history added to canonical `kol_collaborations`.
- Commission payout approval: reuse the M1.4E `kol_payout_requests` workflow; approval is not payment.

No second KOL, Collaboration, Referral, payout, Order, or Finance truth is introduced.

## Paid Collaboration Approval Rules

- `cooperation_type = 付费发帖` requires a positive agreement amount.
- Specialist-created paid Collaboration -> `pending` Manager approval.
- Manager-created paid Collaboration -> `approved` immediately.
- Non-paid Collaboration -> `not_required`.
- If a Specialist changes paid terms after approval (paid/non-paid type, amount, or currency), approval is invalidated and returns to `pending`.
- Manager changes to payable terms may remain immediately approved because the Manager is the approval authority.
- Every projection change appends `kol_collaboration_approval_decisions`; prior decisions are not overwritten.
- Legacy Collaborations are backfilled conservatively so M1.4F does not create artificial historical pending work.

## Payout Segregation of Duties

M1.4F supersedes the temporary M1.4E approval permission split without changing payment execution semantics:

- `kol.payouts.request`: Specialist creates a payout request from eligible unpaid Referral facts.
- `kol.payouts.approve`: KOL Manager approves/rejects the payout request.
- `kol.payouts.manage`: Finance records real payment evidence and completes/retries Provider reconciliation only after approval.
- Requester cannot approve/reject their own payout request.
- Finance cannot approve/reject a pending request merely because it can execute payment.
- Manager approval means approved-for-payment; it never means paid.

## Scope / Visibility Boundary

- Manager Approval Center requires `kol.approvals.manage`.
- KOL Manager retains all-scope KOL visibility through `kol.views.manage`.
- Specialist dashboard remains strict owner scope.
- Specialist payout workspace shows only payout requests created by that Specialist and eligible Referrals belonging to KOLs in that Specialist owner scope.
- Finance may see payment execution facts needed for approved payout requests but does not gain KOL approval authority.

## Weekly / Monthly Dashboard

`/kol` is upgraded from the generic KOL overview into a canonical operating dashboard with `This Week` / `This Month` windows.

Metrics are derived from existing canonical tables only:

- new KOLs;
- approved KOLs;
- rejected KOLs;
- formal Collaboration records;
- published Content records;
- Affiliate-attributed sales and commission;
- eligible unpaid commission;
- payout-request status counts;
- Manager pending tasks: KOL / paid Collaboration / payout request.

Affiliate sales count uses distinct Woo `order_reference`, because one canonical Woo Order may contain multiple AffiliateWP Referral rows. Commission remains Referral-derived. Monetary totals are grouped by currency and are never silently summed across currencies.

## User-facing Surfaces

- `/kol/approvals`: compatibility redirect to `/kol/approvals/kols`.
- `/kol/approvals/kols`: dedicated KOL approval workspace with `待审批 / 审批记录`.
- `/kol/approvals/collaborations`: dedicated paid Collaboration approval workspace with `待审批 / 审批记录`.
- `/kol/approvals/payouts`: dedicated commission payout approval workspace with `待审批 / 审批记录`.
- `/kol`: role-aware weekly/monthly operating dashboard.
- `/kol/collaborations`: displays and filters paid Collaboration approval state separately from business status.
- `/kol/payouts`: separates Manager approval actions from Finance completion actions.

## Final Engineering Gate Evidence

- M1.4F focused backend: 5 warning-marked scenarios / 31 assertions / exit 0.
- M1.4F Approval Center + Overview + Payout frontend: 10 / 10 PASS.
- Shared M1.4F + M1.4E payout + KOL approval + Collaboration + Identity regression: 54 warning-marked scenarios / 257 assertions / exit 0.
- Typecheck (`vue-tsc --noEmit`): PASS.
- Production build: PASS; 834 modules transformed; existing >500 kB chunk warning only.
- Fresh SQLite migration rehearsal through `2026_09_01_131000_add_kol_manager_approval_center`: PASS.
- Architecture: 1 failed / 1 risky / 6 passed / 501 assertions.
- Architecture failure is the existing 12 non-KOL `APP-001` baseline violations; M1.4F KOL `APP-001` violations = 0.
- Architecture frozen module-file, application-scan, migration-hash, frontend-hash, route-name, and permission-key evidence updated for M1.4F.
- `git diff --check`: PASS.
- Sensitive literal/path scan: PASS; no secret/key/`.env` artifacts in implementation changes.
- Dedicated workflow: `.github/workflows/erp-kol-m1-4f-approval-dashboard.yml`; YAML parse PASS.
- `origin/main` exact base guard before final checkpoint must remain `6ac92fdd0e6e4f852cf1fd93225abd6bd143b933`.

## Out of Scope

- Production deployment, release, tag, or version bump.
- Any new AffiliateWP/WooCommerce provider truth.
- Automatic payment execution.
- New payout provider integration.
- Cross-currency financial conversion.
- KOL discovery / scraping / intelligence automation.
- Repair of the existing 12 non-KOL architecture baseline violations.

## Next Gate

Create the implementation checkpoint and Draft PR only after the final main-drift, diff, sensitive scan, and local workflow evidence guards pass. Draft PR does not authorize merge or Production deployment.


## Recovery / Rebase Gate

- Original base: `main@6ac92fdd0e6e4f852cf1fd93225abd6bd143b933`.
- `main` advanced via Product M1.2 / PR #140 to `4f2bc7fb51030e7acc231173d1b2d66bd28eca79`.
- Business-file overlap: none; only `tests/Architecture/ModuleArchitectureTest.php` overlapped mechanically.
- Recovery ref created before rebase.
- Rebase onto `main@4f2bc7fb51030e7acc231173d1b2d66bd28eca79`: PASS, no conflicts.
- Post-rebase focused M1.4F backend: 5 scenarios / 31 assertions / exit 0.
- Post-rebase shared KOL/Identity regression: 54 scenarios / 257 assertions / exit 0.
- Post-rebase frontend Approval Center + Overview + Payouts: 10/10 PASS.
- Typecheck: PASS.
- Production build: PASS; existing chunk-size warning only.
- Fresh migration includes Product M1.2 + M1.4F migrations: PASS.
- Architecture remains existing baseline: 1 failed / 1 risky / 6 passed / 507 assertions due only to 12 historical non-KOL APP-001 violations; M1.4F KOL APP-001 = 0.
- `git diff --check`: PASS.
- Sensitive scan: 29 changed files / 0 hits.
- Post-rebase divergence: 0 behind / 1 ahead.


## Owner UAT Follow-up — Approval History

Owner accepted the pending-approval Center and identified a required audit UX gap: previously approved/rejected decisions must remain visible as a reviewable list.

The Approval Center now includes a fourth `审批记录` tab that projects existing canonical history without creating a second approval ledger:
- KOL history comes from `kol_approval_decisions` approved/rejected facts.
- paid Collaboration history comes from `kol_collaboration_approval_decisions` approved/rejected facts, including the frozen amount/currency terms.
- payout approval history comes from the canonical payout request approver/rejector and decision timestamps.
- records show type, outcome, object, actor, decision time, amount/currency when relevant, and rejection reason when present.
- UI filters support approval type and approved/rejected outcome; newest decisions appear first.
- the Center displays the most recent 300 unified audit records; canonical decision tables remain the durable full history.

Follow-up focused evidence: M1.4F backend 5 scenarios / 38 assertions PASS; Approval Center frontend 4/4 PASS; Typecheck PASS; Build PASS (838 modules; existing chunk-size warning only).


## Owner UAT Follow-up — Module-separated Approval UX

Owner rejected the unified mixed Approval Center presentation and approved a module-separated visual direction.
This section supersedes the earlier unified fourth-tab/filter presentation while preserving the same canonical approval facts.

Frozen UX:
- three direct Manager routes: `KOL 审批`, `付费合作审批`, `佣金付款审批`;
- each route owns only two local views: `待审批` and `审批记录`;
- no approval-type filter is required inside history because the route already defines the business type;
- one horizontal card equals one application/decision record;
- card layout is fixed as business facts on the left and approval facts/actions on the right;
- paid Collaboration and payout rows expose amount/currency prominently; payout never exposes encrypted recipient/account data;
- historical rows show outcome, actor, decision time and rejection reason where applicable;
- left shell navigation exposes all three Manager-only approval routes directly;
- `/kol/approvals` remains a backward-compatible redirect to the KOL approval workspace.

Implementation remains projection-only: KOL uses `kol_approval_decisions`, paid Collaboration uses `kol_collaboration_approval_decisions`, and payout uses canonical `kol_payout_requests` decision fields. No new approval ledger or migration was introduced.

Redesign focused evidence:
- module-isolation backend: 5 tests / 52 assertions PASS;
- exact dedicated frontend suite (Approval Center + Overview + Payouts): 11/11 PASS;
- Typecheck: PASS;
- Build: PASS, 838 modules; existing chunk-size warning only;
- exact dedicated PHP suite (M1.4F + Payout + Approval + Collaboration + User Management + Shell Navigation): 81 tests / 724 assertions PASS;
- Architecture: existing baseline only, 1 failed / 1 risky / 6 passed / 507 assertions; M1.4F KOL APP-001 = 0;
- live US Mac Manager UAT renders the new dedicated paid Collaboration history page with one row per record and the three direct sidebar approval entries.


Redesign owner visual UAT status: `AWAITING_OWNER` on US Mac local UAT; current visible page is `付费合作审批 -> 审批记录`.
