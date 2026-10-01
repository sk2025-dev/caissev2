<script setup>
// Commandes clients et livraisons : suivi par étapes, bon de livraison, remise avec encaissement (qui crée la vente).
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { api, auth, ApiError } from '../api'
import { lookups, loadLookups } from '../lookups'
import { money, qty, dateHeure, number } from '../format'
import { STATUTS, libelleStatut, finale, suivante, datePrevue, enRetard } from '../commandes'
import { toast } from '../toast'
import Icon from '../components/Icon.vue'
import SearchSelect from '../components/SearchSelect.vue'
import Pager from '../components/Pager.vue'
import CommandeForm from '../components/CommandeForm.vue'
import FicheLivraison from '../components/FicheLivraison.vue'
import Ticket from '../components/Ticket.vue'

const ZONE_COURTE = { abidjan: 'Abidjan', interieur: 'Intérieur', exterieur: 'Extérieur' }
const peutCreer = computed(() => !!auth.user?.droits?.commandes_ecriture)
const peutStatut = computed(() => !!auth.user?.droits?.commandes_statut)
const peutEncaisser = computed(() => !!auth.user?.droits?.caisse)

const rows = ref([])
const meta = reactive({ total: 0, page: 1, pages: 1, per_page: 25, comptes: {} })
const loading = ref(true)
const error = ref('')
const f = reactive({ q: '', statut: 'actives', mode: '', zone: '', page: 1 })
const onglets = [['actives', 'En cours'], ['nouvelle', 'Nouvelles'], ['preparation', 'En préparation'], ['prete', 'Prêtes'], ['en_livraison', 'En livraison'], ['livree', 'Livrées'], ['annulee', 'Annulées']]
const compte = (k) => (k === 'actives' ? ['nouvelle', 'preparation', 'prete', 'en_livraison'].reduce((s, x) => s + (meta.comptes[x] || 0), 0) : meta.comptes[k] || 0)

let seq = 0
async function load() {
  const my = ++seq
  loading.value = true; error.value = ''
  try {
    const res = await api.get('commandes', { q: f.q, statut: f.statut, mode: f.mode, zone: f.zone, page: f.page })
    if (my !== seq) return
    rows.value = res.data; Object.assign(meta, res.meta)
  } catch (e) { if (my === seq) error.value = e.message } finally { if (my === seq) loading.value = false }
}
let timer
watch(() => f.q, () => { clearTimeout(timer); timer = setTimeout(() => { f.page = 1; load() }, 300) })
watch(() => [f.statut, f.mode, f.zone], () => { f.page = 1; load() })
onMounted(() => { loadLookups().catch(() => {}); load() })
onBeforeUnmount(() => clearTimeout(timer))

/* ---- Création ---- */
const creation = ref(false)
async function creee(c) { creation.value = false; detail.value = c; ticketCommande.value = c; await load() }

/* ---- Détail et étapes ---- */
const detail = ref(null)
const vente = ref(null)
const ticketCommande = ref(null)
async function voir(r) { try { detail.value = (await api.get('commande', { id: r.idcommande })).commande } catch (e) { toast(e.message, 'error') } }
const busy = ref(false)
const livreur = ref('')
const chercherLivreur = ref(false)
async function avancer(statut) {
  if (statut === 'en_livraison' && !chercherLivreur.value) { chercherLivreur.value = true; livreur.value = detail.value.livreur || ''; return }
  busy.value = true
  try {
    detail.value = (await api.post('commande-statut', { idcommande: detail.value.idcommande, statut, livreur: livreur.value })).commande
    chercherLivreur.value = false; toast(`Commande ${libelleStatut(detail.value).toLowerCase()}`); load()
  } catch (e) { toast(e.errors?.livreur || e.message, 'error') } finally { busy.value = false }
}
const action = computed(() => (detail.value ? suivante(detail.value) : null))
function principale() {
  if (action.value.statut === 'livree') ouvrirPaiement(); else avancer(action.value.statut)
}

/* ---- Annulation ---- */
const annul = reactive({ ouvert: false, motif: '', erreur: '', busy: false })
async function annuler() {
  annul.busy = true; annul.erreur = ''
  try {
    detail.value = (await api.post('commande-annuler', { idcommande: detail.value.idcommande, motif: annul.motif })).commande
    annul.ouvert = false; toast('Commande annulée'); load()
  } catch (e) { if (!(e instanceof ApiError)) throw e; annul.erreur = e.errors?.motif || e.message } finally { annul.busy = false }
}

/* ---- Remise + encaissement ---- */
const pay = reactive({ ouvert: false, lignes: [], erreur: '', busy: false, receptionnaire: '', observations: '' })
const modeInfo = (code) => lookups.modes.find((m) => m.code === code)
const paye = computed(() => pay.lignes.reduce((s, l) => s + (Number(l.montant) || 0), 0))
const especes = computed(() => pay.lignes.filter((l) => modeInfo(l.mode)?.type === 'especes').reduce((s, l) => s + (Number(l.montant) || 0), 0))
const total = computed(() => Number(detail.value?.total || 0))
const exces = computed(() => Math.max(0, Math.round((paye.value - total.value) * 100) / 100))
const rendu = computed(() => (exces.value > 0 && exces.value <= especes.value + 0.004 ? exces.value : 0))
const reste = computed(() => Math.max(0, Math.round((total.value - (paye.value - rendu.value)) * 100) / 100))
function ouvrirPaiement() {
  pay.erreur = ''; pay.receptionnaire = detail.value.client_nom; pay.observations = ''
  pay.lignes = [{ mode: lookups.modes.find((m) => m.type === 'especes')?.code || lookups.modes[0]?.code, montant: total.value, reference: '' }]
  pay.ouvert = true
}
function basculerMode(code) {
  const l = pay.lignes.find((x) => x.mode === code)
  if (l) { if (pay.lignes.length > 1) pay.lignes = pay.lignes.filter((x) => x !== l); return }
  pay.lignes.push({ mode: code, montant: Math.max(0, total.value - paye.value) || '', reference: '' })
}
async function livrer() {
  pay.busy = true; pay.erreur = ''
  try {
    const res = await api.post('commande-livrer', { idcommande: detail.value.idcommande, receptionnaire: pay.receptionnaire, observations: pay.observations, paiements: pay.lignes.filter((l) => Number(l.montant) > 0).map((l) => ({ mode: l.mode, montant: Number(l.montant), reference: l.reference })) })
    detail.value = res.commande; ticketCommande.value = null; vente.value = res.vente; pay.ouvert = false
    toast(`${detail.value.mode === 'livraison' ? 'Livraison' : 'Retrait'} enregistré — vente ${res.vente.numero}`); load()
  } catch (e) {
    if (!(e instanceof ApiError)) throw e
    pay.erreur = e.message
    if (e.code === 'caisse_fermee') pay.erreur = 'Ouvrez votre caisse (écran Caisse) avant d\'encaisser une commande.'
  } finally { pay.busy = false }
}
const imprimer = () => window.print()
const tel = (t) => String(t || '').replace(/[^\d+]/g, '')
const etapeIdx = computed(() => (detail.value ? detail.value.sequence.indexOf(detail.value.statut) : -1))
const nomEtape = (s) => ({ ...{ livree: detail.value?.mode === 'retrait' ? 'Retirée' : 'Livrée' }, nouvelle: 'Reçue', preparation: 'Préparation', prete: 'Prête', en_livraison: 'En route' }[s])
</script>

<template>
  <main class="page">
    <div class="page-head">
      <div><h1>Commandes et livraisons</h1><p>Prenez une commande, suivez sa préparation et encaissez à la remise.</p></div>
      <button v-if="peutCreer" class="btn primary" @click="creation = true"><Icon name="plus" :size="16" /> Nouvelle commande</button>
    </div>

    <div class="card">
      <div class="onglets" role="tablist" aria-label="Statut">
        <button v-for="[k, l] in onglets" :key="k" role="tab" :aria-selected="f.statut === k" :class="{ on: f.statut === k }" @click="f.statut = k">{{ l }} <i v-if="compte(k)">{{ compte(k) }}</i></button>
      </div>
      <div class="toolbar">
        <div class="search"><Icon name="search" :size="16" /><input v-model="f.q" class="input" type="search" placeholder="N°, client, téléphone, adresse…" aria-label="Rechercher une commande" /></div>
        <SearchSelect v-model="f.mode" class="filter" label="Mode" :options="[{ value: '', label: 'Livraisons et retraits' }, { value: 'livraison', label: 'Livraisons' }, { value: 'retrait', label: 'Retraits' }]" />
        <SearchSelect v-model="f.zone" class="filter" label="Zone de livraison" :options="[{ value: '', label: 'Toutes les zones' }, { value: 'abidjan', label: 'Abidjan' }, { value: 'interieur', label: 'Intérieur du pays' }, { value: 'exterieur', label: 'Extérieur' }]" />
      </div>

      <div v-if="error" class="empty"><div class="alert" style="display:inline-block">{{ error }}</div><div style="margin-top:12px"><button class="btn" @click="load">Réessayer</button></div></div>
      <div v-else class="table-wrap" :aria-busy="loading">
        <table v-if="rows.length || loading" class="table stack">
          <thead><tr><th>Commande</th><th>Prévue</th><th>Client</th><th>Lieu de livraison</th><th class="num">Total</th><th>État</th><th /></tr></thead>
          <tbody v-if="loading && !rows.length"><tr v-for="n in 6" :key="n"><td colspan="7"><div class="skeleton" style="height:22px" /></td></tr></tbody>
          <tbody v-else :style="{ opacity: loading ? .55 : 1 }">
            <tr v-for="r in rows" :key="r.idcommande" :class="{ cancelled: r.statut === 'annulee' }">
              <td data-label="Commande" class="strong"><a href="#" class="rowlink" @click.prevent="voir(r)">{{ r.numero }}</a><small class="sub">{{ r.nb_lignes }} article{{ r.nb_lignes > 1 ? 's' : '' }} · {{ dateHeure(r.created_at) }}</small></td>
              <td data-label="Prévue" :class="{ retard: enRetard(r) }">{{ datePrevue(r.date_prevue) }}<span v-if="enRetard(r)" class="badge danger" style="margin-left:6px">En retard</span></td>
              <td data-label="Client">{{ r.client_nom }}<small v-if="r.client_tel" class="sub">{{ r.client_tel }}</small></td>
              <td data-label="Lieu"><template v-if="r.mode === 'livraison'"><span class="zone-badge" :class="r.zone">{{ ZONE_COURTE[r.zone] }}</span> <b class="lieu">{{ r.commune ? r.commune + ' — ' : '' }}{{ r.lieu }}</b><small class="sub adr">{{ r.adresse }}</small></template><span v-else class="mode"><Icon name="cart" :size="15" /> Retrait en boutique</span></td>
              <td data-label="Total" class="num">{{ money(r.total) }}</td>
              <td data-label="État"><span class="badge" :class="STATUTS[r.statut]?.cls">{{ libelleStatut(r) }}</span><small v-if="r.statut === 'en_livraison' && r.livreur" class="sub">{{ r.livreur }}</small></td>
              <td class="actions"><button class="btn ghost icon sm" aria-label="Ouvrir la commande" title="Ouvrir" @click="voir(r)"><Icon name="truck" :size="16" /></button></td>
            </tr>
          </tbody>
        </table>
        <div v-else class="empty"><Icon name="truck" :size="40" /><div>Aucune commande dans cette vue.</div><button v-if="peutCreer" class="btn primary" style="margin-top:12px" @click="creation = true"><Icon name="plus" :size="16" /> Nouvelle commande</button></div>
      </div>
      <Pager :meta="meta" @goto="(p) => { f.page = p; load() }" />
    </div>

    <CommandeForm v-if="creation" @close="creation = false" @created="creee" />

    <!-- ===== Détail ===== -->
    <div v-if="detail" class="overlay center" @click.self="detail = null; vente = null" @keydown.esc="detail = null; vente = null">
      <div class="modal det" role="dialog" aria-modal="true" :aria-label="`Commande ${detail.numero}`">
        <header>
          <div><h3>{{ detail.numero }}</h3><span class="muted">{{ detail.mode === 'livraison' ? 'Livraison à domicile' : 'Retrait en boutique' }} · prise par {{ detail.createur }}</span></div>
          <span class="badge lg" :class="STATUTS[detail.statut]?.cls">{{ libelleStatut(detail) }}</span>
        </header>

        <ol v-if="detail.statut !== 'annulee'" class="etapes" aria-label="Avancement">
          <li v-for="(s, i) in detail.sequence" :key="s" :class="{ fait: i < etapeIdx || detail.statut === 'livree', actif: i === etapeIdx && detail.statut !== 'livree' }"><span class="pt"><Icon v-if="i < etapeIdx || detail.statut === 'livree'" name="check" :size="12" /></span>{{ nomEtape(s) }}</li>
        </ol>
        <div v-else class="alert">Commande annulée : {{ detail.annule_motif }}</div>

        <div class="infos">
          <div><small>Client</small><b>{{ detail.client_nom }}</b><a v-if="detail.client_tel" :href="`tel:${tel(detail.client_tel)}`" class="tel"><Icon name="phone" :size="14" /> {{ detail.client_tel }}</a></div>
          <div v-if="detail.mode === 'livraison'" class="lieu-box"><small>Lieu de livraison · <span class="zone-badge" :class="detail.zone">{{ detail.zone_libelle }}</span></small><b>{{ detail.lieu_complet }}</b><span>{{ detail.adresse }}</span><span v-if="detail.livreur" class="muted">Livreur : {{ detail.livreur }}</span></div>
          <div><small>{{ detail.mode === 'livraison' ? 'Livraison prévue' : 'Retrait prévu' }}</small><b :class="{ retard: enRetard(detail) }">{{ datePrevue(detail.date_prevue) }}</b></div>
          <div v-if="detail.note"><small>Note</small><b>{{ detail.note }}</b></div>
        </div>

        <table class="lignes">
          <tbody>
            <tr v-for="l in detail.lignes" :key="l.idligne"><td>{{ l.designation }}</td><td class="num">{{ qty(l.quantite) }} × {{ number(l.prix_unitaire) }}</td><td class="num">{{ money(l.total) }}</td></tr>
            <tr v-if="Number(detail.frais_livraison) > 0"><td>Livraison — {{ detail.lieu_complet }}<small v-if="detail.frais_tarif !== null && Number(detail.frais_tarif) !== Number(detail.frais_livraison)" class="muted"> (tarif {{ money(detail.frais_tarif) }}, prix modifié)</small></td><td /><td class="num">{{ money(detail.frais_livraison) }}</td></tr>
          </tbody>
          <tfoot><tr><td colspan="2">Total</td><td class="num">{{ money(detail.total) }}</td></tr></tfoot>
        </table>
        <p v-if="detail.vente_numero" class="muted" style="margin:6px 0 0">Encaissée sur la vente <b>{{ detail.vente_numero }}</b>.</p>

        <details class="histo"><summary>Historique</summary>
          <ul><li v-for="(h, i) in detail.historique" :key="i"><b>{{ STATUTS[h.statut]?.label }}</b> · {{ dateHeure(h.created_at) }} · {{ h.par }}<span v-if="h.note" class="muted"> — {{ h.note }}</span></li></ul>
        </details>

        <div v-if="chercherLivreur" class="field livreur"><label for="d-liv">Qui livre ? <span class="req">*</span></label><input id="d-liv" v-model="livreur" class="input" maxlength="80" placeholder="Nom du livreur" autofocus @keydown.enter.prevent="avancer('en_livraison')" /></div>

        <div class="row">
          <button class="btn" @click="detail = null; vente = null">Fermer</button>
          <button class="btn" @click="ticketCommande = detail"><Icon name="printer" :size="16" /> Ticket de commande</button>
          <button class="btn" @click="imprimer"><Icon name="printer" :size="16" /> {{ detail.mode === 'livraison' ? 'Fiche de livraison' : 'Bon de retrait' }}</button>
          <button v-if="peutCreer && !finale(detail)" class="btn danger" @click="Object.assign(annul, { ouvert: true, motif: '', erreur: '' })">Annuler</button>
          <button v-if="action && (action.statut === 'livree' ? peutEncaisser : peutStatut)" class="btn primary" :disabled="busy" @click="principale"><Icon :name="action.statut === 'livree' ? 'cash' : 'check'" :size="16" /> {{ chercherLivreur && action.statut === 'en_livraison' ? 'Confirmer le départ' : action.libelle }}</button>
        </div>
        <p v-if="action?.statut === 'livree' && !peutEncaisser" class="muted" style="margin:8px 0 0;font-size:13px">L'encaissement se fait par un caissier ou le gérant.</p>

        <FicheLivraison v-if="!vente && !ticketCommande" :commande="detail" />
      </div>
    </div>

    <!-- ===== Annulation ===== -->
    <div v-if="annul.ouvert" class="overlay center" style="z-index:70" @click.self="annul.ouvert = false" @keydown.esc.stop="annul.ouvert = false">
      <form class="modal" novalidate @submit.prevent="annuler">
        <h3>Annuler la commande {{ detail.numero }} ?</h3>
        <p class="muted">{{ Number(detail.stock_debite) ? 'Les articles déduits à la prise de commande seront automatiquement remis en stock.' : 'Aucun article n’a encore été déduit du stock pour cette commande.' }}</p>
        <div class="field" :class="{ invalid: annul.erreur }"><label for="a-m">Motif <span class="req">*</span></label><input id="a-m" v-model="annul.motif" class="input" maxlength="200" autofocus /><span v-if="annul.erreur" class="error">{{ annul.erreur }}</span></div>
        <div class="row"><button type="button" class="btn" @click="annul.ouvert = false">Retour</button><button class="btn danger" :disabled="annul.busy">{{ annul.busy ? 'Annulation…' : 'Annuler la commande' }}</button></div>
      </form>
    </div>

    <!-- ===== Encaissement à la remise ===== -->
    <div v-if="pay.ouvert" class="overlay center" style="z-index:70" @click.self="!pay.busy && (pay.ouvert = false)" @keydown.esc.stop="pay.ouvert = false">
      <div class="modal pay" role="dialog" aria-modal="true" aria-label="Encaissement">
        <h3>{{ detail.mode === 'livraison' ? 'Encaisser la livraison' : 'Encaisser le retrait' }}</h3>
        <div class="due"><span>À encaisser</span><b>{{ money(total) }}</b></div>
        <div class="modes"><button v-for="m in lookups.modes" :key="m.code" type="button" class="chip" :class="{ on: pay.lignes.some((l) => l.mode === m.code) }" @click="basculerMode(m.code)">{{ m.libelle }}</button></div>
        <div v-for="l in pay.lignes" :key="l.mode" class="pl">
          <label :for="`pm-${l.mode}`">{{ modeInfo(l.mode)?.libelle }}</label>
          <input :id="`pm-${l.mode}`" v-model="l.montant" class="input" type="number" min="0" inputmode="numeric" @focus="$event.target.select()" @keydown.enter.prevent="livrer" />
          <input v-if="modeInfo(l.mode)?.type !== 'especes'" v-model="l.reference" class="input" placeholder="Référence (facultatif)" maxlength="80" />
        </div>
        <div class="two">
          <div class="field"><label for="pm-rec">Réceptionné par</label><input id="pm-rec" v-model="pay.receptionnaire" class="input" maxlength="120" /></div>
          <div class="field"><label for="pm-obs">Observations</label><input id="pm-obs" v-model="pay.observations" class="input" maxlength="300" placeholder="Facultatif" /></div>
        </div>
        <div class="recap">
          <div class="sum"><span>Payé</span><span>{{ money(paye) }}</span></div>
          <div v-if="rendu > 0" class="sum change"><span>Monnaie à rendre</span><b>{{ money(rendu) }}</b></div>
          <div v-if="reste > 0" class="sum credit"><span>Reste à crédit</span><b>{{ money(reste) }}</b></div>
          <p v-if="reste > 0 && !detail.idclient" class="warn">Un reste à crédit exige un client enregistré : rattachez la commande à une fiche client.</p>
          <p v-if="exces > 0 && !rendu" class="warn">Le montant dépasse le total : seul un excédent en espèces peut être rendu.</p>
        </div>
        <div v-if="pay.erreur" class="alert" role="alert">{{ pay.erreur }}</div>
        <div class="row"><button class="btn" :disabled="pay.busy" @click="pay.ouvert = false">Retour</button><button class="btn primary" :disabled="pay.busy || (exces > 0 && !rendu)" @click="livrer"><Icon name="check" :size="16" /> {{ pay.busy ? 'Enregistrement…' : 'Valider la remise' }}</button></div>
      </div>
    </div>

    <!-- ===== Ticket de la vente créée ===== -->
    <div v-if="vente || ticketCommande" class="overlay center" style="z-index:80" @click.self="vente = null; ticketCommande = null" @keydown.esc.stop="vente = null; ticketCommande = null">
      <div class="modal" style="width:min(460px,calc(100% - 32px));max-height:calc(100vh - 32px);display:flex;flex-direction:column">
        <div style="overflow-y:auto;background:var(--surface-2);border-radius:16px;padding:4px"><Ticket :vente="vente" :commande="ticketCommande" /></div>
        <div class="row"><button class="btn" @click="vente = null; ticketCommande = null">Fermer</button><button class="btn primary" @click="imprimer"><Icon name="printer" :size="16" /> Imprimer le ticket</button></div>
      </div>
    </div>
  </main>
</template>

<style scoped>
.onglets { display: flex; gap: 4px; overflow-x: auto; padding: 12px 14px 0; border-bottom: 1px solid var(--border); }
.onglets button { flex: none; border: 0; background: none; padding: 10px 14px; font: inherit; font-weight: 600; color: var(--muted); cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -1px; display: inline-flex; gap: 8px; align-items: center; }
.onglets button.on { color: var(--primary); border-bottom-color: var(--primary); }
.onglets i { font-style: normal; font-size: 12px; background: var(--primary-soft); color: var(--primary); padding: 1px 8px; border-radius: 99px; font-weight: 700; }
.sub { display: block; color: var(--muted); font-weight: 400; font-size: 12.5px; }
.adr { max-width: 260px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mode { display: inline-flex; align-items: center; gap: 6px; }
.retard { color: var(--danger); font-weight: 700; }
tr.cancelled td { opacity: .6; }

.det { width: min(640px, calc(100% - 24px)); max-height: calc(100vh - 24px); overflow-y: auto; }
.det > .row { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 160px), 1fr)); }
.det > .row > .btn { min-height: 44px; }
.det > header { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
.det h3 { margin: 0; } .badge.lg { font-size: 14px; padding: 6px 14px; }
.etapes { list-style: none; display: flex; margin: 18px 0; padding: 0; }
.etapes li { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 6px; font-size: 12.5px; color: var(--muted); position: relative; text-align: center; }
.etapes li + li::before { content: ''; position: absolute; top: 10px; right: 50%; width: 100%; height: 3px; background: var(--border); z-index: 0; }
.etapes li.fait + li::before, .etapes li.actif::before { background: var(--primary); }
.etapes .pt { position: relative; z-index: 1; width: 22px; height: 22px; border-radius: 50%; background: var(--surface); border: 3px solid var(--border); display: grid; place-items: center; color: #fff; }
.etapes li.fait .pt { background: var(--primary); border-color: var(--primary); }
.etapes li.actif .pt { border-color: var(--primary); box-shadow: 0 0 0 5px var(--primary-soft); animation: pulse2 1.6s infinite; }
.etapes li.fait, .etapes li.actif { color: var(--text); font-weight: 600; }
.infos { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px; }
.infos > div { display: flex; flex-direction: column; gap: 2px; padding: 10px 12px; background: var(--surface-2); border-radius: 12px; }
.infos small { color: var(--muted); font-size: 12px; } .infos b { font-weight: 600; }
.tel { display: inline-flex; align-items: center; gap: 6px; color: var(--primary); font-weight: 600; text-decoration: none; }
.lignes { width: 100%; border-collapse: collapse; } .lignes td { padding: 7px 4px; border-bottom: 1px dashed var(--border); } .lignes .num { text-align: right; white-space: nowrap; }
.lignes tfoot td { font-size: 18px; font-weight: 800; border: 0; padding-top: 10px; }
.histo { margin-top: 12px; font-size: 13px; } .histo summary { cursor: pointer; color: var(--muted); } .histo ul { margin: 8px 0 0; padding-left: 18px; display: grid; gap: 4px; }
.livreur { margin-top: 12px; }
.zone-badge { display: inline-block; padding: 2px 9px; border-radius: 99px; font-size: 11.5px; font-weight: 700; background: var(--primary-soft); color: var(--primary); }
.zone-badge.interieur { background: var(--warning-soft); color: var(--warning); } .zone-badge.exterieur { background: var(--success-soft); color: var(--success); }
.lieu { font-weight: 600; } .lieu-box span { display: block; }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px; }
.modal.pay { width: min(480px, calc(100% - 32px)); max-height: calc(100vh - 32px); overflow-y: auto; }
.due { display: flex; justify-content: space-between; align-items: baseline; padding: 14px 16px; border-radius: 16px; background: var(--surface-2); margin: 8px 0 14px; } .due b { font-size: 26px; }
.modes { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
.chip { border: 1px solid var(--border); background: var(--surface); color: var(--text); padding: 8px 16px; border-radius: 999px; font: inherit; font-weight: 600; font-size: 14px; cursor: pointer; }
.chip.on { background: var(--primary); border-color: var(--primary); color: #fff; }
.pl { display: grid; gap: 6px; margin-bottom: 10px; padding: 12px; border: 1px solid var(--border); border-radius: 14px; } .pl label { font-weight: 700; }
.recap { margin-top: 8px; display: flex; flex-direction: column; gap: 6px; } .sum { display: flex; justify-content: space-between; }
.recap .change { font-size: 20px; color: var(--success); } .recap .credit { font-size: 18px; color: var(--danger); } .warn { margin: 0; color: var(--danger); font-size: 13px; }
@media (max-width: 560px) { .infos { grid-template-columns: 1fr; } }
</style>
