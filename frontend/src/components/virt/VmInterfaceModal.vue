<script setup lang="ts">
import { reactive, ref } from 'vue'
import AppModal from '../AppModal.vue'
import HelpTip from '../HelpTip.vue'
import { ipam, type VlanRow } from '../../lib/dcim'
import { virt, type VmInterface } from '../../lib/virt'
import { clean, useForm } from '../forms/useForm'

const props = defineProps<{ vmId: number; iface?: VmInterface | null }>()
const emit = defineEmits<{ close: []; saved: [] }>()
const { saving, errors, submit } = useForm()
const i = props.iface
const f = reactive({ name: i?.name ?? 'net0', bridge: i?.bridge ?? '', mac_address: i?.mac_address ?? '', mtu: i?.mtu ?? ('' as number | ''), enabled: i?.enabled ?? true,
  mode: i?.mode ?? '', untagged_vlan_id: i?.untagged_vlan_id ?? ('' as number | ''), tagged_vlan_ids: [...(i?.tagged_vlan_ids ?? [])] as number[], description: i?.description ?? '' })
const vlans = ref<VlanRow[]>([])
void ipam.vlans().then((r) => { vlans.value = r.items })
async function save(): Promise<void> {
  const body = clean({ ...f, tagged_vlan_ids: f.tagged_vlan_ids })
  const r = await submit(() => (i ? virt.saveInterface(i.id, body) : virt.addInterfaces(props.vmId, body).then((x) => ({ id: x.ids[0] ?? 0 }))), i ? 'Interface atualizada' : 'Interface criada')
  if (r) emit('saved')
}
</script>

<template>
  <AppModal :title="i ? `Interface ${i.name}` : 'Nova interface de rede da VM'" @close="emit('close')">
    <form class="form-grid" novalidate @submit.prevent="save">
      <div class="field"><label for="vi-name">Nome *<HelpTip ctx="vm_iface" k="name" label="Nome" /></label><input id="vi-name" v-model="f.name" class="input mono" :aria-invalid="!!errors.name" />
        <span v-if="errors.name" class="err">{{ errors.name }}</span></div>
      <div class="field"><label for="vi-br">Bridge / port group<HelpTip ctx="vm_iface" k="bridge" label="Bridge" /></label><input id="vi-br" v-model="f.bridge" class="input mono" placeholder="vmbr0" /></div>
      <div class="field"><label for="vi-mac">MAC<HelpTip ctx="port" k="mac_address" label="MAC" /></label><input id="vi-mac" v-model="f.mac_address" class="input mono" placeholder="BC:24:11:AA:BB:CC" :aria-invalid="!!errors.mac_address" />
        <span v-if="errors.mac_address" class="err">{{ errors.mac_address }}</span></div>
      <div class="field"><label for="vi-mtu">MTU<HelpTip ctx="vm_iface" k="mtu" label="MTU" /></label><input id="vi-mtu" v-model="f.mtu" class="input" type="number" placeholder="1500" /></div>
      <div class="field"><label for="vi-mode">Modo 802.1Q<HelpTip ctx="port" k="mode" label="Modo" /></label>
        <select id="vi-mode" v-model="f.mode" class="input"><option value="">—</option><option value="access">Acesso (tag da VLAN na bridge)</option><option value="tagged">Tronco (tagged)</option><option value="tagged-all">Tronco (todas)</option></select></div>
      <div v-if="f.mode" class="field"><label for="vi-uv">VLAN untagged</label>
        <select id="vi-uv" v-model="f.untagged_vlan_id" class="input"><option value="">—</option><option v-for="v in vlans" :key="v.id" :value="v.id">{{ v.vid }} — {{ v.name }}</option></select></div>
      <div v-if="f.mode === 'tagged'" class="field full"><label>VLANs tagged</label>
        <div class="vchips"><label v-for="v in vlans" :key="v.id" class="tchip" :class="{ on: f.tagged_vlan_ids.includes(v.id) }"><input v-model="f.tagged_vlan_ids" type="checkbox" :value="v.id" class="sr-only" />{{ v.vid }} {{ v.name }}</label></div></div>
      <label class="check full"><input v-model="f.enabled" type="checkbox" /> Habilitada</label>
      <div class="field full"><label for="vi-desc">Descrição</label><input id="vi-desc" v-model="f.description" class="input" /></div>
      <div class="form-actions full"><button class="btn" type="button" @click="emit('close')">Cancelar</button>
        <button class="btn btn-primary" type="submit" :disabled="saving">{{ saving ? 'Salvando…' : 'Salvar' }}</button></div>
    </form>
  </AppModal>
</template>

<style scoped>
.vchips { display: flex; flex-wrap: wrap; gap: 6px; }
.vchips .tchip { cursor: pointer; }
</style>
