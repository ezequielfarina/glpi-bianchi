<script setup lang="ts">
import { safeHref } from '../../lib/safeUrl'
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import AttachmentsPanel from '../../components/AttachmentsPanel.vue'
import LoadState from '../../components/LoadState.vue'
import IpForm from '../../components/dcim/IpForm.vue'
import PhotoGallery from '../../components/dcim/PhotoGallery.vue'
import SpecForm from '../../components/dcim/SpecForm.vue'
import PathToInternet from '../../components/virt/PathToInternet.vue'
import ResourceRing from '../../components/virt/ResourceRing.vue'
import VmFormModal from '../../components/virt/VmFormModal.vue'
import VmInterfaceModal from '../../components/virt/VmInterfaceModal.vue'
import { ipam, IP_STATUS } from '../../lib/dcim'
import { notify } from '../../lib/toast'
import { useAsync } from '../../lib/useAsync'
import { gb, num, platformBadge, virt, VM_STATUS, type VmDisk, type VmInterface } from '../../lib/virt'

const props = defineProps<{ id: string }>()
const router = useRouter()
const { data: v, loading, error, reload } = useAsync(() => virt.vm(Number(props.id)))
const editing = ref(false)
const iface = ref<VmInterface | 'new' | null>(null)
const disk = ref<VmDisk | 'new' | null>(null)
const addIp = ref<{ vm_interface_id?: number } | null>(null)
const pb = computed(() => platformBadge(v.value?.cluster_type))
const diskFields = [
  { key: 'name', label: 'Nome', required: true, placeholder: 'scsi0' },
  { key: 'size_gb', label: 'Tamanho (GB)', type: 'number' as const, required: true },
  { key: 'storage', label: 'Storage / datastore', placeholder: 'local-lvm' },
  { key: 'description', label: 'Descrição', full: true },
]
async function remove(): Promise<void> {
  if (!v.value || !window.confirm(`Excluir a VM ${v.value.name}?`)) return
  await virt.deleteVm(v.value.id)
  notify('ok', 'VM excluída')
  await router.push('/virtualization/vms')
}
async function removeIface(i: VmInterface): Promise<void> {
  if (!window.confirm(`Excluir a interface ${i.name}? Os IPs dela ficam sem interface.`)) return
  await virt.deleteInterface(i.id)
  await reload()
}
async function removeDisk(d: VmDisk): Promise<void> {
  if (!window.confirm(`Excluir o disco ${d.name}?`)) return
  await virt.deleteDisk(d.id)
  await reload()
}
async function removeIp(id: number): Promise<void> {
  if (!window.confirm('Excluir este IP?')) return
  await ipam.deleteIp(id)
  await reload()
}
</script>

<template>
  <div class="dc-page">
    <RouterLink to="/virtualization/vms" class="dc-back"><AppIcon name="back" :size="16" /> Máquinas virtuais</RouterLink>
    <LoadState :loading="loading" :error="error" :rows="8" @retry="reload">
      <template v-if="v">
        <header class="hero" :style="{ '--plat': pb.color }">
          <div class="screen"><span class="led" :class="v.status" /><AppIcon name="vm" :size="30" /></div>
          <div class="ht">
            <p class="muted"><b class="plat">{{ pb.short }}</b> <RouterLink :to="`/virtualization/clusters/${v.cluster_id}`" class="dc-link">{{ v.cluster }}</RouterLink>
              <template v-if="v.host"> › <RouterLink :to="`/dcim/devices/${v.device_id}`" class="dc-link">{{ v.host }}</RouterLink></template></p>
            <h1>{{ v.name }} <span class="pill" :class="VM_STATUS[v.status]?.cls">{{ VM_STATUS[v.status]?.label }}</span><span v-if="v.vmid" class="pill mono">VMID {{ v.vmid }}</span></h1>
            <p class="muted">{{ [v.platform, v.primary_ip, v.description].filter(Boolean).join(' · ') }}</p>
          </div>
          <div class="actions">
            <a v-if="v.management_url" :href="safeHref(v.management_url)" target="_blank" rel="noopener" class="btn"><AppIcon name="external" :size="15" /> Console</a>
            <button class="edit-only btn" type="button" @click="editing = true"><AppIcon name="edit" :size="15" /> Editar</button>
            <button class="edit-only btn btn-icon" type="button" aria-label="Excluir VM" @click="remove"><AppIcon name="trash" :size="15" /></button>
          </div>
          <div class="res">
            <ResourceRing label="vCPU" :used="v.vcpus ?? 0" :size="92" />
            <ResourceRing label="RAM" :used="v.memory_mb ?? 0" :format="gb" :size="92" />
            <ResourceRing label="Disco" :used="v.disk_gb ?? 0" unit="GB" :size="92" />
          </div>
        </header>
        <div class="dc-cols">
          <div>
            <section class="panel">
              <div class="panel-head"><h2><AppIcon name="route" :size="17" /> Caminho até a internet</h2><span class="muted dc-small">VM → virtualizador → servidor → switches → firewall → operadora</span></div>
              <PathToInternet :device-id="v.device_id" :vm="v" @edit-vm="editing = true" />
            </section>
            <section class="panel">
              <div class="panel-head"><h2>Interfaces de rede <span class="muted">({{ v.interfaces_list.length }})</span></h2>
                <button class="edit-only btn" type="button" @click="iface = 'new'"><AppIcon name="plus" :size="15" /> Interface</button></div>
              <p v-if="!v.interfaces_list.length" class="empty">Nenhuma interface. Adicione net0 (bridge vmbr0) e o IP dela.</p>
              <div v-else class="table-wrap"><table class="table">
                <thead><tr><th>Interface</th><th>Bridge</th><th>MAC</th><th>VLAN</th><th>IPs</th><th /></tr></thead>
                <tbody><tr v-for="i in v.interfaces_list" :key="i.id" :class="{ off: !i.enabled }">
                  <td class="mono strong">{{ i.name }}</td><td class="mono">{{ i.bridge }}</td><td class="mono muted">{{ i.mac_address }}</td>
                  <td class="dc-small">{{ i.mode ? (i.mode === 'access' ? 'acesso' : 'tronco') : '' }}{{ i.untagged_vid ? ` ${i.untagged_vid} ${i.untagged_vlan}` : '' }}{{ i.tagged_vids ? ` · tagged ${i.tagged_vids}` : '' }}</td>
                  <td class="mono dc-small">{{ i.ips }}</td>
                  <td class="dc-actions-row">
                    <button class="edit-only btn btn-icon" type="button" :aria-label="`Adicionar IP em ${i.name}`" title="Adicionar IP" @click="addIp = { vm_interface_id: i.id }"><AppIcon name="hash" :size="14" /></button>
                    <button class="edit-only btn btn-icon" type="button" :aria-label="`Editar ${i.name}`" @click="iface = i"><AppIcon name="edit" :size="14" /></button>
                    <button class="edit-only btn btn-icon" type="button" :aria-label="`Excluir ${i.name}`" @click="removeIface(i)"><AppIcon name="trash" :size="14" /></button></td>
                </tr></tbody>
              </table></div>
            </section>
            <section class="panel">
              <div class="panel-head"><h2>Discos virtuais <span class="muted">({{ v.disks.length }})</span></h2>
                <button class="edit-only btn" type="button" @click="disk = 'new'"><AppIcon name="plus" :size="15" /> Disco</button></div>
              <p v-if="!v.disks.length" class="empty">Sem discos detalhados (o total informado na VM vale).</p>
              <div v-else class="disks">
                <div v-for="d in v.disks" :key="d.id" class="disk">
                  <div class="dtop"><strong class="mono">{{ d.name }}</strong><span class="muted dc-small">{{ d.storage }}</span>
                    <span class="acts"><button class="edit-only btn btn-icon" type="button" :aria-label="`Editar ${d.name}`" @click="disk = d"><AppIcon name="edit" :size="13" /></button>
                      <button class="edit-only btn btn-icon" type="button" :aria-label="`Excluir ${d.name}`" @click="removeDisk(d)"><AppIcon name="trash" :size="13" /></button></span></div>
                  <div class="dbar"><i :style="{ width: `${Math.max(4, (100 * d.size_gb) / Math.max(1, v.disk_gb ?? d.size_gb))}%` }" /></div>
                  <span class="mono dc-small">{{ d.size_gb }} GB</span>
                </div>
              </div>
            </section>
          </div>
          <div>
            <section class="panel">
              <div class="panel-head"><h2>Dados</h2></div>
              <dl class="dc-kv">
                <dt>Virtualizador</dt><dd><RouterLink :to="`/virtualization/clusters/${v.cluster_id}`" class="dc-link">{{ v.cluster }}</RouterLink> ({{ v.cluster_type }})</dd>
                <dt>Host físico</dt><dd><RouterLink v-if="v.device_id" :to="`/dcim/devices/${v.device_id}`" class="dc-link">{{ v.host }}</RouterLink><span v-else class="muted">não definido</span></dd>
                <dt>Filial</dt><dd>{{ v.branch ?? '—' }}</dd>
                <dt>Recursos</dt><dd>{{ num(v.vcpus) }} vCPU · {{ gb(v.memory_mb) }} · {{ v.disk_gb ?? '—' }} GB</dd>
                <dt>Ativo (inventário)</dt><dd><RouterLink v-if="v.asset_uuid" :to="`/assets/${v.asset_uuid}`" class="dc-link"><AppIcon name="pc" :size="13" /> {{ v.asset_name }}</RouterLink><span v-else class="muted">não vinculado</span></dd>
                <template v-if="v.comments"><dt>Observações</dt><dd class="pre">{{ v.comments }}</dd></template>
              </dl>
            </section>
            <section class="panel">
              <div class="panel-head"><h2>Endereços IP <span class="muted">({{ v.ips.length }})</span></h2>
                <button v-if="v.interfaces_list.length" class="edit-only btn" type="button" @click="addIp = {}"><AppIcon name="plus" :size="15" /> IP</button></div>
              <p v-if="!v.ips.length" class="empty">Nenhum IP.</p>
              <ul v-else class="ips"><li v-for="ip in v.ips" :key="ip.id"><span class="mono strong">{{ ip.address }}</span> <span class="muted dc-small">{{ ip.interface }}{{ ip.dns_name ? ' · ' + ip.dns_name : '' }}</span>
                <span v-if="ip.id === v.primary_ip_id" class="pill pill-accent">primário</span><span v-if="ip.status !== 'active'" class="pill" :class="IP_STATUS[ip.status]?.cls">{{ IP_STATUS[ip.status]?.label }}</span>
                <button class="edit-only btn btn-icon" type="button" :aria-label="`Excluir ${ip.address}`" @click="removeIp(ip.id)"><AppIcon name="trash" :size="13" /></button></li></ul>
            </section>
            <PhotoGallery owner-type="vm" :owner="v.id" :items="v.attachments" title="Capturas de tela" @changed="reload" />
            <AttachmentsPanel owner-type="vm" :owner="v.id" :items="v.attachments.filter((a) => a.kind === 'document')" title="Documentos" @changed="reload" />
          </div>
        </div>
        <VmFormModal v-if="editing" :vm="v" @close="editing = false" @saved="editing = false; reload()" />
        <VmInterfaceModal v-if="iface" :vm-id="v.id" :iface="iface === 'new' ? null : iface" @close="iface = null" @saved="iface = null; reload()" />
        <SpecForm v-if="disk" ctx="disk" :title="disk === 'new' ? 'Novo disco virtual' : `Disco ${disk.name}`" :fields="diskFields"
          :initial="disk === 'new' ? { name: `scsi${v.disks.length}` } : { ...disk }" :save="(b) => virt.saveDisk(v!.id, disk === 'new' ? null : (disk as VmDisk).id, b)" ok-text="Disco salvo"
          @close="disk = null" @saved="disk = null; reload()" />
        <IpForm v-if="addIp" :vm-interfaces="v.interfaces_list.map((i) => ({ id: i.id, name: i.name }))" :preset="addIp" @close="addIp = null" @saved="addIp = null; reload()" />
      </template>
    </LoadState>
  </div>
</template>

<style scoped>
.hero { display: grid; grid-template-columns: auto 1fr auto; grid-template-areas: 'scr ht act' 'scr res res'; gap: 14px 18px; padding: 22px; border-radius: 22px; border: 1px solid var(--border);
  background: radial-gradient(90% 120% at 0% 0%, color-mix(in srgb, #8e24aa 24%, transparent), transparent 55%), radial-gradient(70% 90% at 100% 0%, color-mix(in srgb, var(--plat) 18%, transparent), transparent 60%), var(--panel); }
.screen { grid-area: scr; position: relative; width: 84px; height: 64px; border-radius: 12px; display: grid; place-items: center; color: #e9d5ff;
  background: linear-gradient(160deg, #3b0764, #1e1b4b); box-shadow: 0 18px 34px -14px #8e24aa, inset 0 0 0 1px rgba(255,255,255,.08); transform: perspective(400px) rotateY(14deg) rotateX(6deg); }
.screen .led { position: absolute; top: 8px; right: 8px; width: 8px; height: 8px; border-radius: 50%; background: #64748b; }
.screen .led.active { background: #22c55e; box-shadow: 0 0 10px #22c55e; }
.ht { grid-area: ht; min-width: 0; }
.ht h1 { margin: 0; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.ht p { margin: 2px 0; font-size: 13px; }
.plat { color: #fff; font-size: 10px; padding: 1px 6px; border-radius: 5px; background: var(--plat); }
.hero .actions { grid-area: act; display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; align-self: start; }
.res { grid-area: res; display: flex; gap: 28px; flex-wrap: wrap; }
@media (max-width: 760px) { .hero { grid-template-columns: auto 1fr; grid-template-areas: 'scr ht' 'act act' 'res res'; } }
.panel-head h2 { display: inline-flex; gap: 8px; align-items: center; }
.empty { padding: 0 20px 16px; margin: 0; font-size: 13.5px; }
.strong { font-weight: 600; }
tr.off td { opacity: .55; }
.disks { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px; padding: 0 20px 20px; }
.disk { border: 1px solid var(--border); border-radius: 14px; padding: 10px 12px; display: flex; flex-direction: column; gap: 6px; background: var(--bg-elev); }
.dtop { display: flex; align-items: center; gap: 8px; }
.dtop .acts { margin-left: auto; display: flex; gap: 2px; }
.dbar { height: 8px; border-radius: 4px; background: var(--muted-soft); overflow: hidden; }
.dbar i { display: block; height: 100%; background: linear-gradient(90deg, #8e24aa, var(--accent)); }
.ips { list-style: none; margin: 0; padding: 0 20px 16px; display: flex; flex-direction: column; gap: 6px; font-size: 13.5px; }
.ips li { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.ips li .btn { margin-left: auto; }
.pre { white-space: pre-wrap; }
</style>
