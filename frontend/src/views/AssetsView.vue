<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import AuthImage from '../components/AuthImage.vue'
import LoadState from '../components/LoadState.vue'
import { api } from '../lib/api'
import { notify } from '../lib/toast'
import { ageDays, mib, num, relative } from '../lib/format'
import type { AssetsResponse, Lookups } from '../lib/types'

const route = useRoute()
const router = useRouter()

// Estado da lista espelhado na URL (link compartilhável, voltar do navegador funciona)
const q = ref(String(route.query.q ?? ''))
const type = ref(String(route.query.type ?? ''))
const stale = ref(route.query.stale === '1')
const branch = ref(String(route.query.branch ?? ''))
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
    data.value = await api.assets({ q: q.value, type: type.value, stale: stale.value, page: page.value, perPage, sort: sort.value, dir: dir.value, branch: branch.value || undefined })
    const visible = new Set(data.value.items.map((a) => a.uuid))
    selected.value = new Set([...selected.value].filter((u) => visible.has(u)))
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
      ...(branch.value ? { branch: branch.value } : {}),
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
watch([type, stale, branch], () => { page.value = 1; syncUrl(); void load() })
// Busca vinda da barra global (outra rota → esta)
watch(() => route.query.q, (v) => { if (typeof v === 'string' && v !== q.value) q.value = v })

function sortBy(col: string): void {
  if (sort.value === col) dir.value = dir.value === 'asc' ? 'desc' : 'asc'
  else { sort.value = col; dir.value = col === 'lastInventoryAt' ? 'desc' : 'asc' }
  syncUrl()
  void load()
}
function go(p: number): void { page.value = p; syncUrl(); void load() }

// Filiais (filtro + mover em lote)
const branches = ref<Lookups['branches']>([])
void api.lookups().then((l) => { branches.value = l.branches }).catch(() => undefined)
const selected = ref<Set<string>>(new Set())
const target = ref<number | ''>('')
const moving = ref(false)
const allChecked = computed(() => !!data.value?.items.length && data.value.items.every((a) => selected.value.has(a.uuid)))
function toggle(uuid: string): void {
  const s = new Set(selected.value)
  if (s.has(uuid)) s.delete(uuid); else s.add(uuid)
  selected.value = s
}
function toggleAll(): void {
  selected.value = allChecked.value ? new Set() : new Set(data.value?.items.map((a) => a.uuid) ?? [])
}
async function moveSelected(): Promise<void> {
  if (target.value === '' || !selected.value.size) return
  moving.value = true
  try {
    const r = await api.moveToBranch(target.value, [...selected.value])
    const name = target.value === 0 ? 'sem filial' : branches.value.find((b) => b.id === target.value)?.name
    notify('ok', `${r.updated} ativo(s) movido(s)`, name ? `Destino: ${name}` : undefined)
    selected.value = new Set()
    target.value = ''
    await load()
  } catch (e) {
    notify('warn', 'Não foi possível mover', e instanceof Error ? e.message : String(e))
  } finally { moving.value = false }
}

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
      <div class="actions">
        <RouterLink to="/assets/new" class="btn btn-primary"><AppIcon name="plus" :size="16" /> Novo ativo</RouterLink>
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
          <option value="tablet">Tablets</option>
          <option value="data_collector">Coletores de dados</option>
          <option value="peripheral">Periféricos</option>
        </select>
        <label class="sr-only" for="asset-branch">Filial</label>
        <select id="asset-branch" v-model="branch" class="input">
          <option value="">Todas as filiais</option>
          <option value="none">Sem filial</option>
          <option v-for="b in branches" :key="b.id" :value="String(b.id)">{{ b.name }}</option>
        </select>
        <label class="check">
          <input v-model="stale" type="checkbox" />
          Sem inventário há mais de 7 dias
        </label>
      </div>

      <div v-if="selected.size" class="bulk" role="region" aria-label="Ações em lote">
        <strong>{{ selected.size }} selecionado(s)</strong>
        <label class="sr-only" for="bulk-branch">Mover para</label>
        <select id="bulk-branch" v-model="target" class="input">
          <option value="" disabled>Mover para a filial…</option>
          <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
          <option :value="0">— remover filial —</option>
        </select>
        <button class="btn btn-primary" type="button" :disabled="target === '' || moving" @click="moveSelected">{{ moving ? 'Movendo…' : 'Mover' }}</button>
        <button class="btn" type="button" @click="selected = new Set()">Limpar seleção</button>
      </div>

      <LoadState :loading="loading && !data" :error="error" :rows="8" @retry="load">
        <div v-if="data && !data.items.length" class="empty">Nenhum ativo encontrado com esses filtros.</div>
        <div v-else-if="data" class="table-wrap" :class="{ dim: loading }">
          <table class="table">
            <thead>
              <tr>
                <th class="sel"><input type="checkbox" :checked="allChecked" aria-label="Selecionar todos desta página" @change="toggleAll" /></th>
                <th :aria-sort="ariaSort('name')"><button type="button" @click="sortBy('name')">Ativo <AppIcon name="sort" :size="12" /></button></th>
                <th :aria-sort="ariaSort('os')"><button type="button" @click="sortBy('os')">Sistema operacional <AppIcon name="sort" :size="12" /></button></th>
                <th>Patrimônio / serial</th>
                <th>IP</th>
                <th>Memória</th>
                <th :aria-sort="ariaSort('branch')"><button type="button" @click="sortBy('branch')">Filial / local <AppIcon name="sort" :size="12" /></button></th>
                <th :aria-sort="ariaSort('lastInventoryAt')"><button type="button" @click="sortBy('lastInventoryAt')">Último inventário <AppIcon name="sort" :size="12" /></button></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="a in data.items" :key="a.uuid" :class="{ picked: selected.has(a.uuid) }">
                <td class="sel"><input type="checkbox" :checked="selected.has(a.uuid)" :aria-label="`Selecionar ${a.name}`" @change="toggle(a.uuid)" /></td>
                <td>
                  <div class="asset-cell">
                    <span class="thumb"><AuthImage :uuid="a.photo_uuid" alt=""><template #fallback><AppIcon name="assets" :size="16" /></template></AuthImage></span>
                    <div>
                      <RouterLink :to="`/assets/${a.uuid}`" class="strong">{{ a.name }}</RouterLink>
                      <div class="muted small">
                        <span class="tag">{{ a.type_label }}</span>
                        {{ [a.manufacturer, a.model].filter(Boolean).join(' · ') }}
                      </div>
                    </div>
                  </div>
                </td>
                <td>{{ a.os ?? '—' }} <span class="muted">{{ a.os_version }}</span></td>
                <td class="mono">
                  {{ a.inventory_number ?? '—' }}
                  <div class="small muted">{{ a.serial_number ?? '' }}</div>
                </td>
                <td class="mono muted">{{ a.ip ?? '—' }}</td>
                <td class="num">{{ mib(a.memory_mib) }}</td>
                <td>
                  <RouterLink v-if="a.branch_id" :to="`/branches/${a.branch_id}`" class="branch">{{ a.branch_code ?? a.branch }}</RouterLink>
                  <span v-else class="muted">—</span>
                  <div v-if="a.location" class="small muted">{{ a.location }}</div>
                </td>
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
.page-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
.page-head p { margin: 4px 0 0; font-size: 13px; }
.bulk { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin: 0 20px 12px; padding: 10px 14px; border: 1px solid var(--accent); background: var(--accent-soft); border-radius: var(--radius-sm); font-size: 13px; }
.sel { width: 36px; }
.sel input { width: 16px; height: 16px; accent-color: var(--accent); }
tr.picked td { background: var(--accent-soft); }
.branch { color: var(--text); font-weight: 500; }
.asset-cell { display: flex; gap: 10px; align-items: center; }
.thumb { width: 36px; height: 36px; border-radius: 8px; background: var(--panel-hover); display: grid; place-items: center; overflow: hidden; color: var(--text-3); flex: none; }
.thumb :deep(img) { width: 100%; height: 100%; object-fit: cover; }
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
