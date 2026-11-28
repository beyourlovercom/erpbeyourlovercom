import {createDisposableRun,cleanupDisposableRun,runManagedChild} from './e2e-environment.mjs';
import {chromium} from '@playwright/test';
import {spawn} from 'node:child_process';
import assert from 'node:assert/strict';

let run, server, browser;
try {
    run=await createDisposableRun();
    const {env,port}=run;
    await runManagedChild('php',['tests/e2e/support/provision-users.php','--runtime-preflight'],{env,timeout:30000});
    await runManagedChild('php',['artisan','migrate','--force','--quiet'],{env,timeout:30000});
    await runManagedChild('php',['tests/e2e/support/provision-packaging-optimization.php'],{env,timeout:30000});
    server=spawn('php',['artisan','serve','--host=127.0.0.1','--port='+port],{env,stdio:'ignore'});
    const base=env.BYL_E2E_BASE_URL;
    let up=false;
    for(let i=0;i<40;i++){
        try {const r=await fetch(base+'/login'); if(r.ok){up=true;break;}}catch{}
        await new Promise(r=>setTimeout(r,250));
    }
    assert(up,'isolated server did not start');
    browser=await chromium.launch({channel:'chrome',headless:true});
    const login=async(role)=>{
        const context=await browser.newContext({baseURL:base});
        const page=await context.newPage();
        const errors=[];
        page.on('pageerror',e=>errors.push(e.message));
        await page.goto('/login');
        await page.getByLabel('邮箱').fill(role+'@packaging.invalid');
        await page.locator('input[name=password]').fill(env.BYL_E2E_ACTIVE_PASSWORD);
        await page.getByRole('button',{name:'登录系统'}).click();
        await page.waitForURL(u=>u.pathname!=='/login',{timeout:15000});
        return {page,context,errors};
    };
    const factory=await login('factory');
    await factory.page.goto('/inventory/packaging');
    await factory.page.getByText('UAT-PACKAGE-001',{exact:true}).first().waitFor();
    await factory.page.getByRole('link',{name:'查看',exact:true}).first().click();
    await factory.page.waitForURL(/\/inventory\/packaging\/\d+$/);
    await factory.page.getByText('Synthetic rejected evidence',{exact:false}).waitFor();
    const originalId=factory.page.url().split('/').pop();
    await factory.page.getByRole('button',{name:'重新测量',exact:true}).click();
    await factory.page.waitForURL(/\/inventory\/packaging\/\d+\/edit$/);
    await factory.page.getByText('尺寸测量图', {exact:false}).first().waitFor();
    assert.equal(await factory.page.locator('.upload-title em').count(),0);
    const draftId=factory.page.url().split('/').at(-2);
    assert.notEqual(draftId,originalId);
    await factory.page.locator('.dimension-inputs input').nth(0).fill('39');
    await factory.page.locator('.dimension-inputs input').nth(1).fill('25.5');
    await factory.page.locator('.dimension-inputs input').nth(2).fill('18.7');
    await factory.page.locator('.weight-fields input[type=number]').fill('2.232');
    await factory.page.locator('input[type=datetime-local]').fill('2026-11-27T10:00');
    await factory.page.getByRole('button',{name:'提交审核',exact:true}).click();
    await factory.page.waitForURL(u=>u.pathname==='/inventory/packaging/'+draftId);
    await factory.page.getByText('待审核',{exact:true}).first().waitFor();
    await factory.page.goto('/inventory/packaging/create');
    await factory.page.getByText('新建',{exact:false}).first().waitFor();
    assert.equal((await factory.context.request.get('/inventory')).status(),403);
    assert.deepEqual(factory.errors,[]);
    await factory.context.close();
    const reviewer=await login('reviewer');
    await reviewer.page.goto('/inventory/packaging');
    await reviewer.page.getByText('UAT-PACKAGE-001',{exact:true}).first().waitFor();
    await reviewer.page.goto('/inventory/packaging/'+draftId);
    await reviewer.page.locator('textarea').fill('Browser synthetic photo review');
    await reviewer.page.getByLabel('驳回后要求拍照复核').check();
    await reviewer.page.getByRole('button',{name:'驳回',exact:true}).click();
    await reviewer.page.getByText('已驳回',{exact:true}).first().waitFor();
    const second=await login('factory');
    await second.page.goto('/inventory/packaging/'+draftId);
    await second.page.getByRole('button',{name:'重新测量',exact:true}).click();
    await second.page.waitForURL(/\/inventory\/packaging\/\d+\/edit$/);
    await second.page.getByText('本次为拍照复核，须上传尺寸图及称重图。',{exact:true}).waitFor();
    assert.equal(await second.page.locator('.upload-title em').count(),2);
    await second.page.locator('.dimension-inputs input').nth(0).fill('39');
    await second.page.locator('.dimension-inputs input').nth(1).fill('25.5');
    await second.page.locator('.dimension-inputs input').nth(2).fill('18.7');
    await second.page.locator('.weight-fields input[type=number]').fill('2.232');
    await second.page.locator('input[type=datetime-local]').fill('2026-11-27T10:00');
    await second.page.getByRole('button',{name:'提交审核',exact:true}).click();
    await second.page.getByText('运营要求拍照复核，请上传尺寸图及称重图。',{exact:true}).waitFor();
    assert.deepEqual(second.errors,[]);
    await second.context.close();
    assert.equal((await reviewer.context.request.get('/inventory/packaging/create')).status(),403);
    assert.deepEqual(reviewer.errors,[]);
    await reviewer.context.close();
    console.log('PACKAGING_PHOTO_BROWSER_PASS: no-photo submission, reviewer requested photos, resubmission blocked until photos, role isolation, no uncaught page errors');
} finally {
    await browser?.close();
    if(server&&!server.killed){server.kill('SIGTERM');await new Promise(r=>server.once('exit',r));}
    if(run){await cleanupDisposableRun(run.runRoot);console.log('PACKAGING_DISPOSABLE_CLEANUP_PASS');}
}
