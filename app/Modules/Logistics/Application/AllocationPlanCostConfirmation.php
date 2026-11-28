<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Finance\Application\FxReferenceSettingsService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

/** Advisory cost confirmation of the exact physical fulfillment plan, never an actual invoice. */
final class AllocationPlanCostConfirmation
{
    public function __construct(private readonly AllocationRatePreviewService $preview, private readonly FxReferenceSettingsService $fx) {}

    public function quote(Order $order, array $plan, Authenticatable $actor): array
    {
        $order->loadMissing('address');
        $segments = $plan['segments'] ?? [];
        $groups = [];
        $all = [];
        $known = 0.0;
        $knownCount = 0;
        $partialCount = 0;
        $unknownCount = 0;
        $unresolved = false;
        $missing = [];
        foreach ($segments as $index => $segment) {
            $items = [];
            $identities = [];
            $invalid = false;
            foreach ($segment['items'] ?? [] as $item) {
                $id = (int) ($item['resolved_product_id'] ?? 0);
                $qty = (int) ($item['quantity'] ?? 0);
                if ($id <= 0 || $qty <= 0) {
                    $invalid = true;

                    continue;
                }
                $items[$id] = ($items[$id] ?? 0) + $qty;
                $all[$id] = ($all[$id] ?? 0) + $qty;
                $identities[] = ['order_item_id' => (int) ($item['order_item_id'] ?? 0), 'product_id' => $id, 'sku' => $item['resolved_fulfillment_sku'] ?? null, 'quantity' => $qty];
            }
            ksort($items, SORT_NUMERIC);
            usort($identities, fn ($a, $b) => $a['order_item_id'] <=> $b['order_item_id']);
            $source = (string) ($segment['source_code'] ?? '');
            $warehouse = $source === 'OUTSOURCE' ? 'FACTORY' : $source;
            $row = ['segment' => $index, 'warehouse_code' => $source, 'warehouse_name' => $segment['warehouse_name'] ?? $source,
                'carrier' => $segment['carrier'] ?? null, 'service' => $segment['service'] ?? null, 'items' => $identities,
                'status' => 'unavailable', 'reason' => 'unresolved_product', 'amount_cny' => null, 'native_totals' => null, 'measurement' => null, 'rate' => null];
            if ($invalid || ! $items) {
                $unresolved = true;
                $groups[] = $row;
                $unknownCount++;

                continue;
            }
            $result = $this->preview->previewProducts($order, $items, null, $warehouse, true);
            $missing = array_merge($missing, $result['missing_product_ids'] ?? []);
            $row['measurement'] = $result['measurement'] ?? null;
            $row['measurement_evidence'] = $result['measurement_evidence'] ?? [];
            $rate = $this->selectedRate($warehouse, $segment, $result);
            $row['rate'] = $rate;
            $row['reason'] = $result['reason'] ?? $rate['reason'] ?? 'selected_service_rate_missing';
            if ($rate && is_array($rate['native_totals'] ?? null)) {
                $native = $rate['native_totals'];
                // Internal extra item handling is known even when supplier base handling is absent.
                if (($rate['extra_item_usd'] ?? 0) > 0) {
                    $native['USD'] = ($native['USD'] ?? 0) + $rate['extra_item_usd'];
                }
                $fx = $this->fx->convertToCny(array_map(fn ($c, $v) => ['currency' => $c, 'revenue' => $v], array_keys($native), array_values($native)));
                $row['native_totals'] = $native;
                $row['fx'] = $fx;
                $row['amount_cny'] = ($fx['available'] ?? false) ? round((float) $fx['amount_cny'], 2) : null;
                $row['status'] = $row['amount_cny'] === null ? 'native_only' : (($rate['status'] ?? null) === 'approved_reference' ? 'estimated' : 'partial_reference');
                $row['reason'] = $row['amount_cny'] === null ? 'fx_unavailable' : ($row['status'] === 'estimated' ? null : ($rate['reason'] ?? 'incomplete_rate'));
                if ($row['amount_cny'] !== null) {
                    $known += $row['amount_cny'];
                    $knownCount++;
                    if ($row['status'] !== 'estimated') {
                        $partialCount++;
                    }
                } else {
                    $unknownCount++;
                }
            } else {
                $unknownCount++;
            }
            $groups[] = $row;
        }
        ksort($all, SORT_NUMERIC);
        $baseline = (! $unresolved && $all) ? $this->preview->previewProducts($order, $all, null, 'FACTORY', true) : null;
        $factory = $baseline['factory_default_reference'] ?? null;
        $snapshot = ['schema' => 'allocation_cost_confirmation_v1', 'estimate_only' => true, 'order_id' => (int) $order->id,
            'workflow_revision' => (int) $order->workflow_revision, 'as_of' => now('Asia/Shanghai')->toDateString(),
            'groups' => $groups, 'known_subtotal_cny' => $knownCount ? round($known, 2) : null,
            'known_group_count' => $knownCount, 'unknown_group_count' => $unknownCount, 'partial_group_count' => $partialCount,
            'total_cny' => $knownCount && $unknownCount === 0 && $partialCount === 0 ? round($known, 2) : null,
            'requires_confirmation' => $knownCount > 0 || collect($groups)->contains(fn ($g) => $g['native_totals'] !== null),
            'factory_default_reference' => $factory, 'missing_product_ids' => array_values(array_unique($missing))];
        // The token binds the current snapshot to the actor and destination, not just the displayed total.
        $binding = ['actor_id' => (string) $actor->getAuthIdentifier(), 'destination' => [
            $order->address?->country, $order->address?->postal_code, $order->address?->province_state, $order->address?->city,
        ], 'snapshot' => $snapshot];
        $snapshot['confirmation_token'] = hash_hmac('sha256', json_encode($this->canonical($binding), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), (string) config('app.key'));

        return $snapshot;
    }

    private function selectedRate(string $warehouse, array $segment, array $result): ?array
    {
        if ($warehouse === 'FACTORY') {
            return $result['factory_default_reference'] ?? null;
        }
        $carrier = strtoupper(trim((string) ($segment['carrier'] ?? '')));
        $service = strtolower(trim((string) ($segment['service'] ?? '')));
        $key = match ($carrier) {
            'DHL' => match ($service) {
                'dhl paket domestic','paket_domestic','domestic' => 'domestic','dhl paket international','paket_international','international' => 'international',default => null
            },
            'USPS' => match ($service) {
                'usps ground advantage','ground_advantage','ground advantage' => 'ground_advantage',default => null
            },
            'GOFO' => match ($service) {
                'gofo express','express' => 'express',default => null
            },
            default => null,
        };
        if ($key === null) {
            return null;
        }

        return collect($result['rows'] ?? [])->first(fn ($row) => $row['warehouse_code'] === $warehouse && $row['carrier'] === $carrier && $row['service'] === $key);
    }

    public function confirm(Order $order, array $plan, Authenticatable $actor, ?string $token): array
    {
        $snapshot = $this->quote($order, $plan, $actor);
        if ($snapshot['requires_confirmation'] && (! $token || ! hash_equals($snapshot['confirmation_token'], $token))) {
            throw ValidationException::withMessages(['cost_confirmation' => '当前分仓费用尚未确认或已变化，请重新计算并确认。']);
        }
        if ($token && ! hash_equals($snapshot['confirmation_token'], $token)) {
            throw ValidationException::withMessages(['cost_confirmation' => '分仓方案或费用已变化，请重新计算。']);
        }
        unset($snapshot['confirmation_token']);

        return [...$snapshot, 'confirmation_status' => $snapshot['requires_confirmation'] ? 'confirmed' : 'skipped_unavailable',
            'confirmed_by' => $actor->getAuthIdentifier(), 'confirmed_at' => now('Asia/Shanghai')->toIso8601String()];
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value,SORT_STRING);
        }

        return array_map($this->canonical(...),$value);
    }
}
