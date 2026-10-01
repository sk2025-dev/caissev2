<script setup>
import { ref, computed, onMounted } from 'vue'
import { api, auth } from '../api'
import { money, dateHeure, initiales } from '../format'
import Icon from '../components/Icon.vue'
import ReglementModal from '../components/ReglementModal.vue'

const props = defineProps({ id: { type: String, required: true } })
const d = ref(null)
const error = ref('')
const regler = ref(false)
async function charger() { try { d.value = await api.get('client-detail', { id: props.id }) } catch (e) { error.value = e.message } }
onMounted(charger)
const c = computed(() => d.value?.client)
const peutRegler = computed(() => auth.user?.droits?.clients_ecriture && Number(c.value?.solde) > 0)
async function fait() { regler.value = false; await charger() }
</script>

<template>
  <main class="page">
    <RouterLink to="/clients" class="crumb"><Icon name="back" :size="16" /> Clients</RouterLink>
    <div v-if="error" class="alert">{{ error }}</div>
    <div v-else-if="!d" class="skeleton" style="height:260px" />
    <template v-else>
      <section class="card hero reveal">
        <span class="badge-xl">{{ initiales(c.nom) }}</span>
        <div class="who">
          <h1>{{ c.nom }}</h1>
          <div class="chips"><span v-if="c.telephone" class="badge">{{ c.telephone }}</span><span v-if="c.email" class="badge">{{ c.email }}</span><span v-if="Number(c.plafond_credit) > 0" class="badge primary">Plafond {{ money(c.plafond_credit) }}</span></div>
          <p v-if="c.adresse" class="muted" style="margin:6px 0 0">{{ c.adresse }}</p>
        </div>
        <div class="actions"><button v-if="peutRegler" class="btn primary" @click="regler = true"><Icon name="cash" :size="16" /> Encaisser un règlement</button></div>
      </section>

      <section class="stats">
        <article class="card stat reveal" style="--i:1"><div class="label">Dette en cours</div><div class="spacer" /><div class="value" :class="Number(c.solde) > 0 ? 'neg' : 'pos'">{{ money(c.solde) }}</div></article>
        <article class="card stat reveal" style="--i:2"><div class="label">Total acheté</div><div class="spacer" /><div class="value">{{ money(c.total_achats) }}</div><div class="sub">{{ c.nb_achats }} achat{{ c.nb_achats > 1 ? 's' : '' }}</div></article>
        <article class="card stat reveal" style="--i:3"><div class="label">Dernier achat</div><div class="spacer" /><div class="value" style="font-size:20px">{{ dateHeure(c.dernier_achat) }}</div></article>
      </section>

      <div class="grid-even">
        <section class="card card-pad reveal" style="--i:4">
          <div class="card-head"><h2>Derniers achats</h2><RouterLink :to="{ path: '/ventes', query: { q: c.nom } }" class="btn sm ghost">Voir les ventes</RouterLink></div>
          <div v-if="!d.ventes.length" class="muted">Aucun achat.</div>
          <div class="list">
            <div v-for="v in d.ventes" :key="v.idvente" class="item">
              <span class="tile" :class="v.statut === 'annulee' ? 'danger' : 'success'"><Icon name="receipt" :size="19" /></span>
              <div class="grow"><b>{{ v.numero }}</b><span>{{ dateHeure(v.date_vente) }}<template v-if="v.statut === 'annulee'"> · annulée</template></span></div>
              <b>{{ money(v.total) }}</b><span v-if="Number(v.reste) > 0 && v.statut !== 'annulee'" class="badge danger">reste {{ money(v.reste) }}</span>
            </div>
          </div>
        </section>
        <section class="card card-pad reveal" style="--i:5">
          <h2>Règlements</h2>
          <div v-if="!d.reglements.length" class="muted">Aucun règlement enregistré.</div>
          <div class="list">
            <div v-for="r in d.reglements" :key="r.idreg" class="item">
              <span class="tile success"><Icon name="cash" :size="19" /></span>
              <div class="grow"><b>{{ money(r.montant) }}</b><span>{{ dateHeure(r.created_at) }} · {{ r.mode }}<template v-if="r.reference"> · {{ r.reference }}</template> · {{ (r.utilisateur || '').trim() }}</span></div>
            </div>
          </div>
        </section>
      </div>
    </template>
    <ReglementModal v-if="regler" type="client" :id="c.idclient" :nom="c.nom" :solde="c.solde" @close="regler = false" @done="fait" />
  </main>
</template>
