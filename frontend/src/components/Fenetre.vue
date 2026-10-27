<script>
// État partagé par toutes les fenêtres : ordre d'ouverture (la plus récente passe devant) et fenêtres réduites (onglets en bas d'écran).
import { reactive } from 'vue'
const pile = reactive([])
const reduites = reactive([])
let compteur = 0
</script>

<script setup>
/**
 * Fenêtre commune à tous les écrans : en-tête (titre, réduire, agrandir, fermer), corps qui défile, pied fixe pour les actions.
 * Réduite, elle devient un onglet en bas de l'écran : la page reste consultable et la saisie en cours est conservée.
 *   <Fenetre titre="…" @close="…"> contenu <template #pied> boutons </template></Fenetre>
 */
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import Icon from './Icon.vue'

const props = defineProps({
  titre: { type: String, required: true },
  sousTitre: { type: String, default: '' },
  icone: { type: String, default: '' },
  largeur: { type: [String, Number], default: 'md' },   // sm 440 · md 560 · lg 720 · xl 960, ou une largeur en pixels
  tiroir: Boolean,          // panneau plein hauteur à droite (formulaires longs)
  formulaire: Boolean,      // <form> : Entrée valide le formulaire, le bouton « submit » est dans le pied
  occupe: Boolean,          // traitement en cours : ni fermeture ni réduction
  reductible: { type: Boolean, default: true },
  alerte: Boolean,          // confirmation (role alertdialog)
  grille: Boolean,          // corps en deux colonnes (champs .full sur toute la largeur)
  plein: Boolean,           // corps sans marge ni défilement propre (éditeur, contenu qui gère sa mise en page)
})
const emit = defineEmits(['close', 'submit', 'redimension'])

const id = ++compteur
const titreId = `fen-${id}-titre`
const LARGEURS = { sm: 440, md: 560, lg: 720, xl: 960 }
const largeurPx = computed(() => (typeof props.largeur === 'number' ? props.largeur : LARGEURS[props.largeur] || 560))
const niveau = pile.length
pile.push(id)
const reduite = computed(() => reduites.includes(id))
const rang = computed(() => reduites.indexOf(id))
const agrandie = ref(false)
watch(agrandie, () => nextTick(() => emit('redimension')))   // le contenu peut se remesurer (ex. toile de l'éditeur photo)
const panneau = ref(null)
let avant = null

function fermer() { if (!props.occupe) emit('close') }
function reduire() { if (!props.occupe && !reduite.value) reduites.push(id) }
function restaurer() {
  const i = reduites.indexOf(id)
  if (i >= 0) reduites.splice(i, 1)
  nextTick(() => panneau.value?.focus({ preventScroll: true }))
}
function surTouche(e) {
  if (e.key !== 'Escape' || e.defaultPrevented) return
  e.stopPropagation()
  fermer()
}

onMounted(() => {
  avant = document.activeElement
  nextTick(() => {
    const el = panneau.value
    if (!el || el.contains(document.activeElement)) return   // un champ s'est déjà donné le focus
    ;(el.querySelector('[autofocus]') || el).focus({ preventScroll: true })
  })
})
onBeforeUnmount(() => {
  pile.splice(pile.indexOf(id), 1)
  if (reduites.includes(id)) reduites.splice(reduites.indexOf(id), 1)
  if (avant?.isConnected) avant.focus?.({ preventScroll: true })
})
</script>

<template>
  <Teleport to="body">
    <div v-show="!reduite" class="overlay" :class="{ center: !tiroir }" :style="{ zIndex: 50 + niveau * 5 }" @click.self="fermer">
      <component :is="formulaire ? 'form' : 'div'" ref="panneau" class="fen" :class="[tiroir ? 'drawer' : 'modal', { agrandie }]" :style="{ '--fen-l': largeurPx + 'px' }"
                 tabindex="-1" :role="alerte ? 'alertdialog' : 'dialog'" aria-modal="true" :aria-labelledby="titreId" :novalidate="formulaire || undefined"
                 @submit.prevent="emit('submit', $event)" @keydown="surTouche">
        <div class="fen-tete">
          <span v-if="icone" class="fen-icone" aria-hidden="true"><Icon :name="icone" :size="18" /></span>
          <div class="fen-titres">
            <h2 :id="titreId">{{ titre }}</h2>
            <p v-if="sousTitre || $slots['sous-titre']"><slot name="sous-titre">{{ sousTitre }}</slot></p>
          </div>
          <slot name="entete" />
          <div class="fen-btns">
            <button v-if="reductible" type="button" class="fen-btn" title="Réduire" aria-label="Réduire la fenêtre" :disabled="occupe" @click="reduire"><Icon name="minus" :size="17" /></button>
            <button type="button" class="fen-btn agrandir" :title="agrandie ? 'Taille normale' : 'Agrandir'" :aria-label="agrandie ? 'Revenir à la taille normale' : 'Agrandir la fenêtre'" :aria-pressed="agrandie" @click="agrandie = !agrandie"><Icon :name="agrandie ? 'shrink' : 'expand'" :size="15" /></button>
            <button type="button" class="fen-btn fermer" title="Fermer" aria-label="Fermer la fenêtre" :disabled="occupe" @click="fermer"><Icon name="x" :size="18" /></button>
          </div>
        </div>
        <div class="fen-corps" :class="{ grille, plein }"><slot /></div>
        <div v-if="$slots.pied" class="fen-pied"><slot name="pied" /></div>
      </component>
    </div>
    <div v-if="reduite" class="fen-reduite" :style="{ '--i': rang }" role="group" :aria-label="`Fenêtre réduite : ${titre}`">
      <button type="button" class="fen-restaurer" :title="`Rouvrir « ${titre} »`" @click="restaurer"><Icon :name="icone || 'expand'" :size="16" /><span>{{ titre }}</span></button>
      <button type="button" class="fen-btn fermer" title="Fermer" aria-label="Fermer la fenêtre" :disabled="occupe" @click="fermer"><Icon name="x" :size="16" /></button>
    </div>
  </Teleport>
</template>
