<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import LoadState from '../../components/LoadState.vue'
import IpForm from '../../components/dcim/IpForm.vue'
import { IP_STATUS, ipam } from '../../lib/dcim'
import { notify } from '../../lib/toast'
import { useAsync } from '../../lib/useAsync'

const props = defineProps<{ id: string }>()
const router = useRouter()
const { data: ip, loading, error, reload } = useAsync(() => ipam.ip(Number(props.id)))
const editing = ref(false)
async function remove(): Promise<void> {
  if (!ip.value || !window.confirm(`Excluir ${ip.value.address}?`)) return
  await ipam.deleteIp(ip.value.id)
  notify('ok', 'IP excluído')
  await router.push('/ipam/ip-addresses')
}
</script>

<template>
  <div class="dc-page">
    <RouterLink to="/ipam/ip-addresses" class="dc-back"><AppIcon name="back" :size="16" /> Endereços IP</RouterLink>
    <LoadState :loading="loading" :error="error" :rows="4" @retry="reload">
      <template v-if="ip">
        <header class="dc-head">
          <div><h1 class="mono">{{ ip.address }} <span class="pill" :class="IP_STATUS[ip.status]?.cls">{{ IP_STATUS[ip.status]?.label }}</span>
            <span v-if="ip.is_primary" class="pill pill-accent">primário</span></h1>
            <p class="muted">{{ [ip.dns_name, ip.description].filter(Boolean).join(' · ') }}</p></div>
          <div class="actions">
            <button class="edit-only btn" type="button" @click="editing = true"><AppIcon name="edit" :size="15" /> Editar</button>
            <button class="edit-only btn btn-icon" type="button" aria-label="Excluir IP" @click="remove"><AppIcon name="trash" :size="15" /></button>
          </div>
        </header>
        <section class="panel">
          <dl class="dc-kv top">
            <dt>Equipamento</dt><dd><template v-if="ip.device_id"><RouterLink :to="`/dcim/devices/${ip.device_id}`" class="dc-link">{{ ip.device }}</RouterLink> · interface <span class="mono">{{ ip.port }}</span> · {{ ip.branch }}</template><template v-else-if="ip.vm_id">VM <RouterLink :to="`/virtualization/vms/${ip.vm_id}`" class="dc-link">{{ ip.vm }}</RouterLink> · interface <span class="mono">{{ ip.vm_interface }}</span></template><span v-else class="muted">sem interface</span></dd>
            <dt>Prefixo</dt><dd><RouterLink v-if="ip.prefix_id" :to="`/ipam/prefixes/${ip.prefix_id}`" class="dc-link mono">{{ ip.prefix }}</RouterLink><span v-else class="muted">fora de prefixo cadastrado</span></dd>
            <dt>Papel</dt><dd>{{ ip.role ?? '—' }}</dd>
            <template v-if="ip.comments"><dt>Observações</dt><dd class="pre">{{ ip.comments }}</dd></template>
          </dl>
        </section>
        <IpForm v-if="editing" :ip="ip" :ports="ip.port_id ? [{ id: ip.port_id, name: ip.port ?? '' }] : undefined" :vm-interfaces="ip.vm_interface_id ? [{ id: ip.vm_interface_id, name: ip.vm_interface ?? '' }] : undefined" @close="editing = false" @saved="editing = false; reload()" />
      </template>
    </LoadState>
  </div>
</template>

<style scoped>
.top { padding-top: 20px; }
.pre { white-space: pre-wrap; }
</style>
