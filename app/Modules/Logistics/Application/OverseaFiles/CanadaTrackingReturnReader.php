<?php

namespace App\Modules\Logistics\Application\OverseaFiles;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

final class CanadaTrackingReturnReader
{
    public function __construct(private readonly ProviderXlsxTableReader $xlsx) {}

    /** @return list<array<string,mixed>> */
    public function read(UploadedFile $file): array
    {
        $table = $this->xlsx->read($file);
        $map = $this->map($table['headers']);
        foreach (['order_reference', 'tracking_number'] as $required) {
            if (! isset($map[$required])) {
                throw new InvalidArgumentException('加拿大回传文件缺少订单号或物流单号列。');
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
                'provider_order_reference' => $get('package_reference') ?: null,
                'tracking_number' => $tracking,
                'carrier' => null,
                'provider_service' => null,
                'provider_status' => null,
                'shipped_at' => null,
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
            if ($h === '订单号') {
                $out['order_reference'] = $index;
            } elseif ($h === '包裹序号') {
                $out['package_reference'] = $index;
            } elseif ($h === 'com_lab_3015') {
                $out['tracking_number'] = $index;
            }
        }

        return $out;
    }
}
