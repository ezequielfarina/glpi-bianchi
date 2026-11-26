<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import { api, auth, ApiError } from '../lib/api'

const router = useRouter()
const route = useRoute()
const token = ref('')
const error = ref<string | null>(null)
const busy = ref(false)

/** Aceita o valor puro ou a linha copiada do .env.local ("API_TOKEN=..."), com ou sem aspas. */
function normalize(raw: string): string {
  return raw.trim().replace(/^API_TOKEN\s*=\s*/i, '').replace(/^["']|["']$/g, '').replace(/\s+/g, '')
}

async function submit(): Promise<void> {
  error.value = null
  busy.value = true
  const value = normalize(token.value)
  try {
    await api.check(value)
    auth.set(value)
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/'
    await router.replace(redirect)
  } catch (e) {
    error.value = e instanceof ApiError && e.status === 401
      ? `Token inválido (${value.length} caracteres recebidos; o esperado são 64).`
      : 'Não foi possível contatar o servidor.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <main class="login">
    <form class="card panel" @submit.prevent="submit">
      <div class="head">
        <span class="mark"><AppIcon name="logo" :size="32" /></span>
        <div>
          <h1>GLPI Bianchi</h1>
          <p class="muted">Inventário de ativos de TI</p>
        </div>
      </div>

      <label for="token">Token de acesso da API</label>
      <input
        id="token"
        v-model="token"
        class="input mono"
        type="password"
        autocomplete="off"
        spellcheck="false"
        data-1p-ignore
        data-lpignore="true"
        required
        placeholder="cole o API_TOKEN"
        :aria-invalid="!!error"
        aria-describedby="token-help"
      />
      <p id="token-help" class="help muted">
        No servidor: <code class="mono">sed -n 's/^API_TOKEN=//p' /opt/glpi-bianchi/.env.local</code>
      </p>
      <p v-if="error" class="error-box" role="alert">{{ error }}</p>
      <button class="btn btn-primary" type="submit" :disabled="busy || !token.trim()">
        {{ busy ? 'Validando…' : 'Entrar' }}
      </button>
      <p class="muted small">Login com AD/LDAP e Entra ID chega na fase 2.</p>
    </form>
  </main>
</template>

<style scoped>
.login { min-height: 100vh; display: grid; place-items: center; padding: 24px; }
.card { width: min(420px, 100%); padding: 28px; display: flex; flex-direction: column; gap: 12px; }
.head { display: flex; gap: 12px; align-items: center; margin-bottom: 12px; }
.head p { margin: 2px 0 0; }
.mark { color: var(--accent); display: grid; }
label { font-weight: 500; font-size: 13px; }
.help { margin: 0; font-size: 12px; overflow-wrap: anywhere; }
.help code { font-size: 11.5px; }
.small { font-size: 12px; margin: 4px 0 0; text-align: center; }
.btn-primary { min-height: 44px; }
.btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
.error-box { margin: 0; }
</style>
