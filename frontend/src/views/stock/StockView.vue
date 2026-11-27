<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import KpiCard from '../../components/KpiCard.vue'
import LoadState from '../../components/LoadState.vue'
import StockAdjustModal from '../../components/stock/StockAdjustModal.vue'
import StockInModal from '../../components/stock/StockInModal.vue'
import StockItemFormModal from '../../components/stock/StockItemFormModal.vue'
import StockOutModal from '../../components/stock/StockOutModal.vue'
import { api } from '../../lib/api'
import { dateOnly, money, num } from '../../lib/format'
import { MOVEMENT_KIND, refreshStockLow, today } from '../../lib/stock'
import type { Lookups, StockConsumption, StockItem, StockMovement, StockSummary } from '../../lib/types'

const route = useRoute()
const router = useRouter()
type Tab = 'balance' | 'movements' | 'consumption'
const tab = ref<Tab>((['balance', 'movements', 'consumption'] as Tab[]).includes(route.query.tab as Tab) ? (route.query.tab as Tab) : 'balance')
watch(tab, (t) => { void router.replace({ query: t === 'balance' ? {} : { tab: t } }); void loadTab() })

const summary = ref<StockSummary | null>(null)
const items = ref<StockItem[]>([])
const lookups = ref<Lookups | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const q = ref('')
const category = ref('')
const lowOnly = ref(route.query.low === '1')

async function loadItems(): Promise<void> {
  loading.value = true
  try {
    const [s, r] = await Promise.all([api.stockSummary(), api.stockItems({ inactive: true })])
    summary.value = s
    items.value = r.items
    error.value = null
    void refreshStockLow()
  } catch (e) { error.value = e instanceof Error ? e.message : String(e) } finally { loading.value = false }
}
const shown = computed(() => {
  const t = q.value.trim().toLowerCase()
  return items.value.filter((i) => (i.active || showInactive.value)
    && (!lowOnly.value || i.low)
    && (!category.value || i.category === category.value)
    && (!t || `${i.name} ${i.category}`.toLowerCase().includes(t)))
})
const showInactive = ref(false)
const lowItems = computed(() => items.value.filter((i) => i.low))
const categories = computed(() => [...new Set(items.value.map((i) => i.category))].sort())

// ---------------------------------------------------------------- movimentações
const moves = ref<StockMovement[]>([])
const movesTotal = ref(0)
const mf = ref({ kind: '', branch: '' as number | '', from: '', to: '' })
const movesLoading = ref(false)
async function loadMoves(): Promise<void> {
  movesLoading.value = true
  try {
    const r = await api.stockMovements({ kind: mf.value.kind || undefined, branch: mf.value.branch || undefined, from: mf.value.from || undefined, to: mf.value.to || undefined, limit: 300 })
    moves.value = r.items
    movesTotal.value = r.total
  } finally { movesLoading.value = false }
}
watch(mf, () => { void loadMoves() }, { deep: true })

// ---------------------------------------------------------------- consumo
const cons = ref<StockConsumption | null>(null)
const cf = ref({ from: '', to: '' })
async function loadCons(): Promise<void> {
  cons.value = await api.stockConsumption(cf.value.from || undefined, cf.value.to || undefined)
  if (!cf.value.from) cf.value = { from: cons.value.from, to: cons.value.to }
}
watch(cf, (_v, old) => { if (old.from) void loadCons() }, { deep: true })

async function loadTab(): Promise<void> {
  lookups.value ??= await api.lookups()
  if (tab.value === 'movements') await loadMoves()
  if (tab.value === 'consumption') await loadCons()
}

// ---------------------------------------------------------------- modais
const editing = ref<StockItem | null | undefined>(undefined)
const outFor = ref<number | null | undefined>(undefined)
const inFor = ref<number | null | undefined>(undefined)
const adjusting = ref<StockItem | null>(null)
async function ensureLookups(): Promise<void> { lookups.value ??= await api.lookups() }
async function openEdit(i: StockItem | null): Promise<void> { await ensureLookups(); editing.value = i }
async function openOut(id: number | null = null): Promise<void> { await ensureLookups(); outFor.value = id }
async function openIn(id: number | null = null): Promise<void> { await ensureLookups(); inFor.value = id }
async function afterChange(): Promise<void> {
  editing.value = undefined; outFor.value = undefined; inFor.value = undefined; adjusting.value = null
  await loadItems()
  if (tab.value !== 'balance') await loadTab()
}
const branches = computed(() => (lookups.value?.branches ?? []).map((b) => ({ id: b.id, name: b.name })))
const assetTypes = computed(() => lookups.value?.asset_types ?? [])

function qtyLabel(m: StockMovement): string { return m.quantity > 0 ? `+${m.quantity}` : String(m.quantity) }
void loadItems()
void loadTab()
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div><h1>Estoque</h1><p class="muted">Estoque central de TI — entrada por nota fiscal, saída para as filiais.</p></div>
      <div class="actions edit-only">
        <RouterLink class="btn btn-primary" to="/invoices/new"><AppIcon name="file" :size="16" /> Registrar nota</RouterLink>
        <button class="btn" type="button" @click="openOut()"><AppIcon name="truck" :size="16" /> Saída para filial</button>
        <button class="btn" type="button" @click="openIn()"><AppIcon name="download" :size="16" /> Entrada manual</button>
        <button class="btn" type="button" @click="openEdit(null)"><AppIcon name="plus" :size="16" /> Novo item</button>
      </div>
    </header>

    <div v-if="summary" class="kpis">
      <KpiCard label="Itens" :value="num(summary.items)" :note="`${num(summary.units)} unidade(s) em estoque`" />
      <KpiCard label="Valor em estoque" :value="money(summary.value)" note="pelo custo médio das entradas" />
      <KpiCard label="Abaixo do mínimo" :value="num(summary.low)" :note="summary.low ? `${num(summary.to_buy)} unidade(s) a comprar` : 'tudo acima do mínimo'" :tone="summary.low ? 'warn' : 'ok'" />
      <KpiCard label="Notas a enviar" :value="num(summary.invoices_pending)" note="ainda não enviadas ao lançamento" :tone="summary.invoices_pending ? 'warn' : 'ok'" to="/invoices?status=registered" />
    </div>

    <section v-if="lowItems.length" class="panel buy">
      <div class="panel-head"><h2><AppIcon name="alert" :size="16" /> A comprar <span class="muted">({{ lowItems.length }})</span></h2></div>
      <ul>
        <li v-for="i in lowItems" :key="i.id">
          <RouterLink :to="`/stock/items/${i.id}`" class="strong">{{ i.name }}</RouterLink>
          <span class="muted">saldo {{ i.balance }} · mínimo {{ i.min_quantity }}</span>
          <span class="pill pill-warn">comprar {{ i.to_buy }}</span>
        </li>
      </ul>
    </section>

    <div class="tabs" role="tablist" aria-label="Estoque">
      <button class="tab" :class="{ active: tab === 'balance' }" role="tab" type="button" :aria-selected="tab === 'balance'" @click="tab = 'balance'"><AppIcon name="box" :size="16" /> Saldo</button>
      <button class="tab" :class="{ active: tab === 'movements' }" role="tab" type="button" :aria-selected="tab === 'movements'" @click="tab = 'movements'"><AppIcon name="history" :size="16" /> Movimentações</button>
      <button class="tab" :class="{ active: tab === 'consumption' }" role="tab" type="button" :aria-selected="tab === 'consumption'" @click="tab = 'consumption'"><AppIcon name="building" :size="16" /> Consumo por filial</button>
    </div>

    <section v-if="tab === 'balance'" class="panel">
      <div class="filters">
        <input v-model="q" class="input search" type="search" aria-label="Buscar item" placeholder="Buscar item…" />
        <select v-model="category" class="input" aria-label="Categoria"><option value="">Todas as categorias</option><option v-for="c in categories" :key="c" :value="c">{{ c }}</option></select>
        <label class="check"><input v-model="lowOnly" type="checkbox" /> Só abaixo do mínimo</label>
        <label class="check"><input v-model="showInactive" type="checkbox" /> Mostrar desativados</label>
      </div>
      <LoadState :loading="loading && !items.length" :error="error" :rows="6" @retry="loadItems">
        <div v-if="!items.length" class="empty">
          Nenhum item cadastrado. Comece em <strong>Novo item</strong> (ex.: Mouse USB, Teclado ABNT2, Monitor 24") e lance o saldo atual em <strong>Entrada manual</strong>.
        </div>
        <div v-else class="table-wrap">
          <table class="table">
            <thead><tr><th>Item</th><th>Categoria</th><th class="num">Saldo</th><th class="num">Mínimo</th><th class="num">Custo médio</th><th class="num">Valor</th><th>Última saída</th><th><span class="sr-only">Ações</span></th></tr></thead>
            <tbody>
              <tr v-for="i in shown" :key="i.id" :class="{ off: !i.active }">
                <td>
                  <RouterLink :to="`/stock/items/${i.id}`" class="strong">{{ i.name }}</RouterLink>
                  <span v-if="i.tracked" class="pill pill-muted tag" title="Cada unidade tem número de patrimônio">patrimônio</span>
                  <span v-if="!i.active" class="pill pill-muted tag">desativado</span>
                </td>
                <td class="muted">{{ i.category }}</td>
                <td class="num"><strong :class="{ warn: i.low }">{{ i.balance }}</strong><span v-if="i.low" class="pill pill-warn tag">comprar {{ i.to_buy }}</span></td>
                <td class="num muted">{{ i.min_quantity || '—' }}</td>
                <td class="num">{{ money(i.avg_cost) }}</td>
                <td class="num">{{ money(i.value) }}</td>
                <td class="muted">{{ dateOnly(i.last_out) }}</td>
                <td class="row-actions edit-only">
                  <button class="btn btn-sm" type="button" :disabled="!i.active || i.balance < 1" @click="openOut(i.id)">Saída</button>
                  <button class="btn btn-sm" type="button" :disabled="!i.active" @click="openIn(i.id)">Entrada</button>
                  <button class="btn btn-sm" type="button" @click="adjusting = i">Ajustar</button>
                  <button class="btn btn-icon btn-sm" type="button" :aria-label="`Editar ${i.name}`" @click="openEdit(i)"><AppIcon name="edit" :size="14" /></button>
                </td>
              </tr>
            </tbody>
          </table>
          <p v-if="!shown.length" class="empty">Nenhum item com esses filtros.</p>
        </div>
      </LoadState>
    </section>

    <section v-else-if="tab === 'movements'" class="panel">
      <div class="filters">
        <select v-model="mf.kind" class="input" aria-label="Tipo"><option value="">Entradas, saídas e ajustes</option><option value="in">Entradas</option><option value="out">Saídas</option><option value="adjust">Ajustes</option></select>
        <select v-model="mf.branch" class="input" aria-label="Filial"><option value="">Todas as filiais</option><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
        <label class="muted" for="mf-from">De</label><input id="mf-from" v-model="mf.from" class="input" type="date" :max="today()" />
        <label class="muted" for="mf-to">até</label><input id="mf-to" v-model="mf.to" class="input" type="date" :max="today()" />
      </div>
      <LoadState :loading="movesLoading && !moves.length" :error="null" :rows="6" :empty="!moves.length" empty-text="Nenhuma movimentação no período.">
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Data</th><th>Tipo</th><th>Item</th><th class="num">Qtd.</th><th>Destino / origem</th><th>Detalhe</th><th>Por</th></tr></thead>
            <tbody>
              <tr v-for="m in moves" :key="m.id">
                <td class="nowrap">{{ dateOnly(m.occurred_at) }}</td>
                <td><span class="pill" :class="MOVEMENT_KIND[m.kind]?.cls">{{ m.kind_label }}</span></td>
                <td><RouterLink :to="`/stock/items/${m.item_id}`">{{ m.item }}</RouterLink>
                  <div v-if="m.inventory_numbers.length" class="muted mono small">{{ m.inventory_numbers.join(', ') }}</div></td>
                <td class="num" :class="m.quantity < 0 ? 'neg' : 'pos'">{{ qtyLabel(m) }}</td>
                <td>
                  <RouterLink v-if="m.invoice_id" :to="`/invoices/${m.invoice_id}`">NF {{ m.invoice_number }}</RouterLink>
                  <template v-else-if="m.branch">{{ m.branch }}</template><template v-else>—</template>
                  <div v-if="m.supplier" class="muted small">{{ m.supplier }}</div>
                </td>
                <td class="muted small">{{ [m.recipient, m.reason].filter(Boolean).join(' · ') || '—' }}</td>
                <td class="muted small">{{ m.actor }}</td>
              </tr>
            </tbody>
          </table>
          <p v-if="movesTotal > moves.length" class="muted small more">Mostrando as {{ moves.length }} operações mais recentes — use os filtros para refinar.</p>
        </div>
      </LoadState>
    </section>

    <section v-else class="panel">
      <div class="filters">
        <label class="muted" for="cf-from">Período de</label><input id="cf-from" v-model="cf.from" class="input" type="date" :max="today()" />
        <label class="muted" for="cf-to">até</label><input id="cf-to" v-model="cf.to" class="input" type="date" :max="today()" />
      </div>
      <LoadState :loading="!cons" :error="null" :rows="4" :empty="!!cons && !cons.branches.length" empty-text="Nenhuma saída para filiais no período.">
        <div v-if="cons" class="cons">
          <article v-for="b in cons.branches" :key="b.branch_id" class="cons-card">
            <header><RouterLink :to="`/branches/${b.branch_id}`" class="strong">{{ b.branch }}</RouterLink><span class="muted">{{ b.quantity }} un. · {{ money(b.value) }}</span></header>
            <ul><li v-for="i in b.items" :key="i.item_id"><span>{{ i.item }}</span><span class="num">{{ i.quantity }}</span></li></ul>
          </article>
        </div>
      </LoadState>
    </section>

    <StockItemFormModal v-if="editing !== undefined && lookups" :item="editing" :categories="summary?.categories ?? []" :asset-types="assetTypes" @close="editing = undefined" @saved="afterChange" />
    <StockOutModal v-if="outFor !== undefined && lookups" :items="items" :branches="branches" :preset-item="outFor ?? undefined" @close="outFor = undefined" @saved="afterChange" />
    <StockInModal v-if="inFor !== undefined" :items="items" :preset-item="inFor ?? undefined" @close="inFor = undefined" @saved="afterChange" />
    <StockAdjustModal v-if="adjusting" :item="adjusting" @close="adjusting = null" @saved="afterChange" />
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 18px; max-width: 1480px; }
.page-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
.page-head p { margin: 4px 0 0; font-size: 13px; }
.actions { display: flex; gap: 8px; flex-wrap: wrap; }
.kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; }
.buy ul { list-style: none; margin: 0; padding: 0 20px 16px; display: flex; flex-wrap: wrap; gap: 8px 22px; }
.buy li { display: flex; align-items: center; gap: 10px; }
.buy h2 { display: inline-flex; align-items: center; gap: 6px; color: var(--warn-text); }
.filters { display: flex; align-items: center; gap: 10px; padding: 16px 20px; flex-wrap: wrap; }
.search { width: min(320px, 100%); }
.strong { color: var(--text); font-weight: 500; }
.tag { margin-left: 8px; font-size: 11px; }
.warn { color: var(--warn-text); }
.off td { opacity: 0.55; }
.row-actions { display: flex; gap: 6px; justify-content: flex-end; white-space: nowrap; }
.btn-sm { height: 30px; padding: 0 10px; font-size: 12.5px; }
.btn-icon.btn-sm { width: 30px; padding: 0; }
.neg { color: var(--warn-text); }
.pos { color: var(--ok); }
.nowrap { white-space: nowrap; }
.small { font-size: 12px; }
.more { padding: 0 20px 16px; }
.cons { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; padding: 0 20px 20px; }
.cons-card { border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 12px 14px; }
.cons-card header { display: flex; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
.cons-card ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; font-size: 13px; }
.cons-card li { display: flex; justify-content: space-between; gap: 8px; }
:deep(.skeletons) { padding: 8px 20px 20px; }
@media (max-width: 1100px) { .kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .kpis { grid-template-columns: 1fr; } }
.table td { vertical-align: middle; }
</style>
