<script setup lang="ts">
import { computed, ref } from 'vue'
import AppIcon, { type IconName } from '../components/AppIcon.vue'
import LoadState from '../components/LoadState.vue'
import StatusPill from '../components/StatusPill.vue'
import { api } from '../lib/api'
import { bytes, dateTime, fieldLabel, kindLabel, mib, num, relative } from '../lib/format'
import type { Component } from '../lib/types'
import { useAsync } from '../lib/useAsync'

const props = defineProps<{ uuid: string }>()
const { data: a, loading, error, reload } = useAsync(() => api.asset(props.uuid))

type Tab = 'hardware' | 'software' | 'network' | 'history' | 'inventories'
const tab = ref<Tab>('hardware')
const swFilter = ref('')
const showVirtual = ref(false)

const tabs = computed<{ id: Tab; label: string; icon: IconName; count?: number }[]>(() => [
  { id: 'hardware', label: 'Hardware', icon: 'cpu', count: a.value?.components.length },
  { id: 'software', label: 'Software', icon: 'software', count: a.value?.software.length },
  { id: 'network', label: 'Rede', icon: 'network', count: a.value?.networkPorts.length },
  { id: 'history', label: 'Histórico', icon: 'history', count: a.value?.history.length },
  { id: 'inventories', label: 'Inventários', icon: 'inbox', count: a.value?.inventories.length },
])

const groups = computed(() => {
  const map = new Map<string, Component[]>()
  for (const c of a.value?.components ?? []) map.set(c.kind, [...(map.get(c.kind) ?? []), c])
  return [...map.entries()]
})

const ramTotal = computed(() => (a.value?.components ?? []).filter((c) => c.kind === 'memory').reduce((s, c) => s + (c.capacity_mib ?? 0), 0))
const diskTotal = computed(() => (a.value?.components ?? []).filter((c) => c.kind === 'storage').reduce((s, c) => s + (c.capacity_mib ?? 0), 0))
const cpu = computed(() => a.value?.components.find((c) => c.kind === 'processor'))

const software = computed(() => {
  const f = swFilter.value.trim().toLowerCase()
  const list = a.value?.software ?? []
  return f ? list.filter((s) => `${s.name} ${s.publisher ?? ''} ${s.version}`.toLowerCase().includes(f)) : list
})

// Adaptadores virtuais conhecidos (VMware, VirtualBox, FortiClient, Hyper-V, Bluetooth PAN)
const VIRTUAL_HINT = /vmware|virtualbox|vethernet|hyper-v|fortinet|forticlient|bluetooth|loopback|tap-|wan miniport/i
const VIRTUAL_OUI = ['00:50:56', '00:0c:29', '0a:00:27', '00:09:0f', '00:15:5d']
const isVirtual = (p: { name: string; mac_address: string | null; virtual: boolean }) =>
  p.virtual || VIRTUAL_HINT.test(p.name) || VIRTUAL_OUI.some((o) => p.mac_address?.startsWith(o))
const ports = computed(() => (a.value?.networkPorts ?? []).filter((p) => showVirtual.value || !isVirtual(p)))
const hiddenPorts = computed(() => (a.value?.networkPorts.length ?? 0) - ports.value.length)

const ATTR_LABELS: Record<string, string> = {
  last_logged_user: 'Último usuário logado',
  last_logged_user_at: 'Último logon',
  chassis_type: 'Chassi',
  workgroup: 'Domínio / grupo de trabalho',
  bios_version: 'Versão da BIOS',
  bios_date: 'Data da BIOS',
  bios_manufacturer: 'Fabricante da BIOS',
  motherboard_model: 'Placa-mãe',
  motherboard_serial: 'Serial da placa-mãe',
  windows_owner: 'Proprietário (Windows)',
  windows_company: 'Empresa (Windows)',
  windows_product_id: 'Product ID',
  asset_tag: 'Asset tag',
  sku: 'SKU',
  secure_boot: 'Secure Boot',
  vm_system: 'Virtualização',
  swap_mib: 'Swap',
}
const attrs = computed(() =>
  Object.entries(a.value?.attributes ?? {})
    .filter(([k, v]) => k !== 'memory_mib' && v !== null && v !== '')
    .map(([k, v]) => ({ label: ATTR_LABELS[k] ?? k.replace(/_/g, ' '), value: k === 'swap_mib' ? mib(Number(v)) : String(v) })),
)

async function copy(text: string | null): Promise<void> {
  if (text) try { await navigator.clipboard.writeText(text) } catch { /* sem permissão */ }
}

function compDetail(c: Component): string {
  const at = c.attributes
  const parts: string[] = []
  if (c.kind === 'processor') {
    if (at.cores) parts.push(`${at.cores} núcleos`)
    if (at.threads) parts.push(`${at.threads} threads`)
    if (c.frequency_mhz) parts.push(`${(c.frequency_mhz / 1000).toFixed(2)} GHz`)
  } else {
    if (c.capacity_mib) parts.push(mib(c.capacity_mib))
    if (c.frequency_mhz) parts.push(`${c.frequency_mhz} MHz`)
    if (at.interface) parts.push(String(at.interface))
    if (at.type && c.kind !== 'memory') parts.push(String(at.type))
  }
  return parts.join(' · ')
}

const payload = ref<{ id: number; text: string } | null>(null)
async function showPayload(id: number): Promise<void> {
  const json = await api.payload(id)
  payload.value = { id, text: JSON.stringify(json, null, 2) }
}
</script>

<template>
  <div class="page">
    <RouterLink to="/assets" class="back"><AppIcon name="back" :size="16" /> Ativos</RouterLink>

    <LoadState :loading="loading" :error="error" :rows="8" @retry="reload">
      <template v-if="a">
        <header class="hero panel">
          <div class="hero-main">
            <div class="title-row">
              <h1>{{ a.name }}</h1>
              <span class="tag">{{ a.type_label }}</span>
              <span v-if="a.dynamic" class="pill pill-accent">inventário automático</span>
              <span v-else class="pill pill-muted">cadastro manual</span>
            </div>
            <p class="muted">
              {{ [a.manufacturer, a.model].filter(Boolean).join(' · ') || 'Fabricante não informado' }}
              · {{ a.organization }}<template v-if="a.location"> · {{ a.location }}</template>
            </p>
          </div>
          <dl class="facts">
            <div><dt>Sistema operacional</dt><dd>{{ a.os_full_name ?? a.os_name ?? '—' }} <span class="muted">{{ a.os_version }}</span></dd></div>
            <div><dt>Processador</dt><dd>{{ cpu?.designation ?? '—' }}</dd></div>
            <div><dt>Memória</dt><dd class="num">{{ mib(ramTotal || Number(a.attributes.memory_mib) || null) }}</dd></div>
            <div><dt>Armazenamento</dt><dd class="num">{{ diskTotal ? mib(diskTotal) : '—' }}</dd></div>
            <div><dt>Número de série</dt><dd class="mono">{{ a.serial_number ?? '—' }}</dd></div>
            <div><dt>Patrimônio</dt><dd class="mono">{{ a.inventory_number ?? '—' }}</dd></div>
            <div>
              <dt>UUID de hardware</dt>
              <dd class="mono uuid">
                {{ a.hardware_uuid ?? '—' }}
                <button v-if="a.hardware_uuid" class="icon-btn" type="button" aria-label="Copiar UUID" @click="copy(a.hardware_uuid)"><AppIcon name="copy" :size="14" /></button>
              </dd>
            </div>
            <div><dt>Último inventário</dt><dd>{{ relative(a.last_inventory_at) }} <span class="muted">({{ dateTime(a.last_inventory_at) }})</span></dd></div>
          </dl>
        </header>

        <div class="tabs" role="tablist" aria-label="Detalhes do ativo">
          <button
            v-for="t in tabs"
            :id="`tab-${t.id}`"
            :key="t.id"
            role="tab"
            type="button"
            class="tab"
            :class="{ active: tab === t.id }"
            :aria-selected="tab === t.id"
            :aria-controls="`panel-${t.id}`"
            @click="tab = t.id"
          >
            <AppIcon :name="t.icon" :size="16" /> {{ t.label }}
            <span v-if="t.count !== undefined" class="count">{{ t.count }}</span>
          </button>
        </div>

        <!-- HARDWARE -->
        <section v-if="tab === 'hardware'" id="panel-hardware" role="tabpanel" aria-labelledby="tab-hardware" class="hw">
          <div class="panel">
            <div class="panel-head"><h2>Componentes</h2></div>
            <div v-if="!groups.length" class="empty">Nenhum componente.</div>
            <div v-for="[kind, items] in groups" :key="kind" class="group">
              <h3>{{ kindLabel(kind) }} <span class="muted">({{ items.length }})</span></h3>
              <ul>
                <li v-for="(c, i) in items" :key="i">
                  <div>
                    <strong>{{ c.designation }}</strong>
                    <div class="muted small">{{ compDetail(c) }}</div>
                  </div>
                  <div class="right muted small">
                    <div v-if="c.manufacturer">{{ c.manufacturer }}</div>
                    <div v-if="c.serial_number" class="mono">S/N {{ c.serial_number }}</div>
                    <div v-if="c.slot" class="mono">{{ c.slot }}</div>
                  </div>
                </li>
              </ul>
            </div>
          </div>
          <div class="panel">
            <div class="panel-head"><h2>Informações adicionais</h2></div>
            <dl class="kv">
              <template v-for="item in attrs" :key="item.label">
                <dt>{{ item.label }}</dt><dd>{{ item.value }}</dd>
              </template>
              <template v-if="a.os_kernel_version"><dt>Kernel / build</dt><dd class="mono">{{ a.os_kernel_version }}</dd></template>
              <template v-if="a.os_architecture"><dt>Arquitetura</dt><dd>{{ a.os_architecture }}</dd></template>
              <template v-if="a.os_installed_at"><dt>SO instalado em</dt><dd>{{ dateTime(a.os_installed_at) }}</dd></template>
              <template v-if="a.agents[0]"><dt>Agente</dt><dd>{{ a.agents[0].version?.replace('GLPI-Agent_', 'GLPI Agent ') ?? '—' }} · <span class="mono">{{ a.agents[0].remote_ip ?? '—' }}</span></dd></template>
              <dt>Cadastrado em</dt><dd>{{ dateTime(a.created_at) }}</dd>
            </dl>
          </div>
        </section>

        <!-- SOFTWARE -->
        <section v-else-if="tab === 'software'" id="panel-software" role="tabpanel" aria-labelledby="tab-software" class="panel">
          <div class="panel-head">
            <h2>Software instalado <span class="muted">({{ num(software.length) }})</span></h2>
            <label class="sr-only" for="sw-filter">Filtrar software</label>
            <input id="sw-filter" v-model="swFilter" class="input" type="search" placeholder="Filtrar por nome, editor ou versão" style="width: min(340px, 100%)" />
          </div>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>Nome</th><th>Versão</th><th>Editor</th><th>Arquitetura</th><th>Instalado em</th></tr></thead>
              <tbody>
                <tr v-for="(s, i) in software" :key="i">
                  <td>{{ s.name }}</td>
                  <td class="mono muted">{{ s.version || '—' }}</td>
                  <td class="muted">{{ s.publisher ?? '—' }}</td>
                  <td class="muted">{{ s.architecture || '—' }}</td>
                  <td class="muted">{{ s.installed_at ? dateTime(s.installed_at).split(' ')[0] : '—' }}</td>
                </tr>
              </tbody>
            </table>
            <div v-if="!software.length" class="empty">Nenhum software encontrado.</div>
          </div>
        </section>

        <!-- REDE -->
        <section v-else-if="tab === 'network'" id="panel-network" role="tabpanel" aria-labelledby="tab-network" class="panel">
          <div class="panel-head">
            <h2>Interfaces de rede</h2>
            <label class="check">
              <input v-model="showVirtual" type="checkbox" />
              Mostrar adaptadores virtuais <span v-if="hiddenPorts" class="muted">({{ hiddenPorts }} ocultos)</span>
            </label>
          </div>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>Interface</th><th>MAC</th><th>Endereços IP</th><th>Velocidade</th><th>Status</th></tr></thead>
              <tbody>
                <tr v-for="(p, i) in ports" :key="i">
                  <td>{{ p.name }} <span v-if="isVirtual(p)" class="tag">virtual</span></td>
                  <td class="mono muted">{{ p.mac_address ?? '—' }}</td>
                  <td class="mono">
                    <div v-for="ip in p.ips" :key="ip.address">{{ ip.address }}<span class="muted">/{{ ip.prefix ?? '?' }}</span></div>
                    <span v-if="!p.ips.length" class="muted">—</span>
                  </td>
                  <td class="muted num">{{ p.speed_mbps ? `${num(p.speed_mbps)} Mb/s` : '—' }}</td>
                  <td>
                    <span v-if="p.oper_status" class="pill" :class="p.oper_status === 'up' ? 'pill-ok' : 'pill-muted'">{{ p.oper_status }}</span>
                    <span v-else class="muted">—</span>
                  </td>
                </tr>
              </tbody>
            </table>
            <div v-if="!ports.length" class="empty">Nenhuma interface física.</div>
          </div>
        </section>

        <!-- HISTÓRICO -->
        <section v-else-if="tab === 'history'" id="panel-history" role="tabpanel" aria-labelledby="tab-history" class="panel">
          <div class="panel-head"><h2>Histórico de alterações</h2><span class="muted small">últimas 200</span></div>
          <div v-if="!a.history.length" class="empty">Nenhuma alteração desde o cadastro.</div>
          <ol v-else class="timeline">
            <li v-for="(h, i) in a.history" :key="i">
              <span class="dot" :class="{ add: h.field.endsWith('added') || h.field.endsWith('installed'), del: h.field.endsWith('removed') }" />
              <div>
                <div><strong>{{ fieldLabel(h.field) }}</strong> <span class="muted small">· {{ relative(h.created_at) }} · {{ h.source }}</span></div>
                <div class="change small">
                  <span v-if="h.old_value" class="old">{{ h.old_value }}</span>
                  <span v-if="h.old_value && h.new_value" class="muted"> → </span>
                  <span v-if="h.new_value" class="new">{{ h.new_value }}</span>
                  <span v-if="!h.old_value && !h.new_value" class="muted">(vazio)</span>
                </div>
              </div>
            </li>
          </ol>
        </section>

        <!-- INVENTÁRIOS -->
        <section v-else id="panel-inventories" role="tabpanel" aria-labelledby="tab-inventories" class="panel">
          <div class="panel-head"><h2>Inventários recebidos</h2></div>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>#</th><th>Recebido</th><th>Tipo</th><th>Tamanho</th><th>Processamento</th><th>Status</th><th><span class="sr-only">Ações</span></th></tr></thead>
              <tbody>
                <tr v-for="r in a.inventories" :key="r.id">
                  <td class="mono muted">{{ r.id }}</td>
                  <td>{{ dateTime(r.received_at) }}</td>
                  <td><span class="tag">{{ r.partial ? 'parcial' : 'completo' }}</span> <span class="muted small">{{ r.source_format }}</span></td>
                  <td class="num muted">{{ bytes(r.size_bytes) }}</td>
                  <td class="num muted">{{ r.duration_ms !== null ? `${num(r.duration_ms)} ms` : '—' }}</td>
                  <td><StatusPill :status="r.status" /><div v-if="r.error" class="small err">{{ r.error }}</div></td>
                  <td><button class="btn" type="button" @click="showPayload(r.id)"><AppIcon name="code" :size="14" /> JSON</button></td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="payload" class="payload">
            <div class="panel-head">
              <h2>Payload bruto #{{ payload.id }}</h2>
              <button class="btn btn-icon" type="button" aria-label="Fechar payload" @click="payload = null"><AppIcon name="close" :size="16" /></button>
            </div>
            <pre class="mono">{{ payload.text }}</pre>
          </div>
        </section>
      </template>
    </LoadState>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 16px; max-width: 1480px; }
.back { display: inline-flex; align-items: center; gap: 4px; color: var(--text-3); font-size: 13px; width: fit-content; }
.hero { padding: 22px 24px; display: flex; flex-direction: column; gap: 18px; }
.title-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.hero-main p { margin: 6px 0 0; }
.facts { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px 24px; margin: 0; }
.facts dt { font-size: 12px; color: var(--text-3); margin-bottom: 3px; }
.facts dd { margin: 0; font-weight: 500; overflow-wrap: anywhere; }
.uuid { display: flex; align-items: center; gap: 6px; font-weight: 400; }
.icon-btn { border: 0; background: transparent; color: var(--text-3); cursor: pointer; padding: 4px; border-radius: 6px; display: grid; }
.icon-btn:hover { color: var(--text); background: var(--panel-hover); }

.tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border); overflow-x: auto; }
.tab {
  display: inline-flex; align-items: center; gap: 8px; min-height: 44px; padding: 0 14px;
  border: 0; background: transparent; color: var(--text-3); cursor: pointer; font-weight: 500;
  border-bottom: 2px solid transparent; margin-bottom: -1px; white-space: nowrap;
}
.tab:hover { color: var(--text); }
.tab.active { color: var(--accent-text); border-bottom-color: var(--accent); }
.count { font-size: 11.5px; padding: 0 7px; border-radius: 99px; background: var(--muted-soft); color: var(--text-2); }

.hw { display: grid; grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); gap: 16px; align-items: start; }
.group { padding: 0 20px 16px; }
.group h3 { font-size: 13px; font-weight: 600; margin: 4px 0 8px; }
.group ul { list-style: none; margin: 0; padding: 0; border: 1px solid var(--border-soft); border-radius: var(--radius-sm); }
.group li { display: flex; justify-content: space-between; gap: 16px; padding: 10px 14px; }
.group li + li { border-top: 1px solid var(--border-soft); }
.right { text-align: right; }
.kv { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.4fr); gap: 10px 16px; margin: 0; padding: 0 20px 20px; font-size: 13px; }
.kv dt { color: var(--text-3); }
.kv dd { margin: 0; overflow-wrap: anywhere; }
.small { font-size: 12px; }
.check { display: inline-flex; align-items: center; gap: 8px; color: var(--text-2); font-size: 13px; cursor: pointer; }
.check input { width: 16px; height: 16px; accent-color: var(--accent); }

.timeline { list-style: none; margin: 0; padding: 0 20px 20px; display: flex; flex-direction: column; }
.timeline li { display: flex; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--border-soft); }
.dot { width: 8px; height: 8px; border-radius: 99px; background: var(--text-3); margin-top: 7px; flex: none; }
.dot.add { background: var(--accent); }
.dot.del { background: var(--warn); }
.change { margin-top: 2px; overflow-wrap: anywhere; }
.old { color: var(--text-3); text-decoration: line-through; }
.new { color: var(--text); }
.err { color: var(--warn-text); margin-top: 4px; max-width: 420px; }
.payload { border-top: 1px solid var(--border); }
.payload pre { margin: 0 20px 20px; padding: 16px; max-height: 520px; overflow: auto; background: var(--bg-elev); border: 1px solid var(--border-soft); border-radius: var(--radius-sm); font-size: 12px; }

@media (max-width: 1100px) { .facts { grid-template-columns: repeat(2, minmax(0, 1fr)); } .hw { grid-template-columns: 1fr; } }
@media (max-width: 560px) { .facts { grid-template-columns: 1fr; } }
</style>
