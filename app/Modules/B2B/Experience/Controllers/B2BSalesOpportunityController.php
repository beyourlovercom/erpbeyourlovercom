<?php

namespace App\Modules\B2B\Experience\Controllers;

use App\Modules\B2B\Infrastructure\Models\B2BCase;
use App\Modules\B2B\Infrastructure\Models\B2BOrganization;
use App\Modules\B2B\Infrastructure\Models\B2BSalesOpportunity;
use App\Modules\B2B\Infrastructure\Models\B2BSalesFollowUp;
use App\Modules\B2B\Infrastructure\Models\B2BSalesFollowUpAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use App\Modules\B2B\Sales\B2BSalesPipeline;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class B2BSalesOpportunityController
{
    public function __construct(private readonly B2BSalesPipeline $pipeline) {}

    private function visible(Request $request): Builder
    {
        $q = B2BSalesOpportunity::query();
        if (! $request->user()->hasPermission('b2b.view_all')) {
            $q->where('owner_id', $request->user()->getAuthIdentifier());
        }
        return $q;
    }

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:open,won,lost'],
            'stage' => ['nullable', Rule::in(B2BSalesPipeline::STAGES)],
            'search' => ['nullable', 'string', 'max:120'],
            'owner_id' => ['nullable', 'integer'],
        ]);
        $scope = $this->visible($request);
        if (isset($filters['owner_id'])) {
            if (! $request->user()->hasPermission('b2b.view_all')
                && (int) $filters['owner_id'] !== (int) $request->user()->getAuthIdentifier()) {
                abort(403);
            }
            $scope->where('owner_id', $filters['owner_id']);
        }
        $base = clone $scope;
        foreach (['status' => 'status', 'stage' => 'pipeline_stage'] as $key => $field) {
            if (filled($filters[$key] ?? null)) {
                $scope->where($field, $filters[$key]);
            }
        }
        if (filled($filters['search'] ?? null)) {
            $term = addcslashes(trim($filters['search']), '%_\\');
            $scope->where(function (Builder $q) use ($term): void {
                $q->where('title', 'like', "%{$term}%")
                    ->orWhere('opportunity_number', 'like', "%{$term}%")
                    ->orWhereHas('organization', fn (Builder $o) => $o->where('display_name', 'like', "%{$term}%"));
            });
        }
        $items = $scope->with(['organization:id,display_name,country_code', 'owner:id,name'])
            ->withCount(['stageEvents','quotes'])
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'won' THEN 1 ELSE 2 END")
            ->orderByRaw('next_follow_up_at IS NULL')
            ->orderBy('next_follow_up_at')
            ->orderByDesc('id')->paginate(25)->withQueryString();

        $orgQuery = B2BOrganization::query()->whereNull('archived_at');
        if (! $request->user()->hasPermission('b2b.view_all')) {
            $id = $request->user()->getAuthIdentifier();
            $orgQuery->where(function (Builder $q) use ($id): void {
                $q->where('owner_id', $id)
                    ->orWhereHas('cases', fn (Builder $c) => $c->where('owner_id', $id));
            });
        }

        $owners = $request->user()->hasPermission('b2b.view_all')
            ? User::query()->where('is_active', true)->get(['id', 'name'])
            : User::query()->whereKey($request->user()->getAuthIdentifier())->get(['id', 'name']);

        return Inertia::render('B2B/Sales/Index', [
            'opportunities' => $items->through(fn (B2BSalesOpportunity $row) => $this->summary($row)),
            'metrics' => [
                'open' => (clone $base)->where('status', 'open')->count(),
                'quoted' => (clone $base)->where('status', 'open')->whereIn('pipeline_stage', ['quoted','negotiating'])->count(),
                'won' => (clone $base)->where('status', 'won')->count(),
                'lost' => (clone $base)->where('status', 'lost')->count(),
                'pipeline_value_micros' => (int) (clone $base)->where('status','open')->sum('expected_value_micros'),
            ],
            'filters' => $filters,
            'owners' => $owners,
            'companies' => $orgQuery->orderBy('display_name')->get(['id', 'display_name', 'country_code'])
                ->map(fn (B2BOrganization $org) => [
                    'id' => $org->id,
                    'name' => $org->display_name,
                    'country_code' => $org->country_code,
                ]),
            'cases' => B2BCase::query()->where('case_status','open')
                ->whereIn('organization_id', $orgQuery->select('id'))
                ->when(! $request->user()->hasPermission('b2b.view_all'), fn (Builder $q) => $q->where('owner_id', $request->user()->getAuthIdentifier()))
                ->whereDoesntHave('salesOpportunity')
                ->orderByDesc('id')->limit(75)->get(['id','organization_id','title','case_number']),
            'canOperate' => $request->user()->hasPermission('b2b.operate'),
            'canAssign' => $request->user()->hasPermission('b2b.assign') && $request->user()->hasPermission('b2b.view_all'),
        ]);
    }

    public function show(Request $request, B2BSalesOpportunity $opportunity): Response
    {
        $row = $this->visible($request)->with([
            'organization:id,display_name,country_code',
            'contact:id,name',
            'owner:id,name',
            'inquiryCase:id,case_number,title',
            'stageEvents.actor:id,name',
        ])->findOrFail($opportunity->id);
        $events = $row->stageEvents->sortBy([['entered_at','asc'], ['id','asc']])->values();
        return Inertia::render('B2B/Sales/Show', [
            'opportunity' => $this->summary($row) + [
                'contact' => $row->contact ? ['id'=>$row->contact->id,'name'=>$row->contact->name] : null,
                'case' => $row->inquiryCase ? ['id'=>$row->inquiryCase->id,'case_number'=>$row->inquiryCase->case_number] : null,
                'stage_events' => $events->map(fn ($event) => [
                    'id'=>$event->id, 'from_stage'=>$event->from_stage,
                    'to_stage'=>$event->to_stage, 'source'=>$event->source, 'reason'=>$event->reason,
                    'actor'=>$event->actor?->name, 'entered_at'=>$event->entered_at?->toIso8601String(),
                ])->all(),
            ],
            'roadmap' => $this->pipeline->roadmap($row),
            'followUps' => $row->followUps()->with('actor:id,name','attachments')->orderBy('happened_at')->orderBy('id')->get()
                ->map(fn (B2BSalesFollowUp $fu) => [
                    'id'=>$fu->id,'stage_key'=>$fu->stage_key,'channel'=>$fu->channel,
                    'summary'=>$fu->summary,'outcome'=>$fu->outcome,'next_plan'=>$fu->next_plan,
                    'happened_at'=>$fu->happened_at?->toIso8601String(),
                    'actor'=>$fu->actor?->name,
                    'attachments'=>$fu->attachments->map(fn ($a)=>[
                        'id'=>$a->id,'name'=>$a->original_name,
                        'href'=>route('b2b.sales.followups.attachments.download',[
                            'opportunity'=>$row->id,'followUp'=>$fu->id,'attachment'=>$a->id,
                        ]),
                    ])->values()->all(),
                ])->all(),
            'quotes' => $row->quotes()->orderBy('version')->get()->map(fn ($q) => [
                'id'=>$q->id,'quote_number'=>$q->quote_number,'version'=>$q->version,
                'status'=>$q->status,'currency'=>$q->currency,'subtotal_micros'=>$q->subtotal_micros,
                'discount_micros'=>$q->discount_micros,'tax_micros'=>$q->tax_micros,
                'total_micros'=>$q->total_micros,'notes'=>$q->notes,
            ])->all(),
            'canOperate' => $request->user()->hasPermission('b2b.operate'),
            'canQuote' => $request->user()->hasPermission('b2b.quotes_manage'),

        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'organization_id' => ['required','integer','exists:b2b_organizations,id'],
            'case_id' => ['nullable','integer','exists:b2b_cases,id'],
            'contact_id' => ['nullable','integer','exists:b2b_contacts,id'],
            'owner_id' => ['nullable','integer','exists:users,id'],
            'title' => ['required','string','max:255'],
            'pipeline_stage' => ['nullable',Rule::in(B2BSalesPipeline::STAGES)],
            'currency' => ['required','regex:/^[A-Z]{3}$/'],
            'expected_value_micros' => ['nullable','integer','min:0','max:100000000000000'],
            'expected_close_date' => ['nullable','date'],
            'next_follow_up_at' => ['nullable','date'],
        ]);
        $org = B2BOrganization::query()->findOrFail((int) $data['organization_id']);
        $actorId = (int) $request->user()->getAuthIdentifier();
        $manager = $request->user()->hasPermission('b2b.view_all');
        $canSeeOrg = $manager || (int) $org->owner_id === $actorId ||
            $org->cases()->where('owner_id', $actorId)->exists();
        abort_unless($canSeeOrg, 403);

        if (! $manager) {
            abort_if(isset($data['owner_id']) && (int) $data['owner_id'] !== $actorId, 403);
            if (! empty($data['case_id'])) {
                abort_unless($org->cases()->whereKey($data['case_id'])->where('owner_id',$actorId)->exists(), 404);
            }
            $data['owner_id'] = $actorId;
        } elseif (! $request->user()->hasPermission('b2b.assign')) {
            abort_if(isset($data['owner_id']) && (int) $data['owner_id'] !== $actorId, 403);
            $data['owner_id'] = $actorId;
        }
        if (! empty($data['owner_id'])) {
            $user = User::query()->whereKey($data['owner_id'])->where('is_active', true)->firstOrFail();
            abort_unless($user->hasPermission('b2b.view') && $user->hasPermission('b2b.operate'), 422);
        }
        $row = $this->pipeline->create($data, $actorId);
        return redirect()->route('b2b.sales.show', $row->id);
    }

    public function stage(Request $request, B2BSalesOpportunity $opportunity): RedirectResponse
    {
        $row = $this->visible($request)->findOrFail($opportunity->id);
        $data = $request->validate(['pipeline_stage' => ['required',Rule::in(B2BSalesPipeline::STAGES)]]);
        $this->pipeline->advance($row, $data['pipeline_stage'], (int) $request->user()->getAuthIdentifier());
        return back();
    }


    public function followUp(Request $request, B2BSalesOpportunity $opportunity): RedirectResponse
    {
        $row = $this->visible($request)->findOrFail($opportunity->id);
        $data = $request->validate([
            'stage_key'=>['nullable',Rule::in([...B2BSalesPipeline::STAGES,'won','lost'])],
            'channel'=>['required',Rule::in(['note','email','whatsapp','call','meeting'])],
            'summary'=>['required','string','max:10000'],
            'outcome'=>['nullable','string','max:64'],
            'next_plan'=>['nullable','string','max:5000'],
            'next_follow_up_at'=>['nullable','date'],
            'attachments'=>['nullable','array','max:3'],
            'attachments.*'=>['file','mimes:pdf,png,jpg,jpeg,docx','max:5120'],
        ]);
        $this->pipeline->followUp($row, $data, (int) $request->user()->getAuthIdentifier());
        return back();
    }

    public function quote(Request $request, B2BSalesOpportunity $opportunity): RedirectResponse
    {
        $row = $this->visible($request)->findOrFail($opportunity->id);
        $data = $request->validate([
            'currency'=>['required','regex:/^[A-Z]{3}$/'],
            'subtotal_micros'=>['required','integer','min:0','max:100000000000000'],
            'discount_micros'=>['nullable','integer','min:0','max:100000000000000'],
            'tax_micros'=>['nullable','integer','min:0','max:100000000000000'],
            'valid_until'=>['nullable','date'],
            'notes'=>['nullable','string','max:10000'],
        ]);
        $this->pipeline->quote($row, $data, (int) $request->user()->getAuthIdentifier());
        return back();
    }

    public function won(Request $request, B2BSalesOpportunity $opportunity): RedirectResponse
    {
        $row = $this->visible($request)->findOrFail($opportunity->id);
        $this->pipeline->close($row,'won',(int)$request->user()->getAuthIdentifier());
        return back();
    }

    public function lost(Request $request, B2BSalesOpportunity $opportunity): RedirectResponse
    {
        $row = $this->visible($request)->findOrFail($opportunity->id);
        $data=$request->validate([
            'reason_code'=>['required','string','max:64'],
            'reason_note'=>['nullable','string','max:2000'],
        ]);
        $this->pipeline->close($row,'lost',(int)$request->user()->getAuthIdentifier(),$data['reason_code'],$data['reason_note']??null);
        return back();
    }

    public function reopen(Request $request, B2BSalesOpportunity $opportunity): RedirectResponse
    {
        $row = $this->visible($request)->findOrFail($opportunity->id);
        $data=$request->validate([
            'stage'=>['required',Rule::in(B2BSalesPipeline::STAGES)],
            'reason'=>['required','string','max:191'],
        ]);
        $this->pipeline->reopen($row,$data['stage'],$data['reason'],(int)$request->user()->getAuthIdentifier());
        return back();
    }

    public function attachment(Request $request, B2BSalesOpportunity $opportunity, B2BSalesFollowUp $followUp, B2BSalesFollowUpAttachment $attachment): BinaryFileResponse
    {
        $row = $this->visible($request)->findOrFail($opportunity->id);
        abort_unless((int) $followUp->opportunity_id === (int) $row->id, 404);
        abort_unless((int) $attachment->follow_up_id === (int) $followUp->id, 404);
        abort_unless($attachment->disk === 'local',404);
        abort_unless(Storage::disk('local')->exists($attachment->path),404);
        return response()->download(
            Storage::disk('local')->path($attachment->path),
            $attachment->original_name,
            ['X-Content-Type-Options'=>'nosniff'],
        );
    }

    private function summary(B2BSalesOpportunity $row): array
    {
        return [
            'id'=>$row->id, 'opportunity_number'=>$row->opportunity_number,
            'title'=>$row->title, 'status'=>$row->status,
            'pipeline_stage'=>$row->pipeline_stage, 'currency'=>$row->currency,
            'expected_value_micros'=>$row->expected_value_micros, 'probability_bps'=>$row->probability_bps,
            'expected_close_date'=>$row->expected_close_date?->toDateString(),
            'next_follow_up_at'=>$row->next_follow_up_at?->toIso8601String(),
            'lost_reason_code'=>$row->lost_reason_code,
            'company'=>$row->organization ? ['id'=>$row->organization->id,'name'=>$row->organization->display_name,'country_code'=>$row->organization->country_code] : null,
            'owner'=>$row->owner ? ['id'=>$row->owner->id,'name'=>$row->owner->name] : null,
            'quotes_count'=>(int) ($row->quotes_count ?? 0),
        ];
    }
}
