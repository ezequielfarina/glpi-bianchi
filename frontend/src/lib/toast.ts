import { ref } from 'vue'

export type ToastTone = 'ok' | 'warn' | 'info'
export interface Toast { id: number; tone: ToastTone; title: string; detail?: string }

export const toasts = ref<Toast[]>([])
let seq = 0

/** Notificação no canto da tela (some sozinha; erros ficam mais tempo). */
export function notify(tone: ToastTone, title: string, detail?: string): void {
  const id = ++seq
  toasts.value = [...toasts.value, { id, tone, title, detail }]
  window.setTimeout(() => dismiss(id), tone === 'warn' ? 12_000 : 6_000)
}

export function dismiss(id: number): void {
  toasts.value = toasts.value.filter((t) => t.id !== id)
}
