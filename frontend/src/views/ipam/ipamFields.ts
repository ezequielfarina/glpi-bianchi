import type { FieldSpec } from '../../components/dcim/SpecForm.vue'
import type { IpamChoices, VlanRow } from '../../lib/dcim'

type Opt = { value: number; label: string }
export function prefixFields(ch: IpamChoices, branches: Opt[], vlans: VlanRow[]): FieldSpec[] {
  return [
    { key: 'prefix', label: 'Prefixo', required: true, mono: true, placeholder: '192.168.10.0/24' },
    { key: 'status', label: 'Situação', type: 'select', required: true, options: ch.prefix_status, hint: 'Contêiner agrupa sub-redes (ex.: o /16 da filial).' },
    { key: 'vrf_id', label: 'VRF', type: 'select', options: ch.vrfs.map((v) => ({ value: v.id, label: v.name })) },
    { key: 'branch_id', label: 'Filial', type: 'select', options: branches },
    { key: 'vlan_id', label: 'VLAN', type: 'select', options: vlans.map((v) => ({ value: v.id, label: `${v.vid} — ${v.name}${v.group ? ` (${v.group})` : ''}` })) },
    { key: 'role', label: 'Papel', placeholder: 'ex.: Dados, Voz, Câmeras, Gerência, Convidados' },
    { key: 'is_pool', label: 'Pool (todos os IPs utilizáveis, inclusive rede/broadcast)', type: 'check', full: true },
    { key: 'mark_utilized', label: 'Considerar 100% utilizado', type: 'check', full: true },
    { key: 'description', label: 'Descrição', full: true },
    { key: 'comments', label: 'Observações', type: 'textarea' },
  ]
}
export function rangeFields(ch: IpamChoices): FieldSpec[] {
  return [
    { key: 'start_address', label: 'Início', required: true, mono: true, placeholder: '192.168.10.100' },
    { key: 'end_address', label: 'Fim', required: true, mono: true, placeholder: '192.168.10.200' },
    { key: 'vrf_id', label: 'VRF', type: 'select', options: ch.vrfs.map((v) => ({ value: v.id, label: v.name })) },
    { key: 'status', label: 'Situação', type: 'select', required: true, options: ch.range_status },
    { key: 'role', label: 'Papel', placeholder: 'ex.: DHCP, impressoras, reservado' },
    { key: 'mark_utilized', label: 'Contar a faixa inteira como utilizada (pool DHCP)', type: 'check', full: true },
    { key: 'description', label: 'Descrição', full: true },
  ]
}
