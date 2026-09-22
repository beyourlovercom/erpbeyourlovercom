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
            $normalized = preg_replace('/\s+/u', ' ', trim((string) $raw));
            $h = mb_strtolower($normalized ?? trim((string) $raw));
            if (in_array($h, ['reference order no./参考单号', 'reference order no.', '参考单号'], true)) {
                $out['order_reference'] = $index;
            } elseif (in_array($h, ['tracking no./物流跟踪号', 'tracking no.', '物流跟踪号'], true)) {
                $out['tracking_number'] = $index;
            } elseif (in_array($h, ['outbound order no./出库单号', 'outbound order no.', '出库单号'], true)) {
                $out['provider_order_reference'] = $index;
            } elseif ($h === 'carrier/承运商' || $h === 'carrier') {
                $out['carrier'] = $index;
            } elseif ($h === 'shipping service/物流渠道' || $h === 'shipping service' || $h === '物流渠道') {
                $out['provider_service'] = $index;
            } elseif ($h === 'status/状态' || $h === 'status') {
                $out['provider_status'] = $index;
            } elseif ($h === 'outboundtime/出库时间' || $h === 'outboundtime' || $h === '出库时间') {
                $out['shipped_at'] = $index;
            }
        }

        return $out;
    }
}
