<?php

namespace App\Modules\B2B\Sales;

use App\Modules\Orders\Application\OrderKind;

/**
 * S3-G2: fail-closed ORDER ELIGIBILITY preflight. This is not a finance source
 * reader and cannot attest an Opportunity -> Order connection.
 *
 * No current OrderKind is a B2B trade order. Consequently, all current ERP
 * Orders (consumer/KOL/derived) are explicitly blocked from being treated as
 * B2B commercial receipts. Future B2B support requires an approved new kind,
 * verified association, authorization, and its own immutable audit trail.
 */
final class B2BSalesOrderAttributionGate
{
    /**
     * @param array<string,mixed> $candidate Untrusted context; never supply payment facts based on this alone.
     * @return array{eligible:bool,reason:string,requires_finance_owner_review:bool}
     */
    public function evaluate(array $candidate): array
    {
        $orderKind = trim((string) ($candidate['order_kind'] ?? ''));
        $orderId = $candidate['order_id'] ?? null;
        $companyId = $candidate['organization_id'] ?? null;
        $opportunityId = $candidate['opportunity_id'] ?? null;
        $registeredKinds = array_map(static fn (OrderKind $kind) => $kind->value, OrderKind::cases());

        if (! is_int($orderId) || $orderId <= 0
            || ! is_int($companyId) || $companyId <= 0
            || ! is_int($opportunityId) || $opportunityId <= 0) {
            return $this->reject('missing_canonical_business_identity');
        }

        if (! in_array($orderKind, $registeredKinds, true)) {
            return $this->reject('unregistered_order_kind');
        }

        // The currently registered order kinds are customer, kol_fulfillment,
        // and fulfillment_derived. None is a stand-alone B2B trade order.
        if ($orderKind !== 'b2b_trade') {
            return $this->reject('consumer_or_fulfillment_order_not_b2b');
        }

        // Never change this to a permissive pass by adding a new enum alone:
        // even a future B2B order still requires Finance identity/ledger approval.
        return $this->reject('finance_link_attestation_not_implemented');
    }

    /** @return array{eligible:bool,reason:string,requires_finance_owner_review:bool} */
    private function reject(string $reason): array
    {
        return [
            'eligible' => false,
            'reason' => $reason,
            'requires_finance_owner_review' => true,
        ];
    }
}
