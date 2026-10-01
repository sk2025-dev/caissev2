<script setup>
// Carte virtuelle du personnel (format carte bancaire 85,6 × 54 mm) : recto avec photo, verso avec l'entreprise.
// Aux couleurs du thème ; imprimable à taille réelle et téléchargeable en PNG.
import { ref, computed, watch } from 'vue'
import { api, auth, fileUrl } from '../api'
import { date } from '../format'
import { logoUrl } from '../theme'
import { toast } from '../toast'
import Icon from '../components/Icon.vue'

const props = defineProps({ id: { type: String, default: '' } })
const c = ref(null)
const error = ref('')
const busy = ref(false)
const input = ref(null)

async function load() {
  error.value = ''
  try { c.value = await api.get('carte', props.id ? { id: props.id } : {}) } catch (e) { error.value = e.message }
}
watch(() => props.id, load, { immediate: true })

const photoUrl = computed(() => (c.value?.photo ? fileUrl('utilisateurs', c.value.photo) : ''))
const nomComplet = computed(() => (c.value ? [c.value.prenom, c.value.nom].filter(Boolean).join(' ') : ''))
const initiales = computed(() => (c.value ? [c.value.prenom, c.value.nom].filter(Boolean).map((p) => p[0]).join('').slice(0, 2).toUpperCase() : ''))
const peutModifier = computed(() => c.value && (c.value.soi || auth.user?.admin))

/* ---- Photo ---- */
async function envoyer(file) {
  if (!file) return
  if (!/\.(jpe?g|png|webp)$/i.test(file.name)) return toast('Formats acceptés : JPG, PNG, WEBP', 'error')
  if (file.size > 5 * 1024 * 1024) return toast('Photo trop lourde (5 Mo maximum)', 'error')
  busy.value = true
  try {
    const fd = new FormData(); fd.append('photo', file)
    c.value = await api.post('carte-photo', fd, { id: c.value.id })
    if (c.value.soi) auth.user.photo = c.value.photo
    toast('Photo mise à jour')
  } catch (e) { toast(e.errors?.photo || e.message, 'error') } finally { busy.value = false; if (input.value) input.value.value = '' }
}
async function retirer() {
  busy.value = true
  try {
    c.value = await api.post('carte-photo', { retirer: 1 }, { id: c.value.id })
    if (c.value.soi) auth.user.photo = ''
    toast('Photo retirée')
  } catch (e) { toast(e.message, 'error') } finally { busy.value = false }
}

/* ---- Téléchargement PNG : la carte est redessinée sur un canevas (recto puis verso), en haute définition ---- */
const charger = (src) => new Promise((ok) => { if (!src) return ok(null); const i = new Image(); i.onload = () => ok(i); i.onerror = () => ok(null); i.src = src })
function arrondi(g, x, y, w, h, r) { g.beginPath(); g.moveTo(x + r, y); g.arcTo(x + w, y, x + w, y + h, r); g.arcTo(x + w, y + h, x, y + h, r); g.arcTo(x, y + h, x, y, r); g.arcTo(x, y, x + w, y, r); g.closePath() }
function couvrir(g, img, x, y, w, h) {   // image recadrée pour remplir la zone (object-fit: cover)
  const r = Math.max(w / img.width, h / img.height); const sw = w / r; const sh = h / r
  g.drawImage(img, (img.width - sw) / 2, (img.height - sh) / 2, sw, sh, x, y, w, h)
}
function contenir(g, img, x, y, w, h) {
  const r = Math.min(w / img.width, h / img.height); const dw = img.width * r; const dh = img.height * r
  g.drawImage(img, x + (w - dw) / 2, y + (h - dh) / 2, dw, dh)
}
function texte(g, t, x, y, taille, poids, couleur, maxW, align = 'left') {
  g.font = `${poids} ${taille}px Inter, system-ui, -apple-system, Segoe UI, sans-serif`; g.fillStyle = couleur; g.textAlign = align
  let s = String(t || ''); while (maxW && s.length > 1 && g.measureText(s).width > maxW) s = s.slice(0, -2) + '…'
  g.fillText(s, x, y)
}
async function telecharger() {
  const css = getComputedStyle(document.documentElement)
  const p1 = css.getPropertyValue('--primary').trim() || '#7048e8'; const p2 = css.getPropertyValue('--primary-2').trim() || p1
  const [photo, logo] = await Promise.all([charger(photoUrl.value), charger(logoUrl.value)])
  const W = 1012; const H = 638; const gap = 40   // 85,6 × 54 mm à ~300 dpi
  const cv = document.createElement('canvas'); cv.width = W; cv.height = H * 2 + gap
  const g = cv.getContext('2d')
  const fond = (y) => {
    g.save(); arrondi(g, 0, y, W, H, 44); g.clip()
    const gr = g.createLinearGradient(0, y, W, y + H); gr.addColorStop(0, p1); gr.addColorStop(1, p2); g.fillStyle = gr; g.fillRect(0, y, W, H)
    g.globalAlpha = 0.12; g.fillStyle = '#fff'; g.beginPath(); g.arc(W - 90, y + 80, 260, 0, Math.PI * 2); g.fill(); g.beginPath(); g.arc(80, y + H + 40, 220, 0, Math.PI * 2); g.fill(); g.globalAlpha = 1
  }
  // Recto
  fond(0)
  if (logo) { g.fillStyle = '#fff'; arrondi(g, 48, 44, 92, 92, 22); g.fill(); contenir(g, logo, 58, 54, 72, 72) }
  texte(g, c.value.entreprise.nom || 'Entreprise', logo ? 160 : 48, 86, 34, 800, '#fff', 560)
  texte(g, 'CARTE DU PERSONNEL', logo ? 160 : 48, 124, 20, 700, 'rgba(255,255,255,.8)', 560)
  g.fillStyle = '#fff'; arrondi(g, 48, 190, 300, 380, 30); g.fill()
  g.save(); arrondi(g, 60, 202, 276, 356, 22); g.clip()
  if (photo) couvrir(g, photo, 60, 202, 276, 356)
  else { g.fillStyle = p1; g.globalAlpha = 0.15; g.fillRect(60, 202, 276, 356); g.globalAlpha = 1; texte(g, initiales.value, 198, 410, 110, 800, p1, 0, 'center') }
  g.restore()
  texte(g, nomComplet.value, 390, 250, 46, 800, '#fff', 580)
  g.fillStyle = 'rgba(255,255,255,.22)'; arrondi(g, 390, 278, Math.min(580, 40 + c.value.role_libelle.length * 15), 52, 26); g.fill()
  texte(g, c.value.role_libelle, 410, 313, 24, 800, '#fff', 540)
  texte(g, 'MATRICULE', 390, 392, 18, 700, 'rgba(255,255,255,.75)'); texte(g, c.value.matricule, 390, 430, 34, 800, '#fff')
  if (c.value.depuis) { texte(g, 'DEPUIS LE', 700, 392, 18, 700, 'rgba(255,255,255,.75)'); texte(g, date(c.value.depuis), 700, 430, 30, 800, '#fff') }
  texte(g, c.value.telephone || '', 390, 508, 24, 600, '#fff', 580); texte(g, c.value.email || '', 390, 546, 24, 600, '#fff', 580)
  if (!c.value.actif) { g.save(); g.translate(W / 2, H / 2); g.rotate(-0.25); texte(g, 'COMPTE DÉSACTIVÉ', 0, 0, 72, 900, 'rgba(255,255,255,.55)', 0, 'center'); g.restore() }
  g.restore()
  // Verso
  const y2 = H + gap
  fond(y2)
  g.fillStyle = 'rgba(0,0,0,.18)'; g.fillRect(0, y2 + 70, W, 90)
  if (logo) { g.fillStyle = '#fff'; arrondi(g, W / 2 - 60, y2 + 200, 120, 120, 28); g.fill(); contenir(g, logo, W / 2 - 46, y2 + 214, 92, 92) }
  texte(g, [c.value.entreprise.nom, c.value.entreprise.forme].filter(Boolean).join(' '), W / 2, y2 + (logo ? 380 : 300), 38, 800, '#fff', 880, 'center')
  texte(g, [c.value.entreprise.ville, c.value.entreprise.telephone, c.value.entreprise.site].filter(Boolean).join(' · '), W / 2, y2 + (logo ? 424 : 344), 24, 600, 'rgba(255,255,255,.85)', 880, 'center')
  texte(g, 'Carte personnelle et non cessible, à présenter sur demande.', W / 2, y2 + 540, 21, 600, 'rgba(255,255,255,.8)', 880, 'center')
  texte(g, 'En cas de perte, merci de la retourner à l\'entreprise.', W / 2, y2 + 574, 21, 600, 'rgba(255,255,255,.8)', 880, 'center')
  g.restore()
  const a = document.createElement('a')
  a.download = `carte-${c.value.matricule}-${nomComplet.value.normalize('NFD').replace(/[^\w]+/g, '-').toLowerCase()}.png`
  a.href = cv.toDataURL('image/png'); a.click()
}
const imprimer = () => window.print()
</script>

<template>
  <main class="page">
    <div class="page-head">
      <div><h1>Carte virtuelle</h1><p>{{ c && !c.soi ? `Carte de ${nomComplet}` : 'Votre carte du personnel' }} — aux couleurs de l'entreprise, imprimable au format carte bancaire.</p></div>
      <div v-if="c" class="head-actions">
        <RouterLink v-if="!c.soi" to="/utilisateurs" class="btn"><Icon name="back" :size="16" /> Utilisateurs</RouterLink>
        <button class="btn" @click="imprimer"><Icon name="printer" :size="16" /> Imprimer</button>
        <button class="btn primary" @click="telecharger"><Icon name="download" :size="16" /> Télécharger (PNG)</button>
      </div>
    </div>

    <div v-if="error" class="alert">{{ error }}</div>
    <div v-else-if="!c" class="skeleton" style="height:300px;max-width:520px" />

    <template v-else>
      <div class="cartes print-area">
        <!-- Recto -->
        <article class="carte recto" :class="{ off: !c.actif }" aria-label="Recto de la carte">
          <header>
            <span v-if="logoUrl" class="logo"><img :src="logoUrl" alt="" /></span>
            <div><b>{{ c.entreprise.nom || 'Entreprise' }}</b><small>Carte du personnel</small></div>
          </header>
          <div class="corps">
            <div class="photo"><img v-if="photoUrl" :src="photoUrl" :alt="`Photo de ${nomComplet}`" /><span v-else>{{ initiales }}</span></div>
            <div class="infos">
              <h2>{{ nomComplet }}</h2>
              <span class="role">{{ c.role_libelle }}</span>
              <dl><div><dt>Matricule</dt><dd>{{ c.matricule }}</dd></div><div v-if="c.depuis"><dt>Depuis le</dt><dd>{{ date(c.depuis) }}</dd></div></dl>
              <p v-if="c.telephone">{{ c.telephone }}</p><p v-if="c.email">{{ c.email }}</p>
            </div>
          </div>
          <span v-if="!c.actif" class="tampon">Compte désactivé</span>
        </article>
        <!-- Verso -->
        <article class="carte verso" aria-label="Verso de la carte">
          <i class="bande" />
          <span v-if="logoUrl" class="logo grand"><img :src="logoUrl" alt="" /></span>
          <b>{{ [c.entreprise.nom, c.entreprise.forme].filter(Boolean).join(' ') }}</b>
          <small>{{ [c.entreprise.ville, c.entreprise.telephone, c.entreprise.site].filter(Boolean).join(' · ') }}</small>
          <p>Carte personnelle et non cessible, à présenter sur demande.<br />En cas de perte, merci de la retourner à l'entreprise.</p>
        </article>
      </div>

      <section v-if="peutModifier" class="card card-pad photo-zone">
        <div><h2>Photo</h2><p class="muted">Portrait de face, cadré sur le visage — JPG, PNG ou WEBP, 5 Mo maximum. Elle apparaît aussi sur l'avatar de la barre du haut.</p></div>
        <div class="row-actions">
          <input ref="input" type="file" class="sr-only" accept=".jpg,.jpeg,.png,.webp" @change="envoyer($event.target.files[0])" />
          <button class="btn primary" :disabled="busy" @click="input.click()"><Icon name="upload" :size="16" /> {{ c.photo ? 'Changer la photo' : 'Ajouter une photo' }}</button>
          <button v-if="c.photo" class="btn" :disabled="busy" @click="retirer"><Icon name="trash" :size="16" /> Retirer</button>
        </div>
      </section>
    </template>
  </main>
</template>

<style scoped>
.head-actions, .row-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.cartes { display: flex; flex-wrap: wrap; gap: 24px; margin-bottom: 22px; }
.carte {
  position: relative; overflow: hidden; width: min(100%, 460px); aspect-ratio: 85.6 / 54; border-radius: 22px; color: #fff; padding: 20px 22px;
  background: linear-gradient(135deg, var(--primary), var(--primary-2)); box-shadow: 0 18px 40px color-mix(in srgb, var(--primary) 35%, transparent);
  display: flex; flex-direction: column; -webkit-print-color-adjust: exact; print-color-adjust: exact;
}
.carte::before, .carte::after { content: ''; position: absolute; border-radius: 50%; background: rgba(255, 255, 255, .12); pointer-events: none; }
.carte::before { width: 260px; height: 260px; right: -90px; top: -110px; } .carte::after { width: 220px; height: 220px; left: -80px; bottom: -150px; }
.carte header { display: flex; align-items: center; gap: 12px; position: relative; }
.carte header b { display: block; font-size: 16px; line-height: 1.2; } .carte header small { font-size: 10px; letter-spacing: .12em; text-transform: uppercase; opacity: .8; font-weight: 700; }
.logo { width: 42px; height: 42px; border-radius: 11px; background: #fff; display: grid; place-items: center; padding: 4px; flex: none; }
.logo img { max-width: 100%; max-height: 100%; object-fit: contain; }
.logo.grand { width: 60px; height: 60px; border-radius: 15px; }
.corps { flex: 1; display: flex; gap: 16px; align-items: center; position: relative; margin-top: 10px; min-height: 0; }
.photo { width: 30%; aspect-ratio: 3 / 4; border-radius: 14px; background: #fff; padding: 5px; flex: none; display: grid; place-items: center; }
.photo img { width: 100%; height: 100%; object-fit: cover; border-radius: 10px; }
.photo span { color: var(--primary); font-size: 34px; font-weight: 800; }
.infos { min-width: 0; flex: 1; }
.infos h2 { margin: 0 0 6px; font-size: 19px; line-height: 1.15; color: #fff; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.role { display: inline-block; background: rgba(255, 255, 255, .22); border-radius: 99px; padding: 3px 11px; font-size: 12px; font-weight: 800; }
dl { display: flex; gap: 18px; margin: 10px 0 6px; } dt { font-size: 9px; letter-spacing: .1em; text-transform: uppercase; opacity: .75; font-weight: 700; } dd { margin: 0; font-weight: 800; font-size: 14px; }
.infos p { margin: 1px 0 0; font-size: 11.5px; opacity: .92; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tampon { position: absolute; inset: 0; display: grid; place-items: center; font-size: 26px; font-weight: 900; letter-spacing: .06em; text-transform: uppercase; color: rgba(255, 255, 255, .6); transform: rotate(-14deg); pointer-events: none; }
.carte.off { filter: grayscale(.7); }
.verso { align-items: center; justify-content: center; text-align: center; gap: 6px; }
.verso .bande { position: absolute; left: 0; right: 0; top: 24px; height: 38px; background: rgba(0, 0, 0, .18); }
.verso b { font-size: 17px; position: relative; } .verso small { opacity: .85; font-size: 11.5px; position: relative; }
.verso p { position: relative; margin: 10px 0 0; font-size: 10.5px; opacity: .85; line-height: 1.5; }
.verso .logo { position: relative; margin-top: 30px; }
.photo-zone { display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; max-width: 944px; }
.photo-zone h2 { margin: 0 0 4px; } .photo-zone p { margin: 0; }
@media print {
  .page-head, .photo-zone { display: none !important; }
  .cartes { gap: 8mm; } .carte { width: 85.6mm; height: 54mm; border-radius: 3.2mm; box-shadow: none; padding: 3.4mm 3.8mm; break-inside: avoid; }
}
</style>
