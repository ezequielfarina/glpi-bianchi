<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import AppModal from '../AppModal.vue'
import HelpTip from '../HelpTip.vue'
import { api } from '../../lib/api'
import { circuits as circuitApi, dcim, dcimChoices, hex, type CableRow, type DcimChoices, type DeviceRow, type PortOption } from '../../lib/dcim'
import type { BranchRow } from '../../lib/types'
import { clean, useForm } from '../forms/useForm'

// Conectar cabo a partir de uma porta (ou terminação de circuito) — ou editar os dados de um cabo existente.
// Ponta B: escolhe filial → equipamento → porta(s) livre(s) compatível(is), ou uma terminação de circuito.
const props = defineProps<{
  a?: { type: 'port' | 'circuit'; ids: number[]; kind: string; label: string; branchId: number | null } | null
  cable?: CableRow | null
}>()
const emit = defineEmits<{ close: []; saved: [id: number] }>()
const { saving, errors, submit } = useForm()
const choices = ref<DcimChoices | null>(null)
const branches = ref<BranchRow[]>([])
void dcimChoices().then((c) => { choices.value = c })
void api.branches().then((r) => { branches.value = r.items })

const c = props.cable
const f = reactive({ label: c?.label ?? '', type: c?.type ?? (props.a?.kind.startsWith('power') ? 'power' : 'cat6'), status: c?.status ?? 'connected',
  color: c?.color ?? '', length: c?.length === null || c?.length === undefined ? '' : String(c.length).replace('.', ','), length_unit: c?.length_unit ?? 'm',
  description: c?.description ?? '' })

const mode = ref<'device' | 'circuit'>('device')
const branch = ref<number | null>(props.a?.branchId ?? null)
const q = ref('')
const devices = ref<DeviceRow[]>([])
const device = ref<number | null>(null)
const ports = ref<PortOption[]>([])
const picked = ref<number[]>([])
const circuitTerms = ref<{ id: number; label: string }[]>([])
const circuitTerm = ref<number | null>(null)
const compatible = computed(() => (props.a && choices.value ? choices.value.compatible[props.a.kind] ?? [] : []))
const canCircuit = computed(() => compatible.value.includes('circuit') && props.a?.type === 'port')

let timer: number | undefined
async function loadDevices(): Promise<void> {
  devices.value = (await dcim.devices({ branch: branch.value, q: q.value.trim() || undefined })).items.slice(0, 200)
}
watch([branch, q], () => { window.clearTimeout(timer); timer = window.setTimeout(() => void loadDevices(), 250) }, { immediate: true })
watch(device, async (d) => {
  picked.value = []
  ports.value = d ? (await dcim.ports(d, undefined, true)).items.filter((p) => compatible.value.includes(p.kind) && !(props.a?.type === 'port' && props.a.ids.includes(p.id))) : []
  if (ports.value.length === 1) picked.value = [ports.value[0]!.id]
})
watch([mode, branch], async () => {
  if (mode.value !== 'circuit') return
  const list = (await circuitApi.list({ branch: branch.value })).items
  circuitTerms.value = list.flatMap((ci) => Object.entries(ci.terminations ?? {})
    .filter(([, t]) => !t.cable_id && !t.provider_network && (props.a?.type !== 'circuit' || !props.a.ids.includes(t.id)))
    .map(([side, t]) => ({ id: t.id, label: `${ci.provider} · ${ci.cid} — lado ${side}${t.branch ? ` (${t.branch}${t.location ? ' · ' + t.location : ''})` : ''}` })))
}, { immediate: true })

const kindLabel = (k: string) => choices.value?.port_kinds.find((o) => o.value === k)?.label ?? k
const portsByKind = computed(() => {
  const g: Record<string, PortOption[]> = {}
  for (const p of ports.value) (g[p.kind] ??= []).push(p)
  return g
})
function toggle(id: number, kind: string, multi: boolean): void {
  const sameKind = picked.value.filter((x) => ports.value.find((p) => p.id === x)?.kind === kind)
  picked.value = multi ? (picked.value.includes(id) ? picked.value.filter((x) => x !== id) : [...sameKind, id]) : [id]
}

async function save(): Promise<void> {
  const attrs = clean({ ...f })
  if (props.cable) {
    const r = await submit(() => dcim.saveCable(props.cable!.id, attrs), 'Cabo atualizado')
    if (r) emit('saved', r.id)
    return
  }
  if (!props.a) return
  const b = mode.value === 'circuit' ? (circuitTerm.value ? [{ type: 'circuit', id: circuitTerm.value }] : []) : picked.value.map((id) => ({ type: 'port', id }))
  const r = await submit(() => dcim.saveCable(null, { ...attrs, a_terminations: props.a!.ids.map((id) => ({ type: props.a!.type, id })), b_terminations: b }), 'Cabo conectado')
  if (r) emit('saved', r.id)
}
</script>

<template>
  <AppModal :title="cable ? `Editar cabo ${cable.label ?? '#' + cable.id}` : 'Conectar cabo'" wide @close="emit('close')">
    <form class="form-grid" novalidate @submit.prevent="save">
      <template v-if="a && !cable">
        <div class="full end"><span class="tagA">A</span> <strong>{{ a.label }}</strong> <span class="muted">({{ kindLabel(a.kind) }})</span></div>
        <div class="full end-b">
          <span class="tagA b">B</span>
          <div v-if="canCircuit" class="seg">
            <button type="button" class="tchip" :class="{ on: mode === 'device' }" @click="mode = 'device'">Equipamento</button>
            <button type="button" class="tchip" :class="{ on: mode === 'circuit' }" @click="mode = 'circuit'">Circuito de operadora</button>
          </div>
        </div>
        <div class="field"><label for="cb-branch">Filial</label>
          <select id="cb-branch" v-model="branch" class="input"><option :value="null">Todas</option><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option></select></div>
        <template v-if="mode === 'device'">
          <div class="field"><label for="cb-q">Buscar equipamento</label><input id="cb-q" v-model="q" class="input" type="search" placeholder="Nome, modelo, série…" /></div>
          <div class="field full"><label for="cb-dev">Equipamento *<HelpTip ctx="cable" k="b" label="Equipamento" /></label>
            <select id="cb-dev" v-model="device" class="input" :size="Math.min(8, Math.max(3, devices.length))">
              <option v-for="d in devices" :key="d.id" :value="d.id">{{ d.name }} — {{ d.role }} · {{ d.device_type }}{{ d.rack ? ` · ${d.rack}` : d.location ? ` · ${d.location}` : '' }}</option>
            </select></div>
          <div v-if="device" class="field full">
            <label>Porta(s) livre(s) compatível(is) *</label>
            <p v-if="!ports.length" class="muted small">Nenhuma porta livre compatível neste equipamento.</p>
            <div v-for="(list, kind) in portsByKind" :key="kind" class="pgroup">
              <div class="muted small">{{ kindLabel(String(kind)) }}</div>
              <div class="pgrid">
                <button v-for="p in list" :key="p.id" type="button" class="pbtn mono" :class="{ on: picked.includes(p.id) }" :title="p.label ?? p.type"
                  @click="toggle(p.id, p.kind, $event.ctrlKey || $event.metaKey || $event.shiftKey)">{{ p.name }}</button>
              </div>
            </div>
            <span class="hint">Clique para escolher. Ctrl+clique escolhe várias portas do mesmo tipo (cabo breakout).</span>
          </div>
        </template>
        <div v-else class="field full"><label for="cb-ct">Terminação do circuito *</label>
          <select id="cb-ct" v-model="circuitTerm" class="input"><option :value="null">—</option><option v-for="t in circuitTerms" :key="t.id" :value="t.id">{{ t.label }}</option></select>
          <span class="hint">Só aparecem lados de circuito com filial definida e sem cabo.</span></div>
        <span v-if="errors.a_terminations || errors.b_terminations" class="err full">{{ errors.a_terminations ?? errors.b_terminations }}</span>
      </template>

      <div class="field"><label for="cb-label">Etiqueta<HelpTip ctx="cable" k="label" label="Etiqueta" /></label><input id="cb-label" v-model="f.label" class="input mono" placeholder="ex.: P-0112" /></div>
      <div class="field"><label for="cb-type">Tipo<HelpTip ctx="cable" k="type" label="Tipo" /></label>
        <select id="cb-type" v-model="f.type" class="input"><option value="">—</option><option v-for="o in choices?.cable_types ?? []" :key="o.value" :value="o.value">{{ o.label }}</option></select></div>
      <div class="field"><label for="cb-status">Situação<HelpTip ctx="cable" k="status" label="Situação" /></label>
        <select id="cb-status" v-model="f.status" class="input"><option v-for="o in choices?.cable_status ?? []" :key="o.value" :value="o.value">{{ o.label }}</option></select></div>
      <div class="field"><label for="cb-len">Comprimento<HelpTip ctx="cable" k="length" label="Comprimento" /></label>
        <div class="row"><input id="cb-len" v-model="f.length" class="input" inputmode="decimal" placeholder="ex.: 2,5" :aria-invalid="!!errors.length" />
          <select v-model="f.length_unit" class="input unit" aria-label="Unidade"><option v-for="o in choices?.length_units ?? []" :key="o.value" :value="o.value">{{ o.label }}</option></select></div>
        <span v-if="errors.length" class="err">{{ errors.length }}</span></div>
      <div class="field full"><label>Cor<HelpTip ctx="cable" k="color" label="Cor" /></label>
        <div class="colors">
          <button type="button" class="sw none" :class="{ on: !f.color }" title="Sem cor" @click="f.color = ''">∅</button>
          <button v-for="o in choices?.colors ?? []" :key="o.value" type="button" class="sw" :class="{ on: f.color === o.value }" :style="{ background: hex(o.value) }" :title="o.label" :aria-label="o.label" @click="f.color = o.value" />
        </div></div>
      <div class="field full"><label for="cb-desc">Descrição<HelpTip ctx="cable" k="description" label="Descrição" /></label><input id="cb-desc" v-model="f.description" class="input" /></div>
      <div class="form-actions full">
        <button class="btn" type="button" @click="emit('close')">Cancelar</button>
        <button class="btn btn-primary" type="submit" :disabled="saving || (!cable && (mode === 'device' ? !picked.length : !circuitTerm))">{{ saving ? 'Salvando…' : cable ? 'Salvar' : 'Conectar' }}</button>
      </div>
    </form>
  </AppModal>
</template>

<style scoped>
.end, .end-b { display: flex; align-items: center; gap: 8px; font-size: 14px; }
.tagA { display: inline-grid; place-items: center; width: 22px; height: 22px; border-radius: 6px; background: var(--accent); color: var(--accent-fg); font-weight: 700; font-size: 12px; }
.tagA.b { background: var(--ok); color: #052e16; }
.seg { display: flex; gap: 6px; }
.pgroup { display: flex; flex-direction: column; gap: 4px; margin-bottom: 6px; }
.pgrid { display: flex; flex-wrap: wrap; gap: 4px; max-height: 180px; overflow: auto; }
.pbtn { min-width: 40px; padding: 4px 8px; border: 1px solid var(--border); border-radius: 6px; background: var(--bg-elev); color: var(--text); cursor: pointer; font-size: 12.5px; }
.pbtn:hover { border-color: var(--accent); }
.pbtn.on { background: var(--accent); color: var(--accent-fg); border-color: var(--accent); }
.row { display: flex; gap: 6px; }
.unit { width: 90px; flex: none; }
.colors { display: flex; flex-wrap: wrap; gap: 6px; }
.sw { width: 26px; height: 26px; border-radius: 50%; border: 2px solid var(--border); cursor: pointer; padding: 0; }
.sw.on { outline: 2px solid var(--accent); outline-offset: 2px; }
.sw.none { background: var(--bg-elev); color: var(--text-3); font-size: 13px; }
.small { font-size: 12px; }
</style>
