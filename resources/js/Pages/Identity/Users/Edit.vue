<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type UserDetail = {
  id: number
  name: string
  email: string
  is_active: boolean
  role: { key: string; label: string; preset: string | null } | null
}
const props = defineProps<{ user: UserDetail; rolePresets: { value: string; label: string }[]; isSelf: boolean }>()

const profileForm = useForm({ name: props.user.name, email: props.user.email })
const submitProfile = () => profileForm.patch(`/settings/users/${props.user.id}`)

const passwordForm = useForm({ password: '', password_confirmation: '' })
const submitPassword = () => passwordForm.post(`/settings/users/${props.user.id}/password`, {
  onSuccess: () => passwordForm.reset(),
})

const roleForm = useForm({ role: props.user.role?.preset ?? '' })
const submitRole = () => roleForm.post(`/settings/users/${props.user.id}/role`)
</script>
<template>
  <Head title="编辑用户 · BYL ERP" />
  <AuthenticatedLayout title="编辑用户" :breadcrumbs="['BYL ERP', '设置', '用户', user.name, '编辑']">
    <section class="mvp-page">
      <div class="mvp-page-heading"><div><p class="mvp-kicker">系统设置</p><h2>编辑 {{ user.name }}</h2></div></div>

      <form class="mvp-form" @submit.prevent="submitProfile">
        <h3>基本信息</h3>
        <div class="mvp-form-grid">
          <label>姓名<input v-model="profileForm.name" required></label>
          <span v-if="profileForm.errors.name" class="mvp-field-error">{{ profileForm.errors.name }}</span>
          <label>邮箱<input v-model="profileForm.email" type="email" required></label>
          <span v-if="profileForm.errors.email" class="mvp-field-error">{{ profileForm.errors.email }}</span>
        </div>
        <div class="mvp-form-actions"><button class="mvp-button mvp-button-primary" :disabled="profileForm.processing">保存基本信息</button></div>
      </form>

      <form class="mvp-form" @submit.prevent="submitPassword">
        <h3>设置新密码</h3>
        <p>出于安全考虑，系统不会显示或找回原密码，只能设置一个新密码。</p>
        <div class="mvp-form-grid">
          <label>新密码<input v-model="passwordForm.password" type="password" autocomplete="new-password"></label>
          <span v-if="passwordForm.errors.password" class="mvp-field-error">{{ passwordForm.errors.password }}</span>
          <label>确认新密码<input v-model="passwordForm.password_confirmation" type="password" autocomplete="new-password"></label>
        </div>
        <div class="mvp-form-actions"><button class="mvp-button mvp-button-primary" :disabled="passwordForm.processing">更新密码</button></div>
      </form>

      <form class="mvp-form" @submit.prevent="submitRole">
        <h3>角色</h3>
        <div class="mvp-form-grid">
          <label>角色
            <select v-model="roleForm.role" required>
              <option value="" disabled>请选择角色</option>
              <option v-for="preset in rolePresets" :key="preset.value" :value="preset.value">{{ preset.label }}</option>
            </select>
          </label>
          <span v-if="roleForm.errors.role" class="mvp-field-error">{{ roleForm.errors.role }}</span>
        </div>
        <div class="mvp-form-actions"><button class="mvp-button mvp-button-primary" :disabled="roleForm.processing">更新角色</button></div>
      </form>

      <p v-if="isSelf" class="mvp-field-error">提示：管理员不能停用或删除自己的账号，请返回用户列表操作其他账号。</p>
      <a class="mvp-button" href="/settings/users">返回用户列表</a>
    </section>
  </AuthenticatedLayout>
</template>
