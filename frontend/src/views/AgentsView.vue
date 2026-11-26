<script setup lang="ts">
import { computed, ref } from 'vue'
import LoadState from '../components/LoadState.vue'
import StatusPill from '../components/StatusPill.vue'
import { api } from '../lib/api'
import { ageDays, dateTime, num, relative } from '../lib/format'
import { useAsync } from '../lib/useAsync'

const { data, loading, error, reload } = useAsync(() => api.agents())
const filter = ref('')

const items = computed(() => {
  const f = filter.value.trim().toLowerCase()
  const list = data.value?.items ?? []
  return f ? list.filter((a) => `${a.name} ${a.device_id} ${a.remote_ip ?? ''} ${a.version ?? ''} ${a.tag ?? ''}`.toLowerCase().includes(f)) : list
})
const silent = computed(() => (data.value?.items ?? []).filter((a) => ageDays(a.last_contact_at) > 2).length)

function tasks(json: string): string[] {
  try { const t = JSON.parse(json) as unknown; return Array.isArray(t) ? t.map(String) : [] } catch { return [] }
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>Agentes</h1>
        <p class="muted">
          {{ data ? `${num(data.items.length)} agente(s)` : 'Carregando…' }}
          <template v-if="silent"> · <span class="warn">{{ silent }} sem contato há mais de 2 dias</span></template>
        </p>
      </div>
    </header>

    <section class="panel">
      <div class="filters">
        <label class="sr-only" for="agent-filter">Filtrar agentes</label>
        <input id="agent-filter" v-model="filter" class="input" type="search" placeholder="Filtrar por nome, device ID, IP, versão ou tag" style="flex: 1 1 320px" />
      </div>
      <LoadState :loading="loading" :error="error" :rows="6" @retry="reload">
        <div v-if="!items.length" class="empty">Nenhum agente. Instale o GLPI Agent apontando para este servidor.</div>
        <div v-else class="table-wrap">
          <table class="table">
            <thead><tr><th>Agente</th><th>Versão</th><th>IP de origem</th><th>Tarefas</th><th>Ativo</th><th>Último inventário</th><th>Último contato</th></tr></thead>
            <tbody>
              <tr v-for="a in items" :key="a.device_id">
                <td><strong>{{ a.name }}</strong><div class="mono muted small">{{ a.device_id }}</div><span v-if="a.tag" class="tag">{{ a.tag }}</span></td>
                <td class="muted">{{ a.version?.replace('GLPI-Agent_', '') ?? '—' }}</td>
                <td class="mono muted">{{ a.remote_ip ?? '—' }}</td>
                <td><span v-for="t in tasks(a.enabled_tasks)" :key="t" class="tag" style="margin-right: 4px">{{ t }}</span></td>
                <td>
                  <RouterLink v-if="a.asset_uuid" :to="`/assets/${a.asset_uuid}`">{{ a.asset_name }}</RouterLink>
                  <span v-else class="muted">—</span>
                </td>
                <td><StatusPill v-if="a.last_status" :status="a.last_status" /><span v-else class="muted">—</span></td>
                <td :title="dateTime(a.last_contact_at)">
                  <span class="pill" :class="ageDays(a.last_contact_at) > 2 ? 'pill-warn' : 'pill-ok'">{{ relative(a.last_contact_at) }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </LoadState>
    </section>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 20px; max-width: 1480px; }
.page-head p { margin: 4px 0 0; font-size: 13px; }
.warn { color: var(--warn-text); }
.filters { display: flex; gap: 10px; padding: 16px 20px; }
.small { font-size: 12px; }
:deep(.skeletons) { padding: 8px 20px 20px; }
:deep(.error-box) { margin: 0 20px 20px; }
</style>
