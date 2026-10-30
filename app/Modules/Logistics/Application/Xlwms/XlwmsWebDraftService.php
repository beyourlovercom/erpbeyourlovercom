<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsOmsWebGateway;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsWebDraftAttempt;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class XlwmsWebDraftService
{
    public function __construct(
        private readonly SingleOrderPreview $preview,
        private readonly XlwmsOmsWebGateway $oms,
    ) {}

    /**
     * One Owner-approved manual click. Historical OMS orders are audit-only.
     * The sole remote duplicate gate is CURRENT OMS status=0 drafts.
     * @return array<string,mixed>
     */
    public function createOrReturn(int $orderId, int $actorId): array
    {
        $order=Order::query()->with(['address','items','shipment.warehouse'])->findOrFail($orderId);
        abort_unless($order->shipment?->warehouse?->code==='US-SM',422,'US_SM_ONLY');
        $plan=$this->preview->forOrder($order,false,true);
        abort_unless($plan['ready_for_remote_check'],422,'OMS_WEB_DRAFT_INPUT_INVALID:'.implode(',',$plan['blockers']));

        $attempt=DB::transaction(function () use ($order,$plan,$actorId): XlwmsWebDraftAttempt {
            Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            // This lock covers concurrent in-flight clicks, NOT historical
            // drafts, canceled, shipped or previously completed OMS orders.
            $active=XlwmsWebDraftAttempt::query()->where('order_id',$order->id)
                ->whereIn('state',['checking_current_drafts','uploading','creating'])
                ->where('updated_at','>=',now()->subMinutes(3))->exists();
            if ($active) throw new RuntimeException('OMS_WEB_DRAFT_REQUEST_IN_FLIGHT');
            return XlwmsWebDraftAttempt::query()->create([
                'order_id'=>$order->id,'warehouse_id'=>$order->shipment->warehouse_id,
                'attempt_ref'=>'BYL-WEB-'.str_pad((string)$order->id,8,'0',STR_PAD_LEFT).'-'.strtoupper(bin2hex(random_bytes(7))),
                'request_fingerprint'=>$plan['payload_hash'],
                'state'=>'checking_current_drafts','requested_by'=>$actorId,
            ]);
        });

        try {
            $references=array_values(array_unique(array_filter([
                trim((string)$order->erp_order_id),
                trim((string)$order->internal_order_number),
                trim((string)($plan['payload']['referOrderNo']??'')),
            ],fn($v)=>$v!=='')));
            if ($references===[]) throw new RuntimeException('OMS_WEB_ORDER_IDENTITY_MISSING');
            $current=$this->matchDraft($this->oms->currentDrafts(),$references);
            if ($current) {
                $omsNo=$this->omsNo($current);
                if (!$omsNo) throw new RuntimeException('OMS_WEB_EXISTING_DRAFT_NUMBER_MISSING');
                $attempt->forceFill(['state'=>'existing_current_draft','oms_order_no'=>$omsNo,
                    'duplicate_match_field'=>$current['_erp_match_field']??null])->save();
                return $this->report($attempt);
            }

            $attempt->forceFill(['state'=>'uploading'])->save();
            $appendix=$this->oms->uploadPlaceholder();
            $payload=$this->webPayload($plan['payload'],$attempt->attempt_ref,$appendix,$references[0]);
            $attempt->forceFill(['state'=>'creating'])->save();
            $ack=$this->oms->createDraft($payload);
            $omsNo=is_string($ack['outboundOrderNo']??null)?$ack['outboundOrderNo']:null;
            // Native OMS Save Draft is not required to return the new number:
            // resolve by the unique provider platform reference on draft inbox.
            for ($i=0;$i<3;$i++) {
                $found=$this->matchDraft($this->oms->currentDrafts(),[$attempt->attempt_ref]);
                if ($found) {
                    $omsNo=$this->omsNo($found);
                    break;
                }
                if ($i<2) usleep(180000);
            }
            if (!$omsNo) throw new RuntimeException('OMS_WEB_CREATE_UNCONFIRMED');
            $detail=$this->oms->detail($omsNo);
            if ((string)($detail['status']??'')!=='0'
                || ($detail['outboundOrderNo']??null)!==$omsNo
                || ($detail['platformOrderNo']??null)!==$attempt->attempt_ref
                || ($detail['referOrderNo']??null)!==$payload['referOrderNo']
                || ($detail['logisticsChannel']??null)!=='Upload_Shipping_Label'
                || strtolower((string)($detail['logisticsCarrier']??''))!=='usps'
                || ($detail['whCode']??null)!==($payload['whCode']??null)
                || filled($detail['trackNo']??null)
                || !$this->sameItems($detail['productList']??null,$payload['productList'])) {
                throw new RuntimeException('OMS_WEB_CREATED_DRAFT_IDENTITY_UNVERIFIED');
            }
            $attempt->forceFill(['state'=>'oms_draft_created','oms_order_no'=>$omsNo])->save();
            return $this->report($attempt);
        } catch (\Throwable $e) {
            $uncertain=$attempt->state==='creating';
            $attempt->forceFill([
                'state'=>$uncertain?'create_uncertain':'failed',
                'failure_code'=>substr($e instanceof RuntimeException?$e->getMessage():'OMS_WEB_OPERATION_FAILED',0,100),
            ])->save();
            throw $e;
        }
    }

    /** @param list<array<string,mixed>> $rows @param list<string> $references
     * @return array<string,mixed>|null
     */
    private function matchDraft(array $rows,array $references): ?array
    {
        foreach ($rows as $row) {
            if ((string)($row['status']??'')!=='0') continue;
            foreach (['referOrderNo','platformOrderNo','thirdOrderNo','referenceOrderNo'] as $field) {
                $value=trim((string)($row[$field]??''));
                foreach ($references as $reference) {
                    if ($value===$reference || str_starts_with($value,$reference.'-')) {
                        $row['_erp_match_field']=$field;
                        return $row;
                    }
                }
            }
        }
        return null;
    }

    /** @param array<string,mixed> $base @param array<string,mixed> $appendix
     * @return array<string,mixed>
     */
    private function webPayload(array $base,string $attemptRef,array $appendix,string $stableReference): array
    {
        $payload=$base;
        unset($payload['isSubmit'],$payload['thirdOrderNo']);
        $payload['platformOrderNo']=$attemptRef;
        // Keep the ERP order as the searchable prefix, but give each
        // *new* OMS draft a distinct warehouse reference. Some warehouses
        // reject historical duplicate reference numbers on later submission.
        // This never changes the current-draft-only duplicate rule.
        $payload['referOrderNo']=substr($stableReference,0,80).'-'.substr($attemptRef,-14);
        $payload['salesPlatform']='';
        $payload['logisticsChannel']='Upload_Shipping_Label';
        $payload['logisticsCarrier']='usps';
        $payload['channelType']=3;
        $payload['trackNo']='';
        $payload['logisticsSheet']=$appendix['fileName'];
        $payload['appendixList']=[$appendix];
        $payload['remark']='TEST PLACEHOLDER - VOID / DO NOT SHIP. Replace with genuine USPS label and check Tracking before manual submit.';
        $payload['updateAddressFlag']=true;
        $payload['needInsure']=1;
        $payload['insureMoney']=0;
        $payload['vasList']=[];
        $payload['productList']=array_map(static fn($x)=>[
            'sku'=>$x['sku'],'quantity'=>$x['quantity'],
            'availableAmount'=>$x['quantity'],
        ],$base['productList']);
        $payload['expressTypeList']=[['packQty'=>1,'expressDetailList'=>array_map(
            static fn($x)=>[
                'item'=>$x['sku'],'quantity'=>$x['quantity'],
                'availableAmount'=>$x['quantity'],
            ],$payload['productList'],
        )]];
        return $payload;
    }

    private function sameItems(mixed $actual,mixed $expected): bool
    {
        if (!is_array($actual) || !is_array($expected)) return false;
        $normalize=static function(array $rows): array {
            $items=[];
            foreach($rows as $row) {
                if (!is_array($row) || !filled($row['sku']??null) || !is_numeric($row['quantity']??null)) return [];
                $sku=(string)$row['sku'];
                $items[$sku]=($items[$sku]??0)+(int)$row['quantity'];
            }
            ksort($items);
            return $items;
        };
        $one=$normalize($actual);$two=$normalize($expected);
        return $one!==[] && $one===$two;
    }

    private function omsNo(array $row): ?string
    {
        $value=$row['outboundOrderNo']??$row['orderNo']??null;
        return is_string($value)&&trim($value)!==''?trim($value):null;
    }

    private function report(XlwmsWebDraftAttempt $attempt): array
    {
        return [
            'state'=>$attempt->state,
            'oms_order_no'=>$attempt->oms_order_no,
            'attempt_ref'=>$attempt->attempt_ref,
            'failure_code'=>$attempt->failure_code,
            'duplicate_match_field'=>$attempt->duplicate_match_field,
        ];
    }
}
