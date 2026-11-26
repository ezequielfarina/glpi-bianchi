<script setup lang="ts">
import { ref } from 'vue'
import AppIcon from './AppIcon.vue'
import { api, ApiError } from '../lib/api'
import { ASSIGNMENT_LABEL } from '../lib/format'
import { notify } from '../lib/toast'

// Lista editável de um cadastro auxiliar (tipos/categorias de licença, tipos de contrato).
const props = defineProps<{ kind: 'license_types' | 'license_categories' | 'contract_types'; title: string; items: { id: number; name: string; in_use: number; assignment?: string }[]; bare?: boolean }>()
const emit = defineEmits<{ changed: []; added: [id: number] }>()
const name = ref('')
const assignment = ref('device')
const editId = ref<number | null>(null)
const editName = ref('')
const editAssignment = ref('device')

async function add(): Promise<void> {
  try {
    const r = await api.saveLookup(props.kind, null, { name: name.value, ...(props.kind === 'license_types' ? { assignment: assignment.value } : {}) })
    name.value = ''
    emit('changed')
    emit('added', r.id)
  } catch (e) { notify('warn', 'Não foi possível adicionar', e instanceof Error ? e.message : String(e)) }
}
function startEdit(i: { id: number; name: string; assignment?: string }): void {
  editId.value = i.id
  editName.value = i.name
  editAssignment.value = i.assignment ?? 'device'
}
async function saveEdit(): Promise<void> {
  if (editId.value === null) return
  try {
    await api.saveLookup(props.kind, editId.value, { name: editName.value, ...(props.kind === 'license_types' ? { assignment: editAssignment.value } : {}) })
    editId.value = null
    emit('changed')
  } catch (e) { notify('warn', 'Não foi possível salvar', e instanceof Error ? e.message : String(e)) }
}
async function remove(i: { id: number; name: string; in_use: number }): Promise<void> {
  const usage = i.in_use ? `\n\n${i.in_use} registro(s) usam este item e ficarão sem ele.` : ''
  if (!window.confirm(`Excluir "${i.name}"?${usage}`)) return
  try { await api.deleteLookup(props.kind, i.id); emit('changed') }
  catch (e) { notify('warn', 'Não foi possível excluir', e instanceof ApiError ? e.message : String(e)) }
}
</script>

<template>
  <section :class="bare ? 'bare' : 'panel'">
    <div v-if="!bare" class="panel-head"><h2>{{ title }}</h2></div>
    <ul class="items">
      <li v-for="i in items" :key="i.id">
        <template v-if="editId === i.id">
          <input v-model="editName" class="input" :aria-label="`Nome de ${i.name}`" @keydown.enter.prevent="saveEdit" />
          <select v-if="kind === 'license_types'" v-model="editAssignment" class="input" aria-label="Modelo de atribuição">
            <option v-for="(label, code) in ASSIGNMENT_LABEL" :key="code" :value="code">{{ label }}</option>
          </select>
          <button class="btn" type="button" @click="saveEdit">Salvar</button>
          <button class="btn" type="button" @click="editId = null">Cancelar</button>
        </template>
        <template v-else>
          <span class="name">{{ i.name }}</span>
          <span v-if="i.assignment" class="tag">{{ ASSIGNMENT_LABEL[i.assignment] }}</span>
          <span class="muted small">{{ i.in_use }} em uso</span>
          <button class="btn btn-icon" type="button" :aria-label="`Editar ${i.name}`" @click="startEdit(i)"><AppIcon name="edit" :size="14" /></button>
          <button class="btn btn-icon" type="button" :aria-label="`Excluir ${i.name}`" :disabled="i.in_use > 0 && kind === 'license_types'" :title="i.in_use > 0 && kind === 'license_types' ? 'Em uso por licenças — altere-as antes de excluir' : undefined" @click="remove(i)"><AppIcon name="trash" :size="14" /></button>
        </template>
      </li>
    </ul>
    <form class="add" @submit.prevent="add">
      <label :for="`add-${kind}`" class="sr-only">Novo item</label>
      <input :id="`add-${kind}`" v-model="name" class="input" placeholder="Adicionar…" maxlength="128" />
      <select v-if="kind === 'license_types'" v-model="assignment" class="input" aria-label="Modelo de atribuição">
        <option v-for="(label, code) in ASSIGNMENT_LABEL" :key="code" :value="code">{{ label }}</option>
      </select>
      <button class="btn" type="submit" :disabled="!name.trim()"><AppIcon name="plus" :size="14" /> Adicionar</button>
    </form>
  </section>
</template>

<style scoped>
.items { list-style: none; margin: 0; padding: 0 20px; }
.bare .items { padding: 0; }
.bare .add { padding: 14px 0 0; }
.items li { display: flex; align-items: center; gap: 8px; padding: 6px 0; border-bottom: 1px solid var(--border-soft); flex-wrap: wrap; }
.items .input { flex: 1; min-width: 140px; }
.name { flex: 1; min-width: 0; }
.small { font-size: 12px; }
.add { display: flex; gap: 8px; padding: 14px 20px 20px; flex-wrap: wrap; }
.add .input:first-of-type { flex: 1; min-width: 160px; }
</style>
