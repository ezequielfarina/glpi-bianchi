import { ref } from 'vue'
import { api } from './api'
import { notify } from './toast'
import type { LabelPrinterInfo } from './types'

/** Itens abaixo do estoque mínimo — badge do menu e telas de estoque compartilham o mesmo estado. */
export const stockLow = ref(0)

export async function refreshStockLow(): Promise<void> {
  try { stockLow.value = (await api.stockLowCount()).low } catch { /* offline: mantém */ }
}

export const INVOICE_STATUS: Record<string, { label: string; cls: string }> = {
  registered: { label: 'Registrada', cls: 'pill-warn' },
  sent: { label: 'Enviada para lançamento', cls: 'pill-ok' },
}

export const MOVEMENT_KIND: Record<string, { label: string; cls: string }> = {
  in: { label: 'Entrada', cls: 'pill-ok' },
  out: { label: 'Saída', cls: 'pill-info' },
  adjust: { label: 'Ajuste', cls: 'pill-warn' },
}

export const REMOVE_STATUS: Record<string, string> = { lost: 'Perdido / extraviado', disposed: 'Descartado', retired: 'Baixado (defeito)' }

/** "1.234,56" / "1234.56" → número (NaN se inválido). */
export function parseMoney(v: string | number | null | undefined): number {
  if (v === null || v === undefined || v === '') return Number.NaN
  if (typeof v === 'number') return v
  let s = v.replace(/R\$|\s/g, '')
  if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.')
  return s === '' ? Number.NaN : Number(s)
}

/** Data local de hoje em AAAA-MM-DD (para input type=date). */
export function today(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

// ---------------------------------------------------------------- impressora de etiquetas (Argox na rede)

export const labelPrinter = ref<LabelPrinterInfo | null>(null)
export async function loadLabelPrinter(force = false): Promise<LabelPrinterInfo | null> {
  if (!labelPrinter.value || force) {
    try { labelPrinter.value = await api.labelPrinter() } catch { labelPrinter.value = null }
  }
  return labelPrinter.value
}

/** Imprime a etiqueta da saída na Argox. Devolve a mensagem de erro (ou null se imprimiu). */
export async function printShipmentLabel(batch: string, volumes: number, fields: Record<string, unknown> = {}): Promise<string | null> {
  try {
    const r = await api.printShipment(batch, { volumes, ...fields })
    notify('ok', r.printed > 1 ? `${r.printed} etiquetas enviadas para a impressora` : 'Etiqueta enviada para a impressora', r.printer)
    return null
  } catch (e) {
    return e instanceof Error ? e.message : String(e)
  }
}
