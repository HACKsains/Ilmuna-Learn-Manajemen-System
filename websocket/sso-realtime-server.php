#!/usr/bin/env php
<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — RFC 6455 REAL-TIME WEBSOCKET SERVER FOR MARGO ONE SSO
 * ==============================================================================
 * Server WebSocket murni berbasis PHP Stream Socket (tanpa Composer / Ratchet),
 * 100% kompatibel PHP 7.4 (ea-php74) hingga PHP 8.3+ di Windows & Linux.
 *
 * Fitur Utama:
 *   1. RFC 6455 Full WebSocket Handshake + Frame Encode/Decode (Text, Ping, Pong, Close)
 *   2. Autentikasi Single-Use Ephemeral Ticket (`?ticket=wst_...` atau auth frame)
 *      sehingga Private Key tidak pernah menyentuh frontend browser Margo One SSO.
 *   3. Class-Scoped Subscription Filtering: Event hanya dikirim secara real-time
 *      ke klien WebSocket yang terhubung sesuai `kelas_id` masing-masing (atau admin).
 *   4. Dual Delivery Path:
 *      - Instan (0ms) via Kanal IPC TCP Lokal (`127.0.0.1:8091`) bertanda tangan HMAC
 *      - Fallback otomatis scan tabel `sso_realtime_events` untuk event tertinggal
 *   5. Auto-Reconnect PDO jika MySQL idle (`wait_timeout`), Keepalive Ping 25 detik.
 *
 * Cara Menjalankan:
 *   php websocket/sso-realtime-server.php
 *   php websocket/sso-realtime-server.php --ws-port=8090 --ipc-port=8091
 * ==============================================================================
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("WebSocket daemon hanya dapat dijalankan dari CLI.\n");
}

if (!defined('ILMUNA_SKIP_GD_WAF_INTERSTITIAL')) {
    define('ILMUNA_SKIP_GD_WAF_INTERSTITIAL', true);
}

date_default_timezone_set('Asia/Jakarta');
set_time_limit(0);
@ini_set('default_socket_timeout', -1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/database.php';
require_once BASE_PATH . '/includes/sso-api-security.php';
require_once BASE_PATH . '/includes/sso-api-data.php';

// Parse CLI arguments
$wsHost  = '0.0.0.0';
$wsPort  = ILMUNA_SSO_WS_DEFAULT_PORT;
$ipcPort = ILMUNA_SSO_WS_DEFAULT_IPC_PORT;

foreach ($argv as $arg) {
    if (strpos($arg, '--ws-host=') === 0) {
        $wsHost = trim(substr($arg, 10)) ?: '0.0.0.0';
    } elseif (strpos($arg, '--ws-port=') === 0) {
        $wsPort = max(1024, (int)substr($arg, 10));
    } elseif (strpos($arg, '--ipc-port=') === 0) {
        $ipcPort = max(1024, (int)substr($arg, 11));
    }
}

function wsLog($msg)
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

function getFreshPdo()
{
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        return new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+07:00'",
        ]);
    } catch (PDOException $e) {
        return new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . DB_NAME . ';charset=utf8mb4', 'root', '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+07:00'",
        ]);
    }
}

function ensurePdoAlive(&$pdo)
{
    try {
        $pdo->query('SELECT 1');
    } catch (Throwable $e) {
        wsLog('Reconnecting MySQL PDO...');
        $pdo = getFreshPdo();
    }
    return $pdo;
}

try {
    $pdo = getFreshPdo();
    ilmuna_sso_api_ensure_schema($pdo);
    $cfgRow = $pdo->query("SELECT ws_port, ws_ipc_port FROM sso_api_clients WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($cfgRow) {
        if (!empty($cfgRow['ws_port']) && !in_array('--ws-port=', array_map(function ($a) { return substr($a, 0, 10); }, $argv), true)) {
            $wsPort = (int)$cfgRow['ws_port'];
        }
        if (!empty($cfgRow['ws_ipc_port']) && !in_array('--ipc-port=', array_map(function ($a) { return substr($a, 0, 11); }, $argv), true)) {
            $ipcPort = (int)$cfgRow['ws_ipc_port'];
        }
    }
} catch (Throwable $e) {
    wsLog('FATAL DB ERROR: ' . $e->getMessage());
    exit(1);
}

$pidFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ilmuna_sso_ws.pid';
@file_put_contents($pidFile, (string)getmypid());

$wsServer = @stream_socket_server("tcp://{$wsHost}:{$wsPort}", $err1, $str1);
if (!$wsServer) {
    wsLog("GAGAL bind WebSocket server di {$wsHost}:{$wsPort} ({$err1}: {$str1})");
    exit(1);
}
stream_set_blocking($wsServer, false);

$ipcServer = @stream_socket_server("tcp://127.0.0.1:{$ipcPort}", $err2, $str2);
if (!$ipcServer) {
    wsLog("GAGAL bind IPC server di 127.0.0.1:{$ipcPort} ({$err2}: {$str2})");
    @fclose($wsServer);
    exit(1);
}
stream_set_blocking($ipcServer, false);

wsLog("Ilmuna Margo One SSO WebSocket Server AKTIF | WS: {$wsHost}:{$wsPort} | IPC: 127.0.0.1:{$ipcPort} | PID: " . getmypid());

/**
 * Encode payload string menjadi WebSocket RFC 6455 Frame (opcode 0x1 text / 0x9 ping / 0xA pong / 0x8 close)
 */
function wsEncodeFrame($payload, $opcode = 0x1)
{
    $payload = (string)$payload;
    $len     = strlen($payload);
    $b1      = 0x80 | ($opcode & 0x0F);

    if ($len <= 125) {
        return pack('CC', $b1, $len) . $payload;
    }
    if ($len <= 65535) {
        return pack('CCn', $b1, 126, $len) . $payload;
    }
    $high = ($len & 0xFFFFFFFF00000000) >> 32;
    $low  = $len & 0xFFFFFFFF;
    return pack('CCNN', $b1, 127, $high, $low) . $payload;
}

/**
 * Decode satu atau beberapa WebSocket frame dari buffer socket klien
 */
function wsDecodeFrames(&$buffer)
{
    $frames = [];
    while (strlen($buffer) >= 2) {
        $b1 = ord($buffer[0]);
        $b2 = ord($buffer[1]);

        $opcode = $b1 & 0x0F;
        $masked = ($b2 & 0x80) !== 0;
        $len    = $b2 & 0x7F;
        $offset = 2;

        if ($len === 126) {
            if (strlen($buffer) < 4) break;
            $u = unpack('nlen', substr($buffer, 2, 2));
            $len = $u['len'];
            $offset = 4;
        } elseif ($len === 127) {
            if (strlen($buffer) < 10) break;
            $u = unpack('Nhi/Nlo', substr($buffer, 2, 8));
            $len = ($u['hi'] << 32) | $u['lo'];
            $offset = 10;
        }

        $maskLen = $masked ? 4 : 0;
        if (strlen($buffer) < ($offset + $maskLen + $len)) {
            break;
        }

        $maskKey = $masked ? substr($buffer, $offset, 4) : '';
        $payload = substr($buffer, $offset + $maskLen, $len);
        $buffer  = substr($buffer, $offset + $maskLen + $len);

        if ($masked && $len > 0) {
            $unmasked = '';
            for ($i = 0; $i < $len; $i++) {
                $unmasked .= $payload[$i] ^ $maskKey[$i % 4];
            }
            $payload = $unmasked;
        }

        $frames[] = ['opcode' => $opcode, 'payload' => $payload];
    }
    return $frames;
}

function wsSendJson(array &$client, array $data)
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $frame = wsEncodeFrame($json, 0x1);
    $written = @fwrite($client['socket'], $frame);
    return $written !== false;
}

function wsCloseClient(array &$clients, $key, $reason = '')
{
    if (!isset($clients[$key])) {
        return;
    }
    $sock = $clients[$key]['socket'];
    if ($clients[$key]['handshake']) {
        @fwrite($sock, wsEncodeFrame('', 0x8));
    }
    @fclose($sock);
    unset($clients[$key]);
    if ($reason !== '') {
        wsLog("Disconnect #{$key}: {$reason}");
    }
}

/**
 * Tentukan apakah event harus dikirim ke klien WebSocket berdasarkan hak kelasnya
 */
function wsShouldDeliverToClient(array $client, array $envelope)
{
    if (empty($client['authenticated'])) {
        return false;
    }
    if (!empty($client['subscribe_all'])) {
        return true;
    }
    $evKelasId = isset($envelope['kelas']['id']) ? (int)$envelope['kelas']['id'] : 0;
    if ($evKelasId <= 0) {
        return true;
    }
    return in_array($evKelasId, $client['kelas_ids'], true);
}

$clients    = [];
$ipcClients = [];
$ipcSecret  = ilmuna_sso_ipc_secret();
$lastEventIdProcessed = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM sso_realtime_events")->fetchColumn();
$lastDbPollAt   = microtime(true);
$lastKeepalive  = microtime(true);

function broadcastEnvelope(array &$clients, array $envelope, &$lastEventIdProcessed)
{
    if (!empty($envelope['event_id']) && (int)$envelope['event_id'] > $lastEventIdProcessed) {
        $lastEventIdProcessed = (int)$envelope['event_id'];
    }
    $sent = 0;
    foreach ($clients as $key => &$cl) {
        if (wsShouldDeliverToClient($cl, $envelope)) {
            if (wsSendJson($cl, $envelope)) {
                $sent++;
            } else {
                wsCloseClient($clients, $key, 'Write failed');
            }
        }
    }
    unset($cl);
    if ($sent > 0) {
        wsLog("Broadcast [{$envelope['event_type']}] kelas=" . ($envelope['kelas']['nama'] ?? 'All') . " -> {$sent} klien SSO");
    }
}

while (true) {
    $read = [$wsServer, $ipcServer];
    foreach ($clients as $cl) {
        $read[] = $cl['socket'];
    }
    foreach ($ipcClients as $ic) {
        $read[] = $ic['socket'];
    }

    $write  = null;
    $except = null;
    $numChanged = @stream_select($read, $write, $except, 1, 0);

    if ($numChanged === false) {
        usleep(50000);
    }

    if ($numChanged > 0) {
        // 1. Terima koneksi klien WebSocket baru
        if (in_array($wsServer, $read, true)) {
            $newSock = @stream_socket_accept($wsServer, 0);
            if (is_resource($newSock)) {
                stream_set_blocking($newSock, false);
                $k = (int)$newSock;
                $clients[$k] = [
                    'socket'        => $newSock,
                    'handshake'     => false,
                    'authenticated' => false,
                    'buffer'        => '',
                    'kelas_ids'     => [],
                    'subscribe_all' => false,
                    'role_code'     => 'siswa',
                    'user_ident'    => '',
                    'connected_at'  => time(),
                    'last_ping'     => time(),
                ];
            }
        }

        // 2. Terima koneksi IPC lokal (dari PHP CRUD Tugas / Proyek / Kelompok / Asesmen)
        if (in_array($ipcServer, $read, true)) {
            $newIpc = @stream_socket_accept($ipcServer, 0);
            if (is_resource($newIpc)) {
                stream_set_blocking($newIpc, false);
                $ik = (int)$newIpc;
                $ipcClients[$ik] = ['socket' => $newIpc, 'buffer' => ''];
            }
        }

        // 3. Proses pesan dari IPC Server Lokal
        foreach ($ipcClients as $ik => &$ic) {
            if (!in_array($ic['socket'], $read, true)) {
                continue;
            }
            $chunk = @fread($ic['socket'], 65535);
            if ($chunk === '' || $chunk === false) {
                @fclose($ic['socket']);
                unset($ipcClients[$ik]);
                continue;
            }
            $ic['buffer'] .= $chunk;
            while (($nlPos = strpos($ic['buffer'], "\n")) !== false) {
                $line = trim(substr($ic['buffer'], 0, $nlPos));
                $ic['buffer'] = substr($ic['buffer'], $nlPos + 1);
                if ($line === '') continue;

                $pkt = json_decode($line, true);
                if (is_array($pkt) && isset($pkt['ts'], $pkt['mac'], $pkt['envelope'])) {
                    $envJson = json_encode($pkt['envelope'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $expMac  = hash_hmac('sha256', (string)$pkt['ts'] . "\n" . $envJson, $ipcSecret);
                    if (abs(time() - (int)$pkt['ts']) <= 30 && hash_equals($expMac, (string)$pkt['mac'])) {
                        broadcastEnvelope($clients, $pkt['envelope'], $lastEventIdProcessed);
                    }
                }
            }
        }
        unset($ic);

        // 4. Proses Handshake & Frame dari masing-masing klien browser SSO
        foreach ($clients as $k => &$cl) {
            if (!in_array($cl['socket'], $read, true)) {
                continue;
            }
            $chunk = @fread($cl['socket'], 65535);
            if ($chunk === '' || $chunk === false) {
                wsCloseClient($clients, $k, 'EOF');
                continue;
            }
            $cl['buffer'] .= $chunk;

            // 4a. HTTP Upgrade Handshake RFC 6455
            if (!$cl['handshake']) {
                $hdrEnd = strpos($cl['buffer'], "\r\n\r\n");
                if ($hdrEnd === false) {
                    if (strlen($cl['buffer']) > 8192) {
                        wsCloseClient($clients, $k, 'Header overflow');
                    }
                    continue;
                }

                $rawHttp      = substr($cl['buffer'], 0, $hdrEnd);
                $cl['buffer'] = substr($cl['buffer'], $hdrEnd + 4);

                $lines = explode("\r\n", $rawHttp);
                $requestLine = array_shift($lines);
                $reqParts = explode(' ', $requestLine);
                $reqUri   = isset($reqParts[1]) ? $reqParts[1] : '/';

                $hdrs = [];
                foreach ($lines as $ln) {
                    if (($colon = strpos($ln, ':')) !== false) {
                        $hdrs[strtolower(trim(substr($ln, 0, $colon)))] = trim(substr($ln, $colon + 1));
                    }
                }

                $secKey = isset($hdrs['sec-websocket-key']) ? $hdrs['sec-websocket-key'] : '';
                if ($secKey === '') {
                    @fwrite($cl['socket'], "HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n");
                    wsCloseClient($clients, $k, 'Missing Sec-WebSocket-Key');
                    continue;
                }

                // Cek ticket di query parameter (?ticket=wst_...)
                $queryStr = parse_url($reqUri, PHP_URL_QUERY);
                $qParams  = [];
                if (is_string($queryStr) && $queryStr !== '') {
                    parse_str($queryStr, $qParams);
                }
                $ticket = isset($qParams['ticket']) ? trim((string)$qParams['ticket']) : '';

                if ($ticket === '') {
                    @fwrite($cl['socket'], "HTTP/1.1 401 Unauthorized\r\nConnection: close\r\n\r\n");
                    wsCloseClient($clients, $k, 'Missing WebSocket ticket');
                    continue;
                }

                try {
                    ensurePdoAlive($pdo);
                    $authRes = ilmuna_sso_api_consume_ws_ticket($pdo, $ticket);
                } catch (Throwable $eAuth) {
                    $authRes = ['ok' => false, 'reason' => 'DB_ERROR'];
                }

                if (empty($authRes['ok'])) {
                    @fwrite($cl['socket'], "HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n");
                    wsCloseClient($clients, $k, 'Ticket invalid: ' . ($authRes['reason'] ?? 'UNKNOWN'));
                    continue;
                }

                $acceptKey = base64_encode(sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
                $upgradeResp = "HTTP/1.1 101 Switching Protocols\r\n"
                             . "Upgrade: websocket\r\n"
                             . "Connection: Upgrade\r\n"
                             . "Sec-WebSocket-Accept: {$acceptKey}\r\n"
                             . "X-Ilmuna-WS-Version: 1.0\r\n\r\n";
                @fwrite($cl['socket'], $upgradeResp);

                $cl['handshake']     = true;
                $cl['authenticated'] = true;
                $cl['kelas_ids']     = $authRes['kelas_ids'];
                $cl['subscribe_all'] = $authRes['subscribe_all'];
                $cl['role_code']     = $authRes['role_code'];
                $cl['user_ident']    = (string)($authRes['user_identifier'] ?? '');

                wsLog("Connected #{$k} role={$cl['role_code']} kelas=[" . implode(',', $cl['kelas_ids']) . "] all=" . ($cl['subscribe_all'] ? '1' : '0'));

                // Kirim welcome + snapshot feed awal langsung agar SSO langsung sinkron tanpa reload!
                try {
                    $ctx = [
                        'filtered_by_class' => !$cl['subscribe_all'],
                        'kelas_ids'         => $cl['kelas_ids'],
                        'siswa_id'          => $authRes['siswa_id'],
                        'guru_id'           => $authRes['guru_id'],
                        'role'              => $cl['role_code'],
                        'siswa'             => null,
                        'guru'              => null,
                        'kelas_list'        => [],
                    ];
                    $snapshot = ilmuna_sso_build_unified_feed($pdo, ['limit' => 15], $ctx);
                    wsSendJson($cl, [
                        'type'       => 'ilmuna.realtime.welcome',
                        'connected'  => true,
                        'role'       => $cl['role_code'],
                        'kelas_ids'  => $cl['kelas_ids'],
                        'last_event_id' => $lastEventIdProcessed,
                        'snapshot'   => $snapshot,
                        'server_time'=> time(),
                    ]);
                } catch (Throwable $eSnap) {
                    wsSendJson($cl, [
                        'type'          => 'ilmuna.realtime.welcome',
                        'connected'     => true,
                        'last_event_id' => $lastEventIdProcessed,
                        'server_time'   => time(),
                    ]);
                }
                continue;
            }

            // 4b. Decode WebSocket Data Frames
            $frames = wsDecodeFrames($cl['buffer']);
            foreach ($frames as $fr) {
                if ($fr['opcode'] === 0x8) {
                    wsCloseClient($clients, $k, 'Client closed');
                    break;
                }
                if ($fr['opcode'] === 0x9) {
                    @fwrite($cl['socket'], wsEncodeFrame($fr['payload'], 0xA)); // Pong
                    continue;
                }
                if ($fr['opcode'] === 0xA) {
                    $cl['last_ping'] = time();
                    continue;
                }
                if ($fr['opcode'] === 0x1) {
                    $msg = json_decode($fr['payload'], true);
                    if (is_array($msg) && isset($msg['action'])) {
                        if ($msg['action'] === 'ping') {
                            wsSendJson($cl, ['type' => 'pong', 'server_time' => time()]);
                        } elseif ($msg['action'] === 'sync_since') {
                            $sinceId = max(0, (int)($msg['since_event_id'] ?? 0));
                            try {
                                ensurePdoAlive($pdo);
                                $ctx = [
                                    'filtered_by_class' => !$cl['subscribe_all'],
                                    'kelas_ids'         => $cl['kelas_ids'],
                                ];
                                $missed = ilmuna_sso_fetch_recent_events($pdo, $ctx, $sinceId, 30);
                                wsSendJson($cl, [
                                    'type'   => 'ilmuna.realtime.replay',
                                    'events' => $missed,
                                ]);
                            } catch (Throwable $eRep) {
                                // Ignore
                            }
                        }
                    }
                }
            }
        }
        unset($cl);
    }

    // 5. Fallback aman: cek DB sso_realtime_events setiap 2 detik jika ada event yang masuk saat IPC sempat putus
    $nowMicro = microtime(true);
    if (($nowMicro - $lastDbPollAt) >= 2.0 && !empty($clients)) {
        $lastDbPollAt = $nowMicro;
        try {
            ensurePdoAlive($pdo);
            $stEv = $pdo->prepare("SELECT * FROM sso_realtime_events WHERE id > ? ORDER BY id ASC LIMIT 25");
            $stEv->execute([$lastEventIdProcessed]);
            foreach ($stEv->fetchAll(PDO::FETCH_ASSOC) as $evRow) {
                $envelope = [
                    'type'           => 'ilmuna.realtime.event',
                    'event_id'       => (int)$evRow['id'],
                    'event_uuid'     => (string)$evRow['event_uuid'],
                    'event_type'     => (string)$evRow['event_type'],
                    'category'       => (string)$evRow['category'],
                    'kelas'          => [
                        'id'   => $evRow['kelas_id'] !== null ? (int)$evRow['kelas_id'] : null,
                        'nama' => (string)($evRow['nama_kelas'] ?? 'Seluruh Kelas'),
                        'kode' => (string)($evRow['kode_kelas'] ?? ''),
                    ],
                    'reference_id'   => $evRow['reference_id'] !== null ? (int)$evRow['reference_id'] : null,
                    'title'          => (string)$evRow['title'],
                    'summary'        => (string)($evRow['summary'] ?? ''),
                    'deadline_at'    => $evRow['deadline_at'],
                    'urgency'        => (string)$evRow['urgency'],
                    'portal_url'     => $evRow['portal_url'],
                    'data'           => json_decode((string)$evRow['payload_json'], true),
                    'signature_hmac' => (string)$evRow['signature_hmac'],
                    'timestamp'      => strtotime((string)$evRow['created_at']) ?: time(),
                    'created_at_iso' => date('c', strtotime((string)$evRow['created_at']) ?: time()),
                ];
                broadcastEnvelope($clients, $envelope, $lastEventIdProcessed);
            }
        } catch (Throwable $ePoll) {
            wsLog('DB Watch Warning: ' . $ePoll->getMessage());
        }
    }

    // 6. WebSocket Keepalive Ping setiap 25 detik
    if (($nowMicro - $lastKeepalive) >= 25.0 && !empty($clients)) {
        $lastKeepalive = $nowMicro;
        foreach ($clients as $k => &$cl) {
            if (!empty($cl['handshake'])) {
                $ok = @fwrite($cl['socket'], wsEncodeFrame('keepalive', 0x9));
                if ($ok === false) {
                    wsCloseClient($clients, $k, 'Ping failed');
                }
            }
        }
        unset($cl);
    }
}
