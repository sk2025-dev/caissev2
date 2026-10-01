<script setup>
// Ticket de vente ou de commande imprimable (58 ou 80 mm).
import { computed } from 'vue'
import { money, qty, dateHeure } from '../format'
import { datePrevue, libelleStatut, finale } from '../commandes'
import { logoUrl } from '../theme'

const props = defineProps({ vente: Object, commande: Object })
const doc = computed(() => props.commande || props.vente)
const estCommande = computed(() => !!props.commande)
const ent = computed(() => doc.value.ticket?.entreprise || {})
const afficher = (k) => doc.value.ticket?.[`afficher_${k}`] !== false
const ligne = (l) => `${qty(l.quantite)} × ${money(l.prix_unitaire)}`
</script>

<template>
  <div class="ticket print-area" :style="{ '--w': (doc.ticket?.largeur === 58 ? 58 : 80) + 'mm' }">
    <div class="t-head">
      <img v-if="afficher('logo') && ent.logo && logoUrl" :src="logoUrl" alt="Logo de l'entreprise" class="t-logo" />
      <b>{{ ent.nom }}</b>
      <span v-if="afficher('adresse') && (ent.adresse || ent.ville)">{{ [ent.adresse, ent.ville].filter(Boolean).join(', ') }}</span>
      <template v-if="afficher('contact')">
        <span v-if="ent.telephone">Tél. {{ ent.telephone }}</span>
        <span v-if="ent.email">{{ ent.email }}</span>
        <span v-if="ent.site">{{ ent.site }}</span>
      </template>
      <template v-if="afficher('identifiants')">
        <span v-if="ent.rccm">RCCM {{ ent.rccm }}</span>
        <span v-if="ent.nif">NIF {{ ent.nif }}</span>
      </template>
      <span v-if="doc.ticket?.entete" class="t-message">{{ doc.ticket.entete }}</span>
    </div>
    <hr />
    <div class="t-meta">
      <span>{{ estCommande ? 'Commande' : 'Ticket' }} {{ doc.numero }}</span>
      <span v-if="!estCommande">{{ dateHeure(doc.date_vente) }}</span>
      <span v-if="!estCommande">Caissier : {{ doc.caissier }}</span>
      <span v-if="estCommande || doc.client">Client : {{ estCommande ? doc.client_nom : doc.client }}</span>
      <template v-if="estCommande">
        <span v-if="doc.client_tel">Tél. {{ doc.client_tel }}</span>
        <b>{{ doc.mode === 'livraison' ? 'Livraison' : 'Retrait en boutique' }} · {{ libelleStatut(doc) }}</b>
        <span v-if="doc.mode === 'livraison'">{{ doc.zone_libelle }}<template v-if="doc.lieu_complet"> · {{ doc.lieu_complet }}</template></span>
        <span v-if="doc.date_prevue">Prévue : {{ datePrevue(doc.date_prevue) }}</span>
        <span v-if="doc.livreur">Livreur : {{ doc.livreur }}</span>
      </template>
    </div>
    <div v-if="doc.statut === 'annulee'" class="t-annule">{{ estCommande ? 'COMMANDE ANNULÉE' : 'VENTE ANNULÉE' }}</div>
    <hr />
    <div v-for="l in doc.lignes" :key="l.idligne" class="t-line">
      <div class="r"><span class="n">{{ l.designation }}</span><b>{{ money(l.total) }}</b></div>
      <div class="r sub"><span>{{ ligne(l) }}</span><span v-if="Number(l.remise) > 0">remise −{{ money(l.remise) }}</span></div>
    </div>
    <div v-if="estCommande && Number(doc.frais_livraison) > 0" class="r"><span>Frais de livraison</span><b>{{ money(doc.frais_livraison) }}</b></div>
    <hr />
    <div class="r big"><span>TOTAL</span><b>{{ money(doc.total) }}</b></div>
    <template v-if="!estCommande">
      <div v-if="Number(doc.remise) > 0" class="r sub"><span>dont remises</span><span>{{ money(doc.remise) }}</span></div>
      <div v-if="Number(doc.tva) > 0" class="r sub"><span>dont TVA</span><span>{{ money(doc.tva) }}</span></div>
      <hr />
      <div v-for="p in doc.paiements" :key="p.idpaie" class="r"><span>{{ p.libelle }}<template v-if="p.reference"> ({{ p.reference }})</template></span><span>{{ money(p.montant) }}</span></div>
      <div v-if="Number(doc.rendu) > 0" class="r"><span>Monnaie rendue</span><span>{{ money(doc.rendu) }}</span></div>
      <div v-if="Number(doc.reste) > 0" class="r big"><span>RESTE À PAYER</span><b>{{ money(doc.reste) }}</b></div>
    </template>
    <template v-else>
      <p v-if="!finale(doc)" class="t-message">À encaisser {{ doc.mode === 'livraison' ? 'à la livraison' : 'au retrait' }} : {{ money(doc.total) }}</p>
      <p v-if="doc.vente_numero">Vente associée : {{ doc.vente_numero }}</p>
      <p v-if="doc.note" class="t-message">{{ doc.note }}</p>
    </template>
    <hr />
    <div class="t-foot t-message">{{ doc.ticket?.pied }}</div>
  </div>
</template>

<style scoped>
.ticket { box-sizing: border-box; width: min(100%, var(--w)); margin: 0 auto; padding: 14px 12px; background: #fff; color: #111; font: 13px/1.5 Arial, Helvetica, sans-serif; font-variant-numeric: tabular-nums; border-radius: 8px; box-shadow: 0 0 0 1px #0001; overflow-wrap: anywhere; }
.t-head, .t-meta { display: flex; flex-direction: column; text-align: center; gap: 1px; }
.t-head b { font-size: 15px; }
.t-logo { max-width: 40mm; max-height: 20mm; object-fit: contain; align-self: center; margin-bottom: 5px; }
.t-meta { text-align: left; }
.t-message { white-space: pre-wrap; }
hr { border: 0; border-top: 1px dashed #777; margin: 8px 0; }
.r { display: flex; justify-content: space-between; gap: 10px; }
.r .n { flex: 1; min-width: 0; overflow-wrap: anywhere; }
.r.sub { font-size: 12px; color: #444; }
.r.big { font-size: 15px; font-weight: 700; }
.t-line { margin-bottom: 4px; break-inside: avoid; }
.t-foot { text-align: center; margin-top: 4px; }
.t-annule { text-align: center; font-weight: 700; border: 2px solid #c00; color: #c00; padding: 3px; margin-top: 6px; }
@media print { .ticket { width: var(--w); border-radius: 0; } }
</style>
