<?php

namespace Tests\Feature\B2B;

use PHPUnit\Framework\TestCase;

final class B2BMenuContractTest extends TestCase
{
    public function test_independent_b2b_navigation_matches_approved_b2b_baseline_exactly(): void
    {
        $root = dirname(__DIR__, 3);
        $navigation = require $root.'/config/erp_shell_navigation.php';
        $baseline = json_decode(
            (string) file_get_contents($root.'/menu/APPROVED_MENU.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $actual = collect($navigation)->firstWhere('key', 'b2b');
        $this->assertIsArray($actual, 'B2B must remain a separate level-1 menu');
        $this->assertCount(1, collect($navigation)->where('key', 'b2b'));
        $this->assertSame('/b2b', $actual['path']);
        $this->assertSame('b2b.view', $actual['permission']);
        $this->assertSame('b2b.overview', $actual['route']);
        $this->assertFalse($actual['placeholder']);

        $approved = collect($baseline['items'])->firstWhere('code', 'b2b');
        $this->assertIsArray($approved);
        $this->assertNull($approved['parent_code']);
        $this->assertSame(1, $approved['level']);
        foreach ([
            'label' => 'label_zh',
            'path' => 'url',
            'route' => 'route',
            'permission' => 'permission',
            'emoji' => 'emoji',
            'status' => 'status',
            'progress' => 'progress',
        ] as $sourceKey => $approvedKey) {
            $this->assertSame($approved[$approvedKey], $actual[$sourceKey], $sourceKey);
        }

        $expectedChildren = [
            'b2b-inquiries' => ['/b2b/inquiries', 'b2b.inquiries.index'],
            'b2b-companies' => ['/b2b/companies', 'b2b.companies.index'],
            'b2b-followups' => ['/b2b/followups', 'b2b.followups'],
        ];
        $this->assertSame(array_keys($expectedChildren), array_column($actual['children'], 'key'));
        foreach ($actual['children'] as $item) {
            [$path, $route] = $expectedChildren[$item['key']];
            $this->assertSame($path, $item['path']);
            $this->assertSame($route, $item['route']);
            $this->assertSame('b2b.view', $item['permission']);
            $this->assertFalse($item['placeholder']);

            $approvedChild = collect($baseline['items'])->firstWhere('code', $item['key']);
            $this->assertIsArray($approvedChild);
            $this->assertSame('b2b', $approvedChild['parent_code']);
            $this->assertSame(2, $approvedChild['level']);
            foreach ([
                'label' => 'label_zh',
                'path' => 'url',
                'route' => 'route',
                'permission' => 'permission',
                'status' => 'status',
                'progress' => 'progress',
            ] as $sourceKey => $approvedKey) {
                $this->assertSame($approvedChild[$approvedKey], $item[$sourceKey], $item['key'].'.'.$sourceKey);
            }
        }

        $this->assertCount(1, collect($navigation)->where('key', 'customers'));
        $this->assertSame('customers.view', collect($navigation)->firstWhere('key', 'customers')['permission']);
        $this->assertSame('/customers', collect($navigation)->firstWhere('key', 'customers')['path']);
    }
}
