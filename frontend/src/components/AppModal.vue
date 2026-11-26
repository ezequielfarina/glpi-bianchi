<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import AppIcon from './AppIcon.vue'

// Diálogo acessível: foco preso dentro, ESC fecha, devolve o foco ao fechar.
defineProps<{ title: string; wide?: boolean }>()
const emit = defineEmits<{ close: [] }>()
const box = ref<HTMLElement | null>(null)
let previous: HTMLElement | null = null

function onKey(e: KeyboardEvent): void {
  if (e.key === 'Escape') emit('close')
  if (e.key !== 'Tab' || !box.value) return
  const f = box.value.querySelectorAll<HTMLElement>('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')
  const first = f[0]
  const last = f[f.length - 1]
  if (!first || !last) return
  if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus() }
  else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus() }
}
onMounted(async () => {
  previous = document.activeElement as HTMLElement | null
  document.addEventListener('keydown', onKey)
  await nextTick()
  box.value?.querySelector<HTMLElement>('input, select, textarea, button')?.focus()
})
onBeforeUnmount(() => { document.removeEventListener('keydown', onKey); previous?.focus() })
</script>

<template>
  <div class="backdrop" @mousedown.self="emit('close')">
    <div ref="box" class="modal panel" :class="{ wide }" role="dialog" aria-modal="true" :aria-label="title">
      <header class="panel-head">
        <h2>{{ title }}</h2>
        <button class="btn btn-icon" type="button" aria-label="Fechar" @click="emit('close')"><AppIcon name="close" :size="16" /></button>
      </header>
      <div class="modal-body"><slot /></div>
    </div>
  </div>
</template>

<style scoped>
.backdrop { position: fixed; inset: 0; z-index: 40; background: rgba(2, 6, 12, 0.6); display: grid; place-items: center; padding: 20px; overflow-y: auto; }
.modal { width: min(560px, 100%); max-height: calc(100vh - 40px); display: flex; flex-direction: column; }
.modal.wide { width: min(880px, 100%); }
.modal-body { padding: 0 20px 20px; overflow-y: auto; }
</style>
