<script setup>
import { ref, computed, onMounted } from 'vue'
import { api, fileUrl } from '../api'
import { money, qty, dateHeure } from '../format'
import Icon from '../components/Icon.vue'

const props = defineProps({ id: { type: String, required: true } })
const d = ref(null)
const error = ref('')
onMounted(async () => { try { d.value = await api.get('produit-detail', { id: props.id }) } catch (e) { error.value = e.message } })
const p = computed(() => d.value?.produit)
const service = computed(() => p.value && !Number(p.value.stockable))
const etat = computed(() => {
  if (!p.value || service.value) return null
  const q = Number(p.value.stock_qty)
  return q <= 0 ? ['danger', 'En rupture'] : Number(p.value.seuil_alerte) > 0 && q <= Number(p.value.seuil_alerte) ? ['warning', 'Stock bas'] : ['success', 'Stock correct']
})
const marge = computed(() => (p.value && Number(p.value.prix_vente) > 0 ? Math.round(((p.value.prix_vente - p.value.prix_achat) / p.value.prix_vente) * 100) : 0))
</script>

<template>
  <main class="page">
    <RouterLink to="/produits" class="crumb"><Icon name="back" :size="16" /> Produits et services</RouterLink>
    <div v-if="error" class="alert">{{ error }}</div>
    <div v-else-if="!d" class="skeleton" style="height:260px" />
    <template v-else>
      <section class="card hero reveal">
        <span class="badge-xl"><img v-if="p.image" :src="fileUrl('produits', p.image)" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:inherit" /><Icon v-else :name="service ? 'clipboard' : 'box'" :size="34" /></span>
        <div class="who">
          <h1>{{ p.nom }}</h1>
          <div class="chips">
            <span class="badge primary">{{ service ? 'Service' : 'Produit' }}</span>
            <span v-if="p.categorie" class="badge">{{ p.categorie }}</span>
            <span v-if="p.sku" class="badge">Réf. {{ p.sku }}</span>
            <span v-if="p.code_barres" class="badge">{{ p.code_barres }}</span>
            <span v-if="etat" class="badge" :class="etat[0]">{{ etat[1] }}</span>
            <span v-if="!Number(p.actif)" class="badge">Masqué en caisse</span>
          </div>
          <p v-if="p.description" class="muted" style="margin:8px 0 0">{{ p.description }}</p>
        </div>
      </section>

      <section class="stats">
        <article class="card stat reveal" style="--i:1"><div class="label">Prix de vente</div><div class="spacer" /><div class="value">{{ money(p.prix_vente) }}</div><div class="sub"><span v-if="Number(p.tva_taux) > 0">TVA {{ Number(p.tva_taux) }} % incluse</span><span v-else>Sans TVA</span></div></article>
        <article v-if="!service" class="card stat reveal" style="--i:2"><div class="label">En stock</div><div class="spacer" /><div class="value">{{ qty(p.stock_qty) }}<span class="unit">{{ p.unite }}</span></div><div class="sub"><span v-if="d.jours_restants !== null">≈ {{ d.jours_restants }} jour{{ d.jours_restants > 1 ? 's' : '' }} de vente</span><span v-else>Valeur {{ money(p.valeur_stock) }}</span></div></article>
        <article v-if="!service" class="card stat reveal" style="--i:3"><div class="label">Coût moyen / marge</div><div class="spacer" /><div class="value">{{ money(p.prix_achat) }}</div><div class="sub"><span class="delta" :class="marge >= 0 ? 'up' : 'down'">{{ marge }} %</span><span>de marge brute</span></div></article>
        <article class="card stat reveal" style="--i:4"><div class="label">Ventes (30 jours)</div><div class="spacer" /><div class="value">{{ money(d.ventes_30j.ca) }}</div><div class="sub"><span>{{ qty(d.ventes_30j.qte) }} vendu{{ d.ventes_30j.qte > 1 ? 's' : '' }} · {{ d.ventes_30j.nb_ventes }} ticket{{ d.ventes_30j.nb_ventes > 1 ? 's' : '' }}</span></div></article>
      </section>

      <section v-if="!service" class="card card-pad reveal" style="--i:5">
        <div class="card-head"><h2>Derniers mouvements de stock</h2><RouterLink to="/stock" class="btn sm ghost">Tout le stock</RouterLink></div>
        <div v-if="!d.mouvements.length" class="muted">Aucun mouvement.</div>
        <div class="list">
          <div v-for="m in d.mouvements" :key="m.idmvt" class="item">
            <span class="tile" :class="Number(m.quantite) >= 0 ? 'success' : 'warning'"><Icon :name="Number(m.quantite) >= 0 ? 'trend-up' : 'trend-down'" :size="19" /></span>
            <div class="grow"><b>{{ m.libelle_type }}</b><span>{{ dateHeure(m.created_at) }}<template v-if="m.motif"> · {{ m.motif }}</template> · {{ m.utilisateur }}</span></div>
            <b :class="Number(m.quantite) >= 0 ? 'pos' : 'neg'">{{ Number(m.quantite) > 0 ? '+' : '' }}{{ qty(m.quantite) }}</b>
            <span class="muted">→ {{ qty(m.stock_apres) }}</span>
          </div>
        </div>
      </section>
    </template>
  </main>
</template>
