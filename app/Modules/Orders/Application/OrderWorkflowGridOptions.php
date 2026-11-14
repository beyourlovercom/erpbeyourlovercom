<?php

namespace App\Modules\Orders\Application;

/** The exact customer-order dropdown commands exposed on Global Grid lists. */
final class OrderWorkflowGridOptions
{
    /** @return list<array<string, mixed>> */
    public static function customer(): array
    {
        $editable = ['pending_allocation', 'sending', 'out_of_stock', 'exception', 'cancelled', ...OrderManualHoldStatus::CODES];

        return collect(OrderWorkflowStatus::registry())
            ->map(fn (array $definition, string $code): array => [
                'code' => $code,
                ...$definition,
                'mutable' => in_array($code, $editable, true),
            ])->values()->all();
    }
}
