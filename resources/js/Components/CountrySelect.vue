<script setup lang="ts">
import { computed, ref, watch, useId } from 'vue'
import { label, resolveCountry, searchCountries, type Country } from '../lib/countryStandard'

defineOptions({ inheritAttrs: false })
const props = defineProps<{ modelValue: string | null | undefined; placeholder?: string }>()
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
const input = ref<HTMLInputElement>()
const editing = ref(false)
const dirty = ref(false)
const query = ref('')
const error = ref(false)
const listId = useId()
const options = computed(() => searchCountries(query.value))
const display = (value: string | null | undefined) => {
  const country = resolveCountry(value)
  return country ? label(country) : value ?? ''
}
watch(() => props.modelValue, value => {
  if (!editing.value) { query.value = display(value); error.value = false; dirty.value = false }
}, { immediate: true })
function validity(message = '') {
  error.value = !!message
  input.value?.setCustomValidity(message)
}
function choose(country: Country) {
  emit('update:modelValue', country.code)
  query.value = label(country)
  editing.value = false
  dirty.value = false
  validity()
}
function onInput() {
  editing.value = true
  dirty.value = true
  const country = resolveCountry(query.value)
  // A non-exact value cannot silently save the previous selection.
  emit('update:modelValue', country?.code ?? query.value)
  validity(query.value.trim() && !country ? '请选择准确的国家。' : '')
}
function finish() {
  if (!dirty.value) { editing.value = false; return }
  if (!query.value.trim()) {
    emit('update:modelValue', '')
    query.value = ''
    dirty.value = false
    validity()
    editing.value = false
    return
  }
  const country = resolveCountry(query.value)
  if (country) choose(country)
  else { validity('请选择准确的国家。'); editing.value = false }
}
function onEnter(e: KeyboardEvent) {
  if (!editing.value && !dirty.value) return
  e.preventDefault()
  finish()
}
</script>

<template>
  <div class="country-select">
    <input ref="input" v-bind="$attrs" v-model="query" type="text" class="erp-input"
      :placeholder="placeholder || '搜索国家名称 / 代码'" autocomplete="off"
      role="combobox" aria-autocomplete="list" :aria-expanded="editing"
      :aria-controls="listId" :aria-invalid="error"
      @focus="editing = true" @input="onInput" @blur="finish" @keydown.enter="onEnter" @keydown.esc="editing = false">
    <div v-if="editing" :id="listId" role="listbox" class="country-options">
      <button v-for="country in options" :key="country.code" type="button" role="option"
        :aria-selected="resolveCountry(modelValue)?.code === country.code"
        @mousedown.prevent="choose(country)" @click="choose(country)">
        <span>{{ label(country) }}</span><small>{{ country.zh }}</small>
      </button>
      <div v-if="!options.length" class="country-hint">未匹配到国家，请重新输入。</div>
    </div>
    <small v-if="error" class="country-error">请选择准确的国家。</small>
    <small v-else-if="modelValue && !resolveCountry(modelValue) && !editing" class="country-error">历史国家待确认，请选择具体国家。</small>
  </div>
</template>

<style scoped>
.country-select{position:relative;min-width:0;width:100%}.country-select input{width:100%}.country-options{position:absolute;top:100%;left:0;right:0;z-index:80;max-height:240px;overflow:auto;background:#fff;border:1px solid #d8e0e4;border-radius:6px;box-shadow:0 6px 18px #0002;padding:4px}.country-options button{width:100%;display:flex;justify-content:space-between;gap:8px;text-align:left;padding:7px 9px;border:0;background:none;cursor:pointer;color:#25343c}.country-options button:hover,.country-options button:focus{background:#eef3f5}.country-options small{color:#64748b}.country-error{color:#b91c1c;display:block}.country-hint{padding:8px;color:#64748b}
</style>
