import type { RouteRecordRaw } from 'vue-router';

const page = () => import('#/views/erp/workspace.vue');
const routes: RouteRecordRaw[] = [
  {
    name: 'ERP',
    path: '/erp',
    meta: { icon: 'lucide:boxes', order: -100, title: 'BYL ERP', keepAlive: true },
    children: [
      { name: 'ERPOverview', path: 'overview', component: page, meta: { title: '运营总览', icon: 'lucide:layout-dashboard', affixTab: true } },
      { name: 'ERPOrders', path: 'orders', component: page, meta: { title: '订单管理', icon: 'lucide:shopping-cart', keepAlive: true } },
      { name: 'ERPLogistics', path: 'logistics', component: page, meta: { title: '物流履约', icon: 'lucide:truck', keepAlive: true } },
      { name: 'ERPInventory', path: 'inventory', component: page, meta: { title: '库存仓库', icon: 'lucide:warehouse', keepAlive: true } },
      { name: 'ERPProducts', path: 'products', component: page, meta: { title: '产品与 SKU', icon: 'lucide:package', keepAlive: true } },
      { name: 'ERPKol', path: 'kol', component: page, meta: { title: '网红管理', icon: 'lucide:users', keepAlive: true } },
      { name: 'ERPFinance', path: 'finance', component: page, meta: { title: '财务核算', icon: 'lucide:calculator', keepAlive: true } },
      { name: 'ERPSync', path: 'sync', component: page, meta: { title: '同步中心', icon: 'lucide:refresh-cw', keepAlive: true } },
      { name: 'ERPSettings', path: 'settings', component: page, meta: { title: '员工与设置', icon: 'lucide:settings', keepAlive: true } },
    ],
  },
];
export default routes;