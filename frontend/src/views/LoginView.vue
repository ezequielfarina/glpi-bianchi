<script setup lang="ts">
import { nextTick, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import { api, ApiError } from '../lib/api'
import { loadMe } from '../lib/session'

const router = useRouter()
const route = useRoute()
const step = ref<'credentials' | 'mfa'>('credentials')
const username = ref('')
const password = ref('')
const code = ref('')
const showPassword = ref(false)
const error = ref<string | null>(route.query.expired ? 'Sua sessão expirou. Entre novamente.' : null)
const busy = ref(false)
const codeInput = ref<HTMLInputElement | null>(null)

async function finish(mustChange: boolean): Promise<void> {
  password.value = ''
  await loadMe(true)
  if (mustChange) { await router.replace({ name: 'account' }); return }
  const redirect = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') ? route.query.redirect : '/'
  await router.replace(redirect)
}

async function submitCredentials(): Promise<void> {
  error.value = null
  busy.value = true
  try {
    const r = await api.login(username.value.trim(), password.value)
    if (r.mfa_required) {
      step.value = 'mfa'
      password.value = ''
      await nextTick()
      codeInput.value?.focus()
      return
    }
    await finish(!!r.must_change_password)
  } catch (e) {
    error.value = e instanceof ApiError && [401, 429].includes(e.status) ? e.message : 'Não foi possível contatar o servidor.'
    password.value = ''
  } finally {
    busy.value = false
  }
}

async function submitCode(): Promise<void> {
  error.value = null
  busy.value = true
  try {
    const r = await api.mfa(code.value.replace(/\s/g, ''))
    await finish(r.must_change_password)
  } catch (e) {
    code.value = ''
    if (e instanceof ApiError && e.code === 'mfa_expired') {
      step.value = 'credentials'
      error.value = e.message
    } else {
      error.value = e instanceof ApiError ? e.message : 'Não foi possível contatar o servidor.'
    }
  } finally {
    busy.value = false
  }
}

function onCodeInput(): void {
  code.value = code.value.replace(/\D/g, '').slice(0, 6)
  if (code.value.length === 6 && !busy.value) void submitCode()
}
</script>

<template>
  <main class="login">
    <div class="card panel">
      <div class="head">
        <span class="mark"><AppIcon name="logo" :size="32" /></span>
        <div>
          <h1>GLPI Bianchi</h1>
          <p class="muted">Inventário de ativos de TI</p>
        </div>
      </div>

      <form v-if="step === 'credentials'" class="form" novalidate @submit.prevent="submitCredentials">
        <label for="username">Usuário</label>
        <input
          id="username" v-model="username" class="input" type="text" name="username" autocomplete="username"
          autocapitalize="none" spellcheck="false" required autofocus :aria-invalid="!!error"
        />
        <label for="password">Senha</label>
        <div class="pw">
          <input
            id="password" v-model="password" class="input" :type="showPassword ? 'text' : 'password'" name="password"
            autocomplete="current-password" required :aria-invalid="!!error"
          />
          <button class="btn btn-icon" type="button" :aria-label="showPassword ? 'Ocultar senha' : 'Mostrar senha'" :aria-pressed="showPassword" @click="showPassword = !showPassword">
            <AppIcon :name="showPassword ? 'eyeOff' : 'eye'" :size="16" />
          </button>
        </div>
        <p v-if="error" class="error-box" role="alert">{{ error }}</p>
        <button class="btn btn-primary" type="submit" :disabled="busy || !username.trim() || !password">
          {{ busy ? 'Verificando…' : 'Entrar' }}
        </button>
        <p class="muted small">Esqueceu a senha? Peça a um administrador para gerar uma senha temporária.</p>
      </form>

      <form v-else class="form" novalidate @submit.prevent="submitCode">
        <div class="mfa-head">
          <AppIcon name="lock" :size="20" />
          <div>
            <strong>Verificação em duas etapas</strong>
            <p class="muted">Digite o código de 6 dígitos do seu aplicativo autenticador.</p>
          </div>
        </div>
        <label for="code" class="sr-only">Código</label>
        <input
          id="code" ref="codeInput" v-model="code" class="input code mono" type="text" inputmode="numeric" autocomplete="one-time-code"
          maxlength="6" placeholder="000000" required :aria-invalid="!!error" @input="onCodeInput"
        />
        <p v-if="error" class="error-box" role="alert">{{ error }}</p>
        <button class="btn btn-primary" type="submit" :disabled="busy || code.length !== 6">{{ busy ? 'Verificando…' : 'Confirmar' }}</button>
        <button class="btn" type="button" @click="step = 'credentials'; error = null; code = ''">Voltar</button>
        <p class="muted small">Perdeu o celular? Um administrador pode desligar o 2FA da sua conta.</p>
      </form>
    </div>
  </main>
</template>

<style scoped>
.login { min-height: 100vh; display: grid; place-items: center; padding: 24px; }
.card { width: min(400px, 100%); padding: 28px; display: flex; flex-direction: column; gap: 16px; }
.head { display: flex; gap: 12px; align-items: center; }
.head p { margin: 2px 0 0; }
.mark { color: var(--accent); display: grid; }
.form { display: flex; flex-direction: column; gap: 10px; }
label { font-weight: 500; font-size: 13px; }
.pw { display: flex; gap: 6px; }
.pw .input { flex: 1; min-width: 0; }
.mfa-head { display: flex; gap: 10px; align-items: flex-start; color: var(--accent-text); }
.mfa-head strong { color: var(--text); }
.mfa-head p { margin: 2px 0 0; font-size: 13px; }
.code { font-size: 26px; letter-spacing: 0.4em; text-align: center; padding: 12px; }
.small { font-size: 12px; margin: 2px 0 0; text-align: center; }
.btn-primary { min-height: 44px; }
.btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
.error-box { margin: 0; }
</style>
