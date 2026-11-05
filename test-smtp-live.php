<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/dashboard/admin/users-email-template.php';

header('Content-Type: application/json');

$cfg = ilmuna_smtp_config();

$host = $cfg['host'];
$port = (int)$cfg['port'];
$user = $cfg['user'];
$pass = $cfg['pass'];

// Coba port 465 direct SSL
$socket465 = @stream_socket_client("ssl://$host:465", $errno465, $errstr465, 10);
$res465 = '';
if ($socket465) {
    fgets($socket465, 512);
    fputs($socket465, "EHLO localhost\r\n");
    while ($line = fgets($socket465, 512)) { if (substr($line, 3, 1) === ' ') break; }
    fputs($socket465, "AUTH LOGIN\r\n");
    fgets($socket465, 512);
    fputs($socket465, base64_encode($user) . "\r\n");
    fgets($socket465, 512);
    fputs($socket465, base64_encode($pass) . "\r\n");
    $res465 = trim(fgets($socket465, 512));
    fclose($socket465);
}

// Coba port 587
$res587 = ilmuna_raw_smtp_send([
    'host'      => $host,
    'port'      => 587,
    'secure'    => 'tls',
    'user'      => $user,
    'pass'      => $pass,
    'from'      => $user,
    'from_name' => 'Ilmuna Test',
    'to'        => 'syahriel.andika28@gmail.com',
    'subject'   => 'Test dari server cPanel',
    'body'      => 'Test cPanel live'
]);

echo json_encode([
    'server_ip' => $_SERVER['SERVER_ADDR'] ?? gethostbyname(gethostname()),
    'config_user' => $user,
    'config_pass_len' => strlen($pass),
    'config_pass_first4' => substr($pass, 0, 4),
    'port_465_auth_response' => $res465,
    'err465' => $errstr465,
    'port_587_result' => $res587
], JSON_PRETTY_PRINT);
