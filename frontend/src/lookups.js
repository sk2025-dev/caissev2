import { reactive } from 'vue'
import { api } from './api'

export const lookups = reactive({ loaded: false, categories: [], fournisseurs: [], modes: [], categories_mvt: { entree: {}, sortie: {} }, sources_depense: {}, zones_livraison: [], caisses: [], caissiers: [], groupes: [], reglages: { tva_defaut: 0, prix_libre: false, remise_max: 10, stock_negatif: false } })

export async function loadLookups(force = false) {
  if (lookups.loaded && !force) return
  Object.assign(lookups, await api.get('lookups'), { loaded: true })
}

/** Ajoute une catégorie de mouvement d'espèces (gérant) et met à jour les listes ; renvoie son code. */
export async function ajouterCategorieMvt(type, libelle) {
  const res = await api.post('categorie-mouvement-creer', { type, libelle })
  lookups.categories_mvt = res.categories_mvt
  return res.categorie
}
