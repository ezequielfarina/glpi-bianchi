<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import LoadState from '../components/LoadState.vue'
import { api } from '../lib/api'
import { ageDays, mib, num, relative } from '../lib/format'
import type { AssetsResponse } from '../lib/types'

const route = useRoute()
const router = useRouter()

// Estado da lista espelhado na URL (link compartilhável, voltar do navegador funciona)
const q = ref(String(route.query.q ?? ''))
const type = ref(String(route.query.type ?? ''))
const stale = ref(route.query.stale === '1')
const page = ref(Number(route.query.page ?? 1))
const sort = ref(String(route.query.sort ?? 'name'))
const dir = ref(String(route.query.dir ?? 'asc'))
const perPage = 50

const data = ref<AssetsResponse | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    data.value = await api.assets({ q: q.value, type: type.value, stale: stale.value, page: page.value, perPage, sort: sort.value, dir: dir.value })
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}

function syncUrl(): void {
  void router.replace({
    query: {
      ...(q.value ? { q: q.value } : {}),
      ...(type.value ? { type: type.value } : {}),
      ...(stale.value ? { stale: '1' } : {}),
      ...(page.value > 1 ? { page: String(page.value) } : {}),
      ...(sort.value !== 'name' ? { sort: sort.value } : {}),
      ...(dir.value !== 'asc' ? { dir: dir.value } : {}),
    },
  })
}

let debounce: number | undefined
watch(q, () => {
  window.clearTimeout(debounce)
  debounce = window.setTimeout(() => { page.value = 1; syncUrl(); void load() }, 300)
})
watch([type, stale], () => { page.value = 1; syncUrl(); void load() })
// Busca vinda da barra global (outra rota → esta)
watch(() => route.query.q, (v) => { if (typeof v === 'string' && v !== q.value) q.value = v })

function sortBy(col: string): void {
  if (sort.value === col) dir.value = dir.value === 'asc' ? 'desc' : 'asc'
  else { sort.value = col; dir.value = col === 'lastInventoryAt' ? 'desc' : 'asc' }
  syncUrl()
  void load()
}
function go(p: number): void { page.value = p; syncUrl(); void load() }

const pages = computed(() => (data.value ? Math.max(1, Math.ceil(data.value.total / perPage)) : 1))
const ariaSort = (col: string) => (sort.value === col ? (dir.value === 'asc' ? 'ascending' : 'descending') : 'none')

void load()
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>Ativos</h1>
        <p class="muted">{{ data ? `${num(data.total)} encontrado(s)` : 'Carregando…' }}</p>
      </div>
    </header>

    <section class="panel">
      <div class="filters">
        <label class="sr-only" for="asset-q">Buscar</label>
        <input id="asset-q" v-model="q" class="input grow" type="search" placeholder="Nome, serial, patrimônio, UUID, MAC ou IP" />
        <label class="sr-only" for="asset-type">Tipo</label>
        <select id="asset-type" v-model="type" class="input">
          <option value="">Todos os tipos</option>
          <option value="computer">Computadores</option>
          <option value="network_equipment">Equipamentos de rede</option>
          <option value="printer">Impressoras</option>
          <option value="monitor">Monitores</option>
          <option value="phone">Telefones</option>
        </select>
        <label class="check">
          <input v-model="stale" type="checkbox" />
          Sem inventário há mais de 7 dias
        </label>
      </div>

      <LoadState :loading="loading && !data" :error="error" :rows="8" @retry="load">
        <div v-if="data && !data.items.length" class="empty">Nenhum ativo encontrado com esses filtros.</div>
        <div v-else-if="data" class="table-wrap" :class="{ dim: loading }">
          <table class="table">
            <thead>
              <tr>
                <th :aria-sort="ariaSort('name')"><button type="button" @click="sortBy('name')">Ativo <AppIcon name="sort" :size="12" /></button></th>
                <th :aria-sort="ariaSort('os')"><button type="button" @click="sortBy('os')">Sistema operacional <AppIcon name="sort" :size="12" /></button></th>
                <th>Serial / patrimônio</th>
                <th>IP</th>
                <th>Memória</th>
                <th>Local</th>
                <th :aria-sort="ariaSort('lastInventoryAt')"><button type="button" @click="sortBy('lastInventoryAt')">Último inventário <AppIcon name="sort" :size="12" /></button></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="a in data.items" :key="a.uuid">
                <td>
                  <RouterLink :to="`/assets/${a.uuid}`" class="strong">{{ a.name }}</RouterLink>
                  <div class="muted small">
                    <span class="tag">{{ a.type_label }}</span>
                    {{ [a.manufacturer, a.model].filter(Boolean).join(' · ') }}
                  </div>
                </td>
                <td>{{ a.os ?? '—' }} <span class="muted">{{ a.os_version }}</span></td>
                <td class="mono muted">
                  {{ a.serial_number ?? '—' }}
                  <div v-if="a.inventory_number" class="small">{{ a.inventory_number }}</div>
                </td>
                <td class="mono muted">{{ a.ip ?? '—' }}</td>
                <td class="num">{{ mib(a.memory_mib) }}</td>
                <td class="muted">{{ a.location ?? '—' }}</td>
                <td>
                  <span v-if="!a.dynamic" class="pill pill-muted">manual</span>
                  <span v-else class="pill" :class="ageDays(a.last_inventory_at) > 7 ? 'pill-warn' : 'pill-accent'">{{ relative(a.last_inventory_at) }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <nav v-if="data && pages > 1" class="pager" aria-label="Paginação">
          <button class="btn" type="button" :disabled="page <= 1" @click="go(page - 1)">Anterior</button>
          <span class="muted">Página {{ page }} de {{ pages }}</span>
          <button class="btn" type="button" :disabled="page >= pages" @click="go(page + 1)">Próxima</button>
        </nav>
      </LoadState>
    </section>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 20px; max-width: 1480px; }
.page-head p { margin: 4px 0 0; font-size: 13px; }
.filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; padding: 16px 20px; }
.grow { flex: 1 1 320px; }
.check { display: inline-flex; align-items: center; gap: 8px; color: var(--text-2); font-size: 13px; min-height: 40px; cursor: pointer; }
.check input { width: 16px; height: 16px; accent-color: var(--accent); }
.strong { color: var(--text); font-weight: 500; }
.small { font-size: 12px; margin-top: 2px; }
.dim { opacity: 0.6; transition: opacity 0.15s; }
.pager { display: flex; align-items: center; justify-content: flex-end; gap: 12px; padding: 14px 20px; }
.pager .btn:disabled { opacity: 0.5; cursor: not-allowed; }
.table-wrap + .pager { border-top: 1px solid var(--border-soft); }
:deep(.skeletons) { padding: 8px 20px 20px; }
:deep(.error-box) { margin: 0 20px 20px; }
</style>
