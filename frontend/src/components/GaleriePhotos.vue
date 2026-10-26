<script setup>
// Galerie d'un produit : grande photo + vignettes, zoom plein écran ; pour le magasinier et le gérant, ajout de plusieurs photos
// (glisser, parcourir ou coller), retouche, légende, ordre, photo principale (celle de la caisse) et suppression.
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { api, fileUrl } from '../api'
import { toast } from '../toast'
import { compresser } from '../images'
import { recevoirCollage, raccourciColler } from '../clipboard'
import Icon from './Icon.vue'
import Lightbox from './Lightbox.vue'
import ImageEditor from './ImageEditor.vue'

const props = defineProps({
  idprod: { type: [String, Number], required: true },
  nom: { type: String, default: '' },
  photos: { type: Array, default: () => [] },
  image: { type: String, default: '' },        // photo principale
  max: { type: Number, default: 12 },
  modifiable: Boolean,
  icone: { type: String, default: 'box' },
})
const emit = defineEmits(['update'])

const choisie = ref(0)
const zoom = ref(null)        // index ouvert dans la visionneuse
const retouche = ref(null)    // photo en cours de retouche
const busy = ref(false)
const input = ref(null)
const zone = ref(null)
const over = ref(false)
const legende = ref({ id: null, texte: '' })

const liste = computed(() => props.photos.map((p) => ({ ...p, src: fileUrl('produits', p.fichier), principale: p.fichier === props.image })))
const courante = computed(() => liste.value[Math.min(choisie.value, liste.value.length - 1)])
const reste = computed(() => props.max - props.photos.length)
watch(() => props.photos.length, () => { if (choisie.value >= props.photos.length) choisie.value = 0 })

async function envoyer(fichiers) {
  const images = [...fichiers].filter((f) => /^image\/(jpeg|png|webp)$/.test(f.type))
  if (!images.length) return toast('Formats acceptés : JPG, PNG, WEBP', 'error')
  if (images.length > reste.value) return toast(`Encore ${reste.value} photo(s) possible(s) dans la galerie (${props.max} maximum)`, 'error')
  busy.value = true
  try {
    const fd = new FormData()
    for (const f of images) fd.append('photos[]', await compresser(f))   // photos de téléphone réduites avant l'envoi
    const r = await api.post('produit-photos', fd, { id: props.idprod })
    emit('update', r); choisie.value = r.photos.length - 1
    toast(images.length > 1 ? `${images.length} photos ajoutées` : 'Photo ajoutée')
  } catch (e) { toast(e.errors?.photos || e.errors?.produits || e.message, 'error') } finally { busy.value = false; if (input.value) input.value.value = '' }
}
async function agir(p, action, extra = {}) {
  busy.value = true
  try {
    const corps = extra instanceof FormData ? extra : { idphoto: p.idphoto, action, ...extra }
    if (extra instanceof FormData) { corps.append('idphoto', p.idphoto); corps.append('action', action) }
    const r = await api.post('produit-photo', corps)
    emit('update', r)
    return r
  } catch (e) { toast(e.message, 'error') } finally { busy.value = false }
}
async function principale(p) { if (await agir(p, 'principale')) { choisie.value = 0; toast('Photo principale changée : elle s\'affiche en caisse') } }
async function deplacer(p, sens) { await agir(p, 'deplacer', { sens }); choisie.value = Math.max(0, Math.min(props.photos.length - 1, choisie.value + sens)) }
async function supprimer(p) { if (confirm('Retirer cette photo de la galerie ?') && (await agir(p, 'supprimer'))) toast('Photo retirée') }
async function retouchee(fichier) {
  const p = retouche.value; retouche.value = null
  const fd = new FormData(); fd.append('photo', fichier)
  if (await agir(p, 'remplacer', fd)) toast('Photo retouchée enregistrée')
}
async function enregistrerLegende(p) { await agir(p, 'legende', { legende: legende.value.texte }); legende.value = { id: null, texte: '' } }

const collage = props.modifiable ? recevoirCollage(() => zone.value, (f) => envoyer([f])) : null
onBeforeUnmount(() => collage?.retirer())
function deposer(e) { over.value = false; if (props.modifiable && e.dataTransfer?.files?.length) envoyer(e.dataTransfer.files) }
</script>

<template>
  <div ref="zone" class="galerie" :class="{ over }" @pointerenter="collage?.activer()" @dragover.prevent="modifiable && (over = true)" @dragleave.prevent="over = false" @drop.prevent="deposer">
    <div class="principale-zone">
      <button v-if="courante" type="button" class="grande" :aria-label="`Agrandir la photo ${choisie + 1}`" @click="zoom = choisie">
        <img :src="courante.src" :alt="courante.legende || nom" />
        <span class="loupe"><Icon name="zoom-in" :size="18" /> Zoomer</span>
        <span v-if="courante.legende" class="legende">{{ courante.legende }}</span>
      </button>
      <div v-else class="grande vide">
        <Icon :name="icone" :size="46" />
        <span v-if="modifiable">Glissez des photos ici, collez-les ({{ raccourciColler }}) ou</span>
        <span v-else>Aucune photo</span>
        <button v-if="modifiable" type="button" class="btn primary sm" :disabled="busy" @click="input.click()"><Icon name="upload" :size="15" /> Ajouter des photos</button>
      </div>
    </div>

    <div v-if="liste.length" class="vignettes">
      <button v-for="(p, n) in liste" :key="p.idphoto" type="button" class="vignette" :class="{ on: n === choisie }" :aria-label="`Photo ${n + 1}`" @click="choisie = n" @dblclick="zoom = n">
        <img :src="p.src" alt="" loading="lazy" />
        <span v-if="p.principale" class="etoile" title="Photo principale (caisse)"><Icon name="star" :size="12" /></span>
      </button>
      <button v-if="modifiable && reste > 0" type="button" class="vignette ajout" :disabled="busy" aria-label="Ajouter des photos" title="Ajouter des photos" @click="input.click()"><Icon name="plus" :size="22" /></button>
    </div>

    <div v-if="modifiable && courante" class="actions">
      <button type="button" class="btn sm" :disabled="busy || courante.principale" :title="courante.principale ? 'Déjà la photo principale' : 'Photo affichée en caisse et dans les listes'" @click="principale(courante)"><Icon name="star" :size="15" /> {{ courante.principale ? 'Principale' : 'Définir principale' }}</button>
      <button type="button" class="btn sm" :disabled="busy" @click="retouche = courante"><Icon name="sliders" :size="15" /> Retoucher</button>
      <button type="button" class="btn sm" :disabled="busy" @click="legende = { id: courante.idphoto, texte: courante.legende }"><Icon name="edit" :size="15" /> Légende</button>
      <button type="button" class="btn sm icon" :disabled="busy || choisie === 0" aria-label="Déplacer vers la gauche" title="Déplacer vers la gauche" @click="deplacer(courante, -1)"><Icon name="chevL" :size="15" /></button>
      <button type="button" class="btn sm icon" :disabled="busy || choisie === liste.length - 1" aria-label="Déplacer vers la droite" title="Déplacer vers la droite" @click="deplacer(courante, 1)"><Icon name="chevR" :size="15" /></button>
      <button type="button" class="btn sm icon ghost danger-txt" :disabled="busy" aria-label="Retirer la photo" title="Retirer la photo" @click="supprimer(courante)"><Icon name="trash" :size="15" /></button>
    </div>
    <form v-if="legende.id" class="legende-form" @submit.prevent="enregistrerLegende(courante)">
      <input v-model="legende.texte" class="input" maxlength="150" placeholder="Ex. Vue de face, emballage, étiquette…" autofocus />
      <button class="btn sm primary" :disabled="busy">Enregistrer</button><button type="button" class="btn sm" @click="legende = { id: null, texte: '' }">Annuler</button>
    </form>
    <p v-if="modifiable && liste.length" class="muted aide">{{ liste.length }} / {{ max }} photos · glissez, parcourez ou collez ({{ raccourciColler }}) pour en ajouter</p>

    <input ref="input" type="file" class="sr-only" accept=".jpg,.jpeg,.png,.webp" multiple @change="envoyer($event.target.files)" />
    <Lightbox v-if="zoom !== null" :photos="liste" :index="zoom" @close="zoom = null" />
    <ImageEditor v-if="retouche" :source="retouche.src" :nom="retouche.fichier" @close="retouche = null" @done="retouchee" />
  </div>
</template>

<style scoped>
.galerie { display: flex; flex-direction: column; gap: 10px; width: 100%; border-radius: 20px; transition: box-shadow .2s; }
.galerie.over { box-shadow: 0 0 0 3px var(--primary); }
.grande { position: relative; width: 100%; aspect-ratio: 1; border-radius: 18px; overflow: hidden; border: 1px solid var(--border); background: var(--surface-2); padding: 0; cursor: zoom-in; display: block; }
.grande img { width: 100%; height: 100%; object-fit: contain; background: #fff; transition: transform .35s var(--ease); }
.grande:hover img { transform: scale(1.04); }
.loupe { position: absolute; right: 10px; bottom: 10px; display: inline-flex; align-items: center; gap: 6px; padding: 6px 11px; border-radius: 999px; background: rgba(0, 0, 0, .6); color: #fff; font-size: 13px; font-weight: 700; opacity: 0; transition: opacity .2s; }
.grande:hover .loupe, .grande:focus-visible .loupe { opacity: 1; }
.legende { position: absolute; left: 10px; top: 10px; max-width: calc(100% - 20px); padding: 4px 10px; border-radius: 999px; background: rgba(0, 0, 0, .55); color: #fff; font-size: 12.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.grande.vide { cursor: default; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; color: var(--muted); text-align: center; padding: 16px; border-style: dashed; }
.vignettes { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 2px; }
.vignette { position: relative; flex: none; width: 64px; height: 64px; padding: 0; border-radius: 12px; overflow: hidden; border: 2px solid var(--border); background: #fff; cursor: pointer; }
.vignette.on { border-color: var(--primary); }
.vignette img { width: 100%; height: 100%; object-fit: cover; }
.vignette.ajout { display: grid; place-items: center; border-style: dashed; color: var(--primary); background: var(--primary-soft); }
.etoile { position: absolute; top: 3px; right: 3px; width: 20px; height: 20px; border-radius: 50%; display: grid; place-items: center; background: var(--warning); color: #fff; }
.actions { display: flex; flex-wrap: wrap; gap: 6px; }
.danger-txt { color: var(--danger); }
.legende-form { display: flex; gap: 6px; flex-wrap: wrap; }
.legende-form .input { flex: 1 1 200px; }
.aide { margin: 0; font-size: 12.5px; }
</style>
