<script setup lang="ts">
import { onUnmounted, ref, watch } from 'vue'

type KolOption = { value: number; label: string; account?: string | null }
const props = defineProps<{
  modelValue: number | null
  initialOption?: KolOption | null
  hasError?: boolean
  required?: boolean
}>()
const emit = defineEmits<{ 'update:modelValue': [number | null] }>()

const initial = props.initialOption?.value === props.modelValue ? props.initialOption : null
const chosen = ref<KolOption | null>(initial)
const inputValue = ref(initial?.label ?? '')
const results = ref<KolOption[]>([])
const open = ref(false)
const loading = ref(false)
const errorMessage = ref('')
const activeIndex = ref(-1)
let debounceTimer: ReturnType<typeof setTimeout> | undefined
let blurTimer: ReturnType<typeof setTimeout> | undefined
let sequence = 0
let mounted = true

watch(() => props.modelValue, (value) => {
  if (value === null && chosen.value) {
    chosen.value = null
    inputValue.value = ''
  } else if (value !== null && props.initialOption?.value === value) {
    chosen.value = props.initialOption
    inputValue.value = chosen.value?.label ?? ''
  }
})
watch(() => props.initialOption, (option) => {
  if (option?.value === props.modelValue) {
    chosen.value = option
    inputValue.value = option.label
  }
})

onUnmounted(() => {
  mounted = false
  sequence++
  clearTimeout(debounceTimer)
  clearTimeout(blurTimer)
})


const search = async (term: string) => {
  const current = ++sequence
  loading.value = true
  errorMessage.value = ''
  open.value = true
  try {
    const response = await fetch('/kol/orders/kol-search?q=' + encodeURIComponent(term.trim()), {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })
    if (!response.ok) {
      throw new Error(response.status === 401 ? '登录已失效，请重新登录'
        : response.status === 403 ? '没有网红搜索权限，请联系管理员'
          : '网红查询暂时失败，请重试')
    }
    const data: unknown = await response.json()
    if (!Array.isArray(data)) throw new Error('网红查询返回的数据无效')
    if (!mounted || current !== sequence) return
    results.value = data as KolOption[]
    activeIndex.value = results.value.length > 0 ? 0 : -1
  } catch (error) {
    if (!mounted || current !== sequence) return
    results.value = []
    activeIndex.value = -1
    errorMessage.value = error instanceof Error && /登录已失效|没有网红搜索权限/.test(error.message)
      ? error.message : '网红查询暂时失败，请重试'
  } finally {
    if (mounted && current === sequence) loading.value = false
  }
}

const scheduleSearch = (term: string) => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => { void search(term) }, 200)
}

const onFocus = () => {
  clearTimeout(blurTimer)
  open.value = true
  void search(chosen.value ? '' : inputValue.value)
}

const onInput = (event: Event) => {
  inputValue.value = (event.target as HTMLInputElement).value
  // Invalidate even an in-flight focus lookup before the debounce timer fires.
  sequence++
  loading.value = true
  errorMessage.value = ''
  chosen.value = null
  emit('update:modelValue', null)
  results.value = []
  activeIndex.value = -1
  open.value = true
  scheduleSearch(inputValue.value)
}

const choose = (option: KolOption) => {
  sequence++
  clearTimeout(debounceTimer)
  chosen.value = option
  inputValue.value = option.label
  results.value = []
  open.value = false
  emit('update:modelValue', option.value)
}

const onBlur = () => {
  blurTimer = setTimeout(() => { open.value = false }, 150)
}

const moveActive = (direction: number) => {
  if (results.value.length === 0) return
  activeIndex.value = (activeIndex.value + direction + results.value.length) % results.value.length
}

const onKeydown = (event: KeyboardEvent) => {
  if (event.key === 'Escape') {
    open.value = false
  } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault()
    if (!open.value) {
      void search(chosen.value ? '' : inputValue.value)
    } else {
      moveActive(event.key === 'ArrowDown' ? 1 : -1)
    }
  } else if (event.key === 'Enter' && open.value && activeIndex.value >= 0) {
    const option = results.value[activeIndex.value]
    if (option) {
      event.preventDefault()
      choose(option)
    }
  }
}
</script>

<template>
  <div class="kol-identity-combobox">
    <div class="kol-identity-control">
      <input
        id="linked_kol_id"
        :value="inputValue"
        class="erp-input kol-identity-input"
        :class="{ 'has-error': hasError }"
        type="text"
        role="combobox"
        aria-autocomplete="list"
        aria-controls="kol-identity-results"
        :aria-expanded="open"
        :aria-activedescendant="open && activeIndex >= 0 ? 'kol-result-' + results[activeIndex]?.value : undefined"
        :aria-invalid="hasError || undefined"
        :required="required"
        autocomplete="off"
        placeholder="搜索网红姓名、账号或 ID…"
        data-testid="kol-identity-select"
        @focus="onFocus"
        @input="onInput"
        @keydown="onKeydown"
        @blur="onBlur"
      >
      <span class="kol-identity-chevron" aria-hidden="true">⌄</span>
    </div>
    <ul v-if="open" id="kol-identity-results" class="kol-identity-results" role="listbox">
      <li v-if="loading" class="kol-identity-state" aria-live="polite">正在搜索网红…</li>
      <li v-else-if="errorMessage" class="kol-identity-state" role="alert">{{ errorMessage }}</li>
      <li v-else-if="results.length === 0" class="kol-identity-state">没有匹配的网红档案</li>
      <li
        v-for="(item, index) in loading || errorMessage ? [] : results"
        :id="'kol-result-' + item.value"
        :key="item.value"
        role="option"
        :aria-selected="index === activeIndex"
        class="kol-identity-option"
        :class="{ active: index === activeIndex }"
        @mouseenter="activeIndex = index"
        @mousedown.prevent="choose(item)"
        @click.prevent="choose(item)"
      >
        <strong>{{ item.label }}</strong>
        <small>{{ item.account ? '@' + item.account.replace(/^@/, '') + ' · ' : '' }}ID {{ item.value }}</small>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.kol-identity-combobox { position: relative; width: 100%; }
.kol-identity-control { position: relative; }
.kol-identity-input { width: 100%; padding-right: 36px; }
.kol-identity-chevron { position: absolute; top: 50%; right: 13px; transform: translateY(-50%); pointer-events: none; color: #7a8c94; }
.kol-identity-results { position: absolute; z-index: 80; top: calc(100% + 5px); left: 0; right: 0; max-height: 304px; overflow-y: auto; margin: 0; padding: 6px; list-style: none; border: 1px solid #e4eaed; border-radius: 12px; background: #fff; box-shadow: 0 12px 30px rgba(15,23,42,.12); }
.kol-identity-option { display: grid; gap: 2px; padding: 9px 11px; border-radius: 8px; min-height: 58px; cursor: pointer; }
.kol-identity-option.active { background: #f1f7f7; }
.kol-identity-option strong { color: #142126; font-size: 14px; }
.kol-identity-option small, .kol-identity-state { color: #7a8c94; font-size: 12px; }
.kol-identity-state { padding: 12px; }
</style>
