<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'

const props = defineProps<{ sections: Array<{ id: string; label: string }> }>()
const active = ref(props.sections[0]?.id ?? '')
let observer: IntersectionObserver | null = null

const scrollTo = (id: string) => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
const backToTop = () => window.scrollTo({ top: 0, behavior: 'smooth' })

onMounted(() => {
  if (typeof window === 'undefined' || !('IntersectionObserver' in window)) return
  observer = new IntersectionObserver((entries) => {
    const visible = entries.filter(entry => entry.isIntersecting).sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0]
    if (visible) active.value = visible.target.id
  }, { rootMargin: '-96px 0px -55% 0px', threshold: [0.1, 0.5] })
  props.sections.forEach(section => {
    const element = document.getElementById(section.id)
    if (element) observer?.observe(element)
  })
})

onBeforeUnmount(() => observer?.disconnect())
</script>

<template>
  <nav class="product-section-rail" aria-label="产品表单快捷导航">
    <button v-for="section in sections" :key="section.id" type="button" :class="{ active: active === section.id }" :aria-label="section.label" @click="scrollTo(section.id)">{{ section.label }}</button>
    <button type="button" aria-label="返回顶部" @click="backToTop">↑</button>
  </nav>
</template>

<style scoped>
.product-section-rail { position:fixed; top:50%; right:max(18px,calc((100vw - 1080px)/2 - 72px)); z-index:10; display:flex; flex-direction:column; gap:6px; transform:translateY(-50%); }
.product-section-rail button { border:1px solid #E4EAED; background:#fff; color:#53666d; border-radius:999px; padding:7px 9px; font-size:12px; cursor:pointer; box-shadow:0 2px 8px rgba(20,33,38,.06); }
.product-section-rail button.active, .product-section-rail button:hover { border-color:#2D8C84; color:#2D8C84; }
@media (max-width: 1280px) { .product-section-rail { right:12px; } }
@media (max-width: 900px) { .product-section-rail { display:none; } }
</style>
