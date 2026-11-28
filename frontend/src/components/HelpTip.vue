<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref } from 'vue'
import { helpFor, type Help } from '../lib/fieldHelp'

// Ícone (!) ao lado do rótulo: passar o mouse, focar com Tab ou clicar mostra como preencher e um exemplo.
const props = defineProps<{ k?: string; ctx?: string; text?: string; example?: string; label?: string }>()
const help = computed<Help | undefined>(() => (props.text ? { t: props.text, ex: props.example } : props.k ? helpFor(props.ctx, props.k) : undefined))
const open = ref(false)
const pinned = ref(false)
const btn = ref<HTMLButtonElement | null>(null)
const pos = ref({ top: 0, left: 0, up: false })
const id = `help-${Math.random().toString(36).slice(2, 9)}`
async function show(): Promise<void> {
  open.value = true
  await nextTick()
  const r = btn.value?.getBoundingClientRect()
  if (!r) return
  const up = r.bottom + 160 > window.innerHeight
  pos.value = { top: up ? r.top - 8 : r.bottom + 8, left: Math.min(Math.max(12, r.left - 140), window.innerWidth - 312), up }
}
function hide(): void { if (!pinned.value) open.value = false }
function toggle(): void { pinned.value = !pinned.value; if (pinned.value) void show(); else open.value = false }
function outside(e: MouseEvent): void { if (pinned.value && btn.value && !btn.value.contains(e.target as Node)) { pinned.value = false; open.value = false } }
document.addEventListener('click', outside, true)
onBeforeUnmount(() => document.removeEventListener('click', outside, true))
</script>

<template>
  <span v-if="help" class="help">
    <button ref="btn" type="button" class="help-btn" :aria-label="`Ajuda${label ? ': ' + label : ''}`" :aria-describedby="open ? id : undefined" :aria-expanded="open"
      @mouseenter="show" @mouseleave="hide" @focus="show" @blur="hide" @click.prevent.stop="toggle" @keydown.esc="pinned = false; open = false">!</button>
    <Teleport to="body">
      <Transition name="help-pop">
        <span v-if="open" :id="id" role="tooltip" class="help-pop" :class="{ up: pos.up }" :style="{ top: `${pos.top}px`, left: `${pos.left}px` }">
          <span class="t">{{ help.t }}</span>
          <span v-if="help.ex" class="ex"><span>Exemplo</span> {{ help.ex }}</span>
        </span>
      </Transition>
    </Teleport>
  </span>
</template>

<style scoped>
.help { display: inline-flex; vertical-align: middle; margin-left: 6px; }
.help-btn {
  width: 16px; height: 16px; border-radius: 50%; border: 0; padding: 0; cursor: help;
  display: inline-grid; place-items: center; font: 700 11px/1 var(--font, system-ui);
  background: var(--accent-soft); color: var(--accent-text); box-shadow: 0 0 0 1px color-mix(in srgb, var(--accent) 45%, transparent);
  transition: transform .15s ease, background .15s ease;
}
.help-btn:hover, .help-btn:focus-visible, .help-btn[aria-expanded='true'] { background: var(--accent); color: var(--accent-fg); transform: scale(1.12); outline: none; }
</style>
<style>
.help-pop {
  position: fixed; z-index: 3000; width: 300px; display: flex; flex-direction: column; gap: 8px; padding: 12px 14px;
  border-radius: 12px; font-size: 12.5px; line-height: 1.45; color: var(--text); text-transform: none; letter-spacing: normal; font-weight: 400;
  background: color-mix(in srgb, var(--panel) 88%, transparent); backdrop-filter: blur(14px) saturate(1.4);
  border: 1px solid color-mix(in srgb, var(--accent) 35%, var(--border)); box-shadow: 0 18px 40px -12px rgba(0, 0, 0, .55), 0 0 0 1px rgba(255, 255, 255, .03) inset;
}
.help-pop.up { transform: translateY(-100%); }
.help-pop .ex { font-family: var(--mono); font-size: 12px; color: var(--accent-text); background: var(--accent-soft); border-radius: 8px; padding: 6px 8px; }
.help-pop .ex span { display: block; font-family: var(--font, system-ui); font-size: 10.5px; text-transform: uppercase; letter-spacing: .06em; color: var(--text-3); margin-bottom: 2px; }
.help-pop-enter-active, .help-pop-leave-active { transition: opacity .14s ease, translate .14s ease; }
.help-pop-enter-from, .help-pop-leave-to { opacity: 0; translate: 0 4px; }
</style>
