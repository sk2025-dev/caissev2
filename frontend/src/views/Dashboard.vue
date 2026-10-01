<script setup>
import { ref, computed, onMounted } from 'vue'
import { api, auth, prenomDe } from '../api'
import { money, number, qty, dateHeure, alerteLibelle, uniteAccord } from '../format'
import Icon from '../components/Icon.vue'
import Illus from '../components/Illus.vue'
import CountUp from '../components/CountUp.vue'
import SalesChart from '../components/SalesChart.vue'
import DonutChart from '../components/DonutChart.vue'
import MonthlyJournal from '../components/MonthlyJournal.vue'
import StockBalance from '../components/StockBalance.vue'

const d = ref(null)
const alertes = ref([])
const error = ref('')
const rep = ref('categories')
const toutesAlertes = ref(false)
onMounted(async () => {
  try {
    d.value = await api.get('dashboard')
    alertes.value = (await api.get('alertes')).alertes
  } catch (e) { error.value = e.message }
})

const gerant = computed(() => !!auth.user?.droits?.ventes_toutes)
const greeting = computed(() => {
  const h = new Date().getHours()
  return `${h < 5 || h >= 18 ? 'Bonsoir' : 'Bonjour'} ${prenomDe(auth.user)}`
})
const jourLabel = new Date().toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })
const fmt = (v) => number(Math.round(v))
const perf = ref('montant')   // performance des produits : 'montant' | 'nombre'
const topListe = computed(() => (perf.value === 'nombre' ? d.value?.top_quantite : d.value?.top_produits) || [])
const valeurTop = (p) => (perf.value === 'nombre' ? p.quantite : p.ca)
const maxTop = computed(() => Math.max(1, ...topListe.value.map(valeurTop)))
const maxHeure = computed(() => Math.max(1, ...(d.value?.heures || []).map((h) => h.ca)))
const heuresUtiles = computed(() => (d.value?.heures || []).filter((h) => h.h >= 6 && h.h <= 22))
const repartition = computed(() => d.value?.[rep.value])
const delta = (v) => (v === null || v === undefined ? null : v)
</script>

<template>
  <main class="page">
    <div class="page-head reveal">
      <div>
        <h1>{{ greeting }} <span class="wave">👋</span></h1>
        <p style="text-transform:capitalize">{{ jourLabel }}<template v-if="d?.session"> — <span style="text-transform:none">caisse « {{ d.session.caisse }} » ouverte</span></template></p>
      </div>
      <RouterLink v-if="auth.user?.droits?.caisse" to="/caisse" class="btn primary" style="text-decoration:none"><Icon name="cart" /> {{ d?.session ? 'Reprendre la caisse' : 'Ouvrir la caisse' }}</RouterLink>
    </div>

    <div v-if="error" class="alert">{{ error }}</div>
    <div v-else-if="!d" class="stats"><div v-for="n in 4" :key="n" class="card stat"><div class="skeleton" style="height:90px" /></div></div>

    <template v-else>
      <section class="stats" aria-label="Indicateurs">
        <template v-if="gerant">
          <article class="card stat lift reveal" style="--i:1">
            <span class="art"><Illus name="sack" :size="72" /></span>
            <div class="label">Ventes du jour</div><div class="spacer" />
            <div class="value"><CountUp :value="d.ventes.jour.ca" :format="fmt" /><span class="unit">FCFA</span></div>
            <div class="sub">
              <span v-if="delta(d.ventes.delta_jour) !== null" class="delta" :class="d.ventes.delta_jour >= 0 ? 'up' : 'down'">{{ d.ventes.delta_jour >= 0 ? '↑' : '↓' }} {{ Math.abs(d.ventes.delta_jour) }} %</span>
              <span>{{ d.ventes.jour.nb }} vente{{ d.ventes.jour.nb > 1 ? 's' : '' }}<template v-if="delta(d.ventes.delta_jour) !== null"> · vs hier</template></span>
            </div>
          </article>
          <article class="card stat lift reveal" style="--i:2">
            <span class="art"><Illus name="coins" :size="72" /></span>
            <div class="label">Ventes du mois</div><div class="spacer" />
            <div class="value"><CountUp :value="d.ventes.mois.ca" :format="fmt" /><span class="unit">FCFA</span></div>
            <div class="sub">
              <span v-if="delta(d.ventes.delta_mois) !== null" class="delta" :class="d.ventes.delta_mois >= 0 ? 'up' : 'down'">{{ d.ventes.delta_mois >= 0 ? '↑' : '↓' }} {{ Math.abs(d.ventes.delta_mois) }} %</span>
              <span>marge {{ money(d.ventes.mois.marge) }}</span>
            </div>
          </article>
          <article class="card stat lift reveal" style="--i:3">
            <span class="art"><Illus name="wallet" :size="72" /></span>
            <div class="label">Créances clients</div><div class="spacer" />
            <div class="value" :class="d.tiers.creances > 0 ? 'neg' : ''"><CountUp :value="d.tiers.creances" :format="fmt" /><span class="unit">FCFA</span></div>
            <div class="sub"><span>{{ d.tiers.nb_debiteurs }} client{{ d.tiers.nb_debiteurs > 1 ? 's' : '' }} débiteur{{ d.tiers.nb_debiteurs > 1 ? 's' : '' }} · dettes fournisseurs {{ money(d.tiers.dettes) }}</span></div>
          </article>
        </template>
        <article v-else class="card stat lift reveal" style="--i:1">
          <span class="art"><Illus name="sack" :size="72" /></span>
          <div class="label">Mes ventes du jour</div><div class="spacer" />
          <div class="value"><CountUp :value="d.moi.ca" :format="fmt" /><span class="unit">FCFA</span></div>
          <div class="sub"><span>{{ d.moi.nb }} vente{{ d.moi.nb > 1 ? 's' : '' }} aujourd'hui</span></div>
        </article>

        <article v-if="d.stock" class="card stat lift reveal" style="--i:4">
          <span class="art"><Illus name="box" :size="72" /></span>
          <div class="label">Valeur du stock</div><div class="spacer" />
          <div class="value"><CountUp :value="d.stock.valeur" :format="fmt" /><span class="unit">FCFA</span></div>
          <div class="sub"><span>{{ d.stock.nb_produits }} produits · {{ d.stock.nb_services }} services</span>
            <span v-if="d.stock.ruptures" class="badge danger">{{ d.stock.ruptures }} rupture{{ d.stock.ruptures > 1 ? 's' : '' }}</span>
            <span v-if="d.stock.bas" class="badge warning">{{ d.stock.bas }} bas</span></div>
        </article>
      </section>

      <div v-if="gerant" class="grid-2">
        <section class="card card-pad reveal" style="--i:5">
          <div class="card-head">
            <h2>Chiffre d'affaires — 30 derniers jours</h2>
            <div class="legend" style="margin:0"><span><i style="background:var(--primary)" />Ventes</span><span><i style="background:var(--success)" />Marge</span></div>
          </div>
          <SalesChart :data="d.serie" />
        </section>

        <section class="card card-pad reveal" style="--i:6">
          <div class="card-head"><h2>À surveiller</h2><span v-if="alertes.length" class="badge warning">{{ alertes.length }}</span></div>
          <div v-if="!alertes.length" class="muted">Rien à signaler. 🎉</div>
          <div class="list">
            <RouterLink v-for="a in toutesAlertes ? alertes : alertes.slice(0, 6)" :key="a.type + a.id" class="item" :to="a.type === 'ecart_caisse' ? '/sessions' : a.type === 'creances' ? `/clients/${a.id}` : a.id ? `/produits/${a.id}` : '/'" style="text-decoration:none;color:inherit">
              <span class="tile" :class="a.niveau"><Icon name="alert" :size="19" /></span>
              <div class="grow"><b>{{ a.label }}</b><span>{{ alerteLibelle(a.type) }}<template v-if="a.detail"> · {{ a.detail }}</template></span></div>
            </RouterLink>
          </div>
          <button v-if="alertes.length > 6" class="btn sm ghost voir-tout" @click="toutesAlertes = !toutesAlertes">{{ toutesAlertes ? 'Afficher moins' : `Voir les ${alertes.length} alertes` }}</button>
        </section>
      </div>

      <MonthlyJournal v-if="gerant" class="reveal" style="--i:7" />

      <div v-if="gerant" class="grid-even">
        <section class="card card-pad col reveal" style="--i:7">
          <div class="card-head">
            <h2>Répartition du mois</h2>
            <div class="seg" role="tablist">
              <button :class="{ on: rep === 'categories' }" role="tab" @click="rep = 'categories'">Catégories</button>
              <button :class="{ on: rep === 'modes' }" role="tab" @click="rep = 'modes'">Paiements</button>
            </div>
          </div>
          <DonutChart v-if="repartition && repartition.items.length" :key="rep" :items="repartition.items" :total="repartition.total" :center-label="rep === 'modes' ? 'Encaissé' : 'Ventes'" />
          <div v-else class="empty" style="padding:30px 0">Aucune vente ce mois-ci.</div>
        </section>

        <section class="card card-pad reveal" style="--i:8">
          <div class="card-head">
            <h2>Performance des produits <small class="muted" style="font-weight:400">(mois)</small></h2>
            <div class="seg" role="tablist" aria-label="Classer par">
              <button :class="{ on: perf === 'nombre' }" role="tab" :aria-selected="perf === 'nombre'" @click="perf = 'nombre'">Par nombre</button>
              <button :class="{ on: perf === 'montant' }" role="tab" :aria-selected="perf === 'montant'" @click="perf = 'montant'">Par montant</button>
            </div>
          </div>
          <div v-if="!topListe.length" class="muted">Aucune vente ce mois-ci.</div>
          <div v-for="(p, i) in topListe" :key="perf + p.id" class="bar-row" :title="`${p.nom} — ${qty(p.quantite)} ${uniteAccord(p.quantite, p.unite)} · ${money(p.ca)} · ${p.nb_ventes} vente${p.nb_ventes > 1 ? 's' : ''}`">
            <span class="name">{{ p.nom }}</span>
            <div class="track"><div class="fill" :style="{ width: (valeurTop(p) / maxTop) * 100 + '%', '--i': i }" /></div>
            <b v-if="perf === 'nombre'">{{ qty(p.quantite) }} <small class="muted" style="font-weight:600">{{ uniteAccord(p.quantite, p.unite) }}</small></b>
            <b v-else>{{ money(p.ca) }}</b>
          </div>
          <RouterLink to="/produits" class="btn sm ghost voir-tout">Voir le catalogue</RouterLink>
        </section>
      </div>

      <div v-if="gerant" class="grid-even">
        <section class="card card-pad reveal" style="--i:9">
          <div class="card-head"><h2>Dernières ventes</h2><RouterLink to="/ventes" class="btn sm ghost">Tout voir</RouterLink></div>
          <div v-if="!d.dernieres.length" class="muted">Aucune vente pour l'instant.</div>
          <div class="list">
            <RouterLink v-for="v in d.dernieres" :key="v.idvente" class="item" :to="{ path: '/ventes', query: { q: v.numero } }" style="text-decoration:none;color:inherit">
              <span class="tile success"><Icon name="receipt" :size="19" /></span>
              <div class="grow"><b>{{ v.numero }}</b><span>{{ dateHeure(v.date_vente) }} · {{ v.caissier }}<template v-if="v.client"> · {{ v.client }}</template></span></div>
              <b>{{ money(v.total) }}</b>
              <span v-if="v.reste > 0" class="badge danger">crédit</span>
            </RouterLink>
          </div>
        </section>

        <div style="display:flex;flex-direction:column;gap:18px">
          <section class="card card-pad reveal" style="--i:10">
            <h2>Affluence par heure <small class="muted" style="font-weight:400">(30 jours)</small></h2>
            <div class="hours" role="img" aria-label="Chiffre d'affaires par heure">
              <div v-for="h in heuresUtiles" :key="h.h" class="hcol" :title="`${h.h} h — ${money(h.ca)} · ${h.nb} vente(s)`">
                <i :style="{ height: Math.max(3, (h.ca / maxHeure) * 100) + '%' }" /><span>{{ h.h }}</span>
              </div>
            </div>
          </section>
          <section class="card card-pad reveal" style="--i:11;flex:1">
            <h2>Performance des caissiers <small class="muted" style="font-weight:400">(mois)</small></h2>
            <div v-if="!d.caissiers.length" class="muted">Aucune vente ce mois-ci.</div>
            <div v-for="c in d.caissiers" :key="c.caissier" class="list"><div class="item"><span class="tile primary"><Icon name="user" :size="19" /></span><div class="grow"><b>{{ c.caissier }}</b><span>{{ c.nb }} vente{{ c.nb > 1 ? 's' : '' }}</span></div><b>{{ money(c.ca) }}</b></div></div>
          </section>
        </div>
      </div>

      <StockBalance v-if="d.stock" class="reveal" style="--i:12" />

      <section v-if="d.stock && d.stock.a_surveiller.length" class="card card-pad reveal" style="--i:12">
        <div class="card-head"><h2>Stocks à réapprovisionner</h2><RouterLink to="/stock" class="btn sm ghost">Voir le stock</RouterLink></div>
        <div class="list">
          <RouterLink v-for="p in d.stock.a_surveiller" :key="p.id" class="item" :to="`/produits/${p.id}`" style="text-decoration:none;color:inherit">
            <span class="tile" :class="Number(p.stock_qty) <= 0 ? 'danger' : 'warning'"><Icon name="box" :size="19" /></span>
            <div class="grow"><b>{{ p.nom }}</b><span>seuil d'alerte : {{ qty(p.seuil_alerte) }} {{ uniteAccord(p.seuil_alerte, p.unite) }}</span></div>
            <span class="badge" :class="Number(p.stock_qty) <= 0 ? 'danger' : 'warning'">{{ Number(p.stock_qty) <= 0 ? 'Rupture' : `${qty(p.stock_qty)} ${uniteAccord(p.stock_qty, p.unite)}` }}</span>
          </RouterLink>
        </div>
      </section>
    </template>
  </main>
</template>

<style scoped>
.hours { display: flex; align-items: flex-end; gap: 4px; height: 120px; margin-top: 14px; }
.hcol { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 100%; gap: 4px; font-size: 10px; color: var(--muted); }
.hcol i { width: 100%; border-radius: 4px 4px 2px 2px; background: linear-gradient(180deg, var(--primary), color-mix(in srgb, var(--primary) 45%, transparent)); min-height: 3px; transition: filter .2s; }
.hcol:hover i { filter: brightness(1.15); }
.voir-tout { margin-top: 8px; width: 100%; justify-content: center; }
</style>
