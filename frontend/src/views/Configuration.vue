<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, watch, nextTick } from 'vue'
import { api, request, ApiError } from '../api'
import { appConfig, apercu, logoUrl, chargerConfigPublique } from '../theme'
import { exportsStore, startExport, downloadUrl } from '../exports'
import { toast } from '../toast'
import { date } from '../format'
import Icon from '../components/Icon.vue'
import FileDrop from '../components/FileDrop.vue'
import PhoneInput from '../components/PhoneInput.vue'
import SearchSelect from '../components/SearchSelect.vue'
import Ticket from '../components/Ticket.vue'

const onglets = [['entreprise', 'Entreprise', 'briefcase'], ['apparence', 'Apparence', 'palette'], ['licence', "Durée d'utilisation", 'shield'], ['sauvegarde', 'Sauvegarde', 'database'], ['caisse', 'Caisse et tickets', 'wallet'], ['alertes', 'Alertes e-mail', 'mail']]
const onglet = ref('entreprise')
const cfg = ref(null)
const erreurs = ref({})
const busy = ref('')
const f = reactive({ entreprise: {}, copyright: {}, theme: {}, documents: {}, licence: {}, session: {}, mail: {}, alertes: {}, caisse: {} })
const nouveauPass = ref('')
const logoFile = ref(null)

const apercuTicket = computed(() => ({
  numero: 'APERÇU', date_vente: new Date().toISOString().slice(0, 19).replace('T', ' '), caissier: 'Exemple', total: 2500,
  lignes: [{ idligne: 1, designation: 'Article exemple', quantite: 2, prix_unitaire: 1250, total: 2500 }],
  paiements: [{ idpaie: 1, libelle: 'Espèces', montant: 2500 }],
  ticket: {
    entreprise: { ...cfg.value?.entreprise, nom: cfg.value?.entreprise?.nom || 'Votre entreprise' },
    largeur: Number(f.caisse.ticket_largeur), entete: f.caisse.ticket_entete, pied: f.caisse.ticket_pied,
    afficher_logo: f.caisse.ticket_logo === '1', afficher_adresse: f.caisse.ticket_adresse === '1',
    afficher_contact: f.caisse.ticket_contact === '1', afficher_identifiants: f.caisse.ticket_identifiants === '1',
  },
}))

// Ne remet à zéro que les formulaires demandés : la saisie en cours dans les autres cartes est conservée
function remplir(sections = Object.keys(f)) {
  for (const k of sections) f[k] = JSON.parse(JSON.stringify(cfg.value[k]))
  if (sections.includes('mail')) f.mail.actif = cfg.value.mail.actif === '1'
}
async function charger() {
  cfg.value = await api.get('config')
  remplir()
}
onMounted(charger)
onBeforeUnmount(() => { apercu.palette = ''; apercu.mode = '' })   // l'aperçu non enregistré est abandonné en quittant la page

async function enregistrer(section, valeurs, message = 'Enregistré') {
  busy.value = section; erreurs.value = {}
  try {
    cfg.value = await api.post('config', { section, values: valeurs })   // la réponse contient la configuration complète à jour
    remplir([section]); await chargerConfigPublique()
    toast(message)
    return true
  } catch (e) {
    if (!(e instanceof ApiError)) throw e
    erreurs.value = e.errors || {}
    toast(Object.values(e.errors || {})[0] || e.message, 'error')
    nextTick(() => {   // le champ fautif peut se trouver dans une autre carte, hors de l'écran
      const champ = document.querySelector('.cfg-section .field.invalid')
      champ?.scrollIntoView({ behavior: 'smooth', block: 'center' }); champ?.querySelector('input, textarea')?.focus({ preventScroll: true })
    })
    return false
  } finally { busy.value = '' }
}

/* ---- Entreprise ---- */
const piedParDefaut = computed(() => { const e = f.entreprise; return [[e.nom, e.forme].filter(Boolean).join(' '), e.rccm && `RCCM ${e.rccm}`, e.nif && `NIF ${e.nif}`].filter(Boolean).join(' · ') || 'Mentions légales…' })
const saveEntreprise = () => enregistrer('entreprise', f.entreprise, "Informations de l'entreprise enregistrées")
async function envoyerLogo(file) {
  logoFile.value = file
  if (!file) return
  busy.value = 'logo'
  try {
    const fd = new FormData(); fd.append('logo', file)
    cfg.value = await request('POST', 'config-logo', { body: fd })
    f.entreprise.logo = cfg.value.entreprise.logo   // le reste de la saisie de l'entreprise n'est pas touché
    appConfig.logoVersion++; await chargerConfigPublique(); toast('Logo mis à jour')
  } catch (e) { toast(e.errors?.logo || e.message, 'error') } finally { busy.value = ''; logoFile.value = null }
}
async function retirerLogo() { if (await enregistrer('entreprise', { ...f.entreprise, logo: '' }, 'Logo retiré')) appConfig.logoVersion++ }

/* ---- Apparence : aperçu en direct, enregistrement explicite ---- */
watch(() => f.theme.palette, (p) => { apercu.palette = p && p !== cfg.value?.theme.palette ? p : '' })
watch(() => f.theme.mode, (m) => { apercu.mode = m && m !== cfg.value?.theme.mode ? m : '' })
const degrades = { violet: ['#7048e8', '#9775fa'], ocean: ['#1c7ed6', '#4dabf7'], emeraude: ['#0ca678', '#38d9a9'], sunset: ['#f76707', '#ff922b'], rose: ['#d6336c', '#f06595'], ardoise: ['#495a78', '#748095'] }
async function saveTheme() { if (await enregistrer('theme', f.theme, 'Thème enregistré pour tous les utilisateurs')) { apercu.palette = ''; apercu.mode = '' } }

/* ---- Licence ---- */
const lic = computed(() => cfg.value?.licence_etat)
const licProgress = computed(() => (lic.value?.defini ? Math.round(Math.min(1, Math.max(0, (Date.now() - new Date(lic.value.debut)) / (new Date(lic.value.fin) - new Date(lic.value.debut) + 86400000))) * 100) : 0))
const licTon = computed(() => ({ ok: 'success', bientot: 'warning', expiree: 'danger', avenir: 'primary', illimitee: '' }[lic.value?.etat]))
const licTexte = computed(() => ({ illimitee: 'Utilisation illimitée', avenir: `Démarre le ${date(lic.value?.debut)}`, ok: `Active — ${lic.value?.jours} jours restants`, bientot: `Expire dans ${lic.value?.jours} jour${lic.value?.jours > 1 ? 's' : ''}`, expiree: `Expirée depuis ${-lic.value?.jours} jour${-lic.value?.jours > 1 ? 's' : ''}` }[lic.value?.etat]))
const saveLicence = () => enregistrer('licence', f.licence, "Période d'utilisation enregistrée")
const saveSession = () => enregistrer('session', f.session, "Délai d'inactivité enregistré")

/* ---- Sauvegarde ---- */
const sauvegardes = computed(() => exportsStore.jobs.filter((j) => j.kind === 'database'))
const taille = (b) => (b < 1024 * 1024 ? `${Math.max(1, Math.round(b / 1024))} Ko` : `${(b / 1024 / 1024).toFixed(1)} Mo`)
async function sauvegarder() { busy.value = 'dump'; try { await startExport({ kind: 'database' }) } catch (e) { toast(e.message, 'error') } finally { busy.value = '' } }

/* ---- Alertes e-mail ---- */
const typesAlertes = [['rupture', 'Produits en rupture'], ['stock_bas', 'Stocks bas'], ['creances', 'Créances anciennes'], ['ecart_caisse', 'Écarts de caisse'], ['licence', "Licence d'utilisation"]]
const basculer = (t) => { const i = f.alertes.types.indexOf(t); i >= 0 ? f.alertes.types.splice(i, 1) : f.alertes.types.push(t) }
const transports = [{ value: 'mail', label: 'Fonction mail() du serveur (hébergement mutualisé)' }, { value: 'smtp', label: 'Serveur SMTP (Gmail, OVH, Office 365…)' }]
const securites = [{ value: 'tls', label: 'STARTTLS (port 587)' }, { value: 'ssl', label: 'SSL/TLS (port 465)' }, { value: 'none', label: 'Aucune (déconseillé)' }]
const frequences = [{ value: 'quotidien', label: 'Tous les jours' }, { value: 'hebdomadaire', label: 'Chaque lundi' }]
async function saveMail() {
  const ok = await enregistrer('mail', { ...f.mail, smtp_pass: nouveauPass.value }, 'Paramètres e-mail enregistrés')
  if (ok) { nouveauPass.value = ''; await enregistrer('alertes', f.alertes, 'Alertes enregistrées') }
}
async function actionMail(route, libelle) {
  busy.value = route
  try {
    const r = await request('POST', route)
    toast(r.envoye === false ? r.message : `${libelle} : envoyé à ${r.destinataires?.join(', ') || 'vos destinataires'}`)
  } catch (e) { toast(e.message, 'error') } finally { busy.value = ''; cfg.value = await api.get('config') }   // journal d'envoi à jour, formulaires intacts
}
const dateHeure = (d) => new Date(d.replace(' ', 'T') + 'Z').toLocaleString('fr-FR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
const statutMail = { envoye: ['success', 'Envoyé'], echec: ['danger', 'Échec'], rien: ['', 'Rien à signaler'] }
const message = (k) => erreurs.value[k]
</script>

<template>
  <main class="page">
    <div class="page-head reveal"><div><h1>Configuration</h1><p>Réservée au super administrateur — entreprise, apparence, licence, caisse, sauvegarde et alertes</p></div></div>

    <div v-if="!cfg" class="skeleton" style="height:320px" />
    <div v-else class="cfg">
      <nav class="card cfg-nav reveal" aria-label="Sections">
        <button v-for="[k, l, i] in onglets" :key="k" :class="{ on: onglet === k }" @click="onglet = k"><Icon :name="i" :size="19" /> {{ l }}</button>
      </nav>

      <!-- ============ Entreprise ============ -->
      <div v-if="onglet === 'entreprise'" class="cfg-section" :key="'e'">
        <section class="card cfg-card">
          <h2>Informations de l'entreprise</h2><p class="muted">Affichées dans l'application, l'écran de connexion et l'en-tête des exports Excel et PDF.</p>
          <div class="logo-box" style="margin-bottom:18px">
            <div class="preview"><img v-if="logoUrl" :src="logoUrl" alt="Logo" /><Icon v-else name="image" :size="30" /></div>
            <div style="flex:1;min-width:240px">
              <FileDrop :model-value="logoFile" accept=".png,.jpg,.jpeg,.webp" :max-mb="2" :invalid="!!message('logo')" @update:model-value="envoyerLogo" />
              <button v-if="logoUrl" class="btn sm ghost" style="margin-top:8px" @click="retirerLogo"><Icon name="trash" :size="14" /> Retirer le logo</button>
            </div>
          </div>
          <div class="grid-form">
            <div class="field full" :class="{ invalid: message('nom') }"><label for="c-nom">Nom de l'entreprise <span class="req">*</span></label><input id="c-nom" v-model="f.entreprise.nom" class="input" /><span v-if="message('nom')" class="error">{{ message('nom') }}</span></div>
            <div class="field"><label for="c-forme">Forme juridique</label><input id="c-forme" v-model="f.entreprise.forme" class="input" placeholder="SARL, SA, SAS…" /></div>
            <div class="field"><label>Téléphone</label><PhoneInput v-model="f.entreprise.telephone" /></div>
            <div class="field" :class="{ invalid: message('email') }"><label for="c-mail">E-mail</label><input id="c-mail" v-model="f.entreprise.email" type="email" class="input" /><span v-if="message('email')" class="error">{{ message('email') }}</span></div>
            <div class="field" :class="{ invalid: message('site') }"><label for="c-site">Site web</label><input id="c-site" v-model="f.entreprise.site" class="input" placeholder="www.exemple.com" /><span v-if="message('site')" class="error">{{ message('site') }}</span></div>
            <div class="field full"><label for="c-adr">Adresse</label><input id="c-adr" v-model="f.entreprise.adresse" class="input" /></div>
            <div class="field"><label for="c-ville">Ville</label><input id="c-ville" v-model="f.entreprise.ville" class="input" /></div>
            <div class="field"><label for="c-pays">Pays</label><input id="c-pays" v-model="f.entreprise.pays" class="input" /></div>
            <div class="field"><label for="c-rccm">N° RCCM</label><input id="c-rccm" v-model="f.entreprise.rccm" class="input" /></div>
            <div class="field"><label for="c-nif">N° d'identification fiscale</label><input id="c-nif" v-model="f.entreprise.nif" class="input" /></div>
            <div class="field" :class="{ invalid: message('devise') }"><label for="c-dev">Devise <span class="req">*</span></label><input id="c-dev" v-model="f.entreprise.devise" class="input" maxlength="10" /><span class="hint">Affichée après chaque montant (FCFA, XOF, EUR…).</span></div>
          </div>
          <div class="cfg-actions"><button class="btn primary" :disabled="busy === 'entreprise'" @click="saveEntreprise"><Icon name="check" :size="16" /> Enregistrer</button></div>
        </section>
        <section class="card cfg-card">
          <h2>En-tête et pied de page des documents</h2><p class="muted">Repris sur les exports PDF et Excel (journal, ventes, stock, mouvements…), avec le logo, les coordonnées ci-dessus et les couleurs de la palette choisie.</p>
          <div class="grid-form">
            <div class="field full" :class="{ invalid: message('entete') }"><label for="d-ent">Mention d'en-tête</label><input id="d-ent" v-model="f.documents.entete" class="input" maxlength="150" placeholder="Ex. Votre partenaire au quotidien" /><span v-if="message('entete')" class="error">{{ message('entete') }}</span><small class="hint">Affichée sous le nom de l'entreprise. Facultatif.</small></div>
            <div class="field full" :class="{ invalid: message('pied') }"><label for="d-pied">Pied de page</label><textarea id="d-pied" v-model="f.documents.pied" class="input" rows="2" maxlength="300" :placeholder="piedParDefaut" /><span v-if="message('pied')" class="error">{{ message('pied') }}</span><small class="hint">Laissé vide : nom, RCCM et NIF de l'entreprise.</small></div>
          </div>
          <div class="cfg-actions"><button class="btn primary" :disabled="busy === 'documents'" @click="enregistrer('documents', f.documents, 'En-tête et pied de page enregistrés')"><Icon name="check" :size="16" /> Enregistrer</button></div>
        </section>
        <section class="card cfg-card">
          <h2>Mention de copyright</h2><p class="muted">Pied de page de l'application. Le nom devient un lien cliquable si vous indiquez une adresse (par exemple le site de Dav'Consulting).</p>
          <div class="grid-form">
            <div class="field"><label for="c-cn">Nom affiché</label><input id="c-cn" v-model="f.copyright.nom" class="input" /></div>
            <div class="field" :class="{ invalid: message('url') }"><label for="c-cu">Lien (site web)</label><input id="c-cu" v-model="f.copyright.url" class="input" placeholder="www.dav-consulting.com" /><span v-if="message('url')" class="error">{{ message('url') }}</span></div>
          </div>
          <div class="cfg-actions"><button class="btn primary" :disabled="busy === 'copyright'" @click="enregistrer('copyright', f.copyright, 'Copyright enregistré')"><Icon name="check" :size="16" /> Enregistrer</button></div>
        </section>
      </div>

      <!-- ============ Apparence ============ -->
      <div v-else-if="onglet === 'apparence'" class="cfg-section" :key="'a'">
        <section class="card cfg-card">
          <h2>Palette de couleurs</h2><p class="muted">Choisissez l'accent de l'application : l'aperçu est immédiat, l'enregistrement l'applique à tous les utilisateurs.</p>
          <div class="palettes" role="radiogroup" aria-label="Palette">
            <button v-for="(nom, k) in cfg.palettes" :key="k" class="pal" :class="{ on: f.theme.palette === k }" role="radio" :aria-checked="f.theme.palette === k" @click="f.theme.palette = k">
              <span v-if="f.theme.palette === k" class="tick"><Icon name="check" :size="15" /></span>
              <span class="sw" :style="{ background: `linear-gradient(135deg, ${degrades[k][0]}, ${degrades[k][1]})` }" />
              <b>{{ nom }}</b><small>{{ cfg.theme.palette === k ? 'Actuelle' : 'Aperçu' }}</small>
            </button>
          </div>
        </section>
        <section class="card cfg-card">
          <h2>Mode d'affichage par défaut</h2><p class="muted">Chaque utilisateur peut ensuite choisir son propre mode depuis son menu.</p>
          <div class="seg" role="radiogroup"><button v-for="[k, l] in [['auto', 'Automatique (selon l\'appareil)'], ['clair', 'Clair'], ['sombre', 'Sombre']]" :key="k" :class="{ on: f.theme.mode === k }" role="radio" @click="f.theme.mode = k">{{ l }}</button></div>
          <div class="cfg-actions"><button v-if="apercu.palette || apercu.mode" class="btn" @click="f.theme.palette = cfg.theme.palette; f.theme.mode = cfg.theme.mode">Annuler l'aperçu</button><button class="btn primary" :disabled="busy === 'theme'" @click="saveTheme"><Icon name="check" :size="16" /> Enregistrer le thème</button></div>
        </section>
      </div>


      <!-- ============ Caisse et tickets ============ -->
      <div v-else-if="onglet === 'caisse'" class="cfg-section" :key="'k'">
        <section class="card cfg-card">
          <h2>Règles de vente</h2><p class="muted">Ces réglages s'appliquent immédiatement à toutes les caisses.</p>
          <div class="grid-form">
            <div class="field" :class="{ invalid: message('tva_defaut') }"><label for="k-tva">TVA par défaut (%)</label><input id="k-tva" v-model="f.caisse.tva_defaut" type="number" min="0" max="50" step="0.01" class="input" /><span class="hint">Prix de vente TTC : la TVA est incluse et détaillée sur le ticket.</span><span v-if="message('tva_defaut')" class="error">{{ message('tva_defaut') }}</span></div>
            <div class="field" :class="{ invalid: message('remise_max') }"><label for="k-rem">Remise maximale du caissier (%)</label><input id="k-rem" v-model="f.caisse.remise_max" type="number" min="0" max="100" step="1" class="input" /><span class="hint">Le gérant n'est pas limité.</span><span v-if="message('remise_max')" class="error">{{ message('remise_max') }}</span></div>
            <div class="field" :class="{ invalid: message('ecart_tolere') }"><label for="k-ec">Écart de caisse toléré</label><input id="k-ec" v-model="f.caisse.ecart_tolere" type="number" min="0" class="input" /><span class="hint">Au-delà, une explication est obligatoire à la clôture.</span><span v-if="message('ecart_tolere')" class="error">{{ message('ecart_tolere') }}</span></div>
            <div class="field" :class="{ invalid: message('prefixe') }"><label for="k-pre">Préfixe des numéros de vente</label><input id="k-pre" v-model="f.caisse.prefixe" class="input" maxlength="6" /><span v-if="message('prefixe')" class="error">{{ message('prefixe') }}</span></div>
            <label class="toggle full"><input v-model="f.caisse.prix_libre" type="checkbox" true-value="1" false-value="0" /><span class="sw" /> Autoriser le caissier à modifier un prix</label>
            <label class="toggle full"><input v-model="f.caisse.stock_negatif" type="checkbox" true-value="1" false-value="0" /><span class="sw" /> Autoriser la vente malgré un stock insuffisant</label>
          </div>
        </section>
        <section class="card cfg-card">
          <h2>Personnaliser les tickets</h2>
          <p class="muted">Ces informations figurent sur les tickets de vente et de commande, y compris avant la livraison. Le nom, les coordonnées, les identifiants et le logo se renseignent dans l'onglet Entreprise.</p>
          <div class="grid-form">
            <div class="field"><label for="k-lar">Largeur du papier</label><SearchSelect id="k-lar" v-model="f.caisse.ticket_largeur" :options="[{ value: '58', label: '58 mm' }, { value: '80', label: '80 mm' }]" /></div>
            <label v-for="[k, label] in [['logo', 'Afficher le logo'], ['adresse', 'Afficher l’adresse'], ['contact', 'Afficher téléphone, e-mail et site'], ['identifiants', 'Afficher RCCM et NIF']]" :key="k" class="toggle full"><input v-model="f.caisse['ticket_' + k]" type="checkbox" true-value="1" false-value="0" /><span class="sw" /> {{ label }}</label>
            <div class="field full" :class="{ invalid: message('ticket_entete') }"><label for="k-ent">Informations d'en-tête</label><textarea id="k-ent" v-model="f.caisse.ticket_entete" class="input" rows="4" maxlength="1000" placeholder="Slogan, horaires, mentions complémentaires…" /><span class="hint">Plusieurs lignes possibles · 1 000 caractères maximum.</span><span v-if="message('ticket_entete')" class="error">{{ message('ticket_entete') }}</span></div>
            <div class="field full" :class="{ invalid: message('ticket_pied') }"><label for="k-pie">Pied de page</label><textarea id="k-pie" v-model="f.caisse.ticket_pied" class="input" rows="4" maxlength="1000" placeholder="Remerciements, conditions de retour…" /><span v-if="message('ticket_pied')" class="error">{{ message('ticket_pied') }}</span></div>
          </div>
          <div style="margin-top:18px;padding:16px;background:var(--surface-2);border-radius:12px"><p class="muted" style="margin-top:0">Aperçu du ticket</p><Ticket :vente="apercuTicket" /></div>
          <div class="cfg-actions"><button class="btn primary" :disabled="busy === 'caisse'" @click="enregistrer('caisse', f.caisse, 'Réglages de caisse enregistrés')"><Icon name="check" :size="16" /> Enregistrer</button></div>
        </section>
      </div>

      <!-- ============ Licence et sessions ============ -->
      <div v-else-if="onglet === 'licence'" class="cfg-section" :key="'l'">
        <section class="card cfg-card">
          <h2>Période d'utilisation (licence)</h2><p class="muted">Définissez la durée pendant laquelle l'application peut être utilisée. Laissez vide pour une utilisation illimitée.</p>
          <div class="lic-box">
            <div class="top"><b>{{ licTexte }}</b><span v-if="lic.defini" class="badge" :class="licTon">{{ date(lic.debut) }} → {{ date(lic.fin) }}</span></div>
            <div v-if="lic.defini" class="goal" style="padding:0"><div class="track" role="progressbar" :aria-valuenow="licProgress" aria-valuemin="0" aria-valuemax="100" aria-label="Avancement de la licence"><div class="fill" :class="lic.etat === 'expiree' ? 'danger' : lic.etat === 'bientot' ? 'warning' : ''" :style="{ width: licProgress + '%' }" /></div></div>
          </div>
          <div class="grid-form">
            <div class="field" :class="{ invalid: message('debut') }"><label for="l-d">Début</label><input id="l-d" v-model="f.licence.debut" type="date" class="input" /><span v-if="message('debut')" class="error">{{ message('debut') }}</span></div>
            <div class="field" :class="{ invalid: message('duree_mois') }"><label for="l-m">Durée (en mois)</label><input id="l-m" v-model="f.licence.duree_mois" type="number" min="1" max="240" class="input" placeholder="12" /><span v-if="message('duree_mois')" class="error">{{ message('duree_mois') }}</span></div>
            <div class="field full"><label>À l'expiration</label>
              <div class="radio-cards">
                <label :class="{ on: f.licence.apres === 'lecture_seule' }"><input v-model="f.licence.apres" type="radio" value="lecture_seule" /><span><b>Lecture seule</b><small>Consultation et exports possibles, aucune modification.</small></span></label>
                <label :class="{ on: f.licence.apres === 'bloque' }"><input v-model="f.licence.apres" type="radio" value="bloque" /><span><b>Accès suspendu</b><small>Les utilisateurs ne peuvent plus se servir de l'application.</small></span></label>
              </div></div>
            <div class="field full"><label for="l-n">Note interne</label><input id="l-n" v-model="f.licence.note" class="input" placeholder="Contrat n°, référence de facturation…" /></div>
          </div>
          <p class="muted" style="margin:14px 0 0;font-size:13px">Le super administrateur garde toujours l'accès pour renouveler la période. Les administrateurs voient un rappel 30 jours avant l'échéance.</p>
          <div class="cfg-actions"><button class="btn primary" :disabled="busy === 'licence'" @click="saveLicence"><Icon name="check" :size="16" /> Enregistrer la période</button></div>
        </section>
        <section class="card cfg-card">
          <h2>Déconnexion après inactivité</h2><p class="muted">Protège les postes laissés ouverts : la session se ferme après ce délai sans action.</p>
          <div class="grid-form"><div class="field" :class="{ invalid: message('minutes') }"><label for="s-m">Délai (en minutes)</label><input id="s-m" v-model="f.session.minutes" type="number" min="0" max="1440" class="input" /><span class="hint">0 = jamais déconnecté automatiquement.</span><span v-if="message('minutes')" class="error">{{ message('minutes') }}</span></div></div>
          <div class="cfg-actions"><button class="btn primary" :disabled="busy === 'session'" @click="saveSession"><Icon name="check" :size="16" /> Enregistrer</button></div>
        </section>
      </div>

      <!-- ============ Sauvegarde ============ -->
      <div v-else-if="onglet === 'sauvegarde'" class="cfg-section" :key="'s'">
        <section class="card cfg-card">
          <h2>Sauvegarde de la base de données</h2><p class="muted">Un fichier SQL compressé (.sql.gz) contenant toutes les tables et toutes les données. Il est préparé en arrière-plan : vous pouvez continuer à travailler et le retrouver dans « Mes exports ».</p>
          <div class="notice warn" style="margin-bottom:16px"><Icon name="alert" :size="18" /><span>Cette sauvegarde contient <b>toutes les données et les comptes utilisateurs</b>. Conservez-la en lieu sûr et ne la partagez pas.</span></div>
          <button class="btn primary" :disabled="busy === 'dump'" @click="sauvegarder"><Icon name="database" :size="16" /> Créer une sauvegarde maintenant</button>
        </section>
        <section class="card cfg-card">
          <h2>Sauvegardes récentes</h2><p class="muted">Conservées 7 jours sur le serveur : téléchargez-les pour les archiver.</p>
          <div v-if="!sauvegardes.length" class="muted">Aucune sauvegarde récente.</div>
          <div v-for="j in sauvegardes" :key="j.id" class="bk-item">
            <span class="tile" :class="j.status === 'termine' ? 'success' : j.status === 'echec' ? 'danger' : 'primary'"><Icon name="database" :size="18" /></span>
            <div class="grow"><b>{{ j.filename || 'Sauvegarde en cours…' }}</b>
              <span v-if="j.status === 'termine'">{{ taille(j.size) }} · {{ dateHeure(j.created_at) }}</span><span v-else-if="j.status === 'echec'" style="color:var(--danger)">{{ j.error }}</span>
              <template v-else><span>Génération… {{ j.progress }} %</span><div class="mini-bar" style="margin-top:5px"><i :style="{ width: Math.max(6, j.progress) + '%' }" /></div></template></div>
            <a v-if="j.status === 'termine'" class="btn sm primary" :href="downloadUrl(j.id)" download><Icon name="download" :size="14" /> Télécharger</a>
          </div>
        </section>
        <section class="card cfg-card">
          <h2>Restaurer une sauvegarde</h2><p class="muted">Décompressez le fichier puis importez-le dans une base vide (phpMyAdmin → Importer, ou en ligne de commande) :</p>
          <div class="code">gunzip caisse-sauvegarde-AAAA-MM-JJ-HHMM.sql.gz
mysql -u utilisateur -p nom_de_la_base &lt; caisse-sauvegarde-AAAA-MM-JJ-HHMM.sql</div>
        </section>
      </div>

      <!-- ============ Alertes e-mail ============ -->
      <div v-else class="cfg-section" :key="'m'">
        <section class="card cfg-card">
          <div class="card-head"><h2>Alertes par e-mail</h2><label class="toggle"><input v-model="f.mail.actif" type="checkbox" /><span class="sw" /><span>{{ f.mail.actif ? 'Activées' : 'Désactivées' }}</span></label></div>
          <p class="muted">Un résumé des ruptures, stocks bas, créances anciennes et écarts de caisse est envoyé par e-mail. Sans tâche planifiée sur le serveur, il part automatiquement à la première connexion de la journée.</p>
          <div class="grid-form">
            <div class="field full" :class="{ invalid: message('destinataires') }"><label for="m-d">Destinataires</label><textarea id="m-d" v-model="f.mail.destinataires" class="input" placeholder="direction@entreprise.com, comptabilite@entreprise.com" /><span class="hint">Séparez les adresses par une virgule ou un retour à la ligne (10 maximum).</span><span v-if="message('destinataires')" class="error">{{ message('destinataires') }}</span></div>
            <div class="field full"><label>Quelles alertes envoyer ?</label>
              <div class="checks"><label v-for="[k, l] in typesAlertes" :key="k" :class="{ on: f.alertes.types.includes(k) }"><input type="checkbox" :checked="f.alertes.types.includes(k)" @change="basculer(k)" /> {{ l }}</label></div></div>
            <div class="field" :class="{ invalid: message('jours_credit') }"><label for="m-r">Créance ancienne après … jours</label><input id="m-r" v-model="f.alertes.jours_credit" type="number" min="1" max="365" class="input" /><span v-if="message('jours_credit')" class="error">{{ message('jours_credit') }}</span></div>
            <div class="field"><label for="m-f">Fréquence</label><SearchSelect id="m-f" v-model="f.alertes.frequence" :options="frequences" /></div>
          </div>
        </section>
        <section class="card cfg-card">
          <h2>Envoi des e-mails</h2><p class="muted">Comment l'application transmet les messages.</p>
          <div class="grid-form">
            <div class="field full"><label for="m-t">Méthode d'envoi</label><SearchSelect id="m-t" v-model="f.mail.transport" :options="transports" /></div>
            <template v-if="f.mail.transport === 'smtp'">
              <div class="field" :class="{ invalid: message('smtp_hote') }"><label for="m-h">Serveur SMTP</label><input id="m-h" v-model="f.mail.smtp_hote" class="input" placeholder="smtp.gmail.com" /><span v-if="message('smtp_hote')" class="error">{{ message('smtp_hote') }}</span></div>
              <div class="field" :class="{ invalid: message('smtp_port') }"><label for="m-p">Port</label><input id="m-p" v-model="f.mail.smtp_port" type="number" class="input" /></div>
              <div class="field"><label for="m-s">Sécurité</label><SearchSelect id="m-s" v-model="f.mail.smtp_securite" :options="securites" /></div>
              <div class="field"><label for="m-u">Identifiant</label><input id="m-u" v-model="f.mail.smtp_user" class="input" autocomplete="off" /></div>
              <div class="field full"><label for="m-w">Mot de passe</label><input id="m-w" v-model="nouveauPass" type="password" class="input" autocomplete="new-password" :placeholder="cfg.mail.smtp_pass_defini ? '•••••••• (défini — laisser vide pour conserver)' : ''" /><span class="hint">Jamais affiché ni renvoyé par l'application une fois enregistré.</span></div>
            </template>
            <div class="field" :class="{ invalid: message('expediteur') }"><label for="m-e">Adresse d'expédition</label><input id="m-e" v-model="f.mail.expediteur" type="email" class="input" placeholder="alertes@entreprise.com" /><span v-if="message('expediteur')" class="error">{{ message('expediteur') }}</span></div>
            <div class="field"><label for="m-n">Nom de l'expéditeur</label><input id="m-n" v-model="f.mail.expediteur_nom" class="input" /></div>
          </div>
          <div class="cfg-actions">
            <button class="btn" :disabled="!!busy" @click="actionMail('config-mail-test', 'Test')"><Icon name="mail" :size="16" /> Envoyer un e-mail de test</button>
            <button class="btn" :disabled="!!busy" @click="actionMail('config-alertes-envoyer', 'Résumé')"><Icon name="alert" :size="16" /> Envoyer le résumé maintenant</button>
            <button class="btn primary" :disabled="busy === 'mail'" @click="saveMail"><Icon name="check" :size="16" /> Enregistrer</button>
          </div>
          <p class="muted" style="margin:14px 0 6px;font-size:13px">Enregistrez d'abord vos réglages avant d'envoyer un test. Tâche planifiée recommandée (facultative) :</p>
          <div class="code">0 7 * * *  php /chemin/vers/caisse/bin/send-alerts.php</div>
        </section>
        <section class="card cfg-card">
          <h2>Derniers envois</h2>
          <div v-if="!cfg.journal_mail.length" class="muted">Aucun envoi pour l'instant.</div>
          <div v-for="m in cfg.journal_mail" :key="m.id" class="bk-item">
            <span class="badge" :class="statutMail[m.statut][0]">{{ statutMail[m.statut][1] }}</span>
            <div class="grow"><b>{{ m.sujet || (m.type === 'test' ? 'E-mail de test' : 'Résumé d\'alertes') }}</b><span>{{ dateHeure(m.created_at) }} · {{ m.destinataires }}<template v-if="m.erreur"> · {{ m.erreur }}</template></span></div>
            <span v-if="m.nb_alertes" class="badge">{{ m.nb_alertes }} alerte{{ m.nb_alertes > 1 ? 's' : '' }}</span>
          </div>
        </section>
      </div>
    </div>
  </main>
</template>
