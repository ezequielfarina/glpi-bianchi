<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import AppIcon from '../AppIcon.vue'
import AppModal from '../AppModal.vue'
import { api } from '../../lib/api'
import { today } from '../../lib/stock'
import type { StockItem } from '../../lib/types'
import { useForm } from '../forms/useForm'

// Entrada sem nota fiscal: saldo inicial, devolução de filial, doação.
const props = defineProps<{ items: StockItem[]; presetItem?: number }>()
const emit = defineEmits<{ close: []; saved: [] }>()
const { saving, errors, submit } = useForm()
type Line = { key: number; stock_item_id: number | ''; quantity: number; unit_cost: string; serials: string }
let seq = 0
const f = reactive({ reason: 'Saldo inicial', occurred_at: today() })
const lines = ref<Line[]>([])
const active = computed(() => props.items.filter((i) => i.active))
const byId = (id: number | '') => props.items.find((i) => i.id === id)
function addLine(itemId: number | '' = ''): void { lines.value.push({ key: ++seq, stock_item_id: itemId, quantity: 1, unit_cost: '', serials: '' }) }
const units = computed(() => lines.value.reduce((s, l) => s + (byId(l.stock_item_id)?.tracked ? l.quantity || 0 : 0), 0))
const ready = computed(() => f.reason.trim() !== '' && lines.value.length > 0 && lines.value.every((l) => l.stock_item_id !== '' && l.quantity > 0))
async function save(): Promise<void> {
  const body = { ...f, lines: lines.value.map((l) => ({ stock_item_id: l.stock_item_id, quantity: l.quantity, unit_cost: l.unit_cost || null, serials: l.serials })) }
  const r = await submit(() => api.stockIn(body), 'Entrada registrada')
  if (r) emit('saved')
}
addLine(props.presetItem ?? '')
</script>

<template>
  <AppModal title="Entrada manual (sem nota)" wide @close="emit('close')">
    <form novalidate @submit.prevent="save">
      <div class="form-grid">
        <div class="field"><label for="sin-reason">Motivo *</label><input id="sin-reason" v-model="f.reason" class="input" list="sin-reasons" :aria-invalid="!!errors.reason" />
          <datalist id="sin-reasons"><option value="Saldo inicial" /><option value="Devolução de filial" /><option value="Doação / transferência" /><option value="Contagem de inventário" /></datalist>
          <span v-if="errors.reason" class="err">{{ errors.reason }}</span></div>
        <div class="field"><label for="sin-date">Data</label><input id="sin-date" v-model="f.occurred_at" class="input" type="date" :max="today()" /></div>
      </div>
      <h3 class="sub">Itens</h3>
      <p v-if="errors.lines" class="err">{{ errors.lines }}</p>
      <div v-for="(l, i) in lines" :key="l.key" class="line">
        <div class="line-row">
          <div class="field grow">
            <label :for="`sin-item-${l.key}`">Item</label>
            <select :id="`sin-item-${l.key}`" v-model="l.stock_item_id" class="input"><option value="">—</option><option v-for="it in active" :key="it.id" :value="it.id">{{ it.name }}{{ it.tracked ? ' (patrimônio)' : '' }}</option></select>
          </div>
          <div class="field qty"><label :for="`sin-qty-${l.key}`">Quantidade</label><input :id="`sin-qty-${l.key}`" v-model.number="l.quantity" class="input num" type="number" min="1" max="500" /></div>
          <div class="field cost"><label :for="`sin-cost-${l.key}`">Valor unitário</label><input :id="`sin-cost-${l.key}`" v-model="l.unit_cost" class="input num" inputmode="decimal" placeholder="opcional" /></div>
          <button class="btn btn-icon rm" type="button" :aria-label="`Remover linha ${i + 1}`" @click="lines.splice(i, 1)"><AppIcon name="trash" :size="15" /></button>
        </div>
        <div v-if="byId(l.stock_item_id)?.tracked" class="field">
          <label :for="`sin-ser-${l.key}`">Números de série (um por linha, opcional)</label>
          <textarea :id="`sin-ser-${l.key}`" v-model="l.serials" class="input mono" rows="3" />
        </div>
        <span v-for="k in ['quantity', 'unit_cost', 'serials']" :key="k" class="err">{{ errors[`lines.${i}.${k}`] }}</span>
      </div>
      <button class="link-btn add" type="button" @click="addLine()"><AppIcon name="plus" :size="13" /> Adicionar item</button>
      <p v-if="units" class="muted note">Serão gerados {{ units }} número(s) de patrimônio automaticamente.</p>
      <div class="form-actions">
        <button class="btn" type="button" @click="emit('close')">Cancelar</button>
        <button class="btn btn-primary" type="submit" :disabled="saving || !ready">{{ saving ? 'Registrando…' : 'Registrar entrada' }}</button>
      </div>
    </form>
  </AppModal>
</template>

<style scoped>
.sub { font-size: 13px; margin: 20px 0 8px; color: var(--text-2); }
.line { display: flex; flex-direction: column; gap: 8px; padding: 10px 0; border-top: 1px solid var(--border); }
.line-row { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; }
.grow { flex: 1; min-width: 220px; }
.qty { width: 110px; }
.cost { width: 140px; }
.rm { flex: none; }
.add { margin-top: 8px; }
.note { font-size: 12.5px; margin: 10px 0 0; }
.err { font-size: 12px; color: var(--warn-text); }
.err:empty { display: none; }
</style>
