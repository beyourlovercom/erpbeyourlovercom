<?php

namespace Tests\Unit\Logistics;

use App\Modules\Logistics\Application\Xlwms\XlwmsInventoryPageParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class XlwmsInventoryPageParserTest extends TestCase
{
    private function response(array $rows, int $total, int $page = 1, int $pageSize = 2): array
    {
        return ['code'=>200,'data'=>['records'=>$rows,'total'=>$total,'page'=>$page,'pageSize'=>$pageSize,'pages'=>(int) ceil($total / $pageSize)],'msg'=>'OK'];
    }

    public function test_accepts_business_success_and_exact_warehouse_scope(): void
    {
        $parsed = (new XlwmsInventoryPageParser())->productPage($this->response([['sku'=>'A']], 1), 1, 2);
        self::assertSame(1, $parsed['total']);
        self::assertSame('A', $parsed['rows'][0]['sku']);
    }

    public function test_inventory_page_requires_exact_warehouse_scope(): void
    {
        $parsed = (new XlwmsInventoryPageParser())->inventoryPage($this->response([['sku'=>'A','whCode'=>'US-SM']], 1), 'US-SM', 1, 2);
        self::assertSame('A', $parsed['rows'][0]['sku']);
        $this->expectExceptionMessage('OMS_WAREHOUSE_SCOPE_MISMATCH');
        (new XlwmsInventoryPageParser())->inventoryPage($this->response([['sku'=>'A','whCode'=>'OTHER']], 1), 'US-SM', 1, 2);
    }

    public function test_rejects_http_like_success_without_business_code(): void
    {
        $this->expectExceptionMessage('OMS_BUSINESS_REJECTED');
        (new XlwmsInventoryPageParser())->productPage(['code'=>0,'data'=>[],'msg'=>'bad'], 1, 2);
    }

    public function test_rejects_ignored_warehouse_filter_and_shape_drift(): void
    {
        $this->expectExceptionMessage('OMS_WAREHOUSE_SCOPE_MISMATCH');
        (new XlwmsInventoryPageParser())->inventoryPage($this->response([['sku'=>'A','whCode'=>'OTHER']], 1), 'US-SM', 1, 2);
    }

    public function test_requires_total_on_full_page_and_rejects_negative_or_unknown_numbers(): void
    {
        $this->expectExceptionMessage('OMS_PAGE_SHAPE_INVALID');
        (new XlwmsInventoryPageParser())->productPage(['code'=>200,'data'=>['records'=>[['sku'=>'A'],['sku'=>'B']]],'msg'=>'OK'], 1, 2);
    }

    public function test_accepts_only_non_negative_integer_numbers(): void
    {
        $parser = new XlwmsInventoryPageParser();
        self::assertSame(3, $parser->nonNegativeInt('3', 'TOTAL'));
        $this->expectException(RuntimeException::class);
        $parser->nonNegativeInt(-1, 'TOTAL');
    }
}
