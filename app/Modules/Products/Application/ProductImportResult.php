<?php

namespace App\Modules\Products\Application;

final class ProductImportResult
{
    public function __construct(
        public readonly int $inserted,
        public readonly int $updated,
        public readonly int $skipped,
        public readonly int $invalid,
        public readonly array $errors = [],
    ) {}

    public function toArray(): array
    {
        return [
            'inserted' => $this->inserted,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'invalid' => $this->invalid,
            'errors' => $this->errors,
        ];
    }
}
