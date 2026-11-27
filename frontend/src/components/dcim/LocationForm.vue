<script setup lang="ts">
import { computed, ref } from 'vue'
import SpecForm, { type FieldSpec } from './SpecForm.vue'
import { api } from '../../lib/api'
import { dcim, dcimChoices, type DcimChoices, type LocationRow } from '../../lib/dcim'
import type { BranchRow } from '../../lib/types'

const props = defineProps<{ location?: { id: number; name: string; kind: string; code: string | null; description: string | null; branch_id: number | null; parent_id: number | null } | null; preset?: { branch_id?: number | null; parent_id?: number | null } }>()
const emit = defineEmits<{ close: []; saved: [id: number] }>()
const branches = ref<BranchRow[]>([])
const all = ref<LocationRow[]>([])
const choices = ref<DcimChoices | null>(null)
const ready = ref(false)
void Promise.all([api.branches(), dcim.locations(), dcimChoices()]).then(([b, l, c]) => { branches.value = b.items; all.value = l.items; choices.value = c; ready.value = true })
const l = props.location
const initial = { name: l?.name ?? '', kind: l?.kind ?? 'room', code: l?.code ?? '', description: l?.description ?? '', branch_id: l?.branch_id ?? props.preset?.branch_id ?? '',
  parent_id: l?.parent_id ?? props.preset?.parent_id ?? '' }
const fields = computed<FieldSpec[]>(() => [
  { key: 'name', label: 'Nome', required: true, placeholder: 'ex.: Prédio A, 2º andar, CPD, Mesa 14 — Financeiro' },
  { key: 'kind', label: 'Tipo', type: 'select', required: true, options: choices.value?.location_kinds ?? [] },
  { key: 'branch_id', label: 'Filial', type: 'select', required: true, options: branches.value.map((b) => ({ value: b.id, label: b.name })), show: (v) => !v.parent_id },
  { key: 'parent_id', label: 'Dentro de', type: 'select', hint: 'O local filho herda a filial do pai.',
    options: all.value.filter((x) => x.id !== l?.id).map((x) => ({ value: x.id, label: `${x.branch_code ?? x.branch ?? ''} · ${'— '.repeat(x.depth)}${x.name}` })) },
  { key: 'code', label: 'Código', mono: true, placeholder: 'opcional' },
  { key: 'description', label: 'Descrição', full: true },
])
</script>

<template>
  <SpecForm v-if="ready" :title="l ? `Editar ${l.name}` : 'Novo local'" :fields="fields" :initial="initial" :save="(v) => dcim.saveLocation(l?.id ?? null, v)"
    :ok-text="l ? 'Local atualizado' : 'Local cadastrado'" @close="emit('close')" @saved="emit('saved', $event)" />
</template>
