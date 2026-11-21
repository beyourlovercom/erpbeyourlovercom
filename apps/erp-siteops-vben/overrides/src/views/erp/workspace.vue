<script setup lang="ts">
import { computed, onActivated, onMounted, ref, watch } from 'vue';
import { getHotOrderSnapshot, putHotOrderSnapshot, expireHotOrderSnapshot,
  sleepOtherHotOrders, clearHotOrderSnapshots, getHotOrderCacheStats, type HotOrderKey } from '#/api/erp-hot-snapshots';
import { useRoute, useRouter } from 'vue-router';
import { activeDevRole, applyAuthorizedDevNavigation, clearAuthorizedDevNavigation,
  canonicalDevIdentity, devBootstrapError, takeInitialDevOrders, type DevRole } from '#/router/erp-live-navigation';
import { AllCommunityModule, ModuleRegistry, themeQuartz, type ColDef } from 'ag-grid-community';
import { AgGridVue } from 'ag-grid-vue3';
import { getExistingErpOrders, getExistingErpOrderDetail, getMacDevReadOnlyOrders, getCurrentDevReadOnlyOrders, type ErpOrderDetail, type ErpCurrentDevPage, type ErpOrderPage, type ErpDevMenuNode } from '#/api/erp-orders';

defineOptions({ name: 'ERPWorkspacePreview' });
ModuleRegistry.registerModules([AllCommunityModule]);
interface Row {
  id: number; kind: string; order_no: string; type: string; warehouse: string;
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
// Each dedicated ERP2 order page binds to exactly one original Laravel read scope.
const pageScope = computed<'all' | 'customers'>(() => isCustomerOrders.value ? 'customers' : 'all');
const devNavigation = ref<ErpDevMenuNode[]>([]);
const authorizedWarehouses = computed(() =>
  devNavigation.value.find((g) => g.key === 'inventory')?.children
    ?.filter((n) => n.key.startsWith('inventory-warehouse-')) ?? [],
);
const title = computed(() => String(route.meta.title ?? 'ERP 工作台'));
const originalPath = computed(() => String(route.meta.erpOriginalPath ?? ''));
const sourceStatus = computed(() => String(route.meta.erpSourceStatus ?? ''));
const isOrdersMaster = computed(() => route.meta.erpMenuKey === 'orders-master');
const isCustomerOrders = computed(() => ['orders-customers', 'customers-orders'].includes(String(route.meta.erpMenuKey)));
const isKolOrders = computed(() => ['orders-kol', 'kol-orders'].includes(String(route.meta.erpMenuKey)));
const isCollaborationOrders = computed(() => route.meta.erpMenuKey === 'orders-collaboration');
const isProcurementOrders = computed(() => route.meta.erpMenuKey === 'orders-procurement');
const isArchiveOrders = computed(() => route.meta.erpMenuKey === 'orders-archive');
const isOrders = computed(() => isOrdersMaster.value || isCustomerOrders.value || isKolOrders.value || isCollaborationOrders.value || isProcurementOrders.value || isArchiveOrders.value);
const sampleRows: Row[] = [
  { id: 0, kind: 'customer', order_no: 'UAT-ORDER-001', type: '客户订单', warehouse: '中国工厂', sku: 'DEMO-SKU-A', status: '待分仓', tracking: '—', updated_at: '合成记录' },
  { id: 0, kind: 'customer', order_no: 'UAT-ORDER-002', type: '客户订单', warehouse: 'us-ny', sku: 'DEMO-SKU-B', status: '待审核', tracking: '—', updated_at: '合成记录' },
  { id: 0, kind: 'kol', order_no: 'UAT-ORDER-003', type: '网红订单', warehouse: 'us-moreplus', sku: 'DEMO-GIFT', status: '待发货', tracking: '—', updated_at: '合成记录' },
  { id: 0, kind: 'customer', order_no: 'UAT-ORDER-004', type: '客户订单', warehouse: 'de-tianyun', sku: 'DEMO-SKU-C', status: '异常订单', tracking: '—', updated_at: '合成记录' },
];
const rows = ref<Row[]>(currentDevEnabled || canonicalIdentityMode ? [] : [...sampleRows]);
const orderDetail = ref<ErpOrderDetail | null>(null);
const detailError = ref('');
const detailLoading = ref(false);
async function openOrderDetail(row: Row) {
  if (!canonicalIdentityMode || row.id < 1) return;
  detailLoading.value = true; detailError.value = ''; orderDetail.value = null;
  try {
    const kind = isKolOrders.value || /kol|网红/i.test(row.kind) ? 'kol' : 'customer';
    orderDetail.value = await getExistingErpOrderDetail(row.id, kind);
  } catch (reason) { detailError.value = reason instanceof Error ? reason.message : '订单详情读取失败'; }
  finally { detailLoading.value = false; }
}
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
    id:row.id,kind:row.order_kind,order_no:row.order_number,type:row.order_kind,warehouse:row.warehouse||'—',
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
  if (currentDevEnabled && !isOrdersMaster.value) devOrderScope.value = pageScope.value;
  // The dedicated KOL page must never reuse a customer/Owner role's all-orders response.
  const roleMatchesPage = isKolOrders.value
    ? roleForRequest === 'kol' || roleForRequest === 'manager'
    : !isCustomerOrders.value || roleForRequest === 'owner' || roleForRequest === 'staff';
  if (currentDevEnabled && !roleMatchesPage) {
    rows.value = [];
    pager.value = {page:1,perPage:50,total:0,lastPage:1};
    error.value = '此页面需要相应的隔离 Dev 测试身份；已阻止跨业务范围读取。';
    fetching.value = false;
    return;
  }
  const preloaded = currentDevEnabled ? takeInitialDevOrders({
    role:roleForRequest, search:search.value, page, perPage, scope:devOrderScope.value,
  }) : undefined;
  const hotKey = devHotKey(page,perPage,roleForRequest);
  const stale = currentDevEnabled && !preloaded ? getHotOrderSnapshot(hotKey) : undefined;
  fetching.value = true;
  hotRefreshing.value = !!stale;
  error.value = '';
  if (canonicalIdentityMode) {
    // No stale business rows may remain visible while a fresh Laravel
    // authorization/search/pagination request is in flight.
    rows.value = [];
    pager.value = {page:1,perPage,total:0,lastPage:1};
  }
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
        : await getExistingErpOrders({ search:search.value, page, perPage,
            scope: isKolOrders.value ? 'kol' : isCustomerOrders.value ? 'customers' : isCollaborationOrders.value ? 'collaboration' : isProcurementOrders.value ? 'procurement' : isArchiveOrders.value ? 'archive' : 'all' });
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
  if (currentDevEnabled) {
    devOrderScope.value = pageScope.value;
    // Local fixture identities are explicitly scoped by business page.
    if (isKolOrders.value && selectedRole.value !== 'kol' && selectedRole.value !== 'manager') {
      selectedRole.value = 'kol';
      clearHotOrderSnapshots();
    } else if (isCustomerOrders.value && selectedRole.value !== 'owner' && selectedRole.value !== 'staff') {
      selectedRole.value = 'owner';
      clearHotOrderSnapshots();
    }
  }
  initialOrdersLoaded = true;
  void loadExisting(1, isCustomerOrders.value ? 20 : 50);
}
onMounted(initCurrentDevOrders);
watch(() => route.meta.erpMenuKey, () => {
  // Workspace can be reused across routes: invalidate every previous request and row.
  ++requestSequence;
  initialOrdersLoaded = false;
  fetching.value = false;
  hotRefreshing.value = false;
  rows.value = [];
  search.value = '';
  error.value = '';
  pager.value = {page:1,perPage:50,total:0,lastPage:1};
  if (isOrders.value) initCurrentDevOrders();
});
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
    <section v-if="detailLoading || detailError || orderDetail" role="dialog" aria-label="订单详情"
      class="rounded border border-indigo-200 bg-white p-4 text-sm shadow-sm">
      <div class="mb-3 flex items-center justify-between">
        <strong>订单详情（Laravel 授权只读）</strong>
        <button type="button" class="rounded border px-2 py-1" @click="orderDetail = null; detailError = ''; detailLoading = false">关闭</button>
      </div>
      <p v-if="detailLoading">正在读取订单详情…</p>
      <p v-if="detailError" role="alert" class="text-red-700">{{ detailError }}</p>
      <template v-if="orderDetail">
        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
          <div>订单号：{{ orderDetail.order_number }}</div>
          <div>类型：{{ orderDetail.order_kind }}</div>
          <div>状态：{{ orderDetail.status }}</div>
          <div>日期：{{ orderDetail.order_date }}</div>
          <div>负责人：{{ orderDetail.owner }}</div>
          <div>发货仓：{{ orderDetail.shipment.warehouse_name || '—' }}</div>
          <div>承运商：{{ orderDetail.shipment.carrier || '—' }}</div>
          <div>物流单号：{{ orderDetail.shipment.tracking_number || '—' }}</div>
        </div>
        <h3 class="mt-3 font-semibold">商品明细</h3>
        <div v-for="(item, index) in orderDetail.items" :key="index" class="border-b py-1">
          {{ item.sku }} × {{ item.quantity }} · {{ item.product_summary }}
        </div>
        <div v-if="orderDetail.fulfillment" class="mt-3">
          <h3 class="font-semibold">拆单／补发履约明细 · {{ orderDetail.fulfillment.aggregate_status_label }}</h3>
          <div v-for="segment in orderDetail.fulfillment.segments" :key="segment.order_id"
            class="mt-2 rounded border border-slate-200 p-2">
            <div class="font-medium">{{ segment.erp_order_id || segment.suffix }} · {{ segment.role_label }} · {{ segment.reason_label }}</div>
            <div class="text-xs text-slate-600">
              {{ segment.shipment.warehouse_name || '未分仓' }} ·
              {{ segment.shipment.status_label }} ·
              {{ segment.shipment.carrier || '—' }} / {{ segment.shipment.service || '—' }} ·
              {{ segment.shipment.tracking_number || '无物流单号' }}
            </div>
            <div v-for="item in segment.items" :key="item.sku" class="text-xs">{{ item.sku }} × {{ item.quantity }}</div>
          </div>
        </div>
      </template>
    </section>
    <template v-if="isOrders">
      <p v-if="currentDevEnabled && !isOrdersMaster"
        class="rounded border border-indigo-100 bg-indigo-50 px-3 py-2 text-xs text-indigo-700">
        {{ isCustomerOrders
          ? '客户订单：仅通过隔离 Laravel /customers/orders 原控制器读取；客户记录独立分页。'
          : '网红订单：仅通过网红专员或经理的隔离 Laravel 授权身份读取 /orders；严禁使用 Owner 全订单结果冒充网红订单。' }}
      </p>
      <div class="flex flex-wrap items-center gap-2">
        <input v-model="search" type="search" placeholder="搜索订单号、仓库或 SKU"
          class="w-64 max-w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm"
          @keydown.enter="mode === 'authenticated-erp' && loadExisting(1)" />
        <button v-if="mode === 'authenticated-erp'" type="button" class="rounded border px-2 py-1 text-xs"
          :disabled="fetching" @click="loadExisting(1)">查询全部授权记录</button>
        <button v-if="!currentDevEnabled && !canonicalIdentityMode" type="button" class="rounded border px-2 py-1 text-xs" @click="showSynthetic">合成演示数据</button>
        <template v-if="currentDevEnabled">
          <label class="text-xs text-slate-600" for="erp-dev-role">Laravel Dev 测试角色</label>
          <select id="erp-dev-role" v-model="selectedRole" class="rounded border px-2 py-1 text-xs"
            aria-label="Laravel Dev 测试角色" @change="onDevRoleChange">
            <option v-if="!isKolOrders" value="owner">Owner</option>
            <option v-if="!isKolOrders" value="staff">运营（普通员工）</option>
            <option v-if="!isCustomerOrders" value="kol">KOL 专员</option>
            <option v-if="!isCustomerOrders" value="manager">KOL 经理</option>
          </select>
          <select v-if="isOrdersMaster" v-model="devOrderScope" aria-label="订单读取范围" class="rounded border px-2 py-1 text-xs"
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
          @row-clicked="(event: { data?: Row }) => event.data && openOrderDetail(event.data)"
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