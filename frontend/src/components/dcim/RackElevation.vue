<script setup lang="ts">
import { computed } from 'vue'
import { hex, onColor, type RackDetail, type RackDevice } from '../../lib/dcim'

// Elevação do rack (uma face): unidades numeradas, equipamentos ocupando u_height unidades na cor da função.
// Equipamento de profundidade total da outra face aparece esmaecido (ocupa a unidade). Clique na U vazia = adicionar.
const props = defineProps<{ rack: RackDetail; face: 'front' | 'rear'; canAdd?: boolean }>()
const emit = defineEmits<{ add: [position: number]; open: [id: number] }>()
const H = 22
const units = computed(() => {
  const list: number[] = []
  for (let u = Number(props.rack.starting_unit); u < Number(props.rack.starting_unit) + Number(props.rack.u_height); u++) list.push(u)
  return props.rack.desc_units ? list : list.reverse()
})
const top = computed(() => Number(props.rack.starting_unit) + Number(props.rack.u_height) - 1)
type Placed = { d: RackDevice; y: number; h: number; ghost: boolean }
const placed = computed<Placed[]>(() => props.rack.devices
  .filter((d) => d.position !== null && d.u_height > 0 && (d.face === props.face || d.is_full_depth))
  .map((d) => {
    const pos = Number(d.position)
    const y = props.rack.desc_units ? (pos - Number(props.rack.starting_unit)) * H : (top.value - (pos + Number(d.u_height) - 1)) * H
    return { d, y, h: Number(d.u_height) * H, ghost: d.face !== props.face }
  }))
const occupied = computed(() => {
  const s = new Set<number>()
  for (const p of placed.value) {
    const start = Number(p.d.position)
    const end = start + Number(p.d.u_height)
    for (let u = start; u < end && u < start + 200; u += 0.5) s.add(Math.floor(u))
  }
  return s
})
</script>

<template>
  <div class="elev" :style="{ height: `${rack.u_height * H}px` }" role="list" :aria-label="`Rack ${rack.name}, face ${face === 'front' ? 'frontal' : 'traseira'}`">
    <div v-for="u in units" :key="u" class="u" role="listitem">
      <span class="n">{{ u }}</span>
      <button v-if="canAdd && !occupied.has(u)" type="button" class="slot" :title="`Adicionar equipamento na U${u}`" @click="emit('add', u)"><span>+</span></button>
      <span v-else class="slot ro" />
    </div>
    <button v-for="p in placed" :key="p.d.id" type="button" class="dev" :class="{ ghost: p.ghost, off: p.d.status !== 'active' }"
      :style="{ top: `${p.y}px`, height: `${p.h - 2}px`, background: hex(p.d.color), color: onColor(p.d.color) }"
      :title="`${p.d.name} — ${p.d.manufacturer} ${p.d.device_type} (U${p.d.position}${p.d.u_height > 1 ? '–' + ((p.d.position as number) + p.d.u_height - 1) : ''})${p.ghost ? ' · ocupa pela outra face (profundidade total)' : ''}`"
      @click="emit('open', p.d.id)">
      <span class="name">{{ p.d.name }}</span><span v-if="p.h > H" class="sub">{{ p.d.device_type }}</span>
    </button>
  </div>
</template>

<style scoped>
.elev { position: relative; width: 100%; max-width: 340px; border: 2px solid var(--border); border-radius: 6px; background: var(--bg-elev); margin-left: 26px; }
.u { height: 22px; display: flex; align-items: stretch; border-bottom: 1px dashed var(--border-soft); position: relative; }
.n { position: absolute; left: -26px; width: 22px; text-align: right; font: 11px/22px var(--mono); color: var(--text-3); }
.slot { flex: 1; border: 0; background: transparent; padding: 0; cursor: pointer; color: transparent; font-size: 14px; }
.slot:hover, .slot:focus-visible { background: var(--accent-soft); color: var(--accent-text); }
.slot.ro { cursor: default; }
.dev { position: absolute; left: 2px; right: 2px; border: 0; border-radius: 4px; padding: 0 8px; display: flex; flex-direction: column; justify-content: center; align-items: flex-start;
  cursor: pointer; overflow: hidden; text-align: left; box-shadow: inset 0 0 0 1px rgba(0,0,0,.25); font-size: 12px; line-height: 1.2; }
.dev:hover, .dev:focus-visible { outline: 2px solid var(--accent); outline-offset: 1px; z-index: 2; }
.dev.ghost { opacity: .35; background-image: repeating-linear-gradient(45deg, transparent 0 6px, rgba(0,0,0,.25) 6px 8px) !important; }
.dev.off { filter: grayscale(.6); }
.name { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
.sub { font-size: 10.5px; opacity: .85; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
</style>
