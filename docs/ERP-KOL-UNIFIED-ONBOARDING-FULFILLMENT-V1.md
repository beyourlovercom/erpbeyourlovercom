# ERP KOL Unified Onboarding & Fulfillment V1

Supersedes the order-first Quick Order V1 design. KOL is the primary entity;
a fulfillment order is optional and belongs to an existing KOL.

## Entry A — create KOL (default)
1. Search username first, or paste original cooperation text and parse it.
2. Resolve existing KOL by exact normalized platform/handle or verified identity.
3. Existing: open the authorized KOL detail page, do not create another profile.
4. Missing: prefill the standard KOL form; confirm and create the KOL,
   WordPress user and AffiliateWP account, approve and verify active binding.
   Record and provision the coupon under the existing coupon policy.
5. Show KOL detail with an explicit Create KOL Order action. No implicit order.
   Shipping address and product are optional for non-fulfillment onboarding.

## Entry B — create KOL order
1. Search username or paste original cooperation text before order creation.
2. Existing: select/associate KOL and load existing shipping information.
3. Missing: use the same KOL creation workflow first; carry the order draft
   (including product hint and parsed shipping data) back after completion.
4. Show current saved and pasted shipping data; require explicit human
   confirmation of the final shipping address, with existing country rules.
5. Match product candidates by SKU/name and require human selection.
6. Create order with canonical KOL ID; no anonymous or duplicate KOL orders.
   A changed order address does not silently overwrite the profile address.

## Shared implementation
- One parser and paste component used by both forms, no external AI transfer.
- Review parsed fields, retain unrecognized/conflicting input; never invent IDs,
  passwords, tax IDs, coupon rates or product IDs.
- One scoped search/identity resolver and shared onboarding service.
- Reuse existing WordPress, AffiliateWP and coupon provisioning services.
- Verify true affiliate ID, WordPress user ID, Active state and binding.
- Existing inactive/unbound KOLs: use verified identity and repair/approve,
  without creating duplicates or silently taking another owner's profile.
- Provision immediately on onboarding; show partial failures honestly and
  support idempotent repair, preserving the order draft and canonical KOL.
- Missing required shipping/customs fields block fulfillment, not unrelated
  non-shipping onboarding. Never fabricate missing profile data.
- Existing permissions, numbering and historical records remain unchanged.

## Acceptance
- New KOL without shipment: active affiliate, zero orders.
- Existing KOL at create-KOL entry: detail page, no duplicate.
- Existing KOL at order entry: address prefill and confirmation, linked order.
- New KOL at order entry: first onboard, resume same draft, linked order.
- Both forms share parser; product hint is not silently mapped to a wrong SKU.
- Existing unbound/inactive affiliate repaired and read-back verified.
- Provider errors, double submit, ambiguous identity and coupon collisions
  have recoverable, non-duplicating outcomes.
- Dev uses simulated provider operations; no production writes before approval.
