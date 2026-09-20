<?php

namespace App\Modules\Logistics\Application\OverseaFiles;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

final class WmsTrackingReturnReader
{
    public function __construct(private readonly ProviderXlsxTableReader $xlsx) {}

    /** @return list<array<string,mixed>> */
    public function read(UploadedFile $file): array
    {
        $table = $this->xlsx->read($file);
        $map = $this->map($table['headers']);
        foreach (['order_reference', 'tracking_number'] as $required) {
            if (! isset($map[$required])) {
                throw new InvalidArgumentException('WMS 回传文件缺少 Reference order No. 或 Tracking No.。');
            }
        }

        $out = [];
        foreach ($table['rows'] as $source) {
            $get = fn (string $key): string => isset($map[$key]) ? trim((string) ($source['cells'][$map[$key]] ?? '')) : '';
            $reference = $get('order_reference');
            $tracking = $get('tracking_number');
            if ($reference === '' && $tracking === '') {
                continue;
            }
            $out[] = [
                'row' => $source['row'],
                'order_reference' => $reference,
                'provider_order_reference' => $get('provider_order_reference') ?: null,
                'tracking_number' => $tracking,
                'carrier' => $get('carrier') ?: null,
                'provider_service' => $get('provider_service') ?: null,
                'provider_status' => $get('provider_status') ?: null,
                'shipped_at' => $get('shipped_at') ?: null,
                'parse_error' => null,
            ];
        }

        return $out;
    }

    /** @param list<string> $headers @return array<string,int> */
    private function map(array $headers): array
    {
        $out = [];
        foreach ($headers as $index => $raw) {
            $h = mb_strtolower(trim((string) $raw));
            if (str_contains($h, 'reference order no.') || str_contains($h, '参考单号')) {
                $out['order_reference'] = $index;
            } elseif (str_contains($h, 'tracking no.') || str_contains($h, '物流跟踪号')) {
                $out['tracking_number'] = $index;
            } elseif (str_contains($h, 'outbound order no.') || str_contains($h, '出库单号')) {
                $out['provider_order_reference'] = $index;
            } elseif ($h === 'carrier/承运商' || $h === 'carrier') {
                $out['carrier'] = $index;
            } elseif (str_contains($h, 'shipping service') || str_contains($h, '物流渠道')) {
                $out['provider_service'] = $index;
            } elseif ($h === 'status/状态' || $h === 'status') {
                $out['provider_status'] = $index;
            } elseif (str_contains($h, 'outboundtime') || str_contains($h, '出库时间')) {
                $out['shipped_at'] = $index;
            }
        }

        return $out;
    }
}
