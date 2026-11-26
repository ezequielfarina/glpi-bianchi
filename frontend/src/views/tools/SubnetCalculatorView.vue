<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import { notify } from '../../lib/toast'
import {
  calc, fmt, info, maskOf, neighbor, parseInput, prefixForHosts, reverseZone, split, toBin, toBinDotted, toHex, toIp, type Subnet,
} from '../../lib/subnet'

const route = useRoute()
const router = useRouter()
const input = ref(typeof route.query.ip === 'string' ? route.query.ip : '192.168.110.55/24')
const prefixSel = ref<number | null>(null)
const parsed = computed(() => parseInput(input.value))
const prefix = computed(() => parsed.value?.prefix ?? 24)
const s = computed<Subnet | null>(() => (parsed.value ? calc(parsed.value.ip, parsed.value.prefix) : null))
const ipInfo = computed(() => (s.value ? info(s.value.ip) : null))
const hasMask = computed(() => /[/ ]/.test(input.value.trim()))

// o seletor de máscara reescreve a entrada (mantém o IP)
watch(prefix, (p) => { prefixSel.value = p }, { immediate: true })
function setPrefix(p: number): void {
  if (!s.value) return
  input.value = `${toIp(s.value.ip)}/${p}`
}
watch(input, (v) => { void router.replace({ query: v.trim() ? { ip: v.trim() } : {} }) })

// precisa de N hosts?
const hostsNeeded = ref<number | null>(null)
const neededPrefix = computed(() => (hostsNeeded.value && hostsNeeded.value > 0 ? prefixForHosts(hostsNeeded.value) : null))

// divisão em sub-redes
const splitPrefix = ref<number>(26)
watch(prefix, (p) => { if (splitPrefix.value <= p) splitPrefix.value = Math.min(32, p + 2) }, { immediate: true })
const splitOptions = computed(() => Array.from({ length: Math.min(32, prefix.value + 12) - prefix.value }, (_, i) => prefix.value + i + 1))
const parts = computed(() => (s.value ? split(s.value, splitPrefix.value, 1024) : { items: [], count: 0 }))
const showAll = ref(false)
const visibleParts = computed(() => (showAll.value ? parts.value.items : parts.value.items.slice(0, 64)))

const prev = computed(() => (s.value ? neighbor(s.value, -1) : null))
const next = computed(() => (s.value ? neighbor(s.value, 1) : null))
const go = (x: Subnet) => { input.value = `${toIp(x.network)}/${x.prefix}` }

// binário com bits de rede destacados
const bits = (n: number) => toBin(n).split('')
const binRows = computed(() => (s.value ? [
  { label: 'Endereço', value: s.value.ip },
  { label: 'Máscara', value: s.value.mask },
  { label: 'Wildcard', value: s.value.wildcard },
  { label: 'Rede', value: s.value.network },
  { label: 'Broadcast', value: s.value.broadcast },
] : []))

// trechos de configuração prontos
const snippets = computed(() => {
  if (!s.value) return []
  const net = toIp(s.value.network)
  const mask = toIp(s.value.mask)
  const cidr = `${net}/${s.value.prefix}`
  const gw = s.value.firstHost !== null ? toIp(s.value.firstHost) : net
  const first = s.value.prefix <= 30 ? toIp(s.value.network + 2) : gw
  const last = s.value.lastHost !== null ? toIp(s.value.lastHost) : net
  return [
    { title: 'FortiGate (FortiOS)', code: `config firewall address\n    edit "NET_${net.replace(/\./g, '_')}_${s.value.prefix}"\n        set subnet ${net} ${mask}\n        set comment "GLPI Bianchi"\n    next\nend` },
    { title: 'MikroTik (RouterOS)', code: `/ip address add address=${gw}/${s.value.prefix} interface=bridge comment="gateway ${cidr}"\n/ip pool add name=pool-${net} ranges=${first}-${last}\n/ip dhcp-server network add address=${cidr} gateway=${gw} dns-server=${gw}` },
    { title: 'Linux (iproute2)', code: `ip addr add ${gw}/${s.value.prefix} dev eth0\nip route add ${cidr} via ${gw}   # em outro host` },
    { title: 'Windows (PowerShell)', code: `New-NetIPAddress -InterfaceAlias "Ethernet" -IPAddress ${first} -PrefixLength ${s.value.prefix} -DefaultGateway ${gw}\nNew-NetRoute -DestinationPrefix ${cidr} -NextHop ${gw} -InterfaceAlias "Ethernet"   # rota estática` },
    { title: 'Cisco IOS / ACL', code: `ip route ${net} ${mask} <next-hop>\naccess-list 10 permit ${net} ${toIp(s.value.wildcard)}` },
  ]
})

async function copy(text: string): Promise<void> {
  try { await navigator.clipboard.writeText(text); notify('ok', 'Copiado', text.length > 60 ? undefined : text) } catch { /* sem clipboard */ }
}
const masks = Array.from({ length: 33 }, (_, p) => ({ p, mask: toIp(maskOf(p)), hosts: calc(0, p).usable }))
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>Calculadora de sub-rede</h1>
        <p class="muted">IPv4: rede, broadcast, faixa utilizável, máscara, binário, divisão em sub-redes e configuração pronta. Calculado no navegador.</p>
      </div>
    </header>

    <section class="panel">
      <div class="entry">
        <div class="field grow">
          <label for="c-in">Endereço IP (com /prefixo ou máscara)</label>
          <input id="c-in" v-model="input" class="input mono big" placeholder="192.168.10.25/24 ou 192.168.10.25 255.255.255.0" spellcheck="false" autocomplete="off" :aria-invalid="!parsed && !!input.trim()" aria-describedby="c-in-hint" />
          <span v-if="!parsed && input.trim()" class="err">Formato inválido. Ex.: 10.20.5.130/26 ou 10.20.5.130 255.255.255.192</span>
          <span v-else-if="parsed && !hasMask" id="c-in-hint" class="hint">Sem máscara informada: usando a da classe (/{{ prefix }}).</span>
        </div>
        <div class="field">
          <label for="c-mask">Máscara</label>
          <select id="c-mask" class="input mono big" :value="prefixSel ?? 24" :disabled="!s" @change="setPrefix(Number(($event.target as HTMLSelectElement).value))">
            <option v-for="m in masks" :key="m.p" :value="m.p">/{{ m.p }} — {{ m.mask }} ({{ fmt(m.hosts) }} host{{ m.hosts === 1 ? '' : 's' }})</option>
          </select>
        </div>
        <div class="field">
          <label for="c-need">Preciso de quantos hosts?</label>
          <input id="c-need" v-model.number="hostsNeeded" class="input mono big need" type="number" min="1" placeholder="ex.: 120" />
          <span v-if="neededPrefix !== null" class="hint">
            Use <button class="link-btn" type="button" @click="setPrefix(neededPrefix!)">/{{ neededPrefix }}</button>
            ({{ fmt(calc(0, neededPrefix).usable) }} utilizáveis)
          </span>
        </div>
      </div>
    </section>

    <template v-if="s && ipInfo">
      <section class="cards">
        <button class="card panel hero" type="button" title="Copiar" @click="copy(`${toIp(s.network)}/${s.prefix}`)">
          <span class="lbl">Rede</span><strong class="mono">{{ toIp(s.network) }}/{{ s.prefix }}</strong>
          <span class="sub">{{ toIp(s.mask) }}</span>
        </button>
        <button class="card panel" type="button" title="Copiar" @click="copy(toIp(s.broadcast))">
          <span class="lbl">Broadcast (último endereço)</span><strong class="mono">{{ toIp(s.broadcast) }}</strong>
          <span class="sub">{{ s.prefix >= 31 ? 'sem broadcast (/31 e /32)' : 'não utilizável por host' }}</span>
        </button>
        <button class="card panel" type="button" title="Copiar" @click="copy(`${toIp(s.firstHost!)}-${toIp(s.lastHost!)}`)">
          <span class="lbl">Faixa utilizável</span><strong class="mono range">{{ toIp(s.firstHost!) }}<span class="muted"> → </span>{{ toIp(s.lastHost!) }}</strong>
          <span class="sub">gateway costuma ser {{ toIp(s.firstHost!) }}</span>
        </button>
        <div class="card panel">
          <span class="lbl">Hosts utilizáveis</span><strong>{{ fmt(s.usable) }}</strong>
          <span class="sub">de {{ fmt(s.total) }} endereços<template v-if="s.prefix === 31"> (RFC 3021, ponto a ponto)</template></span>
        </div>
      </section>

      <div class="grid">
        <section class="panel">
          <div class="panel-head"><h2>Informações do IP</h2></div>
          <dl class="facts">
            <dt>Endereço</dt><dd class="mono">{{ toIp(s.ip) }} <span v-if="s.ip === s.network && s.prefix < 31" class="pill pill-warn">é o endereço de rede</span><span v-else-if="s.ip === s.broadcast && s.prefix < 31" class="pill pill-warn">é o broadcast</span></dd>
            <dt>Tipo</dt><dd><span class="pill" :class="{ 'pill-ok': ipInfo.scope === 'privado', 'pill-accent': ipInfo.scope === 'público', 'pill-warn': ipInfo.scope === 'especial' }">{{ ipInfo.scope }}</span> {{ ipInfo.kind }}<span v-if="ipInfo.rfc" class="muted"> · {{ ipInfo.rfc }}</span></dd>
            <dt>Classe</dt><dd>{{ ipInfo.klass }}</dd>
            <dt>Máscara</dt><dd class="mono">{{ toIp(s.mask) }} · /{{ s.prefix }}</dd>
            <dt>Wildcard (ACL)</dt><dd class="mono">{{ toIp(s.wildcard) }}</dd>
            <dt>Posição na rede</dt><dd>{{ s.prefix >= 31 ? '—' : `host nº ${fmt(s.ip - s.network)} de ${fmt(s.usable)}` }}</dd>
            <dt>Decimal</dt><dd class="mono">{{ s.ip }}</dd>
            <dt>Hexadecimal</dt><dd class="mono">{{ toHex(s.ip) }}</dd>
            <dt>DNS reverso</dt><dd class="mono small">{{ reverseZone(s) }}</dd>
            <dt>Redes vizinhas</dt>
            <dd class="neigh">
              <button v-if="prev" class="btn btn-sm" type="button" @click="go(prev)"><AppIcon name="back" :size="12" /> {{ toIp(prev.network) }}/{{ prev.prefix }}</button>
              <button v-if="next" class="btn btn-sm" type="button" @click="go(next)">{{ toIp(next.network) }}/{{ next.prefix }} <AppIcon name="chevron" :size="12" /></button>
            </dd>
          </dl>
        </section>

        <section class="panel">
          <div class="panel-head"><h2>Em binário</h2><span class="legend small"><i class="b net" /> rede ({{ s.prefix }} bits) <i class="b host" /> host ({{ 32 - s.prefix }} bits)</span></div>
          <div class="bin" role="table" aria-label="Endereços em binário">
            <div v-for="r in binRows" :key="r.label" class="bin-row" role="row">
              <span class="bin-lbl" role="rowheader">{{ r.label }}</span>
              <span class="bits mono" role="cell" :aria-label="toBinDotted(r.value)">
                <template v-for="(b, i) in bits(r.value)" :key="i"><span class="bit" :class="i < s.prefix ? 'net' : 'host'">{{ b }}</span><span v-if="i % 8 === 7 && i < 31" class="dot">.</span></template>
              </span>
              <span class="bin-dec mono muted">{{ toIp(r.value) }}</span>
            </div>
          </div>
        </section>
      </div>

      <section class="panel">
        <div class="panel-head">
          <h2>Dividir em sub-redes</h2>
          <div class="split-ctl">
            <label for="c-split" class="small muted">Tamanho de cada parte</label>
            <select id="c-split" v-model.number="splitPrefix" class="input mono">
              <option v-for="p in splitOptions" :key="p" :value="p">/{{ p }} — {{ fmt(calc(0, p).usable) }} hosts</option>
            </select>
          </div>
        </div>
        <p class="muted small split-sum">
          {{ fmt(parts.count) }} sub-rede(s) /{{ splitPrefix }} dentro de {{ toIp(s.network) }}/{{ s.prefix }}, cada uma com {{ fmt(calc(0, splitPrefix).usable) }} hosts utilizáveis.
          <template v-if="parts.count > parts.items.length"> Mostrando as primeiras {{ fmt(parts.items.length) }}.</template>
        </p>
        <div v-if="parts.items.length" class="table-wrap">
          <table class="table">
            <thead><tr><th class="num">#</th><th>Sub-rede</th><th>Primeiro host</th><th>Último host</th><th>Broadcast</th><th class="num">Hosts</th><th /></tr></thead>
            <tbody>
              <tr v-for="(x, i) in visibleParts" :key="x.network" :class="{ mine: s.ip >= x.network && s.ip <= x.broadcast }">
                <td class="num muted">{{ i + 1 }}</td>
                <td class="mono">{{ toIp(x.network) }}/{{ x.prefix }}<span v-if="s.ip >= x.network && s.ip <= x.broadcast" class="pill pill-accent" style="margin-left: 6px">seu IP</span></td>
                <td class="mono">{{ toIp(x.firstHost!) }}</td>
                <td class="mono">{{ toIp(x.lastHost!) }}</td>
                <td class="mono muted">{{ toIp(x.broadcast) }}</td>
                <td class="num">{{ fmt(x.usable) }}</td>
                <td class="num"><button class="btn btn-sm" type="button" @click="go(x)">Abrir</button></td>
              </tr>
            </tbody>
          </table>
          <p v-if="parts.items.length > 64 && !showAll" class="more"><button class="btn" type="button" @click="showAll = true">Mostrar todas ({{ fmt(parts.items.length) }})</button></p>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head"><h2>Configuração pronta</h2><span class="muted small">gateway = primeiro host · ajuste interface e nomes</span></div>
        <div class="snips">
          <div v-for="sn in snippets" :key="sn.title" class="snip">
            <div class="snip-head"><strong>{{ sn.title }}</strong><button class="btn btn-sm" type="button" @click="copy(sn.code)"><AppIcon name="copy" :size="12" /> Copiar</button></div>
            <pre class="mono">{{ sn.code }}</pre>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 18px; max-width: 1480px; }
.page-head p { margin: 4px 0 0; font-size: 13px; }
.small { font-size: 12px; }
.entry { display: flex; gap: 14px; padding: 20px; flex-wrap: wrap; align-items: flex-start; }
.field { display: flex; flex-direction: column; gap: 6px; }
.field label { font-size: 12.5px; color: var(--text-2); }
.grow { flex: 1; min-width: 280px; }
.big { font-size: 17px; height: 46px; }
.need { width: 200px; }
.cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; }
.card { display: flex; flex-direction: column; gap: 4px; padding: 16px 18px; text-align: left; font: inherit; color: inherit; cursor: default; }
button.card { cursor: copy; }
button.card:hover { border-color: var(--accent); }
.card .lbl { font-size: 12px; color: var(--text-3); text-transform: uppercase; letter-spacing: .04em; }
.card strong { font-size: 22px; font-weight: 650; overflow-wrap: anywhere; }
.card .range { font-size: 18px; }
.card .sub { font-size: 12px; color: var(--text-3); }
.card.hero { border-color: var(--accent); background: var(--accent-soft); }
.grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.45fr); gap: 16px; align-items: start; }
.facts { display: grid; grid-template-columns: max-content 1fr; gap: 8px 18px; margin: 0; padding: 0 20px 20px; font-size: 13px; }
.facts dt { color: var(--text-3); }
.facts dd { margin: 0; overflow-wrap: anywhere; }
.neigh { display: flex; gap: 6px; flex-wrap: wrap; }
.btn-sm { padding: 4px 10px; min-height: 0; font-size: 12.5px; }
.legend { display: inline-flex; gap: 6px; align-items: center; color: var(--text-3); }
.b { width: 10px; height: 10px; border-radius: 3px; display: inline-block; margin-left: 8px; }
.b.net, .bit.net { background: var(--accent-soft); color: var(--accent-text); }
.b.host, .bit.host { background: var(--warn-soft); color: var(--warn-text); }
.bin { padding: 0 20px 20px; display: flex; flex-direction: column; gap: 8px; overflow-x: auto; }
.bin-row { display: grid; grid-template-columns: 68px auto minmax(110px, 1fr); gap: 12px; align-items: center; }
.bin-lbl { font-size: 12.5px; color: var(--text-3); }
.bits { display: inline-flex; font-size: 13px; }
.bit { width: 12px; text-align: center; border-radius: 2px; line-height: 20px; }
.dot { width: 8px; text-align: center; color: var(--text-3); }
.bin-dec { font-size: 12.5px; }
.split-ctl { display: flex; gap: 8px; align-items: center; }
.split-sum { padding: 0 20px; margin: 0 0 8px; }
tr.mine td { background: var(--accent-soft); }
.more { text-align: center; padding: 10px; margin: 0; }
.snips { display: grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); gap: 12px; padding: 0 20px 20px; }
.snip { border: 1px solid var(--border-soft); border-radius: var(--radius-sm); overflow: hidden; }
.snip-head { display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: var(--bg-elev); font-size: 13px; }
.snip pre { margin: 0; padding: 10px 12px; font-size: 12px; white-space: pre-wrap; overflow-wrap: anywhere; }
@media (max-width: 1100px) { .grid { grid-template-columns: 1fr; } }
</style>
