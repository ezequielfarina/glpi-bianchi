<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import AssetPicker from '../components/AssetPicker.vue'
import AttachmentsPanel from '../components/AttachmentsPanel.vue'
import LicenseFormModal from '../components/forms/LicenseFormModal.vue'
import LoadState from '../components/LoadState.vue'
import { api, ApiError } from '../lib/api'
import { ASSIGNMENT_LABEL, COMPLIANCE, dateOnly, dateTime, money } from '../lib/format'
import { notify } from '../lib/toast'
import type { AssetRow, Lookups } from '../lib/types'
import { useAsync } from '../lib/useAsync'

const props = defineProps<{ id: string }>()
const router = useRouter()
const { data: l, loading, error, reload } = useAsync(() => api.license(Number(props.id)))
const lookups = ref<Lookups | null>(null)
const editing = ref(false)
const key = ref<string | null>(null)
const userInput = ref('')

async function edit(): Promise<void> {
  lookups.value ??= await api.lookups()
  editing.value = true
}
async function showKey(): Promise<void> {
  if (key.value) { key.value = null; return }
  try {
    key.value = (await api.licenseKey(Number(props.id))).key ?? '(sem chave cadastrada)'
  } catch (e) {
    notify('warn', 'Não foi possível exibir a chave', e instanceof Error ? e.message : String(e))
  }
}
async function assign(body: { asset_uuid?: string; user?: string }, label: string, force = false): Promise<void> {
  try {
    await api.assignLicense(Number(props.id), { ...body, force })
    notify('ok', `Licença atribuída — ${label}`)
    userInput.value = ''
    await reload()
  } catch (e) {
    if (e instanceof ApiError && e.fields.seats && !force && window.confirm(`${e.fields.seats}\n\nRegistrar mesmo assim (excedente)?`)) return assign(body, label, true)
    notify('warn', 'Não foi possível atribuir', e instanceof Error ? e.message : String(e))
  }
}
const pickAsset = (a: AssetRow) => assign({ asset_uuid: a.uuid }, a.name)
async function unassign(id: number): Promise<void> {
  await api.unassignLicense(id)
  await reload()
}
async function remove(): Promise<void> {
  if (!l.value || !window.confirm(`Excluir a licença "${l.value.name}" e todas as atribuições?`)) return
  await api.deleteLicense(l.value.id)
  notify('ok', 'Licença excluída')
  await router.push('/licenses')
}
async function copyKey(): Promise<void> {
  if (key.value) try { await navigator.clipboard.writeText(key.value); notify('ok', 'Chave copiada') } catch { /* sem permissão */ }
}
</script>

<template>
  <div class="page">
    <RouterLink to="/licenses" class="back"><AppIcon name="back" :size="16" /> Licenças</RouterLink>
    <LoadState :loading="loading" :error="error" :rows="6" @retry="reload">
      <template v-if="l">
        <header class="hero panel">
          <div class="top">
            <div>
              <div class="title-row">
                <h1>{{ l.name }}</h1>
                <span class="pill" :class="COMPLIANCE[l.compliance]?.cls">{{ COMPLIANCE[l.compliance]?.label }}</span>
              </div>
              <p class="muted">{{ l.license_type }} · {{ ASSIGNMENT_LABEL[l.assignment] }}<template v-if="l.category"> · {{ l.category }}</template></p>
            </div>
            <div class="actions">
              <button v-if="l.has_key" class="edit-only btn" type="button" @click="showKey"><AppIcon name="key" :size="16" /> {{ key ? 'Ocultar chave' : 'Mostrar chave' }}</button>
              <button class="edit-only btn" type="button" @click="edit"><AppIcon name="edit" :size="16" /> Editar</button>
              <button class="edit-only btn btn-danger" type="button" @click="remove"><AppIcon name="trash" :size="16" /> Excluir</button>
            </div>
          </div>
          <div v-if="key" class="key mono">{{ key }} <button class="btn btn-icon" type="button" aria-label="Copiar chave" @click="copyKey"><AppIcon name="copy" :size="14" /></button></div>
          <dl class="facts">
            <div><dt>Em uso</dt><dd class="num">{{ l.used }} de {{ l.seats }}</dd></div>
            <div><dt>Instalações detectadas</dt><dd class="num">{{ l.detected ?? '—' }} <span v-if="l.software_match" class="muted small">("{{ l.software_match }}")</span></dd></div>
            <div><dt>Valor unitário / total</dt><dd class="num">{{ money(l.unit_price) }} / {{ money(l.total_price) }}</dd></div>
            <div><dt>Compra · vencimento</dt><dd>{{ dateOnly(l.purchase_date) }} · {{ dateOnly(l.expires_at) }}</dd></div>
            <div><dt>Fornecedor</dt><dd>{{ l.supplier ?? '—' }}</dd></div>
            <div><dt>Contrato</dt><dd><RouterLink v-if="l.contract_id" :to="`/contracts/${l.contract_id}`">{{ l.contract }}</RouterLink><template v-else>—</template></dd></div>
          </dl>
          <p v-if="l.notes" class="notes">{{ l.notes }}</p>
        </header>

        <div class="cols">
          <section class="panel">
            <div class="panel-head"><h2>Atribuições <span class="muted">({{ l.assignments.length }})</span></h2></div>
            <div class="edit-only add-row">
              <AssetPicker v-if="l.assignment !== 'user'" id="lic-asset" label="Atribuir a um ativo (nome, patrimônio, serial, IP)…" @pick="pickAsset" />
              <form v-else class="user-add" @submit.prevent="assign({ user: userInput }, userInput)">
                <label for="lic-user" class="sr-only">Usuário</label>
                <input id="lic-user" v-model="userInput" class="input" placeholder="login ou e-mail do usuário (ex.: maria.souza@empresa.com)" />
                <button class="btn" type="submit" :disabled="!userInput.trim()"><AppIcon name="plus" :size="14" /> Atribuir</button>
              </form>
            </div>
            <div class="table-wrap">
              <table class="table">
                <thead><tr><th>{{ l.assignment === 'user' ? 'Usuário' : 'Ativo' }}</th><th>Desde</th><th><span class="sr-only">Ações</span></th></tr></thead>
                <tbody>
                  <tr v-for="x in l.assignments" :key="x.id">
                    <td>
                      <RouterLink v-if="x.asset_uuid" :to="`/assets/${x.asset_uuid}`">{{ x.asset_name }}</RouterLink>
                      <span v-else><AppIcon name="user" :size="14" /> {{ x.user }}</span>
                      <span v-if="x.inventory_number" class="muted mono small"> {{ x.inventory_number }}</span>
                    </td>
                    <td class="muted">{{ dateTime(x.assigned_at) }}</td>
                    <td><button class="edit-only btn btn-icon" type="button" aria-label="Remover atribuição" @click="unassign(x.id)"><AppIcon name="close" :size="14" /></button></td>
                  </tr>
                </tbody>
              </table>
              <p v-if="!l.assignments.length" class="empty">Nenhum assento atribuído.</p>
            </div>
          </section>

          <section class="panel">
            <div class="panel-head">
              <h2>Instalado sem licença atribuída <span class="muted">({{ l.unassigned_detected.length }})</span></h2>
            </div>
            <p v-if="!l.software_match" class="empty">Preencha "Software correspondente" na licença para cruzar com o inventário.</p>
            <p v-else-if="!l.unassigned_detected.length" class="empty">Todas as instalações detectadas têm licença atribuída.</p>
            <ul v-else class="detected">
              <li v-for="d in l.unassigned_detected" :key="d.uuid">
                <div><RouterLink :to="`/assets/${d.uuid}`">{{ d.name }}</RouterLink> <span class="muted mono small">{{ d.inventory_number }}</span>
                  <div class="muted small">{{ d.software }} {{ d.version }}</div></div>
                <button v-if="l.assignment !== 'user'" class="edit-only btn" type="button" @click="assign({ asset_uuid: d.uuid }, d.name)">Atribuir</button>
              </li>
            </ul>
          </section>
        </div>

        <AttachmentsPanel owner-type="license" :owner="l.id" :items="l.attachments" title="Notas fiscais e comprovantes" @changed="reload" />
        <LicenseFormModal v-if="editing && lookups" :lookups="lookups" @lookups-changed="(v) => (lookups = v)" :license="l" @close="editing = false" @saved="editing = false; reload()" />
      </template>
    </LoadState>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 16px; max-width: 1480px; }
.back { display: inline-flex; align-items: center; gap: 4px; color: var(--text-3); font-size: 13px; width: fit-content; }
.hero { padding: 22px 24px; display: flex; flex-direction: column; gap: 16px; }
.top { display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; align-items: flex-start; }
.title-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.top p { margin: 6px 0 0; }
.actions { display: flex; gap: 8px; flex-wrap: wrap; }
.key { display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: var(--bg-elev); border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 14px; letter-spacing: 0.04em; overflow-wrap: anywhere; }
.facts { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px 24px; margin: 0; }
.facts dt { font-size: 12px; color: var(--text-3); margin-bottom: 3px; }
.facts dd { margin: 0; font-weight: 500; }
.notes { margin: 0; color: var(--text-2); white-space: pre-line; font-size: 13px; }
.cols { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; align-items: start; }
.add-row { padding: 0 20px 14px; display: flex; }
.user-add { display: flex; gap: 8px; flex: 1; }
.user-add .input { flex: 1; min-width: 0; }
.detected { list-style: none; margin: 0; padding: 0 20px 16px; }
.detected li { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid var(--border-soft); }
.small { font-size: 12px; }
@media (max-width: 1100px) { .cols { grid-template-columns: 1fr; } .facts { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
