<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — PENGATURAN & TEMPLATE GMAIL / SMTP KREDENSIAL PENGGUNA
 * ==============================================================================
 * Dibuat terpisah sesuai referensi: template-gmail.code-snippets.php
 * Mendukung konfigurasi SMTP fleksibel (App Password Gmail / Custom SMTP),
 * socket TLS SMTP client handal tanpa dependensi vendor, dan template HTML
 * Amber / Lumina Gold mewah untuk pengiriman username & password akun baru.
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../../config.php';
}

/**
 * File path tempat penyimpanan konfigurasi SMTP lokal (JSON)
 */
function ilmuna_get_smtp_config_file() {
    $dir = BASE_PATH . '/config';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir . '/smtp_config.json';
}

/**
 * 1. Ambil Konfigurasi SMTP
 */
function ilmuna_smtp_config() {
    $file = ilmuna_get_smtp_config_file();
    $defaults = [
        'host'       => defined('ILMUNA_SMTP_HOST') ? ILMUNA_SMTP_HOST : 'smtp.gmail.com',
        'port'       => defined('ILMUNA_SMTP_PORT') ? (int)ILMUNA_SMTP_PORT : 587,
        'secure'     => defined('ILMUNA_SMTP_SECURE') ? ILMUNA_SMTP_SECURE : 'tls',
        'user'       => defined('ILMUNA_SMTP_USER') ? ILMUNA_SMTP_USER : 'support.ilmuna@gmail.com',
        'pass'       => defined('ILMUNA_SMTP_PASS') ? ILMUNA_SMTP_PASS : 'culgyngfrzpudkkr',
        'from'       => defined('ILMUNA_SMTP_FROM') ? ILMUNA_SMTP_FROM : 'support.ilmuna@gmail.com',
        'from_name'  => defined('ILMUNA_SMTP_FROM_NAME') ? ILMUNA_SMTP_FROM_NAME : 'Ilmuna System',
        'is_enabled' => defined('ILMUNA_SMTP_ENABLED') ? (ILMUNA_SMTP_ENABLED ? 1 : 0) : 1,
    ];

    if (file_exists($file)) {
        $json = @file_get_contents($file);
        if ($json) {
            $saved = json_decode($json, true);
            if (is_array($saved)) {
                return array_merge($defaults, $saved);
            }
        }
    }

    return $defaults;
}

/**
 * 2. Simpan Konfigurasi SMTP ke File
 */
function ilmuna_save_smtp_config($newConfig) {
    $file = ilmuna_get_smtp_config_file();
    $current = ilmuna_smtp_config();

    $merged = array_merge($current, [
        'host'       => trim($newConfig['host'] ?? 'smtp.gmail.com'),
        'port'       => (int)($newConfig['port'] ?? 587),
        'secure'     => trim($newConfig['secure'] ?? 'tls'),
        'user'       => trim($newConfig['user'] ?? ''),
        'pass'       => trim($newConfig['pass'] ?? ''),
        'from'       => trim($newConfig['from'] ?? ''),
        'from_name'  => trim($newConfig['from_name'] ?? 'Ilmuna Learn'),
        'is_enabled' => !empty($newConfig['is_enabled']) ? 1 : 0,
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $saved = @file_put_contents($file, json_encode($merged, JSON_PRETTY_PRINT));
    return ($saved !== false);
}

require_once BASE_PATH . '/includes/email-credential-template.php';

/**
 * 3. Ambil Template Email Kredensial Resmi
 * Didelegasikan ke file terpisah includes/email-credential-template.php
 */
function ilmuna_get_email_template($data) {
    if (function_exists('ilmuna_get_credential_email_template')) {
        return ilmuna_get_credential_email_template($data);
    }

    $site_name = defined('APP_NAME') ? APP_NAME : 'Ilmuna Learn';
    $nama      = htmlspecialchars($data['nama'] ?? 'Pengguna', ENT_QUOTES, 'UTF-8');
    return [
        'subject'     => 'Akun Login ' . $site_name . ' — ' . $nama,
        'body'        => '<p>Akun login Anda berhasil dibuat.</p>',
        'attachments' => []
    ];
}

/**
 * 4. Pengiriman Email via Raw Socket SMTP (TLS / SSL / AUTH LOGIN)
 * Sangat handal dan mandiri (tidak butuh vendor composer eksternal)
 */
function ilmuna_send_credential_email($toEmail, $nama, $username, $password, $roleName = 'Siswa') {
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'message' => 'Format alamat email tidak valid: ' . $toEmail
        ];
    }

    $cfg = ilmuna_smtp_config();
    if (empty($cfg['is_enabled'])) {
        return [
            'success' => false,
            'message' => 'Pengiriman email dinonaktifkan di pengaturan SMTP.'
        ];
    }

    $host     = $cfg['host'] ?: 'smtp.gmail.com';
    $port     = (int)($cfg['port'] ?: 587);
    $secure   = strtolower($cfg['secure'] ?: 'tls');
    $user     = $cfg['user'] ?: '';
    $pass     = $cfg['pass'] ?: '';
    $from     = $cfg['from'] ?: $user;
    $fromName = $cfg['from_name'] ?: 'Ilmuna System';

    $templateData = [
        'nama'      => $nama,
        'username'  => $username,
        'password'  => $password,
        'role_name' => $roleName,
        'login_url' => defined('BASE_URL') ? BASE_URL . '/login' : 'https://learn.ilmuna.my.id/login'
    ];

    $tpl = ilmuna_get_email_template($templateData);
    $subject     = $tpl['subject'];
    $bodyHtml    = $tpl['body'];
    $attachments = $tpl['attachments'] ?? [];

    // Kirim via Socket SMTP
    $res = ilmuna_raw_smtp_send([
        'host'        => $host,
        'port'        => $port,
        'secure'      => $secure,
        'user'        => $user,
        'pass'        => $pass,
        'from'        => $from,
        'from_name'   => $fromName,
        'to'          => $toEmail,
        'subject'     => $subject,
        'body'        => $bodyHtml,
        'attachments' => $attachments
    ]);

    // Catat log
    ilmuna_log_email_send([
        'timestamp' => date('Y-m-d H:i:s'),
        'to'        => $toEmail,
        'nama'      => $nama,
        'username'  => $username,
        'success'   => $res['success'],
        'detail'    => $res['message']
    ]);

    return $res;
}

/**
 * 5. Low-level Socket SMTP Engine (Mendukung STARTTLS & SSL dengan Dual-Port Fallback Otomatis)
 */
function ilmuna_raw_smtp_send($params) {
    // Upaya 1: sesuai port & tipe enkripsi yang dikonfigurasi
    $res = _ilmuna_single_socket_smtp($params);
    if ($res['success']) {
        return $res;
    }

    // Upaya 2: Fallback Cerdas Dual-Port (Port 587 TLS <-> Port 465 SSL)
    // Jika port 587/TLS gagal karena limitasi stream crypto/firewall, coba otomatis port 465 SSL langsung (dan sebaliknya)
    $origPort   = (int)($params['port'] ?? 587);
    $origSecure = strtolower($params['secure'] ?? 'tls');

    if ($origPort === 587 || $origSecure === 'tls') {
        $altParams = $params;
        $altParams['port']   = 465;
        $altParams['secure'] = 'ssl';
        $altRes = _ilmuna_single_socket_smtp($altParams);
        if ($altRes['success']) {
            return $altRes;
        }
    } elseif ($origPort === 465 || $origSecure === 'ssl') {
        $altParams = $params;
        $altParams['port']   = 587;
        $altParams['secure'] = 'tls';
        $altRes = _ilmuna_single_socket_smtp($altParams);
        if ($altRes['success']) {
            return $altRes;
        }
    }

    return $res;
}

function _ilmuna_single_socket_smtp($params) {
    $host      = $params['host'];
    $port      = $params['port'];
    $secure    = $params['secure'];
    $user      = trim($params['user'] ?? '');
    $pass      = trim(str_replace(' ', '', $params['pass'] ?? ''));
    $from      = trim($params['from'] ?? '');
    $fromName  = $params['from_name'] ?? 'Ilmuna Learn';
    $to        = $params['to'] ?? '';
    $subject   = $params['subject'] ?? '';
    $body      = $params['body'] ?? $params['body_html'] ?? '';

    $timeout   = 15;
    $errno     = 0;
    $errstr    = '';

    // Tentukan connection target
    $targetHost = ($secure === 'ssl') ? ('ssl://' . $host) : ('tcp://' . $host);

    $context = stream_context_create([
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true
        ]
    ]);

    $socket = @stream_socket_client($targetHost . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        return ['success' => false, 'message' => "Gagal terhubung ke host SMTP ($host:$port): $errstr ($errno)"];
    }

    stream_set_timeout($socket, $timeout);

    $readResponse = function() use ($socket) {
        $data = '';
        while (!feof($socket)) {
            $line = fgets($socket, 1024);
            $data .= $line;
            if (substr($line, 3, 1) === ' ' || empty($line)) break;
        }
        return $data;
    };

    $sendCommand = function($cmd) use ($socket, $readResponse) {
        fputs($socket, $cmd . "\r\n");
        return $readResponse();
    };

    $initial = $readResponse();
    if (substr($initial, 0, 3) !== '220') {
        fclose($socket);
        return ['success' => false, 'message' => 'Respon handshake awal SMTP tidak valid: ' . trim($initial)];
    }

    $ehlo = $sendCommand('EHLO ' . (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost'));

    // Jika STARTTLS diminta dan bukan direct SSL
    if ($secure === 'tls') {
        $tlsRes = $sendCommand('STARTTLS');
        if (substr($tlsRes, 0, 3) !== '220') {
            fclose($socket);
            return ['success' => false, 'message' => 'STARTTLS ditolak server SMTP: ' . trim($tlsRes)];
        }

        $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }

        $cryptoOk = @stream_socket_enable_crypto($socket, true, $cryptoMethod);
        if (!$cryptoOk) {
            fclose($socket);
            return ['success' => false, 'message' => 'Gagal menegosiasikan enkripsi TLS dengan server SMTP.'];
        }

        // Ulangi EHLO setelah TLS aktif
        $sendCommand('EHLO ' . (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost'));
    }

    // Autentikasi SMTP (AUTH LOGIN) jika user dan pass diisi
    if (!empty($user) && !empty($pass)) {
        $authRes = $sendCommand('AUTH LOGIN');
        if (substr($authRes, 0, 3) !== '334') {
            fclose($socket);
            return ['success' => false, 'message' => 'Server tidak mendukung AUTH LOGIN: ' . trim($authRes)];
        }

        $userRes = $sendCommand(base64_encode($user));
        if (substr($userRes, 0, 3) !== '334') {
            fclose($socket);
            return ['success' => false, 'message' => 'Username SMTP ditolak: ' . trim($userRes)];
        }

        $passRes = $sendCommand(base64_encode($pass));
        if (substr($passRes, 0, 3) !== '235') {
            fclose($socket);
            $maskPass = strlen($pass) > 4 ? substr($pass, 0, 4) . '***' : '***';
            return ['success' => false, 'message' => 'Password/App Password SMTP ditolak: ' . trim($passRes) . " [Akun: $user, Pass: $maskPass]"];
        }
    }

    // MAIL FROM
    $mailFromRes = $sendCommand('MAIL FROM:<' . $from . '>');
    if (substr($mailFromRes, 0, 3) !== '250') {
        fclose($socket);
        return ['success' => false, 'message' => 'MAIL FROM ditolak: ' . trim($mailFromRes)];
    }

    // RCPT TO
    $rcptRes = $sendCommand('RCPT TO:<' . $to . '>');
    if (substr($rcptRes, 0, 3) !== '250' && substr($rcptRes, 0, 3) !== '251') {
        fclose($socket);
        return ['success' => false, 'message' => 'Alamat email tujuan ditolak oleh SMTP: ' . trim($rcptRes)];
    }

    // DATA
    $dataRes = $sendCommand('DATA');
    if (substr($dataRes, 0, 3) !== '354') {
        fclose($socket);
        return ['success' => false, 'message' => 'Perintah DATA ditolak: ' . trim($dataRes)];
    }

    // Build Headers & MIME
    $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $encodedSubject  = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $attachments     = $params['attachments'] ?? [];

    if (!empty($attachments)) {
        $boundary = "----=_Ilmuna_Rel_" . md5(uniqid((string)rand(), true));
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: multipart/related; boundary="' . $boundary . '"',
            'From: ' . $encodedFromName . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Reply-To: ' . $encodedFromName . ' <' . $from . '>',
            'Subject: ' . $encodedSubject,
            'Date: ' . date('r'),
            'X-Mailer: Ilmuna-Learn-SMTP/1.0',
            'Precedence: bulk'
        ];

        $bodyParts = [];
        $bodyParts[] = "--" . $boundary;
        $bodyParts[] = "Content-Type: text/html; charset=UTF-8";
        $bodyParts[] = "Content-Transfer-Encoding: 8bit\r\n";
        $bodyParts[] = $body;

        foreach ($attachments as $att) {
            if (!empty($att['path']) && file_exists($att['path'])) {
                $attData = @file_get_contents($att['path']);
                if ($attData !== false) {
                    $cid = $att['cid'] ?? 'ilmuna_logo';
                    $mime = $att['mime'] ?? 'image/png';
                    $filename = $att['name'] ?? basename($att['path']);

                    $bodyParts[] = "--" . $boundary;
                    $bodyParts[] = "Content-Type: " . $mime . '; name="' . $filename . '"';
                    $bodyParts[] = "Content-Transfer-Encoding: base64";
                    $bodyParts[] = "Content-ID: <" . $cid . ">";
                    $bodyParts[] = "Content-Disposition: inline; filename=\"" . $filename . "\"\r\n";
                    $bodyParts[] = chunk_split(base64_encode($attData));
                }
            }
        }
        $bodyParts[] = "--" . $boundary . "--";
        $payload = implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $bodyParts) . "\r\n.";
    } else {
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $encodedFromName . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Reply-To: ' . $encodedFromName . ' <' . $from . '>',
            'Subject: ' . $encodedSubject,
            'Date: ' . date('r'),
            'X-Mailer: Ilmuna-Learn-SMTP/1.0',
            'Precedence: bulk'
        ];
        $payload = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
    }
    $sendRes = $sendCommand($payload);

    $sendCommand('QUIT');
    fclose($socket);

    if (substr($sendRes, 0, 3) === '250') {
        return [
            'success' => true,
            'message' => 'Email kredensial berhasil terkirim ke ' . $to
        ];
    } else {
        return [
            'success' => false,
            'message' => 'Pengiriman data email gagal: ' . trim($sendRes)
        ];
    }
}

/**
 * 6. Pencatat Log Pengiriman Email ke File JSON
 */
function ilmuna_log_email_send($logItem) {
    $dir = BASE_PATH . '/uploads';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = $dir . '/email_logs.json';

    $logs = [];
    if (file_exists($file)) {
        $content = @file_get_contents($file);
        if ($content) {
            $parsed = json_decode($content, true);
            if (is_array($parsed)) $logs = $parsed;
        }
    }

    array_unshift($logs, $logItem);
    if (count($logs) > 60) {
        $logs = array_slice($logs, 0, 60);
    }

    @file_put_contents($file, json_encode($logs, JSON_PRETTY_PRINT));
}
