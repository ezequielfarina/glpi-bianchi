<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import AttachmentsPanel from '../../components/AttachmentsPanel.vue'
import LoadState from '../../components/LoadState.vue'
import CableEnds from '../../components/dcim/CableEnds.vue'
import CableModal from '../../components/dcim/CableModal.vue'
import PhotoGallery from '../../components/dcim/PhotoGallery.vue'
import TraceView from '../../components/dcim/TraceView.vue'
import { CABLE_STATUS, dcim, hex, length, type Trace } from '../../lib/dcim'
import { notify } from '../../lib/toast'
import { useAsync } from '../../lib/useAsync'

const props = defineProps<{ id: string }>()
const router = useRouter()
const trace = ref<Trace | null>(null)
const { data: c, loading, error, reload } = useAsync(async () => {
  const cable = await dcim.cable(Number(props.id))
  const a = cable.a[0]
  trace.value = a?.port_id ? await dcim.tracePort(a.port_id) : a?.circuit_termination_id ? await dcim.traceCircuit(a.circuit_termination_id) : null
  return cable
})
const editing = ref(false)
async function remove(): Promise<void> {
  if (!c.value || !window.confirm(`Excluir o cabo ${c.value.label ?? '#' + c.value.id}? As portas ficam livres.`)) return
  await dcim.deleteCable(c.value.id)
  notify('ok', 'Cabo excluído')
  await router.push('/dcim/cables')
}
</script>

<template>
  <div class="dc-page">
    <RouterLink to="/dcim/cables" class="dc-back"><AppIcon name="back" :size="16" /> Cabos</RouterLink>
    <LoadState :loading="loading" :error="error" :rows="6" @retry="reload">
      <template v-if="c">
        <header class="dc-head">
          <div>
            <h1><span class="dc-wire big" :style="{ background: hex(c.color, '64748b') }" /> {{ c.label ?? `Cabo #${c.id}` }}
              <span class="pill" :class="CABLE_STATUS[c.status]?.cls">{{ CABLE_STATUS[c.status]?.label }}</span></h1>
            <p class="muted">{{ [c.type_label, length(c.length, c.length_unit), c.description].filter(Boolean).join(' · ') }}</p>
          </div>
          <div class="actions">
            <button class="edit-only btn" type="button" @click="editing = true"><AppIcon name="edit" :size="15" /> Editar</button>
            <button class="edit-only btn btn-icon" type="button" aria-label="Excluir cabo" @click="remove"><AppIcon name="trash" :size="15" /></button>
          </div>
        </header>
        <div class="dc-cols">
          <div>
            <section class="panel">
              <div class="panel-head"><h2>Caminho completo</h2>
                <span v-if="trace" class="muted dc-small">{{ trace.complete ? 'completo' : 'incompleto' }} · {{ trace.cables }} cabo(s)</span></div>
              <div class="tr"><TraceView v-if="trace" :trace="trace" /><p v-else class="muted">Sem terminações.</p></div>
            </section>
          </div>
          <div>
            <section class="panel">
              <div class="panel-head"><h2>Pontas</h2></div>
              <dl class="dc-kv"><dt>A</dt><dd><CableEnds :ends="c.a" /></dd><dt>B</dt><dd><CableEnds :ends="c.b" /></dd>
                <template v-if="c.comments"><dt>Observações</dt><dd class="pre">{{ c.comments }}</dd></template></dl>
            </section>
            <PhotoGallery owner-type="cable" :owner="c.id" :items="c.attachments ?? []" @changed="reload" />
            <AttachmentsPanel owner-type="cable" :owner="c.id" :items="(c.attachments ?? []).filter((a) => a.kind === 'document')" title="Documentos (certificação, teste)" @changed="reload" />
          </div>
        </div>
        <CableModal v-if="editing" :cable="c" @close="editing = false" @saved="editing = false; reload()" />
      </template>
    </LoadState>
  </div>
</template>

<style scoped>
.tr { padding: 0 20px 20px; }
.big { width: 30px; height: 10px; }
.pre { white-space: pre-wrap; }
</style>
