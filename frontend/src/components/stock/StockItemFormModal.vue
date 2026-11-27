<script setup lang="ts">
import { reactive } from 'vue'
import AppModal from '../AppModal.vue'
import { api } from '../../lib/api'
import type { StockItem } from '../../lib/types'
import { clean, useForm } from '../forms/useForm'

// Cadastro de item do estoque. "Controlar por patrimônio" = cada unidade vira um ativo com número de patrimônio.
const props = defineProps<{ item?: StockItem | null; categories: string[]; assetTypes: { code: string; label: string }[]; presetName?: string }>()
const emit = defineEmits<{ close: []; saved: [id: number] }>()
const { saving, errors, submit } = useForm()
const it = props.item
const f = reactive({
  name: it?.name ?? props.presetName ?? '', category: it?.category ?? '', tracked: it?.tracked ?? false, type: it?.type_code ?? '',
  min_quantity: it?.min_quantity ?? 0, active: it?.active ?? true, notes: it?.notes ?? '',
})
// sugestão de tipo de ativo pela categoria (Monitor → monitor, CPU → computador…)
const TYPE_HINT: Record<string, string[]> = { monitor: ['monitor'], cpu: ['computer', 'computador', 'desktop'], notebook: ['computer', 'notebook', 'laptop'] }
function onCategory(): void {
  if (!f.tracked || f.type) return
  const hints = TYPE_HINT[f.category.trim().toLowerCase()] ?? []
  const t = props.assetTypes.find((a) => hints.includes(a.code.toLowerCase()) || hints.includes(a.label.toLowerCase()))
  if (t) f.type = t.code
}
async function save(): Promise<void> {
  const body = clean({ ...f, type: f.tracked ? f.type : '' })
  const r = await submit(() => api.saveStockItem(it?.id ?? null, body), it ? 'Item atualizado' : 'Item cadastrado')
  if (r) emit('saved', r.id)
}
</script>

<template>
  <AppModal :title="it ? `Editar ${it.name}` : 'Novo item do estoque'" @close="emit('close')">
    <form class="form-grid" novalidate @submit.prevent="save">
      <div class="field full"><label for="si-name">Nome *</label><input id="si-name" v-model="f.name" class="input" placeholder="ex.: Mouse USB óptico, Monitor 24&quot; Full HD" :aria-invalid="!!errors.name" /><span v-if="errors.name" class="err">{{ errors.name }}</span></div>
      <div class="field">
        <label for="si-cat">Categoria *</label>
        <input id="si-cat" v-model="f.category" class="input" list="si-cats" :aria-invalid="!!errors.category" @change="onCategory" />
        <datalist id="si-cats"><option v-for="c in categories" :key="c" :value="c" /></datalist>
        <span v-if="errors.category" class="err">{{ errors.category }}</span>
      </div>
      <div class="field"><label for="si-min">Estoque mínimo</label><input id="si-min" v-model.number="f.min_quantity" class="input num" type="number" min="0" :aria-invalid="!!errors.min_quantity" /><span class="hint">Abaixo disso o item entra em "a comprar".</span></div>
      <div class="field full">
        <label class="check"><input v-model="f.tracked" type="checkbox" @change="onCategory" /> Controlar por patrimônio (cada unidade vira um ativo)</label>
        <span class="hint">Para monitor, CPU, notebook… Mouse, teclado e cabos normalmente ficam só por quantidade.</span>
        <span v-if="errors.tracked" class="err">{{ errors.tracked }}</span>
      </div>
      <div v-if="f.tracked" class="field full">
        <label for="si-type">Tipo de ativo *</label>
        <select id="si-type" v-model="f.type" class="input" :aria-invalid="!!errors.type"><option value="">—</option><option v-for="t in assetTypes" :key="t.code" :value="t.code">{{ t.label }}</option></select>
        <span v-if="errors.type" class="err">{{ errors.type }}</span>
      </div>
      <div class="field full"><label for="si-notes">Observações</label><textarea id="si-notes" v-model="f.notes" class="input" rows="2" /></div>
      <div v-if="it" class="field full"><label class="check"><input v-model="f.active" type="checkbox" /> Ativo (aparece nas notas e saídas)</label></div>
      <div class="form-actions full">
        <button class="btn" type="button" @click="emit('close')">Cancelar</button>
        <button class="btn btn-primary" type="submit" :disabled="saving || !f.name.trim() || !f.category.trim()">{{ saving ? 'Salvando…' : 'Salvar' }}</button>
      </div>
    </form>
  </AppModal>
</template>
