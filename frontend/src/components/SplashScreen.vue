<script setup>
// Écrans « Bienvenue » et « À bientôt » : dégradé aux couleurs de la palette, logo, prénom animé lettre par lettre, progression, voiture qui roule.
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { splash, DUREES } from '../splash'
import { logoUrl, nomApp } from '../theme'
import Icon from './Icon.vue'
import Illus from './Illus.vue'

const etape = ref(0)
let timers = []
const vider = () => { timers.forEach(clearTimeout); timers = [] }
onBeforeUnmount(vider)

const phrases = computed(() => splash.mode === 'bienvenue'
  ? ['Connexion sécurisée…', 'Chargement de vos données…', 'Préparation du tableau de bord…', 'Tout est prêt !']
  : ['Enregistrement de vos modifications…', 'Fermeture sécurisée de la session…', 'Session fermée'])
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
        <div class="emblem">
          <img v-if="logoUrl" :src="logoUrl" alt="" />
          <Icon v-else-if="splash.mode === 'aurevoir' && fini" name="check" :size="42" />
          <Icon v-else name="cart" :size="42" />
          <span class="ring-pulse" />
        </div>
        <p class="kicker">{{ splash.mode === 'bienvenue' ? 'Bienvenue' : 'À bientôt' }}</p>
        <h1 class="name" :aria-label="splash.nom">
          <span v-for="(l, i) in lettres" :key="i" class="ch" :style="{ animationDelay: 180 + i * 55 + 'ms' }">{{ l === ' ' ? ' ' : l }}</span>
        </h1>
        <p class="step" :key="etape">{{ phrases[etape] }}</p>
        <div class="bar" aria-hidden="true"><i /></div>
      </div>

      <div class="road" aria-hidden="true"><span class="car"><Illus name="cart" :size="74" /></span></div>
    </div>
  </Transition>
</template>
