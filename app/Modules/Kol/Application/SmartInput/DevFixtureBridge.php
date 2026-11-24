<?php

namespace App\Modules\Kol\Application\SmartInput;

use Illuminate\Support\Facades\Process;
use Throwable;

final class DevFixtureBridge
{
    public static function enabled(): bool
    {
        return app()->environment('local', 'testing') && config('kol_smart_input.dev_enabled') === true;
    }

    public static function fixtures(): array
    {
        if (!self::enabled()) return [];
        $fixtures = json_decode(file_get_contents(base_path('tools/byl-ai-worker-dev/fixtures.json')), true, 512, JSON_THROW_ON_ERROR);
        return array_map(fn ($f) => ['id' => $f['id'], 'text' => $f['text']], $fixtures);
    }

    public function suggest(string $id): array
    {
        $fallback = ['source' => 'local_rules', 'reason' => 'worker_unavailable'];
        if (!self::enabled() || !in_array($id, array_column(self::fixtures(), 'id'), true)) return $fallback;
        $node = config('kol_smart_input.node');
        $client = config('kol_smart_input.client');
        $key = config('kol_smart_input.key_path');
        if (!is_string($node) || !is_string($client) || !is_string($key) || !$node || !$client || !$key) return $fallback;
        try {
            $result = Process::timeout(55)->env(['BYL_AI_KEY_PATH' => $key])
                ->run([$node, $client, '--fixture', $id]);
            if (!$result->successful()) return $fallback;
            $payload = json_decode($result->output(), true, 32, JSON_THROW_ON_ERROR);
            $values = $payload['ai_suggestions'] ?? null;
            if (($payload['source'] ?? null) !== 'hybrid_review' || !is_array($values)) return $fallback;
            $fields = ['username','platform','followers','recipient_name','country','province_state','city','address_line_1','address_line_2','postal_code','email','phone','product_hint','coupon_code','tax_id'];
            if (count($values) !== count($fields)) return $fallback;
            foreach ($fields as $field) {
                if (!array_key_exists($field, $values)) return $fallback;
                $v = $values[$field];
                if ($v !== null && ($field === 'followers' ? (!is_int($v) || $v < 0) : (!is_string($v) || mb_strlen($v) > 1000))) return $fallback;
            }
            return ['source' => 'hybrid_review', 'ai_suggestions' => $values];
        } catch (Throwable) {
            // Do not log raw text, process output or key paths.
            return $fallback;
        }
    }
}
