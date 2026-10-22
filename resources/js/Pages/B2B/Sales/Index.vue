<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type Opportunity = {
  id:number; opportunity_number:string; title:string; status:string; pipeline_stage:string; currency:string
  expected_value_micros:number|null; probability_bps:number; expected_close_date:string|null
  next_follow_up_at:string|null; last_follow_up_at:string|null; lost_at:string|null; lost_reason_code:string|null; lost_reason_note:string|null; quotes_count:number
  company:{id:number;name:string;country_code:string|null}|null
  owner:{id:number;name:string}|null
}
const props = defineProps<{
  metrics:{open:number;quoted:number;won:number;lost:number;pipeline_value_micros:number}
  filters:Record<string,string|number|null>
  owners:Array<{id:number;name:string}>
  companies:Array<{id:number;name:string;country_code:string|null}>
  opportunities:{data:Opportunity[];links:Array<{url:string|null;label:string;active:boolean}>}
  cases:Array<{id:number;organization_id:number;title:string;case_number:string|null}>
  canOperate:boolean;canAssign:boolean
}>()
const createForm = useForm({company_id:'',case_id:'',owner_id:'',title:'',currency:'USD',expected_value:'',expected_close_date:'',next_follow_up_at:''})
const filter = useForm({status:String(props.filters.status ?? ''), stage:String(props.filters.stage ?? ''), owner_id:String(props.filters.owner_id ?? ''), search:String(props.filters.search ?? '')})
const money = (micros:number|null, currency='USD') => micros === null ? '—' : new Intl.NumberFormat('zh-CN',{style:'currency',currency,maximumFractionDigits:2}).format(micros/1_000_000)
const applyFilters = () => router.get('/b2b/sales', {...filter.data()}, {preserveState:true, replace:true})
const stageLabel:Record<string,string>={discovery:'初步接洽',qualified:'已确认需求',solution:'方案阶段',quoted:'已报价',negotiating:'谈判中',won:'成交',lost:'丢单'}
const submitCreate = () => createForm.transform((data:any)=>({
  organization_id:Number(data.company_id), case_id:data.case_id?Number(data.case_id):null, owner_id:data.owner_id?Number(data.owner_id):null, title:data.title,
  currency:data.currency, expected_value_micros:data.expected_value === '' ? null : Math.round(Number(data.expected_value)*1_000_000),
  expected_close_date:data.expected_close_date || null, next_follow_up_at:data.next_follow_up_at || null,
})).post('/b2b/sales/opportunities')
</script>

<template>
  <Head title="B2B 销售漏斗 · BYL ERP" />
  <AuthenticatedLayout title="B2B 销售漏斗" :breadcrumbs="['BYL ERP', 'B2B 业务', '销售漏斗']" canonical-topbar>
    <section class="customer-os-page sales-canonical-page">
      <header class="sales-canonical-hero">
        <div class="sales-canonical-hero-copy">
          <p class="sales-canonical-kicker">SALES OS</p>
          <h2>B2B 销售漏斗</h2>
          <p>复用 YBY 商机、五阶段、跟进、报价草稿和 Won/Lost；实际回款与应收由 S3 对接。</p>
        </div>
        <div class="sales-canonical-actions">
          <span class="customer-os-button" aria-disabled="true">回款 · S3 待适配</span>
          <Link class="customer-os-button" :href="'/b2b/companies'">客户</Link>
          <Link class="customer-os-button customer-os-button-primary" :href="'/b2b/inquiries'">询盘</Link>
        </div>
      </header>

      <div class="sales-kpi-grid">
        <article class="sales-kpi-card"><span class="sales-kpi-icon">🌱</span><div><span>进行中商机</span><strong>{{ metrics.open }}</strong><small>持续跟进的潜在机会</small></div></article>
        <article class="sales-kpi-card is-blue"><span class="sales-kpi-icon">▤</span><div><span>报价 / 谈判</span><strong>{{ metrics.quoted }}</strong><small>处于报价或谈判阶段；以真实报价版本为准</small></div></article>
        <article class="sales-kpi-card is-green"><span class="sales-kpi-icon">◆</span><div><span>已成交</span><strong>{{ metrics.won }}</strong><small>成功赢单的商机</small></div></article>
        <article class="sales-kpi-card is-lost"><span class="sales-kpi-icon">×</span><div><span>已丢单</span><strong>{{ metrics.lost }}</strong><small>需要复盘或等待重新联系</small></div></article>
        <article class="sales-kpi-card is-indigo"><span class="sales-kpi-icon">▥</span><div><span>Pipeline Value</span><strong>{{ money(metrics.pipeline_value_micros) }}</strong><small>商机预计总金额</small></div></article>
      </div>

      <article class="sales-pipeline-card">
        <div class="sales-pipeline-heading">
          <div><p class="sales-pipeline-kicker">▥</p><div><h3>销售 Pipeline</h3><p>金额是商机预计值，不等同于真实 Revenue；真实收入仍由订单/财务事实提供。</p></div></div>
        </div>
        <div class="sales-filter-row">
          <input v-model="filter.search" placeholder="搜索商机 / 公司" @keyup.enter="applyFilters">
          <select v-model="filter.status" @change="applyFilters"><option value="">全部状态</option><option value="open">进行中</option><option value="won">成交</option><option value="lost">丢单</option></select>
          <select v-model="filter.stage" @change="applyFilters"><option value="">全部阶段</option><option v-for="(label,key) in stageLabel" :key="key" :value="key" v-show="!['won','lost'].includes(String(key))">{{ label }}</option></select>
          <select v-model="filter.owner_id" @change="applyFilters"><option value="">全部负责人</option><option v-for="owner in owners" :key="owner.id" :value="String(owner.id)">{{ owner.name }}</option></select>
          <button type="button" class="customer-os-button customer-os-button-primary" @click="applyFilters">查询</button>
        </div>

        <div class="b2b-sales-mobile-cards">
          <article v-for="row in opportunities.data" :key="row.id" class="b2b-sales-mobile-card">
            <Link :href="`/b2b/sales/opportunities/${row.id}`" class="customer-os-company-link">{{ row.opportunity_number }}</Link>
            <strong>{{ row.title }}</strong>
            <div class="mobile-company">{{ row.company?.name || '未关联企业' }}</div>
            <dl>
              <div><dt>阶段</dt><dd>{{ row.status==='open' ? stageLabel[row.pipeline_stage] : stageLabel[row.status] }}</dd></div>
              <div><dt>预计金额</dt><dd>{{ money(row.expected_value_micros,row.currency) }}</dd></div>
              <div><dt>概率</dt><dd>{{ (row.probability_bps/100).toFixed(0) }}%</dd></div>
              <div><dt>下次跟进</dt><dd>{{ row.next_follow_up_at?.slice(0,10) || '未安排' }}</dd></div>
            </dl>
          </article>
        </div>
        <div class="sales-table-shell">
          <table class="customer-os-table sales-opportunity-table">
            <thead><tr><th>商机</th><th>公司</th><th>阶段</th><th>负责人</th><th>预计金额</th><th>概率</th><th>下次跟进</th><th>报价</th><th>操作</th></tr></thead>
            <tbody v-if="opportunities.data.length">
              <tr v-for="row in opportunities.data" :key="row.id" :class="{'sales-row-lost':row.status==='lost'}">
                <td><Link class="customer-os-company-link" :href="`/b2b/sales/opportunities/${row.id}`">{{ row.opportunity_number }}</Link><small>{{ row.title }}</small><span v-if="row.status==='lost'" class="sales-lost-inline">已丢单</span></td>
                <td>{{ row.company?.name ?? '—' }}<small>{{ row.company?.country_code ?? '' }}</small></td>
                <td><template v-if="row.status==='lost'"><span class="sales-lost-badge">丢单</span><small class="sales-lost-reason">{{ row.lost_reason_code || '未记录原因' }}</small></template><template v-else>{{ stageLabel[row.pipeline_stage] ?? row.pipeline_stage }}</template></td>
                <td>{{ row.owner?.name ?? '待分配' }}</td>
                <td>{{ money(row.expected_value_micros,row.currency) }}</td>
                <td>{{ (row.probability_bps/100).toFixed(0) }}%</td>
                <td>{{ row.next_follow_up_at ?? '—' }}</td>
                <td>{{ row.quotes_count }}</td>
                <td><Link class="customer-os-row-link" :href="`/b2b/sales/opportunities/${row.id}`">查看</Link></td>
              </tr>
            </tbody>
          </table>
          <div v-if="!opportunities.data.length" class="sales-empty-state">
            <div class="sales-empty-illustration"><span></span><i></i><b></b></div>
            <strong>暂无销售商机</strong>
            <p>请先创建 B2B 企业客户，再从询盘进入销售商机。此处不展示虚构数据。</p>
            <Link class="customer-os-button customer-os-button-primary" :href="'/b2b/inquiries'">进入询盘中心 →</Link>
          </div>
        </div>
        <nav class="sales-pages" aria-label="商机分页">
          <Link v-for="(page,i) in opportunities.links" :key="i" v-show="page.url" :href="page.url||'#'" class="customer-os-button" :class="{'customer-os-button-primary':page.active}">{{ page.label.replace(/&laquo;|&raquo;/g,'') }}</Link>
        </nav>
      </article>

      <article v-if="companies.length" class="sales-create-card">
        <div class="sales-create-heading"><h3>新建商机</h3><p>基于独立 B2B 企业建商机，优先关联已确认的询盘；不会同步 C 端客户。</p></div>
        <form v-if="canOperate" class="sales-create-grid" @submit.prevent="submitCreate">
          <select v-model="createForm.company_id" required><option value="">选择客户公司</option><option v-for="company in companies" :key="company.id" :value="String(company.id)">{{ company.name }}</option></select>
          <select v-model="createForm.case_id"><option value="">无关联询盘（手动商机）</option><option v-for="item in cases.filter(c=>c.organization_id===Number(createForm.company_id))" :key="item.id" :value="String(item.id)">{{ item.case_number }} · {{ item.title }}</option></select>
          <input v-model="createForm.title" required placeholder="商机标题">
          <select v-if="canAssign" v-model="createForm.owner_id"><option value="">负责人（可选）</option><option v-for="owner in owners" :key="owner.id" :value="String(owner.id)">{{ owner.name }}</option></select>
          <input v-model="createForm.expected_value" inputmode="decimal" placeholder="预计金额">
          <input v-model="createForm.currency" maxlength="3" placeholder="USD">
          <input v-model="createForm.expected_close_date" type="date">
          <input v-model="createForm.next_follow_up_at" type="datetime-local">
          <button class="customer-os-button customer-os-button-primary" :disabled="createForm.processing">创建商机</button>
        </form>
      </article>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.sales-canonical-page { padding: 16px 0 28px; }
.sales-canonical-hero { min-height: 118px; display:flex; align-items:center; justify-content:space-between; gap:28px; padding: 6px 6px 18px; }
.sales-canonical-kicker { margin:0 0 8px; color:var(--customer-green); font-size:11px; font-weight:800; letter-spacing:.18em; }
.sales-canonical-hero h2 { margin:0 0 8px; color:#101b32; font-size:36px; line-height:1.08; font-weight:800; letter-spacing:-.03em; }
.sales-canonical-hero-copy > p:last-child { margin:0; color:#69788b; font-size:15px; }
.sales-canonical-actions { display:flex; align-items:center; gap:12px; }
.sales-kpi-grid { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:12px; margin-bottom:16px; }
.sales-kpi-card { min-height:136px; display:flex; align-items:center; gap:16px; padding:20px; border:1px solid var(--customer-border); border-radius:16px; background:#fff; box-shadow:0 7px 22px rgb(28 51 63 / .05); }
.sales-kpi-icon { width:54px; height:54px; flex:0 0 54px; display:grid; place-items:center; border-radius:999px; background:#e4f5ef; color:#078866; font-size:25px; font-weight:800; }
.sales-kpi-card.is-blue .sales-kpi-icon { background:#e7f2ff; color:#2185e5; }
.sales-kpi-card.is-green .sales-kpi-icon { background:#e7f6ec; color:#13935f; }
.sales-kpi-card.is-amber .sales-kpi-icon { background:#fff0d8; color:#d77b00; }
.sales-kpi-card.is-indigo .sales-kpi-icon { background:#eaedff; color:#5a6ee6; }
.sales-kpi-card > div { min-width:0; display:grid; }
.sales-kpi-card > div > span { color:#2f4157; font-size:14px; font-weight:700; }
.sales-kpi-card strong { margin:4px 0 7px; color:#101b32; font-size:29px; line-height:1.15; font-weight:800; min-width:0; white-space:normal; overflow-wrap:anywhere; }
.sales-kpi-card small { color:#7a8898; font-size:11px; line-height:1.35; }
.sales-pipeline-card { overflow:hidden; padding:20px; border:1px solid var(--customer-border); border-radius:17px; background:#fff; box-shadow:0 8px 25px rgb(28 51 63 / .055); }
.sales-pipeline-heading > div { display:flex; align-items:flex-start; gap:13px; }
.sales-pipeline-kicker { width:30px; height:30px; display:grid; place-items:center; margin:0; border-radius:9px; background:#e6f5f0; color:var(--customer-green); font-weight:800; }
.sales-pipeline-heading h3 { margin:0 0 4px; color:#14203a; font-size:20px; font-weight:800; }
.sales-pipeline-heading p { margin:0; color:#6d7b8d; font-size:12px; }
.sales-filter-row { display:grid; grid-template-columns:minmax(240px,1.5fr) repeat(3,minmax(150px,1fr)) 120px; gap:10px; margin:20px 0 14px; }
.sales-filter-row input,.sales-filter-row select,.sales-create-grid input,.sales-create-grid select { width:100%; min-height:42px; padding:0 12px; border:1px solid #cdd8dd; border-radius:9px; background:#fff; color:#34475e; font:inherit; font-size:13px; }
.sales-filter-row .customer-os-button { min-height:42px; }
.sales-table-shell { overflow:hidden; border:1px solid #e4eaed; border-radius:12px; background:#fff; }
.sales-opportunity-table { min-width:1040px; }
.sales-empty-state { min-height:270px; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:28px; text-align:center; background:linear-gradient(180deg,#fff 0%,#fcfefe 100%); }
.sales-empty-state strong { margin:14px 0 7px; color:#17223d; font-size:19px; font-weight:800; }
.sales-empty-state p { max-width:680px; margin:0 0 16px; color:#68788b; font-size:12px; line-height:1.6; }
.sales-empty-illustration { position:relative; width:92px; height:68px; }
.sales-empty-illustration span { position:absolute; left:27px; top:5px; width:42px; height:54px; border:3px solid #d9e3e8; border-radius:8px; background:#f9fbfc; }
.sales-empty-illustration span:after { content:'≡'; position:absolute; inset:5px; color:#aab9c2; font-size:28px; line-height:34px; }
.sales-empty-illustration i,.sales-empty-illustration b { position:absolute; bottom:5px; width:26px; height:13px; border-radius:100% 0 100% 0; background:#8edbb8; }
.sales-empty-illustration i { left:7px; transform:rotate(24deg); }
.sales-empty-illustration b { right:2px; transform:scaleX(-1) rotate(24deg); }
.sales-create-card { margin-top:16px; padding:20px; border:1px solid var(--customer-border); border-radius:17px; background:#fff; box-shadow:0 8px 25px rgb(28 51 63 / .045); }
.sales-create-heading h3 { margin:0 0 4px; color:#17223d; font-size:18px; }
.sales-create-heading p { margin:0 0 16px; color:#6d7b8d; font-size:12px; }
.sales-create-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; }
@media (max-width:1280px){ .sales-kpi-grid{grid-template-columns:repeat(3,minmax(0,1fr));}.sales-filter-row{grid-template-columns:repeat(2,minmax(0,1fr));}.sales-filter-row .customer-os-button{grid-column:span 2}.sales-create-grid{grid-template-columns:repeat(2,minmax(0,1fr));} }
@media (max-width:800px){ .sales-canonical-hero{align-items:flex-start;flex-direction:column}.sales-canonical-hero h2{font-size:30px}.sales-canonical-actions{width:100%}.sales-canonical-actions .customer-os-button{flex:1}.sales-kpi-grid{display:flex;overflow-x:auto}.sales-kpi-card{min-width:235px}.sales-filter-row{grid-template-columns:1fr}.sales-filter-row .customer-os-button{grid-column:auto}.sales-create-grid{grid-template-columns:1fr} }

.customer-os-page{padding:18px 22px 30px;background:#f5f7f7;color:#192c30;min-height:100%;font-family:PingFang SC,-apple-system,BlinkMacSystemFont,Arial,sans-serif}
.customer-os-button{display:inline-flex;align-items:center;justify-content:center;border:1px solid #dce7e5;background:#fff;border-radius:8px;padding:9px 13px;text-decoration:none;color:#245449;font-size:12px;font-weight:650;cursor:pointer}
.customer-os-button-primary{background:#176e56;color:#fff;border-color:#176e56}
.customer-os-company-link{color:#176e56;font-weight:700;text-decoration:none}
.customer-os-row-link{color:#176e56;font-weight:600}
.customer-os-table{width:100%;border-collapse:collapse;font-size:12px}
.customer-os-table th,.customer-os-table td{text-align:left;border-bottom:1px solid #e6ecec;padding:12px 8px;vertical-align:top}
.customer-os-table small{display:block;color:#6e7f83;font-size:11px;max-width:250px}
.sales-upcoming{font-size:11px;color:#81928a}
.sales-pages{display:flex;gap:6px;flex-wrap:wrap;margin-top:12px}
.b2b-sales-mobile-cards{display:none}
.b2b-sales-mobile-card{background:white;border:1px solid #e2e8e6;border-radius:14px;padding:16px;min-width:0;display:flex;flex-direction:column;gap:7px}
.b2b-sales-mobile-card>strong{font-size:15px;overflow-wrap:anywhere}
.b2b-sales-mobile-card .mobile-company{font-size:12px;color:#697c79}
.b2b-sales-mobile-card dl{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:5px 0 0}
.b2b-sales-mobile-card dt{font-size:11px;color:#75837f}
.b2b-sales-mobile-card dd{font-size:12px;margin:2px 0;color:#253c36;overflow-wrap:anywhere}
@media(max-width:680px){.customer-os-page{padding:12px}.sales-table-shell{display:none}.b2b-sales-mobile-cards{display:grid;gap:10px}.sales-kpi-card{min-width:172px}.sales-kpi-grid{gap:7px}.sales-canonical-actions{flex-wrap:wrap}.sales-canonical-hero h2{font-size:22px}.sales-pipeline-card,.sales-create-card{padding:13px}.sales-create-grid>input,.sales-create-grid>select{min-width:0;width:100%}}

.sales-kpi-card.is-lost{border-color:#efcaca;background:linear-gradient(180deg,#fffafa 0%,#fff4f4 100%)}
.sales-kpi-card.is-lost .sales-kpi-icon{background:#f7dede;color:#b63f3f}
.sales-kpi-card.is-lost strong{color:#9d3434}
.sales-row-lost{background:#fffafa}
.sales-row-lost:hover{background:#fff4f4}
.sales-row-lost td:first-child{box-shadow:inset 3px 0 0 #c85a5a}
.sales-lost-inline{display:inline-flex;margin-top:5px;padding:2px 7px;border-radius:999px;background:#fde8e8;color:#ad4040;font-size:9px;font-weight:800}
.sales-lost-badge{display:inline-flex;padding:3px 8px;border-radius:999px;background:#f7dddd;color:#a83838;font-size:10px;font-weight:850}
.sales-lost-reason{display:block;margin-top:4px;max-width:180px;color:#9a6666;font-size:9px;white-space:normal}
</style>
