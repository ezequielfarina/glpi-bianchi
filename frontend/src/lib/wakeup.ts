import { ref } from 'vue'
import { api } from './api'
import { notify } from './toast'

/** device IDs com pedido de inventário em andamento (desabilita o botão). */
export const waking = ref<Set<string>>(new Set())

/** "Forçar inventário": pede ao GLPI Agent (GET /now na porta HTTP dele) para rodar agora. */
export async function forceInventory(deviceId: string, label: string): Promise<boolean> {
  waking.value = new Set(waking.value).add(deviceId)
  try {
    const r = await api.wakeup(deviceId)
    if (r.result === 'ok') {
      notify('ok', `Inventário solicitado — ${label}`, r.message)
      return true
    }
    notify('warn', `Não foi possível acionar ${label}`, r.message)
    return false
  } catch (e) {
    notify('warn', `Erro ao acionar ${label}`, e instanceof Error ? e.message : String(e))
    return false
  } finally {
    const next = new Set(waking.value)
    next.delete(deviceId)
    waking.value = next
  }
}
