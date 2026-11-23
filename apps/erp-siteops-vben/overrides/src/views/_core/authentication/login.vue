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
      ? 'ERP2 Dev 登录未通过：请核对原 ERP 员工账号、密码及账号状态'
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
      <a-alert v-if="canonicalMode" class="mb-5" show-icon type="warning" message="ERP2 隔离 Dev：已接入 ERP1 同构数据副本。可使用原 ERP 员工账号登录；数据操作仅影响 Dev，不会直接发货到真实仓库。" />
      <a-alert v-else class="mb-5" show-icon type="info" message="本地隔离 UAT：仅合成订单，正式 ERP 未连接" />
      <a-form layout="vertical">
        <a-form-item :label="canonicalMode ? '原 ERP 员工用户名 / 邮箱' : 'ERP 本地登录账号'">
          <a-input v-model:value="username" size="large" autocomplete="username"  :placeholder="canonicalMode ? '例如 dede / liang / vita' : 'Owner 为 owner'" />
        </a-form-item>
        <a-form-item :label="canonicalMode ? '原 ERP 员工密码' : username==='owner' ? 'ERP 本地管理密钥' : '用户密码'">
          <a-input-password v-model:value="password" size="large" autocomplete="current-password" :placeholder="canonicalMode ? '请输入原 ERP 员工账号的密码' : username==='owner' ? '请输入管理密钥' : '请输入用户密码'" />
        </a-form-item>
        <a-button type="primary" size="large" block :loading="loading" @click="submit">
          进入 ERP 开发工作台
        </a-button>
      </a-form>
      <p class="mt-5 text-xs leading-5 text-slate-500">
        {{ canonicalMode ? '员工密码仅提交至 ERP2 独立 Laravel Dev 会话端点；ERP1 正式账号和业务数据不因这里的登录而修改。' : '本地独立密钥位于 D:\\ai\\erp-siteops\\private\\local-admin-key.txt；不使用原 SiteOps 管理密钥。' }}
      </p>
    </a-card>
  </div>
</template>