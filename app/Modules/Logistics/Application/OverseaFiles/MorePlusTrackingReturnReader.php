<?php

namespace App\Modules\Logistics\Application\OverseaFiles;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

final class MorePlusTrackingReturnReader
{
    public function __construct(private readonly ProviderXlsxTableReader $xlsx) {}

    /** @return list<array<string,mixed>> */
    public function read(UploadedFile $file): array
    {
        $table = $this->xlsx->read($file);
        $map = $this->map($table['headers']);
        foreach (['order_reference', 'master_tracking', 'tracking_number'] as $required) {
            if (! isset($map[$required])) {
                throw new InvalidArgumentException('MorePlus 回传文件缺少 Order# / Master Tracking# / Tracking#。');
            }
        }

        $out = [];
        foreach ($table['rows'] as $source) {
            $get = fn (string $key): string => isset($map[$key]) ? trim((string) ($source['cells'][$map[$key]] ?? '')) : '';
            $reference = $get('order_reference');
            if ($reference === '') {
                continue;
            }
            $master = $get('master_tracking');
            $tracking = $get('tracking_number');
            $error = null;
            if ($master !== '' && $tracking !== '' && strcasecmp($master, $tracking) !== 0) {
                $error = 'Master Tracking# 与 Tracking# 不一致';
            }
            $resolved = $tracking !== '' ? $tracking : $master;
            $out[] = [
                'row' => $source['row'],
                'order_reference' => $reference,
                'provider_order_reference' => $get('provider_order_reference') ?: null,
                'tracking_number' => $resolved,
                'carrier' => $get('carrier') ?: null,
                'provider_service' => $get('provider_service') ?: null,
                'provider_status' => null,
                'shipped_at' => $get('shipped_at') ?: null,
                'parse_error' => $error,
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
            if ($h === 'order#') {
                $out['order_reference'] = $index;
            } elseif ($h === 'outbound order#') {
                $out['provider_order_reference'] = $index;
            } elseif ($h === 'master tracking#') {
                $out['master_tracking'] = $index;
            } elseif ($h === 'tracking#') {
                $out['tracking_number'] = $index;
            } elseif ($h === 'carrier') {
                $out['carrier'] = $index;
            } elseif ($h === 'ship via') {
                $out['provider_service'] = $index;
            } elseif ($h === 'shipped time') {
                $out['shipped_at'] = $index;
            }
        }

        return $out;
    }
}
