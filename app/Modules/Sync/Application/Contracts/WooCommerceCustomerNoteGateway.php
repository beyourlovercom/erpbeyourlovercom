<?php

namespace App\Modules\Sync\Application\Contracts;

interface WooCommerceCustomerNoteGateway
{
    /**
     * Submits exactly one customer-visible WooCommerce note for a package.
     * A remote note readback proves Woo accepted it, NOT email delivery.
     *
     * @return array{created:bool,verified:bool,note_id:int}
     */
    public function notifyPackage(int $wooOrderId, string $carrier, string $tracking): array;
}
