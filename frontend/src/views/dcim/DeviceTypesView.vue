<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import AuthImage from '../../components/AuthImage.vue'
import LoadState from '../../components/LoadState.vue'
import SpecForm from '../../components/dcim/SpecForm.vue'
import { dcim, hex, onColor, type DeviceRole, type DeviceTypeRow } from '../../lib/dcim'
import { notify } from '../../lib/toast'
import { typeFields } from './typeFields'

const router = useRouter()
const tab = ref<'types' | 'roles'>('types')
const q = ref('')
const types = ref<DeviceTypeRow[]>([])
const roles = ref<DeviceRole[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const creating = ref(false)
const role = ref<DeviceRole | null | 'new'>(null)
let t: number | undefined
async function load(): Promise<void> {
  try { [types.value, roles.value] = await Promise.all([dcim.types(q.value.trim() || undefined).then((r) => r.items), dcim.roles().then((r) => r.items)]); error.value = null }
  catch (e) { error.value = e instanceof Error ? e.message : String(e) } finally { loading.value = false }
}
void load()
watch(q, () => { window.clearTimeout(t); t = window.setTimeout(load, 250) })
async function removeRole(r: DeviceRole): Promise<void> {
  if (!window.confirm(`Excluir a função "${r.name}"?`)) return
  await dcim.deleteRole(r.id)
  notify('ok', 'Função excluída')
  await load()
}
const roleFields = [
  { key: 'name', label: 'Nome', required: true },
  { key: 'color', label: 'Cor', type: 'color' as const },
  { key: 'description', label: 'Descrição', full: true },
]
</script>

<template>
  <div class="dc-page">
    <header class="dc-head">
      <div><h1>Tipos de equipamento</h1><p class="muted">Modelos com as portas já definidas: ao cadastrar um equipamento, as portas são criadas a partir do modelo.</p></div>
      <div class="actions">
        <button v-if="tab === 'types'" class="edit-only btn btn-primary" type="button" @click="creating = true"><AppIcon name="plus" :size="16" /> Novo tipo</button>
        <button v-else class="edit-only btn btn-primary" type="button" @click="role = 'new'"><AppIcon name="plus" :size="16" /> Nova função</button>
      </div>
    </header>
    <section class="panel">
      <div class="tabs dc-tabs" role="tablist">
        <button type="button" role="tab" class="tab" :class="{ active: tab === 'types' }" @click="tab = 'types'">Tipos <span class="count">{{ types.length }}</span></button>
        <button type="button" role="tab" class="tab" :class="{ active: tab === 'roles' }" @click="tab = 'roles'">Funções <span class="count">{{ roles.length }}</span></button>
      </div>
      <LoadState :loading="loading" :error="error" :rows="5" @retry="load">
        <template v-if="tab === 'types'">
          <div class="dc-filters"><input v-model="q" class="input search" type="search" placeholder="Fabricante ou modelo…" aria-label="Buscar tipo" /></div>
          <div class="table-wrap"><table class="table">
            <thead><tr><th /><th>Modelo</th><th>Função padrão</th><th class="num">U</th><th class="num">Interfaces</th><th class="num">Frontais/Traseiras</th><th class="num">Energia</th><th class="num">Em uso</th></tr></thead>
            <tbody><tr v-for="x in types" :key="x.id" class="dc-clickable" @click="router.push(`/dcim/device-types/${x.id}`)">
              <td class="ph"><span class="thumb"><AuthImage v-if="x.photo" :uuid="x.photo" :alt="x.model" /><AppIcon v-else name="layers" :size="16" /></span></td>
              <td><span class="muted">{{ x.manufacturer }}</span> <RouterLink :to="`/dcim/device-types/${x.id}`" class="dc-strong" @click.stop>{{ x.model }}</RouterLink>
                <span v-if="x.part_number" class="muted mono dc-small"> {{ x.part_number }}</span></td>
              <td><span v-if="x.default_role" class="dc-role" :style="{ background: hex(x.color), color: onColor(x.color) }">{{ x.default_role }}</span></td>
              <td class="num">{{ x.u_height }}</td><td class="num">{{ x.interfaces || '' }}</td>
              <td class="num">{{ x.front_ports || x.rear_ports ? `${x.front_ports}/${x.rear_ports}` : '' }}</td><td class="num">{{ x.power_ports || '' }}</td><td class="num">{{ x.devices || '' }}</td>
            </tr></tbody>
          </table></div>
        </template>
        <div v-else class="table-wrap"><table class="table">
          <thead><tr><th>Função</th><th>Descrição</th><th class="num">Equipamentos</th><th /></tr></thead>
          <tbody><tr v-for="r in roles" :key="r.id">
            <td><span class="dc-role" :style="{ background: hex(r.color), color: onColor(r.color) }">{{ r.name }}</span></td>
            <td class="muted">{{ r.description }}</td>
            <td class="num"><RouterLink v-if="r.devices" :to="`/dcim/devices?role=${r.id}`" class="dc-link">{{ r.devices }}</RouterLink></td>
            <td class="dc-actions-row"><button class="edit-only btn btn-icon" type="button" :aria-label="`Editar ${r.name}`" @click="role = r"><AppIcon name="edit" :size="15" /></button>
              <button class="edit-only btn btn-icon" type="button" :aria-label="`Excluir ${r.name}`" :disabled="r.devices > 0" @click="removeRole(r)"><AppIcon name="trash" :size="15" /></button></td>
          </tr></tbody>
        </table></div>
      </LoadState>
    </section>
    <SpecForm v-if="creating" ctx="type" title="Novo tipo de equipamento" :fields="typeFields(roles)" :initial="{ u_height: '1', is_full_depth: true }"
      :save="(v) => dcim.saveType(null, v)" ok-text="Tipo cadastrado" @close="creating = false" @saved="(id) => router.push(`/dcim/device-types/${id}`)" />
    <SpecForm v-if="role" ctx="role" :title="role === 'new' ? 'Nova função' : `Editar ${role.name}`" :fields="roleFields" :initial="role === 'new' ? { color: '2196f3' } : { ...role }"
      :save="(v) => dcim.saveRole(role === 'new' ? null : (role as DeviceRole).id, v)" ok-text="Função salva" @close="role = null" @saved="role = null; load()" />
  </div>
</template>

<style scoped>
.ph { width: 44px; }
.thumb { width: 36px; height: 36px; border-radius: 6px; background: var(--panel-hover); display: grid; place-items: center; overflow: hidden; color: var(--text-3); }
.thumb :deep(img) { width: 100%; height: 100%; object-fit: cover; }
</style>
