<script setup lang="ts">
import { reactive } from 'vue'
import AppModal from '../AppModal.vue'
import { api } from '../../lib/api'
import type { SupplierDetail } from '../../lib/types'
import { clean, useForm } from './useForm'

const props = defineProps<{ supplier?: SupplierDetail | null }>()
const emit = defineEmits<{ close: []; saved: [id: number] }>()
const { saving, errors, submit } = useForm()
const s = props.supplier
const f = reactive({
  name: s?.name ?? '', trade_name: s?.trade_name ?? '', tax_id: s?.tax_id ?? '', email: s?.email ?? '', phone: s?.phone ?? '',
  website: s?.website ?? '', address: s?.address ?? '', notes: s?.notes ?? '', active: s?.active ?? true,
})
async function save(): Promise<void> {
  const r = await submit(() => api.saveSupplier(s?.id ?? null, clean({ ...f })), s ? 'Fornecedor atualizado' : 'Fornecedor cadastrado')
  if (r) emit('saved', r.id)
}
</script>

<template>
  <AppModal :title="s ? `Editar ${s.name}` : 'Novo fornecedor'" wide @close="emit('close')">
    <form class="form-grid" novalidate @submit.prevent="save">
      <div class="field"><label for="s-name">Razão social *</label><input id="s-name" v-model="f.name" class="input" :aria-invalid="!!errors.name" /><span v-if="errors.name" class="err">{{ errors.name }}</span></div>
      <div class="field"><label for="s-trade">Nome fantasia</label><input id="s-trade" v-model="f.trade_name" class="input" /></div>
      <div class="field"><label for="s-tax">CNPJ / CPF</label><input id="s-tax" v-model="f.tax_id" class="input mono" inputmode="numeric" placeholder="00.000.000/0000-00" :aria-invalid="!!errors.tax_id" /><span v-if="errors.tax_id" class="err">{{ errors.tax_id }}</span></div>
      <div class="field"><label for="s-email">E-mail</label><input id="s-email" v-model="f.email" class="input" type="email" :aria-invalid="!!errors.email" /><span v-if="errors.email" class="err">{{ errors.email }}</span></div>
      <div class="field"><label for="s-phone">Telefone</label><input id="s-phone" v-model="f.phone" class="input" type="tel" /></div>
      <div class="field"><label for="s-web">Site / portal</label><input id="s-web" v-model="f.website" class="input" type="url" placeholder="https://" /></div>
      <div class="field full"><label for="s-addr">Endereço</label><textarea id="s-addr" v-model="f.address" class="input" rows="2" /></div>
      <div class="field full"><label for="s-notes">Observações</label><textarea id="s-notes" v-model="f.notes" class="input" rows="2" /></div>
      <label class="check full"><input v-model="f.active" type="checkbox" /> Fornecedor ativo</label>
      <div class="form-actions full">
        <button class="btn" type="button" @click="emit('close')">Cancelar</button>
        <button class="btn btn-primary" type="submit" :disabled="saving || !f.name.trim()">{{ saving ? 'Salvando…' : 'Salvar' }}</button>
      </div>
    </form>
  </AppModal>
</template>

<style scoped>
.check { display: inline-flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-2); }
.check input { width: 16px; height: 16px; accent-color: var(--accent); }
</style>
