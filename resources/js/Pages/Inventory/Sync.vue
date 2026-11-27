<script setup lang="ts">
import { ref, computed, watch, defineAsyncComponent } from 'vue'
import type { ColDef } from 'ag-grid-community'
const GlobalGrid = defineAsyncComponent(() => import('../../Components/DataGrid/GlobalGrid.vue'))
import { Head } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import axios from 'axios'
import { publishGlobalFeedback } from '../../lib/globalFeedback'

interface Warehouse {
  id: number
  slug: string
  name: string
  country_code: string | null
}

interface SyncRow {
  id: number
  source_row_no: number
  sku: string | null
  stock_status: string | null
  putaway_date: string | null
  quantity: number | null
  product_id: number | null
  raw_product_name: string | null
  raw_warehouse_name: string | null
  raw_stock_status: string | null
  raw_dimension: string | null
  raw_total_volume: string | null
  validation_status: 'ok' | 'warning' | 'error'
  validation_message: string | null
}

interface AggregatedRow {
  product_id: number
  sku: string | null
  sellable_qty: number
  defective_qty: number
  lot_count: number
  inbound_at: string | null
  quantity_reserved: number
  quantity_available_after: number
  current_sellable_qty?: number
  source_present: boolean
  action: 'replace' | 'zero'
}

interface BatchResult {
  id: number
  warehouse_id: number
  warehouse_name: string
  source_type: string
  sync_mode: string
  original_filename: string | null
  stock_count_at: string | null
  status: 'preview' | 'confirmed' | 'abandoned'
  preview_total_rows: number
  preview_unique_skus: number
  preview_sellable_qty: number
  preview_defective_qty: number
  preview_unmatched_skus: number
  preview_duplicate_skus: number
  preview_warning_rows: number
  preview_zeroed_skus: number
  preview_error_rows: number
  preview_importable_rows: number
  preview_skipped_rows: number
  has_blockers: boolean
  confirmed_at: string | null
  aggregated_rows: AggregatedRow[]
  full_snapshot_zero_rows: AggregatedRow[]
  rows: SyncRow[] | null
}

interface RecentBatch {
  id: number
  warehouse_name: string
  source_type: string
  sync_mode: string
  status: string
  preview_unique_skus: number
  preview_sellable_qty: number
  confirmed_at: string | null
  created_at: string
}

const props = defineProps<{
  warehouses: Warehouse[]
  initialWarehouseId?: number | null
  apiAvailable: boolean
  apiStatusLabel: string
  recentBatches: RecentBatch[]
}>()

type Tab = 'paste' | 'file' | 'api'
const activeTab = ref<Tab>(props.initialWarehouseId ? 'file' : 'paste')

// Form state
const selectedWarehouseId = ref<number | null>(props.warehouses.some(w => w.id === props.initialWarehouseId) ? props.initialWarehouseId! : null)
const syncMode = ref<'partial' | 'full'>('partial')
const stockCountAt = ref('')
const nativeFactory = computed(() => props.warehouses.find(w=>w.id===selectedWarehouseId.value)?.slug === 'factory' && activeTab.value==='file')
type NativeChange={scope:string;sku:string;material_code:string;field:string;before:string|number|null;after:string|number|null;status:'added'|'modified'|'zeroed'|'blocked'|'unchanged';note:string|null}
type NativeResult={material_count:number;linked:number;unmapped:number;conflicts:number;applied_products:number;held_products:number;legacy_preserved_products?:number;availability_held_products?:number;duplicate:boolean;preview_token?:string|null;sync_mode:'partial'|'full';changes:NativeChange[];summary:Record<string,number>}
const nativeResult=ref<NativeResult|null>(null)
const nativePreview=ref<NativeResult|null>(null)
const nativeFilter=ref('changed')
const diffSource=computed(()=>nativeResult.value??nativePreview.value)
const nativeStatusLabels:Record<string,string>={added:'新增',modified:'修改',zeroed:'归零',blocked:'待核实 / 保留',unchanged:'未变化'}
const nativeDiffRows=computed(()=> (diffSource.value?.changes??[]).filter(r=>nativeFilter.value==='all'||(nativeFilter.value==='changed'?['added','modified','zeroed'].includes(r.status):r.status===nativeFilter.value)).map((r,i)=>({
  ...r,id:i+1,status_label:nativeStatusLabels[r.status],before_display:r.before??'—',after_display:r.after??'—',
  delta:typeof r.before==='number'&&typeof r.after==='number'?r.after-r.before:null,
})))
const nativeDiffColumns:ColDef<Record<string,unknown>>[]=[
  {field:'sku',headerName:'ERP SKU',width:140,pinned:'left'},
  {field:'material_code',headerName:'库存表代码',width:210},
  {field:'field',headerName:'变化字段',width:185},
  {field:'before_display',headerName:'导入前',width:270,tooltipField:'before_display'},
  {field:'after_display',headerName:'导入后',width:270,tooltipField:'after_display'},
  {field:'delta',headerName:'数量变化',width:115},
  {field:'status_label',headerName:'状态',width:145,cellStyle:p=>({color:p.data?.status==='zeroed'?'#b91c1c':p.data?.status==='blocked'?'#a16207':p.data?.status==='added'?'#15803d':p.data?.status==='modified'?'#1d4ed8':'#64748b'})},
  {field:'note',headerName:'说明',width:300,tooltipField:'note'},
]
const pasteText = ref('')
const selectedFile = ref<File | null>(null)
const fileInputRef = ref<HTMLInputElement | null>(null)

// State
const loading = ref(false)
const error = ref<string | null>(null)
const batch = ref<BatchResult | null>(null)
const confirming = ref(false)
const confirmError = ref<string | null>(null)
const historyLoadingId = ref<number | null>(null)

const canPreview = computed(() => {
  if (!selectedWarehouseId.value) return false
  if (activeTab.value === 'paste') return pasteText.value.trim().length > 0
  if (activeTab.value === 'file') return selectedFile.value !== null
  return false
})

const canConfirm = computed(() =>
  batch.value !== null &&
  batch.value.status === 'preview' &&
  !batch.value.has_blockers
)

const erpRows = computed(() => batch.value ? [...batch.value.aggregated_rows, ...batch.value.full_snapshot_zero_rows] : [])

function onFileChange(e: Event) {
  const input = e.target as HTMLInputElement
  selectedFile.value = input.files?.[0] ?? null
  batch.value = null
  error.value = null
}

function resetForm() {
  batch.value = null
  error.value = null
  confirmError.value = null
  pasteText.value = ''
  selectedFile.value = null
  if (fileInputRef.value) fileInputRef.value.value = ''
}

watch([selectedFile, stockCountAt, syncMode, selectedWarehouseId, activeTab],()=>{
  nativePreview.value=null;nativeResult.value=null;error.value=null
})
function nativeForm() {
  const fd=new FormData()
  fd.append('file',selectedFile.value!)
  fd.append('stock_count_at',stockCountAt.value)
  fd.append('sync_mode',syncMode.value)
  return fd
}
async function previewFactoryNative() {
  if (!nativeFactory.value || !selectedFile.value || !stockCountAt.value || loading.value || confirming.value) return
  loading.value=true;nativeResult.value=null;nativePreview.value=null;error.value=null
  try {
    const {data}=await axios.post('/inventory/sync/factory-native/preview',nativeForm(),{headers:{'Content-Type':'multipart/form-data'}})
    nativePreview.value=data;nativeFilter.value='changed'
    if (data.duplicate) nativeResult.value=data
  } catch(e:any) {
    error.value=e?.response?.data?.message??'预览失败，请检查文件及库存日期。'
    publishGlobalFeedback({state:'ERROR',title:'工厂库存预览失败',message:error.value??''})
  } finally {loading.value=false}
}
async function importFactoryNative() {
  if (!nativeFactory.value || !selectedFile.value || !nativePreview.value?.preview_token || confirming.value || loading.value) return
  confirming.value=true;error.value=null
  try {
    const fd=nativeForm()
    fd.append('preview_token',nativePreview.value.preview_token)
    const {data}=await axios.post('/inventory/sync/factory-native',fd,{headers:{'Content-Type':'multipart/form-data'}})
    nativeResult.value=data;nativePreview.value=null
    publishGlobalFeedback({state:'SUCCESS',title:'工厂库存同步完成',
      message:'原始物料 '+data.material_count+' 条；关联 '+data.linked+' 条；已覆盖商品 '+data.applied_products+' 个；未绑定 '+data.unmapped+' 条。'})
  } catch(e:any) {
    error.value=e?.response?.data?.message??'确认失败，请重新预览。'
    nativePreview.value=null
    publishGlobalFeedback({state:'ERROR',title:'工厂库存导入失败',message:error.value??''})
  } finally {confirming.value=false}
}

async function doPreview() {
  if (!canPreview.value) return
  loading.value = true
  error.value = null
  batch.value = null

  try {
    const fd = new FormData()
    fd.append('warehouse_id', String(selectedWarehouseId.value!))
    fd.append('sync_mode', syncMode.value)
    if (stockCountAt.value) fd.append('stock_count_at', stockCountAt.value)

    if (activeTab.value === 'paste') {
      fd.append('source_type', 'paste')
      fd.append('paste_text', pasteText.value)
    } else if (activeTab.value === 'file' && selectedFile.value) {
      const ext = selectedFile.value.name.split('.').pop()?.toLowerCase()
      fd.append('source_type', ext === 'xlsx' ? 'xlsx' : 'csv')
      fd.append('file', selectedFile.value)
    }

    const { data } = await axios.post('/inventory/sync/preview', fd, {
      headers: { 'Content-Type': 'multipart/form-data', 'X-Requested-With': 'XMLHttpRequest' },
    })
    batch.value = data
  } catch (e: unknown) {
    const msg = (e as { response?: { data?: { error?: string; message?: string } } })?.response?.data?.error
      ?? (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      ?? '预览失败，请重试'
    error.value = msg
  } finally {
    loading.value = false
  }
}

async function doConfirm() {
  if (!batch.value || !canConfirm.value) return
  confirming.value = true
  confirmError.value = null

  try {
    const { data } = await axios.post(`/inventory/sync/${batch.value.id}/confirm`, {
      warehouse_id: selectedWarehouseId.value,
    })
    batch.value = data
  } catch (e: unknown) {
    confirmError.value = (e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? '确认失败，请重试'
  } finally {
    confirming.value = false
  }
}

async function loadHistoryBatch(id: number) {
  historyLoadingId.value = id
  error.value = null
  confirmError.value = null
  try {
    const { data } = await axios.get(`/inventory/sync/${id}`)
    batch.value = data
    selectedWarehouseId.value = data.warehouse_id
    syncMode.value = data.sync_mode
  } catch (e: unknown) {
    error.value = (e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? '同步记录加载失败'
  } finally {
    historyLoadingId.value = null
  }
}

function statusLabel(s: string) {
  if (s === 'sellable') return '正品'
  if (s === 'defective') return '次品'
  return s ?? '—'
}

function validationBadge(s: 'ok' | 'warning' | 'error') {
  if (s === 'ok') return 'bg-green-100 text-green-800'
  if (s === 'warning') return 'bg-yellow-100 text-yellow-800'
  return 'bg-red-100 text-red-800'
}
</script>

<template>
  <Head title="批量同步" />
  <AuthenticatedLayout title="批量同步" :breadcrumbs="[{ label: '库存', href: '/inventory' }, { label: '批量同步' }]">
    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8 space-y-6">

      <!-- Config panel -->
      <div class="bg-white shadow rounded-lg p-6 space-y-4">
        <h2 class="text-lg font-semibold text-gray-900">同步设置</h2>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">目标仓库 <span class="text-red-500">*</span></label>
            <select v-model="selectedWarehouseId" :disabled="loading||confirming" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" @change="batch = null">
              <option :value="null" disabled>请选择仓库</option>
              <option v-for="w in props.warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">同步模式</label>
            <select v-model="syncMode" :disabled="loading||confirming" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" @change="batch = null">
              <option value="partial">{{nativeFactory?'增量同步（只更新表内 SKU）':'部分同步（只更新来源 SKU）'}}</option>
              <option value="full">{{nativeFactory?'覆盖同步（核准范围完整快照）':'全量同步（不在来源中的 SKU 归零）'}}</option>
            </select>

          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">库存统计日期</label>
            <input v-model="stockCountAt" :disabled="loading||confirming" type="date" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" @change="batch = null" />
          </div>
        </div>
      </div>

      <!-- Source tabs -->
      <div class="bg-white shadow rounded-lg">
        <div class="border-b border-gray-200">
          <nav class="-mb-px flex">
            <button
              v-for="tab in (['paste', 'file', 'api'] as const)"
              :key="tab"
              class="px-6 py-3 text-sm font-medium border-b-2 transition-colors"
              :class="activeTab === tab ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
              :disabled="loading||confirming" @click="() => { activeTab = tab; batch = null; error = null }"
            >
              <template v-if="tab === 'paste'">复制粘贴</template>
              <template v-else-if="tab === 'file'">XLSX / CSV</template>
              <template v-else>API <span class="ml-1 text-xs text-gray-400">{{ props.apiStatusLabel }}</span></template>
            </button>
          </nav>
        </div>

        <div class="p-6">
          <!-- Paste -->
          <template v-if="activeTab === 'paste'">
            <p class="text-sm text-gray-500 mb-2">从仓库系统复制表格后粘贴。首行为表头，必须包含 SKU 和 Qty/数量 列。库存属性未填时默认视为正品。</p>
            <textarea
              v-model="pasteText"
              class="w-full h-48 border border-gray-300 rounded-md px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500"
              placeholder="粘贴数据（首行为表头）..."
              @input="batch = null"
            />
          </template>

          <!-- File -->
          <template v-else-if="activeTab === 'file'">
            <p class="text-sm text-gray-500 mb-2">{{ nativeFactory ? '上传工厂原始 XLSX（谦禾库存明细），系统自动读取物料代码、名称、成品库存汇总。' : '上传 XLSX 或 CSV 文件（最大 10 MB）。首行为表头，必须包含 SKU 和 Qty/数量 列。' }}</p>
            <input ref="fileInputRef" type="file" :disabled="loading||confirming" :accept="nativeFactory?'.xlsx':'.xlsx,.csv'" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100" @change="onFileChange" />
            <p v-if="selectedFile" class="mt-2 text-sm text-gray-600">已选择：{{ selectedFile.name }} ({{ Math.round(selectedFile.size / 1024) }} KB)</p>
          </template>

          <!-- API placeholder -->
          <template v-else>
            <div class="text-center py-10 text-gray-400">
              <p class="text-4xl mb-3">🔌</p>
              <p class="text-base font-medium">API 连接器尚未配置</p>
              <p class="text-sm mt-1">M1 阶段仅支持复制粘贴和文件上传。API 连接器将在后续版本启用。</p>
            </div>
          </template>

          <div v-if="nativeFactory" class="mt-4 flex flex-wrap gap-3 items-center">
            <button :disabled="!selectedFile || !stockCountAt || loading || confirming" class="px-4 py-2 bg-blue-600 text-white rounded-md disabled:opacity-50" @click="previewFactoryNative">{{loading?'分析差异中…':'1. 预览导入差异'}}</button>
            <button :disabled="!nativePreview?.preview_token || confirming || loading" class="px-4 py-2 bg-green-700 text-white rounded-md disabled:opacity-50" @click="importFactoryNative">{{confirming?'同步中…':'2. 确认导入'}}</button>
            <span v-if="!stockCountAt" class="text-sm text-gray-500">请选择原表实际库存统计日期</span>
          </div>
          <div v-else-if="activeTab !== 'api'" class="mt-4 flex flex-wrap gap-3 items-center">
            <button
              :disabled="!canPreview || loading"
              class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
              @click="doPreview"
            >
              {{ loading ? '解析中…' : '1. 预览数据' }}
            </button>
            <button
              :disabled="!canConfirm || confirming"
              class="px-5 py-2 bg-green-600 text-white text-sm font-medium rounded-md hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed"
              @click="doConfirm"
            >
              {{ confirming ? '导入中…' : batch?.status === 'confirmed' ? '已导入库存' : batch?.sync_mode === 'partial' && batch.preview_skipped_rows > 0 ? '2. 导入 ' + batch.preview_importable_rows + ' 条（跳过 ' + batch.preview_skipped_rows + ' 条）' : '2. 确认导入库存' }}
            </button>
            <span v-if="!batch" class="text-xs text-gray-400">请先预览数据；部分同步自动跳过错误行，全量同步保持严格校验。</span>
            <span v-else-if="batch.has_blockers" class="text-xs text-red-600">{{ batch.sync_mode === 'full' ? '全量同步有错误，禁止导入。' : '没有可导入的有效行，请核对 SKU 和数量。' }}</span>
            <span v-else-if="batch.status === 'confirmed'" class="text-xs text-green-700">本批次已写入库存。</span>
            <button v-if="batch || error" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900" @click="resetForm">重置</button>
          </div>
        </div>
      </div>

      <section v-if="nativeFactory && diffSource" class="bg-white rounded-lg p-4 space-y-3">
        <div class="flex items-center justify-between flex-wrap gap-2">
          <h2 class="font-semibold">{{nativeResult?'本次导入结果':'预览并确认导入'}}</h2>
          <span class="text-sm">{{diffSource.sync_mode==='full'?'覆盖同步':'增量同步'}} · {{diffSource.duplicate?'该文件已同步，未重复写入':nativeResult?'已确认同步':'尚未写入库存'}}</span>
        </div>
        <div class="flex flex-wrap gap-2">
          <button v-for="stat in [{key:'changed',label:'全部变化',count:(diffSource.summary?.added??0)+(diffSource.summary?.modified??0)+(diffSource.summary?.zeroed??0)},{key:'added',label:'新增',count:diffSource.summary?.added??0},{key:'modified',label:'修改',count:diffSource.summary?.modified??0},{key:'zeroed',label:'归零',count:diffSource.summary?.zeroed??0},{key:'blocked',label:'待核实 / 保留',count:diffSource.summary?.blocked??0},{key:'all',label:'全部记录',count:diffSource.changes?.length??0}]" :key="stat.key" type="button" class="px-3 py-2 rounded border text-sm" :class="nativeFilter===stat.key?'border-teal-700 text-teal-700 bg-teal-50':'border-gray-200'" @click="nativeFilter=stat.key">{{stat.label}} {{stat.count}}</button>
        </div>
        <div class="text-sm text-gray-600">原表 {{diffSource.material_count}} 条 · 变更统计按字段计数 · 已关联 {{diffSource.linked}} 条 · 未绑定 {{diffSource.unmapped}} 条 · {{nativeResult?'已覆盖':'预计覆盖'}} {{diffSource.applied_products}} 个商品 · 保留旧库存口径 {{diffSource.legacy_preserved_products??0}} 个 · 暂停可用性 {{diffSource.availability_held_products??0}} 个。</div>
        <GlobalGrid v-if="nativeDiffRows.length" namespace="grid.inventory.factory-import-diff.v1" title="逐项变更明细" :rows="nativeDiffRows" :columns="nativeDiffColumns" :required-column-ids="['sku']" cell-text-selection/>
        <p v-else class="text-sm text-gray-500 py-3">{{diffSource.duplicate && !diffSource.changes?.length?'该历史批次没有保存逐项差异；请使用新日期的实际库存原表进行预览。':'当前筛选没有变更记录。'}}</p>
        <p class="text-xs text-gray-500">数量为新库存绝对值，不累加旧库存。增量同步保留表外 SKU；覆盖同步检查完整核准范围，缺失记录保留历史，必要时暂停可用性，不强制删除或归零。007 原表数量仅记录。</p>
        <a class="text-blue-700 text-sm" href="/products/skus">查看 SKU 映射中心</a>
      </section>
      <!-- Error -->
      <div v-if="error" class="bg-red-50 border border-red-200 rounded-lg p-4 text-red-700 text-sm">{{ error }}</div>

      <!-- Preview result -->
      <div v-if="batch" class="bg-white shadow rounded-lg p-6 space-y-4">
        <div class="flex items-center justify-between">
          <h2 class="text-lg font-semibold text-gray-900">预览结果</h2>
          <span
            class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
            :class="batch.status === 'confirmed' ? 'bg-green-100 text-green-800' : batch.has_blockers ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800'"
          >
            {{ batch.status === 'confirmed' ? '已确认' : batch.has_blockers ? '禁止导入' : batch.preview_skipped_rows > 0 ? '部分可导入' : '待确认' }}
          </span>
        </div>

        <!-- Summary stats -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
          <div v-for="metric in [
            ['来源行数', batch.preview_total_rows],
            ['唯一 SKU', batch.preview_unique_skus],
            ['正品数量', batch.preview_sellable_qty],
            ['次品数量', batch.preview_defective_qty],
            ['重复 / 多 Lot SKU', batch.preview_duplicate_skus],
            ['未匹配 SKU', batch.preview_unmatched_skus],
            ['警告行', batch.preview_warning_rows],
            ['错误行', batch.preview_error_rows],
            ['可导入行', batch.preview_importable_rows],
          ]" :key="String(metric[0])" class="bg-gray-50 rounded p-3">
            <div class="text-xs text-gray-500">{{ metric[0] }}</div>
            <div class="text-xl font-bold text-gray-900">{{ metric[1] }}</div>
          </div>
          <div v-if="batch.sync_mode === 'full'" class="bg-yellow-50 rounded p-3">
            <div class="text-xs text-gray-500">确认后归零 SKU</div>
            <div class="text-xl font-bold text-yellow-800">{{ batch.preview_zeroed_skus }}</div>
          </div>
        </div>

        <!-- Blocker notice -->
        <div v-if="batch.has_blockers" class="bg-red-50 border border-red-200 rounded p-3 text-sm text-red-700">
          {{ batch.sync_mode === 'full' ? '全量同步不允许忽略错误行，请修正数据或切换部分同步。' : '当前没有可导入的有效 SKU，全部错误行均已隔离，库存不会被修改。' }}
        </div>
        <div v-if="batch.preview_skipped_rows > 0 && batch.sync_mode === 'partial' && !batch.has_blockers"
             class="bg-amber-50 border border-amber-200 rounded p-3 text-sm text-amber-900">
          本批次 {{ batch.preview_importable_rows }} 条有效数据可以导入；{{ batch.preview_skipped_rows }} 条错误数据将自动跳过且不修改其库存。
          请核对下方 ERP 聚合结果。{{ batch.status === 'confirmed' ? '有效数据已写入，错误行已忽略。' : '点击确认仅更新有效 SKU。' }}
        </div>

        <!-- Full sync warning -->
        <div v-if="batch.sync_mode === 'full'" class="bg-yellow-50 border border-yellow-200 rounded p-3 text-sm text-yellow-800">
          ⚠️ 全量同步模式：确认后，该仓库中不在来源中的 SKU 可售库存将归零。
        </div>

        <!-- Aggregated ERP result -->
        <div v-if="erpRows.length > 0" class="overflow-x-auto">
          <h3 class="text-sm font-semibold text-gray-900 mb-2">ERP 聚合结果（确认后）</h3>
          <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50"><tr>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500">SKU</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500">动作</th>
              <th class="px-3 py-2 text-right text-xs font-medium text-gray-500">正品</th>
              <th class="px-3 py-2 text-right text-xs font-medium text-gray-500">次品</th>
              <th class="px-3 py-2 text-right text-xs font-medium text-gray-500">Lot</th>
              <th class="px-3 py-2 text-right text-xs font-medium text-gray-500">Reserved</th>
              <th class="px-3 py-2 text-right text-xs font-medium text-gray-500">Available</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500">最早上架</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="row in erpRows" :key="`${row.action}-${row.product_id}`" :class="row.action === 'zero' ? 'bg-yellow-50' : ''">
                <td class="px-3 py-2 font-mono font-medium">{{ row.sku ?? '—' }}</td>
                <td class="px-3 py-2">
                  <span v-if="row.action === 'zero'" class="text-yellow-800">归零（当前 {{ row.current_sellable_qty ?? 0 }}）</span>
                  <span v-else class="text-gray-600">替换</span>
                </td>
                <td class="px-3 py-2 text-right">{{ row.sellable_qty }}</td>
                <td class="px-3 py-2 text-right">{{ row.defective_qty }}</td>
                <td class="px-3 py-2 text-right">{{ row.lot_count }}</td>
                <td class="px-3 py-2 text-right">{{ row.quantity_reserved }}</td>
                <td class="px-3 py-2 text-right">{{ row.quantity_available_after }}</td>
                <td class="px-3 py-2">{{ row.inbound_at ?? '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Row table -->
        <div v-if="batch.rows && batch.rows.length > 0" class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">行号</th>
                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">SKU</th>
                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">状态</th>
                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">上架日期</th>
                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">数量</th>
                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">验证</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="row in batch.rows" :key="row.id" :class="row.validation_status === 'error' ? 'bg-red-50' : ''">
                <td class="px-3 py-2 text-gray-400">{{ row.source_row_no }}</td>
                <td class="px-3 py-2 font-mono font-medium">{{ row.sku ?? '—' }}</td>
                <td class="px-3 py-2">{{ statusLabel(row.stock_status ?? '') }}</td>
                <td class="px-3 py-2">{{ row.putaway_date ?? '—' }}</td>
                <td class="px-3 py-2 text-right">{{ row.quantity ?? '—' }}</td>
                <td class="px-3 py-2">
                  <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium" :class="validationBadge(row.validation_status)">
                    {{ row.validation_status === 'ok' ? '✓' : row.validation_message ?? row.validation_status }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Confirm result -->
        <div v-if="batch.status === 'confirmed'" class="flex items-center gap-2 text-green-700 text-sm pt-2">
          <span>✓</span>
          <span>已于 {{ batch.confirmed_at ? new Date(batch.confirmed_at).toLocaleString('zh-CN') : '—' }} 导入 {{ batch.preview_importable_rows }} 条有效数据，跳过 {{ batch.preview_skipped_rows }} 条错误行</span>
        </div>

        <div v-if="confirmError" class="text-red-600 text-sm">{{ confirmError }}</div>
      </div>

      <!-- Sync history -->
      <div v-if="props.recentBatches.length > 0" class="bg-white shadow rounded-lg p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">最近同步记录</h2>
        <table class="min-w-full divide-y divide-gray-200 text-sm">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">仓库</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">来源</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">模式</th>
              <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">SKU</th>
              <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">正品数量</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">状态</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">时间</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">详情</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="b in props.recentBatches" :key="b.id">
              <td class="px-3 py-2">{{ b.warehouse_name }}</td>
              <td class="px-3 py-2">{{ b.source_type }}</td>
              <td class="px-3 py-2">{{ b.sync_mode === 'full' ? '全量' : '部分' }}</td>
              <td class="px-3 py-2 text-right">{{ b.preview_unique_skus }}</td>
              <td class="px-3 py-2 text-right">{{ b.preview_sellable_qty }}</td>
              <td class="px-3 py-2">
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
                  :class="b.status === 'confirmed' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'">
                  {{ b.status === 'confirmed' ? '已确认' : b.status }}
                </span>
              </td>
              <td class="px-3 py-2 text-gray-400">{{ new Date(b.created_at).toLocaleString('zh-CN') }}</td>
              <td class="px-3 py-2">
                <button class="text-blue-600 hover:text-blue-800 disabled:opacity-50" :disabled="historyLoadingId === b.id" @click="loadHistoryBatch(b.id)">
                  {{ historyLoadingId === b.id ? '加载中…' : '查看' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

    </div>
  </AuthenticatedLayout>
</template>
