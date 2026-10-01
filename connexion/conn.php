<?php
require_once __DIR__ . '/env.php';

try {
    // DB_DSN permet de surcharger la connexion (tests automatisés) ; par défaut : MySQL
    $dsn = env('DB_DSN') ?: "mysql:host=" . env('DB_HOST', 'localhost') . ";dbname=" . env('DB_NAME') . ";charset=utf8mb4";
    $bdd = new PDO(
        $dsn,
        env('DB_USER'),
        env('DB_PASS'),
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_BOTH,
        ]
    );
} catch (PDOException $e) {
    error_log('Connexion BD impossible : ' . $e->getMessage());
    http_response_code(500);
    die('Service momentanément indisponible.');
}
