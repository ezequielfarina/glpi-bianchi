/**
 * Link externo vindo de dado cadastrado: só http(s). Qualquer outro esquema (`javascript:`, `data:`…)
 * vira undefined e o <a> fica sem href — defesa em profundidade além da validação do backend
 * (auditoria 2026-11, XSS armazenado). Registros antigos já gravados também ficam inofensivos.
 */
export function safeHref(url: string | null | undefined): string | undefined {
  if (!url) return undefined
  try {
    const u = new URL(url, 'https://invalid.local')
    return (u.protocol === 'https:' || u.protocol === 'http:') && u.host !== 'invalid.local' ? u.href : undefined
  } catch {
    return undefined
  }
}
