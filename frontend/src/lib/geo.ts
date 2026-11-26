// Endereço e coordenadas das filiais.
// Consultas externas partem do navegador do usuário (ViaCEP e Nominatim/OSM aceitam CORS);
// o servidor do CT não precisa de acesso à internet.

export interface LatLng { lat: number; lng: number }

export interface CepAddress { street: string; district: string; city: string; state: string; complement: string }

/** Busca o endereço pelo CEP (ViaCEP). null se não encontrado. */
export async function lookupCep(cep: string): Promise<CepAddress | null> {
  const digits = cep.replace(/\D/g, '')
  if (digits.length !== 8) return null
  const r = await fetch(`https://viacep.com.br/ws/${digits}/json/`, { referrerPolicy: 'no-referrer' })
  if (!r.ok) return null
  const j = (await r.json()) as Record<string, string | boolean>
  if (j.erro) return null
  return {
    street: String(j.logradouro ?? ''), district: String(j.bairro ?? ''), city: String(j.localidade ?? ''),
    state: String(j.uf ?? ''), complement: String(j.complemento ?? ''),
  }
}

/**
 * Geocodifica um endereço (Nominatim/OpenStreetMap). Uso manual, 1 consulta por clique
 * (política de uso do Nominatim: no máximo 1 req/s, sem uso em massa).
 */
export async function geocode(parts: { street?: string | null; number?: string | null; city?: string | null; state?: string | null; zip?: string | null }): Promise<LatLng | null> {
  const params = new URLSearchParams({ format: 'jsonv2', limit: '1', countrycodes: 'br', 'accept-language': 'pt-BR' })
  const street = [parts.number, parts.street].filter(Boolean).join(' ')
  if (street) params.set('street', street)
  if (parts.city) params.set('city', parts.city)
  if (parts.state) params.set('state', parts.state)
  if (parts.zip) params.set('postalcode', parts.zip.replace(/\D/g, '').replace(/^(\d{5})(\d{3})$/, '$1-$2'))
  const r = await fetch(`https://nominatim.openstreetmap.org/search?${params}`, { referrerPolicy: 'strict-origin-when-cross-origin' })
  if (!r.ok) return null
  const j = (await r.json()) as { lat: string; lon: string }[]
  return j[0] ? { lat: round(Number(j[0].lat)), lng: round(Number(j[0].lon)) } : null
}

/**
 * Extrai coordenadas de um link do Google Maps (ou texto "lat, lng").
 * Formatos: .../@-23.56,-46.65,17z · ...!3d-23.56!4d-46.65 · ?q=-23.56,-46.65 · ll=… · "-23.56, -46.65".
 * Links curtos (maps.app.goo.gl) não trazem coordenadas — abra-o e copie o endereço completo.
 */
export function parseCoordinates(text: string): LatLng | null {
  const s = decodeURIComponent(text.trim())
  const n = String.raw`(-?\d{1,3}(?:\.\d+)?)`
  const patterns = [
    new RegExp(String.raw`!3d${n}!4d${n}`), // posição exata do lugar (preferível ao centro do mapa)
    new RegExp(String.raw`@${n},${n}`),
    new RegExp(String.raw`[?&](?:q|query|ll|center|destination)=${n},\s*${n}`),
    new RegExp(String.raw`^${n}\s*[,;\s]\s*${n}$`),
  ]
  for (const re of patterns) {
    const m = s.match(re)
    if (!m) continue
    const lat = Number(m[1])
    const lng = Number(m[2])
    if (Math.abs(lat) <= 90 && Math.abs(lng) <= 180) return { lat: round(lat), lng: round(lng) }
  }
  return null
}

export function googleMapsUrl(lat: number | null, lng: number | null, address?: string): string {
  if (lat !== null && lng !== null) return `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`
  return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(address ?? '')}`
}

export function directionsUrl(lat: number, lng: number): string {
  return `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`
}

/** Endereço em uma linha para exibição. */
export function formatAddress(b: { street?: string | null; number?: string | null; complement?: string | null; district?: string | null; city?: string | null; state?: string | null; zip_code?: string | null }): string {
  const line1 = [b.street, b.number].filter(Boolean).join(', ')
  const parts = [
    [line1, b.complement].filter(Boolean).join(' — '),
    b.district,
    [b.city, b.state].filter(Boolean).join('/'),
    b.zip_code ? `CEP ${b.zip_code.replace(/^(\d{5})(\d{3})$/, '$1-$2')}` : '',
  ]
  return parts.filter(Boolean).join(' · ')
}

const round = (x: number) => Math.round(x * 1e6) / 1e6
