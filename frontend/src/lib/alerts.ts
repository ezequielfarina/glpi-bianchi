import { ref } from 'vue'
import { api } from './api'
import type { AlertCounts } from './types'

/** Alertas de rede abertos — badge do menu (AppShell) e telas de monitoramento compartilham o mesmo estado. */
export const alertCounts = ref<AlertCounts>({ critical: 0, warning: 0, info: 0, open: 0 })

export async function refreshAlertCounts(): Promise<void> {
  try { alertCounts.value = await api.alertCount() } catch { /* sem permissão/offline: mantém */ }
}

export const SEVERITY: Record<string, { label: string; tone: string; icon: 'alert' | 'bell' | 'check' }> = {
  critical: { label: 'Crítico', tone: 'bad', icon: 'alert' },
  warning: { label: 'Atenção', tone: 'warn', icon: 'alert' },
  info: { label: 'Informativo', tone: 'info', icon: 'bell' },
}
