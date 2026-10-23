<?php

namespace App\Modules\B2B\Sales;

use App\Modules\B2B\Infrastructure\Models\B2BCase;
use App\Modules\B2B\Infrastructure\Models\B2BOrganization;
use App\Modules\B2B\Infrastructure\Models\B2BSalesOpportunity;
use App\Modules\B2B\Infrastructure\Models\B2BSalesQuote;
use App\Modules\B2B\Infrastructure\Models\B2BSalesFollowUp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        'quoted' => 6500,
        'negotiating' => 8000,
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


    /** YBY Sales OS addFollowUp, ported to private B2B Opportunity and local-only attachments. */
    public function followUp(B2BSalesOpportunity $row, array $values, int $actorId): B2BSalesFollowUp
    {
        $channel = (string) ($values['channel'] ?? 'note');
        if (! in_array($channel, ['note','email','whatsapp','call','meeting'], true)) {
            throw ValidationException::withMessages(['channel'=>'不支持该跟进渠道']);
        }
        $stage = (string) ($values['stage_key'] ?? ($row->status === 'open' ? $row->pipeline_stage : $row->status));
        if (! in_array($stage, [...self::STAGES,'won','lost'], true)) {
            throw ValidationException::withMessages(['stage_key'=>'跟进阶段不正确']);
        }
        $summary = trim((string) ($values['summary'] ?? ''));
        if ($summary === '') {
            throw ValidationException::withMessages(['summary'=>'请填写跟进摘要']);
        }
        return DB::transaction(function () use ($row,$values,$actorId,$channel,$stage,$summary): B2BSalesFollowUp {
            $happened = $values['happened_at'] ?? now();
            $record = $row->followUps()->create([
                'actor_id'=>$actorId,'stage_key'=>$stage,'channel'=>$channel,
                'outcome'=>$values['outcome'] ?? null,'summary'=>$summary,
                'next_plan'=>($values['next_plan'] ?? null),
                'happened_at'=>$happened,'next_follow_up_at'=>$values['next_follow_up_at'] ?? null,
            ]);
            foreach (($values['attachments'] ?? []) as $upload) {
                if (! $upload instanceof UploadedFile) {
                    continue;
                }
                // Always use private local storage, never webroot or production outbound provider.
                $stored = Storage::disk('local')->putFileAs(
                    'b2b-sales/followups/'.$record->id, $upload, Str::uuid().'.'.$upload->extension());
                if (! $stored) {
                    throw new \RuntimeException('Failed to store B2B follow-up attachment');
                }
                $record->attachments()->create([
                    'uploaded_by'=>$actorId,'disk'=>'local','path'=>$stored,
                    'original_name'=>$upload->getClientOriginalName(),
                    'mime_type'=>$upload->getMimeType(),
                    'size_bytes'=>$upload->getSize(),
                ]);
            }
            $row->forceFill([
                'last_follow_up_at'=>$happened,
                'next_follow_up_at'=>$values['next_follow_up_at'] ?? $row->next_follow_up_at,
            ])->save();
            return $record->load('actor','attachments');
        });
    }

    /** YBY immutable quote versioning. Drafts do not create real sales or customer revenue. */
    public function quote(B2BSalesOpportunity $row, array $values, int $actorId): B2BSalesQuote
    {
        return DB::transaction(function () use ($row,$values,$actorId): B2BSalesQuote {
            $locked = B2BSalesOpportunity::query()->lockForUpdate()->findOrFail($row->id);
            if ($locked->status !== 'open') {
                throw ValidationException::withMessages(['status'=>'已关闭商机不可新建报价']);
            }
            $subtotal = max(0,(int)($values['subtotal_micros'] ?? 0));
            $discount = max(0,(int)($values['discount_micros'] ?? 0));
            $tax = max(0,(int)($values['tax_micros'] ?? 0));
            $version = (int) $locked->quotes()->max('version')+1;
            $draft = $locked->quotes()->create([
                'quote_number'=>'B2B-Q-'.strtoupper(bin2hex(random_bytes(7))),
                'version'=>$version,'status'=>'draft',
                'currency'=>$values['currency'] ?? $locked->currency,
                'subtotal_micros'=>$subtotal,'discount_micros'=>$discount,'tax_micros'=>$tax,
                'total_micros'=>max(0,$subtotal-$discount+$tax),
                'valid_until'=>$values['valid_until'] ?? null,
                'line_items'=>$values['line_items'] ?? null,
                'notes'=>$values['notes'] ?? null,
            ]);
            if (! in_array($locked->pipeline_stage,['quoted','negotiating'],true)) {
                $from=$locked->pipeline_stage;
                $locked->forceFill(['pipeline_stage'=>'quoted','probability_bps'=>self::PROBABILITIES['quoted']])->save();
                $this->record($locked,$from,'quoted',$actorId,'quote','quote_created');
            }
            return $draft;
        });
    }

    /** Won and Lost are sales decisions, never accounting receipts. */
    public function close(B2BSalesOpportunity $row, string $outcome, int $actorId, ?string $reasonCode=null, ?string $reasonNote=null): B2BSalesOpportunity
    {
        if (! in_array($outcome,['won','lost'],true)) {
            throw ValidationException::withMessages(['status'=>'无效的成交结果']);
        }
        if ($outcome==='lost' && trim((string)$reasonCode)==='') {
            throw ValidationException::withMessages(['reason_code'=>'丢单必须填写原因']);
        }
        return DB::transaction(function () use ($row,$outcome,$actorId,$reasonCode,$reasonNote) {
            $locked=B2BSalesOpportunity::query()->lockForUpdate()->findOrFail($row->id);
            if ($locked->status===$outcome) return $locked;
            if ($locked->status!=='open') {
                throw ValidationException::withMessages(['status'=>'必须先重新开启已关闭的商机']);
            }
            $previous=$locked->pipeline_stage;
            $locked->forceFill([
                'status'=>$outcome,'pipeline_stage'=>$outcome,
                'probability_bps'=>$outcome==='won'?10000:0,
                'won_at'=>$outcome==='won'?now():null,
                'lost_at'=>$outcome==='lost'?now():null,
                'lost_reason_code'=>$outcome==='lost'?$reasonCode:null,
                'lost_reason_note'=>$outcome==='lost'?$reasonNote:null,
                'next_follow_up_at'=>null,
            ])->save();
            $why=$outcome==='lost' ? trim($reasonCode.' '.($reasonNote??'')) : 'opportunity_won';
            $this->record($locked,$previous,$outcome,$actorId,'close',$why);
            return $locked;
        });
    }

    public function reopen(B2BSalesOpportunity $row, string $stage, string $reason, int $actorId): B2BSalesOpportunity
    {
        if (! in_array($stage,self::STAGES,true) || trim($reason)==='') {
            throw ValidationException::withMessages(['reason'=>'需填写重新开启原因和有效的销售阶段']);
        }
        return DB::transaction(function () use ($row,$stage,$reason,$actorId) {
            $locked=B2BSalesOpportunity::query()->lockForUpdate()->findOrFail($row->id);
            if ($locked->status!=='lost') {
                throw ValidationException::withMessages(['status'=>'只有已丢单商机可以重新开启']);
            }
            $locked->forceFill([
                'status'=>'open','pipeline_stage'=>$stage,'probability_bps'=>self::PROBABILITIES[$stage],
                'won_at'=>null,'lost_at'=>null,'lost_reason_code'=>null,'lost_reason_note'=>null,
            ])->save();
            $this->record($locked,'lost',$stage,$actorId,'reopen',trim($reason));
            return $locked;
        });
    }

    private function record(B2BSalesOpportunity $row, ?string $previous, string $stage, int $actorId, string $source, ?string $reason = null): void
    {
        $row->stageEvents()->create([
            'actor_id' => $actorId,
            'from_stage' => $previous,
            'to_stage' => $stage,
            'source' => $source,
            'reason' => $reason,
            'entered_at' => now(),
        ]);
    }

    public function roadmap(B2BSalesOpportunity $row): array
    {
        $events = $row->stageEvents()->orderBy('entered_at')->orderBy('id')->get();
        $reached = $events->pluck('to_stage')->all();
        $active = $row->status === 'open' ? $row->pipeline_stage : $row->status;
        $nodes = collect(self::STAGES)->map(fn (string $stage) => [
            'key' => $stage,
            'label' => self::LABELS[$stage],
            'visited' => in_array($stage, $reached, true),
            'current' => $stage === $active,
            'entered_at' => $events->firstWhere('to_stage', $stage)?->entered_at?->toIso8601String(),
        ])->all();
        $nodes[] = [
            'key'=>$row->status === 'open' ? 'final' : $row->status,
            'label'=>$row->status === 'open' ? '成交 / 丢单' : self::LABELS[$row->status],
            'visited'=>$row->status !== 'open',
            'current'=>$row->status !== 'open',
            'entered_at'=>$row->status === 'won'
                ? $row->won_at?->toIso8601String()
                : $row->lost_at?->toIso8601String(),
        ];
        return $nodes;
    }
}
