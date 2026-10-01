<?php
// Dépenses saisies par le gérant hors session de caisse (coffre, banque, mobile money) : pas de session, origine des fonds.
return function (Migrator $m) {
    $m->db->exec('ALTER TABLE caisse_operations MODIFY idsession INT NULL');
    $m->ensureColumn('caisse_operations', 'source', "VARCHAR(20) NOT NULL DEFAULT 'caisse'");
};
