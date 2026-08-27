<?php

namespace App\Modules\Products\Experience\Console;

use App\Modules\Products\Application\ProductService;
use Illuminate\Console\Command;

final class ImportWooCsvCommand extends Command
{
    protected $signature = 'products:import-woo-csv {path : Absolute path to the Woo export CSV}';
    protected $description = 'Import or update products from a WooCommerce product export CSV (idempotent, keyed by Woo product ID)';

    public function handle(ProductService $service): int
    {
        $path = (string) $this->argument('path');

        if (! file_exists($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $this->info("Importing from: {$path}");
        $result = $service->import($path);

        $this->table(
            ['Inserted', 'Updated', 'Skipped', 'Invalid'],
            [[$result->inserted, $result->updated, $result->skipped, $result->invalid]],
        );

        foreach ($result->errors as $error) {
            $this->warn($error);
        }

        $total = $result->inserted + $result->updated + $result->skipped;
        $this->info("Done. {$total} rows processed ({$result->inserted} inserted, {$result->updated} updated, {$result->skipped} skipped, {$result->invalid} invalid).");

        return self::SUCCESS;
    }
}
