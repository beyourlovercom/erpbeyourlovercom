<script setup lang="ts">
import {computed,ref} from 'vue'
import axios from 'axios'
import {parseKolQuickOrder,type QuickOrderDraft} from '../../lib/kol/kolQuickOrderParser'
import {projectKolShape,recoverShapeSuggestions,shapeFields,type ShapeField} from '../../lib/kol/kolShapeProjection'
const props=defineProps<{aiFixtures?: {id:string;text:string}[];shapeEnabled?:boolean}>()
const emit=defineEmits<{apply:[QuickOrderDraft]}>()
const raw=ref('')
const preview=ref<QuickOrderDraft|null>(null)
const labels:Record<string,string>={recipient_name:'收件人',country:'收件国家',province_state:'州/省',city:'城市',address_line_1:'地址行 1',address_line_2:'地址行 2',postal_code:'邮编',email:'邮箱',phone:'电话',recipient_customs_id:'清关税号',coupon_code:'折扣码',product_hint:'产品候选（需核对 SKU）',username_hint:'Username（待核对平台）'}
const aiBusy=ref(false)
const aiStatus=ref('')
const aiSuggestions=ref<Record<string,string|number|null>|null>(null)
const shapeSuggestions=ref<Partial<Record<ShapeField,string>>>({})
const accepted=ref<ShapeField[]>([])
const shapeRequest=computed(()=>{
 if(!props.shapeEnabled||!preview.value)return null
 const p=projectKolShape(raw.value)
 if(!p)return null
 const missing=shapeFields.filter(f=>f!=='recipient_customs_id'&&preview.value?.[f]===undefined)
 if(!missing.length)return null
 return {...p.request,missing}
})
const apply=()=>{
 if(!preview.value)return
 const result={...preview.value}
 for(const field of accepted.value){
  if(result[field]===undefined&&shapeSuggestions.value[field])result[field]=shapeSuggestions.value[field]
 }
 emit('apply',result)
}

let revision=0
const fixture=computed(()=>props.aiFixtures?.find(f=>f.text===raw.value))
const reset=()=>{revision++;preview.value=null;aiStatus.value='';aiSuggestions.value=null;shapeSuggestions.value={};accepted.value=[];aiBusy.value=false}
const parse=()=>{reset();preview.value=parseKolQuickOrder(raw.value)}
const askShape=async()=>{
 const payload=shapeRequest.value
 if(!payload||!preview.value||aiBusy.value)return
 const current=++revision
 aiBusy.value=true;aiStatus.value='';shapeSuggestions.value={};accepted.value=[]
 try{
  const r=await axios.post('/kol/smart-input/shape',payload,{timeout:58000})
  if(current!==revision||!preview.value)return
  if(r.data?.source==='shape_review'){
   shapeSuggestions.value=recoverShapeSuggestions(raw.value,r.data.assignments,preview.value)
   aiStatus.value=Object.keys(shapeSuggestions.value).length?'AI 找到候选字段，请逐项核对并勾选。':'AI 未找到可靠候选，请人工填写。'
  }else aiStatus.value='AI 暂不可用，继续使用本地识别'
 }catch{if(current===revision)aiStatus.value='AI 暂不可用，继续使用本地识别'}
 finally{if(current===revision)aiBusy.value=false}
}

const askAi=async()=>{
 const id=fixture.value?.id
 if(!id||aiBusy.value)return
 const current=++revision
 aiBusy.value=true;aiStatus.value='';aiSuggestions.value=null
 try{
  const response=await axios.post('/kol/smart-input/fixture',{fixture_id:id},{timeout:58000})
  if(current!==revision)return
  if(response.data?.source==='hybrid_review'&&response.data.ai_suggestions){
   aiSuggestions.value=response.data.ai_suggestions
   aiStatus.value='AI 建议（仅供核对，不自动覆盖本地结果）'
  }else aiStatus.value='AI 暂不可用，继续使用本地识别'
 }catch{if(current===revision)aiStatus.value='AI 暂不可用，继续使用本地识别'}
 finally{if(current===revision)aiBusy.value=false}
}
</script>
<template>
 <section class="erp-form-card" data-testid="kol-smart-input">
  <h2 class="erp-form-card-title">智能录入网红信息</h2>
  <label class="erp-form-label" for="kol-smart-raw">粘贴原始合作资料</label>
  <textarea id="kol-smart-raw" v-model="raw" class="erp-textarea" rows="6" maxlength="16000" data-testid="kol-smart-raw" @input="reset" placeholder="社媒链接、Username、收件地址、联系方式、产品、折扣码" />
  <p class="erp-form-helper">本地规则识别，不上传原文。无法确定的信息请人工填写。</p>
  <button type="button" class="erp-btn erp-btn-secondary" data-testid="kol-smart-parse" :disabled="!raw.trim()" @click="parse">识别信息</button>
  <div v-if="preview" data-testid="kol-smart-preview">
   <button v-if="fixture" type="button" class="erp-btn erp-btn-secondary" data-testid="kol-smart-ai" :disabled="aiBusy" @click="askAi">{{aiBusy?'AI 识别中…':'AI 辅助识别（Dev 合成样本）'}}</button>
   <button v-if="shapeRequest" type="button" class="erp-btn erp-btn-secondary" data-testid="kol-smart-shape" :disabled="aiBusy" @click="askShape">{{aiBusy?'AI 识别中…':'AI 辅助识别（仅传字段结构）'}}</button>
   <div v-if="Object.keys(shapeSuggestions).length" data-testid="kol-smart-shape-suggestions">
     <label v-for="(value,field) in shapeSuggestions" :key="field" class="erp-form-helper"><input v-model="accepted" type="checkbox" :value="field"> {{labels[field]??field}}：{{value}}</label>
   </div>
   <p v-if="aiStatus" role="status" data-testid="kol-smart-ai-status">{{aiStatus}}</p>
   <div v-if="aiSuggestions" data-testid="kol-smart-ai-suggestions">
     <template v-for="(value,key) in aiSuggestions" :key="key"><p v-if="value!==null">{{labels[key]??key}}：{{value}}</p></template>
   </div>
   <div v-for="account in preview.social_accounts" :key="account.profile_url">{{account.platform}} · {{account.handle}} · {{account.followers ?? '粉丝数待确认'}}</div>
   <template v-for="(label,key) in labels" :key="key">
    <p v-if="(preview as unknown as Record<string,unknown>)[key] !== undefined">{{label}}：{{(preview as unknown as Record<string,unknown>)[key]}}</p>
   </template>
   <div v-if="preview.unparsed.length" role="status">
    <strong>未识别或冲突信息（请核对）</strong>
    <p v-for="(line,index) in preview.unparsed" :key="index">{{line}}</p>
   </div>
   <button type="button" class="erp-btn erp-btn-primary" data-testid="kol-smart-apply" @click="apply">确认填充</button>
  </div>
 </section>
</template>
