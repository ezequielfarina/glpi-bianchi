<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import AuthImage from '../../components/AuthImage.vue'
import LoadState from '../../components/LoadState.vue'
import DeviceFormModal from '../../components/dcim/DeviceFormModal.vue'
import { api } from '../../lib/api'
import { dcim, dcimChoices, DEVICE_STATUS, hex, onColor, uLabel, type DcimChoices, type DeviceRow } from '../../lib/dcim'
import type { BranchRow } from '../../lib/types'

const route = useRoute()
const router = useRouter()
const qn = (k: string) => (typeof route.query[k] === 'string' && route.query[k] ? Number(route.query[k]) : null)
const branches = ref<BranchRow[]>([])
const choices = ref<DcimChoices | null>(null)
const branch = ref<number | null>(qn('branch'))
const role = ref<number | null>(qn('role'))
const status = ref(typeof route.query.status === 'string' ? route.query.status : '')
const q = ref(typeof route.query.q === 'string' ? route.query.q : '')
const items = ref<DeviceRow[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const creating = ref(false)
void api.branches().then((r) => { branches.value = r.items })
void dcimChoices().then((c) => { choices.value = c })
let t: number | undefined
async function load(): Promise<void> {
  try { items.value = (await dcim.devices({ branch: branch.value, role: role.value, status: status.value || undefined, q: q.value.trim() || undefined })).items; error.value = null }
  catch (e) { error.value = e instanceof Error ? e.message : String(e) } finally { loading.value = false }
  void router.replace({ query: Object.fromEntries(Object.entries({ branch: branch.value, role: role.value, status: status.value, q: q.value.trim() }).filter(([, v]) => v)) as Record<string, string> })
}
watch([branch, role, status], load, { immediate: true })
watch(q, () => { window.clearTimeout(t); t = window.setTimeout(load, 250) })
</script>

<template>
  <div class="dc-page">
    <header class="dc-head">
      <div><h1>Equipamentos</h1><p class="muted">{{ items.length }} equipamento(s) documentado(s): switches, patch panels, tomadas, servidores, estações…</p></div>
      <div class="actions"><button class="edit-only btn btn-primary" type="button" @click="creating = true"><AppIcon name="plus" :size="16" /> Novo equipamento</button></div>
    </header>
    <section class="panel">
      <div class="dc-filters">
        <select v-model="branch" class="input" aria-label="Filial"><option :value="null">Todas as filiais</option><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
        <select v-model="role" class="input" aria-label="Função"><option :value="null">Todas as funções</option><option v-for="r in choices?.roles ?? []" :key="r.id" :value="r.id">{{ r.name }}</option></select>
        <select v-model="status" class="input" aria-label="Situação"><option value="">Qualquer situação</option><option v-for="o in choices?.device_status ?? []" :key="o.value" :value="o.value">{{ o.label }}</option></select>
        <input v-model="q" class="input search" type="search" placeholder="Nome, série, patrimônio, modelo, ativo…" aria-label="Buscar equipamento" />
      </div>
      <LoadState :loading="loading && !items.length" :error="error" :rows="6" @retry="load">
        <p v-if="!items.length" class="empty">Nenhum equipamento{{ branch || role || status || q ? ' com esses filtros' : '' }}.</p>
        <div v-else class="table-wrap">
          <table class="table">
            <thead><tr><th /><th>Equipamento</th><th>Função</th><th>Tipo</th><th>Onde</th><th>IP primário</th><th class="num">Portas</th><th>Situação</th></tr></thead>
            <tbody>
              <tr v-for="d in items" :key="d.id" class="dc-clickable" @click="router.push(`/dcim/devices/${d.id}`)">
                <td class="ph"><span class="thumb"><AuthImage v-if="d.photo" :uuid="d.photo" :alt="d.name" /><span v-else class="dc-dot" :style="{ background: hex(d.color) }" /></span></td>
                <td><RouterLink :to="`/dcim/devices/${d.id}`" class="dc-strong" @click.stop>{{ d.name }}</RouterLink>
                  <div v-if="d.asset_uuid" class="dc-small"><RouterLink :to="`/assets/${d.asset_uuid}`" class="dc-link" @click.stop><AppIcon name="pc" :size="12" /> {{ d.asset_name }}</RouterLink></div></td>
                <td><span class="dc-role" :style="{ background: hex(d.color), color: onColor(d.color) }">{{ d.role }}</span></td>
                <td class="muted">{{ d.manufacturer }} {{ d.device_type }}</td>
                <td>{{ d.branch_code ?? d.branch }}<span class="muted">{{ d.location ? ' · ' + d.location : '' }}{{ d.rack ? ` · ${d.rack} ${uLabel(d.position)}` : '' }}</span></td>
                <td class="mono">{{ d.primary_ip }}</td>
                <td class="num dc-nowrap"><span :class="{ muted: !d.connected }">{{ d.connected }}/{{ d.ports }}</span></td>
                <td><span class="pill" :class="DEVICE_STATUS[d.status]?.cls">{{ DEVICE_STATUS[d.status]?.label ?? d.status }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </LoadState>
    </section>
    <DeviceFormModal v-if="creating" :preset="{ branch_id: branch ?? undefined }" @close="creating = false" @saved="(id) => router.push(`/dcim/devices/${id}`)" />
  </div>
</template>

<style scoped>
.ph { width: 44px; }
.thumb { width: 36px; height: 36px; border-radius: 6px; background: var(--panel-hover); display: grid; place-items: center; overflow: hidden; }
.thumb :deep(img) { width: 100%; height: 100%; object-fit: cover; }
.empty { padding: 0 20px 20px; }
</style>
