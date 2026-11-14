'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs'),path=require('node:path');
const {chromium}=require(path.join(__dirname,'frontend/node_modules/playwright'));
const base='http://127.0.0.1:18786';
(async()=>{
  const secret=fs.readFileSync(path.join(__dirname,'private/local-admin-key.txt'),'utf8').trim();
  const browser=await chromium.launch({headless:true,channel:'msedge'});
  try {
    const page=await browser.newPage({viewport:{width:1440,height:900}});
    await page.goto(base+'/#/auth/login',{waitUntil:'domcontentloaded'});
    await page.locator('input[type=password]').fill(secret);
    await page.getByRole('button',{name:'进入 ERP 开发工作台'}).click();
    await page.waitForURL(/#\/erp\/overview/,{timeout:20000});
    await page.goto(base+'/#/erp/orders',{waitUntil:'domcontentloaded'});
    const btn=page.getByRole('button',{name:'读取 Mac mini 的真实 Dev 订单'});
    await btn.waitFor({timeout:15000});
    await btn.click();
    await page.getByText(/Mac mini 本地 Dev SQLite · 非正式订单/).waitFor({timeout:18000});
    await page.getByText('10 条',{exact:false}).first().waitFor({timeout:18000});
    await page.waitForFunction(()=>document.querySelectorAll('.ag-row').length===10,{timeout:18000});
    assert.equal(await page.getByText('UAT-ORDER-001',{exact:true}).count(),0);
    assert.equal(await page.locator('[role=alert]').count(),0);
    await page.screenshot({path:path.join(__dirname,'r2-dev-orders-desktop.png'),fullPage:true});
    const input=page.getByPlaceholder('搜索订单号、仓库或 SKU');
    await input.fill('R2-NOT-FOUND-STRING-999999');
    await input.press('Enter');
    await page.getByText(/总计 0 条/).waitFor({timeout:12000});
    await input.fill('');
    await input.press('Enter');
    await page.getByText(/总计 10 条/).waitFor({timeout:12000});
    const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth);
    console.log('R2 EDGE PASS: real Dev 10 rows, no synthetic fallback, server search 0->10, no alerts, overflow='+overflow);
  }finally{await browser.close();}
})().catch(e=>{console.error('R2 EDGE FAIL:',e.message);process.exitCode=1});