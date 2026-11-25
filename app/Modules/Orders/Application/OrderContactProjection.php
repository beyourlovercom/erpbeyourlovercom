<?php

namespace App\Modules\Orders\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Shared, request-scoped projection. Prime a full page before rendering list rows. */
final class OrderContactProjection
{
    private array $rows = [];

    public function prime(iterable $orders): void
    {
        $rootIds = [];
        $orderRoots = [];
        $loadedRoots = collect();
        foreach ($orders as $order) {
            if (! $order instanceof Order) {
                continue;
            }
            $id = (int) $order->id;
            $root = (int) ($order->fulfillment_parent_id ?: $id);
            if (! array_key_exists($id, $this->rows)) {
                $orderRoots[$id] = $root;
                $rootIds[] = $root;
                if ($root === $id && array_key_exists('contact_user_id', $order->getAttributes())) {
                    $loadedRoots->put($id, $order);
                }
            }
        }
        if ($orderRoots === []) {
            return;
        }

        $missing = array_diff(array_unique($rootIds), $loadedRoots->keys()->all());
        $roots = $missing === [] ? $loadedRoots : $loadedRoots->union(
            Order::withTrashed()->whereIn('id', $missing)
                ->get(['id', 'order_kind', 'contact_user_id', 'owner_id', 'kol_id'])->keyBy('id')
        );
        $legacy = $roots->filter(fn (Order $order) => ! $order->contact_user_id);
        $kolRoots = $legacy->filter(fn (Order $order) => $order->order_kind === OrderKind::KolFulfillment);
        $kolProfileIds = $kolRoots->pluck('kol_id')->filter()->unique();
        $kolOwners = $kolProfileIds->isEmpty() ? collect() : Kol::query()->whereIn('id', $kolProfileIds)->pluck('owner_id', 'id');
        $kol = $kolRoots->isEmpty() ? collect() : collect(OrderContactPolicy::options(OrderKind::KolFulfillment))->keyBy('value');
        $ops = $legacy->contains(fn (Order $order) => $order->order_kind === OrderKind::Customer)
            ? collect(OrderContactPolicy::options(OrderKind::Customer))->keyBy('value') : collect();
        $explicitIds = $roots->pluck('contact_user_id')->filter()->unique();
        $explicit = $explicitIds->isEmpty() ? collect() : User::query()->whereIn('id', $explicitIds)->pluck('name', 'id');
        $configured = (int) config('order_contact.operations_user_id');

        foreach ($orderRoots as $id => $rootId) {
            $root = $roots->get($rootId);
            $contact = null;
            $name = null;
            if ($root && $root->contact_user_id && $explicit->has($root->contact_user_id)) {
                $contact = (int) $root->contact_user_id;
                $name = $explicit->get($contact);
            } elseif ($root?->order_kind === OrderKind::KolFulfillment) {
                $profileOwner = (int) $kolOwners->get($root->kol_id);
                $candidate = $kol->has($profileOwner) ? $profileOwner : (int) $root->owner_id;
                if ($kol->has($candidate)) {
                    $contact = $candidate;
                    $name = $kol->get($candidate)['label'];
                }
            } elseif ($root?->order_kind === OrderKind::Customer) {
                $candidate = $configured > 0 ? $configured
                    : ($ops->count() === 1 ? (int) $ops->keys()->first() : (int) $root->owner_id);
                if ($ops->has($candidate)) {
                    $contact = $candidate;
                    $name = $ops->get($candidate)['label'];
                }
            }
            $this->rows[$id] = ['contact_user_id' => $contact, 'contact_user_name' => $name];
        }
    }

    public function filterRequest(Builder $query, Request $request, bool $throughOrder = false): void
    {
        $raw = $request->query('filter_contact', '');
        $name = is_string($raw) ? mb_substr(trim($raw), 0, 80) : '';
        if ($name === '') {
            return;
        }
        if ($throughOrder) {
            $query->whereHas('order', fn ($order) => $this->applyNameFilter($order, $name));
        } else {
            $this->applyNameFilter($query, $name);
        }
    }

    /** Apply the same effective-contact precedence to the complete paginated dataset. */
    public function applyNameFilter(Builder $query, string $name): void
    {
        $needle = mb_strtolower(trim($name));
        if ($needle === '') {
            return;
        }
        $match = static fn (string $label): bool => str_contains(mb_strtolower($label), $needle);
        $users = User::query()->get(['id', 'name'])->filter(fn (User $user) => $match($user->name))->pluck('id')->all();
        $kol = collect(OrderContactPolicy::options(OrderKind::KolFulfillment));
        $ops = collect(OrderContactPolicy::options(OrderKind::Customer));
        $kolIds = $kol->pluck('value')->all();
        $matchingKol = $kol->filter(fn ($row) => $match($row['label']))->pluck('value')->all();
        $matchingOps = $ops->filter(fn ($row) => $match($row['label']))->pluck('value')->all();
        $configured = (int) config('order_contact.operations_user_id');
        $default = $configured > 0 ? $configured : ($ops->count() === 1 ? (int) $ops->first()['value'] : null);
        $root = function ($q) use ($users, $kolIds, $matchingKol, $matchingOps, $default): void {
            $q->where(function ($q) use ($users, $kolIds, $matchingKol, $matchingOps, $default): void {
                $q->whereIn('contact_user_id', $users)->orWhere(function ($legacy) use ($kolIds, $matchingKol, $matchingOps, $default): void {
                    $legacy->whereNull('contact_user_id')->where(function ($kind) use ($kolIds, $matchingKol, $matchingOps, $default): void {
                        $kind->where(function ($k) use ($kolIds, $matchingKol): void {
                            $k->where('order_kind', OrderKind::KolFulfillment->value)->where(function ($owner) use ($kolIds, $matchingKol): void {
                                $owner->whereHas('kol', fn ($profile) => $profile->whereIn('owner_id', $matchingKol))
                                    ->orWhere(fn ($fallback) => $fallback->whereDoesntHave('kol', fn ($profile) => $profile->whereIn('owner_id', $kolIds))->whereIn('owner_id', $matchingKol));
                            });
                        })->orWhere(function ($c) use ($matchingOps, $default): void {
                            $c->where('order_kind', OrderKind::Customer->value);
                            if ($default !== null) {
                                if (! in_array($default, $matchingOps, false)) {
                                    $c->whereRaw('1 = 0');
                                }
                            } else {
                                $c->whereIn('owner_id', $matchingOps);
                            }
                        });
                    });
                });
            });
        };
        $query->where(function ($all) use ($root): void {
            $all->where(fn ($parent) => $parent->whereNull('fulfillment_parent_id')->where($root))
                ->orWhereHas('fulfillmentParent', fn ($parent) => $parent->withTrashed()->where($root));
        });
    }

    public function for(?Order $order): array
    {
        if ($order) {
            $this->prime([$order]);
        }

        return $this->rows[$order?->id ?? 0] ?? ['contact_user_id' => null, 'contact_user_name' => null];
    }
}
