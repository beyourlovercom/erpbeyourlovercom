<script setup lang="ts">
import { ref } from 'vue';
import { message } from 'ant-design-vue';
import { useAuthStore } from '#/store';
import { useRouter } from 'vue-router';
import { resetAllStores } from '@vben/stores';
import { loginCanonicalErp } from '#/api/erp-laravel-auth';
import { clearAuthorizedDevNavigation } from '#/router/erp-live-navigation';
import { clearHotOrderSnapshots } from '#/api/erp-hot-snapshots';

const canonicalMode = import.meta.env.VITE_ERP_IDENTITY_MODE === 'laravel';
const username = ref(canonicalMode ? '' : 'owner');
const password = ref('');
const loading = ref(false);
const store = useAuthStore();
const router = useRouter();

async function submit() {
  if (!username.value.trim()||!password.value) return void message.warning('请输入账号与密码');
  loading.value = true;
  try {
    if (canonicalMode) {
      // The original Laravel session and real employee ID are mandatory.
      await loginCanonicalErp(username.value.trim(), password.value);
      clearHotOrderSnapshots();
      clearAuthorizedDevNavigation();
      resetAllStores();
      await router.replace('/erp/orders/orders-master');
    } else {
      await store.authLogin({ username: username.value.trim().toLowerCase(), password: password.value });
    }
    password.value = '';
  } catch {
    message.error(canonicalMode
      ? '原 ERP 登录或 Dev 真实权限校验失败，请核对账号及会话状态'
      : '登录验证失败，请核对本地账号、密码与权限状态');
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
      <a-alert v-if="canonicalMode" class="mb-5" show-icon type="info" message="ERP2 Dev：使用原 ERP 员工账号和 Laravel 会话，所有操作权限以服务器为准" />
      <a-alert v-else class="mb-5" show-icon type="info" message="本地隔离 UAT：仅合成订单，正式 ERP 未连接" />
      <a-form layout="vertical">
        <a-form-item :label="canonicalMode ? '原 ERP 员工账号或邮箱' : 'ERP 本地登录账号'">
          <a-input v-model:value="username" size="large" autocomplete="username"  :placeholder="canonicalMode ? '如 vita@beyourlover.com' : 'Owner 为 owner'" />
        </a-form-item>
        <a-form-item :label="canonicalMode ? '原 ERP 密码' : username==='owner' ? 'ERP 本地管理密钥' : '用户密码'">
          <a-input-password v-model:value="password" size="large" autocomplete="current-password" :placeholder="canonicalMode ? '请输入原 ERP 员工密码' : username==='owner' ? '请输入管理密钥' : '请输入用户密码'" />
        </a-form-item>
        <a-button type="primary" size="large" block :loading="loading" @click="submit">
          进入 ERP 开发工作台
        </a-button>
      </a-form>
      <p class="mt-5 text-xs leading-5 text-slate-500">
        {{ canonicalMode ? '密码仅发送至同源 Laravel 登录接口，不保存到 Dev 配置或浏览器本地权限存储。' : '本地独立密钥位于 D:\\ai\\erp-siteops\\private\\local-admin-key.txt；不使用原 SiteOps 管理密钥。' }}
      </p>
    </a-card>
  </div>
</template>