// Contratos dos endpoints /api/ui/* (src/Ui/ReadModel/*.php).
// Datas chegam como string do PostgreSQL ("2026-11-26 17:18:12+00").

export interface TypeSummary {
  type: string
  label: string
  total: number
  in_use: number
  in_stock: number
  in_repair: number
  other: number
  stale: number
  no_branch: number
}

export interface BranchTypeCell {
  branch_id: number | null
  branch: string | null
  branch_code: string | null
  kind: BranchKind | null
  type: string
  total: number
}

export interface Overview {
  by_type: TypeSummary[]
  by_branch: BranchTypeCell[]
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
  branch_id: number | null
  branch: string | null
  branch_code: string | null
  last_inventory_at: string | null
  dynamic: boolean
  memory_mib: number | null
  ip: string | null
  photo_uuid: string | null
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

export type Outcome = 'created' | 'updated' | 'unchanged'

export interface InventoryRow {
  id: number
  device_id?: string
  itemtype?: string
  status: string
  outcome: Outcome | null
  changes: number | null
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
  last_outcome?: Outcome | null
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
  branch_id: number | null
  branch: string | null
  branch_code: string | null
  user: string | null
  tech_group: string | null
  components: Component[]
  software: SoftwareItem[]
  networkPorts: NetworkPort[]
  history: HistoryEntry[]
  agents: AgentRow[]
  inventories: InventoryRow[]
  attachments: Attachment[]
  photo_uuid: string | null
  licenses: { assignment_id: number; id: number; name: string; expires_at: string | null; license_type: string }[]
  contracts: { id: number; title: string; number: string | null; end_date: string | null; supplier: string | null }[]
  locked_fields: string[]
  deleted_at: string | null
}

export interface AgentsResponse { items: AgentRow[] }
export interface InventoriesResponse { items: InventoryRow[] }

export interface SettingItem {
  value: number | string
  default: number | string
  label: string
  help: string
  type: 'int' | 'string'
  min: number
  max: number
  unit?: string
  updatedAt: string | null
}
export interface SettingsResponse { items: Record<string, SettingItem> }

export interface WakeupResult {
  result: 'ok' | 'forbidden' | 'unreachable' | 'no_address'
  message: string
  address: string | null
  tried: string[]
  answer: string | null
}

// ---------------------------------------------------------------- gestão
export interface Attachment {
  uuid: string
  kind: 'photo' | 'document'
  filename: string
  mime_type: string
  size_bytes: number
  description?: string | null
  created_by?: string | null
  created_at?: string
}

export type Assignment = 'device' | 'user' | 'volume'
export interface Lookups {
  asset_types: { code: string; label: string; inventoriable: boolean }[]
  license_types: { id: number; name: string; assignment: Assignment; in_use: number }[]
  license_categories: { id: number; name: string; in_use: number }[]
  contract_types: { id: number; name: string; in_use: number }[]
  suppliers: { id: number; name: string }[]
  contracts: { id: number; title: string; number: string | null }[]
  locations: { id: number; name: string }[]
  branches: { id: number; name: string; code: string | null; kind: BranchKind }[]
  manufacturers: { id: number; name: string }[]
}

export interface Alerts {
  contracts_expiring: number
  contracts_expired: number
  contracts_annual_value: string
  licenses_expiring: number
  licenses_over: number
}

export interface SupplierRow {
  id: number; name: string; trade_name: string | null; tax_id: string | null; email: string | null; phone: string | null
  website: string | null; active: boolean; contacts: number; contracts: number; licenses: number
}
export interface Contact { id: number; name: string; role: string | null; email: string | null; phone: string | null; mobile: string | null; notes: string | null }
export interface SupplierDetail extends Omit<SupplierRow, 'contacts' | 'contracts' | 'licenses'> {
  address: string | null; notes: string | null; created_at: string; updated_at: string
  contacts_list: Contact[]; contracts_list: ContractRow[]; licenses_list: { id: number; name: string; seats: number; expires_at: string | null }[]
  attachments: Attachment[]
}

export type ContractStatus = 'open' | 'active' | 'expiring' | 'expired'
export interface ContractRow {
  id: number; title: string; number: string | null; start_date: string | null; end_date: string | null
  renewal: 'none' | 'manual' | 'automatic'; notice_days: number | null
  billing_period: 'one_time' | 'monthly' | 'quarterly' | 'semiannual' | 'yearly'; value: string | null
  status: ContractStatus; annual_value: string | null; days_left: number | null
  supplier_id: number | null; supplier: string | null; contract_type: string | null; assets: number; attachments: number
}
export interface ContractDetail extends Omit<ContractRow, 'assets' | 'attachments'> {
  description: string | null; contract_type_id: number | null; created_at: string; updated_at: string
  assets: { uuid: string; name: string; inventory_number: string | null; serial_number: string | null; type_label: string }[]
  licenses: { id: number; name: string; seats: number }[]
  attachments: Attachment[]
}

export type Compliance = 'ok' | 'over' | 'expiring' | 'expired'
export interface LicenseRow {
  id: number; name: string; assignment: Assignment; seats: number; unit_price: string | null; total_price: string | null
  purchase_date: string | null; expires_at: string | null; software_match: string | null; has_key: boolean
  license_type: string; category: string | null; supplier: string | null; contract: string | null
  used: number; detected: number | null; compliance: Compliance
}
export interface LicenseDetail extends LicenseRow {
  license_type_id: number; license_category_id: number | null; supplier_id: number | null; contract_id: number | null
  notes: string | null; created_at: string; updated_at: string
  assignments: { id: number; assigned_at: string; notes: string | null; asset_uuid: string | null; asset_name: string | null; inventory_number: string | null; user: string | null; user_name: string | null }[]
  unassigned_detected: { uuid: string; name: string; inventory_number: string | null; software: string; version: string }[]
  attachments: Attachment[]
}

export type BranchKind = 'headquarters' | 'branch'

export interface BranchRow {
  id: number
  name: string
  code: string | null
  kind: BranchKind
  tax_id: string | null
  phone: string | null
  email: string | null
  zip_code: string | null
  street: string | null
  number: string | null
  complement: string | null
  district: string | null
  city: string | null
  state: string | null
  latitude: number | null
  longitude: number | null
  networks: string[]
  notes: string | null
  active: boolean
  created_at: string
  updated_at: string
  assets: number
  computers: number
  stale: number
}

export interface BranchDetail extends BranchRow {
  by_type: { type: string; type_label: string; total: number }[]
  unassigned_assets: number
  attachments: Attachment[]
}
