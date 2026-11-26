import { ref } from 'vue'

export type Theme = 'dark' | 'light'
const KEY = 'bianchi.theme'

function initial(): Theme {
  try {
    const saved = localStorage.getItem(KEY)
    if (saved === 'dark' || saved === 'light') return saved
  } catch { /* noop */ }
  return 'dark'
}

export const theme = ref<Theme>(initial())

export function toggleTheme(): void {
  theme.value = theme.value === 'dark' ? 'light' : 'dark'
  document.documentElement.dataset.theme = theme.value
  try { localStorage.setItem(KEY, theme.value) } catch { /* noop */ }
}
