import type { RouteRecordRaw } from 'vue-router';

import { erpMenuSource } from '../../erp-menu-source';

const workspace = () => import('#/views/erp/workspace.vue');
const icons: Record<string, string> = {
  dashboard: 'lucide:layout-dashboard',
  orders: 'lucide:shopping-cart',
  logistics: 'lucide:truck',
  sync: 'lucide:refresh-cw',
  customers: 'lucide:users',
  marketing: 'lucide:megaphone',
  kol: 'lucide:star',
  inventory: 'lucide:warehouse',
  procurement: 'lucide:shopping-bag',
  products: 'lucide:package',
  guides: 'lucide:book-open',
  accounting: 'lucide:calculator',
  settings: 'lucide:settings',
};

// Owner-only Dev shell: menu taxonomy mirrors latest ERP main, NOT staff RBAC.
// Per-staff navigation must be returned by Laravel ErpShellNavigation::for(user).
const routes: RouteRecordRaw[] = erpMenuSource.map((group, order) => {
  const path = group.key === 'dashboard' ? '/erp/overview' : `/erp/${group.key}`;
  const meta = {
    title: group.label,
    icon: icons[group.key] ?? 'lucide:folder',
    order: -100 + order,
    erpMenuKey: group.key,
    erpOriginalPath: group.originalPath,
  };
  if (!group.children?.length) {
    return { name: 'ERPOverview', path, component: workspace,
      meta: { ...meta, affixTab: true, keepAlive: true } };
  }
  const children = group.key === 'orders'
    ? [{ key: 'orders-master', label: '订单总表', originalPath: '/orders',
      permission: 'orders.view', status: 'implemented' }, ...group.children]
    : group.children;
  return {
    name: `ERPSection-${group.key}`,
    path,
    redirect: `${path}/${children[0]!.key}`,
    meta,
    children: children.map((child) => ({
      name: `ERPMenu-${child.key}`,
      path: child.key,
      component: workspace,
      meta: {
        title: child.label,
        keepAlive: true,
        erpMenuKey: child.key,
        erpModule: group.key,
        erpOriginalPath: child.originalPath,
        erpSourceStatus: child.status,
      },
    })),
  };
});
export default routes;