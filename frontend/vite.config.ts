import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'

// Build estático servido pelo nginx do CT em /app/ (ver deploy/lxc/nginx).
// Em desenvolvimento, `VITE_API_TARGET=http://192.168.110.55 npm run dev`
// faz proxy de /api para o servidor real.
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  return {
    base: '/app/',
    plugins: [vue()],
    server: {
      port: 5173,
      proxy: {
        '/api': { target: env.VITE_API_TARGET ?? 'http://127.0.0.1', changeOrigin: true },
      },
    },
    build: {
      outDir: 'dist',
      emptyOutDir: true,
      sourcemap: false,
      chunkSizeWarningLimit: 600,
    },
  }
})
