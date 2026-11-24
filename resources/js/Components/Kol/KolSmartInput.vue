<script setup lang="ts">
import {computed,ref} from 'vue'
import {parseKolQuickOrder,type QuickOrderDraft} from '../../lib/kol/kolQuickOrderParser'

const emit=defineEmits<{apply:[QuickOrderDraft]}>()
const raw=ref('')
const result=ref<QuickOrderDraft|null>(null)
const identity=computed(()=>result.value?.social_accounts.length===1?result.value.social_accounts[0].handle:result.value?.username_hint)
const recognized=computed(()=>result.value ? Object.entries(result.value).filter(([key,value])=>key!=='unparsed'&&key!=='social_accounts'&&typeof value==='string'&&value.length>0).length+result.value.social_accounts.length :0)
const parse=()=>{
  if(!raw.value.trim())return
  result.value=parseKolQuickOrder(raw.value)
  emit('apply',result.value)
}
</script>
<template>
  <section class="erp-form-card kol-quick-input" data-testid="kol-smart-input">
    <div class="kol-quick-heading">
      <h2 class="erp-form-card-title">网红检索与快捷录入</h2>
      <span class="kol-quick-tag">本地规则</span>
    </div>
    <label class="erp-form-label" for="kol-smart-raw">Username 或完整合作资料</label>
    <textarea id="kol-smart-raw" v-model="raw" class="erp-textarea kol-quick-text" rows="5" maxlength="16000" data-testid="kol-smart-raw" @input="result=null" placeholder="直接输入 Username，或者粘贴社媒链接、收件地址、联系方式、产品及折扣码…" />
    <div class="kol-quick-actions">
      <p class="erp-form-helper">识别成功的内容带入表单，剩余内容可手动填写。不上传原文至 AI。</p>
      <button type="button" class="erp-btn erp-btn-primary" data-testid="kol-smart-parse" :disabled="!raw.trim()" @click="parse">识别并检索</button>
    </div>
    <div v-if="result" data-testid="kol-smart-preview" class="kol-quick-result" role="status">
      <strong v-if="identity">识别账号：@{{identity}} · </strong><span>识别到 {{recognized}} 项，请核对下方表单。</span>
      <details v-if="result.unparsed.length" class="kol-quick-unparsed" open>
        <summary>有 {{result.unparsed.length}} 项未识别或冲突信息，可人工补充</summary>
        <p v-for="(line,index) in result.unparsed" :key="index">{{line}}</p>
      </details>
    </div>
  </section>
</template>
<style scoped>
.kol-quick-heading,.kol-quick-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.kol-quick-heading .erp-form-card-title{margin:0}
.kol-quick-heading{margin-bottom:12px}
.kol-quick-tag{padding:3px 9px;border-radius:5px;background:#f0edf9;color:#6653b9;font-size:12px}
.kol-quick-text{width:100%;min-height:120px;resize:vertical}
.kol-quick-actions{margin-top:9px}
.kol-quick-actions .erp-form-helper{margin:0;flex:1;min-width:180px}
.kol-quick-result{margin-top:12px;padding:10px 12px;border-radius:6px;background:#f5f7fb;overflow-wrap:anywhere}
.kol-quick-unparsed{margin-top:8px;color:#916020}
.kol-quick-unparsed summary{cursor:pointer}
.kol-quick-unparsed p{margin:4px 0;white-space:pre-wrap}
</style>
