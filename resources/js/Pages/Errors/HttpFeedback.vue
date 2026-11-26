<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import GlobalFeedbackIsland from '../../Components/AppShell/GlobalFeedbackIsland.vue'
import { canonicalFeedbackCopyText, copyFailureFeedback, copySuccessFeedback, globalFeedback, publishGlobalFeedback, scheduleGlobalFeedbackRestore } from '../../lib/globalFeedback'
import { httpFeedback } from '../../lib/httpFeedback'
const props = defineProps<{status: number; errorId: string}>()
const value = httpFeedback(props.status, props.errorId)
if (value) publishGlobalFeedback(value)
async function copy() {
    const original = globalFeedback.value
    try {
        await navigator.clipboard.writeText(canonicalFeedbackCopyText(original))
        if (globalFeedback.value !== original) return
        const overlay = copySuccessFeedback()
        publishGlobalFeedback(overlay)
        scheduleGlobalFeedbackRestore(overlay, original)
    } catch (error) {
        if (globalFeedback.value === original) publishGlobalFeedback(copyFailureFeedback(error))
    }
}
</script>
<template>
  <Head title="访问提示 · BYL ERP" />
  <main style="min-height:100vh;display:grid;place-items:center;background:#f4f7f8;color:#17303a">
    <GlobalFeedbackIsland :feedback="globalFeedback" @copy="copy"/>
    <a href="/dashboard" class="mvp-button">返回可访问页面</a>
  </main>
</template>
