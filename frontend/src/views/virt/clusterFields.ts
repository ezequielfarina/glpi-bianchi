import type { FieldSpec } from '../../components/dcim/SpecForm.vue'
import type { VirtChoices } from '../../lib/virt'

type Opt = { value: number; label: string }
export function clusterFields(ch: VirtChoices, branches: Opt[], locations: Opt[]): FieldSpec[] {
  return [
    { key: 'name', label: 'Nome', required: true, placeholder: 'PVE-MATRIZ' },
    { key: 'type_id', label: 'Plataforma', type: 'lookup', source: 'cluster_types', required: true, placeholder: 'Proxmox VE, VMware ESXi…' },
    { key: 'status', label: 'Situação', type: 'select', required: true, options: ch.cluster_status },
    { key: 'version', label: 'Versão', placeholder: '8.3' },
    { key: 'management_url', label: 'URL da console', full: true, mono: true, placeholder: 'https://pve01.matriz.local:8006' },
    { key: 'branch_id', label: 'Filial', type: 'select', options: branches },
    { key: 'location_id', label: 'Local', type: 'select', options: locations },
    { key: 'group_id', label: 'Grupo', type: 'lookup', source: 'cluster_groups', placeholder: 'ex.: Produção' },
    { key: 'description', label: 'Descrição', full: true },
    { key: 'comments', label: 'Observações', type: 'textarea' },
  ]
}
