<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import ContractFormModal from '../components/forms/ContractFormModal.vue'
import LoadState from '../components/LoadState.vue'
import { api } from '../lib/api'
import { BILLING_LABEL, CONTRACT_STATUS, dateOnly, money } from '../lib/format'
import type { ContractRow, Lookups } from '../lib/types'

const route = useRoute()
const router = useRouter()
const status = ref(typeof route.query.status === 'string' ? route.query.status : '')
const items = ref<ContractRow[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const lookups = ref<Lookups | null>(null)
const creating = ref(false)

async function load(): Promise<void> {
  loading.value = true
  try { items.value = (await api.contracts(status.value || undefined)).items; error.value = null }
  catch (e) { error.value = e instanceof Error ? e.message : String(e) }
  finally { loading.value = false }
}
watch(status, () => { void router.replace({ query: status.value ? { status: status.value } : {} }); void load() })
async function openNew(): Promise<void> { lookups.value ??= await api.lookups(); creating.value = true }

const annual = computed(() => items.value.filter((c) => c.status !== 'expired').reduce((s, c) => s + Number(c.annual_value ?? 0), 0))
function daysLabel(c: ContractRow): string {
  if (c.days_left === null) return ''
  if (c.days_left < 0) return `venceu há ${-c.days_left} dia(s)`
  return c.days_left === 0 ? 'vence hoje' : `${c.days_left} dia(s)`
}
void load()
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div><h1>Contratos</h1><p class="muted">{{ items.length }} contrato(s) · custo anual vigente {{ money(annual) }}</p></div>
      <div class="actions"><button class="btn btn-primary" type="button" @click="openNew"><AppIcon name="plus" :size="16" /> Novo contrato</button></div>
    </header>
    <section class="panel">
      <div class="filters">
        <label for="ct-status" class="muted">Situação</label>
        <select id="ct-status" v-model="status" class="input">
          <option value="">Todos</option><option value="active">Vigentes</option><option value="expiring">Vencendo</option>
          <option value="expired">Vencidos</option><option value="open">Sem prazo</option>
        </select>
      </div>
      <LoadState :loading="loading && !items.length" :error="error" :rows="5" @retry="load">
        <div v-if="!items.length" class="empty">Nenhum contrato.</div>
        <div v-else class="table-wrap">
          <table class="table">
            <thead><tr><th>Contrato</th><th>Fornecedor</th><th>Tipo</th><th>Vigência</th><th>Valor</th><th>Ativos</th><th>Situação</th></tr></thead>
            <tbody>
              <tr v-for="c in items" :key="c.id" class="clickable" @click="router.push(`/contracts/${c.id}`)">
                <td><RouterLink :to="`/contracts/${c.id}`" class="strong" @click.stop>{{ c.title }}</RouterLink><div class="muted mono small">{{ c.number }}</div></td>
                <td class="muted">{{ c.supplier ?? '—' }}</td>
                <td class="muted">{{ c.contract_type ?? '—' }}</td>
                <td>{{ dateOnly(c.start_date) }} → {{ dateOnly(c.end_date) }}<div class="muted small">{{ daysLabel(c) }}</div></td>
                <td class="num">{{ money(c.value) }}<div class="muted small">{{ BILLING_LABEL[c.billing_period] }}</div></td>
                <td class="num">{{ c.assets }}</td>
                <td><span class="pill" :class="CONTRACT_STATUS[c.status]?.cls">{{ CONTRACT_STATUS[c.status]?.label }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </LoadState>
    </section>
    <ContractFormModal v-if="creating && lookups" :lookups="lookups" @lookups-changed="(v) => (lookups = v)" @close="creating = false" @saved="(id) => router.push(`/contracts/${id}`)" />
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 20px; max-width: 1480px; }
.page-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
.page-head p { margin: 4px 0 0; font-size: 13px; }
.filters { display: flex; align-items: center; gap: 10px; padding: 16px 20px; }
.strong { color: var(--text); font-weight: 500; }
.small { font-size: 12px; }
.clickable { cursor: pointer; }
:deep(.skeletons) { padding: 8px 20px 20px; }
</style>
