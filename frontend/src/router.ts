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
    { path: '/audit', name: 'audit', component: () => import('./views/AuditView.vue'), meta: { title: 'Auditoria de acesso', admin: true } },
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
