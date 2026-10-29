<?php

namespace App\Modules\Sync\Application;

use App\Modules\Sync\Infrastructure\Models\SyncRun;

final class WooOrderSyncHealthReader
{
    /**
     * Read only: the local scheduler feature flag is not proof that a tick ran.
     * Never claim a Woo-to-ERP backlog count without contacting Woo.
     */
    public function snapshot(): array
    {
        $connectionKey = trim((string) config('services.woocommerce.connection_key', 'beyourlover.com')) ?: 'beyourlover.com';
        $query = SyncRun::query()
            ->where('provider_key', 'woocommerce')
            ->where('connection_key', $connectionKey)
            ->where('run_type', 'woocommerce_order_incremental');
        $latest = (clone $query)->latest('id')->first();
        $successful = (clone $query)->where('status', 'completed')
            ->where('failed_items', 0)->whereNotNull('completed_at')->latest('id')->first();
        $failure = (clone $query)->where(function ($q): void {
            $q->whereIn('status', ['partial', 'failed'])->orWhere('failed_items', '>', 0);
        })->latest('id')->first();
        $failedLatest = $latest && ($latest->failed_items > 0 || in_array($latest->status->value, ['partial', 'failed'], true));
        // Production MySQL TIMESTAMP is read through a PDT session while Laravel runs UTC.
        // started_at is corroborated by the scheduler log and is authoritative for freshness.
        $stale = ! $successful?->started_at || $successful->started_at->lt(now()->subMinutes(75));
        $enabled = (bool) config('services.woocommerce.order_sync_enabled', false);
        $health = ! $enabled ? 'disabled' : ($failedLatest ? 'failed' : ($stale ? 'stale' : 'ok'));
        $failedItem = $failure?->items()->whereNotNull('error_code')->latest('id')->first();

        return [
            'enabled' => $enabled,
            'frequency' => '每 30 分钟',
            'health' => $health,
            'latest_run' => $latest,
            'last_success_at' => $successful?->started_at?->toIso8601String(),
            'last_failure' => $failure ? [
                'run_id' => $failure->id,
                'at' => $failure->started_at?->toIso8601String(),
                'error_code' => $failedItem?->error_code,
                'message' => $failedItem?->error_message ? mb_substr($failedItem->error_message, 0, 180) : null,
            ] : null,
            'unimported_count' => null, // Requires explicit source-side reconciliation.
        ];
    }
}
