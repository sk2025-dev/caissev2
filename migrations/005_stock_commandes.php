<?php
// Les anciennes commandes restent sans sortie anticipée ; les nouvelles sortent le stock à la création.
return function (Migrator $m) {
    $m->ensureColumn('commandes', 'stock_debite', 'TINYINT NOT NULL DEFAULT 0');
};
