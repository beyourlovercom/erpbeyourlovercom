import { writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
    assertNotAborted,
    assertRuntimeEnvironment,
    cleanupDisposableRun,
    createDisposableRun,
    resolveLocalPlaywrightCli,
    runManagedChild,
} from './e2e-environment.mjs';

let activeRun;
const abortController = new AbortController();

for (const signal of ['SIGINT', 'SIGTERM', 'SIGHUP']) {
    process.once(signal, () => abortController.abort(new Error('Process signal received.')));
}

async function runLifecycleFixture(mode, controlPath) {
    activeRun = await createDisposableRun();
    await writeFile(controlPath, activeRun.runRoot, { mode: 0o600 });

    if (mode === 'failure') throw new Error('Requested lifecycle failure fixture.');
    if (mode === 'timeout') {
        await runManagedChild(process.execPath, ['-e', 'setTimeout(() => {}, 10000)'], {
            signal: abortController.signal,
            timeout: 50,
            stdio: 'ignore',
        });
    }
    if (mode === 'signal') {
        await new Promise((resolve, reject) => {
            const keepAlive = setInterval(() => {}, 1_000);
            abortController.signal.addEventListener('abort', () => {
                clearInterval(keepAlive);
                reject(new Error('Signal fixture stopped.'));
            }, { once: true });
        });
    }
}

async function runFoundation() {
    const playwrightCli = resolveLocalPlaywrightCli();
    activeRun = await createDisposableRun();
    const { browserCache, databasePath, env, runRoot } = activeRun;
    if (process.env.BYL_E2E_SUITE === 'kol-smart-local') {
        Object.assign(env, {
            ANDY_CORE_UAT_FAKE: 'true',
            ANDY_CORE_CONNECTION_KEY: 'beyourlover.com',
            KOL_SMART_INPUT_SHAPE_ENABLED: 'false',
            KOL_SMART_INPUT_DEV_ENABLED: 'false',
        });
    }

    process.stdout.write(`E2E_RUN_ROOT=${runRoot}\n`);
    process.stdout.write(`E2E_DATABASE_PATH=${databasePath}\n`);
    process.stdout.write(`E2E_BROWSER_CACHE=${browserCache}\n`);
    process.stdout.write('E2E_RUN_ROOT_OUTSIDE_REPOSITORY=true\n');

    assertRuntimeEnvironment(env, runRoot);
    assertNotAborted(abortController.signal);
    await runManagedChild('npm', ['run', 'build'], { env, signal: abortController.signal });
    assertNotAborted(abortController.signal);
    await runManagedChild('php', ['tests/e2e/support/provision-users.php', '--runtime-preflight'], {
        env,
        signal: abortController.signal,
    });
    assertNotAborted(abortController.signal);
    await runManagedChild('php', ['artisan', 'migrate', '--force'], { env, signal: abortController.signal });
    assertRuntimeEnvironment(env, runRoot, { allowPopulatedDatabase: true });
    assertNotAborted(abortController.signal);
    await runManagedChild('php', ['tests/e2e/support/provision-users.php'], { env, signal: abortController.signal });
    assertNotAborted(abortController.signal);
    const suite = process.env.BYL_E2E_SUITE ?? 'all';
    if (!['all', 'kol-role', 'kol-smart-local', 'tracking-ux', 'oms-import', 'orders-master', 'global-grid', 'rejection-visual'].includes(suite)) {
        throw new Error('BYL_E2E_SUITE must be all, kol-role, tracking-ux, oms-import, orders-master, global-grid or rejection-visual.');
    }
    if (suite === 'global-grid' || suite === 'rejection-visual') {
        await runManagedChild('php', ['tests/e2e/support/provision-global-grid.php'], {
            env, signal: abortController.signal,
        });
    }
    if (suite === 'rejection-visual') {
        await runManagedChild('php', ['tests/e2e/support/provision-rejection-visual.php'], {
            env, signal: abortController.signal,
        });
    }
    const testArgs = suite === 'kol-smart-local'
        ? [playwrightCli, 'test', 'tests/e2e/orders/kol-smart-local.spec.ts', '--project=chromium', '--timeout=90000']
        : suite === 'rejection-visual'
        ? [playwrightCli, 'test', 'tests/e2e/global-grid/rejection-visual.spec.ts', '--project=chromium', '--timeout=90000']
        : suite === 'global-grid'
        ? [playwrightCli, 'test', 'tests/e2e/global-grid/global-grid-pilot.spec.ts', '--project=chromium', '--timeout=90000']
        : suite === 'oms-import'
        ? [playwrightCli, 'test', 'tests/e2e/orders/oms-import-browser.spec.ts', '--project=chromium', '--timeout=90000']
        : suite === 'orders-master'
        ? [playwrightCli, 'test', 'tests/e2e/orders/orders-master-browser.spec.ts', '--project=chromium', '--timeout=90000']
        : suite === 'kol-role'
        ? [playwrightCli, 'test', 'tests/e2e/orders/kol-role-isolation.spec.ts', '--project=chromium', '--timeout=90000']
        : suite === 'tracking-ux'
            ? ['tests/e2e/support/tracking-ux-browser.mjs']
            : [playwrightCli, 'test', '--project=chromium'];
    // Narrow an isolated Global Grid Dev run to a named regression without
    // skipping database provisioning or disposable runtime cleanup.
    if (suite === 'global-grid' && process.env.BYL_E2E_GREP) {
        testArgs.push('--grep', process.env.BYL_E2E_GREP);
        process.stdout.write(`E2E_GREP=${process.env.BYL_E2E_GREP}\n`);
    }
    await runManagedChild(process.execPath, testArgs, {
        env,
        signal: abortController.signal,
        timeout: ['kol-role','kol-smart-local'].includes(suite) ? 360_000 : ['oms-import', 'orders-master', 'global-grid', 'rejection-visual'].includes(suite) ? 360_000 : 120_000,
    });
    if (suite === 'kol-smart-local') {
        await runManagedChild('php', ['tests/e2e/support/verify-kol-smart-local.php'], {env, signal: abortController.signal});
    }
}

async function main() {
    const fixture = process.env.BYL_E2E_LIFECYCLE_FIXTURE;
    try {
        if (fixture) {
            const controlPath = process.env.BYL_E2E_LIFECYCLE_CONTROL_PATH;
            if (!controlPath || !path.isAbsolute(controlPath)) throw new Error('Lifecycle fixture control path is required.');
            await runLifecycleFixture(fixture, controlPath);
        } else {
            await runFoundation();
        }
    } finally {
        if (activeRun?.runRoot) {
            await cleanupDisposableRun(activeRun.runRoot);
            process.stdout.write('E2E_CLEANUP=PASS\n');
        }
    }
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    main().catch(() => {
        process.stderr.write('E2E run failed safely; external runtime cleanup was attempted.\n');
        process.exitCode = 1;
    });
}

export { main };
