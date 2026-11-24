/** Bind the three Owner-approved logistics menu entries to dedicated pages. */
export const erpViewFor = (key: string) => {
  switch (key) {
    case 'logistics-wait-stock': return () => import('#/views/erp/logistics-allocation.vue');
    case 'logistics-china': return () => import('#/views/erp/logistics-domestic.vue');
    case 'logistics-oversea': return () => import('#/views/erp/logistics-overseas.vue');
    case 'logistics-awaiting-tracking': return () => import('#/views/erp/logistics-awaiting.vue');
    case 'logistics-in-transit': return () => import('#/views/erp/logistics-transit.vue');
    case 'logistics-exceptions': return () => import('#/views/erp/logistics-exceptions.vue');
    default: return () => import('#/views/erp/workspace.vue');
  }
};
