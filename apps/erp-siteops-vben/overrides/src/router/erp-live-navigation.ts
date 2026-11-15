import type { Router } from 'vue-router';
import type { MenuRecordRaw } from '@vben/types';

import { ref } from 'vue';
import { useAccessStore } from '@vben/stores';

import { getCurrentDevReadOnlyOrders, type ErpCurrentDevPage, type ErpDevMenuNode } from '#/api/erp-orders';

export type DevRole = 'owner' | 'staff' | 'kol' | 'manager';
export const activeDevRole = ref<DevRole>('owner');
export const devBootstrapError = ref('');
let approvedSource: MenuRecordRaw[] = [];
let initialPage: ErpCurrentDevPage | undefined;

// The local Owner credential authenticates ONLY this isolated 007 UAT shell.
// This navigation is computed by the CURRENT Laravel Dev session, not static owner menus.
export function setApprovedMenuSource(menus: MenuRecordRaw[]) {
  approvedSource = [...menus];
}
export function suspendAuthorizedDevNavigation() {
  useAccessStore().setAccessMenus([]);
}
export function takeInitialDevOrders(input: {
  role: DevRole; search?: string; page?: number; perPage?: number; scope?: string;
}): ErpCurrentDevPage | undefined {
  if (input.role !== activeDevRole.value || input.scope !== 'all' ||
      input.search || (input.page ?? 1) !== 1 || (input.perPage ?? 50) !== 50) return;
  const page = initialPage;
  initialPage = undefined;
  return page;
}
export async function bootstrapAuthorizedDevNavigation(router: Router) {
  suspendAuthorizedDevNavigation();
  initialPage = undefined;
  devBootstrapError.value = '';
  try {
    const page = await getCurrentDevReadOnlyOrders({role:activeDevRole.value, page:1, perPage:50});
    if (page.role !== activeDevRole.value) throw new Error('DEV_ROLE_MISMATCH');
    applyAuthorizedDevNavigation(page.navigation, router);
    initialPage = page;
  } catch (error) {
    devBootstrapError.value = error instanceof Error ? error.message : '授权菜单加载失败';
    suspendAuthorizedDevNavigation();
  }
}
// Browser sidebar is NOT a security boundary; server-side Laravel permissions and
// order scopes are still enforced per request. Never restore all owner menu routes
// while a per-role Dev request is pending or failed.
export function applyAuthorizedDevNavigation(navigation: ErpDevMenuNode[], router: Router) {
  const menus: MenuRecordRaw[] = [];
  for (const [index, group] of navigation.entries()) {
    if (!/^[a-z0-9_-]+$/.test(group.key)) continue;
    const parentPath = group.key === 'dashboard' ? '/erp/overview' : '/erp/' + group.key;
    const original = approvedSource.find((item) => item.path === parentPath);
    if (!original) continue;
    const children: MenuRecordRaw[] = [];
    if (group.key === 'orders') children.push({ name:'订单总表', path:'/erp/orders/orders-master' });
    for (const child of group.children ?? []) {
      if (!/^[a-z0-9_-]+$/.test(child.key) || !child.path.startsWith('/')) continue;
      const routeName = 'ERPMenu-' + child.key;
      const navPath = parentPath + '/' + child.key;
      if (!router.hasRoute(routeName)) {
        router.addRoute('ERPSection-' + group.key, {
          name: routeName, path: child.key,
          component: () => import('#/views/erp/workspace.vue'),
          meta: {title:child.label, keepAlive:true, erpMenuKey:child.key,
            erpModule:group.key, erpOriginalPath:child.path,
            erpSourceStatus:'implemented'},
        });
      }
      children.push({name:child.label, path:navPath});
    }
    menus.push({
      name:group.label, path:parentPath, icon:original.icon, order:index,
      ...(children.length ? {children} : {}),
    });
  }
  useAccessStore().setAccessMenus(menus);
}
export function clearAuthorizedDevNavigation() {
  suspendAuthorizedDevNavigation();
  initialPage = undefined;
}