<?php

namespace App\Modules\Inventory\Application\PackagingScan;

use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderExternalReference;
use Illuminate\Support\Collection;

final class PackagingScanEvidencePreviewService
{
    /** @param list<array<string,mixed>> $rows */
    public function preview(array $rows): array
    {
        $refs = collect($rows)->pluck('order_reference')->filter()->map(fn ($v) => trim((string) $v))->unique()->values();
        $orders = $this->ordersForReferences($refs);
        $referenceMap = $this->referenceMap($refs, $orders);
        $resultRows = [];
        foreach ($rows as $row) $resultRows[] = $this->classify($row, $referenceMap, $orders);
        $collection = collect($resultRows);
        return [
            'summary' => [
                'rows' => $collection->count(),
                'matched_orders' => $collection->where('order_match_status', 'matched')->count(),
                'ready_single_unit_evidence' => $collection->where('evidence_status', 'ready_product_evidence')->count(),
                'placeholder_dimensions' => $collection->where('evidence_status', 'placeholder_dimensions')->count(),
                'multi_unit' => $collection->where('classification', 'single_sku_multi_unit')->count(),
                'multi_sku' => $collection->where('classification', 'multi_sku')->count(),
                'unmatched_orders' => $collection->where('order_match_status', 'not_found')->count(),
                'ambiguous_orders' => $collection->where('order_match_status', 'ambiguous')->count(),
            ],
            'rows' => $resultRows,
        ];
    }

    private function ordersForReferences(Collection $refs): Collection
    {
        if ($refs->isEmpty()) return collect();
        $values = $refs->all();
        $direct = Order::query()->with(['items.product:id,sku,name'])
            ->where(function ($q) use ($values): void {
                $q->whereIn('external_order_number', $values)
                    ->orWhereIn('internal_order_number', $values)
                    ->orWhereIn('erp_order_id', $values);
            })->get();
        $externalIds = OrderExternalReference::query()->whereIn('reference', $values)->pluck('order_id');
        $external = $externalIds->isEmpty() ? collect() : Order::query()->with(['items.product:id,sku,name'])->whereIn('id', $externalIds)->get();
        return $direct->concat($external)->unique('id')->keyBy('id');
    }

    private function referenceMap(Collection $refs, Collection $orders): array
    {
        $map = [];
        foreach ($refs as $ref) $map[(string) $ref] = [];
        foreach ($orders as $order) {
            foreach ([$order->external_order_number, $order->internal_order_number, $order->erp_order_id] as $value) {
                $value = trim((string) $value);
                if ($value !== '' && array_key_exists($value, $map)) $map[$value][$order->id] = true;
            }
        }
        if ($refs->isNotEmpty()) {
            foreach (OrderExternalReference::query()->whereIn('reference', $refs->all())->get(['order_id','reference']) as $external) {
                $ref = trim((string) $external->reference);
                if (array_key_exists($ref, $map) && $orders->has($external->order_id)) $map[$ref][$external->order_id] = true;
            }
        }
        return $map;
    }

    private function classify(array $row, array $referenceMap, Collection $orders): array
    {
        $ref = trim((string) ($row['order_reference'] ?? ''));
        $ids = array_keys($referenceMap[$ref] ?? []);
        $base = [
            'source_row_number' => (int) ($row['source_row_number'] ?? 0),
            'order_reference' => $ref !== '' ? $ref : null,
            'tracking_number' => $this->text($row['tracking_number'] ?? null),
            'servicer_reference' => $this->text($row['servicer_reference'] ?? null),
            'forecast_weight_kg' => $this->number($row['forecast_weight_kg'] ?? null),
            'inbound_weight_kg' => $this->number($row['inbound_weight_kg'] ?? null),
            'volume_weight_kg' => $this->number($row['volume_weight_kg'] ?? null),
            'billing_weight_kg' => $this->number($row['billing_weight_kg'] ?? null),
            'weight_difference_kg' => $this->number($row['weight_difference_kg'] ?? null),
            'length_cm' => $this->number($row['length_cm'] ?? null),
            'width_cm' => $this->number($row['width_cm'] ?? null),
            'height_cm' => $this->number($row['height_cm'] ?? null),
            'measured_at' => $this->text($row['measured_at'] ?? null),
        ];
        if ($ids === []) return [...$base, 'order_match_status'=>'not_found','classification'=>'unresolved','evidence_status'=>'order_not_found','can_be_product_evidence'=>false,'order'=>null,'product'=>null];
        if (count($ids) > 1) return [...$base, 'order_match_status'=>'ambiguous','classification'=>'unresolved','evidence_status'=>'ambiguous_order','can_be_product_evidence'=>false,'order'=>null,'product'=>null];
        $order = $orders->get($ids[0]);
        if (! $order) return [...$base, 'order_match_status'=>'not_found','classification'=>'unresolved','evidence_status'=>'order_not_found','can_be_product_evidence'=>false,'order'=>null,'product'=>null];
        $items = $order->items->filter(fn ($item) => (int) $item->quantity > 0);
        if ($items->isEmpty()) return $this->resolvedBase($base, $order, 'no_items', 'order_has_no_items');
        if ($items->contains(fn ($item) => $item->product_id === null || $item->product === null)) return $this->resolvedBase($base, $order, 'unresolved_product', 'unresolved_product');
        $byProduct = $items->groupBy('product_id')->map(fn ($group) => ['item'=>$group->first(), 'quantity'=>$group->sum(fn ($item) => (int) $item->quantity)]);
        if ($byProduct->count() > 1) return $this->resolvedBase($base, $order, 'multi_sku', 'multi_sku_parcel');
        $entry = $byProduct->first(); $item = $entry['item']; $qty = (int) $entry['quantity'];
        $classification = $qty === 1 ? 'single_sku_single_unit' : 'single_sku_multi_unit';
        $product = ['id'=>$item->product->id,'sku'=>$item->product->sku,'name'=>$item->product->name,'quantity'=>$qty];
        if ($qty !== 1) return [...$base,'order_match_status'=>'matched','classification'=>$classification,'evidence_status'=>'multi_unit_parcel','can_be_product_evidence'=>false,'order'=>$this->order($order),'product'=>$product];
        $weight = $base['inbound_weight_kg']; $dims = [$base['length_cm'],$base['width_cm'],$base['height_cm']];
        if ($weight === null || $weight <= 0) $status = 'missing_actual_weight';
        elseif (collect($dims)->contains(fn ($v) => $v === null || $v <= 0)) $status = 'missing_dimensions';
        elseif ($this->placeholderDimensions($dims)) $status = 'placeholder_dimensions';
        else $status = 'ready_product_evidence';
        return [...$base,'order_match_status'=>'matched','classification'=>$classification,'evidence_status'=>$status,'can_be_product_evidence'=>$status === 'ready_product_evidence','order'=>$this->order($order),'product'=>$product];
    }

    private function resolvedBase(array $base, Order $order, string $classification, string $status): array
    { return [...$base,'order_match_status'=>'matched','classification'=>$classification,'evidence_status'=>$status,'can_be_product_evidence'=>false,'order'=>$this->order($order),'product'=>null]; }
    private function order(Order $order): array
    { return ['id'=>$order->id,'external_order_number'=>$order->external_order_number,'internal_order_number'=>$order->internal_order_number,'erp_order_id'=>$order->erp_order_id]; }
    private function placeholderDimensions(array $dims): bool
    { return count($dims) === 3 && abs((float)$dims[0]-1.0)<0.0001 && abs((float)$dims[1]-1.0)<0.0001 && abs((float)$dims[2]-1.0)<0.0001; }
    private function number(mixed $value): ?float
    {
        if ($value === null || trim((string)$value) === '') return null;
        $normalized = str_replace([',','kg','KG','cm','CM'], '', trim((string)$value));
        return is_numeric($normalized) ? (float) $normalized : null;
    }
    private function text(mixed $value): ?string { $value=trim((string)($value ?? '')); return $value===''?null:$value; }
}
