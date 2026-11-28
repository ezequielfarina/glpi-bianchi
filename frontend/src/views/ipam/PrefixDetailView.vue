<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import LoadState from '../../components/LoadState.vue'
import IpForm from '../../components/dcim/IpForm.vue'
import SpecForm from '../../components/dcim/SpecForm.vue'
import { api } from '../../lib/api'
import { bigCount, IP_STATUS, ipam, ipamChoices, type IpamChoices, type VlanRow } from '../../lib/dcim'
import { notify } from '../../lib/toast'
import type { BranchRow } from '../../lib/types'
import { useAsync } from '../../lib/useAsync'
import { prefixFields, rangeFields } from './ipamFields'

const props = defineProps<{ id: string }>()
const router = useRouter()
const { data: p, loading, error, reload } = useAsync(() => ipam.prefix(Number(props.id)))
const ch = ref<IpamChoices | null>(null)
const branches = ref<BranchRow[]>([])
const vlans = ref<VlanRow[]>([])
void ipamChoices(true).then((c) => { ch.value = c })
void api.branches().then((r) => { branches.value = r.items })
void ipam.vlans().then((r) => { vlans.value = r.items })
const editing = ref(false)
const child = ref<string | null>(null)
const newRange = ref(false)
const newIp = ref<string | null>(null)
async function remove(): Promise<void> {
  if (!p.value || !window.confirm(`Excluir o prefixo ${p.value.prefix}? IPs e sub-redes continuam cadastrados.`)) return
  await ipam.deletePrefix(p.value.id)
  notify('ok', 'Prefixo excluído')
  await router.push('/ipam/prefixes')
}
async function removeRange(id: number): Promise<void> {
  if (!window.confirm('Excluir esta faixa?')) return
  await ipam.deleteRange(id)
  await reload()
}
const cls = (u: number) => (u >= 95 ? 'bad' : u >= 80 ? 'warn' : '')
</script>

<template>
  <div class="dc-page">
    <RouterLink to="/ipam/prefixes" class="dc-back"><AppIcon name="back" :size="16" /> Prefixos</RouterLink>
    <LoadState :loading="loading" :error="error" :rows="6" @retry="reload">
      <template v-if="p">
        <header class="dc-head">
          <div>
            <p class="muted"><template v-for="x in p.parents" :key="x.id"><RouterLink :to="`/ipam/prefixes/${x.id}`" class="dc-link mono">{{ x.prefix }}</RouterLink> › </template></p>
            <h1 class="mono">{{ p.prefix }} <span class="pill" :class="IP_STATUS[p.status]?.cls">{{ p.status_label }}</span><span v-if="p.vrf" class="pill pill-muted">{{ p.vrf }}</span>
              <span v-if="p.role" class="pill pill-accent">{{ p.role }}</span></h1>
            <p class="muted">{{ [p.branch, p.vlan_vid ? `VLAN ${p.vlan_vid} ${p.vlan}` : null, p.description].filter(Boolean).join(' · ') }}</p>
          </div>
          <div class="actions">
            <button v-if="p.first_available_ip" class="edit-only btn btn-primary" type="button" @click="newIp = p.first_available_ip"><AppIcon name="plus" :size="15" /> Próximo IP livre ({{ p.first_available_ip }})</button>
            <button class="edit-only btn" type="button" @click="newRange = true"><AppIcon name="plus" :size="15" /> Faixa</button>
            <button class="edit-only btn" type="button" @click="child = ''"><AppIcon name="plus" :size="15" /> Sub-rede</button>
            <button class="edit-only btn" type="button" @click="editing = true"><AppIcon name="edit" :size="15" /> Editar</button>
            <button class="edit-only btn btn-icon" type="button" aria-label="Excluir prefixo" @click="remove"><AppIcon name="trash" :size="15" /></button>
          </div>
        </header>
        <section class="panel util">
          <div class="dc-util big"><div class="bar"><span :class="cls(p.utilization)" :style="{ width: `${p.utilization}%` }" /></div><span class="pct">{{ p.utilization }}%</span></div>
          <span class="muted">{{ bigCount(p.used) }} de {{ bigCount(p.usable) }} endereços {{ p.status === 'container' ? 'em sub-redes' : 'em uso (IPs + faixas marcadas)' }}</span>
        </section>
        <div class="dc-cols">
          <div>
            <section v-if="p.children.length" class="panel">
              <div class="panel-head"><h2>Sub-redes <span class="muted">({{ p.children.length }})</span></h2></div>
              <div class="table-wrap"><table class="table">
                <thead><tr><th>Prefixo</th><th>VLAN</th><th>Papel / descrição</th><th>Utilização</th></tr></thead>
                <tbody><tr v-for="c in p.children" :key="c.id" class="dc-clickable" @click="router.push(`/ipam/prefixes/${c.id}`)">
                  <td><RouterLink :to="`/ipam/prefixes/${c.id}`" class="dc-strong mono" @click.stop>{{ c.prefix }}</RouterLink></td>
                  <td>{{ c.vlan_vid ? `${c.vlan_vid} ${c.vlan}` : '' }}</td><td><span v-if="c.role" class="pill pill-accent">{{ c.role }}</span> <span class="muted">{{ c.description }}</span></td>
                  <td><div class="dc-util"><div class="bar"><span :class="cls(c.utilization)" :style="{ width: `${c.utilization}%` }" /></div><span class="pct">{{ c.utilization }}%</span></div></td>
                </tr></tbody>
              </table></div>
            </section>
            <section class="panel">
              <div class="panel-head"><h2>Endereços <span class="muted">({{ p.ips.length }})</span></h2></div>
              <p v-if="!p.slots && !p.ips.length" class="empty">Nenhum IP cadastrado.</p>
              <div v-else class="table-wrap"><table class="table">
                <thead><tr><th>Endereço</th><th>Situação</th><th>Equipamento / interface</th><th>DNS / descrição</th></tr></thead>
                <tbody>
                  <template v-if="p.slots">
                    <tr v-for="(s, i) in p.slots" :key="i" :class="s.t">
                      <template v-if="s.t === 'ip'">
                        <td class="mono"><RouterLink :to="`/ipam/ip-addresses/${s.id}`" class="dc-strong">{{ s.address }}</RouterLink></td>
                        <td><span class="pill" :class="IP_STATUS[s.status]?.cls">{{ IP_STATUS[s.status]?.label }}</span><span v-if="s.role" class="pill pill-muted">{{ s.role }}</span><span v-if="s.is_primary" class="pill pill-accent">primário</span></td>
                        <td><template v-if="s.device_id"><RouterLink :to="`/dcim/devices/${s.device_id}`" class="dc-link">{{ s.device }}</RouterLink> <span class="mono">{{ s.port }}</span></template></td>
                        <td class="muted">{{ [s.dns_name, s.description].filter(Boolean).join(' · ') }}</td>
                      </template>
                      <template v-else-if="s.t === 'range'">
                        <td class="mono" colspan="2">{{ s.start_address }} – {{ s.end_address }}</td>
                        <td><span class="pill pill-info">faixa{{ s.role ? ' ' + s.role : '' }}</span> <span class="muted dc-small">{{ s.used }}/{{ s.size }} em uso{{ s.mark_utilized ? ' · conta como utilizada' : '' }}</span></td>
                        <td class="muted">{{ s.description }} <button class="edit-only btn btn-icon" type="button" aria-label="Excluir faixa" @click="removeRange(s.id)"><AppIcon name="trash" :size="13" /></button></td>
                      </template>
                      <template v-else>
                        <td class="mono free" colspan="3">{{ s.count === 1 ? s.first : `${s.first} – ${s.last}` }} <span class="muted dc-small">· {{ s.count }} livre(s)</span></td>
                        <td><button class="edit-only btn" type="button" @click="newIp = `${s.first}/${p.len}`"><AppIcon name="plus" :size="13" /> Usar {{ s.first }}</button></td>
                      </template>
                    </tr>
                  </template>
                  <template v-else>
                    <tr v-for="s in p.ips" :key="s.id">
                      <td class="mono"><RouterLink :to="`/ipam/ip-addresses/${s.id}`" class="dc-strong">{{ s.address }}</RouterLink></td>
                      <td><span class="pill" :class="IP_STATUS[s.status]?.cls">{{ IP_STATUS[s.status]?.label }}</span></td>
                      <td><template v-if="s.device_id"><RouterLink :to="`/dcim/devices/${s.device_id}`" class="dc-link">{{ s.device }}</RouterLink> <span class="mono">{{ s.port }}</span></template></td>
                      <td class="muted">{{ [s.dns_name, s.description].filter(Boolean).join(' · ') }}</td>
                    </tr>
                  </template>
                </tbody>
              </table></div>
            </section>
          </div>
          <div>
            <section class="panel">
              <div class="panel-head"><h2>Dados</h2></div>
              <dl class="dc-kv">
                <dt>Tamanho</dt><dd class="mono">{{ bigCount(p.size) }} endereços (/{{ p.len }})</dd>
                <dt>Próximo IP livre</dt><dd class="mono">{{ p.first_available_ip ?? '—' }}</dd>
                <dt>Filial</dt><dd>{{ p.branch ?? '—' }}</dd>
                <dt>VLAN</dt><dd>{{ p.vlan_vid ? `${p.vlan_vid} — ${p.vlan}` : '—' }}</dd>
                <template v-if="p.comments"><dt>Observações</dt><dd class="pre">{{ p.comments }}</dd></template>
              </dl>
            </section>
            <section v-if="p.ranges.length && !p.slots" class="panel">
              <div class="panel-head"><h2>Faixas</h2></div>
              <ul class="list"><li v-for="r in p.ranges" :key="r.id"><span class="mono">{{ r.start_address }} – {{ r.end_address }}</span> <span class="muted dc-small">{{ r.role }} {{ r.used }}/{{ r.size }}</span></li></ul>
            </section>
            <section v-if="p.available_prefixes.length" class="panel">
              <div class="panel-head"><h2>Blocos livres</h2></div>
              <ul class="list"><li v-for="a in p.available_prefixes" :key="a"><span class="mono">{{ a }}</span>
                <button class="edit-only btn" type="button" @click="child = a"><AppIcon name="plus" :size="13" /> Criar</button></li></ul>
            </section>
          </div>
        </div>
        <SpecForm v-if="editing && ch" ctx="prefix" :title="`Editar ${p.prefix}`" :fields="prefixFields(ch, branches.map((b) => ({ value: b.id, label: b.name })), vlans)"
          :initial="{ prefix: p.prefix, status: p.status, vrf_id: p.vrf_id ?? '', branch_id: p.branch_id ?? '', vlan_id: p.vlan_id ?? '', role: p.role ?? '', is_pool: p.is_pool, mark_utilized: p.mark_utilized, description: p.description ?? '', comments: p.comments ?? '' }"
          :save="(v) => ipam.savePrefix(p!.id, v)" ok-text="Prefixo atualizado" @close="editing = false" @saved="editing = false; reload()" />
        <SpecForm v-if="child !== null && ch" ctx="prefix" title="Nova sub-rede" :fields="prefixFields(ch, branches.map((b) => ({ value: b.id, label: b.name })), vlans)"
          :initial="{ prefix: child, status: 'active', vrf_id: p.vrf_id ?? '', branch_id: p.branch_id ?? '' }"
          :save="(v) => ipam.savePrefix(null, v)" ok-text="Sub-rede cadastrada" @close="child = null" @saved="child = null; reload()" />
        <SpecForm v-if="newRange && ch" ctx="range" title="Nova faixa de IPs" :fields="rangeFields(ch)" :initial="{ status: 'active', vrf_id: p.vrf_id ?? '' }"
          :save="(v) => ipam.saveRange(null, v)" ok-text="Faixa cadastrada" @close="newRange = false" @saved="newRange = false; reload()" />
        <IpForm v-if="newIp" :preset="{ address: newIp, vrf_id: p.vrf_id ?? '' }" @close="newIp = null" @saved="newIp = null; reload()" />
      </template>
    </LoadState>
  </div>
</template>

<style scoped>
.util { display: flex; align-items: center; gap: 16px; padding: 14px 20px; flex-wrap: wrap; }
.dc-util.big { width: min(420px, 100%); }
.dc-util.big .bar { height: 10px; border-radius: 5px; }
.empty { padding: 0 20px 16px; margin: 0; }
tr.free td { background: var(--ok-soft); }
.free { color: var(--ok); }
tr.range td { background: var(--accent-soft); }
.list { list-style: none; margin: 0; padding: 0 20px 16px; display: flex; flex-direction: column; gap: 6px; font-size: 13.5px; }
.list li { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.pre { white-space: pre-wrap; }
</style>
