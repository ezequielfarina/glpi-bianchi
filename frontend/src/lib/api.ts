// Cliente HTTP da API (/api). Token Bearer guardado no navegador (MVP).
// Fase 2: OIDC — o token deixa de ser estático e passa a vir do IdP.
import type { AgentsResponse, AssetDetail, AssetsResponse, InventoriesResponse, Overview, SettingsResponse, WakeupResult } from './types'

const TOKEN_KEY = 'bianchi.token'

export class ApiError extends Error {
  constructor(public readonly status: number, message: string) {
    super(message)
  }
}

export const auth = {
  get token(): string | null {
    try { return localStorage.getItem(TOKEN_KEY) } catch { return null }
  },
  set(token: string): void {
    try { localStorage.setItem(TOKEN_KEY, token) } catch { /* storage indisponível */ }
  },
  clear(): void {
    try { localStorage.removeItem(TOKEN_KEY) } catch { /* noop */ }
  },
}

/** Disparado quando a API responde 401 — o router manda para o login. */
export const onUnauthorized = new EventTarget()

async function request<T>(path: string, init: RequestInit = {}, token = auth.token): Promise<T> {
  const res = await fetch(`/api/ui${path}`, {
    ...init,
    headers: {
      Accept: 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...init.headers,
    },
  })

  if (res.status === 401) {
    onUnauthorized.dispatchEvent(new Event('unauthorized'))
    throw new ApiError(401, 'Sessão inválida ou token expirado.')
  }
  // wake-up devolve 502 com corpo útil (agente inalcançável/recusou): não é erro da API
  if (res.status === 502 && path.endsWith('/wakeup')) {
    return (await res.json()) as T
  }
  if (!res.ok) {
    let message = `Erro ${res.status} ao consultar a API.`
    try {
      const body = (await res.json()) as { error?: string; detail?: string; errors?: Record<string, string> }
      message = body.errors ? Object.values(body.errors).join(' ') : (body.error ?? body.detail ?? message)
    } catch { /* corpo não-JSON */ }
    throw new ApiError(res.status, message)
  }
  return (await res.json()) as T
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
  /** Valida um token antes de salvá-lo (tela de login). */
  check: (token: string) => request<{ authenticated: boolean }>('/me', {}, token),
  overview: () => request<Overview>('/overview'),
  assets: (p: { q?: string; type?: string; stale?: boolean; page?: number; perPage?: number; sort?: string; dir?: string }) =>
    request<AssetsResponse>(`/assets${qs(p)}`),
  asset: (uuid: string) => request<AssetDetail>(`/assets/${encodeURIComponent(uuid)}`),
  agents: () => request<AgentsResponse>('/agents'),
  inventories: (status?: string) => request<InventoriesResponse>(`/inventories${qs({ status })}`),
  payload: (id: number | string) => request<unknown>(`/inventories/${id}/payload`),
  wakeup: (deviceId: string) => request<WakeupResult>(`/agents/${encodeURIComponent(deviceId)}/wakeup`, { method: 'POST' }),
  agentStatus: (deviceId: string) => request<WakeupResult>(`/agents/${encodeURIComponent(deviceId)}/status`),
  settings: () => request<SettingsResponse>('/settings'),
  saveSettings: (values: Record<string, number>) =>
    request<SettingsResponse>('/settings', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(values),
    }),
}
