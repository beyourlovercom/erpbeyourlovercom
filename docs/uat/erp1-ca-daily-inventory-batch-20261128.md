# ERP1 Canada daily stock and operator batch enablement

Owner directive: automatic Canada stock mirror once daily, retain manual stock sync and five-minute tracking, enable staff one-click warehouse batch dispatch. ERP1 only.

- Daily CA02 mirror at 09:23 Asia/Shanghai; no hourly CA job. NY remains two-hour freshness; CA proof window is 25 hours (24-hour cycle plus one-hour schedule grace), failed or incomplete snapshots invalidate source proof immediately, no cross-warehouse changes.
- Preserve current-warehouse approval, reservation, per-SKU verified mapping, duplicate checks, signed catalog validation and durable no-retry CREATE claims. The cutover excludes existing canary and older historical orders; no automatic CREATE when opening the switch. Exact new-order minimum is recorded at production cutover.
- Local SQLite regression 131/131, 1177 assertions. Isolated MySQL CA suite 10/10, 101 assertions. Daily proof test checks 24-hour CA availability, NY two-hour expiry and CA expiry beyond 25 hours.
- Production enablement requires exact-head CI, clean runtime base, no queued live batch, and existing canceled canary claim retained. Verify schedule and preview read-only. Never submit a real order during this enablement.
