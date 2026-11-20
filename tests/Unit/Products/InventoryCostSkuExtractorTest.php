<?php

namespace Tests\Unit\Products;

use App\Modules\Products\Application\InventoryCostSkuExtractor;
use PHPUnit\Framework\TestCase;

final class InventoryCostSkuExtractorTest extends TestCase
{
    public function test_chinese_domestic_version_suffix_is_not_part_of_base_sku(): void
    {
        $result = InventoryCostSkuExtractor::extract('VA617A（国内新版）');
        $this->assertSame('VA617A', $result['base_sku']);
        $this->assertSame('extracted', $result['status']);
        $this->assertSame('product_name', $result['evidence']);
    }

    public function test_packaging_description_and_hyphenated_sku(): void
    {
        $this->assertSame('VA617A', InventoryCostSkuExtractor::extract('VA617A...包装更新款')['base_sku']);
        $this->assertSame('VX610A-S', InventoryCostSkuExtractor::extract('VX610A-S 蓝色')['base_sku']);
    }

    public function test_factory_long_code_produces_a_reviewable_suggestion_only(): void
    {
        $result = InventoryCostSkuExtractor::extract('BEYOURLOVER 35毫升软管润滑油', '1BY489A5C0100');
        $this->assertSame('BY489A', $result['base_sku']);
        $this->assertSame('needs_confirmation', $result['status']);
        $this->assertSame('factory_long_code', $result['evidence']);
    }

    public function test_conflicting_name_and_long_code_stays_ambiguous(): void
    {
        $result = InventoryCostSkuExtractor::extract('VA617A（国内新版）', '1BY489A5C0100');
        $this->assertSame('VA617A', $result['base_sku']);
        $this->assertSame('ambiguous', $result['status']);
        $this->assertSame(['VA617A', 'BY489A'], $result['candidates']);
    }

    public function test_short_skus_and_marketing_year_are_handled(): void
    {
        $this->assertSame('A1', InventoryCostSkuExtractor::extract('B站007 A1肛塞')['base_sku']);
        $this->assertSame('M1', InventoryCostSkuExtractor::extract('M1 B站007手持世电动飞机杯')['base_sku']);
        $this->assertSame('TX218Y', InventoryCostSkuExtractor::extract('TX218Y塔娜四代粉色APP2024')['base_sku']);
    }

    public function test_color_suffix_needs_explicit_confirmation(): void
    {
        $result = InventoryCostSkuExtractor::extract('VA617A-GREEN 成品');
        $this->assertSame('VA617A', $result['base_sku']);
        $this->assertSame('needs_confirmation', $result['status']);
        $this->assertSame(['VA617A-GREEN', 'VA617A'], $result['candidates']);
    }

    public function test_disagreeing_factory_long_code_keeps_name_candidate_but_blocks_automatic_match(): void
    {
        $result = InventoryCostSkuExtractor::extract('S106E 抱球双拍打伸缩震动棒', '1VS106E04C120');
        $this->assertSame('S106E', $result['base_sku']);
        $this->assertSame('ambiguous', $result['status']);
    }

    public function test_multiple_or_missing_name_skus_are_not_auto_matched(): void
    {
        $this->assertSame('ambiguous', InventoryCostSkuExtractor::extract('VA617A + BX033B 组合')['status']);
        $this->assertSame('unmatched', InventoryCostSkuExtractor::extract('无编码包装袋')['status']);
    }
}
