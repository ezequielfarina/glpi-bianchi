<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import LoadState from '../../components/LoadState.vue'
import SpecForm from '../../components/dcim/SpecForm.vue'
import { api } from '../../lib/api'
import { bigCount, IP_STATUS, ipam, ipamChoices, type IpamChoices, type PrefixRow, type VlanRow } from '../../lib/dcim'
import type { BranchRow } from '../../lib/types'
import { prefixFields } from './ipamFields'

const router = useRouter()
const ch = ref<IpamChoices | null>(null)
const branches = ref<BranchRow[]>([])
const vlans = ref<VlanRow[]>([])
const vrf = ref<number | null>(null)
const branch = ref<number | null>(null)
const status = ref('')
const q = ref('')
const items = ref<PrefixRow[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const creating = ref(false)
void ipamChoices(true).then((c) => { ch.value = c })
void api.branches().then((r) => { branches.value = r.items })
void ipam.vlans().then((r) => { vlans.value = r.items })
let t: number | undefined
async function load(): Promise<void> {
  try { items.value = (await ipam.prefixes({ vrf: vrf.value, branch: branch.value, status: status.value || undefined, q: q.value.trim() || undefined })).items; error.value = null }
  catch (e) { error.value = e instanceof Error ? e.message : String(e) } finally { loading.value = false }
}
watch([vrf, branch, status], load, { immediate: true })
watch(q, () => { window.clearTimeout(t); t = window.setTimeout(load, 250) })
const minDepth = computed(() => Math.min(...items.value.map((p) => p.depth), 0))
const cls = (u: number) => (u >= 95 ? 'bad' : u >= 80 ? 'warn' : '')
</script>

<template>
  <div class="dc-page">
    <header class="dc-head">
      <div><h1>Prefixos</h1><p class="muted">Faixas de rede por filial e VLAN, em árvore, com utilização. Clique para ver os IPs e o próximo livre.</p></div>
      <div class="actions"><button class="edit-only btn btn-primary" type="button" @click="creating = true"><AppIcon name="plus" :size="16" /> Novo prefixo</button></div>
    </header>
    <section class="panel">
      <div class="dc-filters">
        <select v-model="vrf" class="input" aria-label="VRF"><option :value="null">Todas as VRFs</option><option :value="0">Global</option><option v-for="v in ch?.vrfs ?? []" :key="v.id" :value="v.id">{{ v.name }}</option></select>
        <select v-model="branch" class="input" aria-label="Filial"><option :value="null">Todas as filiais</option><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
        <select v-model="status" class="input" aria-label="Situação"><option value="">Qualquer situação</option><option v-for="o in ch?.prefix_status ?? []" :key="o.value" :value="o.value">{{ o.label }}</option></select>
        <input v-model="q" class="input search" type="search" placeholder="Prefixo, IP contido, papel, descrição…" aria-label="Buscar prefixo" />
      </div>
      <LoadState :loading="loading && !items.length" :error="error" :rows="6" @retry="load">
        <p v-if="!items.length" class="empty">Nenhum prefixo.</p>
        <div v-else class="table-wrap">
          <table class="table">
            <thead><tr><th>Prefixo</th><th>Situação</th><th>VLAN</th><th>Filial</th><th>Papel / descrição</th><th>Utilização</th><th class="num">Tamanho</th></tr></thead>
            <tbody>
              <tr v-for="p in items" :key="p.id" class="dc-clickable" @click="router.push(`/ipam/prefixes/${p.id}`)">
                <td class="dc-nowrap"><span :style="{ paddingLeft: `${(p.depth - minDepth) * 18}px` }"><span v-if="p.depth > minDepth" class="muted">└ </span>
                  <RouterLink :to="`/ipam/prefixes/${p.id}`" class="dc-strong mono" @click.stop>{{ p.prefix }}</RouterLink></span>
                  <span v-if="p.vrf" class="pill pill-muted">{{ p.vrf }}</span></td>
                <td><span class="pill" :class="IP_STATUS[p.status]?.cls">{{ p.status_label }}</span><span v-if="p.is_pool" class="pill pill-info">pool</span></td>
                <td class="dc-nowrap">{{ p.vlan_vid ? `${p.vlan_vid} ${p.vlan}` : '' }}</td>
                <td>{{ p.branch }}</td>
                <td><span v-if="p.role" class="pill pill-accent">{{ p.role }}</span> <span class="muted">{{ p.description }}</span></td>
                <td><div class="dc-util"><div class="bar"><span :class="cls(p.utilization)" :style="{ width: `${p.utilization}%` }" /></div><span class="pct">{{ p.utilization }}%</span></div></td>
                <td class="num mono dc-small">{{ bigCount(p.size) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </LoadState>
    </section>
    <SpecForm v-if="creating && ch" title="Novo prefixo" :fields="prefixFields(ch, branches.map((b) => ({ value: b.id, label: b.name })), vlans)" :initial="{ status: 'active', vrf_id: vrf || '', branch_id: branch ?? '' }"
      :save="(v) => ipam.savePrefix(null, v)" ok-text="Prefixo cadastrado" @close="creating = false" @saved="(id) => router.push(`/ipam/prefixes/${id}`)" />
  </div>
</template>

<style scoped>
.empty { padding: 0 20px 20px; }
</style>
