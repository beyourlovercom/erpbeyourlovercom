'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require(path.join(__dirname,'frontend/node_modules/playwright'));
const base='http://127.0.0.1:18786';
const cases=[['owner',123],['staff',123],['kol',20],['manager',41],['owner',123]];
const routes=[
'orders/orders-customers','orders/orders-kol','orders/orders-collaboration',
'orders/orders-procurement','orders/orders-archive',
'logistics/logistics-wait-stock','logistics/logistics-china',
'logistics/logistics-oversea','logistics/logistics-tingon',
'logistics/logistics-awaiting-tracking','logistics/logistics-in-transit',
'logistics/logistics-exceptions','logistics/logistics-carriers',
'kol/kol-library','kol/kol-orders','kol/kol-logistics',
'kol/kol-library-create','kol/kol-opportunities','kol/kol-collaborations',
'kol/kol-communications','kol/kol-samples','kol/kol-content',
'kol/kol-commissions','kol/kol-affiliatewp',
'inventory/inventory-overview','inventory/inventory-list',
'inventory/inventory-products','inventory/inventory-sync',
'inventory/inventory-packaging','inventory/inventory-factory-parameters',
'accounting/accounting-overview','accounting/accounting-order-costs',
'accounting/accounting-loss-orders','settings/settings-users',
'settings/settings-roles-permissions','settings/settings-fx',
];
const tabs='.tabs-chrome__item';
(async()=>{
 const secret=fs.readFileSync(path.join(__dirname,'private/local-admin-key.txt'),'utf8').trim();
 const b=await chromium.launch({executablePath:'D:/Program Files/Chrome/Chrome/chrome.exe',headless:true});
 const sessions=[];
 const errors=[];
 const start=Date.now();
 try{
  for(let i=0;i<cases.length;i++){
   const [role,count]=cases[i];
   const context=await b.newContext({viewport:i===4?{width:390,height:844}:{width:1440,height:900}});
   const page=await context.newPage();
   page.on('pageerror',e=>errors.push('s'+i+':'+e.message.slice(0,140)));
   await page.goto(base+'/#/auth/login',{waitUntil:'domcontentloaded',timeout:35000});
   await page.locator('input[type=password]').fill(secret);
   await page.getByRole('button',{name:'进入 ERP 开发工作台'}).click();
   await page.waitForURL(/#\/erp\/overview/,{waitUntil:'commit',timeout:80000});
   await page.goto(base+'/#/erp/orders/orders-master',{waitUntil:'domcontentloaded',timeout:35000});
   await page.getByText('总计 123 条').first().waitFor({timeout:80000});
   if(role!=='owner'){
    await page.getByLabel('Laravel Dev 测试角色').last().selectOption(role);
    await page.getByText('总计 '+count+' 条').first().waitFor({timeout:80000});
   }
   const body=await page.locator('body').innerText();
   assert.ok(!body.includes('UAT-ORDER-001'),'no synthetic data in real Dev mode');
   const authorized=i===4 ? '' : await page.locator('ul.vben-menu').first().innerText();
   if(role==='kol'||role==='manager')assert.ok(!authorized.includes('库存')&&!authorized.includes('算账'),'KOL no forbidden groups');
   sessions.push({context,page,role,count});
   console.log('SESSION_READY',i,role,count,'ms',Date.now()-start);
  }
  await Promise.all(sessions.map(async({page,role},index)=>{
   for(const suffix of routes){
    await page.evaluate(s=>{window.location.hash='#/erp/'+s},suffix);
    await page.waitForTimeout(75);
   }
   await page.waitForTimeout(450);
   const count=await page.locator(tabs).count();
   if(index!==4){
    assert.equal(count,30,'session '+index+' 30-tab cap');
    assert.ok((await page.locator(tabs+'.affix-tab').count())>=1,'dashboard pin survives');
   }else assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth),false,'mobile no overflow');
   console.log('TAB_STRESS_PASS',index,role,'visibleTabs',count);
  }));
  for(let i=0;i<sessions.length;i++){
   const {page,role,count}=sessions[i];
   await page.evaluate(()=>{window.location.hash='#/erp/orders/orders-master'});
   await page.getByText('总计 '+count+' 条').first().waitFor({timeout:80000});
   const nav=i===4 ? '' : await page.locator('ul.vben-menu').first().innerText();
   if(role==='kol'||role==='manager')assert.ok(!nav.includes('库存')&&!nav.includes('算账'));
   await page.locator('[aria-busy=true]').first().waitFor({state:'hidden',timeout:80000});
   const busy=await page.locator('[aria-busy=true]').count();
   assert.equal(busy,0,'revalidation done before session accepted');
   if(i===4)assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth),false);
   console.log('SESSION_RETURN_PASS',i,role,'tabs',await page.locator(tabs).count());
  }
  assert.equal(errors.length,0,'unexpected JS exceptions: '+errors.join('|'));
  console.log('R6T_5_SESSION_DEV_PASS','contexts',sessions.length,'tabTransitions',routes.length*sessions.length,'elapsedMs',Date.now()-start);
 }finally{await Promise.all(sessions.map(s=>s.context.close()));await b.close();}
})().catch(e=>{console.error('R6T_5_SESSION_DEV_FAIL',e.stack||e.message);process.exitCode=1});