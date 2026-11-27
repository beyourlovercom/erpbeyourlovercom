<?php

namespace App\Modules\Inventory\Experience\Console;

use App\Modules\Logistics\Application\Xlwms\XlwmsInventorySyncService;
use Illuminate\Console\Command;

final class SyncXlwmsInventoryCommand extends Command
{
    protected $signature = 'xlwms:sync-inventory {--dry-run : Fetch and persist a read-only snapshot (default)} {--apply : Apply only a fully verified, safe matched snapshot} {--warehouse=US-SM : ERP warehouse code (US-SM, DE or US-NY)}';
    protected $description = 'Read XLWMS inventory per warehouse; DE apply requires isolated Dev and separate gates';

    public function handle(XlwmsInventorySyncService $service): int
    {
        if ($this->option('dry-run') && $this->option('apply')) { $this->error('Choose --dry-run or --apply, not both.'); return self::INVALID;
        }
        $warehouseCode = strtoupper(trim((string) $this->option('warehouse')));
        if (! in_array($warehouseCode, ['US-SM', 'DE', 'US-NY'], true)) {
            $this->error('Unsupported XLWMS warehouse. Allowed: US-SM, DE, US-NY.');
            return self::INVALID;
        }
        if ($warehouseCode === 'DE' && $this->option('apply') && app()->environment('production')) {
            // Real stock changes must be initiated from an authorized local TTY,
            // never from a web button, cron, queue, remote pipe or automation.
            if (! defined('STDIN') || ! function_exists('stream_isatty') || ! stream_isatty(STDIN)) {
                $this->error('Production DE apply requires a trusted interactive local terminal.');
                return self::FAILURE;
            }
            if (! $this->confirm('Apply verified DE/Hannover inventory to Production ERP using the approved exact snapshot checksum?', false)) {
                return self::INVALID;
            }
        }
        try { $result = $service->sync((bool) $this->option('apply'), $warehouseCode); $this->line(json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)); if (! $this->option('apply')) {
            // A successfully recorded read-only mismatch snapshot is expected
            // evidence, not a failed API read or failed scheduled collection.
            if (in_array($result['status'], ['blocked', 'not_ready'], true)) {
                $this->warn('XLWMS snapshot captured; ERP stock unchanged because reconciliation is not ready.');
            }
            return self::SUCCESS;
        }
        return $result['status'] === 'applied' ? self::SUCCESS : self::FAILURE; }
        catch (\Throwable $e) { $this->error('XLWMS inventory sync failed: '.$e->getMessage()); return self::FAILURE; }
    }
}
