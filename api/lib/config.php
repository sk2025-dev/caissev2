<?php
/** Configuration de l'application (super administrateur) : validation des sections et vues publique / complète. */

function cfg_err($champ, $msg) { throw new ApiError(422, 'Données invalides', [$champ => $msg]); }

/** Adresse web, conservée telle que saisie : "www.exemple.com" ou "https://www.exemple.com". */
function cfg_url(array $in, $k, $max, $defaut)
{
    $v = cfg_texte($in, $k, $max, $defaut);
    if ($v !== '' && !preg_match('#^(https?://)?[^\s/$.?\#]+\.[^\s]+$#i', $v)) cfg_err($k, "Adresse invalide (ex. www.exemple.com)");
    return $v;
}

function cfg_texte(array $in, $k, $max, $defaut = '')
{
    $v = isset($in[$k]) ? trim((string)$in[$k]) : $defaut;
    if (mb_strlen($v) > $max) cfg_err($k, "$max caractères maximum");
    return $v;
}

/** Valide une section et renvoie les couples "section.cle" => valeur à enregistrer. */
function config_valider($section, array $in, array $all)
{
    $o = [];
    switch ($section) {
        case 'entreprise':
            foreach (['nom' => 120, 'forme' => 60, 'adresse' => 200, 'ville' => 80, 'pays' => 80, 'telephone' => 40, 'rccm' => 60, 'nif' => 60] as $k => $max) $o["entreprise.$k"] = cfg_texte($in, $k, $max, $all["entreprise.$k"]);
            $email = cfg_texte($in, 'email', 150, $all['entreprise.email']);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) cfg_err('email', 'E-mail invalide');
            $site = cfg_url($in, 'site', 200, $all['entreprise.site']);
            $dev = cfg_texte($in, 'devise', 10, $all['entreprise.devise']);
            if ($dev === '') cfg_err('devise', 'Devise obligatoire');
            $o['entreprise.email'] = $email; $o['entreprise.site'] = $site; $o['entreprise.devise'] = $dev;
            if (array_key_exists('logo', $in) && $in['logo'] === '') $o['entreprise.logo'] = '';   // suppression du logo (le dépôt passe par config-logo)
            if (trim($o['entreprise.nom']) === '') cfg_err('nom', "Le nom de l'entreprise est obligatoire");
            break;
        case 'copyright':
            $o['copyright.nom'] = cfg_texte($in, 'nom', 120, $all['copyright.nom']);
            $url = cfg_url($in, 'url', 200, $all['copyright.url']);
            $o['copyright.url'] = $url;
            break;
        case 'theme':
            $pal = isset($in['palette']) ? (string)$in['palette'] : $all['theme.palette'];
            if (!isset(PALETTES[$pal])) cfg_err('palette', 'Palette inconnue');
            $mode = isset($in['mode']) ? (string)$in['mode'] : $all['theme.mode'];
            if (!in_array($mode, ['auto', 'clair', 'sombre'], true)) cfg_err('mode', 'Mode inconnu');
            $o['theme.palette'] = $pal; $o['theme.mode'] = $mode;
            break;
        case 'documents':   // en-tête et pied de page des exports (PDF, Excel)
            $o['documents.entete'] = cfg_texte($in, 'entete', 150, $all['documents.entete']);
            $o['documents.pied'] = cfg_texte($in, 'pied', 300, $all['documents.pied']);
            break;
        case 'licence':
            $debut = isset($in['debut']) ? trim((string)$in['debut']) : $all['licence.debut'];
            $duree = isset($in['duree_mois']) ? trim((string)$in['duree_mois']) : $all['licence.duree_mois'];
            if ($debut !== '') { $d = DateTime::createFromFormat('Y-m-d', $debut); if (!$d || $d->format('Y-m-d') !== $debut) cfg_err('debut', 'Date invalide'); }
            if ($duree !== '' && (filter_var($duree, FILTER_VALIDATE_INT) === false || $duree < 1 || $duree > 240)) cfg_err('duree_mois', 'Durée de 1 à 240 mois');
            if (($debut === '') !== ($duree === '')) cfg_err($debut === '' ? 'debut' : 'duree_mois', "Renseignez à la fois le début et la durée, ou laissez les deux vides (utilisation illimitée)");
            $apres = isset($in['apres']) ? (string)$in['apres'] : $all['licence.apres'];
            if (!in_array($apres, ['lecture_seule', 'bloque'], true)) cfg_err('apres', 'Choix inconnu');
            $o['licence.debut'] = $debut; $o['licence.duree_mois'] = $duree; $o['licence.apres'] = $apres; $o['licence.note'] = cfg_texte($in, 'note', 300, $all['licence.note']);
            break;
        case 'session':
            $min = isset($in['minutes']) ? trim((string)$in['minutes']) : $all['session.minutes'];
            if (filter_var($min, FILTER_VALIDATE_INT) === false || $min < 0 || $min > 1440) cfg_err('minutes', 'De 0 (jamais) à 1440 minutes');
            $o['session.minutes'] = (string)(int)$min;
            break;
        case 'mail':
            $o['mail.actif'] = !empty($in['actif']) && $in['actif'] !== '0' ? '1' : '0';
            $tr = isset($in['transport']) ? (string)$in['transport'] : $all['mail.transport'];
            if (!in_array($tr, ['mail', 'smtp'], true)) cfg_err('transport', 'Transport inconnu');
            $sec = isset($in['smtp_securite']) ? (string)$in['smtp_securite'] : $all['mail.smtp_securite'];
            if (!in_array($sec, ['tls', 'ssl', 'none'], true)) cfg_err('smtp_securite', 'Sécurité inconnue');
            $port = isset($in['smtp_port']) ? trim((string)$in['smtp_port']) : $all['mail.smtp_port'];
            if (filter_var($port, FILTER_VALIDATE_INT) === false || $port < 1 || $port > 65535) cfg_err('smtp_port', 'Port invalide');
            $exp = cfg_texte($in, 'expediteur', 150, $all['mail.expediteur']);
            if ($exp !== '' && !filter_var($exp, FILTER_VALIDATE_EMAIL)) cfg_err('expediteur', 'E-mail invalide');
            $dest = isset($in['destinataires']) ? (string)$in['destinataires'] : $all['mail.destinataires'];
            foreach (preg_split('/[\s,;]+/', $dest, -1, PREG_SPLIT_NO_EMPTY) as $m) if (!filter_var($m, FILTER_VALIDATE_EMAIL)) cfg_err('destinataires', "Adresse invalide : $m");
            if (count(alertes_destinataires($dest)) > 10) cfg_err('destinataires', '10 destinataires maximum');
            $o += ['mail.transport' => $tr, 'mail.smtp_securite' => $sec, 'mail.smtp_port' => (string)(int)$port, 'mail.expediteur' => $exp, 'mail.destinataires' => implode(', ', alertes_destinataires($dest)),
                'mail.smtp_hote' => cfg_texte($in, 'smtp_hote', 150, $all['mail.smtp_hote']), 'mail.smtp_user' => cfg_texte($in, 'smtp_user', 150, $all['mail.smtp_user']),
                'mail.expediteur_nom' => cfg_texte($in, 'expediteur_nom', 80, $all['mail.expediteur_nom'])];
            // Mot de passe : vide = inchangé ; "__effacer__" = supprimé
            if (isset($in['smtp_pass']) && $in['smtp_pass'] !== '') $o['mail.smtp_pass'] = $in['smtp_pass'] === '__effacer__' ? '' : (string)$in['smtp_pass'];
            if ($o['mail.actif'] === '1') {
                if ($exp === '') cfg_err('expediteur', "Indiquez l'adresse d'expédition pour activer les alertes");
                if ($tr === 'smtp' && $o['mail.smtp_hote'] === '') cfg_err('smtp_hote', 'Serveur SMTP obligatoire');
                if (!alertes_destinataires($dest)) cfg_err('destinataires', 'Indiquez au moins un destinataire');
            }
            break;
        case 'alertes':
            $j = isset($in['jours_credit']) ? trim((string)$in['jours_credit']) : $all['alertes.jours_credit'];
            if (filter_var($j, FILTER_VALIDATE_INT) === false || $j < 1 || $j > 365) cfg_err('jours_credit', 'De 1 à 365 jours');
            $types = isset($in['types']) ? (is_array($in['types']) ? $in['types'] : explode(',', (string)$in['types'])) : explode(',', $all['alertes.types']);
            $types = array_values(array_intersect(['rupture', 'stock_bas', 'creances', 'ecart_caisse', 'licence'], array_map('strval', $types)));
            $f = isset($in['frequence']) ? (string)$in['frequence'] : $all['alertes.frequence'];
            if (!in_array($f, ['quotidien', 'hebdomadaire'], true)) cfg_err('frequence', 'Fréquence inconnue');
            $o['alertes.jours_credit'] = (string)(int)$j; $o['alertes.types'] = implode(',', $types); $o['alertes.frequence'] = $f;
            break;
        case 'caisse':
            $tva = isset($in['tva_defaut']) ? trim(str_replace(',', '.', (string)$in['tva_defaut'])) : $all['caisse.tva_defaut'];
            if (!is_numeric($tva) || $tva < 0 || $tva > 50) cfg_err('tva_defaut', 'Taux de 0 à 50 %');
            $rem = isset($in['remise_max']) ? trim(str_replace(',', '.', (string)$in['remise_max'])) : $all['caisse.remise_max'];
            if (!is_numeric($rem) || $rem < 0 || $rem > 100) cfg_err('remise_max', 'Remise de 0 à 100 %');
            $ecart = isset($in['ecart_tolere']) ? trim((string)$in['ecart_tolere']) : $all['caisse.ecart_tolere'];
            if (!is_numeric($ecart) || $ecart < 0) cfg_err('ecart_tolere', 'Montant positif attendu');
            $larg = isset($in['ticket_largeur']) ? (string)$in['ticket_largeur'] : $all['caisse.ticket_largeur'];
            if (!in_array($larg, ['58', '80'], true)) cfg_err('ticket_largeur', 'Largeur 58 ou 80 mm');
            $pref = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', cfg_texte($in, 'prefixe', 6, $all['caisse.prefixe'])));
            if ($pref === '') cfg_err('prefixe', 'Préfixe obligatoire (lettres ou chiffres)');
            $bool = function ($k, $def) use ($in) { return array_key_exists($k, $in) ? (!empty($in[$k]) && $in[$k] !== '0' ? '1' : '0') : $def; };
            $o = ['caisse.tva_defaut' => (string)(float)$tva, 'caisse.remise_max' => (string)(float)$rem, 'caisse.ecart_tolere' => (string)(float)$ecart, 'caisse.ticket_largeur' => $larg, 'caisse.prefixe' => $pref,
                'caisse.stock_negatif' => $bool('stock_negatif', $all['caisse.stock_negatif']), 'caisse.prix_libre' => $bool('prix_libre', $all['caisse.prix_libre']),
                'caisse.ticket_entete' => cfg_texte($in, 'ticket_entete', 1000, $all['caisse.ticket_entete']), 'caisse.ticket_pied' => cfg_texte($in, 'ticket_pied', 1000, $all['caisse.ticket_pied'])];
            foreach (['logo', 'adresse', 'contact', 'identifiants'] as $k) $o['caisse.ticket_' . $k] = $bool('ticket_' . $k, $all['caisse.ticket_' . $k]);
            break;
        default:
            throw new ApiError(404, 'Section inconnue');
    }
    return $o;
}

/** Ce que voit n'importe qui (écran de connexion) : identité visuelle, sans donnée sensible. */
function config_publique(PDO $db)
{
    $a = settings_all($db);
    $l = licence_etat($a);
    return [
        'entreprise' => ['nom' => $a['entreprise.nom'], 'logo' => $a['entreprise.logo'] !== '', 'devise' => $a['entreprise.devise']],
        'copyright' => ['nom' => $a['copyright.nom'], 'url' => $a['copyright.url']],
        'theme' => ['palette' => $a['theme.palette'], 'mode' => $a['theme.mode']],
        'caisse' => ['tva_defaut' => (float)$a['caisse.tva_defaut']],
        'licence' => ['etat' => $l['etat'], 'bloque' => $l['bloque'], 'jours' => $l['jours'], 'fin' => $l['fin']],
    ];
}

function config_complete(PDO $db)
{
    $a = settings_all($db);
    $mail = settings_section($a, 'mail');
    $mail['smtp_pass_defini'] = $mail['smtp_pass'] !== '';
    unset($mail['smtp_pass']);   // le mot de passe ne ressort jamais
    $alertes = settings_section($a, 'alertes'); $alertes['types'] = array_values(array_filter(explode(',', $alertes['types'])));
    $log = $db->query('SELECT * FROM mail_log ORDER BY id DESC LIMIT 8')->fetchAll(PDO::FETCH_ASSOC);
    return [
        'entreprise' => settings_section($a, 'entreprise'), 'copyright' => settings_section($a, 'copyright'), 'theme' => settings_section($a, 'theme'), 'documents' => settings_section($a, 'documents'),
        'licence' => settings_section($a, 'licence'), 'session' => settings_section($a, 'session'), 'mail' => $mail, 'alertes' => $alertes, 'caisse' => settings_section($a, 'caisse'),
        'licence_etat' => licence_etat($a), 'palettes' => PALETTES, 'journal_mail' => $log,
    ];
}
