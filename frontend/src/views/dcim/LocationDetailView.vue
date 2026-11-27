<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import AttachmentsPanel from '../../components/AttachmentsPanel.vue'
import LoadState from '../../components/LoadState.vue'
import DeviceFormModal from '../../components/dcim/DeviceFormModal.vue'
import LocationForm from '../../components/dcim/LocationForm.vue'
import PhotoGallery from '../../components/dcim/PhotoGallery.vue'
import SpecForm from '../../components/dcim/SpecForm.vue'
import { dcim, DEVICE_STATUS, hex, onColor, uLabel } from '../../lib/dcim'
import { notify } from '../../lib/toast'
import { useAsync } from '../../lib/useAsync'
import { rackFields } from './rackFields'

const props = defineProps<{ id: string }>()
const router = useRouter()
const { data: l, loading, error, reload } = useAsync(() => dcim.location(Number(props.id)))
const editing = ref(false)
const child = ref(false)
const newRack = ref(false)
const newDevice = ref(false)
async function remove(): Promise<void> {
  if (!l.value || !window.confirm(`Excluir o local "${l.value.name}"?`)) return
  await dcim.deleteLocation(l.value.id)
  notify('ok', 'Local excluído')
  await router.push('/dcim/locations')
}
</script>

<template>
  <div class="dc-page">
    <RouterLink to="/dcim/locations" class="dc-back"><AppIcon name="back" :size="16" /> Locais</RouterLink>
    <LoadState :loading="loading" :error="error" :rows="6" @retry="reload">
      <template v-if="l">
        <header class="dc-head">
          <div>
            <p class="muted crumbs">{{ l.branch }}<template v-for="b in l.breadcrumb" :key="b.id"> › <RouterLink :to="`/dcim/locations/${b.id}`" class="dc-link">{{ b.name }}</RouterLink></template></p>
            <h1>{{ l.name }} <span class="pill pill-muted">{{ l.kind_label }}</span><span v-if="l.code" class="pill mono">{{ l.code }}</span></h1>
            <p v-if="l.description" class="muted">{{ l.description }}</p>
          </div>
          <div class="actions">
            <button class="edit-only btn" type="button" @click="child = true"><AppIcon name="plus" :size="15" /> Sublocal</button>
            <button class="edit-only btn" type="button" @click="newRack = true"><AppIcon name="server" :size="15" /> Rack</button>
            <button class="edit-only btn" type="button" @click="newDevice = true"><AppIcon name="plus" :size="15" /> Equipamento</button>
            <button class="edit-only btn" type="button" @click="editing = true"><AppIcon name="edit" :size="15" /> Editar</button>
            <button class="edit-only btn btn-icon" type="button" aria-label="Excluir local" @click="remove"><AppIcon name="trash" :size="15" /></button>
          </div>
        </header>
        <div class="dc-cols">
          <div>
            <section v-if="l.children.length" class="panel">
              <div class="panel-head"><h2>Dentro deste local</h2></div>
              <div class="chips"><RouterLink v-for="c in l.children" :key="c.id" :to="`/dcim/locations/${c.id}`" class="tchip">{{ c.name }}</RouterLink></div>
            </section>
            <section class="panel">
              <div class="panel-head"><h2>Racks <span class="muted">({{ l.racks.length }})</span></h2></div>
              <p v-if="!l.racks.length" class="empty">Nenhum rack aqui.</p>
              <div v-else class="table-wrap"><table class="table">
                <thead><tr><th>Rack</th><th>Local</th><th>Ocupação</th></tr></thead>
                <tbody><tr v-for="r in l.racks" :key="r.id" class="dc-clickable" @click="router.push(`/dcim/racks/${r.id}`)">
                  <td><RouterLink :to="`/dcim/racks/${r.id}`" class="dc-strong" @click.stop>{{ r.name }}</RouterLink></td><td class="muted">{{ r.location }}</td>
                  <td><div class="dc-util"><div class="bar"><span :style="{ width: `${Math.min(100, 100 * r.used_u / r.u_height)}%` }" /></div><span class="pct">{{ r.used_u }}/{{ r.u_height }}U</span></div></td>
                </tr></tbody>
              </table></div>
            </section>
            <section class="panel">
              <div class="panel-head"><h2>Equipamentos <span class="muted">({{ l.devices.length }})</span></h2></div>
              <p v-if="!l.devices.length" class="empty">Nenhum equipamento documentado aqui.</p>
              <div v-else class="table-wrap"><table class="table">
                <thead><tr><th>Equipamento</th><th>Função</th><th>Tipo</th><th>Rack</th><th>Situação</th></tr></thead>
                <tbody><tr v-for="d in l.devices" :key="d.id" class="dc-clickable" @click="router.push(`/dcim/devices/${d.id}`)">
                  <td><RouterLink :to="`/dcim/devices/${d.id}`" class="dc-strong" @click.stop>{{ d.name }}</RouterLink></td>
                  <td><span class="dc-role" :style="{ background: hex(d.color), color: onColor(d.color) }">{{ d.role }}</span></td>
                  <td class="muted">{{ d.device_type }}</td><td>{{ d.rack }} {{ uLabel(d.position) }}</td>
                  <td><span class="pill" :class="DEVICE_STATUS[d.status]?.cls">{{ DEVICE_STATUS[d.status]?.label ?? d.status }}</span></td>
                </tr></tbody>
              </table></div>
            </section>
            <section class="panel">
              <div class="panel-head"><h2>Ativos do inventário aqui <span class="muted">({{ l.assets.length }})</span></h2></div>
              <p v-if="!l.assets.length" class="empty">Nenhum ativo com este local (ajuste na ficha do ativo).</p>
              <ul v-else class="assets"><li v-for="a in l.assets" :key="a.uuid"><RouterLink :to="`/assets/${a.uuid}`" class="dc-link">{{ a.name }}</RouterLink>
                <span class="muted dc-small">{{ a.type }}{{ a.inventory_number ? ' · ' + a.inventory_number : '' }}{{ a.location !== l.name ? ' · ' + a.location : '' }}</span></li></ul>
            </section>
          </div>
          <div>
            <PhotoGallery owner-type="location" :owner="l.id" :items="l.attachments" @changed="reload" />
            <AttachmentsPanel owner-type="location" :owner="l.id" :items="l.attachments.filter((a) => a.kind === 'document')" title="Documentos e plantas" @changed="reload" />
          </div>
        </div>
        <LocationForm v-if="editing" :location="l" @close="editing = false" @saved="editing = false; reload()" />
        <LocationForm v-if="child" :preset="{ parent_id: l.id, branch_id: l.branch_id }" @close="child = false" @saved="(id) => { child = false; router.push(`/dcim/locations/${id}`) }" />
        <SpecForm v-if="newRack" title="Novo rack" :fields="rackFields()" :initial="{ branch_id: l.branch_id, location_id: l.id, u_height: 42, width: 19, starting_unit: 1, status: 'active' }"
          :save="(v) => dcim.saveRack(null, v)" ok-text="Rack cadastrado" @close="newRack = false" @saved="(id) => router.push(`/dcim/racks/${id}`)" />
        <DeviceFormModal v-if="newDevice" :preset="{ branch_id: l.branch_id ?? undefined, location_id: l.id }" @close="newDevice = false" @saved="(id) => router.push(`/dcim/devices/${id}`)" />
      </template>
    </LoadState>
  </div>
</template>

<style scoped>
.crumbs { margin: 0 0 4px !important; }
.chips { display: flex; flex-wrap: wrap; gap: 6px; padding: 0 20px 20px; }
.chips a { text-decoration: none; }
.empty { padding: 0 20px 16px; margin: 0; }
.assets { list-style: none; margin: 0; padding: 0 20px 20px; display: flex; flex-direction: column; gap: 4px; font-size: 13.5px; }
.assets li { display: flex; gap: 8px; align-items: baseline; }
</style>
