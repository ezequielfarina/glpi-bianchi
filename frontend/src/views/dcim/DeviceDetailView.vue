<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import AttachmentsPanel from '../../components/AttachmentsPanel.vue'
import LoadState from '../../components/LoadState.vue'
import CableModal from '../../components/dcim/CableModal.vue'
import DeviceFormModal from '../../components/dcim/DeviceFormModal.vue'
import IpForm from '../../components/dcim/IpForm.vue'
import PhotoGallery from '../../components/dcim/PhotoGallery.vue'
import PortFormModal from '../../components/dcim/PortFormModal.vue'
import TraceModal from '../../components/dcim/TraceModal.vue'
import PathToInternet from '../../components/virt/PathToInternet.vue'
import ResourceRing from '../../components/virt/ResourceRing.vue'
import { gb, num, platformBadge, VM_STATUS } from '../../lib/virt'
import { dcim, DEVICE_STATUS, hex, ipam, IP_STATUS, onColor, PORT_KIND_TABS, uLabel, type PortRow } from '../../lib/dcim'
import { canEdit } from '../../lib/session'
import { notify } from '../../lib/toast'
import { useAsync } from '../../lib/useAsync'

const props = defineProps<{ id: string }>()
const router = useRouter()
const showPath = ref(false)
const { data: d, loading, error, reload } = useAsync(() => dcim.device(Number(props.id)))
const vmUse = computed(() => (d.value?.vms ?? []).filter((v) => v.status !== 'offline').reduce((a, v) => ({ cpu: a.cpu + (v.vcpus ?? 0), mem: a.mem + (v.memory_mb ?? 0), disk: a.disk + (v.disk_gb ?? 0) }), { cpu: 0, mem: 0, disk: 0 }))
const editing = ref(false)
const addingPorts = ref(false)
const editPort = ref<PortRow | null>(null)
const connect = ref<PortRow | null>(null)
const tracing = ref<PortRow | null>(null)
const addIp = ref<{ port_id?: number } | null>(null)
const tab = ref('interface')
const selected = ref<number[]>([])

const tabs = computed(() => PORT_KIND_TABS.map((t) => ({ ...t, ports: (d.value?.ports ?? []).filter((p) => t.kinds.includes(p.kind)) })).filter((t) => t.ports.length || t.key === 'interface'))
const current = computed(() => tabs.value.find((t) => t.key === tab.value) ?? tabs.value[0])
const rears = computed(() => (d.value?.ports ?? []).filter((p) => p.kind === 'rear').map((p) => ({ id: p.id, name: p.name, positions: p.positions })))
const interfaces = computed(() => (d.value?.ports ?? []).filter((p) => p.kind === 'interface').map((p) => ({ id: p.id, name: p.name })))
const connected = computed(() => (d.value?.ports ?? []).filter((p) => p.cable_id || p.mark_connected).length)
// abre na primeira aba com portas
let firstLoad = true
watch(d, (v) => { if (v && firstLoad) { firstLoad = false; tab.value = tabs.value.find((t) => t.ports.length)?.key ?? 'interface' } })

async function disconnect(p: PortRow): Promise<void> {
  if (!p.cable_id || !window.confirm(`Remover o cabo ${p.cable_label ?? '#' + p.cable_id} da porta ${p.name}?`)) return
  await dcim.deleteCable(p.cable_id)
  notify('ok', 'Cabo removido')
  await reload()
}
async function removePorts(ids: number[]): Promise<void> {
  if (!ids.length || !window.confirm(`Excluir ${ids.length} porta(s)? Cabos ligados a elas também serão removidos.`)) return
  const r = await dcim.deletePorts(ids)
  selected.value = []
  notify('ok', `${r.deleted} porta(s) excluída(s)`)
  await reload()
}
async function remove(): Promise<void> {
  if (!d.value || !window.confirm(`Excluir o equipamento "${d.value.name}"?\n\nAs portas e os cabos ligados a elas também serão removidos.`)) return
  await dcim.deleteDevice(d.value.id)
  notify('ok', 'Equipamento excluído')
  await router.push('/dcim/devices')
}
async function removeIp(id: number): Promise<void> {
  if (!window.confirm('Excluir este endereço IP?')) return
  await ipam.deleteIp(id)
  notify('ok', 'IP excluído')
  await reload()
}
const allSel = computed(() => !!current.value?.ports.length && current.value.ports.every((p) => selected.value.includes(p.id)))
function toggleAll(on: boolean): void { selected.value = on ? (current.value?.ports.map((p) => p.id) ?? []) : [] }
const draw = (p: PortRow) => [p.allocated_draw ? `${p.allocated_draw} W` : null, p.maximum_draw ? `máx. ${p.maximum_draw} W` : null].filter(Boolean).join(' · ')
</script>

<template>
  <div class="dc-page">
    <RouterLink to="/dcim/devices" class="dc-back"><AppIcon name="back" :size="16" /> Equipamentos</RouterLink>
    <LoadState :loading="loading" :error="error" :rows="8" @retry="reload">
      <template v-if="d">
        <header class="dc-head">
          <div>
            <p class="muted">{{ d.branch }}<template v-if="d.location"> › <RouterLink :to="`/dcim/locations/${d.location_id}`" class="dc-link">{{ d.location }}</RouterLink></template>
              <template v-if="d.rack"> › <RouterLink :to="`/dcim/racks/${d.rack_id}`" class="dc-link">{{ d.rack }}</RouterLink> {{ uLabel(d.position) }}{{ d.face === 'rear' ? ' (traseira)' : '' }}</template></p>
            <h1>{{ d.name }}
              <span class="dc-role" :style="{ background: hex(d.color), color: onColor(d.color) }">{{ d.role }}</span>
              <span class="pill" :class="DEVICE_STATUS[d.status]?.cls">{{ DEVICE_STATUS[d.status]?.label ?? d.status }}</span></h1>
            <p class="muted">{{ d.manufacturer }} {{ d.device_type }} · {{ d.u_height }}U · {{ connected }}/{{ d.ports.length }} portas conectadas<template v-if="d.primary_ip"> · <span class="mono">{{ d.primary_ip }}</span></template></p>
          </div>
          <div class="actions">
            <button class="edit-only btn" type="button" @click="addingPorts = true"><AppIcon name="plus" :size="15" /> Portas</button>
            <button class="edit-only btn" type="button" @click="editing = true"><AppIcon name="edit" :size="15" /> Editar</button>
            <button class="edit-only btn btn-icon" type="button" aria-label="Excluir equipamento" @click="remove"><AppIcon name="trash" :size="15" /></button>
          </div>
        </header>
        <div class="dc-cols">
          <div>
            <section class="panel">
              <div class="panel-head"><h2>Portas</h2>
                <button v-if="canEdit && selected.length" class="btn btn-danger" type="button" @click="removePorts(selected)"><AppIcon name="trash" :size="14" /> Excluir {{ selected.length }}</button></div>
              <div class="tabs dc-tabs" role="tablist">
                <button v-for="t in tabs" :key="t.key" type="button" role="tab" class="tab" :class="{ active: current?.key === t.key }" :aria-selected="current?.key === t.key" @click="tab = t.key; selected = []">
                  {{ t.label }} <span class="count">{{ t.ports.length }}</span></button>
              </div>
              <p v-if="!current?.ports.length" class="empty">Nenhuma porta. {{ canEdit ? 'Use "Portas" para adicionar em lote (ex.: Gi1/0/[1-48]).' : '' }}</p>
              <div v-else class="table-wrap">
                <table class="table ports">
                  <thead><tr>
                    <th v-if="canEdit" class="sel"><input type="checkbox" :checked="allSel" aria-label="Selecionar todas" @change="toggleAll(($event.target as HTMLInputElement).checked)" /></th>
                    <th>Porta</th><th>Tipo</th>
                    <th v-if="current.key === 'interface'">MAC / IP · VLAN</th>
                    <th v-if="current.key === 'front'">Traseira</th>
                    <th v-if="current.key === 'rear'">Posições</th>
                    <th v-if="current.key === 'power'">Consumo</th>
                    <th>Cabo</th><th>Ligado em</th>
                    <th v-if="['interface', 'console', 'power'].includes(current.key)">Destino final</th>
                    <th />
                  </tr></thead>
                  <tbody>
                    <tr v-for="p in current.ports" :key="p.id" :class="{ off: !p.enabled }">
                      <td v-if="canEdit" class="sel"><input v-model="selected" type="checkbox" :value="p.id" :aria-label="`Selecionar ${p.name}`" /></td>
                      <td class="dc-nowrap"><strong class="mono">{{ p.name }}</strong><span v-if="p.label" class="muted dc-small"> {{ p.label }}</span>
                        <span v-if="p.mgmt_only" class="pill pill-info">gerência</span><span v-if="p.kind === 'console_server' || p.kind === 'power_outlet'" class="muted dc-small"> ({{ p.kind_label }})</span>
                        <div v-if="p.description" class="muted dc-small">{{ p.description }}</div></td>
                      <td class="muted dc-small">{{ p.type_label }}</td>
                      <td v-if="current.key === 'interface'" class="dc-small"><div v-if="p.mac_address" class="mono muted">{{ p.mac_address }}</div><div v-if="p.ips" class="mono">{{ p.ips }}</div>
                        <div v-if="p.mode" class="muted">{{ p.mode === 'access' ? 'acesso' : 'tronco' }}{{ p.untagged_vid ? ` · nativa ${p.untagged_vid}` : '' }}{{ p.tagged_vids ? ` · tagged ${p.tagged_vids}` : '' }}</div></td>
                      <td v-if="current.key === 'front'" class="mono dc-small">{{ p.rear_port }}{{ p.rear_port && p.rear_position && p.rear_position > 1 ? ':' + p.rear_position : '' }}</td>
                      <td v-if="current.key === 'rear'" class="mono">{{ p.positions }}</td>
                      <td v-if="current.key === 'power'" class="dc-small">{{ draw(p) }}</td>
                      <td class="dc-nowrap"><template v-if="p.cable_id"><span class="dc-wire" :style="{ background: hex(p.cable_color, '64748b') }" />
                        <RouterLink :to="`/dcim/cables/${p.cable_id}`" class="dc-link"> {{ p.cable_label ?? '#' + p.cable_id }}</RouterLink>
                        <span v-if="p.cable_status !== 'connected'" class="pill pill-warn">{{ p.cable_status === 'planned' ? 'planejado' : 'desativando' }}</span></template>
                        <span v-else-if="p.mark_connected" class="pill pill-muted">conectada (sem cabo)</span></td>
                      <td class="dc-small"><div v-for="lp in p.link_peers" :key="`${lp.port_id}-${lp.circuit_termination_id}`">
                        <template v-if="lp.port_id"><RouterLink :to="`/dcim/devices/${lp.device_id}`" class="dc-link">{{ lp.device }}</RouterLink> <span class="mono">{{ lp.port }}</span></template>
                        <template v-else><AppIcon name="globe" :size="12" /> {{ lp.provider }} {{ lp.cid }} ({{ lp.term_side }})</template></div></td>
                      <td v-if="['interface', 'console', 'power'].includes(current.key)" class="dc-small">
                        <template v-if="p.destination"><div v-for="n in p.destination.nodes" :key="n.id"><RouterLink :to="`/dcim/devices/${n.device_id}`" class="dc-link">{{ n.device }}</RouterLink> <span class="mono">{{ n.name }}</span>
                          <span v-if="n.ips" class="mono muted"> · {{ n.ips }}</span></div></template>
                        <span v-else-if="p.cable_id" class="muted">incompleto</span></td>
                      <td class="dc-actions-row">
                        <button v-if="p.cable_id" class="btn btn-icon" type="button" :aria-label="`Rastrear ${p.name}`" title="Rastrear o caminho" @click="tracing = p"><AppIcon name="route" :size="15" /></button>
                        <button v-if="canEdit && !p.cable_id && !p.mark_connected && !['virtual', 'lag', 'bridge'].includes(p.type)" class="btn btn-icon" type="button" :aria-label="`Conectar cabo em ${p.name}`" title="Conectar cabo" @click="connect = p"><AppIcon name="cable" :size="15" /></button>
                        <button v-if="canEdit && p.cable_id" class="btn btn-icon" type="button" :aria-label="`Desconectar ${p.name}`" title="Remover cabo" @click="disconnect(p)"><AppIcon name="unplug" :size="15" /></button>
                        <button v-if="canEdit && p.kind === 'interface'" class="btn btn-icon" type="button" :aria-label="`Adicionar IP em ${p.name}`" title="Adicionar IP" @click="addIp = { port_id: p.id }"><AppIcon name="hash" :size="15" /></button>
                        <button class="edit-only btn btn-icon" type="button" :aria-label="`Editar ${p.name}`" title="Editar" @click="editPort = p"><AppIcon name="edit" :size="15" /></button>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </section>
            <section v-if="interfaces.length && !d.role.toLowerCase().includes('patch')" class="panel">
              <div class="panel-head"><h2><AppIcon name="route" :size="17" /> Caminho até a internet</h2>
                <button class="btn" type="button" @click="showPath = !showPath">{{ showPath ? 'Ocultar' : 'Mostrar' }}</button></div>
              <PathToInternet v-if="showPath" :device-id="d.id" :start-name="d.name" />
              <p v-else class="empty">Switches, cabos, firewall e link da operadora entre este equipamento e a internet, passo a passo.</p>
            </section>
          </div>
          <div>
            <section v-if="d.cluster_id || d.vms.length" class="panel virt">
              <div class="panel-head"><h2><AppIcon name="cpu" :size="17" /> Virtualização</h2>
                <RouterLink v-if="d.cluster_id" :to="`/virtualization/clusters/${d.cluster_id}`" class="dc-link dc-small"><b class="plat" :style="{ background: platformBadge(d.cluster_type).color }">{{ platformBadge(d.cluster_type).short }}</b> {{ d.cluster }}</RouterLink></div>
              <div class="rings">
                <ResourceRing label="vCPU" :used="vmUse.cpu" :total="d.capacity.cpu_threads" :size="84" />
                <ResourceRing label="RAM" :used="vmUse.mem" :total="d.capacity.memory_mb" :format="gb" :size="84" />
                <ResourceRing label="Disco" :used="vmUse.disk" :total="d.capacity.storage_gb" unit="GB" :size="84" />
              </div>
              <p v-if="d.capacity.capacity_from_inventory" class="muted dc-small pad">Capacidade lida do inventário do GLPI Agent.</p>
              <ul v-if="d.vms.length" class="vml">
                <li v-for="v in d.vms" :key="v.id"><span class="led" :class="v.status" :title="VM_STATUS[v.status]?.label" />
                  <RouterLink :to="`/virtualization/vms/${v.id}`" class="dc-link">{{ v.name }}</RouterLink>
                  <span class="muted dc-small">{{ num(v.vcpus) }} vCPU · {{ gb(v.memory_mb) }}{{ v.primary_ip ? ' · ' + v.primary_ip : '' }}</span></li>
              </ul>
              <p v-else class="empty">Nenhuma VM neste host ainda.</p>
            </section>
            <section class="panel">
              <div class="panel-head"><h2>Dados</h2></div>
              <dl class="dc-kv">
                <dt>Tipo</dt><dd><RouterLink :to="`/dcim/device-types/${d.device_type_id}`" class="dc-link">{{ d.manufacturer }} {{ d.device_type }}</RouterLink></dd>
                <dt>Filial</dt><dd>{{ d.branch }}</dd>
                <dt>Local</dt><dd>{{ d.location ?? '—' }}</dd>
                <dt>Rack</dt><dd>{{ d.rack ? `${d.rack} ${uLabel(d.position)}${d.face === 'rear' ? ' (traseira)' : d.face === 'front' ? ' (frontal)' : ''}` : '—' }}</dd>
                <dt>Nº de série</dt><dd class="mono">{{ d.serial ?? '—' }}</dd>
                <dt>Patrimônio</dt><dd class="mono">{{ d.asset_tag ?? '—' }}</dd>
                <dt>IP primário</dt><dd class="mono">{{ d.primary_ip ?? '—' }}</dd>
                <dt>Ativo (inventário)</dt><dd><RouterLink v-if="d.asset_uuid" :to="`/assets/${d.asset_uuid}`" class="dc-link"><AppIcon name="pc" :size="13" /> {{ d.asset_name }}</RouterLink><span v-else class="muted">não vinculado</span></dd>
                <template v-if="d.description"><dt>Descrição</dt><dd>{{ d.description }}</dd></template>
                <template v-if="d.comments"><dt>Observações</dt><dd class="pre">{{ d.comments }}</dd></template>
              </dl>
            </section>
            <section v-if="d.power" class="panel">
              <div class="panel-head"><h2>Energia</h2></div>
              <dl class="dc-kv">
                <dt>Tomadas em uso</dt><dd>{{ d.power.used_outlets }} de {{ d.power.outlets }}</dd>
                <dt>Carga alocada</dt><dd>{{ d.power.allocated_w }} W<template v-if="d.power.maximum_w"> de {{ d.power.maximum_w }} W ({{ Math.round(100 * d.power.allocated_w / d.power.maximum_w) }}%)</template></dd>
              </dl>
            </section>
            <section class="panel">
              <div class="panel-head"><h2>Endereços IP <span class="muted">({{ d.ips.length }})</span></h2>
                <button v-if="interfaces.length" class="edit-only btn" type="button" @click="addIp = {}"><AppIcon name="plus" :size="15" /> IP</button></div>
              <p v-if="!d.ips.length" class="empty">Nenhum IP nas interfaces.</p>
              <ul v-else class="ips">
                <li v-for="ip in d.ips" :key="ip.id"><span class="mono strong">{{ ip.address }}</span> <span class="muted dc-small">{{ ip.port }}{{ ip.vrf ? ' · ' + ip.vrf : '' }}{{ ip.dns_name ? ' · ' + ip.dns_name : '' }}</span>
                  <span v-if="ip.id === d.primary_ip_id" class="pill pill-accent">primário</span>
                  <span v-if="ip.status !== 'active'" class="pill" :class="IP_STATUS[ip.status]?.cls">{{ IP_STATUS[ip.status]?.label }}</span>
                  <button class="edit-only btn btn-icon" type="button" :aria-label="`Excluir ${ip.address}`" @click="removeIp(ip.id)"><AppIcon name="trash" :size="14" /></button></li>
              </ul>
            </section>
            <PhotoGallery owner-type="device" :owner="d.id" :items="d.attachments" :extra="d.type_photo ? { uuid: d.type_photo, label: 'Foto do modelo' } : null" @changed="reload" />
            <AttachmentsPanel owner-type="device" :owner="d.id" :items="d.attachments.filter((a) => a.kind === 'document')" title="Documentos" @changed="reload" />
          </div>
        </div>
        <DeviceFormModal v-if="editing" :device="d" @close="editing = false" @saved="editing = false; reload()" />
        <PortFormModal v-if="addingPorts" target="device" :rears="rears" :branch-id="d.branch_id" :save="(b) => dcim.addPorts(d!.id, b)" @close="addingPorts = false" @saved="addingPorts = false; reload()" />
        <PortFormModal v-if="editPort" target="device" :port="editPort" :rears="rears" :branch-id="d.branch_id" :save="(b) => dcim.savePort(editPort!.id, b)" @close="editPort = null" @saved="editPort = null; reload()" />
        <CableModal v-if="connect" :a="{ type: 'port', ids: [connect.id], kind: connect.kind, label: `${d.name} ${connect.name}`, branchId: d.branch_id }" @close="connect = null" @saved="connect = null; reload()" />
        <TraceModal v-if="tracing" :port-id="tracing.id" :title="`Rastreamento — ${d.name} ${tracing.name}`" @close="tracing = null" />
        <IpForm v-if="addIp" :ports="interfaces" :branch-id="d.branch_id" :preset="addIp" @close="addIp = null" @saved="addIp = null; reload()" />
      </template>
    </LoadState>
  </div>
</template>

<style scoped>
.sel { width: 32px; }
.empty { padding: 0 20px 16px; margin: 0; }
.ports tr.off td { opacity: .55; }
.ports td { padding-top: 6px; padding-bottom: 6px; vertical-align: middle; }
.ports td:nth-child(3) { white-space: nowrap; }
.ports :deep(.btn-icon) { width: 28px; height: 28px; min-width: 28px; padding: 0; }
.ports .dc-actions-row { gap: 2px; }
.ips { list-style: none; margin: 0; padding: 0 20px 16px; display: flex; flex-direction: column; gap: 6px; font-size: 13.5px; }
.ips li { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.ips li .btn { margin-left: auto; }
.strong { font-weight: 600; }
.pre { white-space: pre-wrap; }
.virt { background: linear-gradient(160deg, color-mix(in srgb, #009688 10%, transparent), transparent 60%), var(--panel); }
.virt .rings { display: flex; justify-content: space-around; gap: 10px; padding: 4px 16px 10px; }
.virt .pad { padding: 0 20px; margin: 0 0 8px; }
.plat { color: #fff; font-size: 10px; padding: 1px 6px; border-radius: 5px; }
.vml { list-style: none; margin: 0; padding: 0 20px 16px; display: flex; flex-direction: column; gap: 6px; font-size: 13.5px; }
.vml li { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.led { width: 8px; height: 8px; border-radius: 50%; background: var(--text-3); }
.led.active { background: var(--ok); box-shadow: 0 0 8px var(--ok); }
.led.failed { background: var(--bad); }
</style>
