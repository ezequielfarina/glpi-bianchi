<script setup lang="ts">
import { computed } from 'vue'

// Anel de uso (alocado × capacidade), animado. Sem capacidade: mostra só o valor alocado.
const props = defineProps<{ label: string; used: number; total?: number | null; unit?: string; format?: (n: number) => string; size?: number }>()
const pct = computed(() => (props.total ? Math.min(100, (100 * props.used) / props.total) : 0))
const r = 30
const c = 2 * Math.PI * r
const tone = computed(() => (pct.value >= 90 ? 'var(--bad)' : pct.value >= 75 ? 'var(--warn)' : 'var(--accent)'))
const fmt = (n: number) => (props.format ? props.format(n) : `${String(+n.toFixed(1)).replace('.', ',')}${props.unit ? ' ' + props.unit : ''}`)
</script>

<template>
  <div class="ring" :style="{ width: `${size ?? 104}px` }" role="img" :aria-label="`${label}: ${fmt(used)}${total ? ' de ' + fmt(total) : ''}`">
    <svg viewBox="0 0 80 80" :width="size ?? 104" :height="size ?? 104">
      <defs>
        <linearGradient :id="`g-${label}`" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" :stop-color="tone" stop-opacity="1" /><stop offset="100%" :stop-color="tone" stop-opacity=".55" /></linearGradient>
      </defs>
      <circle cx="40" cy="40" :r="r" class="track" />
      <circle v-if="total" cx="40" cy="40" :r="r" class="bar" :stroke="`url(#g-${label})`" :stroke-dasharray="c" :stroke-dashoffset="c * (1 - pct / 100)" />
      <circle v-else cx="40" cy="40" :r="r" class="bar dashed" />
      <text x="40" y="38" class="v">{{ total ? Math.round(pct) + '%' : fmt(used) }}</text>
      <text x="40" y="51" class="l">{{ label }}</text>
    </svg>
    <div class="cap">{{ fmt(used) }}<template v-if="total"> / {{ fmt(total) }}</template><template v-else> alocado</template></div>
  </div>
</template>

<style scoped>
.ring { display: flex; flex-direction: column; align-items: center; gap: 4px; }
svg { transform: rotate(-90deg); overflow: visible; }
.track { fill: none; stroke: var(--muted-soft); stroke-width: 7; }
.bar { fill: none; stroke-width: 7; stroke-linecap: round; transition: stroke-dashoffset 1s cubic-bezier(.2, .8, .2, 1); filter: drop-shadow(0 0 4px color-mix(in srgb, var(--accent) 50%, transparent)); }
.bar.dashed { stroke: var(--accent); stroke-dasharray: 3 5; opacity: .6; }
text { transform: rotate(90deg); transform-origin: 40px 40px; text-anchor: middle; fill: var(--text); }
.v { font: 600 13px var(--font, system-ui); }
.l { font: 9px var(--font, system-ui); fill: var(--text-3); text-transform: uppercase; letter-spacing: .05em; }
.cap { font-size: 11.5px; color: var(--text-2); text-align: center; font-family: var(--mono); }
</style>
