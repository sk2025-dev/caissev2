<script setup>
// Barre « Ma sélection » en bas de la vitrine et son détail : quantités, total estimé, envoi de la commande sur WhatsApp.
import { ref, computed } from 'vue'
import { catalogueImageUrl } from '../api'
import { money } from '../format'
import { ajouter, vider, lignesSelection, lienWhatsApp } from '../catalogue'
import Icon from './Icon.vue'

const props = defineProps({ produits: { type: Array, required: true }, reglages: { type: Object, required: true } })
const ouvert = ref(false)
const lignes = computed(() => lignesSelection(props.produits))
const nb = computed(() => lignes.value.reduce((s, l) => s + l.q, 0))
const total = computed(() => (lignes.value.every((l) => l.produit.prix != null) ? lignes.value.reduce((s, l) => s + l.produit.prix * l.q, 0) : null))
const lien = computed(() => (props.reglages.whatsapp && lignes.value.length ? lienWhatsApp(props.reglages.whatsapp, lignes.value) : ''))
</script>

<template>
  <div v-if="reglages.whatsapp && nb" class="barre-selection">
    <button class="resume" @click="ouvert = true"><span class="pastille">{{ nb }}</span><span>Ma sélection<small v-if="total != null">{{ money(total) }}</small></span><Icon name="up" :size="16" /></button>
    <a class="btn wa" :href="lien" target="_blank" rel="noopener"><Icon name="message" :size="17" /> Commander</a>
  </div>

  <Teleport to="body">
    <div v-if="ouvert" class="overlay center feuille-fond" @click.self="ouvert = false" @keydown.esc="ouvert = false">
      <div class="feuille" role="dialog" aria-modal="true" aria-label="Ma sélection">
        <header><h2>Ma sélection</h2><button class="btn ghost icon" aria-label="Fermer" @click="ouvert = false"><Icon name="x" /></button></header>
        <div v-if="!lignes.length" class="muted vide">Votre sélection est vide.</div>
        <div v-else class="lignes">
          <div v-for="l in lignes" :key="l.produit.id" class="ligne">
            <img v-if="l.produit.image" :src="catalogueImageUrl(l.produit.image)" alt="" /><span v-else class="img-vide"><Icon name="box" :size="20" /></span>
            <div class="nom"><b>{{ l.produit.nom }}</b><small v-if="l.produit.prix != null" class="muted">{{ money(l.produit.prix) }}</small></div>
            <div class="qte">
              <button class="btn icon sm" :aria-label="`Retirer un ${l.produit.nom}`" @click="ajouter(l.produit.id, -1)"><Icon :name="l.q > 1 ? 'minus' : 'trash'" :size="15" /></button>
              <b>{{ l.q }}</b>
              <button class="btn icon sm" :aria-label="`Ajouter un ${l.produit.nom}`" @click="ajouter(l.produit.id, 1)"><Icon name="plus" :size="15" /></button>
            </div>
          </div>
        </div>
        <footer v-if="lignes.length">
          <div v-if="total != null" class="total"><span>Total estimé</span><b>{{ money(total) }}</b></div>
          <a class="btn wa large" :href="lien" target="_blank" rel="noopener"><Icon name="message" :size="18" /> Envoyer la commande sur WhatsApp</a>
          <button class="btn ghost sm" @click="vider(); ouvert = false">Vider la sélection</button>
          <small class="muted">Le message est pré-rempli : il ne reste qu'à l'envoyer. La boutique confirme les prix et la disponibilité.</small>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.barre-selection { position: fixed; left: 50%; bottom: 16px; transform: translateX(-50%); z-index: 40; width: min(560px, calc(100% - 24px)); display: flex; gap: 8px; padding: 8px; border-radius: 999px; background: var(--surface); box-shadow: var(--shadow-lg); border: 1px solid var(--border); animation: pop .3s var(--ease); }
.resume { flex: 1; display: flex; align-items: center; gap: 10px; border: 0; background: none; font: inherit; color: var(--text); cursor: pointer; text-align: left; padding: 0 8px; }
.resume > span:nth-child(2) { flex: 1; display: flex; flex-direction: column; font-weight: 700; }
.resume small { color: var(--muted); font-weight: 600; }
.pastille { min-width: 32px; height: 32px; padding: 0 8px; border-radius: 999px; display: grid; place-items: center; background: var(--primary); color: #fff; font-weight: 800; }
.btn.wa { background: #1da851; color: #fff; border-color: #1da851; border-radius: 999px; }
.btn.wa:hover { filter: brightness(1.06); }
.btn.large { width: 100%; justify-content: center; padding: 13px; font-size: 16px; }
.feuille-fond { align-items: flex-end; }
.feuille { width: min(560px, 100%); max-height: 86vh; display: flex; flex-direction: column; background: var(--surface); border-radius: 26px 26px 0 0; box-shadow: var(--shadow-lg); animation: slideup .3s var(--ease); }
@media (min-width: 640px) { .feuille-fond { align-items: center; } .feuille { border-radius: 26px; } }
@keyframes slideup { from { transform: translateY(40px); opacity: 0; } to { transform: none; opacity: 1; } }
.feuille header { display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; border-bottom: 1px solid var(--border); }
.feuille header h2 { margin: 0; font-size: 19px; }
.vide { padding: 30px; text-align: center; }
.lignes { overflow-y: auto; padding: 6px 20px; }
.ligne { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--border); }
.ligne:last-child { border-bottom: 0; }
.ligne img, .img-vide { width: 52px; height: 52px; border-radius: 12px; object-fit: cover; flex: none; background: var(--surface-2); display: grid; place-items: center; color: var(--muted); }
.nom { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.nom b { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.qte { display: flex; align-items: center; gap: 8px; }
.qte b { min-width: 20px; text-align: center; }
.feuille footer { display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 16px 20px 20px; border-top: 1px solid var(--border); text-align: center; }
.total { width: 100%; display: flex; justify-content: space-between; font-size: 17px; }
</style>
