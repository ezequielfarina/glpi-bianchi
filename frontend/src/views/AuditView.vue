<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import AccessAuditPanel from '../components/audit/AccessAuditPanel.vue'
import ActionsAuditPanel from '../components/audit/ActionsAuditPanel.vue'

// Auditoria: ações no sistema (ADR-0015) e acessos/contas (ADR-0011).
const route = useRoute()
const router = useRouter()
const tab = ref<'actions' | 'access'>(route.query.tab === 'access' ? 'access' : 'actions')
watch(tab, (t) => { void router.replace({ query: t === 'actions' ? {} : { tab: t } }) })
const actions = ref<InstanceType<typeof ActionsAuditPanel> | null>(null)
const access = ref<InstanceType<typeof AccessAuditPanel> | null>(null)
function refresh(): void { void (tab.value === 'actions' ? actions.value?.load() : access.value?.load()) }
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>Auditoria</h1>
        <p class="muted">Tudo que é feito no sistema — cadastros, alterações, exclusões, movimentações de estoque, notas, ferramentas — e os acessos.</p>
      </div>
      <button class="btn" type="button" @click="refresh"><AppIcon name="refresh" :size="16" /> Atualizar</button>
    </header>
    <div class="tabs" role="tablist" aria-label="Auditoria">
      <button class="tab" :class="{ active: tab === 'actions' }" role="tab" type="button" :aria-selected="tab === 'actions'" @click="tab = 'actions'"><AppIcon name="history" :size="16" /> Ações no sistema</button>
      <button class="tab" :class="{ active: tab === 'access' }" role="tab" type="button" :aria-selected="tab === 'access'" @click="tab = 'access'"><AppIcon name="key" :size="16" /> Acessos e contas</button>
    </div>
    <ActionsAuditPanel v-if="tab === 'actions'" ref="actions" />
    <AccessAuditPanel v-else ref="access" />
    <p class="muted small">Ações: registro permanente e somente inclusão (o banco bloqueia alteração e exclusão). Acessos: retenção de 365 dias.</p>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 18px; max-width: 1480px; }
.page-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
.page-head p { margin: 4px 0 0; font-size: 13px; }
.small { font-size: 12px; margin: 0; }
</style>
