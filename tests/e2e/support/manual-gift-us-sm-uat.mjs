import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { setTimeout as delay } from 'node:timers/promises';
import path from 'node:path';
import { chromium } from 'playwright';
import { assertRuntimeEnvironment, cleanupDisposableRun, createDisposableRun, runManagedChild } from './e2e-environment.mjs';

process.env.BYL_E2E_BROWSER = 'chrome';
const hold = process.env.BYL_GIFT_OWNER_UAT === '1';
let run, browser, server;
const seed = [
    "$w=\\App\\Modules\\Inventory\\Infrastructure\\Models\\Warehouse::query()->updateOrCreate(['code'=>'US-SM'],['slug'=>'us-3sm','name'=>'美国3世迈仓','type'=>'warehouse','is_active'=>true,'priority'=>10]);",
    "$p=\\App\\Modules\\Products\\Infrastructure\\Models\\Product::query()->create(['sku'=>'BY489A','name'=>'BEYOURLOVER LUBRICANT 35ML','product_type'=>'simple','source_type'=>'manual','is_active'=>true]);",
    "\\App\\Modules\\Inventory\\Infrastructure\\Models\\WarehouseInventory::query()->create(['warehouse_id'=>$w->id,'product_id'=>$p->id,'quantity_on_hand'=>1,'quantity_reserved'=>0]);",
    "\\App\\Modules\\Logistics\\Infrastructure\\Xlwms\\XlwmsInventorySnapshot::query()->create(['warehouse_id'=>$w->id,'wh_code'=>'OMS-US-SM','checksum'=>hash('sha256','synthetic-unverified'),'status'=>'applied','fetched_at'=>now(),'applied_at'=>now(),'summary'=>['v2'=>true]]);",
    "$s=\\App\\Modules\\Products\\Infrastructure\\Models\\Product::query()->create(['sku'=>'GIFT-UAT-PLACEHOLDER','name'=>'Gift Placeholder','product_type'=>'simple','source_type'=>'manual','is_active'=>true]);",
    "$v=\\App\\Modules\\Products\\Infrastructure\\Models\\Product::query()->create(['sku'=>'VA617A-GREEN','name'=>'VA617A-GREEN','product_type'=>'simple','source_type'=>'manual','is_active'=>true]);",
    "\\App\\Modules\\Inventory\\Infrastructure\\Models\\WarehouseInventory::query()->create(['warehouse_id'=>$w->id,'product_id'=>$v->id,'quantity_on_hand'=>0,'quantity_reserved'=>0]);",
    "$u=\\App\\Modules\\Identity\\Infrastructure\\Models\\User::query()->where('email','admin@local.test')->firstOrFail();",
    "$o=\\App\\Modules\\Orders\\Infrastructure\\Models\\Order::query()->create(['internal_order_number'=>'UAT-US-SM-BY489A','erp_order_id'=>'007-1119-c42587','source_type'=>'manual','order_kind'=>'customer','external_order_number'=>'SYNTHETIC-GIFT-UAT','order_date'=>now()->toDateString(),'order_status'=>'pending_shipment','currency'=>'USD','owner_id'=>$u->id,'order_note'=>'Synthetic US-SM gift UAT']);",
    "$o->address()->create(['recipient_name'=>'Synthetic Gift UAT','phone'=>'10000000000','country'=>'US','province_state'=>'CA','city'=>'Los Angeles','address_line_1'=>'123 Test Ave','postal_code'=>'90001']);",
    "$o->items()->create(['product_id'=>$s->id,'product_summary'=>'Gift','sku'=>'gift','quantity'=>1,'unit_price'=>'0.00','line_revenue'=>'0.00']);",
    "$o->items()->create(['product_id'=>$v->id,'product_summary'=>'VA617A-GREEN','sku'=>'VA617A-GREEN','quantity'=>1,'unit_price'=>'19.99','line_revenue'=>'19.99']);",
    "\\App\\Modules\\Logistics\\Infrastructure\\Models\\WarehouseGiftSkuMapping::query()->create(['warehouse_id'=>$w->id,'source_sku'=>'gift','fulfillment_product_id'=>$p->id,'priority'=>1,'is_active'=>true]);",
].join('');
try {
    run = await createDisposableRun();
    const { env, port, runRoot } = run;
    assertRuntimeEnvironment(env, runRoot);
    await runManagedChild('php', ['artisan', 'migrate', '--force'], { env, timeout: 120000, stdio: 'ignore' });
    assertRuntimeEnvironment(env, runRoot, { allowPopulatedDatabase: true });
    const devEnv = { ...env, UAT_ADMIN_PASSWORD: 'password' };
    await runManagedChild('php', ['artisan', 'db:seed', '--class=LocalUatSeeder', '--force'], { env: devEnv, timeout: 120000, stdio: 'ignore' });
    await runManagedChild('php', ['artisan', 'tinker', '--execute', seed], { env: devEnv, timeout: 90000, stdio: 'ignore' });
    server = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', '--port=' + port], {
        cwd: path.resolve(import.meta.dirname, '../../..'), env: devEnv, stdio: 'ignore'
    });
    const base = 'http://127.0.0.1:' + port;
    let ready = false;
    for (let attempt = 0; attempt < 100; attempt++) {
        try { if ((await fetch(base + '/login')).ok) { ready = true; break; } } catch {}
        await delay(250);
    }
    assert(ready, 'Isolated Dev server not ready');
    browser = await chromium.launch({ channel: 'chrome', headless: !hold });
    const context = await browser.newContext({ viewport: { width: 1600, height: 900 } });
    await context.route('**/*', async route => {
        if (new URL(route.request().url()).hostname !== '127.0.0.1') await route.abort('blockedbyclient');
        else await route.continue();
    });
    const page = await context.newPage();
    const failures = [];
    page.on('pageerror', e => failures.push(e.message));
    await page.goto(base + '/login');
    await page.getByLabel('邮箱').fill('admin@local.test');
    await page.locator('input[name="password"]').fill('password');
    await Promise.all([page.waitForURL(url => url.pathname === '/dashboard'), page.getByRole('button', { name: '登录系统' }).click()]);
    const response = await page.goto(base + '/logistics/wait-stock?search=007-1119-c42587&global_grid=0', { waitUntil: 'networkidle' });
    assert.equal(response?.status(), 200);
    const order = page.locator('.allocation-table tbody tr').filter({ hasText: '007-1119-c42587' }).first();
    await order.waitFor({ state: 'visible', timeout: 15000 });
    await order.getByRole('button', { name: '查看方案' }).click();
    const row = page.locator('.plan-modal .manual-table-wrap tbody tr').filter({ has: page.locator('.gift-tag') }).first();
    await row.locator('td').nth(4).locator('select').selectOption('US-SM');
    const select = row.locator('td').nth(6).locator('select');
    const option = select.locator('option').filter({ hasText: 'BY489A' }).first();
    const value = await option.getAttribute('value');
    assert(value, 'Missing BY489A mapping');
    assert.match(await option.innerText(), /OMS 未验证此 SKU/);
    assert.equal(await option.isDisabled(), false, 'BY489A should be enabled');
    await select.selectOption(value);
    assert.match(await row.innerText(), /人工库存例外/);
    const regular = page.locator('.plan-modal .manual-table-wrap tbody tr').filter({ hasText: 'VA617A-GREEN' }).first();
    await regular.locator('td').nth(4).locator('select').selectOption('US-SM');
    assert.deepEqual(failures, []);
    process.stdout.write('US_SM_GIFT_DEV_SELECTION_PASS order=007-1119-c42587 sku=BY489A mapping=' + value + ' regular=VA617A-GREEN port=' + port + '\n');
    if (!hold) {
        const [submitted] = await Promise.all([
            page.waitForResponse(r => r.url().includes('/fulfillment-allocation/manual') && r.request().method() === 'POST'),
            page.getByRole('button', { name: '确认人工分配并提交' }).click(),
        ]);
        assert.equal(submitted.status(), 200, 'Manual allocation must submit even with zero regular stock');
        assert.equal((await submitted.json()).status, 'submitted');
        const id = submitted.url().match(/\/orders\/(\d+)\/fulfillment-allocation\/manual$/)?.[1];
        assert(id, 'Missing submitted order ID');
        const status = await page.request.get(base + '/logistics/orders/' + id + '/fulfillment-allocation/status');
        assert.equal(status.status(), 200);
        const saved = await status.json();
        assert.equal(saved.status, 'submitted');
        assert.equal(saved.decision_source, 'manual');
        assert.equal(saved.assignments?.length, 2);
        assert(saved.assignments.every(a => a.source_code === 'US-SM'));
        process.stdout.write('US_SM_GIFT_FULL_ORDER_DEV_PASS order=007-1119-c42587 items=2 warehouse=US-SM zero_regular_stock=true\n');
    }
    if (hold) {
        await page.bringToFront();
        process.stdout.write('US_SM_GIFT_OWNER_UAT_READY url=' + base + '/logistics/wait-stock?search=007-1119-c42587&global_grid=0 synthetic=true\n');
        await new Promise(resolve => { process.once('SIGTERM', resolve); process.once('SIGINT', resolve); });
    }
} catch (error) {
    process.stderr.write('US_SM_GIFT_DEV_FAIL ' + error.stack + '\n');
    process.exitCode = 1;
} finally {
    if (browser) await browser.close();
    if (server) {
        const exited = once(server, 'exit').catch(() => []);
        server.kill('SIGTERM');
        if (!await Promise.race([exited.then(() => true), delay(1500).then(() => false)])) {
            server.kill('SIGKILL'); await exited;
        }
    }
    if (run) await cleanupDisposableRun(run.runRoot);
}
