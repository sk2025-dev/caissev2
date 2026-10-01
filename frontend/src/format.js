// Espace insécable classique (U+00A0) plutôt que l'espace fine (U+202F) de fr-FR : beaucoup plus lisible dans les grands nombres
const nf = { format: (v) => new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 }).format(v).replace(/\u202f/g, '\u00a0') }
let devise = 'FCFA'
export const setDevise = (d) => { devise = d || 'FCFA' }
export const getDevise = () => devise
export const money = (v) => (v === null || v === '' || v === undefined ? '—' : `${nf.format(Number(v))} ${devise}`)
export const number = (v) => nf.format(Number(v || 0))

export function date(v) {
  if (!v) return '—'
  const d = new Date(v.length <= 10 ? `${v}T00:00:00` : v.replace(' ', 'T') + 'Z')
  return isNaN(d) ? v : d.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' })
}

export function monthLabel(ym) {
  const d = new Date(`${ym}-01T00:00:00`)
  return d.toLocaleDateString('fr-FR', { month: 'short' }).replace('.', '')
}

export const compact = (v) => new Intl.NumberFormat('fr-FR', { notation: 'compact', maximumFractionDigits: 1 }).format(Number(v || 0))

export const alerteLibelle = (type) => ({ rupture: 'Rupture de stock', stock_bas: 'Stock bas', creances: 'Créance ancienne', ecart_caisse: 'Écart de caisse', licence: "Licence d'utilisation" }[type] || type)
export const alerteLien = (type, id) => ({ rupture: id ? `/produits/${id}` : '/stock', stock_bas: id ? `/produits/${id}` : '/stock', creances: id ? `/clients/${id}` : '/clients', ecart_caisse: '/sessions' }[type] || '/')

export const initiales = (nom) => (nom || '?').split(/\s+/).map((p) => p[0]).slice(0, 2).join('').toUpperCase()
export const today = () => new Date().toISOString().slice(0, 10)
// Quantités : jusqu'à 3 décimales, sans zéros inutiles (12 ou 2,5)
export const qty = (v) => new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 3 }).format(Number(v || 0)).replace(/\u202f/g, '\u00a0')
export const dateHeure = (v) => {
  if (!v) return '—'
  const d = new Date(v.replace(' ', 'T') + 'Z')
  return isNaN(d) ? v : d.toLocaleString('fr-FR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
}
export const heure = (v) => (v ? new Date(v.replace(' ', 'T') + 'Z').toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : '—')
