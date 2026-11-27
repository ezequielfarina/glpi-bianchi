<script lang="ts">
export interface FieldSpec {
  key: string
  label: string
  type?: 'text' | 'number' | 'textarea' | 'select' | 'check' | 'color' | 'date'
  options?: { value: string | number | null; label: string }[]
  required?: boolean
  placeholder?: string
  full?: boolean
  hint?: string
  mono?: boolean
  /** mostra o campo só quando a função devolver true */
  show?: (v: Record<string, unknown>) => boolean
}
</script>

<script setup lang="ts">
import { reactive } from 'vue'
import AppModal from '../AppModal.vue'
import { clean, useForm } from '../forms/useForm'

// Formulário genérico (modal) dirigido por especificação de campos — cadastros simples do DCIM/IPAM.
const props = defineProps<{
  title: string
  fields: FieldSpec[]
  initial?: Record<string, unknown>
  save: (values: Record<string, unknown>) => Promise<{ id: number }>
  okText?: string
  wide?: boolean
}>()
const emit = defineEmits<{ close: []; saved: [id: number] }>()
const { saving, errors, submit } = useForm()
const v = reactive<Record<string, unknown>>(Object.fromEntries(props.fields.map((f) => [f.key, props.initial?.[f.key] ?? (f.type === 'check' ? false : '')])))
for (const [k, val] of Object.entries(props.initial ?? {})) if (!(k in v)) v[k] = val

async function go(): Promise<void> {
  const r = await submit(() => props.save(clean({ ...v })), props.okText ?? 'Salvo')
  if (r) emit('saved', r.id)
}
const visible = (f: FieldSpec) => !f.show || f.show(v)
const id = (f: FieldSpec) => `sf-${f.key}`
</script>

<template>
  <AppModal :title="title" :wide="wide ?? fields.length > 4" @close="emit('close')">
    <form class="form-grid" novalidate @submit.prevent="go">
      <template v-for="f in fields" :key="f.key">
        <label v-if="f.type === 'check' && visible(f)" class="check" :class="{ full: f.full }"><input v-model="v[f.key]" type="checkbox" /> {{ f.label }}</label>
        <div v-else-if="visible(f)" class="field" :class="{ full: f.full || f.type === 'textarea' }">
          <label :for="id(f)">{{ f.label }}{{ f.required ? ' *' : '' }}</label>
          <select v-if="f.type === 'select'" :id="id(f)" v-model="v[f.key]" class="input" :aria-invalid="!!errors[f.key]">
            <option v-if="!f.required" :value="''">—</option>
            <option v-for="o in f.options ?? []" :key="String(o.value)" :value="o.value ?? ''">{{ o.label }}</option>
          </select>
          <textarea v-else-if="f.type === 'textarea'" :id="id(f)" v-model="v[f.key] as string" class="input" rows="3" :placeholder="f.placeholder" />
          <div v-else-if="f.type === 'color'" class="color-row">
            <input :id="id(f)" :value="`#${String(v[f.key] || '9e9e9e')}`" type="color" class="swatch" @input="v[f.key] = ($event.target as HTMLInputElement).value.slice(1)" />
            <input v-model="v[f.key] as string" class="input mono" maxlength="7" :aria-invalid="!!errors[f.key]" />
          </div>
          <input v-else :id="id(f)" v-model="v[f.key]" class="input" :class="{ mono: f.mono }" :type="f.type === 'number' ? 'number' : f.type === 'date' ? 'date' : 'text'"
            :placeholder="f.placeholder" :aria-invalid="!!errors[f.key]" />
          <span v-if="errors[f.key]" class="err">{{ errors[f.key] }}</span>
          <span v-else-if="f.hint" class="hint">{{ f.hint }}</span>
        </div>
      </template>
      <slot :values="v" :errors="errors" />
      <div v-if="Object.keys(errors).some((k) => !fields.some((f) => f.key === k))" class="err full">{{ Object.entries(errors).filter(([k]) => !fields.some((f) => f.key === k)).map(([, m]) => m).join(' ') }}</div>
      <div class="form-actions full">
        <button class="btn" type="button" @click="emit('close')">Cancelar</button>
        <button class="btn btn-primary" type="submit" :disabled="saving">{{ saving ? 'Salvando…' : 'Salvar' }}</button>
      </div>
    </form>
  </AppModal>
</template>

<style scoped>
.color-row { display: flex; gap: 8px; align-items: center; }
.swatch { width: 40px; height: 36px; padding: 2px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--bg-elev); }
</style>
