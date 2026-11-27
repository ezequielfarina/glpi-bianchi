<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import LoadState from '../../components/LoadState.vue'
import CableEnds from '../../components/dcim/CableEnds.vue'
import TraceModal from '../../components/dcim/TraceModal.vue'
import AppIcon from '../../components/AppIcon.vue'
import { api } from '../../lib/api'
import { CABLE_STATUS, dcim, dcimChoices, hex, length, type CableRow, type DcimChoices } from '../../lib/dcim'
import type { BranchRow } from '../../lib/types'

const router = useRouter()
const branches = ref<BranchRow[]>([])
const choices = ref<DcimChoices | null>(null)
const branch = ref<number | null>(null)
const type = ref('')
const status = ref('')
const q = ref('')
const items = ref<CableRow[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const tracing = ref<number | null>(null)
void api.branches().then((r) => { branches.value = r.items })
void dcimChoices().then((c) => { choices.value = c })
let t: number | undefined
async function load(): Promise<void> {
  try { items.value = (await dcim.cables({ branch: branch.value, type: type.value || undefined, status: status.value || undefined, q: q.value.trim() || undefined })).items; error.value = null }
  catch (e) { error.value = e instanceof Error ? e.message : String(e) } finally { loading.value = false }
}
watch([branch, type, status], load, { immediate: true })
watch(q, () => { window.clearTimeout(t); t = window.setTimeout(load, 250) })
</script>

<template>
  <div class="dc-page">
    <header class="dc-head">
      <div><h1>Cabos</h1><p class="muted">{{ items.length }} cabo(s). Para conectar um cabo, abra o equipamento e use o botão da porta.</p></div>
    </header>
    <section class="panel">
      <div class="dc-filters">
        <select v-model="branch" class="input" aria-label="Filial"><option :value="null">Todas as filiais</option><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
        <select v-model="type" class="input" aria-label="Tipo"><option value="">Todos os tipos</option><option v-for="o in choices?.cable_types ?? []" :key="o.value" :value="o.value">{{ o.label }}</option></select>
        <select v-model="status" class="input" aria-label="Situação"><option value="">Qualquer situação</option><option v-for="o in choices?.cable_status ?? []" :key="o.value" :value="o.value">{{ o.label }}</option></select>
        <input v-model="q" class="input search" type="search" placeholder="Etiqueta, descrição ou equipamento…" aria-label="Buscar cabo" />
      </div>
      <LoadState :loading="loading && !items.length" :error="error" :rows="6" @retry="load">
        <p v-if="!items.length" class="empty">Nenhum cabo.</p>
        <div v-else class="table-wrap">
          <table class="table">
            <thead><tr><th>Cabo</th><th>Ponta A</th><th>Ponta B</th><th>Tipo</th><th>Comprimento</th><th>Situação</th><th /></tr></thead>
            <tbody>
              <tr v-for="c in items" :key="c.id" class="dc-clickable" @click="router.push(`/dcim/cables/${c.id}`)">
                <td class="dc-nowrap"><span class="dc-wire" :style="{ background: hex(c.color, '64748b') }" /> <RouterLink :to="`/dcim/cables/${c.id}`" class="dc-strong mono" @click.stop>{{ c.label ?? '#' + c.id }}</RouterLink></td>
                <td><CableEnds :ends="c.a" /></td><td><CableEnds :ends="c.b" /></td>
                <td class="muted">{{ c.type_label }}</td><td class="dc-nowrap">{{ length(c.length, c.length_unit) }}</td>
                <td><span class="pill" :class="CABLE_STATUS[c.status]?.cls">{{ CABLE_STATUS[c.status]?.label }}</span></td>
                <td><button v-if="c.a[0]?.port_id || c.a[0]?.circuit_termination_id" class="btn btn-icon" type="button" aria-label="Rastrear" title="Rastrear o caminho" @click.stop="tracing = c.id"><AppIcon name="route" :size="15" /></button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </LoadState>
    </section>
    <template v-for="c in items" :key="`t${c.id}`">
      <TraceModal v-if="tracing === c.id" :port-id="c.a[0]?.port_id" :circuit-termination-id="c.a[0]?.port_id ? null : c.a[0]?.circuit_termination_id" :title="`Rastreamento — ${c.label ?? 'cabo #' + c.id}`" @close="tracing = null" />
    </template>
  </div>
</template>

<style scoped>
.empty { padding: 0 20px 20px; }
</style>
