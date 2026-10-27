import { reactive } from 'vue'
import { showSplash } from './splash'

const BASE = import.meta.env.DEV ? '/api/index.php' : '../api/index.php'
export const justificatifUrl = (idop) => `${BASE}?r=justificatif&id=${idop}`

export const auth = reactive({ user: null, ready: false, notice: '' })

export class ApiError extends Error {
  constructor(status, message, errors, code) {
    super(message)
    this.status = status
    this.errors = errors || {}
    this.code = code || null
  }
}

export async function request(method, route, { params = {}, body = null, silent401 = false, timeout = 0 } = {}) {
  const qs = new URLSearchParams({ r: route })
  for (const [k, v] of Object.entries(params)) if (v !== '' && v != null) qs.set(k, v)

  const headers = { Accept: 'application/json' }
  if (auth.user?.csrf) headers['X-CSRF-Token'] = auth.user.csrf
  let payload
  if (body instanceof FormData) payload = body
  else if (body) { headers['Content-Type'] = 'application/json'; payload = JSON.stringify(body) }

  let res
  const arret = timeout ? new AbortController() : null
  const minuteur = arret ? setTimeout(() => arret.abort(), timeout) : null
  try {
    res = await fetch(`${BASE}?${qs}`, { method, headers, body: payload, credentials: 'same-origin', signal: arret?.signal })
  } catch {
    throw new ApiError(0, 'Serveur injoignable. Vérifiez votre connexion.')
  } finally { clearTimeout(minuteur) }
  const data = await res.json().catch(() => null)
  if (!res.ok) {
    if (res.status === 401 && !silent401) { auth.user = null; if (data?.code === 'inactivite') auth.notice = data.error }
    throw new ApiError(res.status, data?.error || `Erreur ${res.status}`, data?.errors, data?.code)
  }
  return data
}

/** Document généré à la volée (PDF) : renvoie un Blob ; une erreur du serveur (JSON) devient une ApiError. */
export async function requestBlob(route, params = {}) {
  const qs = new URLSearchParams({ r: route })
  for (const [k, v] of Object.entries(params)) if (v !== '' && v != null) qs.set(k, v)
  let res
  try {
    res = await fetch(`${BASE}?${qs}`, { credentials: 'same-origin' })
  } catch {
    throw new ApiError(0, 'Serveur injoignable. Vérifiez votre connexion.')
  }
  if (!res.ok) {
    const data = await res.json().catch(() => null)
    if (res.status === 401) auth.user = null
    throw new ApiError(res.status, data?.error || `Erreur ${res.status}`, data?.errors, data?.code)
  }
  return res.blob()
}

export const api = {
  get: (route, params) => request('GET', route, { params }),
  post: (route, body, params) => request('POST', route, { body, params }),
  del: (route, id) => request('DELETE', route, { params: { id } }),
}

export async function restoreSession() {
  try {
    auth.user = (await request('GET', 'auth/me', { silent401: true })).user
  } catch {
    auth.user = null
  }
  auth.ready = true
}

// Caissier « pur » : peut vendre mais ni administrer ni consulter toutes les ventes -> interface de caisse plein écran, sans menu latéral
export const estCaissier = (u = auth.user) => !!(u?.droits?.caisse && !u.droits.ventes_toutes && !u.admin && !u.super)
export const prenomDe = (u) => (u?.prenom || (u?.name || '').split(' ')[0] || '').trim()

export async function login(email, password) {
  const { user } = await request('POST', 'auth/login', { body: { email, password }, silent401: true })
  showSplash('bienvenue', prenomDe(user), user.photo ? fileUrl('utilisateurs', user.photo) : '')   // l'écran d'accueil couvre la page AVANT que le tableau de bord ne s'affiche
  auth.user = user
  auth.notice = ''
}

// La fermeture côté serveur se fait « au mieux » : une session déjà expirée (401), un jeton périmé ou un réseau coupé
// ne doivent jamais bloquer la déconnexion à l'écran.
export async function logout() {
  try { await request('POST', 'auth/logout', { silent401: true, timeout: 6000 }) } catch { /* déjà fermée ou serveur injoignable */ } finally { auth.user = null }
}

export const catalogueImageUrl = (name, dossier = 'produits') => `${BASE}?r=catalogue-image&d=${dossier}&f=${encodeURIComponent(name)}`   // photo publique (catalogue numérique)
export const fileUrl = (dir, name) => `${BASE}?r=fichier&d=${dir}&f=${encodeURIComponent(name)}`
