# ERP-KOL-M1.4A-AFFILIATEWP-CONNECTOR-FOUNDATION-001

Status: COMPLETE / MERGED PR #117
Merge SHA: `1a7347f94938be2db29cbcd1fad6822aedcd9052`

## Baseline
- Branch: `feature/erp-kol-m1-4a-affiliatewp-connector-001`
- Base: `main@3e4328deb9990f41ec38308463cbfa5336e82305`
- Depends on merged KOL M1.0-M1.3.
- External execution dependency: Andy Core `v1.5.3` WordPress Connector, implemented separately from released `v1.5.2`.

## Objective
Build the ERP-side provider foundation required by all later M1.4 packages without yet creating Affiliate/Coupon/Referral/Payout business mirrors. The foundation must give later packages one deterministic signed gateway to Andy Core rather than direct WordPress database/API calls scattered through controllers.

## In scope
- `services` configuration for connector base URL, connection key, key id, shared secret and bounded timeout/retry settings;
- secrets from environment/config only; no plaintext credential migration/table;
- application gateway contract for descriptor, health, paged provider snapshots, affiliate provision/status, coupon check/provision and payout completion;
- HMAC-SHA256 V1 signer exactly matching Contract 25;
- HTTP infrastructure adapter using Laravel HTTP client;
- stable connector response/error DTO or equivalent typed boundary;
- validation of contract version, connection key and provider response envelope;
- explicit retryable vs non-retryable connector error classification;
- one bounded console/diagnostic health probe that does not reveal secrets;
- service-provider binding;
- focused tests using HTTP fakes/local deterministic fixtures;
- documentation/Roadmap synchronization already frozen by D-042.

## Not in scope
- no Affiliate/KOL mirror migration;
- no Coupon mirror migration;
- no Referral/Payout mirror migration;
- no KOL approval UI/policy evaluator;
- no WordPress user/Affiliate business workflow UI;
- no `/kol/affiliatewp` Influencer/Coupon production workspace yet;
- no Commission payout request workflow;
- no Manager dashboard;
- no PayPal API;
- no Andy Core repository mutation in this Work Package;
- no Production access/deployment.

## Security contract
Required signed headers and canonical string are frozen in `25_ANDY_CORE_V1.5.3_WORDPRESS_CONNECTOR_CONTRACT.md`.

ERP rules:
- shared secret is never returned by descriptor/status APIs or exceptions;
- raw sensitive request body/header dumps are forbidden;
- each mutation requires caller-provided idempotency key;
- each request gets fresh nonce and timestamp;
- HTTPS is mandatory outside explicit local/testing configuration;
- connector HTTP redirect following must not silently send signed credentials to a different host;
- bounded connect/request timeout; no infinite retry.

## Gateway surface
Conceptual contract:

```text
descriptor()
health()
snapshot(resource, cursor?, updated_after?)
provisionAffiliate(payload, idempotencyKey)
setAffiliateStatus(affiliateId, status, idempotencyKey)
checkCoupon(code)
provisionCoupon(payload, idempotencyKey)
completePayout(payload, idempotencyKey)
```

M1.4A implements transport and response contracts only. M1.4B-E own domain-side calls and persistence.

## Provider truth
- WordPress User truth = WordPress;
- WooCommerce Coupon/customer-order truth = WooCommerce;
- Affiliate/Referral/Payout truth = AffiliateWP;
- ERP later stores synchronized mirrors/projections plus approvals/audit/Finance facts; M1.4A does not create those tables.

## Verification tier
Tier B because this package establishes security-sensitive server-to-server authentication and external integration behavior.

US implementation-host gate before delivery:
- PHP syntax/static checks for touched PHP;
- focused connector tests;
- existing affected Architecture/Sync/KOL tests;
- TypeScript/build only if frontend/config consumers are touched;
- `git diff --check`;
- secret-leak grep;
- deterministic fake-provider signature/response tests.

A live Andy Core v1.5.3 health probe is NOT required before ERP M1.4A PR because the external connector is a separately delivered dependency. The exact live compatibility gate becomes mandatory before M1.4B Owner UAT.

## Delivery gate
- one Work Package / one branch / one PR;
- US Claude Code implements this frozen package;
- do not commit unrelated M1.4 prototype files from `feature/erp-kol-m1-4-finance-affiliate-001`;
- no merge without explicit Owner authorization;
- no remote Production deployment.

## Followed by
`ERP-KOL-M1.4B-INFLUENCER-APPROVAL-PROVISIONING-001`.
