<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type OwnerOption = { value: number; label: string }
type SocialMetric = { followers: number | null; raw_followers_value: string | null; measured_at: string | null }
type SocialAccount = { id: number; platform: string; handle: string | null; profile_url: string | null; is_active: boolean; latest_metric: SocialMetric | null }
type SalesPerformanceEvent = { id: number; order_count: number; recorded_at: string | null; source: string | null; note: string | null }
type KolDetail = {
  id: number; display_name: string; legal_name: string | null; country_code: string | null; language: string | null
  status: string; relationship_stage: string; cooperation_type: string | null; owner_id: number | null
  joined_at: string | null; notes: string | null
  contact_email: string | null; contact_phone: string | null; contact_whatsapp: string | null; other_contact: string | null
  preferred_contact_method: string
  tax_id: string | null; payout_method: string | null; payout_account: string | null
  shipping_recipient_name: string | null; shipping_country: string | null; shipping_province_state: string | null
  shipping_city: string | null; shipping_postal_code: string | null
  shipping_address_line_1: string | null; shipping_address_line_2: string | null
  level: string | null; city: string | null; categories: string[]; products: string[]
  content_format: string | null; product_quantity: number | null; store_count: number | null
  internal_contact: string | null; mcn_agency: string | null; is_mcn_managed: boolean
  historical_order_count: number
  sales_performance_history: SalesPerformanceEvent[]
  social_accounts: SocialAccount[]
}

const props = defineProps<{
  kol: KolDetail
  ownerOptions: OwnerOption[]
  canManageViews: boolean
}>()

const form = useForm({
  display_name: props.kol.display_name,
  legal_name: props.kol.legal_name ?? '',
  country_code: props.kol.country_code ?? '',
  language: props.kol.language ?? '',
  status: props.kol.status,
  owner_id: props.kol.owner_id ? String(props.kol.owner_id) : '',
  relationship_stage: props.kol.relationship_stage,
  cooperation_type: props.kol.cooperation_type ?? '',
  joined_at: props.kol.joined_at ?? '',
  notes: props.kol.notes ?? '',
  contact_email: props.kol.contact_email ?? '',
  contact_phone: props.kol.contact_phone ?? '',
  contact_whatsapp: props.kol.contact_whatsapp ?? '',
  other_contact: props.kol.other_contact ?? '',
  preferred_contact_method: (props.kol.preferred_contact_method || 'email') as 'phone' | 'email' | 'whatsapp' | 'other',
  tax_id: props.kol.tax_id ?? '',
  payout_method: (props.kol.payout_method ?? '') as '' | 'paypal' | 'bank' | 'other',
  payout_account: props.kol.payout_account ?? '',
  shipping_recipient_name: props.kol.shipping_recipient_name ?? '',
  shipping_country: props.kol.shipping_country ?? '',
  shipping_province_state: props.kol.shipping_province_state ?? '',
  shipping_city: props.kol.shipping_city ?? '',
  shipping_postal_code: props.kol.shipping_postal_code ?? '',
  shipping_address_line_1: props.kol.shipping_address_line_1 ?? '',
  shipping_address_line_2: props.kol.shipping_address_line_2 ?? '',
  level: (props.kol.level ?? '') as '' | '头部' | '腰部' | '尾部' | '素人',
  city: props.kol.city ?? '',
  categories: (props.kol.categories ?? []).join(', '),
  products: (props.kol.products ?? []).join(', '),
  content_format: (props.kol.content_format ?? '') as '' | '图文' | '短视频' | '直播' | '图文+视频' | '混合',
  product_quantity: props.kol.product_quantity !== null ? String(props.kol.product_quantity) : '',
  store_count: props.kol.store_count !== null ? String(props.kol.store_count) : '',
  internal_contact: props.kol.internal_contact ?? '',
  mcn_agency: props.kol.mcn_agency ?? '',
  is_mcn_managed: props.kol.is_mcn_managed,
  social_accounts: props.kol.social_accounts.map(sa => ({
    id: sa.id as number | undefined,
    platform: sa.platform,
    handle: sa.handle ?? '',
    profile_url: sa.profile_url ?? '',
    is_active: sa.is_active,
    followers: sa.latest_metric?.followers !== null && sa.latest_metric?.followers !== undefined ? String(sa.latest_metric.followers) : '',
    raw_followers_value: sa.latest_metric?.raw_followers_value ?? '',
  })),
  sales_performance_entries: [] as Array<{
    order_count: string
    recorded_at: string
    source: string
    note: string
  }>,
})

const addSocialAccount = () => {
  form.social_accounts.push({ id: undefined, platform: 'instagram', handle: '', profile_url: '', is_active: true, followers: '', raw_followers_value: '' })
}
const removeSocialAccount = (index: number) => {
  form.social_accounts.splice(index, 1)
}

const addSalesPerformance = () => {
  form.sales_performance_entries.push({ order_count: '', recorded_at: new Date().toISOString().slice(0, 10), source: '', note: '' })
}
const removeSalesPerformance = (index: number) => {
  form.sales_performance_entries.splice(index, 1)
}

const submit = () => {
  form.transform(data => ({
    ...data,
    owner_id: data.owner_id ? Number(data.owner_id) : null,
    level: data.level || null,
    content_format: data.content_format || null,
    product_quantity: data.product_quantity !== '' ? Number(data.product_quantity) : null,
    store_count: data.store_count !== '' ? Number(data.store_count) : null,
    categories: data.categories.split(',').map(v => v.trim()).filter(Boolean),
    products: data.products.split(',').map(v => v.trim()).filter(Boolean),
    social_accounts: data.social_accounts.map(sa => ({
      ...sa,
      followers: sa.followers !== '' ? Number(sa.followers) : null,
    })),
    sales_performance_entries: data.sales_performance_entries
      .filter(e => e.order_count !== '')
      .map(e => ({ ...e, order_count: Number(e.order_count) })),
  })).patch(`/kol/library/${props.kol.id}`)
}

const platformOptions = [
  { value: 'instagram', label: 'Instagram' },
  { value: 'tiktok', label: 'TikTok' },
  { value: 'youtube', label: 'YouTube' },
  { value: 'xiaohongshu', label: '小红书' },
  { value: 'weibo', label: '微博' },
  { value: 'wechat', label: '微信' },
  { value: 'twitter', label: 'X/Twitter' },
  { value: 'facebook', label: 'Facebook' },
  { value: 'other', label: '其他' },
]

const breadcrumbs = [
  { label: 'BYL ERP', href: '/dashboard' },
  { label: '红人库', href: '/kol/library' },
  { label: props.kol.display_name, href: `/kol/library/${props.kol.id}` },
  '编辑',
]
</script>

<template>
  <Head :title="`编辑 ${kol.display_name} · BYL ERP`" />
  <AuthenticatedLayout :title="`编辑 ${kol.display_name}`" :breadcrumbs="breadcrumbs">
    <div class="erp-form-page">
      <div class="erp-form-content">

        <!-- Page header -->
        <div class="erp-form-header-row">
          <div>
            <p class="kol-kicker">编辑 KOL</p>
            <h1 class="erp-form-title">{{ kol.display_name }}</h1>
            <p class="erp-form-subtitle">修改红人资料、业务画像、联系与收货信息后点击「保存更改」</p>
          </div>
          <a :href="`/kol/library/${kol.id}`" class="erp-btn erp-btn-secondary">返回详情</a>
        </div>

        <form @submit.prevent="submit">

          <!-- Card 1: 基本信息 -->
          <div class="erp-form-card">
            <h2 class="erp-form-card-title">基本信息</h2>
            <div class="erp-form-grid">
              <div class="erp-form-field">
                <label class="erp-form-label">显示名称 <span class="erp-form-required">*</span></label>
                <input v-model="form.display_name" type="text" class="erp-input" :class="{ 'has-error': form.errors.display_name }" maxlength="255" required>
                <p v-if="form.errors.display_name" class="erp-field-error">{{ form.errors.display_name }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">法定名称</label>
                <input v-model="form.legal_name" type="text" class="erp-input" :class="{ 'has-error': form.errors.legal_name }" maxlength="255">
                <p v-if="form.errors.legal_name" class="erp-field-error">{{ form.errors.legal_name }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">国家/地区</label>
                <input v-model="form.country_code" type="text" class="erp-input" :class="{ 'has-error': form.errors.country_code }" maxlength="8" placeholder="例如：US、CN">
                <p v-if="form.errors.country_code" class="erp-field-error">{{ form.errors.country_code }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">语言</label>
                <input v-model="form.language" type="text" class="erp-input" :class="{ 'has-error': form.errors.language }" maxlength="16" placeholder="例如：zh、en">
                <p v-if="form.errors.language" class="erp-field-error">{{ form.errors.language }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">合作类型</label>
                <input v-model="form.cooperation_type" type="text" class="erp-input" :class="{ 'has-error': form.errors.cooperation_type }" maxlength="32">
                <p v-if="form.errors.cooperation_type" class="erp-field-error">{{ form.errors.cooperation_type }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">加入时间</label>
                <input v-model="form.joined_at" type="date" class="erp-input" :class="{ 'has-error': form.errors.joined_at }">
                <p v-if="form.errors.joined_at" class="erp-field-error">{{ form.errors.joined_at }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">状态 <span class="erp-form-required">*</span></label>
                <div class="erp-segmented">
                  <button type="button" class="erp-segmented-option" :class="{ selected: form.status === 'active' }" @click="form.status = 'active'">活跃</button>
                  <button type="button" class="erp-segmented-option" :class="{ selected: form.status === 'inactive' }" @click="form.status = 'inactive'">停用</button>
                </div>
                <p v-if="form.errors.status" class="erp-field-error">{{ form.errors.status }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">合作阶段 <span class="erp-form-required">*</span></label>
                <select v-model="form.relationship_stage" class="erp-select" :class="{ 'has-error': form.errors.relationship_stage }">
                  <option value="prospect">潜在</option>
                  <option value="contacted">已联系</option>
                  <option value="negotiating">谈判中</option>
                  <option value="cooperating">合作中</option>
                  <option value="long_term">长期合作</option>
                  <option value="paused">暂停</option>
                  <option value="ended">已结束</option>
                </select>
                <p v-if="form.errors.relationship_stage" class="erp-field-error">{{ form.errors.relationship_stage }}</p>
              </div>
              <div v-if="canManageViews" class="erp-form-field">
                <label class="erp-form-label">负责人</label>
                <select v-model="form.owner_id" class="erp-select" :class="{ 'has-error': form.errors.owner_id }">
                  <option value="">未分配</option>
                  <option v-for="o in ownerOptions" :key="o.value" :value="String(o.value)">{{ o.label }}</option>
                </select>
                <p v-if="form.errors.owner_id" class="erp-field-error">{{ form.errors.owner_id }}</p>
              </div>
            </div>
          </div>

          <!-- Card 2: 业务画像 -->
          <div class="erp-form-card">
            <h2 class="erp-form-card-title">业务画像</h2>
            <div class="erp-form-grid">
              <div class="erp-form-field">
                <label class="erp-form-label">等级</label>
                <select v-model="form.level" class="erp-select" :class="{ 'has-error': form.errors.level }">
                  <option value="">未分级</option>
                  <option value="头部">头部</option>
                  <option value="腰部">腰部</option>
                  <option value="尾部">尾部</option>
                  <option value="素人">素人</option>
                </select>
                <p v-if="form.errors.level" class="erp-field-error">{{ form.errors.level }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">城市</label>
                <input v-model="form.city" type="text" class="erp-input" :class="{ 'has-error': form.errors.city }" maxlength="128" placeholder="可选">
                <p v-if="form.errors.city" class="erp-field-error">{{ form.errors.city }}</p>
              </div>
              <div class="erp-form-field kol-span-2">
                <label class="erp-form-label">主营品类</label>
                <input v-model="form.categories" type="text" class="erp-input" placeholder="多个品类用逗号分隔，例如：美妆,穿搭">
              </div>
              <div class="erp-form-field kol-span-2">
                <label class="erp-form-label">主推产品</label>
                <input v-model="form.products" type="text" class="erp-input" placeholder="多个产品用逗号分隔">
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">内容形式</label>
                <select v-model="form.content_format" class="erp-select" :class="{ 'has-error': form.errors.content_format }">
                  <option value="">未指定</option>
                  <option value="图文">图文</option>
                  <option value="短视频">短视频</option>
                  <option value="直播">直播</option>
                  <option value="图文+视频">图文+视频</option>
                  <option value="混合">混合</option>
                </select>
                <p v-if="form.errors.content_format" class="erp-field-error">{{ form.errors.content_format }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">产品数量</label>
                <input v-model="form.product_quantity" type="number" min="0" class="erp-input" placeholder="可选">
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">店铺数量</label>
                <input v-model="form.store_count" type="number" min="0" class="erp-input" placeholder="可选">
              </div>
            </div>
          </div>

          <!-- Card 3: 联系方式 -->
          <div class="erp-form-card">
            <h2 class="erp-form-card-title">联系方式</h2>
            <div class="erp-form-grid">
              <div class="erp-form-field">
                <label class="erp-form-label">Email</label>
                <input v-model="form.contact_email" type="email" class="erp-input" :class="{ 'has-error': form.errors.contact_email }" maxlength="255" placeholder="可选">
                <p v-if="form.errors.contact_email" class="erp-field-error">{{ form.errors.contact_email }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">联系电话</label>
                <input v-model="form.contact_phone" type="text" class="erp-input" :class="{ 'has-error': form.errors.contact_phone }" maxlength="64" placeholder="可选">
                <p v-if="form.errors.contact_phone" class="erp-field-error">{{ form.errors.contact_phone }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">WhatsApp</label>
                <input v-model="form.contact_whatsapp" type="text" class="erp-input" :class="{ 'has-error': form.errors.contact_whatsapp }" maxlength="64" placeholder="可选">
                <p v-if="form.errors.contact_whatsapp" class="erp-field-error">{{ form.errors.contact_whatsapp }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">其他联系方式</label>
                <input v-model="form.other_contact" type="text" class="erp-input" :class="{ 'has-error': form.errors.other_contact }" maxlength="255" placeholder="可选">
                <p v-if="form.errors.other_contact" class="erp-field-error">{{ form.errors.other_contact }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">首选联系方式 <span class="erp-form-required">*</span></label>
                <select v-model="form.preferred_contact_method" class="erp-select" :class="{ 'has-error': form.errors.preferred_contact_method }">
                  <option value="phone">电话</option>
                  <option value="email">Email</option>
                  <option value="whatsapp">WhatsApp</option>
                  <option value="other">其他</option>
                </select>
                <p v-if="form.errors.preferred_contact_method" class="erp-field-error">{{ form.errors.preferred_contact_method }}</p>
              </div>
            </div>
          </div>

          <!-- Card 4: 税务与收款 -->
          <div class="erp-form-card">
            <h2 class="erp-form-card-title">税务与收款</h2>
            <p class="erp-form-card-helper">税务标识符和收款账户为敏感信息，不会显示在红人列表中。</p>
            <div class="erp-form-grid">
              <div class="erp-form-field">
                <label class="erp-form-label">税务标识符（Tax ID）</label>
                <input v-model="form.tax_id" type="text" class="erp-input" :class="{ 'has-error': form.errors.tax_id }" maxlength="128" placeholder="可选">
                <p v-if="form.errors.tax_id" class="erp-field-error">{{ form.errors.tax_id }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">收款方式</label>
                <select v-model="form.payout_method" class="erp-select" :class="{ 'has-error': form.errors.payout_method }">
                  <option value="">不指定</option>
                  <option value="paypal">PayPal</option>
                  <option value="bank">银行转账</option>
                  <option value="other">其他</option>
                </select>
                <p v-if="form.errors.payout_method" class="erp-field-error">{{ form.errors.payout_method }}</p>
              </div>
              <div class="erp-form-field kol-span-2">
                <label class="erp-form-label">收款账户</label>
                <input v-model="form.payout_account" type="text" class="erp-input" :class="{ 'has-error': form.errors.payout_account }" maxlength="512" placeholder="PayPal 邮箱 / 银行账号等，可选">
                <p v-if="form.errors.payout_account" class="erp-field-error">{{ form.errors.payout_account }}</p>
              </div>
            </div>
          </div>

          <!-- Card 5: 收件地址 -->
          <div class="erp-form-card">
            <h2 class="erp-form-card-title">收件地址</h2>
            <div class="erp-form-grid">
              <div class="erp-form-field">
                <label class="erp-form-label">收件人</label>
                <input v-model="form.shipping_recipient_name" type="text" class="erp-input" :class="{ 'has-error': form.errors.shipping_recipient_name }" maxlength="255" placeholder="可选">
                <p v-if="form.errors.shipping_recipient_name" class="erp-field-error">{{ form.errors.shipping_recipient_name }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">国家</label>
                <input v-model="form.shipping_country" type="text" class="erp-input" :class="{ 'has-error': form.errors.shipping_country }" maxlength="8" placeholder="例如：US">
                <p v-if="form.errors.shipping_country" class="erp-field-error">{{ form.errors.shipping_country }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">省/州</label>
                <input v-model="form.shipping_province_state" type="text" class="erp-input" :class="{ 'has-error': form.errors.shipping_province_state }" maxlength="128" placeholder="可选">
                <p v-if="form.errors.shipping_province_state" class="erp-field-error">{{ form.errors.shipping_province_state }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">城市</label>
                <input v-model="form.shipping_city" type="text" class="erp-input" :class="{ 'has-error': form.errors.shipping_city }" maxlength="128" placeholder="可选">
                <p v-if="form.errors.shipping_city" class="erp-field-error">{{ form.errors.shipping_city }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">邮政编码</label>
                <input v-model="form.shipping_postal_code" type="text" class="erp-input" :class="{ 'has-error': form.errors.shipping_postal_code }" maxlength="32" placeholder="可选">
                <p v-if="form.errors.shipping_postal_code" class="erp-field-error">{{ form.errors.shipping_postal_code }}</p>
              </div>
              <div class="erp-form-field kol-span-2">
                <label class="erp-form-label">地址行 1</label>
                <input v-model="form.shipping_address_line_1" type="text" class="erp-input" :class="{ 'has-error': form.errors.shipping_address_line_1 }" maxlength="255" placeholder="可选">
                <p v-if="form.errors.shipping_address_line_1" class="erp-field-error">{{ form.errors.shipping_address_line_1 }}</p>
              </div>
              <div class="erp-form-field kol-span-2">
                <label class="erp-form-label">地址行 2</label>
                <input v-model="form.shipping_address_line_2" type="text" class="erp-input" :class="{ 'has-error': form.errors.shipping_address_line_2 }" maxlength="255" placeholder="可选">
                <p v-if="form.errors.shipping_address_line_2" class="erp-field-error">{{ form.errors.shipping_address_line_2 }}</p>
              </div>
            </div>
          </div>

          <!-- Card 6: 社交账号 -->
          <div class="erp-form-card">
            <div class="kol-card-header-row">
              <h2 class="erp-form-card-title">社交账号</h2>
              <button type="button" class="erp-btn erp-btn-secondary" @click="addSocialAccount">添加账号</button>
            </div>
            <p v-if="form.social_accounts.length === 0" class="kol-empty-state">暂无社交账号</p>
            <div v-for="(sa, idx) in form.social_accounts" :key="idx" class="kol-sa-row">
              <div class="erp-form-field">
                <label class="erp-form-label">平台</label>
                <select v-model="sa.platform" class="erp-select">
                  <option v-for="p in platformOptions" :key="p.value" :value="p.value">{{ p.label }}</option>
                </select>
                <p v-if="(form.errors as Record<string, string>)[`social_accounts.${idx}.platform`]" class="erp-field-error">{{ (form.errors as Record<string, string>)[`social_accounts.${idx}.platform`] }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">账号名 / Handle</label>
                <input v-model="sa.handle" type="text" class="erp-input" placeholder="可选">
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">主页链接</label>
                <input v-model="sa.profile_url" type="url" class="erp-input" placeholder="可选">
                <p v-if="(form.errors as Record<string, string>)[`social_accounts.${idx}.profile_url`]" class="erp-field-error">{{ (form.errors as Record<string, string>)[`social_accounts.${idx}.profile_url`] }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">粉丝数</label>
                <input v-model="sa.followers" type="number" min="0" class="erp-input" placeholder="整数，可选">
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">原始值</label>
                <input v-model="sa.raw_followers_value" type="text" class="erp-input" maxlength="32" placeholder="如 318.2K">
              </div>
              <div class="erp-form-field kol-sa-status">
                <label class="erp-form-label">状态</label>
                <div class="erp-segmented">
                  <button type="button" class="erp-segmented-option" :class="{ selected: sa.is_active }" @click="sa.is_active = true">启用</button>
                  <button type="button" class="erp-segmented-option" :class="{ selected: !sa.is_active }" @click="sa.is_active = false">停用</button>
                </div>
              </div>
              <div class="kol-sa-remove">
                <button type="button" class="erp-btn erp-btn-secondary" @click="removeSocialAccount(idx)">删除</button>
              </div>
            </div>
          </div>

          <!-- Card 7: 历史带货记录 -->
          <div class="erp-form-card">
            <div class="kol-card-header-row">
              <h2 class="erp-form-card-title">历史带货记录</h2>
              <button type="button" class="erp-btn erp-btn-secondary" @click="addSalesPerformance">添加记录</button>
            </div>
            <p class="erp-form-card-helper">历史带货数量（当前：{{ kol.historical_order_count }}）为已保存记录之和；已保存记录不可编辑，新增记录会累加到总数中。</p>
            <div v-if="kol.sales_performance_history.length > 0" class="kol-perf-history">
              <div v-for="event in kol.sales_performance_history" :key="event.id" class="kol-perf-history-row">
                <span class="kol-perf-count">{{ event.order_count }}</span>
                <span>{{ event.recorded_at ?? '—' }}</span>
                <span>{{ event.source ?? '—' }}</span>
                <span class="kol-perf-note">{{ event.note ?? '' }}</span>
              </div>
            </div>
            <p v-if="form.sales_performance_entries.length === 0" class="kol-empty-state">点击「添加记录」新增带货事件。</p>
            <div v-for="(perf, idx) in form.sales_performance_entries" :key="idx" class="kol-perf-row">
              <div class="erp-form-field">
                <label class="erp-form-label">数量</label>
                <input v-model="perf.order_count" type="number" min="1" class="erp-input" placeholder="订单数量">
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">日期</label>
                <input v-model="perf.recorded_at" type="date" class="erp-input">
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">来源</label>
                <input v-model="perf.source" type="text" class="erp-input" maxlength="64" placeholder="可选">
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">备注</label>
                <input v-model="perf.note" type="text" class="erp-input" maxlength="1000" placeholder="可选">
              </div>
              <div class="kol-sa-remove">
                <button type="button" class="erp-btn erp-btn-secondary" @click="removeSalesPerformance(idx)">删除</button>
              </div>
            </div>
          </div>

          <!-- Card 8: 内部运营信息 -->
          <div class="erp-form-card">
            <h2 class="erp-form-card-title">内部运营信息</h2>
            <div class="erp-form-grid">
              <div class="erp-form-field">
                <label class="erp-form-label">内部联系人</label>
                <input v-model="form.internal_contact" type="text" class="erp-input" maxlength="255" placeholder="可选">
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">MCN 机构</label>
                <input v-model="form.mcn_agency" type="text" class="erp-input" maxlength="255" placeholder="可选">
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">是否 MCN 管理</label>
                <div class="erp-segmented">
                  <button type="button" class="erp-segmented-option" :class="{ selected: form.is_mcn_managed }" @click="form.is_mcn_managed = true">是</button>
                  <button type="button" class="erp-segmented-option" :class="{ selected: !form.is_mcn_managed }" @click="form.is_mcn_managed = false">否</button>
                </div>
              </div>
            </div>
          </div>

          <!-- Card 9: 备注 -->
          <div class="erp-form-card">
            <h2 class="erp-form-card-title">备注</h2>
            <div class="erp-form-field">
              <label class="erp-form-label">备注内容</label>
              <textarea v-model="form.notes" class="erp-textarea" maxlength="5000" placeholder="可选" />
              <p v-if="form.errors.notes" class="erp-field-error">{{ form.errors.notes }}</p>
            </div>
          </div>

          <!-- Action bar -->
          <div class="erp-form-actions kol-action-bar">
            <a :href="`/kol/library/${kol.id}`" class="erp-btn erp-btn-secondary">取消</a>
            <button type="submit" class="erp-btn erp-btn-primary" :disabled="form.processing">
              {{ form.processing ? '保存中…' : '保存更改' }}
            </button>
          </div>

        </form>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<style scoped>
.kol-kicker {
  font-size: 13px;
  color: #7a8c94;
  margin: 0 0 4px;
}

.kol-card-header-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}

.kol-card-header-row .erp-form-card-title {
  margin: 0;
}

.kol-empty-state {
  font-size: 13px;
  color: #7a8c94;
  padding: 8px 0;
}

.kol-sa-row {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr 1fr 1fr auto auto;
  gap: 12px;
  align-items: end;
  padding: 12px 0;
  border-top: 1px solid #e4eaed;
}

.kol-sa-row:first-of-type {
  border-top: none;
}

.kol-perf-row {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr 2fr auto;
  gap: 12px;
  align-items: end;
  padding: 12px 0;
  border-top: 1px solid #e4eaed;
}

.kol-perf-row:first-of-type {
  border-top: none;
}

.kol-perf-history {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-bottom: 8px;
}

.kol-perf-history-row {
  display: grid;
  grid-template-columns: 60px 100px 100px 1fr;
  gap: 12px;
  font-size: 13px;
  padding: 4px 0;
  border-bottom: 1px solid #f1f5f9;
}

.kol-perf-count {
  font-weight: 600;
}

.kol-perf-note {
  color: #64748b;
}

.kol-sa-status {
  min-width: 100px;
}

.kol-sa-remove {
  display: flex;
  align-items: flex-end;
  padding-bottom: 0;
}

.kol-span-2 {
  grid-column: span 2;
}

.kol-action-bar {
  justify-content: flex-end;
}

@media (max-width: 767px) {
  .kol-span-2 {
    grid-column: span 1;
  }

  .kol-sa-row,
  .kol-perf-row,
  .kol-perf-history-row {
    grid-template-columns: 1fr;
  }
}
</style>
