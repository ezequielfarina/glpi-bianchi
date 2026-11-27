<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import LoadState from '../LoadState.vue'
import { api } from '../../lib/api'
import { dateTime, num, relative } from '../../lib/format'
import type { AuditAction, AuditActionsPage } from '../../lib/types'

// Ações no sistema (ADR-0015): tudo que foi feito pela interface, com quem, quando, de onde e o que mudou.
defineExpose({ load: () => load() })
const q = ref('')
const module = ref('')
const actor = ref('')
const from = ref('')
const to = ref('')
const page = ref(1)
const data = ref<AuditActionsPage | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const open = ref<number | null>(null)

async function load(): Promise<void> {
  loading.value = true
  try {
    data.value = await api.auditActions({ q: q.value || undefined, module: module.value || undefined, actor: actor.value || undefined, from: from.value || undefined, to: to.value || undefined, page: page.value })
    error.value = null
  } catch (e) { error.value = e instanceof Error ? e.message : String(e) } finally { loading.value = false }
}
let t: number | undefined
watch(q, () => { window.clearTimeout(t); t = window.setTimeout(() => { page.value = 1; void load() }, 300) })
watch([module, actor, from, to], () => { page.value = 1; void load() })
const pages = computed(() => (data.value ? Math.max(1, Math.ceil(data.value.total / data.value.perPage)) : 1))
function go(p: number): void { page.value = p; void load() }

const KIND_CLS = (a: AuditAction): string => (a.method === 'DELETE' || /deleted|unassigned|unlinked|disable|reset|revoked/.test(a.action) ? 'pill-warn'
  : /created|in$|uploaded|assigned|linked|enabled/.test(a.action) ? 'pill-ok' : a.method === 'GET' ? 'pill-accent' : 'pill-info')

// link para o objeto, quando ainda existe tela para ele
function link(a: AuditAction): string | null {
  if (!a.entity_id || a.method === 'DELETE') return null
  return ({ asset: `/assets/${a.entity_id}`, branch: `/branches/${a.entity_id}`, supplier: `/suppliers/${a.entity_id}`, contract: `/contracts/${a.entity_id}`,
    license: `/licenses/${a.entity_id}`, stock_item: `/stock/items/${a.entity_id}`, invoice: `/invoices/${a.entity_id}`, scan: `/tools/scanner?scan=${a.entity_id}` } as Record<string, string>)[a.entity_type ?? ''] ?? null
}

const FIELD: Record<string, string> = {
  name: 'nome', category: 'categoria', min_quantity: 'mínimo', tracked: 'patrimônio', type: 'tipo', quantity: 'quantidade', reason: 'motivo',
  branch_id: 'filial', recipient: 'A/C', department: 'setor', notes: 'observações', number: 'número', series: 'série', issue_date: 'emissão',
  total: 'total', supplier_id: 'fornecedor', status: 'situação', target: 'alvo', profile: 'perfil', host: 'host', serial_number: 'série',
  inventory_number: 'patrimônio', unit_price: 'valor unitário', items: 'itens', lines: 'itens', assets: 'unidades', role: 'perfil', active: 'ativo',
}
function fmt(v: unknown): string {
  if (v === null || v === undefined || v === '') return '—'
  if (typeof v === 'boolean') return v ? 'sim' : 'não'
  if (Array.isArray(v)) return v.length > 3 ? `${v.length} itens` : v.map(fmt).join(', ')
  if (typeof v === 'object') return Object.entries(v as Record<string, unknown>).map(([k, x]) => `${FIELD[k] ?? k}: ${fmt(x)}`).join(' · ')
  return String(v)
}
function summary(a: AuditAction): string {
  const r = a.details.request
  if (!r) return ''
  return Object.entries(r).filter(([, v]) => v !== null && v !== '' && !(Array.isArray(v) && !v.length)).slice(0, 4)
    .map(([k, v]) => `${FIELD[k] ?? k}: ${fmt(v)}`).join(' · ')
}
void load()
</script>

<template>
  <section class="panel">
    <div class="filters">
      <label class="sr-only" for="aa-q">Buscar</label>
      <input id="aa-q" v-model="q" class="input grow" type="search" placeholder="Buscar: patrimônio, NF, filial, item, usuário…" />
      <label class="sr-only" for="aa-mod">Módulo</label>
      <select id="aa-mod" v-model="module" class="input"><option value="">Todos os módulos</option><option v-for="(l, k) in data?.modules ?? {}" :key="k" :value="k">{{ l }}</option></select>
      <label class="sr-only" for="aa-act">Usuário</label>
      <select id="aa-act" v-model="actor" class="input"><option value="">Todos os usuários</option><option v-for="u in data?.actors ?? []" :key="u" :value="u">{{ u }}</option></select>
      <label class="muted" for="aa-from">De</label><input id="aa-from" v-model="from" class="input" type="date" />
      <label class="muted" for="aa-to">até</label><input id="aa-to" v-model="to" class="input" type="date" />
    </div>
    <LoadState :loading="loading && !data" :error="error" :rows="8" @retry="load">
      <div v-if="data" class="table-wrap" :class="{ dim: loading }">
        <table class="table">
          <thead><tr><th>Quando</th><th>Ação</th><th>Objeto</th><th>Usuário</th><th>Detalhes</th></tr></thead>
          <tbody>
            <template v-for="a in data.items" :key="a.id">
              <tr class="clickable" :aria-expanded="open === a.id" @click="open = open === a.id ? null : a.id">
                <td class="nowrap" :title="dateTime(a.occurred_at)">{{ relative(a.occurred_at) }}<div class="muted small">{{ dateTime(a.occurred_at) }}</div></td>
                <td><span class="pill" :class="KIND_CLS(a)">{{ a.label }}</span><div class="muted small">{{ a.module_label }}</div></td>
                <td>
                  <RouterLink v-if="link(a)" :to="link(a)!" @click.stop>{{ a.subject ?? a.entity_id }}</RouterLink>
                  <span v-else>{{ a.subject ?? (a.entity_id ? `#${a.entity_id}` : '—') }}</span>
                </td>
                <td><span class="mono">{{ a.actor }}</span><div class="muted small mono">{{ a.ip ?? '' }}</div></td>
                <td class="muted small det">{{ summary(a) || '—' }}</td>
              </tr>
              <tr v-if="open === a.id" class="raw">
                <td colspan="5">
                  <div class="muted small mono">{{ a.method }} {{ a.path }} · HTTP {{ a.status }} · {{ a.action }}</div>
                  <pre>{{ JSON.stringify(a.details, null, 2) }}</pre>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
        <p v-if="!data.items.length" class="empty">Nenhuma ação com esses filtros.</p>
      </div>
      <nav v-if="data && pages > 1" class="pager" aria-label="Paginação">
        <button class="btn" type="button" :disabled="page <= 1" @click="go(page - 1)">Anterior</button>
        <span class="muted">Página {{ page }} de {{ pages }} · {{ num(data.total) }} ações</span>
        <button class="btn" type="button" :disabled="page >= pages" @click="go(page + 1)">Próxima</button>
      </nav>
    </LoadState>
  </section>
</template>

<style scoped>
.filters { display: flex; gap: 10px; padding: 16px 20px; flex-wrap: wrap; align-items: center; }
.grow { flex: 1; min-width: 240px; }
.small { font-size: 12px; }
.dim { opacity: .6; }
.nowrap { white-space: nowrap; }
.clickable { cursor: pointer; }
.det { max-width: 460px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.raw td { background: var(--surface-2, transparent); }
.raw pre { margin: 6px 0 0; font-size: 12px; white-space: pre-wrap; word-break: break-word; max-height: 320px; overflow: auto; }
.pager { display: flex; justify-content: center; align-items: center; gap: 12px; padding: 14px; }
</style>
