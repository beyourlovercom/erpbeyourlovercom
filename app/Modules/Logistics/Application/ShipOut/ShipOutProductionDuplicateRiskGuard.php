<?php

namespace App\Modules\Logistics\Application\ShipOut;

use App\Modules\Logistics\Infrastructure\ShipOut\ShipOutProductionReadOnlyGateway;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * R9 fail-closed 14-day content duplicate guard.
 *
 * Scan authentic signed, paginated OMS warehouse history, not just orderNO.
 * The GET payload is sensitive: recipient fields are used in-memory only.
 * Only emit redacted status codes and counts. Never write a raw address,
 * recipient name, telephone, or provider response to an audit/log/exception.
 */
final class ShipOutProductionDuplicateRiskGuard
{
    private const MAX_ROWS = 1000;

    private const PAGE_SIZE = 100;

    public function __construct(private readonly ShipOutProductionReadOnlyGateway $gateway) {}

    /** @return array{status:string,code:string,checked_orders:int,window_days:int} */
    public function inspect(array $payload): array
    {
        if (! app()->environment('production')) {
            return $this->verdict('unknown', 'SHIPOUT_R9_HISTORY_ENV_DENIED', 0);
        }

        try {
            $candidate = $this->candidate($payload);
            $cutoff = CarbonImmutable::now('America/Los_Angeles')->subDays(14)->startOfDay();
            $known = [];
            $checked = 0;
            $total = null;
            for ($page = 1; $page <= 10; $page++) {
                $response = $this->gateway->contentRiskHistoryPage($page, self::PAGE_SIZE);
                $currentTotal = $response['total'];
                if ($total === null) {
                    $total = $currentTotal;
                    if ($total > self::MAX_ROWS) {
                        return $this->verdict('unknown', 'SHIPOUT_R9_HISTORY_OVERFLOW', 0);
                    }
                }
                if ($currentTotal !== $total || $currentTotal < 0) {
                    return $this->verdict('unknown', 'SHIPOUT_R9_HISTORY_UNSTABLE', $checked);
                }
                $rows = $response['records'];
                $remaining = $total - $checked;
                if (count($rows) !== min(self::PAGE_SIZE, $remaining)) {
                    return $this->verdict('unknown', 'SHIPOUT_R9_HISTORY_INCOMPLETE', $checked);
                }
                foreach ($rows as $row) {
                    if (! is_array($row) || ! is_string($row['orderId'] ?? null)
                        || trim($row['orderId']) === '' || isset($known[$row['orderId']])) {
                        return $this->verdict('unknown', 'SHIPOUT_R9_HISTORY_UNTRUSTED', $checked);
                    }
                    $known[$row['orderId']] = true;
                    $checked++;
                    $date = $this->date($row['orderDate'] ?? null);
                    $syncDate = $this->date($row['lastSyncTime'] ?? null);
                    if ($date === null
                        || (isset($row['lastSyncTime']) && $syncDate === null)) {
                        return $this->verdict('unknown', 'SHIPOUT_R9_HISTORY_DATE_UNVERIFIED', $checked);
                    }
                    // Provider orderDate may represent an older ERP business
                    // date. A recently inserted/updated OMS order can still
                    // be a 14-day duplicate. Use latest verifiable timestamp.
                    if ($date->lt($cutoff) && $syncDate === null) {
                        return $this->verdict('unknown', 'SHIPOUT_R9_HISTORY_DATE_UNVERIFIED', $checked);
                    }
                    if ($date->lt($cutoff) && $syncDate?->lt($cutoff)) {
                        continue;
                    }
                    $remote = $this->remote($row);
                    if ($remote === null) {
                        return $this->verdict('unknown', 'SHIPOUT_R9_HISTORY_CONTENT_UNVERIFIED', $checked);
                    }
                    if ($this->sameContent($candidate['items'], $remote['items'])
                        && $this->sameRecipientRisk($candidate['recipient'], $remote['recipient'])) {
                        return $this->verdict('risk', 'SHIPOUT_R9_14D_CONTENT_DUPLICATE_RISK', $checked);
                    }
                }
                if ($checked === $total) {
                    return $this->verdict('clear', 'SHIPOUT_R9_14D_HISTORY_CHECKED', $checked);
                }
            }

            return $this->verdict('unknown', 'SHIPOUT_R9_HISTORY_INCOMPLETE', $checked);
        } catch (Throwable) {
            // The caller must not see a raw provider exception or sensitive JSON.
            return $this->verdict('unknown', 'SHIPOUT_R9_HISTORY_UNAVAILABLE', 0);
        }
    }

    /** @throws RuntimeException */
    public function assertNoContentRisk(array $payload): void
    {
        $result = $this->inspect($payload);
        if ($result['status'] !== 'clear') {
            throw new RuntimeException($result['code']);
        }
    }

    private function verdict(string $status, string $code, int $checked): array
    {
        return ['status' => $status, 'code' => $code, 'checked_orders' => $checked, 'window_days' => 14];
    }

    private function date(mixed $date): ?CarbonImmutable
    {
        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2})?$/D', $date) !== 1) {
            return null;
        }
        try {
            return CarbonImmutable::parse(substr($date, 0, 10), 'America/Los_Angeles')->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private function candidate(array $payload): array
    {
        if (count($payload) !== 1) {
            throw new RuntimeException('SHIPOUT_R9_CONTENT_UNTRUSTED');
        }
        $row = $payload[0];
        $address = $row['toAddress'] ?? [];
        $forms = $row['shipmentForms'] ?? [];
        if (! is_array($address) || ! is_array($forms) || count($forms) !== 1) {
            throw new RuntimeException('SHIPOUT_R9_CONTENT_UNTRUSTED');
        }

        return [
            'recipient' => [
                $this->normalize($address['name'] ?? null),
                $this->normalize($address['zipCode'] ?? null),
                $this->normalize($address['addressLine1'] ?? null),
            ],
            'items' => $this->items($forms[0]['productList'] ?? null),
        ];
    }

    private function remote(array $row): ?array
    {
        $name = $this->normalize($row['recipientName'] ?? null);
        $zip = $this->normalize($row['zipCode'] ?? null);
        $street = $this->normalize($row['shipTo'] ?? null);
        $shipments = $row['shipmentList'] ?? null;
        if ($name === '' || $zip === '' || $street === '' || ! is_array($shipments)
            || $shipments === [] || ! array_is_list($shipments)) {
            return null;
        }
        $products = [];
        foreach ($shipments as $shipment) {
            if (! is_array($shipment)
                || (isset($shipment['warehouseId'])
                    && (string) $shipment['warehouseId'] !== '1265864414750314497')
                || ! is_array($shipment['productList'] ?? null)) {
                return null;
            }
            foreach ($shipment['productList'] as $product) {
                $products[] = $product;
            }
        }
        try {
            return [
                'recipient' => [$name, $zip, $street],
                'items' => $this->items($products),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private function items(mixed $items): array
    {
        if (! is_array($items) || ! array_is_list($items) || $items === []) {
            throw new RuntimeException('SHIPOUT_R9_ITEMS_UNTRUSTED');
        }
        $grouped = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new RuntimeException('SHIPOUT_R9_ITEMS_UNTRUSTED');
            }
            $sku = strtoupper(trim((string) ($item['omsSku'] ?? '')));
            $qty = $item['qty'] ?? null;
            if ($sku === '' || ! is_int($qty) || $qty <= 0) {
                throw new RuntimeException('SHIPOUT_R9_ITEMS_UNTRUSTED');
            }
            $grouped[$sku] = ($grouped[$sku] ?? 0) + $qty;
        }
        ksort($grouped);

        return $grouped;
    }

    private function normalize(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return preg_replace('/[^a-z0-9]+/', '', strtolower($value)) ?? '';
    }

    private function sameContent(array $candidate, array $remote): bool
    {
        // Matching any whole product SKU/quantity pair may indicate a
        // duplicate even if another OMS order had additional package lines.
        foreach ($candidate as $sku => $quantity) {
            if (($remote[$sku] ?? null) === $quantity) {
                return true;
            }
        }

        return false;
    }

    private function sameRecipientRisk(array $candidate, array $remote): bool
    {
        [$name, $zip, $street] = $candidate;
        [$remoteName, $remoteZip, $remoteStreet] = $remote;
        if ($name === '' || $zip === '' || $street === '') {
            throw new RuntimeException('SHIPOUT_R9_RECIPIENT_UNVERIFIED');
        }

        // Conservative: same recipient+ZIP or same street+ZIP is enough to
        // require Dede manual review, even if another field is formatted
        // differently. Street may be a full unstructured address on OMS.
        return ($name === $remoteName && $zip === $remoteZip)
            || ($zip === $remoteZip && (
                str_contains($remoteStreet, $street) || str_contains($street, $remoteStreet)
            ));
    }
}
