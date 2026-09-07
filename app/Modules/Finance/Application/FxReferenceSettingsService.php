<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Application\Contracts\FxManualRateRepository;

final class FxReferenceSettingsService
{
    private const CURRENCIES = [
        'USD' => '美元',
        'EUR' => '欧元',
        'GBP' => '英镑',
        'CAD' => '加元',
        'AUD' => '澳元',
        'CNY' => '人民币',
    ];

    public function __construct(
        private readonly EcbFxService $ecb,
        private readonly FxManualRateRepository $manualRates,
    ) {}

    public function snapshot(): array
    {
        $manual = $this->manualRates->all();
        $reference = $this->ecb->convertToCny(array_map(
            fn (string $currency): array => ['currency' => $currency, 'revenue' => 1.0],
            array_keys(self::CURRENCIES),
        ));

        $ecbRates = [];
        if ($reference['available'] ?? false) {
            foreach ($reference['conversions'] as $conversion) {
                $ecbRates[$conversion['original_currency']] = round((float) $conversion['rate_to_cny'], 8);
            }
        }

        $rows = [];
        foreach (self::CURRENCIES as $currency => $label) {
            $manualRate = $manual[$currency] ?? null;
            $ecbRate = $currency === 'CNY' ? 1.0 : ($ecbRates[$currency] ?? null);
            $effective = $currency === 'CNY' ? 1.0 : ($manualRate ?? $ecbRate);
            $source = $currency === 'CNY' ? 'fixed' : ($manualRate !== null ? 'manual' : ($ecbRate !== null ? 'ecb' : 'unavailable'));
            $rows[] = [
                'currency' => $currency,
                'label' => $label,
                'ecbRate' => $ecbRate,
                'manualRate' => $manualRate,
                'effectiveRate' => $effective,
                'source' => $source,
                'manualEditable' => $currency !== 'CNY',
            ];
        }

        return [
            'rows' => $rows,
            'reference' => [
                'source' => 'ECB',
                'available' => (bool) ($reference['available'] ?? false),
                'referenceDate' => $reference['reference_date'] ?? null,
                'stale' => (bool) ($reference['stale'] ?? false),
                'refreshHours' => 12,
                'policy' => 'manual_first',
            ],
        ];
    }

    /** @param array<string, mixed> $rates */
    public function saveManualRates(array $rates, ?int $actorId): void
    {
        $allowed = array_filter(array_keys(self::CURRENCIES), fn (string $currency): bool => $currency !== 'CNY');
        $normalized = [];
        foreach ($rates as $currency => $value) {
            $currency = strtoupper($currency);
            if (! in_array($currency, $allowed, true)) {
                continue;
            }
            $normalized[$currency] = $value === null || $value === '' ? null : (float) $value;
        }
        $this->manualRates->replace($normalized, $actorId);
    }
}
