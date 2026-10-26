<?php
// Catalogue numérique (QR code) : chaque produit peut être retiré de la vitrine publique sans être masqué en caisse.
return function (Migrator $m) {
    $m->ensureColumn('produits', 'catalogue', 'TINYINT NOT NULL DEFAULT 1');
};
