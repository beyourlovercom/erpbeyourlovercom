import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { setTimeout as delay } from 'node:timers/promises';
import path from 'node:path';
import { chromium } from 'playwright';
import {
    assertRuntimeEnvironment,
    cleanupDisposableRun,
    createDisposableRun,
    runManagedChild,
} from './e2e-environment.mjs';

// Isolated only: throwaway SQLite + loopback PHP. Never point this at Production.
process.env.BYL_E2E_BROWSER = 'chrome';
const holdForOwner = process.env.BYL_LOGISTICS_OWNER_UAT === '1';
let run;
let browser;
let server;
try {
    run = await createDisposableRun();
    const { env, port, runRoot } = run;
    assertRuntimeEnvironment(env, runRoot);
    await runManagedChild('php', ['artisan', 'migrate', '--force'], { env, timeout: 120_000, stdio: 'ignore' });
    assertRuntimeEnvironment(env, runRoot, { allowPopulatedDatabase: true });
    const devEnv = { ...env, UAT_ADMIN_PASSWORD: 'password' };
    await runManagedChild('php', ['artisan', 'db:seed', '--class=LocalUatSeeder', '--force'], {
        env: devEnv, timeout: 120_000, stdio: 'ignore',
    });
    const seedWarehouses = [
        "foreach (['US-NY'=>['us-ny','美东纽约仓'],'US-LA'=>['us-la','美西仓'],",
        "'US-SM'=>['us-3sm','美国3世迈仓'],'DE'=>['de','德国天运仓'],",
        "'CA'=>['ca','加拿大仓']] as $code => $info) {",
        "\\App\\Modules\\Inventory\\Infrastructure\\Models\\Warehouse::query()->updateOrCreate(",
        "['code'=>$code],['slug'=>$info[0],'name'=>$info[1],",
        "'type'=>'warehouse','is_active'=>true,'priority'=>10]); }",
    ].join('');
    await runManagedChild('php', ['artisan', 'tinker', '--execute', seedWarehouses], {
        env: devEnv, timeout: 60_000, stdio: 'ignore',
    });
    server = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`], {
        cwd: path.resolve(import.meta.dirname, '../../..'),
        env: devEnv, stdio: 'ignore',
    });
    const base = `http://127.0.0.1:${port}`;
    let ready = false;
    for (let attempt = 0; attempt < 100; attempt += 1) {
        try { if ((await fetch(`${base}/login`)).ok) { ready = true; break; } } catch { /* startup */ }
        await delay(250);
    }
    if (!ready) throw new Error('Isolated Dev HTTP listener did not become ready.');
    browser = await chromium.launch({ channel: 'chrome', headless: !holdForOwner });
    const context = await browser.newContext({ viewport: { width: 1500, height: 900 } });
    const failures = [];
    await context.route('**/*', async (route) => {
        if (new URL(route.request().url()).hostname !== '127.0.0.1') {
            failures.push('external request blocked');
            await route.abort('blockedbyclient');
        } else {
            await route.continue();
        }
    });
    const page = await context.newPage();
    page.on('pageerror', (error) => failures.push(`pageerror: ${error.message}`));
    page.on('response', (response) => {
        if (new URL(response.url()).pathname.includes('/assets/') && response.status() >= 400) {
            failures.push(`asset HTTP ${response.status()} ${new URL(response.url()).pathname}`);
        }
    });
    await page.goto(`${base}/login`);
    await page.getByLabel('邮箱').fill('admin@local.test');
    await page.locator('input[name="password"]').fill('password');
    await Promise.all([
        page.waitForURL((url) => url.pathname === '/dashboard'),
        page.getByRole('button', { name: '登录系统' }).click(),
    ]);
    const surfaces = [
        ['/logistics', '物流入口'],
        ['/logistics/wait-stock', '待分仓'],
        ['/logistics/china?view=review', '国内发货'],
        ['/logistics/oversea/us-moreplus?view=pending', '美西仓'],
        ['/logistics/oversea/de-tianyun?view=review', '德国天运仓'],
        ['/logistics/oversea/us-ny?view=shipped', '美东仓'],
        ['/logistics/oversea/ca?view=pending', '加拿大仓'],
    ];
    const results = [];
    for (const [route, name] of surfaces) {
        const started = Date.now();
        const response = await page.goto(`${base}${route}`, { waitUntil: 'networkidle', timeout: 40_000 });
        await page.locator('#main-content').waitFor({ state: 'visible', timeout: 15_000 });
        if (response?.status() !== 200) throw new Error(`${name} returned ${response?.status()}`);
        if (!(await page.locator('body').innerText()).includes('物流')) {
            throw new Error(`${name} missing logistics surface`);
        }
        results.push({ name, status: response.status(), ms: Date.now() - started });
    }
    await page.reload({ waitUntil: 'networkidle' });
    await page.locator('#main-content').waitFor({ state: 'visible' });
    if (failures.length) throw new Error(`Browser errors: ${JSON.stringify(failures)}`);
    process.stdout.write(`P0_DEV_SMOKE_PASS ${JSON.stringify(results)}\n`);
    if (holdForOwner) {
        // Leave real authenticated Dev pages visible for Owner UAT, not Production.
        const tabs = ['/logistics/wait-stock', '/logistics/china?view=review',
            '/logistics/oversea/de-tianyun?view=review'];
        for (const route of tabs) {
            const tab = await context.newPage();
            await tab.goto(`${base}${route}`, { waitUntil: 'domcontentloaded' });
        }
        await page.bringToFront();
        process.stdout.write(`P0_DEV_OWNER_UAT_READY url=${base}/logistics email=admin@local.test db=throwaway-sqlite\n`);
        await new Promise((resolve) => {
            process.once('SIGTERM', resolve);
            process.once('SIGINT', resolve);
        });
    }
} catch (error) {
    process.stderr.write(`P0_DEV_SMOKE_FAIL ${error.message}\n`);
    process.exitCode = 1;
} finally {
    if (browser) await browser.close();
    if (server) {
        const exited = once(server, 'exit').catch(() => []);
        server.kill('SIGTERM');
        if (!await Promise.race([exited.then(() => true), delay(1500).then(() => false)])) {
            server.kill('SIGKILL');
            await exited;
        }
    }
    if (run) await cleanupDisposableRun(run.runRoot);
}
