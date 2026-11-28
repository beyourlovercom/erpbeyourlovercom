<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Finance\Application\FxReferenceSettingsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read-only versioned quotation. Never falls back to a different warehouse, service or rate version. */
final class VersionedRateQuoteService
{
    public function __construct(private readonly FxReferenceSettingsService $fx) {}

    public function quote(array $request): array
    {
        return $this->calculate($request, false);
    }

    /** Draft transport-only reference, never an eligible/complete fulfillment quote. */
    public function quoteDraftTransport(array $request): array
    {
        return $this->calculate($request, true);
    }

    /** Explicit actual-weight-only reference; dimensions are unknown, never a complete quote. */
    public function quoteWeightOnlyReference(array $request, bool $draft): array
    {
        return $this->calculate($request, $draft, true);
    }

    private function calculate(array $request, bool $draft, bool $weightOnly = false): array
    {
        $id = $request['version_id'] ?? null;
        $date = $request['as_of'] ?? null;
        $warehouse = $request['warehouse_code'] ?? null;
        $carrier = $request['carrier'] ?? null;
        $service = $request['service'] ?? null;
        $country = $request['country'] ?? null;
        $postal = $request['postal_code'] ?? null;
        $packages = $request['packages'] ?? null;
        $handling = $request['handling_policy'] ?? 'internal';
        if (! is_int($id) || $id <= 0 || ! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
            || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))
            || ! is_string($warehouse) || ! $warehouse || ! is_string($carrier) || ! $carrier || ! is_string($service) || ! $service
            || ! is_string($country) || ! preg_match('/^[A-Z]{2}$/D', $country)
            || ! is_string($postal) || ! is_array($packages) || ! $packages || count($packages) > 100
            || ! in_array($handling, ['supplier', 'internal'], true)) {
            return $this->blocked('invalid_input');
        }
        $version = DB::table('logistics_rate_versions')->find($id);
        if ($draft) {
            if (! $version || $version->status !== 'draft') {
                return $this->blocked('draft_rate_version_required');
            }
        } elseif (! $version || $version->status !== 'approved' || ! $version->approved_at || ! $version->approved_by) {
            return $this->blocked('rate_version_not_approved');
        }
        if (! $version->effective_from || $date < $version->effective_from || ($version->effective_until && $date > $version->effective_until)) {
            return $this->blocked('rate_version_outside_effective_dates');
        }
        $rows = DB::table('logistics_rate_components')->where('version_id', $id)
            ->where('warehouse_code', $warehouse)->where('carrier', $carrier)->where('service', $service)
            ->where('destination_country', $country)->orderBy('id')->get();
        if ($draft) {
            $rows = $rows->where('component_type', 'transport')->values();
        }
        if ($rows->isEmpty()) {
            return $this->blocked('rate_scope_missing');
        }
        $zone = null;
        if ($rows->contains(fn ($r) => $r->destination_zone !== null)) {
            $zones = DB::table('logistics_rate_zones')->where('version_id', $id)
                ->where('warehouse_code', $warehouse)->where('carrier', $carrier)->where('service', $service)
                ->where('destination_country', $country)->get();
            $zip = trim($postal);
            if ($country !== 'US' || ! preg_match('/^\d{5}(?:-\d{4})?$/D', $zip)) {
                return $this->blocked('postal_code_invalid');
            }
            $zip = substr($zip, 0, 5);
            $matches = $zones->filter(function ($z) use ($zip) {
                if (! in_array($z->postal_match_type, ['prefix', 'range', 'exact'], true)) {
                    return false;
                }
                $start = $z->postal_start;
                $end = $z->postal_end ?? $start;
                if (! preg_match('/^(\d{3}|\d{5})$/D', $start) || strlen($start) !== strlen($end) || ! ctype_digit($end)) {
                    return false;
                }
                $value = substr($zip, 0, strlen($start));
                if ($z->postal_match_type === 'exact' && strlen($start) !== 5) {
                    return false;
                }

                return strcmp($value, $start) >= 0 && strcmp($value, $end) <= 0;
            })->values();
            if ($matches->count() !== 1 || (! $draft && $matches[0]->review_status !== 'verified') || ! preg_match('/^[1-9]$/D', $matches[0]->zone_label)) {
                return $this->blocked('postal_zone_missing_or_ambiguous');
            }
            $zone = $matches[0]->zone_label;
            $rows = $rows->filter(fn ($r) => $r->destination_zone === null || $r->destination_zone === $zone)->values();
        }
        if (! $draft && $rows->contains(fn ($r) => $r->review_status !== 'verified')) {
            return $this->blocked('rate_rule_unreviewed');
        }
        // Explicit coverage marker on approved transport rule; no implicit zero handling.
        $transport = $rows->where('component_type', 'transport')->values();
        $handlingRows = $rows->where('component_type', 'handling')->values();
        if ($transport->count() !== 1 || $handlingRows->count() > 1 || $rows->count() !== $transport->count() + $handlingRows->count()) {
            return $this->blocked('rate_components_missing_or_ambiguous');
        }
        $transportRule = json_decode($transport[0]->rule_spec, true);
        if (is_array($transportRule) && isset($transportRule['catalog_restriction_source_cell'])) {
            return $this->blocked('supplier_catalog_restriction');
        }

        if (! is_array($transportRule) || (! $draft && (($transportRule['coverage_complete'] ?? false) !== true
            || ($handlingRows->isEmpty() && ($transportRule['handling_included'] ?? false) !== true)))) {
            return $this->blocked('rate_coverage_unverified');
        }
        $result = [];
        $orderedRows = $transport->concat($handlingRows);
        $rateBands = DB::table('logistics_rate_bands')->whereIn('component_id', $orderedRows->pluck('id'))->orderBy('sequence')->get()->groupBy('component_id');
        foreach ($packages as $index => $package) {
            if (! is_array($package) || ($package['eligibility'] ?? null) !== 'confirmed') {
                return $this->blocked('eligibility_unconfirmed');
            }
            $rawWeight = $package['weight_kg'] ?? null;
            $basis = $package['weight_basis'] ?? null;
            $quantity = $package['item_count'] ?? null;
            $dims = $package['dimensions_cm'] ?? null;
            if (! $this->positive($rawWeight) || ! in_array($basis, ['measured_packaged', 'estimated_packaged', 'bare'], true)) {
                return $this->blocked('missing_weight');
            }
            if (! is_int($quantity) || $quantity < 1 || $quantity > 10000) {
                return $this->blocked('invalid_quantity');
            }
            if (! $weightOnly && (! is_array($dims) || count($dims) !== 3 || count(array_filter($dims, fn ($v) => $this->positive($v))) !== 3)) {
                return $this->blocked('missing_dimensions');
            }
            $gross = (float) $rawWeight * ($basis === 'bare' ? 1.2 : 1);
            if (! is_finite($gross) || $gross > 1000 || (! $weightOnly && max($dims) > 10000)) {
                return $this->blocked('invalid_measurement');
            }
            $dims = $weightOnly ? [] : array_values(array_map('floatval', $dims));
            foreach ($orderedRows as $row) {
                $out = $this->component($row, $gross, $dims, $quantity, $date, $postal, $handling, $warehouse, $rateBands->get($row->id, collect()), $weightOnly);
                if (isset($out['reason'])) {
                    return $this->blocked($out['reason']);
                }
                $result[] = ['package_index' => $index, 'component_id' => $row->id, 'component_key' => $row->component_key,
                    'component_type' => $row->component_type, 'source_sheet' => $row->source_sheet, 'weight_basis' => $basis,
                    'input_weight_kg' => (float) $rawWeight, 'gross_weight_kg' => $gross, 'currency' => $row->currency, ...$out];
            }
            if (! $draft && $warehouse !== 'FACTORY' && $handling === 'internal' && $quantity > 1) {
                $result[] = ['package_index' => $index, 'component_type' => 'extra_item_handling', 'component_key' => 'owner_internal_extra_item',
                    'currency' => 'USD', 'amount' => (float) ($quantity - 1), 'source_sheet' => null, 'source_cells' => null];
            }
        }
        $totals = [];
        foreach ($result as $line) {
            $totals[$line['currency']] = round(($totals[$line['currency']] ?? 0) + $line['amount'], 6);
        }
        $fx = $this->fx->convertToCny(array_map(fn ($currency, $amount) => ['currency' => $currency, 'revenue' => $amount], array_keys($totals), array_values($totals)));

        if ($weightOnly) {
            return ['status' => 'weight_only_reference', 'reason' => 'dimensions_unavailable', 'version_id' => $id, 'as_of' => $date, 'zone' => $zone,
                'components' => $result, 'native_totals' => null, 'total_cny' => null, 'reference_native_totals' => $totals,
                'reference_total_cny' => ($fx['available'] ?? false) ? $fx['amount_cny'] : null, 'fx' => $fx,
                'estimate_only' => true, 'eligible_for_ranking' => false, 'coverage' => $draft ? 'draft_actual_weight_transport_only' : 'actual_weight_only'];
        }
        if ($draft) {
            return ['status' => 'draft_estimate', 'reason' => 'unapproved_transport_reference', 'version_id' => $id, 'as_of' => $date, 'zone' => $zone,
                'components' => $result, 'native_totals' => null, 'total_cny' => null, 'reference_native_totals' => $totals,
                'reference_total_cny' => ($fx['available'] ?? false) ? $fx['amount_cny'] : null, 'fx' => $fx,
                'estimate_only' => true, 'eligible_for_ranking' => false, 'coverage' => 'transport_only'];
        }

        return ['status' => ($fx['available'] ?? false) ? 'ready' : 'fx_unavailable',
            'reason' => ($fx['available'] ?? false) ? null : ($fx['reason'] ?? 'fx_unavailable'),
            'version_id' => $id, 'as_of' => $date, 'zone' => $zone, 'components' => $result,
            'native_totals' => $totals, 'total_cny' => ($fx['available'] ?? false) ? $fx['amount_cny'] : null,
            'fx' => $fx, 'estimate_only' => true];
    }

    private function positive(mixed $v): bool
    {
        return is_numeric($v) && is_finite((float) $v) && (float) $v > 0;
    }

    private function blocked(string $reason): array
    {
        return ['status' => 'unavailable', 'reason' => $reason, 'components' => [], 'native_totals' => null, 'total_cny' => null, 'estimate_only' => true];
    }

    private function component(object $row, float $gross, array $dims, int $quantity, string $date, string $postal, string $handling, string $warehouse, Collection $rateBands, bool $weightOnly = false): array
    {
        $rule = json_decode($row->rule_spec, true);
        $known = ['basis', 'dim_divisor', 'volume_unit', 'volume_threshold', 'minimum', 'increment', 'max_actual_kg', 'max_billed',
            'max_dimensions_cm', 'min_dimensions_cm', 'max_girth_cm', 'excluded_zip_prefixes', 'fuel_percent', 'seasonal_fee', 'seasonal_mmdd',
            'coverage_complete', 'handling_included', 'rounding_tiers'];
        if (! is_array($rule) || array_diff(array_keys($rule), $known)) {
            return ['reason' => 'rule_unsupported'];
        }
        $unit = $row->weight_unit;
        $factor = match ($unit) {
            'kg' => 1.0, 'lb' => 1 / 0.45359237, 'oz' => 16 / 0.45359237, default => null
        };
        if ($factor === null || ! in_array($row->pricing_mode, ['fixed_band', 'linear_band'], true)) {
            return ['reason' => 'rule_unsupported'];
        }
        rsort($dims, SORT_NUMERIC);
        foreach (['max_dimensions_cm', 'min_dimensions_cm'] as $key) {
            if (! isset($rule[$key])) {
                continue;
            }
            if (! is_array($rule[$key]) || count($rule[$key]) !== 3 || count(array_filter($rule[$key], fn ($v) => $this->positive($v))) !== 3) {
                return ['reason' => 'rule_unsupported'];
            }
            $limits = array_values(array_map('floatval', $rule[$key]));
            rsort($limits, SORT_NUMERIC);
            foreach ($dims as $i => $dim) {
                if (($key === 'max_dimensions_cm' && $dim >= $limits[$i]) || ($key === 'min_dimensions_cm' && $dim < $limits[$i])) {
                    return ['reason' => 'package_dimensions_outside_rule'];
                }
            }
        }
        if (isset($rule['max_girth_cm']) && (! $this->positive($rule['max_girth_cm']) || (! $weightOnly && $rule['max_girth_cm'] <= $dims[0] + 2 * ($dims[1] + $dims[2])))) {
            return ['reason' => 'package_dimensions_outside_rule'];
        }
        if (isset($rule['max_actual_kg']) && (! $this->positive($rule['max_actual_kg']) || $gross > (float) $rule['max_actual_kg'])) {
            return ['reason' => 'package_weight_outside_rule'];
        }
        if (isset($rule['excluded_zip_prefixes'])) {
            if (trim($postal) === '') {
                return ['reason' => 'postal_code_invalid'];
            }
            if (! is_array($rule['excluded_zip_prefixes'])) {
                return ['reason' => 'rule_unsupported'];
            }
            foreach ($rule['excluded_zip_prefixes'] as $prefix) {
                if (! is_string($prefix) || $prefix === '') {
                    return ['reason' => 'rule_unsupported'];
                }
                if (str_starts_with($postal, $prefix)) {
                    return ['reason' => 'postal_rate_excluded'];
                }
            }
        }
        $actual = $gross * $factor;
        $volume = $weightOnly ? null : array_product($dims);
        $dim = null;
        $basis = $rule['basis'] ?? null;
        if (! in_array($basis, ['actual', 'max', 'conditional_max'], true)) {
            return ['reason' => 'rule_unsupported'];
        }
        $raw = $actual;
        if ($basis !== 'actual' && ! $weightOnly) {
            if (! $this->positive($rule['dim_divisor'] ?? null)) {
                return ['reason' => 'rule_unsupported'];
            }
            $volumeUnit = $rule['volume_unit'] ?? null;
            if ($volumeUnit === 'cm3_kg') {
                $volumeNative = $volume;
                $dim = ($volumeNative / (float) $rule['dim_divisor']) * $factor;
            } elseif ($volumeUnit === 'in3_lb') {
                $volumeNative = $volume / (2.54 ** 3);
                $dim = ($volumeNative / (float) $rule['dim_divisor']) * 0.45359237 * $factor;
            } else {
                return ['reason' => 'rule_unsupported'];
            }
            if ($basis === 'conditional_max' && ! $this->positive($rule['volume_threshold'] ?? null)) {
                return ['reason' => 'rule_unsupported'];
            }
            if ($basis === 'max' || $volumeNative > (float) $rule['volume_threshold']) {
                $raw = max($actual, $dim);
            }
        }
        if ($weightOnly && $basis !== 'actual' && (! $this->positive($rule['dim_divisor'] ?? null)
            || ! in_array($rule['volume_unit'] ?? null, ['cm3_kg', 'in3_lb'], true)
            || ($basis === 'conditional_max' && ! $this->positive($rule['volume_threshold'] ?? null)))) {
            return ['reason' => 'rule_unsupported'];
        }
        $billed = $raw;
        if (isset($rule['minimum'])) {
            if (! $this->positive($rule['minimum'])) {
                return ['reason' => 'rule_unsupported'];
            }
            $billed = max($billed, (float) $rule['minimum']);
        }
        if (isset($rule['rounding_tiers'])) {
            $tiers = $rule['rounding_tiers'];
            if (isset($rule['increment']) || ! is_array($tiers) || ! array_is_list($tiers) || $tiers === []) {
                return ['reason' => 'rule_unsupported'];
            }
            $previous = 0.0;
            $step = null;
            foreach ($tiers as $i => $tier) {
                if (! is_array($tier) || ! array_key_exists('up_to', $tier) || ! $this->positive($tier['increment'] ?? null)) {
                    return ['reason' => 'rule_unsupported'];
                }
                $limit = $tier['up_to'];
                if ($limit === null) {
                    if ($i !== count($tiers) - 1) {
                        return ['reason' => 'rule_unsupported'];
                    }
                } elseif (! $this->positive($limit) || (float) $limit <= $previous) {
                    return ['reason' => 'rule_unsupported'];
                }
                if ($step === null && ($limit === null || $billed <= (float) $limit)) {
                    $step = (float) $tier['increment'];
                }
                if ($limit !== null) {
                    $previous = (float) $limit;
                }
            }
            if ($step === null || $tiers[count($tiers) - 1]['up_to'] !== null) {
                return ['reason' => 'rule_unsupported'];
            }
            $billed = ceil(round($billed / $step, 10)) * $step;
        } elseif (isset($rule['increment'])) {
            if (! $this->positive($rule['increment'])) {
                return ['reason' => 'rule_unsupported'];
            }
            $step = (float) $rule['increment'];
            $billed = ceil(round($billed / $step, 10)) * $step;
        }
        $billed = round($billed, 9);
        if (isset($rule['max_billed']) && (! $this->positive($rule['max_billed']) || $billed > (float) $rule['max_billed'])) {
            return ['reason' => 'package_weight_outside_rule'];
        }
        $bands = $rateBands->filter(function ($band) use ($billed) {
            $min = $band->min_weight === null || ($band->min_inclusive ? $billed >= (float) $band->min_weight : $billed > (float) $band->min_weight);
            $max = $band->max_weight === null || ($band->max_inclusive ? $billed <= (float) $band->max_weight : $billed < (float) $band->max_weight);

            return $min && $max;
        })->values();
        if ($bands->count() !== 1) {
            return ['reason' => 'weight_band_missing_or_ambiguous'];
        }
        $band = $bands[0];
        if (! is_numeric($band->fixed_amount) || (float) $band->fixed_amount < 0) {
            return ['reason' => 'invalid_rate_amount'];
        }
        $base = (float) $band->fixed_amount;
        if ($row->pricing_mode === 'linear_band') {
            if (! is_numeric($band->per_weight_amount) || (float) $band->per_weight_amount < 0) {
                return ['reason' => 'invalid_rate_amount'];
            }
            $base += $billed * (float) $band->per_weight_amount;
        }
        $extra = 0.0;
        if ($row->component_type === 'handling' && $quantity > 1 && ($handling === 'supplier' || $warehouse === 'FACTORY')) {
            if (! is_numeric($band->per_extra_item_amount) || (float) $band->per_extra_item_amount < 0) {
                return ['reason' => 'extra_item_rate_missing'];
            }
            $extra = ($quantity - 1) * (float) $band->per_extra_item_amount;
        }
        $seasonal = 0.0;
        if (array_key_exists('seasonal_fee', $rule)) {
            $range = $rule['seasonal_mmdd'] ?? null;
            if (! is_numeric($rule['seasonal_fee']) || (float) $rule['seasonal_fee'] < 0 || ! is_array($range) || count($range) !== 2 || ! is_string($range[0]) || ! is_string($range[1]) || $range[0] > $range[1]) {
                return ['reason' => 'rule_unsupported'];
            }
            $md = substr($date, 5);
            if ($md >= $range[0] && $md <= $range[1]) {
                $seasonal = (float) $rule['seasonal_fee'];
            }
        }
        $fuel = $rule['fuel_percent'] ?? 0;
        if (! is_numeric($fuel) || (float) $fuel < 0 || (float) $fuel > 100) {
            return ['reason' => 'rule_unsupported'];
        }
        $amount = round(($base + $extra + $seasonal) * (1 + (float) $fuel / 100), 6);

        return ['amount' => $amount, 'base_amount' => round($base, 6), 'extra_item_amount' => round($extra, 6),
            'seasonal_amount' => $seasonal, 'fuel_amount' => round($amount - $base - $extra - $seasonal, 6),
            'actual_weight' => round($actual, 9), 'dimensional_weight' => $dim === null ? null : round($dim, 9),
            'pre_rounding_weight' => round($raw, 9), 'billable_weight' => $billed, 'weight_unit' => $unit,
            'band_id' => $band->id, 'source_cells' => $band->source_cells, 'rule' => $rule];
    }
}
