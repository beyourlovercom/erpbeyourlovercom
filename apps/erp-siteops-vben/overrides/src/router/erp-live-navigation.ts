import type { Router } from 'vue-router';
import type { MenuRecordRaw } from '@vben/types';

import { useAccessStore } from '@vben/stores';

import type { ErpDevMenuNode } from '#/api/erp-orders';

let originalOwnerMenu: MenuRecordRaw[] | undefined;

// This changes only the local UAT sidebar. Server permissions and order row
// filtering remain the authority; client menu visibility is NOT authorization.
export function applyAuthorizedDevNavigation(navigation: ErpDevMenuNode[], router: Router) {
  const store = useAccessStore();
  if (!originalOwnerMenu) originalOwnerMenu = [...store.accessMenus];
  const menus: MenuRecordRaw[] = [];
  for (const [index, group] of navigation.entries()) {
    if (!/^[a-z0-9_-]+$/.test(group.key)) continue;
    const parentPath = group.key === 'dashboard' ? '/erp/overview' : '/erp/' + group.key;
    const original = originalOwnerMenu.find((item) => item.path === parentPath);
    if (!original) continue;
    const items = group.children ?? [];
    const children: MenuRecordRaw[] = [];
    if (group.key === 'orders') {
      children.push({ name:'订单总表', path:'/erp/orders/orders-master' });
    }
    for (const child of items) {
      if (!/^[a-z0-9_-]+$/.test(child.key) || !child.path.startsWith('/')) continue;
      const routeName = 'ERPMenu-' + child.key;
      const navPath = parentPath + '/' + child.key;
      if (!router.hasRoute(routeName)) {
        router.addRoute('ERPSection-' + group.key, {
          name: routeName, path: child.key,
          component: () => import('#/views/erp/workspace.vue'),
          meta: { title: child.label, keepAlive:true,
            erpMenuKey: child.key, erpModule: group.key,
            erpOriginalPath: child.path, erpSourceStatus:'implemented' },
        });
      }
      children.push({ name:child.label, path:navPath });
    }
    menus.push({
      name: group.label,
      path: parentPath,
      icon: original.icon,
      order: index,
      ...(children.length ? { children } : {}),
    });
  }
  store.setAccessMenus(menus);
}
export function clearAuthorizedDevNavigation() {
  // Never erase the original menu before the first authorized Dev response.
  if (!originalOwnerMenu) return;
  const store = useAccessStore();
  store.setAccessMenus(originalOwnerMenu);
}