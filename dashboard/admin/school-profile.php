<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — PENGATURAN TUNGGAL IDENTITAS & PROFIL SEKOLAH (ADMIN)
 * ==============================================================================
 * Halaman profil tunggal lembaga (1 web untuk 1 sekolah).
 * Semua kolom dipetakan langsung dalam 1 form terstruktur per tab/kategori:
 *   1. Identitas Pokok & Legalitas Sekolah
 *   2. Alamat Lengkap, Kontak & Geolokasi
 *   3. Logo, Kop Surat, Stempel & Motto
 *   4. Kepala Sekolah & Tanda Tangan Digital
 *   5. Jajaran Waka (Kurikulum, Kesiswaan, Sarpras, Humas), TU & Bendahara
 *   6. Parameter Dokumen & Titimangsa Cetak Rapor/Ujian
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';

requireRole('admin');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = getDBConnection();
$pageTitle = 'Identitas & Profil Sekolah';
$pageIcon  = 'solar:diploma-bold';

// Pastikan folder upload sekolah tersedia
$uploadDir = BASE_PATH . '/uploads/school/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

/**
 * Helper untuk upload berkas gambar (Logo, Kop, Stempel, TTD)
 */
function uploadSchoolFile($fileKey, $prefix = 'doc') {
    if (!isset($_FILES[$fileKey]) || empty($_FILES[$fileKey]['name']) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $file = $_FILES[$fileKey];
    $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'svg'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowedExts, true)) {
        return null;
    }

    $filename = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = BASE_PATH . '/uploads/school/' . $filename;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return 'uploads/school/' . $filename;
    }
    return null;
}

/**
 * Helper deteksi & sanitasi input tanda tangan elektronik (SVG / Clear / Upload File)
 */
function processSignatureField($fieldKey, $prefix, $currentValue) {
    // 1. Cek tombol hapus/reset tanda tangan
    if (!empty($_POST[$fieldKey . '_clear']) && $_POST[$fieldKey . '_clear'] === '1') {
        return null;
    }
    // 2. Cek input corat-coret digital (SVG)
    $svgInput = trim($_POST[$fieldKey . '_svg'] ?? '');
    if (!empty($svgInput) && strpos($svgInput, '<svg') !== false && strpos($svgInput, '</svg>') !== false) {
        return $svgInput;
    }
    // 3. Cek apakah ada file upload manual (PNG/JPG/WebP/SVG)
    $uploadedFile = uploadSchoolFile($fieldKey, $prefix);
    if ($uploadedFile !== null) {
        return $uploadedFile;
    }
    // 4. Default: tetap gunakan data sebelumnya
    return $currentValue;
}

/**
 * Helper merender Komponen UI Tanda Tangan Elektronik Interaktif (Canvas & SVG)
 */
function renderSignaturePadUI($fieldKey, $label, $currentValue) {
    $hasValue = !empty($currentValue);
    $isSvg    = $hasValue && (strpos($currentValue, '<svg') !== false);
    $isImage  = $hasValue && !$isSvg;
    $canvasId = 'canvas_' . $fieldKey;
    $previewBoxId = 'preview_box_' . $fieldKey;
    $canvasWrapId = 'canvas_wrap_' . $fieldKey;
    $clearInpId   = 'inp_' . $fieldKey . '_clear';
    $svgInpId     = 'inp_' . $fieldKey . '_svg';

    ob_start();
    ?>
    <div class="form-field" style="margin-top: 10px;">
        <label class="form-label" style="display:flex; justify-content:space-between; align-items:center;">
            <span><?= htmlspecialchars($label) ?></span>
            <span style="font-size: 11px; font-weight: 600; color: #0D7A87;">
                <iconify-icon icon="solar:pen-new-square-bold" width="13" style="vertical-align:middle;"></iconify-icon>
                Format Vektor SVG (Anti-Pecah &amp; Transparan)
            </span>
        </label>

        <div class="sig-card-container">
            <!-- Hidden inputs untuk form submit -->
            <input type="hidden" name="<?= $fieldKey ?>_svg" id="<?= $svgInpId ?>" value="">
            <input type="hidden" name="<?= $fieldKey ?>_clear" id="<?= $clearInpId ?>" value="0">

            <!-- 1. KOTAK PRATINJAU JIKA SUDAH ADA TTD -->
            <div id="<?= $previewBoxId ?>" class="sig-preview-box" style="<?= $hasValue ? '' : 'display:none;' ?>">
                <div style="flex:1;">
                    <div style="display:flex; align-items:center; gap:6px; margin-bottom:6px;">
                        <span class="badge" style="background:#ECFDF5; color:#065F46; border:1px solid #A7F3D0; font-size:10px; font-weight:700; padding:2px 7px; border-radius:6px;">
                            ✓ Tanda Tangan Aktif
                        </span>
                        <span style="font-size:11px; color:#64748B;">
                            <?= $isSvg ? 'Tanda Tangan Elektronik Vektor (SVG)' : 'Berkas Gambar TTD' ?>
                        </span>
                    </div>
                    <div class="sig-preview-display">
                        <div id="preview_content_<?= $fieldKey ?>" style="display:flex; align-items:center; justify-content:center; width:100%; height:100%;">
                            <?php if ($isSvg): ?>
                                <?= $currentValue ?>
                            <?php elseif ($isImage && file_exists(BASE_PATH . '/' . $currentValue)): ?>
                                <img src="<?= BASE_URL . '/' . htmlspecialchars($currentValue) ?>" alt="TTD">
                            <?php elseif ($isImage): ?>
                                <span style="font-size:11px; color:#94A3B8;">Berkas: <?= htmlspecialchars($currentValue) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div style="display:flex; flex-direction:column; gap:6px; margin-left:14px;">
                    <button type="button" class="ilmuna-btn ilmuna-btn--sm ilmuna-btn--outline" onclick="openSignatureCanvas('<?= $fieldKey ?>')" style="font-size:11px; padding:6px 10px;">
                        <iconify-icon icon="solar:pen-bold" width="13"></iconify-icon>
                        <span>Coret Ulang Baru</span>
                    </button>
                    <button type="button" class="ilmuna-btn ilmuna-btn--sm" onclick="clearExistingSignature('<?= $fieldKey ?>')" style="background:#FEE2E2; border:1px solid #FECACA; color:#B91C1C; font-size:11px; padding:6px 10px;">
                        <iconify-icon icon="solar:trash-bin-trash-bold" width="13"></iconify-icon>
                        <span>Hapus TTD</span>
                    </button>
                </div>
            </div>

            <!-- 2. KOTAK KANVAS CORAT-CORET DIGITAL -->
            <div id="<?= $canvasWrapId ?>" style="<?= $hasValue ? 'display:none;' : '' ?>">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                    <span style="font-size:11.5px; font-weight:700; color:#1E293B; display:flex; align-items:center; gap:5px;">
                        <iconify-icon icon="solar:pen-bold" width="14" style="color:#0D7A87;"></iconify-icon>
                        <span>Coret Tanda Tangan di Kotak Berikut:</span>
                    </span>
                    <span style="font-size:10.5px; color:#64748B;">Mendukung Mouse, Touchscreen &amp; Stylus Pen</span>
                </div>

                <div class="sig-canvas-wrapper">
                    <canvas id="<?= $canvasId ?>" class="sig-canvas" width="460" height="150" data-field="<?= $fieldKey ?>"></canvas>
                    <div class="sig-baseline-guide">
                        <span class="sig-baseline-text">Garis Batas Tanda Tangan</span>
                    </div>
                </div>

                <div class="sig-toolbar">
                    <div class="sig-color-picker">
                        <span style="font-size:11px; color:#64748B; font-weight:600;">Warna Tinta:</span>
                        <button type="button" class="sig-color-btn active" style="background:#1E3A8A;" title="Biru Tinta Dokumen Resmi" onclick="setSigPadColor('<?= $fieldKey ?>', '#1E3A8A', this)"></button>
                        <button type="button" class="sig-color-btn" style="background:#0F172A;" title="Hitam Formal" onclick="setSigPadColor('<?= $fieldKey ?>', '#0F172A', this)"></button>
                    </div>

                    <div style="display:flex; gap:6px;">
                        <button type="button" class="ilmuna-btn ilmuna-btn--sm ilmuna-btn--outline" onclick="resetSigCanvas('<?= $fieldKey ?>')" style="font-size:11px; padding:5px 9px;">
                            <iconify-icon icon="solar:restart-bold" width="13"></iconify-icon>
                            <span>Bersihkan Coretan</span>
                        </button>
                        <?php if ($hasValue): ?>
                            <button type="button" class="ilmuna-btn ilmuna-btn--sm ilmuna-btn--outline" onclick="cancelSigCanvas('<?= $fieldKey ?>')" style="font-size:11px; padding:5px 9px; color:#64748B;">
                                <span>Batal</span>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- OPSI MANUAL UPLOAD FILE GAMBAR (OPSIONAL) -->
            <div style="margin-top:10px; border-top:1px dashed #E2E8F0; padding-top:8px;">
                <details style="font-size:11px; color:#64748B;">
                    <summary style="cursor:pointer; font-weight:600; color:#0D7A87;">
                        <span>Atau unggah file gambar TTD manual (PNG transparan)</span>
                    </summary>
                    <div style="margin-top:6px;">
                        <input type="file" name="<?= $fieldKey ?>" accept="image/png,image/jpeg,image/webp,image/svg+xml" class="form-control" style="padding:4px; font-size:11px;">
                    </div>
                </details>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// -----------------------------------------------------------------------------
// 1. AMBIL ATAU INISIALISASI DATA TUNGGAL SEKOLAH (Single Source of Truth)
// -----------------------------------------------------------------------------
$sekolah = $pdo->query("SELECT * FROM identitas_sekolah ORDER BY is_active DESC, id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$sekolah) {
    $pdo->exec("
        INSERT INTO identitas_sekolah (
            nama_sekolah, nama_singkat, jenjang, status_sekolah, akreditasi,
            kurikulum_utama, titimangsa_tempat, tahun_pelajaran_aktif, semester_aktif, is_active
        ) VALUES (
            'Nama Sekolah Anda', 'Sekolah', 'SMA', 'Swasta', 'A',
            'Kurikulum Merdeka', 'Bojonegoro', '2026/2027', 'Ganjil', 1
        )
    ");
    $sekolah = $pdo->query("SELECT * FROM identitas_sekolah ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}
$schoolId = (int)$sekolah['id'];

// -----------------------------------------------------------------------------
// 2. PROSES SIMPAN / UPDATE PERUBAHAN
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_identitas') {
    $namaSekolah = trim($_POST['nama_sekolah'] ?? '');
    if (empty($namaSekolah)) {
        $_SESSION['flash'] = ['type' => 'error', 'title' => 'Gagal', 'msg' => 'Nama resmi sekolah wajib diisi.'];
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    try {
        // Handle file uploads (jika tidak upload baru, tetap gunakan yang lama)
        $logoSekolah     = uploadSchoolFile('logo_sekolah', 'logo') ?? $sekolah['logo_sekolah'];
        $logoKementerian = uploadSchoolFile('logo_kementerian', 'logo_kemen') ?? $sekolah['logo_kementerian'];
        $kopSuratPath    = uploadSchoolFile('kop_surat_path', 'kop') ?? $sekolah['kop_surat_path'];
        $capStempelPath  = uploadSchoolFile('cap_stempel_path', 'stempel') ?? $sekolah['cap_stempel_path'];
        
        // Handle Tanda Tangan Elektronik (SVG Canvas / Clear / File Upload)
        $ttdKepsek       = processSignatureField('ttd_kepala_sekolah', 'ttd_ks', $sekolah['ttd_kepala_sekolah'] ?? null);
        $ttdKurikulum    = processSignatureField('ttd_waka_kurikulum', 'ttd_kur', $sekolah['ttd_waka_kurikulum'] ?? null);
        $ttdKesiswaan    = processSignatureField('ttd_waka_kesiswaan', 'ttd_kes', $sekolah['ttd_waka_kesiswaan'] ?? null);
        $ttdSarpras      = processSignatureField('ttd_waka_sarpras', 'ttd_sar', $sekolah['ttd_waka_sarpras'] ?? null);
        $ttdHumas        = processSignatureField('ttd_waka_humas', 'ttd_hum', $sekolah['ttd_waka_humas'] ?? null);
        $ttdTu           = processSignatureField('ttd_kepala_tu', 'ttd_tu', $sekolah['ttd_kepala_tu'] ?? null);
        $ttdBendahara    = processSignatureField('ttd_bendahara', 'ttd_ben', $sekolah['ttd_bendahara'] ?? null);

        $stmt = $pdo->prepare("
            UPDATE identitas_sekolah SET
                nama_sekolah = ?, nama_singkat = ?, npsn = ?, nss = ?, nds = ?, jenjang = ?, status_sekolah = ?, akreditasi = ?, kurikulum_utama = ?,
                sk_pendirian = ?, tanggal_sk_pendirian = ?, sk_izin_operasional = ?, tanggal_sk_izin_operasional = ?, kementerian_pembina = ?,
                alamat_jalan = ?, rt = ?, rw = ?, dusun_lingkungan = ?, desa_kelurahan = ?, kecamatan = ?, kabupaten_kota = ?, provinsi = ?, kode_pos = ?,
                latitude = ?, longitude = ?, radius_presensi_meter = ?,
                telepon = ?, no_hp_wa = ?, fax = ?, email = ?, website = ?, instagram = ?, facebook = ?, youtube = ?, tiktok = ?,
                logo_sekolah = ?, logo_kementerian = ?, kop_surat_path = ?, cap_stempel_path = ?, semboyan_motto = ?, visi = ?, misi = ?,
                nama_kepala_sekolah = ?, gelar_kepala_sekolah = ?, nip_kepala_sekolah = ?, nuptk_kepala_sekolah = ?, ttd_kepala_sekolah = ?,
                nama_waka_kurikulum = ?, nip_waka_kurikulum = ?, nuptk_waka_kurikulum = ?, no_hp_waka_kurikulum = ?, ttd_waka_kurikulum = ?,
                nama_waka_kesiswaan = ?, nip_waka_kesiswaan = ?, nuptk_waka_kesiswaan = ?, no_hp_waka_kesiswaan = ?, ttd_waka_kesiswaan = ?,
                nama_waka_sarpras = ?, nip_waka_sarpras = ?, nuptk_waka_sarpras = ?, no_hp_waka_sarpras = ?, ttd_waka_sarpras = ?,
                nama_waka_humas = ?, nip_waka_humas = ?, nuptk_waka_humas = ?, no_hp_waka_humas = ?, ttd_waka_humas = ?,
                nama_kepala_tu = ?, nip_kepala_tu = ?, nuptk_kepala_tu = ?, no_hp_kepala_tu = ?, ttd_kepala_tu = ?,
                nama_bendahara = ?, nip_bendahara = ?, nuptk_bendahara = ?, no_hp_bendahara = ?, ttd_bendahara = ?,
                nama_proktor_cbt = ?, nip_proktor_cbt = ?, no_hp_proktor_cbt = ?,
                titimangsa_tempat = ?, tahun_pelajaran_aktif = ?, semester_aktif = ?, is_active = 1
            WHERE id = ?
        ");

        $stmt->execute([
            $namaSekolah,
            trim($_POST['nama_singkat'] ?? '') ?: null,
            trim($_POST['npsn'] ?? '') ?: null,
            trim($_POST['nss'] ?? '') ?: null,
            trim($_POST['nds'] ?? '') ?: null,
            $_POST['jenjang'] ?? 'SMA',
            $_POST['status_sekolah'] ?? 'Swasta',
            $_POST['akreditasi'] ?? 'A',
            trim($_POST['kurikulum_utama'] ?? '') ?: 'Kurikulum Merdeka',

            trim($_POST['sk_pendirian'] ?? '') ?: null,
            !empty($_POST['tanggal_sk_pendirian']) ? $_POST['tanggal_sk_pendirian'] : null,
            trim($_POST['sk_izin_operasional'] ?? '') ?: null,
            !empty($_POST['tanggal_sk_izin_operasional']) ? $_POST['tanggal_sk_izin_operasional'] : null,
            $_POST['kementerian_pembina'] ?? 'Kemendikbudristek',

            trim($_POST['alamat_jalan'] ?? '') ?: null,
            trim($_POST['rt'] ?? '') ?: null,
            trim($_POST['rw'] ?? '') ?: null,
            trim($_POST['dusun_lingkungan'] ?? '') ?: null,
            trim($_POST['desa_kelurahan'] ?? '') ?: null,
            trim($_POST['kecamatan'] ?? '') ?: null,
            trim($_POST['kabupaten_kota'] ?? '') ?: null,
            trim($_POST['provinsi'] ?? '') ?: null,
            trim($_POST['kode_pos'] ?? '') ?: null,

            !empty($_POST['latitude']) ? (float)$_POST['latitude'] : null,
            !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null,
            !empty($_POST['radius_presensi_meter']) ? (int)$_POST['radius_presensi_meter'] : 100,

            trim($_POST['telepon'] ?? '') ?: null,
            trim($_POST['no_hp_wa'] ?? '') ?: null,
            trim($_POST['fax'] ?? '') ?: null,
            trim($_POST['email'] ?? '') ?: null,
            trim($_POST['website'] ?? '') ?: null,
            trim($_POST['instagram'] ?? '') ?: null,
            trim($_POST['facebook'] ?? '') ?: null,
            trim($_POST['youtube'] ?? '') ?: null,
            trim($_POST['tiktok'] ?? '') ?: null,

            $logoSekolah,
            $logoKementerian,
            $kopSuratPath,
            $capStempelPath,
            trim($_POST['semboyan_motto'] ?? '') ?: null,
            trim($_POST['visi'] ?? '') ?: null,
            trim($_POST['misi'] ?? '') ?: null,

            trim($_POST['nama_kepala_sekolah'] ?? '') ?: null,
            trim($_POST['gelar_kepala_sekolah'] ?? '') ?: null,
            trim($_POST['nip_kepala_sekolah'] ?? '') ?: null,
            trim($_POST['nuptk_kepala_sekolah'] ?? '') ?: null,
            $ttdKepsek,

            trim($_POST['nama_waka_kurikulum'] ?? '') ?: null,
            trim($_POST['nip_waka_kurikulum'] ?? '') ?: null,
            trim($_POST['nuptk_waka_kurikulum'] ?? '') ?: null,
            trim($_POST['no_hp_waka_kurikulum'] ?? '') ?: null,
            $ttdKurikulum,

            trim($_POST['nama_waka_kesiswaan'] ?? '') ?: null,
            trim($_POST['nip_waka_kesiswaan'] ?? '') ?: null,
            trim($_POST['nuptk_waka_kesiswaan'] ?? '') ?: null,
            trim($_POST['no_hp_waka_kesiswaan'] ?? '') ?: null,
            $ttdKesiswaan,

            trim($_POST['nama_waka_sarpras'] ?? '') ?: null,
            trim($_POST['nip_waka_sarpras'] ?? '') ?: null,
            trim($_POST['nuptk_waka_sarpras'] ?? '') ?: null,
            trim($_POST['no_hp_waka_sarpras'] ?? '') ?: null,
            $ttdSarpras,

            trim($_POST['nama_waka_humas'] ?? '') ?: null,
            trim($_POST['nip_waka_humas'] ?? '') ?: null,
            trim($_POST['nuptk_waka_humas'] ?? '') ?: null,
            trim($_POST['no_hp_waka_humas'] ?? '') ?: null,
            $ttdHumas,

            trim($_POST['nama_kepala_tu'] ?? '') ?: null,
            trim($_POST['nip_kepala_tu'] ?? '') ?: null,
            trim($_POST['nuptk_kepala_tu'] ?? '') ?: null,
            trim($_POST['no_hp_kepala_tu'] ?? '') ?: null,
            $ttdTu,

            trim($_POST['nama_bendahara'] ?? '') ?: null,
            trim($_POST['nip_bendahara'] ?? '') ?: null,
            trim($_POST['nuptk_bendahara'] ?? '') ?: null,
            trim($_POST['no_hp_bendahara'] ?? '') ?: null,
            $ttdBendahara,

            trim($_POST['nama_proktor_cbt'] ?? '') ?: null,
            trim($_POST['nip_proktor_cbt'] ?? '') ?: null,
            trim($_POST['no_hp_proktor_cbt'] ?? '') ?: null,

            trim($_POST['titimangsa_tempat'] ?? '') ?: 'Bojonegoro',
            trim($_POST['tahun_pelajaran_aktif'] ?? '') ?: '2026/2027',
            $_POST['semester_aktif'] ?? 'Ganjil',
            $schoolId
        ]);

        $_SESSION['flash'] = ['type' => 'success', 'title' => 'Berhasil Disimpan', 'msg' => 'Seluruh data profil identitas sekolah berhasil diperbarui.'];
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'error', 'title' => 'Gagal', 'msg' => 'Gagal menyimpan perubahan: ' . $e->getMessage()];
    }

    $activeTab = $_POST['active_tab'] ?? 'identitas';
    header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '', '?') . '?tab=' . urlencode($activeTab));
    exit;
}

// -----------------------------------------------------------------------------
// 3. MASTER DATA PENDUKUNG
// -----------------------------------------------------------------------------
$guruList = $pdo->query("SELECT id, nip, nuptk, nama_guru, no_hp FROM guru ORDER BY nama_guru ASC")->fetchAll(PDO::FETCH_ASSOC);
$geofenceAktif = $pdo->query("SELECT * FROM titik_koordinat_sekolah WHERE is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$currentTab = $_GET['tab'] ?? 'identitas';
if (!in_array($currentTab, ['identitas', 'alamat', 'dokumen', 'kepsek', 'waka', 'rapor'], true)) {
    $currentTab = 'identitas';
}

$extraHead = '
<style>
/* ==========================================================================
 * IDENTITAS SEKOLAH — FULL SETTINGS / PROFILE PAGE (LUMINA THEME)
 * ========================================================================== */
.school-page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 22px;
}
.school-header-left {
    display: flex;
    align-items: center;
    gap: 14px;
}
.school-header-icon {
    width: 48px; height: 48px;
    border-radius: 14px;
    background: linear-gradient(135deg, #0D7A87 0%, #1D3B5E 100%);
    color: #FFFFFF;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 8px 20px -6px rgba(13, 122, 135, 0.4);
    flex-shrink: 0;
}
.school-header-title {
    font-size: 20px;
    font-weight: 800;
    color: #1E293B;
    margin: 0 0 2px;
    letter-spacing: -0.01em;
}
.school-header-subtitle {
    font-size: 13px;
    color: #64748B;
    margin: 0;
}

/* SEGMENTED TAB NAV */
.school-tabs-nav {
    display: flex;
    align-items: center;
    background: #FFFFFF;
    border: 1px solid #E2E8F0;
    border-radius: 14px;
    padding: 6px;
    gap: 4px;
    margin-bottom: 22px;
    box-shadow: 0 2px 10px -4px rgba(15,23,42,0.06);
    overflow-x: auto;
    scrollbar-width: none;
}
.school-tabs-nav::-webkit-scrollbar { display: none; }
.school-tab-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    border-radius: 10px;
    border: none;
    background: transparent;
    font-family: inherit;
    font-size: 13px;
    font-weight: 700;
    color: #64748B;
    cursor: pointer;
    white-space: nowrap;
    text-decoration: none !important;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}
.school-tab-link:hover {
    color: #0D7A87;
    background: rgba(13, 122, 135, 0.06);
}
.school-tab-link.active {
    background: linear-gradient(135deg, #0D7A87 0%, #1D3B5E 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 14px -3px rgba(13, 122, 135, 0.4);
}

/* MAIN FORM CARD */
.school-main-card {
    background: #FFFFFF;
    border: 1px solid #E2E8F0;
    border-radius: 18px;
    box-shadow: 0 4px 20px -8px rgba(15,23,42,0.08);
    overflow: hidden;
}
.school-card-head {
    padding: 18px 24px;
    background: #F8FAFC;
    border-bottom: 1px solid #E2E8F0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}
.school-card-head-title {
    font-size: 15px;
    font-weight: 800;
    color: #1E293B;
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
}
.school-card-head-desc {
    font-size: 12px;
    color: #64748B;
    margin: 2px 0 0;
}
.school-card-body {
    padding: 24px 28px;
}

/* SECTION HEADINGS */
.section-heading {
    font-size: 13px;
    font-weight: 800;
    color: #0D7A87;
    margin: 24px 0 14px;
    padding-bottom: 8px;
    border-bottom: 1.5px dashed #CCFBF1;
    display: flex;
    align-items: center;
    gap: 8px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.section-heading:first-child {
    margin-top: 0;
}

/* FORM GRID & CONTROLS */
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
.grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
.grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
.form-field { margin-bottom: 16px; }
.form-field:last-child { margin-bottom: 0; }
.form-label {
    display: block;
    font-size: 12px;
    font-weight: 700;
    color: #1E293B;
    margin-bottom: 6px;
}
.form-label .req { color: #EF4444; margin-left: 2px; }
.form-label .hint { font-weight: 400; color: #94A3B8; font-size: 11px; margin-left: 4px; }
.form-control {
    width: 100%;
    padding: 10px 14px;
    border: 1.5px solid #CBD5E1;
    border-radius: 10px;
    font-family: inherit;
    font-size: 13px;
    font-weight: 500;
    color: #1E293B;
    background: #FFFFFF;
    outline: none;
    box-sizing: border-box;
    transition: all 0.18s ease;
}
.form-control:focus {
    border-color: #0D7A87;
    box-shadow: 0 0 0 3px rgba(13, 122, 135, 0.12);
}
textarea.form-control {
    resize: vertical;
    min-height: 80px;
}

/* FILE UPLOAD & PREVIEW BOX */
.upload-preview-card {
    border: 1.5px dashed #CBD5E1;
    border-radius: 12px;
    padding: 14px 16px;
    background: #F8FAFC;
    display: flex;
    align-items: center;
    gap: 16px;
    transition: border-color 0.18s ease;
}
.upload-preview-card:hover {
    border-color: #0D7A87;
}
.upload-thumb {
    width: 64px; height: 64px;
    border-radius: 10px;
    border: 1px solid #E2E8F0;
    background: #FFFFFF;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    flex-shrink: 0;
    box-shadow: 0 2px 6px rgba(0,0,0,0.06);
}
.upload-thumb img {
    max-width: 100%; max-height: 100%;
    object-fit: contain;
}
.upload-meta {
    flex: 1;
    min-width: 0;
}
.upload-meta-title {
    font-size: 12.5px;
    font-weight: 700;
    color: #1E293B;
    margin-bottom: 2px;
}
.upload-meta-desc {
    font-size: 11px;
    color: #64748B;
    margin-bottom: 6px;
}

/* AUTO FILL BOX */
.auto-fill-box {
    background: #F0FDFA;
    border: 1.5px dashed #99F6E4;
    border-radius: 10px;
    padding: 10px 14px;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.auto-fill-label {
    font-size: 11.5px;
    font-weight: 700;
    color: #0F766E;
    white-space: nowrap;
    display: flex;
    align-items: center;
    gap: 6px;
}

/* ELECTRONIC SIGNATURE PAD STYLING */
.sig-card-container {
    background: #FFFFFF;
    border: 1.5px solid #E2E8F0;
    border-radius: 12px;
    padding: 14px;
    margin-top: 6px;
    transition: all 0.2s ease;
}
.sig-preview-box {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #F8FAFC;
    border: 1.5px solid #E2E8F0;
    border-radius: 10px;
    padding: 12px 16px;
    gap: 16px;
    flex-wrap: wrap;
}
.sig-preview-display {
    max-height: 80px;
    min-height: 55px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #FFFFFF;
    border: 1px dashed #CBD5E1;
    border-radius: 8px;
    padding: 6px 14px;
    flex: 1;
    overflow: hidden;
}
.sig-preview-display svg {
    max-height: 65px;
    max-width: 100%;
    width: auto;
    height: auto;
    display: block;
}
.sig-preview-display img {
    max-height: 65px;
    max-width: 180px;
    object-fit: contain;
}
.sig-canvas-wrapper {
    position: relative;
    background: #FFFFFF;
    border: 2px dashed #94A3B8;
    border-radius: 10px;
    overflow: hidden;
    touch-action: none;
    box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);
}
.sig-canvas-wrapper:hover {
    border-color: #0D7A87;
}
.sig-canvas {
    width: 100%;
    height: 150px;
    display: block;
    cursor: crosshair;
    background-color: #FFFFFF;
}
.sig-baseline-guide {
    position: absolute;
    left: 20px;
    right: 20px;
    bottom: 35px;
    border-bottom: 1px dashed #E2E8F0;
    pointer-events: none;
    display: flex;
    justify-content: flex-end;
}
.sig-baseline-text {
    font-size: 9.5px;
    color: #94A3B8;
    font-weight: 600;
    margin-top: 2px;
}
.sig-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 8px;
    flex-wrap: wrap;
    gap: 8px;
}
.sig-color-picker {
    display: flex;
    align-items: center;
    gap: 6px;
}
.sig-color-btn {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    border: 2px solid #FFFFFF;
    box-shadow: 0 0 0 1px #CBD5E1;
    cursor: pointer;
    transition: transform 0.15s ease;
}
.sig-color-btn.active {
    box-shadow: 0 0 0 2.5px #0D7A87;
    transform: scale(1.15);
}

/* BUTTONS */
.btn-save-main {
    background: linear-gradient(135deg, #0D7A87 0%, #1D3B5E 100%);
    color: #FFFFFF;
    border: none;
    padding: 10px 22px;
    border-radius: 10px;
    font-family: inherit;
    font-size: 13.5px;
    font-weight: 800;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 14px -3px rgba(13, 122, 135, 0.5);
    transition: all 0.2s ease;
}
.btn-save-main:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 20px -4px rgba(13, 122, 135, 0.6);
}

.school-card-foot {
    padding: 18px 28px;
    background: #F8FAFC;
    border-top: 1px solid #E2E8F0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

@media (max-width: 860px) {
    .grid-2, .grid-3, .grid-4 { grid-template-columns: 1fr; }
    .school-card-body { padding: 18px 16px; }
}
</style>';

require_once BASE_PATH . '/includes/header.php';
loadCentralTemplates(['modal', 'calendar', 'table']);
?>

<div style="padding: 24px; max-width: 1200px; margin: 0 auto;">

    <!-- FLASH NOTIFIKASI -->
    <?php if (isset($_SESSION['flash'])): $f = $_SESSION['flash']; unset($_SESSION['flash']); ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var flashType = '<?= ($f['type'] === 'success') ? 'success' : 'error' ?>';
            var flashTitle = <?= json_encode($f['title']) ?>;
            var flashMsg = <?= json_encode($f['msg']) ?>;
            if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
                Ilmuna.toast(flashType, flashTitle, flashMsg, 5000);
            } else if (typeof showToast === 'function') {
                showToast(flashType === 'error' ? 'danger' : flashType, flashMsg, flashTitle);
            }
        });
        </script>
        <div style="padding: 14px 18px; border-radius: 12px; margin-bottom: 22px; display: flex; align-items: center; gap: 12px; font-size: 13.5px; font-weight: 600; box-shadow: 0 4px 12px rgba(0,0,0,0.05); <?= ($f['type'] === 'success') ? 'background: #ECFDF5; color: #065F46; border: 1px solid #A7F3D0;' : 'background: #FEF2F2; color: #991B1B; border: 1px solid #FECACA;' ?>">
            <iconify-icon icon="<?= ($f['type'] === 'success') ? 'solar:check-circle-bold' : 'solar:danger-circle-bold' ?>" width="22"></iconify-icon>
            <div>
                <strong><?= htmlspecialchars($f['title']) ?>:</strong> <?= htmlspecialchars($f['msg']) ?>
            </div>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="formSchoolProfile">
        <input type="hidden" name="action" value="save_identitas">
        <input type="hidden" name="active_tab" id="activeTabInput" value="<?= htmlspecialchars($currentTab) ?>">

        <!-- ACTION TOOLBAR (DATA-FIRST ZERO TITLE) -->
        <div style="display: flex; justify-content: flex-end; align-items: center; margin-bottom: 14px;">
            <button type="submit" class="btn-save-main">
                <iconify-icon icon="solar:disk-bold" width="18"></iconify-icon>
                <span>Simpan Perubahan Identitas</span>
            </button>
        </div>

        <!-- TABS NAVIGASI UTAMA -->
        <div class="school-tabs-nav">
            <button type="button" class="school-tab-link <?= $currentTab === 'identitas' ? 'active' : '' ?>" onclick="switchTab('identitas')">
                <iconify-icon icon="solar:buildings-2-bold" width="16"></iconify-icon>
                <span>1. Identitas &amp; Legalitas</span>
            </button>
            <button type="button" class="school-tab-link <?= $currentTab === 'alamat' ? 'active' : '' ?>" onclick="switchTab('alamat')">
                <iconify-icon icon="solar:map-point-wave-bold" width="16"></iconify-icon>
                <span>2. Alamat &amp; Kontak</span>
            </button>
            <button type="button" class="school-tab-link <?= $currentTab === 'dokumen' ? 'active' : '' ?>" onclick="switchTab('dokumen')">
                <iconify-icon icon="solar:gallery-bold" width="16"></iconify-icon>
                <span>3. Logo, Kop &amp; Stempel</span>
            </button>
            <button type="button" class="school-tab-link <?= $currentTab === 'kepsek' ? 'active' : '' ?>" onclick="switchTab('kepsek')">
                <iconify-icon icon="solar:user-bold" width="16"></iconify-icon>
                <span>4. Kepala Sekolah</span>
            </button>
            <button type="button" class="school-tab-link <?= $currentTab === 'waka' ? 'active' : '' ?>" onclick="switchTab('waka')">
                <iconify-icon icon="solar:users-group-rounded-bold" width="16"></iconify-icon>
                <span>5. Jajaran Waka &amp; TU</span>
            </button>
            <button type="button" class="school-tab-link <?= $currentTab === 'rapor' ? 'active' : '' ?>" onclick="switchTab('rapor')">
                <iconify-icon icon="solar:settings-bold" width="16"></iconify-icon>
                <span>6. Parameter Dokumen</span>
            </button>
        </div>

        <!-- MAIN CARD CONTAINER -->
        <div class="school-main-card">

            <!-- ===============================================================
             * TAB 1: IDENTITAS & LEGALITAS
             * =============================================================== -->
            <div class="tab-section" id="section-identitas" style="<?= $currentTab === 'identitas' ? '' : 'display:none;' ?>">
                <div class="school-card-head">
                    <div>
                        <h3 class="school-card-head-title">
                            <iconify-icon icon="solar:buildings-2-bold" width="18" style="color: #0D7A87;"></iconify-icon>
                            <span>Data Pokok Satuan Pendidikan &amp; Legalitas Resmi</span>
                        </h3>
                        <p class="school-card-head-desc">Identitas resmi sekolah sesuai data izin operasional dan Dapodik/EMIS.</p>
                    </div>
                </div>
                <div class="school-card-body">
                    <div class="grid-2">
                        <div class="form-field">
                            <label class="form-label">Nama Resmi Sekolah Lengkap <span class="req">*</span></label>
                            <input type="text" name="nama_sekolah" class="form-control" value="<?= htmlspecialchars($sekolah['nama_sekolah'] ?? '') ?>" placeholder="misal: SMA Plus Al-Hikmah Bojonegoro" required>
                        </div>
                        <div class="form-field">
                            <label class="form-label">Nama Singkatan / Alias Lembaga</label>
                            <input type="text" name="nama_singkat" class="form-control" value="<?= htmlspecialchars($sekolah['nama_singkat'] ?? '') ?>" placeholder="misal: SMA Plus Al-Hikmah">
                        </div>
                    </div>

                    <div class="grid-3">
                        <div class="form-field">
                            <label class="form-label">NPSN (8 Digit)</label>
                            <input type="text" name="npsn" class="form-control" value="<?= htmlspecialchars($sekolah['npsn'] ?? '') ?>" placeholder="20512345">
                        </div>
                        <div class="form-field">
                            <label class="form-label">NSS (Nomor Statistik Sekolah)</label>
                            <input type="text" name="nss" class="form-control" value="<?= htmlspecialchars($sekolah['nss'] ?? '') ?>" placeholder="302050501234">
                        </div>
                        <div class="form-field">
                            <label class="form-label">NDS (Nomor Data Sekolah)</label>
                            <input type="text" name="nds" class="form-control" value="<?= htmlspecialchars($sekolah['nds'] ?? '') ?>" placeholder="Opsional">
                        </div>
                    </div>

                    <div class="grid-3">
                        <div class="form-field">
                            <label class="form-label">Jenjang Pendidikan</label>
                            <select name="jenjang" class="form-control">
                                <?php foreach (['SD', 'MI', 'SMP', 'MTs', 'SMA', 'MA', 'SMK', 'MAK', 'PKBM', 'Perguruan Tinggi', 'Lainnya'] as $j): ?>
                                    <option value="<?= $j ?>" <?= (($sekolah['jenjang'] ?? 'SMA') === $j) ? 'selected' : '' ?>><?= $j ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-field">
                            <label class="form-label">Status Sekolah</label>
                            <select name="status_sekolah" class="form-control">
                                <option value="Swasta" <?= (($sekolah['status_sekolah'] ?? 'Swasta') === 'Swasta') ? 'selected' : '' ?>>Swasta</option>
                                <option value="Negeri" <?= (($sekolah['status_sekolah'] ?? '') === 'Negeri') ? 'selected' : '' ?>>Negeri</option>
                            </select>
                        </div>
                        <div class="form-field">
                            <label class="form-label">Peringkat Akreditasi</label>
                            <select name="akreditasi" class="form-control">
                                <option value="A" <?= (($sekolah['akreditasi'] ?? 'A') === 'A') ? 'selected' : '' ?>>Terakreditasi A (Unggul)</option>
                                <option value="B" <?= (($sekolah['akreditasi'] ?? '') === 'B') ? 'selected' : '' ?>>Terakreditasi B (Baik)</option>
                                <option value="C" <?= (($sekolah['akreditasi'] ?? '') === 'C') ? 'selected' : '' ?>>Terakreditasi C (Cukup)</option>
                                <option value="Belum Terakreditasi" <?= (($sekolah['akreditasi'] ?? '') === 'Belum Terakreditasi') ? 'selected' : '' ?>>Belum Terakreditasi</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid-2">
                        <div class="form-field">
                            <label class="form-label">Kurikulum Utama yang Diterapkan</label>
                            <input type="text" name="kurikulum_utama" class="form-control" value="<?= htmlspecialchars($sekolah['kurikulum_utama'] ?? 'Kurikulum Merdeka') ?>" placeholder="Kurikulum Merdeka">
                        </div>
                        <div class="form-field">
                            <label class="form-label">Kementerian Pembina / Naungan</label>
                            <select name="kementerian_pembina" class="form-control">
                                <option value="Kemendikbudristek" <?= (($sekolah['kementerian_pembina'] ?? 'Kemendikbudristek') === 'Kemendikbudristek') ? 'selected' : '' ?>>Kemendikbudristek (Dinas Pendidikan)</option>
                                <option value="Kemenag" <?= (($sekolah['kementerian_pembina'] ?? '') === 'Kemenag') ? 'selected' : '' ?>>Kementerian Agama (Kemenag)</option>
                                <option value="Kemenperin" <?= (($sekolah['kementerian_pembina'] ?? '') === 'Kemenperin') ? 'selected' : '' ?>>Kementerian Perindustrian (Kemenperin)</option>
                                <option value="Lainnya" <?= (($sekolah['kementerian_pembina'] ?? '') === 'Lainnya') ? 'selected' : '' ?>>Lainnya</option>
                            </select>
                        </div>
                    </div>

                    <div class="section-heading">
                        <iconify-icon icon="solar:document-text-bold" width="16"></iconify-icon>
                        <span>Surat Keputusan (SK) &amp; Izin Operasional</span>
                    </div>
                    <div class="grid-2">
                        <div class="form-field">
                            <label class="form-label">Nomor SK Pendirian Sekolah</label>
                            <input type="text" name="sk_pendirian" class="form-control" value="<?= htmlspecialchars($sekolah['sk_pendirian'] ?? '') ?>" placeholder="Nomor SK Pendirian">
                        </div>
                        <div class="form-field">
                            <label class="form-label">Tanggal SK Pendirian</label>
                            <input type="text" name="tanggal_sk_pendirian" class="form-control" data-ilm-calendar="date" readonly style="cursor: pointer; background: #FFF;" value="<?= htmlspecialchars($sekolah['tanggal_sk_pendirian'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="grid-2">
                        <div class="form-field">
                            <label class="form-label">Nomor SK Izin Operasional</label>
                            <input type="text" name="sk_izin_operasional" class="form-control" value="<?= htmlspecialchars($sekolah['sk_izin_operasional'] ?? '') ?>" placeholder="Nomor SK Izin Operasional">
                        </div>
                        <div class="form-field">
                            <label class="form-label">Tanggal SK Izin Operasional</label>
                            <input type="text" name="tanggal_sk_izin_operasional" class="form-control" data-ilm-calendar="date" readonly style="cursor: pointer; background: #FFF;" value="<?= htmlspecialchars($sekolah['tanggal_sk_izin_operasional'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===============================================================
             * TAB 2: ALAMAT & KONTAK
             * =============================================================== -->
            <div class="tab-section" id="section-alamat" style="<?= $currentTab === 'alamat' ? '' : 'display:none;' ?>">
                <div class="school-card-head">
                    <div>
                        <h3 class="school-card-head-title">
                            <iconify-icon icon="solar:map-point-wave-bold" width="18" style="color: #0D7A87;"></iconify-icon>
                            <span>Alamat Lengkap, Geolokasi Presensi &amp; Kontak Resmi</span>
                        </h3>
                        <p class="school-card-head-desc">Lokasi fisik lembaga, titik koordinat GPS presensi, dan nomor kontak resmi sekolah.</p>
                    </div>
                    <?php if ($geofenceAktif): ?>
                        <button type="button" class="ilmuna-btn ilmuna-btn--outline ilmuna-btn--sm" onclick="syncGeofencing()" style="background:#F0FDFA;border-color:#99F6E4;color:#0F766E;">
                            <iconify-icon icon="solar:map-point-bold" width="14"></iconify-icon>
                            <span>Salin dari Titik Geofencing: <?= htmlspecialchars($geofenceAktif['nama_lokasi']) ?></span>
                        </button>
                    <?php endif; ?>
                </div>
                <div class="school-card-body">
                    <div class="form-field">
                        <label class="form-label">Alamat Jalan / Gedung / Lingkungan</label>
                        <textarea name="alamat_jalan" id="inp_alamat_jalan" class="form-control" placeholder="Jl. Raya No..., RT/RW..."><?= htmlspecialchars($sekolah['alamat_jalan'] ?? '') ?></textarea>
                    </div>

                    <div class="grid-3">
                        <div class="form-field">
                            <label class="form-label">RT</label>
                            <input type="text" name="rt" class="form-control" value="<?= htmlspecialchars($sekolah['rt'] ?? '') ?>" placeholder="01">
                        </div>
                        <div class="form-field">
                            <label class="form-label">RW</label>
                            <input type="text" name="rw" class="form-control" value="<?= htmlspecialchars($sekolah['rw'] ?? '') ?>" placeholder="02">
                        </div>
                        <div class="form-field">
                            <label class="form-label">Dusun / Dukuh</label>
                            <input type="text" name="dusun_lingkungan" class="form-control" value="<?= htmlspecialchars($sekolah['dusun_lingkungan'] ?? '') ?>" placeholder="Dusun">
                        </div>
                    </div>

                    <div class="grid-3">
                        <div class="form-field">
                            <label class="form-label">Desa / Kelurahan</label>
                            <input type="text" name="desa_kelurahan" id="inp_desa_kelurahan" class="form-control" value="<?= htmlspecialchars($sekolah['desa_kelurahan'] ?? '') ?>" placeholder="Sukorejo">
                        </div>
                        <div class="form-field">
                            <label class="form-label">Kecamatan</label>
                            <input type="text" name="kecamatan" id="inp_kecamatan" class="form-control" value="<?= htmlspecialchars($sekolah['kecamatan'] ?? '') ?>" placeholder="Bojonegoro">
                        </div>
                        <div class="form-field">
                            <label class="form-label">Kabupaten / Kota</label>
                            <input type="text" name="kabupaten_kota" id="inp_kabupaten_kota" class="form-control" value="<?= htmlspecialchars($sekolah['kabupaten_kota'] ?? '') ?>" placeholder="Kabupaten Bojonegoro">
                        </div>
                    </div>

                    <div class="grid-2">
                        <div class="form-field">
                            <label class="form-label">Provinsi</label>
                            <input type="text" name="provinsi" id="inp_provinsi" class="form-control" value="<?= htmlspecialchars($sekolah['provinsi'] ?? '') ?>" placeholder="Jawa Timur">
                        </div>
                        <div class="form-field">
                            <label class="form-label">Kode Pos</label>
                            <input type="text" name="kode_pos" class="form-control" value="<?= htmlspecialchars($sekolah['kode_pos'] ?? '') ?>" placeholder="62115">
                        </div>
                    </div>

                    <div class="section-heading">
                        <iconify-icon icon="solar:radar-2-bold" width="16"></iconify-icon>
                        <span>Koordinat Geolokasi Presensi GPS</span>
                    </div>
                    <div class="grid-3">
                        <div class="form-field">
                            <label class="form-label">Latitude GPS</label>
                            <input type="text" name="latitude" id="inp_latitude" class="form-control" value="<?= htmlspecialchars($sekolah['latitude'] ?? '') ?>" placeholder="-7.15664000">
                        </div>
                        <div class="form-field">
                            <label class="form-label">Longitude GPS</label>
                            <input type="text" name="longitude" id="inp_longitude" class="form-control" value="<?= htmlspecialchars($sekolah['longitude'] ?? '') ?>" placeholder="111.88724000">
                        </div>
                        <div class="form-field">
                            <label class="form-label">Radius Toleransi Presensi (Meter)</label>
                            <input type="number" name="radius_presensi_meter" id="inp_radius_presensi_meter" class="form-control" value="<?= htmlspecialchars($sekolah['radius_presensi_meter'] ?? 100) ?>">
                        </div>
                    </div>

                    <div class="section-heading">
                        <iconify-icon icon="solar:phone-calling-bold" width="16"></iconify-icon>
                        <span>Kontak Resmi &amp; Media Sosial Sekolah</span>
                    </div>
                    <div class="grid-3">
                        <div class="form-field">
                            <label class="form-label">Telepon Kantor</label>
                            <input type="text" name="telepon" class="form-control" value="<?= htmlspecialchars($sekolah['telepon'] ?? '') ?>" placeholder="0353-888999">
                        </div>
                        <div class="form-field">
                            <label class="form-label">No. WhatsApp Resmi / Humas</label>
                            <input type="text" name="no_hp_wa" class="form-control" value="<?= htmlspecialchars($sekolah['no_hp_wa'] ?? '') ?>" placeholder="081234567890">
                        </div>
                        <div class="form-field">
                            <label class="form-label">Nomor Fax</label>
                            <input type="text" name="fax" class="form-control" value="<?= htmlspecialchars($sekolah['fax'] ?? '') ?>" placeholder="Opsional">
                        </div>
                    </div>

                    <div class="grid-2">
                        <div class="form-field">
                            <label class="form-label">Email Resmi Sekolah</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($sekolah['email'] ?? '') ?>" placeholder="info@alhikmah-bjn.sch.id">
                        </div>
                        <div class="form-field">
                            <label class="form-label">Website Resmi Lembaga</label>
                            <input type="text" name="website" class="form-control" value="<?= htmlspecialchars($sekolah['website'] ?? '') ?>" placeholder="https://alhikmah-bjn.sch.id">
                        </div>
                    </div>

                    <div class="grid-4">
                        <div class="form-field">
                            <label class="form-label">Instagram (@username)</label>
                            <input type="text" name="instagram" class="form-control" value="<?= htmlspecialchars($sekolah['instagram'] ?? '') ?>" placeholder="@alhikmah_official">
                        </div>
                        <div class="form-field">
                            <label class="form-label">Facebook Page</label>
                            <input type="text" name="facebook" class="form-control" value="<?= htmlspecialchars($sekolah['facebook'] ?? '') ?>" placeholder="Akun FB">
                        </div>
                        <div class="form-field">
                            <label class="form-label">Kanal YouTube</label>
                            <input type="text" name="youtube" class="form-control" value="<?= htmlspecialchars($sekolah['youtube'] ?? '') ?>" placeholder="Channel YouTube">
                        </div>
                        <div class="form-field">
                            <label class="form-label">Akun TikTok</label>
                            <input type="text" name="tiktok" class="form-control" value="<?= htmlspecialchars($sekolah['tiktok'] ?? '') ?>" placeholder="@tiktok_sekolah">
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===============================================================
             * TAB 3: LOGO & DOKUMEN CETAK
             * =============================================================== -->
            <div class="tab-section" id="section-dokumen" style="<?= $currentTab === 'dokumen' ? '' : 'display:none;' ?>">
                <div class="school-card-head">
                    <div>
                        <h3 class="school-card-head-title">
                            <iconify-icon icon="solar:gallery-bold" width="18" style="color: #0D7A87;"></iconify-icon>
                            <span>Aset Visual, Logo, Kop Surat, Stempel &amp; Motto</span>
                        </h3>
                        <p class="school-card-head-desc">Digunakan otomatis untuk kop surat rapor, kartu ujian CBT, sertifikat, dan dokumen resmi.</p>
                    </div>
                </div>
                <div class="school-card-body">
                    <div class="grid-2">
                        <div class="form-field">
                            <label class="form-label">Logo Resmi Sekolah</label>
                            <div class="upload-preview-card">
                                <div class="upload-thumb">
                                    <?php if (!empty($sekolah['logo_sekolah']) && file_exists(BASE_PATH . '/' . $sekolah['logo_sekolah'])): ?>
                                        <img src="<?= BASE_URL . '/' . htmlspecialchars($sekolah['logo_sekolah']) ?>" alt="Logo Sekolah">
                                    <?php else: ?>
                                        <iconify-icon icon="solar:diploma-bold" width="28" style="color: #94A3B8;"></iconify-icon>
                                    <?php endif; ?>
                                </div>
                                <div class="upload-meta">
                                    <div class="upload-meta-title">Berkas Logo Sekolah</div>
                                    <div class="upload-meta-desc">Format PNG, JPG, WebP transparan (disarankan 512x512 px).</div>
                                    <input type="file" name="logo_sekolah" accept="image/*" class="form-control" style="padding: 4px;">
                                </div>
                            </div>
                        </div>

                        <div class="form-field">
                            <label class="form-label">Logo Kementerian / Pemda</label>
                            <div class="upload-preview-card">
                                <div class="upload-thumb">
                                    <?php if (!empty($sekolah['logo_kementerian']) && file_exists(BASE_PATH . '/' . $sekolah['logo_kementerian'])): ?>
                                        <img src="<?= BASE_URL . '/' . htmlspecialchars($sekolah['logo_kementerian']) ?>" alt="Logo Kementerian">
                                    <?php else: ?>
                                        <iconify-icon icon="solar:shield-bold" width="28" style="color: #94A3B8;"></iconify-icon>
                                    <?php endif; ?>
                                </div>
                                <div class="upload-meta">
                                    <div class="upload-meta-title">Logo Tut Wuri / Kemenag / Pemda</div>
                                    <div class="upload-meta-desc">Untuk logo pendamping di sisi kiri/kanan kop surat.</div>
                                    <input type="file" name="logo_kementerian" accept="image/*" class="form-control" style="padding: 4px;">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="grid-2" style="margin-top: 14px;">
                        <div class="form-field">
                            <label class="form-label">Banner Kop Surat Siap Cetak (Header Rapor/Surat)</label>
                            <div class="upload-preview-card">
                                <div class="upload-thumb" style="width: 90px;">
                                    <?php if (!empty($sekolah['kop_surat_path']) && file_exists(BASE_PATH . '/' . $sekolah['kop_surat_path'])): ?>
                                        <img src="<?= BASE_URL . '/' . htmlspecialchars($sekolah['kop_surat_path']) ?>" alt="Kop Surat">
                                    <?php else: ?>
                                        <iconify-icon icon="solar:document-bold" width="28" style="color: #94A3B8;"></iconify-icon>
                                    <?php endif; ?>
                                </div>
                                <div class="upload-meta">
                                    <div class="upload-meta-title">Header Banner Kop Surat Resmi</div>
                                    <div class="upload-meta-desc">Gambar kop siap cetak (rasio lebar horizontal).</div>
                                    <input type="file" name="kop_surat_path" accept="image/*" class="form-control" style="padding: 4px;">
                                </div>
                            </div>
                        </div>

                        <div class="form-field">
                            <label class="form-label">Cap Stempel Basah Digital (PNG Transparan)</label>
                            <div class="upload-preview-card">
                                <div class="upload-thumb">
                                    <?php if (!empty($sekolah['cap_stempel_path']) && file_exists(BASE_PATH . '/' . $sekolah['cap_stempel_path'])): ?>
                                        <img src="<?= BASE_URL . '/' . htmlspecialchars($sekolah['cap_stempel_path']) ?>" alt="Stempel">
                                    <?php else: ?>
                                        <iconify-icon icon="solar:verified-check-bold" width="28" style="color: #94A3B8;"></iconify-icon>
                                    <?php endif; ?>
                                </div>
                                <div class="upload-meta">
                                    <div class="upload-meta-title">Stempel Basah Transparan</div>
                                    <div class="upload-meta-desc">PNG transparan tanpa background putih untuk ttd rapor.</div>
                                    <input type="file" name="cap_stempel_path" accept="image/*" class="form-control" style="padding: 4px;">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="section-heading">
                        <iconify-icon icon="solar:quote-up-bold" width="16"></iconify-icon>
                        <span>Semboyan, Visi &amp; Misi Satuan Pendidikan</span>
                    </div>
                    <div class="form-field">
                        <label class="form-label">Semboyan / Motto / Slogan Sekolah</label>
                        <input type="text" name="semboyan_motto" class="form-control" value="<?= htmlspecialchars($sekolah['semboyan_motto'] ?? '') ?>" placeholder="Cerdas, Berakhlak Mulia, dan Berdaya Saing Global">
                    </div>
                    <div class="grid-2">
                        <div class="form-field">
                            <label class="form-label">Visi Sekolah</label>
                            <textarea name="visi" class="form-control" placeholder="Tuliskan visi sekolah..."><?= htmlspecialchars($sekolah['visi'] ?? '') ?></textarea>
                        </div>
                        <div class="form-field">
                            <label class="form-label">Misi Sekolah</label>
                            <textarea name="misi" class="form-control" placeholder="Tuliskan misi sekolah..."><?= htmlspecialchars($sekolah['misi'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===============================================================
             * TAB 4: KEPALA SEKOLAH
             * =============================================================== -->
            <div class="tab-section" id="section-kepsek" style="<?= $currentTab === 'kepsek' ? '' : 'display:none;' ?>">
                <div class="school-card-head">
                    <div>
                        <h3 class="school-card-head-title">
                            <iconify-icon icon="solar:user-bold" width="18" style="color: #0D7A87;"></iconify-icon>
                            <span>Data Kepala Sekolah &amp; Tanda Tangan Digital</span>
                        </h3>
                        <p class="school-card-head-desc">Pimpinan satuan pendidikan yang menandatangani rapor, ijazah, dan surat dinas.</p>
                    </div>
                </div>
                <div class="school-card-body">
                    <!-- Auto fill dari guru -->
                    <div class="auto-fill-box">
                        <div class="auto-fill-label">
                            <iconify-icon icon="solar:user-speak-bold" width="16"></iconify-icon>
                            <span>Auto-Fill dari Guru:</span>
                        </div>
                        <select class="form-control" style="padding: 6px 10px; font-size: 12px;" onchange="autoFill('kepala_sekolah', this)">
                            <option value="">-- Pilih Nama Guru untuk Dijadikan Kepala Sekolah --</option>
                            <?php foreach ($guruList as $g): ?>
                                <option value="<?= htmlspecialchars($g['nama_guru']) ?>" data-nip="<?= htmlspecialchars($g['nip'] ?? '') ?>" data-nuptk="<?= htmlspecialchars($g['nuptk'] ?? '') ?>" data-hp="<?= htmlspecialchars($g['no_hp'] ?? '') ?>">
                                    <?= htmlspecialchars($g['nama_guru']) ?> (NIP: <?= htmlspecialchars($g['nip'] ?: '-') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="grid-2">
                        <div class="form-field">
                            <label class="form-label">Nama Lengkap Kepala Sekolah (Beserta Gelar)</label>
                            <input type="text" name="nama_kepala_sekolah" id="inp_nama_kepala_sekolah" class="form-control" value="<?= htmlspecialchars($sekolah['nama_kepala_sekolah'] ?? '') ?>" placeholder="Dr. H. Muhammad Arifin, M.Pd.">
                        </div>
                        <div class="form-field">
                            <label class="form-label">Gelar Utama</label>
                            <input type="text" name="gelar_kepala_sekolah" class="form-control" value="<?= htmlspecialchars($sekolah['gelar_kepala_sekolah'] ?? '') ?>" placeholder="M.Pd.">
                        </div>
                    </div>

                    <div class="grid-2">
                        <div class="form-field">
                            <label class="form-label">NIP Kepala Sekolah</label>
                            <input type="text" name="nip_kepala_sekolah" id="inp_nip_kepala_sekolah" class="form-control" value="<?= htmlspecialchars($sekolah['nip_kepala_sekolah'] ?? '') ?>" placeholder="197503122000031002">
                        </div>
                        <div class="form-field">
                            <label class="form-label">NUPTK Kepala Sekolah</label>
                            <input type="text" name="nuptk_kepala_sekolah" id="inp_nuptk_kepala_sekolah" class="form-control" value="<?= htmlspecialchars($sekolah['nuptk_kepala_sekolah'] ?? '') ?>" placeholder="NUPTK">
                        </div>
                    </div>

                    <?= renderSignaturePadUI('ttd_kepala_sekolah', 'Tanda Tangan Digital Kepala Sekolah', $sekolah['ttd_kepala_sekolah'] ?? '') ?>
                </div>
            </div>

            <!-- ===============================================================
             * TAB 5: JAJARAN WAKA, TU & BENDAHARA
             * =============================================================== -->
            <div class="tab-section" id="section-waka" style="<?= $currentTab === 'waka' ? '' : 'display:none;' ?>">
                <div class="school-card-head">
                    <div>
                        <h3 class="school-card-head-title">
                            <iconify-icon icon="solar:users-group-rounded-bold" width="18" style="color: #0D7A87;"></iconify-icon>
                            <span>Jajaran Wakil Kepala Sekolah, Kepala TU, Bendahara &amp; Proktor</span>
                        </h3>
                        <p class="school-card-head-desc">Pejabat struktural sekolah untuk otorisasi kurikulum, kesiswaan, sarpras, humas, dan keuangan.</p>
                    </div>
                </div>
                <div class="school-card-body">

                    <!-- 1. WAKA KURIKULUM -->
                    <div class="section-heading">
                        <iconify-icon icon="solar:diploma-verified-bold" width="16"></iconify-icon>
                        <span>1. Waka Kurikulum (Bidang Akademik &amp; KBM)</span>
                    </div>
                    <div class="auto-fill-box">
                        <div class="auto-fill-label">Pilih dari Guru:</div>
                        <select class="form-control" style="padding: 4px 8px; font-size: 11.5px;" onchange="autoFill('waka_kurikulum', this)">
                            <option value="">-- Pilih Guru untuk Waka Kurikulum --</option>
                            <?php foreach ($guruList as $g): ?>
                                <option value="<?= htmlspecialchars($g['nama_guru']) ?>" data-nip="<?= htmlspecialchars($g['nip'] ?? '') ?>" data-nuptk="<?= htmlspecialchars($g['nuptk'] ?? '') ?>" data-hp="<?= htmlspecialchars($g['no_hp'] ?? '') ?>">
                                    <?= htmlspecialchars($g['nama_guru']) ?> (NIP: <?= htmlspecialchars($g['nip'] ?: '-') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="grid-3">
                        <div class="form-field">
                            <label class="form-label">Nama Waka Kurikulum</label>
                            <input type="text" name="nama_waka_kurikulum" id="inp_nama_waka_kurikulum" class="form-control" value="<?= htmlspecialchars($sekolah['nama_waka_kurikulum'] ?? '') ?>" placeholder="Ustadz Rahmat Hidayat, M.Pd.I">
                        </div>
                        <div class="form-field">
                            <label class="form-label">NIP Waka Kurikulum</label>
                            <input type="text" name="nip_waka_kurikulum" id="inp_nip_waka_kurikulum" class="form-control" value="<?= htmlspecialchars($sekolah['nip_waka_kurikulum'] ?? '') ?>" placeholder="198804202014021003">
                        </div>
                        <div class="form-field">
                            <label class="form-label">No. HP / WA</label>
                            <input type="text" name="no_hp_waka_kurikulum" id="inp_no_hp_waka_kurikulum" class="form-control" value="<?= htmlspecialchars($sekolah['no_hp_waka_kurikulum'] ?? '') ?>" placeholder="08...">
                        </div>
                    </div>
                    <?= renderSignaturePadUI('ttd_waka_kurikulum', 'Tanda Tangan Digital Waka Kurikulum', $sekolah['ttd_waka_kurikulum'] ?? '') ?>

                    <!-- 2. WAKA KESISWAAN -->
                    <div class="section-heading">
                        <iconify-icon icon="solar:square-academic-cap-bold" width="16"></iconify-icon>
                        <span>2. Waka Kesiswaan (Bidang Kesiswaan &amp; Ekstrakurikuler)</span>
                    </div>
                    <div class="auto-fill-box">
                        <div class="auto-fill-label">Pilih dari Guru:</div>
                        <select class="form-control" style="padding: 4px 8px; font-size: 11.5px;" onchange="autoFill('waka_kesiswaan', this)">
                            <option value="">-- Pilih Guru untuk Waka Kesiswaan --</option>
                            <?php foreach ($guruList as $g): ?>
                                <option value="<?= htmlspecialchars($g['nama_guru']) ?>" data-nip="<?= htmlspecialchars($g['nip'] ?? '') ?>" data-nuptk="<?= htmlspecialchars($g['nuptk'] ?? '') ?>" data-hp="<?= htmlspecialchars($g['no_hp'] ?? '') ?>">
                                    <?= htmlspecialchars($g['nama_guru']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="grid-3">
                        <div class="form-field">
                            <label class="form-label">Nama Waka Kesiswaan</label>
                            <input type="text" name="nama_waka_kesiswaan" id="inp_nama_waka_kesiswaan" class="form-control" value="<?= htmlspecialchars($sekolah['nama_waka_kesiswaan'] ?? '') ?>" placeholder="Ahmad Zaki Mubarak, S.Pd.">
                        </div>
                        <div class="form-field">
                            <label class="form-label">NIP Waka Kesiswaan</label>
                            <input type="text" name="nip_waka_kesiswaan" id="inp_nip_waka_kesiswaan" class="form-control" value="<?= htmlspecialchars($sekolah['nip_waka_kesiswaan'] ?? '') ?>" placeholder="NIP">
                        </div>
                        <div class="form-field">
                            <label class="form-label">No. HP / WA</label>
                            <input type="text" name="no_hp_waka_kesiswaan" id="inp_no_hp_waka_kesiswaan" class="form-control" value="<?= htmlspecialchars($sekolah['no_hp_waka_kesiswaan'] ?? '') ?>" placeholder="08...">
                        </div>
                    </div>

                    <!-- 3. WAKA SARANA & PRASARANA -->
                    <div class="section-heading">
                        <iconify-icon icon="solar:buildings-bold" width="16"></iconify-icon>
                        <span>3. Waka Sarana &amp; Prasarana (Sarpras)</span>
                    </div>
                    <div class="grid-3">
                        <div class="form-field">
                            <label class="form-label">Nama Waka Sarpras</label>
                            <input type="text" name="nama_waka_sarpras" class="form-control" value="<?= htmlspecialchars($sekolah['nama_waka_sarpras'] ?? '') ?>" placeholder="Budi Santoso, S.T.">
                        </div>
                        <div class="form-field">
                            <label class="form-label">NIP Waka Sarpras</label>
                            <input type="text" name="nip_waka_sarpras" class="form-control" value="<?= htmlspecialchars($sekolah['nip_waka_sarpras'] ?? '') ?>" placeholder="NIP">
                        </div>
                        <div class="form-field">
                            <label class="form-label">No. HP / WA</label>
                            <input type="text" name="no_hp_waka_sarpras" class="form-control" value="<?= htmlspecialchars($sekolah['no_hp_waka_sarpras'] ?? '') ?>" placeholder="08...">
                        </div>
                    </div>

                    <!-- 4. WAKA HUMAS -->
                    <div class="section-heading">
                        <iconify-icon icon="solar:share-circle-bold" width="16"></iconify-icon>
                        <span>4. Waka Hubungan Masyarakat (Humas) / Kemitraan</span>
                    </div>
                    <div class="grid-3">
                        <div class="form-field">
                            <label class="form-label">Nama Waka Humas</label>
                            <input type="text" name="nama_waka_humas" class="form-control" value="<?= htmlspecialchars($sekolah['nama_waka_humas'] ?? '') ?>" placeholder="Nurul Hidayati, S.Sos.">
                        </div>
                        <div class="form-field">
                            <label class="form-label">NIP Waka Humas</label>
                            <input type="text" name="nip_waka_humas" class="form-control" value="<?= htmlspecialchars($sekolah['nip_waka_humas'] ?? '') ?>" placeholder="NIP">
                        </div>
                        <div class="form-field">
                            <label class="form-label">No. HP / WA</label>
                            <input type="text" name="no_hp_waka_humas" class="form-control" value="<?= htmlspecialchars($sekolah['no_hp_waka_humas'] ?? '') ?>" placeholder="08...">
                        </div>
                    </div>

                    <!-- 5. KEPALA TATA USAHA -->
                    <div class="section-heading">
                        <iconify-icon icon="solar:document-bold" width="16"></iconify-icon>
                        <span>5. Kepala Tata Usaha (TU)</span>
                    </div>
                    <div class="grid-3">
                        <div class="form-field">
                            <label class="form-label">Nama Kepala TU</label>
                            <input type="text" name="nama_kepala_tu" class="form-control" value="<?= htmlspecialchars($sekolah['nama_kepala_tu'] ?? '') ?>" placeholder="Drs. Bambang Wahyudi">
                        </div>
                        <div class="form-field">
                            <label class="form-label">NIP Kepala TU</label>
                            <input type="text" name="nip_kepala_tu" class="form-control" value="<?= htmlspecialchars($sekolah['nip_kepala_tu'] ?? '') ?>" placeholder="NIP">
                        </div>
                        <div class="form-field">
                            <label class="form-label">No. HP / WA</label>
                            <input type="text" name="no_hp_kepala_tu" class="form-control" value="<?= htmlspecialchars($sekolah['no_hp_kepala_tu'] ?? '') ?>" placeholder="08...">
                        </div>
                    </div>

                    <!-- 6. BENDAHARA & PROKTOR CBT -->
                    <div class="section-heading">
                        <iconify-icon icon="solar:wallet-bold" width="16"></iconify-icon>
                        <span>6. Bendahara Lembaga &amp; Proktor Utama Ujian CBT</span>
                    </div>
                    <div class="grid-2">
                        <div class="form-field">
                            <label class="form-label">Nama Bendahara Sekolah</label>
                            <input type="text" name="nama_bendahara" class="form-control" value="<?= htmlspecialchars($sekolah['nama_bendahara'] ?? '') ?>" placeholder="Siti Aminah, S.E.">
                        </div>
                        <div class="form-field">
                            <label class="form-label">NIP Bendahara</label>
                            <input type="text" name="nip_bendahara" class="form-control" value="<?= htmlspecialchars($sekolah['nip_bendahara'] ?? '') ?>" placeholder="NIP">
                        </div>
                    </div>
                    <div class="grid-2">
                        <div class="form-field">
                            <label class="form-label">Nama Proktor Utama CBT</label>
                            <input type="text" name="nama_proktor_cbt" class="form-control" value="<?= htmlspecialchars($sekolah['nama_proktor_cbt'] ?? '') ?>" placeholder="Syahriel Andika">
                        </div>
                        <div class="form-field">
                            <label class="form-label">No. HP Proktor CBT</label>
                            <input type="text" name="no_hp_proktor_cbt" class="form-control" value="<?= htmlspecialchars($sekolah['no_hp_proktor_cbt'] ?? '') ?>" placeholder="081234567890">
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===============================================================
             * TAB 6: PARAMETER DOKUMEN & TITIMANGSA
             * =============================================================== -->
            <div class="tab-section" id="section-rapor" style="<?= $currentTab === 'rapor' ? '' : 'display:none;' ?>">
                <div class="school-card-head">
                    <div>
                        <h3 class="school-card-head-title">
                            <iconify-icon icon="solar:settings-bold" width="18" style="color: #0D7A87;"></iconify-icon>
                            <span>Parameter Pencetakan Dokumen Rapor, Kartu Ujian &amp; Periode Aktif</span>
                        </h3>
                        <p class="school-card-head-desc">Pengaturan kota titimangsa tanggal penerbitan dokumen serta periode ajaran KBM aktif.</p>
                    </div>
                </div>
                <div class="school-card-body">
                    <div class="grid-3">
                        <div class="form-field">
                            <label class="form-label">Kota Titimangsa Penerbitan Dokumen <span class="req">*</span></label>
                            <input type="text" name="titimangsa_tempat" class="form-control" value="<?= htmlspecialchars($sekolah['titimangsa_tempat'] ?? 'Bojonegoro') ?>" placeholder="misal: Bojonegoro" required>
                            <small style="color: #64748B; font-size: 11px; margin-top: 4px; display: block;">
                                Contoh tercetak: "Bojonegoro, 28 Oktober 2026"
                            </small>
                        </div>
                        <div class="form-field">
                            <label class="form-label">Tahun Pelajaran KBM Aktif</label>
                            <input type="text" name="tahun_pelajaran_aktif" class="form-control" value="<?= htmlspecialchars($sekolah['tahun_pelajaran_aktif'] ?? '2026/2027') ?>" placeholder="2026/2027">
                        </div>
                        <div class="form-field">
                            <label class="form-label">Semester Aktif</label>
                            <select name="semester_aktif" class="form-control">
                                <option value="Ganjil" <?= (($sekolah['semester_aktif'] ?? 'Ganjil') === 'Ganjil') ? 'selected' : '' ?>>Semester Ganjil</option>
                                <option value="Genap" <?= (($sekolah['semester_aktif'] ?? '') === 'Genap') ? 'selected' : '' ?>>Semester Genap</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FOOTER ACTION BUTTON -->
            <div class="school-card-foot">
                <div style="font-size: 12.5px; color: #64748B;">
                    Terakhir diperbarui: <strong><?= !empty($sekolah['updated_at']) ? date('d M Y, H:i', strtotime($sekolah['updated_at'])) : '-' ?></strong>
                </div>
                <button type="submit" class="btn-save-main">
                    <iconify-icon icon="solar:check-circle-bold" width="18"></iconify-icon>
                    <span>Simpan Perubahan Identitas Sekolah</span>
                </button>
            </div>

        </div>
    </form>
</div>

<script>
function switchTab(tabKey) {
    document.querySelectorAll('.school-tab-link').forEach(function(btn) {
        btn.classList.remove('active');
    });
    document.querySelectorAll('.tab-section').forEach(function(sec) {
        sec.style.display = 'none';
    });

    var activeBtn = document.querySelector('.school-tab-link[onclick="switchTab(\'' + tabKey + '\')"]');
    if (activeBtn) activeBtn.classList.add('active');

    var activeSec = document.getElementById('section-' + tabKey);
    if (activeSec) activeSec.style.display = 'block';

    var activeInput = document.getElementById('activeTabInput');
    if (activeInput) activeInput.value = tabKey;

    // Refresh kanvas jika berpindah ke tab tanda tangan
    setTimeout(function() {
        if (window.initSignaturePads) initSignaturePads();
    }, 60);
}

// =============================================================================
// ELECTRONIC SIGNATURE PAD CONTROLLER (PURE VANILLA JS & VECTOR SVG GENERATOR)
// =============================================================================
var activeSigPads = {};

function initSignaturePads() {
    document.querySelectorAll('.sig-canvas').forEach(function(canvas) {
        var fieldKey = canvas.getAttribute('data-field');
        if (!fieldKey) return;
        if (activeSigPads[fieldKey]) {
            activeSigPads[fieldKey].resize();
            return;
        }

        var ctx = canvas.getContext('2d');
        var color = '#1E3A8A'; // Default: Biru Pena Dokumen Resmi
        var lineWidth = 2.8;
        var isDrawing = false;
        var paths = [];
        var currentPath = [];

        function resizeCanvas() {
            var rect = canvas.getBoundingClientRect();
            var width = Math.round(rect.width) || 460;
            var height = Math.round(rect.height) || 150;

            if (canvas.width !== width || canvas.height !== height) {
                canvas.width = width;
                canvas.height = height;
                redraw();
            }
        }

        function redraw() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.strokeStyle = color;
            ctx.lineWidth = lineWidth;

            for (var p = 0; p < paths.length; p++) {
                var pts = paths[p];
                if (pts.length === 0) continue;
                if (pts.length === 1) {
                    ctx.beginPath();
                    ctx.arc(pts[0].x, pts[0].y, lineWidth / 2, 0, Math.PI * 2);
                    ctx.fillStyle = color;
                    ctx.fill();
                    continue;
                }
                ctx.beginPath();
                ctx.moveTo(pts[0].x, pts[0].y);
                for (var i = 1; i < pts.length - 1; i++) {
                    var xc = (pts[i].x + pts[i + 1].x) / 2;
                    var yc = (pts[i].y + pts[i + 1].y) / 2;
                    ctx.quadraticCurveTo(pts[i].x, pts[i].y, xc, yc);
                }
                var last = pts[pts.length - 1];
                ctx.lineTo(last.x, last.y);
                ctx.stroke();
            }
        }

        function getPos(e) {
            var rect = canvas.getBoundingClientRect();
            var clientX = e.clientX;
            var clientY = e.clientY;
            if (e.touches && e.touches.length > 0) {
                clientX = e.touches[0].clientX;
                clientY = e.touches[0].clientY;
            }
            return {
                x: clientX - rect.left,
                y: clientY - rect.top
            };
        }

        function startDrawing(e) {
            isDrawing = true;
            currentPath = [];
            var pos = getPos(e);
            currentPath.push(pos);
            paths.push(currentPath);
            redraw();
            e.preventDefault();
        }

        function draw(e) {
            if (!isDrawing) return;
            var pos = getPos(e);
            currentPath.push(pos);
            redraw();
            e.preventDefault();
        }

        function stopDrawing(e) {
            if (!isDrawing) return;
            isDrawing = false;
            updateSvgOutput();
        }

        function updateSvgOutput() {
            var svgInp = document.getElementById('inp_' + fieldKey + '_svg');
            var clearInp = document.getElementById('inp_' + fieldKey + '_clear');
            if (clearInp) clearInp.value = '0';

            if (paths.length === 0) {
                if (svgInp) svgInp.value = '';
                return;
            }

            var w = canvas.width || 460;
            var h = canvas.height || 150;
            var d = '';

            for (var p = 0; p < paths.length; p++) {
                var pts = paths[p];
                if (pts.length === 0) continue;
                if (pts.length === 1) {
                    d += 'M ' + pts[0].x.toFixed(1) + ' ' + pts[0].y.toFixed(1) + ' L ' + (pts[0].x + 0.5).toFixed(1) + ' ' + (pts[0].y + 0.5).toFixed(1) + ' ';
                    continue;
                }
                d += 'M ' + pts[0].x.toFixed(1) + ' ' + pts[0].y.toFixed(1) + ' ';
                for (var i = 1; i < pts.length - 1; i++) {
                    var xc = ((pts[i].x + pts[i + 1].x) / 2).toFixed(1);
                    var yc = ((pts[i].y + pts[i + 1].y) / 2).toFixed(1);
                    d += 'Q ' + pts[i].x.toFixed(1) + ' ' + pts[i].y.toFixed(1) + ', ' + xc + ' ' + yc + ' ';
                }
                var last = pts[pts.length - 1];
                d += 'L ' + last.x.toFixed(1) + ' ' + last.y.toFixed(1) + ' ';
            }

            var svgString = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + w + ' ' + h + '"><path d="' + d.trim() + '" fill="none" stroke="' + color + '" stroke-width="' + lineWidth + '" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            if (svgInp) svgInp.value = svgString;
        }

        // Pointer & Touch Events
        canvas.addEventListener('pointerdown', startDrawing);
        canvas.addEventListener('pointermove', draw);
        window.addEventListener('pointerup', stopDrawing);
        canvas.addEventListener('pointercancel', stopDrawing);

        activeSigPads[fieldKey] = {
            canvas: canvas,
            ctx: ctx,
            clear: function() {
                paths = [];
                currentPath = [];
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                var svgInp = document.getElementById('inp_' + fieldKey + '_svg');
                if (svgInp) svgInp.value = '';
            },
            setColor: function(newColor) {
                color = newColor;
                redraw();
                updateSvgOutput();
            },
            resize: resizeCanvas
        };

        setTimeout(resizeCanvas, 50);
    });
}

function openSignatureCanvas(fieldKey) {
    var pBox = document.getElementById('preview_box_' + fieldKey);
    var cWrap = document.getElementById('canvas_wrap_' + fieldKey);
    if (pBox) pBox.style.display = 'none';
    if (cWrap) {
        cWrap.style.display = 'block';
        if (activeSigPads[fieldKey]) {
            activeSigPads[fieldKey].resize();
        }
    }
}

function cancelSigCanvas(fieldKey) {
    var pBox = document.getElementById('preview_box_' + fieldKey);
    var cWrap = document.getElementById('canvas_wrap_' + fieldKey);
    if (activeSigPads[fieldKey]) {
        activeSigPads[fieldKey].clear();
    }
    if (cWrap) cWrap.style.display = 'none';
    if (pBox) pBox.style.display = 'flex';
}

function resetSigCanvas(fieldKey) {
    if (activeSigPads[fieldKey]) {
        activeSigPads[fieldKey].clear();
    }
}

function setSigPadColor(fieldKey, colorHex, btnEl) {
    if (activeSigPads[fieldKey]) {
        activeSigPads[fieldKey].setColor(colorHex);
    }
    var picker = btnEl.parentElement;
    if (picker) {
        picker.querySelectorAll('.sig-color-btn').forEach(function(b) {
            b.classList.remove('active');
        });
        btnEl.classList.add('active');
    }
}

function clearExistingSignature(fieldKey) {
    var doClear = function() {
        var clearInp = document.getElementById('inp_' + fieldKey + '_clear');
        var svgInp   = document.getElementById('inp_' + fieldKey + '_svg');
        if (clearInp) clearInp.value = '1';
        if (svgInp) svgInp.value = '';

        var pBox  = document.getElementById('preview_box_' + fieldKey);
        var cWrap = document.getElementById('canvas_wrap_' + fieldKey);
        if (pBox) pBox.style.display = 'none';
        if (cWrap) {
            cWrap.style.display = 'block';
            if (activeSigPads[fieldKey]) {
                activeSigPads[fieldKey].clear();
                activeSigPads[fieldKey].resize();
            }
        }
        if (typeof showToast === 'function') {
            showToast('info', 'Tanda tangan telah dikosongkan. Klik "Simpan Identitas Sekolah" untuk menerapkan perubahan.', 'Perhatian', 5000);
        }
    };

    if (window.Ilmuna && typeof window.Ilmuna.confirm === 'function') {
        Ilmuna.confirm('error', 'Hapus Tanda Tangan Digital', 'Apakah Anda yakin ingin menghapus tanda tangan ini dari sistem? Tanda tangan akan dikosongkan permanen setelah Anda menyimpan perubahan formulir.', 'Ya, Hapus TTD', 'Batal').then(function(confirmed) {
            if (confirmed) {
                doClear();
            }
        });
    } else if (typeof ilmunaConfirm === 'function') {
        ilmunaConfirm({
            title: 'Hapus Tanda Tangan Digital',
            message: 'Apakah Anda yakin ingin menghapus tanda tangan ini dari sistem? Tanda tangan akan dikosongkan permanen setelah Anda menyimpan perubahan formulir.',
            confirmText: 'Ya, Hapus TTD',
            cancelText: 'Batal',
            type: 'danger'
        }).then(function(confirmed) {
            if (confirmed) {
                doClear();
            }
        });
    } else {
        doClear();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    initSignaturePads();

    // Validasi Form Tema Ilmuna Learn
    var formEl = document.getElementById('formSchoolProfile');
    if (formEl) {
        formEl.addEventListener('submit', function(e) {
            var namaSekolah = document.querySelector('input[name="nama_sekolah"]');
            if (namaSekolah && !namaSekolah.value.trim()) {
                e.preventDefault();
                switchTab('identitas');
                namaSekolah.focus();
                Ilmuna.toast('error', 'Peringatan Validasi', 'Nama resmi sekolah wajib diisi sebelum menyimpan data.');
                return false;
            }
        });
    }
});

function autoFill(roleKey, selectEl) {
    var opt = selectEl.options[selectEl.selectedIndex];
    if (!opt || !opt.value) return;

    var nama = opt.value;
    var nip  = opt.dataset.nip || '';
    var nuptk = opt.dataset.nuptk || '';
    var hp   = opt.dataset.hp || '';

    var inpNama = document.getElementById('inp_nama_' + roleKey);
    var inpNip  = document.getElementById('inp_nip_' + roleKey);
    var inpNuptk = document.getElementById('inp_nuptk_' + roleKey);
    var inpHp   = document.getElementById('inp_no_hp_' + roleKey);

    if (inpNama) inpNama.value = nama;
    if (inpNip) inpNip.value = nip;
    if (inpNuptk) inpNuptk.value = nuptk;
    if (inpHp) inpHp.value = hp;
}

function syncGeofencing() {
    <?php if ($geofenceAktif): ?>
        var lat = <?= json_encode((string)$geofenceAktif['latitude']) ?>;
        var lng = <?= json_encode((string)$geofenceAktif['longitude']) ?>;
        var rad = <?= json_encode((string)$geofenceAktif['radius_meter']) ?>;
        var alm = <?= json_encode((string)$geofenceAktif['alamat']) ?>;

        if (lat) document.getElementById('inp_latitude').value = lat;
        if (lng) document.getElementById('inp_longitude').value = lng;
        if (rad) document.getElementById('inp_radius_presensi_meter').value = rad;
        if (alm && !document.getElementById('inp_alamat_jalan').value) {
            document.getElementById('inp_alamat_jalan').value = alm;
        }
        Ilmuna.toast('success', 'Berhasil Disalin', 'Koordinat GPS, radius, dan alamat dari titik geofencing aktif berhasil disalin ke formulir.');
    <?php else: ?>
        Ilmuna.toast('warning', 'Peringatan', 'Tidak ada data titik geofencing aktif yang ditemukan di database.');
    <?php endif; ?>
}
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
