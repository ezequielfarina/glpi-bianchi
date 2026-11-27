<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon, { type IconName } from '../../components/AppIcon.vue'
import { api } from '../../lib/api'
import { dateOnly, relative } from '../../lib/format'
import { notify } from '../../lib/toast'
import type { AsnResult, DnsResult, MacItem, NetInfo, PingResult, PortsResult, RdapResult, TraceResult } from '../../lib/types'

const route = useRoute()
const router = useRouter()
type Tab = 'ping' | 'trace' | 'ports' | 'dns' | 'whois' | 'mac'
const TABS: { id: Tab; label: string; icon: IconName }[] = [
  { id: 'ping', label: 'Ping', icon: 'pulse' },
  { id: 'trace', label: 'Traceroute / MTR', icon: 'network' },
  { id: 'ports', label: 'Teste de portas', icon: 'lock' },
  { id: 'dns', label: 'DNS', icon: 'search' },
  { id: 'whois', label: 'Whois / ASN', icon: 'globe' },
  { id: 'mac', label: 'Fabricante do MAC', icon: 'barcode' },
]
const q = (k: string) => (typeof route.query[k] === 'string' ? (route.query[k] as string) : '')
const tab = ref<Tab>(TABS.some((t) => t.id === q('tab')) ? (q('tab') as Tab) : 'ping')
const info = ref<NetInfo | null>(null)
const busy = ref(false)
const error = ref<string | null>(null)

const host = ref(q('host'))
watch([tab, host], ([t, h]) => { void router.replace({ query: { tab: t, ...(h && ['ping', 'trace', 'ports'].includes(t) ? { host: h } : {}) } }); error.value = null })

async function run<T>(fn: () => Promise<T>, done: (r: T) => void): Promise<void> {
  busy.value = true
  error.value = null
  try { done(await fn()) } catch (e) { error.value = e instanceof Error ? e.message : String(e) } finally { busy.value = false }
}

// ---------------------------------------------------------------- ping
const pingCount = ref(4)
const ping = ref<PingResult | null>(null)
const showRaw = ref(false)
const maxMs = computed(() => Math.max(1, ...(ping.value?.replies.map((r) => r.ms ?? 0) ?? [1])))
const doPing = () => run(() => api.ping(host.value.trim(), pingCount.value), (r) => { ping.value = r })

// ---------------------------------------------------------------- trace
const cycles = ref(3)
const trace = ref<TraceResult | null>(null)
const traceMax = computed(() => Math.max(1, ...(trace.value?.hops.map((h) => h.worst ?? 0) ?? [1])))
const intermediateLossOnly = computed(() => {
  const hops = trace.value?.hops ?? []
  const last = hops[hops.length - 1]
  return !!last && last.loss === 0 && hops.some((h) => h.loss > 0 && h.ip)
})
const doTrace = () => run(() => api.trace(host.value.trim(), cycles.value), (r) => { trace.value = r })

// ---------------------------------------------------------------- portas
const PRESETS: { l: string; p: string }[] = [
  { l: 'Windows', p: '135 139 445 3389 5985' },
  { l: 'AD/DC', p: '53 88 135 389 445 636 3268 3269' },
  { l: 'Web', p: '80 443 8080 8443' },
  { l: 'FortiGate', p: '22 443 541 8443 10443' },
  { l: 'MikroTik', p: '22 80 443 8291 8728 8729' },
  { l: 'Proxmox', p: '22 8006 3128' },
  { l: 'Impressora', p: '80 443 515 631 9100' },
  { l: 'E-mail', p: '25 110 143 465 587 993 995' },
  { l: 'Bancos', p: '1433 1521 3306 5432 6379' },
  { l: 'Zabbix', p: '10050 10051' },
]
const portsIn = ref('22 80 443 3389')
const tls = ref(false)
const ports = ref<PortsResult | null>(null)
const doPorts = () => run(() => api.ports(host.value.trim(), portsIn.value, tls.value), (r) => { ports.value = r })
const STATE: Record<string, { l: string; c: string }> = { open: { l: 'aberta', c: 'pill-ok' }, closed: { l: 'fechada', c: 'pill-muted' }, filtered: { l: 'filtrada', c: 'pill-warn' }, skipped: { l: 'não testada', c: 'pill-muted' } }

// ---------------------------------------------------------------- DNS
const dnsForm = reactive({ name: q('tab') === 'dns' ? q('q') : '', type: 'A', servers: [] as string[], custom: '' })
const dns = ref<DnsResult | null>(null)
const serverOptions = computed(() => [...new Set([...(info.value?.resolvers ?? []), '1.1.1.1', '8.8.8.8'])])
function toggleServer(s: string): void { dnsForm.servers = dnsForm.servers.includes(s) ? dnsForm.servers.filter((x) => x !== s) : [...dnsForm.servers, s] }
const doDns = () => {
  const servers = [...dnsForm.servers, ...dnsForm.custom.split(/[\s,;]+/).filter(Boolean)]
  return run(() => api.dns(dnsForm.name.trim(), dnsForm.type, servers), (r) => { dns.value = r })
}
const statusPill = (s: string) => (s === 'NOERROR' ? 'pill-ok' : s === 'NXDOMAIN' ? 'pill-warn' : 'pill-bad')

// ---------------------------------------------------------------- whois / ASN
const whoisQ = ref(q('tab') === 'whois' ? q('q') : '')
const rdap = ref<RdapResult | null>(null)
const asn = ref<AsnResult | null>(null)
const rdapError = ref<string | null>(null)
const showRdapRaw = ref(false)
async function doWhois(): Promise<void> {
  const v = whoisQ.value.trim()
  busy.value = true
  error.value = rdapError.value = null
  rdap.value = asn.value = null
  const isIpLike = /^[\d.]+$|:/.test(v)
  const [r, a] = await Promise.allSettled([api.rdap(v), isIpLike || /^[a-z0-9.-]+\.[a-z]{2,}$/i.test(v) ? api.asn(v) : Promise.resolve(null)])
  if (r.status === 'fulfilled') rdap.value = r.value
  else rdapError.value = r.reason instanceof Error ? r.reason.message : String(r.reason)
  if (a.status === 'fulfilled') asn.value = a.value
  if (r.status === 'rejected' && a.status === 'rejected') error.value = rdapError.value
  busy.value = false
}
const ROLE: Record<string, string> = { registrant: 'Titular', administrative: 'Administrativo', technical: 'Técnico', abuse: 'Abuso', billing: 'Cobrança', registrar: 'Registrar', noc: 'NOC' }

// ---------------------------------------------------------------- MAC
const macIn = ref(q('tab') === 'mac' ? q('q') : '')
const macs = ref<MacItem[]>([])
const doMacs = () => run(() => api.macs(macIn.value), (r) => { macs.value = r.items })

function submit(): void {
  if (busy.value) return
  const map: Record<Tab, () => Promise<void>> = { ping: doPing, trace: doTrace, ports: doPorts, dns: doDns, whois: doWhois, mac: doMacs }
  void map[tab.value]()
}
async function copy(t: string): Promise<void> { try { await navigator.clipboard.writeText(t); notify('ok', 'Copiado') } catch { /* sem clipboard */ } }

onMounted(async () => {
  try { info.value = await api.netInfo() } catch { /* opcional */ }
  // vindo de um link (alerta, conflito, ativo): já executa
  if ((host.value && ['ping', 'trace', 'ports'].includes(tab.value)) || (q('q') && ['mac', 'whois', 'dns'].includes(tab.value))) submit()
})
const missing = computed(() => {
  const t = info.value?.tools
  if (!t) return null
  const need: Partial<Record<Tab, boolean>> = { ping: t.ping, trace: t.mtr || t.traceroute, dns: t.dig, whois: t.dig, mac: t.mac_db }
  return need[tab.value] === false ? ({ ping: 'iputils-ping', trace: 'mtr-tiny', dns: 'bind9-dnsutils', whois: 'bind9-dnsutils', mac: 'nmap' } as Record<string, string>)[tab.value] : null
})
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>Diagnóstico e consultas</h1>
        <p class="muted">Executado a partir do servidor (CT): mostra o que o servidor enxerga — útil para testar regras do FortiGate, rotas entre VLANs e DNS interno × externo.</p>
      </div>
    </header>

    <div class="tabs" role="tablist" aria-label="Ferramenta">
      <button v-for="t in TABS" :id="`tab-${t.id}`" :key="t.id" class="tab" :class="{ active: tab === t.id }" role="tab" type="button" :aria-selected="tab === t.id" @click="tab = t.id">
        <AppIcon :name="t.icon" :size="16" /> {{ t.label }}
      </button>
    </div>

    <p v-if="missing" class="notice"><AppIcon name="alert" :size="16" /> Pacote <code>{{ missing }}</code> não está instalado no servidor (o <code>install.sh</code> instala).</p>

    <section class="panel" role="tabpanel" :aria-labelledby="`tab-${tab}`">
      <form class="entry" novalidate @submit.prevent="submit">
        <!-- ping / trace / portas: alvo -->
        <template v-if="tab === 'ping' || tab === 'trace' || tab === 'ports'">
          <div class="field grow">
            <label for="n-host">IP ou nome</label>
            <input id="n-host" v-model="host" class="input mono big" placeholder="192.168.110.1 ou fw.bianchi.local" spellcheck="false" autocomplete="off" />
          </div>
          <div v-if="tab === 'ping'" class="field">
            <label for="n-count">Pacotes</label>
            <input id="n-count" v-model.number="pingCount" class="input mono big narrow" type="number" min="1" max="20" />
          </div>
          <div v-if="tab === 'trace'" class="field">
            <label for="n-cyc">Ciclos por salto</label>
            <input id="n-cyc" v-model.number="cycles" class="input mono big narrow" type="number" min="1" max="10" />
          </div>
          <div v-if="tab === 'ports'" class="field grow">
            <label for="n-ports">Portas TCP (até 32; aceita faixa 8000-8010)</label>
            <input id="n-ports" v-model="portsIn" class="input mono big" spellcheck="false" />
            <div class="chips">
              <button v-for="p in PRESETS" :key="p.l" class="tchip" :class="{ on: portsIn === p.p }" type="button" @click="portsIn = p.p">{{ p.l }}</button>
              <label class="check"><input v-model="tls" type="checkbox" /> TLS em todas</label>
            </div>
          </div>
        </template>

        <template v-else-if="tab === 'dns'">
          <div class="field grow">
            <label for="n-dns">Nome (ou IP para reverso)</label>
            <input id="n-dns" v-model="dnsForm.name" class="input mono big" placeholder="intranet.bianchi.local" spellcheck="false" autocomplete="off" />
          </div>
          <div class="field">
            <label for="n-type">Tipo</label>
            <select id="n-type" v-model="dnsForm.type" class="input mono big"><option v-for="t in info?.dns_types ?? ['A']" :key="t">{{ t }}</option></select>
          </div>
          <div class="field full">
            <label>Servidores (marque vários para comparar — split-horizon, propagação)</label>
            <div class="chips">
              <button class="tchip" :class="{ on: !dnsForm.servers.length && !dnsForm.custom }" type="button" @click="dnsForm.servers = []; dnsForm.custom = ''">resolver do servidor</button>
              <button v-for="s in serverOptions" :key="s" class="tchip mono" :class="{ on: dnsForm.servers.includes(s) }" type="button" @click="toggleServer(s)">{{ s }}<template v-if="info?.resolvers.includes(s)"> (local)</template></button>
              <input v-model="dnsForm.custom" class="input mono custom" placeholder="outros IPs (ex.: DCs)" aria-label="Outros servidores DNS" />
            </div>
          </div>
        </template>

        <template v-else-if="tab === 'whois'">
          <div class="field grow">
            <label for="n-whois">Domínio, IP público ou ASN</label>
            <input id="n-whois" v-model="whoisQ" class="input mono big" placeholder="bianchi.com.br · 200.160.2.3 · AS28573" spellcheck="false" autocomplete="off" />
          </div>
        </template>

        <template v-else>
          <div class="field grow">
            <label for="n-mac">MACs (um por linha, qualquer formato: aa:bb:…, aa-bb-…, aabb.ccdd.eeff, prefixo OUI)</label>
            <textarea id="n-mac" v-model="macIn" class="input mono" rows="4" placeholder="00:09:0F:12:34:56&#10;bc24.1122.3344&#10;70-85-C2" spellcheck="false" />
          </div>
        </template>

        <div class="field go">
          <button class="btn btn-primary big-btn" type="submit" :disabled="busy">
            <AppIcon :name="busy ? 'refresh' : 'play'" :size="15" :class="{ spin: busy }" /> {{ busy ? (tab === 'trace' ? 'Rastreando…' : 'Executando…') : 'Executar' }}
          </button>
        </div>
      </form>
      <p v-if="error" class="err-box" role="alert">{{ error }}</p>
    </section>

    <!-- ================================================================ PING -->
    <section v-if="tab === 'ping' && ping" class="panel">
      <div class="panel-head"><h2>{{ ping.host }} <span v-if="ping.ip !== ping.host" class="muted mono">({{ ping.ip }})</span></h2>
        <span class="pill" :class="ping.loss === 0 ? 'pill-ok' : ping.loss === 100 ? 'pill-bad' : 'pill-warn'">{{ ping.loss }}% de perda</span></div>
      <div class="stats">
        <div><small>Enviados / recebidos</small><strong>{{ ping.sent }} / {{ ping.received }}</strong></div>
        <template v-if="ping.rtt">
          <div><small>Mínimo</small><strong>{{ ping.rtt.min }} ms</strong></div>
          <div><small>Médio</small><strong>{{ ping.rtt.avg }} ms</strong></div>
          <div><small>Máximo</small><strong>{{ ping.rtt.max }} ms</strong></div>
          <div><small>Jitter (mdev)</small><strong>{{ ping.rtt.mdev }} ms</strong></div>
        </template>
      </div>
      <div class="bars">
        <div v-for="r in ping.replies" :key="r.seq" class="bar-row">
          <span class="muted mono">#{{ r.seq }}</span>
          <span class="bar"><span v-if="r.ok" :style="{ width: `${Math.max(2, (r.ms! / maxMs) * 100)}%` }" /></span>
          <span class="mono">{{ r.ok ? `${r.ms} ms · ttl ${r.ttl}` : r.text }}</span>
        </div>
      </div>
      <p v-if="ping.replies[0]?.ttl" class="muted small pad">TTL {{ ping.replies[0].ttl }} → {{ ping.replies[0].ttl! > 128 ? 'equipamento de rede/Unix (255)' : ping.replies[0].ttl! > 64 ? 'provável Windows (128)' : 'provável Linux/Unix (64)' }}, a {{ (ping.replies[0].ttl! > 128 ? 255 : ping.replies[0].ttl! > 64 ? 128 : 64) - ping.replies[0].ttl! }} salto(s).</p>
      <p class="pad"><button class="link-btn" type="button" @click="showRaw = !showRaw">{{ showRaw ? 'Ocultar' : 'Ver' }} saída bruta</button></p>
      <pre v-if="showRaw" class="raw">{{ ping.raw }}</pre>
    </section>

    <!-- ================================================================ TRACE -->
    <section v-if="tab === 'trace' && trace" class="panel">
      <div class="panel-head"><h2>Rota até {{ trace.host }}</h2><span class="muted small">{{ trace.tool }} · {{ trace.cycles }} ciclo(s) · {{ trace.hops.length }} salto(s)</span></div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th class="num">#</th><th>Host</th><th class="num">Perda</th><th class="num">Env.</th><th class="num">Último</th><th class="num">Médio</th><th class="num">Melhor</th><th class="num">Pior</th><th class="num">Desvio</th><th class="lat">Latência</th></tr></thead>
          <tbody>
            <tr v-for="h in trace.hops" :key="h.hop" :class="{ noreply: !h.ip }">
              <td class="num muted">{{ h.hop }}</td>
              <td><template v-if="h.ip"><span class="mono">{{ h.ip }}</span> <span v-if="h.name && h.name !== h.ip" class="muted small">{{ h.name }}</span></template><span v-else class="muted">* sem resposta</span></td>
              <td class="num"><span v-if="h.ip" :class="h.loss === 0 ? '' : h.loss >= 50 ? 'txt-bad' : 'txt-warn'">{{ h.loss.toFixed(0) }}%</span></td>
              <td class="num">{{ h.sent }}</td>
              <td class="num mono">{{ h.last ?? '—' }}</td>
              <td class="num mono strong">{{ h.avg ?? '—' }}</td>
              <td class="num mono">{{ h.best ?? '—' }}</td>
              <td class="num mono">{{ h.worst ?? '—' }}</td>
              <td class="num mono">{{ h.stdev ?? '—' }}</td>
              <td class="lat"><span v-if="h.avg !== null" class="bar"><span :style="{ width: `${Math.max(2, ((h.avg ?? 0) / traceMax) * 100)}%` }" /></span></td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="intermediateLossOnly" class="muted small pad"><AppIcon name="alert" :size="12" /> Perda só em saltos intermediários com 0% no destino é limitação de ICMP no roteador (rate-limit/CoPP), não perda real.</p>
    </section>

    <!-- ================================================================ PORTAS -->
    <section v-if="tab === 'ports' && ports" class="panel">
      <div class="panel-head"><h2>{{ ports.host }} <span v-if="ports.ip && ports.ip !== ports.host" class="muted mono">({{ ports.ip }})</span></h2>
        <span class="muted small">{{ ports.results.filter((r) => r.state === 'open').length }} aberta(s) de {{ ports.results.length }}</span></div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th class="num">Porta</th><th>Serviço</th><th>Estado</th><th class="num">Conexão</th><th>Detalhes</th></tr></thead>
          <tbody>
            <tr v-for="r in ports.results" :key="r.port">
              <td class="num mono strong">{{ r.port }}</td>
              <td>{{ r.name ?? '' }}</td>
              <td><span class="pill" :class="STATE[r.state]?.c">{{ STATE[r.state]?.l }}</span></td>
              <td class="num mono">{{ r.ms !== null ? `${r.ms} ms` : '—' }}</td>
              <td class="details">
                <span v-if="r.state !== 'open'" class="muted small">{{ r.error }}</span>
                <code v-if="r.banner" class="banner">{{ r.banner }}</code>
                <div v-if="r.tls && !r.tls.error" class="tls">
                  <span><AppIcon name="lock" :size="12" /> <strong>{{ r.tls.subject }}</strong> · {{ r.tls.protocol }} <span class="muted">{{ r.tls.cipher }}</span></span>
                  <span class="muted">emissor: {{ r.tls.issuer || '—' }}</span>
                  <span>
                    <span class="pill" :class="(r.tls.days_left ?? 0) < 0 ? 'pill-bad' : (r.tls.days_left ?? 0) < 30 ? 'pill-warn' : 'pill-ok'">
                      {{ (r.tls.days_left ?? 0) < 0 ? `vencido há ${-r.tls.days_left!} dias` : `vence em ${r.tls.days_left} dias` }}
                    </span>
                    <span class="muted small"> ({{ dateOnly(r.tls.valid_to) }})</span>
                    <span class="pill" :class="r.tls.trusted ? 'pill-ok' : 'pill-warn'" style="margin-left: 6px">{{ r.tls.trusted ? 'cadeia confiável' : r.tls.self_signed ? 'auto-assinado' : 'cadeia não confiável' }}</span>
                  </span>
                  <span v-if="r.tls.sans?.length" class="muted small">SAN: {{ r.tls.sans.join(', ') }}</span>
                </div>
                <span v-else-if="r.tls?.error" class="muted small">TLS: {{ r.tls.error }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="muted small pad"><strong>Filtrada</strong> = nenhuma resposta (DROP no firewall). <strong>Fechada</strong> = o host respondeu RST (alcançável, sem serviço). Teste a partir do servidor; o resultado de outra VLAN pode ser diferente.</p>
    </section>

    <!-- ================================================================ DNS -->
    <section v-if="tab === 'dns' && dns" class="panel">
      <div class="panel-head"><h2>{{ dns.name }} <span class="muted">{{ dns.type }}</span></h2>
        <span v-if="dns.results.length > 1" class="pill" :class="dns.divergent ? 'pill-warn' : 'pill-ok'">{{ dns.divergent ? 'respostas diferentes entre servidores' : 'todos concordam' }}</span></div>
      <div class="dns-grid" :style="{ gridTemplateColumns: `repeat(${Math.min(dns.results.length, 3)}, minmax(0, 1fr))` }">
        <div v-for="r in dns.results" :key="r.server" class="dns-col">
          <header><span class="mono strong">{{ r.server }}</span> <span class="pill" :class="statusPill(r.status)">{{ r.status }}</span>
            <span class="muted small">{{ r.ms !== null ? `${r.ms} ms` : '' }}<template v-if="r.flags.includes('aa')"> · autoritativo</template></span></header>
          <ul v-if="r.answers.length" class="rr">
            <li v-for="(a, i) in r.answers" :key="i"><span class="pill pill-muted">{{ a.type }}</span> <code class="data" @click="copy(a.data)">{{ a.data }}</code> <span class="muted small">TTL {{ a.ttl }}</span></li>
          </ul>
          <p v-else class="muted small">Sem resposta.<template v-if="r.authority[0]"> SOA: <code>{{ r.authority[0].data.split(' ')[0] }}</code> (TTL negativo {{ r.authority[0].ttl }})</template></p>
        </div>
      </div>
    </section>

    <!-- ================================================================ WHOIS -->
    <template v-if="tab === 'whois' && (rdap || asn)">
      <section v-if="asn" class="panel">
        <div class="panel-head"><h2>Roteamento (BGP)</h2><span class="muted small">Team Cymru via DNS</span></div>
        <p v-if="asn.special" class="pad">{{ asn.ip }}: <strong>{{ asn.special }}</strong> — não roteável na internet, sem ASN.</p>
        <p v-else-if="!asn.asn" class="pad muted">{{ asn.error }}</p>
        <dl v-else class="facts">
          <dt>IP</dt><dd class="mono">{{ asn.ip }}</dd>
          <dt>ASN de origem</dt><dd><span v-for="a in asn.asn" :key="a.number" class="asn"><strong class="mono">AS{{ a.number }}</strong> {{ a.name }}</span></dd>
          <dt>Prefixo anunciado</dt><dd class="mono">{{ asn.prefix }}</dd>
          <dt>País / RIR</dt><dd>{{ asn.country }} · {{ asn.registry?.toUpperCase() }}<template v-if="asn.allocated"> · alocado em {{ asn.allocated }}</template></dd>
        </dl>
      </section>
      <section v-if="rdap" class="panel">
        <div class="panel-head"><h2>Registro (RDAP)</h2><span v-if="rdap.source" class="muted small">fonte: {{ rdap.source }}</span></div>
        <p v-if="rdap.special" class="pad">{{ rdap.query }}: <strong>{{ rdap.special }}</strong> — sem registro público.</p>
        <p v-else-if="rdap.found === false" class="pad muted">Não encontrado no registro.</p>
        <template v-else>
          <dl class="facts">
            <dt>Nome</dt><dd class="mono">{{ rdap.name ?? rdap.handle }}</dd>
            <template v-if="rdap.range"><dt>Faixa</dt><dd class="mono">{{ rdap.range }}<template v-if="rdap.cidrs?.length"> ({{ rdap.cidrs.join(', ') }})</template></dd></template>
            <template v-if="rdap.type"><dt>Tipo</dt><dd>{{ rdap.type }}</dd></template>
            <template v-if="rdap.country"><dt>País</dt><dd>{{ rdap.country }}</dd></template>
            <template v-if="rdap.status?.length"><dt>Status</dt><dd>{{ rdap.status.join(', ') }}</dd></template>
            <template v-if="rdap.nameservers?.length"><dt>DNS</dt><dd class="mono">{{ rdap.nameservers.join(' · ') }}<template v-if="rdap.dnssec !== null"> <span class="pill" :class="rdap.dnssec ? 'pill-ok' : 'pill-muted'">{{ rdap.dnssec ? 'DNSSEC' : 'sem DNSSEC' }}</span></template></dd></template>
            <template v-if="rdap.registered"><dt>Registrado</dt><dd>{{ dateOnly(rdap.registered) }}</dd></template>
            <template v-if="rdap.changed"><dt>Alterado</dt><dd>{{ dateOnly(rdap.changed) }} <span class="muted small">({{ relative(rdap.changed) }})</span></dd></template>
            <template v-if="rdap.expires"><dt>Expira</dt><dd>{{ dateOnly(rdap.expires) }} <span class="muted small">({{ relative(rdap.expires) }})</span></dd></template>
          </dl>
          <div v-if="rdap.entities?.length" class="table-wrap">
            <table class="table">
              <thead><tr><th>Papel</th><th>Nome</th><th>Identificador</th><th>E-mail</th></tr></thead>
              <tbody><tr v-for="(e, i) in rdap.entities" :key="i"><td>{{ e.roles.map((r) => ROLE[r] ?? r).join(', ') }}</td><td>{{ e.name ?? e.org ?? '—' }}</td><td class="mono small">{{ e.handle }}</td><td class="small">{{ e.email ?? '' }}</td></tr></tbody>
            </table>
          </div>
          <p class="pad"><button class="link-btn" type="button" @click="showRdapRaw = !showRdapRaw">{{ showRdapRaw ? 'Ocultar' : 'Ver' }} JSON completo</button></p>
          <pre v-if="showRdapRaw" class="raw">{{ rdap.raw }}</pre>
        </template>
      </section>
      <p v-else-if="rdapError" class="err-box">{{ rdapError }}</p>
    </template>

    <!-- ================================================================ MAC -->
    <section v-if="tab === 'mac' && macs.length" class="panel">
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>MAC</th><th>Fabricante</th><th>Observações</th><th>No inventário</th><th>Visto em varreduras</th></tr></thead>
          <tbody>
            <tr v-for="(m, i) in macs" :key="i">
              <td class="mono"><template v-if="m.valid">{{ m.mac ?? m.oui }}<div class="muted small">OUI {{ m.oui }}</div></template><span v-else class="txt-bad">{{ m.input }} (inválido)</span></td>
              <td>{{ m.vendor ?? (m.valid ? 'não registrado' : '') }}</td>
              <td><span v-for="f in m.flags" :key="f" class="pill pill-muted flag">{{ f }}</span></td>
              <td><RouterLink v-for="a in m.assets" :key="a.uuid" :to="`/assets/${a.uuid}`" class="block">{{ a.name }}<span v-if="a.port" class="muted small"> · {{ a.port }}</span></RouterLink><span v-if="m.valid && m.mac && !m.assets.length" class="muted">—</span></td>
              <td><RouterLink v-for="s in m.scans" :key="s.scan_id" :to="{ path: '/tools/scanner', query: { scan: String(s.scan_id) } }" class="block small"><span class="mono">{{ s.ip }}</span> <span class="muted">{{ relative(s.at) }}</span></RouterLink></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 18px; max-width: 1480px; }
.page-head p { margin: 4px 0 0; font-size: 13px; max-width: 860px; }
.small { font-size: 12px; }
.strong { font-weight: 600; }
.entry { display: flex; gap: 14px; padding: 20px; flex-wrap: wrap; align-items: flex-start; }
.grow { flex: 1; min-width: 260px; }
.full { flex-basis: 100%; }
.big { font-size: 16px; height: 44px; }
.narrow { width: 110px; }
.go { align-self: flex-start; padding-top: 22px; }
.big-btn { height: 44px; padding: 0 20px; }
.chips { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; margin-top: 4px; }
.custom { width: 220px; height: 32px; }
.spin { animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.notice { display: flex; gap: 8px; align-items: center; padding: 10px 14px; border-radius: var(--radius-sm); background: var(--warn-soft); color: var(--warn-text); margin: 0; font-size: 13px; }
.err-box { margin: 0 20px 20px; padding: 10px 14px; border-radius: var(--radius-sm); background: var(--bad-soft); color: var(--bad-text); font-size: 13px; }
.panel-head { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
.panel-head h2 { margin-right: auto; }
.stats { display: flex; gap: 28px; flex-wrap: wrap; padding: 0 20px 14px; }
.stats small { display: block; color: var(--text-3); font-size: 12px; }
.stats strong { font-size: 20px; font-weight: 650; }
.bars { padding: 0 20px 10px; display: flex; flex-direction: column; gap: 4px; font-size: 12.5px; }
.bar-row { display: grid; grid-template-columns: 40px minmax(120px, 1fr) 160px; gap: 10px; align-items: center; }
.bar { display: block; height: 8px; border-radius: 4px; background: var(--muted-soft); overflow: hidden; min-width: 60px; }
.bar > span { display: block; height: 100%; background: var(--accent); border-radius: 4px; }
.lat { width: 18%; }
.pad { padding: 0 20px 14px; margin: 0; }
.raw { margin: 0 20px 20px; padding: 12px; background: var(--bg-elev); border-radius: var(--radius-sm); font-size: 12px; max-height: 420px; overflow: auto; white-space: pre-wrap; }
tr.noreply td { color: var(--text-3); }
.txt-bad { color: var(--bad-text); }
.txt-warn { color: var(--warn-text); }
.details { max-width: 640px; }
.banner { display: inline-block; font-size: 12px; padding: 2px 6px; border-radius: 4px; background: var(--bg-elev); overflow-wrap: anywhere; }
.tls { display: flex; flex-direction: column; gap: 3px; font-size: 12.5px; }
.dns-grid { display: grid; gap: 12px; padding: 0 20px 20px; }
.dns-col { border: 1px solid var(--border-soft); border-radius: var(--radius-sm); padding: 12px; min-width: 0; }
.dns-col header { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 8px; }
.rr { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 5px; font-size: 13px; }
.data { cursor: copy; overflow-wrap: anywhere; }
.facts { display: grid; grid-template-columns: max-content 1fr; gap: 8px 18px; margin: 0; padding: 0 20px 16px; font-size: 13px; }
.facts dt { color: var(--text-3); }
.facts dd { margin: 0; overflow-wrap: anywhere; }
.asn { display: block; }
.flag { margin: 0 4px 4px 0; white-space: normal; }
.block { display: block; }
@media (max-width: 900px) { .dns-grid { grid-template-columns: 1fr !important; } }
</style>
