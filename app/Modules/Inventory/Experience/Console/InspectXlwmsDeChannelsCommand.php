<?php

namespace App\Modules\Inventory\Experience\Console;

use App\Modules\Logistics\Application\Xlwms\XlwmsWarehouseChannelCatalog;
use Illuminate\Console\Command;
use Throwable;

/** No provider writes, ERP carrier changes, or credential disclosure. */
final class InspectXlwmsDeChannelsCommand extends Command
{
    protected $signature = 'xlwms:inspect-de-channels';

    protected $description = 'Preview signed DE/Hannover XLWMS channels and conservative ERP mapping candidates (read-only)';

    public function handle(XlwmsWarehouseChannelCatalog $catalog): int
    {
        try {
            $result = $catalog->previewDe();
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            if ($result['scope_unverified_count'] > 0) {
                $this->warn('Some channels lack explicit Hannover scope; these remain unmapped.');
            }

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('DE OMS channel preview unavailable. Check warehouse-scoped API configuration and connectivity.');

            return self::FAILURE;
        }
    }
}
