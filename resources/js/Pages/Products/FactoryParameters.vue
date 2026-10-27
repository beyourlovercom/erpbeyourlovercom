<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
type Part={name:string;quantity:number|string;unit:string;notes?:string}
type Draft={id:number;measurements:Record<string,string>;accessories:Part[];notes:string|null;media:{id:number;name:string;url:string}[]}
const props=defineProps<{product:{id:number;sku:string;name:string};task:{is_required:boolean;required_fields:string[]};draft:Draft|null;history:{id:number;status:string;review_note:string|null}[]}>()
const groups=[
 {title:'产品材质与净重',fields:[['material','产品材质','text'],['weight_composition','重量组成说明','text'],['product_length_cm','裸品长 cm','number'],['product_width_cm','裸品宽 cm','number'],['product_height_cm','裸品高 cm','number'],['net_weight_kg','产品净重 kg','number']]},
 {title:'单件包装与运输重量',fields:[['package_length_cm','包装长 cm','number'],['package_width_cm','包装宽 cm','number'],['package_height_cm','包装高 cm','number'],['color_box_gross_kg','彩盒毛重 kg','number'],['courier_gross_kg','快递毛重 kg','number'],['shipping_weight_kg','运输参考重量 kg','number']]},
 {title:'装箱规格',fields:[['carton_length_cm','外箱长 cm','number'],['carton_width_cm','外箱宽 cm','number'],['carton_height_cm','外箱高 cm','number'],['units_per_carton','每箱数量 pcs','number'],['carton_gross_weight_kg','整箱毛重 kg','number']]},
]
const allFields=groups.flatMap(g=>g.fields.map(f=>f[0]))
const form=useForm({
 measurements:Object.fromEntries(allFields.map(f=>[f,props.draft?.measurements[f]??''])) as Record<string,string>,
 accessories:props.draft?.accessories?.map(a=>({...a}))??[] as Part[],
 notes:props.draft?.notes??''
})
const photo=ref<File|null>(null)
const uploading=ref(false)
const missing=computed(()=>props.task.is_required?props.task.required_fields.filter(k=>!String(form.measurements[k]??'').trim()):[])
const volume=computed(()=>{
 const m=form.measurements
 const v=['package_length_cm','package_width_cm','package_height_cm'].map(k=>Number(m[k]))
 return v.every(x=>Number.isFinite(x)&&x>0)?(v[0]*v[1]*v[2]/1000).toFixed(2):'待填写'
})
function save(){form.post('/products/factory-parameters/'+props.product.id+'/draft',{preserveScroll:true})}
function submit(){if(!props.draft||form.isDirty||missing.value.length)return
 router.post('/products/factory-parameters/'+props.product.id+'/submissions/'+props.draft.id+'/submit',{}, {preserveScroll:true})}
function upload(){
 if(!photo.value||!props.draft||uploading.value)return
 const fd=new FormData();fd.append('photo',photo.value);uploading.value=true
 router.post('/products/factory-parameters/'+props.product.id+'/submissions/'+props.draft.id+'/photo',fd,{
  forceFormData:true,preserveScroll:true,onFinish:()=>{uploading.value=false;photo.value=null}
 })
}
</script>
<template>
<Head title="工厂产品参数填报"/>
<AuthenticatedLayout title="产品参数 · 工厂填报" :breadcrumbs="['BYL ERP','产品参数',product.sku]">
<main class="factory-page">
 <header class="factory-card"><small>PRODUCT 360 · 指定产品填报</small><h1>{{ product.name }}</h1>
 <p>SKU {{ product.sku }} · 产品 ID {{ product.id }}</p>
 <span class="factory-badge">{{ task.is_required?'必填任务':'待填任务' }} · 图片选填</span>
 </header>
 <form @submit.prevent="save">
  <section v-for="group in groups" :key="group.title" class="factory-card">
   <h2>{{ group.title }}</h2>
   <div class="factory-grid"><label v-for="field in group.fields" :key="field[0]">
    <span>{{field[1]}} <b v-if="task.required_fields.includes(field[0])">*</b></span>
    <input v-model="form.measurements[field[0]]" :type="field[2]" :inputmode="field[2]==='text'?'text':'decimal'"
     :step="field[2]==='text'?undefined:'any'" :min="field[2]==='text'?undefined:'0.000001'"
     :placeholder="field[0]==='weight_composition'?'例：主枕 1589g / 小枕 100g':'待填写'"/>
   </label></div>
   <p v-if="group.title==='单件包装与运输重量'" class="factory-muted">由包装长宽高计算体积：{{ volume }} {{volume==='待填写'?'':'L'}}</p>
  </section>
  <section class="factory-card">
   <div class="factory-head"><h2>包装内含物 / 配件</h2><button type="button" class="factory-btn" @click="form.accessories.push({name:'',quantity:1,unit:'个'})">＋ 添加配件</button></div>
   <p class="factory-muted">只填写销售包装中的实际配件，不将外箱和填充材料混入。</p>
   <div v-for="(part,index) in form.accessories" :key="index" class="factory-part">
    <label>名称<input v-model="part.name" maxlength="200"/></label>
    <label>数量<input v-model="part.quantity" type="number" min="0.000001" step="any"/></label>
    <label>单位<input v-model="part.unit" maxlength="32"/></label>
    <button type="button" class="factory-btn danger" @click="form.accessories.splice(index,1)">×</button>
   </div>
  </section>
  <section class="factory-card"><h2>补充说明（选填）</h2><textarea v-model="form.notes" rows="2" maxlength="5000" placeholder="来源、测量方法、异常备注"/>
   <p v-if="Object.keys(form.errors).length" class="factory-error">{{Object.values(form.errors).join('；')}}</p>
   <div class="factory-actions"><button class="factory-btn primary" type="submit" :disabled="form.processing">保存草稿</button>
    <button class="factory-btn" type="button" :disabled="!draft||form.isDirty||missing.length>0" @click="submit">提交审核</button></div>
   <p v-if="form.isDirty&&draft" class="factory-muted">已修改的内容请先保存草稿，再提交。</p>
   <p v-if="missing.length" class="factory-error">还有 {{missing.length}} 项必须测量的字段未填写。</p>
   <p class="factory-muted">提交后记录锁定、不可修改；需要修订时另建新草稿。不会直接覆盖产品主数据。</p>
  </section>
 </form>
 <section v-if="draft" class="factory-card">
  <h2>测量照片（非必填）</h2><p class="factory-muted">照片可直接拍摄或选择；不上传照片也能提交审核。最多 8 张，每张不超过 10MB。</p>
  <div class="factory-actions"><input type="file" accept="image/jpeg,image/png,image/webp" @change="photo=($event.target as HTMLInputElement).files?.[0]??null"/>
   <button type="button" class="factory-btn" :disabled="!photo||uploading" @click="upload">上传照片</button></div>
  <div v-for="item in draft.media" :key="item.id"><a :href="item.url" target="_blank" rel="noopener">{{item.name}}</a></div>
 </section>
 <section v-if="history.length" class="factory-card"><h2>提交记录</h2><p v-for="h in history" :key="h.id">#{{h.id}}　{{h.status}}　{{h.review_note??''}}</p></section>
</main>
</AuthenticatedLayout>
</template>
<style scoped>
.factory-page{max-width:1000px;margin:0 auto;padding:14px 12px 40px;color:#224431}
.factory-card{background:#fff;border:1px solid #e1eae3;border-radius:10px;padding:17px;margin:0 0 12px}
.factory-card small{color:#327b58;font-weight:700}.factory-card h1{font-size:21px;margin:8px 0 3px}
.factory-card h2{font-size:15px;margin:0 0 13px}.factory-card p{font-size:12px;color:#6d8374}
.factory-badge{display:inline-block;border-radius:6px;padding:6px 9px;background:#eaf6ee;color:#26734c;font-size:11px;font-weight:700}
.factory-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:11px}
.factory-grid label,.factory-part label{display:flex;flex-direction:column;gap:5px;color:#5e7668;font-size:11px;font-weight:600}
.factory-grid b{color:#c26739}.factory-grid input,.factory-part input,textarea{border:1px solid #d6e4db;border-radius:7px;padding:9px 10px;outline:0;min-width:0;width:100%;font:inherit}
.factory-grid input:focus,.factory-part input:focus,textarea:focus{border-color:#1b815b;box-shadow:0 0 0 2px #18815b15}
.factory-part{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:9px;margin:11px 0;align-items:end}
.factory-head,.factory-actions{display:flex;align-items:center;justify-content:space-between;gap:9px;flex-wrap:wrap}
.factory-actions{justify-content:flex-start;margin-top:14px}
.factory-btn{border:1px solid #d1dfd5;background:#fff;color:#236e50;border-radius:7px;padding:9px 13px;font-size:11px;font-weight:700;cursor:pointer}
.factory-btn.primary{background:#176c4e;border-color:#176c4e;color:#fff}.factory-btn.danger{color:#a45a46}
.factory-btn:disabled{opacity:.45;cursor:not-allowed}.factory-muted{font-size:11px;color:#7d9384;line-height:1.6}
.factory-error{padding:8px;color:#ad553b;background:#fff1eb;border-radius:6px}
@media(max-width:650px){.factory-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.factory-card{padding:12px}.factory-part{grid-template-columns:1.7fr .8fr .8fr auto;gap:5px}}
</style>
