<script setup lang="ts">
import { computed, ref, watch } from 'vue'

type PermissionEntry = { key: string; label: string }
type PermissionGroup = { label: string; permissions: PermissionEntry[] }

const props = defineProps<{
  modelValue: string[]
  catalog: Record<string, PermissionGroup>
  disabled?: boolean
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: string[]): void
}>()

const selected = ref<Set<string>>(new Set(props.modelValue))

watch(() => props.modelValue, (val) => {
  selected.value = new Set(val)
})

function isChecked(key: string): boolean {
  return selected.value.has(key)
}

function toggle(key: string): void {
  if (props.disabled) return
  const next = new Set(selected.value)
  if (next.has(key)) next.delete(key)
  else next.add(key)
  selected.value = next
  emit('update:modelValue', [...next])
}

function selectAll(): void {
  if (props.disabled) return
  const all: string[] = []
  for (const group of Object.values(props.catalog)) {
    for (const p of group.permissions) all.push(p.key)
  }
  selected.value = new Set(all)
  emit('update:modelValue', all)
}

function clearAll(): void {
  if (props.disabled) return
  selected.value = new Set()
  emit('update:modelValue', [])
}

const groups = computed(() => Object.entries(props.catalog).map(([, group]) => group))
const totalCount = computed(() => groups.value.reduce((n, group) => n + group.permissions.length, 0))
const selectedCount = computed(() => groups.value.reduce((n, group) =>
  n + group.permissions.filter((permission) => selected.value.has(permission.key)).length, 0))
const groupCount = (group: PermissionGroup) =>
  group.permissions.filter((permission) => selected.value.has(permission.key)).length
</script>

<template>
  <div class="permission-matrix">
    <div class="permission-matrix-toolbar">
      <div class="permission-matrix-count">
        <strong>已选择 {{ selectedCount }} / {{ totalCount }} 项权限</strong>
        <span>点击任意一行即可切换勾选状态</span>
      </div>
      <div v-if="!disabled" class="permission-matrix-actions">
        <button type="button" class="mvp-button" @click="selectAll">全选</button>
        <button type="button" class="mvp-button" @click="clearAll">取消全选</button>
      </div>
    </div>

    <div class="permission-matrix-column-head" aria-hidden="true">
      <span>中文名称</span>
      <span>英文权限键（Permission Key）</span>
      <span>选择</span>
    </div>

    <section v-for="group in groups" :key="group.label" class="permission-group" :aria-label="group.label + '权限'">
      <div class="permission-group-heading">
        <h4 class="permission-group-label">{{ group.label }}</h4>
        <span class="permission-group-count">{{ groupCount(group) }} / {{ group.permissions.length }} 项</span>
      </div>
      <div class="permission-group-items">
        <label
          v-for="perm in group.permissions"
          :key="perm.key"
          class="permission-item"
          :class="{ 'permission-item-disabled': disabled, 'permission-item-checked': isChecked(perm.key) }"
        >
          <span class="permission-item-cn">{{ perm.label }}</span>
          <code class="permission-item-key">{{ perm.key }}</code>
          <input
            class="permission-item-check"
            type="checkbox"
            :checked="isChecked(perm.key)"
            :disabled="disabled"
            @change="toggle(perm.key)"
          />
        </label>
      </div>
    </section>
  </div>
</template>

<style scoped>
.permission-matrix {
  --permission-line: #e3e9e4;
  --permission-accent: var(--byl-accent, #17694d);
  width: 100%;
  min-width: 0;
  color: var(--byl-text, #243b30);
}
.permission-matrix-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  padding: 10px 0 15px;
}
.permission-matrix-count {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 12px;
}
.permission-matrix-count strong { color: var(--byl-text, #243b30); font-size: 13px; }
.permission-matrix-count span { color: var(--byl-text-muted, #829087); font-size: 11px; }
.permission-matrix-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.permission-matrix-column-head,
.permission-matrix .permission-item {
  display: grid;
  grid-template-columns: minmax(145px, 0.44fr) minmax(0, 0.56fr) 36px;
  align-items: center;
  column-gap: 12px;
}
.permission-matrix-column-head {
  padding: 9px 14px;
  border: 1px solid var(--permission-line);
  border-radius: 7px 7px 0 0;
  background: #f3f7f4;
  color: #61766a;
  font-size: 11px;
  font-weight: 700;
}
.permission-matrix-column-head span:last-child { text-align: center; }
.permission-group { margin: 0 0 11px; border: 1px solid var(--permission-line); border-top: 0; border-radius: 0 0 7px 7px; }
.permission-group + .permission-group { border-top: 1px solid var(--permission-line); border-radius: 7px; }
.permission-group-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 9px 14px;
  background: #f9fbf9;
  border-bottom: 1px solid var(--permission-line);
}
.permission-group-label { margin: 0; font-size: 13px; font-weight: 750; color: #315c43; }
.permission-group-count { font-size: 11px; color: #738579; font-variant-numeric: tabular-nums; }
.permission-matrix .permission-item {
  min-height: 48px;
  padding: 10px 14px;
  margin: 0;
  border-bottom: 1px dashed #cbd8cd;
  cursor: pointer;
  background: #fff;
  color: var(--byl-text, #243b30);
  transition: background-color .12s ease;
}
.permission-group-items .permission-item:last-child { border-bottom: 0; }
.permission-matrix .permission-item:hover { background: #f6faf7; }
.permission-matrix .permission-item:focus-within { outline: 2px solid var(--permission-accent); outline-offset: -2px; }
.permission-matrix .permission-item-checked { background: #f8fcf9; }
.permission-matrix .permission-item-disabled { cursor: default; }
.permission-matrix .permission-item-disabled:hover { background: #fff; }
.permission-item-cn { font-size: 12px; font-weight: 650; line-height: 1.45; color: var(--byl-text, #243b30); }
.permission-item-key {
  display: block;
  min-width: 0;
  font: 11px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  color: #6c8173;
  background: none;
  overflow-wrap: anywhere;
}
.permission-matrix .permission-item-check {
  display: block;
  justify-self: center;
  align-self: center;
  box-sizing: border-box;
  width: 18px;
  min-width: 18px;
  max-width: 18px;
  height: 18px;
  min-height: 18px;
  padding: 0;
  margin: 0;
  border-radius: 4px;
  accent-color: var(--permission-accent);
  cursor: pointer;
}
.permission-matrix .permission-item-check:disabled { cursor: default; }
@media (max-width: 620px) {
  .permission-matrix-column-head,
  .permission-matrix .permission-item {
    grid-template-columns: minmax(90px, 0.42fr) minmax(0, 0.58fr) 25px;
    column-gap: 7px;
  }
  .permission-matrix-column-head,
  .permission-matrix .permission-item { padding-left: 9px; padding-right: 9px; }
  .permission-matrix-column-head { font-size: 10px; }
  .permission-item-cn { font-size: 11px; }
  .permission-item-key { font-size: 10px; }
}
</style>
