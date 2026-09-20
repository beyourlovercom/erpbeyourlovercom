<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseRestockDocumentJob;
use App\Modules\Inventory\Infrastructure\Models\WarehouseRestockPlan;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WarehouseRestockDocumentJobService
{
    public const DOCUMENT_BLUE_LABELS = 'blue_labels';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    private const SCHEMA_VERSION = 'byl.blue-label.job.v1';
    private const TEMPLATE_VERSION = 'blue-label-picture-master-v1';
    private const GENERATOR_VERSION = 'blue-label-runtime-v1';
    private const DISPATCH_MODE = 'codex_dc_windows_bartender_v1';

    public function __construct(private readonly AuditRecorder $audit) {}

    /** @return array{job:WarehouseRestockDocumentJob,created:bool} */
    public function requestBlueLabels(
        Warehouse $warehouse,
        int $planId,
        ?Authenticatable $actor,
    ): array {
        return DB::transaction(function () use ($warehouse, $planId, $actor): array {
            $plan = WarehouseRestockPlan::query()
                ->where('warehouse_id', $warehouse->id)
                ->whereKey($planId)
                ->lockForUpdate()
                ->first();

            if ($plan === null || $plan->status !== 'confirmed') {
                throw ValidationException::withMessages([
                    'blue_label_job' => '备货计划不存在、仓库不匹配或尚未确认。',
                ]);
            }

            $existing = WarehouseRestockDocumentJob::query()
                ->where('restock_plan_id', $plan->id)
                ->where('document_kind', self::DOCUMENT_BLUE_LABELS)
                ->first();

            if ($existing !== null) {
                return ['job' => $existing, 'created' => false];
            }

            $items = $plan->items()->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'blue_label_job' => '备货计划没有可生成蓝标的冻结明细。',
                ]);
            }

            $payloadItems = [];
            $seenLabelSkus = [];

            foreach ($items as $item) {
                $productSku = trim((string) $item->product_sku);
                $labelSku = trim((string) $item->label_sku);
                $description = trim((string) $item->label_description);
                $quantity = (int) $item->final_restock_qty;

                if ($productSku === '' || $labelSku === '' || $description === '' || $quantity <= 0) {
                    throw ValidationException::withMessages([
                        'blue_label_job' => '备货计划存在不完整的蓝标冻结字段，不能进入执行队列。',
                    ]);
                }

                if (! preg_match('/^[A-Za-z0-9._-]+$/', $labelSku)) {
                    throw ValidationException::withMessages([
                        'blue_label_job' => '蓝标 SKU 不符合生成规则：'.$labelSku,
                    ]);
                }

                if (isset($seenLabelSkus[$labelSku])) {
                    throw ValidationException::withMessages([
                        'blue_label_job' => '备货计划存在重复蓝标 SKU：'.$labelSku,
                    ]);
                }
                $seenLabelSkus[$labelSku] = true;

                $payloadItems[] = [
                    'product_sku' => $productSku,
                    'label_sku' => $labelSku,
                    'label_description' => $description,
                    'restock_qty' => $quantity,
                ];
            }

            $warehouseCode = strtoupper((string) preg_replace(
                '/[^A-Z0-9]+/i',
                '',
                (string) $warehouse->code,
            ));
            if ($warehouseCode === '') $warehouseCode = 'WAREHOUSE';

            $reference = sprintf(
                'BLJ-%s-%s-%s',
                $warehouseCode,
                now()->format('Ymd'),
                substr((string) Str::ulid(), -10),
            );

            $payload = [
                'schema_version' => self::SCHEMA_VERSION,
                'job_id' => $reference,
                'plan_reference' => (string) $plan->reference,
                'plan_snapshot_checksum' => (string) $plan->snapshot_checksum,
                'template_version' => self::TEMPLATE_VERSION,
                'items' => $payloadItems,
            ];
            $payloadChecksum = $this->checksum($payload);

            $job = WarehouseRestockDocumentJob::query()->create([
                'warehouse_id' => $warehouse->id,
                'restock_plan_id' => $plan->id,
                'reference' => $reference,
                'document_kind' => self::DOCUMENT_BLUE_LABELS,
                'status' => self::STATUS_QUEUED,
                'schema_version' => self::SCHEMA_VERSION,
                'template_version' => self::TEMPLATE_VERSION,
                'generator_version' => self::GENERATOR_VERSION,
                'dispatch_mode' => self::DISPATCH_MODE,
                'plan_snapshot_checksum' => (string) $plan->snapshot_checksum,
                'payload_checksum' => $payloadChecksum,
                'payload' => $payload,
                'attempt_count' => 0,
                'requested_by' => $actor?->getAuthIdentifier(),
                'requested_at' => now(),
            ]);

            $this->audit->record(
                $actor
                    ? AuditActor::user($actor, AuditSource::Web)
                    : AuditActor::system(AuditSource::InternalSystem),
                'inventory.restock_document_job.created',
                $job::class,
                $job->id,
                after: [
                    'reference' => $job->reference,
                    'warehouse_id' => (int) $warehouse->id,
                    'restock_plan_id' => (int) $plan->id,
                    'plan_reference' => (string) $plan->reference,
                    'document_kind' => self::DOCUMENT_BLUE_LABELS,
                    'status' => self::STATUS_QUEUED,
                    'payload_checksum' => $payloadChecksum,
                    'items' => count($payloadItems),
                ],
                reason: 'inventory_restock_blue_label_job_created',
            );

            return ['job' => $job, 'created' => true];
        });
    }

    public function forPlanId(?int $planId): ?array
    {
        if (! $planId) return null;

        $job = WarehouseRestockDocumentJob::query()
            ->where('restock_plan_id', $planId)
            ->where('document_kind', self::DOCUMENT_BLUE_LABELS)
            ->first();

        return $job ? $this->payload($job) : null;
    }

    public function payload(WarehouseRestockDocumentJob $job): array
    {
        return [
            'id' => (int) $job->id,
            'reference' => $job->reference,
            'document_kind' => $job->document_kind,
            'status' => $job->status,
            'status_label' => $this->statusLabel($job->status),
            'status_detail' => $this->statusDetail($job->status),
            'attempt_count' => (int) $job->attempt_count,
            'requested_at' => $job->requested_at?->toIso8601String(),
            'started_at' => $job->started_at?->toIso8601String(),
            'completed_at' => $job->completed_at?->toIso8601String(),
            'failed_at' => $job->failed_at?->toIso8601String(),
            'last_error_message' => $job->last_error_message,
            'is_terminal' => in_array(
                $job->status,
                [self::STATUS_SUCCEEDED, self::STATUS_FAILED],
                true,
            ),
            'artifact_count' => is_array($job->result_manifest)
                ? count((array) ($job->result_manifest['artifacts'] ?? []))
                : 0,
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_QUEUED => '已排队',
            self::STATUS_CLAIMED => '执行节点已领取',
            self::STATUS_RUNNING => '生成中',
            self::STATUS_SUCCEEDED => '已完成',
            self::STATUS_FAILED => '失败',
            default => $status,
        };
    }

    private function statusDetail(string $status): string
    {
        return match ($status) {
            self::STATUS_QUEUED => '等待公司 Windows 执行节点领取',
            self::STATUS_CLAIMED => '任务已被执行节点锁定',
            self::STATUS_RUNNING => '正在生成 BTW / PDF',
            self::STATUS_SUCCEEDED => '蓝标文件已生成',
            self::STATUS_FAILED => '任务失败，等待人工检查或后续重试',
            default => '状态待确认',
        };
    }

    private function checksum(array $payload): string
    {
        return hash('sha256', json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR,
        ));
    }
}
