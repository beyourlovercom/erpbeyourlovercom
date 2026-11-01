<?php

namespace App\Modules\Logistics\Experience\Console\Commands;

use App\Modules\Logistics\Application\ShipOut\ShipOutProductionCanaryDispatchService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Explicit, human-run Production Canary operator entry; no web route, cron,
 * batch processing, queued retries, or automatic enabling of Production flags.
 *
 * This command is not a substitute for a separately approved deployment or
 * an authorized Production execution path.
 */
final class ShipOutProductionCanaryOperatorCommand extends Command
{
    protected $signature = 'shipout:production-canary
        {order-id : Exact integer ERP order ID}
        {--ship-date= : Required local Los Angeles shipping date YYYY-MM-DD}
        {--apply : Execute one already-approved one-shot Canary; OFF by default}
        {--payload-hash= : Exact approved SHA-256 for this payload and date}
        {--confirm= : Exact SUBMIT_SHIPOUT_ORDER_<ERP-ID> confirmation}';

    protected $description = 'Preview one approved ShipOut Production Canary; apply requires separate explicit operator gate.';

    public function handle(ShipOutProductionCanaryDispatchService $service): int
    {
        if (! app()->environment('production')) {
            $this->error('SHIPOUT_OPERATOR_PRODUCTION_ONLY');

            return self::FAILURE;
        }

        $orderIdText = (string) $this->argument('order-id');
        $shipDate = (string) $this->option('ship-date');
        $apply = (bool) $this->option('apply');

        if (preg_match('/^[1-9][0-9]*$/D', $orderIdText) !== 1
            || filter_var($orderIdText, FILTER_VALIDATE_INT) === false
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $shipDate) !== 1) {
            $this->error('SHIPOUT_OPERATOR_EXACT_ORDER_AND_DATE_REQUIRED');

            return self::FAILURE;
        }
        $orderId = (int) $orderIdText;

        if ($apply && ! (bool) config('shipout.production_operator_cli_enabled', false)) {
            $this->error('SHIPOUT_OPERATOR_APPLY_DISABLED');

            return self::FAILURE;
        }

        if ($apply) {
            $hash = trim((string) $this->option('payload-hash'));
            $confirmation = trim((string) $this->option('confirm'));
            if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1
                || ! hash_equals('SUBMIT_SHIPOUT_ORDER_'.$orderId, $confirmation)) {
                $this->error('SHIPOUT_OPERATOR_EXACT_HASH_AND_CONFIRMATION_REQUIRED');

                return self::FAILURE;
            }

            // No preview-and-then-reuse: dispatchOne() performs fresh signed
            // stock, product, duplicate and attempt-ledger checks immediately
            // before its single provider POST, gated by time-limited approval.
            try {
                $result = $service->dispatchOne($orderId, $shipDate, $hash, $confirmation);
                $this->line((string) json_encode([
                    'mode' => 'one_shot',
                    'order_id' => $orderId,
                    'status' => $result['status'],
                    'provider_order_id' => $result['provider_order_id'],
                    'result_code' => $result['result_code'],
                    'shipped' => false,
                    'tracking_apply' => false,
                ], JSON_UNESCAPED_SLASHES));

                // A provider rejection is an observed final outcome, not a
                // successful warehouse order. Both outcomes remain one-shot.
                return $result['status'] === 'acknowledged'
                    ? self::SUCCESS : self::FAILURE;
            } catch (\Throwable $error) {
                // Unknown POST outcome MUST NOT be retried. The service has
                // already reserved the unique attempt before any network I/O.
                $this->error($error instanceof RuntimeException
                    ? $error->getMessage() : 'SHIPOUT_OPERATOR_UNKNOWN_RESULT_NO_RETRY');

                return self::FAILURE;
            }
        }

        if (! (bool) config('shipout.production_read_enabled', false)) {
            $this->error('SHIPOUT_OPERATOR_PREVIEW_READ_DISABLED');

            return self::FAILURE;
        }

        try {
            // Preview is GET-only and deliberately excludes recipient PII.
            $summary = $service->preview($orderId, $shipDate);
            $this->line((string) json_encode([
                'mode' => 'preview',
                'order_id' => $summary['order_id'],
                'order_no' => $summary['order_no'],
                'warehouse_id' => $summary['warehouse_id'],
                'shipping_mode' => $summary['shipping_mode'],
                'ship_date_la' => $shipDate,
                'items' => $summary['items'],
                'payload_hash' => $summary['payload_hash'],
                'ready' => $summary['ready'],
                'mutated' => false,
            ], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error instanceof RuntimeException
                ? $error->getMessage() : 'SHIPOUT_OPERATOR_PREVIEW_FAILED');

            return self::FAILURE;
        }
    }
}
