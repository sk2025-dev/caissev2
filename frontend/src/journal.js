// Journal d'activité : libellés des modules et des actions, lien vers la fiche concernée, lecture des détails (avant / après).
import { resources } from './resources'
import { qty } from './format'

export const MODULES = {
  securite: 'Connexions et comptes', caisse: 'Caisse', ventes: 'Ventes', commandes: 'Commandes', catalogue: 'Catalogue', stock: 'Stock',
  achats: 'Achats et fournisseurs', clients: 'Clients', exports: 'Exports et sauvegardes', configuration: 'Configuration', autre: 'Autre',
}

export const ACTIONS = {
  connexion: 'Connexion', deconnexion: 'Déconnexion', connexion_echec: 'Échec de connexion', connexion_bloquee: 'Connexion bloquée',
  create: 'Création', update: 'Modification', delete: 'Suppression',
  ouverture: 'Ouverture de caisse', cloture: 'Clôture de caisse', cloture_forcee: 'Clôture forcée', entree: "Entrée d'espèces", sortie: "Sortie d'espèces", depense: 'Dépense',
  vente: 'Vente', annulation: 'Annulation', statut: 'Suivi de commande', livraison: 'Remise de commande',
  ajustement: 'Ajustement de stock', perte: 'Perte', inventaire: 'Inventaire', reception: 'Réception', reglement: 'Règlement',
  export: 'Export', sauvegarde: 'Sauvegarde', envoi: 'Envoi des alertes', photo: 'Photos',
}

const SENSIBLES = ['connexion_echec', 'connexion_bloquee', 'delete', 'annulation', 'cloture_forcee', 'perte']
const POSITIVES = ['create', 'vente', 'reception', 'reglement', 'entree', 'connexion', 'livraison']
export const ton = (action) => (SENSIBLES.includes(action) ? 'danger' : POSITIVES.includes(action) ? 'success' : action === 'update' ? 'warning' : 'primary')

const ICONES = { securite: 'shield', caisse: 'cash', ventes: 'receipt', commandes: 'truck', catalogue: 'tag', stock: 'box', achats: 'briefcase', clients: 'user', exports: 'download', configuration: 'settings' }
export const icone = (module) => ICONES[module] || 'history'

/** Fiche à ouvrir depuis une ligne du journal (null si l'objet n'a pas de page dédiée). */
export function lienFiche(e) {
  if (!e.entity_id) return null
  const id = e.entity_id
  return {
    produits: `/produits/${id}`, stock: `/produits/${id}`, clients: `/clients/${id}`, fournisseurs: `/fournisseurs/${id}`, utilisateurs: `/carte/${id}`, caisses: `/caisses/${id}`,
  }[e.entity] || null
}

const LIBELLES = {
  stock: 'Stock', motif: 'Motif', note: 'Note', caissier: 'Caissier', caisse: 'Caisse', email: 'E-mail saisi', navigateur: 'Navigateur',
  fond_initial: 'Fond de caisse', attendu_especes: 'Espèces attendues', compte_especes: 'Espèces comptées', ecart: 'Écart', ventes: 'Nombre de ventes', chiffre_affaires: "Chiffre d'affaires",
  categorie: 'Catégorie', justificatif: 'N° de justificatif', reglee_par: 'Réglée par', piece_jointe: 'Pièce jointe', articles: "Nombre d'articles", remise: 'Remise', paiements: 'Paiements',
  mode: 'Mode', adresse: 'Adresse', livreur: 'Livreur', bon: 'N° de bon', paye: 'Payé', reste: 'Reste dû', reference: 'Référence', solde_restant: 'Solde restant',
  pass: 'Mot de passe', photo_user: 'Photo', section: 'Section',
}

function libelleChamp(entity, champ) {
  const f = resources[entity]?.fields?.find?.((x) => x.name === champ)
  if (f?.label) return f.label
  if (LIBELLES[champ]) return LIBELLES[champ]
  const t = champ.replace(/^[a-z]+\./, '').replace(/[._]/g, ' ')   // « entreprise.nom » → « nom »
  return t.charAt(0).toUpperCase() + t.slice(1)
}

function valeur(entity, champ, v) {
  if (v === null || v === undefined || v === '') return '—'
  const opts = resources[entity]?.fields?.find?.((x) => x.name === champ)?.options
  if (Array.isArray(opts)) { const o = opts.find((x) => String(x.value) === String(v)); if (o) return o.label }
  if (typeof v === 'boolean') return v ? 'Oui' : 'Non'
  // Montants et quantités ; codes-barres, téléphones et références (longs ou commençant par 0) restent tels quels
  if (typeof v === 'number' || (typeof v === 'string' && /^-?(\d{1,7}|\d+\.\d+)$/.test(v) && !/^0\d/.test(v))) return qty(v)
  return String(v)
}

/** Détail d'une entrée : modifications (avant → après) ou informations simples. */
export function lignesDetail(e) {
  const d = e.detail
  if (!d || typeof d !== 'object') return { changements: [], infos: [] }
  if (e.entity === 'inventaires' && Array.isArray(d.ecarts)) {
    return { changements: d.ecarts.map((x) => ({ champ: x.nom, avant: qty(x.attendu), apres: qty(x.compte) })), infos: [], titres: ['Article', 'Attendu', 'Compté'] }
  }
  const changements = [], infos = []
  for (const [k, v] of Object.entries(d)) {
    if (Array.isArray(v) && v.length === 2 && e.action !== 'create') changements.push({ champ: libelleChamp(e.entity, k), avant: valeur(e.entity, k, v[0]), apres: valeur(e.entity, k, v[1]) })
    else if (!Array.isArray(v) || v.length) infos.push({ champ: libelleChamp(e.entity, k), valeur: Array.isArray(v) ? v.join(', ') : valeur(e.entity, k, v) })
  }
  return { changements, infos, titres: ['Champ', 'Avant', 'Après'] }
}
