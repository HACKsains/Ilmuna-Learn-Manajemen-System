<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — ZERO-CLI SERVER-SENT EVENTS (SSE) REAL-TIME STREAM (/api/sso-stream.php)
 * ==============================================================================
 * Transport real-time alternatif 100% kompatibel Shared Hosting / cPanel tanpa CLI:
 *   - Tidak membutuhkan daemon port 8090 atau akses SSH/CLI sama sekali.
 *   - Diautentikasi oleh Single-Use WebSocket Ticket (`wst_*`) yang diterbitkan
 *     server-side oleh `/api/sso-broadcast.php?resource=ws_ticket`.
 *   - Mendorong event baru dari `sso_realtime_events` sesuai `kelas_ids` pengguna
 *     seketika lewat HTTP persistent stream (`text/event-stream`).
 *
 * Kompatibel PHP 7.4 (ea-php74) hingga PHP 8.3.
 * ==============================================================================
 */

if (!defined('ILMUNA_SKIP_GD_WAF_INTERSTITIAL')) {
    define('ILMUNA_SKIP_GD_WAF_INTERSTITIAL', true);
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/sso-api-security.php';
require_once dirname(__DIR__) . '/includes/sso-realtime-dispatcher.php';

@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
@set_time_limit(55);

while (ob_get_level() > 0) {
    @ob_end_flush();
}
@ob_implicit_flush(true);

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('X-Accel-Buffering: no');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function ilmuna_sse_emit($eventName, array $payload, $id = null)
{
    if ($id !== null) {
        echo 'id: ' . (int)$id . "\n";
    }
    echo 'event: ' . $eventName . "\n";
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    @flush();
}

$ticket = trim((string)($_GET['ticket'] ?? ''));
if ($ticket === '') {
    http_response_code(401);
    ilmuna_sse_emit('ilmuna.realtime.error', ['code' => 'MISSING_TICKET', 'message' => 'Tiket real-time wajib diisi.']);
    exit;
}

try {
    $pdo = getDBConnection();
    $auth = ilmuna_sso_api_consume_ws_ticket($pdo, $ticket);
    if (empty($auth['ok'])) {
        http_response_code(403);
        ilmuna_sse_emit('ilmuna.realtime.error', [
            'code'    => $auth['reason'] ?? 'TICKET_INVALID',
            'message' => 'Tiket real-time tidak valid atau sudah kedaluwarsa.',
        ]);
        exit;
    }

    $kelasIds     = array_values(array_unique(array_map('intval', $auth['kelas_ids'] ?? [])));
    $subscribeAll = !empty($auth['subscribe_all']);

    $lastId = 0;
    if (isset($_SERVER['HTTP_LAST_EVENT_ID']) && ctype_digit((string)$_SERVER['HTTP_LAST_EVENT_ID'])) {
        $lastId = (int)$_SERVER['HTTP_LAST_EVENT_ID'];
    } elseif (isset($_GET['last_id']) && ctype_digit((string)$_GET['last_id'])) {
        $lastId = (int)$_GET['last_id'];
    } else {
        // Mulai dari event terbaru saat ini agar event lama tidak ter-spam ulang saat koneksi baru dibuka
        $lastId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM sso_realtime_events")->fetchColumn();
    }

    ilmuna_sse_emit('ilmuna.realtime.welcome', [
        'type'          => 'ilmuna.realtime.welcome',
        'transport'     => 'sse',
        'subscribe_all' => $subscribeAll,
        'kelas_ids'     => $kelasIds,
        'last_id'       => $lastId,
        'server_time'   => date('c'),
    ]);

    $startedAt = time();
    $lastPing  = $startedAt;
    $maxSecs   = 42; // Rotasi bersih sebelum limit 60s cPanel PHP

    while ((time() - $startedAt) < $maxSecs) {
        if (connection_aborted()) {
            break;
        }

        try {
            $st = $pdo->prepare("
                SELECT id, event_uuid, event_type, category, kelas_id, nama_kelas, kode_kelas,
                       reference_id, title, summary, deadline_at, urgency, portal_url, payload_json, created_at
                FROM sso_realtime_events
                WHERE id > ?
                ORDER BY id ASC
                LIMIT 25
            ");
            $st->execute([$lastId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $lastId = (int)$row['id'];
                $kId    = !empty($row['kelas_id']) ? (int)$row['kelas_id'] : 0;

                if (!$subscribeAll && $kId > 0 && !in_array($kId, $kelasIds, true)) {
                    continue;
                }

                $decodedPayload = json_decode((string)($row['payload_json'] ?? '{}'), true);
                if (!is_array($decodedPayload)) {
                    $decodedPayload = [];
                }

                $envelope = [
                    'type'       => 'ilmuna.realtime.event',
                    'event_id'   => $lastId,
                    'event_uuid' => (string)$row['event_uuid'],
                    'event_type' => (string)$row['event_type'],
                    'category'   => (string)$row['category'],
                    'kelas_id'   => $kId ?: null,
                    'nama_kelas' => (string)($row['nama_kelas'] ?? ''),
                    'kode_kelas' => (string)($row['kode_kelas'] ?? ''),
                    'title'      => (string)$row['title'],
                    'summary'    => (string)($row['summary'] ?? ''),
                    'urgency'    => (string)($row['urgency'] ?? 'normal'),
                    'portal_url' => (string)($row['portal_url'] ?? ''),
                    'created_at' => (string)$row['created_at'],
                    'payload'    => array_merge([
                        'id'                => (int)($row['reference_id'] ?? $lastId),
                        'kategori_item'     => (string)$row['category'],
                        'type'              => (string)$row['event_type'],
                        'judul'             => (string)$row['title'],
                        'deskripsi_ringkas' => (string)($row['summary'] ?? ''),
                        'portal_url'        => (string)($row['portal_url'] ?? ''),
                        'kelas'             => [
                            'id'   => $kId,
                            'nama' => (string)($row['nama_kelas'] ?? ''),
                            'kode' => (string)($row['kode_kelas'] ?? ''),
                        ],
                    ], $decodedPayload),
                ];

                ilmuna_sse_emit('ilmuna.realtime.event', $envelope, $lastId);
            }
        } catch (Throwable $eLoop) {
            // Lanjutkan loop dengan aman
        }

        if ((time() - $lastPing) >= 12) {
            echo ": keepalive " . time() . "\n\n";
            @flush();
            $lastPing = time();
        }

        usleep(1200000); // 1.2 detik antar cek DB di sisi server
    }

    ilmuna_sse_emit('ilmuna.stream.rotate', ['last_id' => $lastId]);
} catch (Throwable $e) {
    ilmuna_sse_emit('ilmuna.realtime.error', ['code' => 'SERVER_ERROR', 'message' => 'Stream error']);
}
