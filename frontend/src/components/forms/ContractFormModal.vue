<script setup lang="ts">
import { reactive, ref } from 'vue'
import AppIcon from '../AppIcon.vue'
import AppModal from '../AppModal.vue'
import LookupManagerModal from './LookupManagerModal.vue'
import { api } from '../../lib/api'
import { BILLING_LABEL, RENEWAL_LABEL } from '../../lib/format'
import type { ContractDetail, Lookups } from '../../lib/types'
import { clean, useForm } from './useForm'

const props = defineProps<{ lookups: Lookups; contract?: ContractDetail | null; supplierId?: number }>()
const emit = defineEmits<{ close: []; saved: [id: number]; 'lookups-changed': [lookups: Lookups] }>()
const { saving, errors, submit } = useForm()
const c = props.contract
const f = reactive({
  title: c?.title ?? '', number: c?.number ?? '', supplier_id: c?.supplier_id ?? props.supplierId ?? ('' as number | ''),
  contract_type_id: c?.contract_type_id ?? ('' as number | ''), start_date: c?.start_date ?? '', end_date: c?.end_date ?? '',
  renewal: c?.renewal ?? 'manual', notice_days: c?.notice_days ?? 30, billing_period: c?.billing_period ?? 'monthly',
  value: c?.value ?? '', description: c?.description ?? '',
})
const lk = ref<Lookups>(props.lookups)
const managing = ref(false)
function onLookups(v: Lookups): void {
  lk.value = v
  emit('lookups-changed', v)
  if (f.contract_type_id !== '' && !v.contract_types.some((t) => t.id === f.contract_type_id)) f.contract_type_id = ''
}
async function save(): Promise<void> {
  const r = await submit(() => api.saveContract(c?.id ?? null, clean({ ...f })), c ? 'Contrato atualizado' : 'Contrato cadastrado')
  if (r) emit('saved', r.id)
}
</script>

<template>
  <AppModal :title="c ? `Editar ${c.title}` : 'Novo contrato'" wide @close="emit('close')">
    <form class="form-grid" novalidate @submit.prevent="save">
      <div class="field"><label for="k-title">Objeto / título *</label><input id="k-title" v-model="f.title" class="input" placeholder="ex.: Suporte e manutenção de impressoras" :aria-invalid="!!errors.title" /></div>
      <div class="field"><label for="k-num">Número do contrato</label><input id="k-num" v-model="f.number" class="input mono" /></div>
      <div class="field">
        <label for="k-sup">Fornecedor</label>
        <select id="k-sup" v-model="f.supplier_id" class="input"><option value="">—</option><option v-for="s in lk.suppliers" :key="s.id" :value="s.id">{{ s.name }}</option></select>
        <span v-if="errors.supplier_id" class="err">{{ errors.supplier_id }}</span>
      </div>
      <div class="field">
        <div class="label-row"><label for="k-type">Tipo de contrato</label><button class="link-btn" type="button" @click="managing = true"><AppIcon name="gear" :size="13" /> Gerenciar</button></div>
        <select id="k-type" v-model="f.contract_type_id" class="input"><option value="">—</option><option v-for="t in lk.contract_types" :key="t.id" :value="t.id">{{ t.name }}</option></select>
      </div>
      <div class="field"><label for="k-start">Início da vigência</label><input id="k-start" v-model="f.start_date" class="input" type="date" /></div>
      <div class="field"><label for="k-end">Término</label><input id="k-end" v-model="f.end_date" class="input" type="date" :aria-invalid="!!errors.end_date" /><span v-if="errors.end_date" class="err">{{ errors.end_date }}</span></div>
      <div class="field">
        <label for="k-ren">Renovação</label>
        <select id="k-ren" v-model="f.renewal" class="input"><option v-for="(label, code) in RENEWAL_LABEL" :key="code" :value="code">{{ label }}</option></select>
      </div>
      <div class="field"><label for="k-notice">Aviso prévio / alerta (dias)</label><input id="k-notice" v-model.number="f.notice_days" class="input num" type="number" min="0" /><span class="hint">Aparece como "vencendo" a partir desse prazo.</span></div>
      <div class="field"><label for="k-value">Valor (R$)</label><input id="k-value" v-model="f.value" class="input num" inputmode="decimal" placeholder="0,00" :aria-invalid="!!errors.value" /><span v-if="errors.value" class="err">{{ errors.value }}</span></div>
      <div class="field">
        <label for="k-bill">Periodicidade do valor</label>
        <select id="k-bill" v-model="f.billing_period" class="input"><option v-for="(label, code) in BILLING_LABEL" :key="code" :value="code">{{ label }}</option></select>
      </div>
      <div class="field full"><label for="k-desc">Descrição / SLA / observações</label><textarea id="k-desc" v-model="f.description" class="input" rows="3" /></div>
      <div class="form-actions full">
        <button class="btn" type="button" @click="emit('close')">Cancelar</button>
        <button class="btn btn-primary" type="submit" :disabled="saving || !f.title.trim()">{{ saving ? 'Salvando…' : 'Salvar' }}</button>
      </div>
    </form>
    <LookupManagerModal v-if="managing" kind="contract_types" :lookups="lk" @close="managing = false" @changed="onLookups" @added="(id) => (f.contract_type_id = id)" />
  </AppModal>
</template>
