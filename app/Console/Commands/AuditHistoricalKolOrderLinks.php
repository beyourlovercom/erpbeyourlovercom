<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class AuditHistoricalKolOrderLinks extends Command
{
    protected $signature = 'kol:audit-order-links {--output= : Absolute or relative local CSV output path}';

    protected $description = 'Read-only KOL fulfillment linkage evidence report. Never updates an order.';

    public function handle(): int
    {
        $db = DB::connection();
        $pdo = $db->getPdo();
        $readonly = $db->getDriverName() === 'mysql';
        if ($readonly) {
            $pdo->exec('START TRANSACTION READ ONLY');
        } else {
            $db->beginTransaction();
        }
        $path = (string) ($this->option('output') ?: '');
        $handle = null;

        try {
            if ($path !== '') {
                if (file_exists($path)) {
                    $this->error('Output exists: refusing to overwrite an existing report.');

                    return self::FAILURE;
                }
                $handle = fopen($path, 'x');
                if ($handle === false) {
                    $this->error('Cannot create output CSV.');

                    return self::FAILURE;
                }
                fputcsv($handle, ['erp_order_id', 'record_id', 'status', 'candidate_kol_id', 'evidence']);
            }
            $counts = [
                'total' => 0, 'explicitly_linked' => 0, 'formal_collaboration_candidate' => 0,
                'conflict' => 0, 'referral_review_only' => 0, 'missing_evidence' => 0,
            ];
            $orders = $db->table('orders')->where('order_kind', 'kol_fulfillment')
                ->orderBy('id')->get(['id', 'erp_order_id', 'kol_id']);

            foreach ($orders as $order) {
                ++$counts['total'];
                $status = 'missing_evidence';
                $candidateId = null;
                $evidence = 'none';

                if ($order->kol_id !== null) {
                    $status = 'explicitly_linked';
                    $candidateId = (int) $order->kol_id;
                    $evidence = 'orders.kol_id';
                } else {
                    $formal = $db->table('kol_collaborations')->where('order_id', $order->id)
                        ->whereNotNull('kol_id')->distinct()->pluck('kol_id')->map(fn ($v) => (int) $v)->all();
                    if (count($formal) > 1) {
                        $status = 'conflict';
                        $evidence = 'multiple_formal_collaborations';
                    } elseif (count($formal) === 1 && $db->table('kols')->where('id', $formal[0])->exists()) {
                        $status = 'formal_collaboration_candidate';
                        $candidateId = $formal[0];
                        $evidence = 'kol_collaborations.order_id';
                    } else {
                        // Affiliate referrals represent SALES attribution, not proof of a fulfillment order.
                        $referrals = $db->table('kol_affiliate_referrals')
                            ->where('connection_key', 'beyourlover.com')
                            ->where('order_id', $order->id)->whereNotNull('kol_id')
                            ->distinct()->pluck('kol_id')->map(fn ($v) => (int) $v)->all();
                        if (count($referrals) > 1) {
                            $status = 'conflict';
                            $evidence = 'multiple_affiliate_referrals_review_only';
                        } elseif (count($referrals) === 1) {
                            $status = 'referral_review_only';
                            $candidateId = $referrals[0];
                            $evidence = 'affiliate_sales_attribution_not_fulfillment_proof';
                        }
                    }
                }
                ++$counts[$status];
                if ($handle !== null) {
                    fputcsv($handle, [$order->erp_order_id, $order->id, $status, $candidateId, $evidence]);
                }
            }

            $this->line(json_encode([
                'mode' => 'READ_ONLY', 'auto_match' => false, 'database_updates' => 0,
                'counts' => $counts, 'csv' => $path !== '' ? $path : null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if ($readonly) {
                $pdo->exec('ROLLBACK');
            } else {
                $db->rollBack();
            }
        }
    }
}
