<script setup lang="ts">
import { computed, ref } from 'vue'
import SpecForm, { type FieldSpec } from './SpecForm.vue'
import { ipam, ipamChoices, type IpamChoices, type IpRow, type PrefixRow } from '../../lib/dcim'

// Endereço IP: livre ou já numa interface. "Próximo livre" busca no prefixo escolhido (pula faixas, rede e broadcast).
const props = defineProps<{ ip?: IpRow | null; ports?: { id: number; name: string }[]; preset?: Record<string, unknown>; branchId?: number | null }>()
const emit = defineEmits<{ close: []; saved: [id: number] }>()
const ch = ref<IpamChoices | null>(null)
const prefixes = ref<PrefixRow[]>([])
const prefixId = ref<number | ''>('')
const hint = ref('')
void ipamChoices(true).then((c) => { ch.value = c })
void ipam.prefixes(props.branchId ? { branch: props.branchId } : {}).then((r) => { prefixes.value = r.items.filter((p) => p.status !== 'container') })
const i = props.ip
const initial: Record<string, unknown> = { address: i?.address ?? '', vrf_id: i?.vrf_id ?? '', port_id: i?.port_id ?? '', status: i?.status ?? 'active', role: i?.role ?? '',
  dns_name: i?.dns_name ?? '', description: i?.description ?? '', comments: i?.comments ?? '', make_primary: i?.is_primary ?? false, ...props.preset }
const fields = computed<FieldSpec[]>(() => [
  { key: 'address', label: 'Endereço', required: true, mono: true, placeholder: '192.168.10.20/24', hint: 'Sem máscara, usa a do prefixo que contém o IP.' },
  { key: 'vrf_id', label: 'VRF', type: 'select', options: (ch.value?.vrfs ?? []).map((v) => ({ value: v.id, label: v.name })), hint: 'Vazio = tabela global' },
  ...(props.ports ? [{ key: 'port_id', label: 'Interface', type: 'select' as const, options: props.ports.map((p) => ({ value: p.id, label: p.name })) }] : []),
  { key: 'status', label: 'Situação', type: 'select', required: true, options: ch.value?.ip_status ?? [] },
  { key: 'role', label: 'Papel', type: 'select', options: ch.value?.ip_roles ?? [] },
  { key: 'dns_name', label: 'Nome DNS', mono: true, placeholder: 'sw-core.matriz.local' },
  { key: 'description', label: 'Descrição', full: true },
  ...(props.ports ? [{ key: 'make_primary', label: 'IP primário do equipamento (gerência)', type: 'check' as const, full: true }] : []),
])
async function fill(v: Record<string, unknown>): Promise<void> {
  if (!prefixId.value) return
  const r = await ipam.nextIp(Number(prefixId.value))
  const p = prefixes.value.find((x) => x.id === prefixId.value)
  if (r.address) { v.address = r.address; v.vrf_id = p?.vrf_id ?? ''; hint.value = '' } else hint.value = 'Prefixo sem IP livre.'
}
</script>

<template>
  <SpecForm v-if="ch" :title="i ? `Editar ${i.address}` : 'Novo endereço IP'" :fields="fields" :initial="initial" :save="(v) => ipam.saveIp(i?.id ?? null, v)"
    :ok-text="i ? 'IP atualizado' : 'IP cadastrado'" @close="emit('close')" @saved="emit('saved', $event)">
    <template #default="{ values }">
      <div v-if="!i && prefixes.length" class="field full">
        <label for="ip-pfx">Pegar o próximo livre do prefixo</label>
        <div class="row">
          <select id="ip-pfx" v-model="prefixId" class="input"><option value="">—</option>
            <option v-for="p in prefixes" :key="p.id" :value="p.id">{{ p.prefix }}{{ p.vlan ? ` · VLAN ${p.vlan_vid} ${p.vlan}` : '' }}{{ p.description ? ` · ${p.description}` : '' }} ({{ p.utilization }}%)</option></select>
          <button type="button" class="btn" :disabled="!prefixId" @click="fill(values)">Próximo livre</button>
        </div>
        <span v-if="hint" class="err">{{ hint }}</span>
      </div>
    </template>
  </SpecForm>
</template>

<style scoped>
.row { display: flex; gap: 6px; }
.row .input { flex: 1; }
</style>
