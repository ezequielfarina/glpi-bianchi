// Cliente do acesso Wi-Fi por MAC no UniFi (ADR-0020).
import { http } from './api'

const { get, send, qs } = http
type Opt = { value: string; label: string }
type Body = Record<string, unknown>

export interface WifiChoices { device_kinds: Opt[]; states: Opt[]; auth_modes: Opt[] }
export interface TreeWlan {
  id: number; name: string; enabled: boolean; security: string | null; filter_enabled: boolean; filter_policy: 'allow' | 'deny' | null; synced_at: string | null
  remote_count: number; synced: number; pending: number; errors: number; unknown: number
}
export interface TreeSite { id: number; ref: string; name: string; branch_id: number | null; branch: string | null; wlans: TreeWlan[] }
export interface TreeController { id: number; name: string; enabled: boolean; last_sync_at: string | null; last_error: string | null; version: string | null; sites: TreeSite[] }

export interface ControllerRow {
  id: number; name: string; base_url: string; auth_mode: 'local' | 'api_key' | 'cloud'; auth_label: string; username: string | null; cloud_host_id: string | null
  verify_tls: boolean; tls_pin: string | null; platform: string | null; version: string | null; write_client_alias: boolean; enabled: boolean
  last_sync_at: string | null; last_error: string | null; has_secret: boolean; sites: number; wlans: number
}
export interface TlsInfo { subject: string; issuer: string; valid_to: string; self_signed: boolean; sha256: string; pin: string }
export interface TestResult { ok: boolean; platform: string | null; version: string | null; sites: number; error: string | null }
export interface SyncResult { sites: number; wlans: number; drift: number; version: string | null }

export interface IdentityWlan { wlan_id: number; wlan: string; site: string; controller: string; state: AssignState; last_error: string | null; synced_at: string | null }
export type AssignState = 'pending' | 'synced' | 'error' | 'removing'
export interface Identity {
  id: number; mac: string; person_name: string | null; registration: string | null; department: string | null; email: string | null; phone: string | null
  person_id: number | null; person_username: string | null; device_kind: string; device_brand: string | null; device_model: string | null; hostname: string | null
  asset_id: number | null; asset_uuid: string | null; asset_name: string | null; branch_id: number | null; branch: string | null
  valid_until: string | null; status: 'active' | 'revoked'; source: 'panel' | 'imported'; notes: string | null; created_by: string | null; created_at: string; updated_at: string
  expired: boolean; wlans: IdentityWlan[]; vendor: string | null; randomized: boolean
}
export interface UnknownMac { mac: string; vendor: string | null; randomized: boolean; identity: { id: number; person_name: string | null; status: 'active' | 'revoked' } | null }
export interface WlanDetail {
  id: number; name: string; enabled: boolean; security: string | null; filter_enabled: boolean; filter_policy: 'allow' | 'deny' | null; synced_at: string | null; present: boolean
  remote_count: number; site_id: number; site: string; site_ref: string; controller_id: number; controller: string; base_url: string; auth_mode: string
  identities: Identity[]; unknown: UnknownMac[]
}
export interface ApplyResult { wlan_id: number; changed: boolean; added: string[]; removed: string[]; total: number; warnings: string[]; error: string | null }
export interface MacLookup {
  mac: string; vendor: string | null; randomized: boolean; identity: { id: number; person_name: string | null; status: string } | null
  assets: { asset_uuid: string; name: string; inventory_number: string | null; branch_id: number | null; branch: string | null; port: string }[]
  in_wlans: { id: number; name: string; site: string }[]
}

export const STATE: Record<AssignState, { label: string; cls: string }> = {
  synced: { label: 'No UniFi', cls: 'pill-ok' }, pending: { label: 'Aguardando aplicar', cls: 'pill-warn' },
  removing: { label: 'Saindo', cls: 'pill-muted' }, error: { label: 'Com erro', cls: 'pill-bad' },
}
export const KIND_ICON: Record<string, string> = { phone: 'phone', notebook: 'pc', tablet: 'tablet', desktop: 'monitor', printer: 'printer', tv: 'monitor', scanner: 'barcode' }

export const wifi = {
  choices: () => get<WifiChoices>('/wifi/choices'),
  tree: () => get<{ items: TreeController[] }>('/wifi/tree'),
  controllers: () => get<{ items: ControllerRow[] }>('/wifi/controllers'),
  saveController: (id: number | null, b: Body) => send<{ id: number }>(id ? 'PUT' : 'POST', id ? `/wifi/controllers/${id}` : '/wifi/controllers', b),
  deleteController: (id: number) => send<{ deleted: boolean }>('DELETE', `/wifi/controllers/${id}`),
  inspectTls: (baseUrl: string) => send<TlsInfo>('POST', '/wifi/controllers/tls-inspect', { base_url: baseUrl }),
  test: (id: number) => send<TestResult>('POST', `/wifi/controllers/${id}/test`),
  updateCredential: (id: number, b: { secret: string; username?: string }) => send<TestResult>('POST', `/wifi/controllers/${id}/credential`, b),
  syncSite: (id: number) => send<{ site: string; wlans: number; drift: number }>('POST', `/wifi/sites/${id}/sync`),
  sync: (id: number) => send<SyncResult>('POST', `/wifi/sync/${id}`),
  linkSite: (id: number, branchId: number | null) => send<{ id: number }>('PUT', `/wifi/sites/${id}`, { branch_id: branchId }),
  wlan: (id: number) => get<WlanDetail>(`/wifi/wlans/${id}`),
  apply: (id: number) => send<ApplyResult>('POST', `/wifi/wlans/${id}/apply`),
  enableFilter: (id: number, confirm: string) => send<{ filter_enabled: boolean; total: number }>('POST', `/wifi/wlans/${id}/filter`, { confirm }),
  adopt: (wlanId: number, b: Body) => send<{ id: number }>('POST', `/wifi/wlans/${wlanId}/adopt`, b),
  identities: (f: { q?: string; wlan?: number | null; status?: string; state?: string } = {}) => get<{ items: Identity[] }>(`/wifi/identities${qs(f)}`),
  identity: (id: number) => get<Identity>(`/wifi/identities/${id}`),
  lookup: (mac: string) => get<MacLookup>(`/wifi/lookup${qs({ mac })}`),
  saveIdentity: (id: number | null, b: Body) => send<{ id: number; applied: ApplyResult[] }>(id ? 'PUT' : 'POST', id ? `/wifi/identities/${id}` : '/wifi/identities', b),
  revoke: (id: number, apply: boolean) => send<{ id: number; applied: ApplyResult[] }>('POST', `/wifi/identities/${id}/revoke`, { apply }),
  deleteIdentity: (id: number) => send<{ deleted: boolean }>('DELETE', `/wifi/identities/${id}`),
}

/** Filtra a árvore por nome de rede/site e (opcional) só redes com filtro de MAC; sites vazios somem. */
export function filterTree(tree: TreeController[], q: string, onlyFilter: boolean, keep: number[] = []): TreeController[] {
  const s = q.trim().toLowerCase()
  return tree.map((c) => ({
    ...c,
    sites: c.sites.map((site) => ({
      ...site,
      wlans: site.wlans.filter((w) => keep.includes(w.id) || ((!onlyFilter || w.filter_enabled || w.remote_count > 0)
        && (!s || w.name.toLowerCase().includes(s) || site.name.toLowerCase().includes(s)))),
    })).filter((site) => site.wlans.length > 0 || (!s && !onlyFilter)),
  }))
}

/** Estado aberto/fechado dos sites, lembrado no navegador (falha de storage não quebra a tela). */
export function useOpenSites(key: string) {
  let initial: number[] = []
  try { initial = JSON.parse(localStorage.getItem(key) ?? '[]') as number[] } catch { /* storage indisponível */ }
  const open = new Set<number>(Array.isArray(initial) ? initial : [])
  const save = () => { try { localStorage.setItem(key, JSON.stringify([...open])) } catch { /* idem */ } }
  return {
    isOpen: (id: number) => open.has(id),
    toggle: (id: number) => { if (open.has(id)) open.delete(id); else open.add(id); save() },
    ensure: (id: number) => { if (!open.has(id)) { open.add(id); save() } },
  }
}

/** Validação do MAC no navegador (o backend valida de novo). */
export function macCheck(input: string): { ok: boolean; mac: string | null; random: boolean; msg: string | null } {
  const hex = input.replace(/[^0-9a-f]/gi, '').toLowerCase()
  if (!input.trim()) return { ok: false, mac: null, random: false, msg: null }
  if (/[^0-9a-f:\-.\s]/i.test(input)) return { ok: false, mac: null, random: false, msg: 'Só dígitos hexadecimais (0–9, A–F) e separadores : - .' }
  if (hex.length !== 12) return { ok: false, mac: null, random: false, msg: `${hex.length}/12 dígitos` }
  if (hex === '000000000000' || hex === 'ffffffffffff') return { ok: false, mac: null, random: false, msg: 'MAC inválido.' }
  const first = parseInt(hex.slice(0, 2), 16)
  if (first & 1) return { ok: false, mac: null, random: false, msg: 'Endereço multicast — não é MAC de aparelho.' }
  return { ok: true, mac: hex.match(/../g)!.join(':'), random: !!(first & 2), msg: null }
}

/** aa:bb:cc:dd:ee:ff para exibição em maiúsculas, como no UniFi */
export const macUp = (m: string): string => m.toUpperCase()

/** Resumo de uma aplicação para o toast. */
export function applySummary(r: ApplyResult[]): { tone: 'ok' | 'warn'; title: string; detail?: string } {
  const err = r.filter((x) => x.error)
  const warns = r.flatMap((x) => x.warnings)
  if (err.length) return { tone: 'warn', title: `Não aplicado em ${err.length} rede(s) — ficou pendente`, detail: err[0]!.error ?? undefined }
  const add = r.reduce((n, x) => n + x.added.length, 0)
  const rem = r.reduce((n, x) => n + x.removed.length, 0)
  return { tone: warns.length ? 'warn' : 'ok', title: add || rem ? `UniFi atualizado: +${add} −${rem}` : 'UniFi já estava em dia', detail: warns[0] }
}
