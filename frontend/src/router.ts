import { createRouter, createWebHistory } from 'vue-router'
import { authEvents } from './lib/api'
import { clearMe, isAdmin, loadMe } from './lib/session'

export const router = createRouter({
  history: createWebHistory('/app/'),
  routes: [
    { path: '/login', name: 'login', component: () => import('./views/LoginView.vue'), meta: { public: true, title: 'Entrar' } },
    { path: '/', name: 'overview', component: () => import('./views/OverviewView.vue'), meta: { title: 'Visão geral' } },
    { path: '/assets', name: 'assets', component: () => import('./views/AssetsView.vue'), meta: { title: 'Ativos' } },
    { path: '/assets/new', name: 'asset-new', component: () => import('./views/AssetFormView.vue'), meta: { title: 'Novo ativo', edit: true } },
    { path: '/assets/:uuid/edit', name: 'asset-edit', component: () => import('./views/AssetFormView.vue'), props: true, meta: { title: 'Editar ativo', edit: true } },
    { path: '/assets/:uuid', name: 'asset', component: () => import('./views/AssetDetailView.vue'), props: true, meta: { title: 'Ativo' } },
    { path: '/branches', name: 'branches', component: () => import('./views/BranchesView.vue'), meta: { title: 'Matriz e filiais' } },
    { path: '/branches/:id', name: 'branch', component: () => import('./views/BranchDetailView.vue'), props: true, meta: { title: 'Filial' } },
    { path: '/dcim/locations', name: 'dcim-locations', component: () => import('./views/dcim/LocationsView.vue'), meta: { title: 'Locais' } },
    { path: '/dcim/locations/:id', name: 'dcim-location', component: () => import('./views/dcim/LocationDetailView.vue'), props: true, meta: { title: 'Local' } },
    { path: '/dcim/racks', name: 'dcim-racks', component: () => import('./views/dcim/RacksView.vue'), meta: { title: 'Racks' } },
    { path: '/dcim/racks/:id', name: 'dcim-rack', component: () => import('./views/dcim/RackDetailView.vue'), props: true, meta: { title: 'Rack' } },
    { path: '/dcim/devices', name: 'dcim-devices', component: () => import('./views/dcim/DevicesView.vue'), meta: { title: 'Equipamentos' } },
    { path: '/dcim/devices/:id', name: 'dcim-device', component: () => import('./views/dcim/DeviceDetailView.vue'), props: true, meta: { title: 'Equipamento' } },
    { path: '/dcim/cables', name: 'dcim-cables', component: () => import('./views/dcim/CablesView.vue'), meta: { title: 'Cabos' } },
    { path: '/dcim/cables/:id', name: 'dcim-cable', component: () => import('./views/dcim/CableDetailView.vue'), props: true, meta: { title: 'Cabo' } },
    { path: '/dcim/device-types', name: 'dcim-types', component: () => import('./views/dcim/DeviceTypesView.vue'), meta: { title: 'Tipos de equipamento' } },
    { path: '/dcim/device-types/:id', name: 'dcim-type', component: () => import('./views/dcim/DeviceTypeDetailView.vue'), props: true, meta: { title: 'Tipo de equipamento' } },
    { path: '/dcim/map', name: 'dcim-map', component: () => import('./views/virt/NetworkMapView.vue'), meta: { title: 'Mapa da rede' } },
    { path: '/virtualization/clusters', name: 'virt-clusters', component: () => import('./views/virt/ClustersView.vue'), meta: { title: 'Clusters de virtualização' } },
    { path: '/virtualization/clusters/:id', name: 'virt-cluster', component: () => import('./views/virt/ClusterDetailView.vue'), props: true, meta: { title: 'Cluster' } },
    { path: '/virtualization/vms', name: 'virt-vms', component: () => import('./views/virt/VmsView.vue'), meta: { title: 'Máquinas virtuais' } },
    { path: '/virtualization/vms/:id', name: 'virt-vm', component: () => import('./views/virt/VmDetailView.vue'), props: true, meta: { title: 'Máquina virtual' } },
    { path: '/wifi', name: 'wifi', component: () => import('./views/wifi/WifiAccessView.vue'), meta: { title: 'Acesso Wi-Fi por MAC' } },
    { path: '/wifi/identities', name: 'wifi-identities', component: () => import('./views/wifi/MacIdentitiesView.vue'), meta: { title: 'Identidades de MAC' } },
    { path: '/wifi/controllers', name: 'wifi-controllers', component: () => import('./views/wifi/UnifiControllersView.vue'), meta: { title: 'Controllers UniFi', admin: true } },
    { path: '/circuits', name: 'circuits', component: () => import('./views/circuits/CircuitsView.vue'), meta: { title: 'Circuitos' } },
    { path: '/circuits/:id', name: 'circuit', component: () => import('./views/circuits/CircuitDetailView.vue'), props: true, meta: { title: 'Circuito' } },
    { path: '/ipam/prefixes', name: 'ipam-prefixes', component: () => import('./views/ipam/PrefixesView.vue'), meta: { title: 'Prefixos' } },
    { path: '/ipam/prefixes/:id', name: 'ipam-prefix', component: () => import('./views/ipam/PrefixDetailView.vue'), props: true, meta: { title: 'Prefixo' } },
    { path: '/ipam/ip-addresses', name: 'ipam-ips', component: () => import('./views/ipam/IpAddressesView.vue'), meta: { title: 'Endereços IP' } },
    { path: '/ipam/ip-addresses/:id', name: 'ipam-ip', component: () => import('./views/ipam/IpAddressDetailView.vue'), props: true, meta: { title: 'Endereço IP' } },
    { path: '/ipam/vlans', name: 'ipam-vlans', component: () => import('./views/ipam/VlansView.vue'), meta: { title: 'VLANs e VRFs' } },
    { path: '/stock', name: 'stock', component: () => import('./views/stock/StockView.vue'), meta: { title: 'Estoque' } },
    { path: '/stock/items/:id', name: 'stock-item', component: () => import('./views/stock/StockItemView.vue'), props: true, meta: { title: 'Item do estoque' } },
    { path: '/invoices', name: 'invoices', component: () => import('./views/stock/InvoicesView.vue'), meta: { title: 'Notas fiscais' } },
    { path: '/invoices/new', name: 'invoice-new', component: () => import('./views/stock/InvoiceFormView.vue'), meta: { title: 'Registrar nota fiscal', edit: true } },
    { path: '/invoices/:id', name: 'invoice', component: () => import('./views/stock/InvoiceDetailView.vue'), props: true, meta: { title: 'Nota fiscal' } },
    { path: '/licenses', name: 'licenses', component: () => import('./views/LicensesView.vue'), meta: { title: 'Licenças' } },
    { path: '/licenses/:id', name: 'license', component: () => import('./views/LicenseDetailView.vue'), props: true, meta: { title: 'Licença' } },
    { path: '/contracts', name: 'contracts', component: () => import('./views/ContractsView.vue'), meta: { title: 'Contratos' } },
    { path: '/contracts/:id', name: 'contract', component: () => import('./views/ContractDetailView.vue'), props: true, meta: { title: 'Contrato' } },
    { path: '/suppliers', name: 'suppliers', component: () => import('./views/SuppliersView.vue'), meta: { title: 'Fornecedores' } },
    { path: '/suppliers/:id', name: 'supplier', component: () => import('./views/SupplierDetailView.vue'), props: true, meta: { title: 'Fornecedor' } },
    { path: '/agents', name: 'agents', component: () => import('./views/AgentsView.vue'), meta: { title: 'Agentes' } },
    { path: '/inventories', name: 'inventories', component: () => import('./views/InventoriesView.vue'), meta: { title: 'Inventários' } },
    { path: '/settings', name: 'settings', component: () => import('./views/SettingsView.vue'), meta: { title: 'Configurações' } },
    { path: '/tools/scanner', name: 'scanner', component: () => import('./views/tools/NetworkScannerView.vue'), meta: { title: 'Scanner de rede', edit: true } },
    { path: '/tools/monitor', name: 'monitor', component: () => import('./views/tools/MonitorView.vue'), meta: { title: 'Monitoramento de rede', edit: true } },
    { path: '/tools/conflicts', name: 'conflicts', component: () => import('./views/tools/ConflictsView.vue'), meta: { title: 'Conflitos de IP/MAC', edit: true } },
    { path: '/tools/net', name: 'net', component: () => import('./views/tools/NetToolsView.vue'), meta: { title: 'Diagnóstico e consultas', edit: true } },
    { path: '/tools/subnet', name: 'subnet', component: () => import('./views/tools/SubnetCalculatorView.vue'), meta: { title: 'Calculadora de sub-rede' } },
    { path: '/account', name: 'account', component: () => import('./views/AccountView.vue'), meta: { title: 'Minha conta' } },
    { path: '/users', name: 'users', component: () => import('./views/UsersView.vue'), meta: { title: 'Usuários', admin: true } },
    { path: '/audit', name: 'audit', component: () => import('./views/AuditView.vue'), meta: { title: 'Auditoria', admin: true } },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
  scrollBehavior: () => ({ top: 0 }),
})

router.beforeEach(async (to) => {
  if (to.meta.public) return true
  const user = await loadMe().catch(() => null)
  if (!user) return { name: 'login', query: to.fullPath !== '/' ? { redirect: to.fullPath } : {} }
  // senha temporária: só "Minha conta" até trocar
  if (user.must_change_password && to.name !== 'account') return { name: 'account' }
  if (to.meta.admin && !isAdmin.value) return { name: 'overview' }
  if (to.meta.edit && user.role === 'viewer') {
    if (to.name === 'scanner') return { name: 'overview' }
    return to.params.uuid ? { name: 'asset', params: { uuid: to.params.uuid } } : { name: 'assets' }
  }
  return true
})

router.afterEach((to) => {
  document.title = `${String(to.meta.title ?? 'Inventário')} — GLPI Bianchi`
})

authEvents.addEventListener('unauthorized', () => {
  clearMe()
  if (router.currentRoute.value.name !== 'login') {
    void router.push({ name: 'login', query: { redirect: router.currentRoute.value.fullPath, expired: '1' } })
  }
})
authEvents.addEventListener('password-change', () => {
  void loadMe(true).then(() => router.push({ name: 'account' }))
})
