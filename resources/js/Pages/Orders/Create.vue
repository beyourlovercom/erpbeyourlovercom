<script setup lang="ts">
import CountrySelect from '../../Components/CountrySelect.vue';
import { resolveCountry } from '../../lib/countryStandard'
import { Head, router, useForm } from '@inertiajs/vue3'
import { ref, computed, watch } from 'vue'
import axios from 'axios'

const currencyOptions = [
  { value: 'USD', label: 'USD · 美元' },
  { value: 'CAD', label: 'CAD · 加拿大元' },
  { value: 'HKD', label: 'HKD · 港币' },
  { value: 'EUR', label: 'EUR · 欧元' },
  { value: 'CNY', label: 'CNY · 人民币' },
  { value: 'GBP', label: 'GBP · 英镑' },
  { value: 'AUD', label: 'AUD · 澳元' },
  { value: 'JPY', label: 'JPY · 日元' },
  { value: 'SGD', label: 'SGD · 新加坡元' },
]
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import SkuCombobox from '../../Components/Products/SkuCombobox.vue'
import KolIdentityCombobox from './KolIdentityCombobox.vue'
import RecipientCustomsField from '../../Components/RecipientCustomsField.vue'
import KolSmartInput from '../../Components/Kol/KolSmartInput.vue'
import type { QuickOrderDraft } from '../../lib/kol/kolQuickOrderParser'
import { publishGlobalFeedback } from '../../lib/globalFeedback'

type KolAddress = { recipient_customs_id?: string | null; recipient_name: string | null; phone: string | null; email: string | null; country: string | null; province_state: string | null; city: string | null; address_line_1: string | null; address_line_2: string | null; postal_code: string | null }

const props = defineProps<{
  defaultOrderKind: string
  onboardingToken?: string|null
  resumeOrderDraft?: {smart:QuickOrderDraft;order?:{order_date?:string;order_note?:string;currency?:string;items?:OrderItem[]}}|null
  canOnboardKol?: boolean
  orderKindLabel: string
  generatedExternalOrderNumber: string
  kolOptions?: { value: number; label: string; account?: string | null }[]
  selectedKolId?: number | null
  selectedKolAddress?: KolAddress | null
}>()

type OrderItem = {
  product_summary: string
  sku: string
  product_id: number | null
  quantity: number
  unit_price: string
}

const form = useForm<{
  external_order_number: string
  onboarding_token: string|null
  address_confirmed: boolean|null
  kol_id: number | null
  order_date: string
  order_note: string
  currency: string
  recipient_name: string
  phone: string
  email: string
  country: string
  recipient_customs_id: string
  province_state: string
  city: string
  address_line_1: string
  address_line_2: string
  postal_code: string
  items: OrderItem[]
}>({
  external_order_number: props.generatedExternalOrderNumber,
  onboarding_token: props.onboardingToken ?? null,
  address_confirmed: null,
  kol_id: props.defaultOrderKind === 'kol_fulfillment' ? (props.selectedKolId ?? null) : null,
  order_date: props.resumeOrderDraft?.order?.order_date ?? (props.defaultOrderKind === 'kol_fulfillment' ? '' : new Date().toISOString().slice(0, 10)),
  order_note: props.resumeOrderDraft?.order?.order_note ?? '',
  currency: props.resumeOrderDraft?.order?.currency ?? 'USD',
  recipient_name: '',
  phone: '',
  email: '',
  country: '',
  recipient_customs_id: '',
  province_state: '',
  city: '',
  address_line_1: '',
  address_line_2: '',
  postal_code: '',
  items: props.resumeOrderDraft?.order?.items?.length ? props.resumeOrderDraft.order.items.map(i=>({...i,unit_price:String(i.unit_price??'0.00')})) : [{ product_summary: '', sku: '', product_id: null, quantity: 1, unit_price: '0.00' }],
})

// Every KOL selection uses its saved shipping/contact details; never reuse the former KOL address.
const addressLoading = ref(false)
const addressWarning = ref('')
const addressFields = ['recipient_name', 'phone', 'email', 'country', 'recipient_customs_id', 'province_state', 'city', 'address_line_1', 'address_line_2', 'postal_code'] as const
let addressRequestId = 0
let lastKolShippingCountry = ''
const fillKolAddress = (address: KolAddress | null) => {
  for (const field of addressFields) form[field] = address?.[field] ?? ''
  form.country = resolveCountry(form.country)?.code ?? form.country
  lastKolShippingCountry = form.country
}
// A changed destination requires a new recipient ID; never carry another country's number.
watch(() => form.country, country => {
  if (isKol.value && country !== lastKolShippingCountry) {
    form.recipient_customs_id = ''
  }
})
watch(() => form.kol_id, async kolId => {
  if (props.defaultOrderKind !== 'kol_fulfillment') return
  const requestId = ++addressRequestId
  fillKolAddress(null)
  addressWarning.value = ''
  addressLoading.value = false
  if (!kolId) return
  if (kolId === props.selectedKolId && props.selectedKolAddress) {
    fillKolAddress(props.selectedKolAddress)
    return
  }
  addressLoading.value = true
  try {
    const response = await axios.get<{ kol_id: number; address: KolAddress }>('/kol/orders/kol-address', { params: { kol_id: kolId } })
    if (requestId === addressRequestId && response.data.kol_id === kolId) fillKolAddress(response.data.address)
  } catch {
    if (requestId === addressRequestId) addressWarning.value = '未能读取网红档案地址，请重新选择网红。'
  } finally {
    if (requestId === addressRequestId) addressLoading.value = false
  }
}, { immediate: true })

const smartDraft = ref<QuickOrderDraft|null>(props.resumeOrderDraft?.smart ? {...props.resumeOrderDraft.smart,social_accounts:props.resumeOrderDraft.smart.social_accounts??[],unparsed:[]} : null)
const smartSearch = ref('')
const smartWarning=ref('')
const hasPastedAddress=computed(()=>Boolean(smartDraft.value&&addressFields.some(f=>smartDraft.value?.[f])))
const selectedAccount=ref((props.kolOptions??[]).find(item=>item.value===form.kol_id)?.account??null)
const draftSaving=ref(false)
const smartAddressConfirmed = ref(false)
const applySmartDraft = (draft: QuickOrderDraft) => {
  const handle=draft.social_accounts.length===1 ? draft.social_accounts[0].handle : draft.username_hint ?? ''
  smartWarning.value=''
  if(form.kol_id&&handle&&selectedAccount.value&&selectedAccount.value.replace(/^@/,'').toLowerCase()!==handle.toLowerCase()){
    smartWarning.value='当前已关联网红与粘贴账号不同，请先更换关联网红后重新识别。'
    return
  }
  smartDraft.value=draft
  smartSearch.value=handle
  smartAddressConfirmed.value=false
}
const startOnboarding = (term:string) => {
  if(form.kol_id || draftSaving.value || !props.canOnboardKol)return
  const draft=smartDraft.value
  const {unparsed: _ignored,...smart}=draft??{social_accounts:[],unparsed:[]}
  const username=term.trim()
  if(smartSearch.value && username.toLowerCase()!==smartSearch.value.toLowerCase()){
    publishGlobalFeedback({state:'BLOCKED',title:'请核对网红身份',message:'当前搜索账号与粘贴资料不一致，请重新粘贴正确资料。'})
    return
  }
  if(!username && !smart.social_accounts.length)return
  // Retain manual order input too; no raw text or password enters the draft.
  const shipping={...smart}
  for(const field of addressFields)if(form[field])shipping[field]=form[field]
  draftSaving.value=true
  router.post('/kol/onboarding-draft',{
    smart:{...shipping,username_hint:smart.username_hint??username},
    order:{order_date:form.order_date||null,order_note:form.order_note,currency:form.currency,items:form.items},
  },{onFinish:()=>{draftSaving.value=false}})
}
const applySmartAddress = () => {
  const draft=smartDraft.value
  if (!draft || !form.kol_id || addressLoading.value || !hasPastedAddress.value) return
  if(smartSearch.value&&selectedAccount.value&&selectedAccount.value.replace(/^@/,'').toLowerCase()!==smartSearch.value.toLowerCase()){
    smartWarning.value='粘贴账号与当前网红不一致，请核对后手工填写本次收件信息。'
    return
  }
  const previousCountry=resolveCountry(form.country)?.code ?? form.country
  const draftCountry=resolveCountry(draft.country)?.code ?? draft.country
  const changesRecipient = Boolean(draft.recipient_name && draft.recipient_name!==form.recipient_name)
  const changesCountry = Boolean(draftCountry && draftCountry!==previousCountry)
  // Do not mix a different country or recipient with a previously saved shipping address.
  if(changesCountry || changesRecipient) {
    for(const field of addressFields) form[field]=''
  }
  for(const field of addressFields) {
    if(field==='recipient_customs_id') continue
    const value=field==='country' ? draftCountry : draft[field]
    if(value) form[field]=value
  }
  lastKolShippingCountry=form.country
  if(previousCountry!==form.country || changesRecipient)form.recipient_customs_id=''
  if(draft.recipient_customs_id&&draftCountry===form.country)form.recipient_customs_id=draft.recipient_customs_id
  if(draft.product_hint&&!form.items[0].product_id&&!form.items[0].product_summary)form.items[0].product_summary=draft.product_hint
  smartAddressConfirmed.value=false
}
watch([()=>form.kol_id,...addressFields.map(field=>()=>form[field])],()=>{smartAddressConfirmed.value=false})

const numberEditable = ref(false)

const isKol = computed(() => props.defaultOrderKind === 'kol_fulfillment')

const pageTitle = computed(() => isKol.value ? '新建网红履约订单' : '新建客户订单')

const breadcrumbs = computed(() => isKol.value
  ? [{ label: 'BYL ERP', href: '/dashboard' }, { label: '网红', href: '/kol' }, { label: '订单', href: '/kol/orders' }, '新建']
  : [{ label: 'BYL ERP', href: '/dashboard' }, { label: '客户', href: '/customers' }, { label: '订单', href: '/customers/orders' }, '新建']
)

const listHref = computed(() => isKol.value ? '/kol/orders' : '/customers/orders')

const addItem = () =>
  form.items.push({ product_summary: '', sku: '', product_id: null, quantity: 1, unit_price: '0.00' })

const removeItem = (index: number) => {
  if (form.items.length > 1) form.items.splice(index, 1)
}

const validationFeedback = (errors: Record<string, string>) => {
  const keys = Object.keys(errors)
  if (keys.some((key) => /^items\.\d+\.product_id$/.test(key))) {
    return {
      state: 'BLOCKED' as const,
      title: '订单无法创建',
      message: '请先选择订单商品',
      next: '选择 SKU / 产品后重新提交',
    }
  }

  const message = keys.some((key) => key === 'external_order_number')
    ? '请检查业务订单号'
    : keys.some((key) => key === 'order_date')
      ? '请检查订单日期'
      : keys.some((key) => key === 'currency')
        ? '请检查订单币种'
        : keys.some((key) => /^items\.\d+\.quantity$/.test(key))
          ? '请检查商品数量'
          : keys.some((key) => /^items\.\d+\.unit_price$/.test(key))
            ? '请检查商品单价'
            : '请检查标记为错误的字段'

  return {
    state: 'BLOCKED' as const,
    title: '订单无法创建',
    message,
    next: '修正后重新提交',
  }
}

const submit = () => {
  if (isKol.value && (!form.kol_id || addressLoading.value || !smartAddressConfirmed.value)) {
    publishGlobalFeedback({state:'BLOCKED',title:'请确认收件信息',message:'请先关联已有网红并核对本次收件地址。'})
    return
  }
  form.address_confirmed = isKol.value ? smartAddressConfirmed.value : null
  form.post(isKol.value ? '/kol/orders' : '/customers/orders', {
    onStart: () => publishGlobalFeedback({
      state: 'SUBMITTING',
      title: '正在创建订单',
      message: '',
    }),
    onError: (errors) => publishGlobalFeedback(validationFeedback(errors as Record<string, string>)),
  })
}
</script>

<template>
  <Head :title="`${pageTitle} · BYL ERP`" />
  <AuthenticatedLayout :title="pageTitle" :breadcrumbs="breadcrumbs">
    <div class="erp-form-page">
      <div class="erp-form-content">

        <!-- Page header -->
        <div class="erp-form-header-row">
          <div>
            <p class="ord-kicker">{{ isKol ? '网红履约' : '客户订单' }}</p>
            <h1 class="erp-form-title">{{ pageTitle }}</h1>
            <p class="erp-form-subtitle">填写订单信息后点击「创建订单」完成录入</p>
          </div>
          <div class="erp-form-header-actions page-header-actions">
            <a :href="listHref" class="erp-btn erp-btn-secondary">取消</a>
            <a :href="listHref" class="erp-btn erp-btn-secondary">返回列表</a>
            <button type="submit" form="order-create-form" class="erp-btn erp-btn-primary" :disabled="form.processing">创建订单</button>
          </div>
        </div>

        <form id="order-create-form" @submit.prevent="submit">
          <KolSmartInput v-if="isKol" @apply="applySmartDraft" />
          <p v-if="smartWarning" role="alert" data-testid="kol-smart-warning">{{smartWarning}}</p>
          <section v-if="isKol && smartDraft" class="erp-form-card" data-testid="kol-smart-order-review">
            <p v-if="smartSearch">识别 Username：{{smartSearch}}。请在下方检索并选择网红；不存在时先创建网红。</p>
            <p v-if="smartDraft.product_hint">产品候选：{{smartDraft.product_hint}}（请手动核对 SKU）</p>
            <p v-if="smartDraft.coupon_code">折扣码：{{smartDraft.coupon_code}}（请核对网红档案）</p>
            <button v-if="hasPastedAddress" type="button" class="erp-btn erp-btn-secondary" :disabled="!form.kol_id || addressLoading" data-testid="kol-smart-address-apply" @click="applySmartAddress">采用粘贴的收件信息</button>
          </section>

          <!-- Card 1: 订单信息 -->
          <div class="erp-form-card">
            <h2 class="erp-form-card-title">订单信息</h2>
            <div class="erp-form-grid">
              <div class="erp-form-field">
                <label class="erp-form-label">订单类型</label>
                <div class="erp-readonly-input">{{ props.orderKindLabel }}</div>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">来源</label>
                <div class="erp-readonly-input">手动创建</div>
              </div>
              <div v-if="isKol" class="erp-form-field ord-span-2">
                <label for="linked_kol_id" class="erp-form-label">关联网红 <span class="erp-form-required">*</span></label>
                <KolIdentityCombobox
                  v-model="form.kol_id"
                  :initial-option="(kolOptions ?? []).find(item => item.value === form.kol_id) ?? null"
                  :has-error="Boolean(form.errors.kol_id)"
                  :search-hint="smartSearch" :can-onboard="canOnboardKol" :onboarding-busy="draftSaving" @create-new="startOnboarding" @selected="selectedAccount=$event?.account??null"
                  required
                />
                <p v-if="form.errors.kol_id" class="erp-field-error">{{ form.errors.kol_id }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">{{ isKol ? '合作日期（可选）' : '订单日期' }} <span v-if="!isKol" class="erp-form-required">*</span></label>
                <input v-model="form.order_date" type="date" class="erp-input" :class="{ 'has-error': form.errors.order_date }" :required="!isKol">
                <small v-if="isKol">有合作日期请填写真实日期；留空则使用本订单实际创建日期</small>
                <p v-if="form.errors.order_date" class="erp-field-error">{{ form.errors.order_date }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">订单币种 <span class="erp-form-required">*</span></label>
                <select v-model="form.currency" class="erp-input" required>
                  <option v-for="option in currencyOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
                <p v-if="form.errors.currency" class="erp-field-error">{{ form.errors.currency }}</p>
              </div>
              <div class="erp-form-field ord-span-2">
                <label for="external_order_number" class="erp-form-label">
                  业务订单号 <span class="erp-form-required">*</span>
                  <span class="ord-hint-inline">（系统已预生成，保存前可修改）</span>
                </label>
                <div class="ord-inline-row">
                  <input
                    id="external_order_number"
                    v-model="form.external_order_number"
                    class="erp-input"
                    :class="{ 'erp-input-readonly-style': !numberEditable, 'has-error': form.errors.external_order_number }"
                    :readonly="!numberEditable"
                    required
                  >
                  <button
                    v-if="!numberEditable"
                    type="button"
                    class="erp-btn erp-btn-secondary ord-icon-btn"
                    aria-label="编辑订单号"
                    @click="numberEditable = true"
                  >
                    编辑
                  </button>
                </div>
                <p v-if="form.errors.external_order_number" class="erp-field-error">{{ form.errors.external_order_number }}</p>
              </div>
            </div>
          </div>

          <!-- Card 2: 收件与地址 -->
          <div class="erp-form-card">
            <h2 class="erp-form-card-title">收件与地址</h2>
            <label v-if="isKol" class="erp-form-helper"><input type="checkbox" v-model="smartAddressConfirmed" :disabled="!form.kol_id || addressLoading" data-testid="kol-smart-address-confirm"> 已核对本次收件信息（下方表单）</label>
            <p v-if="isKol && form.kol_id" class="ord-hint-inline" data-testid="kol-address-source">{{ addressLoading ? '正在读取网红档案…' : '已自动加载网红档案中的收件信息，可直接核对或按本单调整。' }}</p>
            <p v-if="addressWarning" class="erp-field-error" role="alert">{{ addressWarning }}</p>
            <div class="erp-form-grid">
              <div class="erp-form-field">
                <label class="erp-form-label">收件人姓名</label>
                <input v-model="form.recipient_name" data-testid="order-recipient-name" class="erp-input" placeholder="可选">
                <p v-if="form.errors.recipient_name" class="erp-field-error">{{ form.errors.recipient_name }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">联系电话</label>
                <input v-model="form.phone" data-testid="order-phone" class="erp-input" placeholder="可选">
                <p v-if="form.errors.phone" class="erp-field-error">{{ form.errors.phone }}</p>
              </div>
              <div v-if="isKol" class="erp-form-field">
                <label class="erp-form-label">联系邮箱（档案带入）</label>
                <input v-model="form.email" data-testid="order-email" type="email" class="erp-input" placeholder="网红档案尚未填写">
                <p v-if="form.errors.email" class="erp-field-error">{{ form.errors.email }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">国家</label>
                <CountrySelect v-model="form.country" data-testid="order-country" class="erp-input" placeholder="搜索国家名称 / 代码" />
                <p v-if="form.errors.country" class="erp-field-error">{{ form.errors.country }}</p>
              </div>
              <RecipientCustomsField
                v-if="isKol"
                :country="form.country"
                v-model="form.recipient_customs_id"
                :error="form.errors.recipient_customs_id"
              />
              <div class="erp-form-field">
                <label class="erp-form-label">省/州</label>
                <input v-model="form.province_state" class="erp-input" placeholder="可选">
                <p v-if="form.errors.province_state" class="erp-field-error">{{ form.errors.province_state }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">城市</label>
                <input v-model="form.city" data-testid="order-city" class="erp-input" placeholder="可选">
                <p v-if="form.errors.city" class="erp-field-error">{{ form.errors.city }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">邮政编码</label>
                <input v-model="form.postal_code" class="erp-input" placeholder="可选">
                <p v-if="form.errors.postal_code" class="erp-field-error">{{ form.errors.postal_code }}</p>
              </div>
              <div class="erp-form-field ord-span-2">
                <label class="erp-form-label">地址行 1</label>
                <input v-model="form.address_line_1" data-testid="order-address-line-1" class="erp-input" placeholder="可选">
                <p v-if="form.errors.address_line_1" class="erp-field-error">{{ form.errors.address_line_1 }}</p>
              </div>
              <div class="erp-form-field ord-span-2">
                <label class="erp-form-label">地址行 2</label>
                <input v-model="form.address_line_2" class="erp-input" placeholder="可选">
                <p v-if="form.errors.address_line_2" class="erp-field-error">{{ form.errors.address_line_2 }}</p>
              </div>
            </div>
          </div>

          <!-- Card 3: 订单商品 -->
          <div class="erp-form-card">
            <div class="ord-card-header-row">
              <h2 class="erp-form-card-title">订单商品</h2>
              <button type="button" class="erp-btn erp-btn-secondary" @click="addItem">添加商品</button>
            </div>
            <div v-for="(item, index) in form.items" :key="index" class="ord-item-row">
              <div class="ord-item-sku">
                <label class="erp-form-label">SKU / 产品 <span class="erp-form-required">*</span></label>
                <SkuCombobox
                  v-model="item.sku"
                  :product-id="item.product_id"
                  :search-hint="isKol && index===0 ? smartDraft?.product_hint : undefined"
                  @update:product-id="(id) => { item.product_id = id }"
                />
                <p v-if="(form.errors as Record<string, string>)[`items.${index}.product_id`]" class="erp-field-error">
                  {{ (form.errors as Record<string, string>)[`items.${index}.product_id`] }}
                </p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">产品摘要</label>
                <input v-model="item.product_summary" class="erp-input" placeholder="可选">
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">数量 <span class="erp-form-required">*</span></label>
                <input v-model="item.quantity" type="number" min="1" class="erp-input" required>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">单价（{{ form.currency }}） <span class="erp-form-required">*</span></label>
                <input v-model="item.unit_price" type="number" min="0" step="0.01" class="erp-input" required>
              </div>
              <div class="ord-item-remove">
                <button
                  v-if="form.items.length > 1"
                  type="button"
                  class="erp-btn erp-btn-secondary"
                  @click="removeItem(index)"
                >
                  删除
                </button>
              </div>
            </div>
          </div>

          <!-- Card 4: 内部备注 -->
          <div class="erp-form-card">
            <h2 class="erp-form-card-title">内部备注</h2>
            <div class="erp-form-field">
              <label class="erp-form-label">备注内容</label>
              <textarea v-model="form.order_note" class="erp-textarea" placeholder="可选" />
              <p v-if="form.errors.order_note" class="erp-field-error">{{ form.errors.order_note }}</p>
            </div>
          </div>



        </form>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<style scoped>
.ord-kicker {
  font-size: 13px;
  color: #7a8c94;
  margin: 0 0 4px;
}

.ord-hint-inline {
  font-size: 12px;
  color: #7a8c94;
  font-weight: 400;
}

.ord-inline-row {
  display: flex;
  gap: 8px;
  align-items: center;
}

.ord-inline-row .erp-input {
  flex: 1;
}

.ord-icon-btn {
  white-space: nowrap;
  flex-shrink: 0;
}

.erp-input-readonly-style {
  background: #f4f7f8;
  color: #7a8c94;
}

/* Item row is order-specific layout not covered by erp-form-grid */
.ord-item-row {
  display: grid;
  grid-template-columns: 2fr 2fr 1fr 1fr auto;
  gap: 12px;
  align-items: end;
  padding: 12px 0;
  border-top: 1px solid #e4eaed;
}

.ord-item-row:first-of-type {
  border-top: none;
}

.ord-item-sku {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.ord-item-remove {
  display: flex;
  align-items: flex-end;
  padding-bottom: 0;
}

.ord-card-header-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 4px;
}

.ord-card-header-row .erp-form-card-title {
  margin: 0;
}

/* span-2 within erp-form-grid */
.ord-span-2 {
  grid-column: span 2;
}

.erp-form-header-actions {
  display: flex;
  gap: 8px;
  align-items: center;
  justify-content: flex-end;
  flex-wrap: wrap;
}

@media (max-width: 767px) {
  .erp-form-header-actions {
    width: 100%;
    justify-content: flex-start;
  }
  .erp-form-header-actions .erp-btn {
    flex: 1 1 auto;
  }
  .ord-span-2 {
    grid-column: span 1;
  }
  .ord-item-row {
    grid-template-columns: 1fr;
  }
}
</style>
