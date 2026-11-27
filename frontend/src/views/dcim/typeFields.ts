import type { FieldSpec } from '../../components/dcim/SpecForm.vue'
import type { DeviceRole } from '../../lib/dcim'

export function typeFields(roles: DeviceRole[]): FieldSpec[] {
  return [
    { key: 'manufacturer', label: 'Fabricante', required: true, placeholder: 'ex.: Cisco, Furukawa, Ubiquiti' },
    { key: 'model', label: 'Modelo', required: true, placeholder: 'ex.: Catalyst 9200-48P' },
    { key: 'part_number', label: 'Part number', mono: true },
    { key: 'u_height', label: 'Altura (U)', placeholder: '1', hint: '0 = não ocupa U (tomada, AP, ONT). Aceita 0,5.' },
    { key: 'default_role_id', label: 'Função padrão', type: 'select', options: roles.map((r) => ({ value: r.id, label: r.name })) },
    { key: 'is_full_depth', label: 'Profundidade total (ocupa frente e traseira do rack)', type: 'check', full: true },
    { key: 'description', label: 'Descrição', full: true },
    { key: 'comments', label: 'Observações', type: 'textarea' },
  ]
}
