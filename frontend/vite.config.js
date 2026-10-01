import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { phpApi, apiTarget } from './dev-api.js'

// Build -> ../app (à déployer à côté de api/). Chemins relatifs + routeur « hash » : aucune règle de réécriture serveur.
// Dev : `npm run dev` lance aussi PHP (ou réutilise l'API déjà lancée).
export default defineConfig({
  base: './',
  plugins: [phpApi(), vue()],
  build: { outDir: '../app', emptyOutDir: true },
  server: { proxy: { '/api': apiTarget } },
})
