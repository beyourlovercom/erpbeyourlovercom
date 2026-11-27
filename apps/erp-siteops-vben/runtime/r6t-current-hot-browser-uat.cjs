'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require(path.join(__dirname,'frontend/node_modules/playwright'));
const base='http://127.0.0.1:18786';
(async()=>{
 const pass=fs.readFileSync(path.join(__dirname,'private/local-admin-key.txt'),'utf8').trim();
 const b=await chromium.launch({executablePath:'D:/Program Files/Chrome/Chrome/chrome.exe',headless:true});
 try{
 const page=await b.newPage({viewport:{width:1440,height:900}});
 await page.goto(base+'/#/auth/login',{waitUntil:'domcontentloaded'});
 await page.locator('input[type=password]').fill(pass);
 await page.getByRole('button',{name:'进入 ERP 开发工作台'}).click();
 await page.waitForURL(/#\/erp\/overview/,{waitUntil:'commit',timeout:80000});
 await page.goto(base+'/#/erp/orders/orders-master',{waitUntil:'domcontentloaded'});
 await page.getByText('总计 123 条').first().waitFor({timeout:65000});
 await page.getByText('只读热快照 1 / 3').last().waitFor({timeout:15000});
 const load=page.getByRole('button',{name:'读取当前 Laravel Dev（按角色授权）'}).last();
 let slow=0;
 await page.route('**/api/erp/current/orders*',async route=>{
   slow++;
   await new Promise(resolve=>setTimeout(resolve,1750));
   await route.continue();
 });
 await load.click();
 await page.locator('[role=status]').filter({hasText:'热快照待服务器校验'}).first().waitFor({timeout:12000});
 assert.ok(await page.locator('[aria-busy=true]').count()>0,'stale grid must be busy');
 assert.ok(slow===1,'revalidation GET started exactly once');
 console.log('HOT_STALE_LOCK_PASS');
 await page.getByText('总计 123 条').first().waitFor({timeout:65000});
 await page.locator('[role=status]').filter({hasText:'热快照待服务器校验'}).first().waitFor({state:'hidden',timeout:65000});
 console.log('HOT_REVALIDATION_PASS',slow);
 await page.unroute('**/api/erp/current/orders*');
 await page.route('**/api/erp/current/orders*',route=>route.abort('failed'));
 await load.click();
 await page.locator('[role=alert]').first().waitFor({timeout:25000});
 const txt=(await page.locator('body').innerText());
 assert.ok(!txt.includes('123 条'),'failed check must clear prior rows');
 assert.equal(await page.locator('ul.vben-menu').first().innerText(),'');
 console.log('HOT_FAIL_CLOSED_PASS');
 await page.unroute('**/api/erp/current/orders*');
 await load.click();
 await page.getByText('总计 123 条').first().waitFor({timeout:65000});
 console.log('HOT_RETRY_PASS');
 await page.getByRole('button',{name:'休眠其他页缓存'}).last().click();
 console.log('R6T_CURRENT_CHROME_HOT_SNAPSHOTS_PASS');
 }finally{await b.close()}
})().catch(e=>{console.error('R6T_CURRENT_CHROME_HOT_SNAPSHOTS_FAIL',e.message);process.exitCode=1});