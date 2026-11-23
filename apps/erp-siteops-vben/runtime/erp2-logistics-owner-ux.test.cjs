'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const source=(p)=>fs.readFileSync(path.join(__dirname,'../overrides/src',p),'utf8');
test('ERP2 defaults Light and keeps explicit theme switching after one-time cache migration',()=>{
 const pref=source('preferences.ts'),main=source('main.ts');
 assert.match(pref,/theme:\s*\{\s*mode:\s*'light'/);
 assert.match(main,/updatePreferences\(\{\s*theme:\s*\{\s*mode:\s*'light'/);
 assert.match(main,/localStorage\.getItem\(key\)\s*!==\s*'1'/);
 assert.match(main,/localStorage\.setItem\(key,\s*'1'\)/);
});
test('Oversea workbench preserves exact seven original ERP queue tabs',()=>{
 const vue=source('views/erp/logistics-projection.vue');
 const match=vue.match(/const overseasViews:[\s\S]+?=\s*\[([\s\S]+?)\];/);
 assert.ok(match);
 const tabs=[...match[1].matchAll(/\{key:'[^']+',label:'([^']+)'\}/g)].map(m=>m[1]);
 assert.deepEqual(tabs,['待审核','缺货单','待发货','已下发','已发货','异常订单','全部订单']);
});
test('Dede sees six dedicated warehouse selectors; useless KPI row is deleted',()=>{
 const vue=source('views/erp/logistics-projection.vue');
 const match=vue.match(/const warehouses\s*=\s*\[([\s\S]+?)\];/);
 assert.ok(match);
 const choices=[...match[1].matchAll(/\{value:'[^']*',label:'([^']+)'\}/g)].map(m=>m[1]);
 assert.deepEqual(choices,['全部海外仓','美国 NY','美国 MorePlus','美国 3SM','德国天运','加拿大']);
 assert.match(vue,/aria-label="海外仓切换"/);
 assert.match(vue,/@click="switchWarehouse\(w.value\)"/);
 assert.doesNotMatch(vue,/当前队列订单 \/ 物流单|数据链路|<strong class="mt-1 block text-2xl"/);
});
test('Disconnected Dev gateway renders actionable diagnostic, never generic Failed to fetch',()=>{
 const vue=source('views/erp/logistics-projection.vue');
 assert.match(vue,/隔离 Dev 网关连接失败/);
 assert.match(vue,/请检查本机预览服务后点击/);
});
