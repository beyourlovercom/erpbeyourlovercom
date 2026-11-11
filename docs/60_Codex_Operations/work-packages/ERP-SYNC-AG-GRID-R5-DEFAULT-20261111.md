# ERP Sync AG Grid · R5 Default Rollout (2026-11-11)

- Owner approved Dev R0–R3, passed independent R4 UAT, and authorized the subsequent controlled release.
- PR #722 merged and Owner-ID-1 opt-in production canary passed on 8,007 read-only ERP records.
- This R5 follow-up changes only /sync/orders to use AG Grid by default for authenticated sync.view users. Other GlobalGridPilot behavior is unchanged.
- Explicit ?global_grid=0 preserves the original table, including after searches/pagination. Top-right 返回原表 control is kept.
- No order status, WooCommerce tracking push, customer notification, permissions, DB schema, queue, scheduler, or integration switch modification.
- GlobalGrid columns retain user-account layout preference, desktop/mobile namespaces, left-fixed order identity, right-fixed actions and non-hideable sync progress.
- Acceptance: exact-HEAD CI, read-only Production canary verification of default AG mode and legacy fallback, bounded 20/50/100 pages, valid assets and healthy app, independently preserved rollback pointer.
- Production release must be a minimal overlay on PR #722 live release, not a full replacement of unrelated ERP workstreams.
