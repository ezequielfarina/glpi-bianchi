// Cliente e tipos do módulo de infraestrutura (ADR-0016): DCIM, cabeamento, IPAM e circuitos.
import { http } from './api'
import type { Attachment } from './types'

const { get, send, qs } = http
type Opt = { value: string; label: string }
type Id = { id: number }
type Body = Record<string, unknown>

export interface DcimChoices {
  port_kinds: Opt[]; port_types: Record<string, Opt[]>; non_connectable: string[]; compatible: Record<string, string[]>
  cable_types: Opt[]; cable_status: Opt[]; length_units: Opt[]; colors: Opt[]; device_status: Opt[]; rack_status: Opt[]
  location_kinds: Opt[]; rack_widths: number[]; roles: DeviceRole[]
}
export interface IpamChoices {
  prefix_status: Opt[]; range_status: Opt[]; ip_status: Opt[]; ip_roles: Opt[]; vlan_status: Opt[]; vrfs: Vrf[]; vlan_groups: VlanGroup[]
}
export interface LocationRow {
  id: number; parent_id: number | null; name: string; code: string | null; kind: string; kind_label: string; description: string | null
  branch_id: number | null; branch: string | null; branch_code: string | null; racks: number; devices: number; assets: number; photos: number; depth: number
}
export interface LocationDetail extends Omit<LocationRow, 'racks' | 'devices' | 'assets' | 'photos' | 'depth'> {
  address: string | null; breadcrumb: { id: number; name: string }[]; children: { id: number; name: string; kind: string }[]
  racks: { id: number; name: string; status: string; u_height: number; location: string | null; used_u: number }[]
  devices: { id: number; name: string; status: string; role: string; color: string; device_type: string; rack: string | null; position: number | null; location: string | null }[]
  assets: { uuid: string; name: string; inventory_number: string | null; type: string; location: string | null }[]
  attachments: Attachment[]
}
export interface RackRow {
  id: number; name: string; status: string; role: string | null; u_height: number; width: number; facility_id: string | null
  branch_id: number; branch: string; branch_code: string | null; location_id: number | null; location: string | null; devices: number; used_u: number; photo: string | null
}
export interface RackDevice {
  id: number; name: string; status: string; position: number | null; face: 'front' | 'rear' | null; serial: string | null; asset_tag: string | null
  device_type: string; u_height: number; is_full_depth: boolean; manufacturer: string; role: string; color: string; asset_uuid: string | null
  primary_ip: string | null; photo: string | null; type_photo: string | null
}
export interface RackDetail {
  id: number; name: string; status: string; role: string | null; facility_id: string | null; serial: string | null; asset_tag: string | null
  u_height: number; width: number; starting_unit: number; desc_units: boolean; outer_depth_mm: number | null; description: string | null; comments: string | null
  branch_id: number; branch: string; location_id: number | null; location: string | null
  devices: RackDevice[]; unracked: RackDevice[]; used_u: number; utilization: number; attachments: Attachment[]
}
export interface DeviceRole { id: number; name: string; color: string; description: string | null; devices: number }
export interface DeviceTypeRow {
  id: number; model: string; part_number: string | null; u_height: number; is_full_depth: boolean; description: string | null; manufacturer: string
  default_role_id: number | null; default_role: string | null; color: string | null; devices: number; interfaces: number; front_ports: number; rear_ports: number
  power_ports: number; console_ports: number; photo: string | null
}
export interface PortTemplate {
  id: number; kind: string; name: string; label: string | null; type: string; positions: number; mgmt_only: boolean; maximum_draw: number | null
  allocated_draw: number | null; description: string | null; rear_port_template_id: number | null; rear_port: string | null; rear_position: number | null
}
export interface DeviceTypeDetail extends DeviceTypeRow { comments: string | null; manufacturer_id: number; templates: PortTemplate[]; attachments: Attachment[] }
export interface DeviceRow {
  id: number; name: string; status: string; serial: string | null; asset_tag: string | null; position: number | null; face: string | null
  branch_id: number; branch: string; branch_code: string | null; location_id: number | null; location: string | null; rack_id: number | null; rack: string | null
  role: string; color: string; device_type: string; manufacturer: string; u_height: number; asset_uuid: string | null; asset_name: string | null
  primary_ip: string | null; ports: number; connected: number; photo: string | null
}
export interface LinkPeer {
  cable_id: number; cable_end: string; port_id: number | null; port: string | null; kind: string | null; device_id: number | null; device: string | null
  circuit_termination_id: number | null; cid: string | null; provider: string | null; term_side: string | null
}
export interface PortRow {
  id: number; kind: string; kind_label: string; name: string; label: string | null; type: string; type_label: string; positions: number; enabled: boolean
  mgmt_only: boolean; mark_connected: boolean; mac_address: string | null; mode: string | null; maximum_draw: number | null; allocated_draw: number | null
  description: string | null; untagged_vlan_id: number | null; untagged_vid: number | null; untagged_vlan: string | null; tagged_vids: string | null
  tagged_vlan_ids: number[]; cable_id: number | null; cable_end: string | null; cable_label: string | null; cable_color: string | null
  cable_status: string | null; cable_type: string | null; ips: string | null; rear_port_id: number | null; rear_port: string | null; rear_position: number | null
  link_peers: LinkPeer[]
  destination: { active: boolean; nodes: { id: number; name: string; device_id: number | null; device: string | null; asset_uuid: string | null; ips: string | null }[] } | null
}
export interface DeviceDetail {
  id: number; name: string; status: string; serial: string | null; asset_tag: string | null; position: number | null; face: string | null
  description: string | null; comments: string | null; branch_id: number; branch: string; location_id: number | null; location: string | null
  rack_id: number | null; rack: string | null; device_type_id: number; device_type: string; manufacturer: string; u_height: number; is_full_depth: boolean
  role_id: number; role: string; color: string; asset_id: number | null; asset_uuid: string | null; asset_name: string | null; asset_inventory: string | null
  asset_serial: string | null; primary_ip_id: number | null; primary_ip: string | null; ports: PortRow[]
  ips: { id: number; address: string; status: string; role: string | null; dns_name: string | null; port_id: number; port: string; vrf: string | null }[]
  power: { outlets: number; used_outlets: number; allocated_w: number; maximum_w: number | null } | null
  attachments: Attachment[]; type_photo: string | null
}
export interface PortOption { id: number; kind: string; name: string; label: string | null; type: string; positions: number; mark_connected: boolean; cable_id: number | null; mapped_positions: number }
export interface AssetCandidate { uuid: string; name: string; serial_number: string | null; inventory_number: string | null; type: string; branch: string | null; branch_id: number | null; location_id: number | null; interfaces: number }
export interface CableEnd {
  cable_id: number; cable_end: string; port_id: number | null; port: string | null; kind: string | null; device_id: number | null; device: string | null
  branch: string | null; rack: string | null; location: string | null; circuit_termination_id: number | null; term_side: string | null
  circuit_id: number | null; cid: string | null; provider: string | null
}
export interface CableRow {
  id: number; label: string | null; type: string | null; type_label: string | null; status: string; color: string | null; length: number | null
  length_unit: string | null; description: string | null; comments?: string | null; a: CableEnd[]; b: CableEnd[]; attachments?: Attachment[]
}
export interface TraceNode {
  node: 'port' | 'circuit'; id: number; kind: string; name: string; label?: string | null; type?: string; type_label?: string; kind_label?: string
  positions: number; device_id?: number; device?: string; role?: string; color?: string; rack?: string | null; position?: number | null; face?: string | null
  location?: string | null; branch?: string | null; asset_uuid?: string | null; asset_name?: string | null; ips?: string | null
  term_side?: string; circuit_id?: number; cid?: string; provider?: string; circuit_type?: string; provider_network?: string | null
  xconnect_id?: string | null; pp_info?: string | null; commit_rate_kbps?: number | null
}
export interface TraceCable { id: number; label: string | null; type: string | null; type_label: string | null; status: string; color: string | null; length: number | null; length_unit: string | null; length_m: number | null }
export type TraceHop = { t: 'nodes'; nodes: TraceNode[] } | { t: 'cable'; cable: TraceCable }
export interface Trace {
  origin: TraceNode; hops: TraceHop[]; complete: boolean; active: boolean; split: boolean; ends: TraceNode[][]; cables: number
  length_m: number | null; bidirectional: boolean
}
export interface Vrf { id: number; name: string; rd: string | null; enforce_unique: boolean; description: string | null; prefixes: number; ips: number }
export interface VlanGroup { id: number; name: string; branch_id: number | null; branch: string | null; min_vid: number; max_vid: number; description: string | null; vlans: number }
export interface VlanRow {
  id: number; vid: number; name: string; status: string; role: string | null; description: string | null; group_id: number | null; group: string | null
  branch_id: number | null; branch: string | null; prefixes: string | null; ports: number
}
export interface PrefixRow {
  id: number; prefix: string; family: number; len: number; status: string; status_label: string; role: string | null; is_pool: boolean; mark_utilized: boolean
  description: string | null; vrf_id: number | null; vrf: string | null; branch_id: number | null; branch: string | null; location_id: number | null
  location: string | null; vlan_id: number | null; vlan_vid: number | null; vlan: string | null; depth: number; size: number; used: number; usable: number
  utilization: number; children: number
}
export interface RangeRow {
  id: number; start_address: string; end_address: string; len: number; size: number; status: string; status_label: string; role: string | null
  mark_utilized: boolean; description: string | null; vrf_id: number | null; vrf: string | null; used: number
}
export interface PrefixIp {
  id: number; host: string; address: string; status: string; role: string | null; dns_name: string | null; description: string | null
  port_id: number | null; port: string | null; device_id: number | null; device: string | null; is_primary: boolean | null
}
export type Slot = ({ t: 'ip' } & PrefixIp) | ({ t: 'range' } & RangeRow) | { t: 'free'; first: string; last: string; count: number }
export interface PrefixDetail extends Omit<PrefixRow, 'children'> {
  comments: string | null; parents: { id: number; prefix: string; status: string; description: string | null }[]; children: PrefixRow[]
  ranges: RangeRow[]; ips: PrefixIp[]; first_available_ip: string | null; available_prefixes: string[]; slots: Slot[] | null
}
export interface IpRow {
  id: number; address: string; family: number; status: string; role: string | null; dns_name: string | null; description: string | null
  vrf_id: number | null; vrf: string | null; port_id: number | null; port: string | null; device_id: number | null; device: string | null
  branch: string | null; is_primary: boolean | null; prefix_id: number | null; prefix: string | null; comments?: string | null
}
export interface CircuitType { id: number; name: string; description: string | null; circuits: number }
export interface CircuitRow {
  id: number; cid: string; status: string; commit_rate_kbps: number | null; install_date: string | null; termination_date: string | null
  description: string | null; supplier_id: number; provider: string; type: string; contract_id: number | null; contract_number: string | null
  terminations: Record<'A' | 'Z', { id: number; branch: string | null; location: string | null; provider_network: string | null; cable_id: number | null }> | null
}
export interface CircuitTermination {
  id: number; circuit_id: number; term_side: 'A' | 'Z'; branch_id: number | null; branch: string | null; location_id: number | null; location: string | null
  provider_network: string | null; port_speed_kbps: number | null; upstream_speed_kbps: number | null; xconnect_id: string | null; pp_info: string | null
  description: string | null; cable_id: number | null; cable_label: string | null; cable_color: string | null; peer: string | null
}
export interface CircuitDetail extends Omit<CircuitRow, 'terminations'> {
  type_id: number; comments: string | null; provider_phone: string | null; provider_email: string | null; contract_title: string | null
  terminations: CircuitTermination[]; attachments: Attachment[]
}

const save = (path: string, id: number | null, body: Body) => (id ? send<Id>('PUT', `${path}/${id}`, body) : send<Id>('POST', path, body))
const del = (path: string, id: number) => send<unknown>('DELETE', `${path}/${id}`)

export const dcim = {
  choices: () => get<DcimChoices>('/dcim/choices'),
  locations: (branch?: number | null) => get<{ items: LocationRow[] }>(`/dcim/locations${qs({ branch })}`),
  location: (id: number) => get<LocationDetail>(`/dcim/locations/${id}`),
  saveLocation: (id: number | null, b: Body) => save('/dcim/locations', id, b),
  deleteLocation: (id: number) => del('/dcim/locations', id),
  racks: (f: { branch?: number | null; location?: number | null; q?: string } = {}) => get<{ items: RackRow[] }>(`/dcim/racks${qs(f)}`),
  rack: (id: number) => get<RackDetail>(`/dcim/racks/${id}`),
  saveRack: (id: number | null, b: Body) => save('/dcim/racks', id, b),
  deleteRack: (id: number) => del('/dcim/racks', id),
  roles: () => get<{ items: DeviceRole[] }>('/dcim/roles'),
  saveRole: (id: number | null, b: Body) => save('/dcim/roles', id, b),
  deleteRole: (id: number) => del('/dcim/roles', id),
  types: (q?: string) => get<{ items: DeviceTypeRow[] }>(`/dcim/device-types${qs({ q })}`),
  type: (id: number) => get<DeviceTypeDetail>(`/dcim/device-types/${id}`),
  saveType: (id: number | null, b: Body) => save('/dcim/device-types', id, b),
  deleteType: (id: number) => del('/dcim/device-types', id),
  addTemplates: (typeId: number, b: Body) => send<{ ids: number[] }>('POST', `/dcim/device-types/${typeId}/templates`, b),
  saveTemplate: (id: number, b: Body) => send<Id>('PUT', `/dcim/port-templates/${id}`, b),
  deleteTemplate: (id: number) => del('/dcim/port-templates', id),
  devices: (f: { q?: string; branch?: number | null; location?: number | null; rack?: number | null; role?: number | null; type?: number | null; status?: string } = {}) =>
    get<{ items: DeviceRow[] }>(`/dcim/devices${qs(f)}`),
  device: (id: number) => get<DeviceDetail>(`/dcim/devices/${id}`),
  saveDevice: (id: number | null, b: Body) => save('/dcim/devices', id, b),
  deleteDevice: (id: number) => del('/dcim/devices', id),
  assetCandidates: (q?: string, branch?: number | null) => get<{ items: AssetCandidate[] }>(`/dcim/asset-candidates${qs({ q, branch })}`),
  assetDevice: (uuid: string) => get<{ device: AssetDevice | null }>(`/dcim/assets/${uuid}/device`),
  ports: (deviceId: number, kind?: string, free = false) => get<{ items: PortOption[] }>(`/dcim/devices/${deviceId}/ports${qs({ kind, free })}`),
  addPorts: (deviceId: number, b: Body) => send<{ ids: number[] }>('POST', `/dcim/devices/${deviceId}/ports`, b),
  savePort: (id: number, b: Body) => send<Id>('PUT', `/dcim/ports/${id}`, b),
  deletePorts: (ids: number[]) => send<{ deleted: number }>('POST', '/dcim/ports/delete', { ids }),
  tracePort: (id: number) => get<Trace>(`/dcim/ports/${id}/trace`),
  traceCircuit: (id: number) => get<Trace>(`/dcim/circuit-terminations/${id}/trace`),
  cables: (f: { q?: string; branch?: number | null; device?: number | null; type?: string; status?: string } = {}) => get<{ items: CableRow[] }>(`/dcim/cables${qs(f)}`),
  cable: (id: number) => get<CableRow>(`/dcim/cables/${id}`),
  saveCable: (id: number | null, b: Body) => save('/dcim/cables', id, b),
  deleteCable: (id: number) => del('/dcim/cables', id),
}

export interface AssetDevice {
  id: number; name: string; status: string; branch: string; location: string | null; rack: string | null; position: number | null; face: string | null; role: string
  connections: { port_id: number; port: string; kind: string; cable_label: string | null; destination: PortRow['destination']; link_peers: LinkPeer[] }[]
}

export const ipam = {
  choices: () => get<IpamChoices>('/ipam/choices'),
  prefixes: (f: { q?: string; vrf?: number | null; branch?: number | null; status?: string; family?: number | null; within?: string } = {}) =>
    get<{ items: PrefixRow[] }>(`/ipam/prefixes${qs(f)}`),
  prefix: (id: number) => get<PrefixDetail>(`/ipam/prefixes/${id}`),
  nextIp: (id: number) => get<{ address: string | null }>(`/ipam/prefixes/${id}/next-ip`),
  savePrefix: (id: number | null, b: Body) => save('/ipam/prefixes', id, b),
  deletePrefix: (id: number) => del('/ipam/prefixes', id),
  ranges: (f: { q?: string; vrf?: number | null; status?: string } = {}) => get<{ items: RangeRow[] }>(`/ipam/ranges${qs(f)}`),
  nextIpInRange: (id: number) => get<{ address: string | null }>(`/ipam/ranges/${id}/next-ip`),
  saveRange: (id: number | null, b: Body) => save('/ipam/ranges', id, b),
  deleteRange: (id: number) => del('/ipam/ranges', id),
  ips: (f: { q?: string; vrf?: number | null; status?: string; role?: string; branch?: number | null; device?: number | null; assigned?: boolean | null } = {}) =>
    get<{ items: IpRow[] }>(`/ipam/ip-addresses${qs({ ...f, assigned: f.assigned === null || f.assigned === undefined ? undefined : f.assigned ? 'true' : 'false' })}`),
  ip: (id: number) => get<IpRow>(`/ipam/ip-addresses/${id}`),
  saveIp: (id: number | null, b: Body) => save('/ipam/ip-addresses', id, b),
  deleteIp: (id: number) => del('/ipam/ip-addresses', id),
  vrfs: () => get<{ items: Vrf[] }>('/ipam/vrfs'),
  saveVrf: (id: number | null, b: Body) => save('/ipam/vrfs', id, b),
  deleteVrf: (id: number) => del('/ipam/vrfs', id),
  vlanGroups: () => get<{ items: VlanGroup[] }>('/ipam/vlan-groups'),
  saveVlanGroup: (id: number | null, b: Body) => save('/ipam/vlan-groups', id, b),
  deleteVlanGroup: (id: number) => del('/ipam/vlan-groups', id),
  vlans: (f: { q?: string; group?: number | null; branch?: number | null; status?: string } = {}) => get<{ items: VlanRow[] }>(`/ipam/vlans${qs(f)}`),
  saveVlan: (id: number | null, b: Body) => save('/ipam/vlans', id, b),
  deleteVlan: (id: number) => del('/ipam/vlans', id),
}

export const circuits = {
  types: () => get<{ items: CircuitType[]; status: Opt[] }>('/circuits/types'),
  saveType: (id: number | null, b: Body) => save('/circuits/types', id, b),
  deleteType: (id: number) => del('/circuits/types', id),
  list: (f: { q?: string; branch?: number | null; supplier?: number | null; status?: string } = {}) => get<{ items: CircuitRow[] }>(`/circuits${qs(f)}`),
  detail: (id: number) => get<CircuitDetail>(`/circuits/${id}`),
  save: (id: number | null, b: Body) => save('/circuits', id, b),
  delete: (id: number) => del('/circuits', id),
  saveTermination: (id: number, side: 'A' | 'Z', b: Body) => send<Id>('PUT', `/circuits/${id}/terminations/${side}`, b),
  deleteTermination: (id: number) => del('/circuits/terminations', id),
}

// ---------------------------------------------------------------- utilitários de exibição

let cached: Promise<DcimChoices> | null = null
/** Opções do DCIM (carregadas uma vez por sessão de página). */
export function dcimChoices(): Promise<DcimChoices> {
  cached ??= dcim.choices().catch((e) => { cached = null; throw e })
  return cached
}
let cachedIpam: Promise<IpamChoices> | null = null
export function ipamChoices(force = false): Promise<IpamChoices> {
  if (force) cachedIpam = null
  cachedIpam ??= ipam.choices().catch((e) => { cachedIpam = null; throw e })
  return cachedIpam
}

export const DEVICE_STATUS: Record<string, { label: string; cls: string }> = {
  active: { label: 'Ativo', cls: 'pill-ok' }, planned: { label: 'Planejado', cls: 'pill-info' }, staged: { label: 'Em preparação', cls: 'pill-info' },
  offline: { label: 'Desligado', cls: 'pill-muted' }, failed: { label: 'Com falha', cls: 'pill-bad' }, inventory: { label: 'Em estoque', cls: 'pill-muted' },
  decommissioning: { label: 'Em desativação', cls: 'pill-warn' },
}
export const CABLE_STATUS: Record<string, { label: string; cls: string }> = {
  connected: { label: 'Conectado', cls: 'pill-ok' }, planned: { label: 'Planejado', cls: 'pill-info' }, decommissioning: { label: 'Em desativação', cls: 'pill-warn' },
}
export const CIRCUIT_STATUS: Record<string, { label: string; cls: string }> = {
  planned: { label: 'Planejado', cls: 'pill-info' }, provisioning: { label: 'Em instalação', cls: 'pill-info' }, active: { label: 'Ativo', cls: 'pill-ok' },
  offline: { label: 'Fora do ar', cls: 'pill-bad' }, deprovisioning: { label: 'Em cancelamento', cls: 'pill-warn' }, decommissioned: { label: 'Cancelado', cls: 'pill-muted' },
}
export const IP_STATUS: Record<string, { label: string; cls: string }> = {
  active: { label: 'Ativo', cls: 'pill-ok' }, reserved: { label: 'Reservado', cls: 'pill-info' }, deprecated: { label: 'Obsoleto', cls: 'pill-muted' },
  dhcp: { label: 'DHCP', cls: 'pill-accent' }, container: { label: 'Contêiner', cls: 'pill-muted' },
}
export const PORT_KIND_TABS: { key: string; label: string; kinds: string[] }[] = [
  { key: 'interface', label: 'Interfaces', kinds: ['interface'] },
  { key: 'front', label: 'Portas frontais', kinds: ['front'] },
  { key: 'rear', label: 'Portas traseiras', kinds: ['rear'] },
  { key: 'console', label: 'Console', kinds: ['console', 'console_server'] },
  { key: 'power', label: 'Energia', kinds: ['power_port', 'power_outlet'] },
]

/** "#rrggbb" a partir do hex sem # (cor do cabo/função), com fallback. */
export function hex(c: string | null | undefined, fallback = '9e9e9e'): string {
  return `#${c && /^[0-9a-f]{6}$/i.test(c) ? c : fallback}`
}
/** Texto legível sobre a cor de fundo. */
export function onColor(c: string | null | undefined): string {
  const h = (c && /^[0-9a-f]{6}$/i.test(c) ? c : '9e9e9e')
  const [r, g, b] = [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16))
  return (0.299 * r! + 0.587 * g! + 0.114 * b!) > 150 ? '#111' : '#fff'
}
export function speed(kbps: number | null | undefined): string {
  if (!kbps) return '—'
  if (kbps >= 1_000_000) return `${+(kbps / 1_000_000).toFixed(1)} Gbps`
  if (kbps >= 1000) return `${+(kbps / 1000).toFixed(1)} Mbps`
  return `${kbps} kbps`
}
export function length(l: number | null | undefined, unit: string | null | undefined): string {
  return l === null || l === undefined ? '' : `${String(+l).replace('.', ',')} ${unit ?? 'm'}`
}
export function uLabel(p: number | null | undefined): string {
  return p === null || p === undefined ? '' : `U${String(+p).replace('.', ',')}`
}
/** Tamanho de bloco grande sem estourar (IPv6). */
export function bigCount(n: number): string {
  if (n < 1e6) return n.toLocaleString('pt-BR')
  const exp = Math.round(Math.log2(n))
  return `2^${exp}`
}
