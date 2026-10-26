// Catalogue numérique public : données de la vitrine, « Ma sélection » du client (gardée sur son téléphone), commande par WhatsApp.
import { reactive, watch } from 'vue'
import { api } from './api'
import { money } from './format'

export const vitrine = reactive({ data: null, erreur: '', chargement: false })

export async function chargerVitrine() {
  if (vitrine.data || vitrine.chargement) return
  vitrine.chargement = true; vitrine.erreur = ''
  try { vitrine.data = await api.get('catalogue') } catch (e) { vitrine.erreur = e.message } finally { vitrine.chargement = false }
}

/* ---- Adresses : celles du QR code (catalogue) et de chaque produit (étiquettes) ---- */
const base = () => `${location.origin}${location.pathname}`
export const urlCatalogue = () => `${base()}#/catalogue`
export const urlProduit = (id) => `${base()}#/catalogue/${id}`

/* ---- Ma sélection : { id: quantité }, conservée dans le navigateur du client (rien n'est envoyé au serveur) ---- */
const CLE = 'catalogue.selection'
function lire() { try { return JSON.parse(localStorage.getItem(CLE)) || {} } catch { return {} } }
export const selection = reactive(lire())
watch(selection, (v) => { try { localStorage.setItem(CLE, JSON.stringify(v)) } catch { /* navigation privée : sélection non conservée */ } }, { deep: true })

export function ajouter(id, n = 1) { selection[id] = Math.max(0, (selection[id] || 0) + n); if (!selection[id]) delete selection[id] }
export function vider() { for (const k of Object.keys(selection)) delete selection[k] }

/** Lignes de la sélection rapprochées du catalogue (un produit retiré de la vitrine disparaît de la sélection). */
export function lignesSelection(produits) {
  return Object.entries(selection).map(([id, q]) => ({ produit: produits.find((p) => p.id === Number(id)), q })).filter((l) => l.produit && l.q > 0)
}

/** Lien WhatsApp avec la commande déjà rédigée. */
export function lienWhatsApp(numero, lignes, intro = 'Bonjour, je souhaite commander :') {
  const txt = [intro, ...lignes.map(({ produit: p, q }) => `• ${q} × ${p.nom}${p.prix != null ? ` (${money(p.prix * q)})` : ''}`)]
  const total = lignes.every((l) => l.produit.prix != null) ? lignes.reduce((s, l) => s + l.produit.prix * l.q, 0) : null
  if (lignes.length > 1 && total != null) txt.push(`Total estimé : ${money(total)}`)
  txt.push('', `Vu sur votre catalogue : ${lignes.length === 1 ? urlProduit(lignes[0].produit.id) : urlCatalogue()}`)
  return `https://wa.me/${numero}?text=${encodeURIComponent(txt.join('\n'))}`
}

export const DISPO = { ok: ['success', 'Disponible'], bas: ['warning', 'Derniers articles'], rupture: ['danger', 'Épuisé'], service: ['primary', 'Prestation'] }
