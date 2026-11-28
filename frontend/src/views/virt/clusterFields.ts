import type { FieldSpec } from '../../components/dcim/SpecForm.vue'
import type { VirtChoices } from '../../lib/virt'

type Opt = { value: number; label: string }
export function clusterFields(ch: VirtChoices, branches: Opt[], locations: Opt[]): FieldSpec[] {
  return [
    { key: 'name', label: 'Nome', required: true, placeholder: 'PVE-MATRIZ' },
    { key: 'type_id', label: 'Plataforma', type: 'select', required: true, options: ch.types.map((t) => ({ value: t.id, label: t.name })) },
    { key: 'status', label: 'Situação', type: 'select', required: true, options: ch.cluster_status },
    { key: 'version', label: 'Versão', placeholder: '8.3' },
    { key: 'management_url', label: 'URL da console', full: true, mono: true, placeholder: 'https://pve01.matriz.local:8006' },
    { key: 'branch_id', label: 'Filial', type: 'select', options: branches },
    { key: 'location_id', label: 'Local', type: 'select', options: locations },
    { key: 'group_id', label: 'Grupo', type: 'select', options: ch.groups.map((g) => ({ value: g.id, label: g.name })) },
    { key: 'description', label: 'Descrição', full: true },
    { key: 'comments', label: 'Observações', type: 'textarea' },
  ]
}
