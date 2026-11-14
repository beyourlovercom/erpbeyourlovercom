<?php

namespace App\Modules\Orders\Application;

use App\Modules\Audit\Infrastructure\Models\AuditRecord;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * ERP operator changes are the authoritative local truth. Historical manual
 * order.updated audits also count as protected, including pre-deployment edits.
 */
final class OrderManualFieldProtection
{
    private const ORDER_FIELDS = [
        'external_order_number', 'order_date', 'order_note', 'currency',
        'order_status', 'order_kind', 'source_type', 'owner_id',
    ];

    private const ADDRESS_FIELDS = [
        'recipient_name', 'phone', 'email', 'company', 'country',
        'province_state', 'city', 'address_line_1', 'address_line_2',
        'postal_code',
    ];

    /** @return array<string,bool> */
    public function forOrder(Order $order): array
    {
        $fields = DB::table('order_manual_field_overrides')
            ->where('order_id', (int) $order->getKey())
            ->pluck('field_key')->mapWithKeys(fn (string $name): array => [$name => true])->all();

        // Legacy audit history predates the durable field-protection table.
        // Do not try to reconstruct a field from Woo: only compare before/after
        // snapshots from actual ERP manual corrections.
        AuditRecord::query()->where('entity_type', Order::class)
            ->where('entity_id', (string) $order->getKey())
            ->where('action', 'order.updated')
            ->where('reason', 'manual_correction')
            ->select(['id', 'before', 'after'])
            ->chunkById(100, function ($events) use (&$fields): void {
                foreach ($events as $event) {
                    foreach ($this->changedKeys($event->before ?? [], $event->after ?? []) as $key) {
                        $fields[$key] = true;
                    }
                }
            }, 'id');

        return $fields;
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after
     *  @return list<string>
     */
    public function changedKeys(array $before, array $after): array
    {
        $keys = [];
        foreach (self::ORDER_FIELDS as $field) {
            if (array_key_exists($field, $before) && array_key_exists($field, $after)
                && $this->canonical($before[$field]) !== $this->canonical($after[$field])) {
                $keys[] = 'order.'.$field;
            }
        }
        foreach (self::ADDRESS_FIELDS as $field) {
            if (array_key_exists($field, $before['address'] ?? [])
                && array_key_exists($field, $after['address'] ?? [])
                && $this->canonical($before['address'][$field]) !== $this->canonical($after['address'][$field])) {
                $keys[] = 'address.'.$field;
            }
        }
        if (isset($before['items'], $after['items'])
            && $this->businessItems($before['items']) !== $this->businessItems($after['items'])) {
            $keys[] = 'items';
        }
        return array_values(array_unique($keys));
    }

    /** @param list<string> $keys */
    public function protect(Order $order, array $keys, ?Authenticatable $actor): void
    {
        foreach (array_unique($keys) as $key) {
            DB::table('order_manual_field_overrides')->updateOrInsert(
                ['order_id' => $order->id, 'field_key' => $key],
                ['actor_id' => $actor?->getAuthIdentifier(), 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    private function canonical(mixed $value): string
    {
        if ($value === null) return '';
        return is_scalar($value) ? trim((string) $value) : json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    private function businessItems(array $items): array
    {
        return collect($items)->map(fn (array $item): array => [
            'product_id' => (int) ($item['product_id'] ?? 0),
            'sku' => trim((string) ($item['sku'] ?? '')),
            'quantity' => (int) ($item['quantity'] ?? 0),
            'unit_price' => (string) ($item['unit_price'] ?? ''),
            'product_summary' => (string) ($item['product_summary'] ?? ''),
        ])->values()->all();
    }
}
