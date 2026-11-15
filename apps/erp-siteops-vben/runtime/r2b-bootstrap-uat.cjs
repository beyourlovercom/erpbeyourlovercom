'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require(path.join(__dirname,'frontend/node_modules/playwright'));
const base='http://127.0.0.1:18786';
(async()=>{
 const pass=fs.readFileSync(path.join(__dirname,'private/local-admin-key.txt'),'utf8').trim();
 const browser=await chromium.launch({executablePath:'D:/Program Files/Chrome/Chrome/chrome.exe',headless:true});
 const page=await browser.newPage({viewport:{width:1440,height:900}});
 page.on('console',m=>{if(m.type()==='error')console.log('BROWSER_CONSOLE',m.text().slice(0,180));});
 try{
  await page.goto(base+'/#/auth/login',{waitUntil:'domcontentloaded'});
  await page.locator('input[type=password]').fill(pass);
  await page.getByRole('button',{name:'进入 ERP 开发工作台'}).click();
  await page.waitForURL(/#\/erp\/overview/,{waitUntil:'commit',timeout:75000});
  const sidebar=page.locator('ul.vben-menu').first();
  await sidebar.getByText('美东纽约仓',{exact:true}).waitFor({state:'attached',timeout:20000});
  let nav=(await sidebar.textContent())||'';
  assert.ok(nav.includes('订单')&&nav.includes('物流')&&nav.includes('算账'));
  assert.ok(!nav.includes('营销')&&!nav.includes('同步'),'Owner unauthorized groups must not flash');
  console.log('BOOTSTRAP_NAV_PASS',nav.slice(0,180));
  await page.goto(base+'/#/erp/orders/orders-master',{waitUntil:'domcontentloaded'});
  await page.getByText('总计 123 条').first().waitFor({timeout:55000});
  assert.equal(await page.getByText('UAT-ORDER-001',{exact:true}).count(),0);
  console.log('AUTO_ORDERS_PASS');
  const tabRoutes = [
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
  for (const suffix of tabRoutes) {
    await page.evaluate(s=>{ window.location.hash='#/erp/'+s; },suffix);
    await page.waitForTimeout(90);
  }
  await page.waitForTimeout(600);
  const tabCount = await page.locator('.tabs-chrome__item').count();
  assert.equal(tabCount,30,'Vben tabbar max count must be 30 after 36 visits');
  console.log('TAB_30_CAP_PASS',tabCount);
  await page.evaluate(()=>{ window.location.hash='#/erp/orders/orders-master'; });
  await page.getByText('总计 123 条').first().waitFor({timeout:55000});
  const roles=page.getByLabel('Laravel Dev 测试角色').last();
  await roles.selectOption('kol');
  await page.getByText('总计 20 条').first().waitFor({timeout:55000});
  nav=(await sidebar.textContent())||'';
  assert.ok(!nav.includes('库存')&&!nav.includes('算账'));
  await page.waitForFunction(()=>[...document.querySelectorAll('.tabs-chrome__item')].every(e=>!/库存|算账|产品库存/.test(e.textContent||'')),null,{timeout:15000});
  const roleTabs=await page.locator('.tabs-chrome__item').allTextContents();
  assert.ok(roleTabs.every(text=>!text.includes('库存')&&!text.includes('算账')&&!text.includes('产品库存')),
    'KOL role retains forbidden old ERP tabs');
  console.log('KOL_NAV_AND_TABS_PASS',roleTabs.length);
  await roles.selectOption('owner');
  await page.getByText('总计 123 条').first().waitFor({timeout:55000});
  console.log('OWNER_RESTORE_PASS');
  await roles.selectOption('manager');
  await roles.selectOption('owner');
  await page.getByText('总计 123 条').first().waitFor({timeout:55000});
  assert.ok(!(await sidebar.textContent())?.includes('营销'));
  console.log('ROLE_RACE_PASS');
  await page.setViewportSize({width:390,height:844});
  await page.waitForTimeout(400);
  const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth);
  assert.equal(overflow,false);
  console.log('R2B_BOOTSTRAP_BROWSER_PASS mobile_no_overflow');
 }finally{await browser.close()}
})().catch(e=>{console.error('R2B_BOOTSTRAP_BROWSER_FAIL',e.message);process.exitCode=1});