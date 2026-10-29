<?php
namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Logistics\Application\Reshipment\ReshipmentProviderAdapter;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsOrderGateway;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use RuntimeException;

final class XlwmsReissueAdapter implements ReshipmentProviderAdapter
{
    public function __construct(private readonly XlwmsOrderGateway $gateway) {}
    public function providerKey(): string { return 'xlwms'; }
    public function assertCatalogReady(array $c): void {
        $p=$this->draft($c);
        $this->gateway->assertCatalogReady($this->connection($c), $p['whCode'],
            $p['logisticsChannel'], array_column($p['productList'],'sku'), true);
    }
    public function findExistingExact(array $c): ?array {
        $p=$this->draft($c);
        $matches=$this->gateway->findExistingExact($this->connection($c),
            $p['thirdOrderNo'],$p['platformOrderNo'],[$p['referOrderNo']],$p['productList']);
        if(count($matches)>1) throw new RuntimeException('REISSUE_DUPLICATE_AMBIGUOUS');
        return $matches[0]??null;
    }
    public function createDraft(array $c): array {
        return $this->gateway->createDraftSingle($this->connection($c),$this->draft($c));
    }
    public function readStatus(array $c): ?array {
        $no=trim((string)($c['oms_order_no']??''));
        if(!$no) throw new RuntimeException('REISSUE_OMS_ID_MISSING');
        return $this->gateway->readExactOrderStatus($this->connection($c),$no);
    }
    private function connection(array $c): WarehouseOpenApiConnection {
        $conn=$c['connection']??null;
        if(!$conn instanceof WarehouseOpenApiConnection) throw new RuntimeException('REISSUE_CONNECTION_INVALID');
        return $conn;
    }
    private function draft(array $c): array {
        $p=$c['payload']??null;
        if(!is_array($p) || ($p['isSubmit']??null)!==0
          || ($p['logisticsChannel']??null)!=='No_Shipping_Service'
          || !is_array($p['productList']??null)
          || !preg_match('/^BYL-RESHIP-[0-9a-f]{32}$/D',(string)($p['thirdOrderNo']??''))
          || ($p['thirdOrderNo']??null)!==($p['platformOrderNo']??null)
          || ($p['thirdOrderNo']??null)!==($p['referOrderNo']??null)) {
            throw new RuntimeException('REISSUE_DRAFT_CONTRACT_INVALID');
        }
        return $p;
    }
}
