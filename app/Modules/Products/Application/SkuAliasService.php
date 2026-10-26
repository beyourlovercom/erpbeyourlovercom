<?php

namespace App\Modules\Products\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Products\Application\Contracts\ProductRepository;
use App\Modules\Products\Application\Contracts\SkuAliasRepository;
use App\Modules\Products\Application\SkuMatchKey;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SkuAliasService
{
    public function __construct(
        private readonly SkuAliasRepository $aliases,
        private readonly ProductRepository $products,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Create a new SKU alias for an existing canonical Product.
     * Enforces all collision and integrity rules.
     */
    public function createAlias(
        int $productId,
        string $aliasSku,
        string $sourceType = 'manual',
        ?string $sourceReference = null,
        ?string $notes = null,
        ?Authenticatable $actor = null,
    ): Model {
        $matchKey = SkuMatchKey::compute($aliasSku);
        if ($matchKey === null) {
            throw new RuntimeException('Alias SKU cannot be blank.');
        }

        return DB::transaction(function () use ($productId, $aliasSku, $matchKey, $sourceType, $sourceReference, $notes, $actor): Model {
            $product = $this->products->findById($productId);
            if ($product === null) {
                throw new RuntimeException("Product {$productId} not found.");
            }

            $canonicalSku = (string) $product->getAttribute('sku');
            $canonicalKey = SkuMatchKey::compute($canonicalSku);

            if ($canonicalKey !== null && $canonicalKey === $matchKey) {
                throw new RuntimeException('Alias match key is identical to the canonical SKU match key; alias is redundant.');
            }

            // Collision check via match key, not raw string — DB-collation-independent.
            if ($this->products->findBySkuMatchKey($matchKey) !== null) {
                throw new RuntimeException("Match key '{$matchKey}' already exists as a canonical Product SKU.");
            }

            if ($this->aliases->existsMatchKey($matchKey)) {
                throw new RuntimeException("Match key '{$matchKey}' is already registered as an alias.");
            }

            $alias = $this->aliases->createAlias([
                'product_id' => $productId,
                'alias_sku' => $aliasSku,
                'match_key' => $matchKey,
                'source_type' => $sourceType,
                'source_reference' => $sourceReference,
                'notes' => $notes,
                'created_by_user_id' => $actor?->getAuthIdentifier(),
            ]);

            $this->audit->record(
                $actor ? AuditActor::user($actor, AuditSource::Web) : AuditActor::system(AuditSource::InternalSystem),
                'product.sku_alias.created',
                $alias::class,
                $alias->getKey(),
                before: null,
                after: ['product_id' => $productId, 'canonical_sku' => $canonicalSku, 'alias_sku' => $aliasSku, 'match_key' => $matchKey, 'source_type' => $sourceType],
                reason: 'sku_alias_created',
            );

            return $alias;
        });
    }

    /**
     * Delete an existing alias with audit record.
     */
    public function deleteAlias(Model $alias, ?Authenticatable $actor = null): void
    {
        DB::transaction(function () use ($alias, $actor): void {
            $before = [
                'product_id' => $alias->getAttribute('product_id'),
                'alias_sku' => $alias->getAttribute('alias_sku'),
                'match_key' => $alias->getAttribute('match_key'),
            ];

            $this->aliases->deleteAlias($alias);

            $this->audit->record(
                $actor ? AuditActor::user($actor, AuditSource::Web) : AuditActor::system(AuditSource::InternalSystem),
                'product.sku_alias.deleted',
                $alias::class,
                $alias->getKey(),
                before: $before,
                after: null,
                reason: 'sku_alias_deleted',
            );
        });
    }

    /**
     * Manual physical-identity attestation: an alias can identify a Product
     * before it is safe to use for any OMS. Approval is never inferred from
     * import/source tags and never authorizes a warehouse dispatch by itself.
     */
    public function verifyForOms(Model $alias, string $physicalEvidence, Authenticatable $actor): void
    {
        if (! method_exists($actor, 'can') || ! $actor->can('products.operate')) {
            throw new RuntimeException('SKU_ALIAS_VERIFICATION_FORBIDDEN');
        }
        $evidence = trim($physicalEvidence);
        if (mb_strlen($evidence) < 20) {
            throw new RuntimeException('SKU_ALIAS_IDENTITY_EVIDENCE_REQUIRED');
        }

        DB::transaction(function () use ($alias, $evidence, $actor): void {
            $alias->refresh();
            if (! (bool) $alias->getAttribute('is_active')) {
                throw new RuntimeException('SKU_ALIAS_INACTIVE');
            }
            $before = ['oms_verified_at' => $alias->getAttribute('oms_verified_at')];
            $alias->forceFill([
                'oms_verified_at' => now(),
                'oms_verified_by_user_id' => $actor->getAuthIdentifier(),
                'oms_identity_evidence' => $evidence,
            ])->save();
            $this->audit->record(
                AuditActor::user($actor, AuditSource::Web),
                'product.sku_alias.oms_verified',
                $alias::class,
                $alias->getKey(),
                before: $before,
                after: ['product_id' => $alias->getAttribute('product_id'),
                    'alias_sku' => $alias->getAttribute('alias_sku'),
                    'oms_verified_by_user_id' => $actor->getAuthIdentifier(),
                    'oms_identity_evidence' => $evidence],
                reason: 'manual_physical_identity_attestation',
            );
        });
    }

    /** An inactive alias reserves its key but never resolves or ships. */
    public function setActive(Model $alias, bool $active, Authenticatable $actor): void
    {
        if (! method_exists($actor, 'can') || ! $actor->can('products.operate')) {
            throw new RuntimeException('SKU_ALIAS_STATUS_FORBIDDEN');
        }
        DB::transaction(function () use ($alias, $active, $actor): void {
            $alias->refresh();
            $wasActive = (bool) $alias->getAttribute('is_active');
            if ($wasActive === $active) return;
            $alias->forceFill([
                'is_active' => $active,
                // An off/on cycle never restores historic OMS approval.
                'oms_verified_at' => null,
                'oms_verified_by_user_id' => null,
                'oms_identity_evidence' => null,
            ])->save();
            $this->audit->record(
                AuditActor::user($actor, AuditSource::Web),
                $active ? 'product.sku_alias.activated' : 'product.sku_alias.deactivated',
                $alias::class,
                $alias->getKey(),
                before: ['is_active' => $wasActive],
                after: ['is_active' => $active, 'oms_verified_at' => null],
                reason: 'manual_sku_alias_status_change',
            );
        });
    }

    /** @return list<array<string,mixed>> */
    public function approvedAliasesForOms(int $productId): array
    {
        return array_values(array_filter(
            $this->aliases->listForProduct($productId),
            static fn (array $alias): bool => ($alias['is_active'] ?? false) === true
                && ! empty($alias['oms_verified_at'])
                && trim((string) ($alias['oms_identity_evidence'] ?? '')) !== ''
        ));
    }

    public function listForProduct(int $productId): array
    {
        return $this->aliases->listForProduct($productId);
    }

    public function list(array $filters = [], int $perPage = 50): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return $this->aliases->list($filters, $perPage);
    }
}
