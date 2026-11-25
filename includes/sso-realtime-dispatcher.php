<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — REAL-TIME WEBSOCKET EVENT DISPATCHER (PUSH KE MARGO ONE SSO)
 * ==============================================================================
 * Mengirimkan notifikasi real-time instan (0ms, TANPA polling / TANPA reload)
 * melalui kanal IPC TCP lokal ke Daemon WebSocket RFC 6455 (`websocket/sso-realtime-server.php`)
 * setiap kali terjadi perubahan pada:
 *   1. Tugas & Deadline Tugas (`tugas`)
 *   2. Kelompok Tugas (`tugas_kelompok`, `tugas_kelompok_anggota`)
 *   3. Proyek P5 / PjBL & Deadline Proyek (`proyek`)
 *   4. Kelompok Proyek (`proyek_kelompok`, `proyek_kelompok_anggota`)
 *   5. Jadwal Asesmen dari Bank & Master Asesmen
 *      (`asesmen_sumatif`, `asesmen_sumatif_kelas`, `asesmen_formatif`,
 *       `asesmen_diagnostik`, `pengayaan_remedial`)
 *
 * Seluruh fungsi dibungkus pelindung non-fatal (try/catch) sehingga dijamin 100%
 * tidak pernah mengganggu transaksi database utama meskipun daemon sedang mati.
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/includes/sso-api-security.php';
require_once BASE_PATH . '/includes/sso-api-data.php';

/**
 * Cek apakah server WebSocket sedang aktif menerima koneksi pada port IPC / WS
 */
function ilmuna_sso_ws_server_status($wsPort = null, $ipcPort = null)
{
    $wsPort  = $wsPort ? (int)$wsPort : ILMUNA_SSO_WS_DEFAULT_PORT;
    $ipcPort = $ipcPort ? (int)$ipcPort : ILMUNA_SSO_WS_DEFAULT_IPC_PORT;

    $wsRunning  = false;
    $ipcRunning = false;

    $fpWs = @fsockopen('127.0.0.1', $wsPort, $e1, $s1, 0.15);
    if (is_resource($fpWs)) {
        $wsRunning = true;
        @fclose($fpWs);
    }

    $fpIpc = @fsockopen('127.0.0.1', $ipcPort, $e2, $s2, 0.15);
    if (is_resource($fpIpc)) {
        $ipcRunning = true;
        @fclose($fpIpc);
    }

    return [
        'running'     => ($wsRunning && $ipcRunning),
        'ws_running'  => $wsRunning,
        'ipc_running' => $ipcRunning,
        'ws_port'     => $wsPort,
        'ipc_port'    => $ipcPort,
    ];
}

/**
 * Kirim frame JSON bertanda tangan HMAC ke Daemon WebSocket lokal (127.0.0.1:IPC_PORT)
 */
function ilmuna_sso_push_to_ws_daemon(array $envelope, $ipcPort = null)
{
    $ipcPort = $ipcPort ? (int)$ipcPort : ILMUNA_SSO_WS_DEFAULT_IPC_PORT;
    $json = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    $ts  = time();
    $mac = hash_hmac('sha256', $ts . "\n" . $json, ilmuna_sso_ipc_secret());
    $packet = json_encode([
        'ts'       => $ts,
        'mac'      => $mac,
        'envelope' => $envelope,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

    $fp = @fsockopen('127.0.0.1', $ipcPort, $errno, $errstr, 0.2);
    if (!is_resource($fp)) {
        return false;
    }
    @stream_set_timeout($fp, 0, 200000);
    @fwrite($fp, $packet);
    @fclose($fp);
    return true;
}

/**
 * Kirim Signed Outbound Webhook non-blocking (opsional jika webhook_url diisi di sso_api_clients)
 */
function ilmuna_sso_push_outbound_webhook(array $client, $privateKey, array $envelope)
{
    $webhookUrl = trim((string)($client['webhook_url'] ?? ''));
    if ($webhookUrl === '' || !function_exists('curl_init')) {
        return false;
    }

    $body  = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ts    = (string)time();
    $nonce = bin2hex(random_bytes(16));
    $path  = parse_url($webhookUrl, PHP_URL_PATH) ?: '/webhook';
    $canon = implode("\n", ['POST', $path, '', $ts, $nonce, hash('sha256', (string)$body)]);
    $sig   = hash_hmac('sha256', $canon, (string)$privateKey);

    $ch = @curl_init($webhookUrl);
    if (!$ch) {
        return false;
    }
    @curl_setopt_array($ch, [
        CURLOPT_POST              => true,
        CURLOPT_POSTFIELDS        => $body,
        CURLOPT_RETURNTRANSFER    => true,
        CURLOPT_CONNECTTIMEOUT_MS => 800,
        CURLOPT_TIMEOUT_MS        => 1500,
        CURLOPT_HTTPHEADER        => [
            'Content-Type: application/json; charset=utf-8',
            'X-Ilmuna-Public-Key: ' . $client['public_key'],
            'X-Ilmuna-Timestamp: ' . $ts,
            'X-Ilmuna-Nonce: ' . $nonce,
            'X-Ilmuna-Signature: ' . $sig,
            'X-Ilmuna-Event: ' . ($envelope['event_type'] ?? 'broadcast'),
        ],
    ]);
    @curl_exec($ch);
    @curl_close($ch);
    return true;
}

/**
 * Simpan event real-time ke sso_realtime_events + dorong seketika ke WebSocket Server & Outbound Webhook
 */
function ilmuna_sso_emit_realtime_event(PDO $pdo, array $event)
{
    try {
        ilmuna_sso_api_ensure_schema($pdo);

        if (mt_rand(1, 25) === 1) {
            $pdo->exec("DELETE FROM sso_realtime_events WHERE created_at < DATE_SUB(NOW(), INTERVAL 14 DAY)");
        }

        $client = $pdo->query("SELECT * FROM sso_api_clients WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $privateKey = $client ? ilmuna_sso_api_decrypt_secret($client['private_key_enc']) : ilmuna_sso_ipc_secret();
        $ipcPort = ($client && !empty($client['ws_ipc_port'])) ? (int)$client['ws_ipc_port'] : ILMUNA_SSO_WS_DEFAULT_IPC_PORT;

        $eventUuid   = 'evt_' . bin2hex(random_bytes(12));
        $eventType   = trim((string)($event['event_type'] ?? 'broadcast.notification'));
        $category    = trim((string)($event['category'] ?? 'broadcast'));
        $kelasId     = !empty($event['kelas_id']) ? (int)$event['kelas_id'] : null;
        $namaKelas   = isset($event['nama_kelas']) ? trim((string)$event['nama_kelas']) : null;
        $kodeKelas   = isset($event['kode_kelas']) ? trim((string)$event['kode_kelas']) : null;
        $referenceId = !empty($event['reference_id']) ? (int)$event['reference_id'] : null;
        $title       = trim((string)($event['title'] ?? 'Notifikasi Akademik Baru'));
        $summary     = trim((string)($event['summary'] ?? ''));
        $deadlineAt  = !empty($event['deadline_at']) ? (string)$event['deadline_at'] : null;
        $urgency     = trim((string)($event['urgency'] ?? 'normal')) ?: 'normal';
        $portalUrl   = !empty($event['portal_url']) ? trim((string)$event['portal_url']) : ilmuna_wp_api_public_base_url();
        $dataPayload = isset($event['data']) && is_array($event['data']) ? $event['data'] : [];

        if ($kelasId && ($namaKelas === null || $namaKelas === '')) {
            $stK = $pdo->prepare("SELECT nama_kelas, kode_kelas FROM kelas WHERE id = ? LIMIT 1");
            $stK->execute([$kelasId]);
            $kRow = $stK->fetch(PDO::FETCH_ASSOC);
            if ($kRow) {
                $namaKelas = (string)$kRow['nama_kelas'];
                $kodeKelas = (string)($kRow['kode_kelas'] ?? '');
            }
        }

        $payloadJson = json_encode($dataPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $sigHmac     = hash_hmac('sha256', implode('|', [$eventUuid, $eventType, (string)$kelasId, $title, $payloadJson]), $privateKey ?: 'ilmuna');

        $ins = $pdo->prepare("
            INSERT INTO sso_realtime_events
            (event_uuid, event_type, category, kelas_id, nama_kelas, kode_kelas, reference_id, title, summary, deadline_at, urgency, portal_url, payload_json, signature_hmac)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $eventUuid,
            $eventType,
            $category,
            $kelasId,
            $namaKelas,
            $kodeKelas,
            $referenceId,
            $title,
            $summary,
            $deadlineAt,
            $urgency,
            $portalUrl,
            $payloadJson,
            $sigHmac,
        ]);
        $eventId = (int)$pdo->lastInsertId();

        $envelope = [
            'type'           => 'ilmuna.realtime.event',
            'event_id'       => $eventId,
            'event_uuid'     => $eventUuid,
            'event_type'     => $eventType,
            'category'       => $category,
            'kelas'          => [
                'id'   => $kelasId,
                'nama' => $namaKelas ?: 'Seluruh Kelas',
                'kode' => $kodeKelas ?: '',
            ],
            'reference_id'   => $referenceId,
            'title'          => $title,
            'summary'        => $summary,
            'deadline_at'    => $deadlineAt,
            'urgency'        => $urgency,
            'portal_url'     => $portalUrl,
            'data'           => $dataPayload,
            'signature_hmac' => $sigHmac,
            'timestamp'      => time(),
            'created_at_iso' => date('c'),
        ];

        $pushedWs = ilmuna_sso_push_to_ws_daemon($envelope, $ipcPort);
        if ($client && !empty($client['webhook_url'])) {
            ilmuna_sso_push_outbound_webhook($client, $privateKey, $envelope);
        }

        return [
            'ok'        => true,
            'event_id'  => $eventId,
            'pushed_ws' => $pushedWs,
            'envelope'  => $envelope,
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Hook Helper 1: Broadcast perubahan Tugas (`tugas.created`, `tugas.updated`, `tugas.status_changed`)
 */
function ilmuna_sso_notify_tugas(PDO $pdo, $idTugas, $eventType = 'tugas.created')
{
    try {
        $idTugas = (int)$idTugas;
        if ($idTugas <= 0) {
            return;
        }
        $st = $pdo->prepare("
            SELECT t.*, k.id AS id_kelas, k.nama_kelas, k.kode_kelas,
                   mp.nama_mata_pelajaran, mp.kode_mapel,
                   COALESCE(g2.nama_guru, g.nama_guru, u.full_name, 'Guru Pengampu') AS nama_guru
            FROM tugas t
            JOIN kelas_mata_pelajaran kmp ON t.id_kelas_mata_pelajaran = kmp.id
            JOIN kelas k ON kmp.id_kelas = k.id
            JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
            LEFT JOIN guru g2 ON gmp.id_guru = g2.id
            LEFT JOIN guru g ON t.id_guru = g.id_user
            LEFT JOIN users u ON t.id_guru = u.id
            WHERE t.id = ?
            LIMIT 1
        ");
        $st->execute([$idTugas]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['status_tugas'] === 'Draft') {
            return;
        }

        $dl = ilmuna_wp_api_deadline_meta($row['tenggat_waktu'], false);
        $prefix = ($eventType === 'tugas.created') ? 'Tugas Baru' : 'Pembaruan Tugas';
        $title  = $prefix . ': ' . $row['judul_tugas'] . ' (' . $row['nama_mata_pelajaran'] . ')';
        $sum    = 'Kelas ' . $row['nama_kelas'] . ' · ' . $row['jenis_penugasan']
                . (!empty($row['tenggat_waktu']) ? (' · Batas: ' . ilmuna_wp_api_format_id_date($row['tenggat_waktu'], true)) : '');

        ilmuna_sso_emit_realtime_event($pdo, [
            'event_type'   => $eventType,
            'category'     => 'tugas',
            'kelas_id'     => (int)$row['id_kelas'],
            'nama_kelas'   => (string)$row['nama_kelas'],
            'kode_kelas'   => (string)($row['kode_kelas'] ?? ''),
            'reference_id' => $idTugas,
            'title'        => $title,
            'summary'      => $sum,
            'deadline_at'  => $row['tenggat_waktu'] ?: null,
            'urgency'      => $dl['urgensi'],
            'portal_url'   => ilmuna_wp_api_public_base_url() . '/dashboard/siswa/tugas',
            'data'         => [
                'id_tugas'         => $idTugas,
                'judul_tugas'      => (string)$row['judul_tugas'],
                'jenis_penugasan'  => (string)$row['jenis_penugasan'],
                'status_tugas'     => (string)$row['status_tugas'],
                'mata_pelajaran'   => (string)$row['nama_mata_pelajaran'],
                'guru_pengampu'    => (string)$row['nama_guru'],
                'tenggat_waktu'    => $row['tenggat_waktu'],
                'sisa_waktu_label' => $dl['sisa_waktu_label'],
            ],
        ]);
    } catch (Throwable $e) {
        // Non-fatal
    }
}

/**
 * Hook Helper 2: Broadcast pembagian/pembaruan Kelompok Tugas (`kelompok.tugas_updated`)
 */
function ilmuna_sso_notify_kelompok_tugas(PDO $pdo, $idTugas, $actionSummary = 'Pembagian kelompok tugas telah diperbarui')
{
    try {
        $idTugas = (int)$idTugas;
        if ($idTugas <= 0) {
            return;
        }
        $st = $pdo->prepare("
            SELECT t.id, t.judul_tugas, t.tenggat_waktu,
                   k.id AS id_kelas, k.nama_kelas, k.kode_kelas,
                   mp.nama_mata_pelajaran
            FROM tugas t
            JOIN kelas_mata_pelajaran kmp ON t.id_kelas_mata_pelajaran = kmp.id
            JOIN kelas k ON kmp.id_kelas = k.id
            JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            WHERE t.id = ?
            LIMIT 1
        ");
        $st->execute([$idTugas]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }

        $ctx = ['filtered_by_class' => true, 'kelas_ids' => [(int)$row['id_kelas']]];
        $tugasDto = ilmuna_sso_fetch_tugas_broadcast($pdo, ['limit' => 50], $ctx);
        $kelompokList = [];
        foreach ($tugasDto['items'] as $it) {
            if ((int)$it['id'] === $idTugas) {
                $kelompokList = $it['daftar_kelompok'];
                break;
            }
        }

        ilmuna_sso_emit_realtime_event($pdo, [
            'event_type'   => 'kelompok.tugas_updated',
            'category'     => 'kelompok',
            'kelas_id'     => (int)$row['id_kelas'],
            'nama_kelas'   => (string)$row['nama_kelas'],
            'kode_kelas'   => (string)($row['kode_kelas'] ?? ''),
            'reference_id' => $idTugas,
            'title'        => 'Kelompok Tugas: ' . $row['judul_tugas'],
            'summary'      => $actionSummary . ' pada kelas ' . $row['nama_kelas'] . ' (' . count($kelompokList) . ' kelompok).',
            'deadline_at'  => $row['tenggat_waktu'] ?: null,
            'urgency'      => 'segera',
            'portal_url'   => ilmuna_wp_api_public_base_url() . '/dashboard/siswa/tugas',
            'data'         => [
                'sumber'          => 'tugas',
                'id_tugas'        => $idTugas,
                'judul_tugas'     => (string)$row['judul_tugas'],
                'mata_pelajaran'  => (string)$row['nama_mata_pelajaran'],
                'jumlah_kelompok' => count($kelompokList),
                'daftar_kelompok' => $kelompokList,
            ],
        ]);
    } catch (Throwable $e) {
        // Non-fatal
    }
}

/**
 * Hook Helper 3: Broadcast perubahan Proyek P5 / PjBL (`proyek.created`, `proyek.updated`)
 */
function ilmuna_sso_notify_proyek(PDO $pdo, $idProyek, $eventType = 'proyek.created')
{
    try {
        $idProyek = (int)$idProyek;
        if ($idProyek <= 0) {
            return;
        }
        $st = $pdo->prepare("
            SELECT p.*, k.id AS id_kelas, k.nama_kelas, k.kode_kelas,
                   mp.nama_mata_pelajaran,
                   COALESCE(g2.nama_guru, g.nama_guru, u.full_name, 'Guru Pembimbing') AS nama_guru
            FROM proyek p
            JOIN kelas_mata_pelajaran kmp ON p.id_kelas_mata_pelajaran = kmp.id
            JOIN kelas k ON kmp.id_kelas = k.id
            JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
            LEFT JOIN guru g2 ON gmp.id_guru = g2.id
            LEFT JOIN guru g ON p.id_guru = g.id_user
            LEFT JOIN users u ON p.id_guru = u.id
            WHERE p.id = ?
            LIMIT 1
        ");
        $st->execute([$idProyek]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['status_proyek'] === 'Diarsipkan') {
            return;
        }

        $dl = ilmuna_wp_api_deadline_meta($row['jadwal_selesai'], true);
        $prefix = ($eventType === 'proyek.created') ? 'Proyek Baru' : 'Pembaruan Proyek';
        $title  = $prefix . ': ' . $row['nama_proyek'] . ' (' . $row['nama_mata_pelajaran'] . ')';
        $sum    = 'Kelas ' . $row['nama_kelas'] . ' · Tema: ' . ($row['tema_proyek'] ?: '-')
                . (!empty($row['jadwal_selesai']) ? (' · Tenggat: ' . ilmuna_wp_api_format_id_date($row['jadwal_selesai'], false)) : '');

        ilmuna_sso_emit_realtime_event($pdo, [
            'event_type'   => $eventType,
            'category'     => 'proyek',
            'kelas_id'     => (int)$row['id_kelas'],
            'nama_kelas'   => (string)$row['nama_kelas'],
            'kode_kelas'   => (string)($row['kode_kelas'] ?? ''),
            'reference_id' => $idProyek,
            'title'        => $title,
            'summary'      => $sum,
            'deadline_at'  => !empty($row['jadwal_selesai']) ? ($row['jadwal_selesai'] . ' 23:59:59') : null,
            'urgency'      => $dl['urgensi'],
            'portal_url'   => ilmuna_wp_api_public_base_url() . '/dashboard/siswa/proyek',
            'data'         => [
                'id_proyek'        => $idProyek,
                'nama_proyek'      => (string)$row['nama_proyek'],
                'tema_proyek'      => (string)($row['tema_proyek'] ?? ''),
                'jenis_proyek'     => (string)$row['jenis_proyek'],
                'status_proyek'    => (string)$row['status_proyek'],
                'mata_pelajaran'   => (string)$row['nama_mata_pelajaran'],
                'guru_pembimbing'  => (string)$row['nama_guru'],
                'jadwal_mulai'     => $row['jadwal_mulai'],
                'jadwal_selesai'   => $row['jadwal_selesai'],
                'sisa_waktu_label' => $dl['sisa_waktu_label'],
            ],
        ]);
    } catch (Throwable $e) {
        // Non-fatal
    }
}

/**
 * Hook Helper 4: Broadcast pembagian/pembaruan Kelompok Proyek (`kelompok.proyek_updated`)
 */
function ilmuna_sso_notify_kelompok_proyek(PDO $pdo, $idProyek, $actionSummary = 'Pembagian kelompok proyek telah diperbarui')
{
    try {
        $idProyek = (int)$idProyek;
        if ($idProyek <= 0) {
            return;
        }
        $st = $pdo->prepare("
            SELECT p.id, p.nama_proyek, p.tema_proyek, p.jadwal_selesai,
                   k.id AS id_kelas, k.nama_kelas, k.kode_kelas,
                   mp.nama_mata_pelajaran
            FROM proyek p
            JOIN kelas_mata_pelajaran kmp ON p.id_kelas_mata_pelajaran = kmp.id
            JOIN kelas k ON kmp.id_kelas = k.id
            JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            WHERE p.id = ?
            LIMIT 1
        ");
        $st->execute([$idProyek]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }

        $ctx = ['filtered_by_class' => true, 'kelas_ids' => [(int)$row['id_kelas']]];
        $proyekDto = ilmuna_sso_fetch_proyek_broadcast($pdo, ['limit' => 50, 'include_completed' => '1'], $ctx);
        $kelompokList = [];
        foreach ($proyekDto['items'] as $it) {
            if ((int)$it['id'] === $idProyek) {
                $kelompokList = $it['daftar_kelompok'];
                break;
            }
        }

        ilmuna_sso_emit_realtime_event($pdo, [
            'event_type'   => 'kelompok.proyek_updated',
            'category'     => 'kelompok',
            'kelas_id'     => (int)$row['id_kelas'],
            'nama_kelas'   => (string)$row['nama_kelas'],
            'kode_kelas'   => (string)($row['kode_kelas'] ?? ''),
            'reference_id' => $idProyek,
            'title'        => 'Kelompok Proyek: ' . $row['nama_proyek'],
            'summary'      => $actionSummary . ' pada kelas ' . $row['nama_kelas'] . ' (' . count($kelompokList) . ' kelompok).',
            'deadline_at'  => !empty($row['jadwal_selesai']) ? ($row['jadwal_selesai'] . ' 23:59:59') : null,
            'urgency'      => 'segera',
            'portal_url'   => ilmuna_wp_api_public_base_url() . '/dashboard/siswa/proyek',
            'data'         => [
                'sumber'          => 'proyek',
                'id_proyek'       => $idProyek,
                'nama_proyek'     => (string)$row['nama_proyek'],
                'tema_proyek'     => (string)($row['tema_proyek'] ?? ''),
                'mata_pelajaran'  => (string)$row['nama_mata_pelajaran'],
                'jumlah_kelompok' => count($kelompokList),
                'daftar_kelompok' => $kelompokList,
            ],
        ]);
    } catch (Throwable $e) {
        // Non-fatal
    }
}

/**
 * Hook Helper 5: Broadcast Jadwal Asesmen dari Bank & Master Asesmen per Kelas Sasaran
 * Otomatis memancarkan event ke SETIAP rombel kelas yang terdaftar (termasuk multi-rombel `asesmen_sumatif_kelas`).
 */
function ilmuna_sso_notify_asesmen(PDO $pdo, $kategori, $idAsesmen, $eventType = 'asesmen.scheduled')
{
    try {
        $idAsesmen = (int)$idAsesmen;
        $kategori  = strtolower(trim((string)$kategori));
        if ($idAsesmen <= 0) {
            return;
        }

        $tableMap = [
            'sumatif'    => ['table' => 'asesmen_sumatif', 'title_col' => 'judul_asesmen', 'sub_col' => 'jenis_sumatif'],
            'formatif'   => ['table' => 'asesmen_formatif', 'title_col' => 'judul_asesmen', 'sub_col' => 'teknik_asesmen'],
            'diagnostik' => ['table' => 'asesmen_diagnostik', 'title_col' => 'judul_asesmen', 'sub_col' => 'jenis_diagnostik'],
            'remedial'   => ['table' => 'pengayaan_remedial', 'title_col' => 'judul_program', 'sub_col' => 'kategori'],
        ];
        if (!isset($tableMap[$kategori])) {
            return;
        }

        $cfg = $tableMap[$kategori];
        $st = $pdo->prepare("
            SELECT a.*, a.{$cfg['title_col']} AS resolved_judul, a.{$cfg['sub_col']} AS resolved_sub,
                   k.id AS primary_kelas_id, k.nama_kelas AS primary_nama_kelas, k.kode_kelas AS primary_kode_kelas,
                   mp.nama_mata_pelajaran
            FROM {$cfg['table']} a
            LEFT JOIN kelas_mata_pelajaran kmp ON a.id_kelas_mata_pelajaran = kmp.id
            LEFT JOIN kelas k ON kmp.id_kelas = k.id
            LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            WHERE a.id = ?
            LIMIT 1
        ");
        $st->execute([$idAsesmen]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }

        $targetClasses = [];
        if ($kategori === 'sumatif') {
            $sk = $pdo->prepare("
                SELECT DISTINCT k.id, k.nama_kelas, k.kode_kelas, mp.nama_mata_pelajaran
                FROM asesmen_sumatif_kelas ask
                JOIN kelas_mata_pelajaran kmp ON ask.id_kelas_mata_pelajaran = kmp.id
                JOIN kelas k ON kmp.id_kelas = k.id
                LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                WHERE ask.id_asesmen_sumatif = ?
            ");
            $sk->execute([$idAsesmen]);
            $targetClasses = $sk->fetchAll(PDO::FETCH_ASSOC);
        }

        if (empty($targetClasses) && !empty($row['primary_kelas_id'])) {
            $targetClasses[] = [
                'id'                  => (int)$row['primary_kelas_id'],
                'nama_kelas'          => (string)$row['primary_nama_kelas'],
                'kode_kelas'          => (string)($row['primary_kode_kelas'] ?? ''),
                'nama_mata_pelajaran' => (string)($row['nama_mata_pelajaran'] ?? 'Mata Pelajaran'),
            ];
        }

        $meta = ilmuna_sso_asesmen_schedule_meta($row['tanggal_pelaksanaan'] ?? '', $row['jam_mulai'] ?? '', $row['jam_selesai'] ?? '');
        $deadlineAt = (!empty($row['tanggal_pelaksanaan']) && !empty($row['jam_mulai']))
            ? ($row['tanggal_pelaksanaan'] . ' ' . (strlen($row['jam_mulai']) === 5 ? $row['jam_mulai'] . ':00' : $row['jam_mulai']))
            : null;

        foreach ($targetClasses as $tc) {
            $mapelName = !empty($tc['nama_mata_pelajaran']) ? $tc['nama_mata_pelajaran'] : ($row['nama_mata_pelajaran'] ?: 'Mata Pelajaran');
            $statusBadge = (string)($row['status'] ?? 'Aktif');
            $title = 'Jadwal Asesmen ' . ucfirst($kategori) . ': ' . $row['resolved_judul'];
            $summary = 'Kelas ' . $tc['nama_kelas'] . ' · ' . $mapelName . ' · ' . $meta['jadwal_formatted'] . ' (Status: ' . $statusBadge . ')';

            ilmuna_sso_emit_realtime_event($pdo, [
                'event_type'   => $eventType,
                'category'     => 'asesmen',
                'kelas_id'     => (int)$tc['id'],
                'nama_kelas'   => (string)$tc['nama_kelas'],
                'kode_kelas'   => (string)($tc['kode_kelas'] ?? ''),
                'reference_id' => $idAsesmen,
                'title'        => $title,
                'summary'      => $summary,
                'deadline_at'  => $deadlineAt,
                'urgency'      => $meta['urgensi'],
                'portal_url'   => ilmuna_wp_api_public_base_url() . '/dashboard/siswa/ujian',
                'data'         => [
                    'id_asesmen'          => $idAsesmen,
                    'kategori_asesmen'    => $kategori,
                    'judul_asesmen'       => (string)$row['resolved_judul'],
                    'jenis_asesmen'       => (string)($row['resolved_sub'] ?? ucfirst($kategori)),
                    'status'              => $statusBadge,
                    'mata_pelajaran'      => (string)$mapelName,
                    'tanggal_pelaksanaan' => $row['tanggal_pelaksanaan'],
                    'jam_mulai'           => !empty($row['jam_mulai']) ? substr((string)$row['jam_mulai'], 0, 5) : null,
                    'jam_selesai'         => !empty($row['jam_selesai']) ? substr((string)$row['jam_selesai'], 0, 5) : null,
                    'durasi_menit'        => (int)($row['durasi_menit'] ?? 60),
                    'jadwal_formatted'    => $meta['jadwal_formatted'],
                    'sisa_waktu_label'    => $meta['sisa_waktu_label'],
                ],
            ]);
        }
    } catch (Throwable $e) {
        // Non-fatal
    }
}
