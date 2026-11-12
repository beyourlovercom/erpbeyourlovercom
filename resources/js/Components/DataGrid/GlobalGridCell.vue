<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'

type Row = Record<string, unknown>
export type GlobalGridAction = { label: string; href?: string; onClick?: () => void; danger?: boolean; disabled?: boolean }
export type GlobalGridCellParams = {
  value?: unknown
  data?: Row
  mode?: 'link' | 'image' | 'badge' | 'actions'
  hrefFor?: (row: Row) => string | null
  labelFor?: (row: Row) => string
  actionsFor?: (row: Row) => GlobalGridAction[]
  compactActions?: boolean
  imageAltFor?: (row: Row) => string
}
const props = defineProps<{ params: GlobalGridCellParams }>()
const row = computed<Row>(() => props.params.data ?? {})
const text = computed(() => props.params.labelFor?.(row.value) ?? (
  props.params.value === null || props.params.value === undefined || props.params.value === '' ? '—' : String(props.params.value)
))
const safeUrl = (raw: string | null | undefined): string | null => {
  if (!raw) return null
  if (raw.startsWith('/') && !raw.startsWith('//')) return raw
  if (raw.startsWith('https://')) return raw
  return null
}
const href = computed(() => safeUrl(props.params.hrefFor?.(row.value)))
const actions = computed(() => (props.params.actionsFor?.(row.value) ?? [])
  .map(action => ({ ...action, href: safeUrl(action.href) })))
const urlIsExternal = (url: string | null | undefined) => !!url?.startsWith('https://')
const compactOpen = ref(false)
const compactTrigger = ref<HTMLButtonElement | null>(null)
const compactMenu = ref<HTMLElement | null>(null)
const compactPosition = ref({ top: 0, left: 0 })
const compactMenuWidth = 144
const positionMenu = () => {
  if (!compactTrigger.value) return
  const bounds = compactTrigger.value.getBoundingClientRect()
  compactPosition.value = {
    top: bounds.bottom + 8 + Math.max(30, actions.value.length * 34) <= window.innerHeight
      ? bounds.bottom + 3 : Math.max(4, bounds.top - actions.value.length * 34 - 8),
    left: Math.max(4, Math.min(bounds.left, window.innerWidth - compactMenuWidth - 4)),
  }
}
const removeMenuListeners = () => {
  document.removeEventListener('pointerdown', closeOnOutside, true)
  document.removeEventListener('keydown', closeOnEscape, true)
  window.removeEventListener('scroll', closeMenu, true)
  window.removeEventListener('resize', closeMenu)
}
const closeMenu = () => {
  compactOpen.value = false
  removeMenuListeners()
}
const closeOnOutside = (event: PointerEvent) => {
  const target = event.target as Node
  if (compactTrigger.value?.contains(target) || compactMenu.value?.contains(target)) return
  closeMenu()
}
const closeOnEscape = (event: KeyboardEvent) => {
  if (event.key !== 'Escape') return
  closeMenu()
  compactTrigger.value?.focus()
}
const toggleMenu = () => {
  if (compactOpen.value) { closeMenu(); return }
  positionMenu()
  compactOpen.value = true
  document.addEventListener('pointerdown', closeOnOutside, true)
  document.addEventListener('keydown', closeOnEscape, true)
  window.addEventListener('scroll', closeMenu, true)
  window.addEventListener('resize', closeMenu)
}
const triggerAction = (action: { disabled?: boolean; onClick?: () => void }) => {
  if (action.disabled || !action.onClick) return
  closeMenu()
  action.onClick()
}
onBeforeUnmount(removeMenuListeners)

</script>
<template>
  <a v-if="params.mode === 'link' && href" :href="href" :target="urlIsExternal(href) ? '_blank' : undefined"
    :rel="urlIsExternal(href) ? 'noopener noreferrer' : undefined" class="global-cell-link">{{ text }}</a>
  <span v-else-if="params.mode === 'link'">{{ text }}</span>
  <img v-else-if="params.mode === 'image' && href" class="global-cell-thumb" :src="href" :alt="params.imageAltFor?.(row) ?? text" loading="lazy" referrerpolicy="no-referrer" />
  <span v-else-if="params.mode === 'image'">—</span>
  <span v-else-if="params.mode === 'badge'" class="global-cell-badge">{{ text }}</span>
  <div v-else-if="params.mode === 'actions'" class="global-cell-actions">
    <span v-if="!actions.length" class="global-cell-empty">—</span>
    <template v-else-if="params.compactActions && actions.length > 1">
      <button ref="compactTrigger" type="button" class="global-cell-menu-trigger" aria-label="操作"
        :aria-expanded="compactOpen" @click.stop="toggleMenu">操作 ▾</button>
      <Teleport to="body">
        <div v-if="compactOpen" ref="compactMenu" class="global-cell-menu" role="group" aria-label="订单操作"
          :style="{ top: compactPosition.top + 'px', left: compactPosition.left + 'px' }" @pointerdown.stop>
          <template v-for="(action, index) in actions" :key="index">
            <a v-if="action.href" :href="action.href" :target="urlIsExternal(action.href) ? '_blank' : undefined"
              :rel="urlIsExternal(action.href) ? 'noopener noreferrer' : undefined" @click="closeMenu">{{ action.label }}</a>
            <button v-else-if="action.onClick" type="button" :disabled="action.disabled"
              :class="{danger:action.danger}" @click.stop="triggerAction(action)">{{ action.label }}</button>
          </template>
        </div>
      </Teleport>
    </template>
    <template v-else v-for="(action, index) in actions" :key="index">
      <a v-if="action.href" :href="action.href" :target="urlIsExternal(action.href) ? '_blank' : undefined"
        :rel="urlIsExternal(action.href) ? 'noopener noreferrer' : undefined">{{ action.label }}</a>
      <button v-else-if="action.onClick" type="button" :disabled="action.disabled" :class="{danger:action.danger}" @click.stop="action.onClick()">{{ action.label }}</button>
    </template>
  </div>
  <span v-else>{{ text }}</span>
</template>
<!-- AG Grid creates dynamic cell renderers without Vue scope attributes.
     Unique global-cell-* classes keep these CSS rules component-specific. -->
<style>
.global-cell-link,.global-cell-actions a{color:#116b57;text-decoration:none;font-weight:600}
.global-cell-link:hover,.global-cell-actions a:hover{text-decoration:underline}
.global-cell-thumb{width:30px;height:30px;object-fit:contain;border-radius:4px;background:#f7f9f8;vertical-align:middle}
.global-cell-badge{display:inline-block;background:#eff6f4;color:#176b51;border-radius:4px;padding:2px 7px;font-size:11px;font-weight:600}
.global-cell-actions{display:inline-flex;align-items:center;gap:6px;white-space:nowrap;max-width:100%;vertical-align:middle}
.global-cell-actions>a,.global-cell-actions>button{display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;min-height:22px;box-sizing:border-box;padding:0 8px;font-size:12px;line-height:20px}
.global-cell-actions>:first-child{padding-left:2px}
.global-cell-actions>:not(:first-child){border-left:1px solid #dbe2e6}
.global-cell-actions button{border-top:0;border-right:0;border-bottom:0;background:transparent;cursor:pointer;color:#176b51}
.global-cell-actions button.danger{color:#ad352e}
.global-cell-actions button:disabled{opacity:.5;cursor:not-allowed}
.global-cell-actions>a:hover,.global-cell-actions>button:hover{background:#f1f6f4;border-radius:3px}
.global-cell-empty{color:#94a3b8;padding:0 5px}
.global-cell-actions .global-cell-menu-trigger{padding:0 8px;border:1px solid #cbd5e1;border-radius:4px;color:#176b51;background:white}
.global-cell-menu{position:fixed;z-index:12060;width:144px;max-width:calc(100vw - 8px);
  display:flex;flex-direction:column;padding:4px;box-sizing:border-box;border:1px solid #cbd5e1;
  border-radius:6px;background:white;box-shadow:0 10px 24px rgba(15,23,42,.18)}
.global-cell-menu>a,.global-cell-menu>button{display:flex;align-items:center;width:100%;min-height:30px;
  padding:4px 9px;box-sizing:border-box;font-size:12px;text-align:left;border:0;border-radius:4px;
  background:transparent;color:#176b51;cursor:pointer;text-decoration:none}
.global-cell-menu>a:hover,.global-cell-menu>button:hover{background:#f1f6f4}
.global-cell-menu>button.danger{color:#ad352e}
.global-cell-menu>button:disabled{opacity:.5;cursor:not-allowed}
</style>
