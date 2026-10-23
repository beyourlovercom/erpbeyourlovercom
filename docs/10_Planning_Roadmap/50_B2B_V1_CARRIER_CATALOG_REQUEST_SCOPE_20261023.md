# Carrier active-service catalog lifecycle test — isolated release CI

2026-10-23. Test-only contract correction, stacked after B2B V1 domain-safety Draft #494. No live carrier, dispatch, shipment, service flags or Production changes.

CarrierServiceCatalog intentionally caches currently enabled services per service instance/request for efficient routing. In production a fresh web request obtains a new non-singleton catalog. Legacy test disabled a seeded service in SQLite **after using and caching the same instance** and expected the already-cached object to reflect that DB mutation, which is not the real request lifetime. The test now simulates the next request by constructing a fresh CarrierServiceCatalog, asserts the disabled UPS Next Day Air cannot be resolved and UPS active Ground stays the fallback. This proves the next request enforces disabled service without introducing a per-line-item DB query or changing carrier authority. Within-request administrative changes would require cache invalidation by the caller; this legacy test does not prove that, and no such Production mutation was authorized.

Targeted CarrierMaster suite: **8 tests, 48 assertions PASS**. Isolated full suite before: **1,973 tests / 18,227 assertions / 0 errors / 7 failures**. After: **1,973 tests / 18,228 assertions / 0 errors / 6 failures** (6 skipped). The global suite still FAILS.

The six remaining failures are three frozen Architecture manifests #474, two owner-frozen Weship Mexico/FedEx bilingual aliases #486 and one wait-stock page double batched external refs load under separate fresh payment evidence check. No change to global frozen lists or Owner Logistics routes.
