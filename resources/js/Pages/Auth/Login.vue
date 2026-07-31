<script setup lang="ts">
import { ref } from 'vue'
import { Head, useForm, usePage } from '@inertiajs/vue3'

const form = useForm({ email: '', password: '', remember: false })
const page = usePage<{ errors?: Record<string, string> }>()
const showPassword = ref(false)
const submit = () => form.post('/login')
</script>
<template>
  <Head><title>BYL ERP · 登录</title><meta name="robots" content="noindex, nofollow" /></Head>
  <main class="login-home min-h-screen overflow-hidden bg-[#07111f] text-slate-100">
    <div class="login-grid" aria-hidden="true"><span class="orbit orbit-a"></span><span class="orbit orbit-b"></span><i v-for="n in 7" :key="n" class="node" :class="`node-${n}`"></i></div>
    <header class="relative z-10 flex items-center justify-between px-6 py-6 lg:px-12"><div class="brand-lockup"><span class="brand-mark">BYL</span><span>ERP</span></div><div class="system-status"><span></span>SYSTEM ONLINE</div></header>
    <div class="relative z-10 mx-auto grid min-h-[calc(100vh-100px)] max-w-7xl items-center gap-12 px-6 pb-14 lg:grid-cols-2 lg:px-12"><section class="login-visual hidden lg:block"><p class="text-xs font-semibold tracking-[0.28em] text-cyan-300">BUSINESS OPERATING SYSTEM</p><div class="visual-line"></div><p class="max-w-sm text-sm leading-7 text-slate-400">让订单、客户、物流与团队协作保持清晰连接。</p></section><section class="login-panel w-full max-w-md justify-self-center lg:justify-self-end"><p class="text-xs font-semibold tracking-[0.24em] text-cyan-300">BUSINESS OPERATING SYSTEM</p><h1 class="mt-5 text-4xl font-semibold tracking-tight sm:text-5xl">统一管理<br><span class="text-cyan-200">高效协同</span></h1><p class="mt-5 text-sm leading-7 text-slate-400">连接订单、客户、物流与团队协作，<br>让每一项业务清晰可见、持续推进。</p><form class="mt-8 space-y-5" @submit.prevent="submit">
          <label class="block text-sm text-slate-300">邮箱<input v-model="form.email" name="email" type="email" autocomplete="username" required class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-3 text-sm outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/30"></label>
          <label class="block text-sm text-slate-300">密码<div class="mt-2 flex rounded-lg border border-slate-700 bg-slate-950 focus-within:border-cyan-300 focus-within:ring-2 focus-within:ring-cyan-300/30"><input v-model="form.password" name="password" :type="showPassword ? 'text' : 'password'" autocomplete="current-password" required class="min-w-0 flex-1 bg-transparent px-3 py-3 text-sm outline-none"><button type="button" class="px-3 text-xs text-slate-400 focus:text-cyan-200" @click="showPassword = !showPassword">{{ showPassword ? '隐藏' : '显示' }}</button></div></label>
          <p v-if="form.errors.email || form.errors.password || page.props.errors?.email" class="text-sm text-rose-300" role="alert">邮箱或密码不正确，请重试。</p>
          <button type="submit" :disabled="form.processing" class="w-full rounded-lg bg-cyan-300 px-4 py-3 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 disabled:cursor-not-allowed disabled:opacity-50">{{ form.processing ? '验证中…' : '登录系统' }}</button>
        </form><p class="mt-6 border-t border-slate-800 pt-5 text-xs text-slate-500">需要内部账户？ <span class="text-slate-300">申请开通账户</span>，由管理员完成配置。</p>
      </section>
    </div>
  </main>
</template>
<style scoped>
.login-grid{position:absolute;inset:0;background-image:linear-gradient(#16344a33 1px,transparent 1px),linear-gradient(90deg,#16344a33 1px,transparent 1px);background-size:64px 64px;mask-image:linear-gradient(90deg,#000,transparent 78%)}.orbit{position:absolute;border:1px solid #2a91a655;border-radius:50%;transform:rotate(-24deg)}.orbit-a{width:46vw;height:22vw;left:4vw;top:34vh}.orbit-b{width:32vw;height:14vw;left:15vw;top:47vh}.node{position:absolute;width:5px;height:5px;border-radius:50%;background:#8be9e4;box-shadow:0 0 18px #54d6d0}.node-1{left:18%;top:30%}.node-2{left:43%;top:22%}.node-3{left:35%;top:68%}.node-4{left:11%;top:73%}.node-5{left:48%;top:52%}.node-6{left:27%;top:46%}.node-7{left:55%;top:34%}.brand-lockup{display:flex;align-items:center;gap:8px;font-size:13px;letter-spacing:.18em;color:#9db6c6}.brand-mark{font-size:20px;font-weight:800;letter-spacing:.08em;color:#fff}.system-status{display:flex;align-items:center;gap:8px;font-size:10px;letter-spacing:.18em;color:#8da8b8}.system-status span{width:6px;height:6px;border-radius:50%;background:#65e2b2;box-shadow:0 0 10px #65e2b2}.visual-line{width:150px;height:1px;margin:28px 0 20px;background:#5fd7cf88}@media(max-width:1024px){.login-grid{mask-image:linear-gradient(90deg,#000,transparent 100%)}.login-panel{background:#07111fcc;padding:24px;border:1px solid #1d3a4d;border-radius:16px}}@media(prefers-reduced-motion:reduce){.orbit{transform:none}}
</style>
