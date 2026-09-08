# ERP-INVENTORY-SHIPPING-WEIGHT-STANDARD-CANDIDATE-V1-3-001

**Status:** IMPLEMENTED — PENDING CHINA MAC VERIFICATION
**Branch:** `feature/erp-shipping-weight-standard-candidate-v1-3-001`

## Scope

Separate raw logistics weight observations (`product_weight_evidences`) from an aggregated Shipping Weight Standard. The aggregated median must never be represented as a raw logistics scan observation.

## Deliverables

| # | Artifact |
|---|---------|
| 1 | Migration: `product_shipping_weight_standard_candidates` table |
| 2 | Migration: `product_shipping_weight_standard_candidate_evidences` provenance table |
| 3 | Migration: `products.shipping_weight_standard_candidate_id` nullable FK |
| 4 | Models: `ProductShippingWeightStandardCandidate`, `ProductShippingWeightStandardCandidateEvidence`, Product relations |
| 5 | `ShippingWeightStandardCandidateService` — create, adopt (with confirm gate), reject |
| 6 | `ProductShippingWeightStandardReader` — aggregated priority + legacy fallback |
| 7 | `ShippingWeightStandardCandidateTest` — focused test suite |

## Non-Goals

- No UI, routes, or controllers
- No import pipelines
- No Production migration execution
- No historical migration edits
- No auto-adopt by confidence level
- No changes to raw `product_weight_evidences` statuses on adoption

## Algorithm: `shipping_weight_median_v1`

1. `createFromEvidenceIds(int $productId, array $evidenceIds)` takes explicit product ID first; all evidences must belong to it. Fingerprint uses that explicit product ID.
2. Compute raw median of all input weights (outlier reference only).
3. Mark as gross outlier any evidence whose relative deviation from raw median exceeds 15%.
4. Eligible = non-outliers.
5. Suggested weight = median of eligible weights.
6. Spread ratio = (eligible_max − eligible_min) / suggested_weight.
7. High confidence iff eligible count ≥ 5 AND spread ratio ≤ 10%.

## Adoption Contract

- `adopt()` returns literal string `'confirm_required'` when replacing a non-null `shipping_weight_kg` without `confirmed=true`.
- `adopt()` returns `null` on success.
- Transaction: supersede only the previous adopted candidate currently pointed to by `product.shipping_weight_standard_candidate_id`, and only if it differs from the new candidate; new candidate → adopted + reviewer metadata; product updated; `shipping_weight_evidence_id` set to NULL.
- `reject()` rejection note parameter is nullable (defaults to null).
- Raw evidence statuses are never mutated.

## Reader Priority

1. Valid adopted aggregated candidate where `candidate.product_id = product.id` → `source_type=aggregated_logistics_standard`
2. Else: legacy adopted raw evidence path
3. Pending/rejected/superseded candidates → never trusted

## Migration Notes

- Additive only — no changes to existing tables except adding `shipping_weight_standard_candidate_id` FK to `products`.
- No Production migration execution. Formal acceptance is China Mac exact-SHA verification.

## No Production Deployment

This Work Package does not authorize Production migration or deployment.
