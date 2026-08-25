<script setup lang="ts">
import { ref, watch } from 'vue'

type SkuOption = { id: number; sku: string; name: string }

const props = defineProps<{ modelValue: string; productId?: number | null }>()
const emit = defineEmits<{ 'update:modelValue': [string]; 'update:productId': [number | null] }>()

const inputValue = ref(props.modelValue)
const options = ref<SkuOption[]>([])
const open = ref(false)
const loading = ref(false)
let debounceTimer: ReturnType<typeof setTimeout> | undefined

watch(() => props.modelValue, (v) => { inputValue.value = v })

const fetchOptions = async (q: string) => {
  if (q.trim() === '') { options.value = []; open.value = false; return }
  loading.value = true
  try {
    const res = await fetch(`/products/sku-search?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } })
    options.value = await res.json()
    open.value = options.value.length > 0
  } finally {
    loading.value = false
  }
}

const onInput = (e: Event) => {
  const val = (e.target as HTMLInputElement).value
  inputValue.value = val
  emit('update:modelValue', val)
  emit('update:productId', null)
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => fetchOptions(val), 200)
}

const select = (opt: SkuOption) => {
  inputValue.value = opt.sku
  emit('update:modelValue', opt.sku)
  emit('update:productId', opt.id)
  open.value = false
  options.value = []
}

const onBlur = () => { setTimeout(() => { open.value = false }, 150) }
</script>

<template>
  <div class="sku-combobox">
    <input
      :value="inputValue"
      type="text"
      placeholder="输入 SKU 或产品名称…"
      autocomplete="off"
      @input="onInput"
      @blur="onBlur"
    >
    <ul v-if="open" class="sku-combobox-dropdown">
      <li v-for="opt in options" :key="opt.id" @mousedown.prevent="select(opt)">
        <strong>{{ opt.sku }}</strong> — {{ opt.name }}
      </li>
    </ul>
  </div>
</template>
