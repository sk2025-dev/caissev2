<script setup>
// Ticket de caisse imprimable (58 ou 80 mm). À l'impression, seule cette zone est visible (voir .print-area dans styles.css).
import { money, qty, dateHeure } from '../format'

defineProps({ vente: { type: Object, required: true } })
const ligne = (l) => `${qty(l.quantite)} × ${money(l.prix_unitaire)}`
</script>

<template>
  <div class="ticket print-area" :style="{ '--w': (vente.ticket?.largeur === 58 ? 58 : 80) + 'mm' }">
    <div class="t-head">
      <b>{{ vente.ticket?.entreprise?.nom }}</b>
      <span v-if="vente.ticket?.entreprise?.adresse">{{ vente.ticket.entreprise.adresse }}<template v-if="vente.ticket.entreprise.ville">, {{ vente.ticket.entreprise.ville }}</template></span>
      <span v-if="vente.ticket?.entreprise?.telephone">Tél. {{ vente.ticket.entreprise.telephone }}</span>
      <span v-if="vente.ticket?.entreprise?.rccm">RCCM {{ vente.ticket.entreprise.rccm }}</span>
      <span v-if="vente.ticket?.entete">{{ vente.ticket.entete }}</span>
    </div>
    <hr />
    <div class="t-meta">
      <span>Ticket {{ vente.numero }}</span><span>{{ dateHeure(vente.date_vente) }}</span>
      <span>Caissier : {{ vente.caissier }}</span><span v-if="vente.client">Client : {{ vente.client }}</span>
    </div>
    <div v-if="vente.statut === 'annulee'" class="t-annule">VENTE ANNULÉE</div>
    <hr />
    <div v-for="l in vente.lignes" :key="l.idligne" class="t-line">
      <div class="r"><span class="n">{{ l.designation }}</span><b>{{ money(l.total) }}</b></div>
      <div class="r sub"><span>{{ ligne(l) }}</span><span v-if="Number(l.remise) > 0">remise −{{ money(l.remise) }}</span></div>
    </div>
    <hr />
    <div class="r big"><span>TOTAL</span><b>{{ money(vente.total) }}</b></div>
    <div v-if="Number(vente.remise) > 0" class="r sub"><span>dont remises</span><span>{{ money(vente.remise) }}</span></div>
    <div v-if="Number(vente.tva) > 0" class="r sub"><span>dont TVA</span><span>{{ money(vente.tva) }}</span></div>
    <hr />
    <div v-for="p in vente.paiements" :key="p.idpaie" class="r"><span>{{ p.libelle }}<template v-if="p.reference"> ({{ p.reference }})</template></span><span>{{ money(p.montant) }}</span></div>
    <div v-if="Number(vente.rendu) > 0" class="r"><span>Monnaie rendue</span><span>{{ money(vente.rendu) }}</span></div>
    <div v-if="Number(vente.reste) > 0" class="r big"><span>RESTE À PAYER</span><b>{{ money(vente.reste) }}</b></div>
    <hr />
    <div class="t-foot">{{ vente.ticket?.pied }}</div>
  </div>
</template>

<style scoped>
.ticket { width: min(100%, var(--w)); margin: 0 auto; padding: 14px 12px; background: #fff; color: #111; font: 12.5px/1.45 'Courier New', ui-monospace, monospace; border-radius: 8px; box-shadow: 0 0 0 1px #0001; }
.t-head, .t-meta { display: flex; flex-direction: column; text-align: center; gap: 1px; }
.t-head b { font-size: 15px; }
.t-meta { text-align: left; }
hr { border: 0; border-top: 1px dashed #777; margin: 8px 0; }
.r { display: flex; justify-content: space-between; gap: 10px; }
.r .n { flex: 1; min-width: 0; overflow-wrap: anywhere; }
.r.sub { font-size: 11px; color: #555; }
.r.big { font-size: 15px; font-weight: 700; }
.t-line { margin-bottom: 4px; }
.t-foot { text-align: center; margin-top: 4px; }
.t-annule { text-align: center; font-weight: 700; border: 2px solid #c00; color: #c00; padding: 3px; margin-top: 6px; }
</style>
