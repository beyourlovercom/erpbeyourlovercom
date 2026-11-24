<script setup lang="ts">
import {ref} from 'vue'
import {parseKolQuickOrder,type QuickOrderDraft} from '../../lib/kol/kolQuickOrderParser'
const emit=defineEmits<{apply:[QuickOrderDraft]}>()
const raw=ref('')
const preview=ref<QuickOrderDraft|null>(null)
const labels:Record<string,string>={recipient_name:'收件人',country:'收件国家',province_state:'州/省',city:'城市',address_line_1:'地址行 1',address_line_2:'地址行 2',postal_code:'邮编',email:'邮箱',phone:'电话',recipient_customs_id:'清关税号',coupon_code:'折扣码',product_hint:'产品候选（需核对 SKU）',username_hint:'Username（待核对平台）'}
const parse=()=>{preview.value=parseKolQuickOrder(raw.value)}
</script>
<template>
 <section class="erp-form-card" data-testid="kol-smart-input">
  <h2 class="erp-form-card-title">智能录入网红信息</h2>
  <label class="erp-form-label" for="kol-smart-raw">粘贴原始合作资料</label>
  <textarea id="kol-smart-raw" v-model="raw" class="erp-textarea" rows="6" maxlength="16000" data-testid="kol-smart-raw" @input="preview=null" placeholder="社媒链接、Username、收件地址、联系方式、产品、折扣码" />
  <p class="erp-form-helper">本地规则识别，不上传原文。无法确定的信息请人工填写。</p>
  <button type="button" class="erp-btn erp-btn-secondary" data-testid="kol-smart-parse" :disabled="!raw.trim()" @click="parse">识别信息</button>
  <div v-if="preview" data-testid="kol-smart-preview">
   <div v-for="account in preview.social_accounts" :key="account.profile_url">{{account.platform}} · {{account.handle}} · {{account.followers ?? '粉丝数待确认'}}</div>
   <template v-for="(label,key) in labels" :key="key">
    <p v-if="(preview as unknown as Record<string,unknown>)[key] !== undefined">{{label}}：{{(preview as unknown as Record<string,unknown>)[key]}}</p>
   </template>
   <div v-if="preview.unparsed.length" role="status">
    <strong>未识别或冲突信息（请核对）</strong>
    <p v-for="(line,index) in preview.unparsed" :key="index">{{line}}</p>
   </div>
   <button type="button" class="erp-btn erp-btn-primary" data-testid="kol-smart-apply" @click="emit('apply',preview)">确认填充</button>
  </div>
 </section>
</template>
