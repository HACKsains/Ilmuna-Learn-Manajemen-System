<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — FORMULIR PUBLIK SISWA & ORANG TUA (GAYA GOOGLE FORM + HASH LINK)
 * ==============================================================================
 * URL: /form-siswa?h=<token_hash>
 * - Kunci ketat 1x kirim: 1 Nama + 1 Email + 1 No. Identitas (NISN) hanya 1x.
 * - Ambil email otomatis seperti Google Form (Google Identity Services / Sesi)
 *   ATAU ketik manual langsung.
 * - Siswa memilih Kelas (dropdown) saja; Orang Tua terhubung multi-anak otomatis.
 * - Full-screen desktop responsive + Full animasi & transisi + window.Ilmuna.
 * ==============================================================================
 */

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/public-form-engine.php';

$pdo = getDBConnection();
$pdo->exec("SET time_zone = '+07:00'");

$hashParam   = trim((string)($_GET['h'] ?? $_POST['h'] ?? ''));
$formMeta    = ilmuna_validate_public_form_hash($pdo, 'siswa_ortu', $hashParam);
$school      = ilmuna_get_school_brand($pdo);
$sessionUser = ilmuna_get_session_user_email($pdo);
$googleCid   = defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : '';

if (empty($_SESSION['pub_form_csrf_siswa'])) {
    $_SESSION['pub_form_csrf_siswa'] = bin2hex(random_bytes(16));
}
$csrfToken = $_SESSION['pub_form_csrf_siswa'];

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

        // 1. Data Siswa
        $idKelas      = (int)($_POST['id_kelas'] ?? 0);
        $nisn         = trim((string)($_POST['nisn'] ?? ''));
        $nis          = trim((string)($_POST['nis'] ?? ''));
        $namaSiswa    = trim((string)($_POST['nama_lengkap'] ?? ''));
        $tempatLahir  = trim((string)($_POST['tempat_lahir'] ?? ''));
        $tanggalLahir = trim((string)($_POST['tanggal_lahir'] ?? ''));
        $noHpSiswa    = trim((string)($_POST['no_hp'] ?? ''));
        $gmailSiswa   = trim((string)($_POST['gmail'] ?? ''));
        $alamatSiswa  = trim((string)($_POST['alamat'] ?? ''));

        // 2. Data Orang Tua / Wali
        $namaAyah       = trim((string)($_POST['nama_ayah'] ?? ''));
        $noHpAyah       = trim((string)($_POST['no_hp_ayah'] ?? ''));
        $pekerjaanAyah  = trim((string)($_POST['pekerjaan_ayah'] ?? ''));
        $namaIbu        = trim((string)($_POST['nama_ibu'] ?? ''));
        $noHpIbu        = trim((string)($_POST['no_hp_ibu'] ?? ''));
        $pekerjaanIbu   = trim((string)($_POST['pekerjaan_ibu'] ?? ''));
        $namaWali       = trim((string)($_POST['nama_wali'] ?? ''));
        $noHpWali       = trim((string)($_POST['no_hp_wali'] ?? ''));
        $gmailOrtu      = trim((string)($_POST['email_gmail'] ?? ''));
        $hubungan       = trim((string)($_POST['hubungan'] ?? 'Orang Tua Kandung'));
        $alamatOrtu     = trim((string)($_POST['alamat_orang_tua'] ?? ''));
        if ($alamatOrtu === '') $alamatOrtu = $alamatSiswa;

        if ($idKelas <= 0 || $namaSiswa === '' || $nisn === '' || $gmailSiswa === '') {
            echo json_encode(['success' => false, 'message' => 'Kelas, Nama Lengkap Siswa, NISN (No. Identitas), dan Email Siswa wajib diisi.']);
            exit;
        }
        if (!filter_var($gmailSiswa, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Format Email Siswa tidak valid.']);
            exit;
        }
        $stK = $pdo->prepare("SELECT nama_kelas FROM kelas WHERE id = ? LIMIT 1");
        $stK->execute([$idKelas]);
        $namaKelas = $stK->fetchColumn();
        if (!$namaKelas) {
            echo json_encode(['success' => false, 'message' => 'Kelas yang dipilih tidak ditemukan di database.']);
            exit;
        }
        if ($namaAyah === '' && $namaIbu === '' && $namaWali === '') {
            echo json_encode(['success' => false, 'message' => 'Minimal isi Nama Ayah, Nama Ibu, atau Nama Wali.']);
            exit;
        }

        // KUNCI KETAT 1x KIRIM: 1 Nama + 1 Email + 1 No. Identitas (NISN)
        $dupErr = ilmuna_check_public_form_duplicate($pdo, 'siswa_ortu', $namaSiswa, $gmailSiswa, $nisn);
        if ($dupErr !== null) {
            echo json_encode(['success' => false, 'message' => $dupErr]);
            exit;
        }

        if ($nis === '') $nis = $nisn;
        $tglVal = preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalLahir) ? $tanggalLahir : null;

        $pdo->beginTransaction();

        // Catat penguncian 1x kirim terlebih dahulu (anti race-condition via UNIQUE index)
        ilmuna_record_public_form_submission($pdo, 'siswa_ortu', $namaSiswa, $gmailSiswa, $nisn);

        $stChk = $pdo->prepare("SELECT id FROM siswa WHERE nisn = ? OR nis = ? LIMIT 1");
        $stChk->execute([$nisn, $nis]);
        $existSiswaId = (int)$stChk->fetchColumn();

        if ($existSiswaId > 0) {
            $pdo->prepare("
                UPDATE siswa
                SET id_kelas = ?, nama_lengkap = ?,
                    tempat_lahir = COALESCE(NULLIF(?, ''), tempat_lahir),
                    tanggal_lahir = COALESCE(?, tanggal_lahir),
                    no_hp = COALESCE(NULLIF(?, ''), no_hp),
                    gmail = COALESCE(NULLIF(?, ''), gmail),
                    alamat = COALESCE(NULLIF(?, ''), alamat)
                WHERE id = ?
            ")->execute([$idKelas, $namaSiswa, $tempatLahir, $tglVal, $noHpSiswa, $gmailSiswa, $alamatSiswa, $existSiswaId]);
            $studentId = $existSiswaId;
        } else {
            $pdo->prepare("
                INSERT INTO siswa (id_kelas, nisn, nis, nama_lengkap, tempat_lahir, tanggal_lahir, no_hp, gmail, alamat)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $idKelas, $nisn, $nis, $namaSiswa,
                $tempatLahir ?: null, $tglVal,
                $noHpSiswa ?: null, $gmailSiswa ?: null, $alamatSiswa ?: null
            ]);
            $studentId = (int)$pdo->lastInsertId();
        }

        // Simpan / gabungkan Orang Tua (Multi-Anak Otomatis!)
        $ortuId = 0;
        if ($gmailOrtu !== '') {
            $stO = $pdo->prepare("SELECT id FROM orang_tua WHERE LOWER(email_gmail) = LOWER(?) LIMIT 1");
            $stO->execute([$gmailOrtu]);
            $ortuId = (int)$stO->fetchColumn();
        }
        if ($ortuId <= 0 && ($namaAyah !== '' || $namaIbu !== '')) {
            $stO2 = $pdo->prepare("SELECT id FROM orang_tua WHERE COALESCE(LOWER(nama_ayah), '') = LOWER(?) AND COALESCE(LOWER(nama_ibu), '') = LOWER(?) LIMIT 1");
            $stO2->execute([$namaAyah, $namaIbu]);
            $ortuId = (int)$stO2->fetchColumn();
        }

        if ($ortuId > 0) {
            $pdo->prepare("
                UPDATE orang_tua
                SET nama_ayah = COALESCE(NULLIF(?, ''), nama_ayah),
                    no_hp_ayah = COALESCE(NULLIF(?, ''), no_hp_ayah),
                    pekerjaan_ayah = COALESCE(NULLIF(?, ''), pekerjaan_ayah),
                    nama_ibu = COALESCE(NULLIF(?, ''), nama_ibu),
                    no_hp_ibu = COALESCE(NULLIF(?, ''), no_hp_ibu),
                    pekerjaan_ibu = COALESCE(NULLIF(?, ''), pekerjaan_ibu),
                    nama_wali = COALESCE(NULLIF(?, ''), nama_wali),
                    no_hp_wali = COALESCE(NULLIF(?, ''), no_hp_wali),
                    email_gmail = COALESCE(NULLIF(?, ''), email_gmail),
                    alamat = COALESCE(NULLIF(?, ''), alamat)
                WHERE id = ?
            ")->execute([$namaAyah, $noHpAyah, $pekerjaanAyah, $namaIbu, $noHpIbu, $pekerjaanIbu, $namaWali, $noHpWali, $gmailOrtu, $alamatOrtu, $ortuId]);
        } else {
            $pdo->prepare("
                INSERT INTO orang_tua
                    (nama_ayah, no_hp_ayah, pekerjaan_ayah, nama_ibu, no_hp_ibu, pekerjaan_ibu, nama_wali, no_hp_wali, email_gmail, alamat)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $namaAyah ?: null, $noHpAyah ?: null, $pekerjaanAyah ?: null,
                $namaIbu ?: null, $noHpIbu ?: null, $pekerjaanIbu ?: null,
                $namaWali ?: null, $noHpWali ?: null, $gmailOrtu ?: null, $alamatOrtu ?: null
            ]);
            $ortuId = (int)$pdo->lastInsertId();
        }

        if ($ortuId > 0 && $studentId > 0) {
            $pdo->prepare("INSERT IGNORE INTO orang_tua_siswa (id_orang_tua, id_siswa, hubungan, is_primary) VALUES (?, ?, ?, 1)")
                ->execute([$ortuId, $studentId, $hubungan ?: 'Orang Tua Kandung']);
        }

        $pdo->prepare("UPDATE public_form_links SET submission_count = submission_count + 1 WHERE id = ?")
            ->execute([(int)$formMeta['id']]);

        $pdo->commit();
        echo json_encode([
            'success' => true,
            'message' => 'Terima kasih! Data siswa ' . $namaSiswa . ' (' . $namaKelas . ') beserta data orang tua/wali berhasil direkam ke database sekolah.'
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            echo json_encode(['success' => false, 'message' => 'Data Nama, Email, atau NISN ini sudah tercatat pernah mengirim formulir sebelumnya (maksimal 1 kali kirim).']);
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

$kelasList = $formMeta
    ? $pdo->query("SELECT id, nama_kelas, tahun_pelajaran FROM kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC)
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
                            1 Nama · 1 Email · 1 NISN = 1× Kirim
                        </span>
                    </div>
                    <div class="gf-email-status-sub" id="gfEmailStatusSub">
                        Klik tombol Google di kanan untuk mengambil email otomatis, atau langsung ketik manual pada kolom Email di bawah.
                    </div>
                </div>
            </div>
            <div class="gf-email-banner-right">
                <div id="gSignInWrap"></div>
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

        <form id="gfFormSiswa" onsubmit="submitFormSiswa(event)">
            <input type="hidden" name="h" value="<?= htmlspecialchars($hashParam, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="text" name="website_hp" value="" style="display:none;" tabindex="-1" autocomplete="off">

            <div class="gf-cols">
                <!-- KOLOM KIRI: BAGIAN 1 DATA SISWA -->
                <div class="gf-card gf-delay-2">
                    <div class="gf-sec-head">
                        <div class="gf-sec-head-left">
                            <iconify-icon icon="solar:square-academic-cap-2-bold" width="20"></iconify-icon>
                            <span>Biodata Peserta Didik (Siswa)</span>
                        </div>
                        <span class="gf-step-pill">Bagian 1 dari 2</span>
                    </div>
                    <div class="gf-sec-body">
                        <div class="gf-q">
                            <label for="id_kelas">
                                <iconify-icon icon="solar:buildings-2-bold" width="16" style="color:var(--lg-teal);"></iconify-icon>
                                Pilih Rombel / Kelas <span class="req">*</span>
                            </label>
                            <select id="id_kelas" name="id_kelas" class="gf-select gf-kelas-highlight" required onchange="updateProgress()">
                                <option value="">-- Pilih Kelas Siswa --</option>
                                <?php foreach ($kelasList as $k): ?>
                                    <option value="<?= (int)$k['id'] ?>">
                                        <?= htmlspecialchars($k['nama_kelas'], ENT_QUOTES, 'UTF-8') ?><?= !empty($k['tahun_pelajaran']) ? ' — TP ' . htmlspecialchars($k['tahun_pelajaran'], ENT_QUOTES, 'UTF-8') : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="gf-hint">Siswa cukup memilih kelas saja; mata pelajaran otomatis mengikuti kurikulum rombel.</div>
                        </div>

                        <div class="gf-q">
                            <label for="nama_lengkap">Nama Lengkap Siswa <span class="req">*</span></label>
                            <input type="text" id="nama_lengkap" name="nama_lengkap" class="gf-input" placeholder="Sesuai akta kelahiran / ijazah" required oninput="updateProgress()">
                        </div>

                        <div class="gf-grid-2">
                            <div class="gf-q">
                                <label for="nisn">NISN (No. Identitas) <span class="req">*</span></label>
                                <input type="text" id="nisn" name="nisn" class="gf-input" placeholder="10 digit NISN" required oninput="updateProgress()">
                            </div>
                            <div class="gf-q">
                                <label for="nis">NIS (Nomor Induk Sekolah)</label>
                                <input type="text" id="nis" name="nis" class="gf-input" placeholder="Boleh dikosongkan jika belum tahu">
                            </div>
                        </div>

                        <div class="gf-grid-2">
                            <div class="gf-q">
                                <label for="gmail">Email / Gmail Siswa <span class="req">*</span></label>
                                <input type="email" id="gmail" name="gmail" class="gf-input" placeholder="nama.siswa@gmail.com" value="<?= htmlspecialchars($sessionUser['email'], ENT_QUOTES, 'UTF-8') ?>" required oninput="updateProgress()">
                            </div>
                            <div class="gf-q">
                                <label for="no_hp">No. WhatsApp / HP Siswa</label>
                                <input type="text" id="no_hp" name="no_hp" class="gf-input" placeholder="08xxxxxxxxxx">
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
                            <label for="alamat">Alamat Tempat Tinggal Siswa</label>
                            <textarea id="alamat" name="alamat" class="gf-textarea" placeholder="Jalan, Dusun, RT/RW, Desa/Kelurahan, Kecamatan, Kabupaten"></textarea>
                        </div>
                    </div>
                </div>

                <!-- KOLOM KANAN: BAGIAN 2 DATA ORANG TUA / WALI -->
                <div class="gf-card gf-delay-3">
                    <div class="gf-sec-head">
                        <div class="gf-sec-head-left">
                            <iconify-icon icon="solar:users-group-two-rounded-bold" width="20"></iconify-icon>
                            <span>Data Orang Tua / Wali Siswa</span>
                        </div>
                        <span class="gf-step-pill">Bagian 2 dari 2</span>
                    </div>
                    <div class="gf-sec-body">
                        <div class="gf-grid-2">
                            <div class="gf-q">
                                <label for="nama_ayah">Nama Lengkap Ayah <span class="req">*</span></label>
                                <input type="text" id="nama_ayah" name="nama_ayah" class="gf-input" placeholder="Nama lengkap ayah" oninput="updateProgress()">
                            </div>
                            <div class="gf-q">
                                <label for="no_hp_ayah">No. HP / WhatsApp Ayah</label>
                                <input type="text" id="no_hp_ayah" name="no_hp_ayah" class="gf-input" placeholder="08xxxxxxxxxx">
                            </div>
                        </div>

                        <div class="gf-grid-2">
                            <div class="gf-q">
                                <label for="nama_ibu">Nama Lengkap Ibu <span class="req">*</span></label>
                                <input type="text" id="nama_ibu" name="nama_ibu" class="gf-input" placeholder="Nama lengkap ibu" oninput="updateProgress()">
                            </div>
                            <div class="gf-q">
                                <label for="no_hp_ibu">No. HP / WhatsApp Ibu</label>
                                <input type="text" id="no_hp_ibu" name="no_hp_ibu" class="gf-input" placeholder="08xxxxxxxxxx">
                            </div>
                        </div>

                        <div class="gf-grid-2">
                            <div class="gf-q">
                                <label for="pekerjaan_ayah">Pekerjaan Ayah</label>
                                <input type="text" id="pekerjaan_ayah" name="pekerjaan_ayah" class="gf-input" placeholder="Wiraswasta / PNS / Petani / dsb">
                            </div>
                            <div class="gf-q">
                                <label for="pekerjaan_ibu">Pekerjaan Ibu</label>
                                <input type="text" id="pekerjaan_ibu" name="pekerjaan_ibu" class="gf-input" placeholder="Ibu Rumah Tangga / Guru / dsb">
                            </div>
                        </div>

                        <div class="gf-grid-2">
                            <div class="gf-q">
                                <label for="email_gmail">Email Gmail Orang Tua / Wali</label>
                                <input type="email" id="email_gmail" name="email_gmail" class="gf-input" placeholder="orangtua@gmail.com">
                                <div class="gf-hint">Untuk portal monitoring orang tua (jika 2+ anak, pakai Gmail sama).</div>
                            </div>
                            <div class="gf-q">
                                <label for="hubungan">Hubungan Keluarga</label>
                                <select id="hubungan" name="hubungan" class="gf-select">
                                    <option value="Orang Tua Kandung">Orang Tua Kandung</option>
                                    <option value="Wali">Wali Siswa</option>
                                    <option value="Orang Tua Angkat">Orang Tua Angkat</option>
                                </select>
                            </div>
                        </div>

                        <div class="gf-grid-2">
                            <div class="gf-q">
                                <label for="nama_wali">Nama Wali (opsional)</label>
                                <input type="text" id="nama_wali" name="nama_wali" class="gf-input" placeholder="Isi jika diwakilkan wali">
                            </div>
                            <div class="gf-q">
                                <label for="no_hp_wali">No. HP Wali (opsional)</label>
                                <input type="text" id="no_hp_wali" name="no_hp_wali" class="gf-input" placeholder="08xxxxxxxxxx">
                            </div>
                        </div>

                        <div class="gf-q">
                            <label for="alamat_orang_tua">Alamat Orang Tua / Wali</label>
                            <label class="gf-same-check">
                                <input type="checkbox" id="chkSamaAlamat" onchange="if(this.checked){var a=document.getElementById('alamat_orang_tua');a.value=document.getElementById('alamat').value;a.classList.add('is-filled-flash');setTimeout(()=>a.classList.remove('is-filled-flash'),900);}">
                                <span>Sama dengan alamat siswa</span>
                            </label>
                            <textarea id="alamat_orang_tua" name="alamat_orang_tua" class="gf-textarea" placeholder="Kosongkan jika sama dengan alamat siswa"></textarea>
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
                    <span>Kirim Formulir Siswa &amp; Orang Tua</span>
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
    var input = document.getElementById('gmail');
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
        var inp = document.getElementById('gmail');
        if (inp) { inp.focus(); }
        notify('Silakan ketik langsung email aktif Anda pada kolom Email Siswa.', 'info');
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

function updateProgress() {
    var checks = [
        !!(document.getElementById('id_kelas') && document.getElementById('id_kelas').value),
        !!(document.getElementById('nama_lengkap') && document.getElementById('nama_lengkap').value.trim()),
        !!(document.getElementById('nisn') && document.getElementById('nisn').value.trim()),
        !!(document.getElementById('gmail') && document.getElementById('gmail').value.trim()),
        !!((document.getElementById('nama_ayah') && document.getElementById('nama_ayah').value.trim())
           || (document.getElementById('nama_ibu') && document.getElementById('nama_ibu').value.trim())
           || (document.getElementById('nama_wali') && document.getElementById('nama_wali').value.trim()))
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
            message: 'Seluruh isian pada formulir ini akan dihapus.',
            confirmText: 'Ya, Kosongkan',
            variant: 'warning'
        });
        if (!ok) return;
    }
    document.getElementById('gfFormSiswa').reset();
    updateProgress();
    notify('Formulir telah dikosongkan.', 'info');
}

async function submitFormSiswa(e) {
    e.preventDefault();
    var form = document.getElementById('gfFormSiswa');
    var btn = document.getElementById('gfBtnSubmit');

    btn.disabled = true;
    btn.innerHTML = '<iconify-icon icon="solar:refresh-bold" class="gf-spin" width="18"></iconify-icon><span>Mengirim data...</span>';
    try {
        var r = await fetch('<?= BASE_URL ?>/form-siswa?submit=1', {
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
            notify('Data siswa & orang tua berhasil disimpan!', 'success');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } else {
            notify((res && res.message) || 'Gagal mengirim formulir.', 'error');
        }
    } catch (err) {
        notify('Terjadi kesalahan koneksi ke server. Silakan coba lagi.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<iconify-icon icon="solar:plain-bold" width="18"></iconify-icon><span>Kirim Formulir Siswa &amp; Orang Tua</span>';
    }
}

window.addEventListener('load', function() {
    updateProgress();
    initGoogleAutoEmail();
});
</script>
</body>
</html>
