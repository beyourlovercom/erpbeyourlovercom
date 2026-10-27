<?php

namespace App\Modules\Inventory\Experience\Console;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Operator-local, interactive-only credential provisioning.
 *
 * Never accepts credentials as CLI flags, environment variables, logs,
 * GitHub parameters or other data that could enter command history.
 * --check prints only a presence indicator. No OMS write operations.
 */
final class ConfigureXlwmsInventoryApiCommand extends Command
{
    protected $signature = 'xlwms:configure-inventory-api
        {--check : Check connection presence without exposing secret material (default)}
        {--configure : Securely bind warehouse credentials through hidden local terminal prompts}';

    protected $description = 'Check or configure encrypted US-SM OMS inventory API credentials (local TTY only)';

    public function handle(AuditRecorder $audit): int
    {
        if ($this->option('check') && $this->option('configure')) {
            $this->error('Use --check OR --configure.');
            return self::INVALID;
        }

        $warehouse = Warehouse::query()->where('code', 'US-SM')->first();
        if (! $warehouse) {
            $this->error('US-SM warehouse is not configured.');
            return self::FAILURE;
        }

        $existing = WarehouseOpenApiConnection::query()
            ->where('warehouse_id', $warehouse->id)->where('provider', 'xlwms')->first();

        if (! $this->option('configure')) {
            $configured = $existing !== null
                && filled($existing->oms_warehouse_code)
                && filled($existing->app_key_encrypted)
                && filled($existing->app_secret_encrypted);
            $this->line($configured ? 'XLWMS US-SM credential configuration: PRESENT'
                : 'XLWMS US-SM credential configuration: MISSING');
            return $configured ? self::SUCCESS : self::FAILURE;
        }

        if (! defined('STDIN') || ! function_exists('stream_isatty') || ! stream_isatty(STDIN)) {
            $this->error('Credential setup requires an interactive, trusted local terminal. No stdin pipes or remote automation.');
            return self::FAILURE;
        }

        $this->warn('US-SM OMS API setup; App Key and Secret will be hidden and encrypted in ERP MySQL.');
        if (! $this->confirm('Are you the authorized operator for the US-SM warehouse?', false)) {
            return self::INVALID;
        }

        $omsWarehouse = trim((string) $this->ask('OMS warehouse code (confirm with provider)'));
        $appKey = trim((string) $this->secret('OMS App Key (hidden)'));
        $appSecret = trim((string) $this->secret('OMS App Secret (hidden)'));

        if (strlen($omsWarehouse) < 1 || strlen($omsWarehouse) > 128
            || strlen($appKey) < 6 || strlen($appSecret) < 6) {
            $this->error('One or more required configuration values were missing or invalid.');
            return self::INVALID;
        }

        try {
            DB::transaction(function () use ($warehouse, $omsWarehouse, $appKey, $appSecret, $audit): void {
                $connection = WarehouseOpenApiConnection::query()->updateOrCreate(
                    ['warehouse_id' => $warehouse->id, 'provider' => 'xlwms'],
                    ['app_key_encrypted' => $appKey, 'app_secret_encrypted' => $appSecret,
                        'oms_warehouse_code' => $omsWarehouse, 'last_verified_at' => null],
                );
                $audit->record(
                    AuditActor::system(AuditSource::InternalSystem),
                    'inventory.xlwms_credentials_configured',
                    WarehouseOpenApiConnection::class,
                    $connection->id,
                    after: ['warehouse_id' => $warehouse->id, 'provider' => 'xlwms',
                        'operation' => 'operator_terminal_configure', 'credentials_present' => true],
                    reason: 'owner_authorized_interactive_local_credential_provisioning',
                );
            });
        } catch (Throwable) {
            $this->error('Credential configuration failed. Check application logs for general DB health only.');
            return self::FAILURE;
        } finally {
            unset($appSecret, $appKey);
        }

        $this->info('XLWMS US-SM: encrypted credential configuration saved (API not yet verified).');
        $this->line('Next: php artisan xlwms:sync-inventory --dry-run');
        return self::SUCCESS;
    }
}
