// Cliente HTTP da API do painel (/api/ui).
// Autenticação por cookie de sessão HttpOnly (o JavaScript não vê o token — ADR-0011).
// Toda requisição leva X-Requested-With: o backend recusa escrita sem ele (proteção CSRF).
import type {
  AgentsResponse, Alerts, AssetDetail, AssetsResponse, Attachment, BranchDetail, BranchRow, ContractDetail, ContractRow, AccountRow, AuditPage, Me, SessionRow, InventoriesResponse, LicenseDetail,
  LicenseRow, Lookups, Overview, SettingsResponse, SupplierDetail, SupplierRow, TypeSummary, WakeupResult, ScannerInfo, ScanRow, ScanDetail,
} from './types'

export class ApiError extends Error {
  constructor(public readonly status: number, message: string, public readonly fields: Record<string, string> = {}, public readonly code?: string) {
    super(message)
  }
}

/** 401 → login; 403 password_change_required → tela de troca de senha. */
export const authEvents = new EventTarget()

const BASE_HEADERS = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const res = await fetch(`/api/ui${path}`, {
    ...init,
    credentials: 'same-origin',
    cache: 'no-store',
    headers: { ...BASE_HEADERS, ...init.headers },
  })

  // wake-up devolve 502 com corpo útil (agente inalcançável/recusou): não é erro da API
  if (res.status === 502 && path.endsWith('/wakeup')) {
    return (await res.json()) as T
  }
  if (!res.ok) {
    let message = `Erro ${res.status} ao consultar a API.`
    let fields: Record<string, string> = {}
    let code: string | undefined
    try {
      const body = (await res.json()) as { error?: string; detail?: string; code?: string; errors?: Record<string, string> }
      fields = body.errors ?? {}
      code = body.code
      message = body.errors ? Object.values(body.errors).join(' ') : (body.error ?? body.detail ?? message)
    } catch { /* corpo não-JSON */ }
    const authPath = path.startsWith('/auth/')
    if (res.status === 401 && !authPath) authEvents.dispatchEvent(new Event('unauthorized'))
    if (res.status === 403 && code === 'password_change_required') authEvents.dispatchEvent(new Event('password-change'))
    throw new ApiError(res.status, message, fields, code)
  }
  return (await res.json()) as T
}

/** Escrita JSON (POST/PUT/PATCH/DELETE). Erros 422 viram ApiError com .fields por campo. */
async function send<T>(method: string, path: string, body?: unknown): Promise<T> {
  return request<T>(path, {
    method,
    headers: body === undefined ? {} : { 'Content-Type': 'application/json' },
    body: body === undefined ? undefined : JSON.stringify(body),
  })
}

/** Busca binário autenticado (fotos, anexos) e devolve um object URL. */
export async function fetchBlobUrl(path: string): Promise<string> {
  const res = await fetch(`/api/ui${path}`, { credentials: 'same-origin', headers: BASE_HEADERS })
  if (res.status === 401) authEvents.dispatchEvent(new Event('unauthorized'))
  if (!res.ok) throw new ApiError(res.status, 'Arquivo indisponível.')
  return URL.createObjectURL(await res.blob())
}

function qs(params: Record<string, string | number | boolean | null | undefined>): string {
  const s = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) {
    if (v !== null && v !== undefined && v !== '' && v !== false) s.set(k, String(v === true ? 1 : v))
  }
  const out = s.toString()
  return out ? `?${out}` : ''
}

export const api = {
  // ---------------------------------------------------------------- autenticação / minha conta
  login: (username: string, password: string) => send<{ ok?: boolean; mfa_required?: boolean; must_change_password?: boolean }>('POST', '/auth/login', { username, password }),
  mfa: (code: string) => send<{ ok: boolean; must_change_password: boolean }>('POST', '/auth/mfa', { code }),
  logout: () => send<{ ok: boolean }>('POST', '/auth/logout'),
  me: () => request<Me>('/me'),
  changePassword: (current_password: string, new_password: string) => send<{ ok: boolean }>('POST', '/me/password', { current_password, new_password }),
  mySessions: () => request<{ items: SessionRow[] }>('/me/sessions'),
  revokeMySession: (id: number) => send<{ ok: boolean }>('DELETE', `/me/sessions/${id}`),
  mfaSetup: () => send<{ secret: string; uri: string }>('POST', '/me/mfa/setup'),
  mfaEnable: (code: string) => send<{ ok: boolean }>('POST', '/me/mfa/enable', { code }),
  mfaDisable: (password: string, code: string) => send<{ ok: boolean }>('POST', '/me/mfa/disable', { password, code }),
  // ---------------------------------------------------------------- administração
  users: () => request<{ items: AccountRow[] }>('/users'),
  createUser: (body: Record<string, unknown>) => send<{ id: number; temporary_password: string | null }>('POST', '/users', body),
  updateUser: (id: number, body: Record<string, unknown>) => send<{ ok: boolean }>('PATCH', `/users/${id}`, body),
  deleteUser: (id: number) => send<{ deleted: boolean }>('DELETE', `/users/${id}`),
  resetUserPassword: (id: number) => send<{ temporary_password: string }>('POST', `/users/${id}/reset-password`),
  resetUserMfa: (id: number) => send<{ ok: boolean }>('POST', `/users/${id}/reset-mfa`),
  unlockUser: (id: number) => send<{ ok: boolean }>('POST', `/users/${id}/unlock`),
  userSessions: (id: number) => request<{ items: SessionRow[] }>(`/users/${id}/sessions`),
  revokeUserSessions: (id: number) => send<{ revoked: number }>('DELETE', `/users/${id}/sessions`),
  audit: (p: { q?: string; event?: string; page?: number }) => request<AuditPage>(`/audit${qs(p)}`),
  // ---------------------------------------------------------------- ferramentas
  scannerInfo: () => request<ScannerInfo>('/tools/scanner'),
  scans: () => request<{ items: ScanRow[] }>('/tools/scanner/scans'),
  scan: (id: number) => request<ScanDetail>(`/tools/scanner/scans/${id}`),
  createScan: (body: Record<string, unknown>) => send<{ id: number }>('POST', '/tools/scanner/scans', body),
  cancelScan: (id: number) => send<{ ok: boolean }>('POST', `/tools/scanner/scans/${id}/cancel`),
  deleteScan: (id: number) => send<{ deleted: boolean }>('DELETE', `/tools/scanner/scans/${id}`),
  scanExportUrl: (id: number) => `/api/ui/tools/scanner/scans/${id}/export`,
  // ---------------------------------------------------------------- inventário
  overview: () => request<Overview>('/overview'),
  assets: (p: { q?: string; type?: string; stale?: boolean; page?: number; perPage?: number; sort?: string; dir?: string; branch?: string }) =>
    request<AssetsResponse>(`/assets${qs(p)}`),
  assetSummary: (branch?: string) => request<{ types: TypeSummary[] }>(`/assets/summary${qs({ branch })}`),
  asset: (uuid: string) => request<AssetDetail>(`/assets/${encodeURIComponent(uuid)}`),
  agents: () => request<AgentsResponse>('/agents'),
  inventories: (status?: string) => request<InventoriesResponse>(`/inventories${qs({ status })}`),
  payload: (id: number | string) => request<unknown>(`/inventories/${id}/payload`),
  wakeup: (deviceId: string) => request<WakeupResult>(`/agents/${encodeURIComponent(deviceId)}/wakeup`, { method: 'POST' }),
  agentStatus: (deviceId: string) => request<WakeupResult>(`/agents/${encodeURIComponent(deviceId)}/status`),
  settings: () => request<SettingsResponse>('/settings'),
  // ---- gestão
  lookups: () => request<Lookups>('/lookups'),
  saveLookup: (kind: string, id: number | null, body: { name: string; assignment?: string }) =>
    id ? send<{ id: number }>('PUT', `/lookups/${kind}/${id}`, body) : send<{ id: number }>('POST', `/lookups/${kind}`, body),
  deleteLookup: (kind: string, id: number) => send<unknown>('DELETE', `/lookups/${kind}/${id}`),
  alerts: () => request<Alerts>('/alerts'),

  nextInventoryNumber: () => request<{ next: string }>('/assets/next-inventory-number'),
  createAsset: (body: Record<string, unknown>) => send<{ uuid: string }>('POST', '/assets', body),
  updateAsset: (uuid: string, body: Record<string, unknown>) => send<{ uuid: string }>('PATCH', `/assets/${uuid}`, body),
  deleteAsset: (uuid: string) => send<unknown>('DELETE', `/assets/${uuid}`),

  upload: (ownerType: string, owner: string | number, file: File, kind: 'photo' | 'document', description?: string) => {
    const fd = new FormData()
    fd.set('file', file)
    fd.set('owner_type', ownerType)
    fd.set('owner', String(owner))
    fd.set('kind', kind)
    if (description) fd.set('description', description)
    return request<Attachment>('/attachments', { method: 'POST', body: fd })
  },
  deleteAttachment: (uuid: string) => send<unknown>('DELETE', `/attachments/${uuid}`),

  branches: () => request<{ items: BranchRow[] }>('/branches'),
  branch: (id: number) => request<BranchDetail>(`/branches/${id}`),
  saveBranch: (id: number | null, body: Record<string, unknown>) =>
    id ? send<{ id: number; assigned: number }>('PUT', `/branches/${id}`, body) : send<{ id: number; assigned: number }>('POST', '/branches', body),
  deleteBranch: (id: number) => send<unknown>('DELETE', `/branches/${id}`),
  /** Move ativos em lote para a filial; id 0 = remover vínculo. */
  moveToBranch: (id: number, assets: string[]) => send<{ updated: number }>('POST', `/branches/${id}/assets`, { assets }),
  suppliers: (q?: string) => request<{ items: SupplierRow[] }>(`/suppliers${qs({ q })}`),
  supplier: (id: number) => request<SupplierDetail>(`/suppliers/${id}`),
  saveSupplier: (id: number | null, body: Record<string, unknown>) =>
    id ? send<{ id: number }>('PUT', `/suppliers/${id}`, body) : send<{ id: number }>('POST', '/suppliers', body),
  deleteSupplier: (id: number) => send<unknown>('DELETE', `/suppliers/${id}`),
  saveContact: (supplierId: number, contactId: number | null, body: Record<string, unknown>) =>
    contactId ? send<{ id: number }>('PUT', `/contacts/${contactId}`, body) : send<{ id: number }>('POST', `/suppliers/${supplierId}/contacts`, body),
  deleteContact: (id: number) => send<unknown>('DELETE', `/contacts/${id}`),

  contracts: (status?: string) => request<{ items: ContractRow[] }>(`/contracts${qs({ status })}`),
  contract: (id: number) => request<ContractDetail>(`/contracts/${id}`),
  saveContract: (id: number | null, body: Record<string, unknown>) =>
    id ? send<{ id: number }>('PUT', `/contracts/${id}`, body) : send<{ id: number }>('POST', '/contracts', body),
  deleteContract: (id: number) => send<unknown>('DELETE', `/contracts/${id}`),
  linkContractAsset: (id: number, uuid: string) => send<unknown>('PUT', `/contracts/${id}/assets/${uuid}`),
  unlinkContractAsset: (id: number, uuid: string) => send<unknown>('DELETE', `/contracts/${id}/assets/${uuid}`),

  licenses: (q?: string, category?: number) => request<{ items: LicenseRow[] }>(`/licenses${qs({ q, category })}`),
  license: (id: number) => request<LicenseDetail>(`/licenses/${id}`),
  saveLicense: (id: number | null, body: Record<string, unknown>) =>
    id ? send<{ id: number }>('PUT', `/licenses/${id}`, body) : send<{ id: number }>('POST', '/licenses', body),
  deleteLicense: (id: number) => send<unknown>('DELETE', `/licenses/${id}`),
  licenseKey: (id: number) => request<{ key: string | null }>(`/licenses/${id}/key`),
  assignLicense: (id: number, body: { asset_uuid?: string; user?: string; notes?: string; force?: boolean }) =>
    send<{ id: number }>('POST', `/licenses/${id}/assignments`, body),
  unassignLicense: (assignmentId: number) => send<unknown>('DELETE', `/license-assignments/${assignmentId}`),

  saveSettings: (values: Record<string, number | string>) =>
    request<SettingsResponse>('/settings', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(values),
    }),
}
