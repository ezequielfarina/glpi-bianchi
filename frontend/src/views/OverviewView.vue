<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted } from 'vue'
import AppIcon from '../components/AppIcon.vue'
import KpiCard from '../components/KpiCard.vue'
import LoadState from '../components/LoadState.vue'
import { api } from '../lib/api'
import { ageDays, num, relative } from '../lib/format'
import { useAsync } from '../lib/useAsync'

const { data, loading, error, reload } = useAsync(() => api.overview())

// Painel NOC: atualiza sozinho a cada 30 s
let timer: number | undefined
onMounted(() => { timer = window.setInterval(() => { if (!document.hidden) void reload() }, 30_000) })
onBeforeUnmount(() => window.clearInterval(timer))

const k = computed(() => data.value?.kpi)
const coverage = computed(() => (k.value && k.value.agents ? Math.round((k.value.agents_24h / k.value.agents) * 100) : 0))
const maxHour = computed(() => Math.max(1, ...(data.value?.hourly ?? []).map((h) => h.processed + h.failed + h.other)))
const maxOs = computed(() => Math.max(1, ...(data.value?.os ?? []).map((o) => o.count)))
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
          <KpiCard label="Ativos" :value="num(k.assets)" :note="`+${num(k.assets_new_7d)} nos últimos 7 dias`" to="/assets" />
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
@media (max-width: 1100px) { .kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } .two { grid-template-columns: 1fr; } }
@media (max-width: 560px) { .kpis { grid-template-columns: 1fr; } }
</style>
