<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import AttachmentsPanel from '../../components/AttachmentsPanel.vue'
import LoadState from '../../components/LoadState.vue'
import CableModal from '../../components/dcim/CableModal.vue'
import PhotoGallery from '../../components/dcim/PhotoGallery.vue'
import SpecForm from '../../components/dcim/SpecForm.vue'
import TraceModal from '../../components/dcim/TraceModal.vue'
import { api } from '../../lib/api'
import { CIRCUIT_STATUS, circuits, dcim, hex, speed, type CircuitTermination, type CircuitType, type LocationRow } from '../../lib/dcim'
import { dateOnly } from '../../lib/format'
import { notify } from '../../lib/toast'
import type { BranchRow, ContractRow, SupplierRow } from '../../lib/types'
import { useAsync } from '../../lib/useAsync'
import { circuitFields, termFields, toBody } from './circuitFields'

const props = defineProps<{ id: string }>()
const router = useRouter()
const { data: c, loading, error, reload } = useAsync(() => circuits.detail(Number(props.id)))
const branches = ref<BranchRow[]>([])
const locations = ref<LocationRow[]>([])
const suppliers = ref<SupplierRow[]>([])
const contracts = ref<ContractRow[]>([])
const types = ref<CircuitType[]>([])
const statusOpts = ref<{ value: string; label: string }[]>([])
void api.branches().then((r) => { branches.value = r.items })
void dcim.locations().then((r) => { locations.value = r.items })
void api.suppliers().then((r) => { suppliers.value = r.items })
void api.contracts().then((r) => { contracts.value = r.items })
void circuits.types().then((r) => { types.value = r.items; statusOpts.value = r.status })
const editing = ref(false)
const editSide = ref<'A' | 'Z' | null>(null)
const connect = ref<CircuitTermination | null>(null)
const tracing = ref<number | null>(null)
const side = (s: 'A' | 'Z') => c.value?.terminations.find((t) => t.term_side === s) ?? null
const fields = computed(() => circuitFields(suppliers.value.map((s) => ({ value: s.id, label: s.name })), types.value.map((x) => ({ value: x.id, label: x.name })),
  contracts.value.map((k) => ({ value: k.id, label: `${k.number ? k.number + ' — ' : ''}${k.title}` })), statusOpts.value))
const tFields = computed(() => termFields(branches.value.map((b) => ({ value: b.id, label: b.name })), locations.value.map((l) => ({ value: l.id, label: `${l.branch_code ?? l.branch ?? ''} · ${'— '.repeat(l.depth)}${l.name}` }))))
async function remove(): Promise<void> {
  if (!c.value || !window.confirm(`Excluir o circuito ${c.value.cid}? Cabos ligados às terminações também saem.`)) return
  await circuits.delete(c.value.id)
  notify('ok', 'Circuito excluído')
  await router.push('/circuits')
}
async function removeTerm(t: CircuitTermination): Promise<void> {
  if (!window.confirm(`Remover o lado ${t.term_side}?`)) return
  await circuits.deleteTermination(t.id)
  await reload()
}
async function unplug(t: CircuitTermination): Promise<void> {
  if (!t.cable_id || !window.confirm('Remover o cabo deste lado?')) return
  await dcim.deleteCable(t.cable_id)
  await reload()
}
</script>

<template>
  <div class="dc-page">
    <RouterLink to="/circuits" class="dc-back"><AppIcon name="back" :size="16" /> Circuitos</RouterLink>
    <LoadState :loading="loading" :error="error" :rows="6" @retry="reload">
      <template v-if="c">
        <header class="dc-head">
          <div>
            <p class="muted">{{ c.provider }} · {{ c.type }}</p>
            <h1 class="mono">{{ c.cid }} <span class="pill" :class="CIRCUIT_STATUS[c.status]?.cls">{{ CIRCUIT_STATUS[c.status]?.label }}</span></h1>
            <p class="muted">{{ [speed(c.commit_rate_kbps) !== '—' ? speed(c.commit_rate_kbps) : null, c.description].filter(Boolean).join(' · ') }}</p>
          </div>
          <div class="actions">
            <button class="edit-only btn" type="button" @click="editing = true"><AppIcon name="edit" :size="15" /> Editar</button>
            <button class="edit-only btn btn-icon" type="button" aria-label="Excluir circuito" @click="remove"><AppIcon name="trash" :size="15" /></button>
          </div>
        </header>
        <div class="dc-cols">
          <div>
            <div class="sides">
              <section v-for="s in (['A', 'Z'] as const)" :key="s" class="panel">
                <div class="panel-head"><h2>Lado {{ s }}</h2>
                  <button class="edit-only btn" type="button" @click="editSide = s"><AppIcon :name="side(s) ? 'edit' : 'plus'" :size="14" /> {{ side(s) ? 'Editar' : 'Definir' }}</button></div>
                <template v-if="side(s)">
                  <dl class="dc-kv">
                    <template v-if="side(s)!.provider_network"><dt>Rede da operadora</dt><dd><AppIcon name="globe" :size="13" /> {{ side(s)!.provider_network }}</dd></template>
                    <template v-else><dt>Chega em</dt><dd>{{ side(s)!.branch }}<template v-if="side(s)!.location"> · <RouterLink :to="`/dcim/locations/${side(s)!.location_id}`" class="dc-link">{{ side(s)!.location }}</RouterLink></template></dd></template>
                    <template v-if="side(s)!.xconnect_id"><dt>Cross-connect</dt><dd class="mono">{{ side(s)!.xconnect_id }}</dd></template>
                    <template v-if="side(s)!.pp_info"><dt>Entrega</dt><dd>{{ side(s)!.pp_info }}</dd></template>
                    <template v-if="side(s)!.port_speed_kbps"><dt>Velocidade</dt><dd>{{ speed(side(s)!.port_speed_kbps) }} ↓ {{ side(s)!.upstream_speed_kbps ? '/ ' + speed(side(s)!.upstream_speed_kbps) + ' ↑' : '' }}</dd></template>
                    <dt>Cabo</dt><dd><template v-if="side(s)!.cable_id"><span class="dc-wire" :style="{ background: hex(side(s)!.cable_color, '64748b') }" />
                      <RouterLink :to="`/dcim/cables/${side(s)!.cable_id}`" class="dc-link"> {{ side(s)!.cable_label ?? '#' + side(s)!.cable_id }}</RouterLink> → {{ side(s)!.peer }}</template>
                      <span v-else class="muted">{{ side(s)!.provider_network ? 'não se aplica' : 'sem cabo' }}</span></dd>
                  </dl>
                  <div class="acts">
                    <button v-if="!side(s)!.provider_network && !side(s)!.cable_id" class="edit-only btn btn-primary" type="button" @click="connect = side(s)"><AppIcon name="cable" :size="14" /> Conectar cabo</button>
                    <button v-if="side(s)!.cable_id || side(s)!.provider_network" class="btn" type="button" @click="tracing = side(s)!.id"><AppIcon name="route" :size="14" /> Rastrear</button>
                    <button v-if="side(s)!.cable_id" class="edit-only btn" type="button" @click="unplug(side(s)!)"><AppIcon name="unplug" :size="14" /> Remover cabo</button>
                    <button class="edit-only btn btn-icon" type="button" :aria-label="`Remover lado ${s}`" @click="removeTerm(side(s)!)"><AppIcon name="trash" :size="14" /></button>
                  </div>
                </template>
                <p v-else class="empty">{{ s === 'A' ? 'Normalmente a rede da operadora (ex.: Internet).' : 'Normalmente a filial e a sala onde o link chega (DIO, ONT).' }}</p>
              </section>
            </div>
          </div>
          <div>
            <section class="panel">
              <div class="panel-head"><h2>Dados</h2></div>
              <dl class="dc-kv">
                <dt>Operadora</dt><dd><RouterLink :to="`/suppliers/${c.supplier_id}`" class="dc-link">{{ c.provider }}</RouterLink>
                  <div v-if="c.provider_phone || c.provider_email" class="muted dc-small">{{ [c.provider_phone, c.provider_email].filter(Boolean).join(' · ') }}</div></dd>
                <dt>Contrato</dt><dd><RouterLink v-if="c.contract_id" :to="`/contracts/${c.contract_id}`" class="dc-link">{{ c.contract_number ?? c.contract_title }}</RouterLink><span v-else class="muted">—</span></dd>
                <dt>Ativação</dt><dd>{{ c.install_date ? dateOnly(c.install_date) : '—' }}</dd>
                <dt>Desativação</dt><dd>{{ c.termination_date ? dateOnly(c.termination_date) : '—' }}</dd>
                <template v-if="c.comments"><dt>Observações</dt><dd class="pre">{{ c.comments }}</dd></template>
              </dl>
            </section>
            <PhotoGallery owner-type="circuit" :owner="c.id" :items="c.attachments" @changed="reload" />
            <AttachmentsPanel owner-type="circuit" :owner="c.id" :items="c.attachments.filter((a) => a.kind === 'document')" title="Documentos (proposta, termo de aceite)" @changed="reload" />
          </div>
        </div>
        <SpecForm v-if="editing" ctx="circuit" :title="`Editar ${c.cid}`" :fields="fields"
          :initial="{ supplier_id: c.supplier_id, cid: c.cid, type_id: c.type_id, status: c.status, commit_mbps: c.commit_rate_kbps ? c.commit_rate_kbps / 1000 : '', contract_id: c.contract_id ?? '', install_date: c.install_date ?? '', termination_date: c.termination_date ?? '', description: c.description ?? '', comments: c.comments ?? '' }"
          :save="(v) => circuits.save(c!.id, toBody(v))" ok-text="Circuito atualizado" @close="editing = false" @saved="editing = false; reload()" />
        <SpecForm v-if="editSide" ctx="term" :title="`Lado ${editSide}`" :fields="tFields"
          :initial="side(editSide) ? { ...side(editSide)!, branch_id: side(editSide)!.branch_id ?? '', location_id: side(editSide)!.location_id ?? '' } : (editSide === 'A' ? { provider_network: 'Internet' } : {})"
          :save="async (v) => ({ id: (await circuits.saveTermination(c!.id, editSide!, v)).id })" ok-text="Terminação salva" @close="editSide = null" @saved="editSide = null; reload()" />
        <CableModal v-if="connect" :a="{ type: 'circuit', ids: [connect.id], kind: 'circuit', label: `${c.provider} ${c.cid} — lado ${connect.term_side}`, branchId: connect.branch_id }"
          @close="connect = null" @saved="connect = null; reload()" />
        <TraceModal v-if="tracing" :circuit-termination-id="tracing" :title="`Rastreamento — ${c.cid}`" @close="tracing = null" />
      </template>
    </LoadState>
  </div>
</template>

<style scoped>
.sides { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; }
.acts { display: flex; gap: 6px; padding: 0 20px 16px; flex-wrap: wrap; }
.empty { padding: 0 20px 16px; margin: 0; font-size: 13px; color: var(--text-3); }
.pre { white-space: pre-wrap; }
</style>
