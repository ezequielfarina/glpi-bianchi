<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import AttachmentsPanel from '../components/AttachmentsPanel.vue'
import ContactFormModal from '../components/forms/ContactFormModal.vue'
import ContractFormModal from '../components/forms/ContractFormModal.vue'
import SupplierFormModal from '../components/forms/SupplierFormModal.vue'
import LoadState from '../components/LoadState.vue'
import { api } from '../lib/api'
import { CONTRACT_STATUS, dateOnly, money, taxId } from '../lib/format'
import { notify } from '../lib/toast'
import type { Contact, Lookups } from '../lib/types'
import { useAsync } from '../lib/useAsync'

const props = defineProps<{ id: string }>()
const router = useRouter()
const { data: s, loading, error, reload } = useAsync(() => api.supplier(Number(props.id)))
const editing = ref(false)
const contactEdit = ref<Contact | null | 'new'>(null)
const lookups = ref<Lookups | null>(null)
const newContract = ref(false)

async function openContract(): Promise<void> {
  lookups.value ??= await api.lookups()
  newContract.value = true
}
async function removeContact(c: Contact): Promise<void> {
  if (!window.confirm(`Excluir o contato ${c.name}?`)) return
  await api.deleteContact(c.id)
  await reload()
}
async function remove(): Promise<void> {
  if (!s.value || !window.confirm(`Excluir o fornecedor "${s.value.name}"? Contratos e licenças continuam cadastrados, sem o vínculo.`)) return
  await api.deleteSupplier(s.value.id)
  notify('ok', 'Fornecedor excluído')
  await router.push('/suppliers')
}
</script>

<template>
  <div class="page">
    <RouterLink to="/suppliers" class="back"><AppIcon name="back" :size="16" /> Fornecedores</RouterLink>
    <LoadState :loading="loading" :error="error" :rows="6" @retry="reload">
      <template v-if="s">
        <header class="hero panel">
          <div class="top">
            <div>
              <div class="title-row"><h1>{{ s.name }}</h1><span v-if="!s.active" class="pill pill-muted">inativo</span></div>
              <p class="muted">{{ s.trade_name ?? '' }} <span class="mono">{{ taxId(s.tax_id) }}</span></p>
            </div>
            <div class="actions">
              <button class="edit-only btn" type="button" @click="editing = true"><AppIcon name="edit" :size="16" /> Editar</button>
              <button class="edit-only btn btn-danger" type="button" @click="remove"><AppIcon name="trash" :size="16" /> Excluir</button>
            </div>
          </div>
          <dl class="facts">
            <div><dt>E-mail</dt><dd><a v-if="s.email" :href="`mailto:${s.email}`">{{ s.email }}</a><template v-else>—</template></dd></div>
            <div><dt>Telefone</dt><dd>{{ s.phone ?? '—' }}</dd></div>
            <div><dt>Site / portal</dt><dd><a v-if="s.website" :href="s.website" target="_blank" rel="noopener noreferrer">{{ s.website }}</a><template v-else>—</template></dd></div>
            <div><dt>Endereço</dt><dd style="white-space: pre-line">{{ s.address ?? '—' }}</dd></div>
          </dl>
          <p v-if="s.notes" class="notes">{{ s.notes }}</p>
        </header>

        <section class="panel">
          <div class="panel-head">
            <h2>Contatos <span class="muted">({{ s.contacts_list.length }})</span></h2>
            <button class="edit-only btn" type="button" @click="contactEdit = 'new'"><AppIcon name="plus" :size="14" /> Contato</button>
          </div>
          <p v-if="!s.contacts_list.length" class="empty">Nenhum contato. Adicione comercial, suporte, financeiro…</p>
          <div v-else class="contacts">
            <div v-for="c in s.contacts_list" :key="c.id" class="contact">
              <div class="c-head"><strong>{{ c.name }}</strong><span class="muted small">{{ c.role }}</span></div>
              <div class="small"><a v-if="c.email" :href="`mailto:${c.email}`">{{ c.email }}</a></div>
              <div class="small muted">{{ [c.phone, c.mobile].filter(Boolean).join(' · ') }}</div>
              <p v-if="c.notes" class="small muted">{{ c.notes }}</p>
              <div class="c-actions">
                <button class="edit-only btn btn-icon" type="button" :aria-label="`Editar ${c.name}`" @click="contactEdit = c"><AppIcon name="edit" :size="14" /></button>
                <button class="edit-only btn btn-icon" type="button" :aria-label="`Excluir ${c.name}`" @click="removeContact(c)"><AppIcon name="trash" :size="14" /></button>
              </div>
            </div>
          </div>
        </section>

        <section class="panel">
          <div class="panel-head">
            <h2>Contratos <span class="muted">({{ s.contracts_list.length }})</span></h2>
            <button class="edit-only btn" type="button" @click="openContract"><AppIcon name="plus" :size="14" /> Contrato</button>
          </div>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>Contrato</th><th>Vigência</th><th>Valor</th><th>Situação</th></tr></thead>
              <tbody>
                <tr v-for="c in s.contracts_list" :key="c.id">
                  <td><RouterLink :to="`/contracts/${c.id}`">{{ c.title }}</RouterLink> <span class="muted mono small">{{ c.number }}</span></td>
                  <td class="muted">{{ dateOnly(c.start_date) }} → {{ dateOnly(c.end_date) }}</td>
                  <td class="num">{{ money(c.value) }}</td>
                  <td><span class="pill" :class="CONTRACT_STATUS[c.status]?.cls">{{ CONTRACT_STATUS[c.status]?.label }}</span></td>
                </tr>
              </tbody>
            </table>
            <p v-if="!s.contracts_list.length" class="empty">Nenhum contrato com este fornecedor.</p>
          </div>
        </section>

        <AttachmentsPanel owner-type="supplier" :owner="s.id" :items="s.attachments" title="Documentos do fornecedor" @changed="reload" />

        <SupplierFormModal v-if="editing" :supplier="s" @close="editing = false" @saved="editing = false; reload()" />
        <ContactFormModal v-if="contactEdit" :supplier-id="s.id" :contact="contactEdit === 'new' ? null : contactEdit" @close="contactEdit = null" @saved="contactEdit = null; reload()" />
        <ContractFormModal v-if="newContract && lookups" :lookups="lookups" @lookups-changed="(v) => (lookups = v)" :supplier-id="s.id" @close="newContract = false" @saved="(id) => router.push(`/contracts/${id}`)" />
      </template>
    </LoadState>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 16px; max-width: 1480px; }
.back { display: inline-flex; align-items: center; gap: 4px; color: var(--text-3); font-size: 13px; width: fit-content; }
.hero { padding: 22px 24px; display: flex; flex-direction: column; gap: 16px; }
.top { display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.title-row { display: flex; gap: 10px; align-items: center; }
.top p { margin: 6px 0 0; }
.actions { display: flex; gap: 8px; }
.facts { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px 24px; margin: 0; }
.facts dt { font-size: 12px; color: var(--text-3); margin-bottom: 3px; }
.facts dd { margin: 0; overflow-wrap: anywhere; }
.notes { margin: 0; color: var(--text-2); font-size: 13px; white-space: pre-line; }
.contacts { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; padding: 0 20px 20px; }
.contact { position: relative; border: 1px solid var(--border-soft); border-radius: var(--radius-sm); padding: 12px 14px; display: flex; flex-direction: column; gap: 4px; }
.c-head { display: flex; flex-direction: column; padding-right: 72px; }
.c-actions { position: absolute; top: 8px; right: 8px; display: flex; gap: 4px; }
.contact p { margin: 4px 0 0; }
.small { font-size: 12.5px; }
@media (max-width: 1000px) { .facts { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
