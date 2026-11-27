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
    await factory.page.getByText('UAT-PACKAGE-001',{exact:true}).waitFor();
    await factory.page.getByRole('link',{name:'查看',exact:true}).first().click();
    await factory.page.waitForURL(/\/inventory\/packaging\/\d+$/);
    await factory.page.getByText('Synthetic rejected evidence',{exact:false}).waitFor();
    await factory.page.goto('/inventory/packaging/create');
    await factory.page.getByText('新建',{exact:false}).first().waitFor();
    assert.equal((await factory.context.request.get('/inventory')).status(),403);
    assert.deepEqual(factory.errors,[]);
    await factory.context.close();
    const reviewer=await login('reviewer');
    await reviewer.page.goto('/inventory/packaging');
    await reviewer.page.getByText('UAT-PACKAGE-001',{exact:true}).waitFor();
    await reviewer.page.getByRole('link',{name:'查看',exact:true}).first().click();
    await reviewer.page.getByText('Synthetic rejected evidence',{exact:false}).waitFor();
    assert.equal((await reviewer.context.request.get('/inventory/packaging/create')).status(),403);
    assert.deepEqual(reviewer.errors,[]);
    await reviewer.context.close();
    console.log('PACKAGING_LEGACY_BROWSER_SMOKE_PASS: factory list/detail/create, reviewer list/detail, role isolation, no uncaught page errors');
} finally {
    await browser?.close();
    if(server&&!server.killed){server.kill('SIGTERM');await new Promise(r=>server.once('exit',r));}
    if(run){await cleanupDisposableRun(run.runRoot);console.log('PACKAGING_DISPOSABLE_CLEANUP_PASS');}
}
