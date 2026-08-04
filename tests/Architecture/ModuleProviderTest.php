<?php

namespace Tests\Architecture;

use App\Modules\ModuleManifest;
use App\Modules\ModulesServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ModuleProviderTest extends TestCase
{
    public function test_module_contract_classes_exist_and_have_expected_inheritance(): void
    {
        self::assertTrue(class_exists(ModuleManifest::class));
        self::assertTrue(class_exists(ModulesServiceProvider::class));
        self::assertTrue(is_subclass_of(ModulesServiceProvider::class, ServiceProvider::class));
        self::assertSame(ServiceProvider::class, get_parent_class(ModulesServiceProvider::class));
    }

    public function test_manifest_starts_empty_and_empty_registration_does_not_register(): void
    {
        self::assertSame([], ModuleManifest::providers());

        $application = $this->createMock(Application::class);
        $application->expects(self::never())->method('register');

        $provider = new ModulesServiceProvider($application);
        $provider->register();

        self::assertSame([], ModuleManifest::validate([]));
    }

    public function test_missing_provider_fails_fast_with_class_name(): void
    {
        $provider = 'App\\Modules\\MissingProvider';

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($provider);

        ModuleManifest::validate([$provider]);
    }

    public function test_illegal_provider_type_fails_fast_with_class_name(): void
    {
        $provider = self::class;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($provider);

        ModuleManifest::validate([$provider]);
    }

    public function test_valid_provider_order_is_preserved(): void
    {
        $providers = [
            AppServiceProvider::class,
            ModulesServiceProvider::class,
            FortifyServiceProvider::class,
        ];

        self::assertSame($providers, ModuleManifest::validate($providers));
    }

    public function test_bootstrap_provider_order_and_existing_providers_are_frozen(): void
    {
        self::assertSame([
            AppServiceProvider::class,
            ModulesServiceProvider::class,
            FortifyServiceProvider::class,
        ], require dirname(__DIR__, 2).'/bootstrap/providers.php');
        self::assertTrue(class_exists(AppServiceProvider::class));
        self::assertTrue(class_exists(FortifyServiceProvider::class));
    }

    public function test_boot_is_empty_and_provider_has_no_business_dependencies(): void
    {
        $source = file_get_contents((new \ReflectionClass(ModulesServiceProvider::class))->getFileName());
        self::assertIsString($source);
        self::assertMatchesRegularExpression('/public function boot\(\): void\s*\{\s*\}/s', $source);
        self::assertDoesNotMatchRegularExpression('/(?:Route|Migration|View|Event|Queue|Schedule|Redis|Inertia)::|(?:route|migration|view|event|queue|schedule|redis)\s*\(/i', $source);
    }

    public function test_manifest_and_provider_do_not_use_external_service_names(): void
    {
        $contents = file_get_contents((new \ReflectionClass(ModulesServiceProvider::class))->getFileName());
        $manifest = file_get_contents((new \ReflectionClass(ModuleManifest::class))->getFileName());

        self::assertIsString($contents);
        self::assertIsString($manifest);
        self::assertDoesNotMatchRegularExpression('/(?:DB|Redis|Queue|Schedule|Route|Event|Inertia|filesystem|config\s*\()/i', $contents.$manifest);
    }
}
