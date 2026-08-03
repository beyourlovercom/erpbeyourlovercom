import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { chmod, mkdtemp, mkdir, readFile, realpath, rm, symlink, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { scanArtifactTree } from './artifact-scan.mjs';
import {
    REPOSITORY_ROOT,
    assertExternalRunRoot,
    assertLoopbackBaseUrl,
    assertRuntimeEnvironment,
} from './e2e-environment.mjs';

const browserCache = path.join(tmpdir(), 'byl-e2e-contract-browser-cache', '1.62.1');
const runner = path.join(REPOSITORY_ROOT, 'tests/e2e/support/run-e2e.mjs');

test.before(async () => {
    await mkdir(browserCache, { recursive: true, mode: 0o700 });
});

test.after(async () => {
    await rm(path.dirname(browserCache), { recursive: true });
});

async function temporaryDirectory(prefix = 'byl-e2e-contract-') {
    const directory = await mkdtemp(path.join(tmpdir(), prefix));
    await chmod(directory, 0o700);

    return directory;
}

function disposableEnvironment(runRoot, databasePath) {
    return {
        APP_ENV: 'testing',
        BYL_E2E_DISPOSABLE: 'true',
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: databasePath,
        DB_URL: '',
        ERP_LOCAL_ADMIN_BYPASS: 'false',
    };
}

function storedZip(entryName, content) {
    const name = Buffer.from(entryName);
    const data = Buffer.from(content);
    const local = Buffer.alloc(30);
    local.writeUInt32LE(0x04034b50, 0);
    local.writeUInt16LE(20, 4);
    local.writeUInt32LE(data.length, 18);
    local.writeUInt32LE(data.length, 22);
    local.writeUInt16LE(name.length, 26);

    const central = Buffer.alloc(46);
    central.writeUInt32LE(0x02014b50, 0);
    central.writeUInt16LE(20, 4);
    central.writeUInt16LE(20, 6);
    central.writeUInt32LE(data.length, 20);
    central.writeUInt32LE(data.length, 24);
    central.writeUInt16LE(name.length, 28);

    const centralOffset = local.length + name.length + data.length;
    const centralSize = central.length + name.length;
    const end = Buffer.alloc(22);
    end.writeUInt32LE(0x06054b50, 0);
    end.writeUInt16LE(1, 8);
    end.writeUInt16LE(1, 10);
    end.writeUInt32LE(centralSize, 12);
    end.writeUInt32LE(centralOffset, 16);

    return Buffer.concat([local, name, data, central, name, end]);
}

async function waitForFile(file, timeout = 3_000) {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
        if (existsSync(file)) return;
        await new Promise((resolve) => setTimeout(resolve, 20));
    }
    throw new Error('Timed out waiting for lifecycle control file.');
}

async function runLifecycleFixture(mode) {
    const controlRoot = await temporaryDirectory('byl-e2e-control-');
    const controlPath = path.join(controlRoot, 'run-root.txt');
    const result = spawnSync(process.execPath, [runner], {
        cwd: REPOSITORY_ROOT,
        encoding: 'utf8',
        env: {
            ...process.env,
            BYL_E2E_LIFECYCLE_CONTROL_PATH: controlPath,
            BYL_E2E_LIFECYCLE_FIXTURE: mode,
            PLAYWRIGHT_BROWSERS_PATH: browserCache,
        },
    });
    const runRoot = await readFile(controlPath, 'utf8');
    await rm(controlRoot, { recursive: true });

    return { result, runRoot };
}

test('external run root and loopback URL contracts fail closed', async () => {
    const external = await temporaryDirectory();
    assert.equal(assertExternalRunRoot(external), await realpath(external));
    assert.equal(assertLoopbackBaseUrl('http://127.0.0.1:48123'), 'http://127.0.0.1:48123');
    assert.throws(() => assertExternalRunRoot(REPOSITORY_ROOT), /outside the Git worktree/);
    assert.throws(() => assertLoopbackBaseUrl('https://example.com'), /127\.0\.0\.1/);
    assert.throws(() => assertLoopbackBaseUrl('http://localhost:48123'), /127\.0\.0\.1/);
    await rm(external, { recursive: true });
});

test('database safety gate accepts only a fresh external disposable SQLite file', async () => {
    const runRoot = await temporaryDirectory();
    await mkdir(path.join(runRoot, 'database'));
    const databasePath = path.join(runRoot, 'database', 'e2e.sqlite');
    await writeFile(databasePath, '');
    const environment = disposableEnvironment(runRoot, databasePath);

    assert.equal(assertRuntimeEnvironment(environment, runRoot), path.join(await realpath(runRoot), 'database', 'e2e.sqlite'));
    assert.throws(
        () => assertRuntimeEnvironment({ ...environment, DB_CONNECTION: 'mysql' }, runRoot),
        /Only a direct disposable SQLite/,
    );
    assert.throws(
        () => assertRuntimeEnvironment({ ...environment, APP_ENV: 'local' }, runRoot),
        /APP_ENV/,
    );
    assert.throws(
        () => assertRuntimeEnvironment({ ...environment, DB_DATABASE: path.join(REPOSITORY_ROOT, 'database/database.sqlite') }, runRoot),
        /dedicated SQLite/,
    );
    await writeFile(databasePath, 'shared');
    assert.throws(() => assertRuntimeEnvironment(environment, runRoot), /new empty regular file/);
    await rm(runRoot, { recursive: true });
});

test('PHP user bootstrap rejects an unsafe environment before application boot', () => {
    const result = spawnSync('php', ['tests/e2e/support/provision-users.php'], {
        cwd: REPOSITORY_ROOT,
        encoding: 'utf8',
        env: { ...process.env, APP_ENV: 'production', BYL_E2E_ACTIVE_PASSWORD: 'CANARY-DO-NOT-PRINT' },
    });

    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /safety gate failed/);
    assert.doesNotMatch(result.stderr, /CANARY-DO-NOT-PRINT/);
});

test('Playwright direct mode rejects unsafe input and lists only the Chromium project for a valid contract', async () => {
    const runRoot = await temporaryDirectory();
    for (const directory of ['database', 'artifacts', 'report']) await mkdir(path.join(runRoot, directory));
    const databasePath = path.join(runRoot, 'database', 'e2e.sqlite');
    await writeFile(databasePath, '');
    const environment = {
        ...process.env,
        ...disposableEnvironment(runRoot, databasePath),
        BYL_E2E_BASE_URL: 'https://example.com',
        BYL_E2E_RUN_ROOT: runRoot,
        PLAYWRIGHT_BROWSERS_PATH: browserCache,
    };
    const rejected = spawnSync('npx', ['playwright', 'test', '--list'], {
        cwd: REPOSITORY_ROOT,
        encoding: 'utf8',
        env: environment,
    });
    assert.notEqual(rejected.status, 0);
    assert.match(`${rejected.stdout}${rejected.stderr}`, /127\.0\.0\.1/);

    const listed = spawnSync('npx', ['playwright', 'test', '--list'], {
        cwd: REPOSITORY_ROOT,
        encoding: 'utf8',
        env: { ...environment, BYL_E2E_BASE_URL: 'http://127.0.0.1:48123' },
    });
    assert.equal(listed.status, 0, listed.stderr);
    assert.match(listed.stdout, /\[chromium\]/);
    assert.doesNotMatch(listed.stdout, /\[(?:firefox|webkit)\]/);
    await rm(runRoot, { recursive: true });
});

test('artifact scanner accepts bounded synthetic text and returns only count and hash', async () => {
    const root = await temporaryDirectory();
    await writeFile(path.join(root, 'result.json'), JSON.stringify({ email: 'runner@byl-e2e.invalid', status: 'ok' }));
    const result = await scanArtifactTree(root);

    assert.equal(result.files, 1);
    assert.match(result.manifestHash, /^[a-f0-9]{64}$/);
    assert.deepEqual(Object.keys(result).sort(), ['files', 'manifestHash']);
    await rm(root, { recursive: true });
});

test('artifact scanner rejects sensitive text, configured secrets and real email addresses without echoing them', async () => {
    for (const [name, content, options] of [
        ['plain.txt', 'authorization: bearer REDACT-ME', {}],
        ['result.json', '{"password":"REDACT-ME"}', {}],
        ['report.html', '<p>owner@example.com</p>', {}],
        ['canary.txt', 'CANARY-SECRET-987', { secretValues: ['CANARY-SECRET-987'] }],
    ]) {
        const root = await temporaryDirectory();
        await writeFile(path.join(root, name), content);
        await assert.rejects(scanArtifactTree(root, options), (error) => {
            assert.doesNotMatch(error.message, /REDACT-ME|owner@example\.com|CANARY-SECRET-987/);
            return true;
        });
        await rm(root, { recursive: true });
    }
});

test('artifact scanner inspects ZIP entries and rejects embedded sensitive content', async () => {
    const root = await temporaryDirectory();
    await writeFile(path.join(root, 'results.zip'), storedZip('nested/result.json', '{"csrf":"REDACT-ME"}'));
    await assert.rejects(scanArtifactTree(root), /blocked sensitive-data marker/);
    await rm(root, { recursive: true });
});

test('artifact scanner rejects symlinks and unknown formats', async () => {
    const targetRoot = await temporaryDirectory();
    const symlinkRoot = await temporaryDirectory();
    await writeFile(path.join(targetRoot, 'safe.txt'), 'safe');
    await symlink(path.join(targetRoot, 'safe.txt'), path.join(symlinkRoot, 'linked.txt'));
    await assert.rejects(scanArtifactTree(symlinkRoot), /Symlinks/);
    await rm(symlinkRoot, { recursive: true });

    const unknownRoot = await temporaryDirectory();
    await writeFile(path.join(unknownRoot, 'result.bin'), Buffer.from([0, 1, 2]));
    await assert.rejects(scanArtifactTree(unknownRoot), /Unknown artifact format/);
    await rm(unknownRoot, { recursive: true });

    const disguisedBinaryRoot = await temporaryDirectory();
    await writeFile(path.join(disguisedBinaryRoot, 'result.txt'), Buffer.from([0, 1, 2]));
    await assert.rejects(scanArtifactTree(disguisedBinaryRoot), /not valid UTF-8/);
    await rm(disguisedBinaryRoot, { recursive: true });
    await rm(targetRoot, { recursive: true });
});

test('success, failure and timeout paths remove the disposable run root', async () => {
    for (const [mode, expectedStatus] of [['success', 0], ['failure', 1], ['timeout', 1]]) {
        const { result, runRoot } = await runLifecycleFixture(mode);
        assert.equal(result.status, expectedStatus);
        assert.equal(existsSync(runRoot), false);
    }
});

test('signal exit terminates the runner and removes the disposable run root', async () => {
    const controlRoot = await temporaryDirectory('byl-e2e-control-');
    const controlPath = path.join(controlRoot, 'run-root.txt');
    const child = spawn(process.execPath, [runner], {
        cwd: REPOSITORY_ROOT,
        env: {
            ...process.env,
            BYL_E2E_LIFECYCLE_CONTROL_PATH: controlPath,
            BYL_E2E_LIFECYCLE_FIXTURE: 'signal',
            PLAYWRIGHT_BROWSERS_PATH: browserCache,
        },
        stdio: 'ignore',
    });

    await waitForFile(controlPath);
    const runRoot = await readFile(controlPath, 'utf8');
    const exited = new Promise((resolve, reject) => {
        child.once('exit', resolve);
        child.once('error', reject);
    });
    child.kill('SIGTERM');
    await exited;
    assert.equal(existsSync(runRoot), false);
    await rm(controlRoot, { recursive: true });
});
