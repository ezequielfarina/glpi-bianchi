import { computed, ref } from 'vue'
import { api, ApiError } from './api'
import type { Me, Role } from './types'

/**
 * Usuário logado (carregado de GET /api/ui/me). A autorização de verdade é no
 * servidor (security.yaml); aqui só se escondem botões que o perfil não pode usar.
 */
export const me = ref<Me | null>(null)
let loading: Promise<Me | null> | null = null

export async function loadMe(force = false): Promise<Me | null> {
  if (me.value && !force) return me.value
  loading ??= api.me()
    .then((m) => (me.value = m))
    .catch((e) => {
      if (e instanceof ApiError && (e.status === 401 || e.status === 403)) me.value = null
      else throw e
      return null
    })
    .finally(() => { loading = null })
  return loading
}

export function clearMe(): void {
  me.value = null
}

const RANK: Record<Role, number> = { viewer: 1, editor: 2, admin: 3 }
const has = (r: Role) => computed(() => !!me.value && RANK[me.value.role] >= RANK[r])

/** Pode cadastrar/editar (técnico ou administrador). */
export const canEdit = has('editor')
/** Administra usuários, auditoria e configurações. */
export const isAdmin = has('admin')

export const ROLE_LABEL: Record<Role, string> = { admin: 'Administrador', editor: 'Técnico', viewer: 'Leitura' }
export const ROLE_HINT: Record<Role, string> = {
  admin: 'Tudo, inclusive usuários, auditoria e configurações.',
  editor: 'Cadastra e edita ativos, licenças, contratos, filiais; vê chaves de licença.',
  viewer: 'Somente consulta. Não vê chaves de licença.',
}
