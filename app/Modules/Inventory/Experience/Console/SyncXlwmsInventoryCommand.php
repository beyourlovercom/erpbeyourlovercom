<?php

namespace App\Modules\Inventory\Experience\Console;

use App\Modules\Logistics\Application\Xlwms\XlwmsInventorySyncService;
use Illuminate\Console\Command;

final class SyncXlwmsInventoryCommand extends Command
{
    protected $signature = 'xlwms:sync-inventory {--dry-run : Fetch and persist a read-only snapshot (default)} {--apply : Apply only a fully verified, safe matched snapshot}';
    protected $description = 'Read XLWMS inventory for US-SM; apply is explicit and fail-closed';

    public function handle(XlwmsInventorySyncService $service): int
    {
        if ($this->option('dry-run') && $this->option('apply')) { $this->error('Choose --dry-run or --apply, not both.'); return self::INVALID;
        }
        try { $result = $service->sync((bool) $this->option('apply')); $this->line(json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)); if (! $this->option('apply')) {
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
