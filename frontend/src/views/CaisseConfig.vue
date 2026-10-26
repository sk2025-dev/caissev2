<script setup>
// Paramétrage d'une caisse (gérant) : informations, produits vendus (tous ou une sélection), numérotation et ticket propres.
import { ref, reactive, computed, onMounted } from 'vue'
import { api, auth, ApiError } from '../api'
import { money } from '../format'
import { loadLookups } from '../lookups'
import { toast } from '../toast'
import Icon from '../components/Icon.vue'
import Ticket from '../components/Ticket.vue'

const props = defineProps({ id: { type: String, required: true } })
const d = ref(null)
const erreur = ref('')
const onglet = ref('general')
const f = reactive({})                                     // caisse
const a = reactive({ mode: 'tous', categories: [], produits: [] })
const erreurs = ref({})
const busy = ref(false)
const q = ref('')
const initial = ref('')

async function charger() {
  try {
    d.value = await api.get('caisse-config', { id: props.id })
    Object.assign(f, d.value.caisse, { actif: !!d.value.caisse.actif, ticket_perso: !!d.value.caisse.ticket_perso })
    Object.assign(a, d.value.assortiment)
    initial.value = JSON.stringify([f, a])
  } catch (e) { erreur.value = e.message }
}
onMounted(charger)
const modifie = computed(() => d.value && JSON.stringify([f, a]) !== initial.value)

async function enregistrer() {
  busy.value = true; erreurs.value = {}
  try {
    d.value = await api.post('caisse-config', { caisse: { ...f }, assortiment: { ...a } }, { id: props.id })
    Object.assign(f, d.value.caisse, { actif: !!d.value.caisse.actif, ticket_perso: !!d.value.caisse.ticket_perso })
    Object.assign(a, d.value.assortiment)
    initial.value = JSON.stringify([f, a])
    loadLookups(true)
    toast(`Caisse « ${f.nom} » enregistrée`)
  } catch (e) {
    if (e instanceof ApiError && Object.keys(e.errors).length) {
      erreurs.value = e.errors
      onglet.value = e.errors.assortiment ? 'produits' : e.errors.ticket_largeur ? 'ticket' : 'general'
    } else toast(e.message, 'error')
  } finally { busy.value = false }
}

/* ---- Produits vendus ---- */
const catsChoisies = computed(() => new Set(a.categories.map(Number)))
const prodsChoisis = computed(() => new Set(a.produits.map(Number)))
const parCat = (id) => d.value.produits.filter((p) => Number(p.idcat) === id).length
const viaCategorie = (p) => p.idcat !== null && catsChoisies.value.has(Number(p.idcat))
const vendu = (p) => a.mode === 'tous' || viaCategorie(p) || prodsChoisis.value.has(Number(p.id))
const nbVendus = computed(() => (d.value ? d.value.produits.filter(vendu).length : 0))
const sansAccent = (t) => (t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
const filtres = computed(() => {
  const m = sansAccent(q.value.trim())
  return d.value.produits.filter((p) => !m || sansAccent(`${p.nom} ${p.sku}`).includes(m))
})
const nomCat = (id) => d.value.categories.find((c) => Number(c.id) === Number(id))?.nom || 'Sans catégorie'
function basculerCat(id) { const i = a.categories.map(Number).indexOf(Number(id)); if (i >= 0) a.categories.splice(i, 1); else a.categories.push(Number(id)) }
function basculerProd(id) { const i = a.produits.map(Number).indexOf(Number(id)); if (i >= 0) a.produits.splice(i, 1); else a.produits.push(Number(id)) }
function cocherVisibles(oui) {
  for (const p of filtres.value) {
    if (viaCategorie(p)) continue
    const dedans = prodsChoisis.value.has(Number(p.id))
    if (oui && !dedans) a.produits.push(Number(p.id)); if (!oui && dedans) basculerProd(p.id)
  }
}

/* ---- Ticket : aperçu calculé comme sur le serveur (chaque information renseignée remplace celle de l'entreprise) ---- */
const ticket = computed(() => {
  const t = JSON.parse(JSON.stringify(d.value.ticket_commun))
  if (f.ticket_perso) {
    if (f.ticket_nom) t.entreprise.nom = f.ticket_nom
    if (f.ticket_adresse) { t.entreprise.adresse = f.ticket_adresse; t.entreprise.ville = '' }
    if (f.ticket_telephone) t.entreprise.telephone = f.ticket_telephone
    if (f.ticket_entete) t.entete = f.ticket_entete
    if (f.ticket_pied) t.pied = f.ticket_pied
    if (f.ticket_largeur) t.largeur = Number(f.ticket_largeur)
  }
  return t
})
const prefixe = computed(() => (f.prefixe || '').toUpperCase() || d.value.prefixe_commun)
const apercu = computed(() => {
  const exemples = d.value.produits.filter(vendu).slice(0, 2)
  const lignes = (exemples.length ? exemples : [{ id: 1, nom: 'Article exemple', prix_vente: 1500 }]).map((p, n) => ({ idligne: n, designation: p.nom, quantite: n + 1, prix_unitaire: Number(p.prix_vente), remise: 0, total: Number(p.prix_vente) * (n + 1) }))
  const total = lignes.reduce((s, l) => s + l.total, 0)
  return {
    numero: `${prefixe.value}-${new Date().getFullYear()}-000123`, date_vente: new Date().toISOString().slice(0, 19).replace('T', ' '), caissier: auth.user?.name, caisse: f.nom,
    lignes, total, remise: 0, tva: 0, paiements: [{ idpaie: 1, libelle: 'Espèces', montant: Math.ceil(total / 1000) * 1000 }], rendu: Math.ceil(total / 1000) * 1000 - total, reste: 0, statut: 'validee', ticket: ticket.value,
  }
})
const ONGLETS = [['general', 'Général', 'settings'], ['produits', 'Produits vendus', 'tag'], ['ticket', 'Ticket de caisse', 'receipt']]
</script>

<template>
  <main class="page">
    <RouterLink to="/caisses" class="crumb"><Icon name="back" :size="16" /> Caisses</RouterLink>
    <div v-if="erreur" class="alert">{{ erreur }}</div>
    <div v-else-if="!d" class="skeleton" style="height:420px" />
    <template v-else>
      <div class="page-head">
        <div><h1>{{ f.nom || 'Caisse' }}</h1><p>{{ f.emplacement || 'Paramétrage de la caisse' }} · {{ nbVendus }} produit{{ nbVendus > 1 ? 's' : '' }} vendu{{ nbVendus > 1 ? 's' : '' }} · {{ d.ventes }} vente{{ d.ventes > 1 ? 's' : '' }} enregistrée{{ d.ventes > 1 ? 's' : '' }}</p></div>
        <span class="badge" :class="f.actif ? 'success' : ''">{{ f.actif ? 'Active' : 'Désactivée' }}</span>
      </div>

      <nav class="onglets" role="tablist">
        <button v-for="[k, l, i] in ONGLETS" :key="k" role="tab" :aria-selected="onglet === k" :class="{ on: onglet === k }" @click="onglet = k">
          <Icon :name="i" :size="16" /> {{ l }}<span v-if="(k === 'produits' && erreurs.assortiment) || (k === 'general' && (erreurs.nom || erreurs.prefixe || erreurs.actif))" class="point" />
        </button>
      </nav>

      <!-- Général -->
      <section v-if="onglet === 'general'" class="card card-pad grid-form">
        <div class="field" :class="{ invalid: erreurs.nom }"><label for="c-nom">Nom de la caisse <span class="req">*</span></label><input id="c-nom" v-model="f.nom" class="input" maxlength="80" /><span v-if="erreurs.nom" class="error">{{ erreurs.nom }}</span></div>
        <div class="field"><label for="c-emp">Emplacement</label><input id="c-emp" v-model="f.emplacement" class="input" maxlength="150" placeholder="Ex. Boutique Cocody, rayon boissons, dépôt…" /></div>
        <div class="field" :class="{ invalid: erreurs.prefixe }">
          <label for="c-pre">Préfixe des tickets</label>
          <input id="c-pre" v-model="f.prefixe" class="input mono" maxlength="6" :placeholder="`Commun : ${d.prefixe_commun}`" @input="f.prefixe = f.prefixe.toUpperCase().replace(/[^A-Z0-9]/g, '')" />
          <span v-if="erreurs.prefixe" class="error">{{ erreurs.prefixe }}</span>
          <span v-else class="hint">{{ f.prefixe ? 'Numérotation propre à cette caisse, continue et sans trou' : 'Vide : numérotation commune à toutes les caisses' }} — ex. <b class="mono">{{ prefixe }}-{{ new Date().getFullYear() }}-000001</b></span>
        </div>
        <div class="field" :class="{ invalid: erreurs.actif }">
          <label>État</label>
          <label class="toggle"><input v-model="f.actif" type="checkbox" /><span class="sw" /> {{ f.actif ? 'Caisse active : proposée à l\'ouverture' : 'Caisse désactivée' }}</label>
          <span v-if="erreurs.actif" class="error">{{ erreurs.actif }}</span>
        </div>
      </section>

      <!-- Produits vendus -->
      <section v-else-if="onglet === 'produits'" class="card card-pad">
        <div class="modes">
          <label :class="{ on: a.mode === 'tous' }"><input v-model="a.mode" type="radio" value="tous" /><Icon name="grid" :size="20" /><span><b>Tous les produits</b><small>Tout le catalogue actif, y compris les produits créés plus tard.</small></span></label>
          <label :class="{ on: a.mode === 'selection' }"><input v-model="a.mode" type="radio" value="selection" /><Icon name="tag" :size="20" /><span><b>Une sélection</b><small>Des catégories entières et/ou des produits précis. Les autres sont refusés sur cette caisse.</small></span></label>
        </div>
        <div v-if="erreurs.assortiment" class="alert" style="margin-top:12px">{{ erreurs.assortiment }}</div>

        <template v-if="a.mode === 'selection'">
          <h3 class="titre">Catégories entières <small class="muted">— leurs futurs produits seront aussi vendus ici</small></h3>
          <div class="cats">
            <button v-for="c in d.categories" :key="c.id" type="button" :class="{ on: catsChoisies.has(Number(c.id)) }" :style="c.couleur ? { '--c': c.couleur } : null" @click="basculerCat(c.id)">
              <Icon :name="catsChoisies.has(Number(c.id)) ? 'check' : 'plus'" :size="14" /> {{ c.nom }} <small>{{ parCat(Number(c.id)) }}</small>
            </button>
            <span v-if="!d.categories.length" class="muted">Aucune catégorie.</span>
          </div>

          <h3 class="titre">Produits <small class="muted">— {{ nbVendus }} sur {{ d.produits.length }} vendus sur cette caisse</small></h3>
          <div class="outils">
            <div class="search"><Icon name="search" :size="16" /><input v-model="q" class="input" type="search" placeholder="Nom ou référence…" aria-label="Rechercher un produit" /></div>
            <button type="button" class="btn sm" @click="cocherVisibles(true)">Tout cocher</button>
            <button type="button" class="btn sm ghost" @click="cocherVisibles(false)">Tout décocher</button>
          </div>
          <div class="produits">
            <label v-for="p in filtres" :key="p.id" class="prod" :class="{ on: vendu(p), via: viaCategorie(p) }">
              <input type="checkbox" :checked="vendu(p)" :disabled="viaCategorie(p)" @change="basculerProd(p.id)" />
              <span class="grow"><b>{{ p.nom }}</b><small class="muted">{{ nomCat(p.idcat) }}<template v-if="p.sku"> · {{ p.sku }}</template></small></span>
              <small v-if="viaCategorie(p)" class="badge primary">via la catégorie</small>
              <span class="muted prix">{{ money(p.prix_vente) }}</span>
            </label>
            <div v-if="!filtres.length" class="muted vide">Aucun produit.</div>
          </div>
        </template>
      </section>

      <!-- Ticket -->
      <section v-else class="ticket-grille">
        <div class="card card-pad">
          <label class="toggle perso"><input v-model="f.ticket_perso" type="checkbox" /><span class="sw" /> <span><b>Ticket propre à cette caisse</b><small>Sinon, le ticket commun de la Configuration est utilisé.</small></span></label>
          <div v-if="f.ticket_perso" class="grid-form" style="margin-top:16px">
            <p class="muted full" style="margin:0">Laissez un champ vide pour reprendre l'information commune (affichée en grisé).</p>
            <div class="field full"><label for="t-nom">Nom affiché</label><input id="t-nom" v-model="f.ticket_nom" class="input" maxlength="150" :placeholder="d.ticket_commun.entreprise.nom" /></div>
            <div class="field full"><label for="t-adr">Adresse</label><input id="t-adr" v-model="f.ticket_adresse" class="input" maxlength="200" :placeholder="[d.ticket_commun.entreprise.adresse, d.ticket_commun.entreprise.ville].filter(Boolean).join(', ')" /></div>
            <div class="field"><label for="t-tel">Téléphone</label><input id="t-tel" v-model="f.ticket_telephone" class="input" maxlength="60" :placeholder="d.ticket_commun.entreprise.telephone" /></div>
            <div class="field" :class="{ invalid: erreurs.ticket_largeur }">
              <label for="t-larg">Largeur du papier</label>
              <select id="t-larg" v-model="f.ticket_largeur" class="input"><option value="">Commune ({{ d.ticket_commun.largeur }} mm)</option><option value="80">80 mm</option><option value="58">58 mm</option></select>
            </div>
            <div class="field full"><label for="t-ent">En-tête</label><textarea id="t-ent" v-model="f.ticket_entete" class="input" rows="3" maxlength="1000" :placeholder="d.ticket_commun.entete || 'Slogan, horaires de cette boutique…'" /></div>
            <div class="field full"><label for="t-pied">Pied de page</label><textarea id="t-pied" v-model="f.ticket_pied" class="input" rows="3" maxlength="1000" :placeholder="d.ticket_commun.pied || 'Remerciements, conditions de retour…'" /></div>
          </div>
          <p class="muted astuce"><Icon name="settings" :size="14" /> Logo, RCCM/NIF et informations à afficher se règlent pour toutes les caisses dans Configuration → Caisse et ticket.</p>
        </div>
        <div class="apercu">
          <h3>Aperçu</h3>
          <Ticket :vente="apercu" />
        </div>
      </section>

      <div class="barre-save" :class="{ visible: modifie }">
        <span>Modifications non enregistrées</span>
        <button class="btn" :disabled="busy" @click="charger">Annuler</button>
        <button class="btn primary" :disabled="busy" @click="enregistrer"><Icon name="check" :size="16" /> {{ busy ? 'Enregistrement…' : 'Enregistrer' }}</button>
      </div>
    </template>
  </main>
</template>

<style scoped>
.page-head { align-items: center; }
.mono { font-variant-numeric: tabular-nums; letter-spacing: .04em; }
.onglets { display: flex; gap: 6px; margin-bottom: 16px; overflow-x: auto; }
.onglets button { position: relative; display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border-radius: 999px; border: 1px solid var(--border); background: var(--surface); color: var(--text); font: inherit; font-weight: 700; cursor: pointer; white-space: nowrap; }
.onglets button.on { background: var(--primary); border-color: var(--primary); color: #fff; }
.point { width: 8px; height: 8px; border-radius: 50%; background: var(--danger); }
.modes { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media (max-width: 700px) { .modes { grid-template-columns: 1fr; } }
.modes label { display: flex; gap: 12px; align-items: flex-start; padding: 14px 16px; border: 1.5px solid var(--border); border-radius: 16px; cursor: pointer; transition: border-color .15s, background .15s; }
.modes label.on { border-color: var(--primary); background: var(--primary-soft); }
.modes label > svg { color: var(--primary); flex: none; margin-top: 2px; }
.modes input { position: absolute; opacity: 0; pointer-events: none; }
.modes span { display: flex; flex-direction: column; gap: 2px; }
.modes small { color: var(--muted); }
.titre { margin: 22px 0 10px; font-size: 15px; }
.titre small { font-weight: 400; }
.cats { display: flex; flex-wrap: wrap; gap: 8px; }
.cats button { --c: var(--primary); display: inline-flex; align-items: center; gap: 6px; padding: 7px 13px; border-radius: 999px; border: 1.5px solid var(--border); background: var(--surface); color: var(--text); font: inherit; font-weight: 700; font-size: 14px; cursor: pointer; }
.cats button small { color: var(--muted); font-weight: 600; }
.cats button.on { border-color: var(--c); background: color-mix(in srgb, var(--c) 14%, var(--surface)); }
.outils { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; }
.outils .search { flex: 1 1 220px; position: relative; }
.outils .search svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--muted); }
.outils .search .input { padding-left: 40px; border-radius: 999px; }
.produits { max-height: 460px; overflow-y: auto; border: 1px solid var(--border); border-radius: 14px; }
.prod { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-bottom: 1px solid var(--border); cursor: pointer; }
.prod:last-child { border-bottom: 0; }
.prod.on { background: color-mix(in srgb, var(--primary-soft) 55%, transparent); }
.prod.via { cursor: default; }
.prod input { width: 18px; height: 18px; accent-color: var(--primary); flex: none; }
.prod .grow { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.prod .grow b { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.prix { white-space: nowrap; font-size: 13.5px; }
.vide { padding: 20px; text-align: center; }
.ticket-grille { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 18px; align-items: start; }
@media (max-width: 960px) { .ticket-grille { grid-template-columns: 1fr; } }
.perso { align-items: flex-start; }
.perso > span:last-child { display: flex; flex-direction: column; }
.perso small { color: var(--muted); font-weight: 400; }
.apercu { position: sticky; top: 16px; padding: 16px; border-radius: 22px; background: var(--surface-2); }
.apercu h3 { margin: 0 0 10px; font-size: 14px; color: var(--muted); text-transform: uppercase; letter-spacing: .05em; }
.astuce { display: flex; align-items: center; gap: 6px; margin: 16px 0 0; font-size: 13px; }
.barre-save { position: sticky; bottom: 16px; z-index: 10; display: flex; align-items: center; gap: 10px; margin-top: 18px; padding: 10px 12px 10px 18px; border-radius: 999px; background: var(--surface); border: 1px solid var(--border); box-shadow: var(--shadow-lg); transform: translateY(20px); opacity: 0; pointer-events: none; transition: transform .25s var(--ease), opacity .25s; }
.barre-save.visible { transform: none; opacity: 1; pointer-events: auto; }
.barre-save span { flex: 1; font-weight: 700; color: var(--muted); }
</style>
