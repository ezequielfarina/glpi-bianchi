import type { FieldSpec } from '../../components/dcim/SpecForm.vue'

/** Campos do formulário de rack (a filial e o local vêm do contexto; status/largura com opções fixas). */
export function rackFields(opts: { branches?: { value: number; label: string }[]; locations?: { value: number; label: string }[] } = {}): FieldSpec[] {
  return [
    { key: 'name', label: 'Nome', required: true, placeholder: 'ex.: RACK-CPD-01' },
    ...(opts.branches ? [{ key: 'branch_id', label: 'Filial', type: 'select' as const, required: true, options: opts.branches }] : []),
    ...(opts.locations ? [{ key: 'location_id', label: 'Local', type: 'select' as const, options: opts.locations }] : []),
    { key: 'status', label: 'Situação', type: 'select', required: true, options: [
      { value: 'active', label: 'Ativo' }, { value: 'planned', label: 'Planejado' }, { value: 'reserved', label: 'Reservado' }, { value: 'available', label: 'Disponível' }, { value: 'deprecated', label: 'Obsoleto' }] },
    { key: 'u_height', label: 'Altura (U)', type: 'number', required: true },
    { key: 'width', label: 'Largura', type: 'select', required: true, options: [10, 19, 21, 23].map((w) => ({ value: w, label: `${w}"` })) },
    { key: 'starting_unit', label: 'Primeira U', type: 'number' },
    { key: 'outer_depth_mm', label: 'Profundidade (mm)', type: 'number' },
    { key: 'role', label: 'Função', placeholder: 'ex.: Rede, Servidores, Telecom' },
    { key: 'facility_id', label: 'Identificação no local', mono: true },
    { key: 'serial', label: 'Número de série', mono: true },
    { key: 'asset_tag', label: 'Patrimônio', mono: true },
    { key: 'desc_units', label: 'Numeração de cima para baixo', type: 'check' },
    { key: 'description', label: 'Descrição', full: true },
    { key: 'comments', label: 'Observações', type: 'textarea' },
  ]
}
