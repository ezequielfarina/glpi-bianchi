<script setup lang="ts">
import { reactive, watch } from 'vue'
import AppModal from '../AppModal.vue'
import { api } from '../../lib/api'
import { ASSIGNMENT_LABEL } from '../../lib/format'
import type { LicenseDetail, Lookups } from '../../lib/types'
import { clean, useForm } from './useForm'

const props = defineProps<{ lookups: Lookups; license?: LicenseDetail | null }>()
const emit = defineEmits<{ close: []; saved: [id: number] }>()
const { saving, errors, submit } = useForm()
const l = props.license
const f = reactive({
  name: l?.name ?? '', license_type_id: l?.license_type_id ?? ('' as number | ''), license_category_id: l?.license_category_id ?? ('' as number | ''),
  assignment: l?.assignment ?? 'device', seats: l?.seats ?? 1, unit_price: l?.unit_price ?? '', purchase_date: l?.purchase_date ?? '',
  expires_at: l?.expires_at ?? '', supplier_id: l?.supplier_id ?? ('' as number | ''), contract_id: l?.contract_id ?? ('' as number | ''),
  software_match: l?.software_match ?? '', notes: l?.notes ?? '', license_key: '',
})
// ao trocar o tipo, sugere o modelo de atribuição dele
watch(() => f.license_type_id, (id) => {
  const t = props.lookups.license_types.find((x) => x.id === id)
  if (t && !l) f.assignment = t.assignment
})

async function save(): Promise<void> {
  const body = clean({ ...f })
  if (!f.license_key) delete body.license_key // vazio = não alterar a chave
  const r = await submit(() => api.saveLicense(l?.id ?? null, body), l ? 'Licença atualizada' : 'Licença cadastrada')
  if (r) emit('saved', r.id)
}
</script>

<template>
  <AppModal :title="l ? `Editar ${l.name}` : 'Nova licença'" wide @close="emit('close')">
    <form class="form-grid" novalidate @submit.prevent="save">
      <div class="field full">
        <label for="l-name">Produto / descrição *</label>
        <input id="l-name" v-model="f.name" class="input" required placeholder="ex.: Microsoft 365 Business Standard, Windows 11 Pro OEM" :aria-invalid="!!errors.name" />
        <span v-if="errors.name" class="err">{{ errors.name }}</span>
      </div>
      <div class="field">
        <label for="l-type">Tipo de licença *</label>
        <select id="l-type" v-model="f.license_type_id" class="input" :aria-invalid="!!errors.license_type_id">
          <option value="">Selecione…</option>
          <option v-for="t in lookups.license_types" :key="t.id" :value="t.id">{{ t.name }}</option>
        </select>
        <span v-if="errors.license_type_id" class="err">{{ errors.license_type_id }}</span>
      </div>
      <div class="field">
        <label for="l-cat">Categoria</label>
        <select id="l-cat" v-model="f.license_category_id" class="input">
          <option value="">—</option>
          <option v-for="c in lookups.license_categories" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </div>
      <div class="field">
        <label for="l-assign">Modelo de atribuição</label>
        <select id="l-assign" v-model="f.assignment" class="input">
          <option v-for="(label, code) in ASSIGNMENT_LABEL" :key="code" :value="code">{{ label }}</option>
        </select>
        <span class="hint">Por usuário = 365/assinaturas; por máquina = OEM/Retail; volume = MAK/KMS.</span>
      </div>
      <div class="field">
        <label for="l-seats">Quantidade (assentos / ativações)</label>
        <input id="l-seats" v-model.number="f.seats" class="input num" type="number" min="0" :aria-invalid="!!errors.seats" />
      </div>
      <div class="field">
        <label for="l-price">Valor unitário (R$)</label>
        <input id="l-price" v-model="f.unit_price" class="input num" inputmode="decimal" placeholder="0,00" :aria-invalid="!!errors.unit_price" />
        <span v-if="errors.unit_price" class="err">{{ errors.unit_price }}</span>
      </div>
      <div class="field">
        <label for="l-buy">Data da compra</label>
        <input id="l-buy" v-model="f.purchase_date" class="input" type="date" />
      </div>
      <div class="field">
        <label for="l-exp">Vencimento / renovação</label>
        <input id="l-exp" v-model="f.expires_at" class="input" type="date" />
      </div>
      <div class="field">
        <label for="l-sup">Fornecedor</label>
        <select id="l-sup" v-model="f.supplier_id" class="input">
          <option value="">—</option>
          <option v-for="s in lookups.suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
        </select>
      </div>
      <div class="field">
        <label for="l-ct">Contrato</label>
        <select id="l-ct" v-model="f.contract_id" class="input">
          <option value="">—</option>
          <option v-for="c in lookups.contracts" :key="c.id" :value="c.id">{{ c.title }}</option>
        </select>
      </div>
      <div class="field">
        <label for="l-match">Software correspondente (detecção)</label>
        <input id="l-match" v-model="f.software_match" class="input" placeholder="ex.: Microsoft 365 Apps, Office 2021" />
        <span class="hint">Trecho do nome do software inventariado — conta as instalações para conformidade.</span>
      </div>
      <div class="field full">
        <label for="l-key">Chave da licença {{ l?.has_key ? '(já cadastrada — preencha só para trocar)' : '' }}</label>
        <input id="l-key" v-model="f.license_key" class="input mono" type="password" autocomplete="off" :aria-invalid="!!errors.license_key" placeholder="XXXXX-XXXXX-XXXXX-XXXXX-XXXXX" />
        <span v-if="errors.license_key" class="err">{{ errors.license_key }}</span>
        <span v-else class="hint">Guardada criptografada; exibida só ao clicar em "Mostrar chave".</span>
      </div>
      <div class="field full">
        <label for="l-notes">Observações</label>
        <textarea id="l-notes" v-model="f.notes" class="input" rows="2" />
      </div>
      <div class="form-actions full">
        <button class="btn" type="button" @click="emit('close')">Cancelar</button>
        <button class="btn btn-primary" type="submit" :disabled="saving || !f.name.trim() || f.license_type_id === ''">{{ saving ? 'Salvando…' : 'Salvar' }}</button>
      </div>
    </form>
  </AppModal>
</template>
