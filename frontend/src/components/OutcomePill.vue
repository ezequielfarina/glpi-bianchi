<script setup lang="ts">
import { computed } from 'vue'
import { OUTCOME_PILL } from '../lib/format'

// Responde "o item já estava cadastrado?" para um inventário processado.
const props = defineProps<{ outcome: string | null | undefined; changes?: number | null }>()
const pill = computed(() => (props.outcome ? OUTCOME_PILL[props.outcome] : undefined))
</script>

<template>
  <span v-if="pill" class="pill" :class="pill.cls" :title="pill.hint">
    {{ pill.label }}<template v-if="outcome === 'updated' && changes"> · {{ changes }}</template>
  </span>
  <span v-else class="muted">—</span>
</template>
