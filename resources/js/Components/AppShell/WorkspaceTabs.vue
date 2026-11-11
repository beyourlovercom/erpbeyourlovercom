<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import {
  closeWorkspaceTab, restoreWorkspaceTabs, storageKeyForUser,
  visitWorkspaceTab, workspaceKey, type WorkspaceTab, WORKSPACE_TAB_LIMIT,
} from '../../lib/workspaceTabs'

const props = defineProps<{ currentUrl: string; currentTitle: string; userId: number }>()
const tabs = ref<WorkspaceTab[]>([])
const ready = ref(false)
const notice = ref('')
const dirty = ref(false)
const strip = ref<HTMLElement | null>(null)
const activeKey = computed(() => workspaceKey(props.currentUrl))
const keyForStorage = computed(() => storageKeyForUser(props.userId))

const save = () => {
  if (!ready.value) return
  try { window.sessionStorage.setItem(keyForStorage.value, JSON.stringify(tabs.value)) }
  catch { /* private mode: navigation still works without persistence */ }
}
const visitCurrent = () => {
  if (!ready.value) return
  const result = visitWorkspaceTab(tabs.value, props.currentUrl, props.currentTitle)
  tabs.value = result.tabs
  notice.value = result.evicted ? '已关闭最久未访问的工作标签（最多 30 个）' : ''
  save()
  void nextTick(() => strip.value?.querySelector<HTMLElement>('[aria-current="page"]')?.scrollIntoView({
    inline: 'nearest', block: 'nearest',
  }))
}
const reloadForUser = () => {
  let saved: string | null = null
  try { saved = window.sessionStorage.getItem(keyForStorage.value) } catch { /* optional */ }
  tabs.value = restoreWorkspaceTabs(saved)
  ready.value = true
  visitCurrent()
}
watch(() => props.currentUrl, (next, prev) => {
  if (next !== prev) {
    dirty.value = false
    visitCurrent()
  }
})
watch(() => props.currentTitle, () => visitCurrent())
watch(() => props.userId, () => {
  ready.value = false
  tabs.value = []
  dirty.value = false
  reloadForUser()
})
const allowLeave = (): boolean => {
  if (!dirty.value) return true
  return window.confirm('本页面有未保存的编辑，切换工作标签可能丢失内容。确定继续吗？')
}
const open = (tab: WorkspaceTab) => {
  if (tab.key === activeKey.value && tab.href === props.currentUrl) return
  if (!allowLeave()) return
  router.visit(tab.href, { preserveState: false, preserveScroll: false })
}
const close = (tab: WorkspaceTab) => {
  if (tab.key === activeKey.value && !allowLeave()) return
  const result = closeWorkspaceTab(tabs.value, tab.key, activeKey.value)
  tabs.value = result.tabs
  save()
  if (result.nextHref) router.visit(result.nextHref, { preserveState: false, preserveScroll: false })
}
const onDirtyEvent = (event: Event) => {
  const detail = (event as CustomEvent<{ dirty?: boolean }>).detail
  dirty.value = detail?.dirty === true
}
const onBeforeUnload = (event: BeforeUnloadEvent) => {
  if (!dirty.value) return
  event.preventDefault()
  event.returnValue = ''
}
onMounted(() => {
  reloadForUser()
  window.addEventListener('erp:workspace-dirty', onDirtyEvent)
  window.addEventListener('beforeunload', onBeforeUnload)
})
onBeforeUnmount(() => {
  window.removeEventListener('erp:workspace-dirty', onDirtyEvent)
  window.removeEventListener('beforeunload', onBeforeUnload)
})
</script>

<template>
  <nav v-if="ready && tabs.length" class="erp-workspace-tabs" aria-label="ERP 工作标签">
    <div ref="strip" class="erp-workspace-tab-strip">
      <div
        v-for="tab in tabs" :key="tab.key" class="erp-workspace-tab"
        :class="{ 'is-active': tab.key === activeKey }"
      >
        <button
          type="button" class="erp-workspace-tab-open"
          :title="tab.title" :aria-current="tab.key === activeKey ? 'page' : undefined"
          @click="open(tab)"
        >{{ tab.title }}</button>
        <button
          type="button" class="erp-workspace-tab-close"
          :aria-label="'关闭工作标签：' + tab.title" :title="'关闭：' + tab.title"
          @click="close(tab)"
        >×</button>
      </div>
    </div>
    <span class="erp-workspace-tab-count" :title="'最多 ' + WORKSPACE_TAB_LIMIT + ' 个工作标签'">{{ tabs.length }}/{{ WORKSPACE_TAB_LIMIT }}</span>
    <span class="sr-only" role="status" aria-live="polite">{{ notice }}</span>
  </nav>
</template>

<style scoped>
.erp-workspace-tabs {
  display: flex;
  align-items: center;
  min-height: 38px;
  min-width: 0;
  padding: 0 12px;
  gap: 8px;
  background: var(--byl-surface, #fff);
  border-bottom: 1px solid var(--byl-border-subtle, #e6e9ef);
}
.erp-workspace-tab-strip {
  display: flex;
  align-items: center;
  min-width: 0;
  flex: 1;
  overflow-x: auto;
  scrollbar-width: thin;
  gap: 4px;
  padding: 4px 0;
}
.erp-workspace-tab {
  display: inline-flex;
  align-items: center;
  flex: 0 0 auto;
  max-width: 230px;
  border: 1px solid var(--byl-border-subtle, #e6e9ef);
  border-radius: 6px;
  background: var(--byl-canvas, #f7f8fa);
}
.erp-workspace-tab.is-active {
  background: var(--byl-surface, #fff);
  border-color: #28847b;
}
.erp-workspace-tab-open,
.erp-workspace-tab-close {
  background: transparent;
  color: var(--byl-text, #293744);
  border: 0;
  font: inherit;
  cursor: pointer;
  min-height: 27px;
}
.erp-workspace-tab-open {
  max-width: 185px;
  padding: 3px 4px 3px 10px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 12px;
}
.erp-workspace-tab-close {
  width: 27px;
  font-size: 16px;
  opacity: .67;
  line-height: 1;
}
.erp-workspace-tab-open:focus-visible,
.erp-workspace-tab-close:focus-visible { outline: 2px solid #28847b; outline-offset: 2px; }
.erp-workspace-tab-close:hover { opacity: 1; color: #a33333; }
.erp-workspace-tab-count { flex: 0 0 auto; color: var(--byl-text-muted, #687987); font-size: 11px; }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
@media (max-width: 800px) {
  .erp-workspace-tabs { min-height: 35px; padding: 0 6px; }
  .erp-workspace-tab-open { max-width: 145px; }
}
</style>
