<script setup>
// Rapport de caisse (« ticket Z ») : ventes, encaissements par mode, mouvements d'espèces, espèces attendues et écart.
import { money, dateHeure } from '../format'

defineProps({ rapport: { type: Object, required: true } })
</script>

<template>
  <div class="zr print-area">
    <h3>Rapport de caisse</h3>
    <div class="meta">
      <span>{{ rapport.session.caisse }} · {{ rapport.session.caissier }}</span>
      <span>Ouverte le {{ dateHeure(rapport.session.ouverture_at) }}</span>
      <span v-if="rapport.session.cloture_at">Clôturée le {{ dateHeure(rapport.session.cloture_at) }}</span>
      <span v-else class="open">Session en cours</span>
    </div>

    <h4>Ventes</h4>
    <div class="r"><span>Nombre de ventes</span><b>{{ rapport.ventes.nb }}</b></div>
    <div class="r"><span>Chiffre d'affaires</span><b>{{ money(rapport.ventes.ca) }}</b></div>
    <div class="r sub"><span>dont TVA</span><span>{{ money(rapport.ventes.tva) }}</span></div>
    <div class="r sub"><span>dont remises accordées</span><span>{{ money(rapport.ventes.remises) }}</span></div>
    <div v-if="rapport.ventes.credit > 0" class="r"><span>Vendu à crédit</span><b>{{ money(rapport.ventes.credit) }}</b></div>
    <div v-if="rapport.annulees.nb" class="r sub"><span>Ventes annulées ({{ rapport.annulees.nb }})</span><span>{{ money(rapport.annulees.montant) }}</span></div>

    <h4>Encaissements</h4>
    <div v-for="m in rapport.modes" :key="m.mode" class="r"><span>{{ m.libelle }} <small>({{ m.nb }})</small></span><b>{{ money(m.montant) }}</b></div>
    <div v-if="!rapport.modes.length" class="r sub"><span>Aucun encaissement</span></div>

    <h4>Tiroir-caisse (espèces)</h4>
    <div class="r"><span>Fond de caisse</span><span>{{ money(rapport.session.fond_initial) }}</span></div>
    <div class="r"><span>Ventes en espèces (rendu déduit)</span><span>{{ money(rapport.especes_ventes) }}</span></div>
    <div v-if="rapport.reglements_clients > 0" class="r"><span>Règlements de dettes clients</span><span>{{ money(rapport.reglements_clients) }}</span></div>
    <div v-for="o in rapport.operations" :key="o.idop" class="r sub"><span>{{ o.type === 'entree' ? 'Entrée' : 'Sortie' }} — {{ o.motif }}</span><span>{{ o.type === 'entree' ? '+' : '−' }}{{ money(o.montant) }}</span></div>
    <div class="r total"><span>Espèces attendues</span><b>{{ money(rapport.attendu_especes) }}</b></div>
    <template v-if="rapport.session.statut === 'cloturee'">
      <div class="r"><span>Espèces comptées</span><b>{{ money(rapport.session.compte_especes) }}</b></div>
      <div class="r total" :class="Math.abs(rapport.session.ecart) > 0 ? 'ko' : 'ok'"><span>Écart</span><b>{{ rapport.session.ecart > 0 ? '+' : '' }}{{ money(rapport.session.ecart) }}</b></div>
      <p v-if="rapport.session.notes" class="note">Note : {{ rapport.session.notes }}</p>
    </template>
  </div>
</template>

<style scoped>
.zr { font-size: 14px; }
h3 { font-size: 19px; margin: 0 0 4px; }
h4 { margin: 16px 0 6px; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); }
.meta { display: flex; flex-direction: column; color: var(--muted); font-size: 13px; }
.meta .open { color: var(--success); font-weight: 600; }
.r { display: flex; justify-content: space-between; gap: 12px; padding: 4px 0; border-bottom: 1px dashed var(--border); }
.r.sub { color: var(--muted); font-size: 13px; }
.r.total { font-size: 16px; border-bottom: 0; border-top: 2px solid var(--border); margin-top: 6px; padding-top: 8px; }
.r.total.ko b { color: var(--danger); }
.r.total.ok b { color: var(--success); }
.note { margin: 8px 0 0; padding: 8px 10px; border-radius: 10px; background: var(--surface-2); font-size: 13px; }
</style>
