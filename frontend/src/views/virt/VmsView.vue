<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import LoadState from '../../components/LoadState.vue'
import VmFormModal from '../../components/virt/VmFormModal.vue'
import { api } from '../../lib/api'
import type { BranchRow } from '../../lib/types'
import { gb, num, platformBadge, virt, VM_STATUS, type ClusterRow, type VmRow } from '../../lib/virt'

const route = useRoute()
const router = useRouter()
const branches = ref<BranchRow[]>([])
const clusters = ref<ClusterRow[]>([])
const branch = ref<number | null>(null)
const cluster = ref<number | null>(typeof route.query.cluster === 'string' ? Number(route.query.cluster) : null)
const status = ref('')
const q = ref(typeof route.query.q === 'string' ? route.query.q : '')
const items = ref<VmRow[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const creating = ref(false)
void api.branches().then((r) => { branches.value = r.items })
void virt.clusters().then((r) => { clusters.value = r.items })
let t: number | undefined
async function load(): Promise<void> {
  try { items.value = (await virt.vms({ branch: branch.value, cluster: cluster.value, status: status.value || undefined, q: q.value.trim() || undefined })).items; error.value = null }
  catch (e) { error.value = e instanceof Error ? e.message : String(e) } finally { loading.value = false }
}
watch([branch, cluster, status], load, { immediate: true })
watch(q, () => { window.clearTimeout(t); t = window.setTimeout(load, 250) })
</script>

<template>
  <div class="dc-page">
    <header class="dc-head">
      <div><h1>Máquinas virtuais</h1><p class="muted">{{ items.length }} VM(s) · em qual virtualizador e servidor físico estão, com os recursos.</p></div>
      <div class="actions"><button class="edit-only btn btn-primary" type="button" @click="creating = true"><AppIcon name="plus" :size="16" /> Nova VM</button></div>
    </header>
    <section class="panel">
      <div class="dc-filters">
        <select v-model="cluster" class="input" aria-label="Cluster"><option :value="null">Todos os clusters</option><option v-for="c in clusters" :key="c.id" :value="c.id">{{ c.name }}</option></select>
        <select v-model="branch" class="input" aria-label="Filial"><option :value="null">Todas as filiais</option><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
        <select v-model="status" class="input" aria-label="Situação"><option value="">Qualquer situação</option><option v-for="(s, k) in VM_STATUS" :key="k" :value="k">{{ s.label }}</option></select>
        <input v-model="q" class="input search" type="search" placeholder="Nome, SO, VMID, IP…" aria-label="Buscar VM" />
      </div>
      <LoadState :loading="loading && !items.length" :error="error" :rows="6" @retry="load">
        <p v-if="!items.length" class="empty">Nenhuma VM{{ q || cluster || branch || status ? ' com esses filtros' : '' }}.</p>
        <div v-else class="table-wrap"><table class="table">
          <thead><tr><th>VM</th><th>Virtualizador</th><th>Host físico</th><th>SO</th><th class="num">vCPU</th><th class="num">RAM</th><th class="num">Disco</th><th>IP</th><th>Situação</th></tr></thead>
          <tbody><tr v-for="v in items" :key="v.id" class="dc-clickable" @click="router.push(`/virtualization/vms/${v.id}`)">
            <td><span class="vmn"><span class="led" :class="v.status" /><RouterLink :to="`/virtualization/vms/${v.id}`" class="dc-strong" @click.stop>{{ v.name }}</RouterLink></span>
              <div v-if="v.description" class="muted dc-small">{{ v.description }}</div></td>
            <td class="dc-nowrap"><b class="plat" :style="{ background: platformBadge(v.cluster_type).color }">{{ platformBadge(v.cluster_type).short }}</b> {{ v.cluster }}</td>
            <td><RouterLink v-if="v.device_id" :to="`/dcim/devices/${v.device_id}`" class="dc-link" @click.stop>{{ v.host }}</RouterLink><span v-else class="muted">—</span></td>
            <td class="muted">{{ v.platform }}</td><td class="num">{{ num(v.vcpus) }}</td><td class="num dc-nowrap">{{ gb(v.memory_mb) }}</td><td class="num dc-nowrap">{{ v.disk_gb ? v.disk_gb + ' GB' : '—' }}</td>
            <td class="mono">{{ v.primary_ip?.split('/')[0] }}</td>
            <td><span class="pill" :class="VM_STATUS[v.status]?.cls">{{ VM_STATUS[v.status]?.label }}</span></td>
          </tr></tbody>
        </table></div>
      </LoadState>
    </section>
    <VmFormModal v-if="creating" :preset="{ cluster_id: cluster ?? undefined }" @close="creating = false" @saved="(id) => router.push(`/virtualization/vms/${id}`)" />
  </div>
</template>

<style scoped>
.empty { padding: 0 20px 20px; }
.vmn { display: inline-flex; align-items: center; gap: 8px; }
.led { width: 8px; height: 8px; border-radius: 50%; background: var(--text-3); }
.led.active { background: var(--ok); box-shadow: 0 0 8px var(--ok); }
.led.failed { background: var(--bad); box-shadow: 0 0 8px var(--bad); }
.plat { color: #fff; font-size: 10px; padding: 1px 6px; border-radius: 5px; }
</style>
