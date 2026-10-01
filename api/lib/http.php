<?php
/** Helpers HTTP/JSON pour l'API. */

class ApiError extends Exception
{
    public $status;
    public $errors;
    public $code;
    public function __construct($status, $message, $errors = null, $code = null)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errors = $errors;
        $this->code = $code;
    }
}

function json_out($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Corps de la requête : JSON ou formulaire (multipart / urlencoded). */
function request_input()
{
    $ct = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $d = json_decode($raw, true);
        if ($raw !== '' && !is_array($d)) throw new ApiError(400, 'JSON invalide');
        return is_array($d) ? $d : [];
    }
    return $_POST;
}

function query_int($key, $default, $min = 1, $max = PHP_INT_MAX)
{
    $v = isset($_GET[$key]) ? (int)$_GET[$key] : $default;
    return max($min, min($max, $v));
}

function client_ip()
{
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
}

/** Envoie la réponse JSON tout de suite, puis laisse le script poursuivre (génération d'export, envoi d'e-mails…). */
function json_then(array $data, $status = 200)
{
    if (function_exists('session_write_close')) @session_write_close();   // libère le verrou de session
    ignore_user_abort(true);
    set_time_limit(0);
    $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen($body));
    header('Connection: close');
    echo $body;
    while (ob_get_level()) ob_end_flush();
    flush();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
}
