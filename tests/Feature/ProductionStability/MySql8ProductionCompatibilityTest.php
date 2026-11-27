<?php

namespace Tests\Feature\ProductionStability;

use App\Modules\Backup\Application\LocalDatabaseBackupService;
use App\Modules\Finance\Application\ConfirmedLossProjection;
use App\Modules\Orders\Application\OrderIdentityService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MySql8ProductionCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('BYL_MYSQL_STABILITY_ENABLED') !== '1') {
            $this->markTestSkipped('MySQL 8 Production Stability gate is disabled.');
        }

        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        $expectedDatabase = (string) getenv('BYL_MYSQL_STABILITY_DATABASE');

        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertNotSame('', $expectedDatabase);
        $this->assertSame($expectedDatabase, $database);
        $this->assertMatchesRegularExpression('/^(?:byl_p4_|erp_stability_)[a-z0-9_]+$/', $database);

        $version = (string) $connection->scalar('select version()');
        $this->assertStringStartsWith('8.', $version, 'Production compatibility gate requires MySQL 8.x.');

        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_v2_kol_daily_counter_migration_and_allocation_on_mysql_8(): void
    {
        $this->assertTrue(Schema::hasTable('kol_daily_order_sequences'));
        $this->travelTo(Carbon::parse('2026-09-28 23:59:59', 'Asia/Shanghai'));
        $service = app(OrderIdentityService::class);
        $this->assertSame('K26092801-007', $service->allocateKol());
        $this->assertSame('K26092802-007', $service->allocateKol());
        $this->assertSame(2, (int) DB::table('kol_daily_order_sequences')->where('creation_date', '2026-09-28')->value('last_number'));
        $this->travelTo(Carbon::parse('2026-09-29 00:00:00', 'Asia/Shanghai'));
        $this->assertSame('K26092901-007', $service->allocateKol());
    }

    public function test_manual_backup_exports_real_mysql_schema_and_synthetic_data(): void
    {
        $directory = storage_path('framework/testing/mysql-backup-'.bin2hex(random_bytes(8)));
        config(['backup.local.directory' => $directory]);
        DB::statement('CREATE TABLE backup_uat_marker (id INT PRIMARY KEY, label VARCHAR(100))');
        DB::table('backup_uat_marker')->insert(['id' => 1, 'label' => 'synthetic-backup-marker']);
        try {
            $result = app(LocalDatabaseBackupService::class)->create();
            $bytes = stream_get_contents($result['stream']);
            fclose($result['stream']);
            $this->assertSame($result['sha256'], hash('sha256', $bytes));
            $sql = gzdecode($bytes);
            $this->assertIsString($sql);
            $this->assertStringContainsString('CREATE TABLE `backup_uat_marker`', $sql);
            $this->assertStringContainsString('synthetic-backup-marker', $sql);
            $this->assertStringContainsString('CREATE TABLE `orders`', $sql);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_all_migrations_and_confirmed_loss_scope_execute_on_mysql_8(): void
    {
        $this->assertTrue(Schema::hasTable('orders'));
        $this->assertTrue(Schema::hasTable('order_financials'));
        $this->assertTrue(Schema::hasTable('order_cost_entries'));
        $this->assertTrue(Schema::hasTable('product_shipping_weight_standard_candidates'));

        $count = app(ConfirmedLossProjection::class)
            ->applyConfirmedLossScope(Order::query())
            ->count();

        $this->assertSame(0, $count);
    }
}
