<?php
/**
 * Read-only Woo Completed ↔ ERP fulfillment audit.
 *
 * Usage:
 * ERP_RUNTIME_ROOT=/Users/a/AI/production/erp.beyourlover.com/current \
 * php scripts/ops/audit_woo_completed_orders.php --output-dir=/tmp/byl-completed-audit
 *
 * Reads Woo order API and ERP database. Does not modify Woo, ERP, or send mail.
 * Only order IDs/operational states are written. No PII or tracking values.
 */
declare(strict_types=1);

use App\Modules\Orders\Infrastructure\WooCommerce\HttpWooCommerceOrderGateway;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$runtimeRoot = getenv('ERP_RUNTIME_ROOT') ?: dirname(__DIR__, 2);
require_once $runtimeRoot.'/vendor/autoload.php';
$app = require $runtimeRoot.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$opts = getopt('', ['output-dir:', 'max-pages::']);
$outDir = $opts['output-dir'] ?? (sys_get_temp_dir().'/byl-completed-audit');
$maxPages = isset($opts['max-pages']) ? max(1, (int) $opts['max-pages']) : 9999;
if (!is_dir($outDir) && !mkdir($outDir, 0700, true) && !is_dir($outDir)) {
    throw new RuntimeException('Cannot create report directory');
}
$stamp = gmdate('Ymd_His');
$csvPath = $outDir.'/completed_erp_diff_'.$stamp.'.csv';
$handle = fopen($csvPath, 'wb');
if ($handle === false) {
    throw new RuntimeException('Cannot open report file');
}
fputcsv($handle, [
    'woo_id','erp_id','erp_order_number','erp_order_status','classification',
    'fulfillment_segments','erp_segments_with_tracking','woo_tracking_packages',
    'woo_date_completed','woo_date_modified','woo_ship_date_after_completion'
]);
$counts = [
    'completed_checked' => 0,
    'matched_erp' => 0,
    'no_erp_reference' => 0,
    'woo_no_tracking_erp_no_tracking' => 0,
    'woo_tracking_erp_no_tracking' => 0,
    'erp_partial_fulfillment' => 0,
    'erp_fulfilled' => 0,
    'woo_ship_date_after_completion' => 0,
];
$bothMissing = [];
$examples = [];
$gateway = app(HttpWooCommerceOrderGateway::class);
$totalPages = null;
$scannedPages = 0;

function trackingEvidence(array $woo): array {
    $packages = [];
    $legacyNumber = '';
    $legacyProvider = '';
    foreach (($woo['meta_data'] ?? []) as $meta) {
        if (!is_array($meta)) {
            continue;
        }
        $key = (string) ($meta['key'] ?? '');
        $value = $meta['value'] ?? null;
        if ($key === '_aftership_tracking_items' && is_array($value)) {
            foreach ($value as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $number = trim((string) ($item['tracking_number'] ?? ''));
                if ($number === '') {
                    continue;
                }
                $packages[] = [
                    'carrier' => trim((string) ($item['slug'] ?? $item['provider'] ?? '')),
                    'ship_date' => (string) ($item['additional_fields']['ship_date'] ?? ''),
                ];
            }
        }
        if ($key === '_aftership_tracking_number') {
            $legacyNumber = trim((string) $value);
        }
        if ($key === '_aftership_tracking_provider_name') {
            $legacyProvider = trim((string) $value);
        }
    }
    if ($packages === [] && $legacyNumber !== '') {
        $packages[] = ['carrier' => $legacyProvider, 'ship_date' => ''];
    }
    return $packages;
}

try {
    for ($page = 1; $page <= $maxPages; $page++) {
        $pageResult = $gateway->fetchOrders([
            'status' => 'completed', 'per_page' => 100, 'page' => $page,
            'orderby' => 'modified', 'order' => 'desc',
        ]);
        $wooOrders = (array) ($pageResult['items'] ?? []);
        if ($totalPages === null) {
            $totalPages = (int) ($pageResult['total_pages'] ?? 1);
        }
        if ($wooOrders === []) {
            break;
        }
        $ids = array_values(array_filter(array_map(
            static fn ($woo): int => (int) ($woo['id'] ?? 0), $wooOrders
        )));
        $refs = DB::table('order_external_references')
            ->where('provider', 'woocommerce')->whereIn('reference', $ids)->get()->keyBy('reference');
        $rootIds = $refs->pluck('order_id')->all();
        $roots = DB::table('orders')->whereIn('id', $rootIds)->get()->keyBy('id');
        $children = DB::table('orders')->whereIn('fulfillment_parent_id', $rootIds)
            ->whereNull('deleted_at')->get();
        $childIds = $children->pluck('id')->all();
        $shipments = DB::table('shipments')->whereIn('order_id', array_merge($rootIds, $childIds))
            ->get()->keyBy('order_id');

        foreach ($wooOrders as $woo) {
            $counts['completed_checked']++;
            $id = (int) $woo['id'];
            $ref = $refs->get((string) $id);
            $root = $ref ? $roots->get($ref->order_id) : null;
            $packages = trackingEvidence($woo);
            $completedAt = (string) ($woo['date_completed'] ?? '');
            $modifiedAt = (string) ($woo['date_modified'] ?? '');

            if (!$root) {
                $counts['no_erp_reference']++;
                fputcsv($handle, [$id, '', '', '', 'no_erp_reference', '', '', count($packages), $completedAt, $modifiedAt, '']);
                continue;
            }
            $counts['matched_erp']++;
            $segments = $children->where('fulfillment_parent_id', $root->id);
            $segmentIds = $segments->isNotEmpty() ? $segments->pluck('id')->all() : [$root->id];
            $withProof = 0;
            foreach ($segmentIds as $sid) {
                $ship = $shipments->get($sid);
                if ($ship && in_array($ship->shipment_status, ['shipped','completed','exception'], true)
                    && trim((string) $ship->tracking_number) !== ''
                    && trim((string) $ship->carrier) !== '') {
                    $withProof++;
                }
            }

            if ($withProof === 0 && $packages === []) {
                $class = 'woo_no_tracking_erp_no_tracking';
                if (count($bothMissing) < 200) {
                    $bothMissing[] = ['woo_id'=>$id,'erp_id'=>(int)$root->id,
                        'erp_no'=>(string)$root->external_order_number];
                }
            } elseif ($withProof === 0) {
                $class = 'woo_tracking_erp_no_tracking';
            } elseif ($withProof < count($segmentIds)) {
                $class = 'erp_partial_fulfillment';
            } else {
                $class = 'erp_fulfilled';
            }
            $counts[$class]++;
            $laterDate = false;
            foreach ($packages as $package) {
                if ($completedAt !== '' && ($package['ship_date'] ?? '') !== ''
                    && substr($completedAt, 0, 10) < substr($package['ship_date'], 0, 10)) {
                    $laterDate = true;
                    break;
                }
            }
            if ($laterDate) {
                $counts['woo_ship_date_after_completion']++;
            }
            fputcsv($handle, [
                $id, $root->id, $root->external_order_number, $root->order_status,
                $class, count($segmentIds), $withProof, count($packages),
                $completedAt, $modifiedAt, $laterDate ? 'yes' : 'no',
            ]);
            if (count($examples) < 20 && $class !== 'erp_fulfilled') {
                $examples[] = ['woo_id'=>$id,'erp_id'=>(int)$root->id,'type'=>$class];
            }
        }
        $scannedPages++;
        echo "PAGE {$page}/{$totalPages}; analyzed=".count($wooOrders).PHP_EOL;
        if ($page >= $totalPages) {
            break;
        }
    }
} finally {
    fclose($handle);
}
$complete = $totalPages !== null && $scannedPages >= $totalPages;
$summary = [
    'scope' => 'WooCommerce completed orders',
    'read_only' => true, 'scan_complete' => $complete,
    'total_pages' => $totalPages, 'scanned_pages' => $scannedPages,
    'counts' => $counts, 'both_missing_first_200' => $bothMissing,
    'examples' => $examples, 'csv_path' => $csvPath,
    'generated_utc' => gmdate(DATE_ATOM),
];
$jsonPath = $outDir.'/completed_erp_summary_'.$stamp.'.json';
file_put_contents($jsonPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "AUDIT_SUMMARY ".json_encode($summary, JSON_UNESCAPED_UNICODE).PHP_EOL;
