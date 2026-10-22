<?php

namespace Tests\Feature\B2B;

use PHPUnit\Framework\TestCase;

final class B2BM5SourceContractTest extends TestCase
{
    private function source(string $path): string
    {
        $file = dirname(__DIR__, 3).'/'.$path;
        self::assertFileExists($file);
        return (string) file_get_contents($file);
    }

    public function test_b2b_is_a_separate_registered_module_with_its_own_route_and_permissions(): void
    {
        self::assertStringContainsString('B2BServiceProvider::class', $this->source('app/Modules/ModuleManifest.php'));
        self::assertStringContainsString("'key' => 'b2b'", $this->source('config/erp_shell_navigation.php'));
        self::assertStringContainsString("'permission' => 'b2b.view'", $this->source('config/erp_shell_navigation.php'));
        self::assertStringContainsString("'can:b2b.view'", $this->source('app/Modules/B2B/Experience/Routes/web.php'));
        self::assertStringContainsString("'can:b2b.operate'", $this->source('app/Modules/B2B/Experience/Routes/web.php'));
        self::assertStringContainsString("'can:b2b.assign'", $this->source('app/Modules/B2B/Experience/Routes/web.php'));
        self::assertStringContainsString("'customers.view'", $this->source('config/erp_shell_navigation.php'));
    }

    public function test_new_b2b_code_does_not_import_customer_data_models_or_yby_site_connections(): void
    {
        $root = dirname(__DIR__, 3).'/app/Modules/B2B';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            self::assertStringNotContainsString('App\\Modules\\Customers\\Infrastructure', $source, $file->getFilename());
            self::assertStringNotContainsString('yby_leads', $source, $file->getFilename());
            self::assertStringNotContainsString('DB::connection(', $source, $file->getFilename());
        }
    }

    public function test_retained_sources_are_index_only_and_cases_do_not_cascade_delete_history(): void
    {
        $intake = $this->source('app/Modules/B2B/Infrastructure/B2BVerifiedSourceIntake.php');
        foreach (['createOrFirst', 'source_dedupe_key', 'Provider source identity conflict', 'website_form'] as $needle) {
            self::assertStringContainsString($needle, $intake);
        }
        self::assertStringNotContainsString('body_html', $intake);
        self::assertStringNotContainsString('raw_payload', $intake);
        $schema = $this->source('database/migrations/2026_10_22_000200_create_b2b_cases_and_activity.php');
        self::assertStringContainsString("->constrained('b2b_organizations')->restrictOnDelete()", $schema);
        self::assertStringContainsString("->constrained('b2b_cases')->restrictOnDelete()", $schema);
    }

    public function test_no_business_permission_is_implicitly_part_of_the_existing_consumer_role(): void
    {
        $rolePreset = $this->source('app/Modules/Identity/Application/RolePreset.php');
        $start = strpos($rolePreset, 'self::SalesRepresentative =>');
        $end = strpos($rolePreset, 'self::KolSpecialist =>', $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $legacySalesPermissions = substr($rolePreset, $start, $end - $start);
        self::assertStringNotContainsString("'b2b.", $legacySalesPermissions);
    }
}
