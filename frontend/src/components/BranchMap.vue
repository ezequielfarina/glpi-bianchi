<script setup lang="ts">
// Mapa (Leaflet + OpenStreetMap) da matriz e das filiais.
// - modo visualização: marcadores clicáveis, enquadra todos os pontos;
// - modo seleção (v-model): clique no mapa ou arraste o marcador para definir lat/lng.
// Os tiles vêm do OSM pelo navegador do usuário (o servidor não acessa a internet).
import 'leaflet/dist/leaflet.css'
import L from 'leaflet'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

export interface MapPoint {
  id: number
  name: string
  code?: string | null
  kind: 'headquarters' | 'branch'
  latitude: number
  longitude: number
  subtitle?: string
}

const props = withDefaults(defineProps<{
  points?: MapPoint[]
  /** coordenada editável (modo seleção) */
  modelValue?: { lat: number; lng: number } | null
  picker?: boolean
  height?: string
}>(), { points: () => [], modelValue: null, picker: false, height: '360px' })
const emit = defineEmits<{ 'update:modelValue': [v: { lat: number; lng: number }]; select: [id: number] }>()

const el = ref<HTMLDivElement | null>(null)
let map: L.Map | null = null
let layer: L.LayerGroup | null = null
let pickMarker: L.Marker | null = null
let resize: ResizeObserver | null = null

const BRAZIL: L.LatLngTuple = [-14.2, -51.9]

function icon(kind: 'headquarters' | 'branch', label?: string | null): L.DivIcon {
  const text = (label ?? '').slice(0, 8).replace(/[<>&"]/g, '')
  return L.divIcon({
    className: `bm-pin bm-${kind}`,
    html: `<span class="bm-dot"></span>${text ? `<span class="bm-label">${text}</span>` : ''}`,
    iconSize: [18, 18],
    iconAnchor: [9, 9],
  })
}

function escape(s: string): string {
  return s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] ?? c)
}

function renderPoints(): void {
  if (!map || !layer) return
  layer.clearLayers()
  const bounds: L.LatLngTuple[] = []
  for (const p of props.points) {
    const ll: L.LatLngTuple = [p.latitude, p.longitude]
    bounds.push(ll)
    const m = L.marker(ll, { icon: icon(p.kind, p.code ?? p.name), title: p.name, keyboard: true })
    m.bindTooltip(`<strong>${escape(p.name)}</strong>${p.subtitle ? `<br>${escape(p.subtitle)}` : ''}`, { direction: 'top', offset: [0, -10] })
    m.on('click', () => emit('select', p.id))
    m.addTo(layer)
  }
  if (props.picker) return
  if (bounds.length === 1) map.setView(bounds[0]!, 15)
  else if (bounds.length > 1) map.fitBounds(bounds, { padding: [40, 40], maxZoom: 15 })
  else map.setView(BRAZIL, 4)
}

function renderPick(): void {
  if (!map || !props.picker) return
  const v = props.modelValue
  if (!v) {
    pickMarker?.remove()
    pickMarker = null
    return
  }
  const ll: L.LatLngTuple = [v.lat, v.lng]
  if (!pickMarker) {
    pickMarker = L.marker(ll, { icon: icon('branch'), draggable: true, title: 'Arraste para ajustar' }).addTo(map)
    pickMarker.on('dragend', () => {
      const p = pickMarker!.getLatLng()
      emit('update:modelValue', { lat: round(p.lat), lng: round(p.lng) })
    })
  } else {
    pickMarker.setLatLng(ll)
  }
  if (!map.getBounds().contains(ll)) map.setView(ll, Math.max(map.getZoom(), 15))
}

const round = (n: number) => Math.round(n * 1e6) / 1e6

onMounted(() => {
  if (!el.value) return
  map = L.map(el.value, { zoomControl: true, attributionControl: true, scrollWheelZoom: props.picker })
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
    // o nginx envia Referrer-Policy: no-referrer; o OSM exige Referer nos tiles
    referrerPolicy: 'strict-origin-when-cross-origin',
  }).addTo(map)
  layer = L.layerGroup().addTo(map)
  if (props.picker) {
    map.on('click', (e: L.LeafletMouseEvent) => emit('update:modelValue', { lat: round(e.latlng.lat), lng: round(e.latlng.lng) }))
    const v = props.modelValue
    map.setView(v ? [v.lat, v.lng] : BRAZIL, v ? 16 : 4)
  }
  renderPoints()
  renderPick()
  // o mapa pode nascer dentro de modal/aba ainda sem tamanho final
  resize = new ResizeObserver(() => map?.invalidateSize())
  resize.observe(el.value)
})

watch(() => props.points, renderPoints, { deep: true })
watch(() => props.modelValue, renderPick, { deep: true })

onBeforeUnmount(() => {
  resize?.disconnect()
  map?.remove()
  map = null
})

defineExpose({ focus: (lat: number, lng: number, zoom = 16) => map?.setView([lat, lng], zoom) })
</script>

<template>
  <div ref="el" class="branch-map" :style="{ height }" role="region" :aria-label="picker ? 'Mapa: clique para marcar a localização' : 'Mapa das unidades'" />
</template>

<style scoped>
.branch-map { width: 100%; border-radius: var(--radius-sm); border: 1px solid var(--border); overflow: hidden; z-index: 0; background: var(--bg-elev); }
/* tema escuro: escurece os tiles mantendo legibilidade (sem servidor de tiles próprio) */
:global([data-theme='dark'] .branch-map .leaflet-tile-pane) { filter: invert(1) hue-rotate(180deg) brightness(0.85) contrast(0.9) saturate(0.6); }
:global(.branch-map .leaflet-container) { font-family: var(--font); }
:global(.branch-map .leaflet-control-attribution) { font-size: 10px; background: color-mix(in srgb, var(--panel) 85%, transparent); color: var(--text-3); }
:global(.branch-map .leaflet-control-attribution a) { color: var(--text-2); }
:global(.branch-map .leaflet-tooltip) { background: var(--panel); color: var(--text); border: 1px solid var(--border); box-shadow: none; font-size: 12px; }
:global(.bm-pin) { display: flex; align-items: center; gap: 6px; }
:global(.bm-dot) { width: 18px; height: 18px; flex: none; border-radius: 50%; background: var(--accent); border: 3px solid var(--panel); box-shadow: 0 0 0 2px var(--accent), 0 2px 6px rgba(0, 0, 0, .35); }
:global(.bm-headquarters .bm-dot) { background: var(--warn); box-shadow: 0 0 0 2px var(--warn), 0 2px 6px rgba(0, 0, 0, .35); }
:global(.bm-label) { white-space: nowrap; font: 600 11px/1 var(--font); color: var(--text); background: var(--panel); border: 1px solid var(--border); padding: 3px 6px; border-radius: 6px; }
</style>
