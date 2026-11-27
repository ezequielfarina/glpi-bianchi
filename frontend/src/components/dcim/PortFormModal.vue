<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import AppModal from '../AppModal.vue'
import { dcimChoices, ipamChoices, ipam, type DcimChoices, type PortRow, type PortTemplate, type VlanRow } from '../../lib/dcim'
import { clean, useForm } from '../forms/useForm'

// Portas em lote (padrão "Gi1/0/[1-48]") ou edição de uma porta — do equipamento ou do modelo (tipo).
// "Par frontal/traseira" cria as duas já mapeadas (patch panel, tomada); frontal avulsa mapeia numa traseira existente.
const props = defineProps<{
  target: 'device' | 'type'
  port?: PortRow | PortTemplate | null
  rears: { id: number; name: string; positions: number }[]
  save: (body: Record<string, unknown>) => Promise<{ id?: number; ids?: number[] }>
  branchId?: number | null
}>()
const emit = defineEmits<{ close: []; saved: [] }>()
const { saving, errors, submit } = useForm()
const choices = ref<DcimChoices | null>(null)
const vlans = ref<VlanRow[]>([])
void dcimChoices().then((c) => { choices.value = c })
const p = props.port
const isRow = (x: unknown): x is PortRow => !!x && typeof x === 'object' && 'link_peers' in (x as object)
const row = isRow(p) ? p : null
const f = reactive({
  kind: p?.kind ?? 'interface', name: p?.name ?? '', label: p?.label ?? '', type: p?.type ?? '', positions: p?.positions ?? 1, mgmt_only: p?.mgmt_only ?? false,
  maximum_draw: p?.maximum_draw ?? '', allocated_draw: p?.allocated_draw ?? '', description: p?.description ?? '', rear_type: '110-punch',
  rear_port_id: (row ? row.rear_port_id : (p as PortTemplate | null)?.rear_port_template_id) ?? '' as number | '',
  rear_position: (row ? row.rear_position : (p as PortTemplate | null)?.rear_position) ?? 1,
  mac_address: row?.mac_address ?? '', enabled: row?.enabled ?? true, mark_connected: row?.mark_connected ?? false,
  mode: row?.mode ?? '', untagged_vlan_id: row?.untagged_vlan_id ?? '' as number | '', tagged_vlan_ids: [...(row?.tagged_vlan_ids ?? [])] as number[],
})
const baseKind = computed(() => (f.kind === 'pair' ? 'front' : f.kind))
const typeOptions = computed(() => choices.value?.port_types[baseKind.value] ?? [])
watch(() => f.kind, () => { if (!typeOptions.value.some((o) => o.value === f.type)) f.type = typeOptions.value.find((o) => ['1000base-t', '8p8c', 'rj-45', 'nbr-14136-10a'].includes(o.value))?.value ?? typeOptions.value[0]?.value ?? '' })
watch(typeOptions, () => { if (!f.type) f.type = typeOptions.value.find((o) => ['1000base-t', '8p8c', 'rj-45', 'nbr-14136-10a'].includes(o.value))?.value ?? typeOptions.value[0]?.value ?? '' })
if (props.target === 'device' && (row || !p)) void ipamChoices().then(async () => { vlans.value = (await ipam.vlans(props.branchId ? { branch: props.branchId } : {})).items })
const count = computed(() => {
  let n = 1
  for (const m of f.name.matchAll(/\[([^\]]+)\]/g)) {
    let k = 0
    for (const part of m[1]!.split(',')) { const r = /^(\d+)-(\d+)$/.exec(part.trim()); k += r ? Math.max(0, Number(r[2]) - Number(r[1]) + 1) : 1 }
    n *= k
  }
  return n
})
const kinds = computed(() => [...(choices.value?.port_kinds ?? []), { value: 'pair', label: 'Par frontal + traseira (patch/tomada)' }])

async function go(): Promise<void> {
  const body = clean({ ...f, rear_port_template_id: props.target === 'type' ? f.rear_port_id : undefined, tagged_vlan_ids: f.tagged_vlan_ids })
  if (props.target === 'type') delete body.rear_port_id
  if (p && f.kind === 'front' && !f.rear_port_id) body.rear_port_id = null
  const r = await submit(() => props.save(body), p ? 'Porta atualizada' : `${count.value * (f.kind === 'pair' ? 2 : 1)} porta(s) criada(s)`)
  if (r) emit('saved')
}
</script>

<template>
  <AppModal :title="p ? `Editar porta ${p.name}` : target === 'type' ? 'Adicionar portas ao modelo' : 'Adicionar portas'" wide @close="emit('close')">
    <form class="form-grid" novalidate @submit.prevent="go">
      <div v-if="!p" class="field"><label for="pf-kind">Tipo de porta *</label>
        <select id="pf-kind" v-model="f.kind" class="input"><option v-for="k in kinds" :key="k.value" :value="k.value">{{ k.label }}</option></select></div>
      <div class="field"><label for="pf-name">{{ p ? 'Nome *' : 'Nome ou padrão *' }}</label>
        <input id="pf-name" v-model="f.name" class="input mono" :placeholder="p ? '' : 'ex.: Gi1/0/[1-48], [1-24], eth0'" :aria-invalid="!!errors.name" />
        <span v-if="errors.name" class="err">{{ errors.name }}</span><span v-else-if="!p" class="hint">{{ count }} porta(s){{ f.kind === 'pair' ? ' frontais + ' + count + ' traseiras' : '' }}. Use [1-24] ou [1,3,5] para lotes.</span></div>
      <div class="field"><label for="pf-type">Tipo físico *</label>
        <select id="pf-type" v-model="f.type" class="input" :aria-invalid="!!errors.type"><option v-for="o in typeOptions" :key="o.value" :value="o.value">{{ o.label }}</option></select>
        <span v-if="errors.type" class="err">{{ errors.type }}</span></div>
      <div v-if="f.kind === 'pair'" class="field"><label for="pf-rt">Tipo físico da traseira</label>
        <select id="pf-rt" v-model="f.rear_type" class="input"><option v-for="o in choices?.port_types.rear ?? []" :key="o.value" :value="o.value">{{ o.label }}</option></select></div>
      <div class="field"><label for="pf-label">Rótulo</label><input id="pf-label" v-model="f.label" class="input" placeholder="texto impresso no equipamento" /></div>
      <div v-if="f.kind === 'rear'" class="field"><label for="pf-pos">Posições</label><input id="pf-pos" v-model.number="f.positions" class="input" type="number" min="1" max="1024" :aria-invalid="!!errors.positions" />
        <span v-if="errors.positions" class="err">{{ errors.positions }}</span><span v-else class="hint">Bandeja de DIO / tronco MPO: uma posição por fibra.</span></div>
      <template v-if="f.kind === 'front'">
        <div class="field"><label for="pf-rear">Porta traseira mapeada{{ p ? '' : ' *' }}</label>
          <select id="pf-rear" v-model="f.rear_port_id" class="input" :aria-invalid="!!errors.rear_port_id || !!errors.rear_port_template_id"><option value="">—</option>
            <option v-for="r in rears" :key="r.id" :value="r.id">{{ r.name }} ({{ r.positions }} pos.)</option></select>
          <span v-if="errors.rear_port_id || errors.rear_port_template_id" class="err">{{ errors.rear_port_id ?? errors.rear_port_template_id }}</span></div>
        <div class="field"><label for="pf-rpos">{{ p ? 'Posição na traseira' : 'Posição inicial na traseira' }}</label><input id="pf-rpos" v-model.number="f.rear_position" class="input" type="number" min="1" :aria-invalid="!!errors.rear_position" />
          <span v-if="errors.rear_position" class="err">{{ errors.rear_position }}</span></div>
      </template>
      <template v-if="f.kind === 'power_port'">
        <div class="field"><label for="pf-max">Consumo máximo (W)</label><input id="pf-max" v-model="f.maximum_draw" class="input" type="number" min="1" /></div>
        <div class="field"><label for="pf-alloc">Consumo alocado (W)</label><input id="pf-alloc" v-model="f.allocated_draw" class="input" type="number" min="1" :aria-invalid="!!errors.allocated_draw" />
          <span v-if="errors.allocated_draw" class="err">{{ errors.allocated_draw }}</span></div>
      </template>
      <template v-if="target === 'device' && f.kind === 'interface'">
        <div v-if="p || count === 1" class="field"><label for="pf-mac">MAC</label><input id="pf-mac" v-model="f.mac_address" class="input mono" placeholder="00:11:22:33:44:55" :aria-invalid="!!errors.mac_address" />
          <span v-if="errors.mac_address" class="err">{{ errors.mac_address }}</span></div>
        <template v-if="p">
          <div class="field"><label for="pf-mode">Modo 802.1Q</label>
            <select id="pf-mode" v-model="f.mode" class="input"><option value="">—</option><option value="access">Acesso</option><option value="tagged">Tronco (tagged)</option><option value="tagged-all">Tronco (todas)</option></select></div>
          <div v-if="f.mode" class="field"><label for="pf-uv">VLAN untagged (nativa)</label>
            <select id="pf-uv" v-model="f.untagged_vlan_id" class="input"><option value="">—</option><option v-for="v in vlans" :key="v.id" :value="v.id">{{ v.vid }} — {{ v.name }}{{ v.group ? ` (${v.group})` : '' }}</option></select></div>
          <div v-if="f.mode === 'tagged'" class="field full"><label>VLANs tagged</label>
            <div class="vchips"><label v-for="v in vlans" :key="v.id" class="tchip" :class="{ on: f.tagged_vlan_ids.includes(v.id) }"><input v-model="f.tagged_vlan_ids" type="checkbox" :value="v.id" class="sr-only" />{{ v.vid }} {{ v.name }}</label></div>
            <span v-if="!vlans.length" class="hint">Cadastre VLANs em IPAM → VLANs.</span></div>
        </template>
        <label class="check"><input v-model="f.mgmt_only" type="checkbox" /> Só gerência (OOB)</label>
      </template>
      <template v-if="target === 'device' && p">
        <label class="check"><input v-model="f.enabled" type="checkbox" /> Habilitada</label>
        <label class="check" title="Para portas ligadas a algo que não está documentado (ex.: cabo da operadora fora do sistema)"><input v-model="f.mark_connected" type="checkbox" /> Marcar como conectada sem cabo</label>
        <span v-if="errors.mark_connected" class="err full">{{ errors.mark_connected }}</span>
      </template>
      <div class="field full"><label for="pf-desc">Descrição</label><input id="pf-desc" v-model="f.description" class="input" /></div>
      <div class="form-actions full">
        <button class="btn" type="button" @click="emit('close')">Cancelar</button>
        <button class="btn btn-primary" type="submit" :disabled="saving || !f.name.trim()">{{ saving ? 'Salvando…' : 'Salvar' }}</button>
      </div>
    </form>
  </AppModal>
</template>

<style scoped>
.vchips { display: flex; flex-wrap: wrap; gap: 6px; }
.vchips .tchip { cursor: pointer; }
</style>
