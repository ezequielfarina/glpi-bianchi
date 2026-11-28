<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import LoadState from '../../components/LoadState.vue'
import IpForm from '../../components/dcim/IpForm.vue'
import SpecForm from '../../components/dcim/SpecForm.vue'
import { api } from '../../lib/api'
import { IP_STATUS, ipam, ipamChoices, type IpamChoices, type IpRow, type RangeRow } from '../../lib/dcim'
import { notify } from '../../lib/toast'
import type { BranchRow } from '../../lib/types'
import { rangeFields } from './ipamFields'

const route = useRoute()
const router = useRouter()
const tab = ref<'ips' | 'ranges'>(route.query.tab === 'ranges' ? 'ranges' : 'ips')
const ch = ref<IpamChoices | null>(null)
const branches = ref<BranchRow[]>([])
const branch = ref<number | null>(null)
const status = ref('')
const assigned = ref<'' | 'yes' | 'no'>('')
const q = ref(typeof route.query.q === 'string' ? route.query.q : '')
const ips = ref<IpRow[]>([])
const ranges = ref<RangeRow[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const creating = ref(false)
const editRange = ref<RangeRow | 'new' | null>(null)
void ipamChoices(true).then((c) => { ch.value = c })
void api.branches().then((r) => { branches.value = r.items })
let t: number | undefined
async function load(): Promise<void> {
  try {
    if (tab.value === 'ips') ips.value = (await ipam.ips({ branch: branch.value, status: status.value || undefined, q: q.value.trim() || undefined, assigned: assigned.value === '' ? null : assigned.value === 'yes' })).items
    else ranges.value = (await ipam.ranges({ status: status.value || undefined, q: q.value.trim() || undefined })).items
    error.value = null
  } catch (e) { error.value = e instanceof Error ? e.message : String(e) } finally { loading.value = false }
}
watch([tab, branch, status, assigned], load, { immediate: true })
watch(q, () => { window.clearTimeout(t); t = window.setTimeout(load, 250) })
async function removeRange(r: RangeRow): Promise<void> {
  if (!window.confirm(`Excluir a faixa ${r.start_address} – ${r.end_address}?`)) return
  await ipam.deleteRange(r.id)
  notify('ok', 'Faixa excluída')
  await load()
}
async function nextIn(r: RangeRow): Promise<void> {
  const n = await ipam.nextIpInRange(r.id)
  notify(n.address ? 'ok' : 'warn', n.address ? `Próximo livre: ${n.address}` : 'Faixa sem IP livre')
}
</script>

<template>
  <div class="dc-page">
    <header class="dc-head">
      <div><h1>Endereços IP</h1><p class="muted">Quem usa cada IP, em qual equipamento e interface.</p></div>
      <div class="actions">
        <button v-if="tab === 'ips'" class="edit-only btn btn-primary" type="button" @click="creating = true"><AppIcon name="plus" :size="16" /> Novo IP</button>
        <button v-else class="edit-only btn btn-primary" type="button" @click="editRange = 'new'"><AppIcon name="plus" :size="16" /> Nova faixa</button>
      </div>
    </header>
    <section class="panel">
      <div class="tabs dc-tabs" role="tablist">
        <button type="button" role="tab" class="tab" :class="{ active: tab === 'ips' }" @click="tab = 'ips'">Endereços</button>
        <button type="button" role="tab" class="tab" :class="{ active: tab === 'ranges' }" @click="tab = 'ranges'">Faixas (DHCP, reservas)</button>
      </div>
      <div class="dc-filters">
        <template v-if="tab === 'ips'">
          <select v-model="branch" class="input" aria-label="Filial"><option :value="null">Todas as filiais</option><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
          <select v-model="assigned" class="input" aria-label="Atribuição"><option value="">Atribuídos ou não</option><option value="yes">Em interface</option><option value="no">Sem interface</option></select>
        </template>
        <select v-model="status" class="input" aria-label="Situação"><option value="">Qualquer situação</option><option v-for="o in (tab === 'ips' ? ch?.ip_status : ch?.range_status) ?? []" :key="o.value" :value="o.value">{{ o.label }}</option></select>
        <input v-model="q" class="input search" type="search" :placeholder="tab === 'ips' ? 'IP, rede (10.1.0.0/16), DNS, equipamento…' : 'Início, papel, descrição…'" aria-label="Buscar" />
      </div>
      <LoadState :loading="loading" :error="error" :rows="6" @retry="load">
        <template v-if="tab === 'ips'">
          <p v-if="!ips.length" class="empty">Nenhum IP.</p>
          <div v-else class="table-wrap"><table class="table">
            <thead><tr><th>Endereço</th><th>Situação</th><th>Equipamento / interface</th><th>Prefixo</th><th>DNS / descrição</th></tr></thead>
            <tbody><tr v-for="i in ips" :key="i.id" class="dc-clickable" @click="router.push(`/ipam/ip-addresses/${i.id}`)">
              <td class="mono"><RouterLink :to="`/ipam/ip-addresses/${i.id}`" class="dc-strong" @click.stop>{{ i.address }}</RouterLink></td>
              <td><span class="pill" :class="IP_STATUS[i.status]?.cls">{{ IP_STATUS[i.status]?.label }}</span><span v-if="i.role" class="pill pill-muted">{{ i.role }}</span><span v-if="i.is_primary" class="pill pill-accent">primário</span></td>
              <td><template v-if="i.device_id"><RouterLink :to="`/dcim/devices/${i.device_id}`" class="dc-link" @click.stop>{{ i.device }}</RouterLink> <span class="mono">{{ i.port }}</span> <span class="muted dc-small">{{ i.branch }}</span></template>
                <template v-else-if="i.vm_id"><AppIcon name="vm" :size="13" /> <RouterLink :to="`/virtualization/vms/${i.vm_id}`" class="dc-link" @click.stop>{{ i.vm }}</RouterLink> <span class="mono">{{ i.vm_interface }}</span></template></td>
              <td><RouterLink v-if="i.prefix_id" :to="`/ipam/prefixes/${i.prefix_id}`" class="dc-link mono" @click.stop>{{ i.prefix }}</RouterLink></td>
              <td class="muted">{{ [i.dns_name, i.description].filter(Boolean).join(' · ') }}</td>
            </tr></tbody>
          </table></div>
        </template>
        <template v-else>
          <p v-if="!ranges.length" class="empty">Nenhuma faixa.</p>
          <div v-else class="table-wrap"><table class="table">
            <thead><tr><th>Faixa</th><th>Situação</th><th>Papel</th><th class="num">Em uso</th><th>Descrição</th><th /></tr></thead>
            <tbody><tr v-for="r in ranges" :key="r.id">
              <td class="mono">{{ r.start_address }} – {{ r.end_address }} <span class="muted">/{{ r.len }}</span></td>
              <td><span class="pill" :class="IP_STATUS[r.status]?.cls">{{ r.status_label }}</span><span v-if="r.mark_utilized" class="pill pill-info">conta como usada</span></td>
              <td>{{ r.role }}</td><td class="num">{{ r.used }}/{{ r.size }}</td><td class="muted">{{ r.description }}</td>
              <td class="dc-actions-row"><button class="btn btn-icon" type="button" aria-label="Próximo IP livre" title="Próximo IP livre" @click="nextIn(r)"><AppIcon name="search" :size="14" /></button>
                <button class="edit-only btn btn-icon" type="button" aria-label="Editar faixa" @click="editRange = r"><AppIcon name="edit" :size="14" /></button>
                <button class="edit-only btn btn-icon" type="button" aria-label="Excluir faixa" @click="removeRange(r)"><AppIcon name="trash" :size="14" /></button></td>
            </tr></tbody>
          </table></div>
        </template>
      </LoadState>
    </section>
    <IpForm v-if="creating" @close="creating = false" @saved="(id) => router.push(`/ipam/ip-addresses/${id}`)" />
    <SpecForm v-if="editRange && ch" ctx="range" :title="editRange === 'new' ? 'Nova faixa de IPs' : 'Editar faixa'" :fields="rangeFields(ch)"
      :initial="editRange === 'new' ? { status: 'active' } : { start_address: `${editRange.start_address}/${editRange.len}`, end_address: editRange.end_address, status: editRange.status, role: editRange.role ?? '', mark_utilized: editRange.mark_utilized, description: editRange.description ?? '' }"
      :save="(v) => ipam.saveRange(editRange === 'new' ? null : (editRange as RangeRow).id, v)" ok-text="Faixa salva" @close="editRange = null" @saved="editRange = null; load()" />
  </div>
</template>

<style scoped>
.empty { padding: 0 20px 20px; }
</style>
