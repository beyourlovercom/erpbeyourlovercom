<?php

namespace App\Modules\Orders\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One request-scoped display catalog. Names and sequence may be customized;
 * command codes, source-of-truth status and permissions may not.
 */
final class OrderWorkflowStatusDisplayCatalog
{
    private ?array $resolved = null;

    /** @return array<string,array<string,mixed>> */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $registry = OrderWorkflowStatus::defaults();
        if (Schema::hasTable('order_workflow_status_display_settings')) {
            foreach (DB::table('order_workflow_status_display_settings')->get() as $setting) {
                if (! isset($registry[$setting->code])) {
                    continue;
                }
                $registry[$setting->code]['sequence'] = $setting->sequence;
                $registry[$setting->code]['zh'] = $setting->name_zh;
                $registry[$setting->code]['en'] = $setting->name_en;
            }
        }
        uasort($registry, static fn (array $left, array $right): int => self::compareSequence($left['sequence'], $right['sequence']));

        return $this->resolved = $registry;
    }

    public function clear(): void
    {
        $this->resolved = null;
    }

    /**
     * Compare stage segments as integers, not strings or floats:
     * 1.2 precedes 1.10. Input validation protects the persisted format.
     */
    public static function compareSequence(string $left, string $right): int
    {
        $a = array_map('intval', explode('.', $left));
        $b = array_map('intval', explode('.', $right));
        foreach (range(0, max(count($a), count($b)) - 1) as $index) {
            $difference = ($a[$index] ?? 0) <=> ($b[$index] ?? 0);
            if ($difference !== 0) {
                return $difference;
            }
        }

        return strcmp($left, $right);
    }

    /** @return list<array<string,mixed>> */
    public function rows(): array
    {
        $kolPhases = ['resent', 'returned', 'shared', 'creating', 'published', 'paying', 'paid', 'cancel', 'refunded'];
        $systemFacts = ['allocated', 'partially_shipped', 'sent', 'shipping', 'arrived', 'archived', 'progress_conflict'];
        $rows = [];
        foreach ($this->all() as $code => $definition) {
            $scope = in_array($code, $kolPhases, true) ? '网红订单及详情'
                : (in_array($code, $systemFacts, true) ? '订单列表、详情、物流工作台（系统事实投影）'
                    : '订单总表、客户/网红订单、详情、物流工作台、归档及财务分析');
            $rows[] = [
                'code' => $code,
                'sequence' => $definition['sequence'],
                'name_zh' => $definition['zh'],
                'name_en' => $definition['en'],
                'mutable' => $definition['mutable'],
                'feature' => $definition['mutable'] ? '人工工作状态（实际是否可选取决于订单及账号）'
                    : '系统业务事件投影（不能靠改名生成发货、签收或支付记录）',
                'pages' => $scope,
            ];
        }

        return $rows;
    }

    public function version(): string
    {
        $data = [];
        foreach ($this->all() as $code => $definition) {
            $data[$code] = [$definition['sequence'], $definition['zh'], $definition['en']];
        }
        ksort($data);

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
