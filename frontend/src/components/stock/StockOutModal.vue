<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import AppIcon from '../AppIcon.vue'
import AppModal from '../AppModal.vue'
import UnitPicker from './UnitPicker.vue'
import { api } from '../../lib/api'
import { today } from '../../lib/stock'
import type { StockItem, StockUnit } from '../../lib/types'
import { useForm } from '../forms/useForm'

// Saída para filial: várias linhas; item com patrimônio → escolhe as unidades.
const props = defineProps<{ items: StockItem[]; branches: { id: number; name: string }[]; presetItem?: number }>()
const emit = defineEmits<{ close: []; saved: [] }>()
const { saving, errors, submit } = useForm()
type Line = { key: number; stock_item_id: number | ''; quantity: number; assets: string[]; units: StockUnit[] | null }
let seq = 0
const f = reactive({ branch_id: '' as number | '', recipient: '', occurred_at: today(), notes: '' })
const lines = ref<Line[]>([])
const available = computed(() => props.items.filter((i) => i.active && i.balance > 0))
const byId = (id: number | '') => props.items.find((i) => i.id === id)

async function addLine(itemId: number | '' = ''): Promise<void> {
  const l: Line = { key: ++seq, stock_item_id: itemId, quantity: 1, assets: [], units: null }
  lines.value.push(l)
  if (itemId !== '') await onItem(l)
}
async function onItem(l: Line): Promise<void> {
  l.assets = []
  l.units = null
  const it = byId(l.stock_item_id)
  if (it?.tracked) {
    const d = await api.stockItem(it.id)
    const line = lines.value.find((x) => x.key === l.key)
    if (line) line.units = d.units
  }
}
const ready = computed(() => f.branch_id !== '' && lines.value.length > 0 && lines.value.every((l) => {
  const it = byId(l.stock_item_id)
  return it && (it.tracked ? l.assets.length > 0 : l.quantity > 0)
}))
async function save(): Promise<void> {
  const body = {
    ...f, recipient: f.recipient || null, notes: f.notes || null,
    lines: lines.value.map((l) => (byId(l.stock_item_id)?.tracked ? { stock_item_id: l.stock_item_id, assets: l.assets } : { stock_item_id: l.stock_item_id, quantity: l.quantity })),
  }
  const r = await submit(() => api.stockOut(body), 'Saída registrada')
  if (r) emit('saved')
}
void addLine(props.presetItem ?? '')
</script>

<template>
  <AppModal title="Saída para filial" wide @close="emit('close')">
    <form novalidate @submit.prevent="save">
      <div class="form-grid">
        <div class="field">
          <label for="so-branch">Filial de destino *</label>
          <select id="so-branch" v-model="f.branch_id" class="input" :aria-invalid="!!errors.branch_id"><option value="">—</option><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
          <span v-if="errors.branch_id" class="err">{{ errors.branch_id }}</span>
        </div>
        <div class="field"><label for="so-date">Data</label><input id="so-date" v-model="f.occurred_at" class="input" type="date" :max="today()" /><span v-if="errors.occurred_at" class="err">{{ errors.occurred_at }}</span></div>
        <div class="field"><label for="so-rec">Solicitante / quem recebeu</label><input id="so-rec" v-model="f.recipient" class="input" maxlength="150" /></div>
        <div class="field"><label for="so-notes">Observação</label><input id="so-notes" v-model="f.notes" class="input" placeholder="ex.: chamado 1234, substituição" /></div>
      </div>

      <h3 class="sub">Itens</h3>
      <p v-if="errors.lines" class="err">{{ errors.lines }}</p>
      <div v-for="(l, i) in lines" :key="l.key" class="line">
        <div class="line-row">
          <div class="field grow">
            <label :for="`so-item-${l.key}`">Item</label>
            <select :id="`so-item-${l.key}`" v-model="l.stock_item_id" class="input" @change="onItem(l)">
              <option value="">—</option>
              <option v-for="it in available" :key="it.id" :value="it.id">{{ it.name }} — {{ it.balance }} em estoque</option>
            </select>
          </div>
          <div v-if="byId(l.stock_item_id) && !byId(l.stock_item_id)!.tracked" class="field qty">
            <label :for="`so-qty-${l.key}`">Quantidade</label>
            <input :id="`so-qty-${l.key}`" v-model.number="l.quantity" class="input num" type="number" min="1" :max="byId(l.stock_item_id)!.balance" :aria-invalid="!!errors[`lines.${i}.quantity`]" />
          </div>
          <button class="btn btn-icon rm" type="button" :aria-label="`Remover linha ${i + 1}`" @click="lines.splice(i, 1)"><AppIcon name="trash" :size="15" /></button>
        </div>
        <div v-if="byId(l.stock_item_id)?.tracked" class="field">
          <span class="muted small">Escolha os patrimônios ({{ l.assets.length }} selecionado(s))</span>
          <UnitPicker v-if="l.units" v-model="l.assets" :units="l.units" :id-prefix="`so-u-${l.key}`" />
          <p v-else class="muted small">Carregando unidades…</p>
        </div>
        <span v-if="errors[`lines.${i}.quantity`]" class="err">{{ errors[`lines.${i}.quantity`] }}</span>
        <span v-if="errors[`lines.${i}.assets`]" class="err">{{ errors[`lines.${i}.assets`] }}</span>
      </div>
      <button class="link-btn add" type="button" @click="addLine()"><AppIcon name="plus" :size="13" /> Adicionar item</button>

      <div class="form-actions">
        <button class="btn" type="button" @click="emit('close')">Cancelar</button>
        <button class="btn btn-primary" type="submit" :disabled="saving || !ready">{{ saving ? 'Registrando…' : 'Registrar saída' }}</button>
      </div>
    </form>
  </AppModal>
</template>

<style scoped>
.sub { font-size: 13px; margin: 20px 0 8px; color: var(--text-2); }
.line { display: flex; flex-direction: column; gap: 8px; padding: 10px 0; border-top: 1px solid var(--border); }
.line-row { display: flex; gap: 12px; align-items: flex-end; }
.grow { flex: 1; }
.qty { width: 130px; }
.rm { flex: none; }
.add { margin-top: 8px; }
.small { font-size: 12px; }
.err { font-size: 12px; color: var(--warn-text); }
</style>
