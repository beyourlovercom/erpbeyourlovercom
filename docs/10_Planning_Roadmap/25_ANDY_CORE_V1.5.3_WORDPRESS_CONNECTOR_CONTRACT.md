---
type: external-integration-contract
project: BYL ERP / Andy Core
contract_id: ANDY-CORE-V1.5.3-WORDPRESS-CONNECTOR
status: RELEASED BASELINE / PR #28 POST-RELEASE PATCH AUTHORITY
version: 1.0
updated: 2026-09-01
---

# Andy Core v1.5.3 — WordPress Connector Contract

## 1. Release boundary

- Released stable Andy Core baseline: `v1.5.3` at `715b83e4df9591cc5d47eb73dc8f4df30d157054`.
- Current Connector authority: YBY-Core `main@1b5d1b2ad13d26c15cf47db259351083c418230e`, which is `v1.5.3` plus merged PR #28 post-release Connector patch.
- PR #28 does not change plugin version, tag, release, namespace, contract version, authentication, mutation surface, or Production deployment state.
- Andy Core implementation remains owned by the Andy Core repository; BYL ERP stores and consumes the frozen Connector contract and must not directly mutate WordPress/AffiliateWP database tables.

## 2. Purpose

The connector allows BYL ERP to become the normal operating surface for KOL Specialists and KOL Managers while WordPress, WooCommerce and AffiliateWP remain provider truth.

```text
ERP KOL operations
  -> signed server-to-server Connector request
  -> Andy Core v1.5.3 WordPress Connector
  -> WordPress / WooCommerce / AffiliateWP native APIs/functions
  -> exact provider IDs + read-back result
  -> ERP mirror / approval / Finance workflow
```

## 3. Namespace and version

Recommended REST namespace:

```text
/wp-json/andy-core/v1/erp
```

Every response includes:

```json
{
  "ok": true,
  "contract_version": "1",
  "request_id": "...",
  "connection_key": "beyourlover.com",
  "data": {}
}
```

Errors use an HTTP status plus stable error code and never expose secrets or raw credentials.

## 4. Authentication / replay protection

Required headers:

```text
X-YBY-Key-Id
X-YBY-Connection-Key
X-YBY-Timestamp
X-YBY-Nonce
X-YBY-Idempotency-Key      # required for mutations
X-YBY-Signature-Version: v1
X-YBY-Signature
```

Signature V1 canonical payload:

```text
HTTP_METHOD + "\n"
REQUEST_PATH_WITH_QUERY + "\n"
TIMESTAMP + "\n"
NONCE + "\n"
CONNECTION_KEY + "\n"
IDEMPOTENCY_KEY + "\n"
SHA256(RAW_REQUEST_BODY)
```

`X-YBY-Signature = hex(HMAC-SHA256(shared_secret, canonical_payload))`.

Connector requirements:
- reject stale timestamp outside configured clock-skew window;
- nonce replay protection;
- constant-time signature comparison;
- mutation idempotency by connection + endpoint + idempotency key;
- never log shared secret, authorization header or full sensitive request payload;
- HTTPS required outside explicit local/test mode.

## 5. Health endpoint

```text
GET /health
```

Returns at minimum:
- site URL;
- Andy Core version;
- connector contract version;
- WordPress version;
- WooCommerce active/version;
- AffiliateWP active/version;
- server time;
- writable/operational boolean.

No secret or credential values are returned.

## 6. Snapshot endpoint

```text
GET /snapshot/{resource}
```

Supported resources for V1.5.3:

```text
affiliates
coupons
referrals
payouts
subscribers
```

Query contract:

```text
cursor nullable
updated_after nullable ISO-8601
limit bounded by connector
```

Response returns `items`, `next_cursor`, `provider_modified_at` where available. Snapshot is read-only and provider IDs are preserved exactly.

PR #28 completeness rule for `referrals`:
- the Connector explicitly requests all AffiliateWP referral statuses, including internal `draft` and `failed`;
- ERP must preserve these provider statuses rather than silently filtering or coercing them;
- this is a provider-truth completeness patch within contract version `1`, not a new Connector version.

### Affiliate item minimum
- affiliate_id;
- user_id;
- username/display name/email where provider exposes them;
- status;
- rate/rate_type where available;
- payment email where available;
- registered/modified dates;
- provider totals may be returned as supporting context but ERP canonical Finance remains Order/Referral based.

### Subscriber item minimum (read-only explicit consent facts)

`GET /snapshot/subscribers` returns only records explicitly exposed by the site's subscription/consent source. A WordPress User, role, WooCommerce Customer or Order is never a subscriber fallback.

- `external_subscription_id` required provider-stable string;
- `email` required;
- `first_name` / `last_name` nullable;
- `status`: `subscribed`, `unsubscribed`, `suppressed`, `pending` or `unknown`;
- `consent_source` nullable and preserved verbatim when available;
- `subscribed_at`, `unsubscribed_at`, `status_updated_at` nullable ISO-8601;
- `provider_modified_at` nullable ISO-8601;
- `external_list_id` / `external_list_name` nullable.

The response identifies the actual connection/site source and returns the same bounded `cursor`, `updated_after` and `limit` pagination semantics as other snapshots. Unsupported or unavailable explicit subscription sources fail truthfully; the connector must not fabricate consent records.

### Coupon item minimum
- WooCommerce coupon_id;
- code exact display value;
- normalized code for conflict checking;
- discount_type;
- amount;
- status;
- date_expires;
- linked affiliate_id nullable;
- modified date.

### Referral item minimum
- referral_id;
- affiliate_id;
- context;
- reference;
- amount;
- currency;
- status `paid`, `unpaid`, `rejected`, `pending`, `draft`, or `failed` (all AffiliateWP provider statuses exposed by the Connector);
- description nullable;
- date/referred_at;
- payout_id nullable.

### Payout item minimum
- payout_id;
- affiliate_id;
- exact referral IDs where available;
- amount;
- currency;
- payout_method;
- status;
- date.

## 7. Provision approved ERP KOL

```text
POST /affiliates/provision
```

Purpose: after ERP approval, idempotently ensure one active WordPress User and one approved/active AffiliateWP Affiliate.

Request minimum:
- `erp_kol_id` stable idempotency identity;
- email;
- display_name;
- optional preferred username;
- requested affiliate status = `active`;
- optional payment email.

Rules:
- repeated request for same ERP KOL returns the same WP User/Affiliate IDs;
- never create duplicate Affiliate for the same provision identity;
- if an explicit external Affiliate ID is already bound to another ERP KOL, return conflict;
- Manager/Admin ERP approval maps to active/approved Affiliate without a second WordPress approval step;
- generated password/activation flow must be secure and not returned to ERP in plaintext.

Response minimum:
- wp_user_id;
- affiliate_id;
- affiliate_status;
- created_user boolean;
- created_affiliate boolean.

## 8. Affiliate status mutation

```text
POST /affiliates/{affiliate_id}/status
```

Allowed status transitions are bounded to AffiliateWP-supported states and must return the final read-back provider status.

## 9. Coupon conflict check

```text
POST /coupons/check
```

Input:
- proposed coupon code.

Normalization:

```text
trim + case-insensitive comparison
```

Response:
- available boolean;
- existing coupon_id/code if conflict;
- linked affiliate_id if any;
- source/provider facts needed for ERP conflict UX.

A coupon code is globally unique for the ERP/WordPress operating contract and may belong to at most one Affiliate/KOL.

## 10. Coupon provisioning

```text
POST /coupons/provision
```

Request minimum:
- ERP KOL reference for audit/idempotency;
- authoritative affiliate_id;
- code;
- discount_type;
- amount;
- expiry nullable;
- normal WooCommerce usage restrictions supported by the bounded ERP UI.

Connector sequence:
1. normalize and re-check conflict;
2. create WooCommerce Coupon;
3. bind Coupon to exact AffiliateWP affiliate_id using supported AffiliateWP/WooCommerce APIs/functions;
4. read back Coupon + Affiliate binding;
5. return success only when read-back matches.

Conflict returns HTTP 409 and never overwrites an existing Coupon owner.

## 11. Complete manual-PayPal payout

```text
POST /payouts/complete
```

Called only after ERP Manager has actually sent PayPal money and recorded payment facts.

Request minimum:
- `erp_payout_request_id` idempotency identity;
- affiliate_id;
- exact referral_ids array;
- amount;
- currency;
- payout_method = `paypal_manual`;
- PayPal transaction ID/reference;
- paid_at.

Rules:
- repeated same ERP payout request returns the same AffiliateWP payout result;
- exact Referrals must belong to the affiliate and be eligible for transition;
- create/record AffiliateWP Payout using supported native API/function;
- reconcile exact Referrals to paid;
- return payout_id and exact final Referral states;
- never initiate PayPal money movement;
- if provider reconciliation fails after ERP payment, return retryable provider-sync error. ERP will enter `PAID_SYNC_PENDING` and must retry sync only.

## 12. Error contract

Stable examples:

```text
AUTH_INVALID
REPLAY_DETECTED
CONTRACT_VERSION_UNSUPPORTED
PROVIDER_UNAVAILABLE
AFFILIATE_NOT_FOUND
AFFILIATE_ID_CONFLICT
COUPON_CONFLICT
REFERRAL_NOT_ELIGIBLE
PAYOUT_IDEMPOTENCY_CONFLICT
VALIDATION_FAILED
```

Retryability must be explicit in the response.

## 13. Audit and idempotency

Every mutation records locally in WordPress/Andy Core:
- request_id;
- idempotency key hash/reference;
- endpoint/action;
- actor = ERP trusted system;
- target provider IDs;
- success/failure code;
- timestamp.

No secret material is stored in audit output.

## 14. Out of scope for Andy Core v1.5.3

- ERP business approval decisions;
- KOL threshold policy evaluation;
- ERP Finance calculations;
- PayPal API money movement;
- automatic Instagram/X data collection;
- arbitrary WordPress database access from ERP.

## 15. Acceptance contract

Andy Core v1.5.3 is connector-ready only when:
- health + signed auth + replay/idempotency tests pass;
- all five snapshot resources (`affiliates`, `coupons`, `referrals`, `payouts`, `subscribers`) are available where providers support them;
- provision is idempotent;
- Coupon conflict/create/bind/read-back passes;
- payout complete is idempotent and exact-referral based;
- secrets are absent from logs/responses;
- WordPress/AffiliateWP behavior remains backward compatible with Andy Core v1.5.2 functions outside the connector.
