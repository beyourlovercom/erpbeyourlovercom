<?php

namespace App\Modules\B2B\Experience\Controllers;

use App\Modules\B2B\Infrastructure\Models\B2BCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use App\Modules\Identity\Infrastructure\Models\User;
use Inertia\Inertia;
use Inertia\Response;

final class B2BCaseController
{
    public function index(Request $request): Response
    {
        $query = $this->visibleCases($request);
        $status = trim((string) $request->query('status', ''));
        $stage = trim((string) $request->query('stage', ''));
        $source = trim((string) $request->query('source', ''));
        $search = trim((string) $request->query('search', ''));
        $overdue = $request->query('overdue') === '1';
        $base = clone $query;

        if (in_array($status, ['open', 'won', 'lost', 'spam'], true)) {
            $query->where('case_status', $status);
        }
        if (in_array($stage, ['new', 'contacted', 'qualified', 'quoted', 'negotiating'], true)) {
            $query->where('case_stage', $stage);
        }
        if (in_array($source, ['manual', 'website_form', 'email', 'whatsapp_business', 'trade_show', 'referral', 'other'], true)) {
            $query->where('source_channel', $source);
        }
        if ($overdue) {
            $query->where('case_status', 'open')->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '<', now());
        }
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('case_number', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%')
                    ->orWhere('title', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%');
            });
        }

        $cases = $query->with(['organization:id,display_name,country_code', 'primaryContact:id,name'])
            ->orderByDesc('last_inbound_at')->paginate(30)->withQueryString();

        return Inertia::render('B2B/Cases/Index', [
            'cases' => $cases->through(fn (B2BCase $case): array => [
                'id' => $case->id,
                'case_number' => $case->case_number,
                'title' => $case->title,
                'organization' => $case->organization?->display_name,
                'contact' => $case->primaryContact?->name,
                'country_code' => $case->country_code ?? $case->organization?->country_code,
                'source' => $case->source_channel,
                'product_interest' => $case->product_interest,
                'requested_quantity' => $case->requested_quantity,
                'stage' => $case->case_stage,
                'status' => $case->case_status,
                'handling' => $case->handling_state,
                'next_follow_up_at' => $case->next_follow_up_at?->toIso8601String(),
                'last_inbound_at' => $case->last_inbound_at?->toIso8601String(),
            ]),
            'filters' => compact('status', 'stage', 'source', 'search'),
            'overdueOnly' => $overdue,
            'metrics' => [
                'total' => (clone $base)->count(),
                'open' => (clone $base)->where('case_status', 'open')->count(),
                'waiting' => (clone $base)->where('case_status', 'open')->where('handling_state', 'waiting')->count(),
                'overdue' => (clone $base)->where('case_status', 'open')
                    ->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '<', now())->count(),
            ],
            'canOperate' => $request->user()->hasPermission('b2b.operate'),
        ]);
    }

    public function show(Request $request, B2BCase $case): Response
    {
        $case = $this->visibleCases($request)->whereKey($case->id)
            ->with(['organization:id,display_name,country_code', 'primaryContact:id,name,email_normalized,phone'])
            ->firstOrFail();

        return Inertia::render('B2B/Cases/Show', [
            'case' => [
                'id' => $case->id,
                'case_number' => $case->case_number,
                'title' => $case->title,
                'organization_id' => $case->organization_id,
                'owner_id' => $case->owner_id,
                'organization' => $case->organization?->display_name,
                'contact' => $case->primaryContact?->name,
                'country_code' => $case->country_code ?? $case->organization?->country_code,
                'source' => $case->source_channel,
                'product_interest' => $case->product_interest,
                'requested_quantity' => $case->requested_quantity,
                'inquiry_kind' => $case->inquiry_kind,
                'priority' => $case->priority,
                'stage' => $case->case_stage,
                'status' => $case->case_status,
                'handling' => $case->handling_state,
                'next_follow_up_at' => $case->next_follow_up_at?->toIso8601String(),
            ],
            'activities' => $case->activities()->orderByDesc('occurred_at')->limit(100)
                ->get(['id', 'activity_type', 'body_text', 'occurred_at'])
                ->map(fn ($item): array => [
                    'id' => $item->id, 'type' => $item->activity_type,
                    'text' => $item->body_text, 'time' => $item->occurred_at?->toIso8601String(),
                ])->values()->all(),
            'canOperate' => $request->user()->hasPermission('b2b.operate'),
            'canAssign' => $request->user()->hasPermission('b2b.assign') && $request->user()->hasPermission('b2b.view_all'),
            'owners' => $request->user()->hasPermission('b2b.assign') && $request->user()->hasPermission('b2b.view_all')
                ? User::query()->where('is_active', true)->orderBy('name')->get()
                    ->filter(fn (User $user): bool => $user->hasPermission('b2b.view'))
                    ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])->values()->all()
                : [],
        ]);
    }

    private function visibleCases(Request $request): Builder
    {
        $query = B2BCase::query();
        if (! $request->user()->hasPermission('b2b.view_all')) {
            $query->where('owner_id', $request->user()->getAuthIdentifier());
        }

        return $query;
    }
}
