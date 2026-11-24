<script setup lang="ts">
import {ref} from 'vue';
import Panel from './views/erp/oversea-oms-batch-panel.vue';
import {preflight3sm,original3smManualExport,type OmsBatchWarehouse} from './api/erp-logistics';
const w=ref<OmsBatchWarehouse>('us-moreplus'),ids=ref([101,102]),result=ref('');
async function check(){const p=await preflight3sm(101);result.value=p.blockers.join(',');}
</script>
<template><button @click="w='us-moreplus'">MorePlus</button><button @click="w='de-tianyun'">DE</button><button @click="ids=[101]">Change selection</button><Panel :key="w" :warehouse="w" :ids="ids" :capabilities="{batch:true,simulation:true,paused:false,latestBatchId:''}" :can-operate="true"/><button @click="check">3SM preflight</button><a :href="original3smManualExport(101)">3SM export</a><p>{{result}}</p></template>
