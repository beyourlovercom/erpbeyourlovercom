<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'

const props = defineProps<{ sections: Array<{ id: string; label: string }> }>()
const active = ref(props.sections[0]?.id ?? '')

// Match the approved order-detail floating navigation: track the section that
// has crossed the sticky header, including the final section at page bottom.
const updateActiveSection = () => {
  if (!props.sections.length) return
  const scrollHeight = document.scrollingElement?.scrollHeight ?? document.documentElement.scrollHeight
  if (scrollHeight > window.innerHeight && window.scrollY + window.innerHeight >= scrollHeight - 12) {
    active.value = props.sections[props.sections.length - 1].id
    return
  }
  let current = props.sections[0].id
  for (const section of props.sections) {
    const target = document.getElementById(section.id)
    if (target && target.getBoundingClientRect().top <= 148) current = section.id
  }
  active.value = current
}
const scrollTo = (id: string) => {
  const target = document.getElementById(id)
  if (!target) return
  active.value = id
  target.scrollIntoView({ behavior: 'smooth', block: 'start' })
}
const backToTop = () => scrollTo(props.sections[0]?.id ?? '')

onMounted(async () => {
  await nextTick()
  updateActiveSection()
  window.addEventListener('scroll', updateActiveSection, { passive: true })
  window.addEventListener('resize', updateActiveSection)
})
onBeforeUnmount(() => {
  window.removeEventListener('scroll', updateActiveSection)
  window.removeEventListener('resize', updateActiveSection)
})
</script>

<template>
  <aside class="product-section-nav-rail" aria-label="产品模块快捷导航">
    <nav class="product-section-rail" aria-label="产品模块">
      <span class="product-section-caption">快捷定位</span>
      <button
        v-for="section in sections"
        :key="section.id"
        type="button"
        class="product-section-item"
        :class="{ 'is-active': active === section.id }"
        :aria-current="active === section.id ? 'location' : undefined"
        @click="scrollTo(section.id)"
      >{{ section.label }}</button>
      <button type="button" class="product-section-top" aria-label="返回顶部" @click="backToTop">↑ 返回顶部</button>
    </nav>
  </aside>
</template>

<style scoped>
/* Same grid overlay / left gutter anchoring as Orders/Show.vue.
   The rail hugs the centered 1080px card's LEFT edge, never the viewport right. */
.product-section-nav-rail {
  grid-area:1 / 1;
  position:sticky;
  top:108px;
  justify-self:end;
  align-self:start;
  width:126px;
  min-width:0;
  margin-right:calc(50% + min(540px, 50%) + 5px);
  z-index:5;
}
.product-section-rail {
  display:flex;
  flex-direction:column;
  padding:12px 8px;
  border:1px solid #e4eaed;
  border-radius:12px;
  background:#fff;
  box-shadow:0 2px 7px rgba(20,33,38,.035);
  max-height:calc(100vh - 110px);
  overflow-y:auto;
}
.product-section-caption {
  color:#829198;
  font-size:11px;
  font-weight:600;
  padding:2px 10px 8px;
}
.product-section-item,.product-section-top {
  text-align:left;
  border:0;
  border-radius:7px;
  background:transparent;
  color:#556a73;
  font-size:12px;
  padding:8px 10px;
  line-height:1.35;
  cursor:pointer;
}
.product-section-item:hover,.product-section-top:hover { background:#f2f6f7; }
.product-section-item.is-active {
  color:#165d55;
  background:#eaf5f2;
  font-weight:700;
  box-shadow:inset 2px 0 #27796f;
}
.product-section-item:focus-visible,.product-section-top:focus-visible {
  outline:2px solid #27796f;
  outline-offset:-2px;
}
.product-section-top {
  color:#607880;
  border-top:1px solid #edf0f2;
  margin-top:6px;
  padding-top:11px;
}
</style>
