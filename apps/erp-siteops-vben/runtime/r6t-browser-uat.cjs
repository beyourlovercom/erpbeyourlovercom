'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs'),path=require('node:path');
const {chromium}=require(path.join(__dirname,'frontend/node_modules/playwright'));
const base='http://127.0.0.1:18786';
(async()=>{
 const key=fs.readFileSync(path.join(__dirname,'private/local-admin-key.txt'),'utf8').trim();
 const browser=await chromium.launch({channel:'msedge',headless:true});
 try{
  const page=await browser.newPage({viewport:{width:1440,height:900}});
  const forbidden=[];
  page.on('request',req=>{
   if(req.url().includes('/api/erp/current/orders')&&req.method()!=='GET')
     forbidden.push(req.method()+' '+req.url());
  });
  await page.goto(base+'/#/auth/login',{waitUntil:'domcontentloaded'});
  await page.locator('input[type=password]').fill(key);
  await page.getByRole('button',{name:'进入 ERP 开发工作台'}).click();
  await page.waitForURL(/#\/erp\/overview/,{timeout:30000,waitUntil:'commit'});
  await page.goto(base+'/#/erp/orders/orders-master');
  const count=page.getByText(/只读热快照 \d+ \/ 3/).last();
  await page.getByText('总计 123 条').last().waitFor({timeout:75000});
  assert.match(await count.textContent(),/1 \/ 3/);
  const scope=page.getByLabel('订单读取范围').last();
  await scope.selectOption('customers');
  await page.getByText('总计 82 条').last().waitFor({timeout:75000});
  assert.match(await count.textContent(),/2 \/ 3/);
  await scope.selectOption('all');
  await page.getByText('总计 123 条').last().waitFor({timeout:75000});
  const search=page.getByPlaceholder('搜索订单号、仓库或 SKU').last();
  await search.fill('EX-R2-00042'); await search.press('Enter');
  await page.getByText('总计 1 条').last().waitFor({timeout:75000});
  assert.match(await count.textContent(),/3 \/ 3/);
  await search.fill('not-an-existing-order-0');await search.press('Enter');
  await page.getByText('总计 0 条').last().waitFor({timeout:75000});
  assert.match(await count.textContent(),/3 \/ 3/,'bounded three hot responses');
  await search.fill(''); await search.press('Enter');
  await page.getByText('总计 123 条').last().waitFor({timeout:75000});
  const role=page.getByLabel('Laravel Dev 测试角色').last();
  await role.selectOption('kol');
  await page.getByText('总计 20 条').last().waitFor({timeout:75000});
  assert.match(await count.textContent(),/1 \/ 3/,'switch role invalidates previous snapshots');
  assert.equal(await page.getByText('EX-R2-00042',{exact:true}).count(),0);
  assert.deepEqual(forbidden,[],'business endpoint remains GET-only');
  console.log('R6T_BROWSER_PASS 3 snapshots LRU-cap, scope/search, role-clear, server revalidation, business GET-only');
 } finally {await browser.close();}
})().catch(e=>{console.error('R6T_BROWSER_FAIL',e.stack??e);process.exitCode=1});