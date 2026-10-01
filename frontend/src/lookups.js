import { reactive } from 'vue'
import { api } from './api'

export const lookups = reactive({ loaded: false, categories: [], fournisseurs: [], modes: [], categories_mvt: { entree: {}, sortie: {} }, zones_livraison: [], caisses: [], caissiers: [], groupes: [], reglages: { tva_defaut: 0, prix_libre: false, remise_max: 10, stock_negatif: false } })

export async function loadLookups(force = false) {
  if (lookups.loaded && !force) return
  Object.assign(lookups, await api.get('lookups'), { loaded: true })
}
