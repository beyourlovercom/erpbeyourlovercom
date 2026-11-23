<script setup lang="ts">
import { computed } from 'vue'
import { recipientCustomsSpec } from '../lib/recipientCustoms'

const props = withDefaults(defineProps<{
  country: string | null | undefined
  modelValue: string
  error?: string
}>(), { error: '' })
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
const spec = computed(() => recipientCustomsSpec(props.country))
</script>

<template>
  <div v-if="spec" class="erp-form-field" data-testid="recipient-customs-field">
    <label class="erp-form-label" for="recipient-customs-id">
      {{ spec.inputLabel }} <span v-if="spec.required" class="erp-form-required">*</span>
    </label>
    <input
      id="recipient-customs-id"
      data-testid="recipient-customs-input"
      :value="modelValue"
      type="text"
      class="erp-input"
      :class="{ 'has-error': error }"
      :required="spec.required"
      maxlength="128"
      autocomplete="off"
      placeholder="请填写收件人的清关号码"
      @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    >
    <small data-testid="recipient-customs-hint">{{ spec.hint }}</small>
    <p v-if="error" class="erp-field-error" role="alert">{{ error }}</p>
  </div>
</template>
