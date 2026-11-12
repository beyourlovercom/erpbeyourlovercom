<?php
/**
 * BYL P2.3 Gate A — read-only, non-secret Woo target inspection.
 *
 * Usage: php tools/audit/p23-woo-dev-target.php --inspect
 *        php tools/audit/p23-woo-dev-target.php --self-test
 *
 * NO Woo REST calls, no ERP order changes, no token/key output.
 * Produces a prerequisite assessment, NEVER grants permission to sync.
 */
declare(strict_types=1);

function bylP23ClassifyWooTarget(array $s): array
{
    $raw = trim((string) ($s['url'] ?? ''));
    $parts = $raw !== '' ? parse_url($raw) : false;
    $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
    $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
    $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
    $key = trim((string) ($s['connection_key'] ?? ''));
    $environment = trim((string) ($s['environment'] ?? 'unknown'));
    $hasCredentials = !empty($s['consumer_key']) && !empty($s['consumer_secret']);

    $classification = match ($host) {
        'dev.beyourlover.com' => 'DEV',
        'www.beyourlover.com', 'beyourlover.com' => 'WWW_PRODUCTION',
        '' => 'UNSET',
        default => 'OTHER_OR_UNVERIFIED',
    };
    $candidate = $classification === 'DEV'
        && $scheme === 'https'
        && ($path === '' || $path === '/')
        && $hasCredentials
        && $key !== ''
        && $key !== 'beyourlover.com'
        && in_array($environment, ['local', 'staging', 'testing'], true);

    return [
        'app_environment' => $environment,
        'woocommerce_target_class' => $classification,
        'woocommerce_target_hostname' => $host !== '' ? $host : null,
        'https_endpoint' => $scheme === 'https',
        'base_url_root_only' => $path === '' || $path === '/',
        'consumer_key_present' => !empty($s['consumer_key']),
        'consumer_secret_present' => !empty($s['consumer_secret']),
        'connection_key_present' => $key !== '',
        'connection_key_is_production_default' => $key === 'beyourlover.com',
        'order_import_sync_enabled' => (bool) ($s['order_sync_enabled'] ?? false),
        'config_cached' => (bool) ($s['config_cached'] ?? false),
        'dev_target_prerequisites_met' => $candidate,
        'gate_a' => $candidate
            ? 'CANDIDATE_ONLY_REQUIRES_EXTERNAL_CALLBACK_ISOLATION_AND_OWNER_CANARY_GATE'
            : 'BLOCKED_NO_DEV_OUTBOUND_CANARY',
        'tracking_write_path_remains_manual_or_orchestrated' => true,
        'tracking_may_cause_followup_woocommerce_status_push' => true,
    ];
}

function bylP23SelfTest(): void
{
    $base = [
        'environment' => 'staging',
        'url' => 'https://dev.beyourlover.com',
        'connection_key' => 'byl-dev-testing',
        'consumer_key' => 'dummy-not-real',
        'consumer_secret' => 'dummy-not-real',
        'order_sync_enabled' => false,
    ];
    $tests = [
        'isolated_dev_candidate' => [$base, true, 'DEV'],
        'www_must_block' => [array_replace($base, ['url'=>'https://www.beyourlover.com']), false, 'WWW_PRODUCTION'],
        'root_domain_must_block' => [array_replace($base, ['url'=>'https://beyourlover.com']), false, 'WWW_PRODUCTION'],
        'missing_credential_must_block' => [array_replace($base, ['consumer_secret'=>'']), false, 'DEV'],
        'default_prod_key_must_block' => [array_replace($base, ['connection_key'=>'beyourlover.com']), false, 'DEV'],
        'production_runtime_must_block' => [array_replace($base, ['environment'=>'production']), false, 'DEV'],
        'unverified_target_must_block' => [array_replace($base, ['url'=>'https://unverified.example.invalid']), false, 'OTHER_OR_UNVERIFIED'],
        'http_dev_must_block' => [array_replace($base, ['url'=>'http://dev.beyourlover.com']), false, 'DEV'],
        'dev_subpath_must_block' => [array_replace($base, ['url'=>'https://dev.beyourlover.com/other']), false, 'DEV'],
        'no_url_must_block' => [array_replace($base, ['url'=>'']), false, 'UNSET'],
    ];
    foreach ($tests as $name => [$input, $expectedCandidate, $expectedClass]) {
        $result = bylP23ClassifyWooTarget($input);
        if ($result['dev_target_prerequisites_met'] !== $expectedCandidate
            || $result['woocommerce_target_class'] !== $expectedClass) {
            throw new RuntimeException('Gate A self-test failed: '.$name);
        }
    }
    echo 'P23_WOO_GATE_A_SELF_TEST=PASS cases=',count($tests),PHP_EOL;
}

$mode = $argv[1] ?? '';
if ($mode === '--self-test') {
    bylP23SelfTest();
    exit(0);
}
if ($mode !== '--inspect') {
    fwrite(STDERR, "Usage: php tools/audit/p23-woo-dev-target.php --self-test|--inspect\n");
    exit(64);
}
$basePath = dirname(__DIR__, 2);
if (!is_file($basePath.'/vendor/autoload.php')
    || !is_file($basePath.'/bootstrap/app.php')) {
    throw new RuntimeException('ERP Laravel application missing.');
}

require_once $basePath.'/vendor/autoload.php';
$app = require $basePath.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$config = (array) config('services.woocommerce', []);
$out = bylP23ClassifyWooTarget([
    'environment' => (string) config('app.env', 'unknown'),
    'url' => $config['url'] ?? '',
    'connection_key' => $config['connection_key'] ?? '',
    'consumer_key' => $config['consumer_key'] ?? '',
    'consumer_secret' => $config['consumer_secret'] ?? '',
    'order_sync_enabled' => $config['order_sync_enabled'] ?? false,
    'config_cached' => $app->configurationIsCached(),
]);
echo "P23_WOO_TARGET_AUDIT=",json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),PHP_EOL;
// Never print credentials or authorize a remote call. A candidate still needs callback isolation.
