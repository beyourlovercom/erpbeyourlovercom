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
            public function preflight(array $intent): array {
                return ['ready'=>false,'blockers'=>['MEMO_CONTRACT_UNVERIFIED'],'memo_supported'=>false];
            }
            public function createDraft(array $intent): array {
                return ['result'=>'blocked','provider_order_no'=>null];
            }
            public function findExistingExact(string $uniqueThirdOrderNo): array {
                return ['result'=>'not_found','provider_order_no'=>null];
            }
        };
        $registry = new ReshipmentProviderRegistry([$adapter]);
        self::assertSame($adapter,$registry->get('test-provider'));
        self::assertFalse($registry->get('test-provider')->preflight([])['ready']);
    }
}
