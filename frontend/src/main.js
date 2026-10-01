import { createApp, watch } from 'vue'
import App from './App.vue'
import { router } from './router'
import { auth, restoreSession } from './api'
import { chargerConfigPublique } from './theme'
import './styles.css'

Promise.all([restoreSession(), chargerConfigPublique()]).then(() => {
  createApp(App).use(router).mount('#app')

  // Session expirée en cours d'utilisation -> retour à la connexion
  watch(() => auth.user, (u) => {
    if (!u && router.currentRoute.value.path !== '/login') router.replace({ path: '/login', query: { next: router.currentRoute.value.fullPath } })
  })
})
