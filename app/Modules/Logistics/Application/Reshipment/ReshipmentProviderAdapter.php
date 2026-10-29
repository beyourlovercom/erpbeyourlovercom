<?php
namespace App\Modules\Logistics\Application\Reshipment;
interface ReshipmentProviderAdapter {
 public function providerKey(): string;
 public function assertCatalogReady(array $context): void;
 public function findExistingExact(array $context): ?array;
 public function createDraft(array $context): array;
 public function readStatus(array $context): ?array;
}
