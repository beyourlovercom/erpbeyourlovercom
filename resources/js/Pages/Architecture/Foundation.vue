<script setup lang="ts">
import { ref } from 'vue'

const props = defineProps<{ title: string; status: string }>()
const acknowledged = ref(false)
</script>

<template>
    <main class="mx-auto flex min-h-screen max-w-3xl items-center px-6 py-16">
        <section class="w-full rounded-2xl border border-slate-800 bg-slate-900/80 p-8 shadow-2xl shadow-cyan-950/20">
            <p class="text-xs font-semibold uppercase tracking-[0.24em] text-cyan-300">{{ props.status }}</p>
            <h1 class="mt-4 text-3xl font-semibold tracking-tight text-white">{{ props.title }}</h1>
            <p class="mt-4 max-w-xl text-sm leading-7 text-slate-300">Inertia.js、Vue 3、TypeScript 与 Tailwind CSS foundation validation page。</p>
            <button class="mt-8 rounded-lg border border-cyan-400/50 px-4 py-2 text-sm font-medium text-cyan-200 transition hover:bg-cyan-400/10 focus:outline-none focus:ring-2 focus:ring-cyan-300 focus:ring-offset-2 focus:ring-offset-slate-900" type="button" @click="acknowledged = !acknowledged">
                {{ acknowledged ? 'Foundation 已确认' : '确认基础可用' }}
            </button>
        </section>
    </main>
</template>
