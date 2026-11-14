<script setup lang="ts">
import { ref } from 'vue';
import { message } from 'ant-design-vue';
import { useAuthStore } from '#/store';

const username = ref('owner');
const password = ref('');
const loading = ref(false);
const store = useAuthStore();

async function submit() {
  if (!username.value.trim()||!password.value) return void message.warning('请输入账号与密码');
  loading.value = true;
  try {
    await store.authLogin({ username: username.value.trim().toLowerCase(), password: password.value });
    password.value = '';
  } catch {
    message.error('登录验证失败，请核对本地账号、密码与权限状态');
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <div class="mx-auto flex min-h-[90vh] w-full max-w-md items-center justify-center px-4">
    <a-card :bordered="false" class="w-full shadow-md">
      <div class="mb-6 text-center">
        <div class="text-[26px] font-bold tracking-tight">BYL ERP SiteOps</div>
        <div class="mt-2 text-sm text-slate-500">Vben ERP 工作台 · 007windows</div>
      </div>
      <a-alert class="mb-5" show-icon type="info" message="本地隔离 UAT：仅合成订单，正式 ERP 未连接" />
      <a-form layout="vertical">
        <a-form-item label="ERP 本地登录账号">
          <a-input v-model:value="username" size="large" autocomplete="username" placeholder="Owner 为 owner" />
        </a-form-item>
        <a-form-item :label="username==='owner'?'ERP 本地管理密钥':'用户密码'">
          <a-input-password v-model:value="password" size="large" autocomplete="current-password" :placeholder="username==='owner'?'请输入管理密钥':'请输入用户密码'" />
        </a-form-item>
        <a-button type="primary" size="large" block :loading="loading" @click="submit">
          进入 ERP 开发工作台
        </a-button>
      </a-form>
      <p class="mt-5 text-xs leading-5 text-slate-500">
        本地独立密钥位于 D:\ai\erp-siteops\private\local-admin-key.txt；不使用原 SiteOps 管理密钥。
      </p>
    </a-card>
  </div>
</template>