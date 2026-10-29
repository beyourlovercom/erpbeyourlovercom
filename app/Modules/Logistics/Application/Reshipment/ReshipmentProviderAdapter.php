<?php

namespace App\Modules\Logistics\Application\Reshipment;

/**
 * Provider-specific outbound fulfilment contract.
 * The orchestrator owns approval, idempotency, costs and audit;
 * each adapter owns only its native API shape and reconciliation.
 */
interface ReshipmentProviderAdapter
{
    public function providerKey(): string;

    /** @param array<string,mixed> $intent
     * @return array{ready:bool,blockers:list<string>,memo_supported:bool}
     */
    public function preflight(array $intent): array;

    /** @param array<string,mixed> $intent
     * @return array{result:string,provider_order_no:?string}
     */
    public function createDraft(array $intent): array;

    /** @return array{result:string,provider_order_no:?string} */
    public function findExistingExact(string $uniqueThirdOrderNo): array;
}
