<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — SECURE SSO REAL-TIME BROADCAST & SCHEDULE REST API
 * ==============================================================================
 * Endpoint: https://learn.ilmuna.my.id/api/sso-broadcast.php
 * Consumer: https://margo-one.ilmuna.my.id (Margo One SSO)
 *
 * Resource (?resource=...):
 *   - health             : Status kesehatan API, jam server, WebSocket status, & kunci
 *   - classes            : Daftar master seluruh rombel kelas (untuk mapping SSO)
 *   - tugas_broadcast    : Broadcast Tugas + Deadline + Daftar Kelompok Tugas per Kelas
 *   - proyek_broadcast   : Broadcast Proyek (P5/PjBL) + Deadline + Kelompok Proyek per Kelas
 *   - kelompok_broadcast : Daftar terpadu Kelompok Tugas & Proyek per Kelas / Siswa
 *   - jadwal_asesmen     : Jadwal Asesmen dari Bank & Master Asesmen (Sumatif/Formatif/Diagnostik/Remedial)
 *   - student_feed / all : Paket lengkap sesuai identitas/kelas + Timeline Deadline + Riwayat Notif
 *   - events             : Daftar notifikasi event real-time (atau ?since_event_id=... untuk replay)
 *   - ws_ticket          : Terbitkan Single-Use Ephemeral Ticket (wst_*) untuk koneksi WebSocket
 *                          real-time langsung di frontend Margo One SSO tanpa polling/reload
 *
 * Filter Konteks Kelas / Identitas Otomatis:
 *   ?kelas_id=12 | ?kode_kelas=XI-A | ?nama_kelas=XI-A | ?nis=... | ?nisn=... | ?nip=... | ?email=...
 *
 * Proteksi Zero-Trust Maksimal:
 *   Public Key (`pk_sso_*`) + Private Key (`sk_sso_*` / RSA-2048 PEM) + DUAL Mode +
 *   Canonical Signature + Timestamp +/-300s + Single-Use Nonce + Domain/IP Allowlist +
 *   Rate Limiting + Signed JSON Response (`X-Ilmuna-Response-Signature`) + Audit Log.
 *
 * 100% kompatibel PHP 7.4 (ea-php74) hingga PHP 8.3+.
 * ==============================================================================
 */

$startMicro = microtime(true);
date_default_timezone_set('Asia/Jakarta');

if (!defined('ILMUNA_SKIP_GD_WAF_INTERSTITIAL')) {
    define('ILMUNA_SKIP_GD_WAF_INTERSTITIAL', true);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once BASE_PATH . '/includes/sso-api-security.php';
require_once BASE_PATH . '/includes/sso-api-data.php';
require_once BASE_PATH . '/includes/sso-realtime-dispatcher.php';

/**
 * Keluarkan respon JSON standar dengan header keamanan penuh + Signed Response
 */
function ilmuna_sso_api_emit_json($httpStatus, array $payload, array $extraHeaders = [], $privateKey = null)
{
    while (ob_get_level()) {
        ob_end_clean();
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $httpStatus = 500;
        $json = '{"success":false,"error":{"code":"JSON_ENCODE_ERROR","message":"Gagal menyusun respon JSON."}}';
    }

    if (!headers_sent()) {
        http_response_code((int)$httpStatus);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('X-Ilmuna-API-Version: ' . ILMUNA_SSO_API_VERSION);

        if ($privateKey !== null && $privateKey !== '') {
            $respTs  = time();
            $respSig = ilmuna_sso_api_sign_response($respTs, $json, $privateKey);
            header('X-Ilmuna-Response-Timestamp: ' . $respTs);
            header('X-Ilmuna-Response-Signature: ' . $respSig);
        }

        foreach ($extraHeaders as $k => $v) {
            header($k . ': ' . $v);
        }
    }

    echo $json;
    exit;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    if (!headers_sent()) {
        http_response_code(204);
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Ilmuna-Public-Key, X-Ilmuna-Timestamp, X-Ilmuna-Nonce, X-Ilmuna-Signature, X-Ilmuna-RSA-Signature, X-Ilmuna-Site');
        header('Access-Control-Max-Age: 600');
    }
    exit;
}

if ($method !== 'GET' && $method !== 'POST') {
    ilmuna_sso_api_emit_json(405, [
        'success' => false,
        'error'   => [
            'code'    => 'METHOD_NOT_ALLOWED',
            'message' => 'Endpoint ini hanya menerima metode GET atau POST.',
        ],
    ], ['Allow' => 'GET, POST, OPTIONS']);
}

try {
    $pdo = getDBConnection();
    $pdo->exec("SET time_zone = '+07:00'");
} catch (Throwable $e) {
    ilmuna_sso_api_emit_json(503, [
        'success' => false,
        'error'   => [
            'code'    => 'DATABASE_UNAVAILABLE',
            'message' => 'Koneksi database sementara tidak tersedia.',
        ],
    ]);
}

$rawBody = ($method === 'POST') ? (string)@file_get_contents('php://input') : '';
$bodyArr = [];
if ($rawBody !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $bodyArr = $decoded;
    }
}

$params   = array_merge($_GET, $_POST, $bodyArr);
$resource = strtolower(trim((string)($params['resource'] ?? 'all')));

$auth = ilmuna_sso_api_authenticate($pdo, [
    'method' => $method,
    'uri'    => $_SERVER['REQUEST_URI'] ?? '/api/sso-broadcast.php',
    'query'  => $_GET,
    'body'   => $rawBody,
]);

$durationMs   = (int)round((microtime(true) - $startMicro) * 1000);
$extraHeaders = [];

if (isset($auth['rate_limit']) && is_array($auth['rate_limit'])) {
    $extraHeaders['X-RateLimit-Limit']     = (string)$auth['rate_limit']['limit'];
    $extraHeaders['X-RateLimit-Remaining'] = (string)$auth['rate_limit']['remaining'];
    $extraHeaders['X-RateLimit-Reset']     = (string)$auth['rate_limit']['reset_in'];
    if (!$auth['rate_limit']['allowed']) {
        $extraHeaders['Retry-After'] = (string)$auth['rate_limit']['reset_in'];
    }
}

if (!$auth['ok']) {
    $meta = isset($auth['meta']) && is_array($auth['meta']) ? $auth['meta'] : [];
    ilmuna_sso_api_log_audit($pdo, [
        'client_id'     => $meta['client_id'] ?? null,
        'public_key'    => $meta['public_key'] ?? null,
        'endpoint'      => '/api/sso-broadcast',
        'resource'      => $resource,
        'http_status'   => (int)$auth['status'],
        'client_ip'     => $meta['client_ip'] ?? ilmuna_sso_api_client_ip(),
        'client_site'   => $meta['client_site'] ?? null,
        'sig_algorithm' => $meta['sig_algorithm'] ?? null,
        'error_code'    => (string)$auth['code'],
        'duration_ms'   => $durationMs,
    ]);

    ilmuna_sso_api_emit_json((int)$auth['status'], [
        'success'     => false,
        'error'       => [
            'code'    => (string)$auth['code'],
            'message' => (string)$auth['message'],
        ],
        'server_time' => time(),
    ], $extraHeaders);
}

if (!empty($auth['origin'])) {
    $originHost = ilmuna_sso_api_extract_host($auth['origin']);
    if (ilmuna_sso_api_domain_allowed($originHost, $auth['allowed_domains'])) {
        $extraHeaders['Access-Control-Allow-Origin'] = $auth['origin'];
        $extraHeaders['Vary'] = 'Origin';
    }
}

$client     = $auth['client'];
$privateKey = $auth['private_key'];

$aliasMap = [
    'tugas'     => 'tugas_broadcast',
    'proyek'    => 'proyek_broadcast',
    'kelompok'  => 'kelompok_broadcast',
    'asesmen'   => 'jadwal_asesmen',
    'jadwal'    => 'jadwal_asesmen',
    'feed'      => 'all',
    'broadcast' => 'all',
    'sso_feed'  => 'student_feed',
    'ticket'    => 'ws_ticket',
];
$canonicalResource = isset($aliasMap[$resource]) ? $aliasMap[$resource] : $resource;

try {
    $school = ilmuna_wp_api_fetch_school_profile($pdo);
    $ctx    = ilmuna_sso_resolve_identity_context($pdo, $params);
    $wsStat = ilmuna_sso_ws_server_status((int)$client['ws_port'], (int)$client['ws_ipc_port']);
    $data   = [];

    if ($canonicalResource === 'health') {
        $data = [
            'status'          => 'healthy',
            'client_name'     => (string)$client['client_name'],
            'sig_mode'        => (string)$client['sig_mode'],
            'allowed_scopes'  => ilmuna_sso_api_parse_list($client['allowed_scopes']),
            'websocket'       => [
                'ws_url'      => (string)($client['ws_public_url'] ?: ilmuna_sso_default_ws_url((int)$client['ws_port'])),
                'ws_port'     => (int)$client['ws_port'],
                'ipc_port'    => (int)$client['ws_ipc_port'],
                'daemon_live' => (bool)$wsStat['running'],
                'ws_live'     => (bool)$wsStat['ws_running'],
                'ipc_live'    => (bool)$wsStat['ipc_running'],
            ],
            'school'          => $school,
            'server_time'     => time(),
            'server_time_wib' => date('Y-m-d H:i:s') . ' WIB',
            'api_version'     => ILMUNA_SSO_API_VERSION,
        ];
    } elseif ($canonicalResource === 'classes') {
        $data = [
            'school'  => $school,
            'classes' => ilmuna_sso_fetch_master_classes($pdo),
        ];
    } elseif ($canonicalResource === 'tugas_broadcast') {
        $res = ilmuna_sso_fetch_tugas_broadcast($pdo, $params, $ctx);
        $data = [
            'school'           => $school,
            'identity_context' => [
                'role'              => $ctx['role'],
                'filtered_by_class' => $ctx['filtered_by_class'],
                'kelas_list'        => $ctx['kelas_list'],
            ],
            'tugas'            => $res['items'],
            'pagination'       => $res['pagination'],
        ];
    } elseif ($canonicalResource === 'proyek_broadcast') {
        $res = ilmuna_sso_fetch_proyek_broadcast($pdo, $params, $ctx);
        $data = [
            'school'           => $school,
            'identity_context' => [
                'role'              => $ctx['role'],
                'filtered_by_class' => $ctx['filtered_by_class'],
                'kelas_list'        => $ctx['kelas_list'],
            ],
            'proyek'           => $res['items'],
            'pagination'       => $res['pagination'],
        ];
    } elseif ($canonicalResource === 'kelompok_broadcast') {
        $res = ilmuna_sso_fetch_kelompok_broadcast($pdo, $params, $ctx);
        $data = [
            'school'           => $school,
            'identity_context' => [
                'role'              => $ctx['role'],
                'filtered_by_class' => $ctx['filtered_by_class'],
                'kelas_list'        => $ctx['kelas_list'],
                'siswa'             => $ctx['siswa'],
            ],
            'kelompok'         => $res['items'],
            'total_items'      => $res['total_items'],
        ];
    } elseif ($canonicalResource === 'jadwal_asesmen') {
        $res = ilmuna_sso_fetch_jadwal_asesmen($pdo, $params, $ctx);
        $data = [
            'school'           => $school,
            'identity_context' => [
                'role'              => $ctx['role'],
                'filtered_by_class' => $ctx['filtered_by_class'],
                'kelas_list'        => $ctx['kelas_list'],
            ],
            'jadwal_asesmen'   => $res['items'],
            'pagination'       => $res['pagination'],
        ];
    } elseif ($canonicalResource === 'events') {
        $sinceId = isset($params['since_event_id']) ? (int)$params['since_event_id'] : 0;
        $limit   = isset($params['limit']) ? (int)$params['limit'] : 30;
        $events  = ilmuna_sso_fetch_recent_events($pdo, $ctx, $sinceId, $limit);
        $data = [
            'school'           => $school,
            'identity_context' => [
                'role'              => $ctx['role'],
                'filtered_by_class' => $ctx['filtered_by_class'],
                'kelas_list'        => $ctx['kelas_list'],
            ],
            'events'           => $events,
            'total_events'     => count($events),
        ];
    } elseif ($canonicalResource === 'ws_ticket') {
        $roleCode = trim((string)($params['role'] ?? ($ctx['role'] !== 'all' ? $ctx['role'] : 'siswa')));
        $subscribeAll = !empty($params['subscribe_all']) || in_array(strtolower($roleCode), ['admin', 'superadmin', 'operator'], true) || empty($ctx['kelas_ids']);
        // Bila parameter kelas eksplisit diisi namun pengguna meminta tiket seluruh kelas, hormati flag subscribe_all=0
        if (!empty($ctx['filtered_by_class']) && !empty($ctx['kelas_ids']) && empty($params['subscribe_all'])) {
            $subscribeAll = false;
        }
        $identStr = (string)($params['email'] ?? ($params['nis'] ?? ($params['nip'] ?? ($params['username'] ?? ''))));
        $ttl      = isset($params['ttl']) ? (int)$params['ttl'] : 180;

        $ticketInfo = ilmuna_sso_api_issue_ws_ticket($pdo, $client, $privateKey, [
            'ttl'             => $ttl,
            'kelas_ids'       => $ctx['kelas_ids'],
            'siswa_id'        => $ctx['siswa_id'],
            'guru_id'         => $ctx['guru_id'],
            'role_code'       => $roleCode,
            'user_identifier' => $identStr,
            'subscribe_all'   => $subscribeAll,
        ]);

        $streamBase = ilmuna_wp_api_public_base_url() . '/api/sso-stream.php';
        $ticketInfo['daemon_live']        = (bool)$wsStat['running'];
        $ticketInfo['stream_url']         = $streamBase;
        $ticketInfo['stream_connect_url'] = $streamBase . '?ticket=' . rawurlencode((string)$ticketInfo['ticket']);

        $data = [
            'ws_ticket'        => $ticketInfo,
            'daemon_live'      => (bool)$wsStat['running'],
            'identity_context' => [
                'role'              => $ctx['role'],
                'filtered_by_class' => $ctx['filtered_by_class'],
                'kelas_list'        => $ctx['kelas_list'],
                'siswa'             => $ctx['siswa'],
                'guru'              => $ctx['guru'],
            ],
        ];
    } elseif ($canonicalResource === 'all' || $canonicalResource === 'student_feed') {
        $feed = ilmuna_sso_build_unified_feed($pdo, $params, $ctx);
        $data = array_merge([
            'school'    => $school,
            'websocket' => [
                'ws_url'      => (string)($client['ws_public_url'] ?: ilmuna_sso_default_ws_url((int)$client['ws_port'])),
                'daemon_live' => (bool)$wsStat['running'],
            ],
        ], $feed);

        // Opsional: sekalian terbitkan tiket WebSocket dalam 1 round-trip jika ?include_ws_ticket=1
        if (!empty($params['include_ws_ticket'])) {
            $roleCode = trim((string)($params['role'] ?? ($ctx['role'] !== 'all' ? $ctx['role'] : 'siswa')));
            $subscribeAll = !empty($params['subscribe_all']) || (empty($ctx['kelas_ids']) && !$ctx['filtered_by_class']);
            $identStr = (string)($params['email'] ?? ($params['nis'] ?? ($params['nip'] ?? ($params['username'] ?? ''))));
            $data['ws_ticket'] = ilmuna_sso_api_issue_ws_ticket($pdo, $client, $privateKey, [
                'ttl'             => 180,
                'kelas_ids'       => $ctx['kelas_ids'],
                'siswa_id'        => $ctx['siswa_id'],
                'guru_id'         => $ctx['guru_id'],
                'role_code'       => $roleCode,
                'user_identifier' => $identStr,
                'subscribe_all'   => $subscribeAll,
            ]);
            $streamBase = ilmuna_wp_api_public_base_url() . '/api/sso-stream.php';
            $data['ws_ticket']['daemon_live']        = (bool)$wsStat['running'];
            $data['ws_ticket']['stream_url']         = $streamBase;
            $data['ws_ticket']['stream_connect_url'] = $streamBase . '?ticket=' . rawurlencode((string)$data['ws_ticket']['ticket']);
        }
    } else {
        $durationMs = (int)round((microtime(true) - $startMicro) * 1000);
        ilmuna_sso_api_log_audit($pdo, [
            'client_id'     => (int)$client['id'],
            'public_key'    => (string)$client['public_key'],
            'endpoint'      => '/api/sso-broadcast',
            'resource'      => $resource,
            'http_status'   => 400,
            'client_ip'     => $auth['client_ip'],
            'client_site'   => $auth['client_site'],
            'sig_algorithm' => $auth['sig_mode'],
            'error_code'    => 'UNKNOWN_RESOURCE',
            'duration_ms'   => $durationMs,
        ]);
        ilmuna_sso_api_emit_json(400, [
            'success' => false,
            'error'   => [
                'code'    => 'UNKNOWN_RESOURCE',
                'message' => 'Resource tidak dikenali. Gunakan: health, classes, tugas_broadcast, proyek_broadcast, kelompok_broadcast, jadwal_asesmen, events, ws_ticket, student_feed, atau all.',
            ],
        ], $extraHeaders, $privateKey);
    }

    $durationMs = (int)round((microtime(true) - $startMicro) * 1000);
    ilmuna_sso_api_log_audit($pdo, [
        'client_id'     => (int)$client['id'],
        'public_key'    => (string)$client['public_key'],
        'endpoint'      => '/api/sso-broadcast',
        'resource'      => $canonicalResource,
        'http_status'   => 200,
        'client_ip'     => $auth['client_ip'],
        'client_site'   => $auth['client_site'],
        'sig_algorithm' => $auth['sig_mode'],
        'error_code'    => null,
        'duration_ms'   => $durationMs,
    ]);

    ilmuna_sso_api_emit_json(200, [
        'success'  => true,
        'resource' => $canonicalResource,
        'data'     => $data,
        'meta'     => [
            'api_version'      => ILMUNA_SSO_API_VERSION,
            'generated_at'     => date('c'),
            'generated_at_wib' => date('d M Y H:i:s') . ' WIB',
            'sig_mode'         => (string)$client['sig_mode'],
        ],
    ], $extraHeaders, $privateKey);
} catch (Throwable $e) {
    $durationMs = (int)round((microtime(true) - $startMicro) * 1000);
    ilmuna_sso_api_log_audit($pdo, [
        'client_id'     => (int)$client['id'],
        'public_key'    => (string)$client['public_key'],
        'endpoint'      => '/api/sso-broadcast',
        'resource'      => $canonicalResource,
        'http_status'   => 500,
        'client_ip'     => $auth['client_ip'],
        'client_site'   => $auth['client_site'],
        'sig_algorithm' => $auth['sig_mode'],
        'error_code'    => 'INTERNAL_ERROR',
        'duration_ms'   => $durationMs,
    ]);
    ilmuna_sso_api_emit_json(500, [
        'success' => false,
        'error'   => [
            'code'    => 'INTERNAL_ERROR',
            'message' => 'Terjadi kesalahan internal saat membangun respon API SSO: ' . $e->getMessage(),
        ],
    ], $extraHeaders, $privateKey);
}
