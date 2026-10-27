<?php

namespace Tests\Unit\Products;

use App\Modules\Products\Application\VerifiedOmsSkuSelector;
use PHPUnit\Framework\TestCase;

final class VerifiedOmsSkuSelectorTest extends TestCase
{
    private VerifiedOmsSkuSelector $selector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->selector = new VerifiedOmsSkuSelector();
    }

    private function alias(string $sku = 'ABC-WH'): array
    {
        return [
            'alias_sku' => $sku, 'is_active' => true,
            'oms_verified_at' => '2026-10-26 10:00:00',
            'oms_identity_evidence' => 'Physical model, color and packaging checked.',
        ];
    }

    private function row(string $sku = 'ABC-WH'): array
    {
        return [
            'sku' => $sku, 'warehouse_code' => 'OMS-US',
            'approval_status' => 2, 'available_quantity' => 8,
            'physical_identity_confirmed' => true,
        ];
    }

    private function choose(array $catalog, array $aliases = [], bool $complete = true, int $qty = 1): array
    {
        return $this->selector->select('ABC-001-WH', $aliases, 'OMS-US', $qty, $catalog, $complete);
    }

    public function test_unique_canonical_with_verified_warehouse_stock_is_accepted(): void
    {
        $selection = $this->choose([$this->row('ABC-001-WH')]);
        self::assertSame('ready', $selection['status']);
        self::assertSame('ABC-001-WH', $selection['oms_sku']);
        self::assertSame('canonical', $selection['source']);
    }

    public function test_verified_alias_is_accepted_without_changing_canonical_identity(): void
    {
        $selection = $this->choose([$this->row()], [$this->alias()]);
        self::assertSame('ready', $selection['status']);
        self::assertSame('ABC-WH', $selection['oms_sku']);
        self::assertSame('approved_alias', $selection['source']);
    }

    public function test_existing_unverified_alias_is_not_a_dispatch_candidate(): void
    {
        $alias = $this->alias();
        $alias['oms_verified_at'] = null;
        self::assertSame('OMS_SKU_NOT_FOUND', $this->choose([$this->row()], [$alias])['code']);
    }

    public function test_inactive_alias_is_not_a_dispatch_candidate(): void
    {
        $alias = $this->alias();
        $alias['is_active'] = false;
        self::assertSame('OMS_SKU_NOT_FOUND', $this->choose([$this->row()], [$alias])['code']);
    }

    public function test_two_existing_codes_for_one_product_are_ambiguous(): void
    {
        $rows = [$this->row('ABC-001-WH'), $this->row()];
        self::assertSame('OMS_SKU_AMBIGUOUS', $this->choose($rows, [$this->alias()])['code']);
    }

    public function test_duplicate_vendor_catalog_rows_fail_closed(): void
    {
        self::assertSame('OMS_SKU_AMBIGUOUS', $this->choose([$this->row(), $this->row()], [$this->alias()])['code']);
    }

    public function test_never_falls_back_to_unapproved_or_unverified_physical_item(): void
    {
        $r = $this->row('ABC-001-WH');
        $r['approval_status'] = 1;
        self::assertSame('OMS_SKU_UNAPPROVED', $this->choose([$r])['code']);
        $r['approval_status'] = 2;
        $r['physical_identity_confirmed'] = false;
        self::assertSame('OMS_PHYSICAL_IDENTITY_UNVERIFIED', $this->choose([$r])['code']);
    }

    public function test_unknown_or_insufficient_stock_blocks(): void
    {
        $r = $this->row('ABC-001-WH');
        unset($r['available_quantity']);
        self::assertSame('OMS_STOCK_UNVERIFIED', $this->choose([$r])['code']);
        $r['available_quantity'] = 1;
        self::assertSame('OMS_STOCK_INSUFFICIENT', $this->choose([$r], [], true, 2)['code']);
    }

    public function test_warehouse_scope_and_complete_pagination_are_mandatory(): void
    {
        $r = $this->row('ABC-001-WH');
        $r['warehouse_code'] = 'OMS-OTHER';
        self::assertSame('OMS_SKU_NOT_FOUND', $this->choose([$r])['code']);
        self::assertSame('OMS_CATALOG_INCOMPLETE', $this->choose([$this->row('ABC-001-WH')], [], false)['code']);
    }

    public function test_wrong_variant_is_never_fuzzy_matched(): void
    {
        self::assertSame('OMS_SKU_NOT_FOUND', $this->choose([$this->row('ABC-001-BK')])['code']);
    }
}
