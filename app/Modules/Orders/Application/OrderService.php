<?php

namespace App\Modules\Orders\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

final class OrderService
{
    private const ADDRESS_FIELDS = [
        'recipient_name', 'phone', 'country', 'province_state', 'city',
        'address_line_1', 'address_line_2', 'postal_code',
    ];

    public function __construct(private readonly AuditRecorder $audit) {}

    public function create(array $attributes, array $items, ?Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($attributes, $items, $actor): Model {
            $orderClass = 'App\\Modules\\Orders\\Infrastructure\\Models\\Order';
            $address = array_intersect_key($attributes, array_flip(self::ADDRESS_FIELDS));
            $orderAttributes = array_diff_key($attributes, $address);
            $order = $orderClass::create([
                ...$orderAttributes,
                'internal_order_number' => $this->nextOrderNumber($attributes['source_type']),
                'order_status' => OrderStatus::PendingShipment,
                'owner_id' => $orderAttributes['owner_id'] ?? $actor?->getAuthIdentifier(),
            ]);
            $order->address()->create($address);

            foreach ($items as $item) {
                $order->items()->create([
                    ...$item,
                    'line_revenue' => $this->lineRevenue($item['unit_price'], (int) $item['quantity']),
                ]);
            }

            $this->record($actor, 'order.created', $order, after: $this->auditData($order), reason: 'manual_order_created');

            return $order->load('items', 'owner', 'address');
        });
    }

    public function update(Model $order, array $attributes, ?array $items, ?Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($order, $attributes, $items, $actor): Model {
            $lockedOrder = $order->newQuery()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $before = $this->auditData($lockedOrder);
            $before['address'] = $lockedOrder->address?->toArray();
            $before['items'] = $lockedOrder->items()->get()->toArray();
            $address = array_intersect_key($attributes, array_flip(self::ADDRESS_FIELDS));
            $orderAttributes = array_diff_key($attributes, $address);
            $lockedOrder->fill($orderAttributes)->save();
            if ($address !== []) {
                $lockedOrder->address()->updateOrCreate(['order_id' => $lockedOrder->getKey()], $address);
            }
            if ($items !== null) {
                $lockedOrder->items()->delete();
                foreach ($items as $item) {
                    $lockedOrder->items()->create([
                        ...$item,
                        'line_revenue' => $this->lineRevenue($item['unit_price'], (int) $item['quantity']),
                    ]);
                }
            }
            $after = $this->auditData($lockedOrder);
            $after['address'] = $lockedOrder->address?->fresh()?->toArray();
            $after['items'] = $lockedOrder->items()->get()->toArray();
            $this->record($actor, 'order.updated', $lockedOrder, before: $before, after: $after, reason: 'manual_correction');

            return $lockedOrder->fresh(['items', 'owner', 'address']);
        });
    }

    public function list(array $filters = []): Collection
    {
        $orderClass = 'App\\Modules\\Orders\\Infrastructure\\Models\\Order';

        return $orderClass::query()
            ->with(['items', 'owner', 'address'])
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('internal_order_number', 'like', "%{$search}%")
                        ->orWhereHas('address', fn ($address) => $address->where('recipient_name', 'like', "%{$search}%"))
                        ->orWhere('external_order_number', 'like', "%{$search}%");
                });
            })
            ->when($filters['source_type'] ?? null, fn ($query, string $source) => $query->where('source_type', $source))
            ->when($filters['order_status'] ?? null, fn ($query, string $status) => $query->where('order_status', $status))
            ->latest('order_date')
            ->latest('id')
            ->get();
    }

    private function nextOrderNumber(string $source): string
    {
        $prefix = match ($source) {
            OrderSourceType::Website->value => 'WEB',
            OrderSourceType::Kol->value => 'KOL',
            default => 'MAN',
        };
        $orderClass = 'App\\Modules\\Orders\\Infrastructure\\Models\\Order';

        do {
            $number = $prefix.'-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while ($orderClass::query()->where('internal_order_number', $number)->exists());

        return $number;
    }

    private function lineRevenue(string|int|float $unitPrice, int $quantity): string
    {
        $parts = explode('.', (string) $unitPrice, 2);
        $whole = (int) ($parts[0] ?? 0);
        $fraction = str_pad(substr($parts[1] ?? '', 0, 2), 2, '0');
        $cents = ($whole * 100) + (int) $fraction;

        return number_format(($cents * $quantity) / 100, 2, '.', '');
    }

    private function record(?Authenticatable $actor, string $action, Model $order, ?array $before = null, ?array $after = null, ?string $reason = null): void
    {
        $this->audit->record(
            $actor ? AuditActor::user($actor, AuditSource::InternalSystem) : AuditActor::system(AuditSource::InternalSystem),
            $action,
            get_class($order),
            $order->getKey(),
            before: $before,
            after: $after,
            reason: $reason,
        );
    }

    private function auditData(Model $order): array
    {
        return [
            'internal_order_number' => $order->internal_order_number,
            'source_type' => $order->source_type instanceof \BackedEnum ? $order->source_type->value : $order->source_type,
            'order_status' => $order->order_status instanceof \BackedEnum ? $order->order_status->value : $order->order_status,
            'owner_id' => $order->owner_id,
        ];
    }
}
