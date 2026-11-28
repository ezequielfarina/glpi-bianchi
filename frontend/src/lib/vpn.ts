// Cliente dos túneis VPN IPsec importados do FortiGate (ADR-0022).
import { http } from './api'

const { get, send, qs } = http
type Opt = { value: string; label: string }

export interface Phase2 {
  id: number; name: string; proposal: string | null; pfs: boolean | null; dhgroups: string | null; keylife_seconds: number | null; auto_negotiate: boolean | null
  src: string[]; dst: string[]; src_name: string | null; dst_name: string | null; params: Record<string, string>
}
export interface TunnelRow {
  id: number; name: string; vdom: string; mode: 'interface' | 'policy'; topology: 'site_to_site' | 'dialup' | 'hub' | 'spoke'; status: string; present: boolean
  local_interface: string | null; local_ip: string | null; remote_gw: string | null; ike_version: number | null; ike_mode: string | null; auth_method: string | null
  p1_proposal: string | null; p1_dhgroups: string | null; p1_keylife: number | null; dpd: string | null; nat_traversal: string | null
  tunnel_ip: string | null; tunnel_remote_ip: string | null; routes: string[]; sdwan_zone: string | null; params: Record<string, string>
  peer_label: string | null; psk_location: string | null; description: string | null; comments: string | null; secrets_removed: number; imported_at: string | null
  device_id: number; device: string; branch_id: number | null; branch: string | null
  peer_tunnel_id: number | null; peer_tunnel: string | null; peer_device_id: number | null; peer_device: string | null; peer_branch: string | null
  supplier_id: number | null; supplier: string | null; circuit_id: number | null; circuit: string | null; phase2_count: number
}
export interface Check { level: 'ok' | 'info' | 'warn' | 'error'; msg: string }
export interface Network { net: string; local: boolean; remote: boolean; route: boolean; prefix: { id: number; prefix: string; description: string | null; branch: string | null; vlan_vid: number | null; vlan: string | null } | null }
export interface TunnelDetail extends TunnelRow {
  phase2: Phase2[]; networks: Network[]; compat: { verdict: 'ok' | 'warn' | 'error'; checks: Check[] } | null
  peer?: TunnelRow & { phase2: Phase2[] }; spokes: { id: number; name: string; device: string }[]
}
export interface ImportPreview {
  device: { id: number; name: string; branch: string | null }; hostname: string | null; model: string | null; version: string | null; secrets_removed: number
  warnings: string[]; missing: { name: string; vdom: string }[]
  tunnels: { name: string; vdom: string; mode: string; topology: string; remote_gw: string | null; local_ip: string | null; ike_version: number; phase2: number; routes: number; action: 'new' | 'update' | 'unchanged' }[]
}

export const TOPOLOGY: Record<string, { label: string; short: string }> = {
  site_to_site: { label: 'Site a site', short: 'S2S' }, dialup: { label: 'Dial-up (FortiClient / remoto)', short: 'Dial-up' },
  hub: { label: 'Hub (ADVPN)', short: 'Hub' }, spoke: { label: 'Spoke (ADVPN)', short: 'Spoke' },
}
export const TSTATUS: Record<string, { label: string; cls: string }> = {
  active: { label: 'Ativo', cls: 'pill-ok' }, disabled: { label: 'Desativado', cls: 'pill-muted' }, planned: { label: 'Planejado', cls: 'pill-info' },
  decommissioning: { label: 'Em desativação', cls: 'pill-warn' },
}
export const VERDICT: Record<string, { label: string; cls: string; icon: string }> = {
  ok: { label: 'Lados compatíveis', cls: 'pill-ok', icon: 'check' }, warn: { label: 'Atenção', cls: 'pill-warn', icon: 'alert' }, error: { label: 'Divergência', cls: 'pill-bad', icon: 'alert' },
}

export const vpn = {
  choices: () => get<{ status: Opt[]; topology: Opt[] }>('/vpn/choices'),
  tunnels: (f: { q?: string; device?: number | null; branch?: number | null; status?: string; topology?: string } = {}) => get<{ items: TunnelRow[] }>(`/vpn/tunnels${qs(f)}`),
  tunnel: (id: number) => get<TunnelDetail>(`/vpn/tunnels/${id}`),
  update: (id: number, b: Record<string, unknown>) => send<{ id: number }>('PUT', `/vpn/tunnels/${id}`, b),
  remove: (id: number) => send<{ deleted: boolean }>('DELETE', `/vpn/tunnels/${id}`),
  preview: (deviceId: number, text: string) => send<ImportPreview>('POST', '/vpn/import/preview', { device_id: deviceId, config_text: text }),
  apply: (deviceId: number, text: string) => send<{ created: number; updated: number; unchanged: number; missing: number; secrets_removed: number }>('POST', '/vpn/import', { device_id: deviceId, config_text: text }),
}

/** Comandos de diagnóstico do FortiOS para o túnel (o que rodar quando "não sobe"). */
export function diagCli(t: TunnelRow): string {
  const vdom = t.vdom ? `config vdom\nedit ${t.vdom}\n` : ''
  const peer = t.remote_gw && /^[\d.]+$/.test(t.remote_gw) ? t.remote_gw : null
  return [
    `${vdom}# estado da fase 1 e da fase 2`,
    `get vpn ipsec tunnel name ${t.name}`,
    `diagnose vpn ike gateway list name ${t.name}`,
    `diagnose vpn tunnel list name ${t.name}`,
    '',
    '# subir/derrubar manualmente',
    `execute vpn ipsec tunnel up ${t.name}`,
    `diagnose vpn ike gateway clear name ${t.name}`,
    '',
    '# debug da negociação (desligue depois: diagnose debug reset)',
    'diagnose debug reset',
    'diagnose vpn ike log filter clear',
    peer ? `diagnose vpn ike log filter rem-addr4 ${peer}` : `diagnose vpn ike log filter name ${t.name}`,
    'diagnose debug application ike -1',
    'diagnose debug console timestamp enable',
    'diagnose debug enable',
    '',
    '# tráfego pelo túnel',
    ...(t.tunnel_remote_ip ? [`execute ping-options source ${t.tunnel_ip?.split('/')[0] ?? ''}`, `execute ping ${t.tunnel_remote_ip.split('/')[0]}`] : []),
    `diagnose sniffer packet ${t.name} 'icmp' 4 10 l`,
  ].join('\n')
}
