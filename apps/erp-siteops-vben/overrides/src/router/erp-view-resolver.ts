/** Bind the three Owner-approved logistics menu entries to dedicated pages. */
export const erpViewFor = (key: string) => {
  switch (key) {
    case 'logistics-wait-stock': return () => import('#/views/erp/logistics-allocation.vue');
    case 'logistics-china': return () => import('#/views/erp/logistics-domestic.vue');
    case 'logistics-oversea': return () => import('#/views/erp/logistics-overseas.vue');
    default: return () => import('#/views/erp/workspace.vue');
  }
};
