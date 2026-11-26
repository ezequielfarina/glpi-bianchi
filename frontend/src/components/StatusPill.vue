<script setup lang="ts">
import { computed } from 'vue'
import { STATUS_PILL } from '../lib/format'

const props = defineProps<{ status: string | null }>()
const pill = computed(() => STATUS_PILL[props.status ?? ''] ?? { label: props.status ?? '—', cls: 'pill-muted' })
</script>

<template>
  <span class="pill" :class="pill.cls">{{ pill.label }}</span>
</template>
