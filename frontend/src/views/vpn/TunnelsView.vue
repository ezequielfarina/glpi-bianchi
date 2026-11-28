<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import LoadState from '../../components/LoadState.vue'
import { api } from '../../lib/api'
import { relative } from '../../lib/format'
import { canEdit } from '../../lib/session'
import type { BranchRow } from '../../lib/types'
import { TOPOLOGY, TSTATUS, vpn, type TunnelRow } from '../../lib/vpn'

// Túneis VPN IPsec (ADR-0022): por FortiGate, com o outro lado, criptografia e redes.
const router = useRouter()
const items = ref<TunnelRow[]>([])
const branches = ref<BranchRow[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const q = ref('')
const branch = ref<number | null>(null)
const status = ref('')
const topology = ref('')
void api.branches().then((r) => { branches.value = r.items })
let t: number | undefined
async function load(): Promise<void> {
  try {
    items.value = (await vpn.tunnels({ q: q.value.trim() || undefined, branch: branch.value, status: status.value || undefined, topology: topology.value || undefined })).items
    error.value = null
  } catch (e) { error.value = e instanceof Error ? e.message : String(e) } finally { loading.value = false }
}
watch([branch, status, topology], load, { immediate: true })
watch(q, () => { window.clearTimeout(t); t = window.setTimeout(load, 250) })

const groups = computed(() => {
  const out = new Map<string, { device: string; branch: string | null; device_id: number; rows: TunnelRow[] }>()
  for (const r of items.value) {
    const g = out.get(String(r.device_id)) ?? { device: r.device, branch: r.branch, device_id: r.device_id, rows: [] }
    g.rows.push(r)
    out.set(String(r.device_id), g)
  }
  return [...out.values()]
})
const stats = computed(() => ({
  total: items.value.length, paired: items.value.filter((r) => r.peer_tunnel_id).length, absent: items.value.filter((r) => !r.present).length,
  devices: new Set(items.value.map((r) => r.device_id)).size,
}))
const otherSide = (r: TunnelRow) => r.peer_device ? `${r.peer_device}${r.peer_branch ? ` · ${r.peer_branch}` : ''}` : r.peer_label ?? r.supplier ?? null
const cipher = (r: TunnelRow) => [`IKEv${r.ike_version ?? 1}`, r.p1_proposal?.split(' ')[0], r.p1_dhgroups ? `DH ${r.p1_dhgroups.split(' ')[0]}` : null].filter(Boolean).join(' · ')
</script>

<template>
  <div class="dc-page">
    <header class="dc-head">
      <div><h1>Túneis VPN (IPsec)</h1>
        <p class="muted">{{ stats.total }} túnel(is) em {{ stats.devices }} FortiGate(s) · {{ stats.paired }} com os dois lados no inventário{{ stats.absent ? ` · ${stats.absent} ausente(s) na última importação` : '' }}.</p></div>
      <div class="actions"><RouterLink v-if="canEdit" to="/vpn/import" class="btn btn-primary"><AppIcon name="upload" :size="16" /> Importar do FortiGate</RouterLink></div>
    </header>
    <section class="panel">
      <div class="dc-filters">
        <select v-model="branch" class="input" aria-label="Filial"><option :value="null">Todas as filiais</option><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
        <select v-model="topology" class="input" aria-label="Tipo"><option value="">Todos os tipos</option><option v-for="(v, k) in TOPOLOGY" :key="k" :value="k">{{ v.label }}</option></select>
        <select v-model="status" class="input" aria-label="Situação"><option value="">Qualquer situação</option><option v-for="(v, k) in TSTATUS" :key="k" :value="k">{{ v.label }}</option></select>
        <input v-model="q" class="input search" type="search" placeholder="Túnel, IP do peer, rede (192.168.30.), parceiro…" aria-label="Buscar túnel" />
      </div>
      <LoadState :loading="loading && !items.length" :error="error" :rows="6" @retry="load">
        <div v-if="!items.length" class="empty-state">
          <AppIcon name="tunnel" :size="40" />
          <h2>{{ q || branch || status || topology ? 'Nada com esses filtros' : 'Nenhum túnel documentado' }}</h2>
          <p v-if="!(q || branch || status || topology)" class="muted">Importe a configuração de cada FortiGate: os túneis, as fases 2, as rotas e o outro lado entram sozinhos.</p>
          <RouterLink v-if="canEdit && !(q || branch || status || topology)" to="/vpn/import" class="btn btn-primary">Importar do FortiGate</RouterLink>
        </div>
        <template v-for="g in groups" v-else :key="g.device_id">
          <div class="grp"><AppIcon name="shield" :size="15" /> <RouterLink :to="`/dcim/devices/${g.device_id}`" class="dc-strong">{{ g.device }}</RouterLink>
            <span class="muted">{{ g.branch }}</span><span class="muted dc-small">· {{ g.rows.length }} túnel(is)</span></div>
          <div class="table-wrap"><table class="table">
            <thead><tr><th>Túnel</th><th>Outro lado</th><th>Peer (remote-gw)</th><th>Criptografia</th><th class="num">Fase 2</th><th class="num">Rotas</th><th>Situação</th></tr></thead>
            <tbody><tr v-for="r in g.rows" :key="r.id" class="dc-clickable" :class="{ absent: !r.present }" @click="router.push(`/vpn/tunnels/${r.id}`)">
              <td><RouterLink :to="`/vpn/tunnels/${r.id}`" class="dc-strong mono" @click.stop>{{ r.name }}</RouterLink>
                <span class="tag">{{ TOPOLOGY[r.topology]?.short }}</span><span v-if="r.vdom" class="tag">VDOM {{ r.vdom }}</span><span v-if="r.mode === 'policy'" class="tag">policy</span>
                <div v-if="r.description" class="muted dc-small">{{ r.description }}</div></td>
              <td><span v-if="r.peer_tunnel_id" class="paired"><AppIcon name="tunnel" :size="13" /> {{ otherSide(r) }}</span>
                <span v-else-if="otherSide(r)">{{ otherSide(r) }}</span><span v-else class="muted">—</span>
                <div v-if="r.peer_tunnel" class="muted dc-small mono">{{ r.peer_tunnel }}</div></td>
              <td class="mono dc-small">{{ r.remote_gw ?? (r.topology === 'dialup' || r.topology === 'hub' ? 'dinâmico' : '—') }}<div class="muted">via {{ r.local_interface }}{{ r.local_ip ? ` (${r.local_ip})` : '' }}</div></td>
              <td class="dc-small">{{ cipher(r) }}</td>
              <td class="num">{{ r.phase2_count }}</td><td class="num">{{ r.routes.length || '' }}</td>
              <td><span class="pill" :class="TSTATUS[r.status]?.cls">{{ TSTATUS[r.status]?.label }}</span>
                <div v-if="!r.present" class="warn-t dc-small" :title="`importado ${relative(r.imported_at)}`">fora da última config</div></td>
            </tr></tbody>
          </table></div>
        </template>
      </LoadState>
    </section>
  </div>
</template>

<style scoped>
.grp { display: flex; align-items: center; gap: 8px; padding: 16px 20px 6px; border-top: 1px solid var(--border); }
.grp:first-of-type { border-top: 0; }
.tag { display: inline-block; margin-left: 6px; font-size: 10.5px; padding: 1px 6px; border-radius: 6px; background: var(--muted-soft); color: var(--text-2); vertical-align: 1px; }
.paired { display: inline-flex; align-items: center; gap: 5px; color: var(--ok); font-weight: 600; }
.absent { opacity: .55; }
.warn-t { color: var(--warn-text); }
.empty-state { display: grid; justify-items: center; gap: 8px; padding: 40px 20px; text-align: center; color: var(--text-2); }
.empty-state h2 { margin: 4px 0 0; }
</style>
