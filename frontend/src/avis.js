// Avis clients : sources (réseaux, SMS, magasin), couleurs de repère, étoiles.
export const SOURCES = {
  facebook: { label: 'Facebook', couleur: '#1877f2', lettre: 'f' },
  google: { label: 'Google', couleur: '#ea4335', lettre: 'G' },
  tiktok: { label: 'TikTok', couleur: '#111827', lettre: '♪' },
  instagram: { label: 'Instagram', couleur: '#d62976', lettre: '◎' },
  whatsapp: { label: 'WhatsApp', couleur: '#1da851', lettre: 'W' },
  sms: { label: 'SMS', couleur: '#64748b', lettre: '✉' },
  magasin: { label: 'En magasin', couleur: 'var(--primary)', lettre: '⌂' },
  autre: { label: 'Autre', couleur: '#8b8fa3', lettre: '•' },
}
export const sourceOptions = () => Object.entries(SOURCES).map(([value, s]) => ({ value, label: s.label }))
export const sourceChoix = () => Object.entries(SOURCES).map(([value, s]) => ({ value, label: s.label, color: s.couleur, icon: s.lettre }))
export const NOTES = { 5: 'Excellent', 4: 'Très bien', 3: 'Correct', 2: 'Décevant', 1: 'Mauvais' }
export const etoiles = (n) => '★'.repeat(Math.round(n || 0)) + '☆'.repeat(5 - Math.round(n || 0))
