<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import AppModal from '../AppModal.vue'
import HelpTip from '../HelpTip.vue'
import LookupSelect from '../LookupSelect.vue'
import AppIcon from '../AppIcon.vue'
import { api } from '../../lib/api'
import { dcim, dcimChoices, type AssetCandidate, type DcimChoices, type DeviceDetail, type DeviceTypeRow, type LocationRow, type RackRow } from '../../lib/dcim'
import type { BranchRow } from '../../lib/types'
import { clean, useForm } from '../forms/useForm'
import { virt, type ClusterRow } from '../../lib/virt'
import { lookupState } from '../../lib/lookups'

// Cadastro/edição de equipamento: tipo (portas vêm do modelo), função, filial/local/rack/U/face e vínculo opcional com o ativo.
const props = defineProps<{ device?: DeviceDetail | null; preset?: { branch_id?: number; location_id?: number | null; rack_id?: number; position?: number; face?: string; asset?: AssetCandidate } }>()
const emit = defineEmits<{ close: []; saved: [id: number] }>()
const { saving, errors, submit } = useForm()
const d = props.device
const p = props.preset ?? {}
const f = reactive({
  name: d?.name ?? p.asset?.name ?? '', device_type_id: d?.device_type_id ?? '' as number | '', role_id: d?.role_id ?? '' as number | '', status: d?.status ?? 'active',
  branch_id: d?.branch_id ?? p.branch_id ?? p.asset?.branch_id ?? '' as number | '', location_id: d?.location_id ?? p.location_id ?? p.asset?.location_id ?? '' as number | '',
  rack_id: d?.rack_id ?? p.rack_id ?? '' as number | '', position: d?.position ?? p.position ?? '' as number | '', face: d?.face ?? p.face ?? '',
  serial: d?.serial ?? p.asset?.serial_number ?? '', asset_tag: d?.asset_tag ?? p.asset?.inventory_number ?? '', asset_uuid: d?.asset_uuid ?? p.asset?.uuid ?? '',
  description: d?.description ?? '', comments: d?.comments ?? '', copy_interfaces: false,
  cluster_id: d?.cluster_id ?? '' as number | '', cpu_threads: d?.cpu_threads ?? '' as number | '', storage_gb: d?.storage_gb ?? '' as number | '',
})
// memória em GB na tela; a API guarda MB
const memGb = ref<string>(d?.memory_mb ? String(+(d.memory_mb / 1024).toFixed(1)) : '')
const clusters = ref<ClusterRow[]>([])
void virt.clusters().then((r) => { clusters.value = r.items })
const roleName = computed(() => lookupState('roles').items.find((r) => r.id === f.role_id)?.name ?? '')
const showVirt = computed(() => !!f.cluster_id || /virtualiza|servidor|host/i.test(roleName.value))
const choices = ref<DcimChoices | null>(null)
const types = ref<DeviceTypeRow[]>([])
const branches = ref<BranchRow[]>([])
const locations = ref<LocationRow[]>([])
const racks = ref<RackRow[]>([])
void dcimChoices().then((c) => { choices.value = c })
void dcim.types().then((r) => { types.value = r.items })
void api.branches().then((r) => { branches.value = r.items })
watch(() => f.branch_id, async (b) => {
  locations.value = b ? (await dcim.locations(Number(b))).items : []
  racks.value = b ? (await dcim.racks({ branch: Number(b) })).items : []
}, { immediate: true })
const type = computed(() => types.value.find((t) => t.id === f.device_type_id))
watch(() => f.device_type_id, () => { if (!f.role_id && type.value?.default_role_id) f.role_id = type.value.default_role_id })
watch(() => f.rack_id, (r) => {
  const rack = racks.value.find((x) => x.id === r)
  if (rack?.location_id) f.location_id = rack.location_id
  if (!r) { f.position = ''; f.face = '' } else if (!f.face) f.face = 'front'
})
const byManufacturer = computed(() => {
  const g: Record<string, DeviceTypeRow[]> = {}
  for (const t of types.value) (g[t.manufacturer] ??= []).push(t)
  return g
})

// vínculo com ativo do inventário
const assetQ = ref('')
const candidates = ref<AssetCandidate[]>([])
const linked = ref<{ uuid: string; name: string } | null>(d?.asset_uuid ? { uuid: d.asset_uuid, name: d.asset_name ?? '' } : p.asset ? { uuid: p.asset.uuid, name: p.asset.name } : null)
let timer: number | undefined
watch(assetQ, (v) => {
  window.clearTimeout(timer)
  if (v.trim().length < 2) { candidates.value = []; return }
  timer = window.setTimeout(async () => { candidates.value = (await dcim.assetCandidates(v.trim())).items }, 250)
})
function pickAsset(a: AssetCandidate): void {
  linked.value = { uuid: a.uuid, name: a.name }
  f.asset_uuid = a.uuid
  if (!f.name) f.name = a.name
  if (!f.serial && a.serial_number) f.serial = a.serial_number
  if (!f.asset_tag && a.inventory_number) f.asset_tag = a.inventory_number
  if (!f.branch_id && a.branch_id) f.branch_id = a.branch_id
  if (!f.location_id && a.location_id) f.location_id = a.location_id
  f.copy_interfaces = !d && a.interfaces > 0
  assetQ.value = ''
  candidates.value = []
}
function unlink(): void { linked.value = null; f.asset_uuid = '' }

async function save(): Promise<void> {
  const mem = memGb.value.trim() ? Math.round(Number(memGb.value.replace(',', '.')) * 1024) : ''
  const r = await submit(() => dcim.saveDevice(d?.id ?? null, clean({ ...f, memory_mb: mem })), d ? 'Equipamento atualizado' : 'Equipamento cadastrado')
  if (r) emit('saved', r.id)
}
</script>

<template>
  <AppModal :title="d ? `Editar ${d.name}` : 'Novo equipamento'" wide @close="emit('close')">
    <form class="form-grid" novalidate @submit.prevent="save">
      <div class="field"><label for="dv-name">Nome *<HelpTip ctx="device" k="name" label="Nome" /></label><input id="dv-name" v-model="f.name" class="input" placeholder="ex.: SW-CORE-01, PT-112" :aria-invalid="!!errors.name" /><span v-if="errors.name" class="err">{{ errors.name }}</span></div>
      <div class="field"><label for="dv-type">Tipo de equipamento *<HelpTip ctx="device" k="device_type_id" label="Tipo de equipamento" /></label>
        <select id="dv-type" v-model="f.device_type_id" class="input" :disabled="!!d" :aria-invalid="!!errors.device_type_id">
          <option value="">—</option>
          <optgroup v-for="(list, m) in byManufacturer" :key="m" :label="String(m)"><option v-for="t in list" :key="t.id" :value="t.id">{{ t.model }} ({{ t.u_height }}U)</option></optgroup>
        </select>
        <span v-if="errors.device_type_id" class="err">{{ errors.device_type_id }}</span>
        <span v-else-if="!d && type" class="hint">Cria {{ type.interfaces }} interface(s), {{ type.front_ports }} frontal(is), {{ type.rear_ports }} traseira(s), {{ type.power_ports }} de energia, {{ type.console_ports }} de console.</span></div>
      <div class="field"><label for="dv-role">Função *<HelpTip ctx="device" k="role_id" label="Função" /></label>
        <LookupSelect v-model="f.role_id" source="roles" input-id="dv-role" required :invalid="!!errors.role_id" placeholder="Switch, Firewall, Servidor…" />
        <span v-if="errors.role_id" class="err">{{ errors.role_id }}</span></div>
      <div class="field"><label for="dv-status">Situação<HelpTip ctx="device" k="status" label="Situação" /></label>
        <select id="dv-status" v-model="f.status" class="input"><option v-for="o in choices?.device_status ?? []" :key="o.value" :value="o.value">{{ o.label }}</option></select></div>
      <div class="field"><label for="dv-branch">Filial *<HelpTip ctx="device" k="branch_id" label="Filial" /></label>
        <select id="dv-branch" v-model="f.branch_id" class="input" :aria-invalid="!!errors.branch_id"><option value="">—</option><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
        <span v-if="errors.branch_id" class="err">{{ errors.branch_id }}</span></div>
      <div class="field"><label for="dv-loc">Local<HelpTip ctx="device" k="location_id" label="Local" /></label>
        <select id="dv-loc" v-model="f.location_id" class="input" :disabled="!!f.rack_id" :aria-invalid="!!errors.location_id"><option value="">—</option>
          <option v-for="l in locations" :key="l.id" :value="l.id">{{ '  '.repeat(l.depth) }}{{ l.name }}</option></select>
        <span v-if="errors.location_id" class="err">{{ errors.location_id }}</span><span v-else-if="f.rack_id" class="hint">Vem do rack.</span></div>
      <div class="field"><label for="dv-rack">Rack<HelpTip ctx="device" k="rack_id" label="Rack" /></label>
        <select id="dv-rack" v-model="f.rack_id" class="input" :aria-invalid="!!errors.rack_id"><option value="">— fora de rack —</option><option v-for="r in racks" :key="r.id" :value="r.id">{{ r.name }}{{ r.location ? ` · ${r.location}` : '' }}</option></select>
        <span v-if="errors.rack_id" class="err">{{ errors.rack_id }}</span></div>
      <div v-if="f.rack_id" class="field"><label for="dv-pos">Posição (U) e face<HelpTip ctx="device" k="position" label="Posição (U) e face" /></label>
        <div class="row">
          <input id="dv-pos" v-model="f.position" class="input" inputmode="decimal" placeholder="U inferior" :aria-invalid="!!errors.position" />
          <select v-model="f.face" class="input" aria-label="Face"><option value="">—</option><option value="front">Frontal</option><option value="rear">Traseira</option></select>
        </div>
        <span v-if="errors.position || errors.face" class="err">{{ errors.position ?? errors.face }}</span><span v-else class="hint">Deixe em branco para guardar no rack sem posição (lateral/0U).</span></div>
      <fieldset v-if="showVirt" class="virt full">
        <legend><AppIcon name="cpu" :size="15" /> Virtualização <span class="muted">— este servidor roda VMs?</span></legend>
        <div class="field"><label for="dv-cluster">Cluster / virtualizador<HelpTip ctx="device" k="cluster_id" label="Cluster / virtualizador" /></label>
          <select id="dv-cluster" v-model="f.cluster_id" class="input" :aria-invalid="!!errors.cluster_id"><option value="">— não é host —</option><option v-for="c in clusters" :key="c.id" :value="c.id">{{ c.name }} ({{ c.type }})</option></select>
          <span v-if="errors.cluster_id" class="err">{{ errors.cluster_id }}</span></div>
        <div class="field"><label for="dv-cpu">Threads de CPU<HelpTip ctx="device" k="cpu_threads" label="Threads de CPU" /></label><input id="dv-cpu" v-model="f.cpu_threads" class="input" inputmode="numeric" placeholder="do inventário" :aria-invalid="!!errors.cpu_threads" /><span v-if="errors.cpu_threads" class="err">{{ errors.cpu_threads }}</span></div>
        <div class="field"><label for="dv-mem">Memória (GB)<HelpTip ctx="device" k="memory_mb" label="Memória (GB)" /></label><input id="dv-mem" v-model="memGb" class="input" inputmode="decimal" placeholder="do inventário" :aria-invalid="!!errors.memory_mb" /><span v-if="errors.memory_mb" class="err">{{ errors.memory_mb }}</span></div>
        <div class="field"><label for="dv-sto">Armazenamento (GB)<HelpTip ctx="device" k="storage_gb" label="Armazenamento (GB)" /></label><input id="dv-sto" v-model="f.storage_gb" class="input" inputmode="numeric" placeholder="do inventário" :aria-invalid="!!errors.storage_gb" /><span v-if="errors.storage_gb" class="err">{{ errors.storage_gb }}</span></div>
        <span class="hint full">Em branco = usa o que o GLPI Agent coletou do ativo vinculado.</span>
      </fieldset>
      <div class="field"><label for="dv-serial">Número de série<HelpTip ctx="device" k="serial" label="Número de série" /></label><input id="dv-serial" v-model="f.serial" class="input mono" /></div>
      <div class="field"><label for="dv-tag">Patrimônio<HelpTip ctx="device" k="asset_tag" label="Patrimônio" /></label><input id="dv-tag" v-model="f.asset_tag" class="input mono" /></div>
      <div class="field full">
        <label for="dv-asset">Ativo do inventário (opcional)<HelpTip ctx="device" k="asset" label="Ativo do inventário (opcional)" /></label>
        <div v-if="linked" class="linked"><AppIcon name="pc" :size="15" /> <RouterLink :to="`/assets/${linked.uuid}`" target="_blank">{{ linked.name }}</RouterLink>
          <button type="button" class="btn btn-icon" aria-label="Desvincular ativo" @click="unlink"><AppIcon name="close" :size="14" /></button></div>
        <template v-else>
          <input id="dv-asset" v-model="assetQ" class="input" type="search" placeholder="Buscar por nome, série ou patrimônio do ativo…" />
          <ul v-if="candidates.length" class="cands">
            <li v-for="a in candidates" :key="a.uuid"><button type="button" @click="pickAsset(a)"><strong>{{ a.name }}</strong> <span class="muted">{{ a.type }} · {{ a.inventory_number ?? 's/ patrimônio' }} · {{ a.serial_number ?? 's/ série' }}{{ a.branch ? ` · ${a.branch}` : '' }}</span></button></li>
          </ul>
        </template>
        <span v-if="errors.asset_uuid" class="err">{{ errors.asset_uuid }}</span>
        <span v-else class="hint">Estação, servidor, impressora: vincule ao ativo do GLPI Agent. Switch, patch panel e tomada não precisam.</span>
      </div>
      <label v-if="!d && linked" class="check full"><input v-model="f.copy_interfaces" type="checkbox" /> Copiar as placas de rede do inventário como interfaces (nome + MAC)</label>
      <div class="field full"><label for="dv-desc">Descrição<HelpTip ctx="device" k="description" label="Descrição" /></label><input id="dv-desc" v-model="f.description" class="input" /></div>
      <div class="field full"><label for="dv-com">Observações<HelpTip ctx="device" k="comments" label="Observações" /></label><textarea id="dv-com" v-model="f.comments" class="input" rows="2" /></div>
      <div class="form-actions full">
        <button class="btn" type="button" @click="emit('close')">Cancelar</button>
        <button class="btn btn-primary" type="submit" :disabled="saving">{{ saving ? 'Salvando…' : 'Salvar' }}</button>
      </div>
    </form>
  </AppModal>
</template>

<style scoped>
.row { display: flex; gap: 6px; }
.virt { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; border: 1px dashed color-mix(in srgb, #009688 55%, var(--border)); border-radius: 14px; padding: 10px 14px 14px; margin: 0;
  background: color-mix(in srgb, #009688 6%, transparent); }
.virt legend { display: inline-flex; gap: 6px; align-items: center; font-weight: 600; font-size: 13px; padding: 0 6px; }
.virt .full { grid-column: 1 / -1; }
.linked { display: flex; align-items: center; gap: 8px; padding: 6px 10px; border: 1px solid var(--border); border-radius: var(--radius-sm); }
.linked a { color: var(--accent-text); flex: 1; }
.cands { list-style: none; margin: 4px 0 0; padding: 4px; border: 1px solid var(--border); border-radius: var(--radius-sm); max-height: 220px; overflow: auto; background: var(--bg-elev); }
.cands button { all: unset; display: block; width: 100%; padding: 6px 8px; border-radius: 6px; cursor: pointer; font-size: 13px; box-sizing: border-box; }
.cands button:hover, .cands button:focus-visible { background: var(--accent-soft); }
</style>
