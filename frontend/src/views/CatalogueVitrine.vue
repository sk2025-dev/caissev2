<script setup>
// Vitrine publique ouverte par le QR code : recherche, catégories, tri, fiches produits, sélection et commande WhatsApp.
import { ref, computed, onMounted } from 'vue'
import { catalogueImageUrl } from '../api'
import { money } from '../format'
import { logoUrl } from '../theme'
import { vitrine, chargerVitrine, selection, ajouter, DISPO } from '../catalogue'
import { etoiles } from '../avis'
import Icon from '../components/Icon.vue'
import SelectionCatalogue from '../components/SelectionCatalogue.vue'

const q = ref('')
const cat = ref(null)
const tri = ref('nom')
onMounted(() => { chargerVitrine(); document.title = 'Catalogue' })
const d = computed(() => vitrine.data)
const r = computed(() => d.value?.reglages || {})
const sansAccent = (t) => (t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
const produits = computed(() => {
  if (!d.value) return []
  const m = sansAccent(q.value.trim())
  const l = d.value.produits.filter((p) => (cat.value === null || p.idcat === cat.value) && (!m || sansAccent(`${p.nom} ${p.marque}`).includes(m)))
  if (tri.value === 'prix_asc') return [...l].sort((a, b) => a.prix - b.prix)
  if (tri.value === 'prix_desc') return [...l].sort((a, b) => b.prix - a.prix)
  if (tri.value === 'note') return [...l].sort((a, b) => (b.note || 0) - (a.note || 0) || b.nb_avis - a.nb_avis)
  return l
})
const nbParCat = (id) => d.value.produits.filter((p) => p.idcat === id).length
const telephone = computed(() => (d.value?.entreprise.telephone || '').replace(/[^\d+]/g, ''))
</script>

<template>
  <div class="vitrine">
    <header class="entete">
      <img v-if="d?.entreprise.logo" :src="logoUrl" alt="" class="logo" />
      <div class="titre">
        <h1>{{ d?.entreprise.nom || 'Notre catalogue' }}</h1>
        <p v-if="r.message">{{ r.message }}</p>
        <p v-else-if="d">Découvrez nos {{ d.produits.length }} produits et services</p>
      </div>
      <div v-if="d" class="contacts">
        <a v-if="r.whatsapp" class="chip wa" :href="`https://wa.me/${r.whatsapp}`" target="_blank" rel="noopener"><Icon name="message" :size="15" /> WhatsApp</a>
        <a v-if="telephone" class="chip" :href="`tel:${telephone}`"><Icon name="phone" :size="15" /> Appeler</a>
        <span v-if="d.entreprise.adresse" class="chip neutre"><Icon name="target" :size="15" /> {{ d.entreprise.adresse }}</span>
      </div>
    </header>

    <div v-if="vitrine.erreur" class="etat"><Icon name="inbox" :size="44" /><h2>Catalogue indisponible</h2><p class="muted">{{ vitrine.erreur }}</p></div>
    <div v-else-if="!d" class="grille"><div v-for="n in 8" :key="n" class="skeleton" style="aspect-ratio:3/4;border-radius:20px" /></div>

    <template v-else>
      <div class="outils">
        <div class="recherche"><Icon name="search" :size="17" /><input v-model="q" type="search" class="input" placeholder="Rechercher un produit, une marque…" aria-label="Rechercher" /></div>
        <select v-model="tri" class="input tri" aria-label="Trier">
          <option value="nom">Nom (A → Z)</option>
          <template v-if="r.prix"><option value="prix_asc">Prix croissant</option><option value="prix_desc">Prix décroissant</option></template>
          <option v-if="r.avis" value="note">Mieux notés</option>
        </select>
      </div>
      <nav v-if="d.categories.length > 1" class="cats" aria-label="Catégories">
        <button :class="{ on: cat === null }" @click="cat = null">Tout <small>{{ d.produits.length }}</small></button>
        <button v-for="c in d.categories" :key="c.id" :class="{ on: cat === c.id }" :style="c.couleur ? { '--c': c.couleur } : null" @click="cat = c.id">{{ c.nom }} <small>{{ nbParCat(c.id) }}</small></button>
      </nav>

      <div v-if="!produits.length" class="etat"><Icon name="search" :size="40" /><p class="muted">Aucun produit ne correspond à votre recherche.</p></div>
      <div v-else class="grille">
        <article v-for="(p, n) in produits" :key="p.id" class="carte" :style="{ '--i': Math.min(n, 12) }">
          <RouterLink :to="`/catalogue/${p.id}`" class="lien">
            <div class="photo">
              <img v-if="p.image" :src="catalogueImageUrl(p.image)" :alt="p.nom" loading="lazy" />
              <Icon v-else :name="p.dispo === 'service' ? 'clipboard' : 'box'" :size="40" />
              <span v-if="p.dispo && p.dispo !== 'ok' && p.dispo !== 'service'" class="badge etiquette" :class="DISPO[p.dispo][0]">{{ DISPO[p.dispo][1] }}</span>
              <span v-if="p.nb_photos > 1" class="nb-photos"><Icon name="image" :size="12" /> {{ p.nb_photos }}</span>
            </div>
            <div class="infos">
              <small v-if="p.marque" class="marque">{{ p.marque }}</small>
              <h3>{{ p.nom }}</h3>
              <span v-if="p.note" class="note"><span class="stars">{{ etoiles(p.note) }}</span> ({{ p.nb_avis }})</span>
              <b v-if="p.prix != null" class="prix">{{ money(p.prix) }}</b>
            </div>
          </RouterLink>
          <button v-if="r.whatsapp && p.dispo !== 'rupture'" class="ajout" :class="{ dedans: selection[p.id] }" :aria-label="`Ajouter ${p.nom} à ma sélection`" @click="ajouter(p.id)">
            <Icon :name="selection[p.id] ? 'check' : 'plus'" :size="18" /><span v-if="selection[p.id]">{{ selection[p.id] }}</span>
          </button>
        </article>
      </div>
      <footer class="pied">
        <b>{{ d.entreprise.nom }}</b>
        <span v-if="d.entreprise.adresse">{{ d.entreprise.adresse }}</span>
        <span>{{ [d.entreprise.telephone, d.entreprise.email, d.entreprise.site].filter(Boolean).join(' · ') }}</span>
        <small class="muted">Prix et disponibilités indicatifs, confirmés par la boutique à la commande.</small>
      </footer>
      <SelectionCatalogue :produits="d.produits" :reglages="r" />
    </template>
  </div>
</template>

<style scoped>
.vitrine { max-width: 1180px; margin: 0 auto; padding: 16px 16px 110px; min-height: 100vh; }
.entete { display: flex; flex-wrap: wrap; align-items: center; gap: 14px 18px; padding: 22px; margin-bottom: 16px; border-radius: 26px; color: #fff; background: linear-gradient(135deg, var(--primary), var(--primary-2)); box-shadow: 0 16px 36px color-mix(in srgb, var(--primary) 30%, transparent); }
.logo { width: 68px; height: 68px; object-fit: contain; border-radius: 18px; background: #fff; padding: 6px; flex: none; }
.titre { flex: 1; min-width: 200px; }
.titre h1 { margin: 0; font-size: clamp(22px, 4vw, 30px); }
.titre p { margin: 4px 0 0; opacity: .9; }
.contacts { display: flex; flex-wrap: wrap; gap: 8px; width: 100%; }
.chip { display: inline-flex; align-items: center; gap: 6px; padding: 7px 13px; border-radius: 999px; background: rgba(255, 255, 255, .2); color: #fff; font-weight: 700; font-size: 14px; text-decoration: none; }
.chip.wa { background: #1da851; }
.chip.neutre { background: rgba(255, 255, 255, .12); font-weight: 600; }
.outils { position: sticky; top: 0; z-index: 20; display: flex; gap: 8px; padding: 10px 0; background: var(--bg); }
.recherche { flex: 1; position: relative; }
.recherche svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--muted); }
.recherche .input { padding-left: 42px; border-radius: 999px; }
.tri { width: auto; border-radius: 999px; }
.cats { display: flex; gap: 8px; overflow-x: auto; padding: 4px 0 14px; scrollbar-width: none; }
.cats button { --c: var(--primary); flex: none; display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 999px; border: 1.5px solid var(--border); background: var(--surface); color: var(--text); font: inherit; font-weight: 700; font-size: 14px; cursor: pointer; }
.cats button small { color: var(--muted); font-weight: 600; }
.cats button.on { border-color: var(--c); background: var(--c); color: #fff; }
.cats button.on small { color: rgba(255, 255, 255, .8); }
.grille { display: grid; grid-template-columns: repeat(auto-fill, minmax(165px, 1fr)); gap: 14px; }
@media (min-width: 720px) { .grille { grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 18px; } }
.carte { position: relative; background: var(--surface); border: 1px solid var(--border); border-radius: 20px; overflow: hidden; box-shadow: var(--shadow); animation: rise .5s var(--ease) both; animation-delay: calc(var(--i) * 40ms); transition: transform .25s var(--ease), box-shadow .25s; }
.carte:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); }
.lien { display: flex; flex-direction: column; height: 100%; color: inherit; text-decoration: none; }
.photo { position: relative; aspect-ratio: 1; display: grid; place-items: center; background: #fff; color: var(--muted); border-bottom: 1px solid var(--border); }
.photo img { width: 100%; height: 100%; object-fit: contain; }
.etiquette { position: absolute; left: 8px; top: 8px; }
.nb-photos { position: absolute; right: 8px; bottom: 8px; display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 999px; background: rgba(0, 0, 0, .55); color: #fff; font-size: 12px; font-weight: 700; }
.infos { display: flex; flex-direction: column; gap: 3px; padding: 12px 14px 14px; flex: 1; }
.marque { color: var(--muted); font-weight: 700; text-transform: uppercase; font-size: 11.5px; letter-spacing: .04em; }
.infos h3 { margin: 0; font-size: 15px; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.note { font-size: 12.5px; color: var(--muted); }
.stars { color: #f5a524; letter-spacing: 1px; }
.prix { margin-top: auto; padding-top: 4px; font-size: 17px; color: var(--primary); }
.ajout { position: absolute; right: 10px; bottom: 10px; min-width: 40px; height: 40px; padding: 0 10px; border-radius: 999px; border: 0; display: inline-flex; align-items: center; justify-content: center; gap: 4px; background: var(--primary); color: #fff; font-weight: 800; cursor: pointer; box-shadow: 0 6px 16px color-mix(in srgb, var(--primary) 40%, transparent); transition: transform .15s var(--ease); }
.ajout:active { transform: scale(.92); }
.ajout.dedans { background: #1da851; }
.etat { display: flex; flex-direction: column; align-items: center; gap: 6px; text-align: center; padding: 60px 16px; color: var(--muted); }
.etat h2 { margin: 6px 0 0; color: var(--text); }
.pied { display: flex; flex-direction: column; align-items: center; gap: 4px; margin-top: 40px; padding-top: 20px; border-top: 1px solid var(--border); text-align: center; font-size: 14px; }
</style>
