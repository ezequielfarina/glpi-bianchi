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

export type Role = 'admin' | 'editor' | 'viewer'

export interface Me {
  id: number
  username: string
  display_name: string
  email: string | null
  role: Role
  must_change_password: boolean
  mfa: boolean
  password_changed_at: string | null
  previous_login: { created_at: string; ip: string | null } | null
  session_id: string
}

export interface SessionRow {
  id: number
  ip: string | null
  user_agent: string | null
  created_at: string
  last_seen_at: string
  expires_at: string
  current: boolean
}

export interface AccountRow {
  id: number
  username: string
  display_name: string
  email: string | null
  role: Role
  active: boolean
  must_change_password: boolean
  mfa: boolean
  last_login_at: string | null
  last_login_ip: string | null
  password_changed_at: string | null
  created_at: string
  sessions: number
  locked: boolean
}

export interface AuditEvent {
  id: number
  event: string
  username: string | null
  display_name: string | null
  ip: string | null
  user_agent: string | null
  actor: string | null
  details: Record<string, unknown>
  created_at: string
}

export interface AuditPage { items: AuditEvent[]; total: number; page: number; perPage: number }

// ---------------------------------------------------------------- ferramentas: scanner de rede
export type ScanStatus = 'queued' | 'running' | 'done' | 'failed' | 'cancelled'
export type ScanProfileCode = 'discovery' | 'standard' | 'full'

export interface ScannerInfo {
  nmap: string | null
  snmp: boolean
  profiles: { code: ScanProfileCode; label: string; max_prefix: number }[]
  suggestions: { cidr: string; label: string }[]
}

export interface ScanRow {
  id: number
  target: string
  profile: ScanProfileCode
  status: ScanStatus
  phase: string | null
  progress: number
  hosts_up: number
  requested_by: string
  branch_id: number | null
  branch: string | null
  schedule_id: number | null
  schedule: string | null
  error: string | null
  snmp_gateway: string | null
  privileged: boolean | null
  nmap: string | null
  warnings: string[]
  addresses: number | null
  created_at: string
  started_at: string | null
  finished_at: string | null
}

export interface ScanPort {
  port: number
  proto: string
  service?: string
  product?: string
  version?: string
  extra?: string
  tunnel?: string
  title?: string
  server?: string
  generator?: string
  cert?: { subject?: string; issuer?: string; expires?: string }
  scripts?: Record<string, string>
}

export interface ScanHost {
  id: number
  ip: string
  mac: string | null
  mac_source: 'arp' | 'nbstat' | 'snmp' | 'inventory' | null
  vendor: string | null
  hostname: string | null
  netbios_name: string | null
  domain: string | null
  os: string | null
  os_accuracy: number | null
  device_type: string
  state: 'up' | 'arp'
  latency_ms: number | null
  ports: ScanPort[]
  details: Record<string, unknown>
  match_by: 'mac' | 'ip' | 'name' | null
  asset_uuid: string | null
  asset_name: string | null
  asset_type: string | null
  asset_number: string | null
}

export interface ScanDetail extends ScanRow { hosts: ScanHost[] }

// ---------------------------------------------------------------- Monitoramento (ADR-0013)
export type AlertSeverity = 'info' | 'warning' | 'critical'
export type AlertKind = 'new_device' | 'device_gone' | 'mac_changed' | 'new_ports' | 'ip_conflict' | 'scan_failed' | 'baseline'

export interface NetworkAlert {
  id: number
  kind: AlertKind
  kind_label: string
  severity: AlertSeverity
  title: string
  detail: Record<string, unknown>
  ip: string | null
  mac: string | null
  occurrences: number
  scan_id: number | null
  scan_target: string | null
  schedule_id: number | null
  schedule: string | null
  branch: string | null
  asset_uuid: string | null
  asset_name: string | null
  created_at: string
  last_seen_at: string
  notified_at: string | null
  acknowledged_at: string | null
  acknowledged_by: string | null
}

export interface AlertCounts { critical: number; warning: number; info: number; open: number }

export type AlertFlags = Record<'new_device' | 'mac_changed' | 'ip_conflict' | 'new_ports' | 'device_gone', boolean>

export interface ScanSchedule {
  id: number
  name: string
  target: string
  profile: ScanProfileCode
  frequency: 'interval' | 'daily'
  interval_hours: number | null
  run_at: string | null
  weekdays: number[]
  snmp_gateway: string | null
  has_community: boolean
  alerts: AlertFlags
  enabled: boolean
  next_run_at: string | null
  last_run_at: string | null
  last_scan: { id: number; status: ScanStatus; hosts_up: number; error: string | null; finished_at: string | null } | null
  open_alerts: number
  created_by: string
}

export type ChannelKind = 'webhook' | 'slack' | 'teams' | 'telegram'
export interface NotificationChannel {
  id: number
  name: string
  kind: ChannelKind
  target_masked: string
  chat_id: string | null
  min_severity: AlertSeverity
  enabled: boolean
  last_status: 'ok' | 'error' | null
  last_error: string | null
  last_sent_at: string | null
}

export interface AssetRef { uuid: string; name: string; type?: string | null; serial?: string | null; port?: string | null; mac?: string | null; inventory_number?: string | null; last_inventory_at?: string | null }

export interface ConflictReport {
  days: number
  last_scan_with_arp: string | null
  arp: { ip: string; scan_id: number; target: string; seen_at: string; branch: string | null; current: boolean; macs: { mac: string; vendor: string | null; assets: AssetRef[] }[] }[]
  scan_vs_inventory: {
    ip: string; scan_id: number; target: string; seen_at: string
    seen: { mac: string; vendor: string | null; name: string | null; assets: AssetRef[] }
    inventory: { mac: string; vendor: string | null; asset_uuid: string; asset_name: string; last_inventory_at: string | null }
  }[]
  mac_duplicates: { mac: string; vendor: string | null; assets: AssetRef[]; hint: string }[]
  ip_duplicates: { ip: string; assets: AssetRef[]; likely: boolean; hint: string }[]
}

// ---------------------------------------------------------------- Diagnóstico e consultas
export interface NetInfo {
  tools: Record<'ping' | 'mtr' | 'traceroute' | 'dig' | 'arp_scan' | 'mac_db', boolean>
  resolvers: string[]
  dns_types: string[]
  port_names: Record<string, string>
}
export interface PingResult {
  host: string; ip: string; sent: number; received: number; loss: number
  rtt: { min: number; avg: number; max: number; mdev: number } | null
  replies: { seq: number; ttl: number | null; ms: number | null; ok: boolean; text: string | null }[]
  raw: string
}
export interface TraceHop { hop: number; name: string | null; ip: string | null; loss: number; sent: number; last: number | null; avg: number | null; best: number | null; worst: number | null; stdev: number | null }
export interface TraceResult { host: string; tool: 'mtr' | 'traceroute'; cycles: number; hops: TraceHop[] }
export interface TlsInfo {
  subject?: string | null; issuer?: string; valid_from?: string; valid_to?: string; days_left?: number; sans?: string[]
  self_signed?: boolean; trusted?: boolean; protocol?: string | null; cipher?: string | null; error: string | null
}
export interface PortResult { port: number; name: string | null; state: 'open' | 'closed' | 'filtered' | 'skipped'; ms: number | null; error: string | null; tls: TlsInfo | null; banner: string | null }
export interface PortsResult { host: string; ip: string | null; results: PortResult[] }
export interface DnsRecord { name: string; ttl: number; type: string; data: string }
export interface DnsResult {
  name: string; type: string; divergent: boolean
  results: { server: string; status: string; flags: string[]; answers: DnsRecord[]; authority: DnsRecord[]; ms: number | null; via: string | null }[]
}
export interface AsnResult {
  ip: string; special: string | null; error?: string
  asn: { number: number; name: string | null }[] | null
  prefix?: string | null; country?: string | null; registry?: string | null; allocated?: string | null
}
export interface RdapResult {
  query: string; kind: string; special: string | null; found?: boolean; source?: string | null
  handle?: string | null; name?: string | null; type?: string | null; country?: string | null; status?: string[]; range?: string | null; cidrs?: string[]
  parent?: string | null; nameservers?: string[]; dnssec?: boolean | null; registered?: string | null; changed?: string | null; expires?: string | null
  entities?: { roles: string[]; handle: string | null; name: string | null; org: string | null; email: string | null; country: string | null }[]
  raw?: string
}
export interface MacItem {
  input: string; mac: string | null; oui: string | null; vendor: string | null; valid: boolean; flags: string[]
  assets: AssetRef[]; scans: { scan_id: number; ip: string; target: string; at: string }[]
}

// ------------------------------------------------------------------ estoque e notas fiscais (ADR-0014)

export interface StockItem {
  id: number
  name: string
  category: string
  tracked: boolean
  type_code: string | null
  type_label: string | null
  min_quantity: number
  active: boolean
  notes: string | null
  balance: number
  low: boolean
  to_buy: number
  avg_cost: string | null
  value: string | null
  last_in: string | null
  last_out: string | null
  movements: number
}

export interface StockUnit {
  uuid: string
  inventory_number: string | null
  serial_number: string | null
  since: string
  unit_cost: string | null
  invoice_number: string | null
  invoice_id: number | null
}

export interface StockMovement {
  id: number
  batch: string | null
  kind: 'in' | 'out' | 'adjust'
  kind_label: string
  item_id: number
  item: string
  category: string
  quantity: number
  unit_cost: string | null
  occurred_at: string
  branch_id: number | null
  branch: string | null
  recipient: string | null
  reason: string | null
  actor: string
  invoice_id: number | null
  invoice_number: string | null
  supplier: string | null
  inventory_numbers: string[]
  asset_uuids: string[]
}

export interface StockItemDetail extends StockItem {
  units: StockUnit[]
  movements_recent: StockMovement[]
  consumption: { branch_id: number; branch: string; quantity: number; last_at: string }[]
}

export interface StockSummary {
  items: number
  units: number
  value: string
  low: number
  to_buy: number
  invoices_pending: number
  categories: string[]
}

export interface StockConsumption {
  from: string
  to: string
  branches: { branch_id: number; branch: string; quantity: number; value: string; items: { item_id: number; item: string; category: string; quantity: number; value: string }[] }[]
}

export type InvoiceStatus = 'registered' | 'sent'

export interface InvoiceRow {
  id: number
  number: string
  series: string
  supplier_id: number
  supplier: string
  issue_date: string
  total: string
  items_total: string
  difference: string
  status: InvoiceStatus
  status_label: string
  sent_at: string | null
  created_at: string
  lines?: number
  units?: number
  summary?: string | null
}

export interface InvoiceDetail extends InvoiceRow {
  supplier_tax_id: string | null
  notes: string | null
  sent_by: string | null
  created_by: string
  items: { id: number; stock_item_id: number; item: string; category: string; tracked: boolean; quantity: number; unit_price: string; subtotal: string }[]
  assets: { uuid: string; name: string; inventory_number: string | null; serial_number: string | null; status: string; branch: string | null; invoice_item_id: number }[]
  attachments: Attachment[]
  can_delete: boolean
  delete_blocker: string | null
}
