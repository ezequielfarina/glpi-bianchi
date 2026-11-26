import type { IconName } from '../components/AppIcon.vue'

/** Ícone e cor de cada tipo de ativo — mesmos desenhos do cadastro ("Novo ativo"). */
export const TYPE_ICON: Record<string, IconName> = {
  computer: 'pc', monitor: 'monitor', printer: 'printer', phone: 'phone', tablet: 'tablet', data_collector: 'barcode',
  network_equipment: 'network', peripheral: 'box', pdu: 'bolt', rack: 'box', enclosure: 'box', unmanaged: 'box',
}

/** Matiz (HSL) por tipo: o mesmo tipo tem sempre a mesma cor em listas, detalhe e filtros. */
const TYPE_HUE: Record<string, number> = {
  computer: 200, monitor: 265, printer: 30, phone: 150, tablet: 175, data_collector: 330,
  network_equipment: 95, peripheral: 220, pdu: 50, rack: 0, enclosure: 0, unmanaged: 0,
}

export const typeIcon = (code: string | null | undefined): IconName => TYPE_ICON[code ?? ''] ?? 'box'

/** Variável CSS --type-hue para os estilos .type-badge / .type-chip (styles.css). */
export const typeStyle = (code: string | null | undefined): Record<string, string> => ({ '--type-hue': String(TYPE_HUE[code ?? ''] ?? 210) })

/** Ordem e rótulos no plural para os filtros. */
export const TYPE_FILTERS: { code: string; label: string }[] = [
  { code: 'computer', label: 'Computadores' },
  { code: 'printer', label: 'Impressoras' },
  { code: 'monitor', label: 'Monitores' },
  { code: 'phone', label: 'Telefones' },
  { code: 'tablet', label: 'Tablets' },
  { code: 'data_collector', label: 'Coletores' },
  { code: 'network_equipment', label: 'Rede' },
  { code: 'peripheral', label: 'Periféricos' },
]
