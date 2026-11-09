<script setup lang="ts">
import { computed } from 'vue'

type Row = Record<string, unknown>
export type GlobalGridAction = { label: string; href?: string; onClick?: () => void; danger?: boolean }
export type GlobalGridCellParams = {
  value?: unknown
  data?: Row
  mode?: 'link' | 'image' | 'badge' | 'actions'
  hrefFor?: (row: Row) => string | null
  labelFor?: (row: Row) => string
  actionsFor?: (row: Row) => GlobalGridAction[]
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
</script>
<template>
  <a v-if="params.mode === 'link' && href" :href="href" :target="urlIsExternal(href) ? '_blank' : undefined"
    :rel="urlIsExternal(href) ? 'noopener noreferrer' : undefined" class="global-cell-link">{{ text }}</a>
  <span v-else-if="params.mode === 'link'">{{ text }}</span>
  <img v-else-if="params.mode === 'image' && href" class="global-cell-thumb" :src="href" :alt="params.imageAltFor?.(row) ?? text" loading="lazy" referrerpolicy="no-referrer" />
  <span v-else-if="params.mode === 'image'">—</span>
  <span v-else-if="params.mode === 'badge'" class="global-cell-badge">{{ text }}</span>
  <div v-else-if="params.mode === 'actions'" class="global-cell-actions">
    <template v-for="(action, index) in actions" :key="index">
      <a v-if="action.href" :href="action.href" :target="urlIsExternal(action.href) ? '_blank' : undefined"
        :rel="urlIsExternal(action.href) ? 'noopener noreferrer' : undefined">{{ action.label }}</a>
      <button v-else-if="action.onClick" type="button" :class="{danger:action.danger}" @click.stop="action.onClick()">{{ action.label }}</button>
    </template>
  </div>
  <span v-else>{{ text }}</span>
</template>
<style scoped>
.global-cell-link,.global-cell-actions a{color:#116b57;text-decoration:none;font-weight:600}
.global-cell-link:hover,.global-cell-actions a:hover{text-decoration:underline}
.global-cell-thumb{width:30px;height:30px;object-fit:contain;border-radius:4px;background:#f7f9f8;vertical-align:middle}
.global-cell-badge{display:inline-block;background:#eff6f4;color:#176b51;border-radius:4px;padding:2px 7px;font-size:11px;font-weight:600}
.global-cell-actions{display:flex;align-items:center;gap:9px;white-space:nowrap}
.global-cell-actions button{border:0;background:transparent;cursor:pointer;padding:0;color:#176b51;font-size:12px}
.global-cell-actions button.danger{color:#ad352e}
</style>
