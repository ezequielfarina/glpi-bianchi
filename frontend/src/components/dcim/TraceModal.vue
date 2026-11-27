<script setup lang="ts">
import { ref } from 'vue'
import AppModal from '../AppModal.vue'
import AppIcon from '../AppIcon.vue'
import LoadState from '../LoadState.vue'
import TraceView from './TraceView.vue'
import { dcim, type Trace } from '../../lib/dcim'

// Trace de uma porta ou terminação de circuito, em diálogo.
const props = defineProps<{ portId?: number | null; circuitTerminationId?: number | null; title?: string }>()
const emit = defineEmits<{ close: [] }>()
const trace = ref<Trace | null>(null)
const error = ref<string | null>(null)
const loading = ref(true)
async function load(): Promise<void> {
  loading.value = true
  try { trace.value = props.circuitTerminationId ? await dcim.traceCircuit(props.circuitTerminationId) : await dcim.tracePort(props.portId ?? 0); error.value = null }
  catch (e) { error.value = e instanceof Error ? e.message : String(e) }
  finally { loading.value = false }
}
void load()
const meters = (m: number | null) => (m === null ? null : m >= 1000 ? `${(m / 1000).toFixed(2).replace('.', ',')} km` : `${String(+m.toFixed(2)).replace('.', ',')} m`)
</script>

<template>
  <AppModal :title="title ?? 'Rastreamento do cabo'" wide @close="emit('close')">
    <LoadState :loading="loading" :error="error" :rows="4" @retry="load">
      <template v-if="trace">
        <div class="summary">
          <span class="pill" :class="trace.complete ? 'pill-ok' : 'pill-warn'">{{ trace.complete ? 'Caminho completo' : trace.cables ? 'Caminho incompleto' : 'Sem cabo' }}</span>
          <span v-if="trace.cables" class="pill" :class="trace.active ? 'pill-ok' : 'pill-info'">{{ trace.active ? 'Ativo' : 'Tem trecho planejado/desativando' }}</span>
          <span v-if="trace.split" class="pill pill-bad" title="O caminho bifurca (tronco multiposição sem saber a posição de origem) ou entra em laço.">Bifurcação</span>
          <span class="muted">{{ trace.cables }} cabo(s)<template v-if="trace.length_m !== null"> · {{ meters(trace.length_m) }} documentados</template></span>
        </div>
        <p v-if="!trace.cables" class="empty"><AppIcon name="alert" :size="16" /> Esta porta não tem cabo documentado.</p>
        <TraceView v-else :trace="trace" :highlight="trace.origin.id" @navigate="emit('close')" />
      </template>
    </LoadState>
  </AppModal>
</template>

<style scoped>
.summary { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 16px; font-size: 13px; }
.empty { display: flex; gap: 6px; align-items: center; justify-content: center; }
</style>
