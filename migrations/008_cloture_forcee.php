<?php
// Clôture forcée d'une caisse restée ouverte (super administrateur) : auteur et motif, sans comptage des espèces.
return function (Migrator $m) {
    $m->ensureColumn('sessions_caisse', 'forcee_par', 'INT NULL');
    $m->ensureColumn('sessions_caisse', 'forcee_motif', "VARCHAR(200) NOT NULL DEFAULT ''");
};
