<script setup lang="ts">
import {costMoney,costNative,type AllocationCost} from '../../lib/allocationCostConfirmation'
withDefaults(defineProps<{quote:AllocationCost|null;loading?:boolean;error?:string;showFactory?:boolean;readonly?:boolean}>(),{showFactory:true})
defineEmits<{refresh:[]}>()
const status:Record<string,string>={estimated:'已审核费率 · 预计',partial_reference:'部分估算 / 未审核报价参考',native_only:'仅原币参考',unavailable:'暂无法估算'}
const reasons:Record<string,string>={selected_service_rate_missing:'所选仓库及实际渠道缺少对应费率',missing_packaging_standard:'缺少商品重量',missing_destination:'缺少有效目的国',unresolved_product:'实际发货商品未确认',dimensions_unavailable:'缺少尺寸，未核算体积重',unapproved_transport_reference:'未审核基础运输费，其他费用可能未包含',fx_unavailable:'汇率不可用',rate_scope_missing:'缺少对应报价',rule_unsupported:'计费规则待核实',postal_zone_missing_or_ambiguous:'邮编分区待核实',postal_rate_excluded:'邮编不适用',weight_band_missing_or_ambiguous:'未匹配重量档'}
</script>
<template>
  <section class="selected-cost" data-testid="selected-allocation-cost">
    <div style="display:flex;justify-content:space-between;align-items:center"><strong>{{readonly?'分仓确认费用快照':'当前分仓方案费用'}}</strong><button v-if="!readonly" type="button" :disabled="loading" @click="$emit('refresh')">重新计算</button></div>
    <small v-if="loading" role="status">正在重新计算…</small>
    <small v-else-if="error" role="status">{{error}}；可继续尝试分仓，服务器会核验当前费用。</small>
    <small v-else-if="!quote">选择实际发货仓和商品后自动计算。</small>
    <template v-else>
      <div v-for="(g,i) in quote.groups" :key="i" class="cost-line">
        <span><b>{{String.fromCharCode(65+i)}} · {{g.warehouse_name}}</b><small>{{g.carrier??'待确认物流商'}} {{g.service??''}}<template v-if="g.rate?.version_number"> · 报价 V{{g.rate.version_number}}</template></small><small v-if="g.measurement">{{g.measurement.weight_kg}} kg · {{g.measurement.dimensions_cm?.join(' × ')??'尺寸未知'}}<template v-if="g.measurement.dimensions_cm"> cm</template></small></span>
        <span class="cost-amount"><b>{{costMoney(g.amount_cny)}}</b><small>{{costNative(g.native_totals)}}</small><small>{{status[g.status]??g.status}}<template v-if="g.reason"> · {{reasons[g.reason]??g.reason}}</template></small></span>
      </div>
      <div class="cost-line" v-if="showFactory!==false"><span>全部中国工厂直发参考</span><b>{{costMoney(quote.factory_default_reference?.amount_cny)}}</b></div>
      <div class="cost-line"><span>{{quote.total_cny!=null?'当前预计合计':'已知费用小计'}}<small v-if="quote.partial_group_count||quote.unknown_group_count">{{quote.partial_group_count}} 组部分估算、{{quote.unknown_group_count}} 组人民币费用未知；不含未知费用。</small></span><b class="cost-total">{{costMoney(quote.total_cny??quote.known_subtotal_cny)}}<template v-if="quote.total_cny==null&&quote.known_subtotal_cny!=null"> + 未知费用</template></b></div>
      <small v-if="readonly">{{quote.confirmation_status==='confirmed'?'已确认':'费用未知，允许分仓'}} · 北京时间 {{quote.confirmed_at?.replace('T',' ').slice(0,19)}} · 操作账号 ID {{quote.confirmed_by}} · 历史快照，不随新报价变化。</small>
      <small v-else>预估不代表实际账单或可寄送认证；无报价/重量可直接继续，不改变原有分仓规则。</small>
    </template>
  </section>
</template>
<style scoped>
.selected-cost{margin:8px 18px;padding:9px 12px;border:1px solid #dbe3ec;border-radius:7px;font-size:12px;display:grid;gap:6px}
.selected-cost small{display:block;color:#64748b;font-size:11px}
.cost-line{display:flex;gap:12px;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid #e2e8f0}
.cost-amount{text-align:right}.cost-total{font-size:15px}
</style>
