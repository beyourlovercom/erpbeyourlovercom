import { beforeEach, describe, expect, it } from 'vitest';
import type { ErpCurrentDevPage } from './erp-orders';
import {
  clearHotOrderSnapshots, getHotOrderCacheStats, getHotOrderSnapshot,
  HOT_BYTES_LIMIT, HOT_LIMIT, HOT_TTL_MS, putHotOrderSnapshot, sleepOtherHotOrders,
} from './erp-hot-snapshots';

const key = (n:number, role='owner') => ({role,scope:'all',page:n,perPage:50,search:''});
const data = (role='owner'): ErpCurrentDevPage => ({
 rows:[{id:1,order_number:'TEST-LOCAL-0001',order_kind:'客户订单',order_date:'2026-11-15',
 status:'待发货',warehouse:'us-ny',sku:'TEST-SKU',tracking_number:'',latest_note:''}],
 page:1,perPage:50,total:1,lastPage:1,filters:{},role,navigation:[],
});
describe('R6-T isolated read-only ERP hot snapshots',()=>{
 beforeEach(clearHotOrderSnapshots);
 it('retains at most three pages with LRU removal (no persistent store)',()=>{
  for(let n=1;n<=3;n++)expect(putHotOrderSnapshot(key(n),data(),100)).toBe(true);
  expect(getHotOrderSnapshot(key(1),101)).toBeDefined();
  expect(putHotOrderSnapshot(key(4),data(),102)).toBe(true);
  expect(getHotOrderCacheStats(103).entries).toBe(HOT_LIMIT);
  expect(getHotOrderSnapshot(key(2),104)).toBeUndefined();
  expect(getHotOrderSnapshot(key(1),104)).toBeDefined();
 });
 it('expires after sixty seconds, never serves old snapshot after timeout',()=>{
  putHotOrderSnapshot(key(1),data(),100);
  expect(getHotOrderSnapshot(key(1),100+HOT_TTL_MS-1)).toBeDefined();
  expect(getHotOrderSnapshot(key(1),100+HOT_TTL_MS)).toBeUndefined();
 });
 it('isolates role, query, scope and response authority',()=>{
  expect(putHotOrderSnapshot(key(1,'kol'),data('owner'),100)).toBe(false);
  expect(putHotOrderSnapshot(key(1),data(),100)).toBe(true);
  expect(getHotOrderSnapshot(key(1,'kol'),100)).toBeUndefined();
  expect(getHotOrderSnapshot({...key(1),search:'x'},100)).toBeUndefined();
  expect(getHotOrderSnapshot({...key(1),scope:'customers'},100)).toBeUndefined();
 });
 it('rejects 1.5 MB oversized entry and can sleep other snapshots',()=>{
  const big={...data(),navigation:[{key:'x',label:'x'.repeat(HOT_BYTES_LIMIT),path:'/'}]};
  expect(putHotOrderSnapshot(key(1),big,100)).toBe(false);
  for(let n=1;n<=3;n++)putHotOrderSnapshot(key(n),data(),100);
  sleepOtherHotOrders(key(2));
  expect(getHotOrderCacheStats(101).entries).toBe(1);
  expect(getHotOrderSnapshot(key(2),101)).toBeDefined();
  expect(getHotOrderSnapshot(key(1),101)).toBeUndefined();
 });
});