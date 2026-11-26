import type { IconName } from '../components/AppIcon.vue'

/** Tipos que o scanner reconhece → rótulo, ícone, cor (mesma lógica de matiz dos ativos) e tipo de ativo. */
export const SCAN_TYPE: Record<string, { label: string; icon: IconName; hue: number; asset: string }> = {
  computer: { label: 'Computador', icon: 'pc', hue: 200, asset: 'computer' },
  server: { label: 'Servidor', icon: 'server', hue: 220, asset: 'computer' },
  printer: { label: 'Impressora', icon: 'printer', hue: 30, asset: 'printer' },
  network_equipment: { label: 'Rede', icon: 'network', hue: 95, asset: 'network_equipment' },
  phone: { label: 'Telefone IP', icon: 'phone', hue: 150, asset: 'phone' },
  mobile: { label: 'Celular/tablet', icon: 'tablet', hue: 175, asset: 'phone' },
  camera: { label: 'Câmera', icon: 'camera', hue: 330, asset: 'peripheral' },
  iot: { label: 'IoT', icon: 'bolt', hue: 50, asset: 'peripheral' },
  unknown: { label: 'Desconhecido', icon: 'box', hue: 210, asset: 'peripheral' },
}

export const scanType = (t: string) => SCAN_TYPE[t] ?? SCAN_TYPE.unknown!
export const scanTypeStyle = (t: string): Record<string, string> => ({ '--type-hue': String(scanType(t).hue) })

export const MAC_SOURCE: Record<string, string> = {
  arp: 'ARP (mesmo segmento)',
  nbstat: 'NetBIOS',
  snmp: 'tabela ARP do gateway (SNMP)',
  inventory: 'inventário do agente',
}

export const PROFILE_INFO: Record<string, { title: string; text: string; time: string }> = {
  discovery: {
    title: 'Descoberta rápida',
    text: 'Quem está ligado: IP, MAC, fabricante, nome DNS e NetBIOS. Não varre portas.',
    time: 'segundos (até /20)',
  },
  standard: {
    title: 'Padrão',
    text: '+ 200 portas mais comuns, serviços e versões, sistema operacional, títulos web, certificados, SMB/RDP.',
    time: '1–5 min por /24 (até /22)',
  },
  full: {
    title: 'Completo',
    text: '+ portas 1–1024 e serviços de infraestrutura, UDP (DNS, SNMP, NTP, UPnP…), scripts de detalhes.',
    time: '10–40 min por /24 (até /24)',
  },
}
