'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(path.join(__dirname,'frontend/node_modules/playwright'));
const base='http://127.0.0.1:18786';
(async()=>{
 const pass=fs.readFileSync(path.join(__dirname,'private/local-admin-key.txt'),'utf8').trim();
 const b=await chromium.launch({executablePath:'D:/Program Files/Chrome/Chrome/chrome.exe',headless:true});
 const page=await b.newPage({viewport:{width:1440,height:900}});
 try{
 await page.goto(base+'/#/auth/login');
 await page.locator('input[type=password]').fill(pass);
 await page.getByRole('button',{name:'进入 ERP 开发工作台'}).click();
 await page.waitForURL(/#\/erp\/overview/,{waitUntil:'commit',timeout:75000});
 for(const id of ['/erp/orders/orders-master','/erp/logistics/logistics-china','/erp/inventory/inventory-list','/erp/kol/kol-orders']){
  await page.goto(base+'/#'+id,{waitUntil:'domcontentloaded'});
  await page.waitForTimeout(250);
 }
 const tabs=page.locator('.tabs-chrome__item');
 console.log('PIN_TABS_BEFORE',JSON.stringify(await tabs.allTextContents()));
 const pin=async(name)=>{
   const t=tabs.filter({hasText:name}).first();
   await t.click({button:'right'});
   await page.getByRole('menuitem',{name:'固定',exact:true}).last().click();
   await page.waitForTimeout(300);
 };
 assert.equal(await page.locator('.tabs-chrome__item.affix-tab').count(),1,'dashboard initially pinned');
 await pin('订单总表');
 await pin('国内发货');
 assert.equal(await page.locator('.tabs-chrome__item.affix-tab').count(),3,'exactly three pinned');
 await pin('仓库清单');
 assert.equal(await page.locator('.tabs-chrome__item.affix-tab').count(),3,'fourth pin must be rejected');
 await page.getByText('最多固定 3 个工作标签',{exact:false}).first().waitFor({timeout:10000});
 console.log('R6T_PIN_3_CHROME_PASS 3 pins accepted, fourth rejected');
 }finally{await b.close();}
})().catch(e=>{console.error('PIN_TEST_ERROR',e.message);process.exitCode=1});