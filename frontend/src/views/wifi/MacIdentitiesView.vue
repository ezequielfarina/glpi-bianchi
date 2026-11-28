<script setup lang="ts">
import { ref, watch } from 'vue'
import AppIcon from '../../components/AppIcon.vue'
import LoadState from '../../components/LoadState.vue'
import MacIdentityModal from '../../components/wifi/MacIdentityModal.vue'
import type { IconName } from '../../components/AppIcon.vue'
import { dateOnly } from '../../lib/format'
import { canEdit } from '../../lib/session'
import { notify } from '../../lib/toast'
import { applySummary, KIND_ICON, macUp, STATE, wifi, type Identity } from '../../lib/wifi'

// Todas as identidades de MAC, em todas as redes (ADR-0020).
const items = ref<Identity[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const q = ref('')
const status = ref('active')
const state = ref('')
const editing = ref<Identity | null | undefined>(undefined)
let t: number | undefined
async function load(): Promise<void> {
  try { items.value = (await wifi.identities({ q: q.value.trim() || undefined, status: status.value || undefined, state: state.value || undefined })).items; error.value = null }
  catch (e) { error.value = e instanceof Error ? e.message : String(e) } finally { loading.value = false }
}
watch([status, state], load, { immediate: true })
watch(q, () => { window.clearTimeout(t); t = window.setTimeout(load, 250) })
const kindIcon = (k: string) => (KIND_ICON[k] ?? 'wifi') as IconName

async function revoke(i: Identity): Promise<void> {
  if (!window.confirm(`Revogar o acesso de ${i.person_name ?? macUp(i.mac)} em todas as redes?`)) return
  try { const s = applySummary((await wifi.revoke(i.id, true)).applied); notify(s.tone, 'Acesso revogado — ' + s.title, s.detail); await load() }
  catch (e) { notify('warn', 'Não revogado', e instanceof Error ? e.message : String(e)) }
}
async function remove(i: Identity): Promise<void> {
  if (!window.confirm(`Excluir o cadastro de ${macUp(i.mac)}? O histórico continua na auditoria.`)) return
  try { await wifi.deleteIdentity(i.id); notify('ok', 'Cadastro excluído'); await load() }
  catch (e) { notify('warn', 'Não excluído', e instanceof Error ? e.message : String(e)) }
}
function exportCsv(): void {
  const rows = [['MAC', 'Pessoa', 'Matrícula', 'Setor', 'Tipo', 'Marca', 'Modelo', 'Nome na rede', 'Patrimônio', 'Filial', 'Validade', 'Situação', 'Redes']]
  for (const i of items.value) {
    rows.push([macUp(i.mac), i.person_name ?? '', i.registration ?? '', i.department ?? '', i.device_kind, i.device_brand ?? '', i.device_model ?? '', i.hostname ?? '',
      i.asset_name ?? '', i.branch ?? '', i.valid_until?.slice(0, 10) ?? '', i.status === 'active' ? 'Ativo' : 'Revogado',
      i.wlans.map((w) => `${w.wlan}@${w.site} (${STATE[w.state].label})`).join('; ')])
  }
  const csv = rows.map((r) => r.map((c) => /[";\n]/.test(c) || /^[=+\-@]/.test(c) ? `"${c.replace(/^([=+\-@])/, "'$1").replace(/"/g, '""')}"` : c).join(';')).join('\r\n')
  const a = document.createElement('a')
  a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }))
  a.download = `identidades-mac-${new Date().toISOString().slice(0, 10)}.csv`
  a.click()
  URL.revokeObjectURL(a.href)
}
</script>

<template>
  <div class="dc-page">
    <header class="dc-head">
      <div><h1>Identidades de MAC</h1><p class="muted">{{ items.length }} cadastro(s) · de quem é cada aparelho liberado no Wi-Fi e em quais redes.</p></div>
      <div class="actions">
        <button class="btn" type="button" :disabled="!items.length" @click="exportCsv"><AppIcon name="download" :size="16" /> CSV</button>
        <button v-if="canEdit" class="btn btn-primary" type="button" @click="editing = null"><AppIcon name="plus" :size="16" /> Cadastrar MAC</button>
      </div>
    </header>
    <section class="panel">
      <div class="dc-filters">
        <select v-model="status" class="input" aria-label="Situação"><option value="active">Ativos</option><option value="revoked">Revogados</option><option value="">Todos</option></select>
        <select v-model="state" class="input" aria-label="Estado no UniFi"><option value="">Qualquer estado no UniFi</option>
          <option v-for="(s, k) in STATE" :key="k" :value="k">{{ s.label }}</option></select>
        <input v-model="q" class="input search" type="search" placeholder="MAC (com ou sem separador), pessoa, matrícula, setor, modelo…" aria-label="Buscar" />
      </div>
      <LoadState :loading="loading && !items.length" :error="error" :rows="6" @retry="load">
        <p v-if="!items.length" class="empty">Nenhum MAC{{ q || state ? ' com esses filtros' : '' }}.</p>
        <div v-else class="table-wrap"><table class="table">
          <thead><tr><th>MAC</th><th>Pessoa</th><th>Aparelho</th><th>Redes</th><th>Validade</th><th /></tr></thead>
          <tbody><tr v-for="i in items" :key="i.id" :class="{ dim: i.status === 'revoked' }">
            <td class="mono dc-nowrap">{{ macUp(i.mac) }}<div class="muted dc-small">{{ [i.randomized ? 'aleatório' : i.vendor, i.source === 'imported' ? 'importado do UniFi' : null].filter(Boolean).join(' · ') }}</div></td>
            <td><b>{{ i.person_name ?? '—' }}</b><div class="muted dc-small">{{ [i.department, i.registration ? `mat. ${i.registration}` : null, i.branch].filter(Boolean).join(' · ') }}</div></td>
            <td><span class="dev"><AppIcon :name="kindIcon(i.device_kind)" :size="15" /> {{ [i.device_brand, i.device_model].filter(Boolean).join(' ') || i.hostname || '—' }}</span>
              <RouterLink v-if="i.asset_uuid" :to="`/assets/${i.asset_uuid}`" class="dc-link dc-small">{{ i.asset_name }}</RouterLink></td>
            <td><span v-for="w in i.wlans" :key="w.wlan_id" class="wchip" :title="w.last_error ?? `${w.site} · ${w.controller}`">
              <RouterLink :to="{ path: '/wifi', query: { wlan: w.wlan_id } }">{{ w.wlan }}</RouterLink><span class="pill" :class="STATE[w.state].cls">{{ STATE[w.state].label }}</span></span>
              <span v-if="!i.wlans.length" class="muted">nenhuma</span></td>
            <td class="dc-nowrap" :class="{ 'bad-t': i.expired }">{{ i.valid_until ? dateOnly(i.valid_until) : 'sem prazo' }}</td>
            <td class="row-actions"><template v-if="canEdit">
              <button class="btn btn-icon" type="button" aria-label="Editar" @click="editing = i"><AppIcon name="edit" :size="15" /></button>
              <button v-if="i.status === 'active'" class="btn btn-icon" type="button" aria-label="Revogar" title="Revogar acesso" @click="revoke(i)"><AppIcon name="close" :size="15" /></button>
              <button v-else-if="!i.wlans.length" class="btn btn-icon btn-danger" type="button" aria-label="Excluir" @click="remove(i)"><AppIcon name="trash" :size="15" /></button>
            </template></td>
          </tr></tbody>
        </table></div>
      </LoadState>
    </section>
    <MacIdentityModal v-if="editing !== undefined" :identity="editing" @close="editing = undefined" @saved="editing = undefined; load()" />
  </div>
</template>

<style scoped>
.empty { padding: 0 20px 20px; }
.dev { display: inline-flex; align-items: center; gap: 6px; }
.dim { opacity: .5; }
.bad-t { color: var(--bad-text); }
.wchip { display: inline-flex; align-items: center; gap: 6px; margin: 0 8px 4px 0; font-size: 12.5px; }
.wchip .pill { font-size: 10.5px; }
.row-actions { text-align: right; white-space: nowrap; }
</style>
