<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon, { type IconName } from './AppIcon.vue'
import ToastHost from './ToastHost.vue'
import { api } from '../lib/api'
import { canEdit, clearMe, isAdmin, me, ROLE_LABEL } from '../lib/session'
import { theme, toggleTheme } from '../lib/theme'

const route = useRoute()
const router = useRouter()
const search = ref('')

const allNav: { to: string; label: string; icon: IconName; group?: string; admin?: boolean; edit?: boolean }[] = [
  { to: '/', label: 'Visão geral', icon: 'home' },
  { to: '/assets', label: 'Ativos', icon: 'assets' },
  { to: '/branches', label: 'Matriz e filiais', icon: 'building' },
  { to: '/licenses', label: 'Licenças', icon: 'key', group: 'Gestão' },
  { to: '/contracts', label: 'Contratos', icon: 'contract' },
  { to: '/suppliers', label: 'Fornecedores', icon: 'truck' },
  { to: '/agents', label: 'Agentes', icon: 'agents', group: 'Inventário automático' },
  { to: '/inventories', label: 'Inventários recebidos', icon: 'inbox' },
  { to: '/tools/scanner', label: 'Scanner de rede', icon: 'radar', group: 'Ferramentas', edit: true },
  { to: '/tools/subnet', label: 'Calculadora de sub-rede', icon: 'calculator', group: 'Ferramentas' },
  { to: '/settings', label: 'Configurações', icon: 'settings', group: 'Sistema' },
  { to: '/users', label: 'Usuários', icon: 'user', admin: true },
  { to: '/audit', label: 'Auditoria de acesso', icon: 'history', admin: true },
]
const nav = computed(() => {
  const items = allNav.filter((i) => (!i.admin || isAdmin.value) && (!i.edit || canEdit.value))
  // o rótulo do grupo vai no primeiro item visível dele (ex.: Ferramentas para o perfil Leitura)
  let group: string | undefined
  return items.map((i) => {
    const g = i.group ?? group
    const out = { ...i, group: g !== group ? g : undefined }
    group = g
    return out
  })
})
const initials = computed(() => (me.value?.display_name ?? '?').split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]!.toUpperCase()).join(''))

const isActive = (to: string) => (to === '/' ? route.path === '/' : route.path.startsWith(to))
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
        <template v-for="item in nav" :key="item.to">
          <span v-if="item.group" class="nav-group">{{ item.group }}</span>
          <RouterLink
            :to="item.to"
            class="nav-item"
            :class="{ active: isActive(item.to) }"
            :aria-current="isActive(item.to) ? 'page' : undefined"
          >
            <AppIcon :name="item.icon" />
            <span>{{ item.label }}</span>
          </RouterLink>
        </template>
      </nav>

      <div class="sidebar-foot">
        <button class="btn" type="button" @click="toggleTheme">
          <AppIcon :name="theme === 'dark' ? 'sun' : 'moon'" />
          <span>{{ themeLabel }}</span>
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
  display: flex; flex-direction: column; gap: 4px; padding: 18px 12px;
  position: sticky; top: 0; height: 100vh;
}
.brand { display: flex; align-items: center; gap: 10px; padding: 4px 10px 22px; color: var(--text); text-decoration: none; }
.brand-mark { color: var(--accent); display: grid; }
.brand strong { display: block; font-size: 15px; letter-spacing: -0.01em; }
.brand small { display: block; font-size: 11.5px; color: var(--text-3); }

nav { display: flex; flex-direction: column; gap: 2px; }
.nav-item {
  display: flex; align-items: center; gap: 10px; min-height: 40px; padding: 0 12px;
  border-radius: var(--radius-sm); color: var(--text-2); text-decoration: none; font-weight: 500;
}
.nav-item:hover { background: var(--panel-hover); color: var(--text); text-decoration: none; }
.nav-group { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text-3); padding: 14px 12px 4px; }
.nav-item.active { background: var(--accent-soft); color: var(--accent-text); }

.sidebar-foot { margin-top: auto; display: flex; flex-direction: column; gap: 8px; }
.sidebar-foot .btn { justify-content: flex-start; }

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
  nav { order: 3; flex-basis: 100%; flex-direction: row; overflow-x: auto; }
  .nav-group { display: none; }
  .nav-item { white-space: nowrap; }
  .sidebar-foot { margin: 0; flex-direction: row; }
  .sidebar-foot .btn span { display: none; }
  .topbar, main { padding-left: 16px; padding-right: 16px; }
}
</style>
