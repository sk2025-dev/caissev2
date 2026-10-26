<script setup>
// Traçabilité d'une fiche (produit, client, fournisseur) : dernières actions du journal, réservé au gérant.
import { ref, onMounted } from 'vue'
import { api, auth } from '../api'
import { dateHeure } from '../format'
import { ACTIONS, ton, icone, lignesDetail } from '../journal'
import Icon from './Icon.vue'

const props = defineProps({
  entite: { type: String, required: true },      // « produits,stock » : plusieurs entités possibles
  id: { type: [String, Number], required: true },
})
const lignes = ref(null)
const total = ref(0)
onMounted(async () => {
  if (!auth.user?.admin) return
  try { const r = await api.get('journal', { entite: props.entite, entite_id: props.id, per_page: 8 }); lignes.value = r.data; total.value = r.meta.total } catch { lignes.value = [] }
})
const changes = (e) => lignesDetail(e).changements.filter((c) => c.avant !== '••••').map((c) => `${c.champ} : ${c.avant} → ${c.apres}`).join(' · ')
</script>

<template>
  <section v-if="auth.user?.admin" class="card card-pad reveal">
    <div class="card-head">
      <h2>Historique et traçabilité</h2>
      <RouterLink v-if="total > (lignes?.length || 0)" :to="{ path: '/journal', query: { entite, entite_id: String(id) } }" class="btn sm ghost">Tout l'historique ({{ total }})</RouterLink>
    </div>
    <div v-if="!lignes" class="skeleton" style="height:80px" />
    <div v-else-if="!lignes.length" class="muted">Aucune action enregistrée sur cette fiche.</div>
    <div v-else class="list">
      <div v-for="e in lignes" :key="e.id" class="item">
        <span class="tile" :class="ton(e.action)"><Icon :name="icone(e.module)" :size="19" /></span>
        <div class="grow">
          <b>{{ e.resume || ACTIONS[e.action] || e.action }}</b>
          <span>{{ dateHeure(e.created_at) }} · {{ e.acteur }}</span>
          <span v-if="e.action === 'update' && changes(e)" class="chg">{{ changes(e) }}</span>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.grow { display: flex; flex-direction: column; }
.grow span { color: var(--muted); font-size: 13px; }
.grow .chg { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>
