# BYL ERP OMS Grid R3 — Performance UAT and Roadmap

Date: 2026-11-05 | PR #628 | Read-only Production status; isolated UAT changes only.

## Root causes verified

1. resources/js/app.ts previously used a Vite eager page glob. Unrelated inventory/logistics/finance/products Vue pages were bundled into the initial ERP entry (~1,400 KB minified).
2. The UAT Laravel development server transferred all JS as uncompressed HTTP with no long-lived hashed-asset browser cache. From China Mac, the 1,272,440-byte AG Grid chunk required 22.28 s for one download and 10.63 s for another.
3. OrderService already uses server pagination: by default only 50 canonical orders plus their item/address/fulfillment relationships are fetched. The whole database does not reach the browser.

## Changes made

- Inertia page resolver now dynamically imports the requested Vue page, preserving original component paths and showing an explicit error for an unknown page.
- Separate Tailnet-only UAT Nginx on 100.94.52.109:8200 serves content-hashed Vite files under /build/assets/ with gzip, Vary and Cache-Control max-age=31536000 immutable; ordinary HTML and Inertia/API requests proxy to the same isolated Laravel UAT without immutable caching.
- Old Tailnet UAT :8199 remains available unchanged for side-by-side comparison. No Mac, Chrome or Agent restart, no Production changes.

## Measured evidence

| Metric | Before | After | Context |
|---|---:|---:|---|
| Initial ERP entry JS | ~1,400 KB | ~210 KB | -85% |
| Total decoded JS on OMS Orders page | ~2,658 KB | ~1,555 KB | -42% |
| AG Grid on-wire JS | 1,272,440 B | 356,579 B | gzip |
| China Mac forced first download of AG Grid | 22.28 s | 3.49 s | cross-region network |
| China Mac forced repeat download | 10.63 s | 3.95 s | a fresh HTTP request, not a cached navigation |
| Browser first optimized JS transfer | n/a | ~461 KB | UAT Chrome resource timing |
| Browser repeated navigation JS transfer | n/a | 0 KB | immutable cache hit |
| Local browser render, 229 orders, 50/page | 299 ms | 300 ms | no render regression |
| Local browser render, 1,729 orders, 50/page | n/a | 338 ms | +1,500 synthetic SQLite orders |
| Local browser render, 1,729 orders, 100/page | n/a | 285 ms | server paged |
| Local browser render, 1,729 orders, 20/page | n/a | 187 ms | server paged |

Note: US Mac local times do not represent a guarantee for China Mac. China Mac network throughput fluctuates and these figures compare different server compression modes, not identical network conditions. Production MySQL and infrastructure remain unprofiled.

## Regression and safety

- TypeScript PASS; Vite build PASS with separate 1.27 MB AG Grid Community chunk (approximately 354 KB gzip).
- Authenticated Chrome on optimized UAT: OMS Orders, legacy Orders, Customer Orders, KOL Orders and Order Detail all HTTP 200, no JS page errors or failed static assets.
- /products and /inventory returned 403 for the purposely limited orders-only UAT test account; these cannot be counted as authorized page passes.
- Orders remain server-paginated by canonical order, never SKU line. Default 50; 100 optional; ALL is limited to 200 filtered canonical orders.

## Production deployment standard (requires separate approval)

- On the actual ERP web server/CDN, enable gzip or Brotli for JS/CSS/SVG, send Vary: Accept-Encoding, and long immutable caching ONLY for filename-hashed /build/assets/ resources.
- Never give immutable caching to HTML, dynamic authenticated APIs, Inertia JSON or unhashed manifest.json.
- Deploy matching manifest/hashed assets atomically, retain rollback compatibility and verify cache headers with a real China Mac browser.
- Audit MySQL slow queries and indexes independently on newly migrated Inventory and Logistics lists. This trial does not prove all future list endpoints have equal query performance.

## Remaining Roadmap and engineering time estimates

| Stage | Deliverable | Estimate |
|---|---|---:|
| R3-PERF | Lazy page imports, isolated gzip + cache UAT, 1,729-order scale tests | Completed |
| R3-D | Controlled Dev real-data UAT for permissions, SKU rows, import/export and 50/100 page workloads | 2–4 h |
| R3-E | Exact-HEAD merge review, separate Owner Production approval, site compression verification, canary + rollback | 1–3 h |
| R4-A | Inventory and overdue-stock list adoption using shared grid and current backend | 4–7 h |
| R4-B | Domestic/Overseas Logistics list adoption with existing quick-fill/workflow | 5–9 h |
| R5 | High-frequency Products, KOL, Accounting list adoption with per-domain permissions | 6–11 h |
| R6 | Remaining lists scoped inventory, regression and remove duplicates only after approved migration | 6–12 h |

Owner approved R3 UI and continuation of performance work. This is NOT an approval to merge main or deploy Production: production writes or releases need separate exact-HEAD Owner authorization.

## R3 final production canary safety (2026-11-05)
- Production feature controls are configured in config/oms_grid.php and disabled by default, with a mandatory non-empty exact-user-ID canary allowlist; export and bulk import require separate flags.
- Browser lazy import regression was repeated with local view-only Product, Inventory, Logistics and Accounting permissions (test SQLite only). Nine authorized routes including Orders/Customer/KOL/Detail/Products/Inventory/Logistics/Accounting returned HTTP 200 and had no JavaScript/static-asset failures.
- Public Production asset headers (read only): gzip enabled; hashed JS and CSS currently cache for 4 hours. Do not change Production cache policy without deployment approval.
- Remote Dev domain currently fails DNS, so actual Dev real customer data UAT is not claimed.
- Exact release checklist: docs/oms-grid-r3-production-rc.md.
