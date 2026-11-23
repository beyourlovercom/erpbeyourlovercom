<script setup lang="ts">
import { ref } from 'vue';
import { message } from 'ant-design-vue';
import { useAuthStore } from '#/store';
import { useRouter } from 'vue-router';
import { resetAllStores } from '@vben/stores';
import { loginCanonicalErp } from '#/api/erp-laravel-auth';
import { canonicalHomePath, clearAuthorizedDevNavigation, seedCanonicalVerifiedLogin } from '#/router/erp-live-navigation';
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
      const verifiedIdentity = await loginCanonicalErp(username.value.trim(), password.value);
      clearHotOrderSnapshots();
      clearAuthorizedDevNavigation();
      resetAllStores();
      seedCanonicalVerifiedLogin(verifiedIdentity);
      await router.replace(canonicalHomePath(verifiedIdentity));
    } else {
      await store.authLogin({ username: username.value.trim().toLowerCase(), password: password.value });
    }
    password.value = '';
  } catch {
    message.error(canonicalMode
      ? '隔离 Dev 登录失败：此环境仅接受已创建的 Dev 测试身份，原 ERP 正式员工账号尚未接入'
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
      <a-alert v-if="canonicalMode" class="mb-5" show-icon type="warning" message="ERP2 隔离 Dev：目前仅接受 Dev 测试账号，原 ERP 正式账号密码不能直接登录。所有业务数据均为测试数据。" />
      <a-alert v-else class="mb-5" show-icon type="info" message="本地隔离 UAT：仅合成订单，正式 ERP 未连接" />
      <a-form layout="vertical">
        <a-form-item :label="canonicalMode ? '隔离 Dev 测试账号 / 邮箱' : 'ERP 本地登录账号'">
          <a-input v-model:value="username" size="large" autocomplete="username"  :placeholder="canonicalMode ? '如 kaven-r2c@local.test' : 'Owner 为 owner'" />
        </a-form-item>
        <a-form-item :label="canonicalMode ? '隔离 Dev 测试密码' : username==='owner' ? 'ERP 本地管理密钥' : '用户密码'">
          <a-input-password v-model:value="password" size="large" autocomplete="current-password" :placeholder="canonicalMode ? '请输入对应 Dev 账号的测试密码' : username==='owner' ? '请输入管理密钥' : '请输入用户密码'" />
        </a-form-item>
        <a-button type="primary" size="large" block :loading="loading" @click="submit">
          进入 ERP 开发工作台
        </a-button>
      </a-form>
      <p class="mt-5 text-xs leading-5 text-slate-500">
        {{ canonicalMode ? 'Dev 测试凭证仅发往隔离 Laravel 会话端点，不保存到浏览器本地权限存储；不得在此输入正式 ERP 员工密码。' : '本地独立密钥位于 D:\\ai\\erp-siteops\\private\\local-admin-key.txt；不使用原 SiteOps 管理密钥。' }}
      </p>
    </a-card>
  </div>
</template>