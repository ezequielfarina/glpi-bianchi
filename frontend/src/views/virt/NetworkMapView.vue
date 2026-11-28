<script setup lang="ts">
import { safeHref } from '../../lib/safeUrl'
import { computed, ref, watch } from 'vue'
import AppIcon from '../../components/AppIcon.vue'
import AuthImage from '../../components/AuthImage.vue'
import NetworkGraph from '../../components/virt/NetworkGraph.vue'
import PathToInternet from '../../components/virt/PathToInternet.vue'
import { api } from '../../lib/api'
import { DEVICE_STATUS, dcim, hex, onColor, speed, uLabel, type DeviceDetail } from '../../lib/dcim'
import type { BranchRow } from '../../lib/types'
import { iconOf, iconUrl } from '../../lib/netIcons'
import { gb, num, platformBadge, virt, VM_STATUS, type TopoNode, type Topology, type VmDetail } from '../../lib/virt'

// Mapa da rede (ADR-0017 §3): 3D/2D, lógico (passivos colapsados) ou físico (cabo a cabo), clique para detalhes.
const branches = ref<BranchRow[]>([])
const branch = ref<number | null>(Number(localStorage.getItem('bianchi.map.branch')) || null)
const mode = ref<'logical' | 'physical'>((localStorage.getItem('bianchi.map.mode') as 'logical' | 'physical') || 'logical')
const dim = ref<'3d' | '2d'>((localStorage.getItem('bianchi.map.dim') as '3d' | '2d') || (matchMedia('(max-width: 760px)').matches ? '2d' : '3d'))
const showVms = ref(localStorage.getItem('bianchi.map.vms') !== '0')
const data = ref<Topology | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const sel = ref<TopoNode | null>(null)
const device = ref<DeviceDetail | null>(null)
const vm = ref<VmDetail | null>(null)
const showPath = ref(false)
const q = ref('')
const focus = ref<string | null>(null)
const graph = ref<InstanceType<typeof NetworkGraph> | null>(null)
const dark = ref(document.documentElement.dataset.theme !== 'light')
void api.branches().then((r) => { branches.value = r.items; if (!branch.value && r.items.length === 1) branch.value = r.items[0]!.id })
async function load(): Promise<void> {
  loading.value = true
  try { data.value = await virt.topology({ branch: branch.value, mode: mode.value, vms: showVms.value }); error.value = null }
  catch (e) { error.value = e instanceof Error ? e.message : String(e) } finally { loading.value = false }
  localStorage.setItem('bianchi.map.branch', String(branch.value ?? ''))
  localStorage.setItem('bianchi.map.mode', mode.value)
  localStorage.setItem('bianchi.map.vms', showVms.value ? '1' : '0')
}
watch([branch, mode, showVms], load, { immediate: true })
watch(dim, (d) => localStorage.setItem('bianchi.map.dim', d))
watch(sel, async (n) => {
  device.value = null; vm.value = null; showPath.value = false
  if (!n) return
  if (n.kind === 'device' && n.id.startsWith('d')) device.value = await dcim.device(Number(n.id.slice(1)))
  if (n.kind === 'vm' && n.vm_id) vm.value = await virt.vm(n.vm_id)
})
const stats = computed(() => {
  const n = data.value?.nodes ?? []
  return { devices: n.filter((x) => x.kind === 'device').length, vms: n.filter((x) => x.kind === 'vm').length, links: data.value?.links.filter((l) => ['data', 'power', 'console'].includes(l.kind)).length ?? 0,
    circuits: n.filter((x) => x.kind === 'circuit').length }
})
// legenda com o próprio desenho de cada função presente no mapa
const roles = computed(() => {
  const m = new Map<string, string>()
  for (const n of data.value?.nodes ?? []) if (n.kind === 'device' && n.role && !m.has(n.role)) m.set(n.role, iconUrl(iconOf('device', n.icon, n.role), hex(n.color), 16))
  return [...m.entries()]
})
const fixed = [['Cluster', iconUrl('cluster', '#f97316', 16)], ['VM', iconUrl('vm', '#a855f7', 16)], ['Circuito', iconUrl('circuit', '#a855f7', 16)], ['Operadora', iconUrl('cloud', '#0ea5e9', 16)]] as const
function search(): void {
  const t = q.value.trim().toLowerCase()
  const n = data.value?.nodes.find((x) => x.name.toLowerCase() === t) ?? data.value?.nodes.find((x) => x.name.toLowerCase().includes(t) || (x.primary_ip ?? '').startsWith(t))
  if (n) { focus.value = null; requestAnimationFrame(() => { focus.value = n.id }) }
}
const kindLabel: Record<string, string> = { device: 'Equipamento', circuit: 'Circuito de operadora', cloud: 'Rede da operadora', cluster: 'Cluster de virtualização', vm: 'Máquina virtual' }
</script>

<template>
  <div class="map-page">
    <header class="map-bar glass">
      <div class="title"><AppIcon name="cube" :size="20" /><div><h1>Mapa da rede</h1><span class="muted">{{ stats.devices }} equipamentos · {{ stats.links }} ligações · {{ stats.vms }} VMs · {{ stats.circuits }} links de operadora</span></div></div>
      <div class="ctrls">
        <select v-model="branch" class="input" aria-label="Filial"><option :value="null">Todas as filiais</option><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
        <div class="seg" role="group" aria-label="Tipo de ligação">
          <button type="button" :class="{ on: mode === 'logical' }" title="Equipamento a equipamento (patch panels e tomadas ficam no detalhe da ligação)" @click="mode = 'logical'">Lógico</button>
          <button type="button" :class="{ on: mode === 'physical' }" title="Cabo a cabo, com patch panels, tomadas e DIO" @click="mode = 'physical'">Físico</button>
        </div>
        <div class="seg" role="group" aria-label="Visualização">
          <button type="button" :class="{ on: dim === '3d' }" @click="dim = '3d'">3D</button>
          <button type="button" :class="{ on: dim === '2d' }" @click="dim = '2d'">2D</button>
        </div>
        <label class="check"><input v-model="showVms" type="checkbox" /> VMs</label>
        <form class="find" @submit.prevent="search"><input v-model="q" class="input" type="search" list="map-nodes" placeholder="Achar equipamento ou IP…" aria-label="Achar no mapa" />
          <datalist id="map-nodes"><option v-for="n in data?.nodes ?? []" :key="n.id" :value="n.name" /></datalist></form>
        <button class="btn btn-icon" type="button" title="Enquadrar tudo" aria-label="Enquadrar tudo" @click="graph?.fit()"><AppIcon name="cube" :size="16" /></button>
      </div>
    </header>

    <div class="stage" :class="{ light: !dark }">
      <NetworkGraph v-if="data && data.nodes.length" ref="graph" :data="data" :dim="dim" :focus="focus" :dark="dark" @select="sel = $event" />
      <div v-if="loading" class="overlay"><span class="spinner" /> Montando o mapa…</div>
      <div v-else-if="error" class="overlay err">{{ error }} <button class="btn" type="button" @click="load">Tentar de novo</button></div>
      <div v-else-if="data && !data.nodes.length" class="overlay empty">
        <AppIcon name="cube" :size="42" />
        <p>Nada para mostrar ainda. Cadastre equipamentos e conecte os cabos em <RouterLink to="/dcim/devices">Equipamentos</RouterLink> — o mapa se monta sozinho.</p>
      </div>

      <aside class="legend glass" aria-label="Legenda">
        <div class="lg-t">Legenda</div>
        <div v-for="[r, u] in roles" :key="r" class="lg"><img :src="u" alt="" />{{ r }}</div>
        <div v-for="[r, u] in fixed" :key="r" class="lg"><img :src="u" alt="" />{{ r }}</div>
        <div class="lg-t">Ligações</div>
        <div class="lg"><b class="ln" style="--c:#60a5fa" />cabo de rede (cor real)</div>
        <div class="lg"><b class="ln" style="--c:#facc15" />energia</div>
        <div class="lg"><b class="ln thin" style="--c:#c084fc" />VM no host / host no cluster</div>
        <div class="lg"><b class="ln off" style="--c:#64748b" />planejado (sem tráfego)</div>
        <div class="lg muted">pulsos que correm = tráfego nos dois sentidos</div>
      </aside>

      <Transition name="slide">
        <aside v-if="sel" class="side glass" aria-label="Detalhes">
          <button class="btn btn-icon close" type="button" aria-label="Fechar" @click="sel = null"><AppIcon name="close" :size="16" /></button>
          <div class="k">{{ kindLabel[sel.kind] }}</div>
          <h2>{{ sel.name }}</h2>
          <template v-if="sel.kind === 'device'">
            <span v-if="sel.role" class="dc-role" :style="{ background: hex(sel.color), color: onColor(sel.color) }">{{ sel.role }}</span>
            <div v-if="device" class="body">
              <div v-if="device.attachments.find((a) => a.kind === 'photo') || device.type_photo" class="ph"><AuthImage :uuid="(device.attachments.find((a) => a.kind === 'photo')?.uuid ?? device.type_photo)!" :alt="device.name" /></div>
              <dl class="dc-kv">
                <dt>Modelo</dt><dd>{{ device.manufacturer }} {{ device.device_type }}</dd>
                <dt>Onde</dt><dd>{{ [device.branch, device.location, device.rack ? `${device.rack} ${uLabel(device.position)}` : null].filter(Boolean).join(' › ') }}</dd>
                <dt>Situação</dt><dd><span class="pill" :class="DEVICE_STATUS[device.status]?.cls">{{ DEVICE_STATUS[device.status]?.label }}</span></dd>
                <dt>IP</dt><dd class="mono">{{ device.primary_ip ?? '—' }}</dd>
                <dt>Portas</dt><dd>{{ device.ports.filter((p) => p.cable_id).length }} conectadas de {{ device.ports.length }}</dd>
                <template v-if="device.vms.length"><dt>VMs</dt><dd>{{ device.vms.length }} ({{ device.vms.map((v) => v.name).slice(0, 4).join(', ') }}{{ device.vms.length > 4 ? '…' : '' }})</dd></template>
              </dl>
              <div class="conns">
                <div v-for="p in device.ports.filter((x) => x.kind === 'interface' && x.destination)" :key="p.id" class="conn">
                  <span class="mono">{{ p.name }}</span><span class="wire" :style="{ background: hex(p.cable_color, '64748b') }" /><span>{{ p.destination!.nodes[0]?.device }} <span class="mono muted">{{ p.destination!.nodes[0]?.name }}</span></span>
                </div>
              </div>
              <div class="acts">
                <RouterLink :to="`/dcim/devices/${device.id}`" class="btn btn-primary"><AppIcon name="external" :size="14" /> Abrir equipamento</RouterLink>
                <button class="btn" type="button" @click="showPath = !showPath"><AppIcon name="route" :size="14" /> {{ showPath ? 'Ocultar' : 'Caminho até a internet' }}</button>
              </div>
              <PathToInternet v-if="showPath" :device-id="device.id" :start-name="device.name" />
            </div>
            <p v-else class="muted">Carregando…</p>
          </template>
          <template v-else-if="sel.kind === 'vm'">
            <div v-if="vm" class="body">
              <dl class="dc-kv">
                <dt>Recursos</dt><dd>{{ num(vm.vcpus) }} vCPU · {{ gb(vm.memory_mb) }} · {{ vm.disk_gb ?? '—' }} GB</dd>
                <dt>Virtualizador</dt><dd><b class="plat" :style="{ background: platformBadge(vm.cluster_type).color }">{{ platformBadge(vm.cluster_type).short }}</b> {{ vm.cluster }}</dd>
                <dt>Host</dt><dd>{{ vm.host ?? '—' }}</dd><dt>SO</dt><dd>{{ vm.platform ?? '—' }}</dd><dt>IP</dt><dd class="mono">{{ vm.primary_ip ?? '—' }}</dd>
                <dt>Situação</dt><dd><span class="pill" :class="VM_STATUS[vm.status]?.cls">{{ VM_STATUS[vm.status]?.label }}</span></dd>
              </dl>
              <div class="acts"><RouterLink :to="`/virtualization/vms/${vm.id}`" class="btn btn-primary"><AppIcon name="external" :size="14" /> Abrir VM</RouterLink>
                <button class="btn" type="button" @click="showPath = !showPath"><AppIcon name="route" :size="14" /> {{ showPath ? 'Ocultar' : 'Caminho até a internet' }}</button></div>
              <PathToInternet v-if="showPath" :device-id="vm.device_id" :vm="vm" />
            </div>
          </template>
          <template v-else-if="sel.kind === 'cluster'">
            <dl class="dc-kv"><dt>Plataforma</dt><dd>{{ sel.type }}</dd><dt>VMs</dt><dd>{{ sel.vms }}</dd></dl>
            <div class="acts"><RouterLink :to="`/virtualization/clusters/${sel.cluster_id}`" class="btn btn-primary"><AppIcon name="external" :size="14" /> Abrir cluster</RouterLink>
              <a v-if="sel.management_url" :href="safeHref(sel.management_url)" target="_blank" rel="noopener" class="btn">Console</a></div>
          </template>
          <template v-else-if="sel.kind === 'circuit'">
            <dl class="dc-kv"><dt>Operadora</dt><dd>{{ sel.provider }}</dd><dt>Designação</dt><dd class="mono">{{ sel.cid }}</dd><dt>Tipo</dt><dd>{{ sel.type }}</dd><dt>Banda</dt><dd>{{ speed(sel.commit_rate_kbps) }}</dd></dl>
            <div class="acts"><RouterLink :to="`/circuits/${sel.circuit_id}`" class="btn btn-primary"><AppIcon name="external" :size="14" /> Abrir circuito</RouterLink></div>
          </template>
          <p v-else class="muted">Rede da operadora {{ sel.provider }} — o fim do caminho.</p>
        </aside>
      </Transition>
      <p class="hint-bar">Arraste para {{ dim === '3d' ? 'girar' : 'mover' }} · role para aproximar · clique num item para ver os detalhes</p>
    </div>
  </div>
</template>

<style scoped>
.map-page { display: flex; flex-direction: column; gap: 14px; height: calc(100vh - 120px); min-height: 560px; }
.glass { background: color-mix(in srgb, var(--panel) 78%, transparent); backdrop-filter: blur(14px) saturate(1.3); border: 1px solid var(--border); border-radius: 18px; }
.map-bar { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 12px 16px; flex-wrap: wrap; }
.title { display: flex; gap: 12px; align-items: center; color: var(--accent-text); }
.title h1 { margin: 0; font-size: 18px; color: var(--text); }
.title span { font-size: 12.5px; }
.ctrls { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.ctrls .input { width: auto; min-width: 150px; }
.find .input { min-width: 220px; }
.seg { display: inline-flex; padding: 3px; border-radius: 10px; background: var(--bg-elev); border: 1px solid var(--border); }
.seg button { border: 0; background: transparent; color: var(--text-2); padding: 6px 12px; border-radius: 8px; cursor: pointer; font: inherit; font-size: 13px; }
.seg button.on { background: var(--accent); color: var(--accent-fg); box-shadow: 0 4px 12px -4px var(--accent); }
.stage { position: relative; flex: 1; border-radius: 22px; overflow: hidden; border: 1px solid var(--border);
  background: radial-gradient(1200px 700px at 30% 20%, rgba(37, 99, 235, .18), transparent 60%), radial-gradient(900px 600px at 80% 90%, rgba(168, 85, 247, .16), transparent 60%), #070b16; }
.stage::before { content: ''; position: absolute; inset: 0; pointer-events: none; opacity: .35;
  background-image: linear-gradient(rgba(148, 163, 184, .07) 1px, transparent 1px), linear-gradient(90deg, rgba(148, 163, 184, .07) 1px, transparent 1px); background-size: 44px 44px;
  mask-image: radial-gradient(circle at 50% 50%, #000 40%, transparent 85%); }
.overlay { position: absolute; inset: 0; display: grid; place-content: center; justify-items: center; gap: 10px; color: #cbd5e1; text-align: center; padding: 20px; }
.overlay a { color: #93c5fd; }
.spinner { width: 28px; height: 28px; border-radius: 50%; border: 3px solid rgba(148, 163, 184, .3); border-top-color: #60a5fa; animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.legend { position: absolute; left: 14px; bottom: 44px; padding: 10px 12px; display: flex; flex-direction: column; gap: 4px; font-size: 12px; color: #cbd5e1; max-height: 60%; overflow: auto;
  background: rgba(15, 23, 42, .7); border-color: rgba(148, 163, 184, .2); }
.lg-t { font-size: 10.5px; text-transform: uppercase; letter-spacing: .08em; color: #64748b; margin-top: 4px; }
.lg { display: flex; align-items: center; gap: 8px; }
.lg img { height: 15px; width: 46px; object-fit: contain; object-position: left center; flex: none; }
.ln { position: relative; width: 26px; height: 5px; border-radius: 3px; flex: none; overflow: hidden;
  background: color-mix(in srgb, var(--c) 30%, transparent); box-shadow: 0 0 8px color-mix(in srgb, var(--c) 60%, transparent); }
.ln::before { content: ''; position: absolute; inset: 1px 0; background: radial-gradient(circle, var(--c) 0 1.2px, transparent 1.6px) 0 50% / 4px 3px repeat-x; }
.ln::after { content: ''; position: absolute; inset: 0; width: 8px; background: linear-gradient(90deg, transparent, #fff, transparent); animation: led 1.6s linear infinite; }
.ln.thin { height: 3px; }
.ln.off::after { display: none; }
@keyframes led { from { transform: translateX(-10px); } to { transform: translateX(30px); } }
@media (prefers-reduced-motion: reduce) { .ln::after { animation: none; display: none; } }
.side { position: absolute; top: 14px; right: 14px; bottom: 14px; width: min(400px, calc(100% - 28px)); padding: 18px; overflow: auto; display: flex; flex-direction: column; gap: 10px;
  background: color-mix(in srgb, var(--panel) 90%, transparent); }
.side h2 { margin: 0; font-size: 20px; }
.side .k { font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: var(--text-3); }
.side .close { position: absolute; top: 12px; right: 12px; }
.side .dc-role { align-self: flex-start; }
.side .dc-kv { padding: 0; }
.side :deep(.chain) { padding: 8px 0 0; }
.body { display: flex; flex-direction: column; gap: 12px; }
.ph { border-radius: 12px; overflow: hidden; max-height: 180px; border: 1px solid var(--border); }
.ph :deep(img) { width: 100%; max-height: 180px; object-fit: cover; display: block; }
.conns { display: flex; flex-direction: column; gap: 4px; font-size: 12.5px; max-height: 180px; overflow: auto; }
.conn { display: grid; grid-template-columns: 70px 26px 1fr; gap: 8px; align-items: center; }
.conn .wire { height: 4px; border-radius: 2px; }
.acts { display: flex; gap: 8px; flex-wrap: wrap; }
.plat { color: #fff; font-size: 10px; padding: 1px 6px; border-radius: 5px; }
.hint-bar { position: absolute; left: 50%; bottom: 10px; translate: -50% 0; margin: 0; font-size: 11.5px; color: #64748b; pointer-events: none; white-space: nowrap; }
.slide-enter-active, .slide-leave-active { transition: transform .25s cubic-bezier(.2, .8, .2, 1), opacity .25s; }
.slide-enter-from, .slide-leave-to { transform: translateX(24px); opacity: 0; }
@media (max-width: 760px) { .map-page { height: calc(100vh - 150px); } .legend { display: none; } .hint-bar { display: none; } }
</style>
