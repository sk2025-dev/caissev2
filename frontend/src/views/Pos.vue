<script setup>
// Écran de caisse : catalogue tactile, panier, paiements mixtes, ventes en attente, mouvements d'espèces, clôture et ticket.
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { api, auth, fileUrl, ApiError } from '../api'
import { lookups, loadLookups } from '../lookups'
import { money, qty, number, dateHeure } from '../format'
import { toast } from '../toast'
import Icon from '../components/Icon.vue'
import SearchSelect from '../components/SearchSelect.vue'
import QuickCreate from '../components/QuickCreate.vue'
import Ticket from '../components/Ticket.vue'
import ZReport from '../components/ZReport.vue'

const cat = ref({ produits: [], categories: [], modes: [] })
const session = ref(undefined)         // undefined = chargement, null = aucune caisse ouverte
const clients = ref([])
const erreur = ref('')

/* ---------- Chargement ---------- */
async function chargerCatalogue() {
  cat.value = await api.get('pos-catalogue')
}
async function chargerClients() {
  try { clients.value = (await api.get('clients', { sort: 'nom', dir: 'asc', per_page: 100 })).data } catch { /* non bloquant */ }
}
onMounted(async () => {
  try {
    await Promise.all([loadLookups(), chargerCatalogue(), chargerClients()])
    session.value = (await api.get('session-courante')).session
    restaurer()
    await chargerPaniers()
  } catch (e) { erreur.value = e.message }
  document.addEventListener('keydown', raccourcis)
})
onBeforeUnmount(() => document.removeEventListener('keydown', raccourcis))

/* ---------- Ouverture de caisse ---------- */
const ouverture = reactive({ idcaisse: '', fond: '0', busy: false, erreurs: {} })
watch(() => lookups.caisses, (c) => { if (!ouverture.idcaisse && c.length) ouverture.idcaisse = c[0].id }, { immediate: true })
async function ouvrir() {
  ouverture.busy = true; ouverture.erreurs = {}
  try {
    session.value = (await api.post('session-ouvrir', { idcaisse: ouverture.idcaisse, fond_initial: ouverture.fond || 0 })).session
    toast('Caisse ouverte — bonne vente !')
    await nextTick(); recherche.value?.focus()
  } catch (e) {
    if (e instanceof ApiError && Object.keys(e.errors).length) ouverture.erreurs = e.errors
    toast(e.message, 'error')
  } finally { ouverture.busy = false }
}

/* ---------- Catalogue ---------- */
const q = ref('')
const catSel = ref(null)
const recherche = ref(null)
const norm = (s) => String(s || '').normalize('NFD').replace(/\p{M}/gu, '').toLowerCase()
const visibles = computed(() => {
  const t = norm(q.value.trim())
  return cat.value.produits.filter((p) => (!catSel.value || p.idcat === catSel.value) && (!t || norm(p.nom).includes(t) || norm(p.sku).includes(t) || norm(p.code_barres).includes(t)))
})
function entrerRecherche() {
  const t = q.value.trim()
  if (!t) return
  // Code-barres ou référence exacte (douchette) : ajout direct
  const exact = cat.value.produits.find((p) => p.code_barres === t || (p.sku && p.sku.toLowerCase() === t.toLowerCase()))
  const choix = exact || (visibles.value.length === 1 ? visibles.value[0] : null)
  if (choix) { ajouter(choix); q.value = '' } else if (!visibles.value.length) toast('Aucun produit trouvé', 'error')
}
const stockEtat = (p) => {
  if (!p.stockable) return null
  if (p.stock_qty <= 0) return 'rupture'
  return p.seuil_alerte > 0 && p.stock_qty <= p.seuil_alerte ? 'bas' : null
}

/* ---------- Panier ---------- */
const lignes = ref([])
const remise = reactive({ mode: 'montant', valeur: '' })
const idclient = ref('')
const prixLibre = computed(() => lookups.reglages.prix_libre || auth.user?.admin)

function ajouter(p) {
  if (p.stockable && p.stock_qty <= 0 && !lookups.reglages.stock_negatif) { toast(`${p.nom} : en rupture de stock`, 'error'); return }
  const l = lignes.value.find((x) => x.idprod === p.id)
  if (l) { changerQte(l, l.quantite + 1); return }
  lignes.value.push({ idprod: p.id, nom: p.nom, unite: p.unite, stockable: p.stockable, stock_qty: p.stock_qty, prix_ref: p.prix_vente, prix: p.prix_vente, quantite: 1, tva_taux: p.tva_taux })
}
function changerQte(l, v) {
  let n = Number(v)
  if (!(n > 0)) n = l.unite === 'pièce' ? 1 : 0.001
  if (l.unite === 'pièce') n = Math.max(1, Math.round(n))
  if (l.stockable && !lookups.reglages.stock_negatif && n > l.stock_qty) { toast(`Stock disponible : ${qty(l.stock_qty)} ${l.unite}`, 'error'); n = l.stock_qty }
  l.quantite = n
}
const retirer = (l) => { lignes.value = lignes.value.filter((x) => x !== l) }
function vider() { lignes.value = []; remise.valeur = ''; idclient.value = ''; cle.value = nouvelleCle() }

const brut = computed(() => lignes.value.reduce((s, l) => s + l.prix * l.quantite, 0))
const remiseMontant = computed(() => {
  const v = Number(remise.valeur)
  if (!(v > 0)) return 0
  return Math.min(brut.value, Math.round((remise.mode === 'pct' ? (brut.value * Math.min(v, 100)) / 100 : v) * 100) / 100)
})
const total = computed(() => Math.max(0, Math.round((brut.value - remiseMontant.value) * 100) / 100))
const tva = computed(() => {
  if (!brut.value) return 0
  const t = lignes.value.reduce((s, l) => s + (l.tva_taux > 0 ? (l.prix * l.quantite * l.tva_taux) / (100 + l.tva_taux) : 0), 0)
  return Math.round(t * (total.value / brut.value))
})
const nbArticles = computed(() => lignes.value.reduce((s, l) => s + l.quantite, 0))

// Brouillon conservé dans ce navigateur : un rechargement accidentel ne perd pas le panier
const cleStockage = () => `caisse-panier-${auth.user?.id || ''}`
function restaurer() {
  try {
    const d = JSON.parse(localStorage.getItem(cleStockage()) || 'null')
    if (d?.lignes?.length) { lignes.value = d.lignes; remise.mode = d.remise?.mode || 'montant'; remise.valeur = d.remise?.valeur || ''; idclient.value = d.idclient || '' }
  } catch { /* stockage indisponible */ }
}
watch([lignes, () => remise.valeur, () => remise.mode, idclient], () => {
  try { localStorage.setItem(cleStockage(), JSON.stringify({ lignes: lignes.value, remise: { mode: remise.mode, valeur: remise.valeur }, idclient: idclient.value })) } catch { /* ignoré */ }
}, { deep: true })

/* ---------- Client ---------- */
const optionsClients = computed(() => clients.value.map((c) => ({ value: c.idclient, label: c.nom + (Number(c.solde) > 0 ? ` — doit ${money(c.solde)}` : ''), short: c.nom })))
const clientChoisi = computed(() => clients.value.find((c) => c.idclient === idclient.value))
const nouveauClient = ref(null)
const specClient = { label: 'client', resource: 'clients', nameField: 'nom', fields: [{ name: 'nom', label: 'Nom du client' }, { name: 'telephone', label: 'Téléphone (facultatif)', type: 'phone' }] }
async function clientCree(row) {
  nouveauClient.value = null
  await chargerClients()
  idclient.value = row.idclient
}

/* ---------- Paiement ---------- */
const nouvelleCle = () => (crypto.randomUUID ? crypto.randomUUID().replace(/-/g, '').slice(0, 32) : Math.random().toString(36).slice(2) + Date.now().toString(36))
const cle = ref(nouvelleCle())   // identique tant que la vente n'est pas enregistrée : un renvoi ne crée jamais de doublon
const paiement = reactive({ ouvert: false, lignes: [], busy: false, erreur: '' })
const modeInfo = (code) => cat.value.modes.find((m) => m.code === code)
const payeTotal = computed(() => paiement.lignes.reduce((s, l) => s + (Number(l.montant) || 0), 0))
const especes = computed(() => paiement.lignes.filter((l) => modeInfo(l.mode)?.type === 'especes').reduce((s, l) => s + (Number(l.montant) || 0), 0))
const exces = computed(() => Math.max(0, Math.round((payeTotal.value - total.value) * 100) / 100))
const rendu = computed(() => (exces.value > 0 && exces.value <= especes.value + 0.004 ? exces.value : 0))
const invalideExces = computed(() => exces.value > 0 && rendu.value === 0)
const reste = computed(() => Math.max(0, Math.round((total.value - (payeTotal.value - rendu.value)) * 100) / 100))
const aPayer = computed(() => Math.max(0, Math.round((total.value - payeTotal.value) * 100) / 100))

function ouvrirPaiement() {
  if (!lignes.value.length) return
  paiement.erreur = ''
  paiement.lignes = [{ mode: cat.value.modes.find((m) => m.type === 'especes')?.code || cat.value.modes[0]?.code, montant: total.value, reference: '' }]
  paiement.ouvert = true
  nextTick(() => { const i = document.querySelector('.modal.pay .pay-line input.big'); i?.focus(); i?.select() })
}
function ajouterMode(code) {
  const l = paiement.lignes.find((x) => x.mode === code)
  if (l) { if (!(Number(l.montant) > 0) && aPayer.value > 0) l.montant = aPayer.value; return }
  paiement.lignes.push({ mode: code, montant: aPayer.value || '', reference: '' })
}
const retirerMode = (l) => { paiement.lignes = paiement.lignes.filter((x) => x !== l) }
const billets = computed(() => {
  const t = total.value
  const arrondis = [500, 1000, 2000, 5000, 10000, 20000, 50000].map((b) => Math.ceil(t / b) * b)
  return [...new Set([t, ...arrondis])].filter((v) => v >= t).slice(0, 5)
})
const ticket = ref(null)
async function valider() {
  paiement.erreur = ''
  if (reste.value > 0 && !idclient.value) { paiement.erreur = 'Choisissez un client pour enregistrer le reste à crédit.'; return }
  paiement.busy = true
  try {
    const res = await api.post('vente-creer', {
      cle: cle.value,
      idclient: idclient.value || null,
      remise: remiseMontant.value,
      lignes: lignes.value.map((l) => ({ idprod: l.idprod, quantite: l.quantite, prix_unitaire: l.prix })),
      paiements: paiement.lignes.filter((l) => Number(l.montant) > 0).map((l) => ({ mode: l.mode, montant: Number(l.montant), reference: l.reference })),
    })
    ticket.value = res.vente
    paiement.ouvert = false
    vider()
    chargerCatalogue(); chargerClients()
  } catch (e) {
    if (!(e instanceof ApiError)) throw e
    paiement.erreur = e.message
    if (e.code === 'caisse_fermee') { session.value = null; paiement.ouvert = false }
    if (e.code === 'stock_insuffisant') chargerCatalogue()
  } finally { paiement.busy = false }
}
function nouvelleVente() { ticket.value = null; nextTick(() => recherche.value?.focus()) }
const imprimer = () => window.print()

/* ---------- Ventes en attente ---------- */
const paniers = ref([])
const panneauAttente = ref(false)
async function chargerPaniers() { try { paniers.value = (await api.get('paniers')).paniers } catch { /* non bloquant */ } }
async function mettreEnAttente() {
  if (!lignes.value.length) return
  const libelle = (clientChoisi.value?.nom || '').slice(0, 80)
  try {
    await api.post('paniers', { libelle, contenu: { lignes: lignes.value, remise: { ...remise }, idclient: idclient.value } })
    vider(); await chargerPaniers(); toast('Vente mise en attente')
  } catch (e) { toast(e.message, 'error') }
}
async function reprendre(p) {
  if (lignes.value.length) { toast('Terminez ou mettez en attente la vente en cours d\'abord', 'error'); return }
  const c = p.contenu
  // Prix et stock rafraîchis depuis le catalogue (ils ont pu changer entre-temps)
  lignes.value = (c.lignes || []).map((l) => {
    const prod = cat.value.produits.find((x) => x.id === l.idprod)
    return prod ? { ...l, nom: prod.nom, stock_qty: prod.stock_qty, prix_ref: prod.prix_vente, prix: l.prix === l.prix_ref ? prod.prix_vente : l.prix } : l
  })
  remise.mode = c.remise?.mode || 'montant'; remise.valeur = c.remise?.valeur || ''; idclient.value = c.idclient || ''
  try { await api.del('paniers', p.idpanier) } catch { /* ignoré */ }
  await chargerPaniers(); panneauAttente.value = false
}
async function supprimerAttente(p) { try { await api.del('paniers', p.idpanier); await chargerPaniers() } catch (e) { toast(e.message, 'error') } }

/* ---------- Mouvements d'espèces ---------- */
const mvt = reactive({ ouvert: false, type: 'sortie', montant: '', motif: '', busy: false, erreurs: {} })
function ouvrirMvt(type) { Object.assign(mvt, { ouvert: true, type, montant: '', motif: '', erreurs: {} }) }
async function enregistrerMvt() {
  mvt.busy = true; mvt.erreurs = {}
  try {
    await api.post('operation-caisse', { type: mvt.type, montant: mvt.montant, motif: mvt.motif })
    toast(mvt.type === 'sortie' ? 'Sortie de caisse enregistrée' : 'Entrée de caisse enregistrée'); mvt.ouvert = false
  } catch (e) {
    if (e instanceof ApiError && Object.keys(e.errors).length) mvt.erreurs = e.errors; else toast(e.message, 'error')
  } finally { mvt.busy = false }
}

/* ---------- Clôture ---------- */
const clo = reactive({ ouvert: false, rapport: null, compte: '', notes: '', busy: false, erreurs: {}, final: null })
async function ouvrirCloture() {
  if (lignes.value.length) { toast('Une vente est en cours : terminez-la ou mettez-la en attente', 'error'); return }
  try {
    clo.rapport = await api.get('session-rapport', { id: session.value.idsession })
    Object.assign(clo, { ouvert: true, compte: '', notes: '', erreurs: {}, final: null })
  } catch (e) { toast(e.message, 'error') }
}
const ecart = computed(() => (clo.compte === '' || !clo.rapport ? null : Math.round((Number(clo.compte) - clo.rapport.attendu_especes) * 100) / 100))
async function cloturer() {
  clo.busy = true; clo.erreurs = {}
  try {
    const res = await api.post('session-cloturer', { compte_especes: clo.compte, notes: clo.notes })
    clo.final = res.rapport; session.value = null
    try { localStorage.removeItem(cleStockage()) } catch { /* ignoré */ }
  } catch (e) {
    if (e instanceof ApiError && Object.keys(e.errors).length) clo.erreurs = e.errors; else toast(e.message, 'error')
  } finally { clo.busy = false }
}
function finCloture() { clo.ouvert = false; clo.final = null }

/* ---------- Raccourcis clavier ---------- */
function raccourcis(e) {
  if (!session.value) return
  if (e.key === 'F2') { e.preventDefault(); recherche.value?.focus() }
  else if (e.key === 'F4') { e.preventDefault(); if (!paiement.ouvert && !ticket.value) ouvrirPaiement() }
  else if (e.key === 'F8') { e.preventDefault(); mettreEnAttente() }
  else if (e.key === 'Escape' && paiement.ouvert && !paiement.busy) paiement.ouvert = false
}
</script>

<template>
  <main class="page pos-page">
    <div v-if="erreur" class="alert">{{ erreur }}</div>
    <div v-else-if="session === undefined" class="skeleton" style="height:420px" />

    <!-- ===== Caisse fermée : ouverture ===== -->
    <section v-else-if="session === null && !clo.final" class="card open-card reveal">
      <span class="open-ico"><Icon name="lock" :size="30" /></span>
      <h1>Ouvrir la caisse</h1>
      <p class="muted">Comptez le fond de caisse présent dans le tiroir avant de commencer.</p>
      <div class="field" :class="{ invalid: ouverture.erreurs.idcaisse }">
        <label for="o-c">Caisse</label>
        <SearchSelect id="o-c" v-model="ouverture.idcaisse" :options="lookups.caisses.map((c) => ({ value: c.id, label: c.nom }))" />
        <span v-if="ouverture.erreurs.idcaisse" class="error">{{ ouverture.erreurs.idcaisse }}</span>
      </div>
      <div class="field" :class="{ invalid: ouverture.erreurs.fond_initial }">
        <label for="o-f">Fond de caisse (espèces)</label>
        <input id="o-f" v-model="ouverture.fond" class="input big" type="number" min="0" step="1" inputmode="numeric" @keydown.enter="ouvrir" />
        <span v-if="ouverture.erreurs.fond_initial" class="error">{{ ouverture.erreurs.fond_initial }}</span>
      </div>
      <button class="btn primary lg" :disabled="ouverture.busy" @click="ouvrir"><Icon name="play" :size="18" /> {{ ouverture.busy ? 'Ouverture…' : 'Ouvrir la caisse' }}</button>
    </section>

    <!-- ===== Caisse ouverte ===== -->
    <div v-else-if="session" class="poscols">
      <section class="pos-main">
        <div class="pos-bar">
          <div class="search">
            <Icon name="search" :size="18" />
            <input ref="recherche" v-model="q" class="input" type="search" placeholder="Produit, référence ou code-barres…  (F2)" aria-label="Rechercher un produit" autocomplete="off" autofocus @keydown.enter.prevent="entrerRecherche" />
          </div>
          <button class="btn" @click="panneauAttente = true"><Icon name="pause" :size="16" /> En attente<span v-if="paniers.length" class="badge primary">{{ paniers.length }}</span></button>
          <button class="btn" title="Entrée d'espèces" @click="ouvrirMvt('entree')"><Icon name="plus" :size="16" /> Entrée</button>
          <button class="btn" title="Sortie d'espèces" @click="ouvrirMvt('sortie')"><Icon name="minus" :size="16" /> Sortie</button>
          <button class="btn danger" @click="ouvrirCloture"><Icon name="lock" :size="16" /> Clôturer</button>
        </div>

        <div class="chips" role="tablist" aria-label="Catégories">
          <button class="chip" :class="{ on: !catSel }" role="tab" @click="catSel = null">Tout</button>
          <button v-for="c in cat.categories" :key="c.id" class="chip" :class="{ on: catSel === c.id }" role="tab" @click="catSel = catSel === c.id ? null : c.id">{{ c.nom }}</button>
        </div>

        <div v-if="!visibles.length" class="empty"><Icon name="inbox" :size="40" /><div>{{ cat.produits.length ? 'Aucun produit ne correspond.' : 'Le catalogue est vide. Ajoutez des produits pour commencer à vendre.' }}</div></div>
        <div v-else class="grid-prod">
          <button v-for="p in visibles" :key="p.id" class="prod" :class="{ out: stockEtat(p) === 'rupture' }" :disabled="stockEtat(p) === 'rupture' && !lookups.reglages.stock_negatif" @click="ajouter(p)">
            <span class="thumb"><img v-if="p.image" :src="fileUrl('produits', p.image)" alt="" loading="lazy" /><Icon v-else :name="p.type === 'service' ? 'clipboard' : 'box'" :size="26" /></span>
            <b>{{ p.nom }}</b>
            <span class="price">{{ money(p.prix_vente) }}</span>
            <span v-if="p.type === 'service'" class="stk svc">Service</span>
            <span v-else-if="stockEtat(p) === 'rupture'" class="stk bad">Rupture</span>
            <span v-else class="stk" :class="{ low: stockEtat(p) === 'bas' }">{{ qty(p.stock_qty) }} {{ p.unite }}</span>
          </button>
        </div>
      </section>

      <aside class="cart card" aria-label="Panier">
        <header>
          <h2><Icon name="cart" :size="20" /> Panier <small v-if="nbArticles">· {{ qty(nbArticles) }} article{{ nbArticles > 1 ? 's' : '' }}</small></h2>
          <button v-if="lignes.length" class="btn ghost sm" @click="vider">Vider</button>
        </header>

        <div class="lines">
          <div v-if="!lignes.length" class="empty small"><Icon name="cart" :size="34" /><div>Touchez un produit pour l'ajouter</div></div>
          <article v-for="l in lignes" :key="l.idprod" class="line">
            <div class="top"><b>{{ l.nom }}</b><button class="btn ghost icon sm" :aria-label="`Retirer ${l.nom}`" @click="retirer(l)"><Icon name="x" :size="15" /></button></div>
            <div class="bot">
              <div class="stepper">
                <button class="btn icon sm" :aria-label="`Moins de ${l.nom}`" @click="changerQte(l, l.quantite - 1)"><Icon name="minus" :size="14" /></button>
                <input :value="l.quantite" class="input qty" type="number" min="0" :step="l.unite === 'pièce' ? 1 : 0.1" inputmode="decimal" :aria-label="`Quantité de ${l.nom}`" @change="changerQte(l, $event.target.value)" @focus="$event.target.select()" />
                <button class="btn icon sm" :aria-label="`Plus de ${l.nom}`" @click="changerQte(l, l.quantite + 1)"><Icon name="plus" :size="14" /></button>
              </div>
              <span class="x">×</span>
              <input v-if="prixLibre" v-model.number="l.prix" class="input pu" type="number" min="0" :aria-label="`Prix de ${l.nom}`" @focus="$event.target.select()" />
              <span v-else class="pu-fixe">{{ money(l.prix) }}</span>
              <b class="lt">{{ money(l.prix * l.quantite) }}</b>
            </div>
          </article>
        </div>

        <div class="cart-foot">
          <div class="client">
            <SearchSelect id="p-client" v-model="idclient" :options="optionsClients" placeholder="Client (facultatif)" create-label="client" @create="(name) => (nouveauClient = name)" />
            <button v-if="idclient" class="btn ghost icon sm" aria-label="Retirer le client" @click="idclient = ''"><Icon name="x" :size="15" /></button>
          </div>
          <div class="remise">
            <label for="p-rem">Remise</label>
            <input id="p-rem" v-model="remise.valeur" class="input" type="number" min="0" inputmode="decimal" placeholder="0" />
            <div class="seg"><button :class="{ on: remise.mode === 'montant' }" @click="remise.mode = 'montant'">FCFA</button><button :class="{ on: remise.mode === 'pct' }" @click="remise.mode = 'pct'">%</button></div>
          </div>
          <div class="sum"><span>Sous-total</span><span>{{ money(brut) }}</span></div>
          <div v-if="remiseMontant > 0" class="sum"><span>Remise</span><span>−{{ money(remiseMontant) }}</span></div>
          <div v-if="tva > 0" class="sum sub"><span>dont TVA</span><span>{{ money(tva) }}</span></div>
          <div class="sum total"><span>Total</span><b>{{ money(total) }}</b></div>
          <div class="actions">
            <button class="btn" :disabled="!lignes.length" title="Mettre en attente (F8)" @click="mettreEnAttente"><Icon name="pause" :size="16" /> Attente</button>
            <button class="btn primary lg grow" :disabled="!lignes.length" title="Encaisser (F4)" @click="ouvrirPaiement"><Icon name="cash" :size="18" /> Encaisser {{ lignes.length ? money(total) : '' }}</button>
          </div>
        </div>
      </aside>
    </div>

    <!-- ===== Paiement ===== -->
    <div v-if="paiement.ouvert" class="overlay center" @click.self="!paiement.busy && (paiement.ouvert = false)">
      <div class="modal pay" role="dialog" aria-modal="true" aria-label="Paiement">
        <h3>Encaissement</h3>
        <div class="due"><span>À encaisser</span><b>{{ money(total) }}</b></div>

        <div class="modes">
          <button v-for="m in cat.modes" :key="m.code" class="chip" :class="{ on: paiement.lignes.some((l) => l.mode === m.code) }" @click="ajouterMode(m.code)">{{ m.libelle }}</button>
        </div>

        <div v-for="l in paiement.lignes" :key="l.mode" class="pay-line">
          <div class="head"><b>{{ modeInfo(l.mode)?.libelle }}</b><button v-if="paiement.lignes.length > 1" class="btn ghost icon sm" :aria-label="`Retirer ${modeInfo(l.mode)?.libelle}`" @click="retirerMode(l)"><Icon name="x" :size="14" /></button></div>
          <input v-model="l.montant" class="input big" type="number" min="0" inputmode="numeric" :aria-label="`Montant ${modeInfo(l.mode)?.libelle}`" @focus="$event.target.select()" @keydown.enter.prevent="valider" />
          <div v-if="modeInfo(l.mode)?.type === 'especes'" class="bills">
            <button v-for="b in billets" :key="b" class="chip" @click="l.montant = b">{{ b === total ? 'Exact' : number(b) }}</button>
          </div>
          <input v-else-if="modeInfo(l.mode)?.type !== 'especes'" v-model="l.reference" class="input" placeholder="Référence de la transaction (facultatif)" maxlength="80" />
        </div>

        <div class="recap">
          <div class="sum"><span>Payé</span><span>{{ money(payeTotal) }}</span></div>
          <div v-if="rendu > 0" class="sum change"><span>Monnaie à rendre</span><b>{{ money(rendu) }}</b></div>
          <div v-if="reste > 0" class="sum credit"><span>Reste à crédit</span><b>{{ money(reste) }}</b></div>
          <p v-if="reste > 0 && !idclient" class="warn">Choisissez un client dans le panier pour enregistrer ce reste à crédit.</p>
          <p v-else-if="reste > 0" class="muted small">Ce montant sera ajouté à la dette de {{ clientChoisi?.nom }}.</p>
          <p v-if="invalideExces" class="warn">Le montant dépasse le total : seul un excédent en espèces peut être rendu.</p>
        </div>
        <div v-if="paiement.erreur" class="alert" role="alert">{{ paiement.erreur }}</div>

        <div class="row">
          <button class="btn" :disabled="paiement.busy" @click="paiement.ouvert = false">Retour</button>
          <button class="btn primary lg" :disabled="paiement.busy || invalideExces || (reste > 0 && !idclient)" @click="valider"><Icon name="check" :size="18" /> {{ paiement.busy ? 'Enregistrement…' : reste > 0 ? 'Valider à crédit' : 'Valider la vente' }}</button>
        </div>
      </div>
    </div>

    <!-- ===== Ticket ===== -->
    <div v-if="ticket" class="overlay center">
      <div class="modal ticket-modal" role="dialog" aria-modal="true" aria-label="Vente enregistrée">
        <div class="done"><span class="ok"><Icon name="check" :size="26" /></span><div><h3>Vente enregistrée</h3><p v-if="Number(ticket.rendu) > 0" class="change-big">Rendre {{ money(ticket.rendu) }}</p></div></div>
        <div class="ticket-scroll"><Ticket :vente="ticket" /></div>
        <div class="row">
          <button class="btn" @click="imprimer"><Icon name="printer" :size="16" /> Imprimer</button>
          <button class="btn primary lg" autofocus @click="nouvelleVente"><Icon name="plus" :size="16" /> Nouvelle vente</button>
        </div>
      </div>
    </div>

    <!-- ===== Ventes en attente ===== -->
    <div v-if="panneauAttente" class="overlay center" @click.self="panneauAttente = false" @keydown.esc="panneauAttente = false">
      <div class="modal" role="dialog" aria-modal="true" aria-label="Ventes en attente" style="width:min(520px,calc(100% - 32px))">
        <h3>Ventes en attente</h3>
        <p v-if="!paniers.length" class="muted">Aucune vente en attente.</p>
        <div class="list">
          <div v-for="p in paniers" :key="p.idpanier" class="item">
            <span class="tile primary"><Icon name="pause" :size="18" /></span>
            <div class="grow"><b>{{ p.libelle || 'Sans nom' }}</b><span>{{ p.contenu.lignes.length }} ligne{{ p.contenu.lignes.length > 1 ? 's' : '' }} · {{ dateHeure(p.created_at) }}</span></div>
            <button class="btn sm primary" @click="reprendre(p)">Reprendre</button>
            <button class="btn ghost icon sm" :aria-label="`Supprimer ${p.libelle || 'cette vente en attente'}`" @click="supprimerAttente(p)"><Icon name="trash" :size="15" /></button>
          </div>
        </div>
        <div class="row"><button class="btn" @click="panneauAttente = false">Fermer</button></div>
      </div>
    </div>

    <!-- ===== Entrée / sortie d'espèces ===== -->
    <div v-if="mvt.ouvert" class="overlay center" @click.self="mvt.ouvert = false" @keydown.esc="mvt.ouvert = false">
      <form class="modal" novalidate role="dialog" aria-modal="true" :aria-label="mvt.type === 'sortie' ? 'Sortie de caisse' : 'Entrée de caisse'" @submit.prevent="enregistrerMvt">
        <h3>{{ mvt.type === 'sortie' ? 'Sortie d\'espèces' : 'Entrée d\'espèces' }}</h3>
        <p class="muted">{{ mvt.type === 'sortie' ? 'Dépense payée avec le tiroir-caisse, retrait, dépôt en banque…' : 'Apport de monnaie, fonds ajoutés au tiroir…' }}</p>
        <div style="display:flex;flex-direction:column;gap:14px;margin-top:14px">
          <div class="field" :class="{ invalid: mvt.erreurs.montant }"><label for="m-mt">Montant</label><input id="m-mt" v-model="mvt.montant" class="input" type="number" min="1" inputmode="numeric" autofocus /><span v-if="mvt.erreurs.montant" class="error">{{ mvt.erreurs.montant }}</span></div>
          <div class="field" :class="{ invalid: mvt.erreurs.motif }"><label for="m-mo">Motif <span class="req">*</span></label><input id="m-mo" v-model="mvt.motif" class="input" maxlength="200" placeholder="Ex. achat de sachets" /><span v-if="mvt.erreurs.motif" class="error">{{ mvt.erreurs.motif }}</span></div>
        </div>
        <div class="row"><button type="button" class="btn" @click="mvt.ouvert = false">Annuler</button><button class="btn primary" :disabled="mvt.busy">Enregistrer</button></div>
      </form>
    </div>

    <!-- ===== Clôture ===== -->
    <div v-if="clo.ouvert" class="overlay center">
      <div class="modal close-modal" role="dialog" aria-modal="true" aria-label="Clôture de caisse">
        <template v-if="!clo.final">
          <ZReport :rapport="clo.rapport" />
          <div class="count">
            <div class="field" :class="{ invalid: clo.erreurs.compte_especes }">
              <label for="c-cpt">Espèces comptées dans le tiroir</label>
              <input id="c-cpt" v-model="clo.compte" class="input big" type="number" min="0" inputmode="numeric" autofocus @keydown.enter.prevent="cloturer" />
              <span v-if="clo.erreurs.compte_especes" class="error">{{ clo.erreurs.compte_especes }}</span>
            </div>
            <div v-if="ecart !== null" class="ecart" :class="ecart === 0 ? 'ok' : 'ko'">{{ ecart === 0 ? 'Caisse juste ✔' : `Écart : ${ecart > 0 ? '+' : ''}${money(ecart)}` }}</div>
            <div class="field" :class="{ invalid: clo.erreurs.notes }">
              <label for="c-nt">Note {{ ecart ? '(explication de l\'écart)' : '(facultatif)' }}</label>
              <input id="c-nt" v-model="clo.notes" class="input" maxlength="500" />
              <span v-if="clo.erreurs.notes" class="error">{{ clo.erreurs.notes }}</span>
            </div>
          </div>
          <div class="row"><button class="btn" @click="clo.ouvert = false">Annuler</button><button class="btn danger" :disabled="clo.busy || clo.compte === ''" @click="cloturer"><Icon name="lock" :size="16" /> {{ clo.busy ? 'Clôture…' : 'Clôturer la caisse' }}</button></div>
        </template>
        <template v-else>
          <div class="done"><span class="ok"><Icon name="check" :size="26" /></span><div><h3>Caisse clôturée</h3><p class="muted">Merci, à demain !</p></div></div>
          <ZReport :rapport="clo.final" />
          <div class="row"><button class="btn" @click="imprimer"><Icon name="printer" :size="16" /> Imprimer</button><button class="btn primary" @click="finCloture">Terminer</button></div>
        </template>
      </div>
    </div>

    <QuickCreate v-if="nouveauClient !== null" :spec="specClient" :name="nouveauClient" @close="nouveauClient = null" @created="clientCree" />
  </main>
</template>

<style scoped>
.pos-page { max-width: none; }
.open-card { max-width: 440px; margin: 6vh auto; padding: 34px; display: flex; flex-direction: column; gap: 16px; text-align: center; }
.open-card .field { text-align: left; }
.open-ico { width: 68px; height: 68px; margin: 0 auto; border-radius: 22px; display: grid; place-items: center; color: #fff; background: linear-gradient(135deg, var(--primary), color-mix(in srgb, var(--primary) 55%, #fff)); box-shadow: 0 14px 30px color-mix(in srgb, var(--primary) 40%, transparent); }
.btn.lg { padding: 14px 22px; font-size: 16px; border-radius: 16px; }
.input.big { font-size: 22px; font-weight: 700; padding: 12px 14px; }

.poscols { display: grid; grid-template-columns: minmax(0, 1fr) 400px; gap: 18px; align-items: start; }
.pos-main { min-width: 0; display: flex; flex-direction: column; gap: 14px; }
.pos-bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.pos-bar .search { position: relative; flex: 1; min-width: 220px; }
.pos-bar .search svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--muted); }
.pos-bar .search .input { padding-left: 42px; height: 46px; }
.pos-bar .badge { margin-left: 6px; }
.chips { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 2px; }
.chip { flex: none; border: 1px solid var(--border); background: var(--surface); color: var(--text); padding: 8px 16px; border-radius: 999px; font: inherit; font-weight: 600; font-size: 14px; cursor: pointer; transition: all .15s; }
.chip:hover { border-color: var(--primary); }
.chip.on { background: var(--primary); border-color: var(--primary); color: #fff; }

.grid-prod { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; }
.prod { display: flex; flex-direction: column; align-items: flex-start; gap: 6px; text-align: left; padding: 12px; border: 1px solid var(--border); background: var(--surface); border-radius: 18px; cursor: pointer; font: inherit; color: inherit; transition: transform .15s, box-shadow .15s, border-color .15s; }
.prod:hover:not(:disabled) { transform: translateY(-3px); border-color: var(--primary); box-shadow: 0 12px 26px color-mix(in srgb, var(--primary) 18%, transparent); }
.prod:active:not(:disabled) { transform: scale(.97); }
.prod:disabled { opacity: .5; cursor: not-allowed; }
.prod .thumb { width: 100%; aspect-ratio: 16 / 10; border-radius: 12px; background: var(--surface-2); display: grid; place-items: center; color: var(--muted); overflow: hidden; }
.prod .thumb img { width: 100%; height: 100%; object-fit: cover; }
.prod b { font-size: 14px; line-height: 1.25; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 2.5em; }
.prod .price { font-weight: 800; color: var(--primary); }
.stk { font-size: 11.5px; padding: 2px 8px; border-radius: 999px; background: color-mix(in srgb, var(--success) 14%, transparent); color: var(--success); font-weight: 600; }
.stk.low { background: color-mix(in srgb, var(--warning) 18%, transparent); color: var(--warning); }
.stk.bad { background: color-mix(in srgb, var(--danger) 14%, transparent); color: var(--danger); }
.stk.svc { background: color-mix(in srgb, var(--primary) 14%, transparent); color: var(--primary); }

.cart { position: sticky; top: 12px; display: flex; flex-direction: column; max-height: calc(100vh - 100px); padding: 0; overflow: hidden; }
.cart header { display: flex; justify-content: space-between; align-items: center; padding: 16px 18px 8px; }
.cart header h2 { display: flex; align-items: center; gap: 8px; font-size: 18px; margin: 0; }
.cart header small { font-weight: 500; color: var(--muted); }
.lines { flex: 1; overflow-y: auto; padding: 4px 14px; min-height: 120px; }
.empty.small { padding: 26px 0; }
.line { padding: 10px 0; border-bottom: 1px dashed var(--border); animation: pop .2s var(--ease); }
.line .top { display: flex; justify-content: space-between; gap: 8px; align-items: flex-start; }
.line .top b { font-size: 14px; line-height: 1.3; }
.line .bot { display: flex; align-items: center; gap: 8px; margin-top: 6px; flex-wrap: wrap; }
.stepper { display: flex; align-items: center; gap: 4px; }
.input.qty { width: 62px; text-align: center; padding: 6px; font-weight: 700; }
.input.pu { width: 92px; padding: 6px 8px; }
.pu-fixe { color: var(--muted); font-size: 13px; }
.x { color: var(--muted); }
.lt { margin-left: auto; }
.cart-foot { padding: 12px 18px 18px; border-top: 1px solid var(--border); display: flex; flex-direction: column; gap: 8px; background: color-mix(in srgb, var(--surface-2) 60%, var(--surface)); }
.client { display: flex; gap: 4px; align-items: center; }
.client > :first-child { flex: 1; }
.remise { display: flex; align-items: center; gap: 8px; }
.remise label { font-size: 13px; color: var(--muted); }
.remise .input { width: 90px; padding: 6px 10px; }
.sum { display: flex; justify-content: space-between; }
.sum.sub { color: var(--muted); font-size: 13px; }
.sum.total { font-size: 22px; font-weight: 800; margin-top: 2px; }
.actions { display: flex; gap: 8px; margin-top: 4px; }
.actions .grow { flex: 1; justify-content: center; white-space: nowrap; padding-inline: 12px; }

.modal.pay { width: min(520px, calc(100% - 32px)); max-height: calc(100vh - 32px); overflow-y: auto; }
.due { display: flex; justify-content: space-between; align-items: baseline; padding: 14px 16px; border-radius: 16px; background: var(--surface-2); margin: 8px 0 14px; }
.due b { font-size: 28px; }
.modes { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
.pay-line { padding: 12px; border: 1px solid var(--border); border-radius: 16px; margin-bottom: 10px; display: flex; flex-direction: column; gap: 8px; }
.pay-line .head { display: flex; justify-content: space-between; align-items: center; }
.bills { display: flex; flex-wrap: wrap; gap: 6px; }
.bills .chip { padding: 6px 12px; font-size: 13px; }
.recap { margin-top: 10px; display: flex; flex-direction: column; gap: 6px; }
.recap .change { font-size: 20px; color: var(--success); }
.recap .credit { font-size: 18px; color: var(--danger); }
.warn { margin: 0; color: var(--danger); font-size: 13px; }
.small { font-size: 13px; margin: 0; }

.ticket-modal { width: min(460px, calc(100% - 32px)); max-height: calc(100vh - 32px); display: flex; flex-direction: column; }
.ticket-scroll { overflow-y: auto; margin: 12px 0 0; padding: 4px; background: var(--surface-2); border-radius: 16px; }
.done { display: flex; align-items: center; gap: 14px; }
.done h3 { margin: 0; }
.done .ok { width: 48px; height: 48px; border-radius: 50%; display: grid; place-items: center; background: var(--success); color: #fff; animation: pop .4s var(--ease); }
.change-big { margin: 2px 0 0; font-size: 22px; font-weight: 800; color: var(--success); }

.close-modal { width: min(560px, calc(100% - 32px)); max-height: calc(100vh - 32px); overflow-y: auto; }
.count { display: flex; flex-direction: column; gap: 12px; margin-top: 18px; padding-top: 16px; border-top: 2px solid var(--border); }
.ecart { padding: 10px 14px; border-radius: 12px; font-weight: 700; }
.ecart.ok { background: color-mix(in srgb, var(--success) 14%, transparent); color: var(--success); }
.ecart.ko { background: color-mix(in srgb, var(--danger) 12%, transparent); color: var(--danger); }

@media (max-width: 980px) {
  .poscols { grid-template-columns: 1fr; }
  .cart { position: static; max-height: none; }
}
</style>
