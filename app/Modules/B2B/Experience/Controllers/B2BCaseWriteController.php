<?php

namespace App\Modules\B2B\Experience\Controllers;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\B2B\Infrastructure\Models\B2BCase;
use App\Modules\B2B\Infrastructure\Models\B2BOrganization;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class B2BCaseWriteController
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function create(Request $request): Response
    {
        $query = B2BOrganization::query()->whereNull('archived_at');
        if (! $request->user()->hasPermission('b2b.view_all')) {
            $query->where('owner_id', $request->user()->getAuthIdentifier());
        }

        return Inertia::render('B2B/Cases/Create', [
            'organizations' => $query->with('contacts:id,organization_id,name')
                ->orderBy('display_name')->limit(250)->get()
                ->map(fn (B2BOrganization $org): array => [
                    'id' => $org->id,
                    'name' => $org->display_name,
                    'country_code' => $org->country_code,
                    'contacts' => $org->contacts->map(fn ($contact): array => [
                        'id' => $contact->id, 'name' => $contact->name,
                    ])->values()->all(),
                ])->values()->all(),
            'initialOrganization' => (int) $request->query('organization_id', 0),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'integer', 'exists:b2b_organizations,id'],
            'primary_contact_id' => ['nullable', 'integer', 'exists:b2b_contacts,id'],
            'title' => ['required', 'string', 'max:255'],
            'product_interest' => ['nullable', 'string', 'max:255'],
            'requested_quantity' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'inquiry_kind' => ['required', Rule::in([
                'retail', 'wholesale', 'authorized_distributor_interest', 'exclusive_distributor_interest', 'other',
            ])],
            'priority' => ['required', Rule::in(['low', 'normal', 'high'])],
            'next_follow_up_at' => ['nullable', 'date'],
            'initial_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $org = B2BOrganization::query()->findOrFail((int) $data['organization_id']);
        abort_if($org->archived_at !== null, 404);
        if (! $request->user()->hasPermission('b2b.view_all')) {
            abort_unless((int) $org->owner_id === (int) $request->user()->getAuthIdentifier(), 404);
        }
        if (! empty($data['primary_contact_id'])) {
            abort_unless($org->contacts()->whereKey((int) $data['primary_contact_id'])->exists(), 422);
        }

        $actor = $request->user();
        $case = DB::transaction(function () use ($actor, $org, $data): B2BCase {
            $receivedAt = now();
            $case = B2BCase::create([
                'organization_id' => $org->id,
                'primary_contact_id' => $data['primary_contact_id'] ?? null,
                'owner_id' => $actor->getAuthIdentifier(),
                'title' => trim($data['title']),
                'source_channel' => 'manual',
                'country_code' => $org->country_code,
                'product_interest' => $data['product_interest'] ?? null,
                'requested_quantity' => $data['requested_quantity'] ?? null,
                'inquiry_kind' => $data['inquiry_kind'],
                'priority' => $data['priority'],
                'case_status' => 'open',
                'case_stage' => 'new',
                'handling_state' => 'waiting',
                'first_inbound_at' => $receivedAt,
                'last_inbound_at' => $receivedAt,
                'attention_since_at' => $receivedAt,
                'next_follow_up_at' => $data['next_follow_up_at'] ?? null,
            ]);
            $case->forceFill([
                'case_number' => 'CASE-'.$receivedAt->format('Ymd').'-'.str_pad((string) $case->id, 6, '0', STR_PAD_LEFT),
            ])->save();

            $this->activity($case, $actor, 'case_created', '人工录入企业询盘');
            if (! empty($data['initial_note'])) {
                $this->activity($case, $actor, 'note_added', trim($data['initial_note']));
            }
            if (! empty($data['next_follow_up_at'])) {
                $this->activity($case, $actor, 'followup_scheduled');
            }
            $this->auditChange($case, $actor, 'b2b.case.created', null, [
                'organization_id' => $org->id,
                'owner_id' => $case->owner_id,
                'stage' => 'new',
            ]);

            return $case;
        });

        return redirect()->route('b2b.cases.show', $case->id)->with('success', '询盘已创建');
    }

    public function update(Request $request, B2BCase $case): RedirectResponse
    {
        $this->assertCanOperate($request, $case);
        abort_if($case->case_status !== 'open', 422, '已关闭的询盘不可直接重新打开');

        $data = $request->validate([
            'case_stage' => ['sometimes', Rule::in(['new', 'contacted', 'qualified', 'quoted', 'negotiating'])],
            'case_status' => ['sometimes', Rule::in(['open', 'won', 'lost', 'spam'])],
            'handling_state' => ['sometimes', Rule::in(['waiting', 'in_progress', 'waiting_customer'])],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high'])],
            'next_follow_up_at' => ['present', 'nullable', 'date'],
            'loss_reason_code' => ['nullable', 'string', 'max:64'],
        ]);

        if (($data['case_status'] ?? '') === 'lost' && empty($data['loss_reason_code'])) {
            return back()->withErrors(['loss_reason_code' => '流失询盘需要选择或填写原因']);
        }
        $actor = $request->user();
        DB::transaction(function () use ($case, $data, $actor): void {
            $locked = B2BCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->case_status !== 'open', 422);
            $before = $locked->only(['case_status', 'case_stage', 'handling_state', 'priority', 'next_follow_up_at', 'loss_reason_code']);
            foreach (['case_status', 'case_stage', 'handling_state', 'priority', 'next_follow_up_at', 'loss_reason_code'] as $key) {
                if (array_key_exists($key, $data)) {
                    $locked->{$key} = $data[$key];
                }
            }
            if (in_array($locked->case_status, ['won', 'lost', 'spam'], true)) {
                $locked->handling_state = 'none';
                $locked->closed_at = now();
                $locked->next_follow_up_at = null;
            }
            $locked->last_action_at = now();
            if ($locked->first_action_at === null) {
                $locked->first_action_at = now();
            }
            $locked->save();
            $after = $locked->only(array_keys($before));
            foreach ($before as $key => $old) {
                if ((string) $old === (string) ($after[$key] ?? null)) {
                    continue;
                }
                $this->activity($locked, $actor, match ($key) {
                    'case_stage' => 'stage_changed',
                    'case_status' => 'status_changed',
                    'next_follow_up_at' => 'followup_scheduled',
                    default => 'case_updated',
                }, null, ['field' => $key, 'before' => (string) $old, 'after' => (string) ($after[$key] ?? '')]);
            }
            $this->auditChange($locked, $actor, 'b2b.case.updated', $before, $after);
        });

        return back()->with('success', '询盘进度已更新');
    }

    public function addNote(Request $request, B2BCase $case): RedirectResponse
    {
        $this->assertCanOperate($request, $case);
        $data = $request->validate(['body' => ['required', 'string', 'min:1', 'max:5000']]);
        $actor = $request->user();

        DB::transaction(function () use ($case, $actor, $data): void {
            $locked = B2BCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            $this->activity($locked, $actor, 'note_added', trim($data['body']));
            $locked->last_action_at = now();
            if ($locked->first_action_at === null) {
                $locked->first_action_at = now();
            }
            $locked->save();
            $this->auditChange($locked, $actor, 'b2b.case.note_added', null, ['case_id' => $locked->id]);
        });

        return back()->with('success', '跟进备注已记录');
    }

    public function assign(Request $request, B2BCase $case): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('b2b.assign') && $request->user()->hasPermission('b2b.view_all'), 403);
        $data = $request->validate(['owner_id' => ['required', 'integer', 'exists:users,id']]);
        $next = User::query()->whereKey((int) $data['owner_id'])->where('is_active', true)->firstOrFail();
        abort_unless($next->hasPermission('b2b.view'), 422, '目标业务员缺少 B2B 查看权限');
        $actor = $request->user();

        DB::transaction(function () use ($case, $actor, $next): void {
            $locked = B2BCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            $oldOwner = $locked->owner_id;
            $locked->owner_id = $next->id;
            $locked->save();
            $this->activity($locked, $actor, 'owner_assigned', null, ['previous_owner_id' => $oldOwner, 'owner_id' => $next->id]);
            $this->auditChange($locked, $actor, 'b2b.case.owner_assigned',
                ['owner_id' => $oldOwner], ['owner_id' => $next->id]);
        });

        return back()->with('success', '业务负责人已更新');
    }

    private function assertCanOperate(Request $request, B2BCase $case): void
    {
        if (! $request->user()->hasPermission('b2b.view_all')) {
            abort_unless((int) $case->owner_id === (int) $request->user()->getAuthIdentifier(), 404);
        }
    }

    private function activity(B2BCase $case, User $actor, string $type, ?string $text = null, ?array $metadata = null): void
    {
        $case->activities()->create([
            'actor_user_id' => $actor->id,
            'activity_type' => $type,
            'body_text' => $text,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }

    private function auditChange(B2BCase $case, User $actor, string $action, ?array $before, array $after): void
    {
        $this->audit->record(
            AuditActor::user($actor, AuditSource::InternalSystem),
            $action,
            B2BCase::class,
            $case->id,
            before: $before,
            after: $after,
            reason: 'b2b_case_manual_workflow',
        );
    }
}
