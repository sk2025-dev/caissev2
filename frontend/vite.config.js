import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// Build -> ../app (à déployer à côté de api/). Chemins relatifs + routeur « hash » : aucune règle de réécriture serveur.
// Dev : `php -S 127.0.0.1:8000 -t ..` dans le dossier du projet, puis `npm run dev` (proxy /api).
export default defineConfig({
  base: './',
  plugins: [vue()],
  build: { outDir: '../app', emptyOutDir: true },
  server: { proxy: { '/api': 'http://127.0.0.1:8000' } },
})
