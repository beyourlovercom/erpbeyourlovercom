<?php

namespace App\Modules\Logistics\Application\Xlwms;

use RuntimeException;

/** Strict, provider-facing parser. It deliberately knows no ERP models. */
final class XlwmsInventoryPageParser
{
    /** @return array{rows:list<array<string,mixed>>,total:int,page:int,pageSize:int,pages:int} */
    public function productPage(array $response, int $requestedPage, int $requestedPageSize): array
    {
        return $this->parse($response, $requestedPage, $requestedPageSize, null);
    }

    /** @return array{rows:list<array<string,mixed>>,total:int,page:int,pageSize:int,pages:int} */
    public function inventoryPage(array $response, string $expectedWhCode, int $requestedPage, int $requestedPageSize): array
    {
        return $this->parse($response, $requestedPage, $requestedPageSize, $expectedWhCode);
    }

    /** @return array{rows:list<array<string,mixed>>,total:int,page:int,pageSize:int,pages:int} */
    private function parse(array $response, int $requestedPage, int $requestedPageSize, ?string $expectedWhCode): array
    {
        if ($requestedPage < 1 || $requestedPageSize < 1) throw new RuntimeException('OMS_PAGE_REQUEST_INVALID');
        if (! array_key_exists('code', $response) || ! array_key_exists('data', $response) || (int) ($response['code'] ?? -1) !== 200) {
            throw new RuntimeException('OMS_BUSINESS_REJECTED');
        }
        $data = $response['data'] ?? null;
        if (! is_array($data)) throw new RuntimeException('OMS_PAGE_SHAPE_INVALID');
        if (array_diff(['records', 'total', 'page', 'pageSize', 'pages'], array_keys($data)) !== []) throw new RuntimeException('OMS_PAGE_SHAPE_INVALID');
        $rows = $data['records'];
        $total = $data['total'];
        $page = $data['page'];
        $pageSize = $data['pageSize'];
        $pages = $data['pages'];
        if (! is_array($rows) || ! $this->isNonNegativeInteger($total) || ! $this->isPositiveInteger($page) || ! $this->isPositiveInteger($pageSize) || ! $this->isNonNegativeInteger($pages)) throw new RuntimeException('OMS_PAGE_TOTAL_INVALID');
        $total = (int) $total; $page = (int) $page; $pageSize = (int) $pageSize; $pages = (int) $pages;
        if ($page !== $requestedPage || $pageSize !== $requestedPageSize || $pages !== (int) ceil($total / $pageSize) || count($rows) > $pageSize || $page > max($pages, 1)) throw new RuntimeException('OMS_PAGE_INCOMPLETE');
        foreach ($rows as $row) {
            if (! is_array($row)) throw new RuntimeException('OMS_ROW_SHAPE_INVALID');
            if ($expectedWhCode !== null && (string) ($row['whCode'] ?? '') !== $expectedWhCode) throw new RuntimeException('OMS_WAREHOUSE_SCOPE_MISMATCH');
        }
        return ['rows' => array_values($rows), 'total' => $total, 'page' => $page, 'pageSize' => $pageSize, 'pages' => $pages];
    }

    private function isNonNegativeInteger(mixed $value): bool
    {
        return ! is_bool($value) && ((is_int($value) && $value >= 0) || (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/', $value) === 1));
    }

    private function isPositiveInteger(mixed $value): bool
    {
        return $this->isNonNegativeInteger($value) && (int) $value > 0;
    }

    public function nonNegativeInt(mixed $value, string $field): int
    {
        if (! $this->isNonNegativeInteger($value)) {
            throw new RuntimeException('OMS_INVALID_NUMBER_'.$field);
        }
        return (int) $value;
    }
}
