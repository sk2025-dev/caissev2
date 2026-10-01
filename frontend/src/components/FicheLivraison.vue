<script setup>
// Fiche de livraison (ou bon de retrait) imprimable : destinataire, lieu et zone, articles à contrôler, montant à encaisser,
// contrôle à la remise et signatures. Invisible à l'écran : seul élément imprimé (classe .print-area).
import { computed } from 'vue'
import { money, qty, number, dateHeure } from '../format'
import { datePrevue } from '../commandes'
import { logoUrl, nomApp } from '../theme'

const props = defineProps({ commande: { type: Object, required: true } })
const c = computed(() => props.commande)
const liv = computed(() => c.value.mode === 'livraison')
const ent = computed(() => c.value.entreprise || {})
const remise = computed(() => c.value.statut === 'livree')
const siege = computed(() => [ent.value.adresse, ent.value.ville].filter(Boolean).join(', '))
const imprimeLe = new Date().toISOString().slice(0, 19).replace('T', ' ')
</script>

<template>
  <div class="print-area fiche">
    <header>
      <div class="who"><img v-if="logoUrl" :src="logoUrl" alt="" /><div><b>{{ ent.nom || nomApp }}</b><span v-if="siege">{{ siege }}</span><span v-if="ent.telephone">Tél. {{ ent.telephone }}</span></div></div>
      <div class="ref"><h2>{{ liv ? 'FICHE DE LIVRAISON' : 'BON DE RETRAIT' }}</h2><b>{{ c.numero }}</b><span>Commande prise le {{ dateHeure(c.created_at) }}</span></div>
    </header>

    <div v-if="liv" class="zone">
      <span class="z">{{ c.zone_libelle }}</span>
      <b>{{ c.lieu_complet }}</b>
    </div>

    <div class="boxes">
      <div class="box"><h4>Destinataire</h4><p class="big">{{ c.client_nom }}</p><p>Tél. : <b>{{ c.client_tel || '—' }}</b></p></div>
      <div v-if="liv" class="box"><h4>Lieu de livraison</h4>
        <p><small>Zone</small> {{ c.zone_libelle }}</p>
        <p><small>{{ c.zone === 'abidjan' ? 'Commune / quartier' : c.zone === 'interieur' ? 'Ville' : 'Pays / ville' }}</small> <b>{{ c.lieu_complet }}</b></p>
        <p><small>Adresse précise</small> <b>{{ c.adresse }}</b></p>
      </div>
      <div class="box"><h4>Planification</h4>
        <p><small>{{ liv ? 'Livraison prévue' : 'Retrait prévu' }}</small> <b>{{ datePrevue(c.date_prevue) }}</b></p>
        <p v-if="liv"><small>Livreur</small> <b>{{ c.livreur || '................................' }}</b></p>
        <p v-if="c.note"><small>Consigne</small> {{ c.note }}</p>
      </div>
      <div class="box due"><h4>Montant à encaisser</h4>
        <p><small>Articles</small> {{ money(c.sous_total) }}</p>
        <p v-if="liv"><small>Livraison ({{ c.zone_libelle }})</small> {{ money(c.frais_livraison) }}</p>
        <p class="tot">{{ money(c.total) }}</p>
        <p v-if="!remise" class="modes">☐ Espèces &nbsp; ☐ Mobile Money &nbsp; ☐ Carte &nbsp; ☐ Déjà réglé</p>
        <p v-else class="modes">Encaissé — vente {{ c.vente_numero }}</p>
      </div>
    </div>

    <table class="art">
      <thead><tr><th class="ok">Vérifié</th><th>Désignation</th><th class="n">Qté</th><th class="n">Prix unitaire</th><th class="n">Total</th></tr></thead>
      <tbody>
        <tr v-for="l in c.lignes" :key="l.idligne"><td class="ok">☐</td><td>{{ l.designation }}</td><td class="n">{{ qty(l.quantite) }}</td><td class="n">{{ number(l.prix_unitaire) }}</td><td class="n">{{ money(l.total) }}</td></tr>
        <tr v-if="liv && Number(c.frais_livraison) > 0"><td class="ok" /><td>Frais de livraison — {{ c.lieu_complet }}</td><td class="n">1</td><td class="n">{{ number(c.frais_livraison) }}</td><td class="n">{{ money(c.frais_livraison) }}</td></tr>
      </tbody>
      <tfoot><tr><td colspan="4">TOTAL</td><td class="n">{{ money(c.total) }}</td></tr></tfoot>
    </table>

    <section class="ctrl">
      <h4>Contrôle à la remise</h4>
      <p>☐ Colis complet &nbsp;&nbsp; ☐ Produits en bon état &nbsp;&nbsp; ☐ Conforme à la commande<template v-if="liv"> &nbsp;&nbsp; ☐ Client joignable</template></p>
      <p class="obs"><small>Observations</small><template v-if="remise && c.observations"> {{ c.observations }}</template><i v-else /></p>
      <div class="times">
        <span>{{ liv ? 'Heure de départ' : 'Préparée à' }} : <i /></span>
        <span>{{ liv ? 'Heure de remise' : 'Heure de retrait' }} : <b v-if="remise">{{ dateHeure(c.livree_at) }}</b><i v-else /></span>
      </div>
    </section>

    <div class="sign">
      <div><span>{{ liv ? 'Le livreur' : 'Le vendeur' }}</span><i /><small>{{ liv ? (c.livreur || 'Nom et signature') : 'Nom et signature' }}</small></div>
      <div><span>Réceptionné par le client</span><b v-if="remise && c.receptionnaire" class="rec">{{ c.receptionnaire }}</b><i /><small>Nom, date et signature</small></div>
    </div>
    <footer>Merci de vérifier la marchandise en présence {{ liv ? 'du livreur' : 'du vendeur' }} avant de signer. · {{ c.numero }} · imprimée le {{ dateHeure(imprimeLe) }}</footer>
  </div>
</template>

<style scoped>
.fiche { display: none; }
@media print {
  .fiche { display: block !important; width: 100%; font-size: 12px; color: #000; line-height: 1.35; }
  header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #000; padding-bottom: 8px; }
  .who { display: flex; align-items: center; gap: 10px; } .who img { height: 44px; } .who b { display: block; font-size: 15px; } .who span { display: block; color: #444; font-size: 11px; }
  .ref { text-align: right; } .ref h2 { margin: 0; font-size: 19px; letter-spacing: .03em; } .ref b { font-size: 15px; display: block; } .ref span { font-size: 11px; color: #444; }
  .zone { display: flex; align-items: center; gap: 12px; margin: 12px 0 4px; padding: 10px 14px; border: 2.5px solid #000; border-radius: 6px; font-size: 16px; }
  .zone .z { background: #000; color: #fff !important; padding: 3px 12px; border-radius: 4px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; font-size: 13px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .boxes { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin: 8px 0; }
  .box { border: 1px solid #777; border-radius: 4px; padding: 7px 10px; break-inside: avoid; } .box h4 { margin: 0 0 4px; font-size: 10.5px; text-transform: uppercase; letter-spacing: .07em; color: #333; }
  .box p { margin: 2px 0; } .box small { display: inline-block; min-width: 92px; color: #555; font-size: 10.5px; } .box .big { font-size: 15px; font-weight: 700; }
  .box.due { border: 2px solid #000; } .box .tot { font-size: 20px; font-weight: 800; margin: 4px 0; } .box .modes { font-size: 11.5px; }
  .art { width: 100%; border-collapse: collapse; margin: 8px 0; } .art th, .art td { border-bottom: 1px solid #aaa; padding: 5px 6px; text-align: left; vertical-align: top; } .art th { border-bottom: 2px solid #000; font-size: 10.5px; text-transform: uppercase; }
  .art .n { text-align: right; white-space: nowrap; } .art .ok { width: 46px; text-align: center; font-size: 15px; } .art tfoot td { border-top: 2px solid #000; border-bottom: 0; font-weight: 800; font-size: 13px; } .art tr { break-inside: avoid; }
  .ctrl { border: 1px solid #777; border-radius: 4px; padding: 7px 10px; margin-top: 8px; break-inside: avoid; } .ctrl h4 { margin: 0 0 6px; font-size: 10.5px; text-transform: uppercase; letter-spacing: .07em; }
  .ctrl p { margin: 5px 0; } .obs { display: flex; gap: 8px; align-items: flex-end; } .obs small { color: #555; } .obs i { flex: 1; border-bottom: 1px solid #000; height: 34px; display: block; }
  .times { display: flex; gap: 30px; margin-top: 8px; } .times span { flex: 1; display: flex; gap: 6px; align-items: flex-end; } .times i { flex: 1; border-bottom: 1px solid #000; height: 16px; display: block; }
  .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 36px; margin-top: 18px; break-inside: avoid; } .sign span { font-weight: 700; font-size: 11px; display: block; } .sign i { display: block; height: 62px; border-bottom: 1px solid #000; } .sign small { color: #555; font-size: 10.5px; } .sign .rec { display: block; margin-top: 4px; }
  footer { margin-top: 14px; padding-top: 6px; border-top: 1px solid #aaa; text-align: center; font-size: 10px; color: #555; }
}
</style>
