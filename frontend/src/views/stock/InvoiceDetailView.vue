<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import AppModal from '../../components/AppModal.vue'
import AttachmentsPanel from '../../components/AttachmentsPanel.vue'
import LoadState from '../../components/LoadState.vue'
import { api } from '../../lib/api'
import { ASSET_STATUS, dateOnly, dateTime, money, taxId } from '../../lib/format'
import { INVOICE_STATUS, refreshStockLow, today } from '../../lib/stock'
import { notify } from '../../lib/toast'
import type { InvoiceStatus, Lookups } from '../../lib/types'
import { useAsync } from '../../lib/useAsync'
import { clean, useForm } from '../../components/forms/useForm'

const props = defineProps<{ id: string }>()
const router = useRouter()
const { data: n, loading, error, reload } = useAsync(() => api.invoice(Number(props.id)))
const STEPS: { k: InvoiceStatus; l: string }[] = [{ k: 'registered', l: 'Registrada' }, { k: 'sent', l: 'Enviada para lançamento' }]
const order = (s: InvoiceStatus) => STEPS.findIndex((x) => x.k === s)

async function setStatus(s: InvoiceStatus): Promise<void> {
  await api.setInvoiceStatus(Number(props.id), s)
  notify('ok', s === 'sent' ? 'Nota marcada como enviada para lançamento' : 'Envio desfeito')
  await reload()
}
async function remove(): Promise<void> {
  if (!n.value || !window.confirm(`Excluir a NF ${n.value.number}? A entrada no estoque é desfeita${n.value.assets.length ? ` e os ${n.value.assets.length} patrimônio(s) gerados são excluídos` : ''}.`)) return
  await api.deleteInvoice(n.value.id)
  void refreshStockLow()
  notify('ok', 'Nota excluída e entrada desfeita')
  await router.push('/invoices')
}

// edição do cabeçalho
const editing = ref(false)
const lookups = ref<Lookups | null>(null)
const { saving, errors, submit } = useForm()
const ef = reactive({ supplier_id: 0, number: '', series: '', issue_date: '', total: '', notes: '' })
async function edit(): Promise<void> {
  if (!n.value) return
  lookups.value ??= await api.lookups()
  Object.assign(ef, { supplier_id: n.value.supplier_id, number: n.value.number, series: n.value.series, issue_date: n.value.issue_date, total: n.value.total.replace('.', ','), notes: n.value.notes ?? '' })
  editing.value = true
}
async function saveHeader(): Promise<void> {
  const r = await submit(() => api.updateInvoice(Number(props.id), clean({ ...ef })), 'Nota atualizada')
  if (r) { editing.value = false; await reload() }
}
</script>

<template>
  <div class="page">
    <RouterLink to="/invoices" class="back"><AppIcon name="back" :size="16" /> Notas fiscais</RouterLink>
    <LoadState :loading="loading" :error="error" :rows="6" @retry="reload">
      <template v-if="n">
        <header class="hero panel">
          <div class="top">
            <div>
              <div class="title-row"><h1>NF <span class="mono">{{ n.number }}{{ n.series ? `/${n.series}` : '' }}</span></h1><span class="pill" :class="INVOICE_STATUS[n.status]?.cls">{{ n.status_label }}</span></div>
              <p class="muted"><RouterLink :to="`/suppliers/${n.supplier_id}`">{{ n.supplier }}</RouterLink><template v-if="n.supplier_tax_id"> · {{ taxId(n.supplier_tax_id) }}</template> · emitida em {{ dateOnly(n.issue_date) }}</p>
            </div>
            <div class="actions edit-only">
              <button class="btn" type="button" @click="edit"><AppIcon name="edit" :size="16" /> Editar</button>
              <button class="btn btn-danger" type="button" :disabled="!n.can_delete" :title="n.delete_blocker ?? 'Excluir a nota e desfazer a entrada'" @click="remove"><AppIcon name="trash" :size="16" /> Excluir</button>
            </div>
          </div>

          <ol class="steps" aria-label="Situação da nota">
            <li v-for="(s, i) in STEPS" :key="s.k" :class="{ done: order(n.status) >= i, current: n.status === s.k }">
              <span class="dot"><AppIcon v-if="order(n.status) >= i" name="check" :size="12" /></span>
              <span>{{ s.l }}</span>
              <span v-if="s.k === 'registered'" class="muted small">{{ dateTime(n.created_at) }} · {{ n.created_by }}</span>
              <span v-else-if="s.k === 'sent' && n.sent_at" class="muted small">{{ dateTime(n.sent_at) }} · {{ n.sent_by }}</span>
            </li>
          </ol>
          <div class="edit-only status-actions">
            <button v-if="n.status === 'registered'" class="btn btn-primary" type="button" @click="setStatus('sent')"><AppIcon name="send" :size="15" /> Marcar como enviada para lançamento</button>
            <button v-if="n.status === 'sent'" class="btn" type="button" @click="setStatus('registered')">Desfazer envio</button>
          </div>

          <dl class="facts">
            <div><dt>Total da nota</dt><dd class="num big">{{ money(n.total) }}</dd></div>
            <div><dt>Soma dos itens</dt><dd class="num">{{ money(n.items_total) }}</dd></div>
            <div><dt>Diferença</dt><dd class="num" :class="{ warn: Number(n.difference) !== 0 }">{{ Number(n.difference) === 0 ? '—' : money(n.difference) }}</dd></div>
            <div><dt>Itens</dt><dd>{{ n.items.length }} linha(s) · {{ n.items.reduce((s, i) => s + i.quantity, 0) }} unidade(s)</dd></div>
          </dl>
          <p v-if="n.notes" class="notes">{{ n.notes }}</p>
          <p v-if="!n.can_delete && n.delete_blocker" class="muted small">Não pode ser excluída: {{ n.delete_blocker }}</p>
        </header>

        <section class="panel">
          <div class="panel-head"><h2>Itens</h2></div>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>Item</th><th>Categoria</th><th class="num">Qtd.</th><th class="num">Valor unitário</th><th class="num">Subtotal</th></tr></thead>
              <tbody>
                <tr v-for="i in n.items" :key="i.id">
                  <td><RouterLink :to="`/stock/items/${i.stock_item_id}`">{{ i.item }}</RouterLink><span v-if="i.tracked" class="pill pill-muted tag">patrimônio</span></td>
                  <td class="muted">{{ i.category }}</td>
                  <td class="num">{{ i.quantity }}</td>
                  <td class="num">{{ money(i.unit_price) }}</td>
                  <td class="num">{{ money(i.subtotal) }}</td>
                </tr>
              </tbody>
              <tfoot><tr><td colspan="4" class="num muted">Soma</td><td class="num strong">{{ money(n.items_total) }}</td></tr></tfoot>
            </table>
          </div>
        </section>

        <section v-if="n.assets.length" class="panel">
          <div class="panel-head"><h2>Patrimônios gerados <span class="muted">({{ n.assets.length }})</span></h2></div>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>Patrimônio</th><th>Item</th><th>Série</th><th>Situação</th><th>Filial</th></tr></thead>
              <tbody>
                <tr v-for="a in n.assets" :key="a.uuid">
                  <td class="mono"><RouterLink :to="`/assets/${a.uuid}`">{{ a.inventory_number }}</RouterLink></td>
                  <td>{{ a.name }}</td>
                  <td class="mono muted">{{ a.serial_number ?? '—' }}</td>
                  <td>{{ ASSET_STATUS[a.status] ?? a.status }}</td>
                  <td class="muted">{{ a.branch ?? '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <AttachmentsPanel owner-type="invoice" :owner="n.id" :items="n.attachments" title="DANFE / XML / comprovantes" @changed="reload" />

        <AppModal v-if="editing" :title="`Editar NF ${n.number}`" @close="editing = false">
          <form class="form-grid" novalidate @submit.prevent="saveHeader">
            <div class="field full"><label for="e-sup">Fornecedor</label>
              <select id="e-sup" v-model="ef.supplier_id" class="input"><option v-for="s in lookups?.suppliers ?? []" :key="s.id" :value="s.id">{{ s.name }}</option></select>
              <span v-if="errors.supplier_id" class="err">{{ errors.supplier_id }}</span></div>
            <div class="field"><label for="e-num">Número</label><input id="e-num" v-model="ef.number" class="input mono" maxlength="20" /><span v-if="errors.number" class="err">{{ errors.number }}</span></div>
            <div class="field"><label for="e-ser">Série</label><input id="e-ser" v-model="ef.series" class="input mono" maxlength="5" /></div>
            <div class="field"><label for="e-date">Emissão</label><input id="e-date" v-model="ef.issue_date" class="input" type="date" :max="today()" /><span v-if="errors.issue_date" class="err">{{ errors.issue_date }}</span></div>
            <div class="field"><label for="e-total">Valor total</label><input id="e-total" v-model="ef.total" class="input num" inputmode="decimal" /><span v-if="errors.total" class="err">{{ errors.total }}</span></div>
            <div class="field full"><label for="e-notes">Observações</label><textarea id="e-notes" v-model="ef.notes" class="input" rows="2" /></div>
            <p class="full muted small">Os itens não são editáveis (já deram entrada no estoque). Para corrigir itens, exclua a nota e registre de novo.</p>
            <div class="form-actions full">
              <button class="btn" type="button" @click="editing = false">Cancelar</button>
              <button class="btn btn-primary" type="submit" :disabled="saving">{{ saving ? 'Salvando…' : 'Salvar' }}</button>
            </div>
          </form>
        </AppModal>
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
.actions { display: flex; gap: 8px; }
.steps { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.steps li { display: flex; flex-direction: column; gap: 4px; padding: 10px 12px 10px 40px; border: 1px solid var(--border); border-radius: var(--radius-sm); position: relative; color: var(--text-3); }
.steps li.done { color: var(--text); }
.steps li.current { border-color: var(--accent); }
.dot { position: absolute; left: 12px; top: 11px; width: 18px; height: 18px; border-radius: 50%; border: 1.5px solid var(--border); display: grid; place-items: center; }
.done .dot { background: var(--accent); border-color: var(--accent); color: #fff; }
.status-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.facts { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px 24px; margin: 0; }
.facts dt { font-size: 12px; color: var(--text-3); margin-bottom: 3px; }
.facts dd { margin: 0; font-weight: 500; }
.big { font-size: 22px; }
.warn { color: var(--warn-text); }
.notes { margin: 0; color: var(--text-2); font-size: 13px; white-space: pre-line; }
.tag { margin-left: 8px; font-size: 11px; }
.strong { font-weight: 600; }
.small { font-size: 12px; }
p.small { margin: 0; }
@media (max-width: 900px) { .steps, .facts { grid-template-columns: 1fr; } }
</style>
