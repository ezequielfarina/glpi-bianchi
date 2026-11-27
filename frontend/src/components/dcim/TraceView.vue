<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from '../AppIcon.vue'
import { hex, length, onColor, speed, uLabel, type Trace, type TraceCable, type TraceNode } from '../../lib/dcim'

// Desenho vertical do caminho (como o SVG de trace do NetBox): blocos de equipamento/circuito e,
// entre eles, o cabo na cor dele, com etiqueta, tipo e comprimento. Entrada e saída do mesmo
// equipamento (frontal ↔ traseira) ficam no mesmo bloco.
const props = defineProps<{ trace: Trace; highlight?: number | null }>()
const emit = defineEmits<{ navigate: [] }>()

type Block = { t: 'device'; key: string; head: TraceNode; ports: TraceNode[][] } | { t: 'circuit'; key: string; head: TraceNode; ends: TraceNode[][] } | { t: 'cable'; key: string; cable: TraceCable }
const blocks = computed<Block[]>(() => {
  const out: Block[] = []
  props.trace.hops.forEach((h, i) => {
    if (h.t === 'cable') { out.push({ t: 'cable', key: `c${i}`, cable: h.cable }); return }
    const head = h.nodes[0]
    if (!head) return
    const last = out[out.length - 1]
    if (head.node === 'circuit') {
      if (last?.t === 'circuit' && last.head.circuit_id === head.circuit_id) last.ends.push(h.nodes)
      else out.push({ t: 'circuit', key: `n${i}`, head, ends: [h.nodes] })
      return
    }
    if (last?.t === 'device' && last.head.device_id === head.device_id) last.ports.push(h.nodes)
    else out.push({ t: 'device', key: `n${i}`, head, ports: [h.nodes] })
  })
  return out
})
const names = (ns: TraceNode[]) => ns.map((n) => n.name).join(', ')
const isHi = (ns: TraceNode[]) => props.highlight != null && ns.some((n) => n.id === props.highlight)
</script>

<template>
  <ol class="trace">
    <li v-for="b in blocks" :key="b.key" :class="b.t">
      <template v-if="b.t === 'device'">
        <div class="dev" :style="{ '--role': hex(b.head.color) }">
          <div class="dev-head">
            <RouterLink :to="`/dcim/devices/${b.head.device_id}`" class="dev-name" @click="emit('navigate')">{{ b.head.device }}</RouterLink>
            <span class="role" :style="{ background: hex(b.head.color), color: onColor(b.head.color) }">{{ b.head.role }}</span>
          </div>
          <div class="where muted">
            {{ b.head.branch }}<template v-if="b.head.location"> · {{ b.head.location }}</template>
            <template v-if="b.head.rack"> · {{ b.head.rack }} {{ uLabel(b.head.position) }}</template>
          </div>
          <div class="ports">
            <span v-for="(ns, j) in b.ports" :key="j" class="port" :class="{ hi: isHi(ns) }">
              <span class="pk">{{ ns[0]?.kind_label }}</span> <strong class="mono">{{ names(ns) }}</strong>
              <span v-if="ns[0]?.type_label" class="muted small"> · {{ ns[0]?.type_label }}</span>
            </span>
          </div>
          <div v-if="b.head.ips" class="ips mono small">{{ b.head.ips }}</div>
          <RouterLink v-if="b.head.asset_uuid" :to="`/assets/${b.head.asset_uuid}`" class="asset small" @click="emit('navigate')"><AppIcon name="pc" :size="13" /> Ativo {{ b.head.asset_name }}</RouterLink>
        </div>
      </template>
      <template v-else-if="b.t === 'circuit'">
        <div class="circ">
          <div class="dev-head">
            <RouterLink :to="`/circuits/${b.head.circuit_id}`" class="dev-name" @click="emit('navigate')"><AppIcon name="globe" :size="15" /> {{ b.head.provider }} · {{ b.head.cid }}</RouterLink>
            <span class="muted small">{{ b.head.circuit_type }} {{ speed(b.head.commit_rate_kbps) !== '—' ? '· ' + speed(b.head.commit_rate_kbps) : '' }}</span>
          </div>
          <div v-for="(ns, j) in b.ends" :key="j" class="where">
            <strong>Lado {{ ns[0]?.term_side }}</strong>:
            <template v-if="ns[0]?.provider_network"><AppIcon name="globe" :size="13" /> {{ ns[0]?.provider_network }} (rede da operadora)</template>
            <template v-else>{{ ns[0]?.branch ?? '—' }}<template v-if="ns[0]?.location"> · {{ ns[0]?.location }}</template>
              <template v-if="ns[0]?.xconnect_id"> · conexão {{ ns[0]?.xconnect_id }}</template><template v-if="ns[0]?.pp_info"> · {{ ns[0]?.pp_info }}</template></template>
          </div>
        </div>
      </template>
      <template v-else>
        <div class="cable" :class="b.cable.status">
          <span class="wire" :style="{ background: hex(b.cable.color, '64748b') }" />
          <RouterLink :to="`/dcim/cables/${b.cable.id}`" class="cable-info" @click="emit('navigate')">
            <strong>{{ b.cable.label || `Cabo #${b.cable.id}` }}</strong>
            <span class="muted small">{{ [b.cable.type_label, length(b.cable.length, b.cable.length_unit)].filter(Boolean).join(' · ') }}</span>
            <span v-if="b.cable.status !== 'connected'" class="pill pill-warn">{{ b.cable.status === 'planned' ? 'planejado' : 'em desativação' }}</span>
          </RouterLink>
        </div>
      </template>
    </li>
  </ol>
</template>

<style scoped>
.trace { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; align-items: stretch; max-width: 560px; margin-inline: auto; }
.dev, .circ { border: 1px solid var(--border); border-left: 5px solid var(--role, var(--accent)); border-radius: var(--radius-sm); background: var(--bg-elev); padding: 10px 12px; display: flex; flex-direction: column; gap: 4px; }
.circ { border-left-color: var(--accent); border-style: dashed; border-left-style: solid; }
.dev-head { display: flex; align-items: center; gap: 8px; justify-content: space-between; flex-wrap: wrap; }
.dev-name { font-weight: 600; color: var(--text); text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
.dev-name:hover { color: var(--accent-text); text-decoration: underline; }
.role { font-size: 11px; padding: 1px 7px; border-radius: 999px; font-weight: 500; }
.where { font-size: 12.5px; }
.ports { display: flex; flex-direction: column; gap: 2px; font-size: 13px; }
.port { padding: 2px 6px; border-radius: 6px; }
.port.hi { background: var(--accent-soft); outline: 1px solid var(--accent); }
.pk { color: var(--text-3); font-size: 12px; }
.ips { color: var(--accent-text); }
.asset { color: var(--accent-text); display: inline-flex; align-items: center; gap: 4px; text-decoration: none; }
.cable { display: flex; align-items: stretch; gap: 12px; padding-left: 28px; min-height: 64px; }
.wire { width: 6px; border-radius: 3px; flex: none; box-shadow: 0 0 0 1px var(--border); }
.cable.planned .wire { background-image: repeating-linear-gradient(180deg, transparent 0 6px, var(--bg) 6px 10px) !important; }
.cable-info { display: flex; flex-direction: column; justify-content: center; gap: 2px; color: var(--text); text-decoration: none; font-size: 13px; }
.cable-info:hover strong { text-decoration: underline; }
.cable-info .pill { align-self: flex-start; }
.small { font-size: 12px; }
</style>
