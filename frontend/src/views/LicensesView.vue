<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import LicenseFormModal from '../components/forms/LicenseFormModal.vue'
import LoadState from '../components/LoadState.vue'
import { api } from '../lib/api'
import { ASSIGNMENT_LABEL, COMPLIANCE, dateOnly, money, num } from '../lib/format'
import type { LicenseRow, Lookups } from '../lib/types'

const router = useRouter()
const q = ref('')
const category = ref<number | ''>('')
const items = ref<LicenseRow[]>([])
const lookups = ref<Lookups | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const creating = ref(false)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const [r, l] = await Promise.all([api.licenses(q.value || undefined, category.value === '' ? undefined : category.value), lookups.value ? Promise.resolve(lookups.value) : api.lookups()])
    items.value = r.items
    lookups.value = l
  } catch (e) { error.value = e instanceof Error ? e.message : String(e) } finally { loading.value = false }
}
let t: number | undefined
watch(q, () => { window.clearTimeout(t); t = window.setTimeout(load, 300) })
watch(category, load)

const totals = computed(() => ({
  value: items.value.reduce((s, l) => s + Number(l.total_price ?? 0), 0),
  seats: items.value.reduce((s, l) => s + l.seats, 0),
  used: items.value.reduce((s, l) => s + l.used, 0),
  alerts: items.value.filter((l) => l.compliance !== 'ok').length,
}))
const pct = (l: LicenseRow) => (l.seats ? Math.min(100, Math.round((Math.max(l.used, l.detected ?? 0) / l.seats) * 100)) : 100)
void load()
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>Licenças</h1>
        <p class="muted">{{ num(items.length) }} licença(s) · {{ num(totals.used) }} de {{ num(totals.seats) }} assentos em uso · investimento {{ money(totals.value) }}
          <template v-if="totals.alerts"> · <span class="warn">{{ totals.alerts }} com alerta</span></template></p>
      </div>
      <div class="actions"><button class="btn btn-primary" type="button" @click="creating = true"><AppIcon name="plus" :size="16" /> Nova licença</button></div>
    </header>

    <section class="panel">
      <div class="filters">
        <label for="lic-q" class="sr-only">Buscar</label>
        <input id="lic-q" v-model="q" class="input" type="search" placeholder="Buscar por produto ou software" style="flex: 1 1 280px" />
        <label for="lic-cat" class="sr-only">Categoria</label>
        <select id="lic-cat" v-model="category" class="input">
          <option value="">Todas as categorias</option>
          <option v-for="c in lookups?.license_categories ?? []" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </div>
      <LoadState :loading="loading && !items.length" :error="error" :rows="6" @retry="load">
        <div v-if="!items.length" class="empty">Nenhuma licença cadastrada. Cadastre Windows, Office, Microsoft 365, antivírus…</div>
        <div v-else class="table-wrap">
          <table class="table">
            <thead><tr><th>Licença</th><th>Tipo / modelo</th><th>Uso</th><th>Detectado</th><th>Valor total</th><th>Vencimento</th><th>Situação</th></tr></thead>
            <tbody>
              <tr v-for="l in items" :key="l.id" class="clickable" @click="router.push(`/licenses/${l.id}`)">
                <td><RouterLink :to="`/licenses/${l.id}`" class="strong" @click.stop>{{ l.name }}</RouterLink><div class="muted small">{{ l.category ?? 'Sem categoria' }}<template v-if="l.supplier"> · {{ l.supplier }}</template></div></td>
                <td><span class="tag">{{ l.license_type }}</span><div class="muted small">{{ ASSIGNMENT_LABEL[l.assignment] }}</div></td>
                <td style="min-width: 130px">
                  <div class="num small">{{ l.used }} / {{ l.seats }}</div>
                  <div class="meter" :class="{ over: l.used > l.seats || (l.detected ?? 0) > l.seats }"><span :style="{ width: pct(l) + '%' }" /></div>
                </td>
                <td class="num muted">{{ l.detected ?? '—' }}</td>
                <td class="num">{{ money(l.total_price) }}</td>
                <td class="muted">{{ dateOnly(l.expires_at) }}</td>
                <td><span class="pill" :class="COMPLIANCE[l.compliance]?.cls">{{ COMPLIANCE[l.compliance]?.label }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </LoadState>
    </section>

    <LicenseFormModal v-if="creating && lookups" :lookups="lookups" @lookups-changed="(v) => (lookups = v)" @close="creating = false" @saved="(id) => router.push(`/licenses/${id}`)" />
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 20px; max-width: 1480px; }
.page-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
.page-head p { margin: 4px 0 0; font-size: 13px; }
.warn { color: var(--warn-text); }
.filters { display: flex; flex-wrap: wrap; gap: 10px; padding: 16px 20px; }
.strong { color: var(--text); font-weight: 500; }
.small { font-size: 12px; }
.clickable { cursor: pointer; }
:deep(.skeletons) { padding: 8px 20px 20px; }
</style>
