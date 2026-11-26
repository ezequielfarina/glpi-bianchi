<script setup lang="ts">
import { reactive } from 'vue'
import AppModal from '../AppModal.vue'
import { api } from '../../lib/api'
import type { Contact } from '../../lib/types'
import { clean, useForm } from './useForm'

const props = defineProps<{ supplierId: number; contact?: Contact | null }>()
const emit = defineEmits<{ close: []; saved: [] }>()
const { saving, errors, submit } = useForm()
const c = props.contact
const f = reactive({ name: c?.name ?? '', role: c?.role ?? '', email: c?.email ?? '', phone: c?.phone ?? '', mobile: c?.mobile ?? '', notes: c?.notes ?? '' })
async function save(): Promise<void> {
  if (await submit(() => api.saveContact(props.supplierId, c?.id ?? null, clean({ ...f })), c ? 'Contato atualizado' : 'Contato adicionado')) emit('saved')
}
</script>

<template>
  <AppModal :title="c ? `Editar ${c.name}` : 'Novo contato'" @close="emit('close')">
    <form class="form-grid" novalidate @submit.prevent="save">
      <div class="field"><label for="c-name">Nome *</label><input id="c-name" v-model="f.name" class="input" :aria-invalid="!!errors.name" /></div>
      <div class="field"><label for="c-role">Cargo / área</label><input id="c-role" v-model="f.role" class="input" placeholder="Comercial, Suporte, Financeiro…" /></div>
      <div class="field"><label for="c-email">E-mail</label><input id="c-email" v-model="f.email" class="input" type="email" :aria-invalid="!!errors.email" /><span v-if="errors.email" class="err">{{ errors.email }}</span></div>
      <div class="field"><label for="c-phone">Telefone</label><input id="c-phone" v-model="f.phone" class="input" type="tel" /></div>
      <div class="field"><label for="c-mobile">Celular / WhatsApp</label><input id="c-mobile" v-model="f.mobile" class="input" type="tel" /></div>
      <div class="field full"><label for="c-notes">Observações</label><textarea id="c-notes" v-model="f.notes" class="input" rows="2" /></div>
      <div class="form-actions full">
        <button class="btn" type="button" @click="emit('close')">Cancelar</button>
        <button class="btn btn-primary" type="submit" :disabled="saving || !f.name.trim()">{{ saving ? 'Salvando…' : 'Salvar' }}</button>
      </div>
    </form>
  </AppModal>
</template>
