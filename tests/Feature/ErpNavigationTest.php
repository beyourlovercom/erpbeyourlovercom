<?php

namespace Tests\Feature;

use Tests\TestCase;

class ErpNavigationTest extends TestCase
{
    public function test_every_declared_navigation_path_is_available(): void
    {
        foreach ($this->paths(config('erp_navigation')) as $path) $this->get($path)->assertOk();
    }

    public function test_shell_renders_required_modules_without_affiliate(): void
    {
        $response = $this->get('/logistics/pending');
        foreach (['物流', '客户', '网红', '库存', '外采', '产品', '指南', '会计', '设置'] as $label) $response->assertSee($label);
        $response->assertDontSee('Affiliate OS');
    }

    public function test_accounting_third_level_navigation_is_rendered(): void
    {
        $this->get('/accounting/expense-analysis/2026-execution')->assertOk()->assertSee('费用分析表')->assertSee('2026执行');
    }

    private function paths(array $items): array
    {
        $paths = [];
        foreach ($items as $item) {
            if (isset($item['path'])) $paths[] = $item['path'];
            if (isset($item['children'])) $paths = [...$paths, ...$this->paths($item['children'])];
        }
        return $paths;
    }
}
