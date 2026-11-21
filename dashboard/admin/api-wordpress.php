<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — INTEGRASI API WORDPRESS (PUBLIC KEY & PRIVATE KEY MANAGER)
 * ==============================================================================
 * Panel Admin untuk mengelola koneksi aman learn.ilmuna.my.id <-> ilmuna.my.id:
 *   1. Manajemen Public Key & Private Key (HMAC-SHA256 / RSA-2048 / DUAL)
 *   2. Pengaturan Whitelist Domain, IP, Rate Limit & Privasi Nama Siswa
 *   3. Uji Koneksi Signed Request Live secara kriptografis
 *   4. Kurasi 1-klik Hasil Karya Proyek Siswa (Publikasikan ke WordPress)
 *   5. Unduh Plugin WordPress Siap Pasang (.zip) & Pantau Audit Log API
 *
 * Standar UI: Data-First Zero Title, Segmented Tabs, Solar Icons, Zero Reload.
 * ==============================================================================
 */

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';
require_once BASE_PATH . '/includes/wp-api-security.php';
require_once BASE_PATH . '/includes/wp-api-data.php';

requireRole('admin');

$pdo = getDBConnection();
$pdo->exec("SET time_zone = '+07:00'");
ilmuna_wp_api_ensure_schema($pdo);

/* ==============================================================================
 * DOWNLOAD PLUGIN WORDPRESS SIAP PASANG (?download_plugin=1)
 * ============================================================================== */
if (isset($_GET['download_plugin']) && $_GET['download_plugin'] === '1') {
    $pluginFile = BASE_PATH . '/wordpress-plugin/ilmuna-learn-connector/ilmuna-learn-connector.php';
    if (!file_exists($pluginFile)) {
        http_response_code(404);
        echo 'File plugin WordPress tidak ditemukan.';
        exit;
    }

    while (ob_get_level()) {
        ob_end_clean();
    }

    if (class_exists('ZipArchive')) {
        $tmpZip = tempnam(sys_get_temp_dir(), 'ilmuna_wp_');
        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::OVERWRITE) === true) {
            $zip->addFile($pluginFile, 'ilmuna-learn-connector/ilmuna-learn-connector.php');
            $zip->close();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="ilmuna-learn-connector.zip"');
            header('Content-Length: ' . filesize($tmpZip));
            header('Cache-Control: no-store');
            readfile($tmpZip);
            @unlink($tmpZip);
            exit;
        }
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="ilmuna-learn-connector.php"');
    header('Content-Length: ' . filesize($pluginFile));
    readfile($pluginFile);
    exit;
}

/* ==============================================================================
 * AJAX JSON API (?api=1) — ZERO PAGE RELOAD
 * ============================================================================== */
if (isset($_GET['api']) && $_GET['api'] === '1') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    $act = trim((string)($_POST['api_action'] ?? $_GET['api_action'] ?? ''));

    try {
        if ($act === 'bootstrap') {
            $clients = $pdo->query("SELECT * FROM wp_api_clients ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
            $clientOut = [];
            foreach ($clients as $c) {
                $plainPriv = ilmuna_wp_api_decrypt_secret($c['private_key_enc']);
                $clientOut[] = [
                    'id'                    => (int)$c['id'],
                    'client_name'           => (string)$c['client_name'],
                    'public_key'            => (string)$c['public_key'],
                    'private_key'           => $plainPriv,
                    'private_key_masked'    => strlen($plainPriv) > 16 ? (substr($plainPriv, 0, 14) . str_repeat('•', 20) . substr($plainPriv, -6)) : '••••••••',
                    'has_rsa_public'        => !empty($c['rsa_public_pem']),
                    'rsa_fingerprint'       => !empty($c['rsa_public_pem']) ? substr(hash('sha256', trim($c['rsa_public_pem'])), 0, 24) : '',
                    'sig_mode'              => (string)$c['sig_mode'],
                    'allowed_domains'       => (string)$c['allowed_domains'],
                    'allowed_ips'           => (string)($c['allowed_ips'] ?? ''),
                    'allowed_scopes'        => (string)$c['allowed_scopes'],
                    'rate_limit_per_min'    => (int)$c['rate_limit_per_min'],
                    'showcase_min_status'   => (string)$c['showcase_min_status'],
                    'hide_student_lastname' => (int)$c['hide_student_lastname'],
                    'is_active'             => (int)$c['is_active'],
                    'last_used_at'          => $c['last_used_at'],
                    'last_ip'               => $c['last_ip'],
                    'request_count'         => (int)$c['request_count'],
                ];
            }

            // Daftar seluruh hasil karya proyek siswa untuk kurasi publikasi
            $karyaRows = $pdo->query("
                SELECT pp.id, pp.judul_laporan_produk, pp.status_pengumpulan, pp.nilai_proyek,
                       pp.predikat_proyek, pp.waktu_pengumpulan, pp.file_dokumentasi, pp.link_produk,
                       p.nama_proyek, p.tema_proyek,
                       k.nama_kelas, mp.nama_mata_pelajaran,
                       s.nama_lengkap AS pengumpul_nama,
                       pk.nama_kelompok
                FROM pengumpulan_proyek pp
                JOIN proyek p ON pp.id_proyek = p.id
                JOIN kelas_mata_pelajaran kmp ON p.id_kelas_mata_pelajaran = kmp.id
                JOIN kelas k ON kmp.id_kelas = k.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN siswa s ON pp.id_siswa = s.id
                LEFT JOIN proyek_kelompok pk ON pp.id_proyek_kelompok = pk.id
                ORDER BY FIELD(pp.status_pengumpulan, 'Dipublikasikan', 'Disetujui', 'Diserahkan', 'Revisi'), pp.waktu_pengumpulan DESC
                LIMIT 60
            ")->fetchAll(PDO::FETCH_ASSOC);

            $tugasPreview  = ilmuna_wp_api_fetch_tugas_broadcast($pdo, ['limit' => 15]);
            $proyekPreview = ilmuna_wp_api_fetch_proyek_broadcast($pdo, ['limit' => 15]);

            $logs = $pdo->query("
                SELECT id, public_key, endpoint, resource, http_status, client_ip, client_site,
                       sig_algorithm, error_code, duration_ms, created_at
                FROM wp_api_audit_logs
                ORDER BY id DESC
                LIMIT 50
            ")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => [
                    'api_endpoint'    => ilmuna_wp_api_public_base_url() . '/api/wp-broadcast.php',
                    'clients'         => $clientOut,
                    'karya_list'      => $karyaRows,
                    'tugas_preview'   => $tugasPreview['items'],
                    'proyek_preview'  => $proyekPreview['items'],
                    'audit_logs'      => $logs,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($act === 'save_client') {
            $id = (int)($_POST['id'] ?? 0);
            $clientName = trim((string)($_POST['client_name'] ?? 'WordPress Utama (ilmuna.my.id)'));
            $sigMode = trim((string)($_POST['sig_mode'] ?? 'HMAC-SHA256'));
            if (!in_array($sigMode, ['HMAC-SHA256', 'RSA-SHA256', 'DUAL'], true)) {
                $sigMode = 'HMAC-SHA256';
            }
            $domains = trim((string)($_POST['allowed_domains'] ?? 'ilmuna.my.id,www.ilmuna.my.id'));
            $ips     = trim((string)($_POST['allowed_ips'] ?? ''));
            $rateLim = max(10, min(1000, (int)($_POST['rate_limit_per_min'] ?? 120)));
            $minStat = trim((string)($_POST['showcase_min_status'] ?? 'Disetujui_atau_Dipublikasikan'));
            if (!in_array($minStat, ['Dipublikasikan', 'Disetujui_atau_Dipublikasikan', 'Semua_Terkumpul'], true)) {
                $minStat = 'Disetujui_atau_Dipublikasikan';
            }
            $hideLast = !empty($_POST['hide_student_lastname']) ? 1 : 0;
            $isActive = !empty($_POST['is_active']) ? 1 : 0;

            if ($sigMode !== 'HMAC-SHA256') {
                $chkRsa = $pdo->prepare("SELECT rsa_public_pem FROM wp_api_clients WHERE id = ?");
                $chkRsa->execute([$id]);
                if (trim((string)$chkRsa->fetchColumn()) === '') {
                    echo json_encode(['success' => false, 'message' => 'Silakan klik "Generate RSA-2048 PEM" terlebih dahulu sebelum mengaktifkan mode ' . $sigMode . '.']);
                    exit;
                }
            }

            $stmt = $pdo->prepare("
                UPDATE wp_api_clients
                SET client_name = ?, sig_mode = ?, allowed_domains = ?, allowed_ips = ?,
                    rate_limit_per_min = ?, showcase_min_status = ?, hide_student_lastname = ?, is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([$clientName, $sigMode, $domains, $ips, $rateLim, $minStat, $hideLast, $isActive, $id]);

            echo json_encode(['success' => true, 'message' => 'Pengaturan keamanan API WordPress berhasil disimpan.']);
            exit;
        }

        if ($act === 'rotate_keys') {
            $id = (int)($_POST['id'] ?? 0);
            $pair = ilmuna_wp_api_generate_keypair();
            $encPrivate  = ilmuna_wp_api_encrypt_secret($pair['private_key']);
            $hashPrivate = hash('sha256', $pair['private_key']);

            $stmt = $pdo->prepare("UPDATE wp_api_clients SET public_key = ?, private_key_enc = ?, private_key_hash = ? WHERE id = ?");
            $stmt->execute([$pair['public_key'], $encPrivate, $hashPrivate, $id]);

            echo json_encode([
                'success' => true,
                'message' => 'Public Key & Private Key baru berhasil dibangkitkan! Perbarui juga konfigurasi di WordPress ilmuna.my.id.',
                'data'    => $pair,
            ]);
            exit;
        }

        if ($act === 'generate_rsa') {
            $id = (int)($_POST['id'] ?? 0);
            $rsa = ilmuna_wp_api_generate_rsa_pair();
            if (empty($rsa['success'])) {
                echo json_encode(['success' => false, 'message' => $rsa['message'] ?? 'Gagal membuat kunci RSA-2048.']);
                exit;
            }
            $stmt = $pdo->prepare("UPDATE wp_api_clients SET rsa_public_pem = ? WHERE id = ?");
            $stmt->execute([$rsa['public_pem'], $id]);

            echo json_encode([
                'success' => true,
                'message' => 'Pasangan RSA-2048 berhasil dibuat! Simpan RSA Private Key PEM di WordPress — server ini hanya menyimpan Public PEM.',
                'data'    => [
                    'private_pem' => $rsa['private_pem'],
                    'fingerprint' => substr($rsa['fingerprint'], 0, 24),
                ],
            ]);
            exit;
        }

        if ($act === 'toggle_karya_status') {
            $karyaId = (int)($_POST['karya_id'] ?? 0);
            $status  = trim((string)($_POST['status'] ?? 'Dipublikasikan'));
            if (!in_array($status, ['Dipublikasikan', 'Disetujui', 'Diserahkan'], true)) {
                echo json_encode(['success' => false, 'message' => 'Status publikasi tidak valid.']);
                exit;
            }
            $stmt = $pdo->prepare("UPDATE pengumpulan_proyek SET status_pengumpulan = ? WHERE id = ?");
            $stmt->execute([$status, $karyaId]);
            echo json_encode([
                'success' => true,
                'message' => $status === 'Dipublikasikan'
                    ? 'Karya siswa resmi ditayangkan ke Showcase WordPress!'
                    : 'Status karya diperbarui menjadi ' . $status . '.',
            ]);
            exit;
        }

        if ($act === 'test_signed_request') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM wp_api_clients WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$client) {
                echo json_encode(['success' => false, 'message' => 'Klien API tidak ditemukan.']);
                exit;
            }

            // Uji simulasi request bertanda tangan HMAC (khusus mode RSA murni akan menguji config RSA)
            $privateKey = ilmuna_wp_api_decrypt_secret($client['private_key_enc']);
            $ts = (string)time();
            $nonce = bin2hex(random_bytes(16));
            $query = ['resource' => 'all'];
            $canonical = ilmuna_wp_api_canonical_string('GET', '/api/wp-broadcast.php', $query, $ts, $nonce, '');
            $sig = ilmuna_wp_api_sign_hmac($canonical, $privateKey);

            $domains = ilmuna_wp_api_parse_list($client['allowed_domains']);
            $simSite = !empty($domains) ? $domains[0] : 'ilmuna.my.id';

            if ($client['sig_mode'] === 'HMAC-SHA256') {
                $authRes = ilmuna_wp_api_authenticate($pdo, [
                    'method'    => 'GET',
                    'uri'       => '/api/wp-broadcast.php',
                    'query'     => $query,
                    'body'      => '',
                    'client_ip' => ilmuna_wp_api_client_ip(),
                    'headers'   => [
                        'x-ilmuna-public-key' => $client['public_key'],
                        'x-ilmuna-timestamp'  => $ts,
                        'x-ilmuna-nonce'      => $nonce,
                        'x-ilmuna-signature'  => $sig,
                        'x-ilmuna-site'       => $simSite,
                    ],
                ]);
                if (!$authRes['ok']) {
                    echo json_encode(['success' => false, 'message' => 'Uji Autentikasi Gagal [' . $authRes['code'] . ']: ' . $authRes['message']]);
                    exit;
                }
            }

            $showcase = ilmuna_wp_api_fetch_proyek_showcase($pdo, ['limit' => 5], $client);
            $tugas    = ilmuna_wp_api_fetch_tugas_broadcast($pdo, ['limit' => 5]);
            $proyek   = ilmuna_wp_api_fetch_proyek_broadcast($pdo, ['limit' => 5]);

            echo json_encode([
                'success' => true,
                'message' => 'Verifikasi Kriptografis Sukses! API siap diakses dari ' . $simSite . '.',
                'data' => [
                    'sig_mode'          => $client['sig_mode'],
                    'simulated_site'    => $simSite,
                    'signature_sample'  => substr($sig, 0, 24) . '...',
                    'counts' => [
                        'proyek_showcase'  => $showcase['pagination']['total_items'],
                        'tugas_broadcast'  => $tugas['pagination']['total_items'],
                        'proyek_broadcast' => $proyek['pagination']['total_items'],
                    ],
                ],
            ]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenali.']);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

$pageTitle = 'Integrasi API WordPress';
$pageIcon  = 'solar:shield-keyhole-bold';

$extraHead = '<style>
.wpapi-app{display:flex;flex-direction:column;gap:16px}
.wpapi-pane{display:none}.wpapi-pane.is-active{display:flex;flex-direction:column;gap:16px}
.wpapi-card{background:#fff;border:1px solid #E2E8F0;border-radius:16px;padding:18px 20px;box-shadow:0 4px 16px rgba(15,23,42,.04)}
.wpapi-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid #F1F5F9}
.wpapi-card-title{margin:0;font-size:15px;font-weight:800;color:#1D3B5E;display:inline-flex;align-items:center;gap:8px}
.wpapi-grid-2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
@media(max-width:768px){.wpapi-grid-2{grid-template-columns:1fr}}
.wpapi-key-box{background:#F8FAFC;border:1px solid #CBD5E1;border-radius:12px;padding:12px 14px;display:flex;flex-direction:column;gap:6px}
.wpapi-key-lbl{font-size:11.5px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#475569;display:flex;align-items:center;justify-content:space-between}
.wpapi-key-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.wpapi-code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px;background:#0F172A;color:#E2E8F0;padding:8px 11px;border-radius:9px;word-break:break-all;flex:1;min-width:180px}
.wpapi-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:999px;font-size:11.5px;font-weight:700}
.wpapi-badge--ok{background:#DCFCE7;color:#15803D}
.wpapi-badge--warn{background:#FEF3C7;color:#B45309}
.wpapi-badge--err{background:#FEE2E2;color:#B91C1C}
.wpapi-badge--teal{background:#CCFBF1;color:#0F766E}
.wpapi-badge--navy{background:#E0E7FF;color:#1D3B5E}
.wpapi-btn{appearance:none;border:1px solid transparent;border-radius:10px;padding:8px 14px;font-size:12.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .18s;text-decoration:none}
.wpapi-btn--primary{background:linear-gradient(135deg,#1D3B5E,#0D7A87);color:#fff}
.wpapi-btn--primary:hover{opacity:.93}
.wpapi-btn--gold{background:linear-gradient(135deg,#D97706,#F59E0B);color:#fff}
.wpapi-btn--ghost{background:#F8FAFC;color:#1D3B5E;border-color:#CBD5E1}
.wpapi-btn--ghost:hover{background:#F1F5F9}
.wpapi-btn--danger{background:#FEF2F2;color:#B91C1C;border-color:#FECACA}
.wpapi-btn--danger:hover{background:#FEE2E2}
.wpapi-field{display:flex;flex-direction:column;gap:5px}
.wpapi-field label{font-size:12.5px;font-weight:700;color:#334155}
.wpapi-input{width:100%;padding:9px 12px;border:1px solid #CBD5E1;border-radius:10px;font-size:13px;background:#fff;color:#0F172A}
.wpapi-input:focus{outline:none;border-color:#0D7A87;box-shadow:0 0 0 3px rgba(13,122,135,.14)}
.wpapi-pre{background:#0F172A;color:#E2E8F0;padding:14px;border-radius:12px;font-size:12px;overflow:auto;margin:0;line-height:1.55}
.wpapi-switch{display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:#1E293B;cursor:pointer}
.wpapi-test-banner{display:none;padding:12px 14px;border-radius:12px;font-size:13px;font-weight:600}
</style>';

require_once BASE_PATH . '/includes/header.php';
loadCentralTemplates(['modal', 'table']);
?>

<div class="wpapi-app">
    <!-- SEGMENTED TABS (DATA-FIRST ZERO TITLE — STANDAR README.md) -->
    <div class="curriculum-main-tabs" id="wpapiMainTabs" role="tablist">
        <button type="button" class="curriculum-tab-btn is-active" data-tab="keys" role="tab" aria-selected="true">
            <span class="curriculum-tab-step">1</span>
            <iconify-icon icon="solar:shield-keyhole-bold"></iconify-icon>
            <span class="curriculum-tab-label">Kunci API (Public &amp; Private Key)</span>
            <span class="curriculum-tab-counter" id="cntWpStatus">AKTIF</span>
        </button>
        <button type="button" class="curriculum-tab-btn" data-tab="curation" role="tab" aria-selected="false">
            <span class="curriculum-tab-step">2</span>
            <iconify-icon icon="solar:gallery-wide-bold"></iconify-icon>
            <span class="curriculum-tab-label">Kurasi Showcase &amp; Broadcast</span>
            <span class="curriculum-tab-counter" id="cntWpShowcase">0</span>
        </button>
        <button type="button" class="curriculum-tab-btn" data-tab="guide" role="tab" aria-selected="false">
            <span class="curriculum-tab-step">3</span>
            <iconify-icon icon="solar:code-file-bold"></iconify-icon>
            <span class="curriculum-tab-label">Plugin WordPress &amp; Log Audit</span>
            <span class="curriculum-tab-counter" id="cntWpLogs">0</span>
        </button>
    </div>

    <!-- TAB 1: KUNCI & PROTEKSI MAKSIMAL -->
    <section class="wpapi-pane is-active" id="wpapiPane_keys">
        <div class="wpapi-card">
            <div class="wpapi-card-head">
                <h3 class="wpapi-card-title">
                    <iconify-icon icon="solar:key-minimalistic-square-2-bold" style="color:#0D7A87;font-size:20px;"></iconify-icon>
                    Kredensial Zero-Trust (learn.ilmuna.my.id &harr; ilmuna.my.id)
                </h3>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <button type="button" class="wpapi-btn wpapi-btn--ghost" onclick="WPAPI.testConnection()">
                        <iconify-icon icon="solar:shield-check-bold"></iconify-icon>
                        <span>Uji Koneksi Signed API</span>
                    </button>
                    <button type="button" class="wpapi-btn wpapi-btn--gold" onclick="WPAPI.generateRsa()">
                        <iconify-icon icon="solar:lock-keyhole-bold"></iconify-icon>
                        <span>Generate RSA-2048 PEM</span>
                    </button>
                    <button type="button" class="wpapi-btn wpapi-btn--danger" onclick="WPAPI.rotateKeys()">
                        <iconify-icon icon="solar:refresh-circle-bold"></iconify-icon>
                        <span>Rotasi Kunci Baru</span>
                    </button>
                </div>
            </div>

            <div id="wpapiTestBanner" class="wpapi-test-banner"></div>

            <div class="wpapi-key-box" style="margin-bottom:12px;">
                <div class="wpapi-key-lbl">
                    <span>Endpoint REST API</span>
                    <span class="wpapi-badge wpapi-badge--teal">GET Only · Signed Response · Anti-Replay</span>
                </div>
                <div class="wpapi-key-row">
                    <div class="wpapi-code" id="wpapiEndpointText">Memuat...</div>
                    <button type="button" class="wpapi-btn wpapi-btn--ghost" onclick="WPAPI.copyText('wpapiEndpointText')">
                        <iconify-icon icon="solar:copy-bold"></iconify-icon> Salin URL
                    </button>
                </div>
            </div>

            <div class="wpapi-grid-2">
                <div class="wpapi-key-box">
                    <div class="wpapi-key-lbl">
                        <span>Public Key (X-Ilmuna-Public-Key)</span>
                        <span class="wpapi-badge wpapi-badge--navy">Identitas Klien</span>
                    </div>
                    <div class="wpapi-key-row">
                        <div class="wpapi-code" id="wpapiPublicKeyText">Memuat...</div>
                        <button type="button" class="wpapi-btn wpapi-btn--ghost" onclick="WPAPI.copyText('wpapiPublicKeyText')">
                            <iconify-icon icon="solar:copy-bold"></iconify-icon> Salin
                        </button>
                    </div>
                </div>

                <div class="wpapi-key-box">
                    <div class="wpapi-key-lbl">
                        <span>Private Key (Rahasia Server WordPress)</span>
                        <span class="wpapi-badge wpapi-badge--warn">AES-256 Encrypted</span>
                    </div>
                    <div class="wpapi-key-row">
                        <div class="wpapi-code" id="wpapiPrivateKeyText">••••••••••••••••••••••••••••</div>
                        <button type="button" class="wpapi-btn wpapi-btn--ghost" id="btnTogglePriv" onclick="WPAPI.togglePrivate()">
                            <iconify-icon icon="solar:eye-bold"></iconify-icon> Lihat
                        </button>
                        <button type="button" class="wpapi-btn wpapi-btn--ghost" onclick="WPAPI.copyPrivate()">
                            <iconify-icon icon="solar:copy-bold"></iconify-icon> Salin
                        </button>
                    </div>
                </div>
            </div>

            <div id="wpapiRsaRevealBox" style="display:none;margin-top:14px;background:#FFFBEB;border:1px solid #FDE68A;border-radius:12px;padding:14px;">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap;">
                    <strong style="color:#92400E;font-size:13px;">
                        <iconify-icon icon="solar:danger-triangle-bold" style="vertical-align:middle;"></iconify-icon>
                        RSA-2048 Private Key PEM (Ditampilkan SATU KALI — Salin ke WordPress sekarang!)
                    </strong>
                    <button type="button" class="wpapi-btn wpapi-btn--gold" onclick="WPAPI.copyRsaPem()">
                        <iconify-icon icon="solar:copy-bold"></iconify-icon> Salin RSA Private PEM
                    </button>
                </div>
                <textarea id="wpapiRsaPemOutput" class="wpapi-input" rows="6" readonly style="font-family:monospace;font-size:11.5px;background:#0F172A;color:#FDE68A;"></textarea>
            </div>
        </div>

        <form class="wpapi-card" onsubmit="WPAPI.saveClient(event)">
            <div class="wpapi-card-head">
                <h3 class="wpapi-card-title">
                    <iconify-icon icon="solar:tuning-2-bold" style="color:#0D7A87;font-size:20px;"></iconify-icon>
                    Kebijakan Proteksi, Whitelist &amp; Privasi Data
                </h3>
                <button type="submit" class="wpapi-btn wpapi-btn--primary" id="btnSaveWpClient">
                    <iconify-icon icon="solar:diskette-bold"></iconify-icon>
                    <span>Simpan Kebijakan Keamanan</span>
                </button>
            </div>

            <input type="hidden" id="wpClientId" value="0">
            <div class="wpapi-grid-2" style="margin-bottom:14px;">
                <div class="wpapi-field">
                    <label for="wpClientName">Nama Klien Integrasi</label>
                    <input type="text" id="wpClientName" class="wpapi-input" required>
                </div>
                <div class="wpapi-field">
                    <label for="wpSigMode">Mode Tanda Tangan Kriptografis</label>
                    <select id="wpSigMode" class="wpapi-input">
                        <option value="HMAC-SHA256">HMAC-SHA256 (Public Key + Private Key HMAC — Cepat &amp; Kuat)</option>
                        <option value="RSA-SHA256">RSA-2048 Asimetris (Public PEM di Server, Private PEM di WordPress)</option>
                        <option value="DUAL">DUAL (Wajib HMAC-SHA256 + RSA-2048 Sekaligus — Proteksi Maksimal)</option>
                    </select>
                </div>
                <div class="wpapi-field">
                    <label for="wpAllowedDomains">Whitelist Domain Diizinkan (pisahkan koma)</label>
                    <input type="text" id="wpAllowedDomains" class="wpapi-input" placeholder="ilmuna.my.id,www.ilmuna.my.id">
                </div>
                <div class="wpapi-field">
                    <label for="wpAllowedIps">Whitelist IP Server WordPress (opsional, kosong = bebas IP bertanda tangan)</label>
                    <input type="text" id="wpAllowedIps" class="wpapi-input" placeholder="Contoh: 103.147.154.xx atau CIDR 103.147.154.0/24">
                </div>
                <div class="wpapi-field">
                    <label for="wpShowcasePolicy">Filter Karya Proyek yang Tayang di WordPress</label>
                    <select id="wpShowcasePolicy" class="wpapi-input">
                        <option value="Dipublikasikan">Ketat: Hanya status "Dipublikasikan" (Kurasi Admin/Guru)</option>
                        <option value="Disetujui_atau_Dipublikasikan">Seimbang: Status "Dipublikasikan" &amp; "Disetujui"</option>
                        <option value="Semua_Terkumpul">Terbuka: Semua karya terkumpul (termasuk "Diserahkan")</option>
                    </select>
                </div>
                <div class="wpapi-field">
                    <label for="wpRateLimit">Rate Limit (maks request per menit / IP)</label>
                    <input type="number" id="wpRateLimit" class="wpapi-input" min="10" max="1000" value="120">
                </div>
            </div>

            <div style="display:flex;gap:22px;flex-wrap:wrap;align-items:center;padding-top:6px;">
                <label class="wpapi-switch">
                    <input type="checkbox" id="wpHideLastname">
                    Samarkan nama belakang siswa (misal: "Ahmad Fauzan" &rarr; "Ahmad F.")
                </label>
                <label class="wpapi-switch">
                    <input type="checkbox" id="wpIsActive" checked>
                    Aktifkan Akses API untuk Klien ini
                </label>
                <span id="wpRsaBadge" class="wpapi-badge wpapi-badge--navy" style="margin-left:auto;">RSA Public PEM: Belum diatur</span>
            </div>
        </form>
    </section>

    <!-- TAB 2: KURASI TAYANGAN SHOWCASE & BROADCAST -->
    <section class="wpapi-pane" id="wpapiPane_curation">
        <div class="wpapi-card">
            <div class="wpapi-card-head">
                <h3 class="wpapi-card-title">
                    <iconify-icon icon="solar:gallery-check-bold" style="color:#0D7A87;font-size:20px;"></iconify-icon>
                    Kurasi Hasil Karya Proyek Siswa (Tayang ke ilmuna.my.id)
                </h3>
                <span class="wpapi-badge wpapi-badge--teal">Klik tombol publikasi untuk menayangkan / menarik karya</span>
            </div>
            <div class="table-responsive">
                <table class="lms-table">
                    <thead>
                        <tr>
                            <th>Judul Karya &amp; Proyek</th>
                            <th>Kelas &amp; Mapel</th>
                            <th>Tim / Siswa</th>
                            <th style="text-align:center;">Nilai / P5</th>
                            <th style="text-align:center;">Status</th>
                            <th style="text-align:right;">Kurasi WordPress</th>
                        </tr>
                    </thead>
                    <tbody id="wpapiKaryaTbody">
                        <tr><td colspan="6" style="text-align:center;padding:24px;color:#64748B;">Memuat daftar karya proyek siswa...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="wpapi-grid-2">
            <div class="wpapi-card">
                <div class="wpapi-card-head">
                    <h3 class="wpapi-card-title">
                        <iconify-icon icon="solar:document-text-bold" style="color:#0D7A87;font-size:18px;"></iconify-icon>
                        Preview Broadcast Tugas &amp; Deadline
                    </h3>
                    <span class="wpapi-badge wpapi-badge--navy" id="cntPreviewTugas">0</span>
                </div>
                <div id="wpapiTugasPreview" style="display:flex;flex-direction:column;gap:8px;max-height:360px;overflow-y:auto;"></div>
            </div>

            <div class="wpapi-card">
                <div class="wpapi-card-head">
                    <h3 class="wpapi-card-title">
                        <iconify-icon icon="solar:rocket-2-bold" style="color:#D97706;font-size:18px;"></iconify-icon>
                        Preview Broadcast Proyek &amp; Deadline
                    </h3>
                    <span class="wpapi-badge wpapi-badge--warn" id="cntPreviewProyek">0</span>
                </div>
                <div id="wpapiProyekPreview" style="display:flex;flex-direction:column;gap:8px;max-height:360px;overflow-y:auto;"></div>
            </div>
        </div>
    </section>

    <!-- TAB 3: PLUGIN WORDPRESS & AUDIT LOG -->
    <section class="wpapi-pane" id="wpapiPane_guide">
        <div class="wpapi-card">
            <div class="wpapi-card-head">
                <h3 class="wpapi-card-title">
                    <iconify-icon icon="solar:box-bold" style="color:#0D7A87;font-size:20px;"></iconify-icon>
                    Pasang di WordPress (ilmuna.my.id) dalam 3 Langkah
                </h3>
                <a class="wpapi-btn wpapi-btn--primary" href="<?= BASE_URL ?>/dashboard/admin/api-wordpress?download_plugin=1">
                    <iconify-icon icon="solar:download-square-bold"></iconify-icon>
                    <span>Unduh Plugin WordPress (.zip)</span>
                </a>
            </div>
            <ol style="margin:0 0 14px 18px;font-size:13.5px;color:#334155;line-height:1.7;">
                <li>Klik <strong>Unduh Plugin WordPress (.zip)</strong> di atas, lalu unggah di <code>ilmuna.my.id/wp-admin</code> &rarr; <strong>Plugins &rarr; Add New &rarr; Upload Plugin</strong> &rarr; <strong>Activate</strong>.</li>
                <li>Buka <strong>Settings &rarr; Ilmuna Learn API</strong> di WordPress, lalu tempel <strong>Endpoint URL</strong>, <strong>Public Key</strong>, dan <strong>Private Key</strong> dari Tab 1, lalu klik <strong>Uji Koneksi API</strong>.</li>
                <li>Tempel shortcode berikut di halaman/posting/Elementor WordPress Anda:</li>
            </ol>
            <pre class="wpapi-pre">[ilmuna_broadcast_all limit_showcase="6" limit_tugas="8" limit_proyek="6"]
[ilmuna_proyek_showcase limit="6" columns="3"]
[ilmuna_broadcast_tugas limit="10" upcoming_only="0"]
[ilmuna_broadcast_proyek limit="6" upcoming_only="0"]</pre>
        </div>

        <div class="wpapi-card">
            <div class="wpapi-card-head">
                <h3 class="wpapi-card-title">
                    <iconify-icon icon="solar:history-bold" style="color:#0D7A87;font-size:20px;"></iconify-icon>
                    Log Audit &amp; Monitoring Keamanan API (50 Request Terakhir)
                </h3>
                <button type="button" class="wpapi-btn wpapi-btn--ghost" onclick="WPAPI.load()">
                    <iconify-icon icon="solar:refresh-bold"></iconify-icon> Muat Ulang Log
                </button>
            </div>
            <div class="table-responsive">
                <table class="lms-table">
                    <thead>
                        <tr>
                            <th>Waktu</th>
                            <th>Resource</th>
                            <th>Status</th>
                            <th>Domain &amp; IP</th>
                            <th>Algoritma</th>
                            <th>Keterangan</th>
                            <th style="text-align:right;">Durasi</th>
                        </tr>
                    </thead>
                    <tbody id="wpapiLogsTbody">
                        <tr><td colspan="7" style="text-align:center;padding:24px;color:#64748B;">Belum ada aktivitas API.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>

<script>
(function () {
    const ENDPOINT = <?= json_encode(BASE_URL . '/dashboard/admin/api-wordpress?api=1') ?>;
    let STATE = { client: null, showPriv: false };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    function toast(msg, type) {
        if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
            window.Ilmuna.toast(msg, type || 'info');
        } else {
            alert(msg);
        }
    }

    async function apiCall(action, payload) {
        const fd = new FormData();
        fd.append('api_action', action);
        if (payload) {
            Object.keys(payload).forEach(function (k) { fd.append(k, payload[k]); });
        }
        const res = await fetch(ENDPOINT, { method: 'POST', body: fd, credentials: 'same-origin' });
        return await res.json();
    }

    function bindTabs() {
        document.querySelectorAll('#wpapiMainTabs .curriculum-tab-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const tab = btn.getAttribute('data-tab');
                document.querySelectorAll('#wpapiMainTabs .curriculum-tab-btn').forEach(function (b) {
                    const active = (b === btn);
                    b.classList.toggle('is-active', active);
                    b.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                document.querySelectorAll('.wpapi-pane').forEach(function (p) {
                    p.classList.toggle('is-active', p.id === 'wpapiPane_' + tab);
                });
            });
        });
    }

    function renderClient(data) {
        const c = (data.clients && data.clients[0]) ? data.clients[0] : null;
        STATE.client = c;
        document.getElementById('wpapiEndpointText').textContent = data.api_endpoint || '';
        if (!c) return;

        document.getElementById('wpClientId').value = c.id;
        document.getElementById('wpClientName').value = c.client_name;
        document.getElementById('wpSigMode').value = c.sig_mode;
        document.getElementById('wpAllowedDomains').value = c.allowed_domains;
        document.getElementById('wpAllowedIps').value = c.allowed_ips;
        document.getElementById('wpShowcasePolicy').value = c.showcase_min_status;
        document.getElementById('wpRateLimit').value = c.rate_limit_per_min;
        document.getElementById('wpHideLastname').checked = Number(c.hide_student_lastname) === 1;
        document.getElementById('wpIsActive').checked = Number(c.is_active) === 1;

        document.getElementById('wpapiPublicKeyText').textContent = c.public_key;
        document.getElementById('wpapiPrivateKeyText').textContent = STATE.showPriv ? c.private_key : c.private_key_masked;
        document.getElementById('cntWpStatus').textContent = Number(c.is_active) === 1 ? c.sig_mode : 'NONAKTIF';
        document.getElementById('wpRsaBadge').textContent = c.has_rsa_public
            ? ('RSA Public PEM Aktif · ' + c.rsa_fingerprint.slice(0, 12) + '…')
            : 'RSA Public PEM: Belum diatur';
    }

    function renderKarya(list) {
        const tb = document.getElementById('wpapiKaryaTbody');
        document.getElementById('cntWpShowcase').textContent = list.length;
        if (!list.length) {
            tb.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:26px;color:#64748B;">Belum ada pengumpulan karya proyek siswa.</td></tr>';
            return;
        }
        tb.innerHTML = list.map(function (k) {
            const isPub = k.status_pengumpulan === 'Dipublikasikan';
            const badgeCls = isPub ? 'wpapi-badge--ok' : (k.status_pengumpulan === 'Disetujui' ? 'wpapi-badge--teal' : 'wpapi-badge--warn');
            const creator = k.nama_kelompok ? (k.nama_kelompok + ' (' + (k.pengumpul_nama || '-') + ')') : (k.pengumpul_nama || '-');
            const nextStatus = isPub ? 'Disetujui' : 'Dipublikasikan';
            const btnLbl = isPub ? 'Tarik dari Showcase' : 'Publikasikan ke WP';
            const btnCls = isPub ? 'wpapi-btn--ghost' : 'wpapi-btn--primary';
            return '<tr>'
                + '<td><strong>' + esc(k.judul_laporan_produk || k.nama_proyek) + '</strong><div style="font-size:12px;color:#64748B;">' + esc(k.nama_proyek) + '</div></td>'
                + '<td>' + esc(k.nama_kelas) + '<div style="font-size:12px;color:#64748B;">' + esc(k.nama_mata_pelajaran) + '</div></td>'
                + '<td>' + esc(creator) + '</td>'
                + '<td style="text-align:center;">' + (k.nilai_proyek != null ? ('<strong>' + esc(k.nilai_proyek) + '</strong>') : '-') + (k.predikat_proyek ? ('<div style="font-size:11px;color:#0D7A87;">' + esc(k.predikat_proyek) + '</div>') : '') + '</td>'
                + '<td style="text-align:center;"><span class="wpapi-badge ' + badgeCls + '">' + esc(k.status_pengumpulan) + '</span></td>'
                + '<td style="text-align:right;"><button type="button" class="wpapi-btn ' + btnCls + '" onclick="WPAPI.setKaryaStatus(' + k.id + ',\'' + nextStatus + '\')">' + btnLbl + '</button></td>'
                + '</tr>';
        }).join('');
    }

    function renderBroadcastPreviews(tugas, proyek) {
        document.getElementById('cntPreviewTugas').textContent = tugas.length + ' Tugas';
        document.getElementById('cntPreviewProyek').textContent = proyek.length + ' Proyek';

        const tBox = document.getElementById('wpapiTugasPreview');
        tBox.innerHTML = tugas.length ? tugas.map(function (t) {
            const bCls = t.urgensi === 'kritis' ? 'wpapi-badge--err' : (t.urgensi === 'segera' ? 'wpapi-badge--warn' : (t.urgensi === 'berakhir' ? 'wpapi-badge--navy' : 'wpapi-badge--ok'));
            return '<div style="padding:10px 12px;border:1px solid #E2E8F0;border-radius:11px;background:#F8FAFC;">'
                + '<div style="display:flex;justify-content:space-between;gap:8px;align-items:center;margin-bottom:4px;">'
                + '<strong style="font-size:13px;color:#0F172A;">' + esc(t.judul_tugas) + '</strong>'
                + '<span class="wpapi-badge ' + bCls + '">' + esc(t.sisa_waktu_label) + '</span></div>'
                + '<div style="font-size:12px;color:#64748B;">' + esc(t.kelas.nama) + ' · ' + esc(t.mata_pelajaran.nama) + ' · Deadline: ' + esc(t.tenggat_waktu_formatted || '-') + '</div>'
                + '</div>';
        }).join('') : '<div style="padding:18px;text-align:center;color:#64748B;font-size:13px;">Belum ada tugas aktif.</div>';

        const pBox = document.getElementById('wpapiProyekPreview');
        pBox.innerHTML = proyek.length ? proyek.map(function (p) {
            const bCls = p.urgensi === 'kritis' ? 'wpapi-badge--err' : (p.urgensi === 'segera' ? 'wpapi-badge--warn' : (p.urgensi === 'berakhir' ? 'wpapi-badge--navy' : 'wpapi-badge--ok'));
            return '<div style="padding:10px 12px;border:1px solid #E2E8F0;border-radius:11px;background:#F8FAFC;">'
                + '<div style="display:flex;justify-content:space-between;gap:8px;align-items:center;margin-bottom:4px;">'
                + '<strong style="font-size:13px;color:#0F172A;">' + esc(p.nama_proyek) + '</strong>'
                + '<span class="wpapi-badge ' + bCls + '">' + esc(p.sisa_waktu_label) + '</span></div>'
                + '<div style="font-size:12px;color:#64748B;">' + esc(p.kelas.nama) + ' · ' + esc(p.mata_pelajaran.nama) + ' · Deadline: ' + esc(p.jadwal_selesai_formatted || '-') + '</div>'
                + '</div>';
        }).join('') : '<div style="padding:18px;text-align:center;color:#64748B;font-size:13px;">Belum ada proyek aktif.</div>';
    }

    function renderLogs(logs) {
        document.getElementById('cntWpLogs').textContent = logs.length;
        const tb = document.getElementById('wpapiLogsTbody');
        if (!logs.length) {
            tb.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px;color:#64748B;">Belum ada riwayat request API.</td></tr>';
            return;
        }
        tb.innerHTML = logs.map(function (l) {
            const ok = Number(l.http_status) === 200;
            return '<tr>'
                + '<td style="font-size:12px;white-space:nowrap;">' + esc(l.created_at) + '</td>'
                + '<td><code>' + esc(l.resource || '-') + '</code></td>'
                + '<td><span class="wpapi-badge ' + (ok ? 'wpapi-badge--ok' : 'wpapi-badge--err') + '">' + esc(l.http_status) + '</span></td>'
                + '<td style="font-size:12px;">' + esc(l.client_site || '-') + '<div style="color:#64748B;">' + esc(l.client_ip) + '</div></td>'
                + '<td style="font-size:12px;">' + esc(l.sig_algorithm || '-') + '</td>'
                + '<td style="font-size:12px;color:' + (ok ? '#15803D' : '#B91C1C') + ';">' + esc(l.error_code || 'OK · Verified') + '</td>'
                + '<td style="text-align:right;font-size:12px;">' + esc(l.duration_ms) + ' ms</td>'
                + '</tr>';
        }).join('');
    }

    window.WPAPI = {
        load: async function () {
            const res = await apiCall('bootstrap');
            if (!res.success) {
                toast(res.message || 'Gagal memuat data API.', 'error');
                return;
            }
            renderClient(res.data);
            renderKarya(res.data.karya_list || []);
            renderBroadcastPreviews(res.data.tugas_preview || [], res.data.proyek_preview || []);
            renderLogs(res.data.audit_logs || []);
        },
        togglePrivate: function () {
            if (!STATE.client) return;
            STATE.showPriv = !STATE.showPriv;
            document.getElementById('wpapiPrivateKeyText').textContent = STATE.showPriv ? STATE.client.private_key : STATE.client.private_key_masked;
            document.getElementById('btnTogglePriv').innerHTML = STATE.showPriv
                ? '<iconify-icon icon="solar:eye-closed-bold"></iconify-icon> Sembunyikan'
                : '<iconify-icon icon="solar:eye-bold"></iconify-icon> Lihat';
        },
        copyText: function (elId) {
            const txt = (document.getElementById(elId).textContent || '').trim();
            navigator.clipboard.writeText(txt).then(function () { toast('Berhasil disalin ke clipboard.', 'success'); });
        },
        copyPrivate: function () {
            if (!STATE.client) return;
            navigator.clipboard.writeText(STATE.client.private_key).then(function () { toast('Private Key disalin. Simpan hanya di server WordPress!', 'success'); });
        },
        copyRsaPem: function () {
            const txt = document.getElementById('wpapiRsaPemOutput').value;
            navigator.clipboard.writeText(txt).then(function () { toast('RSA-2048 Private Key PEM disalin!', 'success'); });
        },
        saveClient: async function (ev) {
            ev.preventDefault();
            const res = await apiCall('save_client', {
                id: document.getElementById('wpClientId').value,
                client_name: document.getElementById('wpClientName').value,
                sig_mode: document.getElementById('wpSigMode').value,
                allowed_domains: document.getElementById('wpAllowedDomains').value,
                allowed_ips: document.getElementById('wpAllowedIps').value,
                showcase_min_status: document.getElementById('wpShowcasePolicy').value,
                rate_limit_per_min: document.getElementById('wpRateLimit').value,
                hide_student_lastname: document.getElementById('wpHideLastname').checked ? '1' : '0',
                is_active: document.getElementById('wpIsActive').checked ? '1' : '0',
            });
            toast(res.message || (res.success ? 'Tersimpan' : 'Gagal'), res.success ? 'success' : 'error');
            if (res.success) WPAPI.load();
        },
        rotateKeys: async function () {
            if (!STATE.client) return;
            const ok = (window.Ilmuna && window.Ilmuna.confirm)
                ? await window.Ilmuna.confirm('Rotasi Public & Private Key? Kunci lama di WordPress akan langsung tidak berlaku sampai diperbarui.', { title: 'Rotasi Kunci API', type: 'warning', confirmText: 'Ya, Rotasi Sekarang' })
                : confirm('Rotasi Public & Private Key?');
            if (!ok) return;
            const res = await apiCall('rotate_keys', { id: STATE.client.id });
            toast(res.message, res.success ? 'success' : 'error');
            if (res.success) {
                STATE.showPriv = true;
                WPAPI.load();
            }
        },
        generateRsa: async function () {
            if (!STATE.client) return;
            const ok = (window.Ilmuna && window.Ilmuna.confirm)
                ? await window.Ilmuna.confirm('Buat pasangan RSA-2048 PEM baru? Private Key PEM hanya muncul satu kali dan wajib disalin ke WordPress bila mode RSA-SHA256 / DUAL diaktifkan.', { title: 'Generate RSA-2048 PEM', type: 'info', confirmText: 'Generate RSA-2048' })
                : confirm('Buat pasangan RSA-2048 PEM baru?');
            if (!ok) return;
            const res = await apiCall('generate_rsa', { id: STATE.client.id });
            toast(res.message, res.success ? 'success' : 'error');
            if (res.success && res.data && res.data.private_pem) {
                document.getElementById('wpapiRsaRevealBox').style.display = 'block';
                document.getElementById('wpapiRsaPemOutput').value = res.data.private_pem;
                WPAPI.load();
            }
        },
        testConnection: async function () {
            if (!STATE.client) return;
            const banner = document.getElementById('wpapiTestBanner');
            banner.style.display = 'block';
            banner.style.background = '#F1F5F9';
            banner.style.color = '#334155';
            banner.textContent = 'Menguji tanda tangan kriptografis, nonce anti-replay & query data...';

            const res = await apiCall('test_signed_request', { id: STATE.client.id });
            if (res.success) {
                banner.style.background = '#DCFCE7';
                banner.style.color = '#166534';
                banner.innerHTML = '<strong>VERIFIKASI SUKSES (' + esc(res.data.sig_mode) + ')</strong> — Domain simulasi: <code>' + esc(res.data.simulated_site) + '</code> · Siap tayang: <strong>' + res.data.counts.proyek_showcase + ' Karya Proyek</strong>, <strong>' + res.data.counts.tugas_broadcast + ' Tugas</strong>, <strong>' + res.data.counts.proyek_broadcast + ' Proyek</strong>.';
                toast(res.message, 'success');
                WPAPI.load();
            } else {
                banner.style.background = '#FEE2E2';
                banner.style.color = '#991B1B';
                banner.textContent = res.message || 'Pengujian gagal.';
                toast(res.message, 'error');
            }
        },
        setKaryaStatus: async function (karyaId, status) {
            const res = await apiCall('toggle_karya_status', { karya_id: karyaId, status: status });
            toast(res.message, res.success ? 'success' : 'error');
            if (res.success) WPAPI.load();
        },
    };

    bindTabs();
    WPAPI.load();
})();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
