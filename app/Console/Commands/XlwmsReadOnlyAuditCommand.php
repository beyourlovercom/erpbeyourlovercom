<?php

namespace App\Console\Commands;

use App\Modules\Logistics\Application\XlwmsMappingReadinessService;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsReadOnlyClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator-only, read-only connector health check.
 * Never prints credentials, raw signed URLs or full vendor response bodies.
 */
final class XlwmsReadOnlyAuditCommand extends Command
{
    protected $signature = 'xlwms:audit
        {--warehouse= : ERP warehouse code for local mapping check}
        {--sku=* : ERP SKU(s) for local mapping check}
        {--live : Call only the vendor read-only warehouse, channel and product endpoints}';

    protected $description = 'Audit XLWMS OpenAPI v1 read-only configuration and mapping';

    public function handle(XlwmsReadOnlyClient $client, XlwmsMappingReadinessService $mapper): int
    {
        $this->line('XLWMS enabled + credentials present: '.($client->isConfigured() ? 'YES' : 'NO'));
        $this->line('Automatic outbound dispatch: DISABLED (P0-P2 hard gate)');

        if (is_string($this->option('warehouse')) && $this->option('warehouse') !== '') {
            $result = $mapper->assess($this->option('warehouse'), $this->option('sku'));
            $this->line('Mapping status: '.$result['status']);
            $this->line('Warehouse mapped: '.($result['warehouse_code'] === null ? 'NO' : 'YES'));
            $this->line('SKU mappings found: '.count($result['sku_map']));
            foreach ($result['blockers'] as $blocker) {
                $this->warn('Mapping blocked: '.$blocker);
            }
            $this->line('Remote catalog verified: NO (requires live match)');
        }

        if (! $this->option('live')) {
            $this->info('No external API request made. Pass --live to verify read-only access.');

            return self::SUCCESS;
        }

        if (! $client->isConfigured()) {
            $this->error('Cannot call XLWMS: connector disabled or credentials absent.');

            return self::FAILURE;
        }

        try {
            foreach ([
                'warehouse/options' => $client->warehouses(...),
                'logistics/channel/list' => $client->channels(...),
                'product/pagelist' => $client->products(...),
            ] as $endpoint => $call) {
                $result = $call();
                // HTTP 200 is transport evidence only, not vendor business success.
                $this->line($endpoint.': HTTP '.$result['http_status'].'; JSON received; business status UNVERIFIED');
            }
        } catch (Throwable $exception) {
            // Do not print exception text: underlying transport exceptions may
            // contain signed URLs or credentials.
            $this->error('XLWMS read-only probe failed. Review redacted diagnostics.');

            return self::FAILURE;
        }

        $this->warn('No live warehouse/SKU binding is marked verified by this command.');

        return self::SUCCESS;
    }
}
