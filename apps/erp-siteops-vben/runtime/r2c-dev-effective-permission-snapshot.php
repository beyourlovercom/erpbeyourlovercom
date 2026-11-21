<?php
declare(strict_types=1);

/**
 * ERP2 P0: read-only snapshot of the original Laravel ERP's EFFECTIVE Dev staff grants.
 *
 * Must run against a legitimate isolated Dev DB containing the canonical ERP user IDs.
 * Never run in Production or use synthetic @local.test / @local.invalid identities.
 * Does not modify user accounts, roles, permissions, sessions or business data.
 *
 * BYL_R2C_DEV_STAFF_MANIFEST=/approved/private/staff.json
 * BYL_R2C_PERMISSION_SNAPSHOT_OUT=/approved/private/erp1-rights-20261116.json
 * php apps/erp-siteops-vben/runtime/r2c-dev-effective-permission-snapshot.php
 */
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Contracts\Console\Kernel;

function hold(string $reason): never
{
    fwrite(STDERR, "R2C_PERMISSION_SNAPSHOT_HOLD {$reason}\n");
    exit(2);
}

function privateInput(string $filename): array
{
    if ($filename === '' || !str_starts_with($filename, '/')) hold('PRIVATE_MANIFEST_ABSOLUTE_PATH_REQUIRED');
    if (!is_file($filename)) hold('PRIVATE_MANIFEST_NOT_FOUND');
    if ((fileperms($filename) & 0077) !== 0) hold('PRIVATE_MANIFEST_MUST_BE_0600');
    $data = json_decode((string) file_get_contents($filename), true);
    if (!is_array($data)) hold('INVALID_PRIVATE_MANIFEST_JSON');
    return $data;
}

$manifestFile = getenv('BYL_R2C_DEV_STAFF_MANIFEST') ?: '';
$outputFile = getenv('BYL_R2C_PERMISSION_SNAPSHOT_OUT') ?: '';
$manifest = privateInput($manifestFile);
if ($outputFile === '' || !str_starts_with($outputFile, '/') || file_exists($outputFile)) {
    hold('NEW_ABSOLUTE_PRIVATE_OUTPUT_PATH_REQUIRED');
}
if (!is_dir(dirname($outputFile))) hold('PRIVATE_OUTPUT_DIRECTORY_MISSING');

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (!in_array((string) config('app.env'), ['local', 'testing', 'staging'], true)) {
    hold('PRODUCTION_OR_UNKNOWN_ENVIRONMENT_FORBIDDEN');
}

$expectedNames = ['Vita', 'Liang', 'Dede', 'Emma', 'Guts', 'Hiko'];
$staff = $manifest['staff'] ?? null;
if (!is_array($staff) || count($staff) !== 6) hold('EXACT_SIX_STAFF_REQUIRED');
$actualNames = array_map(fn ($row) => is_array($row) ? ($row['employee'] ?? '') : '', $staff);
sort($actualNames);
sort($expectedNames);
if ($actualNames !== $expectedNames) hold('STAFF_NAMES_MISMATCH');

$ids = [];
$emails = [];
$inventory = [];
foreach ($staff as $record) {
    $employee = $record['employee'];
    $id = $record['erpUserId'] ?? null;
    $email = strtolower((string) ($record['email'] ?? ''));
    if (!is_int($id) || $id < 1 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        hold('CANONICAL_ID_OR_EMAIL_REQUIRED');
    }
    if (preg_match('/(@local\\.(invalid|test)|\\.(test|invalid|example|localhost))$/i', $email)) {
        hold('SYNTHETIC_IDENTITY_FORBIDDEN');
    }
    if (isset($ids[$id]) || isset($emails[$email])) hold('DUPLICATE_CANONICAL_STAFF');
    $ids[$id] = true;
    $emails[$email] = true;

    $user = User::with(['roles.permissions', 'customPermissions'])->find($id);
    if (!$user || strtolower((string) $user->email) !== $email) {
        hold('CANONICAL_DEV_USER_NOT_FOUND_OR_MISMATCH_'.strtoupper($employee));
    }
    $roles = $user->roles->pluck('key')->unique()->sort()->values()->all();
    $superAdmin = in_array('super_admin', $roles, true);
    $mode = $user->permission_mode === 'custom' ? 'custom' : 'role';
    $roleKeys = $user->roles->flatMap(fn ($r) => $r->permissions->pluck('key'))->unique()->sort()->values()->all();
    $customKeys = $user->customPermissions->pluck('key')->unique()->sort()->values()->all();
    $effective = $superAdmin ? ['*'] : ($mode === 'custom' ? $customKeys : $roleKeys);

    $inventory[] = [
        'employee' => $employee,
        'erpUserId' => $id,
        'email' => $email,
        'active' => (bool) $user->is_active,
        'permissionMode' => $mode,
        'roles' => $roles,
        'superAdmin' => $superAdmin,
        'effectivePermissionKeys' => $effective,
    ];
}
usort($inventory, fn ($a, $b) => strcmp($a['employee'], $b['employee']));
$payload = json_encode([
    'source' => 'original-laravel-effective-permissions',
    'environment' => (string) config('app.env'),
    'employees' => $inventory,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
umask(0077);
$handle = @fopen($outputFile, 'x');
if ($handle === false) hold('PRIVATE_OUTPUT_CREATE_FAILED');
try {
    chmod($outputFile, 0600);
    if (fwrite($handle, $payload."\n") !== strlen($payload) + 1) {
        hold('PRIVATE_OUTPUT_WRITE_INCOMPLETE');
    }
} finally {
    fclose($handle);
}
echo 'R2C_PERMISSION_SNAPSHOT_PASS users=6 environment='.(string) config('app.env')
    .' sha256='.hash('sha256', $payload)."\n";
