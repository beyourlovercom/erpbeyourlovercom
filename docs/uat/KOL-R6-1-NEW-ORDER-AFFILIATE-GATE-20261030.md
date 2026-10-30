# ERP KOL R6.1 — new orders only

## Scope
- Staff manually creating a KOL fulfillment order must have kol.view, supply a scoped, active KOL ID, and that KOL must have an active BeYourLover AffiliateWP account with nonzero affiliate_id.
- The KOL profile create action is hidden when the account is missing/inactive. The profile shows an explicit status; the direct order-creation selector disables unbound KOLs and displays a warning. The backend rejects forged direct POSTs.
- Formal collaboration fulfillment orders retain explicit kol_id; before creating a new order they also require an active mapped AffiliateWP account. Existing linked collaboration orders remain idempotently accessible without being altered.
- AffiliateWP edit URL is emitted only for an active beyourlover.com mapping and uses affiliate_id, not wordpress_user_id.
- Historical fulfillment materialization/projection, existing orders and 123 excluded historical records are outside this scope; no migrations, production scripts or WordPress writes.

## Isolated test evidence (US Mac mini)
- Laravel tests/Feature/Kol PASS: 304 tests / 1,491 assertions, including unmapped/inactive, missing ID, out-of-scope KOL, bridge and existing navigation.
- Frontend targeted Vue PASS: 31 tests; Laravel Pint PASS; Vite build PASS.
- Actual isolated Chrome flow on localhost port 18283 and local SQLite:
  - Synthetic unbound KOL #2: no create CTA, warning shown, direct create URL option disabled and warning shown.
  - Bound KOL #1: profile create CTA and correct affiliate #91001; auto-selected KOL #1; saved synthetic order KOL20261030D8ZC007 (local order #9); order detail backlink navigated to /kol/library/1.
- Production remains untouched. Production release and a real new-order Canary require separate explicit Owner approval.

## Operational caveat
- Existing live population at initial read-only baseline: 962 active KOLs; 857 with a mapped affiliate and 105 without. Unmapped KOLs will be blocked for new order creation until the account is linked; intentional fail-closed behavior.
