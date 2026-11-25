<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { onMounted, onUnmounted, ref } from 'vue'
import { currentContactQuery } from './orderContactQuery'
const value = ref(currentContactQuery().filter_contact ?? '')
let cleanup: (() => void) | undefined
onMounted(() => {
  value.value = currentContactQuery().filter_contact ?? ''
  cleanup = router.on?.('navigate', () => { value.value = currentContactQuery().filter_contact ?? '' })
})
onUnmounted(() => cleanup?.())
function submit() {
  const url = new URL(window.location.href)
  const filter = value.value.trim()
  if (filter) url.searchParams.set('filter_contact', filter)
  else url.searchParams.delete('filter_contact')
  url.searchParams.set('page', '1')
  router.visit(url.pathname + url.search, { preserveScroll: true, preserveState: true, replace: true })
}
</script>
<template>
  <form class="mvp-toolbar order-contact-filter" role="search" @submit.prevent="submit">
    <input v-model="value" aria-label="筛选订单联络人" placeholder="订单联络人姓名" maxlength="80" />
    <button class="mvp-button" type="submit">筛选</button>
    <button v-if="value" class="mvp-button" type="button" @click="value=''; submit()">清除</button>
  </form>
</template>
<style scoped>
.order-contact-filter { display:flex; flex-wrap:wrap; align-items:center; gap:6px; margin:6px 0; }
.order-contact-filter input { width:min(100%, 190px); }
</style>
