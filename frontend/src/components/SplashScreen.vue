<script setup>
// Écrans « Bienvenue » et « À bientôt » : dégradé aux couleurs de la palette, photo de l'utilisateur (sinon logo) au centre d'un anneau de progression,
// articles de boutique en orbite, prénom animé lettre par lettre et liste d'étapes cochées (façon ticket de caisse).
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { splash, DUREES } from '../splash'
import { logoUrl, nomApp } from '../theme'
import Icon from './Icon.vue'

const orbite = [['box', 0], ['tag', 72], ['receipt', 144], ['cash', 216], ['truck', 288]]
const etape = ref(0)
let timers = []
const vider = () => { timers.forEach(clearTimeout); timers = [] }
onBeforeUnmount(vider)

const phrases = computed(() => splash.mode === 'bienvenue'
  ? ['Connexion sécurisée', 'Chargement du catalogue et du stock', 'Calcul des ventes du jour', 'Caisse prête !']
  : ['Enregistrement de vos modifications', 'Fermeture sécurisée de la session', 'Session fermée'])
const duree = computed(() => DUREES[splash.mode])
const lettres = computed(() => [...(splash.nom || nomApp.value)])

watch(() => splash.cycle, () => {
  vider(); etape.value = 0
  const n = phrases.value.length
  for (let i = 1; i < n; i++) timers.push(setTimeout(() => (etape.value = i), (duree.value * i) / n - 150))
})
const fini = computed(() => etape.value === phrases.value.length - 1)
</script>

<template>
  <Transition name="splash">
    <div v-if="splash.visible" :key="splash.cycle" class="splash" :class="splash.mode" role="status" aria-live="polite" :style="{ '--dur': duree + 'ms' }">
      <span class="blob b1" /><span class="blob b2" /><span class="blob b3" />

      <div class="splash-card">
        <div class="stage">
          <svg class="ring" viewBox="0 0 160 160" aria-hidden="true"><circle class="track" cx="80" cy="80" r="72" /><circle class="prog" cx="80" cy="80" r="72" pathLength="100" /></svg>
          <div class="orbit" aria-hidden="true"><span v-for="[ic, a] in orbite" :key="ic" class="o" :style="{ '--a': a + 'deg' }"><span class="u" :style="{ '--a': a + 'deg' }"><i><Icon :name="ic" :size="18" /></i></span></span></div>
          <div class="emblem" :class="{ photo: splash.photo }">
            <img v-if="splash.photo" :src="splash.photo" alt="" @error="splash.photo = ''" />
            <img v-else-if="logoUrl" :src="logoUrl" alt="" />
            <Icon v-else-if="splash.mode === 'aurevoir' && fini" name="check" :size="42" />
            <Icon v-else name="cart" :size="42" />
          </div>
          <span v-if="fini" class="badge-ok"><Icon name="check" :size="16" /></span>
        </div>
        <p class="kicker">{{ splash.mode === 'bienvenue' ? 'Bienvenue' : 'À bientôt' }}</p>
        <h1 class="name" :aria-label="splash.nom">
          <span v-for="(l, i) in lettres" :key="i" class="ch" :style="{ animationDelay: 180 + i * 55 + 'ms' }">{{ l === ' ' ? ' ' : l }}</span>
        </h1>
        <ul class="etapes-s" aria-hidden="true">
          <li v-for="(p, i) in phrases" :key="p" :class="{ done: i < etape || fini, active: i === etape && !fini }">
            <span class="tick"><Icon name="check" :size="12" /></span>{{ p }}
          </li>
        </ul>
        <span class="sr-only">{{ phrases[etape] }}</span>
      </div>

    </div>
  </Transition>
</template>
