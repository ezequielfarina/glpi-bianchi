import { reactive } from 'vue'
import { circuits, dcim, dcimChoices } from './dcim'
import { virt } from './virt'

// Listas editáveis usadas nos campos com LookupSelect (sistema operacional, função, plataforma do cluster…).
// Um cache por lista, compartilhado: editar num formulário atualiza todos os campos abertos.

export interface LookupItem { id: number; name: string; color?: string | null; icon?: string | null; uses: number; description?: string | null }
export type LookupSource = 'platforms' | 'roles' | 'cluster_types' | 'cluster_groups' | 'circuit_types'

interface Source {
  /** substantivo no singular, para os textos ("Nova função", "Excluir função X?") */
  noun: string
  feminine?: boolean
  list: () => Promise<LookupItem[]>
  save: (id: number | null, body: Record<string, unknown>) => Promise<{ id: number }>
  remove: (id: number) => Promise<unknown>
  /** campos extras na edição */
  extra?: 'role'
  usesLabel: (n: number) => string
  after?: () => void
}

const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`
const SOURCES: Record<LookupSource, Source> = {
  platforms: {
    noun: 'sistema', list: async () => (await dcim.platforms()).items.map((p) => ({ id: p.id, name: p.name, description: p.description, uses: p.vms })),
    save: dcim.savePlatform, remove: dcim.deletePlatform, usesLabel: (n) => plural(n, 'VM', 'VMs'),
  },
  roles: {
    noun: 'função', feminine: true, extra: 'role',
    list: async () => (await dcim.roles()).items.map((r) => ({ id: r.id, name: r.name, color: r.color, icon: r.icon, description: r.description, uses: r.devices + (r.vms ?? 0) })),
    save: dcim.saveRole, remove: dcim.deleteRole, usesLabel: (n) => plural(n, 'uso', 'usos'), after: () => void dcimChoices(true),
  },
  cluster_types: {
    noun: 'plataforma', feminine: true, list: async () => (await virt.choices()).types.map((t) => ({ id: t.id, name: t.name, description: t.description, uses: t.clusters })),
    save: (id, b) => virt.saveLookup('type', id, b), remove: (id) => virt.deleteLookup('type', id), usesLabel: (n) => plural(n, 'cluster', 'clusters'),
  },
  cluster_groups: {
    noun: 'grupo', list: async () => (await virt.choices()).groups.map((t) => ({ id: t.id, name: t.name, description: t.description, uses: t.clusters })),
    save: (id, b) => virt.saveLookup('group', id, b), remove: (id) => virt.deleteLookup('group', id), usesLabel: (n) => plural(n, 'cluster', 'clusters'),
  },
  circuit_types: {
    noun: 'tipo', list: async () => (await circuits.types()).items.map((t) => ({ id: t.id, name: t.name, description: t.description, uses: t.circuits })),
    save: circuits.saveType, remove: circuits.deleteType, usesLabel: (n) => plural(n, 'circuito', 'circuitos'),
  },
}

const store = reactive<Record<string, { items: LookupItem[]; loaded: boolean; error: string | null }>>({})
const pending: Partial<Record<LookupSource, Promise<void>>> = {}

export function lookupSource(src: LookupSource): Source {
  return SOURCES[src]
}

export function lookupState(src: LookupSource): { items: LookupItem[]; loaded: boolean; error: string | null } {
  store[src] ??= { items: [], loaded: false, error: null }
  if (!store[src].loaded && !pending[src]) void reloadLookup(src)
  return store[src]
}

export async function reloadLookup(src: LookupSource): Promise<void> {
  store[src] ??= { items: [], loaded: false, error: null }
  const st = store[src]
  pending[src] = SOURCES[src].list()
    .then((items) => { st.items = items; st.loaded = true; st.error = null })
    .catch((e: unknown) => { st.error = e instanceof Error ? e.message : String(e) })
    .finally(() => { delete pending[src] })
  return pending[src]
}

export async function saveLookup(src: LookupSource, id: number | null, body: Record<string, unknown>): Promise<number> {
  const r = await SOURCES[src].save(id, body)
  SOURCES[src].after?.()
  await reloadLookup(src)
  return r.id
}

export async function removeLookup(src: LookupSource, id: number): Promise<void> {
  await SOURCES[src].remove(id)
  SOURCES[src].after?.()
  await reloadLookup(src)
}
