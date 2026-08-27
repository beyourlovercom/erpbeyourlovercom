<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { reactive, ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type OwnerOption = { value: number; label: string }
type SocialMetric = { followers: number | null; raw_followers_value: string | null; measured_at: string | null }
type SocialAccount = { id: number; platform: string; handle: string | null; profile_url: string | null; is_active: boolean; latest_metric: SocialMetric | null }
type KolDetail = {
  id: number; display_name: string; legal_name: string | null; country_code: string | null; language: string | null
  status: string; relationship_stage: string; cooperation_type: string | null; owner_id: number | null
  joined_at: string | null; notes: string | null; social_accounts: SocialAccount[]
}

const props = defineProps<{
  kol: KolDetail
  ownerOptions: OwnerOption[]
  canManageViews: boolean
}>()

type SocialAccountForm = {
  id?: number
  platform: string
  handle: string
  profile_url: string
  is_active: boolean
  followers: string
  raw_followers_value: string
}

const form = reactive({
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
  social_accounts: props.kol.social_accounts.map((sa): SocialAccountForm => ({
    id: sa.id,
    platform: sa.platform,
    handle: sa.handle ?? '',
    profile_url: sa.profile_url ?? '',
    is_active: sa.is_active,
    followers: sa.latest_metric?.followers !== null && sa.latest_metric?.followers !== undefined ? String(sa.latest_metric.followers) : '',
    raw_followers_value: sa.latest_metric?.raw_followers_value ?? '',
  })),
})

const errors = ref<Record<string, string>>({})
const submitting = ref(false)

const addSocialAccount = () => {
  form.social_accounts.push({ platform: 'instagram', handle: '', profile_url: '', is_active: true, followers: '', raw_followers_value: '' })
}
const removeSocialAccount = (index: number) => {
  form.social_accounts.splice(index, 1)
}

const submit = () => {
  submitting.value = true
  errors.value = {}
  router.patch(`/kol/library/${props.kol.id}`, {
    ...form,
    owner_id: form.owner_id ? Number(form.owner_id) : null,
    social_accounts: form.social_accounts.map(sa => ({
      ...sa,
      followers: sa.followers !== '' ? Number(sa.followers) : null,
    })),
  }, {
    onError: (e) => { errors.value = e; submitting.value = false },
    onSuccess: () => { submitting.value = false },
  })
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

const breadcrumbs = [{ label: 'BYL ERP', href: '/dashboard' }, { label: '红人库', href: '/kol/library' }, { label: props.kol.display_name, href: `/kol/library/${props.kol.id}` }, '编辑']
</script>

<template>
  <Head :title="`编辑 ${kol.display_name} · BYL ERP`" />
  <AuthenticatedLayout :title="`编辑 ${kol.display_name}`" :breadcrumbs="breadcrumbs">
    <section class="mvp-page">
      <div class="mvp-page-heading">
        <div>
          <p class="mvp-kicker">编辑 KOL</p>
          <h2>{{ kol.display_name }}</h2>
        </div>
      </div>

      <form class="kol-form" @submit.prevent="submit">
        <div class="mvp-card form-section">
          <h3>基本信息</h3>
          <div class="form-grid">
            <label>显示名称 <span class="required">*</span>
              <input v-model="form.display_name" type="text" required maxlength="255">
              <span v-if="errors.display_name" class="field-error">{{ errors.display_name }}</span>
            </label>
            <label>法定名称<input v-model="form.legal_name" type="text" maxlength="255"></label>
            <label>国家/地区<input v-model="form.country_code" type="text" maxlength="8" placeholder="US、CN"></label>
            <label>语言<input v-model="form.language" type="text" maxlength="16" placeholder="zh、en"></label>
            <label>合作类型<input v-model="form.cooperation_type" type="text" maxlength="32"></label>
            <label>加入时间<input v-model="form.joined_at" type="date"></label>
          </div>
        </div>

        <div class="mvp-card form-section">
          <h3>状态与阶段</h3>
          <div class="form-grid">
            <label>状态 <span class="required">*</span>
              <select v-model="form.status">
                <option value="active">活跃</option>
                <option value="inactive">停用</option>
              </select>
            </label>
            <label>关系阶段 <span class="required">*</span>
              <select v-model="form.relationship_stage">
                <option value="prospect">潜在</option>
                <option value="contacted">已联系</option>
                <option value="negotiating">谈判中</option>
                <option value="cooperating">合作中</option>
                <option value="long_term">长期合作</option>
                <option value="paused">暂停</option>
                <option value="ended">已结束</option>
              </select>
            </label>
            <label v-if="canManageViews">负责人
              <select v-model="form.owner_id">
                <option value="">未分配</option>
                <option v-for="o in ownerOptions" :key="o.value" :value="String(o.value)">{{ o.label }}</option>
              </select>
            </label>
          </div>
        </div>

        <div class="mvp-card form-section">
          <div class="section-heading">
            <h3>社交账号</h3>
            <button type="button" class="mvp-button" @click="addSocialAccount">+ 添加账号</button>
          </div>
          <div v-if="form.social_accounts.length === 0" class="empty-state">暂无社交账号</div>
          <div v-for="(sa, idx) in form.social_accounts" :key="idx" class="social-account-row">
            <select v-model="sa.platform">
              <option v-for="p in platformOptions" :key="p.value" :value="p.value">{{ p.label }}</option>
            </select>
            <input v-model="sa.handle" type="text" placeholder="账号名">
            <input v-model="sa.profile_url" type="url" placeholder="主页链接">
            <input v-model="sa.followers" type="number" min="0" placeholder="粉丝数">
            <input v-model="sa.raw_followers_value" type="text" maxlength="32" placeholder="原始值（如 318.2K）">
            <label class="active-check"><input v-model="sa.is_active" type="checkbox"> 启用</label>
            <button type="button" class="mvp-button btn-remove" @click="removeSocialAccount(idx)">删除</button>
          </div>
        </div>

        <div class="mvp-card form-section">
          <h3>备注</h3>
          <textarea v-model="form.notes" rows="4" maxlength="5000"></textarea>
        </div>

        <div class="form-actions">
          <a class="mvp-button" :href="`/kol/library/${kol.id}`">取消</a>
          <button class="mvp-button mvp-button-primary" type="submit" :disabled="submitting">
            {{ submitting ? '保存中…' : '保存更改' }}
          </button>
        </div>
      </form>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.kol-form { display: flex; flex-direction: column; gap: 16px; }
.form-section { display: flex; flex-direction: column; gap: 12px; }
.form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
.form-grid label, .form-section label { display: flex; flex-direction: column; gap: 4px; font-size: 13px; }
.form-grid input, .form-grid select, .form-section select, .form-section input { padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px; }
.form-section textarea { padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px; resize: vertical; }
.required { color: #dc2626; }
.field-error { color: #dc2626; font-size: 12px; }
.section-heading { display: flex; justify-content: space-between; align-items: center; }
.empty-state { font-size: 13px; color: #64748b; }
.social-account-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding: 10px; background: #f8fafc; border-radius: 8px; margin-top: 8px; }
.social-account-row select, .social-account-row input { padding: 6px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px; flex: 1; min-width: 100px; }
.active-check { flex-direction: row !important; align-items: center; gap: 4px; font-size: 13px; }
.btn-remove { color: #dc2626; border-color: #dc2626; }
.form-actions { display: flex; gap: 8px; justify-content: flex-end; }
</style>
