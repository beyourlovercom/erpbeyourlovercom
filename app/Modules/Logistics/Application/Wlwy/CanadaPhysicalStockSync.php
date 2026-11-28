<?php

namespace App\Modules\Logistics\Application\Wlwy;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Inventory\Infrastructure\UsNyStockClock;
use App\Modules\Logistics\Infrastructure\Wlwy\CanadaReadGateway;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Products\Infrastructure\Models\ProductWarehouseSku;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Complete CA02 stock read; isolated identity failures, no inferred mappings. */
final class CanadaPhysicalStockSync
{
    public function __construct(private readonly CanadaReadGateway $gateway) {}

    public function run(bool $apply = false): array
    {
        if ($apply && ! config('wlwy.ca_inventory_apply_enabled')) {
            throw new RuntimeException('CA_APPLY_DISABLED');
        }
        $lock = Cache::lock('wlwy:ca:physical-stock', 600);
        if (! $lock->get()) {
            throw new RuntimeException('CA_SYNC_ALREADY_RUNNING');
        }
        $warehouse = null;
        try {
            $warehouse = Warehouse::query()->where('code', 'CA')->where('is_active', true)->firstOrFail();
            $connection = WarehouseOpenApiConnection::query()->where('warehouse_id', $warehouse->id)->where('provider', 'wlwy')->firstOrFail();
            $customer = trim((string) config('wlwy.ca_customer_code'));
            if ($customer === '') {
                throw new RuntimeException('CA_CUSTOMER_NOT_CONFIGURED');
            }
            $rows = $this->fetch($connection, $customer);

            return UsNyStockClock::utc(fn () => DB::transaction(fn () => $this->reconcile($warehouse, $rows, $apply), 3));
        } catch (\Throwable $e) {
            if ($apply && $warehouse) {
                WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->update(['provider_stock_synced_at' => null]);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    private function number(mixed $v): int
    {
        if ((! is_int($v) && ! is_string($v)) || ! preg_match('/^[0-9]{1,9}$/D', (string) $v)) {
            throw new RuntimeException('CA_INVALID_QUANTITY');
        }

        return (int) $v;
    }

    private function fetch(WarehouseOpenApiConnection $connection, string $customer): array
    {
        $all = [];
        $seen = [];
        $total = null;
        for ($page = 1; $page <= 1000; $page++) {
            $data = $this->gateway->read($connection, 'fu.wms.inventory.get', [
                'customer_code' => $customer, 'warehouse_code' => 'CA02', 'page_no' => $page, 'page_size' => 50,
            ]);
            $count = $this->number($data['total'] ?? null);
            $pageRows = $data['inventorylist'] ?? null;
            if ($this->number($data['page_no'] ?? null) !== $page || $this->number($data['page_size'] ?? null) !== 50
                || ($total !== null && $total !== $count) || ! is_array($pageRows) || ! array_is_list($pageRows)
                || count($pageRows) !== min(50, max(0, $count - ($page - 1) * 50))) {
                throw new RuntimeException('CA_INCOMPLETE_PAGINATION');
            }
            $total = $count;
            foreach ($pageRows as $row) {
                if (! is_array($row) || ($row['warehouse_code'] ?? null) !== 'CA02'
                    || ($row['customer_code'] ?? null) !== $customer || ! is_string($row['sku_code'] ?? null)
                    || trim($row['sku_code']) === '' || empty($row['sku_id']) || empty($row['stock_quality'])) {
                    throw new RuntimeException('CA_INVENTORY_SCOPE_INVALID');
                }
                $identity = json_encode([$row['sku_id'], $row['batch_no'] ?? '', $row['stock_quality']], JSON_THROW_ON_ERROR);
                if (isset($seen[$identity])) {
                    throw new RuntimeException('CA_DUPLICATE_INVENTORY_ROW');
                }
                $seen[$identity] = true;
                $physical = $this->number($row['warehouse_stock'] ?? null);
                $available = $this->number($row['available_stock'] ?? null);
                if ($available > $physical) {
                    throw new RuntimeException('CA_AVAILABLE_EXCEEDS_PHYSICAL');
                }
                $all[] = ['sku' => trim($row['sku_code']), 'sku_id' => (string) $row['sku_id'],
                    'physical' => $row['stock_quality'] === 'G' ? $physical : 0,
                    'available' => $row['stock_quality'] === 'G' ? $available : 0,
                    'non_good' => $row['stock_quality'] === 'G' ? 0 : $physical];
            }
            if (count($all) === $total) {
                if ($all === []) {
                    throw new RuntimeException('CA_EMPTY_SNAPSHOT_REQUIRES_REVIEW');
                }

                return $all;
            }
        }
        throw new RuntimeException('CA_PAGINATION_LIMIT');
    }

    private function reconcile(Warehouse $warehouse, array $rows, bool $apply): array
    {
        Warehouse::query()->whereKey($warehouse->id)->lockForUpdate()->firstOrFail();
        $mappings = ProductWarehouseSku::query()->where('warehouse_id', $warehouse->id)
            ->where('is_active', true)->whereNotNull('verified_at')
            ->whereHas('product', fn ($q) => $q->where('is_active', true))->get();
        $groups = collect($rows)->groupBy(fn ($r) => mb_strtolower($r['sku']));
        $plan = [];
        $quarantine = [];
        foreach ($groups as $key => $group) {
            $mapping = $mappings->filter(fn ($m) => mb_strtolower(trim($m->sku)) === (string) $key);
            $pid = $mapping->count() === 1 ? (int) $mapping->first()->product_id : 0;
            $p = $group->sum('physical');
            $a = $group->sum('available');
            if ($pid <= 0 || $group->pluck('sku_id')->unique()->count() !== 1
                || $group->pluck('sku')->unique()->count() !== 1
                || $mappings->where('product_id', $pid)->count() !== 1) {
                $quarantine[] = ['sku' => $group->first()['sku'], 'physical' => $p, 'available' => $a];

                continue;
            }
            $stock = WarehouseInventory::withTrashed()->where('warehouse_id', $warehouse->id)
                ->where('product_id', $pid)->lockForUpdate()->first();
            if ($stock?->trashed()) {
                $quarantine[] = ['sku' => $group->first()['sku'], 'physical' => $p, 'available' => $a];

                continue;
            }
            $plan[$pid] = [$stock, $p, $a, $group->first()['sku']];
        }
        $verified = [];
        foreach ($plan as $pid => [$stock, $physical, $available, $sku]) {
            $verified[] = ['sku' => $sku, 'physical' => $physical, 'available' => $available];
            if (! $apply) {
                continue;
            }
            $before = (int) ($stock?->quantity_on_hand ?? 0);
            $reserved = (int) ($stock?->quantity_reserved ?? 0);
            $stock ??= new WarehouseInventory(['warehouse_id' => $warehouse->id, 'product_id' => $pid, 'quantity_reserved' => 0]);
            $changed = ! $stock->exists || $before !== $physical || $stock->provider_available_quantity !== $available;
            $stock->forceFill(['quantity_on_hand' => $physical, 'provider_available_quantity' => $available,
                'provider_stock_synced_at' => now()])->save();
            if ($changed) {
                DB::table('inventory_adjustments')->insert([
                    'warehouse_id' => $warehouse->id, 'product_id' => $pid, 'warehouse_inventory_id' => $stock->id,
                    'quantity_before' => $before, 'quantity_after' => $physical, 'reserved_before' => $reserved,
                    'reserved_after' => $reserved, 'actor_user_id' => null, 'reason' => 'CA02 signed physical stock',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        if ($apply) {
            WarehouseInventory::query()->where('warehouse_id', $warehouse->id)
                ->whereNotIn('product_id', array_keys($plan))->update(['provider_stock_synced_at' => null]);
        }

        return ['warehouse' => 'CA02', 'mode' => $apply ? 'apply' : 'dry_run', 'rows' => count($rows),
            'verified' => $verified, 'quarantine' => $quarantine, 'non_good' => array_sum(array_column($rows, 'non_good'))];
    }
}
