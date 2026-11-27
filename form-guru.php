<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — FORMULIR PUBLIK GURU & TENAGA PENDIDIK (GAYA GOOGLE FORM + HASH)
 * ==============================================================================
 * URL: /form-guru?h=<token_hash>
 * - Kunci ketat 1x kirim: 1 Nama + 1 Email + 1 No. Identitas (NIP/NUPTK/NIK) hanya 1x.
 * - Ambil email otomatis seperti Google Form (Google Identity Services / Sesi)
 *   ATAU ketik manual langsung.
 * - Guru mengisi biodata + Multiple Choice Mata Pelajaran saja (kelas diatur Admin).
 * - Full-screen desktop responsive + Full animasi & transisi + window.Ilmuna.
 * ==============================================================================
 */

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/public-form-engine.php';

$pdo = getDBConnection();
$pdo->exec("SET time_zone = '+07:00'");

$hashParam   = trim((string)($_GET['h'] ?? $_POST['h'] ?? ''));
$formMeta    = ilmuna_validate_public_form_hash($pdo, 'guru', $hashParam);
$school      = ilmuna_get_school_brand($pdo);
$sessionUser = ilmuna_get_session_user_email($pdo);
$googleCid   = defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : '';

if (empty($_SESSION['pub_form_csrf_guru'])) {
    $_SESSION['pub_form_csrf_guru'] = bin2hex(random_bytes(16));
}
$csrfToken = $_SESSION['pub_form_csrf_guru'];

/* ==============================================================================
 * AJAX SUBMIT HANDLER (POST ?submit=1)
 * ============================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['submit']) && $_GET['submit'] === '1') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    try {
        if (!$formMeta) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Link formulir tidak valid atau sudah ditutup.']);
            exit;
        }
        if (!empty($_POST['website_hp'])) {
            echo json_encode(['success' => false, 'message' => 'Permintaan ditolak.']);
            exit;
        }
        $postedCsrf = (string)($_POST['csrf_token'] ?? '');
        if (!hash_equals($csrfToken, $postedCsrf)) {
            echo json_encode(['success' => false, 'message' => 'Sesi formulir kadaluarsa. Silakan muat ulang halaman.']);
            exit;
        }

        $namaGuru     = trim((string)($_POST['nama_guru'] ?? ''));
        $nip          = trim((string)($_POST['nip'] ?? ''));
        $nuptk        = trim((string)($_POST['nuptk'] ?? ''));
        $jk           = in_array($_POST['jenis_kelamin'] ?? '', ['L', 'P'], true) ? $_POST['jenis_kelamin'] : 'L';
        $tempatLahir  = trim((string)($_POST['tempat_lahir'] ?? ''));
        $tanggalLahir = trim((string)($_POST['tanggal_lahir'] ?? ''));
        $statusKepeg  = trim((string)($_POST['status_kepegawaian'] ?? 'Tetap Yayasan'));
        $noHp         = trim((string)($_POST['no_hp'] ?? ''));
        $email        = trim((string)($_POST['email'] ?? ''));
        $alamat       = trim((string)($_POST['alamat'] ?? ''));

        $mapelIdsRaw  = $_POST['mapel_ids'] ?? [];
        $mapelIds     = is_array($mapelIdsRaw) ? array_values(array_unique(array_filter(array_map('intval', $mapelIdsRaw)))) : [];

        $noIdentitas  = ($nip !== '') ? $nip : $nuptk;

        if ($namaGuru === '' || $email === '' || $noIdentitas === '') {
            echo json_encode(['success' => false, 'message' => 'Nama Lengkap, Email Aktif, dan No. Identitas (NIP/NIK atau NUPTK) wajib diisi.']);
            exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Format Email Guru tidak valid.']);
            exit;
        }
        if (empty($mapelIds)) {
            echo json_encode(['success' => false, 'message' => 'Pilih minimal satu Mata Pelajaran yang diampu.']);
            exit;
        }

        // KUNCI KETAT 1x KIRIM: 1 Nama + 1 Email + 1 No. Identitas
        $dupErr = ilmuna_check_public_form_duplicate($pdo, 'guru', $namaGuru, $email, $noIdentitas);
        if ($dupErr !== null) {
            echo json_encode(['success' => false, 'message' => $dupErr]);
            exit;
        }

        $tglVal = preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalLahir) ? $tanggalLahir : null;

        $pdo->beginTransaction();

        // Catat penguncian 1x kirim terlebih dahulu (anti race-condition via UNIQUE index)
        ilmuna_record_public_form_submission($pdo, 'guru', $namaGuru, $email, $noIdentitas);

        // 1. Cek duplikasi Guru berdasarkan NIP atau Email
        $existId = 0;
        if ($nip !== '') {
            $stC = $pdo->prepare("SELECT id FROM guru WHERE nip = ? LIMIT 1");
            $stC->execute([$nip]);
            $existId = (int)$stC->fetchColumn();
        }
        if ($existId <= 0 && $email !== '') {
            $stC2 = $pdo->prepare("SELECT id FROM guru WHERE LOWER(email) = LOWER(?) LIMIT 1");
            $stC2->execute([$email]);
            $existId = (int)$stC2->fetchColumn();
        }

        if ($existId > 0) {
            $pdo->prepare("
                UPDATE guru
                SET nama_guru = ?,
                    nip = COALESCE(NULLIF(?, ''), nip),
                    nuptk = COALESCE(NULLIF(?, ''), nuptk),
                    jenis_kelamin = ?,
                    tempat_lahir = COALESCE(NULLIF(?, ''), tempat_lahir),
                    tanggal_lahir = COALESCE(?, tanggal_lahir),
                    status_kepegawaian = ?,
                    no_hp = COALESCE(NULLIF(?, ''), no_hp),
                    email = COALESCE(NULLIF(?, ''), email),
                    alamat = COALESCE(NULLIF(?, ''), alamat)
                WHERE id = ?
            ")->execute([$namaGuru, $nip, $nuptk, $jk, $tempatLahir, $tglVal, $statusKepeg, $noHp, $email, $alamat, $existId]);
            $guruId = $existId;
        } else {
            $kodeGuru = 'GRU-' . random_int(100000, 999999);
            $pdo->prepare("
                INSERT INTO guru
                    (kode_guru, nama_guru, nip, nuptk, jenis_kelamin, tempat_lahir, tanggal_lahir, status_kepegawaian, no_hp, email, alamat)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $kodeGuru, $namaGuru, $nip ?: null, $nuptk ?: null, $jk,
                $tempatLahir ?: null, $tglVal, $statusKepeg,
                $noHp ?: null, $email ?: null, $alamat ?: null
            ]);
            $guruId = (int)$pdo->lastInsertId();
        }

        // 2. Relasi guru_mata_pelajaran (kelas diatur di panel Admin)
        $stMapelKode = $pdo->prepare("SELECT kode_mapel FROM mata_pelajaran WHERE id = ? LIMIT 1");
        $stChkGmp    = $pdo->prepare("SELECT id FROM guru_mata_pelajaran WHERE id_guru = ? AND id_mata_pelajaran = ? LIMIT 1");
        $stInsGmp    = $pdo->prepare("INSERT INTO guru_mata_pelajaran (id_guru, id_mata_pelajaran, kode_relasi) VALUES (?, ?, ?)");

        $linkedMapel = 0;
        foreach ($mapelIds as $mid) {
            $stMapelKode->execute([$mid]);
            $km = $stMapelKode->fetchColumn();
            if ($km === false) continue;

            $stChkGmp->execute([$guruId, $mid]);
            if (!$stChkGmp->fetchColumn()) {
                $prefix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', explode('-', (string)$km)[0] ?: 'GMP'));
                if ($prefix === '') $prefix = 'GMP';
                $kodeRel = substr($prefix, 0, 5) . '-' . $guruId . random_int(10, 99);
                $stInsGmp->execute([$guruId, $mid, $kodeRel]);
            }
            $linkedMapel++;
        }

        $pdo->prepare("UPDATE public_form_links SET submission_count = submission_count + 1 WHERE id = ?")
            ->execute([(int)$formMeta['id']]);

        $pdo->commit();
        echo json_encode([
            'success' => true,
            'message' => 'Terima kasih! Data guru ' . $namaGuru . ' tersimpan bersama ' . $linkedMapel . ' mata pelajaran yang diampu.'
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            echo json_encode(['success' => false, 'message' => 'Data Nama, Email, atau No. Identitas ini sudah tercatat pernah mengirim formulir sebelumnya (maksimal 1 kali kirim).']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Gagal menyimpan data: ' . $e->getMessage()]);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan data: ' . $e->getMessage()]);
    }
    exit;
}

$mapelList = $formMeta
    ? $pdo->query("SELECT id, nama_mata_pelajaran, kode_mapel, semester FROM mata_pelajaran ORDER BY nama_mata_pelajaran ASC")->fetchAll(PDO::FETCH_ASSOC)
    : [];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($formMeta ? $formMeta['title'] : 'Formulir Tidak Tersedia', ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($school['nama_sekolah'], ENT_QUOTES, 'UTF-8') ?></title>
    <?= function_exists('renderFaviconTags') ? renderFaviconTags() : '<link rel="icon" href="' . BASE_URL . '/assets/images/logo.ico">' ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/public-forms.css?v=<?= @filemtime(__DIR__ . '/assets/css/public-forms.css') ?: time() ?>">
    <?= function_exists('renderIconifyScript') ? renderIconifyScript() : '<script src="https://code.iconify.design/iconify-icon/2.1.0/iconify-icon.min.js" defer></script>' ?>
    <script>window.BASE_URL = <?= json_encode(BASE_URL) ?>;</script>
    <script src="<?= BASE_URL ?>/assets/js/alert.js?v=<?= @filemtime(__DIR__ . '/assets/js/alert.js') ?: time() ?>" defer></script>
    <?php if ($googleCid !== ''): ?>
        <script src="https://accounts.google.com/gsi/client" async defer></script>
    <?php endif; ?>
</head>
<body>
<div class="gf-bg-scene">
    <div class="gf-orb gf-orb-1"></div>
    <div class="gf-orb gf-orb-2"></div>
    <div class="gf-orb gf-orb-3"></div>
</div>
<div class="gf-progress-rail"><div class="gf-progress-fill" id="gfProgressFill"></div></div>

<div class="gf-shell">
    <?php if (!$formMeta): ?>
        <div class="gf-card">
            <div class="gf-header-bar"></div>
            <div class="gf-header-body" style="justify-content:center;text-align:center;padding:56px 24px;">
                <div>
                    <iconify-icon icon="solar:lock-keyhole-bold" width="58" style="color:#DC2626;"></iconify-icon>
                    <h1 class="gf-title" style="margin-top:12px;">Formulir Tidak Tersedia</h1>
                    <p class="gf-desc">Link formulir yang Anda buka tidak valid, sudah kadaluarsa, atau sedang ditutup oleh Administrator sekolah.</p>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- HEADER CARD -->
        <div class="gf-card gf-delay-1">
            <div class="gf-header-bar"></div>
            <div class="gf-header-body">
                <div class="gf-gunungan" aria-hidden="true" style="background-image:url('<?= BASE_URL ?>/assets/img/gunungan.png');"></div>
                <div class="gf-header-left">
                    <div class="gf-brand" oncontextmenu="return false;" ondragstart="return false;">
                        <div class="gf-brand-logo" role="img" aria-label="Logo Sekolah" style="background-image:url('<?= BASE_URL ?>/assets/images/logo-learn.png');"></div>
                        <div>
                            <div class="gf-brand-meta"><?= htmlspecialchars($school['nama_sekolah'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div style="font-size:11.5px;color:#64748B;">Tahun Pelajaran <?= htmlspecialchars($school['tahun_aktif'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    </div>
                    <h1 class="gf-title"><?= htmlspecialchars($formMeta['title'], ENT_QUOTES, 'UTF-8') ?></h1>
                    <p class="gf-desc"><?= htmlspecialchars($formMeta['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div class="gf-header-right">
                    <span class="gf-badge-sec">
                        <iconify-icon icon="solar:shield-check-bold" width="15"></iconify-icon>
                        Tautan Resmi Terenkripsi
                    </span>
                    <span class="gf-progress-pill" id="gfProgressPill">
                        <iconify-icon icon="solar:chart-2-bold" width="14"></iconify-icon>
                        <span>Kelengkapan: 0%</span>
                    </span>
                    <span class="gf-req-note">* Menunjukkan pertanyaan wajib diisi</span>
                </div>
            </div>
        </div>

        <!-- GOOGLE-FORM-STYLE AUTO-EMAIL CAPTURE BAR -->
        <div class="gf-email-banner" id="gfEmailBanner">
            <div class="gf-email-banner-left">
                <div class="gf-google-avatar" id="gfAvatarBox">
                    <iconify-icon icon="solar:letter-bold" width="22"></iconify-icon>
                </div>
                <div style="min-width:0;">
                    <div class="gf-email-status-title" id="gfEmailStatusTitle">
                        <span>Ambil Email Otomatis (seperti Google Form)</span>
                        <span class="gf-lock-pill">
                            <iconify-icon icon="solar:lock-keyhole-minimalistic-bold" width="13"></iconify-icon>
                            1 Nama · 1 Email · 1 No. Identitas = 1× Kirim
                        </span>
                    </div>
                    <div class="gf-email-status-sub" id="gfEmailStatusSub">
                        Klik tombol Google di kanan untuk mengambil email otomatis, atau langsung ketik manual pada kolom Email di bawah.
                    </div>
                </div>
            </div>
            <div class="gf-email-banner-right">
                <button type="button" class="gf-btn-google" id="btnTriggerGoogle" onclick="triggerGooglePick()">
                    <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.1H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 8 3l5.7-5.7C34 6 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.7-.4-3.9z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 8 3l5.7-5.7C34 6 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.1H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.7-.4-3.9z"/></svg>
                    <span id="btnGoogleLabel">Ambil Email dari Google</span>
                </button>
            </div>
        </div>

        <!-- SUCCESS SCREEN -->
        <div class="gf-card gf-success-box" id="gfSuccessCard">
            <div class="gf-success-circle">
                <iconify-icon icon="solar:check-circle-bold" width="46"></iconify-icon>
            </div>
            <h2 class="gf-title">Jawaban Anda Telah Direkam!</h2>
            <p class="gf-desc" id="gfSuccessMsg" style="margin:0 auto 22px;max-width:620px;"></p>
            <div style="display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border-radius:999px;background:#ECFDF5;color:#047857;border:1px solid #A7F3D0;font-size:12.5px;font-weight:700;">
                <iconify-icon icon="solar:shield-check-bold" width="16"></iconify-icon>
                <span>Data terkunci (1× pengiriman) &amp; tersinkronisasi otomatis ke SIAKAD</span>
            </div>
        </div>

        <form id="gfFormGuru" onsubmit="submitFormGuru(event)">
            <input type="hidden" name="h" value="<?= htmlspecialchars($hashParam, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="text" name="website_hp" value="" style="display:none;" tabindex="-1" autocomplete="off">

            <div class="gf-cols">
                <!-- KOLOM KIRI: BAGIAN 1 IDENTITAS & KONTAK -->
                <div class="gf-card gf-delay-2">
                    <div class="gf-sec-head">
                        <div class="gf-sec-head-left">
                            <iconify-icon icon="solar:user-speak-bold" width="20"></iconify-icon>
                            <span>Identitas &amp; Kontak Pendidik</span>
                        </div>
                        <span class="gf-step-pill">Bagian 1 dari 2</span>
                    </div>
                    <div class="gf-sec-body">
                        <div class="gf-q">
                            <label for="nama_guru">Nama Lengkap &amp; Gelar <span class="req">*</span></label>
                            <input type="text" id="nama_guru" name="nama_guru" class="gf-input" placeholder="Misal: Ustadz Rahmat Hidayat, M.Pd.I" required oninput="updateProgress()">
                        </div>
                        <div class="gf-grid-2">
                            <div class="gf-q">
                                <label for="nip">NIP / NIK (No. Identitas) <span class="req">*</span></label>
                                <input type="text" id="nip" name="nip" class="gf-input" placeholder="NIP atau NIK KTP" required oninput="updateProgress()">
                            </div>
                            <div class="gf-q">
                                <label for="nuptk">NUPTK (opsional)</label>
                                <input type="text" id="nuptk" name="nuptk" class="gf-input" placeholder="16 digit NUPTK (jika ada)">
                            </div>
                        </div>
                        <div class="gf-grid-2">
                            <div class="gf-q">
                                <label for="email">Email / Gmail Aktif <span class="req">*</span></label>
                                <input type="email" id="email" name="email" class="gf-input" placeholder="nama.guru@gmail.com" value="<?= htmlspecialchars($sessionUser['email'], ENT_QUOTES, 'UTF-8') ?>" required oninput="updateProgress()">
                            </div>
                            <div class="gf-q">
                                <label for="no_hp">No. WhatsApp / HP Aktif</label>
                                <input type="text" id="no_hp" name="no_hp" class="gf-input" placeholder="08xxxxxxxxxx">
                            </div>
                        </div>
                        <div class="gf-grid-2">
                            <div class="gf-q">
                                <label for="jenis_kelamin">Jenis Kelamin <span class="req">*</span></label>
                                <select id="jenis_kelamin" name="jenis_kelamin" class="gf-select" required>
                                    <option value="L">Laki-laki (L)</option>
                                    <option value="P">Perempuan (P)</option>
                                </select>
                            </div>
                            <div class="gf-q">
                                <label for="status_kepegawaian">Status Kepegawaian</label>
                                <select id="status_kepegawaian" name="status_kepegawaian" class="gf-select">
                                    <option value="Tetap Yayasan">Tetap Yayasan</option>
                                    <option value="PNS">PNS</option>
                                    <option value="PPPK">PPPK</option>
                                    <option value="GTT">GTT</option>
                                    <option value="Honorer">Honorer</option>
                                </select>
                            </div>
                        </div>
                        <div class="gf-grid-2">
                            <div class="gf-q">
                                <label for="tempat_lahir">Tempat Lahir</label>
                                <input type="text" id="tempat_lahir" name="tempat_lahir" class="gf-input" placeholder="Kota / Kabupaten">
                            </div>
                            <div class="gf-q">
                                <label for="tanggal_lahir">Tanggal Lahir</label>
                                <div style="position: relative;">
                                    <input type="text" id="tanggal_lahir" name="tanggal_lahir" class="gf-input" data-ilm-calendar="date" readonly placeholder="Pilih tanggal lahir..." style="cursor: pointer; background: #FFFFFF; padding-right: 42px;">
                                    <iconify-icon icon="solar:calendar-date-bold" onclick="document.getElementById('tanggal_lahir').click()" style="position: absolute; right: 14px; top: 50%; transform: translateY(-50%); color: var(--lg-teal); cursor: pointer; font-size: 19px;"></iconify-icon>
                                </div>
                            </div>
                        </div>
                        <div class="gf-q">
                            <label for="alamat">Alamat Tempat Tinggal</label>
                            <textarea id="alamat" name="alamat" class="gf-textarea" placeholder="Alamat domisili saat ini"></textarea>
                        </div>
                    </div>
                </div>

                <!-- KOLOM KANAN: BAGIAN 2 MATA PELAJARAN (MULTIPLE CHOICE) -->
                <div class="gf-card gf-delay-3">
                    <div class="gf-sec-head">
                        <div class="gf-sec-head-left">
                            <iconify-icon icon="solar:book-2-bold" width="20"></iconify-icon>
                            <span>Mata Pelajaran yang Diampu</span>
                        </div>
                        <span class="gf-step-pill">Bagian 2 dari 2</span>
                    </div>
                    <div class="gf-sec-body">
                        <div class="gf-mc-head">
                            <div class="gf-mc-title">
                                <iconify-icon icon="solar:book-bookmark-bold" width="17" style="color:var(--lg-gold-deep);"></iconify-icon>
                                <span>Pilih Mata Pelajaran (boleh lebih dari satu) <span style="color:#DC2626;">*</span></span>
                            </div>
                            <div class="gf-mc-tools">
                                <span class="gf-mc-count" id="cntMapel">0 dipilih</span>
                                <button type="button" class="gf-mc-mini-btn" onclick="toggleAllMapel(true)">Pilih Semua</button>
                                <button type="button" class="gf-mc-mini-btn" onclick="toggleAllMapel(false)">Reset</button>
                            </div>
                        </div>

                        <div class="gf-choice-grid" id="gridMapel">
                            <?php foreach ($mapelList as $m): ?>
                                <label class="gf-choice-item">
                                    <input type="checkbox" name="mapel_ids[]" value="<?= (int)$m['id'] ?>" onchange="syncChoiceUI()">
                                    <div class="gf-choice-text">
                                        <span class="gf-choice-name"><?= htmlspecialchars($m['nama_mata_pelajaran'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if (!empty($m['kode_mapel']) || !empty($m['semester'])): ?>
                                            <span class="gf-choice-sub"><?= htmlspecialchars(trim(($m['kode_mapel'] ?? '') . ' · Smt ' . ($m['semester'] ?? 'Ganjil'), ' ·'), ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <div class="gf-hint-box">
                            <iconify-icon icon="solar:info-circle-bold" width="18" style="color:var(--lg-teal);flex-shrink:0;"></iconify-icon>
                            <span>Guru cukup memilih mata pelajaran yang diampu saja. Pembagian rombel/kelas mengajar akan diatur langsung oleh Administrator sekolah.</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="gf-actions-bar">
                <button type="button" class="gf-btn-ghost" onclick="confirmResetForm()">
                    <iconify-icon icon="solar:eraser-bold" width="17"></iconify-icon>
                    <span>Kosongkan Formulir</span>
                </button>
                <button type="submit" id="gfBtnSubmit" class="gf-btn-submit">
                    <iconify-icon icon="solar:plain-bold" width="18"></iconify-icon>
                    <span>Kirim Formulir Guru</span>
                </button>
            </div>
        </form>
    <?php endif; ?>

    <div class="gf-footer">
        Formulir Resmi <?= htmlspecialchars($school['nama_sekolah'], ENT_QUOTES, 'UTF-8') ?> · Didukung oleh <strong>Ilmuna Learn</strong>
    </div>
</div>

<?php if (function_exists('loadCentralTemplates')) { loadCentralTemplates(['calendar']); } ?>

<script>
const GOOGLE_CLIENT_ID = <?= json_encode($googleCid) ?>;
const SESSION_EMAIL = <?= json_encode($sessionUser['email']) ?>;
const SESSION_NAME  = <?= json_encode($sessionUser['name']) ?>;

function notify(msg, type) {
    if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
        window.Ilmuna.toast(msg, type || 'info');
    }
}

function decodeJwtPayload(token) {
    try {
        var b64 = token.split('.')[1].replace(/-/g, '+').replace(/_/g, '/');
        var json = decodeURIComponent(atob(b64).split('').map(c => '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2)).join(''));
        return JSON.parse(json);
    } catch (e) { return null; }
}

function applyCapturedEmail(email, name, picture, sourceLabel) {
    if (!email) return;
    var input = document.getElementById('email');
    if (input) {
        input.value = email;
        input.classList.remove('is-filled-flash');
        void input.offsetWidth;
        input.classList.add('is-filled-flash');
    }
    var banner = document.getElementById('gfEmailBanner');
    if (banner) banner.classList.add('is-linked');
    var av = document.getElementById('gfAvatarBox');
    if (av) {
        av.innerHTML = picture
            ? '<img src="' + picture + '" alt="Avatar">'
            : '<span>' + (email[0] || 'G').toUpperCase() + '</span>';
    }
    var t = document.getElementById('gfEmailStatusTitle');
    if (t) {
        t.innerHTML = '<iconify-icon icon="solar:check-circle-bold" width="18" style="color:#059669;"></iconify-icon>'
            + '<span>' + email + '</span>'
            + '<span class="gf-lock-pill"><iconify-icon icon="solar:lock-keyhole-minimalistic-bold" width="13"></iconify-icon> 1× Kirim</span>';
    }
    var s = document.getElementById('gfEmailStatusSub');
    if (s) {
        s.textContent = 'Email diambil otomatis dari ' + sourceLabel + (name ? ' (' + name + ')' : '') + '. Anda tetap dapat mengubahnya manual jika perlu.';
    }
    var lbl = document.getElementById('btnGoogleLabel');
    if (lbl) lbl.textContent = 'Ganti Akun Google';
    updateProgress();
}

function initGoogleAutoEmail() {
    if (SESSION_EMAIL) {
        applyCapturedEmail(SESSION_EMAIL, SESSION_NAME, '', 'Sesi Aktif');
    }
    if (!GOOGLE_CLIENT_ID || !window.google || !google.accounts || !google.accounts.id) return;
    google.accounts.id.initialize({
        client_id: GOOGLE_CLIENT_ID,
        callback: function(resp) {
            var p = decodeJwtPayload(resp && resp.credential ? resp.credential : '');
            if (p && p.email) {
                applyCapturedEmail(p.email, p.name || '', p.picture || '', 'Akun Google');
                notify('Email Google (' + p.email + ') berhasil diambil otomatis!', 'success');
            }
        },
        auto_select: false
    });
    if (!SESSION_EMAIL) {
        try { google.accounts.id.prompt(); } catch (e) {}
    }
}

function triggerGooglePick() {
    if (!GOOGLE_CLIENT_ID || !window.google || !google.accounts || !google.accounts.oauth2) {
        var inp = document.getElementById('email');
        if (inp) inp.focus();
        notify('Silakan ketik langsung email aktif Anda pada kolom Email Guru.', 'info');
        return;
    }
    try {
        var client = google.accounts.oauth2.initTokenClient({
            client_id: GOOGLE_CLIENT_ID,
            scope: 'email profile',
            callback: async function(tok) {
                if (!tok || !tok.access_token) return;
                try {
                    var r = await fetch('https://www.googleapis.com/oauth2/v3/userinfo', {
                        headers: { Authorization: 'Bearer ' + tok.access_token }
                    });
                    var u = await r.json();
                    if (u && u.email) {
                        applyCapturedEmail(u.email, u.name || '', u.picture || '', 'Akun Google');
                        notify('Email Google (' + u.email + ') berhasil diambil otomatis!', 'success');
                    }
                } catch (err) {
                    notify('Gagal membaca profil Google. Anda bisa ketik email secara manual.', 'warning');
                }
            }
        });
        client.requestAccessToken({ prompt: 'select_account' });
    } catch (e) {
        if (window.google && google.accounts && google.accounts.id) {
            google.accounts.id.prompt();
        }
    }
}

function syncChoiceUI() {
    var mCount = 0;
    document.querySelectorAll('#gridMapel .gf-choice-item').forEach(function(lbl) {
        var cb = lbl.querySelector('input[type="checkbox"]');
        lbl.classList.toggle('is-checked', cb.checked);
        if (cb.checked) mCount++;
    });
    var cM = document.getElementById('cntMapel');
    if (cM) {
        cM.textContent = mCount + ' dipilih';
        cM.classList.toggle('has-val', mCount > 0);
    }
    updateProgress();
}

function toggleAllMapel(state) {
    document.querySelectorAll('#gridMapel input[type="checkbox"]').forEach(function(cb) { cb.checked = state; });
    syncChoiceUI();
}

function updateProgress() {
    var checks = [
        !!(document.getElementById('nama_guru') && document.getElementById('nama_guru').value.trim()),
        !!(document.getElementById('nip') && document.getElementById('nip').value.trim()),
        !!(document.getElementById('email') && document.getElementById('email').value.trim()),
        document.querySelectorAll('#gridMapel input[type="checkbox"]:checked').length > 0
    ];
    var done = checks.filter(Boolean).length;
    var pct = Math.round((done / checks.length) * 100);
    var fill = document.getElementById('gfProgressFill');
    var pill = document.getElementById('gfProgressPill');
    if (fill) fill.style.width = pct + '%';
    if (pill) {
        pill.classList.toggle('is-full', pct === 100);
        pill.innerHTML = '<iconify-icon icon="' + (pct === 100 ? 'solar:check-circle-bold' : 'solar:chart-2-bold') + '" width="14"></iconify-icon>'
            + '<span>Kelengkapan: ' + pct + '%</span>';
    }
}

function launchConfetti() {
    var colors = ['#1D3B5E', '#0D7A87', '#F59E0B', '#10B981', '#52B2DC'];
    for (var i = 0; i < 42; i++) {
        var el = document.createElement('div');
        el.className = 'gf-confetti-piece';
        el.style.left = (Math.random() * 100) + 'vw';
        el.style.background = colors[i % colors.length];
        el.style.animationDelay = (Math.random() * 0.6) + 's';
        el.style.animationDuration = (1.8 + Math.random() * 1.4) + 's';
        document.body.appendChild(el);
        setTimeout((function(node){ return function(){ node.remove(); }; })(el), 3600);
    }
}

async function confirmResetForm() {
    if (window.Ilmuna && typeof window.Ilmuna.confirm === 'function') {
        const ok = await window.Ilmuna.confirm({
            title: 'Kosongkan Formulir?',
            message: 'Seluruh isian dan pilihan mata pelajaran akan dihapus.',
            confirmText: 'Ya, Kosongkan',
            variant: 'warning'
        });
        if (!ok) return;
    }
    document.getElementById('gfFormGuru').reset();
    syncChoiceUI();
    notify('Formulir telah dikosongkan.', 'info');
}

async function submitFormGuru(e) {
    e.preventDefault();
    var form = document.getElementById('gfFormGuru');
    var btn = document.getElementById('gfBtnSubmit');

    if (form.querySelectorAll('input[name="mapel_ids[]"]:checked').length === 0) {
        notify('Pilih minimal satu Mata Pelajaran yang diampu.', 'warning');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<iconify-icon icon="solar:refresh-bold" class="gf-spin" width="18"></iconify-icon><span>Mengirim data...</span>';
    try {
        var r = await fetch('<?= BASE_URL ?>/form-guru?submit=1', {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        });
        var res = await r.json();
        if (res && res.success) {
            form.style.display = 'none';
            document.getElementById('gfEmailBanner').style.display = 'none';
            document.getElementById('gfSuccessMsg').textContent = res.message;
            document.getElementById('gfSuccessCard').classList.add('is-show');
            launchConfetti();
            notify('Data guru & mata pelajaran berhasil disimpan!', 'success');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } else {
            notify((res && res.message) || 'Gagal mengirim formulir.', 'error');
        }
    } catch (err) {
        notify('Terjadi kesalahan koneksi ke server. Silakan coba lagi.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<iconify-icon icon="solar:plain-bold" width="18"></iconify-icon><span>Kirim Formulir Guru</span>';
    }
}

window.addEventListener('load', function() {
    updateProgress();
    initGoogleAutoEmail();
});
</script>
</body>
</html>
