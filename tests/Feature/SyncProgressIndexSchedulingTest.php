<?php

namespace Tests\Feature;

use Tests\TestCase;

final class SyncProgressIndexSchedulingTest extends TestCase
{
    public function test_heavy_progress_index_runs_in_background_with_lock_and_without_changing_woo_half_hourly_schedule(): void
    {
        $source = file_get_contents(base_path('routes/console.php'));
        self::assertIsString($source);
        $start = strpos($source, "Schedule::command('sync:refresh-progress-index --no-interaction')");
        self::assertNotFalse($start);

        $event = substr($source, $start, strpos($source, ';', $start) - $start);
        self::assertStringContainsString('->everyFiveMinutes()', $event);
        self::assertStringContainsString("->name('sync.orders.progress-index')", $event);
        self::assertStringContainsString('->withoutOverlapping(10)', $event);
        self::assertStringContainsString('->onOneServer()', $event);
        self::assertStringContainsString('->runInBackground()', $event);

        self::assertStringContainsString("Schedule::command('orders:sync-woocommerce --no-interaction')", $source);
        self::assertStringContainsString("Schedule::command('tracking:retry-woocommerce --apply --no-interaction')", $source);
    }
}
