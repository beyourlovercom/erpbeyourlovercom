import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { setTimeout as delay } from 'node:timers/promises';
import { chromium } from 'playwright';

const baseURL = process.env.BYL_E2E_BASE_URL;
const activeEmail = `authorized-${(process.env.BYL_E2E_RUN_ID ?? '').replace(/[^a-z0-9]/giu, '')}@byl-e2e.invalid`;
const password = process.env.BYL_E2E_ACTIVE_PASSWORD;
if (!baseURL || !password) throw new Error('Disposable E2E environment is incomplete.');
const port = new URL(baseURL).port;
const server = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`], { stdio: 'ignore', env: process.env });
let browser;
try {
    let ready = false;
    for (let attempt = 0; attempt < 80; attempt += 1) {
        try { const response = await fetch(`${baseURL}/login`); if (response.ok) { ready = true; break; } } catch {}
        await delay(250);
    }
    if (!ready) throw new Error('Disposable ERP web server did not become ready.');

    browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    let externalRequests = 0;
    let consoleErrors = 0;
    let pageErrors = 0;
    await context.route('**/*', async (route) => {
        const url = new URL(route.request().url());
        if (url.hostname !== '127.0.0.1') { externalRequests += 1; await route.abort('blockedbyclient'); return; }
        await route.continue();
    });
    const page = await context.newPage();
    page.on('console', (message) => { if (message.type() === 'error') consoleErrors += 1; });
    page.on('pageerror', () => { pageErrors += 1; });
    await page.goto(`${baseURL}/login`);
    await page.getByLabel('邮箱').fill(activeEmail);
    await page.locator('input[name="password"]').fill(password);
    await Promise.all([page.waitForURL((url) => url.pathname === '/dashboard'), page.getByRole('button', { name: '登录系统' }).click()]);

    const surfaces = [
        { path: '/orders', name: 'Orders classic', marker: '官网同步' },
        { path: '/orders?oms_grid=1', name: 'Orders OMS Grid', marker: '官网同步' },
        { path: '/sync/orders', name: 'Sync Orders', marker: '官网物流' },
        { path: '/logistics/china', name: 'Domestic Logistics', marker: '官网同步' },
        { path: '/logistics/oversea', name: 'Overseas Logistics', marker: '官网同步' },
    ];
    const results = [];
    for (const viewport of [{ name: 'desktop', width: 1440, height: 900 }, { name: 'mobile', width: 390, height: 844 }]) {
        await page.setViewportSize({ width: viewport.width, height: viewport.height });
        for (const surface of surfaces) {
            process.stdout.write(`VISIT ${viewport.name} ${surface.name}\n`);
            const response = await page.goto(`${baseURL}${surface.path}`);
            if (response?.status() !== 200) throw new Error(`${surface.name} ${viewport.name} returned HTTP ${response?.status()}`);
            if (surface.name === 'Orders OMS Grid') {
                await page.waitForTimeout(800);
                const horizontal = page.locator('.ag-body-horizontal-scroll-viewport');
                if (await horizontal.count()) await horizontal.last().evaluate((element) => { element.scrollLeft = element.scrollWidth; });
                await page.waitForTimeout(150);
                const headers = await page.locator('.ag-header-cell-text').allTextContents();
                if (!headers.some((header) => header.trim() === surface.marker)) throw new Error(`OMS Grid ${viewport.name} missing sync header; headers=${JSON.stringify(headers)}; diagnostics=${JSON.stringify(diagnostics)}`);
            } else {
                const markerCount = await page.getByText(surface.marker, { exact: true }).count();
                if (markerCount === 0) throw new Error(`${surface.name} ${viewport.name} missing sync marker; page title=${await page.title()}; text=${(await page.locator('body').innerText()).slice(0, 500)}`);
                await page.getByText(surface.marker, { exact: true }).first().waitFor({ state: 'visible', timeout: 10000 });
            }
            if (await page.getByRole('button', { name: '同步官网物流', exact: true }).count() !== 0) throw new Error(`${surface.name} shows duplicate header sync action`);
            const geometry = await page.evaluate(() => ({
                viewport: window.innerWidth,
                document: document.documentElement.scrollWidth,
                body: document.body.scrollWidth,
                main: document.querySelector('#main-content')?.getBoundingClientRect().width ?? null,
            }));
            if (geometry.document > geometry.viewport + 1 || geometry.body > geometry.viewport + 1 || geometry.main === null) {
                throw new Error(`${surface.name} ${viewport.name} geometry failed: ${JSON.stringify(geometry)}`);
            }
            results.push({ page: surface.name, viewport: viewport.name, width: geometry.viewport, body: geometry.body, document: geometry.document, main: Math.round(geometry.main) });
        }
    }
    if (externalRequests || consoleErrors || pageErrors) throw new Error(`Browser guard failed: external=${externalRequests}, console=${consoleErrors}, page=${pageErrors}`);
    process.stdout.write(JSON.stringify({ status: 'PASS', checks: results.length, results, externalRequests, consoleErrors, pageErrors }) + '\n');
    await context.close();
} finally {
    if (browser) await browser.close();
    const serverExit = once(server, 'exit').catch(() => []);
    server.kill('SIGTERM');
    const exited = await Promise.race([serverExit.then(() => true), delay(1500).then(() => false)]);
    if (!exited) { server.kill('SIGKILL'); await serverExit; }
}
