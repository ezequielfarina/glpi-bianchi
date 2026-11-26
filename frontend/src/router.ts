import { createRouter, createWebHistory } from 'vue-router'
import { auth, onUnauthorized } from './lib/api'

export const router = createRouter({
  history: createWebHistory('/app/'),
  routes: [
    { path: '/login', name: 'login', component: () => import('./views/LoginView.vue'), meta: { public: true, title: 'Entrar' } },
    { path: '/', name: 'overview', component: () => import('./views/OverviewView.vue'), meta: { title: 'Visão geral' } },
    { path: '/assets', name: 'assets', component: () => import('./views/AssetsView.vue'), meta: { title: 'Ativos' } },
    { path: '/assets/new', name: 'asset-new', component: () => import('./views/AssetFormView.vue'), meta: { title: 'Novo ativo' } },
    { path: '/assets/:uuid/edit', name: 'asset-edit', component: () => import('./views/AssetFormView.vue'), props: true, meta: { title: 'Editar ativo' } },
    { path: '/assets/:uuid', name: 'asset', component: () => import('./views/AssetDetailView.vue'), props: true, meta: { title: 'Ativo' } },
    { path: '/licenses', name: 'licenses', component: () => import('./views/LicensesView.vue'), meta: { title: 'Licenças' } },
    { path: '/licenses/:id', name: 'license', component: () => import('./views/LicenseDetailView.vue'), props: true, meta: { title: 'Licença' } },
    { path: '/contracts', name: 'contracts', component: () => import('./views/ContractsView.vue'), meta: { title: 'Contratos' } },
    { path: '/contracts/:id', name: 'contract', component: () => import('./views/ContractDetailView.vue'), props: true, meta: { title: 'Contrato' } },
    { path: '/suppliers', name: 'suppliers', component: () => import('./views/SuppliersView.vue'), meta: { title: 'Fornecedores' } },
    { path: '/suppliers/:id', name: 'supplier', component: () => import('./views/SupplierDetailView.vue'), props: true, meta: { title: 'Fornecedor' } },
    { path: '/agents', name: 'agents', component: () => import('./views/AgentsView.vue'), meta: { title: 'Agentes' } },
    { path: '/inventories', name: 'inventories', component: () => import('./views/InventoriesView.vue'), meta: { title: 'Inventários' } },
    { path: '/settings', name: 'settings', component: () => import('./views/SettingsView.vue'), meta: { title: 'Configurações' } },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
  scrollBehavior: () => ({ top: 0 }),
})

router.beforeEach((to) => {
  if (!to.meta.public && !auth.token) return { name: 'login', query: { redirect: to.fullPath } }
  return true
})

router.afterEach((to) => {
  document.title = `${String(to.meta.title ?? 'Inventário')} — GLPI Bianchi`
})

onUnauthorized.addEventListener('unauthorized', () => {
  auth.clear()
  if (router.currentRoute.value.name !== 'login') {
    void router.push({ name: 'login', query: { redirect: router.currentRoute.value.fullPath } })
  }
})
