<?php

namespace App\Modules\Products\Application\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

interface ProductRepository
{
    public function findByWooId(int $wooId): ?Model;

    public function create(array $attributes): Model;

    public function update(Model $product, array $attributes): void;

    public function delete(Model $product): void;

    public function findById(int $id): ?Model;

    public function findBySku(string $sku): ?Model;

    /** @return list<array{id:int,sku:string,name:string}> */
    public function searchSkus(string $query, int $limit = 20): array;

    public function list(array $filters = [], int $perPage = 50): LengthAwarePaginator;

    public function totalCount(): int;
}
