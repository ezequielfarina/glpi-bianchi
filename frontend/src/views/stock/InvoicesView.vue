<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import LoadState from '../../components/LoadState.vue'
import { api } from '../../lib/api'
import { canEdit } from '../../lib/session'
import { dateOnly, money } from '../../lib/format'
import { INVOICE_STATUS } from '../../lib/stock'
import { notify } from '../../lib/toast'
import type { InvoiceRow } from '../../lib/types'

const route = useRoute()
const router = useRouter()
const status = ref(typeof route.query.status === 'string' ? route.query.status : '')
const q = ref(typeof route.query.q === 'string' ? route.query.q : '')
const items = ref<InvoiceRow[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const selected = ref<number[]>([])
let timer: number | undefined

async function load(): Promise<void> {
  loading.value = true
  try { items.value = (await api.invoices({ status: status.value || undefined, q: q.value.trim() || undefined })).items; error.value = null; selected.value = [] }
  catch (e) { error.value = e instanceof Error ? e.message : String(e) }
  finally { loading.value = false }
}
watch(status, () => { syncQuery(); void load() })
watch(q, () => { window.clearTimeout(timer); timer = window.setTimeout(() => { syncQuery(); void load() }, 300) })
function syncQuery(): void { void router.replace({ query: { ...(status.value ? { status: status.value } : {}), ...(q.value.trim() ? { q: q.value.trim() } : {}) } }) }

const total = computed(() => items.value.reduce((s, i) => s + Number(i.total), 0))
const pendingIds = computed(() => items.value.filter((i) => i.status === 'registered').map((i) => i.id))
const allPending = computed(() => pendingIds.value.length > 0 && pendingIds.value.every((id) => selected.value.includes(id)))
function toggleAll(on: boolean): void { selected.value = on ? [...pendingIds.value] : [] }
async function markSent(): Promise<void> {
  const ids = [...selected.value]
  for (const id of ids) await api.setInvoiceStatus(id, 'sent')
  notify('ok', `${ids.length} nota(s) marcada(s) como enviadas para lançamento`)
  await load()
}
void load()
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div><h1>Notas fiscais</h1><p class="muted">{{ items.length }} nota(s) · {{ money(total) }}</p></div>
      <div class="actions"><RouterLink class="edit-only btn btn-primary" to="/invoices/new"><AppIcon name="plus" :size="16" /> Registrar nota</RouterLink></div>
    </header>
    <section class="panel">
      <div class="filters">
        <div class="seg" role="group" aria-label="Situação">
          <button v-for="(s, k) in { '': 'Todas', registered: 'A enviar', sent: 'Enviadas' }" :key="k" type="button" class="tchip" :class="{ on: status === k }" @click="status = k">{{ s }}</button>
        </div>
        <input v-model="q" class="input search" type="search" aria-label="Buscar nota" placeholder="Número, fornecedor ou item…" />
        <button v-if="canEdit && selected.length" class="btn btn-primary" type="button" @click="markSent"><AppIcon name="send" :size="15" /> Marcar {{ selected.length }} como enviada(s)</button>
      </div>
      <LoadState :loading="loading && !items.length" :error="error" :rows="5" @retry="load">
        <div v-if="!items.length" class="empty">Nenhuma nota{{ status || q ? ' com esses filtros' : ' registrada' }}.</div>
        <div v-else class="table-wrap">
          <table class="table">
            <thead><tr>
              <th v-if="canEdit" class="sel"><input type="checkbox" :checked="allPending" :disabled="!pendingIds.length" aria-label="Selecionar notas a enviar" @change="toggleAll(($event.target as HTMLInputElement).checked)" /></th>
              <th>Nota</th><th>Fornecedor</th><th>Emissão</th><th>Itens</th><th class="num">Total</th><th>Situação</th>
            </tr></thead>
            <tbody>
              <tr v-for="n in items" :key="n.id" class="clickable" @click="router.push(`/invoices/${n.id}`)">
                <td v-if="canEdit" class="sel" @click.stop><input v-model="selected" type="checkbox" :value="n.id" :disabled="n.status !== 'registered'" :aria-label="`Selecionar NF ${n.number}`" /></td>
                <td><RouterLink :to="`/invoices/${n.id}`" class="strong mono" @click.stop>{{ n.number }}{{ n.series ? `/${n.series}` : '' }}</RouterLink></td>
                <td>{{ n.supplier }}</td>
                <td class="nowrap">{{ dateOnly(n.issue_date) }}</td>
                <td class="muted small summary">{{ n.summary }}</td>
                <td class="num">{{ money(n.total) }}
                  <div v-if="Number(n.difference) !== 0" class="diff small" :title="`Soma dos itens: ${money(n.items_total)}`">itens {{ money(n.items_total) }}</div></td>
                <td><span class="pill" :class="INVOICE_STATUS[n.status]?.cls">{{ n.status_label }}</span>
                  <div v-if="n.sent_at" class="muted small">enviada {{ dateOnly(n.sent_at) }}</div></td>
              </tr>
            </tbody>
          </table>
        </div>
      </LoadState>
    </section>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 20px; max-width: 1480px; }
.page-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
.page-head p { margin: 4px 0 0; font-size: 13px; }
.filters { display: flex; align-items: center; gap: 10px; padding: 16px 20px; flex-wrap: wrap; }
.seg { display: flex; gap: 6px; flex-wrap: wrap; }
.search { width: min(320px, 100%); }
.strong { color: var(--text); font-weight: 500; }
.clickable { cursor: pointer; }
.sel { width: 32px; }
.summary { max-width: 380px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.diff { color: var(--warn-text); }
.nowrap { white-space: nowrap; }
.small { font-size: 12px; }
:deep(.skeletons) { padding: 8px 20px 20px; }
</style>
