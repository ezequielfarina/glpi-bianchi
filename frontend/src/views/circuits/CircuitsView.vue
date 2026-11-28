<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import LoadState from '../../components/LoadState.vue'
import SpecForm from '../../components/dcim/SpecForm.vue'
import { api } from '../../lib/api'
import { CIRCUIT_STATUS, circuits, speed, type CircuitRow, type CircuitType } from '../../lib/dcim'
import { notify } from '../../lib/toast'
import type { BranchRow, ContractRow, SupplierRow } from '../../lib/types'
import { circuitFields, toBody } from './circuitFields'

const router = useRouter()
const branches = ref<BranchRow[]>([])
const suppliers = ref<SupplierRow[]>([])
const contracts = ref<ContractRow[]>([])
const types = ref<CircuitType[]>([])
const statusOpts = ref<{ value: string; label: string }[]>([])
const branch = ref<number | null>(null)
const status = ref('')
const q = ref('')
const items = ref<CircuitRow[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const creating = ref(false)
const typeForm = ref<CircuitType | 'new' | null>(null)
const showTypes = ref(false)
void api.branches().then((r) => { branches.value = r.items })
void api.suppliers().then((r) => { suppliers.value = r.items })
void api.contracts().then((r) => { contracts.value = r.items })
async function loadTypes(): Promise<void> { const r = await circuits.types(); types.value = r.items; statusOpts.value = r.status }
void loadTypes()
let t: number | undefined
async function load(): Promise<void> {
  try { items.value = (await circuits.list({ branch: branch.value, status: status.value || undefined, q: q.value.trim() || undefined })).items; error.value = null }
  catch (e) { error.value = e instanceof Error ? e.message : String(e) } finally { loading.value = false }
}
watch([branch, status], load, { immediate: true })
watch(q, () => { window.clearTimeout(t); t = window.setTimeout(load, 250) })
const fields = computed(() => circuitFields(suppliers.value.map((s) => ({ value: s.id, label: s.name })), types.value.map((x) => ({ value: x.id, label: x.name })),
  contracts.value.map((c) => ({ value: c.id, label: `${c.number ? c.number + ' — ' : ''}${c.title}` })), statusOpts.value))
async function removeType(x: CircuitType): Promise<void> {
  if (!window.confirm(`Excluir o tipo "${x.name}"?`)) return
  await circuits.deleteType(x.id)
  notify('ok', 'Tipo excluído')
  await loadTypes()
}
const end = (c: CircuitRow, s: 'A' | 'Z') => { const x = c.terminations?.[s]; return x ? (x.provider_network ?? [x.branch, x.location].filter(Boolean).join(' · ')) : '—' }
</script>

<template>
  <div class="dc-page">
    <header class="dc-head">
      <div><h1>Circuitos</h1><p class="muted">Links de operadora (internet, MPLS, ponto a ponto): onde chegam e o caminho até o firewall.</p></div>
      <div class="actions">
        <button class="btn" type="button" @click="showTypes = !showTypes"><AppIcon name="settings" :size="15" /> Tipos</button>
        <button class="edit-only btn btn-primary" type="button" @click="creating = true"><AppIcon name="plus" :size="16" /> Novo circuito</button>
      </div>
    </header>
    <section v-if="showTypes" class="panel">
      <div class="panel-head"><h2>Tipos de circuito</h2><button class="edit-only btn" type="button" @click="typeForm = 'new'"><AppIcon name="plus" :size="14" /> Tipo</button></div>
      <div class="chips"><span v-for="x in types" :key="x.id" class="tchip">{{ x.name }} <span class="muted">({{ x.circuits }})</span>
        <button class="edit-only link-btn" type="button" :aria-label="`Editar ${x.name}`" @click="typeForm = x"><AppIcon name="edit" :size="12" /></button>
        <button v-if="!x.circuits" class="edit-only link-btn" type="button" :aria-label="`Excluir ${x.name}`" @click="removeType(x)"><AppIcon name="trash" :size="12" /></button></span></div>
    </section>
    <section class="panel">
      <div class="dc-filters">
        <select v-model="branch" class="input" aria-label="Filial"><option :value="null">Todas as filiais</option><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
        <select v-model="status" class="input" aria-label="Situação"><option value="">Qualquer situação</option><option v-for="o in statusOpts" :key="o.value" :value="o.value">{{ o.label }}</option></select>
        <input v-model="q" class="input search" type="search" placeholder="Designação, operadora, descrição…" aria-label="Buscar circuito" />
      </div>
      <LoadState :loading="loading && !items.length" :error="error" :rows="5" @retry="load">
        <p v-if="!items.length" class="empty">Nenhum circuito. Cadastre a operadora em Gestão → Fornecedores e depois o circuito aqui.</p>
        <div v-else class="table-wrap"><table class="table">
          <thead><tr><th>Circuito</th><th>Operadora</th><th>Tipo</th><th>Banda</th><th>Lado A</th><th>Lado Z</th><th>Situação</th></tr></thead>
          <tbody><tr v-for="c in items" :key="c.id" class="dc-clickable" @click="router.push(`/circuits/${c.id}`)">
            <td><RouterLink :to="`/circuits/${c.id}`" class="dc-strong mono" @click.stop>{{ c.cid }}</RouterLink><div v-if="c.description" class="muted dc-small">{{ c.description }}</div></td>
            <td>{{ c.provider }}</td><td class="muted">{{ c.type }}</td><td class="dc-nowrap">{{ speed(c.commit_rate_kbps) }}</td>
            <td class="dc-small">{{ end(c, 'A') }}<span v-if="c.terminations?.A?.cable_id" class="pill pill-ok">cabo</span></td>
            <td class="dc-small">{{ end(c, 'Z') }}<span v-if="c.terminations?.Z?.cable_id" class="pill pill-ok">cabo</span></td>
            <td><span class="pill" :class="CIRCUIT_STATUS[c.status]?.cls">{{ CIRCUIT_STATUS[c.status]?.label }}</span></td>
          </tr></tbody>
        </table></div>
      </LoadState>
    </section>
    <SpecForm v-if="creating" ctx="circuit" title="Novo circuito" :fields="fields" :initial="{ status: 'active' }" :save="(v) => circuits.save(null, toBody(v))" ok-text="Circuito cadastrado"
      @close="creating = false" @saved="(id) => router.push(`/circuits/${id}`)" />
    <SpecForm v-if="typeForm" :title="typeForm === 'new' ? 'Novo tipo de circuito' : 'Editar tipo'" :fields="[{ key: 'name', label: 'Nome', required: true }, { key: 'description', label: 'Descrição', full: true }]"
      :initial="typeForm === 'new' ? {} : { ...typeForm }" :save="(v) => circuits.saveType(typeForm === 'new' ? null : (typeForm as CircuitType).id, v)" ok-text="Tipo salvo"
      @close="typeForm = null" @saved="typeForm = null; loadTypes()" />
  </div>
</template>

<style scoped>
.empty { padding: 0 20px 20px; }
.chips { display: flex; flex-wrap: wrap; gap: 6px; padding: 0 20px 16px; }
.chips .tchip { display: inline-flex; align-items: center; gap: 4px; }
</style>
