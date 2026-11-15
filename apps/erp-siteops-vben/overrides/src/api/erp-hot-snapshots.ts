import type { ErpCurrentDevPage } from './erp-orders';

// R6-T scoped response cache: isolated 007 Dev, read-only Orders, never Vue/AG Grid instances.
export const HOT_LIMIT = 3;
export const HOT_TTL_MS = 60_000;
export const HOT_BYTES_LIMIT = 1_500_000;
const VERSION = 'b07885e36c491a997f88ae3c7a6ef0b14d17ad31';
export interface HotOrderKey {
  role: string; scope: string; page: number; perPage: number; search: string;
}
interface Entry { at: number; json: string; bytes: number; }
const values = new Map<string, Entry>();

function encodeKey(q: HotOrderKey) {
  return JSON.stringify([VERSION, q.role, q.scope, q.page, q.perPage, q.search.trim()]);
}
function prune(now: number) {
  for (const [key, value] of values) if (now - value.at >= HOT_TTL_MS) values.delete(key);
}
export function getHotOrderSnapshot(q: HotOrderKey, now = Date.now()): ErpCurrentDevPage | undefined {
  prune(now);
  const key = encodeKey(q), entry = values.get(key);
  if (!entry) return;
  values.delete(key);
  values.set(key, entry); // bounded recency, no disk or sessionStorage persistence
  const data: ErpCurrentDevPage = JSON.parse(entry.json);
  if (data.role !== q.role) { values.delete(key); return; }
  return data;
}
export function putHotOrderSnapshot(q: HotOrderKey, data: ErpCurrentDevPage, now = Date.now()) {
  if (data.role !== q.role || !Array.isArray(data.rows) ||
      data.rows.length > 100 || data.rows.some(row => !row.order_number)) return false;
  const json = JSON.stringify(data);
  const bytes = new TextEncoder().encode(json).length;
  if (bytes > HOT_BYTES_LIMIT) return false;
  prune(now);
  const key = encodeKey(q);
  values.delete(key);
  values.set(key, {at:now,json,bytes});
  while (values.size > HOT_LIMIT) values.delete(values.keys().next().value!);
  return true;
}
export function expireHotOrderSnapshot(q: HotOrderKey) {
  values.delete(encodeKey(q));
}
export function sleepOtherHotOrders(q: HotOrderKey) {
  const keep = encodeKey(q);
  for (const key of values.keys()) if (key !== keep) values.delete(key);
}
export function clearHotOrderSnapshots() { values.clear(); }
export function getHotOrderCacheStats(now = Date.now()) {
  prune(now);
  return {entries:values.size, bytes:[...values.values()].reduce((sum, v) => sum+v.bytes,0)};
}