<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { api, auth, ApiError } from '../api'
import { lookups, loadLookups } from '../lookups'
import { money, qty, dateHeure, today } from '../format'
import { toast } from '../toast'
import Icon from '../components/Icon.vue'
import Pager from '../components/Pager.vue'
import SearchSelect from '../components/SearchSelect.vue'
import ExportButtons from '../components/ExportButtons.vue'

const peutEcrire = computed(() => !!auth.user?.droits?.stock_ecriture)
const onglet = ref('niveaux')

/* ---------- Niveaux ---------- */
const niveaux = ref([])
const nMeta = reactive({ total: 0, page: 1, pages: 1, per_page: 25 })
const nf = reactive({ q: '', etat: '', idcat: '', sort: 'nom', dir: 'asc', page: 1 })
const nLoading = ref(true)
let seq = 0
async function chargerNiveaux() {
  const my = ++seq; nLoading.value = true
  try {
    const r = await api.get('produits', { type: 'produit', q: nf.q, etat: nf.etat, idcat: nf.idcat, sort: nf.sort, dir: nf.dir, page: nf.page })
    if (my === seq) { niveaux.value = r.data; Object.assign(nMeta, r.meta) }
  } catch (e) { toast(e.message, 'error') } finally { if (my === seq) nLoading.value = false }
}
let timer
watch(() => nf.q, () => { clearTimeout(timer); timer = setTimeout(() => { nf.page = 1; chargerNiveaux() }, 300) })
watch(() => [nf.etat, nf.idcat], () => { nf.page = 1; chargerNiveaux() })
onBeforeUnmount(() => clearTimeout(timer))
function trier(col) { if (nf.sort === col) nf.dir = nf.dir === 'asc' ? 'desc' : 'asc'; else { nf.sort = col; nf.dir = 'asc' } nf.page = 1; chargerNiveaux() }
const etat = (p) => { const q = Number(p.stock_qty); return q <= 0 ? ['danger', 'Rupture'] : Number(p.seuil_alerte) > 0 && q <= Number(p.seuil_alerte) ? ['warning', 'Stock bas'] : ['success', 'OK'] }

/* ---------- Ajustement ---------- */
const aj = reactive({ produit: null, mode: 'delta', type: 'ajustement', quantite: '', motif: '', busy: false, erreurs: {} })
function ouvrirAjust(p) { Object.assign(aj, { produit: p, mode: 'delta', type: 'ajustement', quantite: '', motif: '', erreurs: {} }) }
const apresAjust = computed(() => {
  if (!aj.produit || aj.quantite === '') return null
  const q = Number(aj.quantite), s = Number(aj.produit.stock_qty)
  return aj.type === 'perte' ? s - Math.abs(aj.mode === 'set' ? q - s : q) : aj.mode === 'set' ? q : s + q
})
async function ajuster() {
  aj.busy = true; aj.erreurs = {}
  try {
    await api.post('stock-ajuster', { idprod: aj.produit.idprod, mode: aj.mode, type: aj.type, quantite: aj.quantite, motif: aj.motif })
    toast('Stock mis à jour'); aj.produit = null; chargerNiveaux(); if (onglet.value === 'mouvements') chargerMvts()
  } catch (e) { if (e instanceof ApiError && Object.keys(e.errors).length) aj.erreurs = e.errors; else toast(e.message, 'error') } finally { aj.busy = false }
}

/* ---------- Mouvements ---------- */
const mvts = ref([])
const mMeta = reactive({ total: 0, page: 1, pages: 1, per_page: 25 })
const mf = reactive({ type: '', from: '', to: '', page: 1 })
const typesMvt = [['initial', 'Stock initial'], ['entree_achat', "Entrée d'achat"], ['sortie_vente', 'Vente'], ['annulation_vente', 'Annulation de vente'], ['ajustement', 'Ajustement'], ['perte', 'Perte / casse'], ['inventaire', 'Inventaire']]
const mLoading = ref(false)
async function chargerMvts() {
  mLoading.value = true
  try { const r = await api.get('mouvements-stock', { type: mf.type, from: mf.from, to: mf.to, page: mf.page }); mvts.value = r.data; Object.assign(mMeta, r.meta) } catch (e) { toast(e.message, 'error') } finally { mLoading.value = false }
}
watch(() => [mf.type, mf.from, mf.to], () => { mf.page = 1; chargerMvts() })
watch(onglet, (o) => { if (o === 'mouvements' && !mvts.value.length) chargerMvts(); if (o === 'inventaire' && !inv.produits.length) chargerInventaire() })

/* ---------- Inventaire ---------- */
const inv = reactive({ produits: [], compte: {}, note: '', date: today(), q: '', busy: false, resultat: null, erreur: '' })
async function chargerInventaire() {
  try { inv.produits = (await api.get('produits', { type: 'produit', all: 1, sort: 'nom', dir: 'asc' })).data; inv.compte = {} } catch (e) { toast(e.message, 'error') }
}
const norm = (s) => String(s || '').normalize('NFD').replace(/\p{M}/gu, '').toLowerCase()
const invFiltre = computed(() => inv.produits.filter((p) => !inv.q || norm(p.nom).includes(norm(inv.q)) || norm(p.sku).includes(norm(inv.q)) || (p.code_barres || '').includes(inv.q)))
const nbSaisis = computed(() => Object.values(inv.compte).filter((v) => v !== '' && v != null).length)
const ecartDe = (p) => { const v = inv.compte[p.idprod]; return v === '' || v == null ? null : Math.round((Number(v) - Number(p.stock_qty)) * 1000) / 1000 }
async function validerInventaire() {
  inv.busy = true; inv.erreur = ''
  try {
    const lignes = Object.entries(inv.compte).filter(([, v]) => v !== '' && v != null).map(([idprod, compte]) => ({ idprod: Number(idprod), compte }))
    inv.resultat = (await api.post('inventaire', { lignes, note: inv.note, date: inv.date })).resultat
    toast(inv.resultat.nb_ecarts ? `Inventaire validé : ${inv.resultat.nb_ecarts} écart(s) régularisé(s)` : 'Inventaire validé : aucun écart')
    await Promise.all([chargerInventaire(), chargerNiveaux()]); inv.note = ''
  } catch (e) { inv.erreur = e instanceof ApiError ? (Object.values(e.errors)[0] || e.message) : e.message } finally { inv.busy = false }
}

onMounted(async () => { await loadLookups().catch(() => {}); chargerNiveaux() })
</script>

<template>
  <main class="page">
    <div class="page-head">
      <div><h1>Stock</h1><p>Niveaux, mouvements tracés et inventaire</p></div>
      <ExportButtons kind="stock" />
    </div>

    <div class="seg tabs" role="tablist">
      <button :class="{ on: onglet === 'niveaux' }" role="tab" @click="onglet = 'niveaux'"><Icon name="box" :size="16" /> Niveaux</button>
      <button :class="{ on: onglet === 'mouvements' }" role="tab" @click="onglet = 'mouvements'"><Icon name="history" :size="16" /> Mouvements</button>
      <button v-if="peutEcrire" :class="{ on: onglet === 'inventaire' }" role="tab" @click="onglet = 'inventaire'"><Icon name="clipboard" :size="16" /> Inventaire</button>
    </div>

    <!-- Niveaux -->
    <div v-if="onglet === 'niveaux'" class="card">
      <div class="toolbar">
        <div class="search"><Icon name="search" :size="16" /><input v-model="nf.q" class="input" type="search" placeholder="Produit, référence, code-barres…" aria-label="Rechercher" /></div>
        <select v-model="nf.etat" class="input filter" aria-label="Niveau"><option value="">Tous les niveaux</option><option value="rupture">En rupture</option><option value="bas">Stock bas</option><option value="ok">Stock correct</option></select>
        <select v-model="nf.idcat" class="input filter" aria-label="Catégorie"><option value="">Toutes catégories</option><option v-for="c in lookups.categories" :key="c.id" :value="c.id">{{ c.nom }}</option></select>
      </div>
      <div class="table-wrap" :aria-busy="nLoading">
        <table v-if="niveaux.length || nLoading" class="table stack">
          <thead><tr>
            <th class="sortable" @click="trier('nom')">Produit</th><th class="sortable" @click="trier('categorie')">Catégorie</th>
            <th class="num sortable" @click="trier('stock_qty')">En stock</th><th class="num">Seuil</th><th class="num">Coût moyen</th><th class="num sortable" @click="trier('valeur_stock')">Valeur</th><th>État</th><th v-if="peutEcrire" />
          </tr></thead>
          <tbody v-if="nLoading && !niveaux.length"><tr v-for="n in 6" :key="n"><td colspan="8"><div class="skeleton" style="height:22px" /></td></tr></tbody>
          <tbody v-else :style="{ opacity: nLoading ? .55 : 1 }">
            <tr v-for="p in niveaux" :key="p.idprod">
              <td data-label="Produit" class="strong"><RouterLink class="rowlink" :to="`/produits/${p.idprod}`">{{ p.nom }}</RouterLink></td>
              <td data-label="Catégorie">{{ p.categorie || '—' }}</td>
              <td data-label="En stock" class="num"><b>{{ qty(p.stock_qty) }}</b> {{ p.unite }}</td>
              <td data-label="Seuil" class="num">{{ Number(p.seuil_alerte) > 0 ? qty(p.seuil_alerte) : '—' }}</td>
              <td data-label="Coût moyen" class="num">{{ money(p.prix_achat) }}</td>
              <td data-label="Valeur" class="num">{{ money(p.valeur_stock) }}</td>
              <td data-label="État"><span class="badge" :class="etat(p)[0]">{{ etat(p)[1] }}</span></td>
              <td v-if="peutEcrire" class="actions"><button class="btn sm" @click="ouvrirAjust(p)"><Icon name="edit" :size="14" /> Ajuster</button></td>
            </tr>
          </tbody>
        </table>
        <div v-else class="empty"><Icon name="inbox" :size="40" /><div>Aucun produit ne correspond.</div></div>
      </div>
      <Pager :meta="nMeta" @goto="(p) => { nf.page = p; chargerNiveaux() }" />
    </div>

    <!-- Mouvements -->
    <div v-else-if="onglet === 'mouvements'" class="card">
      <div class="toolbar">
        <select v-model="mf.type" class="input filter" aria-label="Type de mouvement"><option value="">Tous les mouvements</option><option v-for="[k, l] in typesMvt" :key="k" :value="k">{{ l }}</option></select>
        <input v-model="mf.from" class="input date" type="date" aria-label="Du" /><input v-model="mf.to" class="input date" type="date" aria-label="Au" />
      </div>
      <div class="table-wrap" :aria-busy="mLoading">
        <table v-if="mvts.length" class="table stack">
          <thead><tr><th>Date</th><th>Produit</th><th>Type</th><th class="num">Quantité</th><th class="num">Stock après</th><th>Motif</th><th>Par</th></tr></thead>
          <tbody>
            <tr v-for="m in mvts" :key="m.idmvt">
              <td data-label="Date">{{ dateHeure(m.created_at) }}</td>
              <td data-label="Produit" class="strong"><RouterLink class="rowlink" :to="`/produits/${m.idprod}`">{{ m.produit }}</RouterLink></td>
              <td data-label="Type">{{ m.libelle_type }}</td>
              <td data-label="Quantité" class="num"><b :class="Number(m.quantite) >= 0 ? 'pos' : 'neg'">{{ Number(m.quantite) > 0 ? '+' : '' }}{{ qty(m.quantite) }}</b> {{ m.unite }}</td>
              <td data-label="Stock après" class="num">{{ qty(m.stock_apres) }}</td>
              <td data-label="Motif">{{ m.motif || '—' }}</td><td data-label="Par">{{ m.utilisateur || '—' }}</td>
            </tr>
          </tbody>
        </table>
        <div v-else-if="!mLoading" class="empty"><Icon name="inbox" :size="40" /><div>Aucun mouvement.</div></div>
      </div>
      <Pager :meta="mMeta" @goto="(p) => { mf.page = p; chargerMvts() }" />
    </div>

    <!-- Inventaire -->
    <div v-else class="card">
      <div class="toolbar">
        <div class="search"><Icon name="search" :size="16" /><input v-model="inv.q" class="input" type="search" placeholder="Filtrer les produits…" aria-label="Filtrer" /></div>
        <input v-model="inv.date" class="input date" type="date" :max="today()" aria-label="Date de l'inventaire" />
        <span class="muted">Saisissez uniquement les produits comptés — les autres restent inchangés.</span>
      </div>
      <div class="table-wrap">
        <table class="table stack">
          <thead><tr><th>Produit</th><th class="num">Théorique</th><th class="num" style="width:150px">Compté</th><th class="num">Écart</th></tr></thead>
          <tbody>
            <tr v-for="p in invFiltre" :key="p.idprod">
              <td data-label="Produit" class="strong">{{ p.nom }} <small class="muted">{{ p.sku }}</small></td>
              <td data-label="Théorique" class="num">{{ qty(p.stock_qty) }} {{ p.unite }}</td>
              <td data-label="Compté" class="num"><input v-model="inv.compte[p.idprod]" class="input" type="number" min="0" step="any" style="text-align:right" :aria-label="`Quantité comptée de ${p.nom}`" /></td>
              <td data-label="Écart" class="num"><b v-if="ecartDe(p) !== null" :class="ecartDe(p) === 0 ? 'pos' : 'neg'">{{ ecartDe(p) > 0 ? '+' : '' }}{{ qty(ecartDe(p)) }}</b><span v-else class="muted">—</span></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="inv-foot">
        <input v-model="inv.note" class="input" placeholder="Note (facultatif)" maxlength="300" style="flex:1;min-width:200px" />
        <button class="btn primary" :disabled="inv.busy || !nbSaisis" @click="validerInventaire"><Icon name="check" :size="16" /> {{ inv.busy ? 'Validation…' : `Valider l'inventaire (${nbSaisis})` }}</button>
      </div>
      <div v-if="inv.erreur" class="alert" style="margin:0 18px 18px">{{ inv.erreur }}</div>
      <div v-if="inv.resultat" class="inv-res">
        <b>Dernier inventaire : {{ inv.resultat.nb_lignes }} produit(s) compté(s), {{ inv.resultat.nb_ecarts }} écart(s), valeur {{ money(inv.resultat.valeur_ecart) }}</b>
        <div v-for="e in inv.resultat.ecarts" :key="e.idprod" class="muted">{{ e.nom }} : {{ qty(e.attendu) }} → {{ qty(e.compte) }} ({{ e.ecart > 0 ? '+' : '' }}{{ qty(e.ecart) }})</div>
      </div>
    </div>

    <!-- Ajustement -->
    <div v-if="aj.produit" class="overlay center" @click.self="aj.produit = null" @keydown.esc="aj.produit = null">
      <form class="modal" novalidate role="dialog" aria-modal="true" aria-label="Ajuster le stock" @submit.prevent="ajuster">
        <h3>Ajuster le stock</h3>
        <p class="muted" style="margin:0">{{ aj.produit.nom }} — actuellement <b>{{ qty(aj.produit.stock_qty) }} {{ aj.produit.unite }}</b></p>
        <div style="display:flex;flex-direction:column;gap:14px;margin-top:14px">
          <div class="field"><label>Nature</label><div class="seg"><button type="button" :class="{ on: aj.type === 'ajustement' }" @click="aj.type = 'ajustement'">Correction</button><button type="button" :class="{ on: aj.type === 'perte' }" @click="aj.type = 'perte'">Perte / casse</button></div></div>
          <div class="field"><label>Saisie</label><div class="seg"><button type="button" :class="{ on: aj.mode === 'delta' }" @click="aj.mode = 'delta'">{{ aj.type === 'perte' ? 'Quantité perdue' : 'Ajouter / retirer' }}</button><button type="button" :class="{ on: aj.mode === 'set' }" @click="aj.mode = 'set'">Nouveau stock</button></div></div>
          <div class="field" :class="{ invalid: aj.erreurs.quantite }"><label for="aj-q">{{ aj.mode === 'set' ? 'Stock réel constaté' : aj.type === 'perte' ? 'Quantité perdue' : 'Quantité (négative pour retirer)' }}</label><input id="aj-q" v-model="aj.quantite" class="input" type="number" step="any" autofocus /><span v-if="aj.erreurs.quantite" class="error">{{ aj.erreurs.quantite }}</span><span v-else-if="apresAjust !== null" class="hint">Stock après : {{ qty(apresAjust) }} {{ aj.produit.unite }}</span></div>
          <div class="field" :class="{ invalid: aj.erreurs.motif }"><label for="aj-m">Motif <span class="req">*</span></label><input id="aj-m" v-model="aj.motif" class="input" maxlength="200" placeholder="Ex. carton abîmé, erreur de saisie…" /><span v-if="aj.erreurs.motif" class="error">{{ aj.erreurs.motif }}</span></div>
        </div>
        <div class="row"><button type="button" class="btn" @click="aj.produit = null">Annuler</button><button class="btn primary" :disabled="aj.busy">Enregistrer</button></div>
      </form>
    </div>
  </main>
</template>

<style scoped>
.tabs { margin-bottom: 14px; width: fit-content; }
.tabs button { display: inline-flex; align-items: center; gap: 6px; }
.inv-foot { display: flex; flex-wrap: wrap; gap: 10px; padding: 14px 18px; border-top: 1px solid var(--border); }
.inv-res { padding: 0 18px 18px; display: flex; flex-direction: column; gap: 2px; font-size: 14px; }
</style>
