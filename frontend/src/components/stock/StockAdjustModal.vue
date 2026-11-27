<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import AppModal from '../AppModal.vue'
import UnitPicker from './UnitPicker.vue'
import { api } from '../../lib/api'
import { REMOVE_STATUS, today } from '../../lib/stock'
import type { StockItem, StockUnit } from '../../lib/types'
import { useForm } from '../forms/useForm'

// Ajuste de saldo: sem patrimônio → quantidade (+/−); com patrimônio → baixa de unidades escolhidas.
const props = defineProps<{ item: StockItem }>()
const emit = defineEmits<{ close: []; saved: [] }>()
const { saving, errors, submit } = useForm()
const f = reactive({ direction: 'remove' as 'add' | 'remove', quantity: 1, asset_status: 'lost', reason: '', occurred_at: today() })
const assets = ref<string[]>([])
const units = ref<StockUnit[] | null>(null)
if (props.item.tracked) void api.stockItem(props.item.id).then((d) => (units.value = d.units))
const after = computed(() => props.item.balance + (props.item.tracked ? -assets.value.length : (f.direction === 'add' ? 1 : -1) * (f.quantity || 0)))
const ready = computed(() => f.reason.trim() !== '' && (props.item.tracked ? assets.value.length > 0 : f.quantity > 0) && after.value >= 0)
async function save(): Promise<void> {
  const body = props.item.tracked
    ? { stock_item_id: props.item.id, assets: assets.value, asset_status: f.asset_status, reason: f.reason, occurred_at: f.occurred_at }
    : { stock_item_id: props.item.id, quantity: (f.direction === 'add' ? 1 : -1) * f.quantity, reason: f.reason, occurred_at: f.occurred_at }
  const r = await submit(() => api.stockAdjust(body), 'Ajuste registrado')
  if (r) emit('saved')
}
</script>

<template>
  <AppModal :title="`Ajustar saldo — ${item.name}`" @close="emit('close')">
    <form class="form-grid" novalidate @submit.prevent="save">
      <template v-if="!item.tracked">
        <div class="field full">
          <span class="lbl">Operação</span>
          <div class="seg">
            <label class="tchip" :class="{ on: f.direction === 'remove' }"><input v-model="f.direction" type="radio" value="remove" class="sr-only" /> Retirar (perda, defeito, contagem menor)</label>
            <label class="tchip" :class="{ on: f.direction === 'add' }"><input v-model="f.direction" type="radio" value="add" class="sr-only" /> Acrescentar (contagem maior)</label>
          </div>
        </div>
        <div class="field"><label for="sa-qty">Quantidade</label><input id="sa-qty" v-model.number="f.quantity" class="input num" type="number" min="1" :aria-invalid="!!errors.quantity" /><span v-if="errors.quantity" class="err">{{ errors.quantity }}</span></div>
      </template>
      <template v-else>
        <div class="field full">
          <span class="lbl">Unidades a baixar ({{ assets.length }})</span>
          <UnitPicker v-if="units" v-model="assets" :units="units" id-prefix="sa-u" />
          <span v-if="errors.assets" class="err">{{ errors.assets }}</span>
          <span class="hint">Para acrescentar unidades com patrimônio, use "Entrada manual".</span>
        </div>
        <div class="field">
          <label for="sa-st">Nova situação do ativo</label>
          <select id="sa-st" v-model="f.asset_status" class="input"><option v-for="(l, k) in REMOVE_STATUS" :key="k" :value="k">{{ l }}</option></select>
        </div>
      </template>
      <div class="field"><label for="sa-date">Data</label><input id="sa-date" v-model="f.occurred_at" class="input" type="date" :max="today()" /></div>
      <div class="field full"><label for="sa-reason">Motivo *</label><input id="sa-reason" v-model="f.reason" class="input" maxlength="500" placeholder="ex.: contagem do inventário, item com defeito" :aria-invalid="!!errors.reason" /><span v-if="errors.reason" class="err">{{ errors.reason }}</span></div>
      <p class="full muted">Saldo: <strong class="num">{{ item.balance }}</strong> → <strong class="num" :class="{ neg: after < 0 }">{{ after }}</strong></p>
      <div class="form-actions full">
        <button class="btn" type="button" @click="emit('close')">Cancelar</button>
        <button class="btn btn-primary" type="submit" :disabled="saving || !ready">{{ saving ? 'Salvando…' : 'Registrar ajuste' }}</button>
      </div>
    </form>
  </AppModal>
</template>

<style scoped>
.lbl { font-size: 12.5px; font-weight: 500; color: var(--text-2); }
.seg { display: flex; gap: 6px; flex-wrap: wrap; }
.neg { color: var(--warn-text); }
p { margin: 0; }
</style>
