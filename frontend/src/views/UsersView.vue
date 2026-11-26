<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import AppIcon from '../components/AppIcon.vue'
import AppModal from '../components/AppModal.vue'
import LoadState from '../components/LoadState.vue'
import { api, ApiError } from '../lib/api'
import { dateTime, relative } from '../lib/format'
import { me, ROLE_HINT, ROLE_LABEL } from '../lib/session'
import { notify } from '../lib/toast'
import type { AccountRow, Role } from '../lib/types'
import { useAsync } from '../lib/useAsync'

const { data, loading, error, reload } = useAsync(() => api.users())
const items = computed(() => data.value?.items ?? [])
const q = ref('')
const filtered = computed(() => {
  const t = q.value.trim().toLowerCase()
  return t ? items.value.filter((u) => `${u.username} ${u.display_name} ${u.email ?? ''}`.toLowerCase().includes(t)) : items.value
})
const stats = computed(() => ({
  total: items.value.length,
  admins: items.value.filter((u) => u.role === 'admin' && u.active).length,
  noMfa: items.value.filter((u) => u.active && !u.mfa).length,
  locked: items.value.filter((u) => u.locked).length,
}))
const ROLES: Role[] = ['admin', 'editor', 'viewer']

// ---------------------------------------------------------------- criar / editar
const editing = ref<AccountRow | 'new' | null>(null)
const form = reactive({ username: '', display_name: '', email: '', role: 'viewer' as Role, active: true })
const errors = ref<Record<string, string>>({})
const busy = ref(false)
function openNew(): void {
  Object.assign(form, { username: '', display_name: '', email: '', role: 'viewer', active: true })
  errors.value = {}
  editing.value = 'new'
}
function openEdit(u: AccountRow): void {
  Object.assign(form, { username: u.username, display_name: u.display_name, email: u.email ?? '', role: u.role, active: u.active })
  errors.value = {}
  editing.value = u
}
const isSelf = computed(() => editing.value !== null && editing.value !== 'new' && editing.value.id === me.value?.id)
async function save(): Promise<void> {
  busy.value = true
  errors.value = {}
  try {
    if (editing.value === 'new') {
      const r = await api.createUser({ username: form.username, display_name: form.display_name, email: form.email || null, role: form.role })
      editing.value = null
      if (r.temporary_password) secret.value = { user: form.username, password: r.temporary_password, created: true }
    } else if (editing.value) {
      await api.updateUser(editing.value.id, { display_name: form.display_name, email: form.email || null, role: form.role, active: form.active })
      notify('ok', 'Usuário atualizado', form.role !== editing.value.role ? 'O novo perfil vale a partir do próximo login (sessões encerradas).' : undefined)
      editing.value = null
    }
    await reload()
  } catch (e) {
    if (e instanceof ApiError) errors.value = e.fields
    notify('warn', 'Não foi possível salvar', e instanceof Error ? e.message : String(e))
  } finally { busy.value = false }
}

// ---------------------------------------------------------------- ações sensíveis
const secret = ref<{ user: string; password: string; created: boolean } | null>(null)
async function action(u: AccountRow, kind: 'reset' | 'mfa' | 'unlock' | 'sessions' | 'delete'): Promise<void> {
  const confirmText: Record<typeof kind, string> = {
    reset: `Gerar uma senha temporária para ${u.display_name}?\n\nA senha atual deixa de valer, as sessões abertas são encerradas e a troca será obrigatória no próximo login.`,
    mfa: `Desligar a verificação em duas etapas de ${u.display_name}?\n\nUse quando a pessoa perdeu o celular. Ela poderá reativar em "Minha conta".`,
    unlock: `Liberar o bloqueio por tentativas de ${u.display_name}?`,
    sessions: `Encerrar todas as sessões de ${u.display_name}? A pessoa precisará entrar de novo.`,
    delete: `Excluir a conta "${u.username}"?\n\nO histórico de alterações continua mostrando o login. Para só impedir o acesso, prefira desativar.`,
  }
  if (!window.confirm(confirmText[kind])) return
  try {
    if (kind === 'reset') {
      const r = await api.resetUserPassword(u.id)
      secret.value = { user: u.username, password: r.temporary_password, created: false }
    } else if (kind === 'mfa') { await api.resetUserMfa(u.id); notify('ok', '2FA desligado', u.display_name) }
    else if (kind === 'unlock') { await api.unlockUser(u.id); notify('ok', 'Bloqueio liberado', u.display_name) }
    else if (kind === 'sessions') { const r = await api.revokeUserSessions(u.id); notify('ok', `${r.revoked} sessão(ões) encerrada(s)`) }
    else { await api.deleteUser(u.id); notify('ok', 'Conta excluída'); editing.value = null }
    await reload()
  } catch (e) {
    notify('warn', 'Ação não realizada', e instanceof Error ? e.message : String(e))
  }
}
async function copySecret(): Promise<void> {
  if (!secret.value) return
  try { await navigator.clipboard.writeText(secret.value.password); notify('ok', 'Senha copiada') } catch { /* sem clipboard */ }
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>Usuários</h1>
        <p class="muted">Contas de acesso ao painel · {{ stats.total }} conta(s) · {{ stats.admins }} administrador(es) ativo(s)</p>
      </div>
      <button class="btn btn-primary" type="button" @click="openNew"><AppIcon name="plus" :size="16" /> Novo usuário</button>
    </header>

    <div v-if="stats.noMfa || stats.locked" class="hints">
      <span v-if="stats.noMfa" class="pill pill-warn"><AppIcon name="alert" :size="12" /> {{ stats.noMfa }} conta(s) ativa(s) sem 2FA</span>
      <span v-if="stats.locked" class="pill pill-warn"><AppIcon name="lock" :size="12" /> {{ stats.locked }} bloqueada(s) por tentativas</span>
    </div>

    <section class="panel">
      <div class="filters">
        <label class="sr-only" for="u-q">Buscar</label>
        <input id="u-q" v-model="q" class="input" type="search" placeholder="Nome, login ou e-mail" />
      </div>
      <LoadState :loading="loading" :error="error" :rows="5" @retry="reload">
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Usuário</th><th>Perfil</th><th>Situação</th><th>2FA</th><th>Último acesso</th><th class="num">Sessões</th><th /></tr></thead>
            <tbody>
              <tr v-for="u in filtered" :key="u.id" :class="{ inactive: !u.active }">
                <td>
                  <button class="link strong" type="button" @click="openEdit(u)">{{ u.display_name }}</button>
                  <span v-if="u.id === me?.id" class="pill pill-accent" style="margin-left: 6px">você</span>
                  <div class="muted small"><span class="mono">{{ u.username }}</span><template v-if="u.email"> · {{ u.email }}</template></div>
                </td>
                <td><span class="pill" :class="{ 'pill-warn': u.role === 'admin', 'pill-accent': u.role === 'editor', 'pill-muted': u.role === 'viewer' }">{{ ROLE_LABEL[u.role] }}</span></td>
                <td>
                  <span v-if="!u.active" class="pill pill-muted">desativado</span>
                  <span v-else-if="u.locked" class="pill pill-warn">bloqueado</span>
                  <span v-else-if="u.must_change_password" class="pill pill-accent">senha temporária</span>
                  <span v-else class="pill pill-ok">ativo</span>
                </td>
                <td><AppIcon :name="u.mfa ? 'check' : 'close'" :size="14" :class="u.mfa ? 'ok-text' : 'muted'" /><span class="sr-only">{{ u.mfa ? 'ativo' : 'inativo' }}</span></td>
                <td>
                  <template v-if="u.last_login_at">{{ relative(u.last_login_at) }} <div class="muted small mono">{{ u.last_login_ip }}</div></template>
                  <span v-else class="muted">nunca</span>
                </td>
                <td class="num">{{ u.sessions }}</td>
                <td class="num"><button class="btn btn-icon" type="button" :aria-label="`Gerenciar ${u.display_name}`" @click="openEdit(u)"><AppIcon name="edit" :size="14" /></button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </LoadState>
    </section>

    <section class="panel roles">
      <div class="panel-head"><h2>Perfis</h2></div>
      <dl>
        <template v-for="r in ROLES" :key="r"><dt>{{ ROLE_LABEL[r] }}</dt><dd>{{ ROLE_HINT[r] }}</dd></template>
      </dl>
    </section>

    <!-- criar / editar -->
    <AppModal v-if="editing" :title="editing === 'new' ? 'Novo usuário' : `Usuário: ${editing.display_name}`" @close="editing = null">
      <form class="form" novalidate @submit.prevent="save">
        <div class="field">
          <label for="f-user">Login *</label>
          <input id="f-user" v-model="form.username" class="input mono" :disabled="editing !== 'new'" autocapitalize="none" spellcheck="false" placeholder="maria.souza" :aria-invalid="!!errors.username" />
          <span v-if="errors.username" class="err">{{ errors.username }}</span>
          <span v-else-if="editing === 'new'" class="hint">Minúsculas, números, ponto ou hífen. Use o mesmo login do AD para facilitar a migração futura.</span>
        </div>
        <div class="field">
          <label for="f-name">Nome *</label>
          <input id="f-name" v-model="form.display_name" class="input" :aria-invalid="!!errors.display_name" />
          <span v-if="errors.display_name" class="err">{{ errors.display_name }}</span>
        </div>
        <div class="field">
          <label for="f-mail">E-mail</label>
          <input id="f-mail" v-model="form.email" class="input" type="email" :aria-invalid="!!errors.email" />
          <span v-if="errors.email" class="err">{{ errors.email }}</span>
        </div>
        <fieldset class="field roles-pick" :disabled="isSelf">
          <legend>Perfil *</legend>
          <label v-for="r in ROLES" :key="r" class="role" :class="{ on: form.role === r }">
            <input v-model="form.role" type="radio" :value="r" />
            <span><strong>{{ ROLE_LABEL[r] }}</strong><small>{{ ROLE_HINT[r] }}</small></span>
          </label>
          <span v-if="errors.role" class="err">{{ errors.role }}</span>
          <span v-if="isSelf" class="hint">Você não pode alterar o seu próprio perfil.</span>
        </fieldset>
        <label v-if="editing !== 'new'" class="check"><input v-model="form.active" type="checkbox" :disabled="isSelf" /> Conta ativa (desmarcar encerra as sessões na hora)</label>
        <span v-if="errors.active" class="err">{{ errors.active }}</span>
        <p v-if="editing === 'new'" class="hint">Uma <strong>senha temporária</strong> será gerada e exibida uma única vez. No primeiro login a pessoa define a própria senha.</p>

        <div v-if="editing !== 'new'" class="danger-zone">
          <h3>Acesso</h3>
          <div class="actions-grid">
            <button class="btn" type="button" @click="action(editing, 'reset')"><AppIcon name="key" :size="14" /> Gerar senha temporária</button>
            <button class="btn" type="button" :disabled="!editing.mfa" @click="action(editing, 'mfa')"><AppIcon name="lock" :size="14" /> Desligar 2FA</button>
            <button class="btn" type="button" :disabled="!editing.locked" @click="action(editing, 'unlock')"><AppIcon name="check" :size="14" /> Desbloquear</button>
            <button class="btn" type="button" :disabled="!editing.sessions || isSelf" @click="action(editing, 'sessions')"><AppIcon name="logout" :size="14" /> Encerrar sessões ({{ editing.sessions }})</button>
            <button class="btn btn-danger" type="button" :disabled="isSelf" @click="action(editing, 'delete')"><AppIcon name="trash" :size="14" /> Excluir conta</button>
          </div>
          <p class="muted small">Criada em {{ dateTime(editing.created_at) }}<template v-if="editing.password_changed_at"> · senha alterada {{ relative(editing.password_changed_at) }}</template></p>
        </div>

        <div class="form-actions">
          <button class="btn" type="button" @click="editing = null">Cancelar</button>
          <button class="btn btn-primary" type="submit" :disabled="busy || !form.display_name.trim() || (editing === 'new' && !form.username.trim())">{{ busy ? 'Salvando…' : 'Salvar' }}</button>
        </div>
      </form>
    </AppModal>

    <!-- senha temporária: exibida uma única vez -->
    <AppModal v-if="secret" :title="secret.created ? 'Usuário criado' : 'Senha temporária gerada'" @close="secret = null">
      <div class="secret">
        <p>Entregue ao usuário <strong class="mono">{{ secret.user }}</strong> por um canal seguro (pessoalmente ou mensagem direta). Ela <strong>não será exibida novamente</strong>.</p>
        <div class="pw-box"><code class="mono">{{ secret.password }}</code><button class="btn" type="button" @click="copySecret"><AppIcon name="copy" :size="14" /> Copiar</button></div>
        <p class="hint">No primeiro login será obrigatório definir uma senha pessoal. Recomende ativar a verificação em duas etapas em "Minha conta".</p>
        <div class="form-actions"><button class="btn btn-primary" type="button" @click="secret = null">Entendi</button></div>
      </div>
    </AppModal>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 20px; max-width: 1480px; }
.page-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
.page-head p { margin: 4px 0 0; font-size: 13px; }
.hints { display: flex; gap: 8px; flex-wrap: wrap; }
.hints .pill { display: inline-flex; align-items: center; gap: 5px; }
.filters { display: flex; padding: 16px 20px; }
.filters .input { flex: 1; max-width: 420px; }
.link { background: none; border: 0; padding: 0; font: inherit; color: var(--text); cursor: pointer; text-align: left; }
.link:hover { color: var(--accent-text); text-decoration: underline; }
.strong { font-weight: 600; }
.small { font-size: 12px; }
tr.inactive td { opacity: .55; }
.ok-text { color: var(--ok); }
.roles dl { display: grid; grid-template-columns: max-content 1fr; gap: 8px 20px; margin: 0; padding: 0 20px 20px; font-size: 13px; }
.roles dt { font-weight: 600; }
.roles dd { margin: 0; color: var(--text-2); }
.form { display: flex; flex-direction: column; gap: 14px; }
.field { display: flex; flex-direction: column; gap: 6px; }
.field > label, legend { font-size: 12.5px; color: var(--text-2); }
.roles-pick { border: 0; padding: 0; margin: 0; }
.role { display: flex; gap: 10px; align-items: flex-start; padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); cursor: pointer; }
.role + .role { margin-top: 6px; }
.role.on { border-color: var(--accent); background: var(--accent-soft); }
.role input { margin-top: 3px; accent-color: var(--accent); }
.role span { display: flex; flex-direction: column; gap: 2px; }
.role small { color: var(--text-3); font-size: 12px; }
.roles-pick:disabled .role { cursor: not-allowed; opacity: .7; }
.check { display: inline-flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-2); }
.check input { width: 16px; height: 16px; accent-color: var(--accent); }
.danger-zone { border-top: 1px solid var(--border-soft); padding-top: 12px; display: flex; flex-direction: column; gap: 10px; }
.danger-zone h3 { margin: 0; font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-2); }
.actions-grid { display: flex; flex-wrap: wrap; gap: 8px; }
.form-actions { display: flex; justify-content: flex-end; gap: 8px; }
.secret { display: flex; flex-direction: column; gap: 12px; }
.secret p { margin: 0; }
.pw-box { display: flex; gap: 8px; align-items: center; padding: 12px; border: 1px dashed var(--accent); border-radius: var(--radius-sm); background: var(--accent-soft); }
.pw-box code { flex: 1; font-size: 18px; letter-spacing: .04em; user-select: all; }
</style>
