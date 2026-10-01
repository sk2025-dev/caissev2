// Numéros de téléphone : pays, indicatif, masque de saisie. Valeur stockée : « +225 07 12 34 56 78 ».
import { DEFAULT_COUNTRY } from './config.js'

// mask : « # » = un chiffre. trunk : préfixe national (« 0 ») à retirer en format international.
export const COUNTRIES = [
  { iso: 'CI', name: "Côte d'Ivoire", dial: '225', mask: '## ## ## ## ##', flag: '🇨🇮' },
  { iso: 'SN', name: 'Sénégal', dial: '221', mask: '## ### ## ##', flag: '🇸🇳' },
  { iso: 'ML', name: 'Mali', dial: '223', mask: '## ## ## ##', flag: '🇲🇱' },
  { iso: 'BF', name: 'Burkina Faso', dial: '226', mask: '## ## ## ##', flag: '🇧🇫' },
  { iso: 'BJ', name: 'Bénin', dial: '229', mask: '## ## ## ## ##', flag: '🇧🇯' },
  { iso: 'TG', name: 'Togo', dial: '228', mask: '## ## ## ##', flag: '🇹🇬' },
  { iso: 'NE', name: 'Niger', dial: '227', mask: '## ## ## ##', flag: '🇳🇪' },
  { iso: 'GN', name: 'Guinée', dial: '224', mask: '### ## ## ##', flag: '🇬🇳' },
  { iso: 'CM', name: 'Cameroun', dial: '237', mask: '# ## ## ## ##', flag: '🇨🇲' },
  { iso: 'GA', name: 'Gabon', dial: '241', mask: '## ## ## ##', flag: '🇬🇦' },
  { iso: 'CG', name: 'Congo', dial: '242', mask: '## ### ####', flag: '🇨🇬' },
  { iso: 'CD', name: 'RD Congo', dial: '243', mask: '### ### ###', flag: '🇨🇩', trunk: '0' },
  { iso: 'MG', name: 'Madagascar', dial: '261', mask: '## ## ### ##', flag: '🇲🇬', trunk: '0' },
  { iso: 'MA', name: 'Maroc', dial: '212', mask: '# ## ## ## ##', flag: '🇲🇦', trunk: '0' },
  { iso: 'DZ', name: 'Algérie', dial: '213', mask: '### ## ## ##', flag: '🇩🇿', trunk: '0' },
  { iso: 'TN', name: 'Tunisie', dial: '216', mask: '## ### ###', flag: '🇹🇳' },
  { iso: 'FR', name: 'France', dial: '33', mask: '# ## ## ## ##', flag: '🇫🇷', trunk: '0' },
  { iso: 'BE', name: 'Belgique', dial: '32', mask: '### ## ## ##', flag: '🇧🇪', trunk: '0' },
  { iso: 'CH', name: 'Suisse', dial: '41', mask: '## ### ## ##', flag: '🇨🇭', trunk: '0' },
  { iso: 'GB', name: 'Royaume-Uni', dial: '44', mask: '#### ######', flag: '🇬🇧', trunk: '0' },
  { iso: 'US', name: 'États-Unis / Canada', dial: '1', mask: '### ### ####', flag: '🇺🇸' },
  // Saisie libre pour les pays non listés : le numéro est saisi en entier, après le « + »
  { iso: 'XX', name: 'Autre pays', dial: '', mask: null, flag: '🌍' },
]

export const byIso = (iso) => COUNTRIES.find((c) => c.iso === iso) || COUNTRIES.find((c) => c.iso === DEFAULT_COUNTRY)
const maxDigits = (c) => (c.mask ? (c.mask.match(/#/g) || []).length : 15)

// Indicatifs du plus long au plus court (« 225 » avant « 2 »), hors « Autre pays »
const byDialLength = COUNTRIES.filter((c) => c.dial).sort((a, b) => b.dial.length - a.dial.length)

/** Décompose une valeur stockée en { iso, digits } (chiffres nationaux). Sans « + » : pays par défaut (anciennes saisies). */
export function parsePhone(value) {
  const v = String(value || '').trim()
  if (!v) return { iso: DEFAULT_COUNTRY, digits: '' }
  const all = v.replace(/\D/g, '')
  if (v.startsWith('+')) {
    const c = byDialLength.find((x) => all.startsWith(x.dial))
    if (c) return { iso: c.iso, digits: all.slice(c.dial.length) }
    return { iso: 'XX', digits: all }
  }
  const c = byIso(DEFAULT_COUNTRY)
  const d = c.trunk && all.startsWith(c.trunk) ? all.slice(c.trunk.length) : all
  return { iso: c.iso, digits: d }
}

/** Nettoie les chiffres saisis pour un pays (retire le « 0 » national, tronque à la longueur du masque). */
export function cleanDigits(iso, raw) {
  const c = byIso(iso)
  let d = String(raw).replace(/\D/g, '')
  if (c.trunk && d.startsWith(c.trunk)) d = d.slice(c.trunk.length)
  return d.slice(0, maxDigits(c))
}

/** Applique le masque aux chiffres : « 0712345678 » -> « 07 12 34 56 78 ». */
export function applyMask(iso, digits) {
  const c = byIso(iso)
  if (!c.mask) return digits.replace(/(\d{1,3})(?=\d)/g, '$1 ').trim()
  let out = '', i = 0
  for (const ch of c.mask) {
    if (i >= digits.length) break
    out += ch === '#' ? digits[i++] : ch
  }
  return out
}

/** Valeur à stocker : « +225 07 12 34 56 78 » (chaîne vide si aucun chiffre). */
export function toValue(iso, digits) {
  if (!digits) return ''
  const c = byIso(iso)
  return c.dial ? `+${c.dial} ${applyMask(iso, digits)}` : `+${applyMask(iso, digits)}`
}

/** Message d'erreur si le numéro est incomplet, sinon chaîne vide. */
export function phoneIssue(value) {
  if (!value) return ''
  const { iso, digits } = parsePhone(value)
  const c = byIso(iso)
  if (!c.mask) return digits.length >= 8 && digits.length <= 15 ? '' : 'Numéro invalide (8 à 15 chiffres)'
  const n = maxDigits(c)
  return digits.length === n ? '' : `Numéro incomplet : ${n} chiffres attendus (${digits.length} saisis)`
}
