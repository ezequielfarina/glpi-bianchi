<script setup lang="ts">
import { safeHref } from '../../lib/safeUrl'
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import AppModal from '../../components/AppModal.vue'
import AttachmentsPanel from '../../components/AttachmentsPanel.vue'
import HelpTip from '../../components/HelpTip.vue'
import LoadState from '../../components/LoadState.vue'
import PhotoGallery from '../../components/dcim/PhotoGallery.vue'
import SpecForm from '../../components/dcim/SpecForm.vue'
import ResourceRing from '../../components/virt/ResourceRing.vue'
import VmFormModal from '../../components/virt/VmFormModal.vue'
import { api } from '../../lib/api'
import { dcim, hex, onColor, uLabel, type DeviceRow, type LocationRow } from '../../lib/dcim'
import { notify } from '../../lib/toast'
import type { BranchRow } from '../../lib/types'
import { useAsync } from '../../lib/useAsync'
import { CLUSTER_STATUS, gb, num, platformBadge, virt, VM_STATUS, type ClusterHost, type VirtChoices } from '../../lib/virt'
import { clusterFields } from './clusterFields'

const props = defineProps<{ id: string }>()
const router = useRouter()
const { data: c, loading, error, reload } = useAsync(() => virt.cluster(Number(props.id)))
const ch = ref<VirtChoices | null>(null)
const branches = ref<BranchRow[]>([])
const locations = ref<LocationRow[]>([])
void virt.choices().then((x) => { ch.value = x })
void api.branches().then((r) => { branches.value = r.items })
void dcim.locations().then((r) => { locations.value = r.items })
const editing = ref(false)
const newVm = ref<{ device_id?: number } | null>(null)
const adding = ref(false)
const q = ref('')
const candidates = ref<DeviceRow[]>([])
const pb = computed(() => platformBadge(c.value?.type))
const fields = computed(() => (ch.value ? clusterFields(ch.value, branches.value.map((b) => ({ value: b.id, label: b.name })),
  locations.value.map((l) => ({ value: l.id, label: `${l.branch_code ?? l.branch ?? ''} · ${'— '.repeat(l.depth)}${l.name}` }))) : []))
async function searchHosts(): Promise<void> {
  const list = (await dcim.devices({ q: q.value.trim() || undefined, branch: c.value?.branch_id ?? undefined })).items
  candidates.value = list.filter((d) => !c.value?.hosts_list.some((h) => h.id === d.id) && !d.role.toLowerCase().match(/patch|ponto|tomada|dio|organizador|quadro|pdu/))
}
async function openAdd(): Promise<void> { adding.value = true; await searchHosts() }
async function addHost(d: DeviceRow): Promise<void> {
  try { await virt.setHost(c.value!.id, d.id, true); notify('ok', `${d.name} agora é host do cluster`); adding.value = false; await reload() }
  catch (e) { notify('warn', 'Não foi possível adicionar', e instanceof Error ? e.message : String(e)) }
}
async function removeHost(h: ClusterHost): Promise<void> {
  if (!window.confirm(`Tirar ${h.name} do cluster? ${h.vms ? `As ${h.vms} VM(s) dele ficam sem host definido.` : ''}`)) return
  await virt.setHost(c.value!.id, h.id, false)
  await reload()
}
async function remove(): Promise<void> {
  if (!c.value || !window.confirm(`Excluir o cluster ${c.value.name}?`)) return
  await virt.deleteCluster(c.value.id)
  notify('ok', 'Cluster excluído')
  await router.push('/virtualization/clusters')
}
const pct = (u: number, t: number | null) => (t ? Math.min(100, Math.round((100 * u) / t)) : 0)
</script>

<template>
  <div class="dc-page">
    <RouterLink to="/virtualization/clusters" class="dc-back"><AppIcon name="back" :size="16" /> Clusters</RouterLink>
    <LoadState :loading="loading" :error="error" :rows="6" @retry="reload">
      <template v-if="c">
        <header class="hero" :style="{ '--plat': pb.color }">
          <span class="plat">{{ pb.short }}</span>
          <div class="ht">
            <p class="muted">{{ c.type }}{{ c.version ? ' ' + c.version : '' }}{{ c.group ? ' · ' + c.group : '' }}{{ c.branch ? ' · ' + c.branch : '' }}{{ c.location ? ' › ' + c.location : '' }}</p>
            <h1>{{ c.name }} <span class="pill" :class="CLUSTER_STATUS[c.status]?.cls">{{ CLUSTER_STATUS[c.status]?.label }}</span></h1>
            <p v-if="c.description" class="muted">{{ c.description }}</p>
          </div>
          <div class="actions">
            <a v-if="c.management_url" :href="safeHref(c.management_url)" target="_blank" rel="noopener" class="btn btn-primary"><AppIcon name="external" :size="15" /> Abrir console</a>
            <button class="edit-only btn" type="button" @click="newVm = {}"><AppIcon name="plus" :size="15" /> VM</button>
            <button class="edit-only btn" type="button" @click="editing = true"><AppIcon name="edit" :size="15" /> Editar</button>
            <button class="edit-only btn btn-icon" type="button" aria-label="Excluir cluster" @click="remove"><AppIcon name="trash" :size="15" /></button>
          </div>
          <div class="rings">
            <ResourceRing label="vCPU" :used="c.vcpus" :total="c.cap_cpu_threads" />
            <ResourceRing label="RAM" :used="c.memory_mb" :total="c.cap_memory_mb" :format="gb" />
            <ResourceRing label="Disco" :used="c.disk_gb" :total="c.cap_storage_gb" unit="GB" />
          </div>
        </header>
        <div class="dc-cols">
          <div>
            <section class="panel">
              <div class="panel-head"><h2>Servidores físicos (hosts) <span class="muted">({{ c.hosts_list.length }})</span><HelpTip ctx="cluster" k="host" label="Hosts" /></h2>
                <button class="edit-only btn" type="button" @click="openAdd"><AppIcon name="plus" :size="15" /> Adicionar host</button></div>
              <p v-if="!c.hosts_list.length" class="empty">Nenhum host. Adicione o servidor físico (cadastrado em Equipamentos, com rack e cabos) — é por ele que o caminho até a internet é calculado.</p>
              <div class="hosts">
                <article v-for="h in c.hosts_list" :key="h.id" class="host">
                  <div class="hh"><AppIcon name="server" :size="18" />
                    <RouterLink :to="`/dcim/devices/${h.id}`" class="dc-strong">{{ h.name }}</RouterLink>
                    <span class="muted dc-small">{{ h.manufacturer }} {{ h.device_type }}{{ h.rack ? ` · ${h.rack} ${uLabel(h.position)}` : '' }}</span>
                    <button class="edit-only btn btn-icon rm" type="button" :aria-label="`Tirar ${h.name} do cluster`" title="Tirar do cluster" @click="removeHost(h)"><AppIcon name="close" :size="14" /></button></div>
                  <div class="bars">
                    <div><span>vCPU</span><div class="bar"><i :style="{ width: pct(h.used_vcpus, h.cpu_threads) + '%' }" /></div><b>{{ num(h.used_vcpus) }} / {{ h.cpu_threads ?? '?' }}</b></div>
                    <div><span>RAM</span><div class="bar"><i :style="{ width: pct(h.used_memory_mb, h.memory_mb) + '%' }" /></div><b>{{ gb(h.used_memory_mb) }} / {{ gb(h.memory_mb) }}</b></div>
                    <div><span>Disco</span><div class="bar"><i :style="{ width: pct(h.used_disk_gb, h.storage_gb) + '%' }" /></div><b>{{ h.used_disk_gb }} / {{ h.storage_gb ?? '?' }} GB</b></div>
                  </div>
                  <div class="hf muted dc-small">{{ h.vms }} VM(s){{ h.primary_ip ? ' · ' + h.primary_ip : '' }}
                    <template v-if="h.capacity_from_inventory"> · capacidade do inventário do agente</template>
                    <template v-else-if="h.cpu_threads === null"> · informe a capacidade no equipamento (Editar)</template></div>
                </article>
              </div>
            </section>
            <section class="panel">
              <div class="panel-head"><h2>Máquinas virtuais <span class="muted">({{ c.vm_list.length }})</span></h2>
                <button class="edit-only btn" type="button" @click="newVm = {}"><AppIcon name="plus" :size="15" /> Nova VM</button></div>
              <p v-if="!c.vm_list.length" class="empty">Nenhuma VM.</p>
              <div v-else class="table-wrap"><table class="table">
                <thead><tr><th>VM</th><th>Host</th><th>SO</th><th class="num">vCPU</th><th class="num">RAM</th><th class="num">Disco</th><th>IP</th><th>Situação</th></tr></thead>
                <tbody><tr v-for="v in c.vm_list" :key="v.id" class="dc-clickable" @click="router.push(`/virtualization/vms/${v.id}`)">
                  <td><RouterLink :to="`/virtualization/vms/${v.id}`" class="dc-strong" @click.stop>{{ v.name }}</RouterLink> <span v-if="v.vmid" class="muted mono dc-small">#{{ v.vmid }}</span>
                    <span v-if="v.role && v.role !== 'Máquina virtual'" class="dc-role" :style="{ background: hex(v.color), color: onColor(v.color) }">{{ v.role }}</span></td>
                  <td>{{ v.host ?? '—' }}</td><td class="muted">{{ v.platform }}</td><td class="num">{{ num(v.vcpus) }}</td><td class="num">{{ gb(v.memory_mb) }}</td>
                  <td class="num">{{ v.disk_gb ? v.disk_gb + ' GB' : '—' }}</td><td class="mono">{{ v.primary_ip }}</td>
                  <td><span class="pill" :class="VM_STATUS[v.status]?.cls">{{ VM_STATUS[v.status]?.label }}</span></td>
                </tr></tbody>
              </table></div>
            </section>
          </div>
          <div>
            <section v-if="c.comments" class="panel"><div class="panel-head"><h2>Observações</h2></div><p class="txt">{{ c.comments }}</p></section>
            <PhotoGallery owner-type="cluster" :owner="c.id" :items="c.attachments" @changed="reload" />
            <AttachmentsPanel owner-type="cluster" :owner="c.id" :items="c.attachments.filter((a) => a.kind === 'document')" title="Documentos" @changed="reload" />
          </div>
        </div>
        <SpecForm v-if="editing && ch" ctx="cluster" :title="`Editar ${c.name}`" :fields="fields"
          :initial="{ name: c.name, type_id: c.type_id, status: c.status, version: c.version ?? '', management_url: c.management_url ?? '', branch_id: c.branch_id ?? '', location_id: c.location_id ?? '', group_id: c.group_id ?? '', description: c.description ?? '', comments: c.comments ?? '' }"
          :save="(v) => virt.saveCluster(c!.id, v)" ok-text="Cluster atualizado" @close="editing = false" @saved="editing = false; reload()" />
        <VmFormModal v-if="newVm" :preset="{ cluster_id: c.id, ...newVm }" @close="newVm = null" @saved="(id) => router.push(`/virtualization/vms/${id}`)" />
        <AppModal v-if="adding" title="Adicionar servidor físico ao cluster" wide @close="adding = false">
          <div class="pick">
            <input v-model="q" class="input" type="search" placeholder="Buscar servidor pelo nome, série ou modelo…" aria-label="Buscar servidor" @input="searchHosts" />
            <p class="muted dc-small">O servidor precisa estar cadastrado em Infraestrutura → Equipamentos. Não está? <RouterLink to="/dcim/devices" class="dc-link">Cadastre aqui</RouterLink> (dica: use a função "Host de virtualização").</p>
            <ul class="plist">
              <li v-for="d in candidates" :key="d.id"><button type="button" @click="addHost(d)">
                <span class="dc-role" :style="{ background: hex(d.color), color: onColor(d.color) }">{{ d.role }}</span> <strong>{{ d.name }}</strong>
                <span class="muted">{{ d.manufacturer }} {{ d.device_type }} · {{ d.branch }}{{ d.rack ? ' · ' + d.rack : '' }}</span></button></li>
              <li v-if="!candidates.length" class="muted">Nenhum equipamento encontrado.</li>
            </ul>
          </div>
        </AppModal>
      </template>
    </LoadState>
  </div>
</template>

<style scoped>
.hero { position: relative; display: grid; grid-template-columns: auto 1fr auto; grid-template-areas: 'plat ht act' 'rings rings rings'; gap: 16px 18px; padding: 22px;
  border-radius: 22px; border: 1px solid var(--border); overflow: hidden;
  background: radial-gradient(90% 120% at 0% 0%, color-mix(in srgb, var(--plat) 26%, transparent), transparent 55%),
              radial-gradient(60% 80% at 100% 100%, color-mix(in srgb, var(--accent) 12%, transparent), transparent 60%), var(--panel); }
.hero .plat { grid-area: plat; width: 64px; height: 64px; border-radius: 18px; display: grid; place-items: center; font-weight: 800; color: #fff; background: var(--plat);
  box-shadow: 0 14px 30px -12px var(--plat), inset 0 1px 0 rgba(255,255,255,.3); transform: perspective(300px) rotateY(-12deg); }
.ht { grid-area: ht; min-width: 0; }
.ht h1 { margin: 0; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.ht p { margin: 2px 0; font-size: 13px; }
.hero .actions { grid-area: act; align-self: start; display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
.hero .rings { grid-area: rings; display: flex; gap: 32px; flex-wrap: wrap; }
@media (max-width: 760px) { .hero { grid-template-columns: auto 1fr; grid-template-areas: 'plat ht' 'act act' 'rings rings'; } }
.empty { padding: 0 20px 16px; margin: 0; font-size: 13.5px; }
.hosts { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 12px; padding: 0 20px 20px; }
.host { border: 1px solid var(--border); border-radius: 16px; padding: 12px 14px; background: var(--bg-elev); display: flex; flex-direction: column; gap: 10px; }
.hh { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; color: var(--accent-text); }
.hh .rm { margin-left: auto; }
.bars { display: flex; flex-direction: column; gap: 6px; font-size: 12px; }
.bars > div { display: grid; grid-template-columns: 44px 1fr auto; gap: 8px; align-items: center; }
.bars span { color: var(--text-3); }
.bars b { font: 500 11.5px var(--mono); color: var(--text-2); }
.bar { height: 7px; border-radius: 4px; background: var(--muted-soft); overflow: hidden; }
.bar i { display: block; height: 100%; border-radius: 4px; background: linear-gradient(90deg, var(--accent), color-mix(in srgb, var(--accent) 50%, #a855f7)); transition: width .8s ease; }
.txt { padding: 0 20px 16px; margin: 0; white-space: pre-wrap; font-size: 13.5px; }
.pick { display: flex; flex-direction: column; gap: 10px; }
.plist { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; max-height: 50vh; overflow: auto; }
.plist button { all: unset; box-sizing: border-box; width: 100%; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; padding: 8px 10px; border-radius: 10px; cursor: pointer; border: 1px solid transparent; }
.plist button:hover, .plist button:focus-visible { background: var(--accent-soft); border-color: var(--accent); }
</style>
