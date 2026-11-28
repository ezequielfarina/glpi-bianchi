// Cliente da virtualização e da topologia (ADR-0017).
import { http } from './api'
import type { Attachment } from './types'
import type { Trace } from './dcim'

const { get, send, qs } = http
type Opt = { value: string; label: string }
type Id = { id: number }
type Body = Record<string, unknown>

export interface ClusterLookup { id: number; name: string; description: string | null; clusters: number }
export interface VirtChoices { cluster_status: Opt[]; vm_status: Opt[]; types: ClusterLookup[]; groups: ClusterLookup[] }
export interface ClusterRow {
  id: number; name: string; status: string; version: string | null; management_url: string | null; description: string | null
  type_id: number; type: string; group_id: number | null; group: string | null; branch_id: number | null; branch: string | null; location_id: number | null; location: string | null
  hosts: number; vms: number; vms_active: number; vcpus: number; memory_mb: number; disk_gb: number
  cap_cpu_threads: number | null; cap_memory_mb: number | null; cap_storage_gb: number | null
}
export interface ClusterHost {
  id: number; name: string; status: string; asset_id: number | null; asset_uuid: string | null; rack: string | null; position: number | null; location: string | null
  device_type: string; manufacturer: string; primary_ip: string | null; cpu_threads: number | null; memory_mb: number | null; storage_gb: number | null
  capacity_from_inventory: boolean; vms: number; used_vcpus: number; used_memory_mb: number; used_disk_gb: number
}
export interface VmRow {
  id: number; name: string; status: string; platform: string | null; vmid: string | null; vcpus: number | null; memory_mb: number | null; disk_gb: number | null
  description: string | null; cluster_id: number; cluster: string; cluster_type: string; management_url: string | null; device_id: number | null; host: string | null
  branch_id: number | null; branch: string | null; role_id: number | null; role: string | null; color: string | null; asset_uuid: string | null; asset_name: string | null
  primary_ip_id: number | null; primary_ip: string | null; interfaces: number; photo: string | null
}
export interface ClusterDetail extends ClusterRow {
  comments: string | null; hosts_list: ClusterHost[]; attachments: Attachment[]
  vm_list: { id: number; name: string; status: string; platform: string | null; vmid: string | null; vcpus: number | null; memory_mb: number | null; disk_gb: number | null
    device_id: number | null; host: string | null; role: string | null; color: string | null; primary_ip: string | null }[]
}
export interface VmInterface {
  id: number; name: string; enabled: boolean; mac_address: string | null; mtu: number | null; mode: string | null; bridge: string | null; description: string | null
  untagged_vlan_id: number | null; untagged_vid: number | null; untagged_vlan: string | null; tagged_vids: string | null; tagged_vlan_ids: number[]; ips: string | null
}
export interface VmDisk { id: number; name: string; size_gb: number; storage: string | null; description: string | null }
export interface VmDetail extends VmRow {
  comments: string | null; interfaces_list: VmInterface[]; disks: VmDisk[]; attachments: Attachment[]
  ips: { id: number; address: string; status: string; role: string | null; dns_name: string | null; vm_interface_id: number; interface: string }[]
}
export interface VmAssetCandidate { uuid: string; name: string; serial_number: string | null; inventory_number: string | null; type: string; branch: string | null; vm_system: string | null; memory_mib: string | null; interfaces: number }

export interface PathSegment {
  from_device_id: number; from_port_id: number; from_port: string; to_kind: 'device' | 'circuit'; to_device_id: number | null; to_device: string | null; to_port: string
  circuit_id: number | null; provider: string | null; cid: string | null; provider_network: string | null; complete: boolean; active: boolean; cables: number
  length_m: number | null; trace: Trace
}
export interface PathToInternet { found: boolean; start: { id: number; name: string }; segments: PathSegment[]; reason: 'no_cable' | 'no_route' | null; reached?: number }

export interface TopoNode {
  id: string; kind: 'device' | 'circuit' | 'cloud' | 'cluster' | 'vm'; name: string; role?: string; color?: string | null; branch?: string; location?: string | null
  rack?: string | null; position?: number | null; device_type?: string; status?: string; primary_ip?: string | null; passive?: boolean; external?: boolean
  connected?: number; ports?: number; photo?: string | null; asset_uuid?: string | null; device_id?: number; circuit_id?: number; provider?: string; cid?: string
  type?: string; commit_rate_kbps?: number | null; cluster_id?: number; vm_id?: number; vms?: number; vcpus?: number | null; memory_mb?: number | null; disk_gb?: number | null
  platform?: string | null; management_url?: string | null; icon?: string | null; u_height?: number | null
  // campos do force-graph
  x?: number; y?: number; z?: number
}
export interface TopoLink {
  source: string | TopoNode; target: string | TopoNode; kind: 'data' | 'power' | 'console' | 'member' | 'virtual' | 'provider'
  from_port?: string; to_port?: string; color?: string | null; cables?: number; labels?: string; via?: string[]; length_m?: number | null
  active?: boolean; complete?: boolean; type?: string | null; label?: string; cable_id?: number
}
export interface Topology { nodes: TopoNode[]; links: TopoLink[]; mode: string }

const save = (path: string, id: number | null, b: Body) => (id ? send<Id>('PUT', `${path}/${id}`, b) : send<Id>('POST', path, b))
const del = (path: string, id: number) => send<unknown>('DELETE', `${path}/${id}`)

export const virt = {
  choices: () => get<VirtChoices>('/virtualization/choices'),
  saveLookup: (kind: 'type' | 'group', id: number | null, b: Body) => save(`/virtualization/lookups/${kind}`, id, b),
  deleteLookup: (kind: 'type' | 'group', id: number) => del(`/virtualization/lookups/${kind}`, id),
  clusters: (f: { q?: string; branch?: number | null; type?: number | null; group?: number | null } = {}) => get<{ items: ClusterRow[] }>(`/virtualization/clusters${qs(f)}`),
  cluster: (id: number) => get<ClusterDetail>(`/virtualization/clusters/${id}`),
  saveCluster: (id: number | null, b: Body) => save('/virtualization/clusters', id, b),
  deleteCluster: (id: number) => del('/virtualization/clusters', id),
  setHost: (id: number, deviceId: number, member: boolean) => send<Id>('POST', `/virtualization/clusters/${id}/hosts`, { device_id: deviceId, member }),
  vms: (f: { q?: string; cluster?: number | null; host?: number | null; branch?: number | null; status?: string } = {}) => get<{ items: VmRow[] }>(`/virtualization/vms${qs(f)}`),
  vm: (id: number) => get<VmDetail>(`/virtualization/vms/${id}`),
  saveVm: (id: number | null, b: Body) => save('/virtualization/vms', id, b),
  deleteVm: (id: number) => del('/virtualization/vms', id),
  assetCandidates: (q?: string) => get<{ items: VmAssetCandidate[] }>(`/virtualization/asset-candidates${qs({ q })}`),
  addInterfaces: (vm: number, b: Body) => send<{ ids: number[] }>('POST', `/virtualization/vms/${vm}/interfaces`, b),
  saveInterface: (id: number, b: Body) => send<Id>('PUT', `/virtualization/interfaces/${id}`, b),
  deleteInterface: (id: number) => del('/virtualization/interfaces', id),
  saveDisk: (vm: number, id: number | null, b: Body) => (id ? send<Id>('PUT', `/virtualization/vms/${vm}/disks/${id}`, b) : send<Id>('POST', `/virtualization/vms/${vm}/disks`, b)),
  deleteDisk: (id: number) => del('/virtualization/disks', id),
  topology: (f: { branch?: number | null; mode?: 'logical' | 'physical'; vms?: boolean } = {}) =>
    get<Topology>(`/dcim/topology${qs({ branch: f.branch, mode: f.mode, vms: f.vms === false ? 'false' : undefined })}`),
  pathToInternet: (deviceId: number) => get<PathToInternet>(`/dcim/devices/${deviceId}/path-to-internet`),
}

export const VM_STATUS: Record<string, { label: string; cls: string }> = {
  active: { label: 'Ligada', cls: 'pill-ok' }, offline: { label: 'Desligada', cls: 'pill-muted' }, planned: { label: 'Planejada', cls: 'pill-info' },
  staged: { label: 'Em preparação', cls: 'pill-info' }, failed: { label: 'Com falha', cls: 'pill-bad' }, decommissioning: { label: 'Em desativação', cls: 'pill-warn' },
}
export const CLUSTER_STATUS: Record<string, { label: string; cls: string }> = {
  planned: { label: 'Planejado', cls: 'pill-info' }, staging: { label: 'Em implantação', cls: 'pill-info' }, active: { label: 'Ativo', cls: 'pill-ok' },
  decommissioning: { label: 'Em desativação', cls: 'pill-warn' }, offline: { label: 'Fora do ar', cls: 'pill-bad' },
}
/** Ícone/cor por plataforma de virtualização. */
export function platformBadge(type: string | null | undefined): { short: string; color: string } {
  const t = (type ?? '').toLowerCase()
  if (t.includes('proxmox')) return { short: 'PVE', color: '#e57000' }
  if (t.includes('vmware') || t.includes('esxi')) return { short: 'ESXi', color: '#1d8ec8' }
  if (t.includes('hyper')) return { short: 'Hyper-V', color: '#0078d4' }
  if (t.includes('xcp') || t.includes('xen')) return { short: 'XCP', color: '#5b6abf' }
  if (t.includes('nutanix')) return { short: 'AHV', color: '#4c9e2d' }
  return { short: 'KVM', color: '#8e24aa' }
}
export function gb(mb: number | null | undefined): string {
  if (mb === null || mb === undefined) return '—'
  return mb >= 1024 ? `${+(mb / 1024).toFixed(1)} GB`.replace('.', ',') : `${mb} MB`
}
export function num(n: number | null | undefined): string {
  return n === null || n === undefined ? '—' : String(+n).replace('.', ',')
}
