<script setup lang="ts">
import { computed, onActivated, onMounted, ref } from 'vue';
import { getHotOrderSnapshot, putHotOrderSnapshot, expireHotOrderSnapshot,
  sleepOtherHotOrders, clearHotOrderSnapshots, getHotOrderCacheStats, type HotOrderKey } from '#/api/erp-hot-snapshots';
import { useRoute, useRouter } from 'vue-router';
import { activeDevRole, applyAuthorizedDevNavigation, clearAuthorizedDevNavigation,
  canonicalDevIdentity, devBootstrapError, takeInitialDevOrders, type DevRole } from '#/router/erp-live-navigation';
import { AllCommunityModule, ModuleRegistry, themeQuartz, type ColDef } from 'ag-grid-community';
import { AgGridVue } from 'ag-grid-vue3';
import { getExistingErpOrders, getMacDevReadOnlyOrders, getCurrentDevReadOnlyOrders, type ErpCurrentDevPage, type ErpOrderPage, type ErpDevMenuNode } from '#/api/erp-orders';

defineOptions({ name: 'ERPWorkspacePreview' });
ModuleRegistry.registerModules([AllCommunityModule]);
interface Row {
  order_no: string; type: string; warehouse: string;
  sku: string; status: string; tracking: string; updated_at: string;
}
const route = useRoute();
const router = useRouter();
const search = ref('');
const error = ref('');
const fetching = ref(false);
const canonicalIdentityMode = import.meta.env.VITE_ERP_IDENTITY_MODE === 'laravel';
const mode = ref<'synthetic' | 'authenticated-erp'>(
  canonicalIdentityMode || import.meta.env.VITE_ERP_CURRENT_DEV_ENABLED === '1' ? 'authenticated-erp' : 'synthetic',
);
const pager = ref<Pick<ErpOrderPage, 'page' | 'perPage' | 'total' | 'lastPage'>>({
  page: 1, perPage: 50, total: 0, lastPage: 1,
});
const currentDevEnabled = !canonicalIdentityMode && import.meta.env.VITE_ERP_CURRENT_DEV_ENABLED === '1';
const bridgeEnabled = !currentDevEnabled && import.meta.env.VITE_ERP_DEV_BRIDGE_ENABLED === '1';
const enabled = canonicalIdentityMode || currentDevEnabled || bridgeEnabled || import.meta.env.VITE_ERP_READONLY_ORDERS_ENABLED === '1';
const selectedRole = activeDevRole;
const devOrderScope = ref<'all' | 'customers'>('all');
const devNavigation = ref<ErpDevMenuNode[]>([]);
const authorizedWarehouses = computed(() =>
  devNavigation.value.find((g) => g.key === 'inventory')?.children
    ?.filter((n) => n.key.startsWith('inventory-warehouse-')) ?? [],
);
const title = computed(() => String(route.meta.title ?? 'ERP 工作台'));
const originalPath = computed(() => String(route.meta.erpOriginalPath ?? ''));
const sourceStatus = computed(() => String(route.meta.erpSourceStatus ?? ''));
const isOrders = computed(() => route.meta.erpMenuKey === 'orders-master');
const sampleRows: Row[] = [
  { order_no: 'UAT-ORDER-001', type: '客户订单', warehouse: '中国工厂', sku: 'DEMO-SKU-A', status: '待分仓', tracking: '—', updated_at: '合成记录' },
  { order_no: 'UAT-ORDER-002', type: '客户订单', warehouse: 'us-ny', sku: 'DEMO-SKU-B', status: '待审核', tracking: '—', updated_at: '合成记录' },
  { order_no: 'UAT-ORDER-003', type: '网红订单', warehouse: 'us-moreplus', sku: 'DEMO-GIFT', status: '待发货', tracking: '—', updated_at: '合成记录' },
  { order_no: 'UAT-ORDER-004', type: '客户订单', warehouse: 'de-tianyun', sku: 'DEMO-SKU-C', status: '异常订单', tracking: '—', updated_at: '合成记录' },
];
const rows = ref<Row[]>(currentDevEnabled || canonicalIdentityMode ? [] : [...sampleRows]);
let requestSequence = 0;
let initialOrdersLoaded = false;
let lastFetchStartedAt = 0;
const hotRefreshing = ref(false);
const hotEntryCount = ref(0);
const devHotKey = (page:number, perPage:number, role:DevRole = selectedRole.value): HotOrderKey => ({
  role, scope:devOrderScope.value, page, perPage, search:search.value,
});
function sleepOtherDevPages() {
  sleepOtherHotOrders(devHotKey(pager.value.page,pager.value.perPage));
  hotEntryCount.value = getHotOrderCacheStats().entries;
}
function toGridRows(data: ErpOrderPage): Row[] {
  return data.rows.map(row=>({
    order_no:row.order_number,type:row.order_kind,warehouse:row.warehouse||'—',
    sku:row.sku||'—',status:row.status||'—',
    tracking:row.tracking_number||'—',updated_at:row.order_date||'—',
  }));
}
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
  if (!enabled) { error.value = '未启用 ERP Dev 只读连接。'; return; }
  const ticket = ++requestSequence;
  lastFetchStartedAt = Date.now();
  const roleForRequest: DevRole = selectedRole.value;
  const preloaded = currentDevEnabled ? takeInitialDevOrders({
    role:roleForRequest, search:search.value, page, perPage, scope:devOrderScope.value,
  }) : undefined;
  const hotKey = devHotKey(page,perPage,roleForRequest);
  const stale = currentDevEnabled && !preloaded ? getHotOrderSnapshot(hotKey) : undefined;
  fetching.value = true;
  hotRefreshing.value = !!stale;
  error.value = '';
  if (currentDevEnabled) {
    rows.value = stale ? toGridRows(stale) : [];
    pager.value = stale
      ? {page:stale.page,perPage:stale.perPage,total:stale.total,lastPage:stale.lastPage}
      : {page:1,perPage:50,total:0,lastPage:1};
    devNavigation.value = [];
    clearAuthorizedDevNavigation();
  }
  try {
    const data = currentDevEnabled
      ? preloaded ?? await getCurrentDevReadOnlyOrders({ search:search.value, page, perPage,
          role:roleForRequest, scope:devOrderScope.value })
      : bridgeEnabled
        ? await getMacDevReadOnlyOrders({ search:search.value, page, perPage })
        : await getExistingErpOrders({ search:search.value, page, perPage });
    if (ticket !== requestSequence || roleForRequest !== selectedRole.value) return;
    if ('role' in data && data.role !== roleForRequest) throw new Error('DEV_ROLE_RESPONSE_MISMATCH');
    if ('navigation' in data && Array.isArray(data.navigation)) {
      devNavigation.value = data.navigation as ErpDevMenuNode[];
      if (currentDevEnabled) applyAuthorizedDevNavigation(devNavigation.value, router);
    }
    // Never merge, cache, or fallback to synthetic rows after a real API failure.
    rows.value = toGridRows(data);
    if (currentDevEnabled && 'navigation' in data && 'role' in data) {
      putHotOrderSnapshot(hotKey,data as ErpCurrentDevPage);
      hotEntryCount.value = getHotOrderCacheStats().entries;
    }
    pager.value = { page:data.page, perPage:data.perPage, total:data.total, lastPage:data.lastPage };
    mode.value = 'authenticated-erp';
  } catch (reason) {
    if (ticket !== requestSequence || roleForRequest !== selectedRole.value) return;
    rows.value = [];
    pager.value = {page:1, perPage:50, total:0, lastPage:1};
    devNavigation.value = [];
    if (currentDevEnabled) {
      clearAuthorizedDevNavigation();
      expireHotOrderSnapshot(hotKey);
      hotEntryCount.value = getHotOrderCacheStats().entries;
    }
    mode.value = 'authenticated-erp';
    error.value = reason instanceof Error ? reason.message : '只读订单查询失败';
  } finally { if (ticket === requestSequence) { fetching.value = false; hotRefreshing.value = false; } }
}
function onDevRoleChange() {
  // Switching roles invalidates every preview from the previous authorized fixture session.
  clearHotOrderSnapshots();
  hotEntryCount.value = 0;
  void loadExisting(1);
}
function showSynthetic() {
  if (currentDevEnabled || canonicalIdentityMode) return;
  mode.value = 'synthetic'; rows.value = [...sampleRows]; search.value = '';
  error.value = ''; pager.value = { page:1, perPage:50, total:0, lastPage:1 };
}
function initCurrentDevOrders() {
  if ((!currentDevEnabled && !canonicalIdentityMode) || !isOrders.value || initialOrdersLoaded) return;
  initialOrdersLoaded = true;
  void loadExisting(1, 50);
}
onMounted(initCurrentDevOrders);
onActivated(() => {
  if ((!currentDevEnabled && !canonicalIdentityMode) || !isOrders.value) return;
  if (!initialOrdersLoaded) { initCurrentDevOrders(); return; }
  // Returning to an inactive page always revalidates server-side. The cached
  // response can render immediately but does not permit interaction.
  if (Date.now() - lastFetchStartedAt > 750) void loadExisting(pager.value.page,pager.value.perPage);
});
</script>

<template>
  <div class="flex h-full min-h-0 flex-col gap-3 p-3">
    <header class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <h1 class="text-lg font-semibold text-slate-800">{{ title }}</h1>
        <p class="text-xs text-slate-500">BYL ERP · SiteOps / Vben 独立开发环境
          <span v-if="canonicalIdentityMode && canonicalDevIdentity">
            · Laravel 员工 {{ canonicalDevIdentity.user.name }} (#{{ canonicalDevIdentity.user.id }})
          </span>
        </p>
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
        <button v-if="!currentDevEnabled" type="button" class="rounded border px-2 py-1 text-xs" @click="showSynthetic">合成演示数据</button>
        <template v-if="currentDevEnabled">
          <label class="text-xs text-slate-600" for="erp-dev-role">Laravel Dev 测试角色</label>
          <select id="erp-dev-role" v-model="selectedRole" class="rounded border px-2 py-1 text-xs"
            aria-label="Laravel Dev 测试角色" @change="onDevRoleChange">
            <option value="owner">Owner</option>
            <option value="staff">运营（普通员工）</option>
            <option value="kol">KOL 专员</option>
            <option value="manager">KOL 经理</option>
          </select>
          <select v-model="devOrderScope" aria-label="订单读取范围" class="rounded border px-2 py-1 text-xs"
            @change="loadExisting(1)">
            <option value="all">订单总表 /orders</option>
            <option value="customers">客户订单 /customers/orders</option>
          </select>
        </template>
        <button v-if="enabled" type="button" class="rounded border px-2 py-1 text-xs"
          :disabled="fetching" @click="loadExisting(1)">{{ currentDevEnabled ? '读取当前 Laravel Dev（按角色授权）' : bridgeEnabled ? '读取 Mac mini 的旧 Dev 订单' : '读取 ERP Dev /orders（只读）' }}</button>
        <span class="text-xs text-slate-500">
          {{ mode === 'synthetic' ? '合成演示，非真实订单' : currentDevEnabled ? 'Laravel Dev b07885e3 · 隔离 UAT 数据（非 Production）' : bridgeEnabled ? '旧 Mac Dev SQLite' : 'Laravel Dev 已授权订单' }} · {{ mode === 'synthetic' ? rows.length : pager.total }} 条
        </span>
      </div>
      <p v-if="error" role="alert" class="rounded bg-amber-50 p-2 text-sm text-amber-700">{{ error }}</p>
      <div class="relative min-h-[420px] flex-1 overflow-hidden rounded border border-slate-200"
        :class="fetching ? 'pointer-events-none opacity-60' : ''" :aria-busy="fetching">
        <AgGridVue class="h-full w-full" :theme="gridTheme" :row-data="rows"
          :column-defs="columns" :quick-filter-text="mode === 'synthetic' ? search : undefined"
          :pagination="mode === 'synthetic'" :pagination-page-size="20"
          :default-col-def="{ sortable: mode === 'synthetic', resizable: true }" />
      </div>
      <div v-if="mode === 'authenticated-erp'" class="flex flex-wrap items-center gap-3 text-xs">
        <button :disabled="pager.page <= 1 || fetching" @click="loadExisting(pager.page - 1)">上一页</button>
        <span>第 {{ pager.page }} / {{ pager.lastPage }} 页 · 总计 {{ pager.total }} 条</span>
        <button :disabled="pager.page >= pager.lastPage || fetching" @click="loadExisting(pager.page + 1)">下一页</button>
        <select v-if="!currentDevEnabled || devOrderScope === 'customers'"
          :value="pager.perPage" @change="loadExisting(1, Number(($event.target as HTMLSelectElement).value))">
          <option :value="20">20 / 页</option><option :value="50">50 / 页</option><option :value="100">100 / 页</option>
        </select>
      </div>
      <p class="text-xs text-slate-400">
        {{ canonicalIdentityMode ? 'ERP2 P0：真实员工 Laravel Session + 原 ERP 服务端权限；/orders 仅 GET，禁止假角色与业务写入。' : currentDevEnabled ? 'R2-B：通过原 Laravel Dev 登录 Session 和订单控制器授权；这里是隔离 UAT 角色，不是正式员工登录。全订单 /orders 未开启 OMS Grid Canary 时后端固定 50 条/页。业务写入关闭。' : bridgeEnabled ? '旧 R2 Dev 通道仅用于回归；生产写入关闭。' : '真实连接调用 Laravel 原有授权 GET /orders；业务写入关闭。' }}
      </p>
      <div v-if="currentDevEnabled" class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
        <span v-if="hotRefreshing" role="status" class="font-semibold text-amber-700">热快照待服务器校验 · 表格暂不可操作</span>
        <span>只读热快照 {{ hotEntryCount }} / 3（60 秒内存缓存）</span>
        <button type="button" class="rounded border px-2 py-1"
          :disabled="fetching" @click="sleepOtherDevPages">休眠其他页缓存</button>
      </div>
      <p v-if="currentDevEnabled && mode === 'authenticated-erp'" class="text-xs text-slate-500">
        Laravel 实际授权菜单 {{ devNavigation.length }} 个一级分类 ·
        实际启用的仓库快捷入口 {{ authorizedWarehouses.length }} 个
      </p>
    </template>
    <div v-else class="flex min-h-[360px] flex-1 items-center justify-center rounded border border-dashed border-slate-300 bg-white">
      <div class="max-w-md p-6 text-center">
        <h2 class="text-base font-medium text-slate-700">{{ title }} · 导航已恢复</h2>
        <p v-if="currentDevEnabled && devBootstrapError" role="alert" class="mt-2 text-sm text-amber-700">
          当前 Laravel Dev 授权菜单加载失败：{{ devBootstrapError }}
        </p>
        <button v-if="currentDevEnabled && devBootstrapError" class="mt-2 rounded border px-3 py-1 text-sm"
          type="button" :disabled="fetching" @click="loadExisting(1)">重新加载 Dev 授权菜单</button>
        <p class="mt-2 text-sm text-slate-500">原 ERP 路径：<code>{{ originalPath }}</code></p>
        <p class="mt-2 text-sm text-slate-500">
          {{ sourceStatus === 'planned' ? '原 ERP 标记为规划中。' : '原 ERP 已有业务入口。' }}
          本 Vben 页面尚未迁移该业务模块；不得将当前页面当作可操作的正式页面。
        </p>
      </div>
    </div>
  </div>
</template>