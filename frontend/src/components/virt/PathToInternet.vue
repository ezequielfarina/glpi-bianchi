<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import AppIcon from '../AppIcon.vue'
import TraceView from '../dcim/TraceView.vue'
import { hex } from '../../lib/dcim'
import { gb, num, platformBadge, virt, type PathToInternet, type VmDetail } from '../../lib/virt'

// Caminho reverso até a internet: VM → virtualizador → host físico → switches/cabos → firewall → link → operadora.
// Cada etapa é clicável; quando falta algo, a etapa mostra o que cadastrar.
const props = defineProps<{ deviceId: number | null; vm?: VmDetail | null; startName?: string }>()
const emit = defineEmits<{ editVm: []; connect: [] }>()
const path = ref<PathToInternet | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const open = ref<number | null>(null)
async function load(): Promise<void> {
  if (!props.deviceId) { path.value = null; return }
  loading.value = true
  try { path.value = await virt.pathToInternet(props.deviceId); error.value = null } catch (e) { error.value = e instanceof Error ? e.message : String(e) } finally { loading.value = false }
}
watch(() => props.deviceId, load, { immediate: true })
defineExpose({ reload: load })
const pb = computed(() => platformBadge(props.vm?.cluster_type))
const cableColors = (i: number) => (path.value?.segments[i]?.trace.hops ?? []).flatMap((h) => (h.t === 'cable' ? [hex(h.cable.color, '64748b')] : []))
const via = (i: number) => {
  const names = new Set<string>()
  for (const h of path.value?.segments[i]?.trace.hops ?? []) if (h.t === 'nodes' && h.nodes[0]?.node === 'port' && ['front', 'rear'].includes(h.nodes[0].kind)) names.add(h.nodes[0].device ?? '')
  return [...names]
}
const meters = (m: number | null) => (m === null ? '' : m >= 1000 ? `${(m / 1000).toFixed(2).replace('.', ',')} km` : `${String(+m.toFixed(1)).replace('.', ',')} m`)
</script>

<template>
  <ol class="chain" aria-label="Caminho até a internet">
    <li v-if="vm" class="step vm">
      <span class="dot"><AppIcon name="vm" :size="16" /></span>
      <div class="card">
        <div class="k">Máquina virtual</div>
        <strong>{{ vm.name }}</strong>
        <div class="sub">{{ [vm.platform, vm.vcpus ? num(vm.vcpus) + ' vCPU' : null, vm.memory_mb ? gb(vm.memory_mb) + ' RAM' : null, vm.disk_gb ? vm.disk_gb + ' GB disco' : null, vm.primary_ip].filter(Boolean).join(' · ') }}</div>
      </div>
    </li>
    <li v-if="vm" class="step">
      <span class="dot" :style="{ background: pb.color }"><b>{{ pb.short }}</b></span>
      <div class="card">
        <div class="k">Virtualizador</div>
        <RouterLink :to="`/virtualization/clusters/${vm.cluster_id}`" class="dc-strong">{{ vm.cluster }}</RouterLink> <span class="muted">{{ vm.cluster_type }}</span>
        <a v-if="vm.management_url" :href="vm.management_url" target="_blank" rel="noopener" class="console"><AppIcon name="external" :size="13" /> Abrir console</a>
      </div>
    </li>
    <li v-if="vm || deviceId" class="step" :class="{ missing: !deviceId }">
      <span class="dot"><AppIcon name="server" :size="16" /></span>
      <div class="card">
        <div class="k">{{ vm ? 'Servidor físico (host)' : 'Equipamento' }}</div>
        <template v-if="deviceId"><RouterLink :to="`/dcim/devices/${deviceId}`" class="dc-strong">{{ vm?.host ?? path?.start.name ?? startName }}</RouterLink></template>
        <template v-else><span class="muted">Host não definido.</span> <button type="button" class="edit-only link-btn" @click="emit('editVm')">Informar em qual servidor a VM roda</button></template>
      </div>
    </li>
    <template v-if="deviceId">
      <li v-if="loading" class="step"><span class="dot pulse" /><div class="card muted">Calculando o caminho…</div></li>
      <li v-else-if="error" class="step missing"><span class="dot">!</span><div class="card">{{ error }}</div></li>
      <template v-else-if="path">
        <template v-for="(s, i) in path.segments" :key="i">
          <li class="step link" :class="{ planned: !s.active }">
            <span class="wire" :style="{ background: `linear-gradient(180deg, ${cableColors(i).join(', ') || '#64748b'})` }"><span class="packet" /></span>
            <button type="button" class="card cable" :aria-expanded="open === i" @click="open = open === i ? null : i">
              <span class="k">Saída <span class="mono">{{ s.from_port }}</span> · {{ s.cables }} cabo(s){{ s.length_m ? ' · ' + meters(s.length_m) : '' }}{{ s.active ? '' : ' · tem trecho planejado' }}</span>
              <span v-if="via(i).length" class="sub">passa por {{ via(i).join(' → ') }}</span>
              <span class="more">{{ open === i ? 'ocultar detalhes' : 'ver cabos e patch panels' }}</span>
            </button>
          </li>
          <li v-if="open === i" class="step detail"><span class="dot ghost" /><div class="card"><TraceView :trace="s.trace" :highlight="s.from_port_id" /></div></li>
          <li v-if="s.to_kind === 'device'" class="step">
            <span class="dot"><AppIcon name="network" :size="16" /></span>
            <div class="card"><div class="k">Entra na porta <span class="mono">{{ s.to_port }}</span></div>
              <RouterLink :to="`/dcim/devices/${s.to_device_id}`" class="dc-strong">{{ s.to_device }}</RouterLink></div>
          </li>
          <template v-else>
            <li class="step">
              <span class="dot isp"><AppIcon name="globe" :size="16" /></span>
              <div class="card"><div class="k">Link de internet / operadora</div>
                <RouterLink :to="`/circuits/${s.circuit_id}`" class="dc-strong">{{ s.provider }} · {{ s.cid }}</RouterLink>
                <div class="sub">cabo da operadora até {{ s.provider_network ?? 'a rede dela' }}</div></div>
            </li>
            <li class="step end"><span class="dot cloud"><AppIcon name="globe" :size="18" /></span><div class="card"><strong>{{ s.provider_network ?? 'Internet' }}</strong> <span class="pill pill-ok">caminho completo</span></div></li>
          </template>
        </template>
        <li v-if="!path.found" class="step missing">
          <span class="dot">!</span>
          <div class="card">
            <template v-if="path.reason === 'no_cable'"><strong>Sem cabo de rede documentado neste equipamento.</strong>
              <div class="sub">Abra o equipamento e use <b>Conectar cabo</b> na interface de rede (ex.: eno1 → patch panel/switch).</div>
              <RouterLink :to="`/dcim/devices/${deviceId}`" class="edit-only btn btn-primary small-btn"><AppIcon name="cable" :size="14" /> Conectar cabo</RouterLink></template>
            <template v-else><strong>O caminho não chega a um link de operadora.</strong>
              <div class="sub">Documente o uplink do último equipamento até o firewall e, no firewall, a porta WAN até a ONT/DIO ligada ao <RouterLink to="/circuits" class="dc-link">circuito</RouterLink>.
                {{ path.reached ? `${path.reached} equipamento(s) alcançado(s).` : '' }}</div></template>
          </div>
        </li>
      </template>
    </template>
  </ol>
</template>

<style scoped>
.chain { list-style: none; margin: 0; padding: 4px 20px 20px; display: flex; flex-direction: column; }
.step { display: grid; grid-template-columns: 40px 1fr; gap: 14px; align-items: stretch; position: relative; }
.step:not(.end):not(:last-child)::before { content: ''; position: absolute; left: 19px; top: 38px; bottom: -6px; width: 2px; background: linear-gradient(var(--border), var(--border-soft)); }
.step.link::before { display: none; }
.dot { width: 38px; height: 38px; border-radius: 12px; display: grid; place-items: center; background: var(--panel-hover); color: var(--accent-text); z-index: 1;
  box-shadow: 0 0 0 1px var(--border), 0 6px 16px -8px color-mix(in srgb, var(--accent) 70%, transparent); font-weight: 700; }
.dot b { font-size: 9.5px; color: #fff; letter-spacing: .02em; }
.dot.isp { background: linear-gradient(135deg, #7c3aed, #2563eb); color: #fff; }
.dot.cloud { border-radius: 50%; background: radial-gradient(circle at 35% 30%, #67e8f9, #0ea5e9 60%, #0369a1); color: #fff; box-shadow: 0 0 22px -2px #0ea5e9; }
.dot.ghost { visibility: hidden; }
.dot.pulse { animation: pulse 1.2s ease-in-out infinite; }
@keyframes pulse { 50% { opacity: .4; transform: scale(.9); } }
.card { margin: 0 0 12px; padding: 10px 14px; border: 1px solid var(--border); border-radius: 14px; background: color-mix(in srgb, var(--bg-elev) 80%, transparent);
  display: flex; flex-direction: column; gap: 3px; min-width: 0; text-align: left; font-size: 13.5px; }
.step.vm .card { background: linear-gradient(135deg, color-mix(in srgb, #8e24aa 18%, var(--bg-elev)), var(--bg-elev)); }
.k { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--text-3); }
.sub { font-size: 12.5px; color: var(--text-2); }
.console { align-self: flex-start; display: inline-flex; gap: 4px; align-items: center; font-size: 12.5px; color: var(--accent-text); text-decoration: none; margin-top: 2px; }
.step.link { min-height: 64px; }
.wire { justify-self: center; width: 6px; border-radius: 3px; margin: -6px 0 6px; position: relative; overflow: hidden; box-shadow: 0 0 0 1px var(--border), 0 0 12px -3px currentColor; }
.packet { position: absolute; left: -2px; width: 10px; height: 10px; border-radius: 50%; background: #fff; box-shadow: 0 0 10px 3px rgba(255, 255, 255, .8); animation: packet 2.4s linear infinite; }
.step.planned .wire { opacity: .55; background-image: repeating-linear-gradient(180deg, transparent 0 6px, var(--bg) 6px 10px) !important; }
@keyframes packet { from { top: 100%; } to { top: -12px; } }
@media (prefers-reduced-motion: reduce) { .packet { display: none; } .dot.pulse { animation: none; } }
.card.cable { cursor: pointer; border-style: dashed; background: transparent; color: var(--text); font: inherit; font-size: 13px; }
.card.cable:hover { border-color: var(--accent); }
.more { font-size: 12px; color: var(--accent-text); }
.step.missing .dot { background: var(--warn-soft); color: var(--warn-text); }
.step.missing .card { border-color: color-mix(in srgb, var(--warn) 50%, var(--border)); }
.small-btn { align-self: flex-start; margin-top: 6px; }
.step.detail .card { padding: 14px; }
</style>
