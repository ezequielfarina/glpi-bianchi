<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import LoadState from '../components/LoadState.vue'
import OutcomePill from '../components/OutcomePill.vue'
import StatusPill from '../components/StatusPill.vue'
import { api } from '../lib/api'
import { bytes, dateTime, num } from '../lib/format'
import type { InventoryRow } from '../lib/types'

const route = useRoute()
const router = useRouter()
const status = ref(typeof route.query.status === 'string' ? route.query.status : '')
const items = ref<InventoryRow[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const payload = ref<{ id: number; text: string } | null>(null)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try { items.value = (await api.inventories(status.value || undefined)).items }
  catch (e) { error.value = e instanceof Error ? e.message : String(e) }
  finally { loading.value = false }
}
watch(status, () => { void router.replace({ query: status.value ? { status: status.value } : {} }); void load() })

async function showPayload(id: number): Promise<void> {
  payload.value = { id, text: JSON.stringify(await api.payload(id), null, 2) }
}
void load()
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>Inventários recebidos</h1>
        <p class="muted">Payload bruto enviado pelos agentes — últimos 200. <strong>Resultado</strong> indica se o item já estava cadastrado (novo ativo × atualizado × sem alterações). Reprocessar: <code class="mono">bin/console app:inventory:reprocess &lt;id&gt;</code></p>
      </div>
      <button class="btn" type="button" @click="load"><AppIcon name="refresh" :size="16" /> Atualizar</button>
    </header>

    <section class="panel">
      <div class="filters">
        <label for="inv-status" class="muted">Status</label>
        <select id="inv-status" v-model="status" class="input">
          <option value="">Todos</option>
          <option value="processed">Processados</option>
          <option value="failed">Com falha</option>
          <option value="received">Na fila</option>
          <option value="rejected">Rejeitados</option>
        </select>
      </div>
      <LoadState :loading="loading" :error="error" :rows="8" @retry="load">
        <div v-if="!items.length" class="empty">Nenhum inventário com esse filtro.</div>
        <div v-else class="table-wrap">
          <table class="table">
            <thead><tr><th>#</th><th>Dispositivo</th><th>Recebido</th><th>Tipo</th><th>Tamanho</th><th>Tempo</th><th>Status</th><th>Resultado</th><th>Ativo</th><th><span class="sr-only">Ações</span></th></tr></thead>
            <tbody>
              <tr v-for="r in items" :key="r.id">
                <td class="mono muted">{{ r.id }}</td>
                <td class="mono small">{{ r.device_id }}</td>
                <td class="muted">{{ dateTime(r.received_at) }}</td>
                <td><span class="tag">{{ r.partial ? 'parcial' : 'completo' }}</span> <span class="muted small">{{ r.itemtype }}</span></td>
                <td class="num muted">{{ bytes(r.size_bytes) }}</td>
                <td class="num muted">{{ r.duration_ms !== null ? `${num(r.duration_ms)} ms` : '—' }}</td>
                <td><StatusPill :status="r.status" /><div v-if="r.error" class="err small">{{ r.error }}</div></td>
                <td><OutcomePill :outcome="r.outcome" :changes="r.changes" /></td>
                <td>
                  <RouterLink v-if="r.asset_uuid" :to="`/assets/${r.asset_uuid}`">{{ r.asset_name }}</RouterLink>
                  <span v-else class="muted">—</span>
                </td>
                <td><button class="btn" type="button" @click="showPayload(r.id)"><AppIcon name="code" :size="14" /> JSON</button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </LoadState>
      <div v-if="payload" class="payload">
        <div class="panel-head">
          <h2>Payload bruto #{{ payload.id }}</h2>
          <button class="btn btn-icon" type="button" aria-label="Fechar payload" @click="payload = null"><AppIcon name="close" :size="16" /></button>
        </div>
        <pre class="mono">{{ payload.text }}</pre>
      </div>
    </section>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 20px; max-width: 1480px; }
.page-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
.page-head p { margin: 4px 0 0; font-size: 13px; }
.filters { display: flex; align-items: center; gap: 10px; padding: 16px 20px; }
.small { font-size: 12px; }
.err { color: var(--warn-text); margin-top: 4px; max-width: 420px; overflow-wrap: anywhere; }
.payload { border-top: 1px solid var(--border); }
.payload pre { margin: 0 20px 20px; padding: 16px; max-height: 560px; overflow: auto; background: var(--bg-elev); border: 1px solid var(--border-soft); border-radius: var(--radius-sm); font-size: 12px; }
:deep(.skeletons) { padding: 8px 20px 20px; }
:deep(.error-box) { margin: 0 20px 20px; }
</style>
