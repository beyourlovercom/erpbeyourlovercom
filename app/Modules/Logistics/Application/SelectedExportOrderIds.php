<?php

namespace App\Modules\Logistics\Application;

use Illuminate\Http\Request;

/** Selection is optional; a supplied but invalid selection must never export everything. */
final class SelectedExportOrderIds
{
    /** @return list<int>|null */
    public static function fromRequest(Request $request): ?array
    {
        if (! $request->exists('order_ids')) {
            return null;
        }

        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:200'],
            'order_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);

        return array_values(array_map('intval', $validated['order_ids']));
    }
}
