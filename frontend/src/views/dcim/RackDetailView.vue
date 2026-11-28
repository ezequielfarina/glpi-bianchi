<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import AttachmentsPanel from '../../components/AttachmentsPanel.vue'
import LoadState from '../../components/LoadState.vue'
import DeviceFormModal from '../../components/dcim/DeviceFormModal.vue'
import PhotoGallery from '../../components/dcim/PhotoGallery.vue'
import RackElevation from '../../components/dcim/RackElevation.vue'
import SpecForm from '../../components/dcim/SpecForm.vue'
import { dcim, DEVICE_STATUS, hex, onColor, uLabel } from '../../lib/dcim'
import { canEdit } from '../../lib/session'
import { notify } from '../../lib/toast'
import { useAsync } from '../../lib/useAsync'
import { rackFields } from './rackFields'

const props = defineProps<{ id: string }>()
const router = useRouter()
const { data: r, loading, error, reload } = useAsync(() => dcim.rack(Number(props.id)))
const editing = ref(false)
const adding = ref<{ position?: number; face?: string } | null>(null)
const all = computed(() => (r.value ? [...r.value.devices].sort((a, b) => (b.position ?? 0) - (a.position ?? 0)) : []))
async function remove(): Promise<void> {
  if (!r.value || !window.confirm(`Excluir o rack "${r.value.name}"?`)) return
  await dcim.deleteRack(r.value.id)
  notify('ok', 'Rack excluído')
  await router.push('/dcim/racks')
}
</script>

<template>
  <div class="dc-page">
    <RouterLink to="/dcim/racks" class="dc-back"><AppIcon name="back" :size="16" /> Racks</RouterLink>
    <LoadState :loading="loading" :error="error" :rows="6" @retry="reload">
      <template v-if="r">
        <header class="dc-head">
          <div>
            <p class="muted">{{ r.branch }}<template v-if="r.location"> › <RouterLink :to="`/dcim/locations/${r.location_id}`" class="dc-link">{{ r.location }}</RouterLink></template></p>
            <h1>{{ r.name }} <span class="pill pill-muted">{{ r.u_height }}U · {{ r.width }}"</span><span v-if="r.status !== 'active'" class="pill pill-info">{{ r.status }}</span></h1>
            <p class="muted">{{ r.used_u }}U ocupadas ({{ r.utilization }}%) · {{ r.devices.length + r.unracked.length }} equipamento(s)</p>
          </div>
          <div class="actions">
            <button class="edit-only btn btn-primary" type="button" @click="adding = {}"><AppIcon name="plus" :size="15" /> Equipamento</button>
            <button class="edit-only btn" type="button" @click="editing = true"><AppIcon name="edit" :size="15" /> Editar</button>
            <button class="edit-only btn btn-icon" type="button" aria-label="Excluir rack" @click="remove"><AppIcon name="trash" :size="15" /></button>
          </div>
        </header>
        <div class="dc-cols">
          <div>
            <section class="panel">
              <div class="panel-head"><h2>Elevação</h2><span class="muted dc-small">{{ canEdit ? 'Clique numa U vazia para instalar um equipamento.' : '' }}</span></div>
              <div class="faces">
                <div><h3>Frontal</h3><RackElevation :rack="r" face="front" :can-add="canEdit" @add="(u) => adding = { position: u, face: 'front' }" @open="(id) => router.push(`/dcim/devices/${id}`)" /></div>
                <div><h3>Traseira</h3><RackElevation :rack="r" face="rear" :can-add="canEdit" @add="(u) => adding = { position: u, face: 'rear' }" @open="(id) => router.push(`/dcim/devices/${id}`)" /></div>
              </div>
            </section>
            <section class="panel">
              <div class="panel-head"><h2>Equipamentos</h2></div>
              <div class="table-wrap"><table class="table">
                <thead><tr><th>U</th><th>Equipamento</th><th>Função</th><th>Tipo</th><th>IP</th><th>Situação</th></tr></thead>
                <tbody>
                  <tr v-for="d in [...all, ...r.unracked]" :key="d.id" class="dc-clickable" @click="router.push(`/dcim/devices/${d.id}`)">
                    <td class="mono dc-nowrap">{{ d.position !== null ? uLabel(d.position) + (d.face === 'rear' ? ' T' : '') : '0U' }}</td>
                    <td><RouterLink :to="`/dcim/devices/${d.id}`" class="dc-strong" @click.stop>{{ d.name }}</RouterLink></td>
                    <td><span class="dc-role" :style="{ background: hex(d.color), color: onColor(d.color) }">{{ d.role }}</span></td>
                    <td class="muted">{{ d.manufacturer }} {{ d.device_type }}</td>
                    <td class="mono">{{ d.primary_ip }}</td>
                    <td><span class="pill" :class="DEVICE_STATUS[d.status]?.cls">{{ DEVICE_STATUS[d.status]?.label ?? d.status }}</span></td>
                  </tr>
                </tbody>
              </table></div>
            </section>
          </div>
          <div>
            <section class="panel">
              <div class="panel-head"><h2>Dados</h2></div>
              <dl class="dc-kv">
                <dt>Filial</dt><dd>{{ r.branch }}</dd>
                <dt>Local</dt><dd>{{ r.location ?? '—' }}</dd>
                <dt>Função</dt><dd>{{ r.role ?? '—' }}</dd>
                <dt>Identificação</dt><dd class="mono">{{ r.facility_id ?? '—' }}</dd>
                <dt>Série / patrimônio</dt><dd class="mono">{{ r.serial ?? '—' }} / {{ r.asset_tag ?? '—' }}</dd>
                <dt>Unidades</dt><dd>U{{ r.starting_unit }}–U{{ r.starting_unit + r.u_height - 1 }}{{ r.desc_units ? ' (de cima para baixo)' : '' }}</dd>
                <dt>Profundidade</dt><dd>{{ r.outer_depth_mm ? r.outer_depth_mm + ' mm' : '—' }}</dd>
                <template v-if="r.description"><dt>Descrição</dt><dd>{{ r.description }}</dd></template>
                <template v-if="r.comments"><dt>Observações</dt><dd class="pre">{{ r.comments }}</dd></template>
              </dl>
            </section>
            <PhotoGallery owner-type="rack" :owner="r.id" :items="r.attachments" @changed="reload" />
            <AttachmentsPanel owner-type="rack" :owner="r.id" :items="r.attachments.filter((a) => a.kind === 'document')" title="Documentos" @changed="reload" />
          </div>
        </div>
        <SpecForm v-if="editing" ctx="rack" :title="`Editar ${r.name}`" :fields="rackFields()" :initial="{ name: r.name, status: r.status, u_height: r.u_height, width: r.width, starting_unit: r.starting_unit, outer_depth_mm: r.outer_depth_mm ?? '', role: r.role ?? '',
            facility_id: r.facility_id ?? '', serial: r.serial ?? '', asset_tag: r.asset_tag ?? '', desc_units: r.desc_units, description: r.description ?? '', comments: r.comments ?? '',
            branch_id: r.branch_id, location_id: r.location_id ?? '' }"
          :save="(v) => dcim.saveRack(r!.id, v)" ok-text="Rack atualizado" @close="editing = false" @saved="editing = false; reload()" />
        <DeviceFormModal v-if="adding" :preset="{ branch_id: r.branch_id, location_id: r.location_id, rack_id: r.id, position: adding.position, face: adding.face }"
          @close="adding = null" @saved="adding = null; reload()" />
      </template>
    </LoadState>
  </div>
</template>

<style scoped>
.faces { display: flex; gap: 40px; padding: 0 20px 20px; flex-wrap: wrap; }
.faces > div { flex: 1; min-width: 240px; }
.faces h3 { margin: 0 0 8px 26px; font-size: 13px; color: var(--text-2); font-weight: 600; }
.pre { white-space: pre-wrap; }
</style>
