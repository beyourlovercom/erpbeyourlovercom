<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Explicit fail-closed guard against new B2B Finance APP-001 dependencies. */
final class B2BFinanceApplicationBoundaryTest extends TestCase
{
    public function test_b2b_finance_application_never_imports_infrastructure(): void
    {
        require_once __DIR__.'/ModuleArchitectureTest.php';
        $relative='app/Modules/Finance/Application/B2BTradeCashAccountingService.php';
        $source=(string)file_get_contents(dirname(__DIR__,2).'/'.$relative);
        $reflection=new ReflectionClass(ModuleArchitectureTest::class);
        $scanner=$reflection->getMethod('sourceViolations');
        $instance=$reflection->newInstanceWithoutConstructor();
        self::assertSame([], $scanner->invoke($instance, 'APP-001', $relative, $source, ['Infrastructure']));
    }
}
