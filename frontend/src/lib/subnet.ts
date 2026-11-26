// Calculadora de sub-rede IPv4 (roda no navegador, sem servidor).
// Inteiros de 32 bits sem sinal via ">>> 0".

export interface Subnet {
  ip: number
  prefix: number
  mask: number
  wildcard: number
  network: number
  broadcast: number
  firstHost: number | null
  lastHost: number | null
  total: number
  usable: number
}

export interface IpInfo {
  klass: 'A' | 'B' | 'C' | 'D (multicast)' | 'E (reservada)'
  kind: string
  rfc: string | null
  scope: 'privado' | 'público' | 'especial'
}

export const toInt = (ip: string): number | null => {
  const m = ip.trim().match(/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/)
  if (!m) return null
  const o = m.slice(1).map(Number)
  if (o.some((n) => n > 255)) return null
  return ((o[0]! << 24) | (o[1]! << 16) | (o[2]! << 8) | o[3]!) >>> 0
}

export const toIp = (n: number): string => [n >>> 24, (n >>> 16) & 255, (n >>> 8) & 255, n & 255].join('.')

export const maskOf = (prefix: number): number => (prefix === 0 ? 0 : (0xffffffff << (32 - prefix)) >>> 0)

/** Máscara pontuada → prefixo (null se não for contígua). */
export function prefixOfMask(mask: string): number | null {
  const n = toInt(mask)
  if (n === null) return null
  const bits = n.toString(2).padStart(32, '0')
  return /^1*0*$/.test(bits) ? bits.indexOf('0') === -1 ? 32 : bits.indexOf('0') : null
}

/**
 * Aceita "192.168.1.10/24", "192.168.1.10 255.255.255.0", "192.168.1.10/255.255.255.0"
 * ou só o IP (usa a máscara da classe). Devolve null se inválido.
 */
export function parseInput(input: string, fallbackPrefix?: number): { ip: number; prefix: number } | null {
  const s = input.trim().replace(/\s+/g, ' ')
  const m = s.match(/^(\d{1,3}(?:\.\d{1,3}){3})(?:\s*[/ ]\s*(\d{1,2}|\d{1,3}(?:\.\d{1,3}){3}))?$/)
  if (!m) return null
  const ip = toInt(m[1]!)
  if (ip === null) return null
  let prefix: number | null
  if (m[2] === undefined) prefix = fallbackPrefix ?? classfulPrefix(ip)
  else if (m[2].includes('.')) prefix = prefixOfMask(m[2])
  else prefix = Number(m[2])
  if (prefix === null || prefix < 0 || prefix > 32) return null
  return { ip, prefix }
}

export function calc(ip: number, prefix: number): Subnet {
  const mask = maskOf(prefix)
  const network = (ip & mask) >>> 0
  const broadcast = (network | ~mask) >>> 0
  const total = 2 ** (32 - prefix)
  // /31 (RFC 3021, enlaces ponto a ponto): 2 hosts; /32: o próprio endereço
  const usable = prefix === 32 ? 1 : prefix === 31 ? 2 : total - 2
  const firstHost = prefix >= 31 ? network : network + 1
  const lastHost = prefix >= 31 ? broadcast : broadcast - 1
  return { ip, prefix, mask, wildcard: ~mask >>> 0, network, broadcast, firstHost, lastHost, total, usable }
}

export function classfulPrefix(ip: number): number {
  const first = ip >>> 24
  return first < 128 ? 8 : first < 192 ? 16 : 24
}

export function info(ip: number): IpInfo {
  const first = ip >>> 24
  const klass: IpInfo['klass'] = first < 128 ? 'A' : first < 192 ? 'B' : first < 224 ? 'C' : first < 240 ? 'D (multicast)' : 'E (reservada)'
  const inNet = (base: string, p: number) => ((ip & maskOf(p)) >>> 0) === toInt(base)
  const ranges: [string, number, string, string | null, IpInfo['scope']][] = [
    ['0.0.0.0', 8, '"Esta rede" (origem não definida)', 'RFC 1122', 'especial'],
    ['10.0.0.0', 8, 'Privado (rede interna)', 'RFC 1918', 'privado'],
    ['100.64.0.0', 10, 'CGNAT (NAT de operadora)', 'RFC 6598', 'especial'],
    ['127.0.0.0', 8, 'Loopback (a própria máquina)', 'RFC 1122', 'especial'],
    ['169.254.0.0', 16, 'Link-local / APIPA (DHCP falhou)', 'RFC 3927', 'especial'],
    ['172.16.0.0', 12, 'Privado (rede interna)', 'RFC 1918', 'privado'],
    ['192.0.0.0', 24, 'Atribuições de protocolo IETF', 'RFC 6890', 'especial'],
    ['192.0.2.0', 24, 'Documentação (TEST-NET-1)', 'RFC 5737', 'especial'],
    ['192.88.99.0', 24, '6to4 relay anycast (obsoleto)', 'RFC 7526', 'especial'],
    ['192.168.0.0', 16, 'Privado (rede interna)', 'RFC 1918', 'privado'],
    ['198.18.0.0', 15, 'Testes de desempenho (benchmark)', 'RFC 2544', 'especial'],
    ['198.51.100.0', 24, 'Documentação (TEST-NET-2)', 'RFC 5737', 'especial'],
    ['203.0.113.0', 24, 'Documentação (TEST-NET-3)', 'RFC 5737', 'especial'],
    ['224.0.0.0', 4, 'Multicast', 'RFC 5771', 'especial'],
    ['255.255.255.255', 32, 'Broadcast limitado', 'RFC 919', 'especial'],
    ['240.0.0.0', 4, 'Reservado (uso futuro)', 'RFC 1112', 'especial'],
  ]
  for (const [base, p, kind, rfc, scope] of ranges) {
    if (inNet(base, p)) return { klass, kind, rfc, scope }
  }
  return { klass, kind: 'Público (roteável na internet)', rfc: null, scope: 'público' }
}

export const toBin = (n: number): string => n.toString(2).padStart(32, '0')
export const toBinDotted = (n: number): string => toBin(n).match(/.{8}/g)!.join('.')
export const toHex = (n: number): string => '0x' + n.toString(16).toUpperCase().padStart(8, '0')

/** Zona de DNS reverso (in-addr.arpa) mais específica que cobre a rede. */
export function reverseZone(s: Subnet): string {
  const octets = toIp(s.network).split('.')
  const keep = Math.max(1, Math.floor(s.prefix / 8))
  const zone = octets.slice(0, keep).reverse().join('.') + '.in-addr.arpa'
  return s.prefix % 8 === 0 || s.prefix > 24 ? zone : `${zone} (prefixo /${s.prefix} não alinhado a octeto: delegue as zonas /${Math.ceil(s.prefix / 8) * 8})`
}

/** Divide a rede em sub-redes de `newPrefix` (máx. `limit` linhas). */
export function split(s: Subnet, newPrefix: number, limit = 512): { items: Subnet[]; count: number } {
  if (newPrefix < s.prefix || newPrefix > 32) return { items: [], count: 0 }
  const count = 2 ** (newPrefix - s.prefix)
  const size = 2 ** (32 - newPrefix)
  const items: Subnet[] = []
  for (let i = 0; i < Math.min(count, limit); i++) items.push(calc((s.network + i * size) >>> 0, newPrefix))
  return { items, count }
}

/** Sub-rede de mesmo tamanho anterior/seguinte. */
export function neighbor(s: Subnet, dir: -1 | 1): Subnet | null {
  const next = s.network + dir * s.total
  if (next < 0 || next > 0xffffffff) return null
  return calc(next >>> 0, s.prefix)
}

/** Menor prefixo que comporta `hosts` endereços utilizáveis. */
export function prefixForHosts(hosts: number): number | null {
  for (let p = 32; p >= 0; p--) if (calc(0, p).usable >= hosts) return p
  return null
}

export const fmt = (n: number) => n.toLocaleString('pt-BR')
