<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import AppModal from '../../components/AppModal.vue'
import LoadState from '../../components/LoadState.vue'
import { alertCounts, refreshAlertCounts, SEVERITY } from '../../lib/alerts'
import { api, ApiError } from '../../lib/api'
import { dateTime, relative } from '../../lib/format'
import { PROFILE_INFO } from '../../lib/scanTypes'
import { isAdmin } from '../../lib/session'
import { notify } from '../../lib/toast'
import type { AlertCounts, AlertFlags, AlertKind, ChannelKind, NetworkAlert, NotificationChannel, ScannerInfo, ScanProfileCode, ScanSchedule } from '../../lib/types'

const route = useRoute()
const router = useRouter()
type Tab = 'alerts' | 'schedules' | 'channels'
const tab = ref<Tab>((['alerts', 'schedules', 'channels'] as Tab[]).includes(route.query.tab as Tab) ? (route.query.tab as Tab) : 'alerts')
watch(tab, (t) => { void router.replace({ query: { ...route.query, tab: t } }); if (t === 'channels') void loadChannels() })

// ================================================================= alertas
const KIND_OPTS: { v: AlertKind | ''; l: string }[] = [
  { v: '', l: 'Todos os tipos' }, { v: 'ip_conflict', l: 'Conflito de IP' }, { v: 'new_device', l: 'Dispositivo novo' },
  { v: 'mac_changed', l: 'MAC mudou' }, { v: 'new_ports', l: 'Portas novas' }, { v: 'device_gone', l: 'Ativo sumiu' },
  { v: 'scan_failed', l: 'Varredura falhou' }, { v: 'baseline', l: 'Linha de base' },
]
const filter = reactive({ status: 'open', kind: '', severity: '' })
const alerts = ref<NetworkAlert[]>([])
const counts = ref<AlertCounts>({ critical: 0, warning: 0, info: 0, open: 0 })
const alertsLoading = ref(true)
const alertsError = ref<string | null>(null)
const selected = ref<Set<number>>(new Set())

async function loadAlerts(): Promise<void> {
  try {
    const q: Record<string, string> = { status: filter.status }
    if (filter.kind) q.kind = filter.kind
    if (filter.severity) q.severity = filter.severity
    const r = await api.networkAlerts(q)
    alerts.value = r.items
    counts.value = r.counts
    alertCounts.value = r.counts
    alertsError.value = null
    selected.value = new Set([...selected.value].filter((id) => r.items.some((a) => a.id === id && !a.acknowledged_at)))
  } catch (e) { alertsError.value = e instanceof Error ? e.message : String(e) } finally { alertsLoading.value = false }
}
watch(filter, () => { alertsLoading.value = true; void loadAlerts() })

const openAlerts = computed(() => alerts.value.filter((a) => !a.acknowledged_at))
const allSelected = computed(() => openAlerts.value.length > 0 && openAlerts.value.every((a) => selected.value.has(a.id)))
function toggleAll(): void { selected.value = allSelected.value ? new Set() : new Set(openAlerts.value.map((a) => a.id)) }
function toggle(id: number): void { const s = new Set(selected.value); if (s.has(id)) s.delete(id); else s.add(id); selected.value = s }

async function ack(ids: number[] | 'all'): Promise<void> {
  if (ids === 'all' && !confirm(`Reconhecer todos os ${filter.kind ? 'alertas deste tipo' : 'alertas abertos'}?`)) return
  try {
    const r = await api.ackAlerts(ids === 'all' ? { all: true, ...(filter.kind ? { kind: filter.kind } : {}) } : { ids })
    notify('ok', `${r.acknowledged} alerta(s) reconhecido(s)`)
    selected.value = new Set()
    await Promise.all([loadAlerts(), refreshAlertCounts()])
  } catch (e) { notify('warn', 'Não foi possível reconhecer', e instanceof Error ? e.message : String(e)) }
}

/** link "Cadastrar" para dispositivo novo: abre o formulário de ativo preenchido */
function registerLink(a: NetworkAlert): Record<string, unknown> {
  const d = a.detail as Record<string, string | null>
  const type = ({ printer: 'printer', network_equipment: 'network_equipment', phone: 'phone', mobile: 'phone' } as Record<string, string>)[d.device_type ?? ''] ?? 'computer'
  const attrs: Record<string, string> = {}
  if (a.ip) attrs.ip = a.ip
  if (a.mac) attrs.mac = a.mac
  return { path: '/assets/new', query: { type, name: d.netbios ?? d.hostname?.split('.')[0] ?? '', manufacturer: d.vendor ?? '', attrs: JSON.stringify(attrs), comment: `Descoberto pelo monitoramento (${a.schedule ?? ''})` } }
}
const macs = (a: NetworkAlert) => (a.detail.macs as { mac: string; vendor: string | null }[] | undefined) ?? []

// ================================================================= agendamentos
const schedules = ref<ScanSchedule[]>([])
const defaults = ref<AlertFlags>({ new_device: true, mac_changed: true, ip_conflict: true, new_ports: false, device_gone: false })
const schedLoading = ref(true)
const schedError = ref<string | null>(null)
const scanner = ref<ScannerInfo | null>(null)

async function loadSchedules(): Promise<void> {
  try {
    const r = await api.schedules()
    schedules.value = r.items
    defaults.value = r.alert_defaults
    schedError.value = null
  } catch (e) { schedError.value = e instanceof Error ? e.message : String(e) } finally { schedLoading.value = false }
}

const DAYS = [{ n: 1, l: 'Seg' }, { n: 2, l: 'Ter' }, { n: 3, l: 'Qua' }, { n: 4, l: 'Qui' }, { n: 5, l: 'Sex' }, { n: 6, l: 'Sáb' }, { n: 7, l: 'Dom' }]
const ALERT_INFO: { k: keyof AlertFlags; l: string; d: string }[] = [
  { k: 'new_device', l: 'Dispositivo novo sem cadastro', d: 'MAC que não estava na execução anterior e não existe no inventário.' },
  { k: 'mac_changed', l: 'MAC mudou num IP', d: 'Troca de equipamento, clonagem ou ARP spoofing. No gateway é crítico.' },
  { k: 'ip_conflict', l: 'Conflito de IP', d: 'Dois MACs respondendo pelo mesmo IP (arp-scan, só no segmento do servidor).' },
  { k: 'new_ports', l: 'Portas novas abertas', d: 'Serviço novo num dispositivo conhecido (RDP, SMB, Telnet… sobem para "atenção").' },
  { k: 'device_gone', l: 'Ativo do inventário não respondeu', d: 'Útil para servidores/impressoras; ruidoso para desktops desligados à noite.' },
]
function when(s: ScanSchedule): string {
  if (s.frequency === 'interval') return `A cada ${s.interval_hours} h`
  const d = s.weekdays.length === 7 ? 'todos os dias' : s.weekdays.join(',') === '1,2,3,4,5' ? 'seg a sex' : s.weekdays.map((n) => DAYS[n - 1]!.l.toLowerCase()).join(', ')
  return `${s.run_at} · ${d}`
}

const editing = ref<ScanSchedule | 'new' | null>(null)
const sform = reactive({
  name: '', target: '', profile: 'discovery' as ScanProfileCode, frequency: 'daily' as 'daily' | 'interval', interval_hours: 6, run_at: '02:00',
  weekdays: [1, 2, 3, 4, 5, 6, 7] as number[], snmp: false, snmp_gateway: '', snmp_community: '', alerts: { ...defaults.value } as AlertFlags, enabled: true,
})
const serrors = ref<Record<string, string>>({})
const busy = ref(false)

async function openSchedule(s: ScanSchedule | 'new'): Promise<void> {
  serrors.value = {}
  if (!scanner.value) { try { scanner.value = await api.scannerInfo() } catch { /* sugestões opcionais */ } }
  if (s === 'new') {
    Object.assign(sform, { name: '', target: scanner.value?.suggestions[0]?.cidr ?? '', profile: 'discovery', frequency: 'daily', interval_hours: 6, run_at: '02:00',
      weekdays: [1, 2, 3, 4, 5, 6, 7], snmp: false, snmp_gateway: '', snmp_community: '', alerts: { ...defaults.value }, enabled: true })
  } else {
    Object.assign(sform, { name: s.name, target: s.target, profile: s.profile, frequency: s.frequency, interval_hours: s.interval_hours ?? 6, run_at: s.run_at ?? '02:00',
      weekdays: [...s.weekdays], snmp: !!s.snmp_gateway, snmp_gateway: s.snmp_gateway ?? '', snmp_community: '', alerts: { ...s.alerts }, enabled: s.enabled })
  }
  editing.value = s
}
// erro do campo some quando o usuário corrige
watch(() => [sform.target, sform.name, sform.run_at, sform.snmp_gateway, sform.snmp_community], (now, before) => {
  const keys = ['target', 'name', 'run_at', 'snmp_gateway', 'snmp_community']
  keys.forEach((k, i) => { if (now[i] !== before?.[i] && serrors.value[k]) { const e = { ...serrors.value }; delete e[k]; serrors.value = e } })
})
function toggleDay(n: number): void { sform.weekdays = sform.weekdays.includes(n) ? sform.weekdays.filter((d) => d !== n) : [...sform.weekdays, n].sort() }
function pickSuggestion(cidr: string, label: string): void { sform.target = cidr; if (!sform.name) sform.name = label }

async function saveSchedule(): Promise<void> {
  if (!editing.value) return
  busy.value = true
  serrors.value = {}
  try {
    const body: Record<string, unknown> = {
      name: sform.name.trim(), target: sform.target.trim(), profile: sform.profile, frequency: sform.frequency, weekdays: sform.weekdays,
      alerts: sform.alerts, enabled: sform.enabled,
      ...(sform.frequency === 'interval' ? { interval_hours: sform.interval_hours } : { run_at: sform.run_at }),
      ...(sform.snmp ? { snmp_gateway: sform.snmp_gateway.trim(), snmp_community: sform.snmp_community } : {}),
    }
    await api.saveSchedule(editing.value === 'new' ? null : editing.value.id, body)
    notify('ok', editing.value === 'new' ? 'Agendamento criado' : 'Agendamento salvo')
    editing.value = null
    await loadSchedules()
  } catch (e) {
    if (e instanceof ApiError) serrors.value = e.fields
    notify('warn', 'Não foi possível salvar', e instanceof Error ? e.message : String(e))
  } finally { busy.value = false }
}

async function runNow(s: ScanSchedule): Promise<void> {
  try {
    const r = await api.runSchedule(s.id)
    notify('ok', `Varredura #${r.scan_id} na fila`, s.name)
    await loadSchedules()
  } catch (e) { notify('warn', 'Não foi possível executar', e instanceof Error ? e.message : String(e)) }
}
async function toggleEnabled(s: ScanSchedule): Promise<void> {
  try {
    await api.saveSchedule(s.id, { ...s, enabled: !s.enabled, snmp_community: '' })
    await loadSchedules()
  } catch (e) { notify('warn', 'Não foi possível alterar', e instanceof Error ? e.message : String(e)) }
}
async function removeSchedule(s: ScanSchedule): Promise<void> {
  if (!confirm(`Excluir o agendamento "${s.name}"? O histórico de varreduras e alertas é mantido.`)) return
  try { await api.deleteSchedule(s.id); editing.value = null; await loadSchedules() } catch (e) { notify('warn', 'Não foi possível excluir', e instanceof Error ? e.message : String(e)) }
}

// ================================================================= canais
const channels = ref<NotificationChannel[]>([])
const chLoading = ref(false)
const KINDS: { k: ChannelKind; l: string; help: string; placeholder: string }[] = [
  { k: 'teams', l: 'Microsoft Teams', help: 'No canal: ⋯ → Workflows → "Postar em um canal quando uma solicitação de webhook for recebida". Cole a URL gerada.', placeholder: 'https://…logic.azure.com/workflows/…' },
  { k: 'telegram', l: 'Telegram', help: 'Crie o bot no @BotFather (token) e adicione-o ao grupo. Chat ID do grupo: -100… (veja em api.telegram.org/bot<token>/getUpdates).', placeholder: '123456789:AA…' },
  { k: 'slack', l: 'Slack / Mattermost / Google Chat', help: 'Incoming Webhook do canal. Mattermost e Google Chat aceitam o mesmo formato {"text": …}.', placeholder: 'https://hooks.slack.com/services/…' },
  { k: 'webhook', l: 'Webhook JSON', help: 'POST com JSON (source, title, alerts[] com kind, severity, ip, mac, detail). Para Zabbix, n8n, Node-RED, scripts próprios.', placeholder: 'https://…' },
]
const kindOf = (k: ChannelKind) => KINDS.find((x) => x.k === k)!
const chEditing = ref<NotificationChannel | 'new' | null>(null)
const cform = reactive({ name: '', kind: 'teams' as ChannelKind, target: '', chat_id: '', min_severity: 'warning', enabled: true })
const cerrors = ref<Record<string, string>>({})

async function loadChannels(): Promise<void> {
  if (!isAdmin.value) return
  chLoading.value = true
  try { channels.value = (await api.channels()).items } catch (e) { notify('warn', 'Canais', e instanceof Error ? e.message : String(e)) } finally { chLoading.value = false }
}
function openChannel(c: NotificationChannel | 'new'): void {
  cerrors.value = {}
  Object.assign(cform, c === 'new'
    ? { name: '', kind: 'teams', target: '', chat_id: '', min_severity: 'warning', enabled: true }
    : { name: c.name, kind: c.kind, target: '', chat_id: c.chat_id ?? '', min_severity: c.min_severity, enabled: c.enabled })
  chEditing.value = c
}
async function saveChannel(): Promise<void> {
  if (!chEditing.value) return
  busy.value = true
  cerrors.value = {}
  try {
    const body: Record<string, unknown> = { name: cform.name.trim(), kind: cform.kind, min_severity: cform.min_severity, enabled: cform.enabled }
    if (cform.target.trim()) body.target = cform.target.trim()
    if (cform.kind === 'telegram') body.chat_id = cform.chat_id.trim()
    const r = await api.saveChannel(chEditing.value === 'new' ? null : chEditing.value.id, body)
    cform.target = ''
    chEditing.value = null
    await loadChannels()
    const t = await api.testChannel(r.id)
    notify(t.ok ? 'ok' : 'warn', t.ok ? 'Canal salvo — mensagem de teste enviada' : 'Canal salvo, mas o teste falhou', t.error ?? undefined)
    await loadChannels()
  } catch (e) {
    if (e instanceof ApiError) cerrors.value = e.fields
    notify('warn', 'Não foi possível salvar', e instanceof Error ? e.message : String(e))
  } finally { busy.value = false }
}
async function testChannel(c: NotificationChannel): Promise<void> {
  const t = await api.testChannel(c.id).catch((e: unknown) => ({ ok: false, error: e instanceof Error ? e.message : String(e) }))
  notify(t.ok ? 'ok' : 'warn', t.ok ? `Teste enviado para "${c.name}"` : `Falha no canal "${c.name}"`, t.error ?? undefined)
  await loadChannels()
}
async function removeChannel(c: NotificationChannel): Promise<void> {
  if (!confirm(`Excluir o canal "${c.name}"?`)) return
  try { await api.deleteChannel(c.id); chEditing.value = null; await loadChannels() } catch (e) { notify('warn', 'Não foi possível excluir', e instanceof Error ? e.message : String(e)) }
}

// ================================================================= ciclo de vida
let timer: number | undefined
onMounted(() => {
  void loadAlerts()
  void loadSchedules()
  if (tab.value === 'channels') void loadChannels()
  timer = window.setInterval(() => {
    if (document.visibilityState !== 'visible') return
    void loadAlerts()
    void loadSchedules()
  }, 30_000)
})
onBeforeUnmount(() => window.clearInterval(timer))
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>Monitoramento de rede</h1>
        <p class="muted">Varreduras agendadas que comparam cada execução com a anterior e avisam sobre dispositivos novos, troca de MAC, conflitos de IP e portas novas.</p>
      </div>
    </header>

    <div class="tabs" role="tablist" aria-label="Monitoramento">
      <button id="tab-alerts" class="tab" :class="{ active: tab === 'alerts' }" role="tab" type="button" :aria-selected="tab === 'alerts'" @click="tab = 'alerts'">
        <AppIcon name="bell" :size="16" /> Alertas <span v-if="counts.open" class="count" :class="{ bad: counts.critical }">{{ counts.open }}</span>
      </button>
      <button id="tab-schedules" class="tab" :class="{ active: tab === 'schedules' }" role="tab" type="button" :aria-selected="tab === 'schedules'" @click="tab = 'schedules'">
        <AppIcon name="clock" :size="16" /> Agendamentos <span class="count">{{ schedules.length }}</span>
      </button>
      <button v-if="isAdmin" id="tab-channels" class="tab" :class="{ active: tab === 'channels' }" role="tab" type="button" :aria-selected="tab === 'channels'" @click="tab = 'channels'">
        <AppIcon name="send" :size="16" /> Notificações
      </button>
    </div>

    <!-- ============================================================ ALERTAS -->
    <section v-if="tab === 'alerts'" role="tabpanel" aria-labelledby="tab-alerts" class="stack">
      <div class="sev-cards">
        <button v-for="sv in (['critical', 'warning', 'info'] as const)" :key="sv" type="button" class="panel sev-card" :class="[`sc-${sv}`, { on: filter.severity === sv }]" @click="filter.severity = filter.severity === sv ? '' : sv">
          <span class="sev" :class="`sev-${sv}`"><AppIcon :name="SEVERITY[sv]!.icon" :size="16" /></span>
          <span><strong>{{ counts[sv] }}</strong><small>{{ SEVERITY[sv]!.label }}{{ counts[sv] === 1 ? '' : 's' }} em aberto</small></span>
        </button>
      </div>

      <div class="panel">
        <div class="toolbar">
          <select v-model="filter.status" class="input" aria-label="Situação">
            <option value="open">Em aberto</option><option value="acknowledged">Reconhecidos</option><option value="all">Todos</option>
          </select>
          <select v-model="filter.kind" class="input" aria-label="Tipo">
            <option v-for="o in KIND_OPTS" :key="o.v" :value="o.v">{{ o.l }}</option>
          </select>
          <select v-model="filter.severity" class="input" aria-label="Severidade">
            <option value="">Todas as severidades</option><option value="critical">Crítico</option><option value="warning">Atenção</option><option value="info">Informativo</option>
          </select>
          <span class="grow" />
          <button v-if="selected.size" class="btn" type="button" @click="ack([...selected])"><AppIcon name="check" :size="14" /> Reconhecer {{ selected.size }}</button>
          <button v-else-if="filter.status !== 'acknowledged' && openAlerts.length" class="btn" type="button" @click="ack('all')"><AppIcon name="check" :size="14" /> Reconhecer todos</button>
        </div>

        <LoadState :loading="alertsLoading && !alerts.length" :error="alertsError" :empty="!alerts.length" empty-text="Nenhum alerta com estes filtros." @retry="loadAlerts">
          <div class="alert-list">
            <label v-if="openAlerts.length" class="check all"><input type="checkbox" :checked="allSelected" @change="toggleAll" /> Selecionar todos em aberto</label>
            <article v-for="a in alerts" :key="a.id" class="alert-row" :class="{ acked: a.acknowledged_at }">
              <input v-if="!a.acknowledged_at" type="checkbox" class="sel" :checked="selected.has(a.id)" :aria-label="`Selecionar ${a.title}`" @change="toggle(a.id)" />
              <span v-else class="sel" />
              <span class="sev" :class="`sev-${a.severity}`" :title="SEVERITY[a.severity]?.label"><AppIcon :name="SEVERITY[a.severity]!.icon" :size="16" /></span>
              <div class="alert-body">
                <div class="alert-title">{{ a.title }}</div>
                <div class="alert-meta">
                  <span class="pill" :class="a.severity === 'critical' ? 'pill-bad' : a.severity === 'warning' ? 'pill-warn' : 'pill-info'">{{ a.kind_label }}</span>
                  <span v-if="a.schedule"><AppIcon name="clock" :size="12" /> {{ a.schedule }}</span>
                  <span v-if="a.branch"><AppIcon name="building" :size="12" /> {{ a.branch }}</span>
                  <span :title="dateTime(a.last_seen_at)">{{ relative(a.last_seen_at) }}</span>
                  <span v-if="a.occurrences > 1" class="pill pill-muted" :title="`Desde ${dateTime(a.created_at)}`">visto {{ a.occurrences }}×</span>
                  <span v-if="a.acknowledged_at" class="muted">reconhecido por {{ a.acknowledged_by }} {{ relative(a.acknowledged_at) }}</span>
                </div>
                <ul v-if="macs(a).length" class="macs">
                  <li v-for="m in macs(a)" :key="m.mac"><code>{{ m.mac }}</code> <span class="muted">{{ m.vendor ?? 'fabricante desconhecido' }}</span></li>
                </ul>
                <p v-if="a.kind === 'scan_failed' && a.detail.error" class="err-text">{{ a.detail.error }}</p>
              </div>
              <div class="alert-actions">
                <RouterLink v-if="a.asset_uuid" class="btn btn-sm" :to="`/assets/${a.asset_uuid}`"><AppIcon name="assets" :size="13" /> {{ a.asset_name }}</RouterLink>
                <RouterLink v-if="a.kind === 'new_device' && !a.acknowledged_at" class="btn btn-sm" :to="registerLink(a)"><AppIcon name="plus" :size="13" /> Cadastrar</RouterLink>
                <RouterLink v-if="a.ip" class="btn btn-sm" :to="{ path: '/tools/net', query: { tab: 'ping', host: a.ip } }" title="Ping, traceroute, portas"><AppIcon name="pulse" :size="13" /> Diagnosticar</RouterLink>
                <RouterLink v-if="a.scan_id" class="btn btn-sm" :to="{ path: '/tools/scanner', query: { scan: String(a.scan_id) } }"><AppIcon name="radar" :size="13" /> Varredura</RouterLink>
                <button v-if="!a.acknowledged_at" class="btn btn-sm" type="button" @click="ack([a.id])"><AppIcon name="check" :size="13" /> Reconhecer</button>
              </div>
            </article>
          </div>
        </LoadState>
      </div>
    </section>

    <!-- ============================================================ AGENDAMENTOS -->
    <section v-else-if="tab === 'schedules'" role="tabpanel" aria-labelledby="tab-schedules" class="panel">
      <div class="panel-head">
        <h2>Varreduras agendadas</h2>
        <button class="btn btn-primary" type="button" @click="openSchedule('new')"><AppIcon name="plus" :size="14" /> Novo agendamento</button>
      </div>
      <LoadState :loading="schedLoading" :error="schedError" :empty="!schedules.length" empty-text="Nenhum agendamento. Crie um por rede/filial — a 1ª execução vira a linha de base." @retry="loadSchedules">
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Nome</th><th>Rede</th><th>Quando</th><th>Próxima</th><th>Última execução</th><th class="num">Alertas</th><th>Ativo</th><th /></tr></thead>
            <tbody>
              <tr v-for="s in schedules" :key="s.id" :class="{ off: !s.enabled }">
                <td><button class="link-btn strong" type="button" @click="openSchedule(s)">{{ s.name }}</button><div class="muted small">{{ PROFILE_INFO[s.profile]?.title }}<template v-if="s.snmp_gateway"> · SNMP {{ s.snmp_gateway }}</template></div></td>
                <td class="mono">{{ s.target }}</td>
                <td>{{ when(s) }}</td>
                <td :title="dateTime(s.next_run_at)">{{ s.enabled && s.next_run_at ? relative(s.next_run_at) : '—' }}</td>
                <td>
                  <template v-if="s.last_scan">
                    <RouterLink :to="{ path: '/tools/scanner', query: { scan: String(s.last_scan.id) } }" class="pill" :class="s.last_scan.status === 'done' ? 'pill-ok' : s.last_scan.status === 'failed' ? 'pill-bad' : 'pill-accent'">
                      {{ { done: 'concluída', failed: 'falhou', running: 'escaneando', queued: 'na fila', cancelled: 'cancelada' }[s.last_scan.status] }}
                    </RouterLink>
                    <span class="muted small"> {{ s.last_scan.hosts_up }} disp. · {{ relative(s.last_scan.finished_at ?? s.last_run_at) }}</span>
                  </template>
                  <span v-else class="muted">nunca</span>
                </td>
                <td class="num"><RouterLink v-if="s.open_alerts" :to="{ query: { tab: 'alerts' } }" class="pill pill-warn" @click="tab = 'alerts'">{{ s.open_alerts }}</RouterLink><span v-else class="muted">0</span></td>
                <td><label class="switch" :title="s.enabled ? 'Desativar' : 'Ativar'"><input type="checkbox" :checked="s.enabled" :aria-label="`Ativo: ${s.name}`" @change="toggleEnabled(s)" /><span /></label></td>
                <td class="num nowrap">
                  <button class="btn btn-sm" type="button" :disabled="s.last_scan?.status === 'running' || s.last_scan?.status === 'queued'" @click="runNow(s)"><AppIcon name="play" :size="12" /> Executar agora</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </LoadState>
      <p class="muted small foot">O agendador roda a cada minuto no servidor (<code>bianchi-scan-scheduler.timer</code>). Se a execução anterior ainda estiver rodando, a janela é pulada.</p>
    </section>

    <!-- ============================================================ CANAIS -->
    <section v-else-if="tab === 'channels' && isAdmin" role="tabpanel" aria-labelledby="tab-channels" class="panel">
      <div class="panel-head">
        <h2>Canais de notificação</h2>
        <button class="btn btn-primary" type="button" @click="openChannel('new')"><AppIcon name="plus" :size="14" /> Novo canal</button>
      </div>
      <LoadState :loading="chLoading && !channels.length" :error="null" :empty="!channels.length" empty-text="Nenhum canal. Os alertas aparecem só no painel até você cadastrar Teams, Telegram, Slack ou um webhook." @retry="loadChannels">
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Nome</th><th>Tipo</th><th>Destino</th><th>Envia a partir de</th><th>Último envio</th><th /></tr></thead>
            <tbody>
              <tr v-for="c in channels" :key="c.id" :class="{ off: !c.enabled }">
                <td><button class="link-btn strong" type="button" @click="openChannel(c)">{{ c.name }}</button><span v-if="!c.enabled" class="pill pill-muted" style="margin-left: 6px">desativado</span></td>
                <td>{{ kindOf(c.kind).l }}</td>
                <td class="mono small">{{ c.target_masked }}<template v-if="c.chat_id"> → {{ c.chat_id }}</template></td>
                <td><span class="pill" :class="c.min_severity === 'critical' ? 'pill-bad' : c.min_severity === 'warning' ? 'pill-warn' : 'pill-info'">{{ SEVERITY[c.min_severity]?.label }}</span></td>
                <td>
                  <span v-if="c.last_status === 'ok'" class="pill pill-ok">ok</span>
                  <span v-else-if="c.last_status === 'error'" class="pill pill-bad" :title="c.last_error ?? ''">erro</span>
                  <span class="muted small"> {{ c.last_sent_at ? relative(c.last_sent_at) : 'nunca' }}</span>
                  <div v-if="c.last_status === 'error'" class="err-text small">{{ c.last_error }}</div>
                </td>
                <td class="num nowrap"><button class="btn btn-sm" type="button" @click="testChannel(c)"><AppIcon name="send" :size="12" /> Testar</button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </LoadState>
      <p class="muted small foot">Um envio por varredura e por canal, com o resumo dos alertas <strong>novos</strong> (repetições de um alerta em aberto não notificam de novo). URL e token ficam cifrados no banco.</p>
    </section>

    <!-- ============================================================ modal agendamento -->
    <AppModal v-if="editing" :title="editing === 'new' ? 'Novo agendamento' : `Agendamento: ${editing.name}`" wide @close="editing = null">
      <form class="form" novalidate @submit.prevent="saveSchedule">
        <div class="form-grid">
          <div class="field">
            <label for="s-name">Nome</label>
            <input id="s-name" v-model="sform.name" class="input" placeholder="Ex.: Matriz — VLAN 10 usuários" maxlength="100" :aria-invalid="!!serrors.name" />
            <span v-if="serrors.name" class="err">{{ serrors.name }}</span>
          </div>
          <div class="field">
            <label for="s-target">Rede (CIDR)</label>
            <input id="s-target" v-model="sform.target" class="input mono" placeholder="192.168.10.0/24" :aria-invalid="!!serrors.target" />
            <span v-if="serrors.target" class="err">{{ serrors.target }}</span>
            <div v-if="scanner?.suggestions.length" class="chips">
              <button v-for="sg in scanner.suggestions" :key="sg.cidr + sg.label" class="tchip" :class="{ on: sform.target === sg.cidr }" type="button" @click="pickSuggestion(sg.cidr, sg.label)"><span class="mono">{{ sg.cidr }}</span> {{ sg.label }}</button>
            </div>
          </div>
          <fieldset class="field full">
            <legend>Perfil</legend>
            <div class="chips">
              <label v-for="p in (['discovery', 'standard', 'full'] as ScanProfileCode[])" :key="p" class="tchip" :class="{ on: sform.profile === p }">
                <input v-model="sform.profile" type="radio" :value="p" class="sr-only" /> {{ PROFILE_INFO[p]?.title }} <span class="muted small">{{ PROFILE_INFO[p]?.time }}</span>
              </label>
            </div>
            <span class="hint">Para monitorar presença e MAC, a <strong>Descoberta rápida</strong> basta e é leve. "Portas novas" exige Padrão ou Completo.</span>
          </fieldset>
          <fieldset class="field full">
            <legend>Quando</legend>
            <div class="when">
              <label class="tchip" :class="{ on: sform.frequency === 'daily' }"><input v-model="sform.frequency" type="radio" value="daily" class="sr-only" /> Em horário fixo</label>
              <label class="tchip" :class="{ on: sform.frequency === 'interval' }"><input v-model="sform.frequency" type="radio" value="interval" class="sr-only" /> A cada N horas</label>
              <template v-if="sform.frequency === 'daily'">
                <input v-model="sform.run_at" class="input mono time" type="time" aria-label="Horário" :aria-invalid="!!serrors.run_at" />
                <span class="days">
                  <button v-for="d in DAYS" :key="d.n" class="tchip day" :class="{ on: sform.weekdays.includes(d.n) }" type="button" :aria-pressed="sform.weekdays.includes(d.n)" @click="toggleDay(d.n)">{{ d.l }}</button>
                </span>
              </template>
              <template v-else>
                <input v-model.number="sform.interval_hours" class="input mono hours" type="number" min="1" max="168" aria-label="Intervalo em horas" /> <span class="muted">horas</span>
              </template>
            </div>
            <span v-if="serrors.run_at || serrors.weekdays || serrors.interval_hours" class="err">{{ serrors.run_at ?? serrors.weekdays ?? serrors.interval_hours }}</span>
          </fieldset>
          <fieldset class="field full">
            <legend>Gerar alerta quando</legend>
            <div class="flags">
              <label v-for="f in ALERT_INFO" :key="f.k" class="flag">
                <input v-model="sform.alerts[f.k]" type="checkbox" />
                <span><strong>{{ f.l }}</strong><small>{{ f.d }}</small></span>
              </label>
            </div>
          </fieldset>
          <div class="field full">
            <label class="check"><input v-model="sform.snmp" type="checkbox" /> Obter MAC de outras VLANs pela tabela ARP do gateway (SNMP v2c)</label>
          </div>
          <template v-if="sform.snmp">
            <div class="field">
              <label for="s-gw">Gateway (FortiGate/MikroTik/switch L3)</label>
              <input id="s-gw" v-model="sform.snmp_gateway" class="input mono" placeholder="192.168.10.1" :aria-invalid="!!serrors.snmp_gateway" />
              <span v-if="serrors.snmp_gateway" class="err">{{ serrors.snmp_gateway }}</span>
            </div>
            <div class="field">
              <label for="s-comm">Comunidade (somente leitura)</label>
              <input id="s-comm" v-model="sform.snmp_community" class="input mono" type="password" autocomplete="off"
                :placeholder="editing !== 'new' && editing.has_community ? '•••• (em branco = manter)' : ''" :aria-invalid="!!serrors.snmp_community" />
              <span v-if="serrors.snmp_community" class="err">{{ serrors.snmp_community }}</span>
              <span v-else class="hint">Guardada cifrada (necessária para rodar sem você).</span>
            </div>
          </template>
          <div class="field full">
            <label class="check"><input v-model="sform.enabled" type="checkbox" /> Ativo</label>
          </div>
        </div>
        <div class="form-actions">
          <button v-if="editing !== 'new'" class="btn btn-danger" type="button" style="margin-right: auto" @click="removeSchedule(editing)"><AppIcon name="trash" :size="14" /> Excluir</button>
          <button class="btn" type="button" @click="editing = null">Cancelar</button>
          <button class="btn btn-primary" type="submit" :disabled="busy || !sform.name.trim() || !sform.target.trim()">{{ busy ? 'Salvando…' : 'Salvar' }}</button>
        </div>
      </form>
    </AppModal>

    <!-- ============================================================ modal canal -->
    <AppModal v-if="chEditing" :title="chEditing === 'new' ? 'Novo canal de notificação' : `Canal: ${chEditing.name}`" @close="chEditing = null">
      <form class="form" novalidate @submit.prevent="saveChannel">
        <div class="field">
          <label for="c-name">Nome</label>
          <input id="c-name" v-model="cform.name" class="input" placeholder="Ex.: Teams — Infraestrutura" :aria-invalid="!!cerrors.name" />
          <span v-if="cerrors.name" class="err">{{ cerrors.name }}</span>
        </div>
        <fieldset class="field">
          <legend>Tipo</legend>
          <div class="chips">
            <label v-for="k in KINDS" :key="k.k" class="tchip" :class="{ on: cform.kind === k.k }"><input v-model="cform.kind" type="radio" :value="k.k" class="sr-only" /> {{ k.l }}</label>
          </div>
          <span class="hint">{{ kindOf(cform.kind).help }}</span>
        </fieldset>
        <div class="field">
          <label for="c-target">{{ cform.kind === 'telegram' ? 'Token do bot' : 'URL do webhook' }}</label>
          <input id="c-target" v-model="cform.target" class="input mono" type="password" autocomplete="off"
            :placeholder="chEditing !== 'new' ? `${chEditing.target_masked} (em branco = manter)` : kindOf(cform.kind).placeholder" :aria-invalid="!!cerrors.target" />
          <span v-if="cerrors.target" class="err">{{ cerrors.target }}</span>
        </div>
        <div v-if="cform.kind === 'telegram'" class="field">
          <label for="c-chat">Chat ID</label>
          <input id="c-chat" v-model="cform.chat_id" class="input mono" placeholder="-1001234567890" :aria-invalid="!!cerrors.chat_id" />
          <span v-if="cerrors.chat_id" class="err">{{ cerrors.chat_id }}</span>
        </div>
        <div class="field">
          <label for="c-sev">Enviar alertas a partir de</label>
          <select id="c-sev" v-model="cform.min_severity" class="input">
            <option value="info">Informativo (tudo, inclusive linha de base)</option>
            <option value="warning">Atenção (dispositivo novo, MAC mudou, falhas)</option>
            <option value="critical">Crítico (conflito de IP, MAC do gateway)</option>
          </select>
        </div>
        <label class="check"><input v-model="cform.enabled" type="checkbox" /> Ativo</label>
        <p class="hint">Ao salvar, uma mensagem de teste é enviada. Se a rede faz inspeção SSL no FortiGate, o CT precisa confiar na CA do FortiGate para HTTPS externo.</p>
        <div class="form-actions">
          <button v-if="chEditing !== 'new'" class="btn btn-danger" type="button" style="margin-right: auto" @click="removeChannel(chEditing)"><AppIcon name="trash" :size="14" /> Excluir</button>
          <button class="btn" type="button" @click="chEditing = null">Cancelar</button>
          <button class="btn btn-primary" type="submit" :disabled="busy || !cform.name.trim() || (chEditing === 'new' && !cform.target.trim())">{{ busy ? 'Salvando…' : 'Salvar e testar' }}</button>
        </div>
      </form>
    </AppModal>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 18px; max-width: 1480px; }
.page-head p { margin: 4px 0 0; font-size: 13px; max-width: 880px; }
.stack { display: flex; flex-direction: column; gap: 14px; }
.small { font-size: 12px; }
.sev-cards { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
.sev-card { display: flex; align-items: center; gap: 12px; padding: 14px 16px; text-align: left; font: inherit; color: inherit; cursor: pointer; }
.sev-card strong { display: block; font-size: 22px; line-height: 1.1; }
.sev-card small { color: var(--text-3); font-size: 12px; }
.sev-card.on { border-color: var(--accent); }
.sc-critical.on { border-color: var(--bad); }
.toolbar { display: flex; gap: 10px; padding: 14px 16px; flex-wrap: wrap; align-items: center; border-bottom: 1px solid var(--border-soft); }
.toolbar .input { width: auto; min-width: 170px; }
.grow { flex: 1; }
.alert-list { display: flex; flex-direction: column; }
.check.all { padding: 10px 16px; border-bottom: 1px solid var(--border-soft); }
.alert-row { display: grid; grid-template-columns: 18px 30px minmax(0, 1fr) auto; gap: 12px; align-items: start; padding: 14px 16px; border-bottom: 1px solid var(--border-soft); }
.alert-row:last-child { border-bottom: 0; }
.alert-row.acked { opacity: .62; }
.sel { margin-top: 7px; width: 16px; height: 16px; accent-color: var(--accent); }
.alert-title { font-weight: 550; overflow-wrap: anywhere; }
.alert-meta { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; margin-top: 5px; font-size: 12.5px; color: var(--text-3); }
.alert-meta > span { display: inline-flex; align-items: center; gap: 4px; }
.macs { margin: 8px 0 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 3px; font-size: 12.5px; }
.err-text { color: var(--bad-text); font-size: 12.5px; margin: 6px 0 0; overflow-wrap: anywhere; }
.alert-actions { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; max-width: 420px; }
.btn-sm { padding: 4px 10px; min-height: 0; font-size: 12.5px; }
.panel-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
tr.off td { opacity: .55; }
.link-btn.strong { font-weight: 600; }
.nowrap { white-space: nowrap; }
.foot { padding: 12px 20px; margin: 0; }
.switch { position: relative; display: inline-block; width: 36px; height: 20px; cursor: pointer; }
.switch input { position: absolute; opacity: 0; inset: 0; cursor: pointer; }
.switch span { position: absolute; inset: 0; border-radius: 10px; background: var(--muted-soft); border: 1px solid var(--border); transition: .15s; }
.switch span::after { content: ''; position: absolute; top: 2px; left: 2px; width: 14px; height: 14px; border-radius: 50%; background: var(--text-3); transition: .15s; }
.switch input:checked + span { background: var(--accent-soft); border-color: var(--accent); }
.switch input:checked + span::after { left: 18px; background: var(--accent); }
.switch input:focus-visible + span { outline: 2px solid var(--accent); outline-offset: 2px; }
fieldset { border: 0; padding: 0; margin: 0; }
legend { font-size: 12.5px; font-weight: 500; color: var(--text-2); margin-bottom: 6px; padding: 0; }
.chips { display: flex; flex-wrap: wrap; gap: 6px; }
.when { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.time { width: 140px; }
.hours { width: 90px; }
.days { display: inline-flex; gap: 4px; flex-wrap: wrap; }
.day { padding: 5px 9px; }
.flags { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
.flag { display: flex; gap: 10px; align-items: flex-start; padding: 10px 12px; border: 1px solid var(--border-soft); border-radius: var(--radius-sm); cursor: pointer; }
.flag input { margin-top: 3px; width: 16px; height: 16px; accent-color: var(--accent); }
.flag strong { display: block; font-size: 13px; font-weight: 550; }
.flag small { color: var(--text-3); font-size: 12px; }
.form { display: flex; flex-direction: column; gap: 14px; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
@media (max-width: 900px) { .sev-cards, .flags { grid-template-columns: 1fr; } .alert-row { grid-template-columns: 18px 30px minmax(0, 1fr); } .alert-actions { grid-column: 3; justify-content: flex-start; } }
</style>
