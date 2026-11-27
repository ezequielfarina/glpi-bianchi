<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import LoadState from '../LoadState.vue'
import { api } from '../../lib/api'
import { dateTime, num, relative } from '../../lib/format'
import type { AuditPage } from '../../lib/types'
import { describeAgent } from '../../lib/userAgent'

// Acessos (ADR-0011): logins, falhas, bloqueios e ações sobre contas — tabela auth_event.
defineExpose({ load: () => load() })

const EVENT: Record<string, { label: string; cls: string }> = {
  login_ok: { label: 'Login', cls: 'pill-ok' },
  password_ok: { label: 'Senha ok (aguardando 2FA)', cls: 'pill-muted' },
  login_failed: { label: 'Falha de login', cls: 'pill-warn' },
  login_throttled: { label: 'Bloqueado por tentativas', cls: 'pill-warn' },
  mfa_failed: { label: 'Código 2FA inválido', cls: 'pill-warn' },
  logout: { label: 'Saiu', cls: 'pill-muted' },
  password_changed: { label: 'Trocou a senha', cls: 'pill-accent' },
  password_reset: { label: 'Senha temporária gerada', cls: 'pill-accent' },
  mfa_enabled: { label: '2FA ativado', cls: 'pill-ok' },
  mfa_disabled: { label: '2FA desativado', cls: 'pill-warn' },
  mfa_reset: { label: '2FA desligado (admin)', cls: 'pill-warn' },
  account_created: { label: 'Conta criada', cls: 'pill-accent' },
  account_updated: { label: 'Conta alterada', cls: 'pill-accent' },
  account_disabled: { label: 'Conta desativada', cls: 'pill-warn' },
  account_enabled: { label: 'Conta reativada', cls: 'pill-accent' },
  account_deleted: { label: 'Conta excluída', cls: 'pill-warn' },
  unlock: { label: 'Desbloqueio', cls: 'pill-accent' },
  sessions_revoked: { label: 'Sessões encerradas', cls: 'pill-accent' },
}
const REASON: Record<string, string> = {
  unknown_user: 'login inexistente', bad_password: 'senha incorreta', inactive: 'conta desativada', bad_totp: 'código 2FA errado',
  bad_current_password: 'senha atual errada (troca de senha)', mfa_disable_denied: 'tentativa de desligar 2FA',
}

const q = ref('')
const event = ref('')
const page = ref(1)
const data = ref<AuditPage | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
async function load(): Promise<void> {
  loading.value = true
  try { data.value = await api.audit({ q: q.value || undefined, event: event.value || undefined, page: page.value }); error.value = null }
  catch (e) { error.value = e instanceof Error ? e.message : String(e) }
  finally { loading.value = false }
}
let t: number | undefined
watch(q, () => { window.clearTimeout(t); t = window.setTimeout(() => { page.value = 1; void load() }, 300) })
watch(event, () => { page.value = 1; void load() })
const pages = computed(() => (data.value ? Math.max(1, Math.ceil(data.value.total / data.value.perPage)) : 1))
function go(p: number): void { page.value = p; void load() }
function detail(d: Record<string, unknown>): string {
  const parts: string[] = []
  if (typeof d.reason === 'string') parts.push(REASON[d.reason] ?? d.reason)
  if (typeof d.role === 'string') parts.push(`perfil ${d.role}`)
  if (Array.isArray(d.fields)) parts.push(`campos: ${d.fields.join(', ')}`)
  if (typeof d.retry_after === 'number') parts.push(`liberado em ${Math.ceil(d.retry_after / 60)} min`)
  if (typeof d.count === 'number') parts.push(`${d.count} sessão(ões)`)
  if (d.mfa === 'totp') parts.push('com 2FA')
  return parts.join(' · ')
}
void load()
</script>

<template>
    <section class="panel">
      <div class="filters">
        <label class="sr-only" for="a-q">Usuário</label>
        <input id="a-q" v-model="q" class="input grow" type="search" placeholder="Login do usuário ou do administrador" />
        <label class="sr-only" for="a-ev">Evento</label>
        <select id="a-ev" v-model="event" class="input">
          <option value="">Todos os eventos</option>
          <option value="failures">Falhas e bloqueios</option>
          <option value="login_ok">Logins</option>
          <option value="admin">Ações de administradores</option>
          <option value="password_changed">Trocas de senha</option>
          <option value="mfa_enabled">2FA ativado</option>
        </select>
      </div>
      <LoadState :loading="loading && !data" :error="error" :rows="8" @retry="load">
        <div v-if="data" class="table-wrap" :class="{ dim: loading }">
          <table class="table">
            <thead><tr><th>Quando</th><th>Evento</th><th>Conta</th><th>Origem</th><th>Detalhes</th></tr></thead>
            <tbody>
              <tr v-for="e in data.items" :key="e.id">
                <td :title="dateTime(e.created_at)">{{ relative(e.created_at) }}<div class="muted small">{{ dateTime(e.created_at) }}</div></td>
                <td><span class="pill" :class="EVENT[e.event]?.cls ?? 'pill-muted'">{{ EVENT[e.event]?.label ?? e.event }}</span></td>
                <td>
                  <span class="mono">{{ e.username ?? '—' }}</span>
                  <div v-if="e.display_name" class="muted small">{{ e.display_name }}</div>
                </td>
                <td><span class="mono">{{ e.ip ?? '—' }}</span><div class="muted small">{{ e.actor ? `por ${e.actor}` : describeAgent(e.user_agent) }}</div></td>
                <td class="muted small">{{ detail(e.details) }}</td>
              </tr>
            </tbody>
          </table>
          <p v-if="!data.items.length" class="empty">Nenhum evento com esses filtros.</p>
        </div>
        <nav v-if="data && pages > 1" class="pager" aria-label="Paginação">
          <button class="btn" type="button" :disabled="page <= 1" @click="go(page - 1)">Anterior</button>
          <span class="muted">Página {{ page }} de {{ pages }} · {{ num(data.total) }} eventos</span>
          <button class="btn" type="button" :disabled="page >= pages" @click="go(page + 1)">Próxima</button>
        </nav>
      </LoadState>
    </section>
</template>

<style scoped>
.filters { display: flex; gap: 10px; padding: 16px 20px; flex-wrap: wrap; }
.grow { flex: 1; min-width: 220px; }
.small { font-size: 12px; }
.dim { opacity: .6; }
.pager { display: flex; justify-content: center; align-items: center; gap: 12px; padding: 14px; }
</style>
