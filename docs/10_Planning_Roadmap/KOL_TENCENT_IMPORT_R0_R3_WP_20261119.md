# WP — ERP KOL 腾讯物流表安全导入 R0–R3 (2026-11-19)
WORK_PACKAGE_ID: ERP_KOL_TENCENT_IMPORT_R0_R3_20261119
EXECUTION_PROVIDER: GPT_WEB
IMPLEMENTATION_STATUS: R0_R3_CODE_AND_TARGETED_QA_PASS; OWNER_LOCAL_UAT_PENDING; NOT_MERGED_OR_DEPLOYED
EXECUTION_OVERRIDE_2026-11-19: Owner 明确禁止 Claude，指定现有自建 BYL DC。GPT Web 通过正式 OAuth self-built DC 直接在美国 Mac mini 独立 worktree 实施，禁止调用 Claude/Codex。
PROVIDER_HANDOFF_2026-11-19: 先前 Claude Code 在认证预检处终止，Codex 受 Owner 全局 rewrite-only 门禁禁止编程；两者均未修改业务代码，均已停止。Owner 随后明确指定 GPT Web 直接通过自建 BYL DC 实施；不再使用 Claude/Codex。
## Scope and owner gate
- Owner: Andy. Scope approved in conversation, including prior import-page visual concept (Owner Visual PASS).
- Source of truth: beyourlovercom/erpbeyourlovercom origin/main at 078de3ec, via isolated US Mac worktree.
- Worktree: /Users/a/AI/worktrees/erp-kol-tencent-import-20261119; branch: feat/kol-tencent-import-20261119.
- Objective: allow ERP administrators to upload a Tencent Docs export locally in ERP, preview safe identity matches, resolve business owner, create missing ERP KOL after review, and bind existing synced WWW AffiliateWP snapshot accounts.
- Must reuse existing KolReconciliationService, KolImportPreviewService, KolOwnerMappingService, KolImportActivationService, KolAffiliateBindingActivationService; add focused adapters rather than duplicate full import engines.
- Data source: 【007日更】BYL发货信息表 — 腾讯-物流-同步, selected worksheet/view 网红-KOL, via admin-uploaded XLSX only. No direct Tencent API requirement.
- Example acceptance identity: pixelpebbles_2, AffiliateWP provider affiliate_id=2169, WWW WP user_id=7254, existing ERP mirror id=2179; never guess its business owner.
## Allowed operations
- Add admin-only upload/preview page, routed behind system.permissions.manage plus existing KOL capabilities.
- Secure parse of named worksheet/view, bounded file size/ZIP and row count, identity field mapping, row-level safe source projection.
- Use source fields ONLY as selected: KOL email, social profile URL/handle/platform, optional clearly business-owner column. Do not treat logistics 负责人 as verified KOL owner by default.
- Existing import-preview, owner-decision, and prepare/apply creation and binding gates remain authoritative; changes only if needed for safe integration.
- Automated tests, test fixtures, Vue UI, documentation, local Dev verification, Git commit/push/PR once passing required checks; no Production deploy.
## Prohibited operations
- No Production database write, Production deploy, live account conversion, Woo/WP Affiliate create/provision, order/referral/commission changes, or overbroad owner updates.
- No raw Tencent export upload to model, public storage, Git, remote logging, or saved full raw-source JSON. No shipping address, phone, tax ID, recipient, tracking, order details persisted by new import path.
- No branch mixing, no unrelated fixes, no bypassing permissions, no automatic association on ambiguous identities or ambiguous owners.
## R0–R3 delivery and acceptance
- R0 (2–3h): contract, diff/scope audit, review existing role gates, sheet parser and activation service; freeze minimal compatibility approach.
- R1 (5–8h): dedicated admin-only route + UI; XLSX sheet selection/validation; whitelist safe values before persistence; SHA-256 audit metadata only; temporary upload lifecycle; no raw file persisted after request; explicit upload notice.
- R2 (7–11h): preview exact matches to ERP KOL + AffiliateWP mirrors; owner source distinction; explicit review/decision; duplicate/conflict/ambiguous paths blocked; no low-confidence writes.
- R3 (4–6h): reuse guarded activation prepare/apply and binding activation, not direct WP provisioning; idempotency, owner authority check, no changes to legacy permissions/orders/referrals; focused pixelpebbles_2 simulated verification.
- R4 tests/UAT preparation (5–7h) is follow-on, not approved for Production deployment; essential unit/feature/security regression tests within R1–R3 are in scope.
- Total roadmap 23–35 engineering hours including R4, reference 29h; current R0–R3 slice ~18–28h excluding waiting for Owner, integration and release.
## Verification tier: HIGH_RISK
- Run php artisan test --filter relevant Kol import/reconciliation/binding/authorization tests, targeted new tests, npm run build; Pint and other relevant repo gates.
- Test non-admin GET/POST forbidden; fake XLSX corrupt/ZIP bounds; missing sheet/headers; safe-column persistence; same file rerun; collision email/social; no owner selection; existing bound account; live account remains untouched; unrelated order/referral fixtures unchanged.
- Do not expose source PII in screenshots, reports, commit messages, errors, console logs or responses.
## Owner UAT and release
- Show Dev-only admin import UI, verify security + review, then Owner UAT PASS before merge per AGENTS. No Production release without separate approval.
- Final report must include actual changed files, commit, tests results, deviations, limitations and next gate. Do not claim live pixelpebbles_2 linked without actual production evidence.
## Execution footer
PROJECT POSITION: ERP / KOL AffiliateWP snapshot → Tencent offline source matching → admin-reviewed canonical KOL import/binding.
ROADMAP HOURS: R0 2–3h; R1 5–8h; R2 7–11h; R3 4–6h; R4 5–7h; total 23–35h.
ESTIMATED CODEX HOURS: Current R0–R3 implementation 18–28 engineering hours; verification to report actual elapsed separately.
