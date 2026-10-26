<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;

interface XlwmsInventoryGateway
{
    /** @return array<string,mixed> */
    public function productPage(WarehouseOpenApiConnection $connection, array $data): array;

    /** @return array<string,mixed> */
    public function integratedInventoryPage(WarehouseOpenApiConnection $connection, array $data): array;
}
