<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class MigrationIndexNameTest extends TestCase
{
    public function test_inventory_lot_composite_index_uses_mysql_safe_explicit_name(): void
    {
        $indexName = 'wh_inv_lots_wh_product_status_idx';
        $migration = file_get_contents(dirname(__DIR__, 2).'/database/migrations/2026_08_25_000200_create_inventory_batch_sync_tables.php');

        self::assertLessThanOrEqual(64, strlen($indexName));
        self::assertStringContainsString(
            "\$table->index(['warehouse_id', 'product_id', 'stock_status'], '{$indexName}');",
            $migration,
        );
    }
}
