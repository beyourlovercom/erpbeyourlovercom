'use strict';
const fs=require('node:fs'),path=require('node:path');
const assert=require('node:assert/strict');
const {chromium}=require(path.join(__dirname,'frontend/node_modules/playwright'));
const base='http://127.0.0.1:18786';
(async()=>{
 const secret=fs.readFileSync(path.join(__dirname,'private/local-admin-key.txt'),'utf8').trim();
 const b=await chromium.launch({channel:'msedge',headless:true});
 try{
 const page=await b.newPage({viewport:{width:1440,height:900}});
 await page.goto(base+'/#/auth/login');
 await page.locator('input[type=password]').fill(secret);
 await page.getByRole('button',{name:'进入 ERP 开发工作台'}).click();
 await page.waitForURL(/#\/erp\/overview/,{timeout:25000});
 await page.waitForTimeout(1000);
 const nav=await page.evaluate(()=>[...document.querySelectorAll('aside,nav,[role=menu]')].map(e=>({tag:e.tagName,cls:e.className,txt:e.textContent?.trim().slice(0,500)})).slice(0,12));
 console.log('SIDEBARS',JSON.stringify(nav).slice(0,4800));
 for(const uri of ['/erp/orders','/erp/orders/orders-master','/erp/logistics/logistics-china','/erp/accounting/accounting-overview','/erp/inventory/inventory-list']){
   await page.goto(base+'/#'+uri,{waitUntil:'domcontentloaded'});
   await page.waitForTimeout(400);
   const h=await page.locator('h1:visible').allTextContents();
   console.log('ROUTE',uri,'actual',new URL(page.url()).hash,'h1',h.slice(0,2),'visibleGrids',await page.locator('.ag-root:visible').count());
 }
 await page.goto(base+'/#/erp/logistics/logistics-china');
 await page.screenshot({path:path.join(__dirname,'nav-r6-desktop.png')});
 console.log('NAV_R6_UAT_DONE');
 }finally{await b.close();}
})().catch(e=>{console.error('NAV_R6_UAT_FAIL',e.message);process.exitCode=1});