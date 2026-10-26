<script setup>
// Catalogue numérique (gérant) : QR code de la vitrine, affiche A4 et étiquettes produits à imprimer, réglages de ce que voit le client.
import { ref, reactive, computed, onMounted, watch } from 'vue'
import QRCode from 'qrcode'
import { api, ApiError } from '../api'
import { money } from '../format'
import { logoUrl, nomApp } from '../theme'
import { toast } from '../toast'
import { urlCatalogue, urlProduit } from '../catalogue'
import Icon from '../components/Icon.vue'
import PhoneInput from '../components/PhoneInput.vue'

const cfg = ref(null)
const f = reactive({ actif: true, prix: true, dispo: true, ruptures: true, avis: true, whatsapp: '', message: '' })
const erreurs = ref({})
const busy = ref('')
const qr = ref('')
const url = urlCatalogue()
const hote = location.hostname
const local = /^(localhost|127\.|192\.168\.|10\.)/.test(hote)
const categories = ref([])
const catEtiquettes = ref('')

async function charger() {
  try {
    cfg.value = await api.get('catalogue-config')
    Object.assign(f, cfg.value.reglages)
  } catch (e) { toast(e.message, 'error') }
}
onMounted(async () => {
  charger()
  qr.value = await QRCode.toDataURL(url, { width: 640, margin: 1, errorCorrectionLevel: 'M' })
})

async function enregistrer() {
  busy.value = 'cfg'; erreurs.value = {}
  try { cfg.value = await api.post('catalogue-config', { ...f }); Object.assign(f, cfg.value.reglages); toast('Réglages du catalogue enregistrés') } catch (e) {
    if (e instanceof ApiError && Object.keys(e.errors).length) erreurs.value = e.errors; else toast(e.message, 'error')
  } finally { busy.value = '' }
}
const modifie = computed(() => cfg.value && Object.keys(f).some((k) => String(f[k]) !== String(cfg.value.reglages[k])))

async function copier() { try { await navigator.clipboard.writeText(url); toast('Lien du catalogue copié') } catch { toast(url) } }
function telecharger() { const a = document.createElement('a'); a.href = qr.value; a.download = 'qr-catalogue.png'; a.click() }

/* ---- Impressions : nouvelle fenêtre ouverte tout de suite (sinon bloquée par le navigateur), remplie une fois les QR codes prêts ---- */
const couleur = () => getComputedStyle(document.documentElement).getPropertyValue('--primary').trim() || '#7048e8'
const echap = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]))
function imprimerDans(w, titre, css, corps) {
  w.document.open()
  w.document.write(`<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>${echap(titre)}</title><style>
    *{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#1a1a2e;-webkit-print-color-adjust:exact;print-color-adjust:exact}${css}</style></head>
    <body>${corps}<script>Promise.all([...document.images].map(i=>i.complete?0:new Promise(r=>{i.onload=i.onerror=r}))).then(()=>setTimeout(()=>print(),150))<\/script></body></html>`)
  w.document.close()
}

async function affiche() {
  const w = window.open('', '_blank')
  if (!w) return toast("Autorisez l'ouverture de fenêtres pour imprimer", 'error')
  const nom = nomApp.value, c = couleur()
  const grand = await QRCode.toDataURL(url, { width: 1200, margin: 1, errorCorrectionLevel: 'H' })
  imprimerDans(w, 'Affiche catalogue', `
    @page{size:A4;margin:0}.page{width:210mm;height:297mm;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:9mm;padding:16mm;text-align:center;
      background:linear-gradient(160deg,${c} 0 34%,#fff 34%)}
    .logo{width:34mm;height:34mm;object-fit:contain;background:#fff;border-radius:8mm;padding:3mm;box-shadow:0 2mm 6mm rgba(0,0,0,.15)}
    h1{margin:0;font-size:34pt;color:#fff;letter-spacing:-.5pt}h2{margin:0;font-size:26pt;color:${c}}
    .qr{width:120mm;height:120mm;padding:5mm;background:#fff;border:2.5mm solid ${c};border-radius:8mm}
    .msg{font-size:15pt;color:#555;max-width:160mm;margin:0}.etapes{display:flex;gap:6mm;font-size:12.5pt;font-weight:700}
    .etapes span{display:flex;align-items:center;gap:2.5mm}.etapes i{width:9mm;height:9mm;border-radius:50%;background:${c};color:#fff;display:grid;place-items:center;font-style:normal}
    .url{font-size:10pt;color:#888;word-break:break-all}`,
  `<div class="page">${logoUrl.value ? `<img class="logo" src="${new URL(logoUrl.value, location.href).href}">` : ''}
    <h1>${echap(nom)}</h1><h2>Découvrez notre catalogue</h2>
    ${f.message ? `<p class="msg">${echap(f.message)}</p>` : '<p class="msg">Tous nos produits, leurs prix et les avis de nos clients, sur votre téléphone.</p>'}
    <img class="qr" src="${grand}">
    <div class="etapes"><span><i>1</i>Ouvrez l'appareil photo</span><span><i>2</i>Visez le QR code</span><span><i>3</i>${f.whatsapp ? 'Commandez sur WhatsApp' : 'Choisissez vos produits'}</span></div>
    <div class="url">${echap(url)}</div></div>`)
}

async function chargerCategories() {
  if (categories.value.length) return
  try { const r = await api.get('catalogue-apercu'); categories.value = r.categories; apercu.value = r } catch (e) { toast(e.message, 'error') }
}
const apercu = ref(null)
watch(cfg, () => { if (cfg.value) chargerCategories() })
const aImprimer = computed(() => (apercu.value ? apercu.value.produits.filter((p) => !catEtiquettes.value || String(p.idcat) === String(catEtiquettes.value)) : []))

async function etiquettes() {
  if (!aImprimer.value.length) return toast('Aucun produit visible dans cette catégorie', 'error')
  const w = window.open('', '_blank')
  if (!w) return toast("Autorisez l'ouverture de fenêtres pour imprimer", 'error')
  const c = couleur()
  const codes = await Promise.all(aImprimer.value.map((p) => QRCode.toDataURL(urlProduit(p.id), { width: 300, margin: 0, errorCorrectionLevel: 'M' })))
  imprimerDans(w, 'Étiquettes QR', `
    @page{size:A4;margin:8mm}.grille{display:grid;grid-template-columns:repeat(3,1fr);gap:3mm}
    .et{display:flex;gap:3mm;align-items:center;height:34mm;padding:3mm;border:.4mm dashed #bbb;border-radius:3mm;break-inside:avoid;overflow:hidden}
    .et img{width:27mm;height:27mm;flex:none}.t{display:flex;flex-direction:column;gap:1mm;min-width:0}
    .n{font-weight:800;font-size:10.5pt;line-height:1.2;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
    .p{font-weight:900;font-size:13pt;color:${c}}.s{font-size:7pt;color:#888}`,
  `<div class="grille">${aImprimer.value.map((p, n) => `<div class="et"><img src="${codes[n]}"><div class="t"><span class="n">${echap(p.nom)}</span>${f.prix && p.prix != null ? `<span class="p">${echap(money(p.prix))}</span>` : ''}<span class="s">Scannez : photos, détails, avis</span></div></div>`).join('')}</div>`)
}
</script>

<template>
  <main class="page">
    <div class="page-head"><div><h1>Catalogue numérique</h1><p>Vos clients scannent le QR code et voient vos produits, vos prix et vos avis sur leur téléphone, sans application ni compte.</p></div></div>

    <div v-if="local" class="bandeau"><Icon name="alert" :size="18" /> Vous êtes sur une adresse locale ({{ hote }}) : imprimez le QR code depuis le site en ligne, sinon les clients ne pourront pas l'ouvrir.</div>

    <div class="grille">
      <section class="card card-pad qr-carte">
        <div class="card-head"><h2>QR code du catalogue</h2><span class="badge" :class="f.actif ? 'success' : 'danger'">{{ cfg?.reglages.actif ? 'En ligne' : 'Désactivé' }}</span></div>
        <div class="qr-zone">
          <img v-if="qr" :src="qr" alt="QR code du catalogue" class="qr" />
          <div v-else class="skeleton qr" />
        </div>
        <code class="url">{{ url }}</code>
        <div class="actions">
          <button class="btn primary" @click="affiche"><Icon name="printer" :size="16" /> Imprimer l'affiche A4</button>
          <button class="btn" :disabled="!qr" @click="telecharger"><Icon name="download" :size="16" /> Télécharger (PNG)</button>
          <button class="btn" @click="copier"><Icon name="link" :size="16" /> Copier le lien</button>
          <a class="btn" :href="url" target="_blank" rel="noopener"><Icon name="external" :size="16" /> Ouvrir le catalogue</a>
        </div>
        <p class="muted aide">Affichez-le en caisse, en vitrine, sur vos sacs, vos cartes de visite ou partagez le lien sur Facebook, TikTok et WhatsApp.</p>
      </section>

      <section class="card card-pad">
        <div class="card-head"><h2>Ce que voient vos clients</h2><small v-if="cfg" class="muted">{{ cfg.produits_visibles }} produit{{ cfg.produits_visibles > 1 ? 's' : '' }} visible{{ cfg.produits_visibles > 1 ? 's' : '' }} sur {{ cfg.produits_total }}</small></div>
        <form class="reglages" @submit.prevent="enregistrer">
          <label class="toggle"><input v-model="f.actif" type="checkbox" /><span class="sw" /> <span><b>Catalogue en ligne</b><small>Désactivé, le QR code affiche « catalogue indisponible ».</small></span></label>
          <label class="toggle"><input v-model="f.prix" type="checkbox" /><span class="sw" /> <span><b>Afficher les prix</b></span></label>
          <label class="toggle"><input v-model="f.dispo" type="checkbox" /><span class="sw" /> <span><b>Afficher la disponibilité</b><small>« Disponible », « Derniers articles », « Épuisé » — jamais la quantité exacte.</small></span></label>
          <label class="toggle"><input v-model="f.ruptures" type="checkbox" /><span class="sw" /> <span><b>Montrer les produits épuisés</b></span></label>
          <label class="toggle"><input v-model="f.avis" type="checkbox" /><span class="sw" /> <span><b>Afficher les avis clients</b><small>Prénom et initiale seulement ; ni téléphone ni capture d'écran.</small></span></label>
          <div class="field" :class="{ invalid: erreurs.whatsapp }">
            <label for="c-wa">Numéro WhatsApp pour les commandes</label>
            <PhoneInput id="c-wa" v-model="f.whatsapp" :invalid="!!erreurs.whatsapp" />
            <span v-if="erreurs.whatsapp" class="error">{{ erreurs.whatsapp }}</span>
            <span v-else class="hint">Le client compose sa sélection et vous l'envoie, message déjà rédigé. Laissé vide : bouton « Appeler ».</span>
          </div>
          <div class="field"><label for="c-msg">Message d'accueil</label><textarea id="c-msg" v-model="f.message" class="input" rows="2" maxlength="300" placeholder="Ex. Livraison partout à Abidjan · Ouvert 7j/7 de 8h à 21h" /></div>
          <div class="pied"><small class="muted">Pour retirer un produit du catalogue : fiche produit → Modifier → « Visible dans le catalogue ».</small><button class="btn primary" :disabled="busy === 'cfg' || !modifie">Enregistrer</button></div>
        </form>
      </section>

      <section class="card card-pad etiquettes">
        <div class="card-head"><h2>Étiquettes QR des produits</h2></div>
        <p class="muted">À coller en rayon ou sur l'emballage : le client scanne et ouvre directement la fiche du produit (photos, détails, avis). Planche A4 de 3 étiquettes par ligne.</p>
        <div class="ligne">
          <select v-model="catEtiquettes" class="input" aria-label="Catégorie à imprimer">
            <option value="">Tous les produits visibles</option>
            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.nom }}</option>
          </select>
          <button class="btn primary" :disabled="!aImprimer.length" @click="etiquettes"><Icon name="printer" :size="16" /> Imprimer {{ aImprimer.length }} étiquette{{ aImprimer.length > 1 ? 's' : '' }}</button>
        </div>
      </section>
    </div>
  </main>
</template>

<style scoped>
.bandeau { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; padding: 11px 15px; border-radius: 14px; background: var(--warning-soft); color: var(--warning); font-weight: 700; font-size: 14px; }
.grille { display: grid; grid-template-columns: minmax(0, 420px) minmax(0, 1fr); gap: 18px; align-items: start; }
.etiquettes { grid-column: 1 / -1; }
@media (max-width: 900px) { .grille { grid-template-columns: 1fr; } }
.qr-carte { display: flex; flex-direction: column; gap: 14px; }
.qr-zone { display: grid; place-items: center; padding: 18px; border-radius: 22px; background: linear-gradient(135deg, var(--primary-soft), var(--surface-2)); }
.qr { width: min(280px, 100%); aspect-ratio: 1; border-radius: 16px; background: #fff; padding: 10px; box-shadow: var(--shadow); }
.url { display: block; padding: 8px 12px; border-radius: 10px; background: var(--surface-2); font-size: 12.5px; word-break: break-all; }
.actions { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.actions .btn { justify-content: center; }
.actions .btn:first-child { grid-column: 1 / -1; }
.aide { margin: 0; font-size: 13px; }
.reglages { display: flex; flex-direction: column; gap: 14px; }
.reglages .toggle { align-items: flex-start; }
.reglages .toggle > span:last-child { display: flex; flex-direction: column; }
.reglages .toggle small { color: var(--muted); font-weight: 400; font-size: 12.5px; }
.pied { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.etiquettes p { margin: 0 0 12px; }
.ligne { display: flex; gap: 10px; flex-wrap: wrap; }
.ligne .input { flex: 1 1 220px; width: auto; }
</style>
