<script setup>
// Retouche d'une photo avant enregistrement : recadrage (libre ou au format), rotation, miroir, luminosité, contraste, saturation.
// L'aperçu utilise un filtre CSS ; l'export recalcule chaque pixel en pleine résolution puis réduit à TAILLE_MAX (JPEG compressé).
import { ref, reactive, computed, onMounted, onBeforeUnmount, watch, nextTick } from 'vue'
import Icon from './Icon.vue'
import { chargerImage, appliquerReglages, filtreCss, versFichier, nomJpeg, TAILLE_MAX } from '../images'

const props = defineProps({
  source: { type: [File, Blob, String], required: true },   // fichier choisi ou adresse d'une photo déjà enregistrée
  nom: { type: String, default: 'photo.jpg' },
})
const emit = defineEmits(['close', 'done'])

const img = ref(null)
const erreur = ref('')
const busy = ref(false)
const canvas = ref(null)
const scene = ref(null)
const t = reactive({ rotation: 0, miroirH: false, miroirV: false, luminosite: 100, contraste: 100, saturation: 100 })
const cadre = reactive({ x: 0, y: 0, w: 1, h: 1 })   // recadrage, en fraction de l'image affichée
const format = ref(null)                              // null = libre
const affiche = reactive({ w: 0, h: 0 })

const FORMATS = [{ v: null, l: 'Libre' }, { v: 1, l: 'Carré' }, { v: 4 / 3, l: '4:3' }, { v: 3 / 4, l: '3:4' }, { v: 16 / 9, l: '16:9' }]
const tourne = computed(() => t.rotation % 180 !== 0)
const dims = computed(() => (img.value ? (tourne.value ? [img.value.naturalHeight, img.value.naturalWidth] : [img.value.naturalWidth, img.value.naturalHeight]) : [0, 0]))
const sortie = computed(() => {
  const [W, H] = dims.value
  const w = cadre.w * W, h = cadre.h * H, k = Math.min(1, TAILLE_MAX / Math.max(w, h || 1))
  return `${Math.round(w * k)} × ${Math.round(h * k)} px`
})
const modifie = computed(() => t.rotation || t.miroirH || t.miroirV || t.luminosite !== 100 || t.contraste !== 100 || t.saturation !== 100 || cadre.x || cadre.y || cadre.w < 1 || cadre.h < 1)

/** Dessine l'image tournée / retournée dans un canevas à l'échelle k. */
function dessiner(cv, k) {
  const [W, H] = dims.value
  cv.width = Math.round(W * k); cv.height = Math.round(H * k)
  const ctx = cv.getContext('2d')
  ctx.save()
  ctx.translate(cv.width / 2, cv.height / 2)
  ctx.rotate((t.rotation * Math.PI) / 180)
  ctx.scale(t.miroirH ? -1 : 1, t.miroirV ? -1 : 1)
  const w = img.value.naturalWidth * k, h = img.value.naturalHeight * k
  ctx.drawImage(img.value, -w / 2, -h / 2, w, h)
  ctx.restore()
  return ctx
}

function rafraichir() {
  if (!img.value || !canvas.value || !scene.value) return
  const [W, H] = dims.value
  const k = Math.min(1, (scene.value.clientWidth - 8) / W, (scene.value.clientHeight - 8) / H)
  dessiner(canvas.value, k)
  affiche.w = canvas.value.width; affiche.h = canvas.value.height
}

/* ---- Recadrage ---- */
const ratio = () => (format.value ? (format.value * affiche.h) / affiche.w : null)   // largeur / hauteur en fractions de l'image
function cadrer(f) {
  format.value = f
  const r = ratio()
  if (!r) return Object.assign(cadre, { x: 0, y: 0, w: 1, h: 1 })
  const w = r >= 1 ? 1 : r, h = r >= 1 ? 1 / r : 1
  Object.assign(cadre, { w, h, x: (1 - w) / 2, y: (1 - h) / 2 })
}
const borne = (v, a, b) => Math.min(b, Math.max(a, v))
let geste = null
function saisir(e, coin) {
  e.preventDefault()
  e.currentTarget.setPointerCapture(e.pointerId)
  geste = { coin, sx: e.clientX, sy: e.clientY, depart: { ...cadre } }
}
function glisser(e) {
  if (!geste) return
  const dx = (e.clientX - geste.sx) / affiche.w, dy = (e.clientY - geste.sy) / affiche.h, d = geste.depart
  if (geste.coin === 'centre') { cadre.x = borne(d.x + dx, 0, 1 - d.w); cadre.y = borne(d.y + dy, 0, 1 - d.h); return }
  // Le coin opposé reste fixe ; avec un format imposé, on réduit le côté en trop (le cadre reste donc dans l'image)
  const gauche = geste.coin.includes('o'), haut = geste.coin.includes('n')
  const ax = gauche ? d.x + d.w : d.x, ay = haut ? d.y + d.h : d.y
  const px = borne((gauche ? d.x : d.x + d.w) + dx, 0, 1), py = borne((haut ? d.y : d.y + d.h) + dy, 0, 1)
  let w = Math.max(0.05, Math.abs(px - ax)), h = Math.max(0.05, Math.abs(py - ay))
  const r = ratio()
  if (r) { if (w / h > r) w = h * r; else h = w / r }
  cadre.w = Math.min(w, gauche ? ax : 1 - ax); cadre.h = Math.min(h, haut ? ay : 1 - ay)
  if (r) { if (cadre.w / cadre.h > r) cadre.w = cadre.h * r; else cadre.h = cadre.w / r }
  cadre.x = gauche ? ax - cadre.w : ax; cadre.y = haut ? ay - cadre.h : ay
}
const lacher = () => { geste = null }

/* ---- Transformations ---- */
async function tourner(sens) { t.rotation = (t.rotation + (sens > 0 ? 90 : 270)) % 360; await nextTick(); rafraichir(); cadrer(format.value) }
function auto() { Object.assign(t, { luminosite: 105, contraste: 112, saturation: 118 }) }
async function reinitialiser() { Object.assign(t, { rotation: 0, miroirH: false, miroirV: false, luminosite: 100, contraste: 100, saturation: 100 }); await nextTick(); rafraichir(); cadrer(null) }
watch(() => [t.miroirH, t.miroirV], rafraichir)

async function appliquer() {
  busy.value = true
  try {
    const plein = document.createElement('canvas')
    dessiner(plein, 1)
    const cx = Math.round(cadre.x * plein.width), cy = Math.round(cadre.y * plein.height)
    const cw = Math.max(1, Math.round(cadre.w * plein.width)), ch = Math.max(1, Math.round(cadre.h * plein.height))
    const k = Math.min(1, TAILLE_MAX / Math.max(cw, ch))
    const out = document.createElement('canvas')
    out.width = Math.round(cw * k); out.height = Math.round(ch * k)
    const ctx = out.getContext('2d')
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, out.width, out.height)   // JPEG : fond blanc sous les zones transparentes
    ctx.imageSmoothingQuality = 'high'
    ctx.drawImage(plein, cx, cy, cw, ch, 0, 0, out.width, out.height)
    appliquerReglages(ctx, out.width, out.height, t)
    emit('done', await versFichier(out, nomJpeg(props.nom, '-retouche')))
  } catch (e) { erreur.value = e.message } finally { busy.value = false }
}

function touche(e) { if (e.key === 'Escape') emit('close') }
onMounted(async () => {
  window.addEventListener('keydown', touche)
  window.addEventListener('resize', rafraichir)
  try { img.value = await chargerImage(props.source); await nextTick(); rafraichir() } catch (e) { erreur.value = e.message }
})
onBeforeUnmount(() => { window.removeEventListener('keydown', touche); window.removeEventListener('resize', rafraichir) })
</script>

<template>
  <Teleport to="body">
  <div class="overlay center editeur-fond" @click.self="emit('close')">
    <div class="modal editeur" role="dialog" aria-modal="true" aria-label="Retoucher la photo">
      <header><h3><Icon name="sliders" :size="18" /> Retoucher la photo</h3><button type="button" class="btn ghost icon" aria-label="Fermer" @click="emit('close')"><Icon name="x" /></button></header>
      <div class="corps">
        <div ref="scene" class="scene">
          <div v-if="erreur" class="alert">{{ erreur }}</div>
          <div v-else-if="!img" class="skeleton" style="width:100%;height:100%" />
          <div v-show="img && !erreur" class="toile" :style="{ width: affiche.w + 'px', height: affiche.h + 'px' }" @pointermove="glisser" @pointerup="lacher" @pointercancel="lacher">
            <canvas ref="canvas" :style="{ filter: filtreCss(t) }" />
            <div class="cadre" :style="{ left: cadre.x * 100 + '%', top: cadre.y * 100 + '%', width: cadre.w * 100 + '%', height: cadre.h * 100 + '%' }" @pointerdown="saisir($event, 'centre')">
              <i class="tiers v1" /><i class="tiers v2" /><i class="tiers h1" /><i class="tiers h2" />
              <span v-for="c in ['no', 'ne', 'so', 'se']" :key="c" class="poignee" :class="c" @pointerdown.stop="saisir($event, c)" />
            </div>
          </div>
        </div>

        <aside class="outils">
          <section>
            <h4><Icon name="crop" :size="15" /> Recadrage</h4>
            <div class="choix"><button v-for="f in FORMATS" :key="f.l" type="button" class="btn sm" :class="{ primary: format === f.v }" @click="cadrer(f.v)">{{ f.l }}</button></div>
            <small class="muted">Faites glisser le cadre ou ses coins.</small>
          </section>
          <section>
            <h4><Icon name="rotate-r" :size="15" /> Orientation</h4>
            <div class="choix">
              <button type="button" class="btn sm icon" title="Tourner à gauche" aria-label="Tourner à gauche" @click="tourner(-1)"><Icon name="rotate-l" :size="16" /></button>
              <button type="button" class="btn sm icon" title="Tourner à droite" aria-label="Tourner à droite" @click="tourner(1)"><Icon name="rotate-r" :size="16" /></button>
              <button type="button" class="btn sm" :class="{ primary: t.miroirH }" @click="t.miroirH = !t.miroirH"><Icon name="flip" :size="15" /> Miroir</button>
              <button type="button" class="btn sm" :class="{ primary: t.miroirV }" @click="t.miroirV = !t.miroirV"><Icon name="flip" :size="15" style="transform:rotate(90deg)" /> Vertical</button>
            </div>
          </section>
          <section>
            <h4><Icon name="sun" :size="15" /> Réglages</h4>
            <label v-for="[k, l] in [['luminosite', 'Luminosité'], ['contraste', 'Contraste'], ['saturation', 'Couleurs']]" :key="k" class="curseur">
              <span>{{ l }} <b>{{ t[k] - 100 > 0 ? '+' : '' }}{{ t[k] - 100 }}</b></span>
              <input v-model.number="t[k]" type="range" min="40" max="180" step="1" @dblclick="t[k] = 100" />
            </label>
            <button type="button" class="btn sm" @click="auto"><Icon name="magic" :size="15" /> Amélioration automatique</button>
          </section>
          <small class="muted">Photo enregistrée : {{ sortie }}, JPEG compressé.</small>
        </aside>
      </div>
      <footer>
        <button type="button" class="btn ghost" :disabled="!modifie" @click="reinitialiser"><Icon name="history" :size="15" /> Réinitialiser</button>
        <span class="grow" />
        <button type="button" class="btn" @click="emit('close')">Annuler</button>
        <button type="button" class="btn primary" :disabled="!img || busy" @click="appliquer"><Icon name="check" :size="16" /> {{ busy ? 'Traitement…' : 'Appliquer' }}</button>
      </footer>
    </div>
  </div>
  </Teleport>
</template>

<style scoped>
.editeur-fond { z-index: 80; }
.editeur { width: min(1080px, calc(100vw - 24px)); max-height: calc(100vh - 24px); display: flex; flex-direction: column; padding: 0; overflow: hidden; }
.editeur header, .editeur footer { display: flex; align-items: center; gap: 10px; padding: 12px 16px; }
.editeur header { justify-content: space-between; border-bottom: 1px solid var(--border); }
.editeur header h3 { display: flex; align-items: center; gap: 8px; margin: 0; }
.editeur footer { border-top: 1px solid var(--border); }
.grow { flex: 1; }
.corps { display: grid; grid-template-columns: 1fr 270px; min-height: 0; flex: 1; }
.scene { height: min(560px, calc(100vh - 190px)); display: grid; place-items: center; padding: 4px; background: repeating-conic-gradient(var(--surface-2) 0 25%, var(--surface) 0 50%) 0 0 / 22px 22px; overflow: hidden; }
.toile { position: relative; touch-action: none; user-select: none; overflow: hidden; }
.toile canvas { display: block; }
.cadre { position: absolute; box-shadow: 0 0 0 9999px rgba(0, 0, 0, .55); outline: 2px solid #fff; cursor: move; }
.tiers { position: absolute; background: rgba(255, 255, 255, .45); pointer-events: none; }
.tiers.v1, .tiers.v2 { top: 0; bottom: 0; width: 1px; } .tiers.v1 { left: 33.33%; } .tiers.v2 { left: 66.66%; }
.tiers.h1, .tiers.h2 { left: 0; right: 0; height: 1px; } .tiers.h1 { top: 33.33%; } .tiers.h2 { top: 66.66%; }
.poignee { position: absolute; width: 22px; height: 22px; border: 4px solid #fff; }
.poignee.no { left: -4px; top: -4px; border-right: 0; border-bottom: 0; cursor: nwse-resize; }
.poignee.ne { right: -4px; top: -4px; border-left: 0; border-bottom: 0; cursor: nesw-resize; }
.poignee.so { left: -4px; bottom: -4px; border-right: 0; border-top: 0; cursor: nesw-resize; }
.poignee.se { right: -4px; bottom: -4px; border-left: 0; border-top: 0; cursor: nwse-resize; }
.outils { display: flex; flex-direction: column; gap: 18px; padding: 16px; border-left: 1px solid var(--border); overflow-y: auto; }
.outils h4 { display: flex; align-items: center; gap: 6px; margin: 0 0 8px; font-size: 14px; }
.choix { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 6px; }
.curseur { display: flex; flex-direction: column; gap: 4px; margin-bottom: 10px; font-size: 13.5px; }
.curseur span { display: flex; justify-content: space-between; }
.curseur input { width: 100%; accent-color: var(--primary); }
@media (max-width: 760px) {
  .corps { grid-template-columns: 1fr; overflow-y: auto; }
  .scene { height: 52vh; }
  .outils { border-left: 0; border-top: 1px solid var(--border); }
}
</style>
