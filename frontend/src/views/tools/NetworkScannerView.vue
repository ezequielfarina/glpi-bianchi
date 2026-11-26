<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import LoadState from '../../components/LoadState.vue'
import { api, ApiError } from '../../lib/api'
import { dateTime, num, relative } from '../../lib/format'
import { MAC_SOURCE, PROFILE_INFO, scanType, scanTypeStyle } from '../../lib/scanTypes'
import { notify } from '../../lib/toast'
import type { ScanDetail, ScanHost, ScannerInfo, ScanProfileCode, ScanRow } from '../../lib/types'

const route = useRoute()
const router = useRouter()

// ---------------------------------------------------------------- formulário
const info = ref<ScannerInfo | null>(null)
const infoError = ref<string | null>(null)
const form = reactive({ target: '', profile: 'standard' as ScanProfileCode, snmp: false, snmp_gateway: '', snmp_community: '' })
const errors = ref<Record<string, string>>({})
const starting = ref(false)

async function loadInfo(): Promise<void> {
  try {
    info.value = await api.scannerInfo()
    if (!form.target && info.value.suggestions[0]) form.target = info.value.suggestions[0].cidr
  } catch (e) { infoError.value = e instanceof Error ? e.message : String(e) }
}

const targetHint = computed(() => {
  const m = form.target.trim().match(/^(\d{1,3}(?:\.\d{1,3}){3})(?:\/(\d{1,2}))?$/)
  if (!m) return ''
  const prefix = m[2] ? Number(m[2]) : 32
  const total = 2 ** (32 - prefix)
  const max = info.value?.profiles.find((p) => p.code === form.profile)?.max_prefix ?? 24
  return prefix < max ? `Grande demais para este perfil (máx. /${max})` : `${num(total)} endereço(s)`
})

// gateway sugerido: 1º IP útil da rede
watch(() => form.snmp, (on) => {
  if (on && !form.snmp_gateway) {
    const m = form.target.match(/^(\d+\.\d+\.\d+)\.\d+/)
    if (m) form.snmp_gateway = `${m[1]}.1`
  }
})

async function start(): Promise<void> {
  errors.value = {}
  starting.value = true
  try {
    const body: Record<string, unknown> = { target: form.target.trim(), profile: form.profile }
    if (form.snmp) { body.snmp_gateway = form.snmp_gateway.trim() || null; body.snmp_community = form.snmp_community || null }
    const r = await api.createScan(body)
    form.snmp_community = ''
    await router.replace({ query: { scan: String(r.id) } })
    await openScan(r.id)
    await loadHistory()
  } catch (e) {
    if (e instanceof ApiError) errors.value = e.fields
    notify('warn', 'Varredura não iniciada', e instanceof Error ? e.message : String(e))
  } finally { starting.value = false }
}

// ---------------------------------------------------------------- varredura aberta (com polling)
const scan = ref<ScanDetail | null>(null)
const loadingScan = ref(false)
let timer: number | undefined
const active = computed(() => !!scan.value && ['queued', 'running'].includes(scan.value.status))

async function openScan(id: number): Promise<void> {
  window.clearTimeout(timer)
  loadingScan.value = !scan.value || scan.value.id !== id
  try {
    scan.value = await api.scan(id)
  } catch (e) {
    notify('warn', 'Varredura não encontrada', e instanceof Error ? e.message : String(e))
    scan.value = null
    return
  } finally { loadingScan.value = false }
  if (active.value) timer = window.setTimeout(() => void openScan(id), 2000)
  else if (history.value.some((h) => h.id === id && ['queued', 'running'].includes(h.status))) void loadHistory()
}
onBeforeUnmount(() => window.clearTimeout(timer))

async function cancel(): Promise<void> {
  if (!scan.value) return
  await api.cancelScan(scan.value.id)
  notify('ok', 'Cancelando a varredura…', 'Os dispositivos já encontrados ficam salvos.')
  await openScan(scan.value.id)
}
async function remove(s: ScanRow): Promise<void> {
  if (!window.confirm(`Excluir a varredura de ${s.target} (${dateTime(s.created_at)})?`)) return
  await api.deleteScan(s.id)
  if (scan.value?.id === s.id) { scan.value = null; await router.replace({ query: {} }) }
  await loadHistory()
}
const elapsed = computed(() => {
  const s = scan.value
  if (!s?.started_at) return ''
  const end = s.finished_at ? new Date(s.finished_at).getTime() : Date.now()
  const sec = Math.max(0, Math.round((end - new Date(s.started_at).getTime()) / 1000))
  return sec < 60 ? `${sec}s` : `${Math.floor(sec / 60)}min ${sec % 60}s`
})

// ---------------------------------------------------------------- histórico
const history = ref<ScanRow[]>([])
async function loadHistory(): Promise<void> {
  try { history.value = (await api.scans()).items } catch { /* silencioso */ }
}

// ---------------------------------------------------------------- resultados
const q = ref('')
const typeFilter = ref('')
const invFilter = ref<'' | 'known' | 'new'>('')
const expanded = ref<Set<number>>(new Set())
const hosts = computed(() => scan.value?.hosts ?? [])
const typeCounts = computed(() => {
  const c: Record<string, number> = {}
  for (const h of hosts.value) c[h.device_type] = (c[h.device_type] ?? 0) + 1
  return Object.entries(c).sort((a, b) => b[1] - a[1])
})
const known = computed(() => hosts.value.filter((h) => h.asset_uuid).length)
const filtered = computed(() => {
  const t = q.value.trim().toLowerCase()
  return hosts.value.filter((h) =>
    (!typeFilter.value || h.device_type === typeFilter.value)
    && (!invFilter.value || (invFilter.value === 'known') === !!h.asset_uuid)
    && (!t || [h.ip, h.mac, h.vendor, h.hostname, h.netbios_name, h.os, ...h.ports.map((p) => `${p.port} ${p.service ?? ''} ${p.product ?? ''}`)]
      .some((v) => v?.toLowerCase().includes(t))))
})
function toggle(id: number): void {
  const s = new Set(expanded.value)
  if (s.has(id)) s.delete(id); else s.add(id)
  expanded.value = s
}
const displayName = (h: ScanHost) => h.netbios_name ?? h.hostname?.split('.')[0] ?? null
function portLabel(p: ScanHost['ports'][number]): string {
  return `${p.port}${p.proto === 'udp' ? '/udp' : ''}${p.service ? ` ${p.service}` : ''}`
}
function webUrl(h: ScanHost, p: ScanHost['ports'][number]): string | null {
  const svc = p.service ?? ''
  if (p.proto !== 'tcp' || !(svc.startsWith('http') || [80, 443, 8080, 8443, 8000, 8081, 10000].includes(p.port))) return null
  const https = p.tunnel === 'ssl' || svc === 'https' || [443, 8443].includes(p.port)
  return `${https ? 'https' : 'http'}://${h.ip}${[80, 443].includes(p.port) ? '' : `:${p.port}`}/`
}
function register(h: ScanHost): void {
  const attrs: Record<string, string> = { ip: h.ip }
  if (h.mac) attrs.mac = h.mac
  void router.push({
    path: '/assets/new',
    query: {
      type: scanType(h.device_type).asset,
      name: displayName(h) ?? h.ip,
      manufacturer: h.vendor && !h.vendor.startsWith('MAC aleatório') ? h.vendor : undefined,
      ...(scan.value?.branch_id ? { branch: String(scan.value.branch_id) } : {}),
      attrs: JSON.stringify(attrs),
      comment: [h.os ? `Sistema: ${h.os}` : '', h.ports.length ? `Portas: ${h.ports.map(portLabel).join(', ')}` : '', `Encontrado pelo scanner em ${dateTime(scan.value?.created_at)}`].filter(Boolean).join('\n'),
    },
  })
}
function detailEntries(h: ScanHost): [string, string][] {
  const d = h.details as Record<string, unknown>
  const out: [string, string][] = []
  const add = (k: string, v: unknown) => { if (v !== undefined && v !== null && v !== '') out.push([k, String(v)]) }
  add('Motivo', d.reason)
  add('Família do SO', d.os_family)
  add('Build do Windows', d.windows_build)
  add('Usuário NetBIOS', d.netbios_user)
  add('SNMP sysDescr', d.snmp_sysdescr)
  add('Saltos (hops)', d.hops)
  const smb = d.smb as Record<string, string> | undefined
  if (smb) { add('SMB: SO', smb.os); add('SMB: FQDN', smb.fqdn); add('SMB: domínio', smb.domain_dns ?? smb.workgroup) }
  const rdp = d.rdp as Record<string, string> | undefined
  if (rdp) { add('RDP: computador', rdp.DNS_Computer_Name); add('RDP: domínio', rdp.DNS_Domain_Name) }
  return out
}
const scripts = (h: ScanHost) => Object.entries((h.details.scripts ?? {}) as Record<string, string>)

// ---------------------------------------------------------------- init
void loadInfo()
void loadHistory()
const fromQuery = Number(route.query.scan)
if (fromQuery) void openScan(fromQuery)
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>Scanner de rede</h1>
        <p class="muted">
          Descobre dispositivos, MAC, fabricante, sistema e serviços de uma sub-rede e cruza com o inventário.
          <span v-if="info?.nmap" class="mono small">nmap {{ info.nmap }}</span>
        </p>
      </div>
    </header>

    <p v-if="info && !info.nmap" class="error-box">O nmap não está instalado no servidor. Rode <code class="mono">bash deploy/lxc/install.sh</code> no CT.</p>
    <p v-if="infoError" class="error-box">{{ infoError }}</p>

    <section class="panel">
      <form class="scan-form" novalidate @submit.prevent="start">
        <div class="row-target">
          <div class="field grow">
            <label for="s-target">Rede ou IP</label>
            <input id="s-target" v-model="form.target" class="input mono big" placeholder="192.168.110.0/24" autocomplete="off" spellcheck="false" :aria-invalid="!!errors.target" aria-describedby="s-target-hint" />
            <span v-if="errors.target" class="err">{{ errors.target }}</span>
            <span v-else id="s-target-hint" class="hint">{{ targetHint || 'CIDR (ex.: 192.168.10.0/24) ou um único IP. Só redes privadas.' }}</span>
          </div>
          <button class="btn btn-primary scan-btn" type="submit" :disabled="starting || !form.target.trim() || !info?.nmap">
            <AppIcon name="radar" :size="18" /> {{ starting ? 'Iniciando…' : 'Escanear' }}
          </button>
        </div>
        <div v-if="info?.suggestions.length" class="suggest">
          <span class="muted small">Sugestões:</span>
          <button v-for="s in info.suggestions" :key="s.cidr + s.label" type="button" class="chip" :class="{ on: form.target === s.cidr }" @click="form.target = s.cidr">
            <span class="mono">{{ s.cidr }}</span> <span class="muted">{{ s.label }}</span>
          </button>
        </div>

        <fieldset class="profiles">
          <legend class="sr-only">Perfil</legend>
          <label v-for="(p, code) in PROFILE_INFO" :key="code" class="profile" :class="{ on: form.profile === code }">
            <input v-model="form.profile" type="radio" name="profile" :value="code" />
            <strong>{{ p.title }}</strong>
            <span class="small">{{ p.text }}</span>
            <span class="muted small"><AppIcon name="history" :size="12" /> {{ p.time }}</span>
          </label>
        </fieldset>

        <details class="snmp" :open="form.snmp">
          <summary @click.prevent="form.snmp = !form.snmp">
            <input type="checkbox" :checked="form.snmp" tabindex="-1" aria-hidden="true" />
            Obter o MAC de dispositivos de outras VLANs (tabela ARP do gateway via SNMP)
          </summary>
          <p class="hint">
            O nmap só enxerga o MAC de quem está no mesmo segmento do servidor. Para outras redes, o sistema lê a tabela ARP
            do roteador/firewall (FortiGate, MikroTik, switch L3) por SNMP v2c. A comunidade é usada só nesta varredura e não fica gravada.
            <template v-if="info && !info.snmp"><br><strong class="warn-text">Extensão php-snmp ausente no servidor.</strong></template>
          </p>
          <div class="snmp-grid">
            <div class="field">
              <label for="s-gw">IP do gateway</label>
              <input id="s-gw" v-model="form.snmp_gateway" class="input mono" placeholder="192.168.110.1" :aria-invalid="!!errors.snmp_gateway" />
              <span v-if="errors.snmp_gateway" class="err">{{ errors.snmp_gateway }}</span>
            </div>
            <div class="field">
              <label for="s-comm">Comunidade SNMP (somente leitura)</label>
              <input id="s-comm" v-model="form.snmp_community" class="input" type="password" autocomplete="off" :aria-invalid="!!errors.snmp_community" />
              <span v-if="errors.snmp_community" class="err">{{ errors.snmp_community }}</span>
            </div>
          </div>
        </details>
      </form>
    </section>

    <!-- varredura aberta -->
    <LoadState v-if="scan || loadingScan" :loading="loadingScan" :error="null" :rows="6">
      <template v-if="scan">
        <section class="panel status" :class="scan.status">
          <div class="st-head">
            <div>
              <h2>
                <span class="mono">{{ scan.target }}</span>
                <span class="pill" :class="{ 'pill-accent': active, 'pill-ok': scan.status === 'done', 'pill-warn': scan.status === 'failed', 'pill-muted': scan.status === 'cancelled' }">
                  {{ { queued: 'na fila', running: 'escaneando', done: 'concluída', failed: 'falhou', cancelled: 'cancelada' }[scan.status] }}
                </span>
              </h2>
              <p class="muted small">
                {{ PROFILE_INFO[scan.profile]?.title }} · por {{ scan.requested_by }} · {{ dateTime(scan.created_at) }}
                <template v-if="elapsed"> · {{ elapsed }}</template>
                <template v-if="scan.branch"> · <AppIcon name="pin" :size="11" /> {{ scan.branch }}</template>
                <template v-if="scan.snmp_gateway"> · ARP via SNMP de <span class="mono">{{ scan.snmp_gateway }}</span></template>
              </p>
            </div>
            <div class="st-actions">
              <button v-if="active" class="btn" type="button" @click="cancel"><AppIcon name="close" :size="14" /> Cancelar</button>
              <a v-if="!active && hosts.length" class="btn" :href="api.scanExportUrl(scan.id)" download><AppIcon name="download" :size="14" /> Exportar CSV</a>
            </div>
          </div>
          <div v-if="active" class="progress" role="progressbar" :aria-valuenow="scan.progress" aria-valuemin="0" aria-valuemax="100" :aria-label="scan.phase ?? 'Na fila'">
            <div class="bar" :class="{ indeterminate: scan.status === 'queued' || !scan.progress }" :style="{ width: `${Math.max(scan.progress, 3)}%` }" />
          </div>
          <p v-if="active" class="phase">
            <span class="pulse" aria-hidden="true" />
            {{ scan.status === 'queued' ? 'Aguardando o worker do scanner…' : (scan.phase ?? 'Escaneando…') }}
            <template v-if="scan.progress"> · {{ scan.progress }}%</template>
            · {{ num(hosts.length) }} dispositivo(s) até agora
          </p>
          <p v-if="scan.error" class="error-box">{{ scan.error }}</p>
          <ul v-if="scan.warnings.length" class="warnings">
            <li v-for="w in scan.warnings" :key="w"><AppIcon name="alert" :size="13" /> {{ w }}</li>
          </ul>
        </section>

        <section v-if="hosts.length" class="panel">
          <div class="summary">
            <div class="big-num"><strong>{{ num(hosts.length) }}</strong><span class="muted small">dispositivos</span></div>
            <div class="big-num"><strong class="ok-text">{{ num(known) }}</strong><span class="muted small">no inventário</span></div>
            <div class="big-num"><strong :class="{ 'warn-text': hosts.length - known }">{{ num(hosts.length - known) }}</strong><span class="muted small">não cadastrados</span></div>
            <div class="type-chips">
              <button type="button" class="chip" :class="{ on: !typeFilter }" @click="typeFilter = ''">Todos</button>
              <button v-for="[t, n] in typeCounts" :key="t" type="button" class="chip" :class="{ on: typeFilter === t }" :style="scanTypeStyle(t)" @click="typeFilter = typeFilter === t ? '' : t">
                <AppIcon :name="scanType(t).icon" :size="14" /> {{ scanType(t).label }} <span class="cnt">{{ n }}</span>
              </button>
            </div>
          </div>
          <div class="filters">
            <label class="sr-only" for="r-q">Filtrar</label>
            <input id="r-q" v-model="q" class="input grow" type="search" placeholder="Filtrar por IP, MAC, fabricante, nome, sistema ou porta" />
            <label class="sr-only" for="r-inv">Inventário</label>
            <select id="r-inv" v-model="invFilter" class="input">
              <option value="">Todos</option>
              <option value="new">Não cadastrados</option>
              <option value="known">Já no inventário</option>
            </select>
          </div>
          <div class="table-wrap">
            <table class="table results">
              <thead><tr><th /><th>IP</th><th>Dispositivo</th><th>MAC / fabricante</th><th>Sistema</th><th>Portas abertas</th><th>Inventário</th></tr></thead>
              <tbody>
                <template v-for="h in filtered" :key="h.id">
                  <tr class="host" :class="{ open: expanded.has(h.id), arp: h.state === 'arp' }" @click="toggle(h.id)">
                    <td><span class="type-badge sm" :style="scanTypeStyle(h.device_type)" :title="scanType(h.device_type).label"><AppIcon :name="scanType(h.device_type).icon" :size="15" /></span></td>
                    <td class="mono ip">{{ h.ip }}<div v-if="h.latency_ms !== null" class="muted small">{{ h.latency_ms }} ms</div></td>
                    <td>
                      <strong>{{ displayName(h) ?? '—' }}</strong>
                      <div class="muted small">{{ scanType(h.device_type).label }}<template v-if="h.domain"> · {{ h.domain }}</template><template v-if="h.hostname && h.hostname !== displayName(h)"> · {{ h.hostname }}</template></div>
                    </td>
                    <td>
                      <span class="mono">{{ h.mac ?? '—' }}</span>
                      <div class="muted small" :title="h.mac_source ? `Origem: ${MAC_SOURCE[h.mac_source]}` : undefined">{{ h.vendor ?? (h.mac ? '' : 'MAC indisponível (outra VLAN)') }}<template v-if="h.mac_source && h.mac_source !== 'arp'"> · via {{ h.mac_source === 'snmp' ? 'gateway' : h.mac_source === 'nbstat' ? 'NetBIOS' : 'inventário' }}</template></div>
                    </td>
                    <td>{{ h.os ?? '—' }}<div v-if="h.os_accuracy" class="muted small">{{ h.os_accuracy }}% de certeza</div></td>
                    <td>
                      <span v-for="p in h.ports.slice(0, 5)" :key="p.proto + p.port" class="port mono">{{ portLabel(p) }}</span>
                      <span v-if="h.ports.length > 5" class="muted small">+{{ h.ports.length - 5 }}</span>
                      <span v-if="!h.ports.length" class="muted small">{{ h.state === 'arp' ? 'não respondeu (visto no gateway)' : scan.profile === 'discovery' ? 'não varrido' : 'nenhuma' }}</span>
                    </td>
                    <td @click.stop>
                      <RouterLink v-if="h.asset_uuid" :to="`/assets/${h.asset_uuid}`" class="inv-link">
                        <AppIcon name="check" :size="13" /> {{ h.asset_name }}
                        <div class="muted small">por {{ { mac: 'MAC', ip: 'IP', name: 'nome' }[h.match_by ?? 'ip'] }}<template v-if="h.asset_number"> · {{ h.asset_number }}</template></div>
                      </RouterLink>
                      <button v-else class="btn btn-sm" type="button" @click="register(h)"><AppIcon name="plus" :size="13" /> Cadastrar</button>
                    </td>
                  </tr>
                  <tr v-if="expanded.has(h.id)" class="detail-row">
                    <td colspan="7">
                      <div class="detail">
                        <div v-if="h.ports.length" class="ports-detail">
                          <h3>Serviços</h3>
                          <table class="table mini">
                            <thead><tr><th>Porta</th><th>Serviço</th><th>Produto / versão</th><th>Detalhes</th></tr></thead>
                            <tbody>
                              <tr v-for="p in h.ports" :key="p.proto + p.port">
                                <td class="mono">{{ p.port }}/{{ p.proto }}</td>
                                <td>{{ p.service ?? '—' }}<span v-if="p.tunnel" class="muted"> ({{ p.tunnel }})</span></td>
                                <td>{{ [p.product, p.version].filter(Boolean).join(' ') || '—' }}<div v-if="p.extra" class="muted small">{{ p.extra }}</div></td>
                                <td class="small">
                                  <a v-if="webUrl(h, p)" :href="webUrl(h, p)!" target="_blank" rel="noopener noreferrer">abrir ↗</a>
                                  <span v-if="p.title"> “{{ p.title }}”</span>
                                  <span v-if="p.server" class="muted"> {{ p.server }}</span>
                                  <span v-if="p.cert" class="muted"> · certificado {{ p.cert.subject }}<template v-if="p.cert.expires"> (vence {{ p.cert.expires.slice(0, 10) }})</template></span>
                                  <details v-for="(out, id) in p.scripts ?? {}" :key="id"><summary class="mono">{{ id }}</summary><pre>{{ out }}</pre></details>
                                </td>
                              </tr>
                            </tbody>
                          </table>
                        </div>
                        <dl class="facts">
                          <template v-for="[k, v] in detailEntries(h)" :key="k"><dt>{{ k }}</dt><dd>{{ v }}</dd></template>
                          <template v-if="h.mac_source"><dt>Origem do MAC</dt><dd>{{ MAC_SOURCE[h.mac_source] }}</dd></template>
                        </dl>
                        <details v-for="[id, out] in scripts(h)" :key="id" class="script"><summary class="mono">{{ id }}</summary><pre>{{ out }}</pre></details>
                      </div>
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>
            <p v-if="!filtered.length" class="empty">Nenhum dispositivo com esses filtros.</p>
          </div>
        </section>
        <p v-else-if="!active && scan.status === 'done'" class="panel empty">Nenhum dispositivo respondeu em {{ scan.target }}.</p>
      </template>
    </LoadState>

    <!-- histórico -->
    <section v-if="history.length" class="panel">
      <div class="panel-head"><h2>Varreduras recentes</h2></div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Rede</th><th>Perfil</th><th>Situação</th><th class="num">Dispositivos</th><th>Quando</th><th>Por</th><th /></tr></thead>
          <tbody>
            <tr v-for="s in history" :key="s.id" class="clickable" :class="{ current: scan?.id === s.id }" @click="router.replace({ query: { scan: String(s.id) } }); openScan(s.id)">
              <td class="mono">{{ s.target }}<div v-if="s.branch" class="muted small">{{ s.branch }}</div></td>
              <td>{{ PROFILE_INFO[s.profile]?.title }}</td>
              <td><span class="pill" :class="{ 'pill-accent': ['queued', 'running'].includes(s.status), 'pill-ok': s.status === 'done', 'pill-warn': s.status === 'failed', 'pill-muted': s.status === 'cancelled' }">{{ { queued: 'na fila', running: 'escaneando', done: 'concluída', failed: 'falhou', cancelled: 'cancelada' }[s.status] }}</span></td>
              <td class="num">{{ num(s.hosts_up) }}</td>
              <td>{{ relative(s.created_at) }}</td>
              <td class="muted">{{ s.requested_by }}</td>
              <td class="num" @click.stop><button v-if="!['queued', 'running'].includes(s.status)" class="btn btn-icon" type="button" :aria-label="`Excluir varredura de ${s.target}`" @click="remove(s)"><AppIcon name="trash" :size="14" /></button></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <p class="muted small legal"><AppIcon name="alert" :size="12" /> Escaneie apenas redes que você administra ou tem autorização para testar. Todas as varreduras ficam registradas com usuário e horário.</p>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 18px; max-width: 1480px; }
.page-head p { margin: 4px 0 0; font-size: 13px; }
.small { font-size: 12px; }
.scan-form { padding: 20px; display: flex; flex-direction: column; gap: 14px; }
.row-target { display: flex; gap: 12px; align-items: flex-start; flex-wrap: wrap; }
.field { display: flex; flex-direction: column; gap: 6px; }
.field label { font-size: 12.5px; color: var(--text-2); }
.grow { flex: 1; min-width: 260px; }
.big { font-size: 17px; height: 46px; }
.scan-btn { height: 46px; margin-top: 23px; padding: 0 26px; font-size: 15px; }
.suggest { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
.chip { --type-hue: 210; display: inline-flex; align-items: center; gap: 6px; padding: 5px 11px; border-radius: 999px; border: 1px solid var(--border); background: transparent; color: var(--text-2); font: inherit; font-size: 12.5px; cursor: pointer; }
.chip:hover { background: var(--panel-hover); }
.chip.on { border-color: hsl(var(--type-hue) 70% 55%); background: hsl(var(--type-hue) 70% 50% / .14); color: var(--text); }
.chip :deep(svg) { color: hsl(var(--type-hue) 80% 65%); }
.cnt { font-size: 11px; color: var(--text-3); background: var(--muted-soft); border-radius: 999px; padding: 0 6px; }
.profiles { border: 0; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 10px; }
.profile { position: relative; display: flex; flex-direction: column; gap: 4px; padding: 12px 14px; border: 1px solid var(--border); border-radius: var(--radius-sm); cursor: pointer; }
.profile input { position: absolute; opacity: 0; }
.profile.on { border-color: var(--accent); background: var(--accent-soft); }
.profile:focus-within { outline: 2px solid var(--accent); outline-offset: 2px; }
.profile span { display: inline-flex; gap: 4px; align-items: center; }
.snmp { border: 1px solid var(--border-soft); border-radius: var(--radius-sm); padding: 10px 14px; }
.snmp summary { cursor: pointer; font-size: 13px; color: var(--text-2); display: flex; gap: 8px; align-items: center; list-style: none; }
.snmp summary input { accent-color: var(--accent); }
.snmp .hint { margin: 10px 0; }
.snmp-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
.status { padding: 16px 20px; display: flex; flex-direction: column; gap: 10px; }
.status.running, .status.queued { border-color: var(--accent); }
.st-head { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.st-head h2 { margin: 0; display: flex; gap: 10px; align-items: center; font-size: 18px; }
.st-head p { margin: 4px 0 0; }
.st-actions { display: flex; gap: 8px; align-items: flex-start; }
.progress { height: 8px; border-radius: 999px; background: var(--muted-soft); overflow: hidden; }
.bar { height: 100%; background: var(--accent); border-radius: 999px; transition: width .6s ease; }
.bar.indeterminate { width: 30% !important; animation: slide 1.4s ease-in-out infinite; }
@keyframes slide { from { transform: translateX(-100%); } to { transform: translateX(340%); } }
@media (prefers-reduced-motion: reduce) { .bar.indeterminate { animation: none; width: 100% !important; opacity: .5; } }
.phase { margin: 0; font-size: 13px; color: var(--text-2); display: flex; align-items: center; gap: 8px; }
.pulse { width: 8px; height: 8px; border-radius: 50%; background: var(--accent); animation: pulse 1.2s ease-in-out infinite; }
@keyframes pulse { 50% { opacity: .25; } }
.warnings { margin: 0; padding: 0; list-style: none; font-size: 12.5px; color: var(--warn-text); display: flex; flex-direction: column; gap: 4px; }
.warnings li { display: flex; gap: 6px; align-items: flex-start; }
.summary { display: flex; gap: 28px; align-items: center; flex-wrap: wrap; padding: 16px 20px 6px; }
.big-num { display: flex; flex-direction: column; }
.big-num strong { font-size: 26px; font-variant-numeric: tabular-nums; line-height: 1.1; }
.type-chips { display: flex; gap: 6px; flex-wrap: wrap; margin-left: auto; }
.filters { display: flex; gap: 10px; padding: 10px 20px 14px; flex-wrap: wrap; }
.results tr.host { cursor: pointer; }
.results tr.host:hover td { background: var(--panel-hover); }
.results tr.open td { background: var(--accent-soft); }
.results tr.arp td { opacity: .75; }
.type-badge.sm { width: 30px; height: 30px; border-radius: 8px; }
.ip { white-space: nowrap; }
.port { display: inline-block; font-size: 11.5px; padding: 1px 6px; margin: 0 4px 4px 0; border-radius: 5px; background: var(--muted-soft); color: var(--text-2); white-space: nowrap; }
.inv-link { display: inline-block; color: var(--ok); }
.btn-sm { padding: 4px 10px; min-height: 0; font-size: 12.5px; }
.detail-row td { background: var(--bg-elev); padding: 0; }
.detail { padding: 14px 20px 18px 62px; display: flex; flex-direction: column; gap: 12px; }
.detail h3 { margin: 0 0 6px; font-size: 12.5px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-2); }
.mini { font-size: 12.5px; }
.facts { display: grid; grid-template-columns: max-content 1fr; gap: 4px 16px; margin: 0; font-size: 12.5px; }
.facts dt { color: var(--text-3); }
.facts dd { margin: 0; overflow-wrap: anywhere; }
pre { margin: 6px 0 0; padding: 8px 10px; background: var(--panel); border: 1px solid var(--border-soft); border-radius: 6px; font-size: 11.5px; white-space: pre-wrap; max-height: 240px; overflow: auto; }
details summary { cursor: pointer; font-size: 12px; color: var(--text-2); }
tr.current td { background: var(--accent-soft); }
.clickable { cursor: pointer; }
.ok-text { color: var(--ok); }
.warn-text { color: var(--warn-text); }
.legal { display: flex; gap: 6px; align-items: center; margin: 0; }
</style>
