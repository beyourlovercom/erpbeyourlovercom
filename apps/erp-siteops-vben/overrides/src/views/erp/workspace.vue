<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { AllCommunityModule, ModuleRegistry, themeQuartz, type ColDef } from 'ag-grid-community';
import { AgGridVue } from 'ag-grid-vue3';
import { getExistingErpOrders, type ErpOrderPage } from '#/api/erp-orders';

defineOptions({ name: 'ERPWorkspacePreview' });
ModuleRegistry.registerModules([AllCommunityModule]);
interface Row {
  order_no: string; type: string; warehouse: string;
  sku: string; status: string; tracking: string; updated_at: string;
}
const route = useRoute();
const search = ref('');
const error = ref('');
const fetching = ref(false);
const mode = ref<'synthetic' | 'authenticated-erp'>('synthetic');
const pager = ref<Pick<ErpOrderPage, 'page' | 'perPage' | 'total' | 'lastPage'>>({
  page: 1, perPage: 50, total: 0, lastPage: 1,
});
const enabled = import.meta.env.VITE_ERP_READONLY_ORDERS_ENABLED === '1';
const section = computed(() => String(route.path.split('/').at(-1) ?? 'overview'));
const titles: Record<string, string> = {
  overview: 'ERP 运营总览', orders: '全部订单',
  logistics: '物流履约', inventory: '库存仓库', products: '产品与 SKU',
  kol: '网红管理', finance: '财务核算', sync: '同步中心',
  settings: '员工与设置',
};
const title = computed(() => titles[section.value] || 'ERP 工作台');
const isOrders = computed(() => section.value === 'orders');
const sampleRows: Row[] = [
  { order_no: 'UAT-ORDER-001', type: '客户订单', warehouse: '中国工厂', sku: 'DEMO-SKU-A', status: '待分仓', tracking: '—', updated_at: '合成记录' },
  { order_no: 'UAT-ORDER-002', type: '客户订单', warehouse: 'us-ny', sku: 'DEMO-SKU-B', status: '待审核', tracking: '—', updated_at: '合成记录' },
  { order_no: 'UAT-ORDER-003', type: '网红订单', warehouse: 'us-moreplus', sku: 'DEMO-GIFT', status: '待发货', tracking: '—', updated_at: '合成记录' },
  { order_no: 'UAT-ORDER-004', type: '客户订单', warehouse: 'de-tianyun', sku: 'DEMO-SKU-C', status: '异常订单', tracking: '—', updated_at: '合成记录' },
];
const rows = ref<Row[]>([...sampleRows]);
const columns: ColDef<Row>[] = [
  { field: 'order_no', headerName: '订单号', pinned: 'left', width: 170, filter: true },
  { field: 'type', headerName: '订单类型', width: 130, filter: true },
  { field: 'warehouse', headerName: '仓库', width: 135, filter: true },
  { field: 'sku', headerName: 'SKU', width: 170, filter: true },
  { field: 'status', headerName: '核心状态', width: 130, filter: true },
  { field: 'tracking', headerName: '物流单号', minWidth: 170, flex: 1 },
  { field: 'updated_at', headerName: '订单日期', width: 145 },
];
const gridTheme = themeQuartz.withParams({
  fontSize: 12, spacing: 4, backgroundColor: '#fff',
  headerBackgroundColor: '#f8fafc', borderColor: '#e2e8f0', accentColor: '#4f46e5',
});
async function loadExisting(page = 1, perPage = pager.value.perPage) {
  if (!enabled) { error.value = '未启用 ERP 同源只读连接；需要先验证本地 Laravel 登录和权限。'; return; }
  fetching.value = true;
  error.value = '';
  try {
    const data = await getExistingErpOrders({ search:search.value, page, perPage });
    // Never merge, cache, or fallback to synthetic rows after a real API failure.
    rows.value = data.rows.map(row => ({
      order_no: row.order_number, type: row.order_kind, warehouse: row.warehouse || '—',
      sku: row.sku || '—', status: row.status || '—',
      tracking: row.tracking_number || '—', updated_at: row.order_date || '—',
    }));
    pager.value = { page:data.page, perPage:data.perPage, total:data.total, lastPage:data.lastPage };
    mode.value = 'authenticated-erp';
  } catch (reason) {
    error.value = reason instanceof Error ? reason.message : '只读订单查询失败';
  } finally { fetching.value = false; }
}
function showSynthetic() {
  mode.value = 'synthetic'; rows.value = [...sampleRows]; search.value = '';
  error.value = ''; pager.value = { page:1, perPage:50, total:0, lastPage:1 };
}
</script>

<template>
  <div class="flex h-full min-h-0 flex-col gap-3 p-3">
    <header class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <h1 class="text-lg font-semibold text-slate-800">{{ title }}</h1>
        <p class="text-xs text-slate-500">BYL ERP · SiteOps / Vben 独立开发环境</p>
      </div>
      <span class="rounded border border-amber-200 bg-amber-50 px-2 py-1 text-xs text-amber-800">
        007 本地 UAT · 所有业务写入关闭
      </span>
    </header>
    <template v-if="isOrders">
      <div class="flex flex-wrap items-center gap-2">
        <input v-model="search" type="search" placeholder="搜索订单号、仓库或 SKU"
          class="w-64 max-w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm"
          @keydown.enter="mode === 'authenticated-erp' && loadExisting(1)" />
        <button v-if="mode === 'authenticated-erp'" type="button" class="rounded border px-2 py-1 text-xs"
          :disabled="fetching" @click="loadExisting(1)">查询全部授权记录</button>
        <button type="button" class="rounded border px-2 py-1 text-xs" @click="showSynthetic">合成演示数据</button>
        <button v-if="enabled" type="button" class="rounded border px-2 py-1 text-xs"
          :disabled="fetching" @click="loadExisting(1)">连接现有 ERP /orders GET（只读）</button>
        <span class="text-xs text-slate-500">
          {{ mode === 'synthetic' ? '合成演示，非真实订单' : 'Laravel 原权限订单' }} · {{ mode === 'synthetic' ? rows.length : pager.total }} 条
        </span>
      </div>
      <p v-if="error" role="alert" class="rounded bg-amber-50 p-2 text-sm text-amber-700">{{ error }}</p>
      <div class="min-h-[420px] flex-1 overflow-hidden rounded border border-slate-200">
        <AgGridVue class="h-full w-full" :theme="gridTheme" :row-data="rows"
          :column-defs="columns" :quick-filter-text="mode === 'synthetic' ? search : undefined"
          :pagination="mode === 'synthetic'" :pagination-page-size="20"
          :default-col-def="{ sortable: mode === 'synthetic', resizable: true }" />
      </div>
      <div v-if="mode === 'authenticated-erp'" class="flex flex-wrap items-center gap-3 text-xs">
        <button :disabled="pager.page <= 1 || fetching" @click="loadExisting(pager.page - 1)">上一页</button>
        <span>第 {{ pager.page }} / {{ pager.lastPage }} 页 · 总计 {{ pager.total }} 条</span>
        <button :disabled="pager.page >= pager.lastPage || fetching" @click="loadExisting(pager.page + 1)">下一页</button>
        <select :value="pager.perPage" @change="loadExisting(1, Number(($event.target as HTMLSelectElement).value))">
          <option :value="20">20 / 页</option><option :value="50">50 / 页</option><option :value="100">100 / 页</option>
        </select>
      </div>
      <p class="text-xs text-slate-400">
        真实连接只调用原 Laravel /orders 的同源授权 GET；归档、审核、分仓、发货和 Woo 同步操作均未开放。
      </p>
    </template>
    <div v-else class="flex min-h-[360px] flex-1 items-center justify-center rounded border border-dashed border-slate-300 bg-white">
      <div class="max-w-md p-6 text-center">
        <h2 class="text-base font-medium text-slate-700">{{ title }} · 页面容器就绪</h2>
        <p class="mt-2 text-sm text-slate-500">业务页面按原 ERP 路由逐模块映射；正式写入始终由 ERP 后端负责。</p>
      </div>
    </div>
  </div>
</template>