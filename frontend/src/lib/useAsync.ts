import { ref, shallowRef, type Ref } from 'vue'

/**
 * Estado padrão de carregamento: data / loading / error + reload().
 * Recargas com dados já na tela são silenciosas (sem esqueleto/piscar) e só a
 * resposta da chamada mais recente é aplicada (evita resposta lenta sobrescrever nova).
 */
export function useAsync<T>(fn: () => Promise<T>): {
  data: Ref<T | null>
  loading: Ref<boolean>
  error: Ref<string | null>
  reload: () => Promise<void>
} {
  const data = shallowRef<T | null>(null) as Ref<T | null>
  const loading = ref(true)
  const error = ref<string | null>(null)

  let seq = 0

  async function reload(): Promise<void> {
    const mine = ++seq
    if (data.value === null) loading.value = true
    try {
      const result = await fn()
      if (mine !== seq) return
      data.value = result
      error.value = null
    } catch (e) {
      if (mine !== seq) return
      // falha numa recarga em segundo plano não derruba o que já está na tela
      if (data.value === null) error.value = e instanceof Error ? e.message : String(e)
    } finally {
      if (mine === seq) loading.value = false
    }
  }

  void reload()
  return { data, loading, error, reload }
}
