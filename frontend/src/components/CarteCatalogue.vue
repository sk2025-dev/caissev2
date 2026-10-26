<script setup>
// Carte d'un produit dans la vitrine publique (grille ou rangée d'une catégorie).
import { catalogueImageUrl } from '../api'
import { money } from '../format'
import { selection, ajouter, DISPO } from '../catalogue'
import { etoiles } from '../avis'
import Icon from './Icon.vue'

defineProps({ p: { type: Object, required: true }, commande: Boolean })
</script>

<template>
  <article class="carte">
    <RouterLink :to="`/catalogue/${p.id}`" class="lien">
      <div class="photo">
        <img v-if="p.image" :src="catalogueImageUrl(p.image)" :alt="p.nom" loading="lazy" />
        <Icon v-else :name="p.dispo === 'service' ? 'clipboard' : 'box'" :size="40" />
        <span class="etiquettes">
          <span v-if="p.nouveau" class="badge primary">Nouveau</span>
          <span v-if="p.dispo && p.dispo !== 'ok' && p.dispo !== 'service'" class="badge" :class="DISPO[p.dispo][0]">{{ DISPO[p.dispo][1] }}</span>
        </span>
        <span v-if="p.nb_photos > 1" class="nb-photos"><Icon name="image" :size="12" /> {{ p.nb_photos }}</span>
      </div>
      <div class="infos">
        <small v-if="p.marque" class="marque">{{ p.marque }}</small>
        <h3>{{ p.nom }}</h3>
        <span v-if="p.note" class="note"><span class="stars">{{ etoiles(p.note) }}</span> ({{ p.nb_avis }})</span>
        <b v-if="p.prix != null" class="prix">{{ money(p.prix) }}</b>
      </div>
    </RouterLink>
    <button v-if="commande && p.dispo !== 'rupture'" class="ajout" :class="{ dedans: selection[p.id] }" :aria-label="`Ajouter ${p.nom} à ma sélection`" @click="ajouter(p.id)">
      <Icon :name="selection[p.id] ? 'check' : 'plus'" :size="18" /><span v-if="selection[p.id]">{{ selection[p.id] }}</span>
    </button>
  </article>
</template>

<style scoped>
.carte { position: relative; height: 100%; background: var(--surface); border: 1px solid var(--border); border-radius: 20px; overflow: hidden; box-shadow: var(--shadow); transition: transform .25s var(--ease), box-shadow .25s; }
.carte:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); }
.lien { display: flex; flex-direction: column; height: 100%; color: inherit; text-decoration: none; }
.photo { position: relative; aspect-ratio: 1; display: grid; place-items: center; background: #fff; color: var(--muted); border-bottom: 1px solid var(--border); }
.photo img { width: 100%; height: 100%; object-fit: contain; }
.etiquettes { position: absolute; left: 8px; top: 8px; display: flex; flex-direction: column; align-items: flex-start; gap: 4px; }
.nb-photos { position: absolute; right: 8px; bottom: 8px; display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 999px; background: rgba(0, 0, 0, .55); color: #fff; font-size: 12px; font-weight: 700; }
.infos { display: flex; flex-direction: column; gap: 3px; padding: 12px 14px 14px; flex: 1; }
.marque { color: var(--muted); font-weight: 700; text-transform: uppercase; font-size: 11.5px; letter-spacing: .04em; }
.infos h3 { margin: 0; font-size: 15px; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.note { font-size: 12.5px; color: var(--muted); }
.stars { color: #f5a524; letter-spacing: 1px; }
.prix { margin-top: auto; padding-top: 4px; padding-right: 44px; font-size: 17px; color: var(--primary); }
.ajout { position: absolute; right: 10px; bottom: 10px; min-width: 40px; height: 40px; padding: 0 10px; border-radius: 999px; border: 0; display: inline-flex; align-items: center; justify-content: center; gap: 4px; background: var(--primary); color: #fff; font-weight: 800; cursor: pointer; box-shadow: 0 6px 16px color-mix(in srgb, var(--primary) 40%, transparent); transition: transform .15s var(--ease); }
.ajout:active { transform: scale(.92); }
.ajout.dedans { background: #1da851; }
</style>
