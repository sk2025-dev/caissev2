// Libellés, couleurs et étapes des commandes / livraisons (miroir de api/lib/commandes.php)
export const STATUTS = {
  nouvelle: { label: 'Nouvelle', cls: 'primary' },
  preparation: { label: 'En préparation', cls: 'warning' },
  prete: { label: 'Prête', cls: 'primary' },
  en_livraison: { label: 'En livraison', cls: 'warning' },
  livree: { label: 'Livrée', cls: 'success' },
  annulee: { label: 'Annulée', cls: 'danger' },
}
export const libelleStatut = (c) => (c.statut === 'livree' && c.mode === 'retrait' ? 'Retirée' : STATUTS[c.statut]?.label || c.statut)
export const finale = (c) => c.statut === 'livree' || c.statut === 'annulee'

// Étape suivante proposée selon le statut ; « livree » passe par l'encaissement
export function suivante(c) {
  const seq = c.mode === 'livraison' ? ['nouvelle', 'preparation', 'prete', 'en_livraison', 'livree'] : ['nouvelle', 'preparation', 'prete', 'livree']
  const nxt = seq[seq.indexOf(c.statut) + 1]
  if (!nxt || finale(c)) return null
  return {
    statut: nxt,
    libelle: { preparation: 'Lancer la préparation', prete: 'Marquer prête', en_livraison: 'Partir en livraison', livree: c.mode === 'livraison' ? 'Livrée · encaisser' : 'Remise · encaisser' }[nxt],
  }
}

// Date locale « AAAA-MM-JJ HH:MM:SS » saisie à la commande (pas de conversion de fuseau)
export function datePrevue(v) {
  if (!v) return '—'
  const d = new Date(v.replace(' ', 'T'))
  return isNaN(d) ? v : d.toLocaleString('fr-FR', { weekday: 'short', day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
}
export const enRetard = (c) => !finale(c) && c.date_prevue && new Date(c.date_prevue.replace(' ', 'T')) < new Date()
