<script setup lang="ts">
import AppIcon from './AppIcon.vue'
import { dismiss, toasts } from '../lib/toast'
</script>

<template>
  <div class="toasts" role="status" aria-live="polite">
    <div v-for="t in toasts" :key="t.id" class="toast" :class="t.tone">
      <AppIcon :name="t.tone === 'warn' ? 'alert' : 'check'" :size="18" />
      <div class="body">
        <strong>{{ t.title }}</strong>
        <p v-if="t.detail">{{ t.detail }}</p>
      </div>
      <button type="button" class="x" aria-label="Fechar notificação" @click="dismiss(t.id)"><AppIcon name="close" :size="14" /></button>
    </div>
  </div>
</template>

<style scoped>
.toasts { position: fixed; right: 20px; bottom: 20px; z-index: 50; display: flex; flex-direction: column; gap: 10px; width: min(420px, calc(100vw - 40px)); }
.toast {
  display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px; border-radius: var(--radius);
  background: var(--panel); border: 1px solid var(--border); box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
}
.toast.ok { color: var(--ok); }
.toast.info { color: var(--accent-text); }
.toast.warn { color: var(--warn-text); border-color: color-mix(in srgb, var(--warn) 40%, var(--border)); }
.body { flex: 1; color: var(--text); }
.body strong { font-weight: 600; }
.body p { margin: 4px 0 0; font-size: 12.5px; color: var(--text-2); }
.x { border: 0; background: transparent; color: var(--text-3); cursor: pointer; padding: 2px; border-radius: 6px; display: grid; }
.x:hover { color: var(--text); }
</style>
