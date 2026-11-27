<?php

namespace App\Modules\Logistics\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Initial reviewed scope: German domestic DHL. Other source scopes remain explicitly closed. */
final class LogisticsRateReviewService
{
    public function approveGermanyDomestic(int $versionId, string $sha256, string $evidence, int $actor): array
    {
        return DB::transaction(function () use ($versionId, $sha256, $evidence, $actor) {
            $seed = DB::table('logistics_rate_versions')->find($versionId);
            if (! $seed) {
                $this->reject('报价版本不存在。');
            }
            $book = DB::table('logistics_rate_books')->where('id', $seed->book_id)->lockForUpdate()->first();
            $version = DB::table('logistics_rate_versions')->where('id', $versionId)->lockForUpdate()->first();
            $latest = DB::table('logistics_rate_versions')->where('book_id', $book->id)->orderByDesc('version_number')->first();
            if ($book->key !== 'tianyun_de' || $version->status !== 'draft' || (int) $latest->id !== $versionId || ! $version->effective_from) {
                $this->reject('仅最新且已填写生效日的德国报价草稿可审核本地渠道。');
            }
            $source = DB::table('logistics_rate_source_files')->find($version->source_file_id);
            if (! $source || ! hash_equals($source->sha256, $sha256) || ! preg_match('~^logistics-rates/[a-f0-9]{64}\.xlsx$~D', $source->private_storage_path)) {
                $this->reject('原始文件身份不一致，请重新核验。');
            }
            $stream = Storage::disk('local')->readStream($source->private_storage_path);
            if (! is_resource($stream)) {
                $this->reject('无法核验原始报价文件。');
            }
            try {
                $ctx = hash_init('sha256');
                hash_update_stream($ctx, $stream);
                $actual = hash_final($ctx);
            } finally {
                fclose($stream);
            }
            if (! hash_equals($sha256, $actual)) {
                $this->reject('原始文件校验失败。');
            }
            if (mb_strlen(trim($evidence)) < 12 || mb_strlen($evidence) > 2000) {
                $this->reject('请填写有效供应商协议/核验依据。');
            }
            $components = DB::table('logistics_rate_components')->where('version_id', $versionId)->where('warehouse_code', 'DE')->where('carrier', 'DHL')
                ->where('service', 'domestic')->where('destination_country', 'DE')->orderBy('id')->lockForUpdate()->get();
            if ($components->count() !== 2 || $components->contains(fn ($c) => $c->destination_zone !== null || $c->review_status !== 'needs_review')) {
                $this->reject('德国本地费用范围不完整。');
            }
            $transport = $components->firstWhere('component_type', 'transport');
            $handling = $components->firstWhere('component_type', 'handling');
            if (! $transport || ! $handling || $transport->source_sheet !== 'DE-DHL （本地）' || $handling->source_sheet !== '库内基础服务费用') {
                $this->reject('缺少德国本地原始运费或出库费用。');
            }
            $rules = [];
            foreach ([$transport, $handling] as $component) {
                $rule = json_decode($component->rule_spec, true);
                $expected = $component->component_type === 'transport' ? ['basis' => 'actual', 'increment' => 1] : ['basis' => 'max', 'volume_unit' => 'cm3_kg'];
                if (! is_array($rule) || ($rule['coverage_complete'] ?? null) !== false) {
                    $this->reject('规则状态不符合审核要求。');
                }
                foreach ($expected as $key => $value) {
                    if (! array_key_exists($key, $rule) || $rule[$key] != $value) {
                        $this->reject('规则与已核验德国本地格式不符。');
                    }
                }
                if ($component->component_type === 'transport') {
                    if (! is_numeric($rule['fuel_percent'] ?? null) || (float) $rule['fuel_percent'] < 0 || (float) $rule['fuel_percent'] > 100 ||
                       ! is_numeric($rule['seasonal_fee'] ?? null) || (float) $rule['seasonal_fee'] < 0 || count($rule['seasonal_mmdd'] ?? []) !== 2 ||
                       ! is_numeric($rule['max_actual_kg'] ?? null) || (float) $rule['max_actual_kg'] <= 0) {
                        $this->reject('运费附加费或尺寸规则未核验。');
                    }
                } elseif (! is_numeric($rule['dim_divisor'] ?? null) || (float) $rule['dim_divisor'] <= 0) {
                    $this->reject('体积系数未核验。');
                }
                if ($component->currency !== 'EUR' || $component->weight_unit !== 'kg' || $component->pricing_mode !== 'fixed_band') {
                    $this->reject('币种或重量计价方式不符合报价。');
                }
                $bands = DB::table('logistics_rate_bands')->where('component_id', $component->id)->orderBy('sequence')->get();
                if ($bands->count() !== ($component->component_type === 'transport' ? 9 : 5)) {
                    $this->reject('重量费率档位不完整。');
                }
                $previous = 0.0;
                foreach ($bands as $band) {
                    if ($band->min_weight === null || $band->max_weight === null || (float) $band->min_weight !== $previous ||
                        (float) $band->max_weight <= $previous || $band->min_inclusive || ! $band->max_inclusive ||
                        ! is_numeric($band->fixed_amount) || (float) $band->fixed_amount <= 0) {
                        $this->reject('重量费率档位存在缺失、重复或冲突。');
                    }
                    if ($component->component_type === 'handling' && (! is_numeric($band->per_extra_item_amount) || (float) $band->per_extra_item_amount < 0)) {
                        $this->reject('出库额外件数费未核验。');
                    }
                    $previous = (float) $band->max_weight;
                }
                $rules[$component->id] = $rule;
            }
            // Preserve prior approved versions, but close their effective window when superseded.
            $previous = DB::table('logistics_rate_versions')->where('book_id', $book->id)->where('status', 'approved')->lockForUpdate()->get();
            $end = (new \DateTimeImmutable($version->effective_from))->modify('-1 day')->format('Y-m-d');
            foreach ($previous as $prior) {
                if (! $prior->effective_from || $version->effective_from <= $prior->effective_from) {
                    $this->reject('新报价生效日必须晚于既有批准版本。');
                }
                if (! $prior->effective_until || $prior->effective_until > $end) {
                    DB::table('logistics_rate_versions')->where('id', $prior->id)->update(['effective_until' => $end, 'updated_at' => now()]);
                }
            }
            foreach ($rules as $id => $rule) {
                $rule['coverage_complete'] = true;
                DB::table('logistics_rate_components')->where('id', $id)->update(['rule_spec' => json_encode($rule, JSON_THROW_ON_ERROR), 'review_status' => 'verified', 'updated_at' => now()]);
            }
            $notes = json_decode($version->notes ?? '{}', true) ?? [];
            $notes['approval'] = ['scope' => ['warehouse_code' => 'DE', 'carrier' => 'DHL', 'service' => 'domestic', 'country' => 'DE'],
                'evidence' => trim($evidence), 'source_sha256' => $sha256, 'approved_by' => $actor, 'approved_at' => now()->toIso8601String(),
                'coverage' => '常规合规包裹基础运输和出库费；不含包装材料、异常退件、未发生附加费等实际账单费用。'];
            DB::table('logistics_rate_versions')->where('id', $versionId)->update(['status' => 'approved', 'approved_by' => $actor, 'approved_at' => now(), 'notes' => json_encode($notes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'updated_at' => now()]);

            return ['version_id' => $versionId, 'status' => 'approved', 'scope' => $notes['approval']['scope']];
        }, 3);
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['version' => $message]);
    }
}
