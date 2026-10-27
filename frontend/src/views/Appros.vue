<script setup>
// Réceptions de marchandises : stock et coût moyen. Confidentialité : aucune réception n'est rattachée à un fournisseur
// (ses factures et paiements se gèrent sur la fiche du fournisseur).
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { api, ApiError } from '../api'
import { money, qty, date, today } from '../format'
import { toast } from '../toast'
import Icon from '../components/Icon.vue'
import Pager from '../components/Pager.vue'
import SearchSelect from '../components/SearchSelect.vue'
import Fenetre from '../components/Fenetre.vue'

const rows = ref([])
const meta = reactive({ total: 0, page: 1, pages: 1, per_page: 25 })
const loading = ref(true)
const f = reactive({ q: '', from: '', to: '', page: 1 })
async function load() {
  loading.value = true
  try { const r = await api.get('appros', { q: f.q, from: f.from, to: f.to, page: f.page }); rows.value = r.data; Object.assign(meta, r.meta) } catch (e) { toast(e.message, 'error') } finally { loading.value = false }
}
let timer
watch(() => f.q, () => { clearTimeout(timer); timer = setTimeout(() => { f.page = 1; load() }, 300) })
watch(() => [f.from, f.to], () => { f.page = 1; load() })

/* ---------- Détail ---------- */
const detail = ref(null)
async function voir(a) { try { detail.value = (await api.get('appro', { id: a.idappro })).appro } catch (e) { toast(e.message, 'error') } }

/* ---------- Nouvelle réception ---------- */
const produits = ref([])
const form = reactive({ ouvert: false, date_appro: today(), numero_bon: '', note: '', lignes: [], busy: false, erreurs: {}, erreur: '' })
const optProduits = computed(() => produits.value.map((p) => ({ value: p.idprod, label: p.nom + (p.sku ? ` (${p.sku})` : ''), short: p.nom })))
async function nouvelle() {
  if (!produits.value.length) { try { produits.value = (await api.get('produits', { type: 'produit', all: 1, sort: 'nom', dir: 'asc' })).data } catch (e) { toast(e.message, 'error'); return } }
  Object.assign(form, { ouvert: true, date_appro: today(), numero_bon: '', note: '', lignes: [{ idprod: '', quantite: '', cout_unitaire: '' }], erreurs: {}, erreur: '' })
}
function choisirProduit(l, id) {
  l.idprod = id
  const p = produits.value.find((x) => x.idprod === id)
  if (p && !l.cout_unitaire) l.cout_unitaire = p.prix_achat
}
const total = computed(() => form.lignes.reduce((s, l) => s + (Number(l.quantite) || 0) * (Number(l.cout_unitaire) || 0), 0))
async function enregistrer() {
  form.busy = true; form.erreurs = {}; form.erreur = ''
  try {
    const lignes = form.lignes.filter((l) => l.idprod)
    await api.post('appro-creer', { date_appro: form.date_appro, numero_bon: form.numero_bon, note: form.note, lignes })
    toast('Réception enregistrée — le stock est à jour'); form.ouvert = false; produits.value = []; await load()
  } catch (e) {
    if (!(e instanceof ApiError)) throw e
    form.erreurs = e.errors; form.erreur = Object.keys(e.errors).length ? '' : e.message
  } finally { form.busy = false }
}
onMounted(load)
</script>

<template>
  <main class="page">
    <div class="page-head">
      <div><h1>Approvisionnements</h1><p>Réceptions de marchandises : le stock et le coût moyen sont mis à jour automatiquement</p></div>
      <button class="btn primary" @click="nouvelle"><Icon name="plus" /> Nouvelle réception</button>
    </div>

    <div class="card">
      <div class="toolbar">
        <div class="search"><Icon name="search" :size="16" /><input v-model="f.q" class="input" type="search" placeholder="N° de réception, de bon ou note…" aria-label="Rechercher" /></div>
        <input v-model="f.from" class="input date" type="date" aria-label="Du" /><input v-model="f.to" class="input date" type="date" aria-label="Au" />
      </div>
      <div class="table-wrap" :aria-busy="loading">
        <table v-if="rows.length || loading" class="table stack">
          <thead><tr><th>Réception</th><th>Date</th><th>Saisie par</th><th class="num">Lignes</th><th class="num">Total</th></tr></thead>
          <tbody v-if="loading && !rows.length"><tr v-for="n in 5" :key="n"><td colspan="5"><div class="skeleton" style="height:22px" /></td></tr></tbody>
          <tbody v-else>
            <tr v-for="a in rows" :key="a.idappro">
              <td data-label="Réception" class="strong"><a href="#" class="rowlink" @click.prevent="voir(a)">{{ a.numero }}</a> <small v-if="a.numero_bon" class="muted">bon {{ a.numero_bon }}</small></td>
              <td data-label="Date">{{ date(a.date_appro) }}</td><td data-label="Saisie par">{{ a.utilisateur || '—' }}</td>
              <td data-label="Lignes" class="num">{{ a.nb_lignes }}</td><td data-label="Total" class="num">{{ money(a.total) }}</td>
            </tr>
          </tbody>
        </table>
        <div v-else class="empty"><Icon name="inbox" :size="40" /><div>Aucune réception enregistrée.</div></div>
      </div>
      <Pager :meta="meta" @goto="(p) => { f.page = p; load() }" />
    </div>

    <!-- Détail -->
    <Fenetre v-if="detail" :titre="`Réception ${detail.numero}`" icone="truck" :largeur="580" @close="detail = null">
        <template #sous-titre>{{ date(detail.date_appro) }}<template v-if="detail.numero_bon"> · bon {{ detail.numero_bon }}</template> · saisie par {{ detail.utilisateur }}</template>
        <table class="table"><thead><tr><th>Produit</th><th class="num">Qté</th><th class="num">Coût</th><th class="num">Total</th></tr></thead>
          <tbody><tr v-for="l in detail.lignes" :key="l.idligne"><td>{{ l.designation }}</td><td class="num">{{ qty(l.quantite) }}</td><td class="num">{{ money(l.cout_unitaire) }}</td><td class="num">{{ money(l.total) }}</td></tr></tbody></table>
        <p v-if="detail.note" class="muted" style="margin:10px 0 0">{{ detail.note }}</p>
        <template #pied><span class="grow total-pied">Total <b>{{ money(detail.total) }}</b></span><button class="btn" @click="detail = null">Fermer</button></template>
    </Fenetre>

    <!-- Nouvelle réception -->
    <Fenetre v-if="form.ouvert" titre="Nouvelle réception" sous-titre="Le stock et le coût moyen sont mis à jour à l'enregistrement" icone="truck" :largeur="680" tiroir formulaire :occupe="form.busy" @close="form.ouvert = false" @submit="enregistrer">
        <div class="fen-grille">
          <div v-if="form.erreur" class="alert full">{{ form.erreur }}</div>
          <div class="field" :class="{ invalid: form.erreurs.date_appro }"><label for="r-d">Date de réception</label><input id="r-d" v-model="form.date_appro" class="input" type="date" :max="today()" /><span v-if="form.erreurs.date_appro" class="error">{{ form.erreurs.date_appro }}</span></div>
          <div class="field"><label for="r-b">N° du bon de livraison</label><input id="r-b" v-model="form.numero_bon" class="input" maxlength="60" /></div>

          <div class="field full" :class="{ invalid: form.erreurs.lignes }">
            <label>Produits reçus <span class="req">*</span></label>
            <div v-for="(l, i) in form.lignes" :key="i" class="aline">
              <SearchSelect :id="`r-p${i}`" :model-value="l.idprod" :options="optProduits" placeholder="Produit…" @update:model-value="choisirProduit(l, $event)" />
              <input v-model="l.quantite" class="input" type="number" min="0" step="any" placeholder="Qté" :aria-label="`Quantité ligne ${i + 1}`" />
              <input v-model="l.cout_unitaire" class="input" type="number" min="0" step="any" placeholder="Coût unit." :aria-label="`Coût unitaire ligne ${i + 1}`" />
              <button type="button" class="btn ghost icon sm" :aria-label="`Retirer la ligne ${i + 1}`" :disabled="form.lignes.length === 1" @click="form.lignes.splice(i, 1)"><Icon name="x" :size="15" /></button>
            </div>
            <button type="button" class="btn sm" style="align-self:flex-start" @click="form.lignes.push({ idprod: '', quantite: '', cout_unitaire: '' })"><Icon name="plus" :size="14" /> Ajouter une ligne</button>
            <span v-if="form.erreurs.lignes" class="error">{{ form.erreurs.lignes }}</span>
          </div>

          <div class="field full"><label for="r-n">Note</label><input id="r-n" v-model="form.note" class="input" maxlength="300" /></div>
        </div>
        <template #pied>
          <span class="grow total-pied">Total <b>{{ money(total) }}</b></span>
          <button type="button" class="btn" :disabled="form.busy" @click="form.ouvert = false">Annuler</button>
          <button class="btn primary" :disabled="form.busy">{{ form.busy ? 'Enregistrement…' : 'Enregistrer la réception' }}</button>
        </template>
    </Fenetre>
  </main>
</template>

<style scoped>
.aline { display: grid; grid-template-columns: minmax(0, 1fr) 90px 110px 34px; gap: 8px; align-items: center; margin-bottom: 8px; }
@media (max-width: 560px) { .aline { grid-template-columns: 1fr 70px 90px 34px; } }
</style>
