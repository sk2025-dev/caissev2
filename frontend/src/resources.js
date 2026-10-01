// Configuration déclarative des écrans CRUD : colonnes du tableau et champs du formulaire.
import { money, qty, uniteAccord } from './format'
import { fileUrl } from './api'

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
      { name: 'description', label: 'Description', type: 'textarea', full: true },
      { name: 'image', label: 'Image', type: 'file', dir: 'produits', accept: '.jpg,.jpeg,.png,.webp', full: true },
    ],
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
    pk: 'idcaisse', feminine: true, refreshLookups: true, write: (u) => !!u?.admin,
    title: 'Caisses', singular: 'caisse', subtitle: 'Points d\'encaissement de l\'entreprise',
    searchPlaceholder: 'Rechercher une caisse…',
    columns: [
      { key: 'nom', label: 'Nom', sort: 'nom', cls: 'strong' },
      { key: 'actif', label: 'État', badge: (r) => (Number(r.actif) ? { text: 'Active', cls: 'success' } : { text: 'Désactivée', cls: '' }) },
    ],
    fields: [
      { name: 'nom', label: 'Nom', type: 'text', required: true, full: true },
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
      { name: 'photo_user', label: 'Photo (carte virtuelle)', type: 'file', dir: 'utilisateurs', accept: '.jpg,.jpeg,.png,.webp', full: true },
    ],
  },
}


