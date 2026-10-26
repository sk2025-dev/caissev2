<script setup>
// Vitrine publique ouverte par le QR code. Par défaut, rayons par catégorie (rangées défilantes) ; dès qu'on cherche ou filtre,
// grille de résultats. Filtres : catégories, prix (tranches ou bornes), disponibilité, nouveautés, marques, origines, note.
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { catalogueImageUrl } from '../api'
import { money } from '../format'
import { logoUrl } from '../theme'
import { vitrine, chargerVitrine } from '../catalogue'
import Icon from '../components/Icon.vue'
import CarteCatalogue from '../components/CarteCatalogue.vue'
import SelectionCatalogue from '../components/SelectionCatalogue.vue'

const route = useRoute()
const router = useRouter()
const AUTRES = 0   // produits sans catégorie
const f = reactive({ q: '', cats: route.query.cat ? [Number(route.query.cat)] : [], min: '', max: '', dispo: false, nouveau: false, marques: [], origines: [], note: 0, tri: 'nom' })
const vue = ref('rayons')        // rayons | grille
const panneau = ref(false)
onMounted(() => { chargerVitrine(); document.title = 'Catalogue' })

const d = computed(() => vitrine.data)
const r = computed(() => d.value?.reglages || {})
const commande = computed(() => !!r.value.whatsapp)
const tous = computed(() => d.value?.produits || [])
const telephone = computed(() => (d.value?.entreprise.telephone || '').replace(/[^\d+]/g, ''))

/* ---- Catégories (avec « Autres » pour les produits sans catégorie) ---- */
const categories = computed(() => {
  if (!d.value) return []
  const l = d.value.categories.map((c) => ({ ...c, n: tous.value.filter((p) => p.idcat === c.id).length }))
  const sans = tous.value.filter((p) => p.idcat === null).length
  return sans ? [...l, { id: AUTRES, nom: 'Autres', couleur: '', image: '', n: sans }] : l
})
const catDe = (p) => p.idcat ?? AUTRES
const nomCat = (id) => categories.value.find((c) => c.id === id)?.nom || ''
function choisirCat(id) {
  f.cats = id === null || (f.cats.length === 1 && f.cats[0] === id) ? [] : [id]
  window.scrollTo({ top: 0, behavior: 'smooth' })
}
watch(() => f.cats.join(','), () => router.replace({ query: { ...route.query, cat: f.cats.length === 1 ? String(f.cats[0]) : undefined } }))

/* ---- Filtrage : chaque filtre peut être ignoré pour compter les choix de sa propre rubrique (facettes) ---- */
const sansAccent = (t) => (t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
function passe(p, sauf = '') {
  const m = sansAccent(f.q.trim())
  if (m && !sansAccent(`${p.nom} ${p.marque} ${p.origine} ${nomCat(catDe(p))}`).includes(m)) return false
  if (sauf !== 'cats' && f.cats.length && !f.cats.includes(catDe(p))) return false
  if (sauf !== 'prix' && p.prix != null && ((f.min !== '' && p.prix < Number(f.min)) || (f.max !== '' && p.prix > Number(f.max)))) return false
  if (f.dispo && p.dispo === 'rupture') return false
  if (f.nouveau && !p.nouveau) return false
  if (sauf !== 'marques' && f.marques.length && !f.marques.includes(p.marque)) return false
  if (sauf !== 'origines' && f.origines.length && !f.origines.includes(p.origine)) return false
  if (sauf !== 'note' && f.note && (p.note || 0) < f.note) return false
  return true
}
const TRIS = { nom: 'Nom (A → Z)', prix_asc: 'Prix croissant', prix_desc: 'Prix décroissant', note: 'Mieux notés', nouveautes: 'Nouveautés' }
const tris = computed(() => Object.entries(TRIS).filter(([k]) => (!k.startsWith('prix') || r.value.prix) && (k !== 'note' || r.value.avis)))
const resultats = computed(() => {
  const l = tous.value.filter((p) => passe(p))
  const par = { prix_asc: (a, b) => a.prix - b.prix, prix_desc: (a, b) => b.prix - a.prix, note: (a, b) => (b.note || 0) - (a.note || 0) || b.nb_avis - a.nb_avis, nouveautes: (a, b) => String(b.ajout || '').localeCompare(String(a.ajout || '')) }[f.tri]
  return par ? [...l].sort(par) : l
})
const facette = (cle, rubrique) => {
  const n = {}
  for (const p of tous.value) if (p[cle] && passe(p, rubrique)) n[p[cle]] = (n[p[cle]] || 0) + 1
  return Object.entries(n).sort((a, b) => a[0].localeCompare(b[0], 'fr')).map(([v, c]) => ({ v, c }))
}
const marques = computed(() => facette('marque', 'marques'))
const origines = computed(() => facette('origine', 'origines'))
const nbCat = (id) => tous.value.filter((p) => catDe(p) === id && passe(p, 'cats')).length
const nbNote = (n) => tous.value.filter((p) => (p.note || 0) >= n && passe(p, 'note')).length

/* ---- Tranches de prix calculées sur le catalogue (arrondies à des montants ronds) ---- */
const rond = (v) => { if (v < 100) return Math.round(v); const k = 10 ** (Math.floor(Math.log10(v)) - 1); return Math.round(v / k) * k }
const tranches = computed(() => {
  const prix = tous.value.map((p) => p.prix).filter((v) => v != null).sort((a, b) => a - b)
  if (prix.length < 4) return []
  const b = [...new Set([0.25, 0.5, 0.75].map((q) => rond(prix[Math.floor(q * (prix.length - 1))])))].filter((v) => v > 0)
  return [...b.map((v, i) => ({ min: i ? b[i - 1] : '', max: v })), { min: b[b.length - 1], max: '' }]
})
const libTranche = (t) => (t.min === '' ? `Moins de ${money(t.max)}` : t.max === '' ? `Plus de ${money(t.min)}` : `${money(t.min)} – ${money(t.max)}`)
const trancheActive = (t) => String(f.min) === String(t.min) && String(f.max) === String(t.max)
function choisirTranche(t) { if (trancheActive(t)) { f.min = ''; f.max = '' } else { f.min = t.min; f.max = t.max } }
const basculer = (liste, v) => { const i = liste.indexOf(v); if (i >= 0) liste.splice(i, 1); else liste.push(v) }

/* ---- Filtres actifs : pastilles retirables ---- */
const actifs = computed(() => {
  const l = []
  if (f.cats.length) l.push({ k: 'cats', t: f.cats.map(nomCat).join(', '), x: () => { f.cats = [] } })
  if (f.min !== '' || f.max !== '') l.push({ k: 'prix', t: f.min !== '' && f.max !== '' ? `${money(f.min)} – ${money(f.max)}` : f.min !== '' ? `Dès ${money(f.min)}` : `Jusqu'à ${money(f.max)}`, x: () => { f.min = ''; f.max = '' } })
  if (f.dispo) l.push({ k: 'dispo', t: 'Disponibles', x: () => { f.dispo = false } })
  if (f.nouveau) l.push({ k: 'nouveau', t: 'Nouveautés', x: () => { f.nouveau = false } })
  for (const m of f.marques) l.push({ k: 'm' + m, t: m, x: () => basculer(f.marques, m) })
  for (const o of f.origines) l.push({ k: 'o' + o, t: `Origine : ${o}`, x: () => basculer(f.origines, o) })
  if (f.note) l.push({ k: 'note', t: `${f.note}★ et plus`, x: () => { f.note = 0 } })
  return l
})
const nbFiltres = computed(() => actifs.value.filter((a) => a.k !== 'cats').length)
function toutEffacer() { Object.assign(f, { q: '', cats: [], min: '', max: '', dispo: false, nouveau: false, marques: [], origines: [], note: 0 }) }

/* ---- Rayons par catégorie : affichés tant qu'on ne cherche ni ne filtre ---- */
const enRayons = computed(() => vue.value === 'rayons' && !f.q.trim() && !actifs.value.length && f.tri === 'nom' && categories.value.length > 0)
const rayons = computed(() => categories.value.map((c) => ({ ...c, produits: tous.value.filter((p) => catDe(p) === c.id) })).filter((c) => c.produits.length))
const MAX_RANGEE = 12
const PETIT_RAYON = 8   // jusqu'à 8 produits : grille complète plutôt qu'une rangée à faire défiler
</script>

<template>
  <div class="vitrine">
    <header class="entete">
      <img v-if="d?.entreprise.logo" :src="logoUrl" alt="" class="logo" />
      <div class="titre">
        <h1>{{ d?.entreprise.nom || 'Notre catalogue' }}</h1>
        <p v-if="r.message">{{ r.message }}</p>
        <p v-else-if="d">Découvrez nos {{ tous.length }} produits et services</p>
      </div>
      <div v-if="d" class="contacts">
        <a v-if="r.whatsapp" class="chip wa" :href="`https://wa.me/${r.whatsapp}`" target="_blank" rel="noopener"><Icon name="message" :size="15" /> WhatsApp</a>
        <a v-if="telephone" class="chip" :href="`tel:${telephone}`"><Icon name="phone" :size="15" /> Appeler</a>
        <span v-if="d.entreprise.adresse" class="chip neutre"><Icon name="target" :size="15" /> {{ d.entreprise.adresse }}</span>
      </div>
    </header>

    <div v-if="vitrine.erreur" class="etat"><Icon name="inbox" :size="44" /><h2>Catalogue indisponible</h2><p class="muted">{{ vitrine.erreur }}</p></div>
    <div v-else-if="!d" class="grille"><div v-for="n in 8" :key="n" class="skeleton" style="aspect-ratio:3/4;border-radius:20px" /></div>

    <template v-else>
      <div class="outils">
        <div class="recherche"><Icon name="search" :size="17" /><input v-model="f.q" type="search" class="input" placeholder="Produit, marque, catégorie…" aria-label="Rechercher" /></div>
        <button class="btn filtres-btn" :class="{ actif: nbFiltres }" @click="panneau = true"><Icon name="sliders" :size="17" /><span class="txt">Filtres</span><span v-if="nbFiltres" class="compte">{{ nbFiltres }}</span></button>
        <select v-model="f.tri" class="input tri" aria-label="Trier"><option v-for="[k, l] in tris" :key="k" :value="k">{{ l }}</option></select>
      </div>

      <nav v-if="categories.length" class="cats" aria-label="Catégories">
        <button :class="{ on: !f.cats.length }" @click="choisirCat(null)"><span class="av tout"><Icon name="grid" :size="15" /></span>Tout <small>{{ tous.length }}</small></button>
        <button v-for="c in categories" :key="c.id" :class="{ on: f.cats.includes(c.id) }" :style="c.couleur ? { '--c': c.couleur } : null" @click="choisirCat(c.id)">
          <span class="av"><img v-if="c.image" :src="catalogueImageUrl(c.image, 'categories')" alt="" /><i v-else /></span>{{ c.nom }} <small>{{ c.n }}</small>
        </button>
      </nav>

      <!-- Rayons : une rangée défilante par catégorie -->
      <template v-if="enRayons">
        <div class="bascule"><span class="muted">{{ rayons.length }} rayon{{ rayons.length > 1 ? 's' : '' }} · {{ tous.length }} produits</span>
          <div class="seg"><button class="on"><Icon name="list" :size="15" /> Par catégorie</button><button @click="vue = 'grille'"><Icon name="grid" :size="15" /> Tout</button></div>
        </div>
        <section v-for="c in rayons" :key="c.id" class="rayon">
          <header>
            <span class="av grand" :style="c.couleur ? { '--c': c.couleur } : null"><img v-if="c.image" :src="catalogueImageUrl(c.image, 'categories')" alt="" /><i v-else /></span>
            <h2>{{ c.nom }} <small>{{ c.produits.length }}</small></h2>
            <button v-if="c.produits.length > 2" class="voir" @click="choisirCat(c.id)">Tout voir <Icon name="chevR" :size="15" /></button>
          </header>
          <div class="rangee" :class="{ 'en-grille': c.produits.length <= PETIT_RAYON }">
            <div v-for="p in c.produits.slice(0, MAX_RANGEE)" :key="p.id" class="case"><CarteCatalogue :p="p" :commande="commande" /></div>
            <button v-if="c.produits.length > MAX_RANGEE" class="case plus" @click="choisirCat(c.id)"><b>+{{ c.produits.length - MAX_RANGEE }}</b>Voir tout le rayon</button>
          </div>
        </section>
      </template>

      <!-- Grille de résultats -->
      <template v-else>
        <div class="resultats">
          <b>{{ resultats.length }} produit{{ resultats.length > 1 ? 's' : '' }}<template v-if="f.q.trim()"> pour « {{ f.q.trim() }} »</template></b>
          <div v-if="actifs.length" class="pastilles">
            <button v-for="a in actifs" :key="a.k" class="pastille" @click="a.x()">{{ a.t }} <Icon name="x" :size="13" /></button>
            <button class="effacer" @click="toutEffacer">Tout effacer</button>
          </div>
          <div v-else-if="categories.length && !f.q.trim()" class="seg"><button @click="vue = 'rayons'; f.tri = 'nom'"><Icon name="list" :size="15" /> Par catégorie</button><button class="on"><Icon name="grid" :size="15" /> Tout</button></div>
        </div>
        <div v-if="!resultats.length" class="etat"><Icon name="search" :size="40" /><p class="muted">Aucun produit ne correspond.</p><button v-if="actifs.length || f.q" class="btn" @click="toutEffacer">Effacer la recherche et les filtres</button></div>
        <div v-else class="grille">
          <CarteCatalogue v-for="(p, n) in resultats" :key="p.id" :p="p" :commande="commande" class="apparait" :style="{ '--i': Math.min(n, 12) }" />
        </div>
      </template>

      <footer class="pied">
        <b>{{ d.entreprise.nom }}</b>
        <span v-if="d.entreprise.adresse">{{ d.entreprise.adresse }}</span>
        <span>{{ [d.entreprise.telephone, d.entreprise.email, d.entreprise.site].filter(Boolean).join(' · ') }}</span>
        <small class="muted">Prix et disponibilités indicatifs, confirmés par la boutique à la commande.</small>
      </footer>
      <SelectionCatalogue :produits="tous" :reglages="r" />

      <!-- Panneau des filtres -->
      <Teleport to="body">
        <div v-if="panneau" class="overlay filtres-fond" @click.self="panneau = false" @keydown.esc="panneau = false">
          <aside class="panneau" role="dialog" aria-modal="true" aria-label="Filtres">
            <header><h2>Filtrer et trier</h2><button class="btn ghost icon" aria-label="Fermer" @click="panneau = false"><Icon name="x" /></button></header>
            <div class="corps">
              <section>
                <h3>Trier par</h3>
                <div class="choix"><button v-for="[k, l] in tris" :key="k" :class="{ on: f.tri === k }" @click="f.tri = k">{{ l }}</button></div>
              </section>
              <section v-if="categories.length">
                <h3>Catégories <small v-if="f.cats.length">{{ f.cats.length }} choisie{{ f.cats.length > 1 ? 's' : '' }}</small></h3>
                <label v-for="c in categories" :key="c.id" class="case-a-cocher"><input type="checkbox" :checked="f.cats.includes(c.id)" @change="basculer(f.cats, c.id)" /><span>{{ c.nom }}</span><small>{{ nbCat(c.id) }}</small></label>
              </section>
              <section v-if="r.prix">
                <h3>Prix</h3>
                <div v-if="tranches.length" class="choix"><button v-for="(t, n) in tranches" :key="n" :class="{ on: trancheActive(t) }" @click="choisirTranche(t)">{{ libTranche(t) }}</button></div>
                <div class="bornes">
                  <label><span>Min</span><input v-model="f.min" type="number" min="0" class="input" inputmode="numeric" placeholder="0" /></label>
                  <span class="muted">–</span>
                  <label><span>Max</span><input v-model="f.max" type="number" min="0" class="input" inputmode="numeric" placeholder="Sans limite" /></label>
                </div>
              </section>
              <section>
                <h3>Disponibilité</h3>
                <label v-if="r.dispo" class="toggle"><input v-model="f.dispo" type="checkbox" /><span class="sw" /> Uniquement les produits disponibles</label>
                <label class="toggle"><input v-model="f.nouveau" type="checkbox" /><span class="sw" /> Nouveautés (30 derniers jours)</label>
              </section>
              <section v-if="marques.length">
                <h3>Marques</h3>
                <label v-for="m in marques" :key="m.v" class="case-a-cocher"><input type="checkbox" :checked="f.marques.includes(m.v)" @change="basculer(f.marques, m.v)" /><span>{{ m.v }}</span><small>{{ m.c }}</small></label>
              </section>
              <section v-if="origines.length">
                <h3>Origine</h3>
                <label v-for="o in origines" :key="o.v" class="case-a-cocher"><input type="checkbox" :checked="f.origines.includes(o.v)" @change="basculer(f.origines, o.v)" /><span>{{ o.v }}</span><small>{{ o.c }}</small></label>
              </section>
              <section v-if="r.avis">
                <h3>Avis clients</h3>
                <div class="choix">
                  <button :class="{ on: !f.note }" @click="f.note = 0">Toutes les notes</button>
                  <button v-for="n in [4, 3]" :key="n" :class="{ on: f.note === n }" @click="f.note = f.note === n ? 0 : n"><span class="stars">{{ '★'.repeat(n) }}</span> et plus · {{ nbNote(n) }}</button>
                </div>
              </section>
            </div>
            <footer>
              <button class="btn" :disabled="!actifs.length && f.tri === 'nom'" @click="toutEffacer(); f.tri = 'nom'">Tout effacer</button>
              <button class="btn primary" @click="panneau = false">Voir {{ resultats.length }} produit{{ resultats.length > 1 ? 's' : '' }}</button>
            </footer>
          </aside>
        </div>
      </Teleport>
    </template>
  </div>
</template>

<style scoped>
.vitrine { max-width: 1180px; margin: 0 auto; padding: 16px 16px 110px; min-height: 100vh; }
.entete { display: flex; flex-wrap: wrap; align-items: center; gap: 14px 18px; padding: 22px; margin-bottom: 16px; border-radius: 26px; color: #fff; background: linear-gradient(135deg, var(--primary), var(--primary-2)); box-shadow: 0 16px 36px color-mix(in srgb, var(--primary) 30%, transparent); }
.logo { width: 68px; height: 68px; object-fit: contain; border-radius: 18px; background: #fff; padding: 6px; flex: none; }
.titre { flex: 1; min-width: 200px; }
.titre h1 { margin: 0; font-size: clamp(22px, 4vw, 30px); }
.titre p { margin: 4px 0 0; opacity: .9; }
.contacts { display: flex; flex-wrap: wrap; gap: 8px; width: 100%; }
.chip { display: inline-flex; align-items: center; gap: 6px; padding: 7px 13px; border-radius: 999px; background: rgba(255, 255, 255, .2); color: #fff; font-weight: 700; font-size: 14px; text-decoration: none; }
.chip.wa { background: #1da851; }
.chip.neutre { background: rgba(255, 255, 255, .12); font-weight: 600; }

.outils { position: sticky; top: 0; z-index: 20; display: flex; gap: 8px; padding: 10px 0; background: var(--bg); }
.recherche { flex: 1; position: relative; min-width: 0; }
.recherche svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--muted); }
.recherche .input { padding-left: 42px; border-radius: 999px; }
.filtres-btn { border-radius: 999px; flex: none; }
.filtres-btn.actif { border-color: var(--primary); color: var(--primary); background: var(--primary-soft); }
.compte { min-width: 20px; height: 20px; padding: 0 6px; border-radius: 999px; background: var(--primary); color: #fff; font-size: 12px; display: grid; place-items: center; }
.tri { width: auto; border-radius: 999px; flex: none; }
@media (max-width: 600px) { .tri { display: none; } .filtres-btn .txt { display: none; } }

.cats { display: flex; gap: 8px; overflow-x: auto; padding: 4px 0 14px; scrollbar-width: none; }
.cats button { --c: var(--primary); flex: none; display: inline-flex; align-items: center; gap: 7px; padding: 5px 14px 5px 5px; border-radius: 999px; border: 1.5px solid var(--border); background: var(--surface); color: var(--text); font: inherit; font-weight: 700; font-size: 14px; cursor: pointer; transition: border-color .15s, background .15s; }
.cats button small { color: var(--muted); font-weight: 600; }
.cats button.on { border-color: var(--c); background: var(--c); color: #fff; }
.cats button.on small { color: rgba(255, 255, 255, .85); }
.av { --c: var(--primary); width: 28px; height: 28px; border-radius: 50%; overflow: hidden; display: grid; place-items: center; flex: none; background: color-mix(in srgb, var(--c) 18%, var(--surface)); }
.av img { width: 100%; height: 100%; object-fit: cover; }
.av i { width: 10px; height: 10px; border-radius: 50%; background: var(--c); }
.av.tout { color: var(--primary); }
.cats button.on .av { box-shadow: 0 0 0 2px #fff; }

.seg { display: inline-flex; padding: 3px; border-radius: 999px; background: var(--surface-2); }
.seg button { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border: 0; border-radius: 999px; background: none; color: var(--muted); font: inherit; font-weight: 700; font-size: 13.5px; cursor: pointer; }
.seg button.on { background: var(--surface); color: var(--text); box-shadow: var(--shadow); }
.bascule { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 8px; font-size: 14px; }

.rayon { margin: 14px 0 26px; }
.rayon header { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.av.grand { width: 38px; height: 38px; border-radius: 12px; }
.rayon h2 { flex: 1; margin: 0; font-size: 19px; display: flex; align-items: baseline; gap: 8px; }
.rayon h2 small { color: var(--muted); font-size: 14px; font-weight: 600; }
.voir { display: inline-flex; align-items: center; gap: 2px; border: 0; background: none; color: var(--primary); font: inherit; font-weight: 800; cursor: pointer; }
.rangee { display: flex; gap: 12px; overflow-x: auto; scroll-snap-type: x proximity; padding: 2px 2px 10px; margin: 0 -2px; scrollbar-width: thin; }
.case { flex: none; width: 170px; scroll-snap-align: start; }
@media (min-width: 720px) { .case { width: 210px; } }
.rangee.en-grille { display: grid; grid-template-columns: repeat(auto-fill, minmax(165px, 1fr)); overflow: visible; }
@media (min-width: 720px) { .rangee.en-grille { grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 18px; } }
.rangee.en-grille .case { width: auto; }
.case.plus { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; border: 2px dashed var(--border); border-radius: 20px; background: var(--surface); color: var(--primary); font: inherit; font-weight: 700; cursor: pointer; min-height: 220px; }
.case.plus b { font-size: 24px; }

.resultats { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
.pastilles { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.pastille { display: inline-flex; align-items: center; gap: 6px; padding: 5px 10px 5px 12px; border-radius: 999px; border: 0; background: var(--primary-soft); color: var(--primary); font: inherit; font-weight: 700; font-size: 13px; cursor: pointer; }
.effacer { border: 0; background: none; color: var(--muted); font: inherit; font-size: 13px; font-weight: 700; text-decoration: underline; cursor: pointer; }
.grille { display: grid; grid-template-columns: repeat(auto-fill, minmax(165px, 1fr)); gap: 14px; }
@media (min-width: 720px) { .grille { grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 18px; } }
.apparait { animation: rise .45s var(--ease) both; animation-delay: calc(var(--i) * 35ms); }

.etat { display: flex; flex-direction: column; align-items: center; gap: 8px; text-align: center; padding: 60px 16px; color: var(--muted); }
.etat h2 { margin: 6px 0 0; color: var(--text); }
.pied { display: flex; flex-direction: column; align-items: center; gap: 4px; margin-top: 40px; padding-top: 20px; border-top: 1px solid var(--border); text-align: center; font-size: 14px; }

.filtres-fond { justify-content: flex-end; }
.panneau { width: min(400px, 100%); height: 100%; display: flex; flex-direction: column; background: var(--surface); box-shadow: var(--shadow-lg); animation: slide .3s var(--ease); }
@media (max-width: 600px) {
  .filtres-fond { align-items: flex-end; }
  .panneau { width: 100%; height: auto; max-height: 88vh; border-radius: 24px 24px 0 0; animation: monte .3s var(--ease); }
}
@keyframes monte { from { transform: translateY(40px); opacity: 0; } to { transform: none; opacity: 1; } }
.panneau > header { display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; border-bottom: 1px solid var(--border); }
.panneau > header h2 { margin: 0; font-size: 19px; }
.corps { flex: 1; overflow-y: auto; padding: 4px 20px 12px; }
.corps section { padding: 14px 0; border-bottom: 1px solid var(--border); display: flex; flex-direction: column; gap: 8px; }
.corps section:last-child { border-bottom: 0; }
.corps h3 { margin: 0 0 2px; font-size: 15px; display: flex; justify-content: space-between; align-items: baseline; }
.corps h3 small { color: var(--primary); font-weight: 700; font-size: 12.5px; }
.choix { display: flex; flex-wrap: wrap; gap: 6px; }
.choix button { padding: 7px 13px; border-radius: 999px; border: 1.5px solid var(--border); background: var(--surface); color: var(--text); font: inherit; font-weight: 600; font-size: 13.5px; cursor: pointer; }
.choix button.on { border-color: var(--primary); background: var(--primary-soft); color: var(--primary); font-weight: 800; }
.stars { color: #f5a524; }
.case-a-cocher { display: flex; align-items: center; gap: 10px; padding: 4px 0; cursor: pointer; }
.case-a-cocher input { width: 18px; height: 18px; accent-color: var(--primary); }
.case-a-cocher span { flex: 1; }
.case-a-cocher small { color: var(--muted); }
.bornes { display: flex; align-items: flex-end; gap: 8px; }
.bornes label { flex: 1; display: flex; flex-direction: column; gap: 4px; font-size: 12.5px; color: var(--muted); font-weight: 700; }
.panneau > footer { display: flex; gap: 10px; padding: 14px 20px 18px; border-top: 1px solid var(--border); }
.panneau > footer .btn.primary { flex: 1; justify-content: center; }
</style>
