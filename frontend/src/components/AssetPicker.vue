<script setup lang="ts">
import { ref, watch } from 'vue'
import { api } from '../lib/api'
import type { AssetRow } from '../lib/types'

// Busca de ativo por nome, patrimônio, serial, MAC ou IP (usa /api/ui/assets?q=).
defineProps<{ id: string; label?: string }>()
const emit = defineEmits<{ pick: [asset: AssetRow] }>()
const q = ref('')
const results = ref<AssetRow[]>([])
const open = ref(false)
let t: number | undefined

watch(q, (v) => {
  window.clearTimeout(t)
  if (v.trim().length < 2) { results.value = []; return }
  t = window.setTimeout(async () => {
    results.value = (await api.assets({ q: v.trim(), perPage: 8 })).items
    open.value = true
  }, 250)
})

function pick(a: AssetRow): void {
  emit('pick', a)
  q.value = ''
  results.value = []
  open.value = false
}
</script>

<template>
  <div class="picker">
    <label :for="id" class="sr-only">{{ label ?? 'Buscar ativo' }}</label>
    <input
      :id="id" v-model="q" class="input" type="search" autocomplete="off"
      :placeholder="label ?? 'Buscar ativo por nome, patrimônio, serial ou IP…'"
      role="combobox" :aria-expanded="open && results.length > 0" :aria-controls="`${id}-list`"
      @keydown.escape="open = false"
    />
    <ul v-if="open && results.length" :id="`${id}-list`" class="list panel" role="listbox">
      <li v-for="a in results" :key="a.uuid" role="option" :aria-selected="false">
        <button type="button" @click="pick(a)">
          <strong>{{ a.name }}</strong>
          <span class="muted small">{{ a.type_label }} · {{ a.inventory_number ?? 'sem patrimônio' }}<template v-if="a.serial_number"> · S/N {{ a.serial_number }}</template></span>
        </button>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.picker { position: relative; flex: 1; min-width: 220px; }
.picker .input { width: 100%; }
.list { position: absolute; z-index: 30; left: 0; right: 0; top: 44px; list-style: none; margin: 0; padding: 4px; max-height: 300px; overflow-y: auto; box-shadow: 0 8px 24px rgba(0,0,0,.25); }
.list button { all: unset; box-sizing: border-box; display: flex; flex-direction: column; width: 100%; padding: 8px 10px; border-radius: 6px; cursor: pointer; }
.list button:hover, .list button:focus-visible { background: var(--panel-hover); outline: none; }
.small { font-size: 12px; }
</style>
