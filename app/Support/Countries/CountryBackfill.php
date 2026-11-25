<?php

namespace App\Support\Countries;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CountryBackfill
{
    public const FIELDS = [
        'companies' => ['country_code'],
        'customer_addresses' => ['country_code'],
        'customers' => ['country_code'],
        'kols' => ['country_code', 'shipping_country'],
        'marketing_subscribers' => ['country_code'],
        'order_addresses' => ['country'],
        'products' => ['country_of_origin'],
        'warehouse_service_regions' => ['country_code'],
        'warehouses' => ['country_code'],
    ];

    public function plan(bool $lock = false): array
    {
        $summary = $changes = $unresolved = [];
        foreach (self::FIELDS as $table => $fields) {
            foreach ($fields as $field) {
                $key = $table.'.'.$field;
                $counts = ['standard' => 0, 'convert' => 0, 'unresolved' => 0, 'blank' => 0];
                $query = DB::table($table)->select('id', $field)->orderBy('id');
                if ($lock) $query->lockForUpdate();
                foreach ($query->cursor() as $row) {
                    $raw = $row->$field;
                    if ($raw === null || trim($raw) === '') { $counts['blank']++; continue; }
                    $code = CountryStandard::resolve($raw);
                    if ($code === null) {
                        $counts['unresolved']++;
                        $unresolved[$key][$raw] = ($unresolved[$key][$raw] ?? 0) + 1;
                    } elseif ($code === $raw) {
                        $counts['standard']++;
                    } else {
                        $counts['convert']++;
                        $changes[] = ['table' => $table, 'field' => $field, 'id' => (int) $row->id, 'before' => $raw, 'after' => $code];
                    }
                }
                $summary[$key] = $counts;
            }
        }
        return ['summary' => $summary, 'unresolved' => $unresolved, 'changes' => $changes,
            'fingerprint' => hash('sha256', json_encode([$changes, $unresolved], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))];
    }

    public function apply(string $expected): array
    {
        return DB::transaction(function () use ($expected) {
            $plan = $this->plan(true);
            if (!hash_equals($plan['fingerprint'], $expected)) throw new RuntimeException('国家字段预检已变化，必须重新核对。');
            foreach ($plan['changes'] as $change) {
                // Only the country field changes; preserve original timestamps, order state and existing audit history.
                DB::table($change['table'])->where('id', $change['id'])->update([$change['field'] => $change['after']]);
                DB::table('audit_records')->insert([
                    'actor_id' => null, 'action' => 'country.standardized',
                    'entity_type' => $change['table'], 'entity_id' => (string) $change['id'],
                    'before' => json_encode([$change['field'] => $change['before']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'after' => json_encode([$change['field'] => $change['after']], JSON_THROW_ON_ERROR),
                    'source' => 'system', 'reason' => 'erp_global_country_standard_v1', 'created_at' => now(),
                ]);
            }
            return $plan;
        });
    }
}
