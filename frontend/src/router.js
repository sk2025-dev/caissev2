import { createRouter, createWebHashHistory } from 'vue-router'
import { auth } from './api'

const page = (name, title, extra = {}) => ({ path: `/${name}`, component: () => import('./views/ResourcePage.vue'), props: { name }, meta: { title, ...extra } })

const routes = [
  { path: '/login', component: () => import('./views/Login.vue'), meta: { public: true, title: 'Connexion' } },
  { path: '/', component: () => import('./views/Dashboard.vue'), meta: { title: 'Tableau de bord' } },
  { path: '/caisse', component: () => import('./views/Pos.vue'), meta: { title: 'Caisse', droit: 'caisse', plein: true } },
  { path: '/ventes', component: () => import('./views/Ventes.vue'), meta: { title: 'Ventes', droit: ['caisse', 'ventes_toutes'] } },
  { path: '/sessions', component: () => import('./views/Sessions.vue'), meta: { title: 'Sessions de caisse', droit: ['caisse', 'ventes_toutes'] } },
  { path: '/produits/:id(\\d+)', component: () => import('./views/ProduitDetail.vue'), props: true, meta: { title: 'Fiche produit' } },
  page('produits', 'Produits et services'),
  page('categories', 'Catégories', { droit: 'catalogue_ecriture' }),
  { path: '/stock', component: () => import('./views/Stock.vue'), meta: { title: 'Stock', droit: 'stock' } },
  { path: '/appros', component: () => import('./views/Appros.vue'), meta: { title: 'Approvisionnements', droit: 'achats' } },
  { path: '/fournisseurs/:id(\\d+)', component: () => import('./views/FournisseurDetail.vue'), props: true, meta: { title: 'Fiche fournisseur', droit: 'fournisseurs' } },
  page('fournisseurs', 'Fournisseurs', { droit: 'fournisseurs' }),
  { path: '/clients/:id(\\d+)', component: () => import('./views/ClientDetail.vue'), props: true, meta: { title: 'Fiche client' } },
  page('clients', 'Clients'),
  page('caisses', 'Caisses', { admin: true }),
  page('utilisateurs', 'Utilisateurs', { admin: true }),
  { path: '/configuration', component: () => import('./views/Configuration.vue'), meta: { title: 'Configuration', super: true } },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

export const router = createRouter({ history: createWebHashHistory(), routes })

const autorise = (droit) => !droit || [].concat(droit).some((d) => auth.user?.droits?.[d])

router.beforeEach((to) => {
  if (!to.meta.public && !auth.user) return { path: '/login', query: { next: to.fullPath } }
  if (to.path === '/login' && auth.user) return '/'
  if (to.meta.admin && !auth.user?.admin) return '/'
  if (to.meta.super && !auth.user?.super) return '/'
  if (!autorise(to.meta.droit)) return '/'
})

router.afterEach((to) => {
  document.title = `${to.meta.title || 'Caisse'} · Caisse`
})
