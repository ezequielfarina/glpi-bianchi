<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import { ApiError } from '../../lib/api'
import { dcim, type DeviceRow } from '../../lib/dcim'
import { notify } from '../../lib/toast'
import { TOPOLOGY, vpn, type ImportPreview } from '../../lib/vpn'

// Importar túneis IPsec colando a configuração do FortiGate (ADR-0022). O texto fica só no navegador e
// na requisição: o servidor extrai os parâmetros, descarta PSK/senhas e não guarda o texto.
const router = useRouter()
const text = ref('')
const devices = ref<DeviceRow[]>([])
const deviceId = ref<number | ''>('')
const dq = ref('')
const preview = ref<ImportPreview | null>(null)
const busy = ref<'preview' | 'apply' | null>(null)
const err = ref<string | null>(null)
let t: number | undefined
async function searchDevices(): Promise<void> {
  devices.value = (await dcim.devices({ q: dq.value.trim() || undefined })).items.slice(0, 200)
}
void searchDevices()
watch(dq, () => { window.clearTimeout(t); t = window.setTimeout(searchDevices, 250) })

// hostname do texto colado → sugere o equipamento de mesmo nome
const hostname = computed(() => /set hostname "?([^"\n]+)"?/.exec(text.value)?.[1] ?? /^([A-Za-z0-9._-]+)(?: \([^)]*\))? ?[#$] /m.exec(text.value)?.[1] ?? null)
watch(hostname, async (h) => {
  if (!h || deviceId.value) return
  const r = (await dcim.devices({ q: h })).items
  const hit = r.find((d) => d.name.toLowerCase() === h.toLowerCase())
  if (hit) { deviceId.value = hit.id; if (!devices.value.some((d) => d.id === hit.id)) devices.value = [hit, ...devices.value] }
})
watch([text, deviceId], () => { preview.value = null; err.value = null })
const size = computed(() => new Blob([text.value]).size)
const canGo = computed(() => !!deviceId.value && text.value.trim().length > 20 && size.value <= 8 * 1024 * 1024)

function onFile(e: Event): void {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  if (file.size > 8 * 1024 * 1024) { notify('warn', 'Arquivo grande demais (limite 8 MiB)'); return }
  const reader = new FileReader()
  reader.onload = () => { text.value = String(reader.result ?? '') }
  reader.readAsText(file)
  ;(e.target as HTMLInputElement).value = ''
}
async function doPreview(): Promise<void> {
  if (!canGo.value) return
  busy.value = 'preview'
  err.value = null
  try { preview.value = await vpn.preview(Number(deviceId.value), text.value) }
  catch (e) { err.value = e instanceof ApiError ? (e.fields.config_text ?? e.fields.device_id ?? e.message) : String(e) } finally { busy.value = null }
}
async function doApply(): Promise<void> {
  if (!preview.value) return
  busy.value = 'apply'
  try {
    const r = await vpn.apply(Number(deviceId.value), text.value)
    notify('ok', `Importado: ${r.created} novo(s), ${r.updated} alterado(s), ${r.unchanged} igual(is)`, r.missing ? `${r.missing} túnel(is) não estão mais na config — marcados como ausentes.` : undefined)
    text.value = ''
    void router.push('/vpn')
  } catch (e) { err.value = e instanceof Error ? e.message : String(e) } finally { busy.value = null }
}
const ACTION: Record<string, { label: string; cls: string }> = { new: { label: 'novo', cls: 'pill-ok' }, update: { label: 'alterado', cls: 'pill-warn' }, unchanged: { label: 'igual', cls: 'pill-muted' } }
const CLI = `# no FortiGate (CLI ou console web). Para incluir os valores padrão use "show full-configuration":
show full-configuration vpn ipsec phase1-interface
show full-configuration vpn ipsec phase2-interface
show firewall address
show firewall addrgrp
show router static
show system sdwan
show system interface`
async function copyCli(): Promise<void> {
  try { await navigator.clipboard.writeText(CLI); notify('ok', 'Comandos copiados') } catch { /* sem clipboard */ }
}
</script>

<template>
  <div class="dc-page">
    <header class="dc-head">
      <div><p class="crumbs"><RouterLink to="/vpn">Túneis VPN</RouterLink> › Importar</p>
        <h1>Importar túneis do FortiGate</h1>
        <p class="muted">Cole a configuração (ou o backup .conf). Fase 1, fase 2, objetos de endereço, rotas e SD-WAN viram documentação; reimportar atualiza.</p></div>
    </header>

    <div class="imp">
      <section class="panel card">
        <h2><span class="n">1</span> De qual FortiGate é a configuração?</h2>
        <div class="row">
          <input v-model="dq" class="input" type="search" placeholder="Buscar equipamento…" aria-label="Buscar equipamento" />
          <select v-model="deviceId" class="input" aria-label="FortiGate"><option value="">Escolha o equipamento…</option>
            <option v-for="d in devices" :key="d.id" :value="d.id">{{ d.name }} · {{ d.branch }}{{ d.device_type ? ` · ${d.device_type}` : '' }}</option></select>
        </div>
        <p v-if="hostname" class="hint">Hostname na configuração: <b class="mono">{{ hostname }}</b>{{ deviceId ? '' : ' — nenhum equipamento com esse nome; escolha na lista (ou cadastre em Infraestrutura → Equipamentos).' }}</p>

        <h2><span class="n">2</span> Configuração</h2>
        <textarea v-model="text" class="input cfg mono" rows="16" spellcheck="false" autocomplete="off"
          placeholder="config vpn ipsec phase1-interface&#10;    edit &quot;VPN-CVL&quot;&#10;        set interface &quot;wan1&quot;&#10;        ..." />
        <div class="row between">
          <label class="btn btn-sm file"><AppIcon name="file" :size="14" /> Abrir arquivo .conf / .txt<input type="file" accept=".conf,.txt,.cfg,text/plain" hidden @change="onFile" /></label>
          <span class="muted dc-small">{{ (size / 1024).toFixed(0) }} KiB{{ size > 8 * 1024 * 1024 ? ' — acima do limite de 8 MiB' : '' }}</span>
        </div>
        <p v-if="err" class="err">{{ err }}</p>
        <div class="form-actions"><button class="btn btn-primary" type="button" :disabled="!canGo || !!busy" @click="doPreview">
          <AppIcon name="eye" :size="15" /> {{ busy === 'preview' ? 'Lendo…' : 'Pré-visualizar' }}</button></div>
      </section>

      <aside class="panel card help">
        <h2><AppIcon name="terminal" :size="16" /> O que colar</h2>
        <pre class="cli">{{ CLI }}</pre>
        <button class="btn btn-sm" type="button" @click="copyCli"><AppIcon name="copy" :size="13" /> Copiar comandos</button>
        <p class="hint">Ou o backup completo (<i>System → Configuration → Backup</i>, sem criptografia).</p>
        <p class="sec"><AppIcon name="lock" :size="14" /><span><b>PSK e senhas não são guardadas.</b> Valores <code>ENC …</code>, <code>psksecret</code>, senhas e chaves privadas são
          descartados ao ler; o texto colado não é gravado nem vai para a auditoria. Documente só <i>onde</i> a PSK está (cofre).</span></p>
      </aside>

      <section v-if="preview" class="panel card wide">
        <h2><span class="n">3</span> Conferir e importar em <b>{{ preview.device.name }}</b>
          <span class="muted dc-small">{{ [preview.model, preview.version ? `FortiOS ${preview.version}` : null].filter(Boolean).join(' · ') }}</span></h2>
        <ul v-if="preview.warnings.length" class="warns"><li v-for="w in preview.warnings" :key="w"><AppIcon name="alert" :size="14" /><span>{{ w }}</span></li></ul>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Túnel</th><th>Tipo</th><th>Peer</th><th>IP local</th><th class="num">Fase 2</th><th class="num">Rotas</th><th /></tr></thead>
          <tbody><tr v-for="x in preview.tunnels" :key="x.vdom + x.name">
            <td class="mono">{{ x.name }}<span v-if="x.vdom" class="muted dc-small"> · VDOM {{ x.vdom }}</span></td>
            <td class="dc-small">{{ TOPOLOGY[x.topology]?.label }} · IKEv{{ x.ike_version }}</td>
            <td class="mono dc-small">{{ x.remote_gw ?? 'dinâmico' }}</td><td class="mono dc-small">{{ x.local_ip ?? '—' }}</td>
            <td class="num">{{ x.phase2 }}</td><td class="num">{{ x.routes || '' }}</td>
            <td><span class="pill" :class="ACTION[x.action]?.cls">{{ ACTION[x.action]?.label }}</span></td>
          </tr></tbody>
        </table></div>
        <p v-if="preview.missing.length" class="hint warn-t">Já documentados e <b>fora</b> desta configuração (ficam marcados como ausentes, sem apagar): {{ preview.missing.map((m) => m.name).join(', ') }}.</p>
        <div class="form-actions"><button class="btn" type="button" @click="preview = null">Voltar</button>
          <button class="btn btn-primary" type="button" :disabled="!!busy" @click="doApply"><AppIcon name="upload" :size="15" /> {{ busy === 'apply' ? 'Importando…' : `Importar ${preview.tunnels.length} túnel(is)` }}</button></div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.crumbs { margin: 0 0 4px; font-size: 12.5px; color: var(--text-3); }
.imp { display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 16px; align-items: start; }
.wide { grid-column: 1 / -1; }
.card { padding: 16px 18px; display: grid; gap: 10px; min-width: 0; }
.card h2 { display: flex; align-items: center; gap: 8px; margin: 6px 0 0; font-size: 15px; }
.n { display: inline-grid; place-items: center; width: 22px; height: 22px; border-radius: 50%; background: var(--accent-soft); color: var(--accent-text); font-size: 12px; }
.row { display: flex; gap: 8px; align-items: center; }
.row .input { flex: 1; }
.between { justify-content: space-between; }
.cfg { font-size: 12px; line-height: 1.45; min-height: 260px; resize: vertical; white-space: pre; }
.file { cursor: pointer; }
.cli { margin: 0; padding: 10px 12px; border-radius: 10px; background: var(--bg, #0b0f14); border: 1px solid var(--border); font-size: 11.5px; line-height: 1.5; overflow: auto; white-space: pre; }
.help .btn { justify-self: start; }
.sec { display: flex; gap: 8px; margin: 4px 0 0; padding: 10px 12px; border-radius: 10px; background: var(--ok-soft); font-size: 12.5px; line-height: 1.45; }
.sec :deep(svg) { flex: none; margin-top: 2px; color: var(--ok); }
.warns { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
.warns li { display: flex; gap: 8px; align-items: flex-start; padding: 8px 10px; border-radius: 10px; background: var(--warn-soft); color: var(--warn-text); font-size: 13px; }
.warns :deep(svg) { flex: none; margin-top: 2px; }
.warn-t { color: var(--warn-text); }
.err { color: var(--bad-text); margin: 0; }
@media (max-width: 1100px) { .imp { grid-template-columns: 1fr; } }
</style>
