<?php
// Affectation d'un utilisateur à une seule caisse (NULL = il peut ouvrir toutes les caisses).
return function (Migrator $m) {
    $m->ensureColumn('users', 'idcaisse_fixe', 'INT NULL');
};
