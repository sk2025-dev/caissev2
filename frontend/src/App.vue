<script setup>
import { ref, watch, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { auth, api, logout, prenomDe } from './api'
import { showSplash, hideSplash, pause } from './splash'
import SplashScreen from './components/SplashScreen.vue'
import { toasts } from './toast'
import { date, alerteLibelle, alerteLien } from './format'
import Icon from './components/Icon.vue'
import Illus from './components/Illus.vue'
import GlobalSearch from './components/GlobalSearch.vue'
import AppFooter from './components/AppFooter.vue'
import ExportsMenu from './components/ExportsMenu.vue'
import { initExports } from './exports'
import { appConfig, logoUrl, nomApp, modeEffectif, definirChoixUtilisateur } from './theme'

const route = useRoute()
const router = useRouter()
const menuOpen = ref(false)
// Menu latéral masquable (ordinateur) : le choix est mémorisé dans ce navigateur. Sur mobile, le menu reste un tiroir.
const lireMasque = () => { try { return localStorage.getItem('menu-masque') === '1' } catch { return false } }
const masque = ref(lireMasque())
const mobile = () => window.matchMedia('(max-width: 820px)').matches
function basculerMenu() {
  if (mobile()) { menuOpen.value = !menuOpen.value; return }
  masque.value = !masque.value
  try { localStorage.setItem('menu-masque', masque.value ? '1' : '0') } catch { /* stockage indisponible */ }
}
const raccourciMenu = (e) => { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'b') { e.preventDefault(); basculerMenu() } }
onMounted(() => document.addEventListener('keydown', raccourciMenu))
onBeforeUnmount(() => document.removeEventListener('keydown', raccourciMenu))
const popup = ref('') // '', 'bell', 'user'
const alertes = ref([])
watch(() => route.fullPath, () => { menuOpen.value = false; popup.value = '' })

const prefersDark = () => window.matchMedia('(prefers-color-scheme: dark)').matches
const isDark = computed(() => (modeEffectif.value ? modeEffectif.value === 'dark' : prefersDark()))
const toggleTheme = () => definirChoixUtilisateur(isDark.value ? 'light' : 'dark')
const lic = computed(() => appConfig.licence)
const bandeau = computed(() => (auth.user?.admin && lic.value.etat === 'expiree' ? ['danger', lic.value.bloque ? "La licence d'utilisation a expiré : l'accès est suspendu pour les utilisateurs." : "La licence d'utilisation a expiré : l'application est en lecture seule."] : auth.user?.admin && lic.value.etat === 'bientot' ? ['warning', `La licence d'utilisation expire dans ${lic.value.jours} jour${lic.value.jours > 1 ? 's' : ''} (${date(lic.value.fin)}).`] : null))
const verrou = computed(() => auth.user && lic.value.etat === 'expiree' && lic.value.bloque && !auth.user?.super)

const droit = (d) => !d || [].concat(d).some((x) => auth.user?.droits?.[x])
const nav = computed(() => [
  { to: '/', label: 'Tableau de bord', icon: 'dashboard' },
  { to: '/caisse', label: 'Caisse', icon: 'cart', droit: 'caisse' },
  { to: '/ventes', label: 'Ventes', icon: 'receipt', droit: ['caisse', 'ventes_toutes'] },
  { to: '/sessions', label: 'Sessions de caisse', icon: 'cash', droit: ['caisse', 'ventes_toutes'] },
  { sep: 'Catalogue' },
  { to: '/produits', label: 'Produits et services', icon: 'tag' },
  { to: '/categories', label: 'Catégories', icon: 'grid', droit: 'catalogue_ecriture' },
  { to: '/stock', label: 'Stock', icon: 'box', droit: 'stock' },
  { to: '/appros', label: 'Approvisionnements', icon: 'truck', droit: 'achats' },
  { sep: 'Tiers' },
  { to: '/clients', label: 'Clients', icon: 'user' },
  { to: '/fournisseurs', label: 'Fournisseurs', icon: 'briefcase', droit: 'fournisseurs' },
  ...(auth.user?.admin ? [{ sep: 'Administration' }, { to: '/caisses', label: 'Caisses', icon: 'wallet' }, { to: '/utilisateurs', label: 'Utilisateurs', icon: 'users' }] : []),
  ...(auth.user?.super ? [{ to: '/configuration', label: 'Configuration', icon: 'settings' }] : []),
].filter((n) => n.sep || droit(n.droit)))
// Entrée de menu active aussi sur ses sous-pages (ex. /vehicules/3) ; « / » uniquement en exact
const isActive = (to) => (to === '/' ? route.path === '/' : route.path === to || route.path.startsWith(to + '/'))
const initials = computed(() => (auth.user?.name || '?').split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase())

async function loadAlertes() {
  if (!auth.user) return
  try { alertes.value = (await api.get('alertes')).alertes } catch { /* non bloquant */ }
}
watch(() => auth.user, (u) => { if (u) { loadAlertes(); initExports() } }, { immediate: true })
const refresh = () => document.visibilityState === 'visible' && loadAlertes()
onMounted(() => document.addEventListener('visibilitychange', refresh))
onBeforeUnmount(() => document.removeEventListener('visibilitychange', refresh))
watch(() => route.path, loadAlertes)

const toggle = (name) => (popup.value = popup.value === name ? '' : name)
const closePop = (e) => { if (!e.target.closest('.anchor')) popup.value = '' }
onMounted(() => document.addEventListener('mousedown', closePop))
onBeforeUnmount(() => document.removeEventListener('mousedown', closePop))

async function doLogout() {
  const debut = Date.now()
  showSplash('aurevoir', prenomDe(auth.user))   // « À bientôt » recouvre l'écran pendant la fermeture de la session
  popup.value = ''; menuOpen.value = false
  await logout()
  await pause(1800 - (Date.now() - debut))
  await router.replace('/login')
  await pause(250)                               // laisse la page de connexion se poser avant de retirer l'écran
  hideSplash()
}
</script>

<template>
  <div v-if="auth.user" class="shell" :class="{ masque }">
    <aside id="menu-lateral" class="sidebar" :class="{ open: menuOpen }" :aria-hidden="masque && !menuOpen ? 'true' : undefined" :inert="masque && !menuOpen">
      <div class="brand"><img v-if="logoUrl" :src="logoUrl" alt="" class="brand-logo" /><span v-else class="logo"><Icon name="cart" :size="21" /></span> {{ nomApp }}</div>
      <nav class="nav" aria-label="Navigation principale">
        <template v-for="n in nav" :key="n.to || n.sep">
          <span v-if="n.sep" class="nav-sep">{{ n.sep }}</span>
          <RouterLink v-else :to="n.to" :class="{ on: isActive(n.to) }"><Icon :name="n.icon" :size="20" /> {{ n.label }}</RouterLink>
        </template>
      </nav>
      <div class="spacer" />
      <div v-if="auth.user?.droits?.caisse" class="promo">
        <h4>Prêt à encaisser ✨</h4>
        <p>Ouvrez la caisse et vendez en quelques touches.</p>
        <RouterLink to="/caisse" class="btn">Ouvrir la caisse</RouterLink>
        <span class="art"><Illus name="coins" :size="58" /></span>
      </div>
    </aside>
    <div v-if="menuOpen" class="scrim" @click="menuOpen = false" />

    <div class="main">
      <header class="header">
        <button class="pillbtn menu-btn" :aria-label="masque ? 'Afficher le menu' : 'Masquer le menu'" :title="(masque ? 'Afficher' : 'Masquer') + ' le menu (Ctrl/⌘ + B)'" aria-controls="menu-lateral" :aria-expanded="mobile() ? menuOpen : !masque" @click="basculerMenu"><Icon :name="mobile() ? 'menu' : 'sidebar'" /></button>
        <span v-if="masque" class="mini-brand"><img v-if="logoUrl" :src="logoUrl" alt="" /><span v-else class="logo"><Icon name="cart" :size="18" /></span><b>{{ nomApp }}</b></span>
        <GlobalSearch />
        <div class="grow" />

        <ExportsMenu />

        <div class="anchor">
          <button class="pillbtn" :aria-label="`Notifications (${alertes.length})`" :aria-expanded="popup === 'bell'" @click="toggle('bell')">
            <Icon name="bell" :size="20" />
            <span v-if="alertes.length" class="dot">{{ alertes.length }}</span>
          </button>
          <div v-if="popup === 'bell'" class="menu wide" role="dialog" aria-label="Alertes">
            <div class="who"><b>À surveiller</b><span>{{ alertes.length ? `${alertes.length} point${alertes.length > 1 ? 's' : ''} demandent votre attention` : 'Tout est en ordre 🎉' }}</span></div>
            <RouterLink v-for="a in alertes.slice(0, 7)" :key="a.type + a.id" :to="alerteLien(a.type, a.id)" class="row alert-item">
              <span class="tile" :class="a.niveau"><Icon name="alert" :size="18" /></span>
              <div><b>{{ a.label }}</b><span>{{ alerteLibelle(a.type) }} · {{ a.detail }}</span></div>
            </RouterLink>
          </div>
        </div>

        <div class="anchor">
          <button class="avatar" :aria-label="`Compte de ${auth.user.name}`" :aria-expanded="popup === 'user'" @click="toggle('user')">{{ initials }}</button>
          <div v-if="popup === 'user'" class="menu" role="menu">
            <div class="who"><b>{{ auth.user.name }}</b><span>{{ auth.user.email }} · {{ auth.user.role }}</span></div>
            <button role="menuitem" @click="toggleTheme"><Icon :name="isDark ? 'sun' : 'moon'" :size="18" /> {{ isDark ? 'Mode clair' : 'Mode sombre' }}</button>
            <button role="menuitem" @click="doLogout"><Icon name="logout" :size="18" /> Se déconnecter</button>
          </div>
        </div>
      </header>

      <div v-if="bandeau" class="lic-banner" :class="bandeau[0]" role="alert"><Icon name="alert" :size="18" /> {{ bandeau[1] }}<RouterLink v-if="auth.user?.super" to="/configuration">Renouveler</RouterLink></div>

      <RouterView v-slot="{ Component }">
        <Transition name="page" mode="out-in"><component :is="Component" :key="route.path" /></Transition>
      </RouterView>
      <AppFooter />
    </div>
  </div>
  <template v-else>
    <RouterView />
    <AppFooter class="on-login" />
  </template>

  <div v-if="verrou" class="lock" role="alertdialog" aria-modal="true">
    <div class="card">
      <span class="big"><Icon name="shield" :size="32" /></span>
      <h2>Licence d'utilisation expirée</h2>
      <p class="muted" style="margin:0">L'accès à l'application est suspendu. Contactez votre administrateur pour renouveler la licence.</p>
      <button class="btn primary" style="margin-top:10px" @click="doLogout"><Icon name="logout" :size="16" /> Se déconnecter</button>
    </div>
  </div>

  <SplashScreen />

  <div class="toasts" role="status" aria-live="polite">
    <div v-for="t in toasts" :key="t.id" class="toast" :class="[t.type, { withAction: t.action }]">{{ t.message }}<a v-if="t.action" :href="t.action.href" class="toast-action" download>{{ t.action.label }}</a></div>
  </div>
</template>
