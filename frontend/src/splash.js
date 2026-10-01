// Écran d'accueil / d'au revoir plein page, affiché entre la page de connexion et le tableau de bord.
import { reactive } from 'vue'

export const splash = reactive({ visible: false, mode: 'bienvenue', nom: '', cycle: 0 })
let minuteur = null

export const DUREES = { bienvenue: 2300, aurevoir: 1700 }
const reduit = () => typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches

export function showSplash(mode, nom = '') {
  clearTimeout(minuteur)
  splash.mode = mode; splash.nom = nom; splash.cycle++; splash.visible = true
  // Bienvenue : se referme toute seule. Au revoir : refermé par l'appelant une fois la page de connexion prête.
  if (mode === 'bienvenue') minuteur = setTimeout(hideSplash, reduit() ? 500 : DUREES.bienvenue)
}
export function hideSplash() { clearTimeout(minuteur); splash.visible = false }
export const pause = (ms) => new Promise((r) => setTimeout(r, reduit() ? Math.min(ms, 300) : ms))
