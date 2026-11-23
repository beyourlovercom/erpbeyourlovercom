# DE Tianyun SKU Sync V2 · S1–S4 Dev (2026-11-22)

## Baseline and limits
- Based on `92c95200` from Draft PR #826 (dependencies: #820 → #824 → #826).
- Isolated branch `feat/de-tianyun-sku-sync-v2-s1-s4-20261122`.
- No live OMS CREATE, Production deployment, or real DE inventory apply.
- Independent worktree; existing 8459 Dev and Production left unchanged.

## S1: outcome semantics
- `success`: all warehouse stock-ledger SKUs verified with ERP quantity readback.
- `partial_success`: some verified; some unmatched, blocked, or not completed.
- `failed`: attempted guarded apply with zero verified SKU or failed task.
- `preview`: OMS read-only observation; never implies an ERP inventory update.
- `needs_reconcile`: write evidence missing; further starts blocked pending investigation.
- Buttons: 完全成功→更新同步; 部分成功→坚持同步; 全部失败→继续同步.
- Queue/running, never, and mapping-needed retain separate process states.

## S2: run-scoped SKU proof
- Adds audit table `xlwms_inventory_manual_run_rows` and five run counters.
- Records each OMS SKU, ERP product, before/after/readback, delta, reason, outcome.
- Successful requires current guarded V2 proof or prior verified proof and matching
  ERP quantity readback, not a match-only or OMS-read-only claim.
- Unchanged stock is recorded separately from an actual updated stock quantity.

## S3: resume safely
- POST resume requires permission and an existing completed DE run.
- Resume always refetches full latest OMS data before re-evaluating unfinished items.
- Previously proven successful SKU is skipped: cannot be rewritten by a "continue".
- If its OMS quantity changed, it is quarantined; operator must use a new refresh.
- Carried-forward success requires the exact SKU, product ID and QOH to still agree.
- Independent DE apply gates, V2, first-checksum approval and FIFO protections retained.
- Pending-reconciliation state prohibits blind resubmission.

## S4: gaps and existing Product OS workflow
- Catalog-only SKUs with no Hannover warehouse stock ledger are informational,
  not an implied ERP zero and not a failed stock update.
- OMS SKU lacking a verified existing ERP identity is explicitly unmatched.
- Authorized operators receive the existing Product OS alias search link;
  they must create/verify identity there before trying again.
- No automatic ERP Product creation and no direct alias approval from the sync page.
- Existing DE warehouse inventory identity and lot rules still apply.

## Dev evidence and next gate
- Synthetic-only tests cover read-only, partial→resume→complete, repeat refresh,
  no-op rows, unmapped rows, catalog-only gaps, changed prior success quarantine,
  permission restrictions and independent warehouse quantities.
- PHP selected regression: 80 PASS / 517 assertions; Vue: 9 PASS.
- TypeScript and build/30-tab checks: PASS.
- S5 still required: separate interactive isolated-Dev Owner UAT.
- No PR merged and no Production release authorized.
