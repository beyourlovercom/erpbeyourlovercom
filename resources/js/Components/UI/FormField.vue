<script setup lang="ts">
import { computed } from 'vue'

defineOptions({ inheritAttrs: false })

const props = withDefaults(defineProps<{
    id: string
    label: string
    description?: string
    error?: string
    required?: boolean
}>(), {
    description: undefined,
    error: undefined,
    required: false,
})

const descriptionId = computed(() => `${props.id}-description`)
const errorId = computed(() => `${props.id}-error`)
const describedBy = computed(() => [
    props.description ? descriptionId.value : undefined,
    props.error ? errorId.value : undefined,
].filter(Boolean).join(' ') || undefined)
</script>

<template>
    <div v-bind="$attrs" class="form-field">
        <label class="form-field__label" :for="id">
            <slot name="label">{{ label }}</slot>
            <span v-if="required" class="form-field__required">
                <span aria-hidden="true"> *</span><span class="form-field__sr-only">（必填）</span>
            </span>
        </label>
        <slot :control-id="id" :described-by="describedBy" :invalid="Boolean(error)" />
        <p v-if="description" :id="descriptionId" class="form-field__description">
            <slot name="description">{{ description }}</slot>
        </p>
        <p v-if="error" :id="errorId" class="form-field__error" role="alert">
            <slot name="error">{{ error }}</slot>
        </p>
    </div>
</template>

<style scoped>
.form-field {
    display: grid;
    gap: var(--byl-space-8);
}

.form-field__label {
    color: var(--byl-text-secondary);
    font-size: var(--byl-font-size-14);
}

.form-field__description {
    color: var(--byl-text-muted);
    font-size: var(--byl-font-size-12);
}

.form-field__error,
.form-field__required {
    color: var(--byl-danger);
}

.form-field__error {
    font-size: var(--byl-font-size-14);
}

.form-field__sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
</style>
