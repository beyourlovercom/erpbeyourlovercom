<?php

namespace App\Modules\B2B\Sales;

use App\Modules\B2B\Infrastructure\Models\B2BCase;
use App\Modules\B2B\Infrastructure\Models\B2BOrganization;
use App\Modules\B2B\Infrastructure\Models\B2BSalesOpportunity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class B2BSalesPipeline
{
    /** YBY SalesOpportunityService::ROADMAP_STAGES — preserve stages and probability contract. */
    public const STAGES = ['discovery', 'qualified', 'solution', 'quoted', 'negotiating'];
    public const LABELS = [
        'discovery' => '初步接洽',
        'qualified' => '已确认需求',
        'solution' => '方案阶段',
        'quoted' => '已报价',
        'negotiating' => '谈判中',
        'won' => '成交',
        'lost' => '丢单',
    ];
    public const PROBABILITIES = [
        'discovery' => 1000,
        'qualified' => 3000,
        'solution' => 5000,
        'quoted' => 7000,
        'negotiating' => 9000,
        'won' => 10000,
        'lost' => 0,
    ];

    public function create(array $values, int $actorId): B2BSalesOpportunity
    {
        $org = B2BOrganization::query()->findOrFail((int) $values['organization_id']);
        if ($org->archived_at !== null) {
            throw ValidationException::withMessages(['organization_id' => '该企业已归档']);
        }

        $case = null;
        if (! empty($values['case_id'])) {
            $case = B2BCase::query()->where('organization_id', $org->id)
                ->whereKey((int) $values['case_id'])->first();
            if (! $case || $case->case_status !== 'open') {
                throw ValidationException::withMessages(['case_id' => '只有该企业的开放询盘可以转成商机']);
            }
        }
        $contactId = $values['contact_id'] ?? $case?->primary_contact_id;
        if ($contactId !== null && ! $org->contacts()->whereKey((int) $contactId)->exists()) {
            throw ValidationException::withMessages(['contact_id' => '联系人不属于选定企业']);
        }
        $stage = $values['pipeline_stage'] ?? 'discovery';
        if (! in_array($stage, self::STAGES, true)) {
            throw ValidationException::withMessages(['pipeline_stage' => '不支持该销售阶段']);
        }

        return DB::transaction(function () use ($org, $case, $contactId, $stage, $values, $actorId): B2BSalesOpportunity {
            if ($case) {
                $existing = B2BSalesOpportunity::query()->where('case_id', $case->id)->first();
                if ($existing) {
                    return $existing;
                }
            }
            $row = B2BSalesOpportunity::query()->create([
                'opportunity_number' => 'B2B-OPP-'.strtoupper(bin2hex(random_bytes(7))),
                'organization_id' => $org->id,
                'case_id' => $case?->id,
                'contact_id' => $contactId,
                'owner_id' => $values['owner_id'] ?? $case?->owner_id ?? $org->owner_id ?? $actorId,
                'title' => trim((string) $values['title']),
                'status' => 'open',
                'pipeline_stage' => $stage,
                'probability_bps' => self::PROBABILITIES[$stage],
                'currency' => strtoupper((string) ($values['currency'] ?? 'USD')),
                'expected_value_micros' => $values['expected_value_micros'] ?? null,
                'expected_close_date' => $values['expected_close_date'] ?? null,
                'next_follow_up_at' => $values['next_follow_up_at'] ?? null,
            ]);
            $this->record($row, null, $stage, $actorId, 'opportunity_created');
            return $row;
        });
    }

    public function advance(B2BSalesOpportunity $row, string $stage, int $actorId): B2BSalesOpportunity
    {
        if (! in_array($stage, self::STAGES, true) || $row->status !== 'open') {
            throw ValidationException::withMessages(['pipeline_stage' => '商机已结束，或销售阶段无效']);
        }
        if ($row->pipeline_stage === $stage) {
            return $row;
        }
        return DB::transaction(function () use ($row, $stage, $actorId) {
            $row->refresh();
            if ($row->status !== 'open') {
                throw ValidationException::withMessages(['pipeline_stage' => '已关闭商机不可直接更新']);
            }
            $previous = $row->pipeline_stage;
            $row->forceFill(['pipeline_stage' => $stage, 'probability_bps' => self::PROBABILITIES[$stage]])->save();
            $this->record($row, $previous, $stage, $actorId, 'manual');
            return $row;
        });
    }

    private function record(B2BSalesOpportunity $row, ?string $previous, string $stage, int $actorId, string $source): void
    {
        $row->stageEvents()->create([
            'actor_id' => $actorId,
            'from_stage' => $previous,
            'to_stage' => $stage,
            'source' => $source,
            'entered_at' => now(),
        ]);
    }

    public function roadmap(B2BSalesOpportunity $row): array
    {
        $events = $row->stageEvents()->orderBy('entered_at')->orderBy('id')->get();
        $reached = $events->pluck('to_stage')->all();
        $active = $row->status === 'open' ? $row->pipeline_stage : $row->status;
        return collect(self::STAGES)->map(fn (string $stage) => [
            'key' => $stage,
            'label' => self::LABELS[$stage],
            'visited' => in_array($stage, $reached, true),
            'current' => $stage === $active,
            'entered_at' => $events->firstWhere('to_stage', $stage)?->entered_at?->toIso8601String(),
        ])->all();
    }
}
