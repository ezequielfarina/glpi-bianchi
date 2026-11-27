<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted } from 'vue'
import AppIcon from '../components/AppIcon.vue'
import KpiCard from '../components/KpiCard.vue'
import LoadState from '../components/LoadState.vue'
import { api } from '../lib/api'
import type { Alerts } from '../lib/types'
import { ref } from 'vue'
import { ageDays, money, num, relative } from '../lib/format'
import { TYPE_FILTERS, typeIcon, typeStyle } from '../lib/assetTypes'
import { useAsync } from '../lib/useAsync'

const alerts = ref<Alerts | null>(null)
const { data, loading, error, reload } = useAsync(async () => {
  const [o, a] = await Promise.all([api.overview(), api.alerts()])
  alerts.value = a
  return o
})

// Painel NOC: atualiza sozinho a cada 30 s
let timer: number | undefined
onMounted(() => { timer = window.setInterval(() => { if (!document.hidden) void reload() }, 30_000) })
onBeforeUnmount(() => window.clearInterval(timer))

const k = computed(() => data.value?.kpi)
const coverage = computed(() => (k.value && k.value.agents ? Math.round((k.value.agents_24h / k.value.agents) * 100) : 0))
const maxHour = computed(() => Math.max(1, ...(data.value?.hourly ?? []).map((h) => h.processed + h.failed + h.other)))
const maxOs = computed(() => Math.max(1, ...(data.value?.os ?? []).map((o) => o.count)))
// Totais por tipo: os principais sempre aparecem (mesmo zerados), demais só se houver ativos
const types = computed(() => {
  const all = data.value?.by_type ?? []
  const main = new Set(TYPE_FILTERS.map((t) => t.code))
  const order = TYPE_FILTERS.map((t) => t.code)
  return [
    ...all.filter((t) => main.has(t.type)).sort((a, b) => order.indexOf(a.type) - order.indexOf(b.type)),
    ...all.filter((t) => !main.has(t.type) && t.total > 0),
  ]
})
// Matriz filial × tipo (só colunas de tipos com ativos)
const matrix = computed(() => {
  const cells = data.value?.by_branch ?? []
  const cols = types.value.filter((t) => t.total > 0).map((t) => t.type)
  const rows = new Map<string, { id: number | null; name: string; code: string | null; hq: boolean; counts: Record<string, number>; total: number }>()
  for (const c of cells) {
    const key = String(c.branch_id ?? 'none')
    const row = rows.get(key) ?? { id: c.branch_id, name: c.branch ?? 'Sem filial', code: c.branch_code, hq: c.kind === 'headquarters', counts: {}, total: 0 }
    row.counts[c.type] = c.total
    row.total += c.total
    rows.set(key, row)
  }
  return { cols, rows: [...rows.values()], hasBranches: cells.some((c) => c.branch_id !== null) }
})
const labelOf = (code: string) => types.value.find((t) => t.type === code)?.label ?? code
const hourlyTotal = computed(() => (data.value?.hourly ?? []).reduce((s, h) => s + h.processed + h.failed + h.other, 0))
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>Visão geral</h1>
        <p class="muted">Atualizado {{ data ? relative(data.generatedAt) : '…' }} · atualização automática a cada 30 s</p>
      </div>
      <button class="btn" type="button" :disabled="loading" @click="reload">
        <AppIcon name="refresh" :size="16" /> Atualizar
      </button>
    </header>

    <LoadState :loading="loading && !data" :error="error" :rows="6" @retry="reload">
      <template v-if="data && k">
        <section class="kpis" aria-label="Indicadores">
          <KpiCard label="Ativos" :value="num(k.assets)" :note="`+${num(k.assets_new_7d)} nos últimos 7 dias${k.assets_in_stock ? ` · ${num(k.assets_in_stock)} no estoque` : ''}`" to="/assets" />
          <KpiCard
            label="Agentes ativos (24 h)"
            :value="num(k.agents_24h)"
            :note="`${coverage}% de ${num(k.agents)} agentes`"
            :tone="coverage >= 90 ? 'ok' : 'warn'"
            to="/agents"
          />
          <KpiCard label="Inventários hoje" :value="num(k.inventories_today)" :note="`tempo médio ${num(k.avg_ms_today)} ms`" to="/inventories" />
          <KpiCard
            label="Falhas (24 h)"
            :value="num(k.failed_24h)"
            :note="k.failed_24h ? 'ver inventários com falha →' : 'nenhuma falha'"
            :tone="k.failed_24h ? 'warn' : 'ok'"
            :to="k.failed_24h ? '/inventories?status=failed' : undefined"
          />
        </section>

        <section class="panel by-type" aria-labelledby="by-type-title">
          <div class="panel-head">
            <h2 id="by-type-title">Inventário por tipo</h2>
            <RouterLink to="/assets" class="muted small">ver todos os {{ num(k.assets) }} →</RouterLink>
          </div>
          <div class="type-grid">
            <RouterLink
              v-for="t in types" :key="t.type" :to="{ path: '/assets', query: { type: t.type } }"
              class="type-card" :class="{ zero: !t.total }" :style="typeStyle(t.type)"
              :aria-label="`${t.label}: ${t.total}`"
            >
              <span class="type-badge"><AppIcon :name="typeIcon(t.type)" :size="20" /></span>
              <span class="tc-body">
                <span class="tc-label">{{ TYPE_FILTERS.find((f) => f.code === t.type)?.label ?? t.label }}</span>
                <span class="tc-total">{{ num(t.total) }}</span>
                <span v-if="t.total" class="tc-split">
                  <span title="Em uso">{{ num(t.in_use) }} em uso</span>
                  <span v-if="t.in_stock" title="Em estoque">· {{ num(t.in_stock) }} estoque</span>
                  <span v-if="t.in_repair" class="warn-text" title="Em manutenção">· {{ num(t.in_repair) }} manut.</span>
                </span>
                <span v-else class="tc-split">nenhum cadastrado</span>
              </span>
              <span v-if="t.total" class="tc-bar" aria-hidden="true"><i :style="{ width: `${Math.round((t.in_use / t.total) * 100)}%` }" /></span>
            </RouterLink>
          </div>
        </section>

        <section v-if="matrix.hasBranches && matrix.cols.length" class="panel" aria-labelledby="by-branch-title">
          <div class="panel-head"><h2 id="by-branch-title">Itens por filial</h2><RouterLink to="/branches" class="muted small">matriz e filiais →</RouterLink></div>
          <div class="table-wrap">
            <table class="table matrix">
              <thead>
                <tr>
                  <th>Unidade</th>
                  <th v-for="c in matrix.cols" :key="c" class="num" :style="typeStyle(c)"><span class="th-type"><AppIcon :name="typeIcon(c)" :size="14" /> {{ labelOf(c) }}</span></th>
                  <th class="num">Total</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in matrix.rows" :key="String(r.id)">
                  <td>
                    <RouterLink v-if="r.id" :to="`/branches/${r.id}`" class="strong">{{ r.name }}</RouterLink>
                    <RouterLink v-else :to="{ path: '/assets', query: { branch: 'none' } }" class="warn-text">Sem filial</RouterLink>
                    <span v-if="r.hq" class="pill pill-warn" style="margin-left: 6px">matriz</span>
                  </td>
                  <td v-for="c in matrix.cols" :key="c" class="num">
                    <RouterLink v-if="r.counts[c]" :to="{ path: '/assets', query: { type: c, branch: r.id ? String(r.id) : 'none' } }">{{ num(r.counts[c]) }}</RouterLink>
                    <span v-else class="muted">–</span>
                  </td>
                  <td class="num strong">{{ num(r.total) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section v-if="alerts" class="alerts" aria-label="Alertas de gestão">
          <RouterLink to="/contracts?status=expiring" class="alert panel" :class="{ hot: alerts.contracts_expiring > 0 }">
            <AppIcon name="contract" :size="20" />
            <span><strong class="num">{{ alerts.contracts_expiring }}</strong> contrato(s) vencendo<small v-if="alerts.contracts_expired"> · {{ alerts.contracts_expired }} vencido(s)</small></span>
          </RouterLink>
          <RouterLink to="/licenses" class="alert panel" :class="{ hot: alerts.licenses_over > 0 }">
            <AppIcon name="key" :size="20" />
            <span><strong class="num">{{ alerts.licenses_over }}</strong> licença(s) excedida(s)<small> · {{ alerts.licenses_expiring }} vencem em 30 dias</small></span>
          </RouterLink>
          <RouterLink to="/contracts" class="alert panel">
            <AppIcon name="truck" :size="20" />
            <span>Custo anual em contratos <strong class="num">{{ money(alerts.contracts_annual_value) }}</strong></span>
          </RouterLink>
        </section>

        <section class="two">
          <div class="panel chart-panel">
            <div class="panel-head">
              <h2>Inventários recebidos — últimas 24 h</h2>
              <div class="legend">
                <span><i class="sw ok" />Processados</span>
                <span><i class="sw fail" />Falhas</span>
                <span><i class="sw other" />Fila / rejeitados</span>
              </div>
            </div>
            <div class="panel-body">
              <div class="bars" role="img" :aria-label="`${hourlyTotal} inventários nas últimas 24 horas`">
                <div v-for="h in data.hourly" :key="h.hour" class="bar-col" :title="`${h.hour}h — ${h.processed} processados, ${h.failed} falhas, ${h.other} outros`">
                  <div class="bar other" :style="{ height: (h.other / maxHour) * 100 + '%' }" />
                  <div class="bar fail" :style="{ height: (h.failed / maxHour) * 100 + '%' }" />
                  <div class="bar ok" :style="{ height: (h.processed / maxHour) * 100 + '%' }" />
                </div>
              </div>
              <div class="axis mono">
                <span>{{ data.hourly[0]?.hour }}h</span>
                <span>{{ data.hourly[6]?.hour }}h</span>
                <span>{{ data.hourly[12]?.hour }}h</span>
                <span>{{ data.hourly[18]?.hour }}h</span>
                <span>agora</span>
              </div>
            </div>
          </div>

          <div class="panel">
            <div class="panel-head"><h2>Sistemas operacionais</h2></div>
            <div class="panel-body os-list">
              <p v-if="!data.os.length" class="muted">Nenhum ativo ainda.</p>
              <div v-for="o in data.os" :key="o.name" class="os">
                <div class="os-row"><span>{{ o.name }}</span><span class="muted num">{{ num(o.count) }}</span></div>
                <div class="track"><div class="fill" :style="{ width: (o.count / maxOs) * 100 + '%' }" /></div>
              </div>
            </div>
          </div>
        </section>

        <section class="panel">
          <div class="panel-head">
            <h2>Últimos ativos inventariados</h2>
            <RouterLink to="/assets">Ver todos os ativos →</RouterLink>
          </div>
          <div v-if="!data.recent.length" class="empty">
            Nenhum inventário recebido ainda. Aponte um GLPI Agent para este servidor.
          </div>
          <div v-else class="table-wrap">
            <table class="table">
              <thead>
                <tr><th>Ativo</th><th>Sistema operacional</th><th>Serial</th><th>IP</th><th>Agente</th><th>Último inventário</th></tr>
              </thead>
              <tbody>
                <tr v-for="a in data.recent" :key="a.uuid">
                  <td>
                    <RouterLink :to="`/assets/${a.uuid}`" class="strong">{{ a.name }}</RouterLink>
                    <div class="muted small">{{ [a.manufacturer, a.model].filter(Boolean).join(' · ') || '—' }}</div>
                  </td>
                  <td>{{ a.os ?? '—' }} <span class="muted">{{ a.os_version }}</span></td>
                  <td class="mono muted">{{ a.serial_number ?? '—' }}</td>
                  <td class="mono muted">{{ a.ip ?? '—' }}</td>
                  <td class="muted">{{ a.agent_version?.replace('GLPI-Agent_', '') ?? '—' }}</td>
                  <td>
                    <span class="pill" :class="ageDays(a.last_inventory_at) > 7 ? 'pill-warn' : 'pill-accent'">{{ relative(a.last_inventory_at) }}</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </template>
    </LoadState>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 20px; max-width: 1480px; }
.page-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
.page-head p { margin: 4px 0 0; font-size: 13px; }
.kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; }
.alerts { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
.alert { display: flex; align-items: center; gap: 12px; padding: 14px 18px; color: var(--text-2); text-decoration: none; font-size: 13.5px; }
.alert:hover { border-color: var(--accent); text-decoration: none; }
.alert.hot { border-color: color-mix(in srgb, var(--warn) 45%, var(--border)); color: var(--warn-text); }
.alert strong { color: var(--text); font-size: 15px; }
.alert small { color: var(--text-3); font-size: 12.5px; }
.two { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap: 16px; }
.legend { display: flex; gap: 14px; font-size: 12px; color: var(--text-3); flex-wrap: wrap; }
.legend span { display: inline-flex; align-items: center; gap: 6px; }
.sw { width: 10px; height: 10px; border-radius: 3px; display: inline-block; }
.sw.ok, .bar.ok { background: var(--accent); }
.sw.fail, .bar.fail { background: var(--warn); }
.sw.other, .bar.other { background: var(--text-3); opacity: 0.5; }
.bars { height: 180px; display: flex; align-items: flex-end; gap: 5px; border-bottom: 1px solid var(--border); padding-bottom: 2px; }
.bar-col { flex: 1; height: 100%; display: flex; flex-direction: column; justify-content: flex-end; gap: 1px; min-width: 4px; }
.bar { border-radius: 3px; min-height: 0; }
.axis { display: flex; justify-content: space-between; font-size: 11px; color: var(--text-3); margin-top: 8px; }
.os-list { display: flex; flex-direction: column; gap: 14px; }
.os-row { display: flex; justify-content: space-between; gap: 8px; font-size: 13px; margin-bottom: 6px; }
.track { height: 6px; background: var(--border-soft); border-radius: 99px; }
.fill { height: 100%; background: var(--accent); border-radius: 99px; }
.strong { color: var(--text); font-weight: 500; }
.small { font-size: 12px; }
@media (max-width: 1100px) { .kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } .two, .alerts { grid-template-columns: 1fr; } }
@media (max-width: 560px) { .kpis { grid-template-columns: 1fr; } }
.by-type { padding-bottom: 18px; }
.type-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 12px; padding: 0 20px; }
.type-card { --type-hue: 210; position: relative; display: flex; gap: 12px; align-items: flex-start; padding: 14px 14px 18px; border-radius: var(--radius-sm);
  border: 1px solid var(--border); color: var(--text); text-decoration: none; overflow: hidden; transition: border-color .15s, background .15s; }
.type-card:hover { border-color: hsl(var(--type-hue) 70% 55% / .7); background: hsl(var(--type-hue) 70% 50% / .06); }
.type-card.zero { opacity: .55; }
.type-card .type-badge { width: 42px; height: 42px; border-radius: 10px; }
.tc-body { display: flex; flex-direction: column; min-width: 0; }
.tc-label { font-size: 12.5px; color: var(--text-2); }
.tc-total { font-size: 28px; font-weight: 650; line-height: 1.15; font-variant-numeric: tabular-nums; }
.tc-split { font-size: 11.5px; color: var(--text-3); display: flex; gap: 4px; flex-wrap: wrap; }
.tc-bar { position: absolute; left: 0; right: 0; bottom: 0; height: 3px; background: hsl(var(--type-hue) 70% 50% / .15); }
.tc-bar i { display: block; height: 100%; background: hsl(var(--type-hue) 75% 55%); }
.matrix .th-type { display: inline-flex; align-items: center; gap: 5px; }
.matrix .th-type :deep(svg) { color: hsl(var(--type-hue) 80% 62%); }
.matrix td a { color: var(--text); }
.matrix td a:hover { color: var(--accent-text); }
.warn-text { color: var(--warn-text); }
</style>
