<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon, { type IconName } from './AppIcon.vue'
import ToastHost from './ToastHost.vue'
import { api } from '../lib/api'
import { alertCounts, refreshAlertCounts } from '../lib/alerts'
import { refreshStockLow, stockLow } from '../lib/stock'
import { canEdit, clearMe, isAdmin, me, ROLE_LABEL } from '../lib/session'
import { theme, toggleTheme } from '../lib/theme'

const route = useRoute()
const router = useRouter()
const search = ref('')

const allNav: { to: string; label: string; icon: IconName; group?: string; admin?: boolean; edit?: boolean; badge?: 'alerts' | 'stock' }[] = [
  { to: '/', label: 'Visão geral', icon: 'home' },
  { to: '/assets', label: 'Ativos', icon: 'assets' },
  { to: '/branches', label: 'Matriz e filiais', icon: 'building' },
  { to: '/dcim/locations', label: 'Locais', icon: 'tree', group: 'Infraestrutura' },
  { to: '/dcim/racks', label: 'Racks', icon: 'rack' },
  { to: '/dcim/devices', label: 'Equipamentos', icon: 'server' },
  { to: '/dcim/cables', label: 'Cabos', icon: 'cable' },
  { to: '/circuits', label: 'Circuitos (operadoras)', icon: 'globe' },
  { to: '/dcim/device-types', label: 'Tipos de equipamento', icon: 'layers' },
  { to: '/ipam/prefixes', label: 'Prefixos', icon: 'hash', group: 'IPAM' },
  { to: '/ipam/ip-addresses', label: 'Endereços IP', icon: 'network' },
  { to: '/ipam/vlans', label: 'VLANs e VRFs', icon: 'layers' },
  { to: '/stock', label: 'Estoque', icon: 'box', group: 'Estoque', badge: 'stock' },
  { to: '/invoices', label: 'Notas fiscais', icon: 'file' },
  { to: '/licenses', label: 'Licenças', icon: 'key', group: 'Gestão' },
  { to: '/contracts', label: 'Contratos', icon: 'contract' },
  { to: '/suppliers', label: 'Fornecedores', icon: 'truck' },
  { to: '/agents', label: 'Agentes', icon: 'agents', group: 'Inventário automático' },
  { to: '/inventories', label: 'Inventários recebidos', icon: 'inbox' },
  { to: '/tools/scanner', label: 'Scanner de rede', icon: 'radar', group: 'Ferramentas', edit: true },
  { to: '/tools/monitor', label: 'Monitoramento', icon: 'bell', edit: true, badge: 'alerts' },
  { to: '/tools/conflicts', label: 'Conflitos de IP/MAC', icon: 'conflict', edit: true },
  { to: '/tools/net', label: 'Diagnóstico e consultas', icon: 'pulse', edit: true },
  { to: '/tools/subnet', label: 'Calculadora IP', icon: 'calculator', group: 'Ferramentas' },
  { to: '/settings', label: 'Configurações', icon: 'settings', group: 'Sistema' },
  { to: '/users', label: 'Usuários', icon: 'user', admin: true },
  { to: '/audit', label: 'Auditoria', icon: 'history', admin: true },
]
type NavItem = (typeof allNav)[number]
// menu em seções recolhíveis: itens sem grupo no topo; cada grupo abre/fecha (estado salvo no navegador)
const sections = computed(() => {
  const out: { name: string | null; items: NavItem[] }[] = []
  let group: string | null = null
  for (const i of allNav) {
    group = i.group ?? group
    if ((i.admin && !isAdmin.value) || (i.edit && !canEdit.value)) continue
    const last = out[out.length - 1]
    if (last && last.name === group) last.items.push(i)
    else out.push({ name: group, items: [i] })
  }
  return out
})
const NAV_KEY = 'bianchi.nav.open'
const openGroups = ref<string[]>((() => {
  try { const v = JSON.parse(localStorage.getItem(NAV_KEY) ?? 'null'); return Array.isArray(v) ? v : [] } catch { return [] }
})())
const isOpen = (g: string) => openGroups.value.includes(g)
function toggleGroup(g: string): void {
  openGroups.value = isOpen(g) ? openGroups.value.filter((x) => x !== g) : [...openGroups.value, g]
}
watch(openGroups, (v) => { try { localStorage.setItem(NAV_KEY, JSON.stringify(v)) } catch { /* sem storage */ } })
// o grupo da página atual sempre abre (navegação por link, busca, voltar)
watch(() => route.path, () => {
  const sec = sections.value.find((x) => x.name && x.items.some((i) => isActive(i.to)))
  if (sec?.name && !isOpen(sec.name)) openGroups.value = [...openGroups.value, sec.name]
}, { immediate: true })
// badge do grupo fechado: soma do que está dentro (alertas críticos pintam de vermelho)
function groupBadge(items: NavItem[]): { n: number; crit: boolean } {
  let n = 0
  let crit = false
  for (const i of items) {
    if (i.badge === 'alerts') { n += alertCounts.value.open; crit ||= alertCounts.value.critical > 0 }
    if (i.badge === 'stock') n += stockLow.value
  }
  return { n, crit }
}

// alertas de rede abertos (badge no menu) — técnico/admin, a cada 60 s
let alertTimer: number | undefined
// a sessão (me) carrega depois do mount: liga/desliga conforme o perfil
watch(canEdit, (can) => {
  window.clearInterval(alertTimer)
  if (!can) { alertCounts.value = { critical: 0, warning: 0, info: 0, open: 0 }; return }
  void refreshAlertCounts()
  alertTimer = window.setInterval(() => { if (document.visibilityState === 'visible') void refreshAlertCounts() }, 60_000)
}, { immediate: true })
// itens abaixo do mínimo (badge "Estoque") — todos os perfis
void refreshStockLow()
const stockTimer = window.setInterval(() => { if (document.visibilityState === 'visible') void refreshStockLow() }, 120_000)
onBeforeUnmount(() => { window.clearInterval(alertTimer); window.clearInterval(stockTimer) })
const initials = computed(() => (me.value?.display_name ?? '?').split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]!.toUpperCase()).join(''))

function isActive(to: string): boolean { return to === '/' ? route.path === '/' : route.path === to || route.path.startsWith(`${to}/`) || (to === '/stock' && route.path.startsWith('/stock')) }
const themeLabel = computed(() => (theme.value === 'dark' ? 'Usar tema claro' : 'Usar tema escuro'))

function submitSearch(): void {
  void router.push({ path: '/assets', query: search.value.trim() ? { q: search.value.trim() } : {} })
}

async function logout(): Promise<void> {
  try { await api.logout() } catch { /* sessão já encerrada */ }
  clearMe()
  await router.push('/login')
}
</script>

<template>
  <div class="shell" :class="me ? `role-${me.role}` : ''">
    <a class="skip" href="#main">Pular para o conteúdo</a>

    <aside class="sidebar">
      <RouterLink to="/" class="brand">
        <span class="brand-mark"><AppIcon name="logo" :size="26" /></span>
        <span>
          <strong>GLPI Bianchi</strong>
          <small>Inventário de TI</small>
        </span>
      </RouterLink>

      <nav aria-label="Principal">
        <div v-for="sec in sections" :key="sec.name ?? 'top'" class="nav-sec">
          <button
            v-if="sec.name"
            type="button"
            class="nav-group"
            :aria-expanded="isOpen(sec.name)"
            :aria-controls="`nav-${sec.name}`"
            @click="toggleGroup(sec.name)"
          >
            <span>{{ sec.name }}</span>
            <span v-if="!isOpen(sec.name) && groupBadge(sec.items).n > 0" class="nav-badge sm" :class="{ crit: groupBadge(sec.items).crit }">{{ groupBadge(sec.items).n > 99 ? '99+' : groupBadge(sec.items).n }}</span>
            <AppIcon name="chevron" :size="14" class="chev" />
          </button>
          <div v-show="!sec.name || isOpen(sec.name)" :id="sec.name ? `nav-${sec.name}` : undefined" class="nav-items">
            <RouterLink
              v-for="item in sec.items"
              :key="item.to"
              :to="item.to"
              class="nav-item"
              :class="{ active: isActive(item.to) }"
              :aria-current="isActive(item.to) ? 'page' : undefined"
              :title="item.label"
            >
              <AppIcon :name="item.icon" />
              <span class="nav-label">{{ item.label }}</span>
              <span
                v-if="item.badge === 'alerts' && alertCounts.open > 0"
                class="nav-badge" :class="{ crit: alertCounts.critical > 0 }"
                :title="`${alertCounts.open} alerta(s) aberto(s)${alertCounts.critical ? `, ${alertCounts.critical} crítico(s)` : ''}`"
              >{{ alertCounts.open > 99 ? '99+' : alertCounts.open }}</span>
              <span
                v-if="item.badge === 'stock' && stockLow > 0"
                class="nav-badge" :title="`${stockLow} item(ns) abaixo do estoque mínimo`"
              >{{ stockLow > 99 ? '99+' : stockLow }}</span>
            </RouterLink>
          </div>
        </div>
      </nav>

      <div class="sidebar-foot">
        <button class="btn" type="button" :title="themeLabel" @click="toggleTheme">
          <AppIcon :name="theme === 'dark' ? 'sun' : 'moon'" />
          <span>{{ theme === 'dark' ? 'Tema claro' : 'Tema escuro' }}</span>
        </button>
        <button class="btn" type="button" @click="logout">
          <AppIcon name="logout" />
          <span>Sair</span>
        </button>
      </div>
    </aside>

    <div class="content">
      <header class="topbar">
        <form role="search" class="search" @submit.prevent="submitSearch">
          <label for="global-search" class="sr-only">Buscar ativo</label>
          <AppIcon name="search" :size="16" />
          <input
            id="global-search"
            v-model="search"
            type="search"
            placeholder="Buscar por nome, serial, patrimônio, MAC ou IP…"
            autocomplete="off"
          />
        </form>
        <RouterLink v-if="me" to="/account" class="me" :title="`${me.display_name} — ${ROLE_LABEL[me.role]}`">
          <span class="avatar" aria-hidden="true">{{ initials }}</span>
          <span class="me-text"><strong>{{ me.display_name }}</strong><small>{{ ROLE_LABEL[me.role] }}<template v-if="!me.mfa"> · sem 2FA</template></small></span>
        </RouterLink>
      </header>
      <main id="main" tabindex="-1">
        <slot />
      </main>
    </div>
    <ToastHost />
  </div>
</template>

<style scoped>
.shell { display: flex; min-height: 100vh; }
.skip { position: absolute; left: -9999px; }
.skip:focus { left: 12px; top: 12px; z-index: 10; background: var(--panel); padding: 8px 12px; border-radius: 8px; }

.sidebar {
  width: var(--sidebar); flex: 0 0 var(--sidebar);
  background: var(--bg-elev); border-right: 1px solid var(--border);
  display: flex; flex-direction: column; gap: 4px; padding: 18px 12px 12px;
  position: sticky; top: 0; height: 100vh;
}
.brand { display: flex; align-items: center; gap: 10px; padding: 4px 10px 22px; color: var(--text); text-decoration: none; }
.brand-mark { color: var(--accent); display: grid; }
.brand strong { display: block; font-size: 15px; letter-spacing: -0.01em; }
.brand small { display: block; font-size: 11.5px; color: var(--text-3); }

/* a lista rola sozinha; marca no topo e rodapé ficam fixos */
nav { display: flex; flex-direction: column; gap: 2px; flex: 1; min-height: 0; overflow-y: auto; margin: 0 -6px; padding: 0 6px; scrollbar-width: thin; }
.nav-sec { display: flex; flex-direction: column; }
.nav-items { display: flex; flex-direction: column; gap: 2px; }
.nav-item {
  display: flex; align-items: center; gap: 10px; min-height: 38px; padding: 0 12px; flex: none;
  border-radius: var(--radius-sm); color: var(--text-2); text-decoration: none; font-weight: 500;
}
.nav-item:hover { background: var(--panel-hover); color: var(--text); text-decoration: none; }
.nav-label { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.nav-group {
  display: flex; align-items: center; gap: 8px; width: 100%; margin-top: 8px; padding: 8px 12px; border: 0; border-radius: var(--radius-sm);
  background: none; cursor: pointer; font: inherit; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text-3); text-align: left;
}
.nav-group:hover { color: var(--text-2); background: var(--panel-hover); }
.nav-group > span:first-child { flex: 1; }
.nav-group .chev { transition: transform .15s ease; }
.nav-group[aria-expanded="true"] .chev { transform: rotate(90deg); }
.nav-badge.sm { min-width: 18px; height: 18px; font-size: 10.5px; letter-spacing: 0; }
.nav-item.active { background: var(--accent-soft); color: var(--accent-text); }
.nav-badge { margin-left: auto; min-width: 20px; height: 20px; padding: 0 6px; border-radius: 10px; font-size: 11px; font-weight: 700; display: inline-grid; place-items: center; background: var(--warn-soft); color: var(--warn-text); }
.nav-badge.crit { background: var(--bad-soft); color: var(--bad-text); }

.sidebar-foot { display: flex; gap: 8px; padding-top: 10px; border-top: 1px solid var(--border); flex: none; }
.sidebar-foot .btn { flex: 1; justify-content: center; min-height: 36px; padding: 0 10px; font-size: 13px; white-space: nowrap; }

.content { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.topbar {
  position: sticky; top: 0; z-index: 5; display: flex; align-items: center; gap: 12px;
  padding: 14px 32px; background: color-mix(in srgb, var(--bg) 88%, transparent);
  backdrop-filter: blur(8px); border-bottom: 1px solid var(--border-soft);
}
.search {
  display: flex; align-items: center; gap: 8px; height: 40px; width: min(520px, 100%);
  padding: 0 12px; border: 1px solid var(--border); border-radius: var(--radius-sm);
  background: var(--panel); color: var(--text-3);
}
.me { margin-left: auto; display: flex; align-items: center; gap: 10px; padding: 4px 10px 4px 4px; border-radius: 999px; color: var(--text); text-decoration: none; border: 1px solid transparent; }
.me:hover { border-color: var(--border); background: var(--panel); text-decoration: none; }
.avatar { width: 32px; height: 32px; border-radius: 50%; display: grid; place-items: center; background: var(--accent-soft); color: var(--accent-text); font-size: 12.5px; font-weight: 600; }
.me-text { display: flex; flex-direction: column; line-height: 1.2; }
.me-text strong { font-size: 13px; font-weight: 600; }
.me-text small { font-size: 11.5px; color: var(--text-3); }
@media (max-width: 760px) { .me-text { display: none; } }
.search:focus-within { border-color: var(--accent); }
.search input { flex: 1; min-width: 0; border: 0; outline: 0; background: transparent; color: var(--text); }
main { padding: 24px 32px 48px; outline: none; }

@media (max-width: 900px) {
  .shell { flex-direction: column; }
  .sidebar { position: static; width: 100%; height: auto; flex: none; flex-direction: row; flex-wrap: wrap; align-items: center; padding: 10px 12px; }
  .brand { padding: 4px 8px; }
  .brand { flex: 1; }
  nav { order: 3; flex-basis: 100%; flex-direction: row; overflow-x: auto; overflow-y: visible; flex: none; }
  .nav-sec, .nav-items { flex-direction: row; display: flex !important; }
  .nav-group { display: none; }
  .sidebar-foot { border-top: 0; padding-top: 0; }
  .nav-item { white-space: nowrap; }
  .sidebar-foot { margin: 0; flex-direction: row; }
  .sidebar-foot .btn span { display: none; }
  .topbar, main { padding-left: 16px; padding-right: 16px; }
}
</style>
