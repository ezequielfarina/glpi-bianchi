<script setup lang="ts">
defineProps<{ label: string; value: string; note?: string; tone?: 'default' | 'ok' | 'warn'; to?: string }>()
</script>

<template>
  <component :is="to ? 'RouterLink' : 'div'" :to="to" class="kpi panel" :class="{ link: !!to }">
    <span class="label">{{ label }}</span>
    <span class="value num">{{ value }}</span>
    <span v-if="note" class="note" :class="tone">{{ note }}</span>
  </component>
</template>

<style scoped>
.kpi { display: flex; flex-direction: column; gap: 8px; padding: 18px 20px; color: var(--text); text-decoration: none; }
.kpi.link:hover { border-color: var(--accent); text-decoration: none; }
.label { font-size: 12px; font-weight: 500; color: var(--text-3); text-transform: uppercase; letter-spacing: 0.06em; }
.value { font-size: 30px; font-weight: 600; letter-spacing: -0.02em; line-height: 1.1; }
.note { font-size: 12.5px; color: var(--text-3); }
.note.ok { color: var(--ok); }
.note.warn { color: var(--warn-text); }
</style>
