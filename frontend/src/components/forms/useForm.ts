import { ref } from 'vue'
import { ApiError } from '../../lib/api'
import { notify } from '../../lib/toast'

/** Estado de envio + erros por campo (422) para os formulários de gestão. */
export function useForm() {
  const saving = ref(false)
  const errors = ref<Record<string, string>>({})

  async function submit<T>(fn: () => Promise<T>, okTitle: string): Promise<T | null> {
    saving.value = true
    errors.value = {}
    try {
      const r = await fn()
      notify('ok', okTitle)
      return r
    } catch (e) {
      if (e instanceof ApiError) errors.value = e.fields
      notify('warn', 'Não foi possível salvar', e instanceof Error ? e.message : String(e))
      return null
    } finally {
      saving.value = false
    }
  }
  return { saving, errors, submit }
}

/** Converte "" em null antes de enviar. */
export function clean<T extends Record<string, unknown>>(o: T): Record<string, unknown> {
  return Object.fromEntries(Object.entries(o).map(([k, v]) => [k, v === '' ? null : v]))
}
