<script setup lang="ts">
import { reactive, ref } from 'vue'
import AppIcon from '../AppIcon.vue'
import { api, ApiError } from '../../lib/api'
import { isAdmin } from '../../lib/session'
import { labelPrinter, loadLabelPrinter } from '../../lib/stock'
import { notify } from '../../lib/toast'

// Impressora de etiquetas em uso + edição rápida do IP/porta/linguagem (Administrador) — mesmo dado de Configurações.
const editing = ref(false)
const saving = ref(false)
const err = ref<string | null>(null)
const f = reactive({ host: '', port: 9100, language: 'ppla' })
const LANG: Record<string, string> = { ppla: 'PPLA', ppla_inv: 'PPLA (inverter imagem)', pplb: 'PPLB', zpl: 'PPLZ / ZPL' }

void loadLabelPrinter()
function open(): void {
  const p = labelPrinter.value
  f.host = p?.printer?.split(':')[0] ?? ''
  f.port = Number(p?.printer?.split(':')[1] ?? 9100)
  f.language = p?.language ?? 'ppla'
  err.value = null
  editing.value = true
}
async function save(): Promise<void> {
  saving.value = true
  err.value = null
  try {
    await api.saveSettings({ 'label.printer_host': f.host.trim(), 'label.printer_port': f.port, 'label.printer_language': f.language })
    await loadLabelPrinter(true)
    notify('ok', f.host.trim() ? `Impressora de etiquetas: ${f.host.trim()}:${f.port}` : 'Impressão direta desligada')
    editing.value = false
  } catch (e) {
    err.value = e instanceof ApiError ? Object.values(e.fields ?? {})[0] ?? e.message : e instanceof Error ? e.message : String(e)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="chip-wrap">
    <p v-if="!editing" class="chip">
      <AppIcon name="printer" :size="14" />
      <template v-if="labelPrinter?.configured">Impressora: <strong class="mono">{{ labelPrinter.printer }}</strong> · {{ LANG[labelPrinter.language] ?? labelPrinter.language }}</template>
      <template v-else>Impressora de etiquetas não configurada</template>
      <button v-if="isAdmin" class="link-btn" type="button" @click="open">{{ labelPrinter?.configured ? 'Alterar' : 'Configurar' }}</button>
    </p>
    <form v-else class="edit" novalidate @submit.prevent="save">
      <div class="field"><label for="lpc-host">IP da impressora</label><input id="lpc-host" v-model="f.host" class="input mono" placeholder="192.168.0.253" /></div>
      <div class="field port"><label for="lpc-port">Porta</label><input id="lpc-port" v-model.number="f.port" class="input num" type="number" min="1" max="65535" /></div>
      <div class="field"><label for="lpc-lang">Linguagem</label>
        <select id="lpc-lang" v-model="f.language" class="input"><option v-for="(l, k) in LANG" :key="k" :value="k">{{ l }}</option></select></div>
      <div class="actions">
        <button class="btn" type="button" @click="editing = false">Cancelar</button>
        <button class="btn btn-primary" type="submit" :disabled="saving">{{ saving ? 'Salvando…' : 'Salvar' }}</button>
      </div>
      <p v-if="err" class="err">{{ err }}</p>
      <p class="hint">Vazio desliga a impressão direta. Tamanho, resolução e remetente ficam em Configurações.</p>
    </form>
  </div>
</template>

<style scoped>
.chip { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin: 0; font-size: 12.5px; color: var(--text-3); }
.edit { display: grid; grid-template-columns: minmax(0, 1.4fr) 90px minmax(0, 1fr) auto; gap: 10px; align-items: end; padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); }
.actions { display: flex; gap: 6px; }
.err, .hint { grid-column: 1 / -1; margin: 0; font-size: 12px; }
.err { color: var(--warn-text); }
</style>
