// Configuration déclarative des écrans CRUD : colonnes du tableau et champs du formulaire.
import { money, qty, uniteAccord } from './format'
import { fileUrl } from './api'
import { SOURCES, NOTES, sourceOptions, sourceChoix, etoiles } from './avis'

// Champs du formulaire d'avis ; fixerProduit : avis saisi depuis une fiche produit (le produit est imposé)
export function avisChamps(fixerProduit = null) {
  return [
    fixerProduit
      ? { name: 'idprod', label: 'Produit', type: 'text', default: () => fixerProduit, showIf: () => false }
      : { name: 'idprod', label: 'Produit concerné', type: 'select', full: true, options: (l) => [{ value: '', label: 'La boutique en général' }, ...(l.produits || []).map((p) => ({ value: p.id, label: p.nom }))] },
    { name: 'source', label: 'Avis reçu sur', type: 'choice', required: true, full: true, default: () => 'facebook', options: sourceChoix },
    { name: 'note', label: 'Note du client', type: 'stars', required: true, full: true, default: () => 5, labels: NOTES },
    { name: 'auteur', label: 'Nom du client', type: 'text' },
    { name: 'telephone', label: 'Téléphone (avis par SMS / WhatsApp)', type: 'phone' },
    { name: 'date_avis', label: "Date de l'avis", type: 'date', default: () => new Date().toISOString().slice(0, 10) },
    { name: 'commentaire', label: 'Commentaire du client', type: 'textarea', full: true },
    { name: 'lien', label: "Lien vers l'avis (Facebook, Google, TikTok…)", type: 'url', full: true },
    { name: 'capture', label: "Capture d'écran (preuve)", type: 'file', dir: 'avis', accept: '.jpg,.jpeg,.png,.webp', full: true },
    { name: 'reponse', label: 'Notre réponse', type: 'textarea', full: true },
  ]
}

const oui = [{ value: 1, label: 'Oui' }, { value: 0, label: 'Non' }]
const catalogue = (u) => !!u?.droits?.catalogue_ecriture

export const resources = {
  produits: {
    pk: 'idprod', detail: (r) => `/produits/${r.idprod}`, needsLookups: true, refreshLookups: true, write: catalogue,
    title: 'Produits et services', singular: 'produit', subtitle: 'Catalogue vendu en caisse : prix, TVA, seuils d\'alerte',
    searchPlaceholder: 'Nom, référence, code-barres…',
    filters: [
      { key: 'type', label: 'Type', options: [{ value: '', label: 'Produits et services' }, { value: 'produit', label: 'Produits' }, { value: 'service', label: 'Services' }] },
      { key: 'etat', label: 'Stock', options: [{ value: '', label: 'Tous les stocks' }, { value: 'rupture', label: 'En rupture' }, { value: 'bas', label: 'Stock bas' }, { value: 'ok', label: 'Stock correct' }] },
    ],
    columns: [
      { key: 'nom', label: 'Désignation', sort: 'nom', cls: 'strong' },
      { key: 'categorie', label: 'Catégorie', sort: 'categorie' },
      { key: 'prix_vente', label: 'Prix de vente', sort: 'prix_vente', align: 'num', format: (r) => money(r.prix_vente) },
      { key: 'stock_qty', label: 'Stock', sort: 'stock_qty', align: 'num', badge: (r) => {
        if (!Number(r.stockable)) return { text: 'Service', cls: 'primary' }
        const q = Number(r.stock_qty)
        const cls = q <= 0 ? 'danger' : Number(r.seuil_alerte) > 0 && q <= Number(r.seuil_alerte) ? 'warning' : 'success'
        return { text: `${qty(q)} ${uniteAccord(q, r.unite)}`, cls }
      } },
      { key: 'marge', label: 'Marge unitaire', sort: 'marge', align: 'num', format: (r) => (Number(r.stockable) ? money(r.marge) : '—') },
      { key: 'actif', label: 'État', badge: (r) => (Number(r.actif) ? null : { text: 'Masqué en caisse', cls: '' }), format: () => '' },
    ],
    fields: [
      { name: 'type', label: 'Type', type: 'select', required: true, default: () => 'produit', options: () => [{ value: 'produit', label: 'Produit (suivi en stock)' }, { value: 'service', label: 'Service (sans stock)' }] },
      { name: 'nom', label: 'Désignation', type: 'text', required: true, full: true },
      { name: 'idcat', label: 'Catégorie', type: 'select', options: (l) => l.categories.map((c) => ({ value: c.id, label: c.nom })),
        create: { label: 'catégorie', resource: 'categories', lookup: 'categories', idKey: 'idcat', nameField: 'nom', fields: [{ name: 'nom', label: 'Nom de la catégorie' }] } },
      { name: 'sku', label: 'Référence', type: 'text', editHint: 'Laissez vide pour une référence automatique.' },
      { name: 'code_barres', label: 'Code-barres', type: 'text', showIf: (f) => f.type !== 'service' },
      { name: 'prix_vente', label: 'Prix de vente TTC', type: 'number', required: true },
      { name: 'prix_achat', label: 'Prix d\'achat', type: 'number', showIf: (f) => f.type !== 'service', editHint: 'Mis à jour automatiquement à chaque réception (coût moyen).' },
      { name: 'tva_taux', label: 'TVA (%)', type: 'number', default: () => 0, step: '0.01' },
      { name: 'unite', label: 'Unité', type: 'text', showIf: (f) => f.type !== 'service', default: () => 'pièce' },
      { name: 'stock_initial', label: 'Stock initial', type: 'number', step: '0.001', createOnly: true, showIf: (f) => f.type !== 'service' },
      { name: 'seuil_alerte', label: 'Seuil d\'alerte', type: 'number', step: '0.001', showIf: (f) => f.type !== 'service' },
      { name: 'actif', label: 'Vendu en caisse', type: 'select', default: () => 1, options: () => oui },
      { name: 'catalogue', label: 'Visible dans le catalogue numérique', type: 'select', default: () => 1, options: () => oui },
      { name: 'description', label: 'Description', type: 'textarea', full: true },
      { name: 'marque', label: 'Marque', type: 'text' },
      { name: 'modele', label: 'Modèle / gamme', type: 'text' },
      { name: 'origine', label: 'Origine (pays de fabrication)', type: 'text' },
      { name: 'couleur', label: 'Couleur', type: 'text', showIf: (f) => f.type !== 'service' },
      { name: 'dimensions', label: 'Dimensions / taille', type: 'text', showIf: (f) => f.type !== 'service' },
      { name: 'poids', label: 'Poids / contenance', type: 'text', showIf: (f) => f.type !== 'service' },
      { name: 'garantie', label: 'Garantie', type: 'text' },
      { name: 'caracteristiques', label: 'Caractéristiques', type: 'textarea', full: true, hint: () => 'Une par ligne, sous la forme « Nom : valeur » (ex. Matière : coton). Affichées en tableau sur la fiche.' },
      { name: 'image', label: 'Photo principale', type: 'file', dir: 'produits', accept: '.jpg,.jpeg,.png,.webp', full: true, editHint: 'Les autres photos se gèrent dans la galerie de la fiche produit.' },
    ],
  },

  avis: {
    pk: 'idavis', needsLookups: true, write: catalogue, feminine: false,
    title: 'Avis clients', singular: 'avis', subtitle: 'Avis reçus sur Facebook, Google, TikTok, Instagram, WhatsApp, par SMS ou en magasin',
    searchPlaceholder: 'Client, commentaire, produit…',
    filters: [
      { key: 'idprod', label: 'Produit', options: (l) => [{ value: '', label: 'Tous les produits' }, ...(l.produits || []).map((p) => ({ value: String(p.id), label: p.nom }))] },
      { key: 'source', label: 'Source', options: [{ value: '', label: 'Toutes les sources' }, ...sourceOptions()] },
      { key: 'note', label: 'Note', options: [{ value: '', label: 'Toutes les notes' }, ...[5, 4, 3, 2, 1].map((n) => ({ value: n, label: `${n} étoile${n > 1 ? 's' : ''}` }))] },
    ],
    columns: [
      { key: 'date_avis', label: 'Date', sort: 'date_avis', format: (r) => (r.date_avis ? new Date(r.date_avis).toLocaleDateString('fr-FR') : '—') },
      { key: 'source', label: 'Source', sort: 'source', badge: (r) => ({ text: SOURCES[r.source]?.label || r.source, cls: 'primary' }) },
      { key: 'note', label: 'Note', sort: 'note', badge: (r) => ({ text: etoiles(r.note), cls: r.note >= 4 ? 'success' : r.note === 3 ? 'warning' : 'danger' }) },
      { key: 'auteur', label: 'Client', sort: 'auteur', cls: 'strong', format: (r) => r.auteur || 'Anonyme' },
      { key: 'produit', label: 'Produit', sort: 'produit', format: (r) => r.produit || 'La boutique' },
      { key: 'commentaire', label: 'Commentaire', format: (r) => (r.commentaire || '').length > 90 ? r.commentaire.slice(0, 90) + '…' : r.commentaire || '—' },
    ],
    fields: avisChamps(),
  },

  categories: {
    pk: 'idcat', refreshLookups: true, feminine: true, write: catalogue,
    title: 'Catégories', singular: 'catégorie', subtitle: 'Classement du catalogue et des boutons de la caisse',
    searchPlaceholder: 'Rechercher une catégorie…',
    columns: [
      { key: 'nom', label: 'Nom', sort: 'nom', cls: 'strong', thumb: (r) => (r.image ? fileUrl('categories', r.image) : '') },
      { key: 'nb_produits', label: 'Produits', align: 'num', format: (r) => r.nb_produits },
      { key: 'ordre', label: 'Ordre', sort: 'ordre', align: 'num', format: (r) => r.ordre },
    ],
    fields: [
      { name: 'nom', label: 'Nom', type: 'text', required: true, full: true },
      { name: 'ordre', label: 'Ordre d\'affichage', type: 'number', step: '1', default: () => 0 },
      { name: 'image', label: 'Photo de la catégorie (tuile de la caisse)', type: 'file', dir: 'categories', accept: '.jpg,.jpeg,.png,.webp', full: true },
    ],
  },

  clients: {
    pk: 'idclient', detail: (r) => `/clients/${r.idclient}`, write: (u) => !!u?.droits?.clients_ecriture,
    title: 'Clients', singular: 'client', subtitle: 'Fichier clients, ventes à crédit et créances',
    searchPlaceholder: 'Nom ou téléphone…',
    filters: [{ key: 'etat', label: 'Dette', options: [{ value: '', label: 'Tous les clients' }, { value: 'debiteur', label: 'Avec une dette' }] }],
    columns: [
      { key: 'nom', label: 'Nom', sort: 'nom', cls: 'strong' },
      { key: 'telephone', label: 'Téléphone' },
      { key: 'nb_achats', label: 'Achats', align: 'num', format: (r) => r.nb_achats },
      { key: 'total_achats', label: 'Total acheté', sort: 'total_achats', align: 'num', format: (r) => money(r.total_achats) },
      { key: 'solde', label: 'Dette en cours', sort: 'solde', align: 'num', badge: (r) => (Number(r.solde) > 0 ? { text: money(r.solde), cls: 'danger' } : { text: 'À jour', cls: 'success' }) },
    ],
    fields: [
      { name: 'nom', label: 'Nom', type: 'text', required: true, full: true },
      { name: 'telephone', label: 'Téléphone', type: 'phone' },
      { name: 'email', label: 'E-mail', type: 'email' },
      { name: 'adresse', label: 'Adresse', type: 'text', full: true },
      { name: 'plafond_credit', label: 'Plafond de crédit', type: 'number', default: () => 0, editHint: '0 = pas de crédit autorisé.' },
      { name: 'notes', label: 'Notes', type: 'textarea', full: true },
    ],
  },

  fournisseurs: {
    pk: 'idfour', detail: (r) => `/fournisseurs/${r.idfour}`, write: (u) => !!u?.droits?.fournisseurs, refreshLookups: true,
    title: 'Fournisseurs', singular: 'fournisseur', subtitle: 'Approvisionnements et dettes fournisseurs',
    searchPlaceholder: 'Nom, contact ou téléphone…',
    filters: [{ key: 'etat', label: 'Dette', options: [{ value: '', label: 'Tous les fournisseurs' }, { value: 'debiteur', label: 'Que nous devons' }] }],
    columns: [
      { key: 'nom', label: 'Nom', sort: 'nom', cls: 'strong' },
      { key: 'contact', label: 'Contact' },
      { key: 'telephone', label: 'Téléphone' },
      { key: 'total_achats', label: 'Total acheté', align: 'num', format: (r) => money(r.total_achats) },
      { key: 'solde', label: 'Nous devons', sort: 'solde', align: 'num', badge: (r) => (Number(r.solde) > 0 ? { text: money(r.solde), cls: 'warning' } : { text: 'Soldé', cls: 'success' }) },
    ],
    fields: [
      { name: 'nom', label: 'Nom', type: 'text', required: true, full: true },
      { name: 'contact', label: 'Personne à contacter', type: 'text' },
      { name: 'telephone', label: 'Téléphone', type: 'phone' },
      { name: 'email', label: 'E-mail', type: 'email' },
      { name: 'adresse', label: 'Adresse', type: 'text', full: true },
      { name: 'notes', label: 'Notes', type: 'textarea', full: true },
    ],
  },

  caisses: {
    pk: 'idcaisse', feminine: true, refreshLookups: true, write: (u) => !!u?.admin, detail: (r) => `/caisses/${r.idcaisse}`,
    title: 'Caisses', singular: 'caisse', subtitle: 'Points d\'encaissement : produits vendus, numérotation et ticket propres à chaque caisse (cliquez sur une caisse pour la paramétrer)',
    searchPlaceholder: 'Rechercher une caisse…',
    columns: [
      { key: 'nom', label: 'Nom', sort: 'nom', cls: 'strong' },
      { key: 'emplacement', label: 'Emplacement', format: (r) => r.emplacement || '—' },
      { key: 'assortiment', label: 'Produits vendus', badge: (r) => (r.assortiment === 'selection' ? { text: 'Sélection', cls: 'primary' } : { text: 'Tous', cls: '' }) },
      { key: 'prefixe', label: 'Numérotation', format: (r) => (r.prefixe ? `${r.prefixe}-…` : 'Commune') },
      { key: 'ticket_perso', label: 'Ticket', badge: (r) => (Number(r.ticket_perso) ? { text: 'Personnalisé', cls: 'primary' } : { text: 'Commun', cls: '' }) },
      { key: 'actif', label: 'État', badge: (r) => (Number(r.actif) ? { text: 'Active', cls: 'success' } : { text: 'Désactivée', cls: '' }) },
    ],
    fields: [
      { name: 'nom', label: 'Nom', type: 'text', required: true, full: true, hint: () => 'Produits vendus, numérotation et ticket se règlent ensuite sur la fiche de la caisse.' },
      { name: 'actif', label: 'Active', type: 'select', default: () => 1, options: () => oui },
    ],
  },

  'zones-livraison': {
    pk: 'idzone', refreshLookups: true, write: (u) => !!u?.admin,
    title: 'Tarifs de livraison', singular: 'tarif', subtitle: 'Prix de la livraison par lieu : quartiers d\'Abidjan, villes de l\'intérieur, pays voisins',
    searchPlaceholder: 'Quartier, commune, ville…',
    filters: [{ key: 'zone', label: 'Zone', options: [{ value: '', label: 'Toutes les zones' }, { value: 'abidjan', label: 'Abidjan' }, { value: 'interieur', label: 'Intérieur du pays' }, { value: 'exterieur', label: 'Extérieur' }] }],
    columns: [
      { key: 'zone', label: 'Zone', sort: 'zone', badge: (r) => ({ text: ({ abidjan: 'Abidjan', interieur: 'Intérieur', exterieur: 'Extérieur' })[r.zone], cls: r.zone === 'abidjan' ? 'primary' : r.zone === 'interieur' ? 'warning' : 'success' }), format: () => '' },
      { key: 'commune', label: 'Commune', sort: 'commune', format: (r) => r.commune || '—' },
      { key: 'nom', label: 'Quartier / ville / pays', sort: 'nom', cls: 'strong' },
      { key: 'prix', label: 'Prix', sort: 'prix', align: 'num', format: (r) => money(r.prix) },
      { key: 'delai', label: 'Délai', format: (r) => r.delai || '—' },
      { key: 'actif', label: 'État', badge: (r) => (Number(r.actif) ? null : { text: 'Désactivé', cls: '' }), format: () => '' },
    ],
    fields: [
      { name: 'zone', label: 'Zone', type: 'select', required: true, default: () => 'abidjan', options: () => [{ value: 'abidjan', label: 'Abidjan (par commune et quartier)' }, { value: 'interieur', label: 'Intérieur du pays (par ville)' }, { value: 'exterieur', label: 'Extérieur (hors Côte d\'Ivoire)' }] },
      { name: 'commune', label: 'Commune', type: 'text', showIf: (f) => f.zone === 'abidjan', editHint: 'Ex. Cocody, Yopougon, Marcory… regroupe les quartiers dans la caisse.' },
      { name: 'nom', label: 'Quartier, ville ou pays', type: 'text', required: true, full: true },
      { name: 'prix', label: 'Prix de la livraison', type: 'number', required: true, step: '1' },
      { name: 'delai', label: 'Délai indicatif', type: 'text', editHint: 'Ex. Le jour même, 24 à 48 h, 3 à 5 jours.' },
      { name: 'actif', label: 'Proposé à la prise de commande', type: 'select', default: () => 1, options: () => oui },
    ],
  },

  utilisateurs: {
    pk: 'id_user', deleteLabel: 'Désactiver', needsLookups: true,
    rowActions: [{ icon: 'user', label: 'Carte virtuelle', to: (r) => `/carte/${r.id_user}` }],
    title: 'Utilisateurs', singular: 'utilisateur', subtitle: 'Comptes et rôles : caissier, magasinier, gérant',
    searchPlaceholder: 'Nom ou e-mail…',
    columns: [
      { key: 'nomag', label: 'Nom', sort: 'nomag', cls: 'strong', format: (r) => [r.nomag, r.prenom].filter(Boolean).join(' ') },
      { key: 'emailag', label: 'E-mail', sort: 'emailag' },
      { key: 'groupe', label: 'Rôle', sort: 'groupe', badge: (r) => ({ text: ({ admin: 'Gérant', caissier: 'Caissier', magasinier: 'Magasinier', superadmin: 'Super administrateur' })[r.groupe] || r.groupe || '—', cls: 'primary' }) },
      { key: 'caisse_fixe', label: 'Caisses', badge: (r) => (r.caisse_fixe ? { text: r.caisse_fixe, cls: 'warning' } : { text: 'Toutes', cls: '' }) },
      { key: 'user_status', label: 'Compte', badge: (r) => (Number(r.user_status) ? { text: 'Actif', cls: 'success' } : { text: 'Désactivé', cls: 'danger' }) },
    ],
    fields: [
      { name: 'nomag', label: 'Nom', type: 'text', required: true },
      { name: 'prenom', label: 'Prénom', type: 'text' },
      { name: 'emailag', label: 'Adresse e-mail', type: 'email', required: true, full: true },
      { name: 'telag', label: 'Téléphone', type: 'phone' },
      { name: 'gpe', label: 'Rôle', type: 'select', required: true, options: (l) => l.groupes.map((g) => ({ value: g.id, label: ({ admin: 'Gérant', caissier: 'Caissier', magasinier: 'Magasinier', superadmin: 'Super administrateur' })[g.nom] || g.nom })) },
      { name: 'pass', label: 'Mot de passe', type: 'password', requiredOnCreate: true, editHint: 'Laissez vide pour conserver le mot de passe actuel.' },
      { name: 'user_status', label: 'Compte actif', type: 'select', default: () => 1, options: () => oui },
      { name: 'idcaisse_fixe', label: 'Caisse autorisée', type: 'select', default: () => '', full: true,
        options: (l) => [{ value: '', label: 'Toutes les caisses (il choisit à l\'ouverture)' }, ...l.caisses.map((c) => ({ value: c.id, label: `Uniquement « ${c.nom} »` }))],
        hint: () => 'Une caisse imposée : l\'utilisateur ne peut ouvrir que celle-ci, sans pouvoir changer de caisse.' },
      { name: 'photo_user', label: 'Photo (carte virtuelle)', type: 'file', dir: 'utilisateurs', accept: '.jpg,.jpeg,.png,.webp', full: true },
    ],
  },
}


