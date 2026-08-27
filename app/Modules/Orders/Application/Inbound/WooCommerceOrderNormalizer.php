<?php

namespace App\Modules\Orders\Application\Inbound;

use App\Modules\Orders\Application\OrderStatus;
use InvalidArgumentException;

final class WooCommerceOrderNormalizer
{
    public function normalize(array $payload, string $connectionKey): NormalizedInboundOrder
    {
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            throw new InvalidArgumentException('WooCommerce order id is missing.');
        }

        $items = $this->items($payload['line_items'] ?? []);
        if ($items === []) {
            throw new InvalidArgumentException("WooCommerce order {$id} has no line items.");
        }

        $date = (string) ($payload['date_created_gmt'] ?? $payload['date_created'] ?? '');
        $modified = (string) ($payload['date_modified_gmt'] ?? $payload['date_modified'] ?? $date);
        if ($date === '') {
            throw new InvalidArgumentException("WooCommerce order {$id} has no creation date.");
        }

        $currency = strtoupper(trim((string) ($payload['currency'] ?? 'USD')));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'USD';
        }

        return new NormalizedInboundOrder(
            provider: 'woocommerce',
            connectionKey: $connectionKey,
            reference: (string) $id,
            externalOrderNumber: trim((string) ($payload['number'] ?? $id)),
            orderDate: substr($date, 0, 10),
            providerModifiedAt: $modified,
            currency: $currency,
            orderStatus: $this->status((string) ($payload['status'] ?? 'pending')),
            orderNote: $this->nullable((string) ($payload['customer_note'] ?? '')),
            address: $this->address($payload),
            items: $items,
        );
    }

    private function status(string $status): string
    {
        return match (strtolower($status)) {
            'completed' => OrderStatus::Shipped->value,
            'cancelled', 'refunded', 'failed', 'trash' => OrderStatus::Cancelled->value,
            default => OrderStatus::PendingShipment->value,
        };
    }

    /** @return array<string,string> */
    private function address(array $payload): array
    {
        $shipping = is_array($payload['shipping'] ?? null) ? $payload['shipping'] : [];
        $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
        $source = $this->hasAddress($shipping) ? $shipping : $billing;
        $name = trim(((string) ($source['first_name'] ?? '')).' '.((string) ($source['last_name'] ?? '')));
        if ($name === '') {
            $name = trim((string) ($source['company'] ?? '')) ?: 'WooCommerce customer';
        }

        return [
            'recipient_name' => $name,
            'phone' => trim((string) ($shipping['phone'] ?? $billing['phone'] ?? '')),
            'country' => strtoupper(trim((string) ($source['country'] ?? ''))),
            'province_state' => trim((string) ($source['state'] ?? '')),
            'city' => trim((string) ($source['city'] ?? '')),
            'address_line_1' => trim((string) ($source['address_1'] ?? '')),
            'address_line_2' => trim((string) ($source['address_2'] ?? '')),
            'postal_code' => trim((string) ($source['postcode'] ?? '')),
        ];
    }

    private function hasAddress(array $address): bool
    {
        foreach (['address_1', 'city', 'postcode', 'country', 'first_name', 'last_name'] as $key) {
            if (trim((string) ($address[$key] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    /** @return list<array<string,mixed>> */
    private function items(mixed $lineItems): array
    {
        if (! is_array($lineItems)) {
            return [];
        }

        $result = [];
        foreach ($lineItems as $line) {
            if (! is_array($line)) {
                continue;
            }
            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $remoteProductId = (int) ($line['variation_id'] ?? 0);
            if ($remoteProductId <= 0) {
                $remoteProductId = (int) ($line['product_id'] ?? 0);
            }
            $price = trim((string) ($line['price'] ?? ''));
            if ($price === '') {
                $price = number_format(((float) ($line['total'] ?? 0)) / $quantity, 2, '.', '');
            }
            $result[] = [
                'woocommerce_product_id' => $remoteProductId > 0 ? $remoteProductId : null,
                'sku' => trim((string) ($line['sku'] ?? '')),
                'product_summary' => trim((string) ($line['name'] ?? '')) ?: 'WooCommerce item',
                'quantity' => $quantity,
                'unit_price' => number_format((float) $price, 2, '.', ''),
            ];
        }
        return $result;
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
