<?php

namespace App\Modules\Products\Application;

/**
 * Conservative extraction of a base SKU from domestic inventory book evidence.
 * A suggestion inferred solely from a factory long code must be confirmed by an operator.
 */
final class InventoryCostSkuExtractor
{
    /** @return array{base_sku:?string,status:string,candidates:list<string>,evidence:string} */
    public static function extract(string $productName, ?string $factoryCode = null): array
    {
        $name = mb_strtoupper(trim($productName), 'UTF-8');
        $code = strtoupper(trim((string) $factoryCode));

        // Delimiters include Chinese parentheses, so "VA617A（国内新版）" is VA617A.
        preg_match_all('/(?<![A-Z0-9])(?:[A-Z][0-9][A-Z]{1,3}[0-9]|[A-Z]{1,5}[0-9]{1,5}[A-Z]?(?:-[A-Z0-9]{1,5})?)(?![A-Z0-9])/u', $name, $matches);
        // Marketing generation labels (e.g. APP2024) are not product SKUs.
        $named = array_values(array_unique(array_filter($matches[0] ?? [], static fn (string $candidate) => ! preg_match('/^APP20[0-9]{2}$/D', $candidate))));

        // Factory example 1BY489A5C0100 is not a canonical SKU. Suggest BY489A,
        // but never silently authorize a cost posting on this inference.
        $fromCode = null;
        if (preg_match('/^1([A-Z]{2}[0-9]{3}[A-Z])[0-9][A-Z0-9]{1,}$/D', $code, $codeMatch)) {
            $fromCode = $codeMatch[1];
        } elseif (preg_match('/^([A-Z]{2,5}[0-9]{2,5}[A-Z](?:-[A-Z0-9]{1,5})?)$/D', $code, $codeMatch)) {
            $fromCode = $codeMatch[1];
        }

        $candidates = array_values(array_unique(array_merge($named, $fromCode === null ? [] : [$fromCode])));
        if (count($named) === 1) {
            $fromName = $named[0];
            // Color suffixes are variants; suggest the base but force an ERP mapping confirmation.
            if (preg_match('/^(.+)-(?:PINK|GREEN|BLACK|BLUE|WHITE|PURPLE|P|G|Y)$/D', $fromName, $color)) {
                return ['base_sku' => $color[1], 'status' => 'needs_confirmation', 'candidates' => array_values(array_unique([$fromName, $color[1], ...($fromCode === null ? [] : [$fromCode])])), 'evidence' => 'color_variant_suffix'];
            }
            if ($fromCode !== null && $fromCode !== $fromName) {
                return ['base_sku' => $fromName, 'status' => 'ambiguous', 'candidates' => $candidates, 'evidence' => 'name_factory_code_disagree'];
            }

            return ['base_sku' => $fromName, 'status' => 'extracted', 'candidates' => $candidates, 'evidence' => 'product_name'];
        }
        if (count($candidates) > 1) {
            return ['base_sku' => null, 'status' => 'ambiguous', 'candidates' => $candidates, 'evidence' => 'multiple_candidates'];
        }
        if (count($named) === 0 && $fromCode !== null) {
            return ['base_sku' => $fromCode, 'status' => 'needs_confirmation', 'candidates' => [$fromCode], 'evidence' => 'factory_long_code'];
        }

        return ['base_sku' => null, 'status' => 'unmatched', 'candidates' => [], 'evidence' => 'no_reliable_sku'];
    }
}
