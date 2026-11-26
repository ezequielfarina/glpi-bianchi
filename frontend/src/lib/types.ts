// Contratos dos endpoints /api/ui/* (src/Ui/ReadModel/*.php).
// Datas chegam como string do PostgreSQL ("2026-11-26 17:18:12+00").

export interface Overview {
  kpi: {
    assets: number
    assets_new_7d: number
    assets_stale_7d: number
    agents: number
    agents_24h: number
    inventories_today: number
    avg_ms_today: number
    failed_24h: number
    pending: number
    software: number
  }
  hourly: { hour: string; processed: number; failed: number; other: number }[]
  os: { name: string; count: number }[]
  recent: RecentAsset[]
  generatedAt: string
}

export interface RecentAsset {
  uuid: string
  name: string
  serial_number: string | null
  inventory_number: string | null
  os: string | null
  os_version: string | null
  manufacturer: string | null
  model: string | null
  type: string
  last_inventory_at: string | null
  agent_version: string | null
  ip: string | null
}

export interface AssetRow {
  uuid: string
  name: string
  status: string
  serial_number: string | null
  inventory_number: string | null
  os: string | null
  os_version: string | null
  os_architecture: string | null
  type: string
  type_label: string
  manufacturer: string | null
  model: string | null
  location: string | null
  last_inventory_at: string | null
  dynamic: boolean
  memory_mib: number | null
  ip: string | null
}

export interface AssetsResponse {
  items: AssetRow[]
  total: number
  page: number
  perPage: number
}

export interface Component {
  kind: string
  designation: string
  serial_number: string | null
  slot: string | null
  capacity_mib: number | null
  frequency_mhz: number | null
  attributes: Record<string, unknown>
  dynamic: boolean
  manufacturer: string | null
}

export interface SoftwareItem {
  name: string
  version: string
  architecture: string
  publisher: string | null
  installed_at: string | null
  dynamic: boolean
  first_seen_at: string
}

export interface NetworkPort {
  name: string
  mac_address: string | null
  type: string | null
  speed_mbps: number | null
  oper_status: string | null
  virtual: boolean
  dynamic: boolean
  ips: { address: string; prefix: number | null; version: number }[]
}

export interface HistoryEntry {
  field: string
  old_value: string | null
  new_value: string | null
  source: string
  actor: string | null
  created_at: string
}

export interface InventoryRow {
  id: number
  device_id?: string
  itemtype?: string
  status: string
  partial: boolean
  source_format: string
  size_bytes: number
  duration_ms: number | null
  received_at: string
  processed_at?: string | null
  error: string | null
  asset_uuid?: string | null
  asset_name?: string | null
}

export interface AgentRow {
  device_id: string
  name: string
  version: string | null
  remote_ip: string | null
  tag: string | null
  last_contact_at: string
  created_at?: string
  enabled_tasks: string
  asset_uuid: string | null
  asset_name: string | null
  last_status: string | null
  last_inventory_at: string | null
}

export interface AssetDetail {
  uuid: string
  name: string
  status: string
  serial_number: string | null
  inventory_number: string | null
  hardware_uuid: string | null
  os_name: string | null
  os_full_name: string | null
  os_version: string | null
  os_service_pack: string | null
  os_architecture: string | null
  os_kernel_version: string | null
  os_installed_at: string | null
  attributes: Record<string, string | number | null>
  dynamic: boolean
  comment: string | null
  last_inventory_at: string | null
  created_at: string
  updated_at: string
  type: string
  type_label: string
  manufacturer: string | null
  model: string | null
  organization: string
  location: string | null
  user: string | null
  tech_group: string | null
  components: Component[]
  software: SoftwareItem[]
  networkPorts: NetworkPort[]
  history: HistoryEntry[]
  agents: AgentRow[]
  inventories: InventoryRow[]
}

export interface AgentsResponse { items: AgentRow[] }
export interface InventoriesResponse { items: InventoryRow[] }
