<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import AssetPicker from '../components/AssetPicker.vue'
import AttachmentsPanel from '../components/AttachmentsPanel.vue'
import ContractFormModal from '../components/forms/ContractFormModal.vue'
import LoadState from '../components/LoadState.vue'
import { api } from '../lib/api'
import { BILLING_LABEL, CONTRACT_STATUS, dateOnly, money, RENEWAL_LABEL } from '../lib/format'
import { notify } from '../lib/toast'
import type { AssetRow, Lookups } from '../lib/types'
import { useAsync } from '../lib/useAsync'

const props = defineProps<{ id: string }>()
const router = useRouter()
const { data: c, loading, error, reload } = useAsync(() => api.contract(Number(props.id)))
const lookups = ref<Lookups | null>(null)
const editing = ref(false)

async function edit(): Promise<void> { lookups.value ??= await api.lookups(); editing.value = true }
async function link(a: AssetRow): Promise<void> {
  await api.linkContractAsset(Number(props.id), a.uuid)
  notify('ok', `${a.name} vinculado ao contrato`)
  await reload()
}
async function unlink(uuid: string): Promise<void> {
  await api.unlinkContractAsset(Number(props.id), uuid)
  await reload()
}
async function remove(): Promise<void> {
  if (!c.value || !window.confirm(`Excluir o contrato "${c.value.title}" e seus anexos?`)) return
  await api.deleteContract(c.value.id)
  notify('ok', 'Contrato excluído')
  await router.push('/contracts')
}
</script>

<template>
  <div class="page">
    <RouterLink to="/contracts" class="back"><AppIcon name="back" :size="16" /> Contratos</RouterLink>
    <LoadState :loading="loading" :error="error" :rows="6" @retry="reload">
      <template v-if="c">
        <header class="hero panel">
          <div class="top">
            <div>
              <div class="title-row"><h1>{{ c.title }}</h1><span class="pill" :class="CONTRACT_STATUS[c.status]?.cls">{{ CONTRACT_STATUS[c.status]?.label }}</span></div>
              <p class="muted"><span class="mono">{{ c.number }}</span> · {{ c.contract_type ?? 'Sem tipo' }} ·
                <RouterLink v-if="c.supplier_id" :to="`/suppliers/${c.supplier_id}`">{{ c.supplier }}</RouterLink><template v-else>sem fornecedor</template></p>
            </div>
            <div class="actions">
              <button class="btn" type="button" @click="edit"><AppIcon name="edit" :size="16" /> Editar</button>
              <button class="btn btn-danger" type="button" @click="remove"><AppIcon name="trash" :size="16" /> Excluir</button>
            </div>
          </div>
          <dl class="facts">
            <div><dt>Vigência</dt><dd>{{ dateOnly(c.start_date) }} → {{ dateOnly(c.end_date) }}</dd></div>
            <div><dt>Prazo</dt><dd>{{ c.days_left === null ? 'indeterminado' : c.days_left < 0 ? `vencido há ${-c.days_left} dia(s)` : `${c.days_left} dia(s)` }}</dd></div>
            <div><dt>Renovação</dt><dd>{{ RENEWAL_LABEL[c.renewal] }}<template v-if="c.notice_days"> · aviso {{ c.notice_days }} dias</template></dd></div>
            <div><dt>Valor</dt><dd class="num">{{ money(c.value) }} <span class="muted">{{ BILLING_LABEL[c.billing_period] }}</span></dd></div>
            <div><dt>Custo anual</dt><dd class="num">{{ money(c.annual_value) }}</dd></div>
          </dl>
          <p v-if="c.description" class="notes">{{ c.description }}</p>
        </header>

        <section class="panel">
          <div class="panel-head"><h2>Ativos cobertos <span class="muted">({{ c.assets.length }})</span></h2></div>
          <div class="add-row"><AssetPicker id="ct-asset" label="Vincular ativo (nome, patrimônio, serial, IP)…" @pick="link" /></div>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>Ativo</th><th>Tipo</th><th>Patrimônio</th><th>Serial</th><th><span class="sr-only">Ações</span></th></tr></thead>
              <tbody>
                <tr v-for="a in c.assets" :key="a.uuid">
                  <td><RouterLink :to="`/assets/${a.uuid}`">{{ a.name }}</RouterLink></td>
                  <td class="muted">{{ a.type_label }}</td>
                  <td class="mono">{{ a.inventory_number ?? '—' }}</td>
                  <td class="mono muted">{{ a.serial_number ?? '—' }}</td>
                  <td><button class="btn btn-icon" type="button" :aria-label="`Desvincular ${a.name}`" @click="unlink(a.uuid)"><AppIcon name="close" :size="14" /></button></td>
                </tr>
              </tbody>
            </table>
            <p v-if="!c.assets.length" class="empty">Nenhum ativo vinculado (equipamentos em garantia, locação, manutenção…).</p>
          </div>
        </section>

        <section v-if="c.licenses.length" class="panel">
          <div class="panel-head"><h2>Licenças deste contrato</h2></div>
          <ul class="lic"><li v-for="l in c.licenses" :key="l.id"><RouterLink :to="`/licenses/${l.id}`">{{ l.name }}</RouterLink> <span class="muted">({{ l.seats }} assentos)</span></li></ul>
        </section>

        <AttachmentsPanel owner-type="contract" :owner="c.id" :items="c.attachments" title="Contrato assinado, aditivos e notas" @changed="reload" />
        <ContractFormModal v-if="editing && lookups" :lookups="lookups" @lookups-changed="(v) => (lookups = v)" :contract="c" @close="editing = false" @saved="editing = false; reload()" />
      </template>
    </LoadState>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 16px; max-width: 1480px; }
.back { display: inline-flex; align-items: center; gap: 4px; color: var(--text-3); font-size: 13px; width: fit-content; }
.hero { padding: 22px 24px; display: flex; flex-direction: column; gap: 16px; }
.top { display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.title-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.top p { margin: 6px 0 0; }
.actions { display: flex; gap: 8px; }
.facts { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 14px 24px; margin: 0; }
.facts dt { font-size: 12px; color: var(--text-3); margin-bottom: 3px; }
.facts dd { margin: 0; font-weight: 500; }
.notes { margin: 0; color: var(--text-2); font-size: 13px; white-space: pre-line; }
.add-row { padding: 0 20px 14px; display: flex; }
.lic { margin: 0; padding: 0 20px 16px 36px; }
.small { font-size: 12px; }
@media (max-width: 1100px) { .facts { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
