import type { Router } from 'vue-router';
import type { MenuRecordRaw } from '@vben/types';

import { ref } from 'vue';
import { useAccessStore, useTabbarStore, useUserStore } from '@vben/stores';
import { clearHotOrderSnapshots } from '#/api/erp-hot-snapshots';

import { getCurrentDevReadOnlyOrders, type ErpCurrentDevPage, type ErpDevMenuNode } from '#/api/erp-orders';
import { getCanonicalErpIdentity, canonicalAuthorityFingerprint, type CanonicalErpIdentity } from '#/api/erp-identity';

export type DevRole = 'owner' | 'staff' | 'kol' | 'manager';
export const activeDevRole = ref<DevRole>('owner');
export const devBootstrapError = ref('');
export const canonicalDevIdentity = ref<CanonicalErpIdentity | undefined>();
const canonicalAllowedPaths = new Set<string>();
export const isCanonicalErpPathAllowed = (path: string) => canonicalAllowedPaths.has(path);

// Route changes are read-only against the last verified scope. An independent
// timer/focus check performs revalidation, never a fetch on every sidebar click.
let canonicalRefresh: Promise<boolean> | undefined;
let authorityGeneration = 0;
export async function bootstrapCanonicalDevNavigation(
  router: Router, forceRevalidation = false,
): Promise<boolean> {
  if (!forceRevalidation && canonicalDevIdentity.value) return true;
  if (canonicalRefresh) return canonicalRefresh;
  const generation = authorityGeneration;
  const refresh = async (): Promise<boolean> => {
    const priorAuthority = canonicalDevIdentity.value;
    devBootstrapError.value = '';
    try {
      const identity = await getCanonicalErpIdentity();
      if (generation !== authorityGeneration) return !!canonicalDevIdentity.value;
      const changed = !!priorAuthority &&
        canonicalAuthorityFingerprint(priorAuthority) !== canonicalAuthorityFingerprint(identity);
      // Revocation, identity switch, or permission narrowing invalidates tabs
      // and hot snapshots before the new authorized menu is installed.
      if (changed) clearAuthorizedDevNavigation();
      canonicalDevIdentity.value = identity;
      if (!priorAuthority || changed) {
        useUserStore().setUserInfo({
          userId: String(identity.user.id), id: String(identity.user.id),
          username: identity.user.email,
          realName: identity.user.name, roles: [], avatar: '',
          homePath: '/erp/orders/orders-master',
        });
        applyAuthorizedDevNavigation(identity.navigation, router, {
          allowOrdersMaster: identity.effectivePermissionKeys.includes('orders.view'),
        });
      }
      canonicalAllowedPaths.clear();
      for (const group of identity.navigation) {
        if (!/^[a-z0-9_-]+$/.test(group.key)) continue;
        const parent = group.key === 'dashboard' ? '/erp/overview' : '/erp/' + group.key;
        if (!group.children?.length) canonicalAllowedPaths.add(parent);
        for (const child of group.children ?? []) {
          if (/^[a-z0-9_-]+$/.test(child.key))
            canonicalAllowedPaths.add(parent + '/' + child.key);
        }
        if (group.key === 'orders' && identity.effectivePermissionKeys.includes('orders.view'))
          canonicalAllowedPaths.add('/erp/orders/orders-master');
      }
      return true;
    } catch (reason) {
      if (generation !== authorityGeneration) return !!canonicalDevIdentity.value;
      devBootstrapError.value =
        reason instanceof Error ? reason.message : 'ERP2_CANONICAL_AUTH_FAILED';
      if (devBootstrapError.value === 'ERP2_CANONICAL_SESSION_REQUIRED' ||
          devBootstrapError.value === 'ERP2_CANONICAL_IDENTITY_INVALID' ||
          devBootstrapError.value === 'ERP2_CANONICAL_NAVIGATION_INVALID') {
        clearAuthorizedDevNavigation();
        return false;
      }
      // A transient 503/network error must not erase an already verified
      // sidebar or the Owner's open tabs. Server ACL still denies requests.
      return !!priorAuthority;
    }
  };
  canonicalRefresh = refresh();
  try { return await canonicalRefresh; }
  finally { canonicalRefresh = undefined; }
}
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
export function applyAuthorizedDevNavigation(
  navigation: ErpDevMenuNode[], router: Router,
  options: {allowOrdersMaster?: boolean} = {},
) {
  const menus: MenuRecordRaw[] = [];
  for (const [index, group] of navigation.entries()) {
    if (!/^[a-z0-9_-]+$/.test(group.key)) continue;
    const parentPath = group.key === 'dashboard' ? '/erp/overview' : '/erp/' + group.key;
    const original = approvedSource.find((item) => item.path === parentPath);
    if (!original) continue;
    const children: MenuRecordRaw[] = [];
    if (group.key === 'orders' && options.allowOrdersMaster !== false)
      children.push({ name:'订单总表', path:'/erp/orders/orders-master' });
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
  // A former Owner tab (even pinned) must not outlive a narrower Dev test role.
  // Remove forbidden cached/bookmarked ERP routes whenever server scope changes.
  const allowed = new Set<string>();
  for (const menu of menus) {
    allowed.add(menu.path);
    for (const child of menu.children ?? []) allowed.add(child.path);
  }
  const tabbar = useTabbarStore();
  const forbidden = tabbar.tabs
    .filter((tab) => tab.path.startsWith('/erp/') && !allowed.has(tab.path))
    .map((tab) => String(tab.key ?? tab.path));
  if (forbidden.length) void tabbar._bulkCloseByKeys(forbidden);
}
export function clearAuthorizedDevNavigation() {
  authorityGeneration++;
  canonicalDevIdentity.value = undefined;
  canonicalAllowedPaths.clear();
  suspendAuthorizedDevNavigation();
  clearHotOrderSnapshots();
  const tabbar = useTabbarStore();
  const stale = tabbar.tabs
    .filter((tab) => tab.path.startsWith('/erp/'))
    .map((tab) => String(tab.key ?? tab.path));
  if (stale.length) void tabbar._bulkCloseByKeys(stale);
  initialPage = undefined;
}