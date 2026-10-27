<?php

declare(strict_types=1);

/**
 * Disposable MySQL 8 R5 gate ONLY. This file must never be executed against
 * production, staging, or a database populated with customer data.
 */
if (getenv('BYL_ORDER_TRASH_ROLLBACK_CI') !== '1'
    || getenv('APP_ENV') !== 'testing'
    || getenv('DB_CONNECTION') !== 'mysql'
    || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_DATABASE') !== 'erp_order_trash_ci'
    || getenv('ERP_LOCAL_ADMIN_BYPASS') !== 'false') {
    fwrite(STDERR, "REFUSED_NON_DISPOSABLE_ENVIRONMENT\n");
    exit(30);
}

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'erp_order_trash_ci'
    || config('database.connections.mysql.host') !== '127.0.0.1') {
    throw new RuntimeException('Effective PDO target is not the isolated MySQL test DB.');
}
$pdoDatabase = (string) DB::selectOne('SELECT DATABASE() AS db')->db;
if ($pdoDatabase !== 'erp_order_trash_ci') {
    throw new RuntimeException('PDO connected to an unexpected database.');
}
if (DB::table('orders')->count() !== 0 || ! Schema::hasColumn('orders', 'deleted_at')
    || ! Schema::hasColumn('orders', 'deleted_by')) {
    throw new RuntimeException('Expected a fresh migrated order-trash schema.');
}

$id = DB::table('orders')->insertGetId([
    'internal_order_number' => 'R5-ROLLBACK-UAT-001',
    'erp_order_id' => '007erp-1027-k-R5-001',
    'source_type' => 'manual',
    'order_kind' => 'kol_fulfillment',
    'order_date' => '2026-10-27',
    'order_status' => 'pending_shipment',
    'currency' => 'USD',
    'created_at' => now(),
    'updated_at' => now(),
]);
$recordCount = DB::table('orders')->count();
DB::table('orders')->where('id', $id)->update(['deleted_at' => now()]);
$migration = require $root.'/database/migrations/2026_10_27_000001_add_order_trash_fields.php';
$blocked = false;
try {
    $migration->down();
} catch (RuntimeException $e) {
    $blocked = str_contains($e->getMessage(), 'Cannot drop order trash columns');
}
if (! $blocked || ! Schema::hasColumn('orders', 'deleted_at')
    || DB::table('orders')->whereKey($id)->whereNull('deleted_at')->exists()
    || DB::table('orders')->count() !== $recordCount) {
    throw new RuntimeException('Rollback did not fail closed for a trashed order.');
}

DB::table('orders')->where('id', $id)->update(['deleted_at' => null, 'deleted_by' => null]);
$migration->down();
if (Schema::hasColumn('orders', 'deleted_at') || Schema::hasColumn('orders', 'deleted_by')
    || DB::table('orders')->count() !== $recordCount) {
    throw new RuntimeException('Clean rollback changed records or left trash columns.');
}
$migration->up();
if (! Schema::hasColumn('orders', 'deleted_at') || ! Schema::hasColumn('orders', 'deleted_by')
    || DB::table('orders')->count() !== $recordCount) {
    throw new RuntimeException('Re-applying the migration after rollback failed.');
}

echo "MYSQL_ORDER_TRASH_UP_FAIL_CLOSED_DOWN_CLEAN_DOWN_REUP=PASS\n";
