import type { FieldSpec } from '../../components/dcim/SpecForm.vue'

type Opt = { value: number | string; label: string }
export function circuitFields(suppliers: Opt[], _types: Opt[], contracts: Opt[], status: Opt[]): FieldSpec[] {
  return [
    { key: 'supplier_id', label: 'Operadora (fornecedor)', type: 'select', required: true, options: suppliers },
    { key: 'cid', label: 'Designação / ID do circuito', required: true, mono: true, placeholder: 'como aparece na fatura/contrato' },
    { key: 'type_id', label: 'Tipo', type: 'lookup', source: 'circuit_types', required: true, placeholder: 'Internet dedicada, MPLS…' },
    { key: 'status', label: 'Situação', type: 'select', required: true, options: status },
    { key: 'commit_mbps', label: 'Banda contratada (Mbps)', type: 'number' },
    { key: 'contract_id', label: 'Contrato', type: 'select', options: contracts },
    { key: 'install_date', label: 'Ativação', type: 'date' },
    { key: 'termination_date', label: 'Desativação / fim', type: 'date' },
    { key: 'description', label: 'Descrição', full: true },
    { key: 'comments', label: 'Observações (NOC, protocolo de chamado, IP do link…)', type: 'textarea' },
  ]
}
export function toBody(v: Record<string, unknown>): Record<string, unknown> {
  const mbps = v.commit_mbps === null || v.commit_mbps === '' || v.commit_mbps === undefined ? null : Math.round(Number(v.commit_mbps) * 1000)
  const { commit_mbps: _drop, ...rest } = v
  void _drop
  return { ...rest, commit_rate_kbps: mbps }
}
export const termFields = (branches: Opt[], locations: Opt[]): FieldSpec[] => [
  { key: 'branch_id', label: 'Filial onde chega', type: 'select', options: branches, hint: 'Ou preencha a rede da operadora (outro lado do link).' },
  { key: 'location_id', label: 'Local (sala/CPD)', type: 'select', options: locations },
  { key: 'provider_network', label: 'Rede da operadora', placeholder: 'ex.: Backbone Vivo, Internet' },
  { key: 'xconnect_id', label: 'ID de cross-connect / DG', mono: true },
  { key: 'pp_info', label: 'Patch panel / porta de entrega', placeholder: 'ex.: DIO-01 bandeja 1 fibra 3' },
  { key: 'port_speed_kbps', label: 'Velocidade de download (kbps)', type: 'number' },
  { key: 'upstream_speed_kbps', label: 'Velocidade de upload (kbps)', type: 'number' },
  { key: 'description', label: 'Descrição', full: true },
]
