<script setup>
import { ref, computed, onMounted } from 'vue'
import { api, auth } from '../api'
import { money, qty, dateHeure, uniteAccord } from '../format'
import { loadLookups } from '../lookups'
import { resources } from '../resources'
import { etoiles } from '../avis'
import Icon from '../components/Icon.vue'
import HistoriqueFiche from '../components/HistoriqueFiche.vue'
import GaleriePhotos from '../components/GaleriePhotos.vue'
import AvisProduit from '../components/AvisProduit.vue'
import FormDrawer from '../components/FormDrawer.vue'

const props = defineProps({ id: { type: String, required: true } })
const d = ref(null)
const error = ref('')
const edition = ref(false)
async function charger() { try { d.value = await api.get('produit-detail', { id: props.id }) } catch (e) { error.value = e.message } }
onMounted(charger)
const p = computed(() => d.value?.produit)
const service = computed(() => p.value && !Number(p.value.stockable))
const modifiable = computed(() => !!auth.user?.droits?.catalogue_ecriture)
const etat = computed(() => {
  if (!p.value || service.value) return null
  const q = Number(p.value.stock_qty)
  return q <= 0 ? ['danger', 'En rupture'] : Number(p.value.seuil_alerte) > 0 && q <= Number(p.value.seuil_alerte) ? ['warning', 'Stock bas'] : ['success', 'Stock correct']
})
const marge = computed(() => (p.value && Number(p.value.prix_vente) > 0 ? Math.round(((p.value.prix_vente - p.value.prix_achat) / p.value.prix_vente) * 100) : 0))
const ht = computed(() => (p.value ? Number(p.value.prix_vente) / (1 + Number(p.value.tva_taux || 0) / 100) : 0))

// Fiche technique : seuls les renseignements saisis sont affichés
const technique = computed(() => {
  if (!p.value) return []
  const x = p.value
  return [
    ['Marque', x.marque], ['Modèle / gamme', x.modele], ['Catégorie', x.categorie], ['Origine', x.origine], ['Couleur', x.couleur],
    ['Dimensions / taille', x.dimensions], ['Poids / contenance', x.poids], ['Garantie', x.garantie],
    ['Unité de vente', service.value ? '' : x.unite], ['Référence', x.sku], ['Code-barres', x.code_barres],
    ['TVA', Number(x.tva_taux) > 0 ? `${Number(x.tva_taux)} % (incluse)` : 'Sans TVA'],
  ].filter(([, v]) => v !== null && v !== undefined && String(v).trim() !== '')
})
// « Matière : coton » → [Matière, coton] ; une ligne sans « : » est une remarque sur toute la largeur
const caracteristiques = computed(() => (p.value?.caracteristiques || '').split('\n').map((l) => l.trim()).filter(Boolean).map((l) => {
  const i = l.indexOf(':')
  return i > 0 ? [l.slice(0, i).trim(), l.slice(i + 1).trim()] : [null, l]
}))

// Ancre « #avis » impossible : le routeur utilise déjà le fragment de l'adresse
const voirAvis = () => document.getElementById('avis')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
function majGalerie(r) { d.value.photos = r.photos; d.value.produit.image = r.image }
async function modifier() { await loadLookups(); edition.value = true }
function modifie() { edition.value = false; charger() }
</script>

<template>
  <main class="page">
    <div class="haut">
      <RouterLink to="/produits" class="crumb"><Icon name="back" :size="16" /> Produits et services</RouterLink>
      <button v-if="p && modifiable" class="btn sm" @click="modifier"><Icon name="edit" :size="15" /> Modifier la fiche</button>
    </div>
    <div v-if="error" class="alert">{{ error }}</div>
    <div v-else-if="!d" class="skeleton" style="height:420px" />
    <template v-else>
      <section class="card fiche reveal">
        <div class="col-photos">
          <GaleriePhotos :idprod="p.idprod" :nom="p.nom" :photos="d.photos || []" :image="p.image" :max="d.galerie_max || 12" :modifiable="modifiable" :icone="service ? 'clipboard' : 'box'" @update="majGalerie" />
        </div>
        <div class="col-infos">
          <div class="chips">
            <span class="badge primary">{{ service ? 'Service' : 'Produit' }}</span>
            <span v-if="p.categorie" class="badge">{{ p.categorie }}</span>
            <span v-if="etat" class="badge" :class="etat[0]">{{ etat[1] }}</span>
            <span v-if="!Number(p.actif)" class="badge">Masqué en caisse</span>
          </div>
          <h1>{{ p.nom }}</h1>
          <p v-if="p.marque || p.modele" class="sous-titre">{{ [p.marque, p.modele].filter(Boolean).join(' · ') }}</p>
          <a v-if="d.avis_synthese?.nb" href="" class="note-resume" @click.prevent="voirAvis"><span class="stars">{{ etoiles(d.avis_synthese.moyenne) }}</span> {{ String(d.avis_synthese.moyenne).replace('.', ',') }} · {{ d.avis_synthese.nb }} avis</a>

          <div class="prix">
            <b>{{ money(p.prix_vente) }}</b>
            <small class="muted">{{ Number(p.tva_taux) > 0 ? `soit ${money(ht)} HT` : 'Sans TVA' }}<template v-if="!service"> · par {{ p.unite }}</template></small>
          </div>
          <div v-if="!service" class="dispo" :class="etat?.[0]">
            <Icon name="box" :size="16" /> {{ qty(p.stock_qty) }} {{ uniteAccord(p.stock_qty, p.unite) }} en stock
            <template v-if="d.jours_restants !== null"> · environ {{ d.jours_restants }} jour{{ d.jours_restants > 1 ? 's' : '' }} de vente</template>
          </div>

          <div v-if="p.description" class="bloc"><h2>Description</h2><p class="description">{{ p.description }}</p></div>

          <div v-if="technique.length" class="bloc">
            <h2>Fiche technique</h2>
            <dl class="technique"><template v-for="[k, v] in technique" :key="k"><dt>{{ k }}</dt><dd>{{ v }}</dd></template></dl>
          </div>

          <div v-if="caracteristiques.length" class="bloc">
            <h2>Caractéristiques</h2>
            <dl class="technique"><template v-for="([k, v], n) in caracteristiques" :key="n"><dt v-if="k">{{ k }}</dt><dd :class="{ large: !k }">{{ v }}</dd></template></dl>
          </div>
          <p v-if="modifiable && !p.description && !technique.slice(0, 8).length && !caracteristiques.length" class="muted astuce">
            <Icon name="edit" :size="14" /> Complétez la fiche (description, marque, origine, dimensions, caractéristiques…) avec « Modifier la fiche ».
          </p>
        </div>
      </section>

      <section class="stats">
        <article class="card stat reveal" style="--i:1"><div class="label">Prix de vente</div><div class="spacer" /><div class="value">{{ money(p.prix_vente) }}</div><div class="sub"><span v-if="Number(p.tva_taux) > 0">TVA {{ Number(p.tva_taux) }} % incluse</span><span v-else>Sans TVA</span></div></article>
        <article v-if="!service" class="card stat reveal" style="--i:2"><div class="label">En stock</div><div class="spacer" /><div class="value">{{ qty(p.stock_qty) }}<span class="unit">{{ uniteAccord(p.stock_qty, p.unite) }}</span></div><div class="sub"><span v-if="d.jours_restants !== null">≈ {{ d.jours_restants }} jour{{ d.jours_restants > 1 ? 's' : '' }} de vente</span><span v-else>Valeur {{ money(p.valeur_stock) }}</span></div></article>
        <article v-if="!service" class="card stat reveal" style="--i:3"><div class="label">Coût moyen / marge</div><div class="spacer" /><div class="value">{{ money(p.prix_achat) }}</div><div class="sub"><span class="delta" :class="marge >= 0 ? 'up' : 'down'">{{ marge }} %</span><span>de marge brute</span></div></article>
        <article class="card stat reveal" style="--i:4"><div class="label">Ventes (30 jours)</div><div class="spacer" /><div class="value">{{ money(d.ventes_30j.ca) }}</div><div class="sub"><span>{{ qty(d.ventes_30j.qte) }} vendu{{ d.ventes_30j.qte > 1 ? 's' : '' }} · {{ d.ventes_30j.nb_ventes }} ticket{{ d.ventes_30j.nb_ventes > 1 ? 's' : '' }}</span></div></article>
      </section>

      <AvisProduit id="avis" :idprod="p.idprod" :avis="d.avis || []" :total="d.avis_total || 0" :synthese="d.avis_synthese || { nb: 0, etoiles: {}, sources: [] }" :modifiable="modifiable" style="margin-bottom:18px" @changed="charger" />

      <section v-if="!service" class="card card-pad reveal" style="--i:5">
        <div class="card-head"><h2>Derniers mouvements de stock</h2><RouterLink to="/stock" class="btn sm ghost">Tout le stock</RouterLink></div>
        <div v-if="!d.mouvements.length" class="muted">Aucun mouvement.</div>
        <div class="list">
          <div v-for="m in d.mouvements" :key="m.idmvt" class="item">
            <span class="tile" :class="Number(m.quantite) >= 0 ? 'success' : 'warning'"><Icon :name="Number(m.quantite) >= 0 ? 'trend-up' : 'trend-down'" :size="19" /></span>
            <div class="grow"><b>{{ m.libelle_type }}</b><span>{{ dateHeure(m.created_at) }}<template v-if="m.motif"> · {{ m.motif }}</template> · {{ m.utilisateur }}</span></div>
            <b :class="Number(m.quantite) >= 0 ? 'pos' : 'neg'">{{ Number(m.quantite) > 0 ? '+' : '' }}{{ qty(m.quantite) }}</b>
            <span class="muted">→ {{ qty(m.stock_apres) }}</span>
          </div>
        </div>
      </section>
      <HistoriqueFiche entite="produits,stock" :id="id" style="margin-top:18px" />
    </template>
    <FormDrawer v-if="edition" :config="resources.produits" route="produits" id-key="idprod" :row="p" @close="edition = false" @saved="modifie" />
  </main>
</template>

<style scoped>
.haut { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
.haut .crumb { margin-bottom: 14px; }
.haut .btn { margin-bottom: 14px; }
.fiche { display: grid; grid-template-columns: minmax(260px, 420px) 1fr; gap: 28px; padding: 22px; margin-bottom: 18px; }
.col-photos { position: sticky; top: 16px; align-self: start; }
.col-infos { min-width: 0; display: flex; flex-direction: column; gap: 10px; }
.chips { display: flex; flex-wrap: wrap; gap: 6px; }
h1 { margin: 2px 0 0; font-size: clamp(22px, 3vw, 30px); line-height: 1.2; }
.sous-titre { margin: 0; color: var(--muted); font-weight: 700; }
.note-resume { display: inline-flex; align-items: center; gap: 6px; color: var(--muted); font-size: 14px; font-weight: 700; text-decoration: none; width: fit-content; }
.stars { color: #f5a524; letter-spacing: 1px; }
.prix { display: flex; flex-direction: column; margin-top: 4px; }
.prix b { font-size: 30px; color: var(--primary); }
.dispo { display: inline-flex; align-items: center; gap: 6px; width: fit-content; padding: 6px 12px; border-radius: 999px; background: var(--surface-2); font-weight: 700; font-size: 14px; }
.dispo.success { background: var(--success-soft); color: var(--success); }
.dispo.warning { background: var(--warning-soft); color: var(--warning); }
.dispo.danger { background: var(--danger-soft); color: var(--danger); }
.bloc { border-top: 1px solid var(--border); padding-top: 14px; margin-top: 6px; }
.bloc h2 { font-size: 16px; margin: 0 0 8px; }
.description { margin: 0; white-space: pre-line; line-height: 1.6; }
.technique { display: grid; grid-template-columns: minmax(120px, max-content) 1fr; margin: 0; border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
.technique dt, .technique dd { margin: 0; padding: 9px 14px; border-bottom: 1px solid var(--border); }
.technique dt { color: var(--muted); background: var(--surface-2); font-weight: 600; }
.technique dd { word-break: break-word; }
.technique dd.large { grid-column: 1 / -1; }
.technique > :nth-last-child(-n + 2) { border-bottom: 0; }
.astuce { display: flex; align-items: center; gap: 6px; font-size: 13.5px; }
@media (max-width: 860px) {
  .fiche { grid-template-columns: 1fr; padding: 16px; gap: 18px; }
  .col-photos { position: static; }
}
</style>
