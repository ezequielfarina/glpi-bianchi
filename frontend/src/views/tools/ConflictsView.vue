<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import AppIcon from '../../components/AppIcon.vue'
import LoadState from '../../components/LoadState.vue'
import { api } from '../../lib/api'
import { dateTime, relative } from '../../lib/format'
import type { AssetRef, ConflictReport } from '../../lib/types'

const days = ref(7)
const report = ref<ConflictReport | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  try { report.value = await api.conflicts(days.value); error.value = null } catch (e) { error.value = e instanceof Error ? e.message : String(e) } finally { loading.value = false }
}
watch(days, load)
onMounted(load)

const total = computed(() => report.value ? report.value.arp.length + report.value.scan_vs_inventory.length + report.value.mac_duplicates.length + report.value.ip_duplicates.length : 0)
const diag = (ip: string) => ({ path: '/tools/net', query: { tab: 'ping', host: ip } })
const macLookup = (mac: string) => ({ path: '/tools/net', query: { tab: 'mac', q: mac } })
const assetLabel = (a: AssetRef) => a.name + (a.inventory_number ? ` · ${a.inventory_number}` : '')
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>Conflitos de IP e MAC</h1>
        <p class="muted">Cruza as respostas ARP das varreduras com o inventário dos agentes. Do mais conclusivo (dois equipamentos respondendo pelo mesmo IP agora) ao indício (cadastro duplicado).</p>
      </div>
      <div class="head-actions">
        <label for="c-days" class="muted small">Período</label>
        <select id="c-days" v-model.number="days" class="input">
          <option :value="1">últimas 24 h</option><option :value="7">últimos 7 dias</option><option :value="30">últimos 30 dias</option>
        </select>
        <button class="btn" type="button" :disabled="loading" @click="load"><AppIcon name="refresh" :size="14" /> Atualizar</button>
      </div>
    </header>

    <LoadState :loading="loading && !report" :error="error" @retry="load">
      <template v-if="report">
        <div class="summary">
          <a href="#arp" class="panel sum" :class="{ hot: report.arp.length }"><span class="sev sev-critical"><AppIcon name="conflict" :size="16" /></span><span><strong>{{ report.arp.length }}</strong><small>IP em conflito (ARP)</small></span></a>
          <a href="#svi" class="panel sum" :class="{ warm: report.scan_vs_inventory.length }"><span class="sev sev-warning"><AppIcon name="alert" :size="16" /></span><span><strong>{{ report.scan_vs_inventory.length }}</strong><small>IP em uso × inventário</small></span></a>
          <a href="#macdup" class="panel sum" :class="{ warm: report.mac_duplicates.length }"><span class="sev sev-warning"><AppIcon name="copy" :size="16" /></span><span><strong>{{ report.mac_duplicates.length }}</strong><small>MAC em 2+ ativos</small></span></a>
          <a href="#ipdup" class="panel sum"><span class="sev sev-info"><AppIcon name="network" :size="16" /></span><span><strong>{{ report.ip_duplicates.length }}</strong><small>IP em 2+ ativos</small></span></a>
        </div>

        <p v-if="!report.last_scan_with_arp" class="notice">
          <AppIcon name="alert" :size="16" />
          <span>Nenhuma varredura privilegiada recente: o conflito por ARP depende do <RouterLink to="/tools/scanner">scanner</RouterLink> rodando com CAP_NET_RAW numa rede ligada ao servidor.
            Para vigiar continuamente, crie um <RouterLink :to="{ path: '/tools/monitor', query: { tab: 'schedules' } }">agendamento</RouterLink>.</span>
        </p>
        <p v-else-if="!total" class="ok-box"><AppIcon name="check" :size="16" /> Nenhum conflito no período. Última varredura com ARP {{ relative(report.last_scan_with_arp) }}.</p>

        <!-- 1. ARP -->
        <section id="arp" class="panel">
          <div class="panel-head"><h2>IP em conflito (ARP)</h2><span class="muted small">2+ MACs responderam pelo mesmo IP na mesma varredura</span></div>
          <div v-if="!report.arp.length" class="empty">Nenhum.</div>
          <div v-else class="table-wrap">
            <table class="table">
              <thead><tr><th>IP</th><th>MACs que responderam</th><th>Rede</th><th>Visto</th><th /></tr></thead>
              <tbody>
                <tr v-for="c in report.arp" :key="c.ip">
                  <td class="mono strong">{{ c.ip }} <span v-if="c.current" class="pill pill-bad">atual</span><span v-else class="pill pill-muted" title="Não apareceu na varredura mais recente desta rede">resolvido?</span></td>
                  <td>
                    <div v-for="m in c.macs" :key="m.mac" class="mac-line">
                      <RouterLink :to="macLookup(m.mac)" class="mono">{{ m.mac }}</RouterLink>
                      <span class="muted">{{ m.vendor ?? 'fabricante desconhecido' }}</span>
                      <RouterLink v-for="a in m.assets" :key="a.uuid" :to="`/assets/${a.uuid}`" class="asset-link"><AppIcon name="assets" :size="12" /> {{ a.name }}</RouterLink>
                    </div>
                  </td>
                  <td class="mono small">{{ c.target }}<div v-if="c.branch" class="muted">{{ c.branch }}</div></td>
                  <td :title="dateTime(c.seen_at)"><RouterLink :to="{ path: '/tools/scanner', query: { scan: String(c.scan_id) } }">{{ relative(c.seen_at) }}</RouterLink></td>
                  <td class="num"><RouterLink class="btn btn-sm" :to="diag(c.ip)"><AppIcon name="pulse" :size="12" /> Diagnosticar</RouterLink></td>
                </tr>
              </tbody>
            </table>
          </div>
          <p class="muted small foot">VRRP/HSRP/CARP e proxy-ARP também respondem com outro MAC — confira se o IP é virtual antes de agir.</p>
        </section>

        <!-- 2. scan × inventário -->
        <section id="svi" class="panel">
          <div class="panel-head"><h2>IP em uso por outro equipamento</h2><span class="muted small">a varredura viu o IP com um MAC; o inventário recente diz que o IP é de outro ativo</span></div>
          <div v-if="!report.scan_vs_inventory.length" class="empty">Nenhum.</div>
          <div v-else class="table-wrap">
            <table class="table">
              <thead><tr><th>IP</th><th>Respondeu na varredura</th><th>Inventário diz</th><th>Varredura</th><th /></tr></thead>
              <tbody>
                <tr v-for="c in report.scan_vs_inventory" :key="c.ip + c.scan_id">
                  <td class="mono strong">{{ c.ip }}</td>
                  <td>
                    <RouterLink :to="macLookup(c.seen.mac)" class="mono">{{ c.seen.mac }}</RouterLink> <span class="muted">{{ c.seen.vendor }}</span>
                    <div v-if="c.seen.name" class="small">{{ c.seen.name }}</div>
                    <RouterLink v-for="a in c.seen.assets" :key="a.uuid" :to="`/assets/${a.uuid}`" class="asset-link"><AppIcon name="assets" :size="12" /> {{ a.name }}</RouterLink>
                  </td>
                  <td>
                    <RouterLink :to="`/assets/${c.inventory.asset_uuid}`" class="strong">{{ c.inventory.asset_name }}</RouterLink>
                    <div class="small"><span class="mono">{{ c.inventory.mac }}</span> <span class="muted">{{ c.inventory.vendor }} · inventário {{ relative(c.inventory.last_inventory_at) }}</span></div>
                  </td>
                  <td :title="dateTime(c.seen_at)"><RouterLink :to="{ path: '/tools/scanner', query: { scan: String(c.scan_id) } }">{{ relative(c.seen_at) }}</RouterLink></td>
                  <td class="num"><RouterLink class="btn btn-sm" :to="diag(c.ip)"><AppIcon name="pulse" :size="12" /> Diagnosticar</RouterLink></td>
                </tr>
              </tbody>
            </table>
          </div>
          <p class="muted small foot">Causa comum: IP fixo configurado dentro do escopo do DHCP, ou reserva DHCP apontando para o MAC errado.</p>
        </section>

        <!-- 3. MAC duplicado -->
        <section id="macdup" class="panel">
          <div class="panel-head"><h2>MAC repetido em vários ativos</h2><span class="muted small">ignora portas virtuais e MACs fixos de VPN/hipervisor (FortiClient, Npcap, AnyConnect, VMnet)</span></div>
          <div v-if="!report.mac_duplicates.length" class="empty">Nenhum.</div>
          <div v-else class="cards">
            <article v-for="d in report.mac_duplicates" :key="d.mac" class="dup">
              <header><RouterLink :to="macLookup(d.mac)" class="mono strong">{{ d.mac }}</RouterLink> <span class="muted">{{ d.vendor ?? 'fabricante desconhecido' }}</span></header>
              <p class="hint-line"><AppIcon name="alert" :size="13" /> {{ d.hint }}</p>
              <ul>
                <li v-for="a in d.assets" :key="a.uuid">
                  <RouterLink :to="`/assets/${a.uuid}`">{{ assetLabel(a) }}</RouterLink>
                  <span class="muted small"><template v-if="a.port"> · {{ a.port }}</template><template v-if="a.serial"> · S/N {{ a.serial }}</template> · inventário {{ a.last_inventory_at ? relative(a.last_inventory_at) : 'manual' }}</span>
                </li>
              </ul>
            </article>
          </div>
        </section>

        <!-- 4. IP duplicado -->
        <section id="ipdup" class="panel">
          <div class="panel-head"><h2>IP reportado por vários ativos</h2><span class="muted small">inventariados no período, dentro das redes cadastradas nas filiais</span></div>
          <div v-if="!report.ip_duplicates.length" class="empty">Nenhum.</div>
          <div v-else class="cards">
            <article v-for="d in report.ip_duplicates" :key="d.ip" class="dup" :class="{ likely: d.likely }">
              <header>
                <span class="mono strong">{{ d.ip }}</span>
                <span class="pill" :class="d.likely ? 'pill-warn' : 'pill-muted'">{{ d.likely ? 'provável conflito' : 'provável DHCP' }}</span>
                <RouterLink class="btn btn-sm" :to="diag(d.ip)" style="margin-left: auto"><AppIcon name="pulse" :size="12" /> Diagnosticar</RouterLink>
              </header>
              <p class="hint-line">{{ d.hint }}</p>
              <ul>
                <li v-for="a in d.assets" :key="a.uuid + (a.mac ?? '')">
                  <RouterLink :to="`/assets/${a.uuid}`">{{ a.name }}</RouterLink>
                  <span class="muted small"> · <span class="mono">{{ a.mac ?? 'sem MAC' }}</span><template v-if="a.port"> ({{ a.port }})</template> · {{ dateTime(a.last_inventory_at) }}</span>
                </li>
              </ul>
            </article>
          </div>
        </section>
      </template>
    </LoadState>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 18px; max-width: 1480px; }
.page-head { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; flex-wrap: wrap; }
.page-head p { margin: 4px 0 0; font-size: 13px; max-width: 860px; }
.head-actions { display: flex; gap: 8px; align-items: center; }
.head-actions .input { width: auto; }
.small { font-size: 12px; }
.strong { font-weight: 600; }
.summary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
.sum { display: flex; gap: 12px; align-items: center; padding: 14px 16px; color: inherit; text-decoration: none; }
.sum:hover { border-color: var(--accent); text-decoration: none; }
.sum strong { display: block; font-size: 22px; line-height: 1.1; }
.sum small { color: var(--text-3); font-size: 12px; }
.sum.hot { border-color: var(--bad); }
.sum.warm { border-color: var(--warn); }
.notice, .ok-box { display: flex; gap: 10px; align-items: flex-start; padding: 12px 16px; border-radius: var(--radius-sm); margin: 0; font-size: 13px; }
.notice { background: var(--warn-soft); color: var(--warn-text); }
.ok-box { background: var(--ok-soft); color: var(--ok); }
.panel-head { display: flex; gap: 12px; align-items: baseline; flex-wrap: wrap; }
.empty { padding: 14px 20px 20px; color: var(--text-3); font-size: 13px; }
.mac-line { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; padding: 2px 0; }
.asset-link { display: inline-flex; align-items: center; gap: 4px; font-size: 12.5px; }
.btn-sm { padding: 4px 10px; min-height: 0; font-size: 12.5px; }
.foot { padding: 0 20px 14px; margin: 0; }
.cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: 12px; padding: 0 20px 20px; }
.dup { border: 1px solid var(--border-soft); border-radius: var(--radius-sm); padding: 12px 14px; display: flex; flex-direction: column; gap: 6px; }
.dup.likely { border-color: var(--warn); }
.dup header { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.dup ul { margin: 0; padding-left: 18px; display: flex; flex-direction: column; gap: 3px; font-size: 13px; }
.hint-line { margin: 0; font-size: 12.5px; color: var(--text-2); display: flex; gap: 6px; align-items: flex-start; }
@media (max-width: 1000px) { .summary { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
