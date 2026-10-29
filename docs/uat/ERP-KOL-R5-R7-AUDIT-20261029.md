# ERP KOL R5–R7 — evidence gates

- R5: Chrome reached the www AffiliateWP administration page for a known linked affiliate (ERP KOL 1 / Affiliate 878), with the title `Affiliates ‹ BeYourLover® — WordPress`. Read-only observation only. The exact edit form could not be inspected; R5 full edit-form UAT remains open.
- R6: planned profile -> preselected KOL order -> order detail -> KOL profile closure. Preselection must be scoped server-side; no implicit order creation.
- R7 baseline: 888 historical KOL fulfillment orders, all without explicit `orders.kol_id`, and no formal collaboration `order_id` links as of the last verified production readback. Do not treat affiliate sales referrals, matching names, email, shipping addresses, or counts as authoritative proof of fulfillment ownership. Results require real read-only database audit.
- No production updates/backfills or WordPress changes authorized by this audit.
