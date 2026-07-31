<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'

const form = useForm({ email: '', password: '', remember: false })
const page = usePage<{ errors?: Record<string, string> }>()
const showPassword = ref(false)
const submit = () => form.post('/login')
const logout = () => router.post('/logout')
</script>
<template>
  <main class="min-h-screen bg-slate-950 px-6 py-10 text-slate-100">
    <div class="mx-auto flex min-h-[calc(100vh-5rem)] max-w-6xl items-center justify-center">
      <section class="w-full max-w-md rounded-2xl border border-slate-800 bg-slate-900 p-8 shadow-2xl shadow-cyan-950/20">
        <p class="text-xs font-semibold tracking-[0.24em] text-cyan-300">BYL ERP · SYSTEM ONLINE</p>
        <h1 class="mt-5 text-3xl font-semibold tracking-tight">登录系统</h1>
        <p class="mt-3 text-sm leading-6 text-slate-400">使用已配置的内部账户访问 ERP 工作空间。</p>
        <form class="mt-8 space-y-5" @submit.prevent="submit">
          <label class="block text-sm text-slate-300">邮箱<input v-model="form.email" name="email" type="email" autocomplete="username" required class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-3 text-sm outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/30"></label>
          <label class="block text-sm text-slate-300">密码<div class="mt-2 flex rounded-lg border border-slate-700 bg-slate-950 focus-within:border-cyan-300 focus-within:ring-2 focus-within:ring-cyan-300/30"><input v-model="form.password" name="password" :type="showPassword ? 'text' : 'password'" autocomplete="current-password" required class="min-w-0 flex-1 bg-transparent px-3 py-3 text-sm outline-none"><button type="button" class="px-3 text-xs text-slate-400 focus:text-cyan-200" @click="showPassword = !showPassword">{{ showPassword ? '隐藏' : '显示' }}</button></div></label>
          <p v-if="form.errors.email || form.errors.password || page.props.errors?.email" class="text-sm text-rose-300" role="alert">邮箱或密码不正确，请重试。</p>
          <button type="submit" :disabled="form.processing" class="w-full rounded-lg bg-cyan-300 px-4 py-3 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 disabled:cursor-not-allowed disabled:opacity-50">{{ form.processing ? '验证中…' : '登录系统' }}</button>
        </form>
      </section>
    </div>
  </main>
</template>
