<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import AppModal from '../AppModal.vue'
import AppIcon from '../AppIcon.vue'
import HelpTip from '../HelpTip.vue'
import LookupSelect from '../LookupSelect.vue'
import { dcimChoices, type DcimChoices } from '../../lib/dcim'
import { platformBadge, virt, type ClusterHost, type ClusterRow, type VirtChoices, type VmAssetCandidate, type VmDetail } from '../../lib/virt'
import { clean, useForm } from '../forms/useForm'

// Cadastro/edição de VM: cluster → host → recursos; vínculo opcional com o ativo inventariado dentro da VM.
const props = defineProps<{ vm?: VmDetail | null; preset?: { cluster_id?: number; device_id?: number } }>()
const emit = defineEmits<{ close: []; saved: [id: number] }>()
const { saving, errors, submit } = useForm()
const v = props.vm
const f = reactive({
  name: v?.name ?? '', cluster_id: v?.cluster_id ?? props.preset?.cluster_id ?? ('' as number | ''), device_id: v?.device_id ?? props.preset?.device_id ?? ('' as number | ''),
  status: v?.status ?? 'active', platform: v?.platform ?? '', vmid: v?.vmid ?? '', vcpus: v?.vcpus === null || v?.vcpus === undefined ? '' : String(v.vcpus).replace('.', ','),
  memory_gb: v?.memory_mb ? String(+(v.memory_mb / 1024).toFixed(2)).replace('.', ',') : '', disk_gb: v?.disk_gb ?? ('' as number | ''),
  role_id: v?.role_id ?? ('' as number | ''), asset_uuid: v?.asset_uuid ?? '', description: v?.description ?? '', comments: v?.comments ?? '', copy_interfaces: false,
})
const ch = ref<VirtChoices | null>(null)
const dch = ref<DcimChoices | null>(null)
const clusters = ref<ClusterRow[]>([])
const hosts = ref<ClusterHost[]>([])
void virt.choices().then((c) => { ch.value = c })
void dcimChoices().then((c) => { dch.value = c })
void virt.clusters().then((r) => { clusters.value = r.items })
watch(() => f.cluster_id, async (c, old) => {
  hosts.value = c ? (await virt.cluster(Number(c))).hosts_list : []
  if (old !== undefined && old !== '' && !hosts.value.some((h) => h.id === f.device_id)) f.device_id = ''
  if (!f.device_id && hosts.value.length === 1) f.device_id = hosts.value[0]!.id
}, { immediate: true })
const cluster = computed(() => clusters.value.find((c) => c.id === f.cluster_id))
const hostFree = (h: ClusterHost) => [h.cpu_threads ? `${+(h.cpu_threads - h.used_vcpus).toFixed(1)} vCPU livres` : null,
  h.memory_mb ? `${+((h.memory_mb - h.used_memory_mb) / 1024).toFixed(0)} GB RAM livres` : null].filter(Boolean).join(' · ')

const assetQ = ref('')
const candidates = ref<VmAssetCandidate[]>([])
const linked = ref<{ uuid: string; name: string } | null>(v?.asset_uuid ? { uuid: v.asset_uuid, name: v.asset_name ?? '' } : null)
let timer: number | undefined
watch(assetQ, (q) => {
  window.clearTimeout(timer)
  if (q.trim().length < 2) { candidates.value = []; return }
  timer = window.setTimeout(async () => { candidates.value = (await virt.assetCandidates(q.trim())).items }, 250)
})
function pick(a: VmAssetCandidate): void {
  linked.value = { uuid: a.uuid, name: a.name }
  f.asset_uuid = a.uuid
  if (!f.name) f.name = a.name
  if (!f.memory_gb && a.memory_mib) f.memory_gb = String(Math.round(Number(a.memory_mib) / 1024))
  f.copy_interfaces = !v && a.interfaces > 0
  assetQ.value = ''
  candidates.value = []
}
async function save(): Promise<void> {
  const mem = f.memory_gb === '' ? null : Math.round(Number(String(f.memory_gb).replace(',', '.')) * 1024)
  const body = clean({ ...f, memory_mb: Number.isFinite(mem) ? mem : f.memory_gb })
  delete body.memory_gb
  const r = await submit(() => virt.saveVm(v?.id ?? null, body), v ? 'VM atualizada' : 'VM cadastrada')
  if (r) emit('saved', r.id)
}
</script>

<template>
  <AppModal :title="v ? `Editar ${v.name}` : 'Nova máquina virtual'" wide @close="emit('close')">
    <form class="form-grid" novalidate @submit.prevent="save">
      <div class="field"><label for="vm-name">Nome *<HelpTip ctx="vm" k="name" label="Nome" /></label>
        <input id="vm-name" v-model="f.name" class="input" placeholder="SRV-AD01" :aria-invalid="!!errors.name" /><span v-if="errors.name" class="err">{{ errors.name }}</span></div>
      <div class="field"><label for="vm-status">Situação<HelpTip k="status" label="Situação" /></label>
        <select id="vm-status" v-model="f.status" class="input"><option v-for="o in ch?.vm_status ?? []" :key="o.value" :value="o.value">{{ o.label }}</option></select></div>
      <div class="field"><label for="vm-cluster">Virtualizador (cluster) *<HelpTip ctx="vm" k="cluster_id" label="Cluster" /></label>
        <select id="vm-cluster" v-model="f.cluster_id" class="input" :aria-invalid="!!errors.cluster_id"><option value="">—</option>
          <option v-for="c in clusters" :key="c.id" :value="c.id">{{ c.name }} — {{ c.type }}</option></select>
        <span v-if="errors.cluster_id" class="err">{{ errors.cluster_id }}</span>
        <span v-else-if="!clusters.length" class="hint">Nenhum cluster ainda: cadastre em Virtualização → Clusters.</span>
        <span v-else-if="cluster" class="hint badge"><b :style="{ background: platformBadge(cluster.type).color }">{{ platformBadge(cluster.type).short }}</b> {{ cluster.hosts }} host(s) · {{ cluster.vms }} VM(s)</span></div>
      <div class="field"><label for="vm-host">Host físico<HelpTip ctx="vm" k="device_id" label="Host" /></label>
        <select id="vm-host" v-model="f.device_id" class="input" :disabled="!f.cluster_id" :aria-invalid="!!errors.device_id"><option value="">— não definido —</option>
          <option v-for="h in hosts" :key="h.id" :value="h.id">{{ h.name }}{{ hostFree(h) ? ` (${hostFree(h)})` : '' }}</option></select>
        <span v-if="errors.device_id" class="err">{{ errors.device_id }}</span>
        <span v-else-if="f.cluster_id && !hosts.length" class="hint">O cluster ainda não tem hosts: adicione o servidor físico na tela do cluster.</span></div>
      <fieldset class="full res">
        <legend>Recursos</legend>
        <div class="field"><label for="vm-cpu"><AppIcon name="cpu" :size="14" /> vCPU<HelpTip ctx="vm" k="vcpus" label="vCPU" /></label>
          <input id="vm-cpu" v-model="f.vcpus" class="input" inputmode="decimal" placeholder="4" :aria-invalid="!!errors.vcpus" /><span v-if="errors.vcpus" class="err">{{ errors.vcpus }}</span></div>
        <div class="field"><label for="vm-mem">Memória (GB)<HelpTip ctx="vm" k="memory_gb" label="Memória" /></label>
          <input id="vm-mem" v-model="f.memory_gb" class="input" inputmode="decimal" placeholder="8" :aria-invalid="!!errors.memory_mb" /><span v-if="errors.memory_mb" class="err">{{ errors.memory_mb }}</span></div>
        <div class="field"><label for="vm-disk">Disco (GB)<HelpTip ctx="vm" k="disk_gb" label="Disco" /></label>
          <input id="vm-disk" v-model="f.disk_gb" class="input" type="number" min="0" placeholder="120" :disabled="!!v?.disks.length" />
          <span v-if="v?.disks.length" class="hint">Soma dos {{ v.disks.length }} disco(s) virtuais.</span></div>
      </fieldset>
      <div class="field"><label for="vm-os">Sistema operacional<HelpTip ctx="vm" k="platform" label="Sistema operacional" /></label>
        <LookupSelect v-model="f.platform" source="platforms" by="name" free input-id="vm-os" placeholder="Windows Server 2022" /></div>
      <div class="field"><label for="vm-vmid">ID no hipervisor<HelpTip ctx="vm" k="vmid" label="ID no hipervisor" /></label><input id="vm-vmid" v-model="f.vmid" class="input mono" placeholder="101" /></div>
      <div class="field"><label for="vm-role">Função<HelpTip ctx="device" k="role_id" label="Função" /></label>
        <LookupSelect v-model="f.role_id" source="roles" input-id="vm-role" placeholder="Máquina virtual (padrão)" /></div>
      <div class="field full"><label for="vm-asset">Ativo do inventário (opcional)<HelpTip ctx="vm" k="asset" label="Ativo" /></label>
        <div v-if="linked" class="linked"><AppIcon name="pc" :size="15" /> <RouterLink :to="`/assets/${linked.uuid}`" target="_blank">{{ linked.name }}</RouterLink>
          <button type="button" class="btn btn-icon" aria-label="Desvincular ativo" @click="linked = null; f.asset_uuid = ''"><AppIcon name="close" :size="14" /></button></div>
        <template v-else>
          <input id="vm-asset" v-model="assetQ" class="input" type="search" placeholder="Buscar computador inventariado pelo nome ou série…" />
          <ul v-if="candidates.length" class="cands"><li v-for="a in candidates" :key="a.uuid"><button type="button" @click="pick(a)"><strong>{{ a.name }}</strong>
            <span class="muted">{{ a.vm_system && a.vm_system !== 'Physical' ? `virtual (${a.vm_system})` : a.type }}{{ a.memory_mib ? ` · ${Math.round(Number(a.memory_mib) / 1024)} GB` : '' }}{{ a.branch ? ` · ${a.branch}` : '' }}</span></button></li></ul>
        </template>
        <span v-if="errors.asset_uuid" class="err">{{ errors.asset_uuid }}</span></div>
      <label v-if="!v && linked" class="check full"><input v-model="f.copy_interfaces" type="checkbox" /> Copiar as placas de rede do inventário como interfaces da VM (nome + MAC)</label>
      <div class="field full"><label for="vm-desc">Descrição<HelpTip k="description" label="Descrição" /></label><input id="vm-desc" v-model="f.description" class="input" placeholder="Controlador de domínio primário" /></div>
      <div class="field full"><label for="vm-com">Observações<HelpTip k="comments" label="Observações" /></label><textarea id="vm-com" v-model="f.comments" class="input" rows="2" /></div>
      <div class="form-actions full">
        <button class="btn" type="button" @click="emit('close')">Cancelar</button>
        <button class="btn btn-primary" type="submit" :disabled="saving">{{ saving ? 'Salvando…' : 'Salvar' }}</button>
      </div>
    </form>
  </AppModal>
</template>

<style scoped>
.res { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; border: 1px solid var(--border); border-radius: 14px; padding: 12px 14px 14px; margin: 0;
  background: linear-gradient(135deg, color-mix(in srgb, var(--accent) 7%, transparent), transparent); }
.res legend { padding: 0 6px; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: var(--text-3); }
@media (max-width: 640px) { .res { grid-template-columns: 1fr; } }
.badge { display: inline-flex; gap: 6px; align-items: center; }
.badge b { color: #fff; font-size: 10px; padding: 1px 6px; border-radius: 5px; }
.linked { display: flex; align-items: center; gap: 8px; padding: 6px 10px; border: 1px solid var(--border); border-radius: var(--radius-sm); }
.linked a { color: var(--accent-text); flex: 1; }
.cands { list-style: none; margin: 4px 0 0; padding: 4px; border: 1px solid var(--border); border-radius: var(--radius-sm); max-height: 220px; overflow: auto; background: var(--bg-elev); }
.cands button { all: unset; display: block; width: 100%; padding: 6px 8px; border-radius: 6px; cursor: pointer; font-size: 13px; box-sizing: border-box; }
.cands button:hover, .cands button:focus-visible { background: var(--accent-soft); }
</style>
