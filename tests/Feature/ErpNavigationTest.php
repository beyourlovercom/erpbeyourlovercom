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

    public function test_order_list_and_detail_render_the_phase_two_skeleton(): void
    {
        $this->get('/kol/orders')->assertOk()->assertSee('网红订单')->assertSee('KOL26073001-007');
        $this->get('/kol/orders/KOL26073001-007')->assertOk()->assertSee('客户详情')->assertSee('物流信息拆分')->assertSee('物流发货')->assertSee('操作记录');
        $this->get('/kol/orders/INVALID-ORDER')->assertNotFound();
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
