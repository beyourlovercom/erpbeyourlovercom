<?php

namespace App\Modules\Customers\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Customers\Domain\CountryCodeNormalizer;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class CustomerService
{
    private const CUSTOMER_CLASS = 'App\\Modules\\Customers\\Infrastructure\\Models\\Customer';

    private const AUDIT_FIELDS = ['name', 'email', 'phone', 'country_code', 'language_code', 'source_type', 'owner_id', 'notes'];

    public function __construct(private readonly AuditRecorder $audit) {}

    public function list(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        $customerClass = self::CUSTOMER_CLASS;
        $archived = $filters['archived'] ?? '';

        return $customerClass::query()
            ->with('owner')
            ->withCount(['orders' => fn ($query) => $query->where('order_kind', 'customer')])
            ->when($archived === '', fn ($query) => $query->whereNull('archived_at'))
            ->when($archived === 'only', fn ($query) => $query->whereNotNull('archived_at'))
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($filters['country_code'] ?? null, fn ($query, string $country) => $query->where('country_code', strtoupper($country)))
            ->when($filters['source_type'] ?? null, fn ($query, string $source) => $query->where('source_type', $source))
            ->orderByDesc('updated_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function create(array $attributes, ?Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($attributes, $actor): Model {
            $customerClass = self::CUSTOMER_CLASS;
            $customer = $customerClass::create($this->normalize($attributes));
            $this->record($actor, 'customer.created', $customer, after: $this->auditData($customer), reason: 'customer_created');
            return $customer->load('owner');
        });
    }

    public function update(Model $customer, array $attributes, ?Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($customer, $attributes, $actor): Model {
            $locked = $customer->newQuery()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
            $before = $this->auditData($locked);
            $locked->fill($this->normalize($attributes))->save();
            $after = $this->auditData($locked);
            $this->record($actor, 'customer.updated', $locked, before: $before, after: $after, reason: 'customer_corrected');
            return $locked->fresh('owner');
        });
    }

    public function archive(Model $customer, ?Authenticatable $actor): Model
    {
        return $this->setArchived($customer, $actor, true);
    }

    public function restore(Model $customer, ?Authenticatable $actor): Model
    {
        return $this->setArchived($customer, $actor, false);
    }

    private function setArchived(Model $customer, ?Authenticatable $actor, bool $archive): Model
    {
        return DB::transaction(function () use ($customer, $actor, $archive): Model {
            $locked = $customer->newQuery()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
            if (($locked->archived_at !== null) === $archive) {
                return $locked;
            }
            $before = $this->auditData($locked);
            $locked->forceFill(['archived_at' => $archive ? now() : null])->save();
            $after = $this->auditData($locked);
            $action = $archive ? 'customer.archived' : 'customer.restored';
            $this->record($actor, $action, $locked, before: $before, after: $after, reason: $action);
            return $locked->fresh('owner');
        });
    }

    private function normalize(array $attributes): array
    {
        foreach (['name', 'email', 'phone', 'language_code', 'notes'] as $field) {
            if (array_key_exists($field, $attributes) && is_string($attributes[$field])) {
                $attributes[$field] = trim($attributes[$field]);
            }
        }
        if (array_key_exists('email', $attributes)) {
            $attributes['email'] = $attributes['email'] === '' ? null : strtolower($attributes['email']);
        }
        if (array_key_exists('phone', $attributes) && $attributes['phone'] === '') {
            $attributes['phone'] = null;
        }
        if (array_key_exists('country_code', $attributes)) {
            $code = $attributes['country_code'];
            $attributes['country_code'] = ($code === null || $code === '') ? null : CountryCodeNormalizer::normalize($code);
        }
        if (array_key_exists('language_code', $attributes) && $attributes['language_code'] === '') {
            $attributes['language_code'] = null;
        }
        return $attributes;
    }

    private function auditData(Model $customer): array
    {
        $data = [];
        foreach (self::AUDIT_FIELDS as $field) {
            $data[$field] = $customer->{$field};
        }
        $data['archived_at'] = $customer->archived_at?->toIso8601String();
        return $data;
    }

    private function record(?Authenticatable $actor, string $action, Model $customer, ?array $before = null, ?array $after = null, ?string $reason = null): void
    {
        $this->audit->record(
            $actor ? AuditActor::user($actor, AuditSource::InternalSystem) : AuditActor::system(AuditSource::InternalSystem),
            $action,
            get_class($customer),
            $customer->getKey(),
            before: $before,
            after: $after,
            reason: $reason,
        );
    }
}
