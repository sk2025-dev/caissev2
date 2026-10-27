<script setup>
// Fiche fournisseur : ce qu'on lui doit vient de ses factures (saisies ici), jamais des réceptions de marchandises (confidentialité).
import { ref, reactive, computed, onMounted } from 'vue'
import { api, auth, ApiError } from '../api'
import { money, qty, date, initiales, today } from '../format'
import { toast } from '../toast'
import Icon from '../components/Icon.vue'
import HistoriqueFiche from '../components/HistoriqueFiche.vue'
import ReglementModal from '../components/ReglementModal.vue'
import Fenetre from '../components/Fenetre.vue'

const props = defineProps({ id: { type: String, required: true } })
const d = ref(null)
const error = ref('')
const regler = ref(false)
async function charger() { try { d.value = await api.get('fournisseur-detail', { id: props.id }) } catch (e) { error.value = e.message } }
onMounted(charger)
const f = computed(() => d.value?.fournisseur)
const peutRegler = computed(() => auth.user?.droits?.achats && Number(f.value?.solde) > 0)
const peutFacturer = computed(() => !!auth.user?.droits?.achats)
const peutRetirer = computed(() => !!(auth.user?.admin || auth.user?.super))
async function fait() { regler.value = false; await charger() }

/* ---- Factures : désignation libre / quantité / prix (ou montant global), sans lien avec le catalogue ---- */
const ligneVide = () => ({ designation: '', quantite: '', prix_unitaire: '' })
const fac = reactive({ ouvert: false, detail: true, lignes: [], montant: '', reference: '', date_facture: today(), note: '', busy: false, erreurs: {} })
const libelles = ref([])   // désignations déjà saisies sur des factures (ce fournisseur d'abord), avec le dernier prix
function nouvelleFacture() {
  Object.assign(fac, { ouvert: true, detail: true, lignes: [ligneVide()], montant: '', reference: '', date_facture: today(), note: '', erreurs: {} })
  api.get('factures-libelles', { id: f.value.idfour }).then((r) => { libelles.value = r.libelles }).catch(() => {})
}
// Désignation connue : reprend le dernier prix si aucun n'est saisi. Une ligne vide reste toujours prête en bas de la liste.
function saisie(l) {
  const connu = libelles.value.find((x) => x.designation.toLowerCase() === l.designation.trim().toLowerCase())
  if (connu && l.prix_unitaire === '') l.prix_unitaire = connu.prix
  if (connu && l.quantite === '') l.quantite = 1
  if (l === fac.lignes[fac.lignes.length - 1] && l.designation.trim()) fac.lignes.push(ligneVide())
}
const totalLigne = (l) => (Number(l.quantite) || 0) * (Number(l.prix_unitaire) || 0)
const lignesSaisies = computed(() => fac.lignes.filter((l) => l.designation.trim() || l.quantite !== '' || l.prix_unitaire !== ''))
const totalFacture = computed(() => (fac.detail ? lignesSaisies.value.reduce((s, l) => s + totalLigne(l), 0) : Number(fac.montant) || 0))
function retirerLigne(i) { fac.lignes.splice(i, 1); if (!fac.lignes.length) fac.lignes.push(ligneVide()) }
async function enregistrerFacture() {
  fac.busy = true; fac.erreurs = {}
  try {
    const lignes = fac.detail ? lignesSaisies.value.map((l) => ({ designation: l.designation.trim(), quantite: l.quantite, prix_unitaire: l.prix_unitaire })) : []
    if (fac.detail && !lignes.length) { fac.erreurs = { lignes: 'Saisissez au moins une ligne, ou passez en montant global' }; return }
    await api.post('facture-fournisseur', { idfour: f.value.idfour, lignes, montant: fac.detail ? '' : fac.montant, reference: fac.reference, date_facture: fac.date_facture, note: fac.note })
    toast('Facture enregistrée — la dette envers le fournisseur est à jour'); fac.ouvert = false; await charger()
  } catch (e) {
    if (e instanceof ApiError && Object.keys(e.errors).length) fac.erreurs = e.errors; else toast(e.message, 'error')
  } finally { fac.busy = false }
}
const ouverte = ref(null)   // facture dont le détail est déplié
const aRetirer = ref(null)   // retrait en deux temps : « Retirer » puis « Confirmer »
async function retirer(x) {
  if (aRetirer.value !== x.idfacture) { aRetirer.value = x.idfacture; return }
  try { await api.del('facture-fournisseur', x.idfacture); toast('Facture retirée'); aRetirer.value = null; await charger() } catch (e) { toast(e.message, 'error') }
}
</script>

<template>
  <main class="page">
    <RouterLink to="/fournisseurs" class="crumb"><Icon name="back" :size="16" /> Fournisseurs</RouterLink>
    <div v-if="error" class="alert">{{ error }}</div>
    <div v-else-if="!d" class="skeleton" style="height:260px" />
    <template v-else>
      <section class="card hero reveal">
        <span class="badge-xl">{{ initiales(f.nom) }}</span>
        <div class="who">
          <h1>{{ f.nom }}</h1>
          <div class="chips"><span v-if="f.contact" class="badge">{{ f.contact }}</span><span v-if="f.telephone" class="badge">{{ f.telephone }}</span><span v-if="f.email" class="badge">{{ f.email }}</span></div>
          <p v-if="f.adresse" class="muted" style="margin:6px 0 0">{{ f.adresse }}</p>
        </div>
        <div class="actions">
          <button v-if="peutFacturer" class="btn" @click="nouvelleFacture"><Icon name="file" :size="16" /> Enregistrer une facture</button>
          <button v-if="peutRegler" class="btn primary" @click="regler = true"><Icon name="cash" :size="16" /> Payer le fournisseur</button>
        </div>
      </section>

      <section class="stats">
        <article class="card stat reveal" style="--i:1"><div class="label">Nous devons</div><div class="spacer" /><div class="value" :class="Number(f.solde) > 0 ? 'neg' : 'pos'">{{ money(f.solde) }}</div></article>
        <article class="card stat reveal" style="--i:2"><div class="label">Total acheté</div><div class="spacer" /><div class="value">{{ money(f.total_achats) }}</div><div class="sub">{{ f.nb_factures }} facture{{ f.nb_factures > 1 ? 's' : '' }}</div></article>
      </section>

      <div class="grid-even">
        <section class="card card-pad reveal" style="--i:3">
          <div class="card-head"><h2>Factures</h2><button v-if="peutFacturer" class="btn sm ghost" @click="nouvelleFacture"><Icon name="plus" :size="14" /> Ajouter</button></div>
          <div v-if="!d.factures.length" class="muted">Aucune facture enregistrée.</div>
          <div class="list">
            <div v-for="x in d.factures" :key="x.idfacture" class="item facture" :class="{ ouverte: ouverte === x.idfacture }">
              <span class="tile primary"><Icon name="file" :size="19" /></span>
              <div class="grow"><b>{{ x.reference || 'Facture sans n°' }}</b><span>{{ date(x.date_facture) }}<template v-if="x.lignes.length"> · {{ x.lignes.length }} article{{ x.lignes.length > 1 ? 's' : '' }}</template><template v-if="x.note"> · {{ x.note }}</template></span></div>
              <b>{{ money(x.montant) }}</b>
              <button v-if="x.lignes.length" class="btn sm ghost icon" :aria-expanded="ouverte === x.idfacture" :aria-label="`Détail de la facture ${x.reference}`" :title="ouverte === x.idfacture ? 'Masquer le détail' : 'Voir le détail'" @click="ouverte = ouverte === x.idfacture ? null : x.idfacture"><Icon :name="ouverte === x.idfacture ? 'up' : 'down'" :size="15" /></button>
              <button v-if="peutRetirer" class="btn sm" :class="aRetirer === x.idfacture ? 'danger' : 'ghost icon'" :aria-label="`Retirer la facture ${x.reference}`" :title="aRetirer === x.idfacture ? '' : 'Retirer (saisie erronée)'" @click="retirer(x)" @blur="aRetirer = null">
                <template v-if="aRetirer === x.idfacture">Confirmer</template><Icon v-else name="trash" :size="15" />
              </button>
              <table v-if="ouverte === x.idfacture" class="detail-facture">
                <tbody><tr v-for="(l, i) in x.lignes" :key="i"><td>{{ l.designation }}</td><td class="num">{{ qty(l.quantite) }} × {{ money(l.prix_unitaire) }}</td><td class="num">{{ money(l.total) }}</td></tr></tbody>
              </table>
            </div>
          </div>
        </section>
        <section class="card card-pad reveal" style="--i:4">
          <h2>Paiements</h2>
          <div v-if="!d.reglements.length" class="muted">Aucun paiement enregistré.</div>
          <div class="list">
            <div v-for="r in d.reglements" :key="r.idreg" class="item">
              <span class="tile success"><Icon name="cash" :size="19" /></span>
              <div class="grow"><b>{{ money(r.montant) }}</b><span>{{ date(r.created_at) }} · {{ r.mode }}<template v-if="r.note"> · {{ r.note }}</template></span></div>
            </div>
          </div>
        </section>
      </div>
      <HistoriqueFiche entite="fournisseurs" :id="id" style="margin-top:18px" />
    </template>
    <ReglementModal v-if="regler" type="fournisseur" :id="f.idfour" :nom="f.nom" :solde="f.solde" @close="regler = false" @done="fait" />

    <Fenetre v-if="fac.ouvert" :titre="`Facture de ${f.nom}`" sous-titre="Le total s'ajoute à ce que nous lui devons" icone="file" :largeur="780" formulaire :occupe="fac.busy" @close="fac.ouvert = false" @submit="enregistrerFacture">
        <div class="facture-form">
          <div class="trois">
            <div class="field"><label for="fac-r">N° de facture</label><input id="fac-r" v-model="fac.reference" class="input" maxlength="60" autofocus /></div>
            <div class="field" :class="{ invalid: fac.erreurs.date_facture }"><label for="fac-d">Date</label><input id="fac-d" v-model="fac.date_facture" class="input" type="date" :max="today()" /><span v-if="fac.erreurs.date_facture" class="error">{{ fac.erreurs.date_facture }}</span></div>
            <div class="field"><label>Saisie</label><div class="seg"><button type="button" :class="{ on: fac.detail }" @click="fac.detail = true">Produits</button><button type="button" :class="{ on: !fac.detail }" @click="fac.detail = false">Montant global</button></div></div>
          </div>

          <div v-if="fac.detail" class="field" :class="{ invalid: fac.erreurs.lignes }">
            <div class="fline tete" aria-hidden="true"><span>Désignation</span><span>Quantité</span><span>Prix unitaire</span><span>Total</span><span /></div>
            <div v-for="(l, i) in fac.lignes" :key="i" class="fline">
              <input v-model="l.designation" class="input" maxlength="150" list="fac-libelles" autocomplete="off" placeholder="Saisir ou choisir…" :aria-label="`Désignation ligne ${i + 1}`" @input="saisie(l)" />
              <input v-model="l.quantite" class="input" type="number" min="0" step="any" inputmode="decimal" placeholder="Qté" :aria-label="`Quantité ligne ${i + 1}`" />
              <input v-model="l.prix_unitaire" class="input" type="number" min="0" step="any" inputmode="numeric" placeholder="Prix" :aria-label="`Prix unitaire ligne ${i + 1}`" />
              <b class="lt">{{ totalLigne(l) ? money(totalLigne(l)) : '' }}</b>
              <button type="button" class="btn ghost icon sm" :aria-label="`Retirer la ligne ${i + 1}`" :disabled="fac.lignes.length === 1 && !lignesSaisies.includes(l)" @click="retirerLigne(i)"><Icon name="x" :size="15" /></button>
            </div>
            <datalist id="fac-libelles"><option v-for="x in libelles" :key="x.designation" :value="x.designation">{{ money(x.prix) }}</option></datalist>
            <span v-if="fac.erreurs.lignes" class="error">{{ fac.erreurs.lignes }}</span>
            <small class="hint">Saisie libre, sans lien avec le catalogue{{ libelles.length ? ' : les désignations déjà utilisées sont proposées pendant la frappe, avec leur dernier prix' : '' }}. Le stock n'est pas modifié : les entrées de marchandises passent par les réceptions.</small>
          </div>
          <div v-else class="field" :class="{ invalid: fac.erreurs.montant }"><label for="fac-m">Montant <span class="req">*</span></label><input id="fac-m" v-model="fac.montant" class="input big" type="number" min="1" inputmode="numeric" /><span v-if="fac.erreurs.montant" class="error">{{ fac.erreurs.montant }}</span></div>

          <div class="field"><label for="fac-n">Note</label><input id="fac-n" v-model="fac.note" class="input" maxlength="300" placeholder="Facultatif" /></div>
        </div>
        <template #pied>
          <span class="grow total-pied">Total <b>{{ money(totalFacture) }}</b></span>
          <button type="button" class="btn" :disabled="fac.busy" @click="fac.ouvert = false">Annuler</button>
          <button class="btn primary" :disabled="fac.busy || totalFacture <= 0"><Icon name="check" :size="16" /> {{ fac.busy ? 'Enregistrement…' : 'Enregistrer la facture' }}</button>
        </template>
    </Fenetre>
  </main>
</template>

<style scoped>
.actions { display: flex; flex-wrap: wrap; gap: 8px; position: relative; z-index: 1; }
.input.big { font-size: 20px; font-weight: 700; }
.facture { flex-wrap: wrap; }
.detail-facture { width: 100%; margin: 4px 0 2px 52px; border-collapse: collapse; font-size: 13.5px; }
.detail-facture td { padding: 5px 4px; border-top: 1px dashed var(--border); } .detail-facture .num { text-align: right; white-space: nowrap; }
.facture-form { display: flex; flex-direction: column; gap: 16px; }
.trois { display: grid; grid-template-columns: 1fr 1fr auto; gap: 12px; align-items: start; }
.fline { display: grid; grid-template-columns: minmax(0, 1fr) 90px 120px 110px 34px; gap: 8px; align-items: center; margin-bottom: 8px; }
.fline.tete { margin-bottom: 4px; color: var(--muted); font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
.fline .lt { text-align: right; white-space: nowrap; }
@media (max-width: 640px) {
  .trois { grid-template-columns: 1fr 1fr; } .trois > :last-child { grid-column: 1 / -1; }
  .fline { grid-template-columns: 1fr 1fr 34px; padding: 10px; border: 1px solid var(--border); border-radius: 14px; }
  .fline > :first-child { grid-column: 1 / -1; } .fline .lt { grid-column: 1 / 3; grid-row: 3; text-align: left; } .fline > button { grid-row: 2; grid-column: 3; }
  .fline.tete { display: none; }
}
</style>
