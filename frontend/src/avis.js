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
export const NOTES = { 5: 'Excellent', 4: 'Très bien', 3: 'Correct', 2: 'Décevant', 1: 'Mauvais' }
export const noteOptions = () => [5, 4, 3, 2, 1].map((n) => ({ value: n, label: `${'★'.repeat(n)}${'☆'.repeat(5 - n)}  ${n} — ${NOTES[n]}` }))
export const etoiles = (n) => '★'.repeat(Math.round(n || 0)) + '☆'.repeat(5 - Math.round(n || 0))
