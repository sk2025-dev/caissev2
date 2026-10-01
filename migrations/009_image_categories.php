<?php
// Photo de catégorie (tuiles de la caisse, liste des catégories).
return function (Migrator $m) {
    $m->ensureColumn('categories', 'image', "VARCHAR(255) NOT NULL DEFAULT ''");
};
