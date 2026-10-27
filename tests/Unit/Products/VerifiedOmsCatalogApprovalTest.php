<?php

namespace Tests\Unit\Products;

use App\Modules\Products\Application\VerifiedOmsCatalogApproval;
use PHPUnit\Framework\TestCase;

final class VerifiedOmsCatalogApprovalTest extends TestCase
{
    private function check(array $rows, array $codes=['ABC-WH'], bool $complete=true): array
    {
        return (new VerifiedOmsCatalogApproval())->check($rows,$codes,$complete);
    }

    public function test_explicit_approval_two_is_accepted_from_complete_catalog(): void
    {
        $rows=[['sku'=>'ABC-WH','approveStatus'=>2],['sku'=>'OTHER','approveStatus'=>1]];
        self::assertSame(['ABC-WH'],$this->check($rows)['approved_skus']);
        self::assertSame('ready',$this->check($rows)['status']);
    }

    public function test_absent_approval_never_implicitly_passes(): void
    {
        self::assertSame('OMS_PRODUCT_SKU_NOT_EXPLICITLY_APPROVED',$this->check([['sku'=>'ABC-WH']])['code']);
        self::assertSame('OMS_PRODUCT_SKU_NOT_EXPLICITLY_APPROVED',$this->check([['sku'=>'ABC-WH','approveStatus'=>1]])['code']);
    }

    public function test_incomplete_catalog_and_ambiguous_keys_reject(): void
    {
        self::assertSame('OMS_PRODUCT_CATALOG_INCOMPLETE',$this->check([['sku'=>'ABC-WH','approveStatus'=>2]],complete:false)['code']);
        self::assertSame('OMS_PRODUCT_CATALOG_SKU_CONFLICT',$this->check([['sku'=>'ABC-WH','approveStatus'=>2],['sku'=>'abc-wh','approveStatus'=>2]])['code']);
        self::assertSame('OMS_PRODUCT_SKU_MISSING',$this->check([['sku'=>'UNRELATED','approveStatus'=>2]])['code']);
    }

    public function test_account_catalog_is_not_warehouse_stock_evidence(): void
    {
        // Product approval is deliberately independent of target warehouse quantity.
        $result=$this->check([['sku'=>'ABC-WH','approveStatus'=>'2','whCode'=>null]]);
        self::assertSame('ready',$result['status']);
        self::assertArrayNotHasKey('available_quantity',$result);
    }

    public function test_exact_case_required_before_sending_vendor_code(): void
    {
        self::assertSame('OMS_PRODUCT_SKU_CASE_MISMATCH',$this->check([['sku'=>'abc-wh','approveStatus'=>2]])['code']);
    }
}
