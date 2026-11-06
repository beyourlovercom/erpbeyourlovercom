<?php

namespace App\Modules\Sync\Application\Contracts;

interface WooCommerceTrackingBatchInspector
{
    /**
     * Inspect current WooCommerce order status and every AfterShip package
     * using one bounded collection request per 100 provider orders.
     *
     * @param  list<int>  $providerOrderIds
     * @return array<int, array{
     *     order_status:string,
     *     tracking_items:list<array{tracking_number:string,provider:string,ship_date:?string}>,
     *     has_tracking:bool,
     *     checked_at:string
     * }>
     */
    public function inspectTrackingBatch(array $providerOrderIds): array;
}
