# ERP-CUSTOMER-M1-3-SUBSCRIBER-INTEGRATION-001

STATUS: OWNER_UAT_PASS
EXECUTION_PROVIDER: US_CODEX_CLI
BASELINE: origin/main@1fdd11db586548982cef6b2fb19a7f984f3c2fed
OWNER_UAT_REQUIRED: YES
OWNER_UAT_RESULT: PASS
GIT_DELIVERY_AUTHORIZED: COMMIT + PUSH + DRAFT PR AUTHORIZED IN CHAT 2026-08-30; MERGE NOT AUTHORIZED

## Goal

Project canonical Marketing subscriber data into Customer surfaces without creating a second subscriber authority.

## Authority

- `marketing_subscribers` is the canonical subscriber identity table.
- `marketing_subscriptions` is the cross-source Source / Consent Fact Authority (WordPress, Shopify, Manual CSV, and future providers).
- Customer never reads WordPress or `/sync/wpapi` directly.
- Customer never writes Marketing subscriber or subscription tables. It only projects the canonical Subscriber Master identity and its source facts.
- Customer purchase/account existence never implies marketing consent.
## Scope

1. Add a read-only Customer subscriber projection service/query.
2. Match Customer.email to marketing_subscribers.email using deterministic trim + lowercase semantics only.
3. Customer Dashboard: replace unavailable subscriber KPI with a real count of active Customer records that exactly match a Marketing Subscriber identity. Keep wording/tooltip clear that this is an identity link, not a synthesized global consent.
4. Customer List: add an optional `订阅` Data Grid column. Summarize provider/source facts without collapsing conflicting statuses into one global state.
5. Customer Show: replace the M1.3 placeholder with real subscription rows. Show provider, source_site, status, consent_source, subscribed_at, unsubscribed_at, status_updated_at, last_synced_at.
6. Honest empty states for Customer with no email, no subscriber match, or matched identity with no subscription facts.
7. Focused PHP + Vue tests and existing regression gates.
## Explicit exclusions

- Do not modify Sync or Marketing product code.
- Do not modify Andy Core or WordPress connector code.
- Do not implement Marketing M1.0A or M1.1+.
- Do not add Customer subscriber/consent columns or duplicate tables.
- Do not infer consent from Customer, Order, Woo user, or purchase data.
- Do not synthesize conflicting provider facts into a global subscribed/unsubscribed status. Master consent resolution remains a Marketing responsibility, not Customer M1.3.
- Do not commit, push, create PR, merge, or deploy.

## Parallel dependency

Marketing M1.0A may be implemented concurrently in another worktree. This package develops against the already-merged M1.0 schema only. Final real-data joint UAT occurs after M1.0A is merged and WordPress subscription facts exist in Marketing.

## Verification evidence

- Customer feature tests: 59 PASS / 642 assertions.
- Customer Library Vitest focused: PASS.
- Typecheck, fresh production build, E2E typecheck/contracts: PASS.
- Full Vitest: 354 PASS / 3 known baseline failures in Orders/Create and Products/Edit only.
- Architecture: 3 known baseline KOL/Finance drift failures only; no Customer M1.3 architecture regression.
- System Chrome gate on isolated runtime `127.0.0.1:18116`: PASS for Dashboard, Library, and Customer detail.
- UAT fixture is disposable local data only; it contains one Subscriber Master with conflicting WordPress/legacy source facts to prove source facts remain separate.
- Git delivery remains unauthorized.
