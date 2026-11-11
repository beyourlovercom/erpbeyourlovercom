<script setup lang="ts">
import { computed } from 'vue'

type Row = Record<string, unknown>
const props = defineProps<{ params: { data?: Row } }>()
const allowed = ['unsynced', 'failed', 'synced', 'notified'] as const
const code = computed(() => {
  const value = String(props.params.data?.progress_code ?? 'unsynced')
  return allowed.find((item) => item === value) ?? 'unsynced'
})
const label = computed(() => String(props.params.data?.progress_label ?? '未同步'))
const reason = computed(() => String(props.params.data?.progress_reason ?? ''))
const showReason = () => { if (reason.value) window.alert(reason.value) }
</script>

<template>
  <button type="button" class="sync-grid-progress" :class="'sync-grid-progress-' + code" :title="reason" :aria-label="label + (reason ? '：' + reason : '')" :disabled="!reason" @click.stop="showReason">
    {{ label }}
  </button>
</template>

<style>
.sync-grid-progress{border:0;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;padding:3px 8px;min-width:72px;line-height:18px;border-radius:5px;font-weight:700;font-size:12px;white-space:nowrap}
.sync-grid-progress-unsynced{background:#edf0f3;color:#5f6b76}
.sync-grid-progress-failed{background:#fff1ef;color:#a72626}
.sync-grid-progress-synced{background:#def5ea;color:#176b56}
.sync-grid-progress-notified{background:#e6f1ff;color:#14579e}
</style>

<style>
.sync-grid-progress:disabled{cursor:default;opacity:1}
</style>
