<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import AppIcon from '../../components/AppIcon.vue'
import LoadState from '../../components/LoadState.vue'
import SpecForm, { type FieldSpec } from '../../components/dcim/SpecForm.vue'
import { api } from '../../lib/api'
import { IP_STATUS, ipam, type VlanGroup, type VlanRow, type Vrf } from '../../lib/dcim'
import { notify } from '../../lib/toast'
import type { BranchRow } from '../../lib/types'

const tab = ref<'vlans' | 'groups' | 'vrfs'>('vlans')
const branches = ref<BranchRow[]>([])
const vlans = ref<VlanRow[]>([])
const groups = ref<VlanGroup[]>([])
const vrfs = ref<Vrf[]>([])
const group = ref<number | null>(null)
const q = ref('')
const loading = ref(true)
const error = ref<string | null>(null)
const form = ref<{ kind: 'vlan' | 'group' | 'vrf'; row: Record<string, unknown> | null } | null>(null)
void api.branches().then((r) => { branches.value = r.items })
let t: number | undefined
async function load(): Promise<void> {
  try {
    ;[vlans.value, groups.value, vrfs.value] = await Promise.all([ipam.vlans({ group: group.value, q: q.value.trim() || undefined }).then((r) => r.items), ipam.vlanGroups().then((r) => r.items), ipam.vrfs().then((r) => r.items)])
    error.value = null
  } catch (e) { error.value = e instanceof Error ? e.message : String(e) } finally { loading.value = false }
}
watch(group, load, { immediate: true })
watch(q, () => { window.clearTimeout(t); t = window.setTimeout(load, 250) })
const bOpts = computed(() => branches.value.map((b) => ({ value: b.id, label: b.name })))
const fields = computed<FieldSpec[]>(() => {
  if (form.value?.kind === 'vlan') return [
    { key: 'vid', label: 'VID', type: 'number', required: true }, { key: 'name', label: 'Nome', required: true, placeholder: 'ex.: DADOS, VOZ, CFTV' },
    { key: 'group_id', label: 'Grupo', type: 'select', options: groups.value.map((g) => ({ value: g.id, label: `${g.name} (${g.min_vid}–${g.max_vid})` })) },
    { key: 'branch_id', label: 'Filial', type: 'select', options: bOpts.value, hint: 'Sem grupo, o VID é único por filial.' },
    { key: 'status', label: 'Situação', type: 'select', required: true, options: [{ value: 'active', label: 'Ativa' }, { value: 'reserved', label: 'Reservada' }, { value: 'deprecated', label: 'Obsoleta' }] },
    { key: 'role', label: 'Papel', placeholder: 'ex.: Usuários, Gerência' }, { key: 'description', label: 'Descrição', full: true },
  ]
  if (form.value?.kind === 'group') return [
    { key: 'name', label: 'Nome', required: true, placeholder: 'ex.: VLANs Matriz' }, { key: 'branch_id', label: 'Filial', type: 'select', options: bOpts.value },
    { key: 'min_vid', label: 'VID inicial', type: 'number' }, { key: 'max_vid', label: 'VID final', type: 'number' }, { key: 'description', label: 'Descrição', full: true },
  ]
  return [
    { key: 'name', label: 'Nome', required: true, placeholder: 'ex.: CORPORATIVA, CONVIDADOS' }, { key: 'rd', label: 'Route distinguisher', mono: true, placeholder: '65000:10' },
    { key: 'enforce_unique', label: 'IPs e prefixos únicos nesta VRF', type: 'check', full: true }, { key: 'description', label: 'Descrição', full: true },
  ]
})
function save(v: Record<string, unknown>): Promise<{ id: number }> {
  const id = (form.value?.row?.id as number | undefined) ?? null
  return form.value?.kind === 'vlan' ? ipam.saveVlan(id, v) : form.value?.kind === 'group' ? ipam.saveVlanGroup(id, v) : ipam.saveVrf(id, v)
}
async function remove(kind: 'vlan' | 'group' | 'vrf', id: number, label: string): Promise<void> {
  if (!window.confirm(`Excluir ${label}?`)) return
  await (kind === 'vlan' ? ipam.deleteVlan(id) : kind === 'group' ? ipam.deleteVlanGroup(id) : ipam.deleteVrf(id))
  notify('ok', 'Excluído')
  await load()
}
const initialFor = (kind: 'vlan' | 'group' | 'vrf') => (kind === 'vlan' ? { status: 'active', group_id: group.value ?? '' } : kind === 'group' ? { min_vid: 1, max_vid: 4094 } : { enforce_unique: true })
</script>

<template>
  <div class="dc-page">
    <header class="dc-head">
      <div><h1>VLANs e VRFs</h1><p class="muted">VLANs por grupo/filial, as portas e os prefixos de cada uma.</p></div>
      <div class="actions"><button class="edit-only btn btn-primary" type="button" @click="form = { kind: tab === 'vlans' ? 'vlan' : tab === 'groups' ? 'group' : 'vrf', row: null }">
        <AppIcon name="plus" :size="16" /> {{ tab === 'vlans' ? 'Nova VLAN' : tab === 'groups' ? 'Novo grupo' : 'Nova VRF' }}</button></div>
    </header>
    <section class="panel">
      <div class="tabs dc-tabs" role="tablist">
        <button type="button" role="tab" class="tab" :class="{ active: tab === 'vlans' }" @click="tab = 'vlans'">VLANs <span class="count">{{ vlans.length }}</span></button>
        <button type="button" role="tab" class="tab" :class="{ active: tab === 'groups' }" @click="tab = 'groups'">Grupos <span class="count">{{ groups.length }}</span></button>
        <button type="button" role="tab" class="tab" :class="{ active: tab === 'vrfs' }" @click="tab = 'vrfs'">VRFs <span class="count">{{ vrfs.length }}</span></button>
      </div>
      <LoadState :loading="loading" :error="error" :rows="5" @retry="load">
        <template v-if="tab === 'vlans'">
          <div class="dc-filters">
            <select v-model="group" class="input" aria-label="Grupo"><option :value="null">Todos os grupos</option><option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option></select>
            <input v-model="q" class="input search" type="search" placeholder="VID ou nome…" aria-label="Buscar VLAN" />
          </div>
          <p v-if="!vlans.length" class="empty">Nenhuma VLAN.</p>
          <div v-else class="table-wrap"><table class="table">
            <thead><tr><th class="num">VID</th><th>Nome</th><th>Grupo / filial</th><th>Prefixos</th><th class="num">Portas</th><th>Situação</th><th /></tr></thead>
            <tbody><tr v-for="v in vlans" :key="v.id">
              <td class="num mono strong">{{ v.vid }}</td><td>{{ v.name }} <span v-if="v.role" class="pill pill-muted">{{ v.role }}</span><div v-if="v.description" class="muted dc-small">{{ v.description }}</div></td>
              <td class="muted">{{ [v.group, v.branch].filter(Boolean).join(' · ') }}</td>
              <td class="mono dc-small">{{ v.prefixes }}</td><td class="num">{{ v.ports || '' }}</td>
              <td><span class="pill" :class="IP_STATUS[v.status]?.cls">{{ IP_STATUS[v.status]?.label ?? v.status }}</span></td>
              <td class="dc-actions-row"><button class="edit-only btn btn-icon" type="button" :aria-label="`Editar VLAN ${v.vid}`" @click="form = { kind: 'vlan', row: { ...v } }"><AppIcon name="edit" :size="14" /></button>
                <button class="edit-only btn btn-icon" type="button" :aria-label="`Excluir VLAN ${v.vid}`" @click="remove('vlan', v.id, `a VLAN ${v.vid} ${v.name}`)"><AppIcon name="trash" :size="14" /></button></td>
            </tr></tbody>
          </table></div>
        </template>
        <div v-else-if="tab === 'groups'" class="table-wrap"><table class="table">
          <thead><tr><th>Grupo</th><th>Filial</th><th>Faixa de VID</th><th class="num">VLANs</th><th /></tr></thead>
          <tbody><tr v-for="g in groups" :key="g.id">
            <td><button type="button" class="link-btn" @click="group = g.id; tab = 'vlans'">{{ g.name }}</button><div v-if="g.description" class="muted dc-small">{{ g.description }}</div></td>
            <td>{{ g.branch }}</td><td class="mono">{{ g.min_vid }}–{{ g.max_vid }}</td><td class="num">{{ g.vlans }}</td>
            <td class="dc-actions-row"><button class="edit-only btn btn-icon" type="button" :aria-label="`Editar ${g.name}`" @click="form = { kind: 'group', row: { ...g } }"><AppIcon name="edit" :size="14" /></button>
              <button class="edit-only btn btn-icon" type="button" :aria-label="`Excluir ${g.name}`" @click="remove('group', g.id, `o grupo ${g.name}`)"><AppIcon name="trash" :size="14" /></button></td>
          </tr></tbody>
        </table></div>
        <div v-else class="table-wrap"><table class="table">
          <thead><tr><th>VRF</th><th>RD</th><th>Únicos</th><th class="num">Prefixos</th><th class="num">IPs</th><th /></tr></thead>
          <tbody><tr v-for="v in vrfs" :key="v.id">
            <td><strong>{{ v.name }}</strong><div v-if="v.description" class="muted dc-small">{{ v.description }}</div></td>
            <td class="mono">{{ v.rd }}</td><td>{{ v.enforce_unique ? 'sim' : 'não' }}</td><td class="num">{{ v.prefixes }}</td><td class="num">{{ v.ips }}</td>
            <td class="dc-actions-row"><button class="edit-only btn btn-icon" type="button" :aria-label="`Editar ${v.name}`" @click="form = { kind: 'vrf', row: { ...v } }"><AppIcon name="edit" :size="14" /></button>
              <button class="edit-only btn btn-icon" type="button" :aria-label="`Excluir ${v.name}`" @click="remove('vrf', v.id, `a VRF ${v.name}`)"><AppIcon name="trash" :size="14" /></button></td>
          </tr></tbody>
        </table></div>
      </LoadState>
    </section>
    <SpecForm v-if="form" :ctx="form.kind === 'group' ? 'vlan_group' : form.kind" :title="form.row ? 'Editar' : form.kind === 'vlan' ? 'Nova VLAN' : form.kind === 'group' ? 'Novo grupo de VLANs' : 'Nova VRF'" :fields="fields"
      :initial="form.row ?? initialFor(form.kind)" :save="save" ok-text="Salvo" @close="form = null" @saved="form = null; load()" />
  </div>
</template>

<style scoped>
.empty { padding: 0 20px 20px; }
.strong { font-weight: 600; }
</style>
