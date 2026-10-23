<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
type Warehouse = { id:number;code:string;name:string;country_code:string|null;is_active:boolean;url:string }
defineProps<{warehouses:Warehouse[];autoDispatchEnabled:boolean}>()
</script>
<template>
  <Head title="仓库设置 · BYL ERP" />
  <AuthenticatedLayout title="设置" :breadcrumbs="['BYL ERP','设置','仓库设置']">
    <section class="mvp-page warehouse-settings-page">
      <div class="mvp-page-heading"><div><p class="mvp-kicker">WAREHOUSE SETTINGS</p><h2>仓库设置</h2><p>复用现有仓库资料和地址编辑页面；OpenAPI 位于每个仓库的「配置」标签内。</p></div><Link class="mvp-button" href="/settings">返回设置</Link></div>
      <article class="mvp-card">
        <h3>已有仓库</h3>
        <p>选择仓库后，可编辑地址、服务地区与 OpenAPI。相同 SKU 直接使用，不建立物流渠道映射表；自动发货保持关闭。</p>
        <div v-for="w in warehouses" :key="w.id" class="warehouse-row">
          <div><strong>{{w.name}}</strong><small>{{w.code}} · {{w.country_code||'未填写国家'}} · {{w.is_active?'启用':'停用'}}</small></div>
          <Link class="mvp-button mvp-button-primary" :href="w.url">配置仓库 →</Link>
        </div>
        <p v-if="warehouses.length===0">暂无仓库资料，可从库存模块建立仓库后再统一配置。</p>
      </article>
    </section>
  </AuthenticatedLayout>
</template>
<style scoped>
.warehouse-settings-page{max-width:1120px}.warehouse-row{display:flex;align-items:center;justify-content:space-between;gap:14px;border-top:1px solid #e0e6e1;padding:15px 0}.warehouse-row>div{display:grid;gap:5px}.warehouse-row small{color:#637067;font-size:12px}
</style>
