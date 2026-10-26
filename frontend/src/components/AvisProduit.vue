<script setup>
// Avis clients d'un produit : note moyenne, répartition par étoiles et par source, liste des avis avec capture et lien d'origine.
import { ref, computed } from 'vue'
import { api, auth, fileUrl } from '../api'
import { toast } from '../toast'
import { resources, avisChamps } from '../resources'
import { SOURCES, NOTES, etoiles } from '../avis'
import Icon from './Icon.vue'
import FormDrawer from './FormDrawer.vue'
import Lightbox from './Lightbox.vue'

const props = defineProps({
  idprod: { type: [String, Number], required: true },
  avis: { type: Array, default: () => [] },
  total: { type: Number, default: 0 },
  synthese: { type: Object, required: true },
  modifiable: Boolean,
})
const emit = defineEmits(['changed'])

const formulaire = ref(undefined)   // undefined = fermé, null = nouvel avis, objet = modification
const capture = ref(null)
const config = computed(() => ({ ...resources.avis, fields: avisChamps(String(props.idprod)) }))
const pct = (n) => (props.synthese.nb ? Math.round((props.synthese.etoiles[n] / props.synthese.nb) * 100) : 0)
const dateFr = (d) => (d ? new Date(d).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' }) : '')
const src = (a) => SOURCES[a.source] || SOURCES.autre

function enregistre() { formulaire.value = undefined; emit('changed') }
async function supprimer(a) {
  if (!confirm(`Supprimer l'avis de ${a.auteur || 'ce client'} ?`)) return
  try { await api.del('avis', a.idavis); toast('Avis supprimé'); emit('changed') } catch (e) { toast(e.message, 'error') }
}
</script>

<template>
  <section class="card card-pad reveal avis">
    <div class="card-head">
      <h2>Avis clients</h2>
      <div class="row-btns">
        <RouterLink v-if="total > avis.length" :to="{ path: '/avis', query: { idprod: String(idprod) } }" class="btn sm ghost">Tous les avis ({{ total }})</RouterLink>
        <button v-if="modifiable" class="btn sm primary" @click="formulaire = null"><Icon name="plus" :size="15" /> Ajouter un avis</button>
      </div>
    </div>

    <div v-if="!synthese.nb" class="vide">
      <Icon name="message" :size="34" />
      <p>Aucun avis pour ce produit.</p>
      <small class="muted">Recopiez ici les avis reçus sur Facebook, Google, TikTok, Instagram, WhatsApp ou par SMS, avec une capture d'écran comme preuve.</small>
    </div>

    <template v-else>
      <div class="synthese">
        <div class="moyenne">
          <b>{{ String(synthese.moyenne).replace('.', ',') }}</b>
          <span class="stars" :aria-label="`${synthese.moyenne} sur 5`">{{ etoiles(synthese.moyenne) }}</span>
          <small class="muted">{{ synthese.nb }} avis</small>
        </div>
        <div class="barres">
          <div v-for="n in [5, 4, 3, 2, 1]" :key="n" class="barre"><span>{{ n }} ★</span><i><em :style="{ width: pct(n) + '%' }" /></i><small>{{ synthese.etoiles[n] }}</small></div>
        </div>
        <div class="sources">
          <span v-for="s in synthese.sources" :key="s.source" class="src" :style="{ '--c': SOURCES[s.source]?.couleur }"><i>{{ SOURCES[s.source]?.lettre }}</i>{{ s.libelle }} · {{ s.nb }} · {{ String(s.moyenne).replace('.', ',') }} ★</span>
        </div>
      </div>

      <div class="liste">
        <article v-for="a in avis" :key="a.idavis" class="un-avis">
          <header>
            <span class="pastille" :style="{ background: src(a).couleur }" :title="src(a).label">{{ src(a).lettre }}</span>
            <div class="qui"><b>{{ a.auteur || 'Client anonyme' }}</b><small class="muted">{{ src(a).label }}<template v-if="a.date_avis"> · {{ dateFr(a.date_avis) }}</template><template v-if="a.telephone"> · {{ a.telephone }}</template></small></div>
            <span class="stars" :class="a.note >= 4 ? 'bon' : a.note <= 2 ? 'mauvais' : ''" :title="NOTES[a.note]">{{ etoiles(a.note) }}</span>
          </header>
          <p v-if="a.commentaire" class="texte">{{ a.commentaire }}</p>
          <div v-if="a.reponse" class="reponse"><b>Notre réponse</b>{{ a.reponse }}</div>
          <footer>
            <button v-if="a.capture" class="btn sm ghost" @click="capture = a"><Icon name="image" :size="15" /> Capture</button>
            <a v-if="a.lien" class="btn sm ghost" :href="a.lien" target="_blank" rel="noopener noreferrer"><Icon name="external" :size="15" /> Voir sur {{ src(a).label }}</a>
            <span class="grow" />
            <button v-if="modifiable" class="btn sm ghost icon" aria-label="Modifier l'avis" title="Modifier" @click="formulaire = a"><Icon name="edit" :size="15" /></button>
            <button v-if="auth.user?.admin" class="btn sm ghost icon danger-txt" aria-label="Supprimer l'avis" title="Supprimer" @click="supprimer(a)"><Icon name="trash" :size="15" /></button>
          </footer>
        </article>
      </div>
    </template>

    <FormDrawer v-if="formulaire !== undefined" :config="config" route="avis" id-key="idavis" :row="formulaire" @close="formulaire = undefined" @saved="enregistre" />
    <Lightbox v-if="capture" :photos="[{ src: fileUrl('avis', capture.capture), legende: `Avis ${src(capture).label} · ${capture.auteur || 'client'}` }]" @close="capture = null" />
  </section>
</template>

<style scoped>
.row-btns { display: flex; gap: 8px; flex-wrap: wrap; }
.vide { display: flex; flex-direction: column; align-items: center; text-align: center; gap: 4px; padding: 18px 8px; color: var(--muted); }
.vide p { margin: 6px 0 0; font-weight: 700; color: var(--text); }
.synthese { display: grid; grid-template-columns: auto 1fr; gap: 14px 26px; align-items: center; padding-bottom: 16px; border-bottom: 1px solid var(--border); margin-bottom: 6px; }
.moyenne { display: flex; flex-direction: column; align-items: center; }
.moyenne b { font-size: 42px; line-height: 1; }
.stars { color: #f5a524; letter-spacing: 1px; white-space: nowrap; }
.stars.mauvais { color: var(--danger); }
.barres { display: flex; flex-direction: column; gap: 4px; }
.barre { display: grid; grid-template-columns: 34px 1fr 28px; align-items: center; gap: 8px; font-size: 13px; }
.barre i { height: 8px; border-radius: 99px; background: var(--surface-2); overflow: hidden; }
.barre em { display: block; height: 100%; background: #f5a524; border-radius: inherit; }
.barre small { color: var(--muted); text-align: right; }
.sources { grid-column: 1 / -1; display: flex; flex-wrap: wrap; gap: 6px; }
.src { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px 3px 3px; border-radius: 999px; background: var(--surface-2); font-size: 12.5px; font-weight: 700; }
.src i { width: 20px; height: 20px; border-radius: 50%; display: grid; place-items: center; background: var(--c); color: #fff; font-style: normal; font-size: 11px; }
.liste { display: flex; flex-direction: column; }
.un-avis { padding: 14px 0; border-bottom: 1px solid var(--border); }
.un-avis:last-child { border-bottom: 0; }
.un-avis header { display: flex; align-items: center; gap: 10px; }
.pastille { width: 34px; height: 34px; border-radius: 50%; display: grid; place-items: center; color: #fff; font-weight: 800; flex: none; }
.qui { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.texte { margin: 8px 0 0 44px; white-space: pre-line; }
.reponse { margin: 8px 0 0 44px; padding: 8px 12px; border-left: 3px solid var(--primary); background: var(--primary-soft); border-radius: 0 10px 10px 0; white-space: pre-line; font-size: 14px; }
.reponse b { display: block; font-size: 12.5px; color: var(--primary); }
.un-avis footer { display: flex; align-items: center; gap: 4px; margin: 6px 0 0 38px; flex-wrap: wrap; }
.grow { flex: 1; }
.danger-txt { color: var(--danger); }
@media (max-width: 560px) { .synthese { grid-template-columns: 1fr; } .texte, .reponse { margin-left: 0; } .un-avis footer { margin-left: 0; } }
</style>
