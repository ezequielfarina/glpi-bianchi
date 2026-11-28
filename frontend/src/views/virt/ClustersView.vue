<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import LoadState from '../../components/LoadState.vue'
import SpecForm from '../../components/dcim/SpecForm.vue'
import ResourceRing from '../../components/virt/ResourceRing.vue'
import { api } from '../../lib/api'
import { dcim, type LocationRow } from '../../lib/dcim'
import { notify } from '../../lib/toast'
import type { BranchRow } from '../../lib/types'
import { CLUSTER_STATUS, gb, platformBadge, virt, type ClusterLookup, type ClusterRow, type VirtChoices } from '../../lib/virt'
import { clusterFields } from './clusterFields'

const router = useRouter()
const ch = ref<VirtChoices | null>(null)
const branches = ref<BranchRow[]>([])
const locations = ref<LocationRow[]>([])
const branch = ref<number | null>(null)
const q = ref('')
const items = ref<ClusterRow[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const creating = ref(false)
const showLookups = ref(false)
const lookup = ref<{ kind: 'type' | 'group'; row: ClusterLookup | null } | null>(null)
async function loadChoices(): Promise<void> { ch.value = await virt.choices() }
void loadChoices()
void api.branches().then((r) => { branches.value = r.items })
void dcim.locations().then((r) => { locations.value = r.items })
let t: number | undefined
async function load(): Promise<void> {
  try { items.value = (await virt.clusters({ branch: branch.value, q: q.value.trim() || undefined })).items; error.value = null }
  catch (e) { error.value = e instanceof Error ? e.message : String(e) } finally { loading.value = false }
}
watch(branch, load, { immediate: true })
watch(q, () => { window.clearTimeout(t); t = window.setTimeout(load, 250) })
const fields = computed(() => (ch.value ? clusterFields(ch.value, branches.value.map((b) => ({ value: b.id, label: b.name })),
  locations.value.map((l) => ({ value: l.id, label: `${l.branch_code ?? l.branch ?? ''} · ${'— '.repeat(l.depth)}${l.name}` }))) : []))
const totals = computed(() => items.value.reduce((a, c) => ({ hosts: a.hosts + c.hosts, vms: a.vms + c.vms, vcpus: a.vcpus + c.vcpus, mem: a.mem + c.memory_mb }), { hosts: 0, vms: 0, vcpus: 0, mem: 0 }))
async function removeLookup(kind: 'type' | 'group', x: ClusterLookup): Promise<void> {
  if (!window.confirm(`Excluir "${x.name}"?`)) return
  await virt.deleteLookup(kind, x.id)
  notify('ok', 'Excluído')
  await loadChoices()
}
</script>

<template>
  <div class="dc-page">
    <header class="dc-head">
      <div><h1>Clusters de virtualização</h1><p class="muted">Proxmox, ESXi/vSphere, Hyper-V… com os servidores físicos (hosts) e as VMs de cada um.</p></div>
      <div class="actions">
        <button class="btn" type="button" @click="showLookups = !showLookups"><AppIcon name="settings" :size="15" /> Plataformas e grupos</button>
        <button class="edit-only btn btn-primary" type="button" @click="creating = true"><AppIcon name="plus" :size="16" /> Novo cluster</button>
      </div>
    </header>
    <section v-if="items.length" class="kpis">
      <div class="kpi"><span>Clusters</span><strong>{{ items.length }}</strong></div>
      <div class="kpi"><span>Hosts físicos</span><strong>{{ totals.hosts }}</strong></div>
      <div class="kpi"><span>VMs</span><strong>{{ totals.vms }}</strong></div>
      <div class="kpi"><span>vCPU alocadas</span><strong>{{ String(+totals.vcpus.toFixed(1)).replace('.', ',') }}</strong></div>
      <div class="kpi"><span>RAM alocada</span><strong>{{ gb(totals.mem) }}</strong></div>
    </section>
    <section v-if="showLookups && ch" class="panel">
      <div class="lk">
        <div v-for="kind in (['type', 'group'] as const)" :key="kind">
          <div class="panel-head"><h2>{{ kind === 'type' ? 'Plataformas' : 'Grupos' }}</h2><button class="edit-only btn" type="button" @click="lookup = { kind, row: null }"><AppIcon name="plus" :size="14" /> Novo</button></div>
          <div class="chips"><span v-for="x in (kind === 'type' ? ch.types : ch.groups)" :key="x.id" class="tchip">{{ x.name }} <span class="muted">({{ x.clusters }})</span>
            <button class="edit-only link-btn" type="button" :aria-label="`Editar ${x.name}`" @click="lookup = { kind, row: x }"><AppIcon name="edit" :size="12" /></button>
            <button v-if="!x.clusters" class="edit-only link-btn" type="button" :aria-label="`Excluir ${x.name}`" @click="removeLookup(kind, x)"><AppIcon name="trash" :size="12" /></button></span>
            <span v-if="kind === 'group' && !ch.groups.length" class="muted dc-small">Opcional: Produção, Homologação, DR…</span></div>
        </div>
      </div>
    </section>
    <section class="panel">
      <div class="dc-filters">
        <select v-model="branch" class="input" aria-label="Filial"><option :value="null">Todas as filiais</option><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
        <input v-model="q" class="input search" type="search" placeholder="Nome ou descrição…" aria-label="Buscar cluster" />
      </div>
      <LoadState :loading="loading && !items.length" :error="error" :rows="3" @retry="load">
        <div v-if="!items.length" class="dc-empty-cta">
          <AppIcon name="cube" :size="40" />
          <p class="muted">Nenhum cluster ainda. Comece pelo seu Proxmox ou ESXi: cadastre o cluster, adicione o servidor físico como host e depois as VMs.</p>
          <button class="edit-only btn btn-primary" type="button" @click="creating = true"><AppIcon name="plus" :size="16" /> Cadastrar o primeiro cluster</button>
        </div>
        <div v-else class="cards">
          <RouterLink v-for="c in items" :key="c.id" :to="`/virtualization/clusters/${c.id}`" class="ccard" :style="{ '--plat': platformBadge(c.type).color }">
            <div class="top">
              <span class="plat">{{ platformBadge(c.type).short }}</span>
              <div class="tt"><strong>{{ c.name }}</strong><span class="muted dc-small">{{ c.type }}{{ c.version ? ' ' + c.version : '' }}{{ c.branch ? ' · ' + c.branch : '' }}</span></div>
              <span class="pill" :class="CLUSTER_STATUS[c.status]?.cls">{{ CLUSTER_STATUS[c.status]?.label }}</span>
            </div>
            <div class="rings">
              <ResourceRing label="vCPU" :used="c.vcpus" :total="c.cap_cpu_threads" :size="84" />
              <ResourceRing label="RAM" :used="c.memory_mb" :total="c.cap_memory_mb" :format="gb" :size="84" />
              <ResourceRing label="Disco" :used="c.disk_gb" :total="c.cap_storage_gb" unit="GB" :size="84" />
            </div>
            <div class="foot"><span><AppIcon name="server" :size="13" /> {{ c.hosts }} host(s)</span><span><AppIcon name="vm" :size="13" /> {{ c.vms_active }}/{{ c.vms }} VMs ligadas</span></div>
          </RouterLink>
        </div>
      </LoadState>
    </section>
    <SpecForm v-if="creating && ch" ctx="cluster" title="Novo cluster de virtualização" :fields="fields" :initial="{ status: 'active', branch_id: branch ?? '' }"
      :save="(v) => virt.saveCluster(null, v)" ok-text="Cluster cadastrado" @close="creating = false" @saved="(id) => router.push(`/virtualization/clusters/${id}`)" />
    <SpecForm v-if="lookup" :title="lookup.row ? `Editar ${lookup.row.name}` : lookup.kind === 'type' ? 'Nova plataforma' : 'Novo grupo'"
      :fields="[{ key: 'name', label: 'Nome', required: true, placeholder: lookup.kind === 'type' ? 'oVirt' : 'Produção' }, { key: 'description', label: 'Descrição', full: true }]"
      :initial="lookup.row ? { ...lookup.row } : {}" :save="(v) => virt.saveLookup(lookup!.kind, lookup!.row?.id ?? null, v)" ok-text="Salvo"
      @close="lookup = null" @saved="lookup = null; loadChoices()" />
  </div>
</template>

<style scoped>
.kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
.kpi { padding: 14px 16px; border-radius: 16px; border: 1px solid var(--border); background: linear-gradient(140deg, var(--panel), color-mix(in srgb, var(--accent) 8%, var(--panel))); display: flex; flex-direction: column; gap: 2px; }
.kpi span { font-size: 11.5px; text-transform: uppercase; letter-spacing: .06em; color: var(--text-3); }
.kpi strong { font-size: 24px; font-weight: 650; }
.lk { display: grid; grid-template-columns: 1fr 1fr; }
@media (max-width: 800px) { .lk { grid-template-columns: 1fr; } }
.chips { display: flex; flex-wrap: wrap; gap: 6px; padding: 0 20px 16px; align-items: center; }
.chips .tchip { display: inline-flex; gap: 4px; align-items: center; }
.cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 16px; padding: 0 20px 20px; }
.ccard { position: relative; display: flex; flex-direction: column; gap: 14px; padding: 16px; border-radius: 18px; text-decoration: none; color: var(--text); overflow: hidden;
  border: 1px solid var(--border); background: radial-gradient(120% 90% at 0% 0%, color-mix(in srgb, var(--plat) 22%, transparent), transparent 60%), var(--bg-elev);
  transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease; }
.ccard:hover { transform: translateY(-3px) perspective(800px) rotateX(2deg); border-color: var(--plat); box-shadow: 0 18px 36px -18px var(--plat); }
.top { display: flex; align-items: center; gap: 12px; }
.plat { width: 46px; height: 46px; border-radius: 13px; display: grid; place-items: center; font-weight: 800; font-size: 11.5px; color: #fff; background: var(--plat);
  box-shadow: 0 8px 18px -8px var(--plat), inset 0 1px 0 rgba(255,255,255,.25); flex: none; }
.tt { display: flex; flex-direction: column; flex: 1; min-width: 0; }
.tt strong { font-size: 16px; }
.rings { display: flex; justify-content: space-around; }
.foot { display: flex; justify-content: space-between; font-size: 12.5px; color: var(--text-2); }
.foot span { display: inline-flex; gap: 5px; align-items: center; }
</style>
