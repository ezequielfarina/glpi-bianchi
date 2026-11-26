<script setup lang="ts">
import { computed, ref } from 'vue'
import AppModal from '../AppModal.vue'
import LookupEditor from '../LookupEditor.vue'
import { api } from '../../lib/api'
import type { Lookups } from '../../lib/types'

// Gerencia um cadastro auxiliar (tipos/categorias de licença, tipos de contrato)
// sem sair do formulário: adicionar, renomear, excluir. Devolve as listas atualizadas.
type Kind = 'license_types' | 'license_categories' | 'contract_types'
const props = defineProps<{ kind: Kind; lookups: Lookups }>()
const emit = defineEmits<{ close: []; changed: [lookups: Lookups]; added: [id: number] }>()

const TITLES: Record<Kind, string> = {
  license_types: 'Tipos de licença',
  license_categories: 'Categorias de licença',
  contract_types: 'Tipos de contrato',
}
const HINTS: Record<Kind, string> = {
  license_types: 'O modelo de atribuição de cada tipo é sugerido automaticamente ao cadastrar a licença (por máquina, por usuário ou volume).',
  license_categories: 'Agrupa licenças nas listas e relatórios (ex.: Sistema operacional, Office, Antivírus, Banco de dados).',
  contract_types: 'Classifica os contratos (ex.: Suporte, Locação, Link de internet).',
}
const current = ref<Lookups>(props.lookups)
const items = computed(() => current.value[props.kind])

async function refresh(): Promise<void> {
  current.value = await api.lookups()
  emit('changed', current.value)
}
</script>

<template>
  <AppModal :title="TITLES[kind]" @close="emit('close')">
    <p class="hint top">{{ HINTS[kind] }}</p>
    <LookupEditor :kind="kind" :title="TITLES[kind]" :items="items" bare @changed="refresh" @added="(id) => emit('added', id)" />
  </AppModal>
</template>

<style scoped>
.top { margin: 0 0 12px; }
</style>
