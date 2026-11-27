<script lang="ts">
// Ícones em traço (24×24), decorativos (aria-hidden). Nome tipado: IconName.
const paths = {
  logo: 'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 17.5h7M17.5 14v7',
  home: 'M3 12l9-8 9 8M5 10v10h14V10',
  assets: 'M3 4h18v12H3zM8 20h8M12 16v4',
  software: 'M4 7l8-4 8 4-8 4-8-4zM4 12l8 4 8-4M4 17l8 4 8-4',
  agents: 'M12 3v3M12 18v3M3 12h3M18 12h3M12 7a5 5 0 100 10 5 5 0 000-10z',
  inbox: 'M4 4h16v16H4zM4 9h16M9 9v11',
  search: 'M11 4a7 7 0 100 14 7 7 0 000-14zM20 20l-3.5-3.5',
  moon: 'M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z',
  sun: 'M12 4V2M12 22v-2M4 12H2M22 12h-2M5.6 5.6L4.2 4.2M19.8 19.8l-1.4-1.4M5.6 18.4l-1.4 1.4M19.8 4.2l-1.4 1.4M12 7a5 5 0 100 10 5 5 0 000-10z',
  logout: 'M15 4h4v16h-4M10 8l-4 4 4 4M6 12h11',
  refresh: 'M20 11a8 8 0 10-2.3 5.7M20 4v7h-7',
  chevron: 'M9 6l6 6-6 6',
  back: 'M15 6l-6 6 6 6',
  cpu: 'M7 7h10v10H7zM10 10h4v4h-4zM9 3v4M15 3v4M9 17v4M15 17v4M3 9h4M3 15h4M17 9h4M17 15h4',
  memory: 'M3 8h18v8H3zM7 16v3M11 16v3M15 16v3M7 11v2M11 11v2M15 11v2',
  disk: 'M4 5h16v14H4zM4 14h16M16 17h.01',
  network: 'M12 3v5M5 21v-5h14v5M12 8a2 2 0 100 4 2 2 0 000-4zM12 12v4',
  history: 'M3 12a9 9 0 103-6.7M3 4v5h5M12 8v4l3 2',
  copy: 'M9 9h11v11H9zM5 15H4V4h11v1',
  alert: 'M12 3l10 18H2zM12 10v4M12 17h.01',
  sort: 'M8 9l4-4 4 4M8 15l4 4 4-4',
  code: 'M8 8l-4 4 4 4M16 8l4 4-4 4',
  close: 'M6 6l12 12M18 6L6 18',
  settings: 'M12 9a3 3 0 100 6 3 3 0 000-6zM19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.8-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1.1-1.5 1.7 1.7 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.8 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.5-1.1 1.7 1.7 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.8.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.8V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z',
  bolt: 'M13 2L4 14h7l-1 8 9-12h-7z',
  check: 'M5 12l5 5L20 7',
  key: 'M15 7a4 4 0 11-3.9 4.9L3 20v-3h3v-3h3l1.1-1.1A4 4 0 0115 7zM16 9h.01',
  contract: 'M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6',
  truck: 'M3 7h11v9H3zM14 10h4l3 3v3h-7M7 19a2 2 0 100-4 2 2 0 000 4zM17 19a2 2 0 100-4 2 2 0 000 4z',
  plus: 'M12 5v14M5 12h14',
  edit: 'M4 20h4L19 9l-4-4L4 16zM14 6l4 4',
  trash: 'M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13',
  upload: 'M12 16V4M7 9l5-5 5 5M4 20h16',
  download: 'M12 4v12M7 11l5 5 5-5M4 20h16',
  camera: 'M4 7h4l2-3h4l2 3h4v12H4zM12 10a3.5 3.5 0 100 7 3.5 3.5 0 000-7z',
  file: 'M7 3h7l5 5v13H7zM14 3v5h5',
  user: 'M12 12a4 4 0 100-8 4 4 0 000 8zM4 21a8 8 0 0116 0',
  lock: 'M6 11h12v10H6zM8 11V7a4 4 0 018 0v4',
  printer: 'M7 8V3h10v5M5 8h14v8h-3v5H8v-5H5zM8 14h8',
  tablet: 'M6 3h12v18H6zM11 18h2',
  barcode: 'M4 5v14M7 5v14M10 5v14M14 5v14M17 5v14M20 5v14',
  monitor: 'M3 4h18v12H3zM9 20h6M12 16v4',
  pc: 'M2 5h12v9H2zM6 19h4M8 14v5M17 4h5v16h-5zM19.5 8h.01M19.5 11h.01',
  phone: 'M8 3h8v18H8zM11 18h2',
  box: 'M3 7l9-4 9 4v10l-9 4-9-4zM3 7l9 4 9-4M12 11v10',
  building: 'M4 21V4h10v17M14 9h6v12M2 21h20M7 8h2M7 12h2M7 16h2M10 8h1M10 12h1M10 16h1M17 13h1M17 17h1',
  radar: 'M12 12l6-6M12 3a9 9 0 109 9M12 7a5 5 0 105 5M12 11a1 1 0 100 2 1 1 0 000-2z',
  calculator: 'M5 3h14v18H5zM8 6h8v3H8zM8 13h.01M12 13h.01M16 13h.01M8 17h.01M12 17h.01M16 17h.01',
  server: 'M4 4h16v6H4zM4 14h16v6H4zM8 7h.01M8 17h.01',
  bell: 'M6 8a6 6 0 0112 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.9 1.9 0 003.4 0',
  pulse: 'M3 12h4l3-8 4 16 3-8h4',
  globe: 'M12 3a9 9 0 100 18 9 9 0 000-18zM3 12h18M12 3c2.5 2.5 3.8 5.5 3.8 9s-1.3 6.5-3.8 9c-2.5-2.5-3.8-5.5-3.8-9s1.3-6.5 3.8-9z',
  conflict: 'M8 4l-4 4 4 4M4 8h11M16 12l4 4-4 4M20 16H9',
  clock: 'M12 3a9 9 0 100 18 9 9 0 000-18zM12 7v5l3 2',
  play: 'M7 4l13 8-13 8z',
  send: 'M4 12l16-8-6 16-2-6zM12 14l8-10',
  wrench: 'M14.7 6.3a4 4 0 00-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 005.4-5.4l-2.5 2.5-2.8-.7-.7-2.8z',
  eye: 'M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7zM12 15a3 3 0 100-6 3 3 0 000 6z',
  eyeOff: 'M3 3l18 18M10.6 5.1A10 10 0 0112 5c6.4 0 10 7 10 7a17 17 0 01-3.2 4.1M6.6 6.6C3.8 8.4 2 12 2 12s3.6 7 10 7a9.6 9.6 0 005.4-1.6M9.9 9.9a3 3 0 004.2 4.2',
  pin: 'M12 21s-7-6.2-7-11.5a7 7 0 0114 0C19 14.8 12 21 12 21zM12 12a2.5 2.5 0 100-5 2.5 2.5 0 000 5z',
  map: 'M3 6l6-3 6 3 6-3v15l-6 3-6-3-6 3zM9 3v15M15 6v15',
  rack: 'M5 3h14v18H5zM8 7h8M8 11h8M8 15h8M8 19h.01',
  cable: 'M7 3v5M17 3v5M5 8h4v4H5zM15 8h4v4h-4zM7 12v3a5 5 0 0010 0v-3M12 20v1',
  unplug: 'M3 3l18 18M7 3v5M17 3v5M15 8h4v4h-2M5 8h4v4H5zM7 12v3a5 5 0 008.5 3.5',
  route: 'M6 19a2 2 0 100-4 2 2 0 000 4zM18 9a2 2 0 100-4 2 2 0 000 4zM6 15V9a4 4 0 014-4h2M18 9v6a4 4 0 01-4 4h-2',
  hash: 'M5 9h14M5 15h14M10 3L8 21M16 3l-2 18',
  layers: 'M12 3l9 5-9 5-9-5zM3 13l9 5 9-5M3 17l9 5 9-5',
  tree: 'M4 4h6v4H4zM14 10h6v4h-6zM14 18h6v4h-6zM7 8v12h7M7 12h7',
  gear: 'M12 9a3 3 0 100 6 3 3 0 000-6zM4 12h2M18 12h2M12 4v2M12 18v2M6.3 6.3l1.4 1.4M16.3 16.3l1.4 1.4M6.3 17.7l1.4-1.4M16.3 7.7l1.4-1.4',
} as const

export type IconName = keyof typeof paths
</script>

<script setup lang="ts">
import type { PropType } from 'vue'

defineProps({
  name: { type: String as PropType<IconName>, required: true },
  size: { type: Number, default: 18 },
})
</script>

<template>
  <svg
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.8"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    focusable="false"
  >
    <path :d="paths[name]" />
  </svg>
</template>
