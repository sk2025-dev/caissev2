<script setup>
// Règlement d'une dette client (encaissement) ou fournisseur (paiement) : partiel possible, jamais supérieur au solde.
import { reactive, onMounted } from 'vue'
import { api, ApiError } from '../api'
import { lookups, loadLookups } from '../lookups'
import { money } from '../format'
import { toast } from '../toast'
import Icon from './Icon.vue'

const props = defineProps({ type: { type: String, required: true }, id: { type: [Number, String], required: true }, nom: String, solde: { type: [Number, String], required: true } })
const emit = defineEmits(['close', 'done'])
const f = reactive({ montant: Number(props.solde) || '', mode: 'especes', reference: '', note: '', busy: false, erreurs: {} })
onMounted(() => loadLookups().catch(() => {}))

async function envoyer() {
  f.busy = true; f.erreurs = {}
  try {
    const r = await api.post('reglement-creer', { tiers_type: props.type, tiers_id: props.id, montant: f.montant, mode: f.mode, reference: f.reference, note: f.note })
    toast(props.type === 'client' ? 'Règlement encaissé' : 'Paiement fournisseur enregistré')
    emit('done', r.reglement)
  } catch (e) {
    if (e instanceof ApiError && Object.keys(e.errors).length) f.erreurs = e.errors; else toast(e.message, 'error')
  } finally { f.busy = false }
}
</script>

<template>
  <div class="overlay center" @click.self="emit('close')" @keydown.esc="emit('close')">
    <form class="modal" novalidate role="dialog" aria-modal="true" :aria-label="type === 'client' ? 'Encaisser un règlement' : 'Payer le fournisseur'" @submit.prevent="envoyer">
      <h3>{{ type === 'client' ? 'Encaisser un règlement' : 'Payer le fournisseur' }}</h3>
      <p class="muted" style="margin:0">{{ nom }} — {{ type === 'client' ? 'doit' : 'nous lui devons' }} <b>{{ money(solde) }}</b></p>
      <div style="display:flex;flex-direction:column;gap:14px;margin-top:14px">
        <div class="field" :class="{ invalid: f.erreurs.montant }"><label for="rg-m">Montant</label><input id="rg-m" v-model="f.montant" class="input big" type="number" min="1" :max="solde" autofocus /><span v-if="f.erreurs.montant" class="error">{{ f.erreurs.montant }}</span></div>
        <div class="field"><label>Mode de paiement</label><div class="seg wrap"><button v-for="m in lookups.modes" :key="m.code" type="button" :class="{ on: f.mode === m.code }" @click="f.mode = m.code">{{ m.libelle }}</button></div></div>
        <div v-if="f.mode !== 'especes'" class="field"><label for="rg-r">Référence</label><input id="rg-r" v-model="f.reference" class="input" maxlength="80" /></div>
        <p v-if="type === 'client' && f.mode === 'especes'" class="muted" style="margin:0;font-size:13px">Les espèces sont comptées dans votre caisse ouverte (si vous en avez une).</p>
      </div>
      <div class="row"><button type="button" class="btn" @click="emit('close')">Annuler</button><button class="btn primary" :disabled="f.busy"><Icon name="check" :size="16" /> Valider</button></div>
    </form>
  </div>
</template>

<style scoped>
.input.big { font-size: 20px; font-weight: 700; }
.seg.wrap { flex-wrap: wrap; }
</style>
