<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import AttachmentsPanel from '../../components/AttachmentsPanel.vue'
import LoadState from '../../components/LoadState.vue'
import PhotoGallery from '../../components/dcim/PhotoGallery.vue'
import PortFormModal from '../../components/dcim/PortFormModal.vue'
import SpecForm from '../../components/dcim/SpecForm.vue'
import { dcim, dcimChoices, hex, onColor, PORT_KIND_TABS, type DeviceRole, type PortTemplate } from '../../lib/dcim'
import { notify } from '../../lib/toast'
import { useAsync } from '../../lib/useAsync'
import { typeFields } from './typeFields'

const props = defineProps<{ id: string }>()
const router = useRouter()
const { data: t, loading, error, reload } = useAsync(() => dcim.type(Number(props.id)))
const roles = ref<DeviceRole[]>([])
const typeLabels = ref<Record<string, Record<string, string>>>({})
void dcimChoices().then((c) => { roles.value = c.roles; typeLabels.value = Object.fromEntries(Object.entries(c.port_types).map(([k, list]) => [k, Object.fromEntries(list.map((o) => [o.value, o.label]))])) })
const editing = ref(false)
const adding = ref(false)
const editTpl = ref<PortTemplate | null>(null)
const groups = computed(() => PORT_KIND_TABS.map((g) => ({ ...g, items: (t.value?.templates ?? []).filter((p) => g.kinds.includes(p.kind)) })).filter((g) => g.items.length))
const rears = computed(() => (t.value?.templates ?? []).filter((p) => p.kind === 'rear').map((p) => ({ id: p.id, name: p.name, positions: p.positions })))
async function removeTpl(p: PortTemplate): Promise<void> {
  if (!window.confirm(`Excluir a porta "${p.name}" do modelo? (equipamentos já criados não mudam)`)) return
  await dcim.deleteTemplate(p.id)
  await reload()
}
async function remove(): Promise<void> {
  if (!t.value || !window.confirm(`Excluir o tipo "${t.value.model}"?`)) return
  await dcim.deleteType(t.value.id)
  notify('ok', 'Tipo excluído')
  await router.push('/dcim/device-types')
}
</script>

<template>
  <div class="dc-page">
    <RouterLink to="/dcim/device-types" class="dc-back"><AppIcon name="back" :size="16" /> Tipos de equipamento</RouterLink>
    <LoadState :loading="loading" :error="error" :rows="6" @retry="reload">
      <template v-if="t">
        <header class="dc-head">
          <div>
            <p class="muted">{{ t.manufacturer }}</p>
            <h1>{{ t.model }} <span v-if="t.default_role" class="dc-role" :style="{ background: hex(t.color), color: onColor(t.color) }">{{ t.default_role }}</span>
              <span class="pill pill-muted">{{ t.u_height }}U{{ t.is_full_depth ? ' · profundidade total' : '' }}</span></h1>
            <p class="muted">{{ t.part_number ? t.part_number + ' · ' : '' }}<RouterLink :to="`/dcim/devices?type=${t.id}`" class="dc-link">{{ t.devices }} equipamento(s)</RouterLink></p>
          </div>
          <div class="actions">
            <button class="edit-only btn btn-primary" type="button" @click="adding = true"><AppIcon name="plus" :size="15" /> Portas</button>
            <button class="edit-only btn" type="button" @click="editing = true"><AppIcon name="edit" :size="15" /> Editar</button>
            <button class="edit-only btn btn-icon" type="button" aria-label="Excluir tipo" :disabled="t.devices > 0" @click="remove"><AppIcon name="trash" :size="15" /></button>
          </div>
        </header>
        <div class="dc-cols">
          <div>
            <section class="panel">
              <div class="panel-head"><h2>Modelo de portas <span class="muted">({{ t.templates.length }})</span></h2><span class="muted dc-small">Mudanças valem para equipamentos criados depois.</span></div>
              <p v-if="!t.templates.length" class="empty">Sem portas. Ex.: interfaces "Gi1/0/[1-48]", ou "Par frontal + traseira" "[1-24]" para patch panel.</p>
              <div v-for="g in groups" :key="g.key" class="grp">
                <h3>{{ g.label }} <span class="muted">({{ g.items.length }})</span></h3>
                <div class="table-wrap"><table class="table">
                  <thead><tr><th>Nome</th><th>Tipo físico</th><th>Detalhes</th><th /></tr></thead>
                  <tbody><tr v-for="p in g.items" :key="p.id">
                    <td class="mono">{{ p.name }}<span v-if="p.label" class="muted"> ({{ p.label }})</span></td>
                    <td class="muted">{{ typeLabels[p.kind]?.[p.type] ?? p.type }}</td>
                    <td class="dc-small">
                      <template v-if="p.kind === 'front' && p.rear_port">→ traseira <span class="mono">{{ p.rear_port }}{{ p.rear_position && p.rear_position > 1 ? ':' + p.rear_position : '' }}</span></template>
                      <template v-if="p.kind === 'rear' && p.positions > 1">{{ p.positions }} posições</template>
                      <template v-if="p.maximum_draw">máx. {{ p.maximum_draw }} W</template><template v-if="p.allocated_draw"> · alocado {{ p.allocated_draw }} W</template>
                      <template v-if="p.mgmt_only">gerência</template></td>
                    <td class="dc-actions-row"><button class="edit-only btn btn-icon" type="button" :aria-label="`Editar ${p.name}`" @click="editTpl = p"><AppIcon name="edit" :size="14" /></button>
                      <button class="edit-only btn btn-icon" type="button" :aria-label="`Excluir ${p.name}`" @click="removeTpl(p)"><AppIcon name="trash" :size="14" /></button></td>
                  </tr></tbody>
                </table></div>
              </div>
            </section>
          </div>
          <div>
            <section v-if="t.description || t.comments" class="panel"><div class="panel-head"><h2>Descrição</h2></div>
              <p class="txt">{{ t.description }}</p><p v-if="t.comments" class="txt pre muted">{{ t.comments }}</p></section>
            <PhotoGallery owner-type="device_type" :owner="t.id" :items="t.attachments" title="Fotos do modelo (frente/traseira)" @changed="reload" />
            <AttachmentsPanel owner-type="device_type" :owner="t.id" :items="t.attachments.filter((a) => a.kind === 'document')" title="Manuais e datasheets" @changed="reload" />
          </div>
        </div>
        <SpecForm v-if="editing" :title="`Editar ${t.model}`" :fields="typeFields(roles)"
          :initial="{ manufacturer: t.manufacturer, model: t.model, part_number: t.part_number ?? '', u_height: String(t.u_height), default_role_id: t.default_role_id ?? '', is_full_depth: t.is_full_depth, description: t.description ?? '', comments: t.comments ?? '' }"
          :save="(v) => dcim.saveType(t!.id, v)" ok-text="Tipo atualizado" @close="editing = false" @saved="editing = false; reload()" />
        <PortFormModal v-if="adding" target="type" :rears="rears" :save="(b) => dcim.addTemplates(t!.id, b)" @close="adding = false" @saved="adding = false; reload()" />
        <PortFormModal v-if="editTpl" target="type" :port="editTpl" :rears="rears" :save="(b) => dcim.saveTemplate(editTpl!.id, b)" @close="editTpl = null" @saved="editTpl = null; reload()" />
      </template>
    </LoadState>
  </div>
</template>

<style scoped>
.empty { padding: 0 20px 16px; margin: 0; }
.grp h3 { margin: 4px 20px 6px; font-size: 13px; font-weight: 600; color: var(--text-2); }
.grp { margin-bottom: 12px; }
.txt { padding: 0 20px; margin: 0 0 12px; font-size: 13.5px; }
.pre { white-space: pre-wrap; }
</style>
