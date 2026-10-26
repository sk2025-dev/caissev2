<script setup>
// Fiche produit publique (QR code d'étiquette ou clic depuis la vitrine) : photos avec zoom, prix, disponibilité,
// description, fiche technique, avis clients ; ajout à la sélection, commande WhatsApp directe, partage.
import { ref, computed, watch } from 'vue'
import { api, catalogueImageUrl } from '../api'
import { money } from '../format'
import { logoUrl } from '../theme'
import { toast } from '../toast'
import { vitrine, chargerVitrine, selection, ajouter, lienWhatsApp, urlProduit, DISPO } from '../catalogue'
import { SOURCES, etoiles } from '../avis'
import Icon from '../components/Icon.vue'
import Lightbox from '../components/Lightbox.vue'
import SelectionCatalogue from '../components/SelectionCatalogue.vue'

const props = defineProps({ id: { type: String, required: true } })
const d = ref(null)
const erreur = ref('')
const choisie = ref(0)
const zoom = ref(null)
const qte = ref(1)
const blocAvis = ref(null)

async function charger() {
  d.value = null; erreur.value = ''; choisie.value = 0; qte.value = 1
  try { d.value = await api.get('catalogue-produit', { id: props.id }); document.title = d.value.produit.nom; window.scrollTo(0, 0) } catch (e) { erreur.value = e.message }
  chargerVitrine()   // pour les produits similaires et la sélection
}
watch(() => props.id, charger, { immediate: true })

const p = computed(() => d.value?.produit)
const r = computed(() => d.value?.reglages || {})
const photos = computed(() => (d.value?.photos || []).map((x) => ({ src: catalogueImageUrl(x.fichier), legende: x.legende })))
const technique = computed(() => (p.value ? [['Marque', p.value.marque], ['Modèle', p.value.modele], ['Catégorie', p.value.categorie], ['Origine', p.value.origine], ['Couleur', p.value.couleur],
  ['Dimensions / taille', p.value.dimensions], ['Poids / contenance', p.value.poids], ['Garantie', p.value.garantie], ['Référence', p.value.reference]].filter(([, v]) => v) : []))
const caracteristiques = computed(() => (p.value?.caracteristiques || '').split('\n').map((l) => l.trim()).filter(Boolean).map((l) => {
  const i = l.indexOf(':'); return i > 0 ? [l.slice(0, i).trim(), l.slice(i + 1).trim()] : [null, l]
}))
const similaires = computed(() => (vitrine.data && p.value ? vitrine.data.produits.filter((x) => x.idcat === p.value.idcat && x.id !== p.value.id).slice(0, 8) : []))
const commandable = computed(() => r.value.whatsapp && p.value?.dispo !== 'rupture')
const lienDirect = computed(() => (commandable.value ? lienWhatsApp(r.value.whatsapp, [{ produit: p.value, q: qte.value }]) : ''))
const telephone = computed(() => (d.value?.entreprise.telephone || '').replace(/[^\d+]/g, ''))
const dateFr = (v) => (v ? new Date(v).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' }) : '')

function ajouterSelection() { ajouter(p.value.id, qte.value); toast(`${qte.value} × ${p.value.nom} ajouté${qte.value > 1 ? 's' : ''} à votre sélection`) }
async function partager() {
  const url = urlProduit(p.value.id)
  try {
    if (navigator.share) await navigator.share({ title: p.value.nom, text: `${p.value.nom}${p.value.prix != null ? ` · ${money(p.value.prix)}` : ''}`, url })
    else { await navigator.clipboard.writeText(url); toast('Lien copié') }
  } catch { /* partage annulé */ }
}
</script>

<template>
  <div class="vitrine">
    <nav class="barre">
      <RouterLink to="/catalogue" class="retour"><Icon name="back" :size="18" /> Catalogue</RouterLink>
      <RouterLink to="/catalogue" class="marque-boutique"><img v-if="d?.entreprise.logo" :src="logoUrl" alt="" /><b>{{ d?.entreprise.nom }}</b></RouterLink>
      <button v-if="p" class="btn ghost icon" aria-label="Partager ce produit" title="Partager" @click="partager"><Icon name="link" :size="18" /></button>
    </nav>

    <div v-if="erreur" class="etat"><Icon name="inbox" :size="44" /><h2>Produit indisponible</h2><p class="muted">{{ erreur }}</p><RouterLink to="/catalogue" class="btn primary">Voir le catalogue</RouterLink></div>
    <div v-else-if="!d" class="skeleton" style="height:70vh;border-radius:24px" />

    <template v-else>
      <section class="produit">
        <div class="photos">
          <button v-if="photos.length" class="grande" :aria-label="`Agrandir la photo ${choisie + 1}`" @click="zoom = choisie">
            <img :src="photos[choisie].src" :alt="photos[choisie].legende || p.nom" />
            <span class="loupe"><Icon name="zoom-in" :size="16" /> Toucher pour zoomer</span>
          </button>
          <div v-else class="grande vide"><Icon :name="p.service ? 'clipboard' : 'box'" :size="56" /></div>
          <div v-if="photos.length > 1" class="vignettes">
            <button v-for="(ph, n) in photos" :key="n" :class="{ on: n === choisie }" :aria-label="`Photo ${n + 1}`" @click="choisie = n"><img :src="ph.src" alt="" loading="lazy" /></button>
          </div>
        </div>

        <div class="infos">
          <small v-if="p.marque" class="marque">{{ p.marque }}<template v-if="p.modele"> · {{ p.modele }}</template></small>
          <h1>{{ p.nom }}</h1>
          <a v-if="d.avis_synthese?.nb" href="" class="note" @click.prevent="blocAvis?.scrollIntoView({ behavior: 'smooth' })"><span class="stars">{{ etoiles(d.avis_synthese.moyenne) }}</span> {{ String(d.avis_synthese.moyenne).replace('.', ',') }} · {{ d.avis_synthese.nb }} avis</a>
          <div v-if="p.prix != null" class="prix">{{ money(p.prix) }}<small v-if="!p.service && p.unite"> / {{ p.unite }}</small></div>
          <span v-if="p.dispo" class="badge" :class="DISPO[p.dispo][0]">{{ DISPO[p.dispo][1] }}</span>

          <div v-if="commandable" class="commande">
            <div class="qte">
              <button class="btn icon" aria-label="Moins" :disabled="qte <= 1" @click="qte--"><Icon name="minus" :size="16" /></button>
              <b>{{ qte }}</b>
              <button class="btn icon" aria-label="Plus" @click="qte++"><Icon name="plus" :size="16" /></button>
            </div>
            <a class="btn wa" :href="lienDirect" target="_blank" rel="noopener"><Icon name="message" :size="18" /> Commander sur WhatsApp</a>
            <button class="btn" @click="ajouterSelection"><Icon name="plus" :size="16" /> Ajouter à ma sélection<span v-if="selection[p.id]" class="dans">({{ selection[p.id] }})</span></button>
          </div>
          <a v-else-if="telephone" class="btn primary appel" :href="`tel:${telephone}`"><Icon name="phone" :size="16" /> Appeler la boutique</a>

          <div v-if="p.description" class="bloc"><h2>Description</h2><p class="texte">{{ p.description }}</p></div>
          <div v-if="technique.length" class="bloc"><h2>Fiche technique</h2><dl class="tableau"><template v-for="[k, v] in technique" :key="k"><dt>{{ k }}</dt><dd>{{ v }}</dd></template></dl></div>
          <div v-if="caracteristiques.length" class="bloc"><h2>Caractéristiques</h2><dl class="tableau"><template v-for="([k, v], n) in caracteristiques" :key="n"><dt v-if="k">{{ k }}</dt><dd :class="{ large: !k }">{{ v }}</dd></template></dl></div>
        </div>
      </section>

      <section v-if="d.avis_synthese?.nb" ref="blocAvis" class="bloc-page">
        <h2>Avis clients <span class="stars">{{ etoiles(d.avis_synthese.moyenne) }}</span> <small class="muted">{{ String(d.avis_synthese.moyenne).replace('.', ',') }} / 5 · {{ d.avis_synthese.nb }} avis</small></h2>
        <div class="avis-liste">
          <article v-for="(a, n) in d.avis" :key="n" class="avis">
            <header>
              <span class="pastille" :style="{ background: SOURCES[a.source]?.couleur }">{{ SOURCES[a.source]?.lettre }}</span>
              <div><b>{{ a.auteur }}</b><small class="muted">{{ a.source_libelle }}<template v-if="a.date"> · {{ dateFr(a.date) }}</template></small></div>
              <span class="stars">{{ etoiles(a.note) }}</span>
            </header>
            <p v-if="a.commentaire">{{ a.commentaire }}</p>
            <div v-if="a.reponse" class="reponse"><b>Réponse de la boutique</b>{{ a.reponse }}</div>
          </article>
        </div>
      </section>

      <section v-if="similaires.length" class="bloc-page">
        <h2>Dans la même catégorie</h2>
        <div class="similaires">
          <RouterLink v-for="s in similaires" :key="s.id" :to="`/catalogue/${s.id}`" class="mini">
            <span class="mini-photo"><img v-if="s.image" :src="catalogueImageUrl(s.image)" alt="" loading="lazy" /><Icon v-else name="box" :size="26" /></span>
            <b>{{ s.nom }}</b><small v-if="s.prix != null">{{ money(s.prix) }}</small>
          </RouterLink>
        </div>
      </section>

      <SelectionCatalogue v-if="vitrine.data" :produits="vitrine.data.produits" :reglages="r" />
    </template>
    <Lightbox v-if="zoom !== null" :photos="photos" :index="zoom" @close="zoom = null" />
  </div>
</template>

<style scoped>
.vitrine { max-width: 1180px; margin: 0 auto; padding: 12px 16px 110px; min-height: 100vh; }
.barre { position: sticky; top: 0; z-index: 20; display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 8px 0; margin-bottom: 8px; background: var(--bg); }
.retour { display: inline-flex; align-items: center; gap: 4px; font-weight: 700; color: var(--primary); text-decoration: none; }
.marque-boutique { display: flex; align-items: center; gap: 8px; min-width: 0; color: var(--text); text-decoration: none; }
.marque-boutique img { width: 32px; height: 32px; object-fit: contain; border-radius: 8px; }
.marque-boutique b { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.produit { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 32px; align-items: start; }
@media (max-width: 820px) { .produit { grid-template-columns: 1fr; gap: 18px; } }
.photos { position: sticky; top: 60px; display: flex; flex-direction: column; gap: 10px; }
@media (max-width: 820px) { .photos { position: static; } }
.grande { position: relative; aspect-ratio: 1; width: 100%; padding: 0; border: 1px solid var(--border); border-radius: 22px; overflow: hidden; background: #fff; cursor: zoom-in; }
.grande img { width: 100%; height: 100%; object-fit: contain; }
.grande.vide { display: grid; place-items: center; color: var(--muted); cursor: default; background: var(--surface-2); }
.loupe { position: absolute; right: 12px; bottom: 12px; display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 999px; background: rgba(0, 0, 0, .6); color: #fff; font-size: 13px; font-weight: 700; }
.vignettes { display: flex; gap: 8px; overflow-x: auto; }
.vignettes button { flex: none; width: 66px; height: 66px; padding: 0; border-radius: 12px; overflow: hidden; border: 2px solid var(--border); background: #fff; cursor: pointer; }
.vignettes button.on { border-color: var(--primary); }
.vignettes img { width: 100%; height: 100%; object-fit: cover; }
.infos { display: flex; flex-direction: column; gap: 10px; min-width: 0; }
.marque { color: var(--muted); font-weight: 800; text-transform: uppercase; letter-spacing: .05em; font-size: 12.5px; }
h1 { margin: 0; font-size: clamp(24px, 4vw, 34px); line-height: 1.15; }
.note { display: inline-flex; align-items: center; gap: 6px; width: fit-content; color: var(--muted); font-weight: 700; text-decoration: none; }
.stars { color: #f5a524; letter-spacing: 1px; }
.prix { font-size: 32px; font-weight: 800; color: var(--primary); }
.prix small { font-size: 15px; color: var(--muted); font-weight: 600; }
.badge { width: fit-content; }
.commande { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 6px; }
.qte { display: flex; align-items: center; gap: 10px; padding: 4px; border: 1px solid var(--border); border-radius: 999px; }
.qte b { min-width: 22px; text-align: center; font-size: 17px; }
.btn.wa { background: #1da851; border-color: #1da851; color: #fff; flex: 1 1 220px; justify-content: center; padding: 12px 16px; font-size: 15.5px; }
.btn.wa:hover { filter: brightness(1.06); }
.dans { margin-left: 4px; color: var(--success); }
.appel { width: fit-content; }
.bloc { border-top: 1px solid var(--border); padding-top: 14px; margin-top: 8px; }
.bloc h2, .bloc-page h2 { font-size: 18px; margin: 0 0 10px; display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.texte { margin: 0; white-space: pre-line; line-height: 1.65; }
.tableau { display: grid; grid-template-columns: minmax(110px, max-content) 1fr; margin: 0; border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
.tableau dt, .tableau dd { margin: 0; padding: 9px 14px; border-bottom: 1px solid var(--border); }
.tableau dt { color: var(--muted); background: var(--surface-2); font-weight: 600; }
.tableau dd.large { grid-column: 1 / -1; }
.bloc-page { margin-top: 34px; }
.avis-liste { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; }
.avis { padding: 14px 16px; border: 1px solid var(--border); border-radius: 18px; background: var(--surface); }
.avis header { display: flex; align-items: center; gap: 10px; }
.avis header div { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.pastille { width: 32px; height: 32px; border-radius: 50%; display: grid; place-items: center; color: #fff; font-weight: 800; flex: none; }
.avis p { margin: 10px 0 0; white-space: pre-line; }
.reponse { margin-top: 10px; padding: 8px 12px; border-left: 3px solid var(--primary); background: var(--primary-soft); border-radius: 0 10px 10px 0; font-size: 14px; white-space: pre-line; }
.reponse b { display: block; font-size: 12.5px; color: var(--primary); }
.similaires { display: flex; gap: 12px; overflow-x: auto; padding-bottom: 6px; }
.mini { flex: none; width: 150px; display: flex; flex-direction: column; gap: 4px; color: inherit; text-decoration: none; }
.mini-photo { aspect-ratio: 1; border-radius: 16px; overflow: hidden; border: 1px solid var(--border); background: #fff; display: grid; place-items: center; color: var(--muted); }
.mini-photo img { width: 100%; height: 100%; object-fit: contain; }
.mini b { font-size: 14px; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.mini small { color: var(--primary); font-weight: 800; }
.etat { display: flex; flex-direction: column; align-items: center; gap: 8px; text-align: center; padding: 60px 16px; color: var(--muted); }
.etat h2 { margin: 4px 0 0; color: var(--text); }
</style>
