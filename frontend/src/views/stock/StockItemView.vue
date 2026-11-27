<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import LoadState from '../../components/LoadState.vue'
import StockAdjustModal from '../../components/stock/StockAdjustModal.vue'
import StockInModal from '../../components/stock/StockInModal.vue'
import StockItemFormModal from '../../components/stock/StockItemFormModal.vue'
import StockOutModal from '../../components/stock/StockOutModal.vue'
import ShippingLabelModal from '../../components/stock/ShippingLabelModal.vue'
import { api } from '../../lib/api'
import { dateOnly, money } from '../../lib/format'
import { MOVEMENT_KIND, refreshStockLow } from '../../lib/stock'
import { notify } from '../../lib/toast'
import type { Lookups, StockItem } from '../../lib/types'
import { useAsync } from '../../lib/useAsync'

const props = defineProps<{ id: string }>()
const router = useRouter()
const { data: it, loading, error, reload } = useAsync(() => api.stockItem(Number(props.id)))
const lookups = ref<Lookups | null>(null)
const categories = ref<string[]>([])
const modal = ref<'' | 'edit' | 'out' | 'in' | 'adjust'>('')
const label = ref<{ batch: string; fresh: boolean } | null>(null)
const departments = ref<string[]>([])
const branches = computed(() => (lookups.value?.branches ?? []).map((b) => ({ id: b.id, name: b.name })))
const asList = computed<StockItem[]>(() => (it.value ? [it.value] : []))

async function open(m: 'edit' | 'out' | 'in' | 'adjust'): Promise<void> {
  lookups.value ??= await api.lookups()
  if ((m === 'edit' && !categories.value.length) || (m === 'out' && !departments.value.length)) {
    const s = await api.stockSummary()
    categories.value = s.categories
    departments.value = s.departments
  }
  modal.value = m
}
async function done(): Promise<void> { modal.value = ''; await reload(); void refreshStockLow() }
async function remove(): Promise<void> {
  if (!it.value) return
  const has = it.value.movements > 0
  if (!window.confirm(has ? `"${it.value.name}" tem histórico: ele será desativado (o histórico é mantido). Continuar?` : `Excluir o item "${it.value.name}"?`)) return
  const r = await api.deleteStockItem(it.value.id)
  notify('ok', r.result === 'deleted' ? 'Item excluído' : 'Item desativado')
  await router.push('/stock')
}
</script>

<template>
  <div class="page">
    <RouterLink to="/stock" class="back"><AppIcon name="back" :size="16" /> Estoque</RouterLink>
    <LoadState :loading="loading" :error="error" :rows="6" @retry="reload">
      <template v-if="it">
        <header class="hero panel">
          <div class="top">
            <div>
              <div class="title-row">
                <h1>{{ it.name }}</h1>
                <span v-if="it.low" class="pill pill-warn">abaixo do mínimo — comprar {{ it.to_buy }}</span>
                <span v-if="!it.active" class="pill pill-muted">desativado</span>
              </div>
              <p class="muted">{{ it.category }}<template v-if="it.tracked"> · controle por patrimônio ({{ it.type_label }})</template><template v-else> · controle por quantidade</template></p>
            </div>
            <div class="actions edit-only">
              <button class="btn btn-primary" type="button" :disabled="!it.active || it.balance < 1" @click="open('out')"><AppIcon name="truck" :size="16" /> Saída</button>
              <button class="btn" type="button" :disabled="!it.active" @click="open('in')"><AppIcon name="download" :size="16" /> Entrada</button>
              <button class="btn" type="button" @click="open('adjust')">Ajustar</button>
              <button class="btn" type="button" @click="open('edit')"><AppIcon name="edit" :size="16" /> Editar</button>
              <button class="btn btn-danger" type="button" @click="remove"><AppIcon name="trash" :size="16" /></button>
            </div>
          </div>
          <dl class="facts">
            <div><dt>Saldo</dt><dd class="num big">{{ it.balance }}</dd></div>
            <div><dt>Estoque mínimo</dt><dd class="num">{{ it.min_quantity || '—' }}</dd></div>
            <div><dt>Custo médio</dt><dd class="num">{{ money(it.avg_cost) }}</dd></div>
            <div><dt>Valor em estoque</dt><dd class="num">{{ money(it.value) }}</dd></div>
            <div><dt>Última entrada / saída</dt><dd>{{ dateOnly(it.last_in) }} / {{ dateOnly(it.last_out) }}</dd></div>
          </dl>
          <p v-if="it.notes" class="notes">{{ it.notes }}</p>
        </header>

        <section v-if="it.tracked" class="panel">
          <div class="panel-head"><h2>Unidades em estoque <span class="muted">({{ it.units.length }})</span></h2></div>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>Patrimônio</th><th>Série</th><th>Em estoque desde</th><th class="num">Custo</th><th>Nota fiscal</th></tr></thead>
              <tbody>
                <tr v-for="u in it.units" :key="u.uuid">
                  <td class="mono"><RouterLink :to="`/assets/${u.uuid}`">{{ u.inventory_number }}</RouterLink></td>
                  <td class="mono muted">{{ u.serial_number ?? '—' }}</td>
                  <td>{{ dateOnly(u.since) }}</td>
                  <td class="num">{{ money(u.unit_cost) }}</td>
                  <td><RouterLink v-if="u.invoice_id" :to="`/invoices/${u.invoice_id}`">NF {{ u.invoice_number }}</RouterLink><span v-else class="muted">entrada manual</span></td>
                </tr>
              </tbody>
            </table>
            <p v-if="!it.units.length" class="empty">Nenhuma unidade em estoque.</p>
          </div>
        </section>

        <div class="grid2">
          <section class="panel">
            <div class="panel-head"><h2>Movimentações recentes</h2><RouterLink class="muted small" :to="{ path: '/stock', query: { tab: 'movements' } }">todas</RouterLink></div>
            <div class="table-wrap">
              <table class="table">
                <thead><tr><th>Data</th><th>Tipo</th><th class="num">Qtd.</th><th>Destino / origem</th><th>Detalhe</th><th><span class="sr-only">Etiqueta</span></th></tr></thead>
                <tbody>
                  <tr v-for="m in it.movements_recent" :key="m.id">
                    <td class="nowrap">{{ dateOnly(m.occurred_at) }}</td>
                    <td><span class="pill" :class="MOVEMENT_KIND[m.kind]?.cls">{{ m.kind_label }}</span></td>
                    <td class="num" :class="m.quantity < 0 ? 'neg' : 'pos'">{{ m.quantity > 0 ? `+${m.quantity}` : m.quantity }}</td>
                    <td><RouterLink v-if="m.invoice_id" :to="`/invoices/${m.invoice_id}`">NF {{ m.invoice_number }}</RouterLink><template v-else>{{ m.branch ?? '—' }}</template>
                      <div v-if="m.inventory_numbers.length" class="muted mono small">{{ m.inventory_numbers.join(', ') }}</div></td>
                    <td class="muted small">{{ [m.department, m.recipient, m.reason].filter(Boolean).join(' · ') || '—' }}</td>
                    <td><button v-if="m.kind === 'out' && m.batch" class="btn btn-icon btn-sm" type="button" title="Etiqueta de envio" :aria-label="`Etiqueta de envio — ${m.branch}`" @click="label = { batch: m.batch, fresh: false }"><AppIcon name="printer" :size="14" /></button></td>
                  </tr>
                </tbody>
              </table>
              <p v-if="!it.movements_recent.length" class="empty">Sem movimentações.</p>
            </div>
          </section>
          <section class="panel">
            <div class="panel-head"><h2>Saídas por filial (12 meses)</h2></div>
            <ul class="cons">
              <li v-for="c in it.consumption" :key="c.branch_id"><RouterLink :to="`/branches/${c.branch_id}`">{{ c.branch }}</RouterLink><span class="num">{{ c.quantity }}</span></li>
            </ul>
            <p v-if="!it.consumption.length" class="empty">Nenhuma saída ainda.</p>
          </section>
        </div>

        <StockItemFormModal v-if="modal === 'edit' && lookups" :item="it" :categories="categories" :asset-types="lookups.asset_types" @close="modal = ''" @saved="done" />
        <StockOutModal v-if="modal === 'out' && lookups" :items="asList" :branches="branches" :departments="departments" :preset-item="it.id" @close="modal = ''" @saved="(b) => { label = { batch: b, fresh: true }; void done() }" />
        <ShippingLabelModal v-if="label" :batch="label.batch" :just-saved="label.fresh" :departments="departments" @close="label = null" />
        <StockInModal v-if="modal === 'in'" :items="asList" :preset-item="it.id" @close="modal = ''" @saved="done" />
        <StockAdjustModal v-if="modal === 'adjust'" :item="it" @close="modal = ''" @saved="done" />
      </template>
    </LoadState>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 16px; max-width: 1480px; }
.back { display: inline-flex; align-items: center; gap: 4px; color: var(--text-3); font-size: 13px; width: fit-content; }
.hero { padding: 22px 24px; display: flex; flex-direction: column; gap: 16px; }
.top { display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.title-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.top p { margin: 6px 0 0; }
.actions { display: flex; gap: 8px; flex-wrap: wrap; }
.facts { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 14px 24px; margin: 0; }
.facts dt { font-size: 12px; color: var(--text-3); margin-bottom: 3px; }
.facts dd { margin: 0; font-weight: 500; }
.big { font-size: 22px; }
.notes { margin: 0; color: var(--text-2); font-size: 13px; white-space: pre-line; }
.grid2 { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap: 16px; align-items: start; }
.cons { list-style: none; margin: 0; padding: 0 20px 16px; display: flex; flex-direction: column; gap: 6px; }
.cons li { display: flex; justify-content: space-between; }
.neg { color: var(--warn-text); }
.pos { color: var(--ok); }
.nowrap { white-space: nowrap; }
.small { font-size: 12px; }
.btn-sm { height: 30px; width: 30px; padding: 0; }
@media (max-width: 1100px) { .facts { grid-template-columns: repeat(2, minmax(0, 1fr)); } .grid2 { grid-template-columns: 1fr; } }
</style>
