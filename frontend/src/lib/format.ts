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
  branch: 'Filial',
  location: 'Local',
  user: 'Usuário',
  status: 'Situação',
  inventoryNumber: 'Patrimônio',
  'asset.created': 'Ativo cadastrado',
  'asset.deleted': 'Enviado para a lixeira',
  'asset.restored': 'Restaurado da lixeira',
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

export const OUTCOME_PILL: Record<string, { label: string; cls: string; hint: string }> = {
  created: { label: 'Novo ativo', cls: 'pill-ok', hint: 'Item não estava cadastrado — ativo criado por este inventário.' },
  updated: { label: 'Atualizado', cls: 'pill-accent', hint: 'Item já cadastrado — houve alterações (ver histórico do ativo).' },
  unchanged: { label: 'Sem alterações', cls: 'pill-muted', hint: 'Item já cadastrado — nada mudou desde o último inventário.' },
}

const brl = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' })
export function money(v: string | number | null | undefined): string {
  if (v === null || v === undefined || v === '') return '—'
  const n = typeof v === 'number' ? v : Number(v)
  return Number.isFinite(n) ? brl.format(n) : '—'
}

/** "2026-12-31" → "31/12/2026" (datas sem hora, sem fuso). */
export function dateOnly(v: string | null | undefined): string {
  if (!v) return '—'
  const [y, m, d] = v.slice(0, 10).split('-')
  return y && m && d ? `${d}/${m}/${y}` : v
}

export function taxId(v: string | null | undefined): string {
  if (!v) return '—'
  if (v.length === 14) return v.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5')
  if (v.length === 11) return v.replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.$2.$3-$4')
  return v
}

export const ASSIGNMENT_LABEL: Record<string, string> = { device: 'Por máquina', user: 'Por usuário', volume: 'Volume (ativações)' }
export const RENEWAL_LABEL: Record<string, string> = { none: 'Sem renovação', manual: 'Renovação manual', automatic: 'Renovação automática' }
export const BILLING_LABEL: Record<string, string> = { one_time: 'Pagamento único', monthly: 'Mensal', quarterly: 'Trimestral', semiannual: 'Semestral', yearly: 'Anual' }
export const CONTRACT_STATUS: Record<string, { label: string; cls: string }> = {
  open: { label: 'Sem prazo', cls: 'pill-muted' },
  active: { label: 'Vigente', cls: 'pill-ok' },
  expiring: { label: 'Vencendo', cls: 'pill-warn' },
  expired: { label: 'Vencido', cls: 'pill-warn' },
}
export const COMPLIANCE: Record<string, { label: string; cls: string }> = {
  ok: { label: 'Em conformidade', cls: 'pill-ok' },
  over: { label: 'Excedida', cls: 'pill-warn' },
  expiring: { label: 'Vence em 30 dias', cls: 'pill-warn' },
  expired: { label: 'Vencida', cls: 'pill-warn' },
}
export const ASSET_STATUS: Record<string, string> = {
  in_use: 'Em uso', in_stock: 'Em estoque', in_repair: 'Em manutenção', reserved: 'Reservado', retired: 'Desativado', disposed: 'Descartado', lost: 'Extraviado',
}

/** Campos sugeridos por tipo no cadastro manual (viram attributes). */
export const TYPE_FIELDS: Record<string, string[]> = {
  printer: ['IP', 'MAC', 'Contador de páginas', 'Suprimento / toner'],
  monitor: ['Polegadas', 'Resolução', 'Conexões'],
  data_collector: ['IMEI', 'Sistema operacional', 'MAC Wi-Fi'],
  tablet: ['IMEI', 'Sistema operacional', 'Número da linha', 'Operadora'],
  phone: ['IMEI', 'Número da linha', 'Ramal', 'Operadora'],
  peripheral: ['Conexão'],
  network_equipment: ['IP de gerência', 'Firmware', 'Portas'],
  computer: ['Processador', 'Memória (GB)', 'Disco'],
}

const ACRONYMS = new Set(['ip', 'mac', 'imei', 'os', 'sku', 'ssd', 'hd', 'dns', 'sim', 'ddd', 'wifi'])
/** "numero_da_linha" → "Número da linha"; "ip" → "IP" (rótulo de característica manual). */
export function attrLabel(key: string): string {
  const words = key.split('_').filter(Boolean).map((w) => (ACRONYMS.has(w) ? w.toUpperCase() : w))
  const s = words.join(' ').replace(/\bnumero\b/i, 'número').replace(/\bpaginas\b/i, 'páginas').replace(/\bresolucao\b/i, 'resolução').replace(/\bconexao\b/i, 'conexão').replace(/\bconexoes\b/i, 'conexões')
  return s.charAt(0).toUpperCase() + s.slice(1)
}
