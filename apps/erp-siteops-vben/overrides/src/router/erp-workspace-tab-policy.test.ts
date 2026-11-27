import { describe, expect, it } from 'vitest';
import { canPinDevTab, DEV_PIN_LIMIT, DEV_TAB_LIMIT, pickDevLruVictim } from './erp-workspace-tab-policy';

const tab = (path:string, pinned:boolean) => ({path,meta:{affixTab:pinned}});
describe('R6-T Owner pin contract',()=>{
 it('caps at three including the default pinned dashboard',()=>{
  expect(DEV_PIN_LIMIT).toBe(3);
  const first=[tab('/erp/overview',true),tab('/erp/orders/orders-master',true),
    tab('/erp/kol/kol-orders',false)];
  expect(canPinDevTab(first,'/erp/kol/kol-orders')).toBe(true);
  const filled=[...first.slice(0,2),tab('/erp/kol/kol-orders',true),tab('/erp/inventory/inventory-list',false)];
  expect(canPinDevTab(filled,'/erp/inventory/inventory-list')).toBe(false);
 });
 it('allows re-pinning an already pinned tab and unpinning remains independent',()=>{
  const filled=[tab('/erp/overview',true),tab('/erp/orders/orders-master',true),tab('/erp/kol/kol-orders',true)];
  expect(canPinDevTab(filled,'/erp/kol/kol-orders')).toBe(true);
 });
 it('selects the least recently used non-pinned bookmark at the 30-tab cap',()=>{
  expect(DEV_TAB_LIMIT).toBe(30);
  const tabs=[tab('/erp/overview',true),tab('/erp/orders/orders-master',true),
    tab('/erp/orders/one',false),tab('/erp/orders/two',false)];
  const seen=new Map([['/erp/orders/one',50],['/erp/orders/two',10]]);
  expect(pickDevLruVictim(tabs,seen)?.path).toBe('/erp/orders/two');
  expect(pickDevLruVictim(tabs.slice(0,2),seen)).toBeNull();
 });
});