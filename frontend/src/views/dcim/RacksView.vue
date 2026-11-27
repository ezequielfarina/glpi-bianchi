<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import AuthImage from '../../components/AuthImage.vue'
import LoadState from '../../components/LoadState.vue'
import SpecForm from '../../components/dcim/SpecForm.vue'
import { api } from '../../lib/api'
import { dcim, type LocationRow, type RackRow } from '../../lib/dcim'
import type { BranchRow } from '../../lib/types'
import { rackFields } from './rackFields'

const router = useRouter()
const branches = ref<BranchRow[]>([])
const locations = ref<LocationRow[]>([])
const branch = ref<number | null>(null)
const q = ref('')
const items = ref<RackRow[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const creating = ref(false)
void api.branches().then((r) => { branches.value = r.items })
void dcim.locations().then((r) => { locations.value = r.items })
let t: number | undefined
async function load(): Promise<void> {
  try { items.value = (await dcim.racks({ branch: branch.value, q: q.value.trim() || undefined })).items; error.value = null } catch (e) { error.value = e instanceof Error ? e.message : String(e) } finally { loading.value = false }
}
watch(branch, load, { immediate: true })
watch(q, () => { window.clearTimeout(t); t = window.setTimeout(load, 250) })
const fields = computed(() => rackFields({
  branches: branches.value.map((b) => ({ value: b.id, label: b.name })),
  locations: locations.value.map((l) => ({ value: l.id, label: `${l.branch_code ?? l.branch ?? ''} · ${'— '.repeat(l.depth)}${l.name}` })),
}))
const pct = (r: RackRow) => Math.min(100, Math.round((100 * r.used_u) / r.u_height))
</script>

<template>
  <div class="dc-page">
    <header class="dc-head">
      <div><h1>Racks</h1><p class="muted">{{ items.length }} rack(s) · clique para ver a elevação frontal e traseira.</p></div>
      <div class="actions"><button class="edit-only btn btn-primary" type="button" @click="creating = true"><AppIcon name="plus" :size="16" /> Novo rack</button></div>
    </header>
    <section class="panel">
      <div class="dc-filters">
        <select v-model="branch" class="input" aria-label="Filial"><option :value="null">Todas as filiais</option><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
        <input v-model="q" class="input search" type="search" placeholder="Nome ou identificação…" aria-label="Buscar rack" />
      </div>
      <LoadState :loading="loading && !items.length" :error="error" :rows="4" @retry="load">
        <p v-if="!items.length" class="empty">Nenhum rack{{ branch || q ? ' com esses filtros' : '' }}.</p>
        <div v-else class="cards">
          <RouterLink v-for="r in items" :key="r.id" :to="`/dcim/racks/${r.id}`" class="card">
            <span class="thumb"><AuthImage v-if="r.photo" :uuid="r.photo" :alt="`Foto do ${r.name}`" /><AppIcon v-else name="server" :size="28" /></span>
            <span class="info">
              <strong>{{ r.name }}</strong>
              <span class="muted dc-small">{{ r.branch }}{{ r.location ? ' · ' + r.location : '' }}</span>
              <span class="dc-util"><span class="bar"><span :class="{ warn: pct(r) >= 80, bad: pct(r) >= 95 }" :style="{ width: `${pct(r)}%` }" /></span><span class="pct">{{ pct(r) }}%</span></span>
              <span class="muted dc-small">{{ r.used_u }} de {{ r.u_height }}U · {{ r.devices }} equipamento(s){{ r.status !== 'active' ? ' · ' + r.status : '' }}</span>
            </span>
          </RouterLink>
        </div>
      </LoadState>
    </section>
    <SpecForm v-if="creating" title="Novo rack" :fields="fields" :initial="{ branch_id: branch ?? '', u_height: 42, width: 19, starting_unit: 1, status: 'active' }"
      :save="(v) => dcim.saveRack(null, v)" ok-text="Rack cadastrado" @close="creating = false" @saved="(id) => router.push(`/dcim/racks/${id}`)" />
  </div>
</template>

<style scoped>
.cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 12px; padding: 0 20px 20px; }
.card { display: flex; gap: 12px; padding: 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); text-decoration: none; color: var(--text); background: var(--bg-elev); }
.card:hover { border-color: var(--accent); }
.thumb { width: 64px; height: 84px; border-radius: 6px; background: var(--panel-hover); display: grid; place-items: center; color: var(--text-3); overflow: hidden; flex: none; }
.thumb :deep(img) { width: 100%; height: 100%; object-fit: cover; }
.info { display: flex; flex-direction: column; gap: 4px; min-width: 0; flex: 1; }
.empty { padding: 0 20px 20px; }
</style>
