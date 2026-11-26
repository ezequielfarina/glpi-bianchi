// Formatação pt-BR (datas relativas, bytes, números).

const nf = new Intl.NumberFormat('pt-BR')
const rtf = new Intl.RelativeTimeFormat('pt-BR', { numeric: 'auto' })
const dtf = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' })

/** PostgreSQL devolve "2026-11-26 17:18:12+00" — normaliza para ISO. */
export function parseDate(value: string | null | undefined): Date | null {
  if (!value) return null
  const d = new Date(value.replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00'))
  return Number.isNaN(d.getTime()) ? null : d
}

export function num(n: number | null | undefined): string {
  return n === null || n === undefined ? '—' : nf.format(n)
}

export function dateTime(value: string | null | undefined): string {
  const d = parseDate(value)
  return d ? dtf.format(d) : '—'
}

export function relative(value: string | null | undefined): string {
  const d = parseDate(value)
  if (!d) return 'nunca'
  const diff = (d.getTime() - Date.now()) / 1000
  const abs = Math.abs(diff)
  if (abs < 60) return 'agora'
  if (abs < 3600) return rtf.format(Math.round(diff / 60), 'minute')
  if (abs < 86400) return rtf.format(Math.round(diff / 3600), 'hour')
  if (abs < 86400 * 30) return rtf.format(Math.round(diff / 86400), 'day')
  return dtf.format(d)
}

/** Idade em dias (para destacar ativos que não reportam). */
export function ageDays(value: string | null | undefined): number {
  const d = parseDate(value)
  return d ? (Date.now() - d.getTime()) / 86_400_000 : Infinity
}

export function mib(value: number | null | undefined): string {
  if (value === null || value === undefined) return '—'
  if (value >= 1024 * 1024) return `${(value / 1024 / 1024).toFixed(value >= 10 * 1024 * 1024 ? 0 : 1)} TB`
  if (value >= 1024) return `${(value / 1024).toFixed(value >= 10 * 1024 ? 0 : 1)} GB`
  return `${value} MB`
}

export function bytes(value: number | null | undefined): string {
  if (value === null || value === undefined) return '—'
  if (value >= 1048576) return `${(value / 1048576).toFixed(1)} MB`
  if (value >= 1024) return `${Math.round(value / 1024)} KB`
  return `${value} B`
}

const KIND_LABELS: Record<string, string> = {
  processor: 'Processador',
  memory: 'Memória',
  storage: 'Armazenamento',
  drive: 'Unidade óptica',
  graphic_card: 'Placa de vídeo',
  network_card: 'Placa de rede',
  sound_card: 'Áudio',
  controller: 'Controladora',
  firmware: 'Firmware',
  battery: 'Bateria',
  power_supply: 'Fonte',
}
export const kindLabel = (k: string) => KIND_LABELS[k] ?? k

const FIELD_LABELS: Record<string, string> = {
  name: 'Nome',
  serialNumber: 'Número de série',
  hardwareUuid: 'UUID de hardware',
  manufacturer: 'Fabricante',
  model: 'Modelo',
  operatingSystem: 'Sistema operacional',
  'component.added': 'Componente adicionado',
  'component.removed': 'Componente removido',
  'software.installed': 'Software instalado',
  'software.removed': 'Software removido',
  'network_port.added': 'Interface adicionada',
  'network_port.removed': 'Interface removida',
}
export function fieldLabel(field: string): string {
  if (FIELD_LABELS[field]) return FIELD_LABELS[field]
  if (field.startsWith('attributes.')) return field.slice(11).replace(/_/g, ' ')
  if (field.startsWith('component.')) return field.replace('component.', 'componente ').replace(/\./g, ' · ')
  return field
}

export const STATUS_PILL: Record<string, { label: string; cls: string }> = {
  processed: { label: 'Processado', cls: 'pill-ok' },
  received: { label: 'Na fila', cls: 'pill-accent' },
  processing: { label: 'Processando', cls: 'pill-accent' },
  failed: { label: 'Falhou', cls: 'pill-warn' },
  rejected: { label: 'Rejeitado', cls: 'pill-muted' },
}
