<?php

namespace App\Modules\Marketing\Experience\Controllers;

use App\Modules\Marketing\Infrastructure\Models\MarketingSubscriber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class MarketingController
{
    public function overview(): Response
    {
        return Inertia::render('Marketing/Overview', [
            'metrics' => [
                'subscribers' => MarketingSubscriber::query()->count(),
                'subscriptions' => DB::table('marketing_subscriptions')->count(),
                'subscribed' => DB::table('marketing_subscriptions')->where('status', 'subscribed')->count(),
            ],
        ]);
    }

    public function subscribers(Request $request): Response
    {
        $query = MarketingSubscriber::query()
            ->select('marketing_subscribers.*')
            ->selectSub(function ($subquery): void {
                $subquery->from('marketing_subscriptions')->selectRaw('GROUP_CONCAT(DISTINCT provider)')
                    ->whereColumn('subscriber_id', 'marketing_subscribers.id');
            }, 'providers')
            ->selectSub(function ($subquery): void {
                $subquery->from('marketing_subscriptions')->selectRaw('GROUP_CONCAT(DISTINCT source_site)')
                    ->whereColumn('subscriber_id', 'marketing_subscribers.id');
            }, 'source_sites')
            ->selectSub(function ($subquery): void {
                $subquery->from('marketing_subscriptions')->selectRaw('GROUP_CONCAT(DISTINCT status)')
                    ->whereColumn('subscriber_id', 'marketing_subscribers.id');
            }, 'statuses')
            ->selectSub(function ($subquery): void {
                $subquery->from('marketing_subscriptions')->selectRaw('GROUP_CONCAT(DISTINCT external_list_name)')
                    ->whereColumn('subscriber_id', 'marketing_subscribers.id');
            }, 'lists')
            ->selectSub(fn ($subquery) => $subquery->from('marketing_subscriptions')->selectRaw('MAX(subscribed_at)')->whereColumn('subscriber_id', 'marketing_subscribers.id'), 'latest_subscribed_at')
            ->selectSub(fn ($subquery) => $subquery->from('marketing_subscriptions')->selectRaw('MAX(unsubscribed_at)')->whereColumn('subscriber_id', 'marketing_subscribers.id'), 'latest_unsubscribed_at')
            ->selectSub(fn ($subquery) => $subquery->from('marketing_subscriptions')->selectRaw('MAX(last_synced_at)')->whereColumn('subscriber_id', 'marketing_subscribers.id'), 'latest_synced_at');

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('email', 'like', "%{$search}%")->orWhere('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"));
        }
        foreach (['provider', 'source_site'] as $filter) {
            if ($value = $request->input($filter)) {
                $query->whereHas('subscriptions', fn ($q) => $q->where($filter, $value));
            }
        }
        if ($status = $request->input('status')) {
            $query->whereHas('subscriptions', fn ($q) => $q->where('status', $status));
        }

        $subscribers = $query->orderBy('email')->paginate(50)->withQueryString();
        return Inertia::render('Marketing/Subscribers/Index', [
            'subscribers' => $subscribers,
            'filters' => $request->only(['search', 'status', 'provider', 'source_site']),
            'filterOptions' => [
                'statuses' => ['subscribed', 'unsubscribed', 'suppressed', 'pending', 'unknown'],
                'providers' => DB::table('marketing_subscriptions')->whereNotNull('provider')->distinct()->orderBy('provider')->pluck('provider')->values(),
                'source_sites' => DB::table('marketing_subscriptions')->whereNotNull('source_site')->distinct()->orderBy('source_site')->pluck('source_site')->values(),
            ],
            'gridPreference' => app('App\\Modules\\Shell\\Application\\UserUiPreferenceService')->get($request->user(), 'grid.marketing.subscribers.v1'),
        ]);
    }

    public function planned(string $workspace): Response
    {
        abort_unless(in_array($workspace, ['newsletters', 'campaigns', 'automations', 'promotions'], true), 404);
        return Inertia::render('Marketing/Planned', ['workspace' => $workspace]);
    }
}
