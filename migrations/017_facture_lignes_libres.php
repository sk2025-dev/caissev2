<?php
// Confidentialité : les lignes de facture fournisseur sont des saisies libres, jamais rattachées aux produits du catalogue.
// La désignation déjà copiée sur chaque ligne est conservée ; seul le lien vers le produit disparaît.
return function (Migrator $m) {
    if ($m->columnExists('fournisseur_facture_lignes', 'idprod')) {
        $m->db->exec('ALTER TABLE fournisseur_facture_lignes DROP COLUMN idprod');
        echo "    - colonne fournisseur_facture_lignes.idprod\n";
    }
};
