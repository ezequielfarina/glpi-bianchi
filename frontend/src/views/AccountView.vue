<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import AppIcon from '../components/AppIcon.vue'
import { api, ApiError } from '../lib/api'
import { dateTime, relative } from '../lib/format'
import { loadMe, me, ROLE_HINT, ROLE_LABEL } from '../lib/session'
import { notify } from '../lib/toast'
import { describeAgent } from '../lib/userAgent'
import type { SessionRow } from '../lib/types'

// ---------------------------------------------------------------- senha
const pw = reactive({ current: '', next: '', confirm: '' })
const pwErrors = ref<Record<string, string>>({})
const pwBusy = ref(false)
const forced = computed(() => !!me.value?.must_change_password)
const checks = computed(() => [
  { ok: pw.next.length >= 12, label: 'pelo menos 12 caracteres (frase-senha é ótima)' },
  { ok: pw.next.length > 0 && new Set(pw.next).size >= 5, label: 'variedade de caracteres' },
  { ok: !!pw.next && pw.next === pw.confirm, label: 'confirmação igual' },
])
async function changePassword(): Promise<void> {
  pwErrors.value = {}
  if (pw.next !== pw.confirm) { pwErrors.value = { confirm: 'As senhas não conferem.' }; return }
  pwBusy.value = true
  try {
    await api.changePassword(pw.current, pw.next)
    Object.assign(pw, { current: '', next: '', confirm: '' })
    notify('ok', 'Senha alterada', 'As outras sessões abertas com a senha antiga foram encerradas.')
    await loadMe(true)
    await loadSessions()
  } catch (e) {
    if (e instanceof ApiError) pwErrors.value = e.fields
    notify('warn', 'Senha não alterada', e instanceof Error ? e.message : String(e))
  } finally { pwBusy.value = false }
}

// ---------------------------------------------------------------- 2FA
const setup = ref<{ secret: string; uri: string; qr: string } | null>(null)
const mfaCode = ref('')
const mfaBusy = ref(false)
const disabling = ref(false)
const disableForm = reactive({ password: '', code: '' })
const mfaErrors = ref<Record<string, string>>({})
async function startMfa(): Promise<void> {
  mfaBusy.value = true
  try {
    const r = await api.mfaSetup()
    const QRCode = (await import('qrcode')).default
    const qr = await QRCode.toDataURL(r.uri, { margin: 1, width: 220, errorCorrectionLevel: 'M' })
    setup.value = { ...r, qr }
  } catch (e) {
    notify('warn', 'Não foi possível iniciar', e instanceof Error ? e.message : String(e))
  } finally { mfaBusy.value = false }
}
async function confirmMfa(): Promise<void> {
  mfaErrors.value = {}
  mfaBusy.value = true
  try {
    await api.mfaEnable(mfaCode.value.replace(/\s/g, ''))
    setup.value = null
    mfaCode.value = ''
    notify('ok', 'Verificação em duas etapas ativada', 'No próximo login o código do aplicativo será pedido.')
    await loadMe(true)
  } catch (e) {
    if (e instanceof ApiError) mfaErrors.value = e.fields
  } finally { mfaBusy.value = false }
}
async function disableMfa(): Promise<void> {
  mfaErrors.value = {}
  mfaBusy.value = true
  try {
    await api.mfaDisable(disableForm.password, disableForm.code)
    Object.assign(disableForm, { password: '', code: '' })
    disabling.value = false
    notify('ok', 'Verificação em duas etapas desativada')
    await loadMe(true)
  } catch (e) {
    if (e instanceof ApiError) mfaErrors.value = e.fields
  } finally { mfaBusy.value = false }
}
async function copySecret(): Promise<void> {
  if (!setup.value) return
  try { await navigator.clipboard.writeText(setup.value.secret); notify('ok', 'Chave copiada') } catch { /* sem clipboard */ }
}

// ---------------------------------------------------------------- sessões
const sessions = ref<SessionRow[]>([])
async function loadSessions(): Promise<void> {
  if (forced.value) return
  try { sessions.value = (await api.mySessions()).items } catch { /* silencioso */ }
}
async function revoke(s: SessionRow): Promise<void> {
  await api.revokeMySession(s.id)
  notify('ok', 'Sessão encerrada')
  await loadSessions()
}
void loadSessions()
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>Minha conta</h1>
        <p v-if="me" class="muted">
          {{ me.display_name }} · <span class="mono">{{ me.username }}</span> · {{ ROLE_LABEL[me.role] }}
          <template v-if="me.previous_login"> · acesso anterior {{ relative(me.previous_login.created_at) }} de <span class="mono">{{ me.previous_login.ip }}</span></template>
        </p>
      </div>
    </header>

    <div v-if="forced" class="banner" role="alert">
      <AppIcon name="lock" :size="18" />
      <div><strong>Defina sua senha pessoal para continuar.</strong> Você entrou com uma senha temporária criada por um administrador.</div>
    </div>

    <div class="grid">
      <section class="panel">
        <div class="panel-head"><h2>Senha</h2></div>
        <form class="body form" novalidate @submit.prevent="changePassword">
          <input type="text" name="username" autocomplete="username" :value="me?.username" hidden readonly />
          <div class="field">
            <label for="pw-cur">{{ forced ? 'Senha temporária' : 'Senha atual' }}</label>
            <input id="pw-cur" v-model="pw.current" class="input" type="password" autocomplete="current-password" :aria-invalid="!!pwErrors.current_password" />
            <span v-if="pwErrors.current_password" class="err">{{ pwErrors.current_password }}</span>
          </div>
          <div class="field">
            <label for="pw-new">Nova senha</label>
            <input id="pw-new" v-model="pw.next" class="input" type="password" autocomplete="new-password" maxlength="128" :aria-invalid="!!pwErrors.new_password" aria-describedby="pw-rules" />
            <span v-if="pwErrors.new_password" class="err">{{ pwErrors.new_password }}</span>
          </div>
          <div class="field">
            <label for="pw-conf">Repita a nova senha</label>
            <input id="pw-conf" v-model="pw.confirm" class="input" type="password" autocomplete="new-password" maxlength="128" :aria-invalid="!!pwErrors.confirm" />
            <span v-if="pwErrors.confirm" class="err">{{ pwErrors.confirm }}</span>
          </div>
          <ul id="pw-rules" class="rules">
            <li v-for="c in checks" :key="c.label" :class="{ ok: c.ok }"><AppIcon :name="c.ok ? 'check' : 'close'" :size="12" /> {{ c.label }}</li>
          </ul>
          <p class="hint">Não use a mesma senha de outros sistemas. Um gerenciador de senhas (KeePass, Bitwarden) facilita.</p>
          <button class="btn btn-primary" type="submit" :disabled="pwBusy || !pw.current || !checks.every((c) => c.ok)">{{ pwBusy ? 'Salvando…' : 'Alterar senha' }}</button>
        </form>
      </section>

      <section v-if="!forced" class="panel">
        <div class="panel-head">
          <h2>Verificação em duas etapas</h2>
          <span class="pill" :class="me?.mfa ? 'pill-ok' : 'pill-warn'">{{ me?.mfa ? 'ativa' : 'desativada' }}</span>
        </div>
        <div class="body">
          <template v-if="!me?.mfa && !setup">
            <p>Além da senha, o login pede um código de 6 dígitos gerado no celular. Mesmo que a senha vaze, ninguém entra sem o aparelho.</p>
            <p class="hint">Funciona com Microsoft Authenticator, Google Authenticator, FortiToken Mobile, Authy ou 1Password.</p>
            <button class="btn btn-primary" type="button" :disabled="mfaBusy" @click="startMfa"><AppIcon name="lock" :size="16" /> Ativar</button>
          </template>

          <form v-else-if="setup" class="setup" novalidate @submit.prevent="confirmMfa">
            <ol>
              <li>No aplicativo autenticador, adicione uma conta lendo o QR code:</li>
            </ol>
            <img :src="setup.qr" width="220" height="220" alt="QR code para configurar o aplicativo autenticador" class="qr" />
            <p class="hint">Sem câmera? Digite a chave: <code class="mono secret">{{ setup.secret.match(/.{1,4}/g)?.join(' ') }}</code> <button class="link-btn" type="button" @click="copySecret">copiar</button></p>
            <ol start="2"><li>Digite o código que aparece no aplicativo:</li></ol>
            <input v-model="mfaCode" class="input code mono" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000" aria-label="Código do aplicativo" :aria-invalid="!!mfaErrors.code" />
            <span v-if="mfaErrors.code" class="err">{{ mfaErrors.code }}</span>
            <div class="row">
              <button class="btn" type="button" @click="setup = null">Cancelar</button>
              <button class="btn btn-primary" type="submit" :disabled="mfaBusy || mfaCode.length !== 6">Confirmar e ativar</button>
            </div>
          </form>

          <template v-else>
            <p>Seu login está protegido pelo código do aplicativo autenticador.</p>
            <button v-if="!disabling" class="btn" type="button" @click="disabling = true">Desativar…</button>
            <form v-else class="form" novalidate @submit.prevent="disableMfa">
              <p class="hint">Para desativar, confirme sua senha e um código atual.</p>
              <input v-model="disableForm.password" class="input" type="password" autocomplete="current-password" placeholder="Senha" aria-label="Senha" :aria-invalid="!!mfaErrors.password" />
              <span v-if="mfaErrors.password" class="err">{{ mfaErrors.password }}</span>
              <input v-model="disableForm.code" class="input mono" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="Código de 6 dígitos" aria-label="Código" :aria-invalid="!!mfaErrors.code" />
              <span v-if="mfaErrors.code" class="err">{{ mfaErrors.code }}</span>
              <div class="row">
                <button class="btn" type="button" @click="disabling = false">Cancelar</button>
                <button class="btn btn-danger" type="submit" :disabled="mfaBusy">Desativar 2FA</button>
              </div>
            </form>
          </template>
        </div>
      </section>
    </div>

    <section v-if="!forced" class="panel">
      <div class="panel-head"><h2>Sessões ativas</h2><span class="muted small">Encerre o que não reconhecer e troque a senha.</span></div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Dispositivo</th><th>IP</th><th>Início</th><th>Última atividade</th><th /></tr></thead>
          <tbody>
            <tr v-for="s in sessions" :key="s.id">
              <td>{{ describeAgent(s.user_agent) }} <span v-if="s.current" class="pill pill-ok">esta sessão</span></td>
              <td class="mono">{{ s.ip ?? '—' }}</td>
              <td class="muted">{{ dateTime(s.created_at) }}</td>
              <td>{{ relative(s.last_seen_at) }}</td>
              <td class="num"><button v-if="!s.current" class="btn" type="button" @click="revoke(s)">Encerrar</button></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <p v-if="me" class="muted small">Perfil <strong>{{ ROLE_LABEL[me.role] }}</strong>: {{ ROLE_HINT[me.role] }}<template v-if="me.password_changed_at"> · Senha alterada {{ relative(me.password_changed_at) }}.</template></p>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 20px; max-width: 1200px; }
.page-head p { margin: 4px 0 0; font-size: 13px; }
.banner { display: flex; gap: 12px; align-items: flex-start; padding: 14px 16px; border-radius: var(--radius-sm); border: 1px solid var(--warn); background: var(--warn-soft); color: var(--text); }
.banner :deep(svg) { color: var(--warn-text); flex: none; margin-top: 2px; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 16px; align-items: start; }
.body { padding: 0 20px 20px; display: flex; flex-direction: column; gap: 12px; }
.body p { margin: 0; }
.form { display: flex; flex-direction: column; gap: 10px; }
.field { display: flex; flex-direction: column; gap: 6px; }
.field label { font-size: 12.5px; color: var(--text-2); }
.rules { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; font-size: 12.5px; color: var(--text-3); }
.rules li { display: flex; gap: 6px; align-items: center; }
.rules li.ok { color: var(--ok); }
.setup { display: flex; flex-direction: column; gap: 10px; }
.setup ol { margin: 0; padding-left: 18px; }
.qr { background: #fff; padding: 8px; border-radius: 8px; width: 220px; height: 220px; }
.secret { font-size: 12.5px; word-break: break-all; }
.code { font-size: 22px; letter-spacing: .35em; text-align: center; max-width: 220px; }
.row { display: flex; gap: 8px; }
.small { font-size: 12.5px; }
.btn-primary, .btn-danger { align-self: flex-start; }
</style>
