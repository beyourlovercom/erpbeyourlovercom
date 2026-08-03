<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

function failClosed(string $message): never
{
    fwrite(STDERR, "E2E provisioning safety gate failed: {$message}\n");
    exit(1);
}

$repositoryRoot = realpath(dirname(__DIR__, 3));
$requestedRunRoot = (string) getenv('BYL_E2E_RUN_ROOT');
$requestedDatabase = (string) getenv('DB_DATABASE');
$runRoot = realpath($requestedRunRoot);
$database = realpath($requestedDatabase);

if (getenv('APP_ENV') !== 'testing') {
    failClosed('APP_ENV');
}
if (getenv('BYL_E2E_DISPOSABLE') !== 'true') {
    failClosed('disposable marker');
}
if (getenv('ERP_LOCAL_ADMIN_BYPASS') !== 'false') {
    failClosed('local bypass');
}
if (getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_URL') !== false && getenv('DB_URL') !== '') {
    failClosed('connection');
}
if ($repositoryRoot === false || $runRoot === false || $database === false) {
    failClosed('real paths');
}
if (is_link($requestedRunRoot) || is_link($requestedDatabase)) {
    failClosed('symlink');
}
if ($runRoot === $repositoryRoot || str_starts_with($runRoot, $repositoryRoot.DIRECTORY_SEPARATOR)) {
    failClosed('repository-contained root');
}
if (dirname($database) !== $runRoot.DIRECTORY_SEPARATOR.'database') {
    failClosed('database path');
}
if (basename($database) !== 'e2e.sqlite' || ! is_file($database)) {
    failClosed('database file');
}

$pdo = new PDO('sqlite:'.$database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
foreach (['migrations', 'users', 'roles', 'permissions', 'role_user'] as $requiredTable) {
    if (! in_array($requiredTable, $tables, true)) {
        failClosed('schema not migrated');
    }
}
if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) {
    failClosed('database is not fresh');
}
unset($pdo);

$runId = preg_replace('/[^a-z0-9]/i', '', (string) getenv('BYL_E2E_RUN_ID'));
$activePassword = (string) getenv('BYL_E2E_ACTIVE_PASSWORD');
$inactivePassword = (string) getenv('BYL_E2E_INACTIVE_PASSWORD');
if ($runId === '' || strlen($activePassword) < 24 || strlen($inactivePassword) < 24) {
    failClosed('synthetic credentials');
}

require $repositoryRoot.'/vendor/autoload.php';
$app = require $repositoryRoot.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

DB::transaction(function () use ($runId, $activePassword, $inactivePassword): void {
    $role = Role::query()->create(['key' => 'super_admin', 'label' => 'E2E Super Admin']);
    $authorized = User::query()->create([
        'name' => 'E2E Authorized',
        'email' => "authorized-{$runId}@byl-e2e.invalid",
        'password' => Hash::make($activePassword),
        'is_active' => true,
    ]);
    $authorized->roles()->attach($role);

    User::query()->create([
        'name' => 'E2E Unauthorized',
        'email' => "unauthorized-{$runId}@byl-e2e.invalid",
        'password' => Hash::make($activePassword),
        'is_active' => true,
    ]);
    User::query()->create([
        'name' => 'E2E Inactive',
        'email' => "inactive-{$runId}@byl-e2e.invalid",
        'password' => Hash::make($inactivePassword),
        'is_active' => false,
    ]);
});

fwrite(STDOUT, json_encode(['synthetic_users' => 3, 'database' => 'disposable-sqlite'], JSON_THROW_ON_ERROR)."\n");
