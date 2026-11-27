<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import SupplierFormModal from '../../components/forms/SupplierFormModal.vue'
import StockItemFormModal from '../../components/stock/StockItemFormModal.vue'
import { api } from '../../lib/api'
import { money } from '../../lib/format'
import { parseMoney, refreshStockLow, today } from '../../lib/stock'
import type { Lookups, StockItem } from '../../lib/types'
import { useForm } from '../../components/forms/useForm'

// Registro simplificado de nota fiscal: número, fornecedor, itens (qtd × valor unitário) e total.
const router = useRouter()
const { saving, errors, submit } = useForm()
const lookups = ref<Lookups | null>(null)
const stock = ref<StockItem[]>([])
const categories = ref<string[]>([])
type Line = { key: number; stock_item_id: number | ''; quantity: number; unit_price: string; serials: string }
let seq = 0
const f = reactive({ supplier_id: '' as number | '', number: '', series: '', issue_date: today(), received_at: today(), total: '', notes: '' })
const lines = ref<Line[]>([])
const newSupplier = ref(false)
const newItemFor = ref<Line | null>(null)

async function load(): Promise<void> {
  const [lk, st, sm] = await Promise.all([api.lookups(), api.stockItems(), api.stockSummary()])
  lookups.value = lk
  stock.value = st.items
  categories.value = sm.categories
}
function addLine(): void { lines.value.push({ key: ++seq, stock_item_id: '', quantity: 1, unit_price: '', serials: '' }) }
const byId = (id: number | '') => stock.value.find((i) => i.id === id)
const subtotal = (l: Line) => (l.quantity || 0) * (parseMoney(l.unit_price) || 0)
const itemsTotal = computed(() => Math.round(lines.value.reduce((s, l) => s + subtotal(l), 0) * 100) / 100)
const informed = computed(() => parseMoney(f.total))
const diff = computed(() => (Number.isFinite(informed.value) ? Math.round((informed.value - itemsTotal.value) * 100) / 100 : 0))
const assetsToCreate = computed(() => lines.value.reduce((s, l) => s + (byId(l.stock_item_id)?.tracked ? l.quantity || 0 : 0), 0))
const ready = computed(() => f.supplier_id !== '' && f.number.trim() !== '' && f.issue_date !== '' && Number.isFinite(informed.value)
  && lines.value.length > 0 && lines.value.every((l) => l.stock_item_id !== '' && l.quantity > 0 && Number.isFinite(parseMoney(l.unit_price))))

function onItemSelect(l: Line, ev: Event): void {
  const v = (ev.target as HTMLSelectElement).value
  if (v === 'new') { newItemFor.value = l; l.stock_item_id = ''; (ev.target as HTMLSelectElement).value = ''; return }
  l.stock_item_id = v === '' ? '' : Number(v)
  const it = byId(l.stock_item_id)
  if (it?.avg_cost && !l.unit_price) l.unit_price = Number(it.avg_cost).toFixed(2).replace('.', ',')
}
async function itemCreated(id: number): Promise<void> {
  stock.value = (await api.stockItems()).items
  if (newItemFor.value) newItemFor.value.stock_item_id = id
  newItemFor.value = null
}
async function supplierCreated(id: number): Promise<void> {
  lookups.value = await api.lookups()
  f.supplier_id = id
  newSupplier.value = false
}
function fillTotal(): void { f.total = itemsTotal.value.toFixed(2).replace('.', ',') }

async function save(): Promise<void> {
  const body = {
    ...f, series: f.series || null, notes: f.notes || null,
    items: lines.value.map((l) => ({ stock_item_id: l.stock_item_id, quantity: l.quantity, unit_price: l.unit_price, serials: byId(l.stock_item_id)?.tracked ? l.serials : undefined })),
  }
  const r = await submit(() => api.createInvoice(body), 'Nota registrada — itens lançados no estoque')
  if (r) { void refreshStockLow(); await router.push(`/invoices/${r.id}`) }
}
addLine()
void load()
</script>

<template>
  <div class="page">
    <RouterLink to="/invoices" class="back"><AppIcon name="back" :size="16" /> Notas fiscais</RouterLink>
    <h1>Registrar nota fiscal</h1>
    <form class="panel body" novalidate @submit.prevent="save">
      <div class="head-grid">
        <div class="field sup">
          <div class="label-row"><label for="nf-sup">Fornecedor *</label><button class="link-btn" type="button" @click="newSupplier = true"><AppIcon name="plus" :size="13" /> Novo fornecedor</button></div>
          <select id="nf-sup" v-model="f.supplier_id" class="input" :aria-invalid="!!errors.supplier_id"><option value="">—</option><option v-for="s in lookups?.suppliers ?? []" :key="s.id" :value="s.id">{{ s.name }}</option></select>
          <span v-if="errors.supplier_id" class="err">{{ errors.supplier_id }}</span>
        </div>
        <div class="field"><label for="nf-num">Número da nota *</label><input id="nf-num" v-model="f.number" class="input mono" inputmode="numeric" maxlength="20" :aria-invalid="!!errors.number" /><span v-if="errors.number" class="err">{{ errors.number }}</span></div>
        <div class="field small-f"><label for="nf-ser">Série</label><input id="nf-ser" v-model="f.series" class="input mono" maxlength="5" /></div>
        <div class="field"><label for="nf-date">Emissão *</label><input id="nf-date" v-model="f.issue_date" class="input" type="date" :max="today()" :aria-invalid="!!errors.issue_date" /><span v-if="errors.issue_date" class="err">{{ errors.issue_date }}</span></div>
        <div class="field"><label for="nf-rec">Recebimento</label><input id="nf-rec" v-model="f.received_at" class="input" type="date" :max="today()" /><span class="hint">data da entrada no estoque</span></div>
      </div>

      <h2 class="sub">Itens</h2>
      <p v-if="errors.items" class="err">{{ errors.items }}</p>
      <div class="table-wrap">
        <table class="table items">
          <thead><tr><th>Item</th><th class="num q">Qtd.</th><th class="num p">Valor unitário</th><th class="num s">Subtotal</th><th><span class="sr-only">Remover</span></th></tr></thead>
          <tbody>
            <template v-for="(l, i) in lines" :key="l.key">
              <tr>
                <td>
                  <select :id="`nf-item-${l.key}`" class="input" :value="l.stock_item_id" :aria-label="`Item da linha ${i + 1}`" :aria-invalid="!!errors[`items.${i}.stock_item_id`]" @change="onItemSelect(l, $event)">
                    <option value="">—</option>
                    <option v-for="it in stock" :key="it.id" :value="it.id">{{ it.name }}{{ it.tracked ? ' (patrimônio)' : '' }}</option>
                    <option value="new">+ Cadastrar novo item…</option>
                  </select>
                  <span v-if="errors[`items.${i}.stock_item_id`]" class="err">{{ errors[`items.${i}.stock_item_id`] }}</span>
                </td>
                <td><input v-model.number="l.quantity" class="input num" type="number" min="1" max="500" :aria-label="`Quantidade da linha ${i + 1}`" :aria-invalid="!!errors[`items.${i}.quantity`]" /></td>
                <td><input v-model="l.unit_price" class="input num" inputmode="decimal" placeholder="0,00" :aria-label="`Valor unitário da linha ${i + 1}`" :aria-invalid="!!errors[`items.${i}.unit_price`]" /></td>
                <td class="num">{{ money(subtotal(l)) }}</td>
                <td><button class="btn btn-icon" type="button" :disabled="lines.length === 1" :aria-label="`Remover linha ${i + 1}`" @click="lines.splice(i, 1)"><AppIcon name="trash" :size="15" /></button></td>
              </tr>
              <tr v-if="byId(l.stock_item_id)?.tracked" class="serials">
                <td colspan="5">
                  <label :for="`nf-ser-${l.key}`" class="muted small">Números de série das {{ l.quantity }} unidade(s) — um por linha, opcional (o patrimônio é gerado automaticamente)</label>
                  <textarea :id="`nf-ser-${l.key}`" v-model="l.serials" class="input mono" :rows="Math.min(Math.max(l.quantity, 2), 6)" />
                  <span v-if="errors[`items.${i}.serials`]" class="err">{{ errors[`items.${i}.serials`] }}</span>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
      <button class="link-btn add" type="button" @click="addLine"><AppIcon name="plus" :size="13" /> Adicionar item</button>

      <div class="totals">
        <div><span class="muted">Soma dos itens</span><strong class="num">{{ money(itemsTotal) }}</strong></div>
        <div class="field tot">
          <div class="label-row"><label for="nf-total">Valor total da nota *</label><button class="link-btn" type="button" @click="fillTotal">usar soma</button></div>
          <input id="nf-total" v-model="f.total" class="input num" inputmode="decimal" placeholder="0,00" :aria-invalid="!!errors.total" />
          <span v-if="errors.total" class="err">{{ errors.total }}</span>
        </div>
        <p v-if="diff !== 0" class="diff" role="status">Diferença de {{ money(diff) }} entre o total da nota e a soma dos itens (frete, desconto, impostos?). Pode salvar assim.</p>
      </div>
      <div class="field"><label for="nf-notes">Observações</label><textarea id="nf-notes" v-model="f.notes" class="input" rows="2" placeholder="ex.: pedido, centro de custo, quem solicitou" /></div>
      <p v-if="assetsToCreate" class="muted note">Ao salvar, {{ assetsToCreate }} ativo(s) serão criados com número de patrimônio e situação "Em estoque".</p>

      <div class="form-actions">
        <RouterLink class="btn" to="/invoices">Cancelar</RouterLink>
        <button class="btn btn-primary" type="submit" :disabled="saving || !ready">{{ saving ? 'Salvando…' : 'Registrar nota e dar entrada' }}</button>
      </div>
    </form>
    <SupplierFormModal v-if="newSupplier" @close="newSupplier = false" @saved="supplierCreated" />
    <StockItemFormModal v-if="newItemFor && lookups" :categories="categories" :asset-types="lookups.asset_types" @close="newItemFor = null" @saved="itemCreated" />
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 16px; max-width: 1100px; }
.back { display: inline-flex; align-items: center; gap: 4px; color: var(--text-3); font-size: 13px; width: fit-content; }
h1 { margin: 0; }
.body { padding: 20px 24px; display: flex; flex-direction: column; gap: 14px; }
.head-grid { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr) 90px minmax(0, 1fr) minmax(0, 1fr); gap: 14px 16px; }
.sub { font-size: 14px; margin: 10px 0 0; }
.items td { vertical-align: top; }
.items .q { width: 100px; }
.items .p { width: 150px; }
.items .s { width: 130px; }
.items td.num { padding-top: 18px; }
.serials td { border-top: 0; padding-top: 0; }
.serials textarea { width: 100%; margin-top: 4px; }
.add { width: fit-content; }
.totals { display: flex; align-items: flex-end; gap: 24px; flex-wrap: wrap; border-top: 1px solid var(--border); padding-top: 14px; }
.totals > div:first-child { display: flex; flex-direction: column; gap: 6px; }
.totals strong { font-size: 18px; }
.tot { width: 220px; }
.diff { flex-basis: 100%; margin: 0; color: var(--warn-text); font-size: 12.5px; }
.note { margin: 0; font-size: 12.5px; }
.small { font-size: 12px; }
.err { display: block; font-size: 12px; color: var(--warn-text); }
@media (max-width: 900px) { .head-grid { grid-template-columns: 1fr 1fr; } .sup { grid-column: 1 / -1; } }
</style>
