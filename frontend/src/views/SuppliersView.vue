<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import SupplierFormModal from '../components/forms/SupplierFormModal.vue'
import LoadState from '../components/LoadState.vue'
import { api } from '../lib/api'
import { taxId } from '../lib/format'
import type { SupplierRow } from '../lib/types'

const router = useRouter()
const q = ref('')
const items = ref<SupplierRow[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const creating = ref(false)

async function load(): Promise<void> {
  loading.value = true
  try { items.value = (await api.suppliers(q.value || undefined)).items; error.value = null }
  catch (e) { error.value = e instanceof Error ? e.message : String(e) }
  finally { loading.value = false }
}
let t: number | undefined
watch(q, () => { window.clearTimeout(t); t = window.setTimeout(load, 300) })
void load()
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div><h1>Fornecedores</h1><p class="muted">{{ items.length }} cadastrado(s)</p></div>
      <div class="actions"><button class="edit-only btn btn-primary" type="button" @click="creating = true"><AppIcon name="plus" :size="16" /> Novo fornecedor</button></div>
    </header>
    <section class="panel">
      <div class="filters">
        <label for="sup-q" class="sr-only">Buscar</label>
        <input id="sup-q" v-model="q" class="input" type="search" placeholder="Razão social, fantasia, CNPJ ou e-mail" style="flex: 1" />
      </div>
      <LoadState :loading="loading && !items.length" :error="error" :rows="5" @retry="load">
        <div v-if="!items.length" class="empty">Nenhum fornecedor cadastrado.</div>
        <div v-else class="table-wrap">
          <table class="table">
            <thead><tr><th>Fornecedor</th><th>CNPJ / CPF</th><th>Contato</th><th>Contatos</th><th>Contratos</th><th>Licenças</th></tr></thead>
            <tbody>
              <tr v-for="s in items" :key="s.id" class="clickable" @click="router.push(`/suppliers/${s.id}`)">
                <td>
                  <RouterLink :to="`/suppliers/${s.id}`" class="strong" @click.stop>{{ s.name }}</RouterLink>
                  <span v-if="!s.active" class="pill pill-muted" style="margin-left: 6px">inativo</span>
                  <div v-if="s.trade_name" class="muted small">{{ s.trade_name }}</div>
                </td>
                <td class="mono muted">{{ taxId(s.tax_id) }}</td>
                <td class="muted small">{{ s.email ?? '' }}<div>{{ s.phone ?? '' }}</div></td>
                <td class="num">{{ s.contacts }}</td>
                <td class="num">{{ s.contracts }}</td>
                <td class="num">{{ s.licenses }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </LoadState>
    </section>
    <SupplierFormModal v-if="creating" @close="creating = false" @saved="(id) => router.push(`/suppliers/${id}`)" />
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 20px; max-width: 1480px; }
.page-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
.page-head p { margin: 4px 0 0; font-size: 13px; }
.filters { display: flex; gap: 10px; padding: 16px 20px; }
.strong { color: var(--text); font-weight: 500; }
.small { font-size: 12px; }
.clickable { cursor: pointer; }
:deep(.skeletons) { padding: 8px 20px 20px; }
</style>
