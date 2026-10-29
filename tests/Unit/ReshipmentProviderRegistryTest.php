<?php

namespace Tests\Unit;

use App\Modules\Logistics\Application\Reshipment\ReshipmentProviderAdapter;
use App\Modules\Logistics\Application\Reshipment\ReshipmentProviderRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReshipmentProviderRegistryTest extends TestCase
{
    public function test_registry_fails_closed_on_unsupported_warehouse_provider(): void
    {
        $registry = new ReshipmentProviderRegistry([]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RESHIP_PROVIDER_NOT_SUPPORTED');
        $registry->get('unknown');
    }

    public function test_registry_resolves_only_explicit_provider_adapter(): void
    {
        $adapter = new class implements ReshipmentProviderAdapter {
            public function providerKey(): string { return 'test-provider'; }
            public function assertCatalogReady(array $context): void {}
            public function createDraft(array $context): array {
                return ['confirmed'=>false,'order_no'=>''];
            }
            public function findExistingExact(array $context): ?array { return null; }
            public function readStatus(array $context): ?array { return null; }
        };
        $registry = new ReshipmentProviderRegistry([$adapter]);
        self::assertSame($adapter,$registry->get('test-provider'));
        self::assertNull($registry->get('test-provider')->findExistingExact([]));
    }
}
