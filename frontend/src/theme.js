// Identité et thème de l'application, réglés par le super administrateur (lisibles avant la connexion).
import { reactive, computed, watch } from 'vue'
import { setDevise } from './format'

const API = import.meta.env.DEV ? '/api/index.php' : '../api/index.php'

export const appConfig = reactive({
  loaded: false,
  entreprise: { nom: '', logo: false, devise: 'FCFA' },
  copyright: { nom: "Dav'Holding Group", url: '' },
  theme: { palette: 'violet', mode: 'auto' },
  licence: { etat: 'illimitee', bloque: false, jours: null, fin: null },
  logoVersion: 0,
})

export const logoUrl = computed(() => (appConfig.entreprise.logo ? `${API}?r=config-logo&v=${appConfig.logoVersion}` : ''))
export const nomApp = computed(() => appConfig.entreprise.nom || 'Caisse')

// Choix personnel (mode clair / sombre) mémorisé dans ce navigateur ; il l'emporte sur le mode par défaut de l'entreprise.
const lire = () => { try { return localStorage.getItem('theme') || '' } catch { return '' } }
export const choixUtilisateur = reactive({ mode: lire() })

export function definirChoixUtilisateur(mode) {
  choixUtilisateur.mode = mode
  try { mode ? localStorage.setItem('theme', mode) : localStorage.removeItem('theme') } catch { /* stockage indisponible */ }
}

// Mode effectif : choix personnel, sinon réglage de l'entreprise, sinon préférence du système
export const modeEffectif = computed(() => choixUtilisateur.mode || ({ clair: 'light', sombre: 'dark' }[appConfig.theme.mode] || ''))

// Aperçu en direct (page Configuration) : palette et mode temporaires sans enregistrement
export const apercu = reactive({ palette: '', mode: '' })

export function appliquerTheme() {
  const root = document.documentElement
  const palette = apercu.palette || appConfig.theme.palette
  if (palette && palette !== 'violet') root.dataset.palette = palette; else delete root.dataset.palette
  const mode = apercu.mode ? ({ clair: 'light', sombre: 'dark' }[apercu.mode] || '') : modeEffectif.value
  if (mode) root.dataset.theme = mode; else delete root.dataset.theme
}
watch([() => appConfig.theme.palette, () => appConfig.theme.mode, () => choixUtilisateur.mode, () => apercu.palette, () => apercu.mode], appliquerTheme)

export function appliquerConfig(c) {
  Object.assign(appConfig.entreprise, c.entreprise)
  Object.assign(appConfig.copyright, c.copyright)
  Object.assign(appConfig.theme, c.theme)
  Object.assign(appConfig.licence, c.licence)
  appConfig.loaded = true
  setDevise(appConfig.entreprise.devise)
  appliquerTheme()
  document.title = document.title.replace(/·.*$/, '').trim() + ' · ' + nomApp.value
}

export async function chargerConfigPublique() {
  try {
    const res = await fetch(`${API}?r=public-config`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
    if (res.ok) appliquerConfig(await res.json())
  } catch { /* hors ligne : on garde les valeurs par défaut */ }
  appliquerTheme()
}
