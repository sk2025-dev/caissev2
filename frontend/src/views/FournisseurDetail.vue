<script setup>
import { ref, computed, onMounted } from 'vue'
import { api, auth } from '../api'
import { money, date, initiales } from '../format'
import Icon from '../components/Icon.vue'
import ReglementModal from '../components/ReglementModal.vue'

const props = defineProps({ id: { type: String, required: true } })
const d = ref(null)
const error = ref('')
const regler = ref(false)
async function charger() { try { d.value = await api.get('fournisseur-detail', { id: props.id }) } catch (e) { error.value = e.message } }
onMounted(charger)
const f = computed(() => d.value?.fournisseur)
const peutRegler = computed(() => auth.user?.droits?.achats && Number(f.value?.solde) > 0)
async function fait() { regler.value = false; await charger() }
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
        <div class="actions"><button v-if="peutRegler" class="btn primary" @click="regler = true"><Icon name="cash" :size="16" /> Payer le fournisseur</button></div>
      </section>

      <section class="stats">
        <article class="card stat reveal" style="--i:1"><div class="label">Nous devons</div><div class="spacer" /><div class="value" :class="Number(f.solde) > 0 ? 'neg' : 'pos'">{{ money(f.solde) }}</div></article>
        <article class="card stat reveal" style="--i:2"><div class="label">Total acheté</div><div class="spacer" /><div class="value">{{ money(f.total_achats) }}</div><div class="sub">{{ f.nb_appros }} réception{{ f.nb_appros > 1 ? 's' : '' }}</div></article>
      </section>

      <div class="grid-even">
        <section class="card card-pad reveal" style="--i:3">
          <div class="card-head"><h2>Dernières réceptions</h2><RouterLink to="/appros" class="btn sm ghost">Toutes</RouterLink></div>
          <div v-if="!d.appros.length" class="muted">Aucune réception.</div>
          <div class="list">
            <div v-for="a in d.appros" :key="a.idappro" class="item">
              <span class="tile primary"><Icon name="truck" :size="19" /></span>
              <div class="grow"><b>{{ a.numero }}</b><span>{{ date(a.date_appro) }} · {{ a.nb_lignes }} ligne{{ a.nb_lignes > 1 ? 's' : '' }}</span></div>
              <b>{{ money(a.total) }}</b><span v-if="Number(a.reste) > 0" class="badge warning">reste {{ money(a.reste) }}</span>
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
    </template>
    <ReglementModal v-if="regler" type="fournisseur" :id="f.idfour" :nom="f.nom" :solde="f.solde" @close="regler = false" @done="fait" />
  </main>
</template>
