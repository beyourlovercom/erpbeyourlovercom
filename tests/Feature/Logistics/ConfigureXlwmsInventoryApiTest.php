<?php

namespace Tests\Feature\Logistics;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ConfigureXlwmsInventoryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('warehouse_openapi_connections')) {
            $migration = require dirname(__DIR__, 3)
                .'/database/migrations/2026_10_23_000001_create_warehouse_openapi_connections_table.php';
            $migration->up();
        }
    }

    public function test_default_and_check_omit_credentials_and_fail_when_not_configured(): void
    {
        $this->artisan('xlwms:configure-inventory-api')
            ->expectsOutput('XLWMS US-SM credential configuration: MISSING')
            ->assertExitCode(1);

        $this->artisan('xlwms:configure-inventory-api --check')
            ->expectsOutput('XLWMS US-SM credential configuration: MISSING')
            ->assertExitCode(1);
    }

    public function test_existing_encrypted_warehouse_scope_is_detected_without_secret_disclosure(): void
    {
        $w = Warehouse::query()->where('code', 'US-SM')->firstOrFail();
        $model = WarehouseOpenApiConnection::query()->create([
            'warehouse_id' => $w->id, 'provider' => 'xlwms',
            'app_key_encrypted' => 'SAMPLE_TEST_KEY_ONLY',
            'app_secret_encrypted' => 'SAMPLE_TEST_SECRET_ONLY',
            'oms_warehouse_code' => 'SIMULATED',
        ]);

        self::assertSame('SAMPLE_TEST_KEY_ONLY', $model->fresh()->app_key_encrypted);
        self::assertSame('SAMPLE_TEST_SECRET_ONLY', $model->fresh()->app_secret_encrypted);
        self::assertNotSame('SAMPLE_TEST_SECRET_ONLY',
            (string) \DB::table('warehouse_openapi_connections')
                ->where('warehouse_id', $w->id)->value('app_secret_encrypted'));

        $this->artisan('xlwms:configure-inventory-api --check')
            ->expectsOutput('XLWMS US-SM credential configuration: PRESENT')
            ->assertExitCode(0);
    }

    public function test_conflicting_arguments_rejected_before_prompting_for_secrets(): void
    {
        $this->artisan('xlwms:configure-inventory-api --check --configure')
            ->expectsOutput('Use --check OR --configure.')
            ->assertExitCode(2);
    }
}
