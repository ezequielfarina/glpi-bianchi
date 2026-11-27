<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import AppIcon from '../AppIcon.vue'
import AppModal from '../AppModal.vue'
import { api } from '../../lib/api'
import { dateOnly } from '../../lib/format'
import type { StockShipment } from '../../lib/types'

/**
 * Etiqueta de envio da saída para filial (modelo "Entrega_TI": remetente, destinatário, filial em destaque,
 * setor A/C pessoa e logo). Impressão pelo navegador com @page no tamanho exato da etiqueta — pensada para
 * impressora térmica (Argox): preto puro, logo em P&B, sem margens do navegador.
 */
const props = defineProps<{ batch: string; departments?: string[]; justSaved?: boolean }>()
const emit = defineEmits<{ close: [] }>()
const KEY = 'bianchi.label'
type Saved = { sender: string; width: number; height: number; logo: boolean }
const saved: Saved = (() => {
  try { return { sender: 'TI', width: 105, height: 75, logo: true, ...JSON.parse(localStorage.getItem(KEY) ?? '{}') } } catch { return { sender: 'TI', width: 105, height: 75, logo: true } }
})()
const ship = ref<StockShipment | null>(null)
const error = ref<string | null>(null)
const f = reactive({ sender: saved.sender, destination: '', department: '', attention: '', volumes: 1, width: saved.width, height: saved.height, logo: saved.logo })
const frame = ref<HTMLIFrameElement | null>(null)

api.stockShipment(props.batch).then((s) => {
  ship.value = s
  const name = (s.branch ?? '').toUpperCase()
  f.destination = s.branch_code && !name.includes(s.branch_code.toUpperCase()) ? `${name} - ${s.branch_code.toUpperCase()}` : name
  f.department = (s.department ?? '').toUpperCase()
  f.attention = (s.recipient ?? '').toUpperCase()
}).catch((e) => { error.value = e instanceof Error ? e.message : String(e) })

watch(() => [f.sender, f.width, f.height, f.logo], () => {
  try { localStorage.setItem(KEY, JSON.stringify({ sender: f.sender, width: f.width, height: f.height, logo: f.logo })) } catch { /* sem storage */ }
})

const esc = (s: string) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c] as string)
// fonte do destino encolhe para caber numa linha (texto em caixa alta, fonte pesada ≈ 0,72 em por caractere)
function fit(text: string, maxPt: number, minPt: number, widthMm: number): number {
  const chars = Math.max(text.length, 1)
  return Math.max(minPt, Math.min(maxPt, ((widthMm - 6) / (chars * 0.72)) * 2.835))
}
const html = computed(() => {
  const w = Math.min(Math.max(f.width || 105, 40), 120)
  const h = Math.min(Math.max(f.height || 75, 25), 200)
  const vols = Math.min(Math.max(Math.round(f.volumes || 1), 1), 50)
  const dest = f.destination.trim() || '—'
  const ac = [f.department.trim(), f.attention.trim() ? `A/C ${f.attention.trim()}` : (f.department.trim() ? 'A/C' : '')].filter(Boolean).join(' ')
  const logo = `${location.origin}/app/brand/logo-etiqueta.png`
  const scale = h / 75
  const page = (i: number) => `<section class="label">
      <p class="sm"><b>REMETENTE:</b> ${esc(f.sender.trim().toUpperCase())}</p>
      <p class="sm"><b>DESTINATÁRIO:</b></p>
      <p class="dest" style="font-size:${fit(dest, 30 * scale, 11, w).toFixed(1)}pt">${esc(dest)}</p>
      ${ac ? `<p class="ac" style="font-size:${fit(ac, 17 * scale, 8, w).toFixed(1)}pt">${esc(ac)}</p>` : ''}
      ${vols > 1 ? `<p class="vol">VOLUME ${i}/${vols}</p>` : ''}
      ${f.logo ? `<img class="logo" src="${logo}" alt="">` : ''}
    </section>`
  return `<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Etiqueta</title><style>
    @page { size: ${w}mm ${h}mm; margin: 0; }
    * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    html, body { margin: 0; padding: 0; background: #fff; color: #000; font-family: Arial, Helvetica, sans-serif; }
    .label { position: relative; width: ${w}mm; height: ${h}mm; padding: ${(4 * scale).toFixed(1)}mm 3mm 0; text-align: center; overflow: hidden; page-break-after: always; break-after: page; }
    .label:last-child { page-break-after: auto; break-after: auto; }
    p { margin: 0; line-height: 1.15; }
    .sm { font-size: ${(11 * scale).toFixed(1)}pt; font-weight: 700; }
    .sm + .sm { margin-bottom: ${(3.5 * scale).toFixed(1)}mm; }
    .dest { font-family: "Arial Black", "Archivo Black", Arial, sans-serif; font-weight: 900; white-space: nowrap; letter-spacing: 0.01em; }
    .ac { margin-top: ${(0.8 * scale).toFixed(1)}mm; white-space: nowrap; }
    .vol { position: absolute; left: 3mm; bottom: 3mm; font-size: ${(9 * scale).toFixed(1)}pt; font-weight: 700; }
    .logo { position: absolute; right: 3mm; bottom: 3mm; width: ${(31 * scale).toFixed(1)}mm; filter: grayscale(1) contrast(1.4); }
    @media screen { body { background: #e9e9e9; } .label { background: #fff; margin: 0 auto 6px; box-shadow: 0 1px 3px rgba(0,0,0,.25); } }
  </style></head><body>${Array.from({ length: vols }, (_, i) => page(i + 1)).join('')}</body></html>`
})
const frameStyle = computed(() => ({ width: `${Math.min(Math.max(f.width || 105, 40), 120) * 3.78 + 4}px`, height: `${Math.min(Math.max(f.height || 75, 25), 200) * 3.78 + 8}px` }))

function print(): void {
  const win = frame.value?.contentWindow
  if (!win) return
  const imgs = Array.from(win.document.images)
  Promise.all(imgs.map((i) => (i.complete ? Promise.resolve() : new Promise((r) => { i.onload = r; i.onerror = r })))).then(() => {
    win.focus()
    win.print()
  })
}
</script>

<template>
  <AppModal :title="justSaved ? 'Saída registrada — etiqueta de envio' : 'Etiqueta de envio'" wide @close="emit('close')">
    <p v-if="error" class="err">{{ error }}</p>
    <div v-else-if="ship" class="wrap">
      <div class="form">
        <p class="muted small">
          {{ dateOnly(ship.occurred_at) }} · {{ ship.items.map((i) => `${i.quantity}× ${i.item}`).join(', ') }}
        </p>
        <div class="field"><label for="lb-dest">Destinatário (filial)</label><input id="lb-dest" v-model="f.destination" class="input" /></div>
        <div class="row">
          <div class="field"><label for="lb-dep">Setor</label><input id="lb-dep" v-model="f.department" class="input" list="lb-deps" />
            <datalist id="lb-deps"><option v-for="d in departments ?? []" :key="d" :value="d" /></datalist></div>
          <div class="field"><label for="lb-ac">A/C (pessoa)</label><input id="lb-ac" v-model="f.attention" class="input" /></div>
        </div>
        <div class="row">
          <div class="field"><label for="lb-snd">Remetente</label><input id="lb-snd" v-model="f.sender" class="input" /></div>
          <div class="field"><label for="lb-vol">Volumes</label><input id="lb-vol" v-model.number="f.volumes" class="input num" type="number" min="1" max="50" /></div>
        </div>
        <details class="size">
          <summary>Tamanho da etiqueta: {{ f.width }} × {{ f.height }} mm</summary>
          <div class="row">
            <div class="field"><label for="lb-w">Largura (mm)</label><input id="lb-w" v-model.number="f.width" class="input num" type="number" min="40" max="120" /></div>
            <div class="field"><label for="lb-h">Altura (mm)</label><input id="lb-h" v-model.number="f.height" class="input num" type="number" min="25" max="200" /></div>
          </div>
          <label class="check"><input v-model="f.logo" type="checkbox" /> Imprimir logo</label>
          <p class="hint">No driver da Argox, use o mesmo tamanho de papel e margens zero; no diálogo de impressão, escala 100% (ou "Tamanho real").</p>
        </details>
        <p class="hint">As alterações valem só para esta impressão; remetente e tamanho ficam salvos neste navegador.</p>
      </div>
      <div class="preview">
        <iframe ref="frame" title="Prévia da etiqueta" :srcdoc="html" :style="frameStyle" />
      </div>
    </div>
    <p v-else class="muted">Carregando…</p>
    <div class="form-actions">
      <button class="btn" type="button" @click="emit('close')">Fechar</button>
      <button class="btn btn-primary" type="button" :disabled="!ship" @click="print"><AppIcon name="printer" :size="16" /> Imprimir etiqueta</button>
    </div>
  </AppModal>
</template>

<style scoped>
.wrap { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 20px; align-items: start; }
.form { display: flex; flex-direction: column; gap: 12px; }
.row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.preview { background: var(--surface-2, #e9e9e9); border-radius: var(--radius-sm); padding: 10px; max-height: 70vh; overflow: auto; }
.preview iframe { border: 0; display: block; background: #e9e9e9; }
.size summary { cursor: pointer; font-size: 13px; color: var(--text-2); }
.size[open] { display: flex; flex-direction: column; gap: 10px; }
.small { font-size: 12px; margin: 0; }
.hint { margin: 0; }
.err { color: var(--warn-text); }
@media (max-width: 900px) { .wrap { grid-template-columns: 1fr; } }
</style>
