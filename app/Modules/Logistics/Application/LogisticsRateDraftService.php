<?php

namespace App\Modules\Logistics\Application;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class LogisticsRateDraftService
{
    private const BOOKS = [
        'speeda_us' => ['速达', '美国海外仓报价'],
        'tianyun_de' => ['天运', '德国海外仓报价'],
        'speeda_sd' => ['速达', '国内小包报价'],
        'territory_evidence' => ['原始地区资料', '非欧盟地区例外'],
    ];

    public function __construct(private readonly LogisticsRateWorkbookParser $parser) {}

    private function parse(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if (! $file->isValid() || ! $path || $file->getSize() <= 0 || $file->getSize() > 16 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => '无效或超过16MB的文件。']);
        }
        try {
            // Limit expanded XLSX bytes before PhpSpreadsheet reads worksheets.
            if (strtolower($file->getClientOriginalExtension()) === 'xlsx') {
                $zip = new \ZipArchive;
                if ($zip->open($path) !== true) {
                    throw new RuntimeException('无效工作簿');
                }
                try {
                    $total = 0;
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $stat = $zip->statIndex($i);
                        $total += (int) ($stat['size'] ?? 0);
                        if ($total > 160 * 1024 * 1024 || $zip->numFiles > 3000) {
                            throw new RuntimeException('工作簿解压体积超过安全上限');
                        }
                    }
                } finally {
                    $zip->close();
                }
            }
            $parsed = $this->parser->parse($path);
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['file' => '无法安全识别报价内容，未导入。请检查原始 Excel 格式及工作表。']);
        }
        if (count($parsed['components']) > 5000 || count($parsed['zones']) > 30000) {
            throw ValidationException::withMessages(['file' => '报价数据超出单次导入安全上限。']);
        }

        return $parsed + ['sha256' => hash_file('sha256', $path)];
    }

    public function preview(UploadedFile $file): array
    {
        $parsed = $this->parse($file);
        $book = DB::table('logistics_rate_books')->where('key', $parsed['book_key'])->first();
        $version = $book ? DB::table('logistics_rate_versions')->where('book_id', $book->id)->orderByDesc('version_number')->first() : null;

        return $this->summary($parsed, $version?->id);
    }

    private function summary(array $parsed, ?int $previous): array
    {
        $old = [];
        if ($previous) {
            $components = DB::table('logistics_rate_components')->where('version_id', $previous)->get();
            $bands = DB::table('logistics_rate_bands')->whereIn('component_id', $components->pluck('id'))->orderBy('sequence')->get()->groupBy('component_id');
            foreach ($components as $c) {
                $key = 'component:'.$c->component_key;
                $old[$key] = $this->componentValue((array) $c);
                foreach ($bands->get($c->id, collect()) as $b) {
                    $old[$key.':band:'.$b->sequence] = $this->bandValue((array) $b);
                }
            }
            foreach (DB::table('logistics_rate_zones')->where('version_id', $previous)->get() as $z) {
                $old['zone:'.$z->zone_key] = $this->zoneValue((array) $z);
            }
        }
        $new = [];
        foreach ($parsed['components'] as $c) {
            $key = 'component:'.$c['component_key'];
            $new[$key] = $this->componentValue($c);
            foreach ($c['bands'] as $b) {
                $new[$key.':band:'.$b['sequence']] = $this->bandValue($b);
            }
        }
        foreach ($parsed['zones'] as $z) {
            $new['zone:'.$z['zone_key']] = $this->zoneValue($z);
        }
        $counts = ['added' => 0, 'changed' => 0, 'removed' => 0, 'unchanged' => 0];
        $changes = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $key) {
            $kind = ! isset($old[$key]) ? 'added' : (! isset($new[$key]) ? 'removed' : ($this->canonical($old[$key]) !== $this->canonical($new[$key]) ? 'changed' : 'unchanged'));
            $counts[$kind]++;
            if ($kind !== 'unchanged' && count($changes) < 250) {
                $changes[] = ['id' => $key, 'kind' => $kind, 'old' => $old[$key] ?? null, 'new' => $new[$key] ?? null];
            }
        }

        // Supplier quotes remain draft, no approval from a partial parse.
        return ['book_key' => $parsed['book_key'], 'sha256' => $parsed['sha256'], 'previous_version_id' => $previous,
            'sheets' => $parsed['sheets'], 'parsed_sheets' => $parsed['parsed_sheets'], 'warnings' => $parsed['warnings'],
            'component_count' => count($parsed['components']), 'band_count' => array_sum(array_map(fn ($c) => count($c['bands']), $parsed['components'])),
            'zone_count' => count($parsed['zones']), 'counts' => $counts, 'changes' => $changes, 'change_sample_limit' => 250,
            'approval_allowed' => false];
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX ? (int) $value : $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map($this->canonical(...), $value);
    }

    private function componentValue(array $row): array
    {
        $sourceRule = is_array($row['rule_spec']) ? $row['rule_spec'] : json_decode($row['rule_spec'], true);
        if (is_array($sourceRule)) {
            $sourceRule['coverage_complete'] = false;
        } // Approval state is not a supplier price change.

        return ['warehouse' => $row['warehouse_code'], 'carrier' => $row['carrier'], 'service' => $row['service'], 'country' => $row['destination_country'],
            'zone' => $row['destination_zone'], 'type' => $row['component_type'], 'currency' => $row['currency'], 'unit' => $row['weight_unit'],
            'mode' => $row['pricing_mode'], 'rule' => $sourceRule,
            'sheet' => $row['source_sheet'], 'cells' => $row['source_cells']];
    }

    private function bandValue(array $row): array
    {
        $number = fn ($v) => $v === null ? null : number_format((float) $v, 6, '.', '');

        return ['min' => $number($row['min_weight']), 'max' => $number($row['max_weight']), 'min_inclusive' => (bool) $row['min_inclusive'],
            'max_inclusive' => (bool) $row['max_inclusive'], 'fixed' => $number($row['fixed_amount']), 'per_weight' => $number($row['per_weight_amount']),
            'per_extra_item' => $number($row['per_extra_item_amount']), 'cells' => $row['source_cells'],
            'source_values' => is_array($row['raw_values'] ?? null) ? $row['raw_values'] : json_decode($row['raw_values'] ?? 'null', true)];
    }

    private function zoneValue(array $row): array
    {
        return ['warehouse' => $row['warehouse_code'], 'carrier' => $row['carrier'], 'service' => $row['service'], 'country' => $row['destination_country'],
            'type' => $row['postal_match_type'], 'start' => $row['postal_start'], 'end' => $row['postal_end'], 'zone' => $row['zone_label'],
            'sheet' => $row['source_sheet'], 'cells' => $row['source_cells']];
    }

    public function save(UploadedFile $file, string $expectedHash, ?int $expectedVersion, ?string $effectiveFrom, int $actor): array
    {
        $parsed = $this->parse($file);
        if (! hash_equals($parsed['sha256'], $expectedHash)) {
            throw ValidationException::withMessages(['file' => '文件已变化，请重新预览。']);
        }
        $sha = $parsed['sha256'];
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['xls', 'xlsx'], true)) {
            throw ValidationException::withMessages(['file' => '只支持 XLS/XLSX。']);
        }
        $storagePath = 'logistics-rates/'.$sha.'.'.$ext;
        $disk = Storage::disk('local');
        // Content addressed private file; no user supplied directory/name is used.
        if (! $disk->exists($storagePath)) {
            $stream = fopen($file->getRealPath(), 'rb');
            try {
                if (! $disk->put($storagePath, $stream, ['visibility' => 'private'])) {
                    throw new RuntimeException('报价原文件保存失败');
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
        $stored = $disk->readStream($storagePath);
        if (! is_resource($stored)) {
            throw new RuntimeException('报价原文件读取失败');
        }
        try {
            $ctx = hash_init('sha256');
            hash_update_stream($ctx, $stored);
            $actual = hash_final($ctx);
        } finally {
            fclose($stored);
        }
        if (! hash_equals($sha, $actual)) {
            throw new RuntimeException('报价文件完整性检查失败');
        }

        return DB::transaction(function () use ($parsed, $expectedVersion, $effectiveFrom, $actor, $file, $sha, $storagePath) {
            [$supplier,$name] = self::BOOKS[$parsed['book_key']];
            DB::table('logistics_rate_books')->insertOrIgnore(['key' => $parsed['book_key'], 'supplier_name' => $supplier, 'display_name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            $book = DB::table('logistics_rate_books')->where('key', $parsed['book_key'])->lockForUpdate()->first();
            $source = DB::table('logistics_rate_source_files')->where('sha256', $sha)->first();
            if ($source) {
                $duplicate = DB::table('logistics_rate_versions')->where('book_id', $book->id)->where('source_file_id', $source->id)->first();
                if ($duplicate) {
                    return ['version_id' => $duplicate->id, 'duplicate' => true];
                }
            }
            $latest = DB::table('logistics_rate_versions')->where('book_id', $book->id)->orderByDesc('version_number')->first();
            if (($latest?->id === null ? null : (int) $latest->id) !== $expectedVersion) {
                throw ValidationException::withMessages(['file' => '报价版本已变化，请重新预览。']);
            }
            if (! $source) {
                DB::table('logistics_rate_source_files')->insertOrIgnore(['sha256' => $sha, 'original_filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                    'private_storage_path' => $storagePath, 'mime_type' => $file->getMimeType() ?? 'application/octet-stream', 'byte_size' => $file->getSize(),
                    'uploaded_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
                $source = DB::table('logistics_rate_source_files')->where('sha256', $sha)->first();
            }
            $version = DB::table('logistics_rate_versions')->insertGetId(['book_id' => $book->id, 'source_file_id' => $source->id,
                'version_number' => ($latest?->version_number ?? 0) + 1, 'status' => 'draft', 'effective_from' => $effectiveFrom,
                'notes' => json_encode(['sheets' => $parsed['sheets'], 'parsed_sheets' => $parsed['parsed_sheets'], 'warnings' => $parsed['warnings']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now()]);
            foreach ($parsed['components'] as $c) {
                $bands = $c['bands'];
                unset($c['bands']);
                $c['rule_spec'] = json_encode($c['rule_spec'], JSON_THROW_ON_ERROR);
                $cid = DB::table('logistics_rate_components')->insertGetId(['version_id' => $version, 'created_at' => now(), 'updated_at' => now(), ...$c]);
                foreach ($bands as &$band) {
                    $band['component_id'] = $cid;
                    $band['raw_values'] = json_encode($band['raw_values'], JSON_THROW_ON_ERROR);
                }unset($band);
                foreach (array_chunk($bands, 200) as $chunk) {
                    DB::table('logistics_rate_bands')->insert($chunk);
                }
            }
            foreach (array_chunk($parsed['zones'], 200) as $chunk) {
                DB::table('logistics_rate_zones')->insert(array_map(fn ($z) => ['version_id' => $version, 'created_at' => now(), 'updated_at' => now(), ...$z], $chunk));
            }

            return ['version_id' => $version, 'duplicate' => false];
        }, 3);
    }
}
