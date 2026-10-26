<script setup>
// Visionneuse plein écran : zoom à la molette, au pincement, au double-clic ou aux boutons ; déplacement à la souris ou au doigt ;
// photo précédente / suivante au clavier, aux flèches ou par balayage.
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import Icon from './Icon.vue'

const props = defineProps({
  photos: { type: Array, required: true },   // [{ src, legende }]
  index: { type: Number, default: 0 },
})
const emit = defineEmits(['close'])

const i = ref(props.index)
const v = reactive({ s: 1, x: 0, y: 0 })
const scene = ref(null)
const photo = computed(() => props.photos[i.value])
const ZMIN = 1, ZMAX = 6
const zoomPct = computed(() => Math.round(v.s * 100))

function reinit() { Object.assign(v, { s: 1, x: 0, y: 0 }) }
watch(i, reinit)
const aller = (d) => { if (props.photos.length > 1) i.value = (i.value + d + props.photos.length) % props.photos.length }

/** Zoom autour d'un point de l'écran (le point visé reste sous le curseur). */
function zoomer(facteur, cx, cy) {
  const r = scene.value.getBoundingClientRect()
  const px = (cx ?? r.left + r.width / 2) - (r.left + r.width / 2), py = (cy ?? r.top + r.height / 2) - (r.top + r.height / 2)
  const s = Math.min(ZMAX, Math.max(ZMIN, v.s * facteur))
  if (s === 1) return reinit()
  v.x = px - (px - v.x) * (s / v.s); v.y = py - (py - v.y) * (s / v.s); v.s = s
}
function molette(e) { e.preventDefault(); zoomer(e.deltaY < 0 ? 1.2 : 1 / 1.2, e.clientX, e.clientY) }
function doubleClic(e) { if (v.s > 1) reinit(); else zoomer(2.5, e.clientX, e.clientY) }

/* Pointeurs : un doigt (ou la souris) déplace l'image zoomée ou balaie ; deux doigts zooment */
const doigts = new Map()
let depart = null
function bas(e) {
  scene.value.setPointerCapture(e.pointerId)
  doigts.set(e.pointerId, { x: e.clientX, y: e.clientY })
  depart = { x: e.clientX, y: e.clientY, vx: v.x, vy: v.y, dist: null, s: v.s }
  if (doigts.size === 2) { const [a, b] = [...doigts.values()]; depart.dist = Math.hypot(a.x - b.x, a.y - b.y) }
}
function bouge(e) {
  if (!doigts.has(e.pointerId) || !depart) return
  doigts.set(e.pointerId, { x: e.clientX, y: e.clientY })
  if (doigts.size === 2 && depart.dist) {
    const [a, b] = [...doigts.values()]
    zoomer((depart.s * Math.hypot(a.x - b.x, a.y - b.y) / depart.dist) / v.s, (a.x + b.x) / 2, (a.y + b.y) / 2)
  } else if (v.s > 1) { v.x = depart.vx + e.clientX - depart.x; v.y = depart.vy + e.clientY - depart.y }
}
function haut(e) {
  if (depart && doigts.size === 1 && v.s === 1) {   // balayage horizontal sans zoom : photo suivante / précédente
    const dx = e.clientX - depart.x
    if (Math.abs(dx) > 60) aller(dx < 0 ? 1 : -1)
  }
  doigts.delete(e.pointerId)
  if (!doigts.size) depart = null
}

function touche(e) {
  if (e.key === 'Escape') emit('close')
  else if (e.key === 'ArrowRight') aller(1)
  else if (e.key === 'ArrowLeft') aller(-1)
  else if (e.key === '+' || e.key === '=') zoomer(1.4)
  else if (e.key === '-') zoomer(1 / 1.4)
  else if (e.key === '0') reinit()
}
onMounted(() => { window.addEventListener('keydown', touche); document.body.style.overflow = 'hidden' })
onBeforeUnmount(() => { window.removeEventListener('keydown', touche); document.body.style.overflow = '' })
</script>

<template>
  <Teleport to="body">
  <div class="lightbox" role="dialog" aria-modal="true" aria-label="Visionneuse de photos">
    <header>
      <span class="compteur">{{ i + 1 }} / {{ photos.length }}<template v-if="photo.legende"> · {{ photo.legende }}</template></span>
      <div class="outils">
        <button class="btn icon ghost" aria-label="Dézoomer" title="Dézoomer (−)" :disabled="v.s <= 1" @click="zoomer(1 / 1.4)"><Icon name="zoom-out" /></button>
        <button class="btn ghost zoom" title="Taille réelle (0)" @click="reinit">{{ zoomPct }} %</button>
        <button class="btn icon ghost" aria-label="Zoomer" title="Zoomer (+)" :disabled="v.s >= 6" @click="zoomer(1.4)"><Icon name="zoom-in" /></button>
        <a class="btn icon ghost" :href="photo.src" target="_blank" rel="noopener" aria-label="Ouvrir l'original" title="Ouvrir l'original"><Icon name="external" /></a>
        <button class="btn icon ghost" aria-label="Fermer" title="Fermer (Échap)" @click="emit('close')"><Icon name="x" /></button>
      </div>
    </header>
    <div ref="scene" class="scene" :class="{ zoome: v.s > 1 }" @wheel="molette" @dblclick="doubleClic" @pointerdown="bas" @pointermove="bouge" @pointerup="haut" @pointercancel="haut">
      <img :key="photo.src" :src="photo.src" :alt="photo.legende || ''" draggable="false" :style="{ transform: `translate(${v.x}px, ${v.y}px) scale(${v.s})` }" />
    </div>
    <button v-if="photos.length > 1" class="nav prec" aria-label="Photo précédente" @click="aller(-1)"><Icon name="chevL" :size="26" /></button>
    <button v-if="photos.length > 1" class="nav suiv" aria-label="Photo suivante" @click="aller(1)"><Icon name="chevR" :size="26" /></button>
    <footer v-if="photos.length > 1">
      <button v-for="(p, n) in photos" :key="n" class="vignette" :class="{ on: n === i }" :aria-label="`Photo ${n + 1}`" @click="i = n"><img :src="p.src" alt="" /></button>
    </footer>
    <p class="aide">Molette, pincement ou double-clic pour zoomer · glisser pour se déplacer</p>
  </div>
  </Teleport>
</template>

<style scoped>
.lightbox { position: fixed; inset: 0; z-index: 90; background: rgba(8, 10, 16, .94); color: #fff; display: flex; flex-direction: column; animation: pop .2s var(--ease); }
header { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 14px; }
.compteur { font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.outils { display: flex; align-items: center; gap: 4px; }
.lightbox .btn.ghost { color: #fff; }
.lightbox .btn.ghost:hover { background: rgba(255, 255, 255, .12); }
.zoom { min-width: 70px; font-variant-numeric: tabular-nums; }
.scene { flex: 1; min-height: 0; display: grid; place-items: center; overflow: hidden; touch-action: none; cursor: zoom-in; }
.scene.zoome { cursor: grab; }
.scene.zoome:active { cursor: grabbing; }
.scene img { max-width: 100%; max-height: 100%; object-fit: contain; user-select: none; transition: transform .12s ease-out; will-change: transform; }
.nav { position: absolute; top: 50%; transform: translateY(-50%); width: 48px; height: 48px; border-radius: 50%; border: 0; display: grid; place-items: center; background: rgba(255, 255, 255, .14); color: #fff; cursor: pointer; }
.nav:hover { background: rgba(255, 255, 255, .26); }
.nav.prec { left: 14px; } .nav.suiv { right: 14px; }
footer { display: flex; justify-content: center; gap: 8px; padding: 10px; overflow-x: auto; }
.vignette { flex: none; width: 58px; height: 58px; padding: 0; border-radius: 10px; border: 2px solid transparent; overflow: hidden; background: none; cursor: pointer; opacity: .55; }
.vignette.on { border-color: #fff; opacity: 1; }
.vignette img { width: 100%; height: 100%; object-fit: cover; }
.aide { margin: 0 0 10px; text-align: center; font-size: 12.5px; opacity: .6; }
@media (max-width: 640px) { .nav { display: none; } .aide { display: none; } }
</style>
