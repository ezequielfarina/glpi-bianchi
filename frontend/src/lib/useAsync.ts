import { ref, shallowRef, type Ref } from 'vue'

/** Estado padrão de carregamento: data / loading / error + reload(). */
export function useAsync<T>(fn: () => Promise<T>): {
  data: Ref<T | null>
  loading: Ref<boolean>
  error: Ref<string | null>
  reload: () => Promise<void>
} {
  const data = shallowRef<T | null>(null) as Ref<T | null>
  const loading = ref(true)
  const error = ref<string | null>(null)

  async function reload(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      data.value = await fn()
    } catch (e) {
      error.value = e instanceof Error ? e.message : String(e)
    } finally {
      loading.value = false
    }
  }

  void reload()
  return { data, loading, error, reload }
}
