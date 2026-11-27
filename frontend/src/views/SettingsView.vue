<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import AppIcon from '../components/AppIcon.vue'
import LookupEditor from '../components/LookupEditor.vue'
import LoadState from '../components/LoadState.vue'
import { api } from '../lib/api'
import { dateTime } from '../lib/format'
import { notify } from '../lib/toast'
import type { Lookups, SettingItem } from '../lib/types'
import { useAsync } from '../lib/useAsync'

const { data, loading, error, reload } = useAsync(() => api.settings())
const form = reactive<Record<string, number | string>>({})
const lookups = ref<Lookups | null>(null)
async function loadLookups(): Promise<void> { lookups.value = await api.lookups() }
void loadLookups()
const saving = ref(false)

watch(data, (d) => {
  if (!d) return
  for (const [k, v] of Object.entries(d.items)) form[k] = v.value
}, { immediate: true })

const entries = computed(() => Object.entries(data.value?.items ?? {}) as [string, SettingItem][])
const dirty = computed(() => entries.value.some(([k, v]) => form[k] !== v.value))

async function save(): Promise<void> {
  saving.value = true
  try {
    data.value = await api.saveSettings({ ...form })
    notify('ok', 'Configurações salvas')
  } catch (e) {
    notify('warn', 'Não foi possível salvar', e instanceof Error ? e.message : String(e))
  } finally {
    saving.value = false
  }
}

const testing = ref(false)
async function testLabel(): Promise<void> {
  testing.value = true
  try {
    const r = await api.labelPrinterTest()
    notify('ok', 'Etiqueta de teste enviada', `${r.printer} · ${Math.round(r.bytes / 1024)} KB`)
  } catch (e) {
    notify('warn', 'A impressora não respondeu', e instanceof Error ? e.message : String(e))
  } finally {
    testing.value = false
  }
}

function restore(key: string, item: SettingItem): void {
  form[key] = item.default
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>Configurações</h1>
        <p class="muted">Coleta do inventário, patrimônio, impressora de etiquetas e cadastros auxiliares</p>
      </div>
    </header>

    <LoadState :loading="loading" :error="error" :rows="4" @retry="reload">
      <form class="panel form" @submit.prevent="save">
        <div v-for="[key, item] in entries" :key="key" class="field">
          <div class="field-head">
            <label :for="key">{{ item.label }}</label>
            <span v-if="item.updatedAt" class="muted small">alterado em {{ dateTime(item.updatedAt) }}</span>
          </div>
          <div class="field-row">
            <select v-if="item.options" :id="key" v-model="form[key]" class="input" :aria-describedby="`${key}-help`">
              <option v-for="(l, k) in item.options" :key="k" :value="k">{{ l }}</option>
            </select>
            <input
              v-else-if="item.type === 'string'"
              :id="key"
              v-model="form[key]"
              class="input mono"
              :maxlength="item.max"
              :aria-describedby="`${key}-help`"
            />
            <input
              v-else
              :id="key"
              v-model.number="form[key]"
              class="input num"
              type="number"
              :min="item.min"
              :max="item.max"
              step="1"
              required
              :aria-describedby="`${key}-help`"
            />
            <span v-if="item.unit" class="muted">{{ item.unit }}</span>
            <button v-if="form[key] !== item.default" type="button" class="btn" @click="restore(key, item)">
              Restaurar padrão ({{ item.options?.[String(item.default)] ?? (item.default === '' ? 'vazio' : item.default) }})
            </button>
            <button v-if="key === 'label.printer_host' && item.value" type="button" class="admin-only btn" :disabled="testing || dirty" :title="dirty ? 'Salve antes de testar' : ''" @click="testLabel">
              <AppIcon name="printer" :size="15" /> {{ testing ? 'Enviando…' : 'Imprimir etiqueta de teste' }}
            </button>
          </div>
          <p :id="`${key}-help`" class="help muted">{{ item.help }}</p>
        </div>
        <div class="actions">
          <button class="admin-only btn btn-primary" type="submit" :disabled="saving || !dirty">
            {{ saving ? 'Salvando…' : 'Salvar' }}
          </button>
        </div>
      </form>
    </LoadState>

    <h2 class="section-title">Cadastros auxiliares</h2>
    <div v-if="lookups" class="lookups edit-only">
      <LookupEditor kind="license_types" title="Tipos de licença" :items="lookups.license_types" @changed="loadLookups" />
      <LookupEditor kind="license_categories" title="Categorias de licença" :items="lookups.license_categories" @changed="loadLookups" />
      <LookupEditor kind="contract_types" title="Tipos de contrato" :items="lookups.contract_types" @changed="loadLookups" />
    </div>

    <section class="panel info">
      <div class="panel-head"><h2><AppIcon name="bolt" :size="16" /> Como funciona a coleta</h2></div>
      <div class="panel-body">
        <ul>
          <li>
            <strong>Frequência:</strong> a cada contato o servidor informa ao GLPI Agent o intervalo até o próximo
            (<code class="mono">expiration</code>). Alterar aqui vale a partir do <em>próximo</em> contato de cada máquina —
            agentes que acabaram de reportar só verão o novo valor no ciclo seguinte. Use <strong>Forçar inventário</strong> para aplicar já.
          </li>
          <li>
            <strong>Forçar inventário</strong> (telas Agentes e Ativo): o servidor chama
            <code class="mono">http://&lt;ip-do-agente&gt;:{{ form['agent.http_port'] ?? 62354 }}/now</code>. Requer:
            <ol>
              <li>firewall liberando TCP {{ form['agent.http_port'] ?? 62354 }} <em>deste servidor → estação</em> (Windows Defender Firewall e FortiGate entre VLANs);</li>
              <li>o IP deste servidor confiável no agente (<code class="mono">httpd-trust</code>; por padrão o agente já confia no host configurado em <code class="mono">server</code>).</li>
            </ol>
          </li>
          <li>
            Na própria máquina, sem painel: <code class="mono">glpi-agent --force</code> ou abrir
            <code class="mono">http://localhost:62354/now</code>.
          </li>
        </ul>
      </div>
    </section>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 20px; max-width: 1200px; }
.form { max-width: 920px; }
.section-title { margin-top: 8px; }
.lookups { display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 16px; align-items: start; }
.page-head p { margin: 4px 0 0; font-size: 13px; }
.form { padding: 8px 24px 20px; }
.field { padding: 18px 0; border-bottom: 1px solid var(--border-soft); }
.field-head { display: flex; justify-content: space-between; gap: 12px; align-items: baseline; flex-wrap: wrap; }
.field-head label { font-weight: 600; }
.field-row { display: flex; align-items: center; gap: 10px; margin-top: 10px; flex-wrap: wrap; }
.field-row .input { width: 140px; }
.help { margin: 8px 0 0; font-size: 12.5px; max-width: 680px; }
.small { font-size: 12px; }
.actions { display: flex; justify-content: flex-end; padding-top: 16px; }
.actions .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.info h2 { display: inline-flex; align-items: center; gap: 8px; }
.info ul { margin: 0; padding-left: 18px; display: flex; flex-direction: column; gap: 10px; color: var(--text-2); font-size: 13px; }
.info ol { margin: 6px 0 0; padding-left: 18px; }
.info code { font-size: 12px; }
</style>
