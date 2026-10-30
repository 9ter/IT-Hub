import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
  base: '/it-hub/',
  plugins: [vue(), tailwindcss()],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  build: {
    // Open tabs still import the hashed chunks from their original build.
    // dist is served directly by Apache; keep these files across deployments.
    emptyOutDir: false,
    rollupOptions: {input: {main: fileURLToPath(new URL('./index.html', import.meta.url)), archive: fileURLToPath(new URL('./archive-print.html', import.meta.url))}},
  },
  server: {
    port: 5173,
    // npm run dev → ส่ง API ไปที่ PHP dev server (php -S 127.0.0.1:8099 dev/router.php)
    proxy: { '/it-hub/api': 'http://127.0.0.1:8099' },
  },
})
