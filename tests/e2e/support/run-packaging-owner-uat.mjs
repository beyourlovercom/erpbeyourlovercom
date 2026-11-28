import {createDisposableRun,cleanupDisposableRun,runManagedChild,assertRuntimeEnvironment} from './e2e-environment.mjs';
import {chromium} from '@playwright/test';
import {spawn} from 'node:child_process';
import assert from 'node:assert/strict';
process.env.BYL_E2E_BROWSER='chrome';
const hold=process.env.BYL_PACKAGING_OWNER_UAT==='1';
let run,server,browser;
try {
 run=await createDisposableRun();
 const env={...run.env,UAT_ADMIN_PASSWORD:'password',BYL_PACKAGING_OWNER_UAT:'1'};
 await runManagedChild('php',['tests/e2e/support/provision-users.php','--runtime-preflight'],{env,timeout:30000});
 await runManagedChild('php',['artisan','migrate','--force','--quiet'],{env,timeout:30000});
 assertRuntimeEnvironment(env,run.runRoot,{allowPopulatedDatabase:true});
 await runManagedChild('php',['artisan','db:seed','--class=LocalUatSeeder','--force'],{env,timeout:30000,stdio:'ignore'});
 await runManagedChild('php',['tests/e2e/support/provision-packaging-optimization.php'],{env,timeout:30000});
 server=spawn('php',['artisan','serve','--host=127.0.0.1','--port='+run.port],{env,stdio:'ignore'});
 const base=env.BYL_E2E_BASE_URL;
 let ready=false;
 for(let i=0;i<80;i++){try{if((await fetch(base+'/login')).ok){ready=true;break}}catch{}await new Promise(r=>setTimeout(r,250))}
 assert(ready);
 browser=await chromium.launch({channel:'chrome',headless:!hold});
 const context=await browser.newContext({viewport:{width:1500,height:900},baseURL:base});
 await context.route('**/*',r=>new URL(r.request().url()).hostname==='127.0.0.1'?r.continue():r.abort());
 context.setDefaultTimeout(15000);const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto('/login');await page.getByLabel('邮箱').fill('admin@local.test');await page.locator('input[name=password]').fill('password');await page.getByRole('button',{name:'登录系统'}).click();await page.waitForURL(u=>u.pathname!=='/login');
 assert.equal((await page.goto('/inventory/packaging?tab=optimization')).status(),200);
 await page.getByText('UAT-PACKAGE-001',{exact:true}).waitFor();
 await page.getByRole('row').filter({hasText:'UAT-PACKAGE-001'}).getByRole('button',{name:'查看方案'}).click();
 await page.getByText('高度 ≤ 13.4 cm',{exact:true}).waitFor();
 assert.equal(await page.locator('.details tbody tr').count(),4);
 await page.getByText('参考体积除数',{exact:false}).waitFor();
 const route=page.locator('.route-controls select').filter({has:page.locator('option').filter({hasText:'UAT-TEST'})});
 await route.selectOption({label:await route.locator('option').filter({hasText:'UAT-TEST'}).innerText()});
 await page.getByPlaceholder('实际试算邮编').fill('10001');
 await page.getByLabel('确认本次假设路线适用').check();
 await page.getByRole('button',{name:'重新测算'}).click();
 await page.waitForURL(u=>u.searchParams.get('confirmed')==='1');

 await page.getByText('参考 ¥10.00',{exact:true}).waitFor();
 assert.deepEqual(errors,[]);
 const detailUrl=page.url();
 await page.locator('.details').scrollIntoViewIfNeeded();
 // Factory account must not get the catalogue or rate preview.
 const factory=await browser.newContext({baseURL:base});const fp=await factory.newPage();
 await fp.goto('/login');await fp.getByLabel('邮箱').fill('factory@packaging.invalid');await fp.locator('input[name=password]').fill(env.BYL_E2E_ACTIVE_PASSWORD);await fp.getByRole('button',{name:'登录系统'}).click();await fp.waitForURL(u=>u.pathname!=='/login');
 assert.equal((await factory.request.get('/inventory/packaging?tab=optimization')).status(),403);
 await factory.close();
 const standards=await context.newPage();assert.equal((await standards.goto('/inventory/packaging?tab=standards')).status(),200);await standards.getByText('UAT-PACKAGE-001',{exact:true}).waitFor();
 const records=await context.newPage();assert.equal((await records.goto('/inventory/packaging?tab=records')).status(),200);
 await records.getByText('UAT-PACKAGE-001',{exact:true}).first().waitFor();
 const create=await context.newPage();assert.equal((await create.goto('/inventory/packaging/create')).status(),200);
 await create.getByText('尺寸测量图',{exact:false}).first().waitFor();
 assert.equal(await create.locator('.upload-title em').count(),0);
 await page.bringToFront();
 console.log('PACKAGING_WORKSPACE_UAT_READY '+JSON.stringify({base,detailUrl,port:run.port,db:'isolated synthetic SQLite',tabs:4,headless:!hold,serverPid:server.pid}));
 if(hold)await new Promise(resolve=>{process.once('SIGTERM',resolve);process.once('SIGINT',resolve)});
} finally {
 await browser?.close();
 if(server&&!server.killed){server.kill('SIGTERM');await new Promise(r=>server.once('exit',r))}
 if(run)await cleanupDisposableRun(run.runRoot);
}
