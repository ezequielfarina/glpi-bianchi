<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import LoadState from '../../components/LoadState.vue'
import LocationForm from '../../components/dcim/LocationForm.vue'
import { api } from '../../lib/api'
import { dcim, type LocationRow } from '../../lib/dcim'
import type { BranchRow } from '../../lib/types'

const router = useRouter()
const branches = ref<BranchRow[]>([])
const branch = ref<number | null>(null)
const items = ref<LocationRow[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const q = ref('')
const creating = ref(false)
void api.branches().then((r) => { branches.value = r.items })
async function load(): Promise<void> {
  loading.value = true
  try { items.value = (await dcim.locations(branch.value)).items; error.value = null } catch (e) { error.value = e instanceof Error ? e.message : String(e) } finally { loading.value = false }
}
watch(branch, load, { immediate: true })
const ICON: Record<string, 'building' | 'map' | 'server' | 'pc' | 'pin'> = { building: 'building', floor: 'map', room: 'pin', datacenter: 'server', workstation: 'pc', other: 'pin' }
const shown = computed(() => {
  const t = q.value.trim().toLowerCase()
  return t ? items.value.filter((l) => l.name.toLowerCase().includes(t) || (l.code ?? '').toLowerCase().includes(t)) : items.value
})
const groups = computed(() => {
  const g = new Map<string, LocationRow[]>()
  for (const l of shown.value) { const k = l.branch ?? 'Sem filial'; g.set(k, [...(g.get(k) ?? []), l]) }
  return [...g.entries()]
})
</script>

<template>
  <div class="dc-page">
    <header class="dc-head">
      <div><h1>Locais</h1><p class="muted">Prédios, andares, salas, CPD e postos de trabalho de cada filial.</p></div>
      <div class="actions"><button class="edit-only btn btn-primary" type="button" @click="creating = true"><AppIcon name="plus" :size="16" /> Novo local</button></div>
    </header>
    <section class="panel">
      <div class="dc-filters">
        <select v-model="branch" class="input" aria-label="Filial"><option :value="null">Todas as filiais</option><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
        <input v-model="q" class="input search" type="search" placeholder="Buscar local…" aria-label="Buscar local" />
      </div>
      <LoadState :loading="loading && !items.length" :error="error" :rows="5" @retry="load">
        <div v-if="!items.length" class="dc-empty-cta">
          <p class="muted">Nenhum local cadastrado. Comece pelo prédio da matriz e, dentro dele, o CPD e os andares.</p>
          <button class="edit-only btn btn-primary" type="button" @click="creating = true"><AppIcon name="plus" :size="16" /> Cadastrar o primeiro local</button>
        </div>
        <div v-else class="table-wrap">
          <table class="table">
            <thead><tr><th>Local</th><th>Tipo</th><th class="num">Racks</th><th class="num">Equipamentos</th><th class="num">Ativos</th><th class="num">Fotos</th></tr></thead>
            <tbody v-for="[name, list] in groups" :key="name">
              <tr class="grp"><td colspan="6"><AppIcon name="building" :size="14" /> {{ name }}</td></tr>
              <tr v-for="l in list" :key="l.id" class="dc-clickable" @click="router.push(`/dcim/locations/${l.id}`)">
                <td><span :style="{ paddingLeft: `${(q ? 0 : l.depth) * 20}px` }" class="lname"><AppIcon :name="ICON[l.kind] ?? 'pin'" :size="15" />
                  <RouterLink :to="`/dcim/locations/${l.id}`" class="dc-strong" @click.stop>{{ l.name }}</RouterLink><span v-if="l.code" class="muted mono dc-small">{{ l.code }}</span></span></td>
                <td class="muted">{{ l.kind_label }}</td>
                <td class="num">{{ l.racks || '' }}</td><td class="num">{{ l.devices || '' }}</td><td class="num">{{ l.assets || '' }}</td><td class="num">{{ l.photos || '' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </LoadState>
    </section>
    <LocationForm v-if="creating" :preset="{ branch_id: branch }" @close="creating = false" @saved="(id) => { creating = false; router.push(`/dcim/locations/${id}`) }" />
  </div>
</template>

<style scoped>
.grp td { background: var(--panel-hover); font-weight: 600; font-size: 12.5px; color: var(--text-2); }
.lname { display: inline-flex; align-items: center; gap: 8px; color: var(--text-3); }
</style>
