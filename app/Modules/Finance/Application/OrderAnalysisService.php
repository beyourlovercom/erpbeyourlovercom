<?php

namespace App\Modules\Finance\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class OrderAnalysisService
{
    private const COST_FIELDS = [
        'product_cost', 'warehouse_cost', 'first_mile_cost', 'overseas_handling_cost', 'last_mile_cost',
        'kol_commission', 'payment_fee', 'customs_tax', 'refund_replacement_loss', 'other_variable_cost',
    ];

    private const FINANCIAL_FIELDS = [
        'merchandise_list_amount', 'discount_amount', 'shipping_revenue', 'amount_paid', 'refund_amount',
    ];

    public function __construct(private readonly AuditRecorder $audit) {}

    public function save(Model $order, array $attributes, ?Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($order, $attributes, $actor): Model {
            $financials = $order->financials()->lockForUpdate()->firstOrCreate(
                ['order_id' => $order->getKey()],
                ['currency' => $attributes['currency'] ?? 'USD'],
            );
            $before = [
                'financials' => $financials->toArray(),
                'costs' => $order->costEntries()->get()->toArray(),
            ];
            $financials->fill(array_intersect_key($attributes, array_flip([...self::FINANCIAL_FIELDS, 'currency'])))->save();

            foreach (self::COST_FIELDS as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }
                $entry = $order->costEntries()->where('cost_type', $field)->first();
                if ($attributes[$field] === null) {
                    $entry?->delete();
                    continue;
                }
                $order->costEntries()->updateOrCreate(
                    ['cost_type' => $field],
                    ['amount' => $attributes[$field], 'currency' => $attributes['currency'] ?? $financials->currency],
                );
            }

            $after = [
                'financials' => $financials->fresh()->toArray(),
                'costs' => $order->costEntries()->get()->toArray(),
            ];
            $this->audit->record(
                $actor ? AuditActor::user($actor, AuditSource::InternalSystem) : AuditActor::system(AuditSource::InternalSystem),
                'order_analysis.updated',
                get_class($order),
                $order->getKey(),
                before: $before,
                after: $after,
                reason: 'order_cost_correction',
            );

            return $order->fresh(['financials', 'costEntries']);
        });
    }

    public function summary(Model $order): array
    {
        $financials = $order->relationLoaded('financials') ? $order->financials : $order->financials()->first();
        $entries = $order->relationLoaded('costEntries') ? $order->costEntries : $order->costEntries()->get();
        $byType = $entries->keyBy('cost_type');
        $complete = $financials?->amount_paid !== null
            && $financials?->refund_amount !== null
            && collect(self::COST_FIELDS)->every(fn (string $field): bool => $byType->has($field));

        if (! $complete) {
            return [
                'completeness' => 'incomplete', 'profit_status' => 'not_confirmed',
                'net_revenue' => null, 'total_cost' => null, 'contribution_profit' => null, 'margin' => null,
            ];
        }

        $netRevenueCents = $this->cents($financials->amount_paid) - $this->cents($financials->refund_amount);
        $totalCostCents = collect(self::COST_FIELDS)->sum(fn (string $field): int => $this->cents($byType->get($field)->amount));
        $profitCents = $netRevenueCents - $totalCostCents;

        return [
            'completeness' => 'complete', 'profit_status' => 'confirmed',
            'net_revenue' => $this->money($netRevenueCents), 'total_cost' => $this->money($totalCostCents),
            'contribution_profit' => $this->money($profitCents),
            'margin' => $netRevenueCents === 0 ? null : bcdiv((string) $profitCents, (string) $netRevenueCents, 4),
        ];
    }

    public function fields(): array
    {
        return [...self::FINANCIAL_FIELDS, ...self::COST_FIELDS];
    }

    public function values(Model $order): array
    {
        $financials = $order->relationLoaded('financials') ? $order->financials : $order->financials()->first();
        $entries = $order->relationLoaded('costEntries') ? $order->costEntries : $order->costEntries()->get();
        $values = [];
        foreach (self::FINANCIAL_FIELDS as $field) {
            $values[$field] = $financials?->{$field};
        }
        $byType = $entries->keyBy('cost_type');
        foreach (self::COST_FIELDS as $field) {
            $values[$field] = $byType->get($field)?->amount;
        }

        return $values;
    }

    private function cents(mixed $amount): int
    {
        $value = trim((string) $amount);
        if (! preg_match('/^\+?(?<whole>\d+)(?:\.(?<fraction>\d{1,2}))?$/D', $value, $matches)) {
            throw new \UnexpectedValueException('Financial amounts must be decimal values with at most two fractional digits.');
        }

        return ((int) $matches['whole'] * 100) + (int) str_pad($matches['fraction'] ?? '', 2, '0');
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
