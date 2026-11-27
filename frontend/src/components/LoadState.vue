<script setup lang="ts">
// Envolve conteúdo assíncrono: skeleton enquanto carrega, caixa de erro com "tentar de novo".
defineProps<{ loading: boolean; error: string | null; rows?: number; empty?: boolean; emptyText?: string }>()
defineEmits<{ retry: [] }>()
</script>

<template>
  <div v-if="error" class="error-box" role="alert">
    {{ error }}
    <button class="btn" type="button" style="margin-left: 12px" @click="$emit('retry')">Tentar de novo</button>
  </div>
  <div v-else-if="loading" aria-busy="true" aria-live="polite" class="skeletons">
    <span class="sr-only">Carregando…</span>
    <div v-for="i in rows ?? 4" :key="i" class="skeleton" style="height: 18px" />
  </div>
  <div v-else-if="empty" class="load-empty">{{ emptyText ?? 'Nada para mostrar.' }}</div>
  <slot v-else />
</template>

<style scoped>
.load-empty { padding: 28px 20px; text-align: center; color: var(--text-3); font-size: 13px; }
.skeletons { display: flex; flex-direction: column; gap: 12px; padding: 8px 0; }
</style>
