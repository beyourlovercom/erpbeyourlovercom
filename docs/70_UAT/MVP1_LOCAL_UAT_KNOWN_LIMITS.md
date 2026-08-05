# MVP1 本地 UAT 已知限制

- 本版本只支持人工录入和人工网站更新，不调用 WooCommerce、Shopify、WordPress 或其他外部 API。
- Tracking 不自动回写外部网站；批量同步、Webhook、自动导入和自动邮件均不在范围内。
- `customers`、`products`、`inventory`、`procurement`、完整 `accounting` 和完整 KOL OS 不是本次 MVP1。
- UAT 使用本机专用 MySQL `8.4.x`、Redis 和合成数据；SQLite 只保留为 001F-E 历史证据。
- Browser E2E 是自动化证据，不是 Owner 的人工 UAT 结论。
- 由于测试环境是本地运行，不能证明 Remote Dev、Staging 或 Production 的部署状态。
- 大范围历史 PHP 文件存在 Pint 格式漂移；本次未改写无关历史兼容文件。
- 历史阻塞：旧 Codex sandbox 曾拒绝 loopback；当前 danger-full-access PHP CLI 已加载 `redis 6.3.0`，Redis/Queue/Scheduler 自动验收均已通过。当前仅等待 Owner local human UAT。
