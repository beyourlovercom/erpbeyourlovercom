<?php

namespace App\Modules\Kol\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class KolService
{
    private const KOL = 'App\\Modules\\Kol\\Infrastructure\\Models\\Kol';
    private const KOL_SOCIAL_ACCOUNT = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolSocialAccount';

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters, ?int $ownerScopeId = null): LengthAwarePaginator
    {
        $kolClass = self::KOL;
        $query = $kolClass::with(['owner', 'socialAccounts.latestMetric'])
            ->active();

        if ($ownerScopeId !== null) {
            $query->where('owner_id', $ownerScopeId);
        } elseif (isset($filters['owner_id']) && $filters['owner_id'] !== '') {
            $query->where('owner_id', (int) $filters['owner_id']);
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = '%'.mb_strtolower($filters['search']).'%';
            $query->where(function (Builder $q) use ($search): void {
                $q->whereRaw('LOWER(display_name) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(legal_name) LIKE ?', [$search]);
            });
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['relationship_stage']) && $filters['relationship_stage'] !== '') {
            $query->where('relationship_stage', $filters['relationship_stage']);
        }

        if (isset($filters['country_code']) && $filters['country_code'] !== '') {
            $query->where('country_code', $filters['country_code']);
        }

        if (isset($filters['platform']) && $filters['platform'] !== '') {
            $query->whereHas('socialAccounts', function (Builder $q) use ($filters): void {
                $q->where('platform', $filters['platform'])->where('is_active', true);
            });
        }

        if (isset($filters['followers_min']) && $filters['followers_min'] !== '') {
            $query->whereHas('socialAccounts', function (Builder $q) use ($filters): void {
                $q->where('is_active', true)->whereHas('latestMetric', function (Builder $mq) use ($filters): void {
                    $mq->where('followers', '>=', (int) $filters['followers_min']);
                });
            });
        }

        if (isset($filters['followers_max']) && $filters['followers_max'] !== '') {
            $query->whereHas('socialAccounts', function (Builder $q) use ($filters): void {
                $q->where('is_active', true)->whereHas('latestMetric', function (Builder $mq) use ($filters): void {
                    $mq->where('followers', '<=', (int) $filters['followers_max']);
                });
            });
        }

        if (! empty($filters['view'])) {
            $query = $this->applySystemView($query, $filters['view'], $ownerScopeId);
        }

        $sort = $filters['sort'] ?? 'display_name';
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $allowedSorts = ['display_name', 'joined_at', 'created_at', 'relationship_stage'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'display_name';
        }
        $query->orderBy($sort, $direction);

        return $query->paginate(30)->withQueryString();
    }

    private function applySystemView(Builder $query, string $view, ?int $ownerScopeId): Builder
    {
        return match ($view) {
            'my_kols' => $ownerScopeId !== null ? $query : $query,
            'all_kols' => $query,
            '100k_plus' => $query->hundredKPlus(),
            default => $query,
        };
    }

    public function overview(?int $ownerScopeId = null): array
    {
        $kolClass = self::KOL;
        $base = $kolClass::active();
        $scoped = $ownerScopeId !== null ? (clone $base)->where('owner_id', $ownerScopeId) : clone $base;

        return [
            'total' => $base->count(),
            'mine' => $ownerScopeId !== null ? $scoped->count() : null,
            'hundred_k_plus' => (clone $base)->hundredKPlus()->count(),
            'by_stage' => (clone $base)
                ->selectRaw('relationship_stage, count(*) as count')
                ->groupBy('relationship_stage')
                ->pluck('count', 'relationship_stage')
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $socialAccounts
     */
    public function create(array $data, array $socialAccounts, Authenticatable $actor): Model
    {
        $kolClass = self::KOL;
        $kol = $kolClass::create($data);

        foreach ($socialAccounts as $account) {
            $sa = $kol->socialAccounts()->create([
                'platform' => $account['platform'],
                'handle' => $account['handle'] ?? null,
                'profile_url' => $account['profile_url'] ?? null,
                'is_active' => $account['is_active'] ?? true,
            ]);

            if (! empty($account['followers']) || ! empty($account['raw_followers_value'])) {
                $sa->metrics()->create([
                    'followers' => $account['followers'] ?? null,
                    'raw_followers_value' => $account['raw_followers_value'] ?? null,
                    'measured_at' => now(),
                ]);
            }
        }

        $this->audit->record(
            AuditActor::user($actor, AuditSource::Web),
            'kol.created',
            'kol',
            $kol->id,
            null,
            ['display_name' => $kol->display_name, 'owner_id' => $kol->owner_id],
        );

        return $kol;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $socialAccounts
     */
    public function update(Model $kol, array $data, array $socialAccounts, Authenticatable $actor): Model
    {
        $before = ['display_name' => $kol->display_name, 'owner_id' => $kol->owner_id, 'status' => $kol->status];

        $previousOwnerId = $kol->owner_id;
        $kol->update($data);

        $this->syncSocialAccounts($kol, $socialAccounts);

        $after = ['display_name' => $kol->display_name, 'owner_id' => $kol->owner_id, 'status' => $kol->status];

        $this->audit->record(
            AuditActor::user($actor, AuditSource::Web),
            'kol.updated',
            'kol',
            $kol->id,
            $before,
            $after,
        );

        if ($previousOwnerId !== $kol->owner_id) {
            $this->audit->record(
                AuditActor::user($actor, AuditSource::Web),
                'kol.owner_reassigned',
                'kol',
                $kol->id,
                ['owner_id' => $previousOwnerId],
                ['owner_id' => $kol->owner_id],
            );
        }

        return $kol;
    }

    public function archive(Model $kol, Authenticatable $actor): void
    {
        $kol->update(['archived_at' => now()]);

        $this->audit->record(
            AuditActor::user($actor, AuditSource::Web),
            'kol.archived',
            'kol',
            $kol->id,
        );
    }

    public function reactivate(Model $kol, Authenticatable $actor): void
    {
        $kol->update(['archived_at' => null]);

        $this->audit->record(
            AuditActor::user($actor, AuditSource::Web),
            'kol.reactivated',
            'kol',
            $kol->id,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $accounts
     */
    private function syncSocialAccounts(Model $kol, array $accounts): void
    {
        $saClass = self::KOL_SOCIAL_ACCOUNT;
        $keepIds = [];

        foreach ($accounts as $account) {
            $id = $account['id'] ?? null;
            if ($id) {
                $sa = $saClass::where('kol_id', $kol->id)->find($id);
                if ($sa) {
                    $sa->update([
                        'platform' => $account['platform'],
                        'handle' => $account['handle'] ?? null,
                        'profile_url' => $account['profile_url'] ?? null,
                        'is_active' => $account['is_active'] ?? true,
                    ]);
                    $keepIds[] = $sa->id;

                    if (array_key_exists('followers', $account)) {
                        $sa->metrics()->create([
                            'followers' => $account['followers'] ?? null,
                            'raw_followers_value' => $account['raw_followers_value'] ?? null,
                            'measured_at' => now(),
                        ]);
                    }
                    continue;
                }
            }

            $sa = $kol->socialAccounts()->create([
                'platform' => $account['platform'],
                'handle' => $account['handle'] ?? null,
                'profile_url' => $account['profile_url'] ?? null,
                'is_active' => $account['is_active'] ?? true,
            ]);
            $keepIds[] = $sa->id;

            if (! empty($account['followers']) || ! empty($account['raw_followers_value'])) {
                $sa->metrics()->create([
                    'followers' => $account['followers'] ?? null,
                    'raw_followers_value' => $account['raw_followers_value'] ?? null,
                    'measured_at' => now(),
                ]);
            }
        }

        $kol->socialAccounts()->whereNotIn('id', $keepIds)->delete();
    }
}
