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
            $status = $get('provider_status');
            $cancelStatus = $get('cancel_status');
            $out[] = [
                'row' => $source['row'],
                'order_reference' => $reference,
                'provider_order_reference' => $get('provider_order_reference') ?: null,
                'tracking_number' => $resolved,
                'carrier' => $get('carrier') ?: null,
                'provider_service' => $get('provider_service') ?: null,
                'provider_status' => $status ?: null,
                'provider_warehouse' => $get('provider_warehouse') ?: null,
                'tracking_status' => null,
                'package_tracking' => null,
                'return_tracking' => $get('return_tracking') ?: null,
                'cancelled' => $this->cancelled($status, $cancelStatus),
                'cancellation_reason' => $get('cancellation_reason') ?: null,
                'shipped_at' => $get('shipped_at') ?: null,
                'provider_evidence' => $this->evidence($table['headers'], (array) $source['cells']),
                'parse_error' => $error,
            ];
        }

        return $out;
    }

    /** @param list<string> $headers @param list<mixed> $cells @return array<string,string> */
    private function evidence(array $headers, array $cells): array
    {
        $out = [];
        foreach ($headers as $index => $header) {
            $key = trim((string) $header);
            if ($key !== '') {
                $out[$key] = trim((string) ($cells[$index] ?? ''));
            }
        }

        return $out;
    }

    private function cancelled(string $status, string $cancelStatus): bool
    {
        foreach ([$status, $cancelStatus] as $value) {
            $value = mb_strtolower(trim($value));
            if ($value === '') {
                continue;
            }
            if (in_array($value, ['cancel', 'cancelled', 'canceled', 'yes', 'true', '1', '取消', '已取消'], true)
                || str_contains($value, 'cancelled') || str_contains($value, 'canceled')) {
                return true;
            }
        }

        return false;
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
            } elseif ($h === 'warehouse') {
                $out['provider_warehouse'] = $index;
            } elseif ($h === 'status') {
                $out['provider_status'] = $index;
            } elseif ($h === 'cancel status') {
                $out['cancel_status'] = $index;
            } elseif ($h === 'reason for cancellation') {
                $out['cancellation_reason'] = $index;
            } elseif ($h === 'return order tracking#') {
                $out['return_tracking'] = $index;
            } elseif ($h === 'ship via') {
                $out['provider_service'] = $index;
            } elseif ($h === 'shipped time') {
                $out['shipped_at'] = $index;
            }
        }

        return $out;
    }
}
