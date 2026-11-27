<script lang="ts">
/** pilha de diálogos abertos (topo = último) */
const stack: symbol[] = []
</script>

<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import AppIcon from './AppIcon.vue'

// Diálogo acessível: foco preso dentro, ESC fecha, devolve o foco ao fechar.
// Empilhável (ex.: gerenciar categorias de dentro do formulário de licença):
// só o diálogo do topo reage a teclado; renderizado no <body> via Teleport.
defineProps<{ title: string; wide?: boolean; xwide?: boolean }>()
const emit = defineEmits<{ close: [] }>()
const box = ref<HTMLElement | null>(null)
let previous: HTMLElement | null = null
const id = Symbol('modal')
const depth = ref(0)

function onKey(e: KeyboardEvent): void {
  if (stack[stack.length - 1] !== id) return
  if (e.key === 'Escape') { e.stopPropagation(); emit('close') }
  if (e.key !== 'Tab' || !box.value) return
  const f = box.value.querySelectorAll<HTMLElement>('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')
  const first = f[0]
  const last = f[f.length - 1]
  if (!first || !last) return
  if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus() }
  else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus() }
}
onMounted(async () => {
  stack.push(id)
  depth.value = stack.length
  previous = document.activeElement as HTMLElement | null
  document.addEventListener('keydown', onKey)
  await nextTick()
  box.value?.querySelector<HTMLElement>('input, select, textarea, button')?.focus()
})
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKey)
  const i = stack.indexOf(id)
  if (i >= 0) stack.splice(i, 1)
  previous?.focus()
})
</script>

<template>
  <Teleport to="body">
  <div class="backdrop" :style="{ zIndex: 40 + depth * 2 }" @mousedown.self="emit('close')">
    <div ref="box" class="modal panel" :class="{ wide, xwide }" role="dialog" aria-modal="true" :aria-label="title">
      <header class="panel-head">
        <h2>{{ title }}</h2>
        <button class="btn btn-icon" type="button" aria-label="Fechar" @click="emit('close')"><AppIcon name="close" :size="16" /></button>
      </header>
      <div class="modal-body"><slot /></div>
    </div>
  </div>
  </Teleport>
</template>

<style scoped>
.backdrop { position: fixed; inset: 0; z-index: 40; background: rgba(2, 6, 12, 0.6); display: grid; place-items: center; padding: 20px; overflow-y: auto; }
.modal { width: min(560px, 100%); max-height: calc(100vh - 40px); display: flex; flex-direction: column; }
.modal.wide { width: min(880px, 100%); }
.modal.xwide { width: min(1240px, 100%); }
.modal-body { padding: 0 20px 20px; overflow-y: auto; }
</style>
