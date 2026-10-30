<?php

namespace App\Modules\Logistics\Infrastructure\Xlwms;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Authenticated XLWMS OMS *website* API, not the XLWMS signed OpenAPI.
 * Only exposes Save Draft, the placeholder upload and read-only queries;
 * intentionally exposes no createAndSubmit or submit route.
 */
final class XlwmsOmsWebGateway
{
    public function __construct(
        private readonly string $baseUrl = '',
        private readonly string $token = '',
        private readonly string $hmacKey = '',
    ) {}

    /** @return list<array<string,mixed>> */
    public function currentDrafts(): array
    {
        $all=[];
        $pageSize=20;
        for ($page=1;$page<=100;$page++) {
            $data=$this->postJson('/outboundOrder/small/page',[
                'pageNum'=>$page,'pageSize'=>$pageSize,'status'=>0,
            ]);
            $rows=$data['records']??null;
            $total=$data['total']??null;
            if (!is_array($rows) || !is_numeric($total) || (int)$total<0) {
                throw new RuntimeException('OMS_WEB_DRAFT_PAGE_INVALID');
            }
            foreach ($rows as $row) {
                if (!is_array($row) || (string)($row['status']??'')!=='0') {
                    throw new RuntimeException('OMS_WEB_DRAFT_PAGE_INCONSISTENT');
                }
                $all[]=$row;
            }
            if (count($all)>=(int)$total) return $all;
            if (count($rows)===0) throw new RuntimeException('OMS_WEB_DRAFT_PAGE_INCOMPLETE');
        }
        throw new RuntimeException('OMS_WEB_DRAFT_PAGE_INCOMPLETE');
    }

    /** @param list<string> $references @return array<string,mixed>|null */
    public function findCurrentDraft(array $references): ?array
    {
        foreach ($this->currentDrafts() as $draft) {
            foreach (['referOrderNo','platformOrderNo','thirdOrderNo','referenceOrderNo'] as $field) {
                $value=trim((string)($draft[$field]??''));
                foreach ($references as $ref) {
                    if ($value===$ref || str_starts_with($value,$ref.'-')) return $draft;
                }
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    public function uploadPlaceholder(): array
    {
        $path=public_path('assets/xlwms-VOID-TEST-DO-NOT-SHIP.png');
        return $this->uploadAppendix($path);
    }

    /** @return array<string,mixed> */
    public function uploadAppendix(string $path): array
    {
        $this->assertConfigured();
        if (!is_file($path) || filesize($path) > 10*1024*1024) {
            throw new RuntimeException('OMS_WEB_VOID_PLACEHOLDER_INVALID');
        }
        $handle=fopen($path,'rb');
        if (!$handle) throw new RuntimeException('OMS_WEB_VOID_PLACEHOLDER_UNREADABLE');
        try {
            $response=Http::withToken($this->token)->acceptJson()
                ->withOptions(['allow_redirects'=>false,'verify'=>true])
                ->connectTimeout(5)->timeout($this->timeout())
                ->attach('file',$handle,'VOID_TEST_ONLY_NOT_USPS_DO_NOT_SHIP.png',
                    ['Content-Type'=>'image/png'])
                ->post($this->url('/appendix/upload'),[
                    'bizAppendixType'=>'1','bizCode'=>'outbound','bizNo'=>'',
                ]);
        } catch (\Throwable) {
            throw new RuntimeException('OMS_WEB_UPLOAD_TRANSPORT_UNCERTAIN');
        } finally {
            fclose($handle);
        }
        $data=$this->decode($response);
        if (!filled($data['fileName']??null)) {
            throw new RuntimeException('OMS_WEB_UPLOAD_RESPONSE_INVALID');
        }
        return $data;
    }

    /** Web native Save Draft, never createAndSubmit or OpenAPI CREATE.
     * @param array<string,mixed> $body @return array<string,mixed>
     */
    public function createDraft(array $body): array
    {
        if (($body['logisticsChannel']??'')!=='Upload_Shipping_Label'
            || strtolower((string)($body['logisticsCarrier']??''))!=='usps'
            || (int)($body['channelType']??0)!==3
            || !filled($body['appendixList'][0]['fileName']??null)
            || filled($body['trackNo']??null)) {
            throw new RuntimeException('OMS_WEB_USPS_DRAFT_PAYLOAD_INVALID');
        }
        return $this->postJson('/outboundOrder/create',$body);
    }

    /** @return array<string,mixed> */
    public function detail(string $orderNo): array
    {
        $this->assertConfigured();
        try {
            $response=Http::withToken($this->token)->acceptJson()
                ->withOptions(['allow_redirects'=>false,'verify'=>true])
                ->connectTimeout(5)->timeout($this->timeout())
                ->get($this->url('/outboundOrder/detail'),['outboundOrderNo'=>$orderNo]);
        } catch (\Throwable) {
            throw new RuntimeException('OMS_WEB_DETAIL_UNAVAILABLE');
        }
        return $this->decode($response);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private function postJson(string $path, array $body): array
    {
        $this->assertConfigured();
        $canonical=XlwmsWebTrackKey::canonicalJson($body);
        $signature=XlwmsWebTrackKey::sign($body,$this->hmacKey);
        try {
            $response=Http::withToken($this->token)->acceptJson()
                ->withHeaders(['Track-Key'=>$signature])
                ->withOptions(['allow_redirects'=>false,'verify'=>true])
                ->connectTimeout(5)->timeout($this->timeout())
                ->withBody($canonical,'application/json;charset=UTF-8')
                ->post($this->url($path));
        } catch (\Throwable) {
            throw new RuntimeException('OMS_WEB_TRANSPORT_UNCERTAIN');
        }
        return $this->decode($response);
    }

    /** @return array<string,mixed> */
    private function decode(Response $response): array
    {
        if ($response->status()===401) throw new RuntimeException('OMS_WEB_LOGIN_EXPIRED');
        if ($response->status()>=500) throw new RuntimeException('OMS_WEB_TRANSPORT_UNCERTAIN');
        if (!$response->successful()) throw new RuntimeException('OMS_WEB_HTTP_REJECTED');
        $json=$response->json();
        if (!is_array($json) || (int)($json['code']??-1)!==200) {
            throw new RuntimeException('OMS_WEB_BUSINESS_REJECTED');
        }
        return is_array($json['data']??null)?$json['data']:[];
    }

    private function assertConfigured(): void
    {
        if (!(bool) config('xlwms.oms_web_enabled',false)) throw new RuntimeException('OMS_WEB_DISABLED');
        if (trim($this->token)==='') throw new RuntimeException('OMS_WEB_TOKEN_MISSING');
        if (trim($this->hmacKey)==='') throw new RuntimeException('OMS_WEB_HMAC_KEY_MISSING');
    }
    private function timeout(): int { return (int)config('xlwms.oms_web_timeout_seconds',15); }
    private function url(string $path): string
    {
        $base=rtrim($this->baseUrl,'/');
        if ($base!=='https://oms.xlwms.com/gateway/woms') {
            throw new RuntimeException('OMS_WEB_HOST_NOT_APPROVED');
        }
        return $base.$path;
    }
}
