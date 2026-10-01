<script setup>
// Rapport de caisse (« Z ») au format comptable : en-tête, indicateurs, ventes HT/TVA/TTC, TVA par taux, encaissements,
// détail des mouvements d'espèces, rapprochement du tiroir-caisse, contrôle de l'écart et signatures.
import { computed } from 'vue'
import { money, number, dateHeure, heure } from '../format'
import { logoUrl, nomApp } from '../theme'

const props = defineProps({ rapport: { type: Object, required: true } })
const s = computed(() => props.rapport.session)
const ouverte = computed(() => s.value.statut !== 'cloturee')
const ms = (v) => new Date(String(v).replace(' ', 'T') + 'Z').getTime()
const duree = computed(() => {
  const fin = s.value.cloture_at ? ms(s.value.cloture_at) : Date.now()
  const min = Math.max(0, Math.round((fin - ms(s.value.ouverture_at)) / 60000))
  return min >= 60 ? `${Math.floor(min / 60)} h ${String(min % 60).padStart(2, '0')}` : `${min} min`
})
const signe = (v) => (Number(v) > 0 ? '+' : '') + money(v)
const nom = computed(() => props.rapport.entreprise?.nom || nomApp.value)
const nbEntrees = computed(() => props.rapport.operations.filter((o) => o.type === 'entree').length)
const nbSorties = computed(() => props.rapport.operations.filter((o) => o.type === 'sortie').length)
const net = computed(() => Number(props.rapport.entrees_caisse) - Number(props.rapport.sorties_caisse))
const totalEncaisse = computed(() => props.rapport.modes.reduce((t, m) => t + Number(m.montant), 0))
const edite = new Date().toISOString().slice(0, 19).replace('T', ' ')
</script>

<template>
  <div class="zr print-area">
    <header class="top">
      <div class="who"><img v-if="logoUrl" :src="logoUrl" alt="" /><div><b>{{ nom }}</b><span>Rapport de caisse (Z)</span></div></div>
      <div class="ref"><b>{{ rapport.numero_z }}</b><span v-if="ouverte" class="prov">Provisoire — session en cours</span><span v-else>Définitif</span></div>
    </header>

    <dl class="meta">
      <div><dt>Caisse</dt><dd>{{ s.caisse }}</dd></div>
      <div><dt>Caissier</dt><dd>{{ s.caissier }}</dd></div>
      <div><dt>Durée</dt><dd>{{ duree }}</dd></div>
      <div><dt>Ouverture</dt><dd>{{ dateHeure(s.ouverture_at) }}</dd></div>
      <div><dt>Clôture</dt><dd>{{ s.cloture_at ? dateHeure(s.cloture_at) : '—' }}</dd></div>
      <div><dt>Tickets</dt><dd>{{ rapport.tickets.premier ? (rapport.tickets.premier === rapport.tickets.dernier ? rapport.tickets.premier : `${rapport.tickets.premier} → ${rapport.tickets.dernier}`) : 'Aucun' }}</dd></div>
    </dl>

    <div class="kpis">
      <div><span>Chiffre d'affaires TTC</span><b>{{ money(rapport.ventes.ca) }}</b></div>
      <div><span>Ventes</span><b>{{ rapport.ventes.nb }}</b></div>
      <div><span>Panier moyen</span><b>{{ money(rapport.panier_moyen) }}</b></div>
    </div>

    <h4>1. Ventes</h4>
    <table>
      <tbody>
        <tr><td>Ventes hors taxes</td><td class="n">{{ money(rapport.ventes_ht) }}</td></tr>
        <tr><td>TVA collectée</td><td class="n">{{ money(rapport.ventes.tva) }}</td></tr>
        <tr class="sum"><td>Total ventes TTC</td><td class="n">{{ money(rapport.ventes.ca) }}</td></tr>
        <tr class="sub"><td>dont remises accordées</td><td class="n">{{ money(rapport.ventes.remises) }}</td></tr>
        <tr v-if="rapport.ventes.credit > 0" class="sub"><td>dont vendu à crédit (créances)</td><td class="n">{{ money(rapport.ventes.credit) }}</td></tr>
        <tr v-if="rapport.annulees.nb" class="sub"><td>Ventes annulées ({{ rapport.annulees.nb }}) — non comptées</td><td class="n">{{ money(rapport.annulees.montant) }}</td></tr>
      </tbody>
    </table>

    <template v-if="rapport.tva_taux.length">
      <h4>2. Ventilation de la TVA</h4>
      <table class="grid">
        <thead><tr><th>Taux</th><th class="n">Base HT</th><th class="n">TVA</th><th class="n">TTC</th></tr></thead>
        <tbody><tr v-for="t in rapport.tva_taux" :key="t.taux"><td>{{ t.taux > 0 ? number(t.taux) + ' %' : 'Exonéré' }}</td><td class="n">{{ money(t.ht) }}</td><td class="n">{{ money(t.tva) }}</td><td class="n">{{ money(t.ttc) }}</td></tr></tbody>
      </table>
    </template>

    <h4>3. Encaissements par mode de paiement</h4>
    <table class="grid">
      <thead><tr><th>Mode</th><th class="n">Opérations</th><th class="n">Montant</th></tr></thead>
      <tbody>
        <tr v-for="m in rapport.modes" :key="m.mode"><td>{{ m.libelle }}<small v-if="m.type === 'especes'"> (net du rendu de monnaie)</small></td><td class="n">{{ m.nb }}</td><td class="n">{{ money(m.montant) }}</td></tr>
        <tr v-if="!rapport.modes.length"><td colspan="3" class="vide">Aucun encaissement</td></tr>
      </tbody>
      <tfoot v-if="rapport.modes.length"><tr><td>Total encaissé</td><td /><td class="n">{{ money(totalEncaisse) }}</td></tr></tfoot>
    </table>

    <h4>4. Mouvements d'espèces</h4>
    <table class="grid mvt">
      <thead><tr><th>N° pièce</th><th>Heure</th><th>Catégorie / libellé</th><th>Saisi par</th><th class="n">Entrée</th><th class="n">Sortie</th></tr></thead>
      <tbody>
        <tr v-for="o in rapport.operations" :key="o.idop">
          <td class="mono">{{ o.numero }}</td><td>{{ heure(o.created_at) }}</td>
          <td><b>{{ o.categorie_libelle }}</b><small>{{ o.motif }}<template v-if="o.reference"> · Justif. {{ o.reference }}</template></small></td>
          <td>{{ o.auteur }}</td>
          <td class="n">{{ o.type === 'entree' ? money(o.montant) : '' }}</td><td class="n">{{ o.type === 'sortie' ? money(o.montant) : '' }}</td>
        </tr>
        <tr v-if="!rapport.operations.length"><td colspan="6" class="vide">Aucun mouvement d'espèces pendant la session</td></tr>
      </tbody>
      <tfoot v-if="rapport.operations.length">
        <tr><td colspan="4">Total ({{ nbEntrees }} entrée{{ nbEntrees > 1 ? 's' : '' }}, {{ nbSorties }} sortie{{ nbSorties > 1 ? 's' : '' }})</td><td class="n">{{ money(rapport.entrees_caisse) }}</td><td class="n">{{ money(rapport.sorties_caisse) }}</td></tr>
        <tr class="sub"><td colspan="4">Solde net des mouvements</td><td class="n" colspan="2">{{ signe(net) }}</td></tr>
      </tfoot>
    </table>

    <h4>5. Rapprochement du tiroir-caisse (espèces)</h4>
    <table>
      <tbody>
        <tr><td>Fond de caisse à l'ouverture</td><td class="n">{{ money(s.fond_initial) }}</td></tr>
        <tr><td>+ Ventes encaissées en espèces <small>(rendu de monnaie déduit)</small></td><td class="n">{{ money(rapport.especes_ventes) }}</td></tr>
        <tr v-if="rapport.reglements_clients > 0"><td>+ Règlements de dettes clients</td><td class="n">{{ money(rapport.reglements_clients) }}</td></tr>
        <tr><td>+ Entrées d'espèces</td><td class="n">{{ money(rapport.entrees_caisse) }}</td></tr>
        <tr><td>− Sorties d'espèces</td><td class="n">−{{ money(rapport.sorties_caisse) }}</td></tr>
        <tr class="sum"><td>Espèces attendues dans le tiroir</td><td class="n">{{ money(rapport.attendu_especes) }}</td></tr>
        <template v-if="!ouverte">
          <tr><td>Espèces comptées</td><td class="n">{{ money(s.compte_especes) }}</td></tr>
          <tr class="ecart" :class="Number(s.ecart) === 0 ? 'ok' : Number(s.ecart) < 0 ? 'ko' : 'plus'"><td>Écart de caisse <small>({{ Number(s.ecart) === 0 ? 'caisse juste' : Number(s.ecart) < 0 ? 'manque' : 'excédent' }})</small></td><td class="n">{{ signe(s.ecart) }}</td></tr>
        </template>
      </tbody>
    </table>
    <p v-if="s.notes" class="note"><b>Note du caissier :</b> {{ s.notes }}</p>

    <div class="sign">
      <div><span>Le caissier</span><i /><small>{{ s.caissier }}</small></div>
      <div><span>Vérifié par le responsable</span><i /><small>Date et signature</small></div>
    </div>
    <footer>{{ rapport.numero_z }} · {{ nom }} · rapport généré le {{ dateHeure(edite) }}</footer>
  </div>
</template>

<style scoped>
.zr { font-size: 13.5px; line-height: 1.4; }
.top { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; padding-bottom: 12px; border-bottom: 3px solid var(--primary); }
.who { display: flex; align-items: center; gap: 12px; } .who img { height: 42px; max-width: 120px; object-fit: contain; }
.who b { display: block; font-size: 17px; } .who span { color: var(--muted); font-size: 13px; }
.ref { text-align: right; } .ref b { display: block; font-size: 20px; letter-spacing: .03em; color: var(--primary); } .ref span { font-size: 12px; color: var(--muted); } .ref .prov { color: var(--warning); font-weight: 700; }
.meta { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px 14px; margin: 12px 0; }
.meta dt { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); } .meta dd { margin: 0; font-weight: 600; }
.kpis { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin: 4px 0 6px; }
.kpis > div { padding: 10px 12px; border-radius: 12px; background: var(--primary-soft); display: flex; flex-direction: column; } .kpis span { font-size: 11.5px; color: var(--muted); } .kpis b { font-size: 17px; color: var(--primary); }
h4 { margin: 18px 0 6px; font-size: 12px; text-transform: uppercase; letter-spacing: .07em; color: var(--primary); }
table { width: 100%; border-collapse: collapse; }
td, th { padding: 5px 6px; vertical-align: top; } .n { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
tbody tr { border-bottom: 1px dashed var(--border); }
.grid th { text-align: left; font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); border-bottom: 2px solid var(--border); } .grid th.n { text-align: right; }
.grid tfoot td { font-weight: 700; border-top: 2px solid var(--border); }
.sub td { color: var(--muted); font-size: 12.5px; }
.sum td { font-weight: 800; font-size: 15px; border-top: 2px solid var(--border); border-bottom: 0; }
td small { color: var(--muted); } .mvt td b { display: block; font-weight: 600; } .mvt td small { display: block; }
.mono { font-family: ui-monospace, Menlo, monospace; font-size: 12px; white-space: nowrap; }
.vide { text-align: center; color: var(--muted); padding: 12px; }
.ecart td { font-size: 16px; font-weight: 800; border: 0; } .ecart.ok td:last-child { color: var(--success); } .ecart.ko td:last-child { color: var(--danger); } .ecart.plus td:last-child { color: var(--warning); }
.note { margin: 10px 0 0; padding: 8px 10px; border-radius: 10px; background: var(--surface-2); font-size: 13px; }
.sign { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; margin-top: 26px; } .sign span { font-size: 12px; font-weight: 700; } .sign i { display: block; height: 46px; border-bottom: 1px solid var(--text); } .sign small { color: var(--muted); font-size: 11.5px; }
footer { margin-top: 18px; padding-top: 8px; border-top: 1px solid var(--border); color: var(--muted); font-size: 11px; text-align: center; }
@media (max-width: 560px) { .meta, .kpis { grid-template-columns: 1fr 1fr; } }
@media print { .kpis > div { background: #f3f0fa !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; } .zr { color: #000; } }
</style>
