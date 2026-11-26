/// <reference types="vite/client" />

declare module '*.vue' {
  import type { DefineComponent } from 'vue'
  const component: DefineComponent<object, object, unknown>
  export default component
}

declare module '@fontsource-variable/inter'
declare module '@fontsource-variable/jetbrains-mono'

import 'vue-router'
declare module 'vue-router' {
  interface RouteMeta {
    public?: boolean
    title?: string
  }
}
