<?php

namespace App\Modules\Inventory\Experience\Controllers;

use App\Modules\Inventory\Application\InventoryService;
use App\Modules\Inventory\Application\MorePlusFifoReconciliationService;
use App\Modules\Inventory\Application\MorePlusInventorySyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class MorePlusInventorySyncController
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly MorePlusInventorySyncService $sync,
        private readonly MorePlusFifoReconciliationService $fifo,
    ) {}

    /**
     * One click = real MorePlus GET, then ERP-only mirror, with immutable audit.
     * This endpoint never sends provider inventory mutations or OMS orders.
     */
    public function sync(Request $request, string $warehouse): RedirectResponse
    {
        $w = $this->inventory->warehouse($warehouse);
        abort_unless(strtoupper((string) $w->code) === 'US-LA', 404);
        try {
            $result = $this->sync->syncProvider($w, $request->user());
        } catch (\Throwable $error) {
            return back()->withErrors(['moreplus_stock' => $error instanceof RuntimeException
                ? $error->getMessage() : 'MOREPLUS_INVENTORY_SYNC_UNAVAILABLE']);
        }

        return back()->with('success', sprintf(
            'MorePlus 库存同步完成：更新 %d 个（含未返回清单已归零 %d 个）、新增 %d 个、一致 %d 个，SKU 异常 %d 个。只改 ERP，未改海外仓。',
            $result['updated'], $result['absent_zeroed'], $result['created'], $result['unchanged'], $result['blocked']
        ));
    }

    public function preview(Request $request, string $warehouse): RedirectResponse
    {
        $w = $this->inventory->warehouse($warehouse);
        abort_unless(strtoupper((string) $w->code) === 'US-LA', 404);
        try {
            $preview = $this->sync->previewWithLastRead($w);
            $fifo = $this->fifo->previewFromStock($w, $preview);
        } catch (\Throwable $error) {
            return back()->withErrors(['moreplus_stock' => $error instanceof RuntimeException
                ? $error->getMessage() : 'MOREPLUS_INVENTORY_PREVIEW_UNAVAILABLE']);
        }
        session([$this->key((int) $w->id) => $preview,
            $this->fifoKey((int) $w->id) => $fifo]);
        // The next warehouse GET consumes this visibility marker once.
        // Preserve the checksum separately for an independently authorized apply.
        session()->put('inventory.moreplus_preview_visible.'.$w->id, true);

        return back()->with('success', 'MorePlus 库存差异预览已刷新；尚未更改 ERP 库存');
    }

    public function apply(Request $request, string $warehouse): RedirectResponse
    {
        $w = $this->inventory->warehouse($warehouse);
        abort_unless(strtoupper((string) $w->code) === 'US-LA', 404);
        $data = $request->validate(['expected_checksum' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']]);
        $preview = session($this->key((int) $w->id));
        if (! is_array($preview) || ! hash_equals((string) ($preview['checksum'] ?? ''), $data['expected_checksum'])) {
            return back()->withErrors(['moreplus_stock' => '预览已过期或不一致，请重新查询仓库库存。']);
        }
        try {
            $result = $this->sync->apply($w, $data['expected_checksum'], $request->user());
        } catch (\Throwable $error) {
            return back()->withErrors(['moreplus_stock' => $error instanceof RuntimeException
                ? $error->getMessage() : 'MOREPLUS_INVENTORY_APPLY_UNAVAILABLE']);
        }
        session()->forget([$this->key((int) $w->id), $this->fifoKey((int) $w->id), 'inventory.moreplus_preview_visible.'.$w->id]);

        return back()->with('success', sprintf(
            'MorePlus 同步完成：更新 %d 个、新增 %d 个、未变 %d 个，%d 个待复核（未覆盖）。',
            $result['updated'], $result['created'], $result['unchanged'], $result['blocked']
        ));
    }

    private function fifoKey(int $warehouseId): string
    {
        return 'inventory.moreplus_fifo_preview.'.$warehouseId;
    }

    private function key(int $warehouseId): string
    {
        return 'inventory.moreplus_provider_preview.'.$warehouseId;
    }
}
