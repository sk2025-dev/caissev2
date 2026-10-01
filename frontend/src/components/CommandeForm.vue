<script setup>
// Prise de commande (livraison ou retrait) : depuis la page Commandes ou depuis le panier de la caisse.
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { api, auth, fileUrl, ApiError } from '../api'
import { lookups, loadLookups } from '../lookups'
import { money, qty } from '../format'
import { toast } from '../toast'
import Icon from './Icon.vue'
import SearchSelect from './SearchSelect.vue'

const props = defineProps({
  lignesInit: { type: Array, default: () => [] },   // [{ idprod, nom, unite, quantite, prix }]
  clientInit: { type: [String, Number], default: '' },
})
const emit = defineEmits(['close', 'created'])

const produits = ref([])
const clients = ref([])
const f = reactive({ mode: 'livraison', zone: 'abidjan', idzone: '', commune: '', lieu: '', idclient: props.clientInit || '', client_nom: '', client_tel: '', adresse: '', date_prevue: '', frais_livraison: '', note: '' })
const lignes = ref(props.lignesInit.map((l) => ({ ...l })))
const erreurs = ref({})
const erreur = ref('')
const busy = ref(false)
const q = ref('')
/* ---- Lieu et prix de la livraison : grille de tarifs par zone (Abidjan par quartier, intérieur par ville, extérieur) ---- */
const ZONES = [['abidjan', 'Abidjan', 'Tous les quartiers'], ['interieur', 'Intérieur du pays', 'Toutes les villes'], ['exterieur', 'Extérieur', 'Hors Côte d\'Ivoire']]
const AUTRE = '__autre'
const tarifs = computed(() => lookups.zones_livraison.filter((z) => z.zone === f.zone))
const optionsLieu = computed(() => [
  ...tarifs.value.map((z) => ({ value: z.id, label: `${z.commune ? z.commune + ' — ' : ''}${z.nom} · ${money(z.prix)}`, short: `${z.commune ? z.commune + ' — ' : ''}${z.nom}` })),
  { value: AUTRE, label: f.zone === 'abidjan' ? 'Autre quartier (hors grille)…' : f.zone === 'interieur' ? 'Autre ville (hors grille)…' : 'Autre pays / ville…', short: 'Autre lieu' },
])
const tarif = computed(() => tarifs.value.find((z) => String(z.id) === String(f.idzone)) || null)
const horsGrille = computed(() => f.idzone === AUTRE)
const placeholderLieu = computed(() => ({ abidjan: 'Rechercher un quartier ou une commune…', interieur: 'Rechercher une ville…', exterieur: 'Rechercher un pays ou une ville…' })[f.zone])
watch(() => f.zone, () => { f.idzone = ''; f.commune = ''; f.lieu = ''; f.frais_livraison = ''; if (!tarifs.value.length) f.idzone = AUTRE })
watch(() => f.idzone, (v) => {
  if (v === AUTRE || !v) { f.frais_livraison = ''; return }
  f.commune = ''; f.lieu = ''
  if (tarif.value) f.frais_livraison = String(Number(tarif.value.prix))
})
const ecartTarif = computed(() => tarif.value && f.frais_livraison !== '' && Number(f.frais_livraison) !== Number(tarif.value.prix))
const prixLibre = computed(() => lookups.reglages.prix_libre || auth.user?.admin)

onMounted(async () => {
  try { await loadLookups() } catch { /* non bloquant */ }
  try { produits.value = (await api.get('pos-catalogue')).produits } catch (e) { erreur.value = e.message }
  try { clients.value = (await api.get('clients', { sort: 'nom', dir: 'asc', per_page: 100 })).data } catch { /* non bloquant */ }
  if (f.idclient) choisirClient(f.idclient)
})


const optionsClients = computed(() => clients.value.map((c) => ({ value: c.idclient, label: c.nom + (c.telephone ? ` · ${c.telephone}` : ''), short: c.nom })))
function choisirClient(id) {
  const c = clients.value.find((x) => String(x.idclient) === String(id))
  if (!c) return
  f.client_nom = c.nom; f.client_tel = c.telephone || f.client_tel
  if (!f.adresse && c.adresse) f.adresse = c.adresse
}
watch(() => f.idclient, (id) => { if (id) choisirClient(id); else { f.client_nom = ''; f.client_tel = '' } })

const norm = (s) => String(s || '').normalize('NFD').replace(/\p{M}/gu, '').toLowerCase()
const resultats = computed(() => {
  const t = norm(q.value.trim())
  if (!t) return []
  return produits.value.filter((p) => norm(p.nom).includes(t) || norm(p.sku).includes(t) || norm(p.code_barres).includes(t)).slice(0, 8)
})
function ajouter(p) {
  const l = lignes.value.find((x) => x.idprod === p.id)
  if (l) l.quantite = Number(l.quantite) + 1
  else lignes.value.push({ idprod: p.id, nom: p.nom, unite: p.unite, quantite: 1, prix: p.prix_vente })
  q.value = ''
}
const changer = (l, v) => {
  let n = Number(v)
  if (!(n > 0)) n = l.unite === 'pièce' ? 1 : 0.001
  l.quantite = l.unite === 'pièce' ? Math.max(1, Math.round(n)) : n
}
const retirer = (l) => { lignes.value = lignes.value.filter((x) => x !== l) }
const sousTotal = computed(() => lignes.value.reduce((s, l) => s + l.prix * l.quantite, 0))
const frais = computed(() => (f.mode === 'livraison' ? Math.max(0, Number(f.frais_livraison) || 0) : 0))
const total = computed(() => sousTotal.value + frais.value)

async function envoyer() {
  busy.value = true; erreurs.value = {}; erreur.value = ''
  try {
    const res = await api.post('commande-creer', { ...f, idzone: horsGrille.value || !f.idzone ? null : f.idzone, idclient: f.idclient || null, lignes: lignes.value.map((l) => ({ idprod: l.idprod, quantite: l.quantite, prix_unitaire: l.prix })) })
    toast(`Commande ${res.commande.numero} enregistrée`)
    emit('created', res.commande)
  } catch (e) {
    if (!(e instanceof ApiError)) throw e
    erreurs.value = e.errors || {}; erreur.value = e.message
  } finally { busy.value = false }
}
</script>

<template>
  <div class="overlay center" @click.self="emit('close')" @keydown.esc="emit('close')">
    <form class="modal cmd-form" novalidate role="dialog" aria-modal="true" aria-label="Nouvelle commande" @submit.prevent="envoyer">
      <header>
        <h3>Nouvelle commande</h3>
        <div class="seg" role="tablist" aria-label="Mode">
          <button type="button" :class="{ on: f.mode === 'livraison' }" role="tab" @click="f.mode = 'livraison'"><Icon name="truck" :size="14" /> Livraison</button>
          <button type="button" :class="{ on: f.mode === 'retrait' }" role="tab" @click="f.mode = 'retrait'"><Icon name="cart" :size="14" /> Retrait en boutique</button>
        </div>
      </header>

      <div class="cols">
        <section>
          <div class="field"><label for="c-client">Client enregistré</label><SearchSelect id="c-client" v-model="f.idclient" :options="optionsClients" placeholder="Rechercher un client…" /></div>
          <div class="two">
            <div class="field" :class="{ invalid: erreurs.client_nom }"><label for="c-nom">Nom <span class="req">*</span></label><input id="c-nom" v-model="f.client_nom" class="input" maxlength="150" autocomplete="off" /><span v-if="erreurs.client_nom" class="error">{{ erreurs.client_nom }}</span></div>
            <div class="field" :class="{ invalid: erreurs.client_tel }"><label for="c-tel">Téléphone <span v-if="f.mode === 'livraison'" class="req">*</span></label><input id="c-tel" v-model="f.client_tel" class="input" type="tel" maxlength="40" inputmode="tel" /><span v-if="erreurs.client_tel" class="error">{{ erreurs.client_tel }}</span></div>
          </div>
          <template v-if="f.mode === 'livraison'">
            <div class="zones" role="tablist" aria-label="Zone de livraison">
              <button v-for="[k, l, sub] in ZONES" :key="k" type="button" role="tab" :aria-selected="f.zone === k" :class="{ on: f.zone === k }" @click="f.zone = k"><b>{{ l }}</b><small>{{ sub }}</small></button>
            </div>
            <div class="field" :class="{ invalid: erreurs.idzone }"><label for="c-lieu">{{ f.zone === 'abidjan' ? 'Commune et quartier' : f.zone === 'interieur' ? 'Ville' : 'Pays et ville' }} <span class="req">*</span></label>
              <SearchSelect id="c-lieu" v-model="f.idzone" :options="optionsLieu" :placeholder="placeholderLieu" :invalid="!!erreurs.idzone || !!erreurs.lieu" />
              <span v-if="erreurs.idzone" class="error">{{ erreurs.idzone }}</span>
              <span v-else-if="tarif && tarif.delai" class="hint">Délai indicatif : {{ tarif.delai }}</span></div>
            <div v-if="horsGrille" class="two">
              <div v-if="f.zone === 'abidjan'" class="field"><label for="c-commune">Commune</label><input id="c-commune" v-model="f.commune" class="input" maxlength="80" placeholder="Ex. Cocody" /></div>
              <div class="field" :class="{ invalid: erreurs.lieu }"><label for="c-lieu2">{{ f.zone === 'abidjan' ? 'Quartier' : f.zone === 'interieur' ? 'Ville' : 'Pays, ville' }} <span class="req">*</span></label><input id="c-lieu2" v-model="f.lieu" class="input" maxlength="120" :placeholder="f.zone === 'exterieur' ? 'Ex. Accra, Ghana' : ''" /><span v-if="erreurs.lieu" class="error">{{ erreurs.lieu }}</span></div>
            </div>
            <div class="field" :class="{ invalid: erreurs.adresse }"><label for="c-adr">Adresse précise et repère <span class="req">*</span></label><input id="c-adr" v-model="f.adresse" class="input" maxlength="300" placeholder="Rue, immeuble, porte, repère connu…" /><span v-if="erreurs.adresse" class="error">{{ erreurs.adresse }}</span></div>
          </template>
          <div class="two">
            <div class="field" :class="{ invalid: erreurs.date_prevue }"><label for="c-date">{{ f.mode === 'livraison' ? 'Livraison prévue' : 'Retrait prévu' }}</label><input id="c-date" v-model="f.date_prevue" class="input" type="datetime-local" /><span v-if="erreurs.date_prevue" class="error">{{ erreurs.date_prevue }}</span></div>
            <div v-if="f.mode === 'livraison'" class="field" :class="{ invalid: erreurs.frais_livraison }"><label for="c-frais">Prix de la livraison</label><input id="c-frais" v-model="f.frais_livraison" class="input" type="number" min="0" inputmode="numeric" :placeholder="horsGrille || !f.idzone ? 'À convenir' : '0'" />
              <span v-if="erreurs.frais_livraison" class="error">{{ erreurs.frais_livraison }}</span>
              <span v-else-if="ecartTarif" class="hint warn">Tarif de la grille : {{ money(tarif.prix) }} — prix modifié</span>
              <span v-else-if="tarif" class="hint">Tarif de la grille</span>
              <span v-else-if="horsGrille" class="hint">Hors grille : saisissez le prix convenu</span></div>
          </div>
          <div class="field"><label for="c-note">Note (facultatif)</label><input id="c-note" v-model="f.note" class="input" maxlength="300" placeholder="Digicode, étage, appeler avant…" /></div>
        </section>

        <section class="articles">
          <div class="search"><Icon name="search" :size="16" /><input v-model="q" class="input" type="search" placeholder="Ajouter un article…" aria-label="Ajouter un article" autocomplete="off" />
            <ul v-if="resultats.length" class="found">
              <li v-for="p in resultats" :key="p.id"><button type="button" @click="ajouter(p)"><span class="th"><img v-if="p.image" :src="fileUrl('produits', p.image)" alt="" /><Icon v-else name="box" :size="16" /></span><b>{{ p.nom }}</b><span>{{ money(p.prix_vente) }}</span></button></li>
            </ul>
          </div>
          <div v-if="!lignes.length" class="vide"><Icon name="cart" :size="30" /><span>Aucun article</span></div>
          <div v-for="l in lignes" :key="l.idprod" class="art">
            <b>{{ l.nom }}</b>
            <div class="stepper"><button type="button" :aria-label="`Moins de ${l.nom}`" @click="changer(l, l.quantite - 1)"><Icon name="minus" :size="13" /></button><input :value="l.quantite" type="number" min="0" :step="l.unite === 'pièce' ? 1 : 0.1" inputmode="decimal" :aria-label="`Quantité de ${l.nom}`" @change="changer(l, $event.target.value)" @focus="$event.target.select()" /><button type="button" :aria-label="`Plus de ${l.nom}`" @click="changer(l, Number(l.quantite) + 1)"><Icon name="plus" :size="13" /></button></div>
            <input v-if="prixLibre" v-model.number="l.prix" class="pu" type="number" min="0" :aria-label="`Prix de ${l.nom}`" />
            <span v-else class="pu-f">{{ money(l.prix) }}</span>
            <span class="lt">{{ money(l.prix * l.quantite) }}</span>
            <button type="button" class="x" :aria-label="`Retirer ${l.nom}`" @click="retirer(l)"><Icon name="x" :size="14" /></button>
          </div>
          <span v-if="erreurs.lignes" class="error">{{ erreurs.lignes }}</span>
          <div class="totaux">
            <div><span>Articles</span><span>{{ money(sousTotal) }}</span></div>
            <div v-if="frais > 0"><span>Livraison</span><span>{{ money(frais) }}</span></div>
            <div class="t"><span>Total à payer</span><b>{{ money(total) }}</b></div>
          </div>
        </section>
      </div>

      <div v-if="erreur && !Object.keys(erreurs).length" class="alert" role="alert">{{ erreur }}</div>
      <div class="row">
        <button type="button" class="btn" @click="emit('close')">Annuler</button>
        <button class="btn primary" :disabled="busy || !lignes.length"><Icon name="check" :size="16" /> {{ busy ? 'Enregistrement…' : 'Enregistrer la commande' }}</button>
      </div>
    </form>
  </div>
</template>

<style scoped>
.cmd-form { width: min(920px, calc(100% - 24px)); max-height: calc(100vh - 24px); overflow-y: auto; }
header { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
header h3 { margin: 0; }
.seg button { display: inline-flex; align-items: center; gap: 6px; }
.cols { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
section { display: flex; flex-direction: column; gap: 12px; min-width: 0; }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.zones { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
.zones button { border: 1.5px solid var(--border); background: var(--surface); border-radius: 14px; padding: 9px 8px; font: inherit; color: var(--text); cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 1px; transition: all .15s; }
.zones button small { color: var(--muted); font-size: 11.5px; } .zones button:hover { border-color: var(--primary); }
.zones button.on { border-color: var(--primary); background: var(--primary-soft); color: var(--primary); } .zones button.on small { color: var(--primary); }
.hint { font-size: 12.5px; color: var(--muted); } .hint.warn { color: var(--warning); font-weight: 600; }
.search { position: relative; }
.search > svg { position: absolute; left: 13px; top: 15px; color: var(--muted); }
.search .input { padding-left: 38px; }
.found { position: absolute; z-index: 5; left: 0; right: 0; top: calc(100% + 4px); margin: 0; padding: 4px; list-style: none; background: var(--surface); border: 1px solid var(--border); border-radius: 14px; box-shadow: var(--shadow-lg); }
.found button { width: 100%; display: flex; align-items: center; gap: 10px; border: 0; background: none; padding: 8px; border-radius: 10px; font: inherit; color: inherit; cursor: pointer; text-align: left; }
.found button:hover { background: var(--primary-soft); } .found b { flex: 1; font-weight: 600; font-size: 14px; } .found span:last-child { color: var(--primary); font-weight: 700; }
.th { width: 32px; height: 32px; border-radius: 8px; background: var(--surface-2); display: grid; place-items: center; overflow: hidden; color: var(--muted); } .th img { width: 100%; height: 100%; object-fit: cover; }
.vide { display: grid; place-items: center; gap: 6px; padding: 26px; color: var(--muted); border: 2px dashed var(--border); border-radius: 14px; }
.art { display: grid; grid-template-columns: 1fr auto; gap: 6px 10px; align-items: center; padding: 10px 12px; border: 1px solid var(--border); border-radius: 12px; background: var(--surface); }
.art > b { font-size: 14px; grid-column: 1 / 2; } .art .x { grid-row: 1; grid-column: 2; justify-self: end; }
.art .stepper { display: flex; align-items: center; gap: 2px; grid-column: 1; }
.art .lt { grid-column: 2; grid-row: 2; font-weight: 800; justify-self: end; }
.art .pu, .art .pu-f { grid-column: 1; grid-row: 3; font-size: 13px; color: var(--muted); }
.art .pu { width: 100px; padding: 4px 8px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface-2); font: inherit; color: var(--text); }
.stepper button, .art .x { width: 28px; height: 28px; border-radius: 8px; border: 1px solid var(--border); background: var(--surface-2); color: var(--primary); display: grid; place-items: center; cursor: pointer; }
.art .x { color: var(--danger); }
.stepper input { width: 52px; text-align: center; border: 0; border-bottom: 1px dashed var(--border); background: transparent; font: inherit; font-weight: 700; color: var(--text); padding: 3px 0; }
.totaux { display: flex; flex-direction: column; gap: 4px; padding: 12px 14px; border-radius: 14px; background: var(--surface-2); color: var(--muted); }
.totaux > div { display: flex; justify-content: space-between; } .totaux .t { color: var(--text); font-size: 18px; } .totaux .t b { font-size: 22px; }
@media (max-width: 760px) { .cols, .two { grid-template-columns: 1fr; } }
</style>
