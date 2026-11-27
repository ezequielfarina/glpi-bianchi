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
// v5: papel = o do driver da Argox (USER 102,4 × 74,9 mm). O Chrome só usa o papel do driver quando o
// @page pede exatamente esse tamanho; sem tamanho ele cai no padrão dele (A4/Carta) e divide a etiqueta.
const KEY = 'bianchi.label.v5'
type Saved = { sender: string; logo: boolean; width: number; height: number }
const DEFAULTS: Saved = { sender: 'TI', logo: true, width: 102.4, height: 74.9 }
const saved: Saved = (() => {
  try { return { ...DEFAULTS, ...JSON.parse(localStorage.getItem(KEY) ?? '{}') } } catch { return { ...DEFAULTS } }
})()
const ship = ref<StockShipment | null>(null)
const error = ref<string | null>(null)
const f = reactive({ sender: saved.sender, destination: '', department: '', attention: '', volumes: 1, logo: saved.logo, width: saved.width, height: saved.height })
const frame = ref<HTMLIFrameElement | null>(null)

api.stockShipment(props.batch).then((s) => {
  ship.value = s
  const name = (s.branch ?? '').toUpperCase()
  f.destination = s.branch_code && !name.includes(s.branch_code.toUpperCase()) ? `${name} - ${s.branch_code.toUpperCase()}` : name
  f.department = (s.department ?? '').toUpperCase()
  f.attention = (s.recipient ?? '').toUpperCase()
}).catch((e) => { error.value = e instanceof Error ? e.message : String(e) })

watch(() => [f.sender, f.logo, f.width, f.height], () => {
  try { localStorage.setItem(KEY, JSON.stringify({ sender: f.sender, logo: f.logo, width: f.width, height: f.height })) } catch { /* sem storage */ }
})

const esc = (s: string) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c] as string)
/*
 * @page = papel do driver (padrão USER 102,4 × 74,9 mm da Argox). O desenho do modelo
 * (105 × 75) é escalado para caber na página que vier, com --u = 1 "mm do modelo" (vw/vh = página na impressão),
 * e cada etiqueta ocupa exatamente uma página (altura 100vh − folga) — nunca quebra em duas.
 */
const paper = computed(() => ({ w: Math.min(Math.max(Number(f.width) || 102.4, 30), 120), h: Math.min(Math.max(Number(f.height) || 74.9, 20), 220) }))
const html = computed(() => {
  const { w, h } = paper.value
  const vols = Math.min(Math.max(Math.round(f.volumes || 1), 1), 50)
  const dest = f.destination.trim() || '—'
  const ac = [f.department.trim(), f.attention.trim() ? `A/C ${f.attention.trim()}` : (f.department.trim() ? 'A/C' : '')].filter(Boolean).join(' ')
  const logo = `${location.origin}/app/brand/logo-etiqueta.png`
  // largura útil 99 "mm do modelo"; fonte pesada ≈ 0,72 em por caractere, Arial ≈ 0,6
  const fit = (text: string, maxMm: number, k: number) => `calc(var(--u) * ${Math.min(maxMm, 99 / (Math.max(text.length, 1) * k)).toFixed(2)})`
  const page = (i: number) => `<section class="label"><div class="content">
      <p class="sm"><b>REMETENTE:</b> ${esc(f.sender.trim().toUpperCase())}</p>
      <p class="sm sm2"><b>DESTINATÁRIO:</b></p>
      <p class="dest" style="font-size:${fit(dest, 10.6, 0.74)}">${esc(dest)}</p>
      ${ac ? `<p class="ac" style="font-size:${fit(ac, 6, 0.62)}">${esc(ac)}</p>` : ''}
      ${vols > 1 ? `<p class="vol">VOLUME ${i}/${vols}</p>` : ''}
      ${f.logo ? `<img class="logo" src="${logo}" alt="">` : ''}
    </div></section>`
  return `<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Etiqueta</title><style>
    @page { size: ${w}mm ${h}mm; margin: 0; }
    * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    html, body { margin: 0; padding: 0; background: #fff; color: #000; font-family: Arial, Helvetica, sans-serif; }
    .label { width: 100vw; height: calc(100vh - 1.5mm); overflow: hidden; display: flex; align-items: center; justify-content: center;
      page-break-after: always; break-after: page; page-break-inside: avoid; break-inside: avoid;
      --u: min(calc((100vw - 2mm) / 105), calc((100vh - 3mm) / 75)); }
    .label:last-child { page-break-after: auto; break-after: auto; }
    .content { position: relative; width: calc(var(--u) * 105); height: calc(var(--u) * 75); padding: calc(var(--u) * 4) calc(var(--u) * 3) 0; text-align: center; }
    p { margin: 0; line-height: 1.15; }
    .sm { font-size: calc(var(--u) * 3.9); font-weight: 700; }
    .sm2 { margin-bottom: calc(var(--u) * 3.5); }
    .dest { font-family: "Arial Black", "Archivo Black", Arial, sans-serif; font-weight: 900; white-space: nowrap; letter-spacing: 0.01em; }
    .ac { margin-top: calc(var(--u) * 0.8); white-space: nowrap; }
    .vol { position: absolute; left: calc(var(--u) * 3); bottom: calc(var(--u) * 3); font-size: calc(var(--u) * 3.2); font-weight: 700; }
    .logo { position: absolute; right: calc(var(--u) * 3); bottom: calc(var(--u) * 3); width: calc(var(--u) * 31); }
  </style></head><body>${Array.from({ length: vols }, (_, i) => page(i + 1)).join('')}</body></html>`
})
// prévia em escala: largura fixa de 400 px, altura proporcional ao papel
const frameStyle = computed(() => ({ width: '400px', height: `${Math.round(400 * paper.value.h / paper.value.w)}px` }))

// .odt do modelo Entrega_TI com os campos desta tela — impressão pelo LibreOffice (caminho que funciona na Argox)
const odtUrl = computed(() => {
  const p = new URLSearchParams({ sender: f.sender, destination: f.destination, department: f.department, attention: f.attention })
  return `/api/ui/stock/shipments/${props.batch}/label.odt?${p.toString()}`
})

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
        <div class="row">
          <div class="field"><label for="lb-w">Papel — largura (mm)</label><input id="lb-w" v-model.number="f.width" class="input num" type="number" min="30" max="120" step="0.1" /></div>
          <div class="field"><label for="lb-h">Papel — altura (mm)</label><input id="lb-h" v-model.number="f.height" class="input num" type="number" min="20" max="220" step="0.1" /></div>
        </div>
        <label class="check"><input v-model="f.logo" type="checkbox" /> Imprimir logo</label>
        <p class="hint"><strong>Recomendado:</strong> "Baixar etiqueta (LibreOffice)" gera o seu modelo <em>Entrega_TI</em> já preenchido — abra e imprima (Ctrl+P) na ArgoxBancada; com mais de um volume, informe as cópias no LibreOffice.</p>
        <p class="hint">Pelo navegador: use <strong>o mesmo tamanho do papel do driver</strong> (Argox: USER 102,4 × 74,9). No diálogo de impressão: <strong>Tamanho do papel = USER</strong>, <strong>Margens: Nenhuma</strong>, <strong>Escala: Padrão</strong>, sem cabeçalhos e rodapés.</p>
        <p class="hint">As alterações valem só para esta impressão; o remetente fica salvo neste navegador.</p>
      </div>
      <div class="preview">
        <iframe ref="frame" title="Prévia da etiqueta" :srcdoc="html" :style="frameStyle" />
      </div>
    </div>
    <p v-else class="muted">Carregando…</p>
    <div class="form-actions">
      <button class="btn" type="button" @click="emit('close')">Fechar</button>
      <button class="btn" type="button" :disabled="!ship" title="Depende do Chrome casar a página com o papel do driver" @click="print"><AppIcon name="printer" :size="16" /> Imprimir pelo navegador</button>
      <a class="btn btn-primary" :class="{ disabled: !ship }" :href="ship ? odtUrl : undefined" download><AppIcon name="download" :size="16" /> Baixar etiqueta (LibreOffice)</a>
    </div>
  </AppModal>
</template>

<style scoped>
.wrap { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 20px; align-items: start; }
.form { display: flex; flex-direction: column; gap: 12px; }
.row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.preview { background: var(--surface-2, #e9e9e9); border-radius: var(--radius-sm); padding: 10px; max-height: 70vh; overflow: auto; }
.preview iframe { border: 0; display: block; background: #e9e9e9; }
.small { font-size: 12px; margin: 0; }
.hint { margin: 0; }
.err { color: var(--warn-text); }
a.btn.disabled { opacity: .55; pointer-events: none; }
@media (max-width: 900px) { .wrap { grid-template-columns: 1fr; } }
</style>
