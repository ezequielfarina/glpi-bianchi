<script setup lang="ts">
import { computed, ref } from 'vue'
import { dateOnly } from '../../lib/format'
import type { StockUnit } from '../../lib/types'

// Escolha de unidades (patrimônios) em estoque de um item controlado por patrimônio.
const props = defineProps<{ units: StockUnit[]; modelValue: string[]; idPrefix: string }>()
const emit = defineEmits<{ 'update:modelValue': [v: string[]] }>()
const filter = ref('')
const shown = computed(() => {
  const q = filter.value.trim().toLowerCase()
  return q ? props.units.filter((u) => `${u.inventory_number ?? ''} ${u.serial_number ?? ''}`.toLowerCase().includes(q)) : props.units
})
function toggle(uuid: string, on: boolean): void {
  emit('update:modelValue', on ? [...props.modelValue, uuid] : props.modelValue.filter((u) => u !== uuid))
}
</script>

<template>
  <div class="units">
    <div v-if="units.length > 8" class="u-filter"><input v-model="filter" class="input" :aria-label="'Filtrar patrimônio ou série'" placeholder="Filtrar patrimônio ou série…" /></div>
    <p v-if="!units.length" class="muted small">Nenhuma unidade em estoque.</p>
    <label v-for="u in shown" :key="u.uuid" class="check unit" :for="`${idPrefix}-${u.uuid}`">
      <input :id="`${idPrefix}-${u.uuid}`" type="checkbox" :checked="modelValue.includes(u.uuid)" @change="toggle(u.uuid, ($event.target as HTMLInputElement).checked)" />
      <span class="mono">{{ u.inventory_number }}</span>
      <span class="muted mono">{{ u.serial_number ?? 'sem série' }}</span>
      <span class="muted small">desde {{ dateOnly(u.since) }}<template v-if="u.invoice_number"> · NF {{ u.invoice_number }}</template></span>
    </label>
  </div>
</template>

<style scoped>
.units { display: flex; flex-direction: column; gap: 4px; max-height: 220px; overflow-y: auto; padding: 8px 10px; border: 1px solid var(--border); border-radius: var(--radius-sm); }
.unit { display: grid; grid-template-columns: 16px 110px minmax(0, 1fr) auto; gap: 10px; align-items: center; }
.u-filter { margin-bottom: 6px; }
.small { font-size: 12px; }
</style>
