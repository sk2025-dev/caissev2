<script setup>
// Fiche fournisseur : ce qu'on lui doit vient de ses factures (saisies ici), jamais des réceptions de marchandises (confidentialité).
import { ref, reactive, computed, onMounted } from 'vue'
import { api, auth, ApiError } from '../api'
import { money, date, initiales, today } from '../format'
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

/* ---- Factures ---- */
const fac = reactive({ ouvert: false, montant: '', reference: '', date_facture: today(), note: '', busy: false, erreurs: {} })
function nouvelleFacture() { Object.assign(fac, { ouvert: true, montant: '', reference: '', date_facture: today(), note: '', erreurs: {} }) }
async function enregistrerFacture() {
  fac.busy = true; fac.erreurs = {}
  try {
    await api.post('facture-fournisseur', { idfour: f.value.idfour, montant: fac.montant, reference: fac.reference, date_facture: fac.date_facture, note: fac.note })
    toast('Facture enregistrée — la dette envers le fournisseur est à jour'); fac.ouvert = false; await charger()
  } catch (e) {
    if (e instanceof ApiError && Object.keys(e.errors).length) fac.erreurs = e.errors; else toast(e.message, 'error')
  } finally { fac.busy = false }
}
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
            <div v-for="x in d.factures" :key="x.idfacture" class="item">
              <span class="tile primary"><Icon name="file" :size="19" /></span>
              <div class="grow"><b>{{ x.reference || 'Facture sans n°' }}</b><span>{{ date(x.date_facture) }}<template v-if="x.note"> · {{ x.note }}</template></span></div>
              <b>{{ money(x.montant) }}</b>
              <button v-if="peutRetirer" class="btn sm" :class="aRetirer === x.idfacture ? 'danger' : 'ghost icon'" :aria-label="`Retirer la facture ${x.reference}`" :title="aRetirer === x.idfacture ? '' : 'Retirer (saisie erronée)'" @click="retirer(x)" @blur="aRetirer = null">
                <template v-if="aRetirer === x.idfacture">Confirmer</template><Icon v-else name="trash" :size="15" />
              </button>
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

    <Fenetre v-if="fac.ouvert" :titre="`Facture de ${f.nom}`" sous-titre="Le montant s'ajoute à ce que nous lui devons" icone="file" largeur="sm" formulaire :occupe="fac.busy" @close="fac.ouvert = false" @submit="enregistrerFacture">
        <div style="display:flex;flex-direction:column;gap:14px">
          <div class="field" :class="{ invalid: fac.erreurs.montant }"><label for="fac-m">Montant <span class="req">*</span></label><input id="fac-m" v-model="fac.montant" class="input big" type="number" min="1" inputmode="numeric" autofocus /><span v-if="fac.erreurs.montant" class="error">{{ fac.erreurs.montant }}</span></div>
          <div class="deux">
            <div class="field"><label for="fac-r">N° de facture</label><input id="fac-r" v-model="fac.reference" class="input" maxlength="60" /></div>
            <div class="field" :class="{ invalid: fac.erreurs.date_facture }"><label for="fac-d">Date</label><input id="fac-d" v-model="fac.date_facture" class="input" type="date" :max="today()" /><span v-if="fac.erreurs.date_facture" class="error">{{ fac.erreurs.date_facture }}</span></div>
          </div>
          <div class="field"><label for="fac-n">Note</label><input id="fac-n" v-model="fac.note" class="input" maxlength="300" placeholder="Facultatif" /></div>
          <p class="muted" style="margin:0;font-size:13px">Les réceptions de marchandises restent anonymes : elles ne sont jamais rattachées à un fournisseur.</p>
        </div>
        <template #pied><button type="button" class="btn" :disabled="fac.busy" @click="fac.ouvert = false">Annuler</button><button class="btn primary" :disabled="fac.busy"><Icon name="check" :size="16" /> {{ fac.busy ? 'Enregistrement…' : 'Enregistrer' }}</button></template>
    </Fenetre>
  </main>
</template>

<style scoped>
.actions { display: flex; flex-wrap: wrap; gap: 8px; position: relative; z-index: 1; }
.input.big { font-size: 20px; font-weight: 700; }
</style>
