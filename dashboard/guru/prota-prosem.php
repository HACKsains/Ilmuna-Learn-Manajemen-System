<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — PROGRAM TAHUNAN (PROTA) & PROGRAM SEMESTER (PROSEM)
 * ==============================================================================
 * Portal Dewan Guru & Kurikulum Merdeka Terintegrasi Kalender Pendidikan (Kaldik)
 * 
 * Standar & Fitur Utama:
 * 1. Filter Cascading: Rombel Kelas -> Mata Pelajaran Guru -> Tahun Ajaran & Semester
 * 2. Integrasi Total dengan Kalender Pendidikan (Kaldik) & Pekan Efektif (RPE):
 *    - Sinkronisasi realtime `kalender_pendidikan` -> `hari_efektif` & `pekan_efektif`
 *    - Perhitungan otomatis Jam Tatap Muka (JTM) dari Hari Mengajar KBM guru
 * 3. Program Tahunan (PROTA):
 *    - Generator otomatis dari database Capaian (CP) & Tujuan Pembelajaran (TP)
 *    - Perencanaan beban belajar 1 tahun (Ganjil & Genap) + Asesmen (AS, STS, ASAS, P5)
 * 4. Program Semester (PROSEM):
 *    - Generator otomatis PROSEM dari PROTA & Kaldik
 *    - Matriks Distribusi Mingguan Interaktif (Bulan 1-6 x Pekan 1-5)
 *    - Deteksi otomatis pekan non-efektif (MPLS, STS, ASAS, Libur Semester) dari Kaldik
 * 5. Distribusi JTM & Alokasi Hari Mengajar (`alokasi_hari_mengajar` & `distribusi_jtm_tp`)
 * 6. Dokumen Cetak Resmi Siap Ekspor / Print dengan Kop Sekolah & TTD Legalitas
 * ==============================================================================
 */

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';

requireRole(['guru', 'admin']);
$activeRole = hasRole('guru') ? 'guru' : 'admin';
$GLOBALS['activeRole'] = $activeRole;

$pdo = getDBConnection();
$pdo->exec("SET time_zone = '+07:00'");
$currentUser = getCurrentUser();

// Identifikasi profil guru pengampu
$currentGuru = null;
$idGuruLogged = 0; // id di tabel guru
$idUserLogged = (int)($currentUser['id'] ?? 0); // id di tabel users
$namaGuruLogged = 'Dewan Guru';
try {
    $gStmt = $pdo->prepare("SELECT * FROM guru WHERE id_user = ? LIMIT 1");
    $gStmt->execute([$currentUser['id']]);
    $currentGuru = $gStmt->fetch(PDO::FETCH_ASSOC);
    if ($currentGuru) {
        $idGuruLogged = (int)$currentGuru['id'];
        $namaGuruLogged = $currentGuru['nama_guru'];
    }
} catch (Exception $e) {}

// Profil Identitas Sekolah untuk cetak dokumen
$identitasSekolah = [];
try {
    $stmtSekolah = $pdo->query("SELECT * FROM identitas_sekolah LIMIT 1");
    $identitasSekolah = $stmtSekolah->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

/* ==============================================================================
 * DIRECT PRINT ROUTE (STANDALONE OFFICIAL DOCUMENT)
 * ============================================================================== */
if (isset($_GET['action']) && in_array($_GET['action'], ['print_prota', 'print_prosem'], true)) {
    $action = $_GET['action'];
    $idKmp = (int)($_GET['id_kmp'] ?? 0);
    $tahun = trim((string)($_GET['tahun'] ?? '2026/2027'));
    $semester = trim((string)($_GET['semester'] ?? 'Ganjil'));

    // Fetch KMP, Kelas, Mapel
    $stmt = $pdo->prepare("
        SELECT kmp.*, k.nama_kelas, mp.nama_mata_pelajaran, mp.kode_mapel,
               COALESCE(g.nama_guru, 'Dewan Guru') as nama_guru,
               COALESCE(g.nip, '-') as nip_guru
        FROM kelas_mata_pelajaran kmp
        JOIN kelas k ON kmp.id_kelas = k.id
        JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
        LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
        LEFT JOIN guru g ON gmp.id_guru = g.id
        WHERE kmp.id = ?
    ");
    $stmt->execute([$idKmp]);
    $kmpData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$kmpData) {
        die("Data rombel kelas & mata pelajaran tidak ditemukan.");
    }

    $namaSekolah = $identitasSekolah['nama_sekolah'] ?? 'ILMUNA LEARN ACADEMY';
    $alamatSekolah = $identitasSekolah['alamat'] ?? '';
    $kepsek = $identitasSekolah['nama_kepala_sekolah'] ?? 'Kepala Sekolah';
    $nipKepsek = !empty($identitasSekolah['nip_kepala_sekolah']) ? 'NIP. ' . $identitasSekolah['nip_kepala_sekolah'] : '-';
    $guruNama = ($namaGuruLogged !== 'Dewan Guru') ? $namaGuruLogged : $kmpData['nama_guru'];
    $nipGuru = ($currentGuru && !empty($currentGuru['nip'])) ? 'NIP. ' . $currentGuru['nip'] : ($kmpData['nip_guru'] !== '-' ? 'NIP. ' . $kmpData['nip_guru'] : '-');

    $fase = 'Fase F';
    if (preg_match('/\b(10|x)\b/i', $kmpData['nama_kelas'])) $fase = 'Fase E';
    elseif (preg_match('/\b(7|8|9|vii|viii|ix)\b/i', $kmpData['nama_kelas'])) $fase = 'Fase D';

    if ($action === 'print_prota') {
        // Fetch prota
        $pStmt = $pdo->prepare("SELECT * FROM prota WHERE id_kelas_mata_pelajaran = ? AND tahun_pelajaran = ? LIMIT 1");
        $pStmt->execute([$idKmp, $tahun]);
        $prota = $pStmt->fetch(PDO::FETCH_ASSOC);

        $details = [];
        if ($prota) {
            $fase = $prota['fase'] ?: $fase;
            $dStmt = $pdo->prepare("
                SELECT pd.*, e.nama_elemen, tp.kode_tp, tp.tp as uraian_tp, tp.materi_pokok
                FROM prota_detail pd
                LEFT JOIN elemen e ON pd.id_elemen = e.id
                LEFT JOIN tp ON pd.id_tp = tp.id
                WHERE pd.id_prota = ?
                ORDER BY pd.semester ASC, pd.nomor_urut ASC, pd.id ASC
            ");
            $dStmt->execute([$prota['id']]);
            $details = $dStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        ?>
        <!DOCTYPE html>
        <html lang="id">
        <head>
            <meta charset="utf-8">
            <title>Cetak PROTA - <?= htmlspecialchars($kmpData['nama_mata_pelajaran']) ?> <?= htmlspecialchars($kmpData['nama_kelas']) ?></title>
            <style>
                @page { size: A4 portrait; margin: 15mm 15mm 15mm 15mm; }
                body { font-family: 'Times New Roman', Times, serif; font-size: 11pt; color: #000; margin: 0; padding: 20px; }
                .kop-wrapper { text-align: center; border-bottom: 3px double #000; padding-bottom: 8px; margin-bottom: 16px; }
                .kop-sekolah { font-size: 15pt; font-weight: bold; text-transform: uppercase; margin: 0; }
                .kop-alamat { font-size: 10pt; margin: 3px 0 0 0; }
                .doc-title { text-align: center; font-size: 13pt; font-weight: bold; text-transform: uppercase; margin: 12px 0 4px; }
                .doc-subtitle { text-align: center; font-size: 11pt; margin-bottom: 16px; }
                .meta-table { width: 100%; font-size: 10.5pt; margin-bottom: 14px; border-collapse: collapse; }
                .meta-table td { padding: 3px 4px; vertical-align: top; }
                .data-table { width: 100%; border-collapse: collapse; font-size: 10pt; margin-bottom: 20px; }
                .data-table th, .data-table td { border: 1px solid #000; padding: 6px 8px; vertical-align: top; }
                .data-table th { background: #f2f2f2; text-align: center; font-weight: bold; }
                .sig-table { width: 100%; margin-top: 25px; border-collapse: collapse; font-size: 11pt; page-break-inside: avoid; }
                .sig-table td { text-align: center; vertical-align: top; width: 50%; }
                .no-print { margin-bottom: 20px; background: #f8fafc; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; display: flex; gap: 10px; justify-content: flex-end; }
                .btn { padding: 8px 16px; font-size: 13px; font-weight: bold; border-radius: 6px; cursor: pointer; text-decoration: none; border: 1px solid #000; }
                .btn-print { background: #0d7a87; color: #fff; border-color: #0d7a87; }
                .btn-close { background: #fff; color: #333; }
                @media print { .no-print { display: none !important; } body { padding: 0; } }
            </style>
        </head>
        <body>
            <div class="no-print">
                <button type="button" class="btn btn-print" onclick="window.print()">Cetak Dokumen (Print / PDF)</button>
                <button type="button" class="btn btn-close" onclick="window.close()">Tutup</button>
            </div>
            <div class="kop-wrapper">
                <div class="kop-sekolah"><?= htmlspecialchars($namaSekolah) ?></div>
                <div class="kop-alamat"><?= htmlspecialchars($alamatSekolah) ?></div>
            </div>
            <div class="doc-title">PROGRAM TAHUNAN (PROTA)</div>
            <div class="doc-subtitle">KURIKULUM MERDEKA &bull; TAHUN AJARAN <?= htmlspecialchars($tahun) ?></div>
            <table class="meta-table">
                <tr>
                    <td style="width: 140px; font-weight: bold;">Satuan Pendidikan</td>
                    <td style="width: 10px;">:</td>
                    <td><?= htmlspecialchars($namaSekolah) ?></td>
                    <td style="width: 130px; font-weight: bold;">Mata Pelajaran</td>
                    <td style="width: 10px;">:</td>
                    <td><?= htmlspecialchars($kmpData['nama_mata_pelajaran']) ?></td>
                </tr>
                <tr>
                    <td style="font-weight: bold;">Kelas / Fase</td>
                    <td>:</td>
                    <td><?= htmlspecialchars($kmpData['nama_kelas']) ?> / <?= htmlspecialchars($fase) ?></td>
                    <td style="font-weight: bold;">Tahun Ajaran</td>
                    <td>:</td>
                    <td><?= htmlspecialchars($tahun) ?></td>
                </tr>
            </table>

            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 35px;">No</th>
                        <th style="width: 80px;">Semester</th>
                        <th style="width: 130px;">Elemen</th>
                        <th style="width: 70px;">Kode</th>
                        <th>Tujuan Pembelajaran (TP) & Materi Pokok</th>
                        <th style="width: 70px;">Alokasi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($details)): ?>
                    <tr><td colspan="6" style="text-align: center; padding: 25px;">Belum ada rincian data Program Tahunan.</td></tr>
                    <?php else: ?>
                    <?php 
                    $totalJpTahunan = 0;
                    foreach ($details as $idx => $r): 
                        $totalJpTahunan += (int)$r['alokasi_waktu'];
                    ?>
                    <tr>
                        <td style="text-align: center;"><?= $idx + 1 ?></td>
                        <td style="text-align: center;"><?= htmlspecialchars($r['semester']) ?></td>
                        <td><?= htmlspecialchars($r['nama_elemen'] ?: '-') ?></td>
                        <td style="text-align: center; font-weight: bold;"><?= htmlspecialchars($r['kode_tp'] ?: '-') ?></td>
                        <td>
                            <?php if (!empty($r['materi_pokok'])): ?><strong><?= htmlspecialchars($r['materi_pokok']) ?>:</strong> <?php endif; ?>
                            <?= htmlspecialchars($r['uraian_tp'] ?: '-') ?>
                        </td>
                        <td style="text-align: center; font-weight: bold;"><?= (int)$r['alokasi_waktu'] ?> JP</td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight: bold; background: #f9f9f9;">
                        <td colspan="5" style="text-align: right;">TOTAL ALOKASI WAKTU TAHUNAN:</td>
                        <td style="text-align: center;"><?= !empty($details) ? $totalJpTahunan : ($prota ? (int)$prota['total_jtm_tahunan'] : 0) ?> JP</td>
                    </tr>
                </tfoot>
            </table>

            <table class="sig-table">
                <tr>
                    <td>
                        Mengetahui,<br>Kepala Sekolah<br><br><br><br><br>
                        <strong><?= htmlspecialchars($kepsek) ?></strong><br>
                        <?= htmlspecialchars($nipKepsek) ?>
                    </td>
                    <td>
                        Bojonegoro, <?= date('d') . ' ' . [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'][(int)date('n')] . ' ' . date('Y') ?><br>
                        Guru Mata Pelajaran<br><br><br><br><br>
                        <strong><?= htmlspecialchars($guruNama) ?></strong><br>
                        <?= htmlspecialchars($nipGuru) ?>
                    </td>
                </tr>
            </table>
            <script>window.onload = function() { window.print(); };</script>
        </body>
        </html>
        <?php
        exit;
    }

    if ($action === 'print_prosem') {
        // Fetch prosem
        $psStmt = $pdo->prepare("SELECT * FROM prosem WHERE id_kelas_mata_pelajaran = ? AND tahun_pelajaran = ? AND semester = ? LIMIT 1");
        $psStmt->execute([$idKmp, $tahun, $semester]);
        $prosem = $psStmt->fetch(PDO::FETCH_ASSOC);

        $bulanList = ($semester === 'Ganjil')
            ? ['Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember']
            : ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni'];

        $details = [];
        $mingguan = [];
        if ($prosem) {
            $dStmt = $pdo->prepare("
                SELECT psd.*, tp.kode_tp, tp.tp as uraian_tp, tp.materi_pokok, ma.judul_modul
                FROM prosem_detail psd
                LEFT JOIN tp ON psd.id_tp = tp.id
                LEFT JOIN modul_ajar ma ON psd.id_modul_ajar = ma.id
                WHERE psd.id_prosem = ?
                ORDER BY psd.nomor_urut ASC, psd.id ASC
            ");
            $dStmt->execute([$prosem['id']]);
            $details = $dStmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($details)) {
                $ids = array_column($details, 'id');
                $inP = implode(',', array_fill(0, count($ids), '?'));
                $mStmt = $pdo->prepare("SELECT * FROM prosem_jadwal_mingguan WHERE id_prosem_detail IN ($inP)");
                $mStmt->execute($ids);
                foreach ($mStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
                    $mingguan[$m['id_prosem_detail']][$m['bulan']][$m['pekan_ke']] = (int)$m['jam_tatap_muka'];
                }
            }
        }
        ?>
        <!DOCTYPE html>
        <html lang="id">
        <head>
            <meta charset="utf-8">
            <title>Cetak PROSEM - <?= htmlspecialchars($kmpData['nama_mata_pelajaran']) ?> <?= htmlspecialchars($kmpData['nama_kelas']) ?></title>
            <style>
                @page { size: A4 landscape; margin: 12mm 12mm 12mm 12mm; }
                body { font-family: 'Times New Roman', Times, serif; font-size: 10pt; color: #000; margin: 0; padding: 15px; }
                .kop-wrapper { text-align: center; border-bottom: 3px double #000; padding-bottom: 8px; margin-bottom: 12px; }
                .kop-sekolah { font-size: 15pt; font-weight: bold; text-transform: uppercase; margin: 0; }
                .kop-alamat { font-size: 9.5pt; margin: 2px 0 0 0; }
                .doc-title { text-align: center; font-size: 13pt; font-weight: bold; text-transform: uppercase; margin: 10px 0 3px; }
                .doc-subtitle { text-align: center; font-size: 10.5pt; margin-bottom: 12px; }
                .meta-table { width: 100%; font-size: 10pt; margin-bottom: 10px; border-collapse: collapse; }
                .meta-table td { padding: 2px 4px; vertical-align: top; }
                .matrix-table { width: 100%; border-collapse: collapse; font-size: 9pt; margin-bottom: 15px; }
                .matrix-table th, .matrix-table td { border: 1px solid #000; padding: 4px 3px; vertical-align: middle; }
                .matrix-table th { background: #f2f2f2; text-align: center; font-weight: bold; }
                .sig-table { width: 100%; margin-top: 20px; border-collapse: collapse; font-size: 10pt; page-break-inside: avoid; }
                .sig-table td { text-align: center; vertical-align: top; width: 50%; }
                .no-print { margin-bottom: 15px; background: #f8fafc; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; display: flex; gap: 10px; justify-content: flex-end; }
                .btn { padding: 6px 14px; font-size: 12px; font-weight: bold; border-radius: 6px; cursor: pointer; text-decoration: none; border: 1px solid #000; }
                .btn-print { background: #0d7a87; color: #fff; border-color: #0d7a87; }
                .btn-close { background: #fff; color: #333; }
                @media print { .no-print { display: none !important; } body { padding: 0; } }
            </style>
        </head>
        <body>
            <div class="no-print">
                <button type="button" class="btn btn-print" onclick="window.print()">Cetak Dokumen (Print / PDF)</button>
                <button type="button" class="btn btn-close" onclick="window.close()">Tutup</button>
            </div>
            <div class="kop-wrapper">
                <div class="kop-sekolah"><?= htmlspecialchars($namaSekolah) ?></div>
                <div class="kop-alamat"><?= htmlspecialchars($alamatSekolah) ?></div>
            </div>
            <div class="doc-title">PROGRAM SEMESTER (PROSEM)</div>
            <div class="doc-subtitle">KURIKULUM MERDEKA &bull; SEMESTER <?= strtoupper(htmlspecialchars($semester)) ?> &bull; TAHUN AJARAN <?= htmlspecialchars($tahun) ?></div>
            <table class="meta-table">
                <tr>
                    <td style="width: 130px; font-weight: bold;">Satuan Pendidikan</td>
                    <td style="width: 8px;">:</td>
                    <td><?= htmlspecialchars($namaSekolah) ?></td>
                    <td style="width: 120px; font-weight: bold;">Mata Pelajaran</td>
                    <td style="width: 8px;">:</td>
                    <td><?= htmlspecialchars($kmpData['nama_mata_pelajaran']) ?></td>
                </tr>
                <tr>
                    <td style="font-weight: bold;">Kelas / Semester</td>
                    <td>:</td>
                    <td><?= htmlspecialchars($kmpData['nama_kelas']) ?> / <?= htmlspecialchars($semester) ?></td>
                    <td style="font-weight: bold;">Tahun Ajaran</td>
                    <td>:</td>
                    <td><?= htmlspecialchars($tahun) ?></td>
                </tr>
            </table>

            <table class="matrix-table">
                <thead>
                    <tr>
                        <th rowspan="2" style="width: 25px;">No</th>
                        <th rowspan="2" style="text-align: left; min-width: 200px;">Materi Pokok / Agenda Pembelajaran</th>
                        <th rowspan="2" style="width: 35px;">JP</th>
                        <?php foreach ($bulanList as $b): ?>
                        <th colspan="5"><?= $b ?></th>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <?php foreach ($bulanList as $b): ?>
                            <?php for ($w = 1; $w <= 5; $w++): ?>
                            <th style="width: 18px; font-size: 8pt;"><?= $w ?></th>
                            <?php endfor; ?>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($details)): ?>
                    <tr><td colspan="33" style="text-align: center; padding: 25px;">Belum ada rincian data Program Semester.</td></tr>
                    <?php else: ?>
                    <?php 
                    $colSums = [];
                    foreach ($bulanList as $b) {
                        for ($w = 1; $w <= 5; $w++) $colSums[$b][$w] = 0;
                    }
                    $totJp = 0;
                    foreach ($details as $idx => $r):
                        $totJp += (int)$r['alokasi_waktu'];
                        $label = $r['nama_agenda'] ?: ($r['materi_pokok'] ?: ($r['uraian_tp'] ? mb_substr($r['uraian_tp'], 0, 50).'...' : 'Agenda KBM'));
                    ?>
                    <tr>
                        <td style="text-align: center;"><?= $idx + 1 ?></td>
                        <td>
                            <?php if (!empty($r['kode_tp'])): ?><strong>[<?= htmlspecialchars($r['kode_tp']) ?>]</strong> <?php endif; ?>
                            <?= htmlspecialchars($label) ?>
                            <?php if ($r['kategori'] !== 'Materi KBM'): ?>
                            <em style="font-size: 8pt; color: #555;">(<?= htmlspecialchars($r['kategori']) ?>)</em>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center; font-weight: bold;"><?= (int)$r['alokasi_waktu'] ?></td>
                        <?php foreach ($bulanList as $b): ?>
                            <?php for ($w = 1; $w <= 5; $w++): 
                                $val = $mingguan[$r['id']][$b][$w] ?? 0;
                                if ($val > 0) $colSums[$b][$w] += $val;
                            ?>
                            <td style="text-align: center;"><?= $val > 0 ? $val : '' ?></td>
                            <?php endfor; ?>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight: bold; background: #f9f9f9;">
                        <td colspan="2" style="text-align: right;">TOTAL JAM PELAJARAN:</td>
                        <td style="text-align: center;"><?= $prosem ? (int)$prosem['total_alokasi_jp'] : $totJp ?></td>
                        <?php foreach ($bulanList as $b): ?>
                            <?php for ($w = 1; $w <= 5; $w++): 
                                $cs = $colSums[$b][$w] ?? 0;
                            ?>
                            <td style="text-align: center; font-size: 8.5pt;"><?= $cs > 0 ? $cs : '' ?></td>
                            <?php endfor; ?>
                        <?php endforeach; ?>
                    </tr>
                </tfoot>
            </table>

            <table class="sig-table">
                <tr>
                    <td>
                        Mengetahui,<br>Kepala Sekolah<br><br><br><br>
                        <strong><?= htmlspecialchars($kepsek) ?></strong><br>
                        <?= htmlspecialchars($nipKepsek) ?>
                    </td>
                    <td>
                        Bojonegoro, <?= date('d') . ' ' . [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'][(int)date('n')] . ' ' . date('Y') ?><br>
                        Guru Mata Pelajaran<br><br><br><br>
                        <strong><?= htmlspecialchars($guruNama) ?></strong><br>
                        <?= htmlspecialchars($nipGuru) ?>
                    </td>
                </tr>
            </table>
            <script>window.onload = function() { window.print(); };</script>
        </body>
        </html>
        <?php
        exit;
    }
}

/* ==============================================================================
 * HELPER: SINKRONISASI KALDIK KE HARI & PEKAN EFEKTIF
 * ============================================================================== */
if (!function_exists('syncKaldikToKmp')) {
    function syncKaldikToKmp($pdo, $idKmp, $tahun, $semester) {
        try {
            $stmtK = $pdo->prepare("SELECT * FROM kalender_pendidikan WHERE tahun_pelajaran = ? AND semester = ? ORDER BY tanggal ASC");
            $stmtK->execute([$tahun, $semester]);
            $kaldikList = $stmtK->fetchAll(PDO::FETCH_ASSOC);

            // Fallback cerdas jika tahun spesifik tidak ditemukan di kalender_pendidikan
            if (empty($kaldikList)) {
                $stmtAlt = $pdo->prepare("SELECT DISTINCT tahun_pelajaran FROM kalender_pendidikan WHERE semester = ? ORDER BY tahun_pelajaran DESC LIMIT 1");
                $stmtAlt->execute([$semester]);
                $altTahun = $stmtAlt->fetchColumn();
                if ($altTahun) {
                    $stmtK->execute([$altTahun, $semester]);
                    $kaldikList = $stmtK->fetchAll(PDO::FETCH_ASSOC);
                }
            }

            $pdo->beginTransaction();

            // 1. Simpan hari_efektif jika ada data tanggal kaldik
            if (!empty($kaldikList)) {
                $stmtHe = $pdo->prepare("
                    INSERT INTO hari_efektif 
                        (id_kelas_mata_pelajaran, tahun_pelajaran, semester, tanggal, hari, status_hari, keterangan)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        hari = VALUES(hari),
                        status_hari = VALUES(status_hari),
                        keterangan = VALUES(keterangan),
                        semester = VALUES(semester),
                        tahun_pelajaran = VALUES(tahun_pelajaran)
                ");

                foreach ($kaldikList as $k) {
                    $stmtHe->execute([
                        $idKmp, $tahun, $semester, $k['tanggal'],
                        $k['hari'], $k['status_hari'], $k['keterangan']
                    ]);
                }
            }

            // 2. Kalkulasi ulang pekan_efektif per bulan
            $bulanList = ($semester === 'Ganjil')
                ? [1=>['Juli',7], 2=>['Agustus',8], 3=>['September',9], 4=>['Oktober',10], 5=>['November',11], 6=>['Desember',12]]
                : [7=>['Januari',1], 8=>['Februari',2], 9=>['Maret',3], 10=>['April',4], 11=>['Mei',5], 12=>['Juni',6]];

            $stmtPe = $pdo->prepare("
                INSERT INTO pekan_efektif
                    (id_kelas_mata_pelajaran, tahun_pelajaran, semester, bulan, urutan_bulan,
                     senin, selasa, rabu, kamis, jumat, sabtu, minggu,
                     total_hari_efektif, jumlah_pekan_total, jumlah_pekan_efektif, jumlah_pekan_tidak_efektif, keterangan_tidak_efektif)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    urutan_bulan = VALUES(urutan_bulan),
                    senin = VALUES(senin), selasa = VALUES(selasa), rabu = VALUES(rabu),
                    kamis = VALUES(kamis), jumat = VALUES(jumat), sabtu = VALUES(sabtu), minggu = VALUES(minggu),
                    total_hari_efektif = VALUES(total_hari_efektif),
                    jumlah_pekan_total = VALUES(jumlah_pekan_total),
                    jumlah_pekan_efektif = VALUES(jumlah_pekan_efektif),
                    jumlah_pekan_tidak_efektif = VALUES(jumlah_pekan_tidak_efektif),
                    keterangan_tidak_efektif = VALUES(keterangan_tidak_efektif)
            ");

            foreach ($bulanList as $urutan => $bInfo) {
                $namaBulan = $bInfo[0]; $mDigit = $bInfo[1];

                $stmtH = $pdo->prepare("
                    SELECT hari, COUNT(*) as jml FROM hari_efektif
                    WHERE id_kelas_mata_pelajaran = ? AND semester = ?
                      AND status_hari = 'Efektif KBM' AND MONTH(tanggal) = ?
                    GROUP BY hari
                ");
                $stmtH->execute([$idKmp, $semester, $mDigit]);
                $counts = $stmtH->fetchAll(PDO::FETCH_KEY_PAIR);

                $senin  = (int)($counts['Senin'] ?? 0);
                $selasa = (int)($counts['Selasa'] ?? 0);
                $rabu   = (int)($counts['Rabu'] ?? 0);
                $kamis  = (int)($counts['Kamis'] ?? 0);
                $jumat  = (int)($counts['Jumat'] ?? 0);
                $sabtu  = (int)($counts['Sabtu'] ?? 0);
                $minggu = (int)($counts['Minggu'] ?? 0);

                $totalHe = $senin + $selasa + $rabu + $kamis + $jumat + $sabtu;
                $pekanTotal = in_array($mDigit, [7,8,10,12,1,3,5], true) ? 5 : 4;

                if ($totalHe === 0) {
                    // Fallback kalender standar sekolah agar tidak kosong
                    $senin = 3; $selasa = 3; $rabu = 3; $kamis = 3; $jumat = 3; $sabtu = 3;
                    $totalHe = 18;
                    $pekanEfektif = 4;
                    $pekanTidakEfektif = max(0, $pekanTotal - $pekanEfektif);
                    $ketNon = ($namaBulan === 'Desember' || $namaBulan === 'Juni') ? 'Libur Akhir Semester & Pengolahan Rapor' : 'KBM Efektif Penuh';
                } else {
                    $pekanEfektif = min($pekanTotal, max(1, (int)round($totalHe / 5)));
                    $pekanTidakEfektif = max(0, $pekanTotal - $pekanEfektif);

                    $stmtKet = $pdo->prepare("
                        SELECT GROUP_CONCAT(DISTINCT keterangan SEPARATOR '; ')
                        FROM hari_efektif
                        WHERE id_kelas_mata_pelajaran = ? AND semester = ?
                          AND status_hari != 'Efektif KBM' AND MONTH(tanggal) = ?
                    ");
                    $stmtKet->execute([$idKmp, $semester, $mDigit]);
                    $ketNon = $stmtKet->fetchColumn() ?: ($pekanTidakEfektif > 0 ? 'Kegiatan Non-KBM / Libur Semester' : 'KBM Efektif Penuh');
                }

                $stmtPe->execute([
                    $idKmp, $tahun, $semester, $namaBulan, $urutan,
                    $senin, $selasa, $rabu, $kamis, $jumat, $sabtu, $minggu,
                    $totalHe, $pekanTotal, $pekanEfektif, $pekanTidakEfektif, $ketNon
                ]);
            }

            $pdo->commit();
            return true;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Error syncKaldikToKmp: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Smart Cascade Shift & Synchronization between PROSEM & PROTA
 * Meredistribusikan seluruh alokasi mingguan agenda PROSEM secara berurutan
 * ke pekan-pekan efektif Kalender Pendidikan (pekan non-efektif diabaikan),
 * serta menyelaraskan total alokasi waktu pada baris PROTA terkait.
 */
function rebalanceProsemWeeklyGrid($pdo, $idProsem) {
    $stmt = $pdo->prepare("SELECT * FROM prosem WHERE id = ?");
    $stmt->execute([$idProsem]);
    $prosem = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$prosem) return false;

    $idKmp = (int)$prosem['id_kelas_mata_pelajaran'];
    $tahun = $prosem['tahun_pelajaran'];
    $semester = $prosem['semester'];
    $idProta = !empty($prosem['id_prota']) ? (int)$prosem['id_prota'] : 0;

    // JP per pekan dari alokasi_hari_mengajar atau jadwal_pelajaran
    $ahmStmt = $pdo->prepare("SELECT jp_per_minggu FROM alokasi_hari_mengajar WHERE id_kelas_mata_pelajaran = ? AND semester = ? LIMIT 1");
    $ahmStmt->execute([$idKmp, $semester]);
    $jpPerPekan = max(1, (int)$ahmStmt->fetchColumn() ?: 2);

    // Ambil slot pekan efektif murni dari tabel pekan_efektif (pekan non-efektif diabaikan)
    $peStmt = $pdo->prepare("SELECT * FROM pekan_efektif WHERE id_kelas_mata_pelajaran = ? AND tahun_pelajaran = ? AND semester = ? ORDER BY urutan_bulan ASC");
    $peStmt->execute([$idKmp, $tahun, $semester]);
    $peRows = $peStmt->fetchAll(PDO::FETCH_ASSOC);

    $effectiveSlots = [];
    foreach ($peRows as $pe) {
        $bName = $pe['bulan'];
        $uBln  = (int)$pe['urutan_bulan'];
        $jmlPe = (int)$pe['jumlah_pekan_efektif'];
        $jmlPt = (int)$pe['jumlah_pekan_total'];

        for ($p = 1; $p <= $jmlPt; $p++) {
            // Hanya pekan efektif yang boleh diisi KBM
            if ($p <= $jmlPe) {
                $effectiveSlots[] = [
                    'bulan' => $bName,
                    'urutan_bulan' => $uBln,
                    'pekan_ke' => $p
                ];
            }
        }
    }

    if (empty($effectiveSlots)) {
        $bulanArr = ($semester === 'Ganjil')
            ? ['Juli'=>1, 'Agustus'=>2, 'September'=>3, 'Oktober'=>4, 'November'=>5, 'Desember'=>6]
            : ['Januari'=>7, 'Februari'=>8, 'Maret'=>9, 'April'=>10, 'Mei'=>11, 'Juni'=>12];
        foreach ($bulanArr as $bName => $uBln) {
            for ($p = 1; $p <= 4; $p++) {
                $effectiveSlots[] = ['bulan' => $bName, 'urutan_bulan' => $uBln, 'pekan_ke' => $p];
            }
        }
    }

    // Ambil seluruh rincian agenda prosem_detail terurut
    $dStmt = $pdo->prepare("SELECT * FROM prosem_detail WHERE id_prosem = ? ORDER BY nomor_urut ASC, id ASC");
    $dStmt->execute([$idProsem]);
    $details = $dStmt->fetchAll(PDO::FETCH_ASSOC);

    $detailIds = array_column($details, 'id');
    if (!empty($detailIds)) {
        $inD = implode(',', $detailIds);
        $pdo->exec("DELETE FROM prosem_jadwal_mingguan WHERE id_prosem_detail IN ($inD)");
    }

    $insPjm = $pdo->prepare("
        INSERT INTO prosem_jadwal_mingguan
            (id_prosem_detail, bulan, urutan_bulan, pekan_ke, jam_tatap_muka, keterangan)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $slotIdx = 0;
    $totalSlots = count($effectiveSlots);
    $totalAllocatedJp = 0;

    foreach ($details as $row) {
        $jpRemaining = (int)$row['alokasi_waktu'];
        $dId = (int)$row['id'];
        $kat = $row['kategori'];
        $idTp = !empty($row['id_tp']) ? (int)$row['id_tp'] : 0;
        $totalAllocatedJp += $jpRemaining;

        // Sinkronisasi alokasi waktu baris PROTA jika berelasi dengan TP
        if ($idProta > 0 && $idTp > 0) {
            $pdo->prepare("UPDATE prota_detail SET alokasi_waktu = ? WHERE id_prota = ? AND id_tp = ? AND semester = ?")
                ->execute([$jpRemaining, $idProta, $idTp, $semester]);
        }

        while ($jpRemaining > 0 && $slotIdx < $totalSlots) {
            $slot = $effectiveSlots[$slotIdx];
            $alloc = min($jpPerPekan, $jpRemaining);
            $insPjm->execute([
                $dId, $slot['bulan'], $slot['urutan_bulan'], $slot['pekan_ke'], $alloc, $kat
            ]);
            $jpRemaining -= $alloc;
            $slotIdx++;
        }
    }

    // Update total alokasi di tabel prosem
    $pdo->prepare("UPDATE prosem SET total_alokasi_jp = ? WHERE id = ?")->execute([$totalAllocatedJp, $idProsem]);

    // Update rekap total JTM di tabel prota jika ada
    if ($idProta > 0) {
        $calcStmt = $pdo->prepare("SELECT semester, SUM(alokasi_waktu) FROM prota_detail WHERE id_prota = ? GROUP BY semester");
        $calcStmt->execute([$idProta]);
        $subs = $calcStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $ganjil = (int)($subs['Ganjil'] ?? 0) + 8;
        $genap = (int)($subs['Genap'] ?? 0) + 8;
        $tahunan = $ganjil + $genap;
        $pdo->prepare("UPDATE prota SET total_jtm_ganjil = ?, total_jtm_genap = ?, total_jtm_tahunan = ? WHERE id = ?")
            ->execute([$ganjil, $genap, $tahunan, $idProta]);
    }

    return true;
}

/**
 * ==============================================================================
 * HELPER: SINKRONISASI MASTER ELEMEN & CP SESUAI MAPEL & KELAS (KURIKULUM MERDEKA)
 * ==============================================================================
 * Memastikan setiap rombel kelas & mata pelajaran (KMP) memiliki master Elemen,
 * Capaian Pembelajaran (CP), dan Tujuan Pembelajaran (TP) yang presisi sesuai
 * mata pelajaran yang diampu guru.
 */
if (!function_exists('ensureCurriculumElementsForKmp')) {
    function ensureCurriculumElementsForKmp($pdo, $idKmp, $kmpInfo, $faseDefault = 'Fase E') {
        if (!$idKmp || empty($kmpInfo)) return;

        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM elemen WHERE id_kelas_mata_pelajaran = ?");
        $cntStmt->execute([$idKmp]);
        if ((int)$cntStmt->fetchColumn() > 0) {
            return; // Sudah memiliki master elemen khusus KMP ini
        }

        $mapelName = trim($kmpInfo['nama_mata_pelajaran'] ?? '');
        $idMapel   = (int)($kmpInfo['id_mata_pelajaran'] ?? 0);

        // 1. Cek apakah ada KMP lain dengan id_mata_pelajaran yang sama yang SUDAH memiliki elemen
        $srcElemenStmt = $pdo->prepare("
            SELECT e.id, e.nama_elemen, e.deskripsi, e.fase
            FROM elemen e
            JOIN kelas_mata_pelajaran kmp ON e.id_kelas_mata_pelajaran = kmp.id
            WHERE kmp.id_mata_pelajaran = ? AND e.id_kelas_mata_pelajaran != ?
            ORDER BY e.id ASC
        ");
        $srcElemenStmt->execute([$idMapel, $idKmp]);
        $srcElements = $srcElemenStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($srcElements)) {
            $uniqueByName = [];
            foreach ($srcElements as $el) {
                $key = strtolower(trim($el['nama_elemen']));
                if (!isset($uniqueByName[$key])) {
                    $uniqueByName[$key] = $el;
                }
            }

            $insEl = $pdo->prepare("INSERT INTO elemen (id_kelas_mata_pelajaran, nama_elemen, fase, deskripsi) VALUES (?, ?, ?, ?)");
            $insCp = $pdo->prepare("INSERT INTO cp (id_kelas_mata_pelajaran, id_elemen, cp) VALUES (?, ?, ?)");
            $insTp = $pdo->prepare("INSERT INTO tp (id_cp, kode_tp, tp, materi_pokok, alokasi_jam) VALUES (?, ?, ?, ?, ?)");

            foreach ($uniqueByName as $srcEl) {
                $insEl->execute([$idKmp, $srcEl['nama_elemen'], $faseDefault, $srcEl['deskripsi'] ?: "Elemen {$srcEl['nama_elemen']}"]);
                $newElId = (int)$pdo->lastInsertId();

                $srcCpStmt = $pdo->prepare("SELECT * FROM cp WHERE id_elemen = ? ORDER BY id ASC");
                $srcCpStmt->execute([$srcEl['id']]);
                $srcCps = $srcCpStmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($srcCps)) {
                    foreach ($srcCps as $sCp) {
                        $insCp->execute([$idKmp, $newElId, $sCp['cp']]);
                        $newCpId = (int)$pdo->lastInsertId();

                        $srcTpStmt = $pdo->prepare("SELECT * FROM tp WHERE id_cp = ? ORDER BY id ASC");
                        $srcTpStmt->execute([$sCp['id']]);
                        $srcTps = $srcTpStmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($srcTps as $sTp) {
                            $insTp->execute([$newCpId, $sTp['kode_tp'], $sTp['tp'], $sTp['materi_pokok'], $sTp['alokasi_jam'] ?: 4]);
                        }
                    }
                } else {
                    $insCp->execute([$idKmp, $newElId, "Capaian Pembelajaran {$srcEl['nama_elemen']} untuk {$faseDefault}."]);
                }
            }
            return;
        }

        // 2. Jika belum ada di rombel lain, sediakan template standar kurikulum merdeka sesuai mata pelajaran
        $template = [];

        // A. Akidah Akhlak (Hanya elemen Akidah dan Akhlak)
        if (preg_match('/akidah|aqidah|akhlak/i', $mapelName)) {
            $template = [
                [
                    'nama' => 'Akidah',
                    'deskripsi' => 'Elemen Akidah membimbing peserta didik memahami cabang-cabang iman (syu‘ab al-īmān) serta keterkaitan harmonis antara iman, Islam, dan ihsan.',
                    'cp' => 'Peserta didik mampu memahami beberapa cabang iman (syu‘ab al-īmān), hakikat keimanan, serta keterkaitan harmonis antara iman, Islam, dan ihsan dalam kehidupan sehari-hari.',
                    'tps' => [
                        ['kode' => '1.1', 'tp' => 'Mempelajari pengertian dan dalil tentang Syu’ab al-iman (cabang-cabang iman).', 'materi' => 'Pengertian dan Dalil Syu’ab al-iman', 'alokasi' => 4],
                        ['kode' => '1.2', 'tp' => 'Mengklasifikasikan cabang-cabang iman dan keterkaitan antara iman, Islam, dan ihsan.', 'materi' => 'Keterkaitan Iman, Islam, dan Ihsan', 'alokasi' => 4],
                        ['kode' => '1.3', 'tp' => 'Mencontohkan implementasi syu’ab al-iman dalam kehidupan pribadi dan sosial.', 'materi' => 'Implementasi Cabang Iman', 'alokasi' => 4],
                    ]
                ],
                [
                    'nama' => 'Akhlak',
                    'deskripsi' => 'Elemen Akhlak membimbing peserta didik menghindarkan diri dari berbagai penyakit sosial, membiasakan adab bermasyarakat, dan menerapkan etika digital Islami.',
                    'cp' => 'Peserta didik mampu memahami manfaat menghindari penyakit sosial; memahami adab bermasyarakat dan menerapkan etika digital dalam Islam.',
                    'tps' => [
                        ['kode' => '2.1', 'tp' => 'Mengidentifikasi beberapa penyakit sosial dalam perspektif Islam dan cara menghindarinya.', 'materi' => 'Penyakit Sosial dalam Perspektif Islam', 'alokasi' => 4],
                        ['kode' => '2.2', 'tp' => 'Menerapkan adab bermasyarakat dan etika pergaulan Islami dalam kehidupan sehari-hari.', 'materi' => 'Adab Bermasyarakat Islami', 'alokasi' => 4],
                        ['kode' => '2.3', 'tp' => 'Menerapkan etika digital dan bijak bermedia sosial sesuai tuntunan syariat Islam.', 'materi' => 'Etika Digital dalam Islam', 'alokasi' => 4],
                    ]
                ]
            ];
        }
        // B. Al-Qur'an Hadits & Tajwid
        elseif (preg_match('/qur[\'’]?an|hadis|hadits|tajwid/i', $mapelName)) {
            $template = [
                [
                    'nama' => 'Al-Qur’an Hadis',
                    'deskripsi' => 'Elemen Al-Qur’an dan Hadis membimbing peserta didik memahami ayat suci dan hadis tentang pentingnya berpikir kritis, ilmu pengetahuan dan teknologi, memelihara kehidupan, dan moderasi beragama.',
                    'cp' => 'Peserta didik mampu memahami dan menganalisis ayat Al-Qur’an dan hadis tentang pentingnya berpikir kritis, ilmu pengetahuan dan teknologi, memelihara kehidupan manusia, dan moderasi beragama.',
                    'tps' => [
                        ['kode' => '1.1', 'tp' => 'Membaca dan menghafalkan ayat Al-Qur’an dan Hadis pilihan beserta artinya dengan lancar dan tartil.', 'materi' => 'Tartil Membaca dan Menghafal Al-Qur’an & Hadis', 'alokasi' => 4],
                        ['kode' => '1.2', 'tp' => 'Menganalisis pesan utama dari ayat Al-Qur’an dan Hadis tentang berpikir kritis dan moderasi beragama.', 'materi' => 'Berpikir Kritis & Moderasi Beragama', 'alokasi' => 4],
                        ['kode' => '1.3', 'tp' => 'Menerapkan hukum bacaan tajwid dalam membaca ayat-ayat Al-Qur’an pilihan secara fasih.', 'materi' => 'Kaidah Tajwid Al-Qur’an', 'alokasi' => 4]
                    ]
                ]
            ];
        }
        // C. Fikih / Fiqih Ibadah
        elseif (preg_match('/fiq?ih|ibadah/i', $mapelName)) {
            $template = [
                [
                    'nama' => 'Fikih Ibadah',
                    'deskripsi' => 'Elemen Fikih membimbing peserta didik memahami ketentuan ibadah, prinsip muamalah syariah modern, munakahat, dan mawaris.',
                    'cp' => 'Peserta didik mampu memahami ketentuan ibadah, prinsip muamalah syariah modern, munakahat, dan mawaris sesuai tuntunan fikih Islam.',
                    'tps' => [
                        ['kode' => '1.1', 'tp' => 'Menelusuri dan menerapkan ketentuan tata cara ibadah sesuai sunnah.', 'materi' => 'Ketentuan Ibadah Praktis', 'alokasi' => 4],
                        ['kode' => '1.2', 'tp' => 'Menggali dan menganalisis prinsip fiqh muamalah syariah modern dalam kehidupan sehari-hari.', 'materi' => 'Fiqh Muamalah Kontemporer', 'alokasi' => 4]
                    ]
                ]
            ];
        }
        // D. Sejarah Kebudayaan Islam (SKI)
        elseif (preg_match('/sejarah|ski|kebudayaan islam/i', $mapelName)) {
            $template = [
                [
                    'nama' => 'Sejarah Kebudayaan Islam',
                    'deskripsi' => 'Elemen Sejarah Kebudayaan Islam membimbing peserta didik menelusuri keteladanan dan kontribusi dakwah para ulama dunia dan ulama Nusantara serta peradaban Islam.',
                    'cp' => 'Peserta didik mampu memahami peran tokoh ulama dalam perkembangan peradaban Islam di dunia dan peran organisasi-organisasi Islam di Indonesia.',
                    'tps' => [
                        ['kode' => '1.1', 'tp' => 'Mengkaji biografi dan keteladanan dakwah tokoh ulama dunia dan Nusantara.', 'materi' => 'Peran Ulama Dunia dan Nusantara', 'alokasi' => 4],
                        ['kode' => '1.2', 'tp' => 'Menganalisis sejarah perkembangan dan kontribusi organisasi Islam dalam memajukan peradaban.', 'materi' => 'Peran Organisasi Islam di Indonesia', 'alokasi' => 4]
                    ]
                ]
            ];
        }
        // E. Bahasa Arab
        elseif (preg_match('/bahasa arab|arab/i', $mapelName)) {
            $template = [
                [
                    'nama' => 'Menyimak (Istima\')',
                    'deskripsi' => 'Kemampuan memahami, mengidentifikasi ide pokok, dan merespons wacana lisan bahasa Arab.',
                    'cp' => 'Peserta didik mampu menyimak dan memahami ide pokok dari wacana lisan bahasa Arab sederhana.',
                    'tps' => [
                        ['kode' => '1.1', 'tp' => 'Mengidentifikasi bunyi huruf hijaiyah dan kosakata bahasa Arab dari teks lisan.', 'materi' => 'Kosakata & Ungkapan Bahasa Arab Lisan', 'alokasi' => 4]
                    ]
                ],
                [
                    'nama' => 'Berbicara (Kalam)',
                    'deskripsi' => 'Kemampuan mengungkapkan pikiran, perasaan, dan informasi secara lisan dalam bahasa Arab.',
                    'cp' => 'Peserta didik mampu berdialog dan menyampaikan informasi secara lisan dalam bahasa Arab.',
                    'tps' => [
                        ['kode' => '2.1', 'tp' => 'Mempraktikkan percakapan sehari-hari dalam bahasa Arab dengan intonasi yang tepat.', 'materi' => 'Muhadatsah Harian', 'alokasi' => 4]
                    ]
                ],
                [
                    'nama' => 'Membaca - Memirsa (Qira\'ah)',
                    'deskripsi' => 'Kemampuan membaca, memahami, dan menganalisis teks tertulis bahasa Arab.',
                    'cp' => 'Peserta didik mampu membaca dan memahami teks bahasa Arab dengan kaidah tata bahasa dasar.',
                    'tps' => [
                        ['kode' => '3.1', 'tp' => 'Membaca teks naratif bahasa Arab secara tartil dan memahami kandungannya.', 'materi' => 'Qira\'ah Mutamayyizah', 'alokasi' => 4]
                    ]
                ],
                [
                    'nama' => 'Menulis - Mempresentasikan (Kitabah)',
                    'deskripsi' => 'Kemampuan menyusun kalimat dan teks bahasa Arab secara tertulis dengan kaidah imla\' dan nahwu-sharaf.',
                    'cp' => 'Peserta didik mampu menyusun kalimat dan teks sederhana bahasa Arab dengan tepat.',
                    'tps' => [
                        ['kode' => '4.1', 'tp' => 'Menulis kalimat terstruktur dalam bahasa Arab sesuai kaidah tata bahasa dasar.', 'materi' => 'Insya\' & Kitabah', 'alokasi' => 4]
                    ]
                ]
            ];
        }
        // F. Pendidikan Agama Islam & Budi Pekerti (PAI)
        elseif (preg_match('/pendidikan agama islam|pai/i', $mapelName)) {
            $template = [
                [
                    'nama' => 'Al-Qur’an Hadis',
                    'deskripsi' => 'Elemen Al-Qur’an dan Hadis membimbing peserta didik memahami ayat suci dan hadis tentang berpikir kritis, iptek, dan moderasi beragama.',
                    'cp' => 'Memahami ayat Al-Qur’an dan hadist tentang pentingnya berpikir kritis, ilmu pengetahuan dan teknologi, memelihara kehidupan manusia, dan moderasi beragama.',
                    'tps' => [
                        ['kode' => '1.1', 'tp' => 'Menghafal dan menafsirkan ayat Al-Qur\'an dan Hadis tentang berpikir kritis dan IPTEK.', 'materi' => 'Berpikir Kritis dalam Islam', 'alokasi' => 4]
                    ]
                ],
                [
                    'nama' => 'Akidah',
                    'deskripsi' => 'Elemen Akidah membimbing peserta didik memahami cabang-cabang iman (syu‘ab al-īmān) serta keterkaitan iman, Islam, dan ihsan.',
                    'cp' => 'Memahami beberapa cabang iman (syu‘ab al īmān), keterkaitan antara iman, Islam, dan ihsan.',
                    'tps' => [
                        ['kode' => '2.1', 'tp' => 'Mempelajari pengertian dan dalil tentang Syu’ab al-iman serta keterkaitan iman, Islam, dan ihsan.', 'materi' => 'Pengertian Syu’ab al-iman', 'alokasi' => 4]
                    ]
                ],
                [
                    'nama' => 'Akhlak',
                    'deskripsi' => 'Elemen Akhlak membimbing peserta didik menghindarkan diri dari penyakit sosial dan membiasakan adab bermasyarakat.',
                    'cp' => 'Memahami manfaat menghindari penyakit sosial; Memahami adab bermasyarakat dan etika digital dalam Islam.',
                    'tps' => [
                        ['kode' => '3.1', 'tp' => 'Menunjukkan beberapa penyakit sosial dalam perspektif Islam dan mempraktikkan adab bermasyarakat.', 'materi' => 'Adab Bermasyarakat', 'alokasi' => 4]
                    ]
                ],
                [
                    'nama' => 'Fikih',
                    'deskripsi' => 'Elemen Fikih membimbing peserta didik memahami ketentuan ibadah, khotbah, tabligh, dakwah, dan muamalah syariah.',
                    'cp' => 'Memahami ketentuan khotbah, tabligh dan dakwah, muamalah, munakahat, dan mawaris.',
                    'tps' => [
                        ['kode' => '4.1', 'tp' => 'Menelusuri ketentuan khotbah, tabligh, dakwah, dan muamalah syariah.', 'materi' => 'Khotbah & Muamalah Syariah', 'alokasi' => 4]
                    ]
                ],
                [
                    'nama' => 'Sejarah Peradaban Islam',
                    'deskripsi' => 'Elemen Sejarah Peradaban Islam membimbing peserta didik menelusuri keteladanan dan kontribusi ulama dunia dan ulama Nusantara.',
                    'cp' => 'Memahami peran tokoh ulama dalam perkembangan peradaban Islam di dunia dan peran organisasi-organisasi Islam di Indonesia.',
                    'tps' => [
                        ['kode' => '5.1', 'tp' => 'Menganalisis peran dan kontribusi tokoh ulama dunia dan Nusantara.', 'materi' => 'Peran Ulama Islam', 'alokasi' => 4]
                    ]
                ]
            ];
        }
        // G. Mapel Umum / Lainnya
        else {
            $cleanName = !empty($mapelName) ? $mapelName : 'Materi Pembelajaran';
            $template = [
                [
                    'nama' => $cleanName,
                    'deskripsi' => "Lingkup materi capaian pembelajaran mata pelajaran {$cleanName}.",
                    'cp' => "Capaian Pembelajaran mata pelajaran {$cleanName} untuk {$faseDefault}.",
                    'tps' => [
                        ['kode' => '1.1', 'tp' => "Memahami konsep dasar dan materi pokok pembelajaran {$cleanName}.", 'materi' => "Materi Pokok {$cleanName}", 'alokasi' => 4]
                    ]
                ]
            ];
        }

        $insEl = $pdo->prepare("INSERT INTO elemen (id_kelas_mata_pelajaran, nama_elemen, fase, deskripsi) VALUES (?, ?, ?, ?)");
        $insCp = $pdo->prepare("INSERT INTO cp (id_kelas_mata_pelajaran, id_elemen, cp) VALUES (?, ?, ?)");
        $insTp = $pdo->prepare("INSERT INTO tp (id_cp, kode_tp, tp, materi_pokok, alokasi_jam) VALUES (?, ?, ?, ?, ?)");

        foreach ($template as $item) {
            $insEl->execute([$idKmp, $item['nama'], $faseDefault, $item['deskripsi']]);
            $newElId = (int)$pdo->lastInsertId();

            $insCp->execute([$idKmp, $newElId, $item['cp']]);
            $newCpId = (int)$pdo->lastInsertId();

            if (!empty($item['tps'])) {
                foreach ($item['tps'] as $tp) {
                    $insTp->execute([$newCpId, $tp['kode'], $tp['tp'], $tp['materi'], $tp['alokasi']]);
                }
            }
        }
    }
}

/* ==============================================================================
 * BACKEND AJAX HANDLERS (JSON API)
 * ============================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];

    try {
        // 1. LIST KELAS GURU
        if ($action === 'list_kelas_options') {
            $params = [];
            $whereClause = "";

            if ($activeRole === 'guru' && ($idGuruLogged > 0 || $idUserLogged > 0)) {
                $whereClause = "WHERE k.id IN (
                    SELECT kmp.id_kelas 
                    FROM kelas_mata_pelajaran kmp
                    LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
                    WHERE gmp.id_guru = :id_guru 
                       OR kmp.id IN (SELECT id_kelas_mata_pelajaran FROM prota WHERE id_guru = :id_user1 OR id_guru = :id_guru1)
                       OR kmp.id IN (SELECT id_kelas_mata_pelajaran FROM prosem WHERE id_guru = :id_user2 OR id_guru = :id_guru2)
                       OR kmp.id IN (SELECT id_kelas_mata_pelajaran FROM jadwal_pelajaran WHERE id_guru = :id_guru3)
                )";
                $params[':id_guru']  = $idGuruLogged;
                $params[':id_user1'] = $idUserLogged;
                $params[':id_guru1'] = $idGuruLogged;
                $params[':id_user2'] = $idUserLogged;
                $params[':id_guru2'] = $idGuruLogged;
                $params[':id_guru3'] = $idGuruLogged;
            }

            $sql = "SELECT k.id, k.nama_kelas, k.tahun_pelajaran,
                           (SELECT COUNT(*) FROM siswa s WHERE s.id_kelas = k.id) AS total_siswa
                    FROM kelas k
                    {$whereClause}
                    ORDER BY k.nama_kelas ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $kelas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($kelas)) {
                $kelas = $pdo->query("SELECT id, nama_kelas, tahun_pelajaran, 
                    (SELECT COUNT(*) FROM siswa s WHERE s.id_kelas = kelas.id) AS total_siswa 
                    FROM kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode(['success' => true, 'data' => ['kelas' => $kelas ?: []]]);
            exit;
        }

        // 2. LIST MAPEL BY KELAS
        if ($action === 'get_mapel_by_kelas') {
            $idKelas = (int)($_POST['id_kelas'] ?? 0);
            $params = [$idKelas];
            $whereGuru = "";

            if ($activeRole === 'guru' && ($idGuruLogged > 0 || $idUserLogged > 0)) {
                $whereGuru = " AND (gmp.id_guru = ? OR kmp.id IN (SELECT id_kelas_mata_pelajaran FROM prota WHERE id_guru = ? OR id_guru = ?) OR kmp.id IN (SELECT id_kelas_mata_pelajaran FROM prosem WHERE id_guru = ? OR id_guru = ?))";
                $params[] = $idGuruLogged;
                $params[] = $idUserLogged;
                $params[] = $idGuruLogged;
                $params[] = $idUserLogged;
                $params[] = $idGuruLogged;
            }

            $stmt = $pdo->prepare("
                SELECT kmp.id, kmp.id_mata_pelajaran, mp.nama_mata_pelajaran, mp.kode_mapel,
                       COALESCE(g.nama_guru, 'Dewan Guru') AS nama_guru
                FROM kelas_mata_pelajaran kmp
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
                LEFT JOIN guru g ON gmp.id_guru = g.id
                WHERE kmp.id_kelas = ? {$whereGuru}
                ORDER BY mp.nama_mata_pelajaran ASC
            ");
            $stmt->execute($params);
            $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($list)) {
                $stmtFallback = $pdo->prepare("
                    SELECT kmp.id, kmp.id_mata_pelajaran, mp.nama_mata_pelajaran, mp.kode_mapel,
                           'Dewan Guru' AS nama_guru
                    FROM kelas_mata_pelajaran kmp
                    JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                    WHERE kmp.id_kelas = ?
                    ORDER BY mp.nama_mata_pelajaran ASC
                ");
                $stmtFallback->execute([$idKelas]);
                $list = $stmtFallback->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode(['success' => true, 'data' => ['mapel' => $list ?: []]]);
            exit;
        }

        // 3. GET PROTA & PROSEM COMPLETE STATE
        if ($action === 'get_prota_prosem_state') {
            $idKelas = (int)($_POST['id_kelas'] ?? 0);
            $idKmp   = (int)($_POST['id_kmp'] ?? 0);
            $tahun   = trim((string)($_POST['tahun_pelajaran'] ?? '2026/2027'));
            $semester = trim((string)($_POST['semester'] ?? 'Ganjil'));

            if ($idKmp <= 0) {
                throw new Exception("Pilih rombel kelas dan mata pelajaran terlebih dahulu.");
            }

            // Sync Kaldik jika data pekan_efektif belum ada untuk KMP ini
            $peCheck = $pdo->prepare("SELECT COUNT(*) FROM pekan_efektif WHERE id_kelas_mata_pelajaran = ? AND tahun_pelajaran = ? AND semester = ?");
            $peCheck->execute([$idKmp, $tahun, $semester]);
            if ((int)$peCheck->fetchColumn() === 0) {
                syncKaldikToKmp($pdo, $idKmp, $tahun, $semester);
            }

            // Ambil Info Kelas & Mapel
            $kmpInfoStmt = $pdo->prepare("
                SELECT kmp.id, kmp.id_kelas, kmp.id_mata_pelajaran, k.nama_kelas, mp.nama_mata_pelajaran, mp.kode_mapel
                FROM kelas_mata_pelajaran kmp
                JOIN kelas k ON kmp.id_kelas = k.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                WHERE kmp.id = ?
            ");
            $kmpInfoStmt->execute([$idKmp]);
            $kmpInfo = $kmpInfoStmt->fetch(PDO::FETCH_ASSOC);

            // Deteksi Fase dari Nama Kelas (Merdeka)
            $faseDefault = 'Fase F';
            if ($kmpInfo) {
                if (preg_match('/\b(10|x)\b/i', $kmpInfo['nama_kelas'])) {
                    $faseDefault = 'Fase E';
                } elseif (preg_match('/\b(7|8|9|vii|viii|ix)\b/i', $kmpInfo['nama_kelas'])) {
                    $faseDefault = 'Fase D';
                }
            }

            // Ambil Data Rincian Pekan Efektif (RPE)
            $peStmt = $pdo->prepare("
                SELECT * FROM pekan_efektif
                WHERE id_kelas_mata_pelajaran = ? AND tahun_pelajaran = ? AND semester = ?
                ORDER BY urutan_bulan ASC
            ");
            $peStmt->execute([$idKmp, $tahun, $semester]);
            $peRows = $peStmt->fetchAll(PDO::FETCH_ASSOC);

            $totalHeSemester = 0;
            $totalPeSemester = 0;
            $totalPteSemester = 0;
            foreach ($peRows as $pe) {
                $totalHeSemester += (int)$pe['total_hari_efektif'];
                $totalPeSemester += (int)$pe['jumlah_pekan_efektif'];
                $totalPteSemester += (int)$pe['jumlah_pekan_tidak_efektif'];
            }

            // Ambil atau Inisialisasi Alokasi Hari Mengajar Guru
            $ahmStmt = $pdo->prepare("
                SELECT * FROM alokasi_hari_mengajar
                WHERE id_kelas_mata_pelajaran = ? AND semester = ?
                ORDER BY id ASC LIMIT 1
            ");
            $ahmStmt->execute([$idKmp, $semester]);
            $ahm = $ahmStmt->fetch(PDO::FETCH_ASSOC);

            // Jika belum ada alokasi hari mengajar, cari hari dari jadwal_pelajaran atau default 'Senin'
            if (!$ahm) {
                $hariMengajar = 'Senin';
                $jpPerMinggu = 2;

                $jadwalStmt = $pdo->prepare("SELECT hari, alokasi_jp FROM jadwal_pelajaran WHERE id_kelas_mata_pelajaran = ? AND semester = ? LIMIT 1");
                $jadwalStmt->execute([$idKmp, $semester]);
                $jRow = $jadwalStmt->fetch(PDO::FETCH_ASSOC);
                if ($jRow && !empty($jRow['hari'])) {
                    $hariMengajar = $jRow['hari'];
                    if (!empty($jRow['alokasi_jp'])) $jpPerMinggu = (int)$jRow['alokasi_jp'];
                }

                // Hitung banyaknya hari efektif untuk hari ini
                $colDay = strtolower($hariMengajar);
                $banyakHari = 0;
                foreach ($peRows as $pe) {
                    $banyakHari += (int)($pe[$colDay] ?? 0);
                }
                if ($banyakHari === 0) $banyakHari = 18; // Fallback jika kaldik belum lengkap

                $totalJtm = $banyakHari * $jpPerMinggu;

                $pdo->prepare("
                    INSERT INTO alokasi_hari_mengajar 
                        (id_kelas_mata_pelajaran, id_guru, semester, hari, banyaknya_hari_efektif, jp_per_minggu, total_jtm)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ")->execute([$idKmp, ($idUserLogged > 0 ? $idUserLogged : null), $semester, $hariMengajar, $banyakHari, $jpPerMinggu, $totalJtm]);

                $ahmStmt->execute([$idKmp, $semester]);
                $ahm = $ahmStmt->fetch(PDO::FETCH_ASSOC);
            }

            // AMBIL DATA PROTA
            $protaStmt = $pdo->prepare("
                SELECT * FROM prota
                WHERE id_kelas_mata_pelajaran = ? AND tahun_pelajaran = ?
                LIMIT 1
            ");
            $protaStmt->execute([$idKmp, $tahun]);
            $prota = $protaStmt->fetch(PDO::FETCH_ASSOC);

            $protaDetails = [];
            if ($prota) {
                $pdStmt = $pdo->prepare("
                    SELECT pd.*, e.nama_elemen, tp.kode_tp, tp.tp as uraian_tp, tp.materi_pokok, itp.kode_itp, COALESCE(itp.itp, itp.deskripsi_ketercapaian, '') as indikator
                    FROM prota_detail pd
                    LEFT JOIN elemen e ON pd.id_elemen = e.id
                    LEFT JOIN tp ON pd.id_tp = tp.id
                    LEFT JOIN itp ON pd.id_itp = itp.id
                    WHERE pd.id_prota = ?
                    ORDER BY pd.semester ASC, pd.nomor_urut ASC, pd.id ASC
                ");
                $pdStmt->execute([$prota['id']]);
                $protaDetails = $pdStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // AMBIL DATA PROSEM
            $prosemStmt = $pdo->prepare("
                SELECT * FROM prosem
                WHERE id_kelas_mata_pelajaran = ? AND tahun_pelajaran = ? AND semester = ?
                LIMIT 1
            ");
            $prosemStmt->execute([$idKmp, $tahun, $semester]);
            $prosem = $prosemStmt->fetch(PDO::FETCH_ASSOC);

            $prosemDetails = [];
            $prosemMingguan = []; // Map: [id_detail][bulan][pekan] => jam_tatap_muka
            if ($prosem) {
                $psdStmt = $pdo->prepare("
                    SELECT psd.*, tp.kode_tp, tp.tp as uraian_tp, tp.materi_pokok, itp.kode_itp, COALESCE(itp.itp, itp.deskripsi_ketercapaian, '') as indikator, ma.judul_modul
                    FROM prosem_detail psd
                    LEFT JOIN tp ON psd.id_tp = tp.id
                    LEFT JOIN itp ON psd.id_itp = itp.id
                    LEFT JOIN modul_ajar ma ON psd.id_modul_ajar = ma.id
                    WHERE psd.id_prosem = ?
                    ORDER BY psd.nomor_urut ASC, psd.id ASC
                ");
                $psdStmt->execute([$prosem['id']]);
                $prosemDetails = $psdStmt->fetchAll(PDO::FETCH_ASSOC);

                // Ambil seluruh jadwal mingguan
                if (!empty($prosemDetails)) {
                    $detailIds = array_column($prosemDetails, 'id');
                    $inPlaceholders = implode(',', array_fill(0, count($detailIds), '?'));
                    $pjmStmt = $pdo->prepare("
                        SELECT * FROM prosem_jadwal_mingguan
                        WHERE id_prosem_detail IN ($inPlaceholders)
                    ");
                    $pjmStmt->execute($detailIds);
                    $rawPjm = $pjmStmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rawPjm as $jm) {
                        $dId = $jm['id_prosem_detail'];
                        $bln = $jm['bulan'];
                        $pek = $jm['pekan_ke'];
                        $prosemMingguan[$dId][$bln][$pek] = (int)$jm['jam_tatap_muka'];
                    }
                }
            }

            // PASTIKAN MASTER ELEMEN, CP, & TP TERSEDIA KHUSUS UNTUK KELAS & MAPEL INI
            ensureCurriculumElementsForKmp($pdo, $idKmp, $kmpInfo, $faseDefault);

            // AMBIL MASTER ELEMEN KHUSUS MATA PELAJARAN & ROMBEL KELAS INI
            $idMapel = (int)($kmpInfo['id_mata_pelajaran'] ?? 0);

            $elemenList = $pdo->prepare("
                SELECT e.id, e.nama_elemen, e.fase, e.deskripsi
                FROM elemen e
                WHERE e.id_kelas_mata_pelajaran = ?
                ORDER BY e.id ASC
            ");
            $elemenList->execute([$idKmp]);
            $masterElemen = $elemenList->fetchAll(PDO::FETCH_ASSOC);

            if (empty($masterElemen)) {
                $elemenList = $pdo->prepare("
                    SELECT e.id, e.nama_elemen, e.fase, e.deskripsi
                    FROM elemen e
                    JOIN kelas_mata_pelajaran kmp ON e.id_kelas_mata_pelajaran = kmp.id
                    WHERE kmp.id_mata_pelajaran = ?
                    ORDER BY e.id ASC
                ");
                $elemenList->execute([$idMapel]);
                $masterElemen = $elemenList->fetchAll(PDO::FETCH_ASSOC);
            }

            // AMBIL MASTER CAPAIAN PEMBELAJARAN (CP) KHUSUS ELEMEN & MAPEL INI
            $cpList = $pdo->prepare("
                SELECT cp.id, cp.id_elemen, cp.cp, e.nama_elemen
                FROM cp
                JOIN elemen e ON cp.id_elemen = e.id
                WHERE cp.id_kelas_mata_pelajaran = ?
                ORDER BY cp.id ASC
            ");
            $cpList->execute([$idKmp]);
            $masterCp = $cpList->fetchAll(PDO::FETCH_ASSOC);

            if (empty($masterCp)) {
                $cpList = $pdo->prepare("
                    SELECT cp.id, cp.id_elemen, cp.cp, e.nama_elemen
                    FROM cp
                    JOIN elemen e ON cp.id_elemen = e.id
                    JOIN kelas_mata_pelajaran kmp ON cp.id_kelas_mata_pelajaran = kmp.id
                    WHERE kmp.id_mata_pelajaran = ?
                    ORDER BY cp.id ASC
                ");
                $cpList->execute([$idMapel]);
                $masterCp = $cpList->fetchAll(PDO::FETCH_ASSOC);
            }

            // AMBIL MASTER TP KHUSUS MAPEL & CP ROMBEL INI
            $tpList = $pdo->prepare("
                SELECT tp.id, tp.id_cp, tp.kode_tp, tp.tp, tp.materi_pokok, tp.alokasi_jam, cp.id_elemen, e.nama_elemen
                FROM tp
                JOIN cp ON tp.id_cp = cp.id
                JOIN elemen e ON cp.id_elemen = e.id
                WHERE cp.id_kelas_mata_pelajaran = ?
                ORDER BY tp.id ASC
            ");
            $tpList->execute([$idKmp]);
            $masterTp = $tpList->fetchAll(PDO::FETCH_ASSOC);

            if (empty($masterTp)) {
                $tpList = $pdo->prepare("
                    SELECT tp.id, tp.id_cp, tp.kode_tp, tp.tp, tp.materi_pokok, tp.alokasi_jam, cp.id_elemen, e.nama_elemen
                    FROM tp
                    JOIN cp ON tp.id_cp = cp.id
                    JOIN elemen e ON cp.id_elemen = e.id
                    JOIN kelas_mata_pelajaran kmp ON cp.id_kelas_mata_pelajaran = kmp.id
                    WHERE kmp.id_mata_pelajaran = ?
                    ORDER BY tp.id ASC
                ");
                $tpList->execute([$idMapel]);
                $masterTp = $tpList->fetchAll(PDO::FETCH_ASSOC);
            }

            // AMBIL MODUL AJAR
            $maStmt = $pdo->prepare("
                SELECT id, judul_modul, fase FROM modul_ajar 
                WHERE id_kelas_mata_pelajaran = ? 
                   OR id_kelas_mata_pelajaran IN (SELECT id FROM kelas_mata_pelajaran WHERE id_mata_pelajaran = ?)
                ORDER BY judul_modul ASC
            ");
            $maStmt->execute([$idKmp, $idMapel]);
            $masterModul = $maStmt->fetchAll(PDO::FETCH_ASSOC);

            // HITUNG KALKULASI ALOKASI WAKTU KBM & PEKAN EFEKTIF DARI DATABASE
            $jpPerMinggu = $ahm ? max(1, (int)$ahm['jp_per_minggu']) : 2;
            $kapasitasJtmSemester = $totalPeSemester * $jpPerMinggu;
            $jpTerpakaiSemester = 0;
            if (!empty($protaDetails)) {
                foreach ($protaDetails as $pd) {
                    if ($pd['semester'] === $semester) {
                        $jpTerpakaiSemester += (int)$pd['alokasi_waktu'];
                    }
                }
            }
            $sisaJpSemester = max(0, $kapasitasJtmSemester - $jpTerpakaiSemester);

            $perhitunganAlokasi = [
                'total_pekan_efektif' => $totalPeSemester,
                'total_pekan_tidak_efektif' => $totalPteSemester,
                'jp_per_pekan' => $jpPerMinggu,
                'kapasitas_jtm_semester' => $kapasitasJtmSemester,
                'jp_terpakai_semester' => $jpTerpakaiSemester,
                'sisa_jp_semester' => $sisaJpSemester,
                'hari_mengajar' => $ahm ? $ahm['hari'] : 'Senin'
            ];

            echo json_encode([
                'success' => true,
                'data' => [
                    'kmp_info' => $kmpInfo,
                    'fase_default' => $faseDefault,
                    'rpe' => [
                        'rows' => $peRows,
                        'total_he' => $totalHeSemester,
                        'total_pe' => $totalPeSemester,
                        'total_pte' => $totalPteSemester
                    ],
                    'alokasi_mengajar' => $ahm,
                    'perhitungan_alokasi' => $perhitunganAlokasi,
                    'prota' => $prota,
                    'prota_details' => $protaDetails,
                    'prosem' => $prosem,
                    'prosem_details' => $prosemDetails,
                    'prosem_mingguan' => $prosemMingguan,
                    'master_elemen' => $masterElemen ?: [],
                    'master_cp' => $masterCp ?: [],
                    'master_tp' => $masterTp ?: [],
                    'master_modul' => $masterModul ?: []
                ]
            ]);
            exit;
        }

        // 4. SINKRONISASI CEPAT KALDIK
        if ($action === 'sync_kaldik_ke_kmp') {
            $idKmp = (int)($_POST['id_kmp'] ?? 0);
            $tahun = trim((string)($_POST['tahun_pelajaran'] ?? '2025/2026'));
            $semester = trim((string)($_POST['semester'] ?? 'Ganjil'));

            if ($idKmp <= 0) throw new Exception("Pilih rombel dan mata pelajaran.");

            $ok = syncKaldikToKmp($pdo, $idKmp, $tahun, $semester);
            if (!$ok) throw new Exception("Gagal menyinkronkan data Kaldik. Pastikan Master Kalender Pendidikan telah diisi oleh Administrator.");

            echo json_encode(['success' => true, 'message' => 'Kalender Pendidikan berhasil disinkronkan ke kelas & mata pelajaran ini!']);
            exit;
        }

        // 5. SIMPAN ALOKASI HARI MENGAJAR
        if ($action === 'save_alokasi_hari_mengajar') {
            $idKmp = (int)($_POST['id_kmp'] ?? 0);
            $semester = trim((string)($_POST['semester'] ?? 'Ganjil'));
            $hari = trim((string)($_POST['hari'] ?? 'Senin'));
            $jpPerMinggu = max(1, (int)($_POST['jp_per_minggu'] ?? 2));
            $tahun = trim((string)($_POST['tahun_pelajaran'] ?? '2025/2026'));

            if ($idKmp <= 0) throw new Exception("Pilih rombel dan mata pelajaran.");

            // Hitung banyaknya hari efektif pada hari tersebut dari pekan_efektif / hari_efektif
            $colDay = strtolower($hari);
            $stmtPe = $pdo->prepare("SELECT SUM($colDay) FROM pekan_efektif WHERE id_kelas_mata_pelajaran = ? AND semester = ? AND tahun_pelajaran = ?");
            $stmtPe->execute([$idKmp, $semester, $tahun]);
            $banyakHari = (int)$stmtPe->fetchColumn();
            if ($banyakHari === 0) {
                // Fallback dari hari_efektif
                $stmtHe = $pdo->prepare("SELECT COUNT(*) FROM hari_efektif WHERE id_kelas_mata_pelajaran = ? AND semester = ? AND hari = ? AND status_hari = 'Efektif KBM'");
                $stmtHe->execute([$idKmp, $semester, $hari]);
                $banyakHari = (int)$stmtHe->fetchColumn();
            }
            if ($banyakHari === 0) $banyakHari = 18;

            $totalJtm = $banyakHari * $jpPerMinggu;

            $stmtCheck = $pdo->prepare("SELECT id FROM alokasi_hari_mengajar WHERE id_kelas_mata_pelajaran = ? AND semester = ? LIMIT 1");
            $stmtCheck->execute([$idKmp, $semester]);
            $existingId = $stmtCheck->fetchColumn();

            if ($existingId) {
                $stmtUp = $pdo->prepare("
                    UPDATE alokasi_hari_mengajar 
                    SET hari = ?, jp_per_minggu = ?, banyaknya_hari_efektif = ?, total_jtm = ?, id_guru = ?
                    WHERE id = ?
                ");
                $stmtUp->execute([$hari, $jpPerMinggu, $banyakHari, $totalJtm, ($idUserLogged > 0 ? $idUserLogged : null), $existingId]);
            } else {
                $stmtIn = $pdo->prepare("
                    INSERT INTO alokasi_hari_mengajar 
                        (id_kelas_mata_pelajaran, id_guru, semester, hari, banyaknya_hari_efektif, jp_per_minggu, total_jtm)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtIn->execute([$idKmp, ($idUserLogged > 0 ? $idUserLogged : null), $semester, $hari, $banyakHari, $jpPerMinggu, $totalJtm]);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Alokasi hari mengajar berhasil diperbarui!',
                'data' => [
                    'hari' => $hari,
                    'banyaknya_hari_efektif' => $banyakHari,
                    'jp_per_minggu' => $jpPerMinggu,
                    'total_jtm' => $totalJtm
                ]
            ]);
            exit;
        }

        // 6. GENERATE PROTA OTOMATIS DARI DATABASE TP/CP
        if ($action === 'generate_prota_otomatis') {
            $idKmp = (int)($_POST['id_kmp'] ?? 0);
            $tahun = trim((string)($_POST['tahun_pelajaran'] ?? '2026/2027'));
            $fase = trim((string)($_POST['fase'] ?? 'Fase F'));

            if ($idKmp <= 0) throw new Exception("Pilih rombel dan mata pelajaran.");

            $pdo->beginTransaction();

            // 1. Ambil atau Buat Header Prota
            $protaStmt = $pdo->prepare("SELECT id FROM prota WHERE id_kelas_mata_pelajaran = ? AND tahun_pelajaran = ? LIMIT 1");
            $protaStmt->execute([$idKmp, $tahun]);
            $idProta = $protaStmt->fetchColumn();

            if (!$idProta) {
                $insProta = $pdo->prepare("
                    INSERT INTO prota (id_kelas_mata_pelajaran, id_guru, tahun_pelajaran, fase, total_jtm_ganjil, total_jtm_genap, total_jtm_tahunan, as_ganjil, sts_ganjil, remidi_ganjil, proyek_ganjil, as_genap, sts_genap, remidi_genap, proyek_genap, status)
                    VALUES (?, ?, ?, ?, 26, 26, 52, 4, 2, 0, 2, 4, 2, 0, 2, 'Approved')
                ");
                $insProta->execute([$idKmp, ($idUserLogged > 0 ? $idUserLogged : null), $tahun, $fase]);
                $idProta = $pdo->lastInsertId();
            }

            // 2. Ambil seluruh TP khusus untuk rombel & mata pelajaran ini
            $kmpInfoStmt = $pdo->prepare("SELECT kmp.*, mp.nama_mata_pelajaran FROM kelas_mata_pelajaran kmp JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id WHERE kmp.id = ?");
            $kmpInfoStmt->execute([$idKmp]);
            $kmpRow = $kmpInfoStmt->fetch(PDO::FETCH_ASSOC);
            $idMapel = (int)($kmpRow['id_mata_pelajaran'] ?? 0);

            // Pastikan master elemen & TP sudah tersedia untuk KMP ini
            ensureCurriculumElementsForKmp($pdo, $idKmp, $kmpRow, $fase);

            $tpStmt = $pdo->prepare("
                SELECT tp.id, tp.kode_tp, tp.alokasi_jam, cp.id_elemen
                FROM tp
                JOIN cp ON tp.id_cp = cp.id
                JOIN elemen e ON cp.id_elemen = e.id
                WHERE cp.id_kelas_mata_pelajaran = ?
                ORDER BY tp.id ASC
            ");
            $tpStmt->execute([$idKmp]);
            $tpList = $tpStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($tpList)) {
                $tpStmtFallback = $pdo->prepare("
                    SELECT tp.id, tp.kode_tp, tp.alokasi_jam, cp.id_elemen
                    FROM tp
                    JOIN cp ON tp.id_cp = cp.id
                    JOIN elemen e ON cp.id_elemen = e.id
                    JOIN kelas_mata_pelajaran kmp ON cp.id_kelas_mata_pelajaran = kmp.id
                    WHERE kmp.id_mata_pelajaran = ?
                    ORDER BY tp.id ASC
                ");
                $tpStmtFallback->execute([$idMapel]);
                $tpList = $tpStmtFallback->fetchAll(PDO::FETCH_ASSOC);
            }

            if (empty($tpList)) {
                throw new Exception("Belum ada Tujuan Pembelajaran (TP) untuk mata pelajaran ini. Silakan klik '+ Tambah TP Baru' untuk menambahkan TP.");
            }

            // 3. Bersihkan detail prota lama
            $pdo->prepare("DELETE FROM prota_detail WHERE id_prota = ?")->execute([$idProta]);

            // 4. Masukkan baris TP (Separuh di Ganjil, Separuh di Genap)
            $totalCount = count($tpList);
            $half = (int)ceil($totalCount / 2);

            $insPd = $pdo->prepare("
                INSERT INTO prota_detail 
                    (id_prota, id_elemen, id_tp, id_itp, semester, nomor_urut, alokasi_waktu)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $jtmGanjilKbm = 0;
            $jtmGenapKbm  = 0;

            foreach ($tpList as $idx => $tItem) {
                $sem = ($idx < $half) ? 'Ganjil' : 'Genap';
                $noUrut = ($idx < $half) ? ($idx + 1) : ($idx - $half + 1);
                $jp = !empty($tItem['alokasi_jam']) ? (int)$tItem['alokasi_jam'] : 4;

                // Ambil ITP pertama jika ada
                $itpStmt = $pdo->prepare("SELECT id FROM itp WHERE id_tp = ? ORDER BY id ASC LIMIT 1");
                $itpStmt->execute([$tItem['id']]);
                $itpId = $itpStmt->fetchColumn() ?: null;

                $insPd->execute([$idProta, $tItem['id_elemen'], $tItem['id'], $itpId, $sem, $noUrut, $jp]);

                if ($sem === 'Ganjil') $jtmGanjilKbm += $jp;
                else $jtmGenapKbm += $jp;
            }

            // Hitung total JTM
            $totGanjil = $jtmGanjilKbm + 4 + 2 + 2; // + AS + STS + Proyek
            $totGenap  = $jtmGenapKbm + 4 + 2 + 2;
            $totTahunan = $totGanjil + $totGenap;

            $pdo->prepare("
                UPDATE prota 
                SET total_jtm_ganjil = ?, total_jtm_genap = ?, total_jtm_tahunan = ?, fase = ?, updated_at = NOW()
                WHERE id = ?
            ")->execute([$totGanjil, $totGenap, $totTahunan, $fase, $idProta]);

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => "Program Tahunan (PROTA) berhasil digenerate otomatis! Total $totalCount TP berhasil dipetakan.",
                'data' => ['id_prota' => $idProta]
            ]);
            exit;
        }

        // 6b. TAMBAH TUJUAN PEMBELAJARAN (TP) BARU UNTUK MAPEL INI (BERDASARKAN ELEMEN & CP DARI DB)
        if ($action === 'create_tp_mapel') {
            $idKmp       = (int)($_POST['id_kmp'] ?? 0);
            $tahun       = trim((string)($_POST['tahun_pelajaran'] ?? '2026/2027'));
            $semester    = trim((string)($_POST['semester'] ?? 'Ganjil'));
            $kodeTp      = trim((string)($_POST['kode_tp'] ?? ''));
            $materiPokok = trim((string)($_POST['materi_pokok'] ?? ''));
            $uraianTp    = trim((string)($_POST['uraian_tp'] ?? ''));
            $alokasi     = max(1, (int)($_POST['alokasi_jam'] ?? 4));
            $idElemen    = (int)($_POST['id_elemen'] ?? 0);
            $idCp        = (int)($_POST['id_cp'] ?? 0);
            $namaElemenBaru = trim((string)($_POST['nama_elemen_baru'] ?? ''));
            $masukProta  = !empty($_POST['masuk_prota']);
            $masukProsem = !empty($_POST['masuk_prosem']);

            if ($idKmp <= 0) throw new Exception("Pilih rombel dan mata pelajaran.");
            if (empty($uraianTp)) throw new Exception("Uraian Tujuan Pembelajaran (TP) tidak boleh kosong.");

            $pdo->beginTransaction();

            // Cek KMP & Fase
            $kmpStmt = $pdo->prepare("SELECT kmp.*, k.nama_kelas, mp.nama_mata_pelajaran FROM kelas_mata_pelajaran kmp JOIN kelas k ON kmp.id_kelas = k.id JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id WHERE kmp.id = ?");
            $kmpStmt->execute([$idKmp]);
            $kmpRow = $kmpStmt->fetch(PDO::FETCH_ASSOC);
            if (!$kmpRow) throw new Exception("Data kelas mata pelajaran tidak ditemukan.");

            $fase = 'Fase F';
            if (preg_match('/\b(10|x)\b/i', $kmpRow['nama_kelas'])) $fase = 'Fase E';
            elseif (preg_match('/\b(7|8|9|vii|viii|ix)\b/i', $kmpRow['nama_kelas'])) $fase = 'Fase D';

            // Elemen: jika baru atau belum ada id_elemen
            if ($idElemen <= 0) {
                $namaElemen = !empty($namaElemenBaru) ? $namaElemenBaru : 'Materi Pembelajaran';
                $ce = $pdo->prepare("SELECT id FROM elemen WHERE id_kelas_mata_pelajaran = ? AND nama_elemen = ? LIMIT 1");
                $ce->execute([$idKmp, $namaElemen]);
                $idElemen = $ce->fetchColumn();
                if (!$idElemen) {
                    $ie = $pdo->prepare("INSERT INTO elemen (id_kelas_mata_pelajaran, nama_elemen, fase, deskripsi) VALUES (?, ?, ?, ?)");
                    $ie->execute([$idKmp, $namaElemen, $fase, 'Elemen ' . $kmpRow['nama_mata_pelajaran']]);
                    $idElemen = $pdo->lastInsertId();
                }
            }

            // Validasi atau Tautkan ke Capaian Pembelajaran (CP) dari Database
            if ($idCp <= 0) {
                $cpStmt = $pdo->prepare("SELECT id FROM cp WHERE id_elemen = ? AND id_kelas_mata_pelajaran = ? LIMIT 1");
                $cpStmt->execute([$idElemen, $idKmp]);
                $idCp = (int)$cpStmt->fetchColumn();
                if (!$idCp) {
                    $cpStmtAlt = $pdo->prepare("SELECT id FROM cp WHERE id_elemen = ? LIMIT 1");
                    $cpStmtAlt->execute([$idElemen]);
                    $idCp = (int)$cpStmtAlt->fetchColumn();
                }
                if (!$idCp) {
                    $insCp = $pdo->prepare("INSERT INTO cp (id_kelas_mata_pelajaran, id_elemen, cp) VALUES (?, ?, ?)");
                    $insCp->execute([$idKmp, $idElemen, 'Capaian Pembelajaran ' . $kmpRow['nama_mata_pelajaran'] . ' untuk ' . $fase]);
                    $idCp = $pdo->lastInsertId();
                }
            } else {
                // Pastikan idElemen konsisten dengan CP yang dipilih
                $cpInfoStmt = $pdo->prepare("SELECT id_elemen FROM cp WHERE id = ?");
                $cpInfoStmt->execute([$idCp]);
                $cpInfo = $cpInfoStmt->fetch(PDO::FETCH_ASSOC);
                if ($cpInfo && !empty($cpInfo['id_elemen'])) {
                    $idElemen = (int)$cpInfo['id_elemen'];
                }
            }

            // Auto-generate kode_tp jika kosong
            if (empty($kodeTp)) {
                $countTp = $pdo->prepare("SELECT COUNT(*) FROM tp WHERE id_cp = ?");
                $countTp->execute([$idCp]);
                $nextNo = (int)$countTp->fetchColumn() + 1;
                $kodeTp = ($semester === 'Ganjil' ? '1.' : '2.') . $nextNo;
            }

            // Simpan ke tabel TP
            $insTp = $pdo->prepare("INSERT INTO tp (id_cp, kode_tp, tp, materi_pokok, alokasi_jam) VALUES (?, ?, ?, ?, ?)");
            $insTp->execute([$idCp, $kodeTp, $uraianTp, $materiPokok ?: $uraianTp, $alokasi]);
            $idTp = $pdo->lastInsertId();

            // Masukkan ke PROTA jika dicentang
            if ($masukProta) {
                $pStmt = $pdo->prepare("SELECT id FROM prota WHERE id_kelas_mata_pelajaran = ? AND tahun_pelajaran = ? LIMIT 1");
                $pStmt->execute([$idKmp, $tahun]);
                $idProta = $pStmt->fetchColumn();
                if (!$idProta) {
                    $insProta = $pdo->prepare("INSERT INTO prota (id_kelas_mata_pelajaran, id_guru, tahun_pelajaran, fase, total_jtm_ganjil, total_jtm_genap, total_jtm_tahunan, status) VALUES (?, ?, ?, ?, 0, 0, 0, 'Approved')");
                    $insProta->execute([$idKmp, ($idUserLogged > 0 ? $idUserLogged : null), $tahun, $fase]);
                    $idProta = $pdo->lastInsertId();
                }

                $noStmt = $pdo->prepare("SELECT COALESCE(MAX(nomor_urut), 0) + 1 FROM prota_detail WHERE id_prota = ? AND semester = ?");
                $noStmt->execute([$idProta, $semester]);
                $noUrutProta = (int)$noStmt->fetchColumn();

                $insPd = $pdo->prepare("INSERT INTO prota_detail (id_prota, id_elemen, id_tp, semester, nomor_urut, alokasi_waktu) VALUES (?, ?, ?, ?, ?, ?)");
                $insPd->execute([$idProta, $idElemen, $idTp, $semester, $noUrutProta, $alokasi]);

                $calcStmt = $pdo->prepare("SELECT semester, SUM(alokasi_waktu) FROM prota_detail WHERE id_prota = ? GROUP BY semester");
                $calcStmt->execute([$idProta]);
                $subs = $calcStmt->fetchAll(PDO::FETCH_KEY_PAIR);
                $g1 = (int)($subs['Ganjil'] ?? 0) + 8;
                $g2 = (int)($subs['Genap'] ?? 0) + 8;
                $pdo->prepare("UPDATE prota SET total_jtm_ganjil = ?, total_jtm_genap = ?, total_jtm_tahunan = ? WHERE id = ?")->execute([$g1, $g2, $g1+$g2, $idProta]);
            }

            // Masukkan ke PROSEM jika dicentang
            if ($masukProsem) {
                $psStmt = $pdo->prepare("SELECT id FROM prosem WHERE id_kelas_mata_pelajaran = ? AND tahun_pelajaran = ? AND semester = ? LIMIT 1");
                $psStmt->execute([$idKmp, $tahun, $semester]);
                $idProsem = $psStmt->fetchColumn();
                if (!$idProsem) {
                    $insPs = $pdo->prepare("INSERT INTO prosem (id_kelas_mata_pelajaran, id_guru, tahun_pelajaran, semester, total_alokasi_jp, status) VALUES (?, ?, ?, ?, ?, 'Approved')");
                    $insPs->execute([$idKmp, ($idUserLogged > 0 ? $idUserLogged : null), $tahun, $semester, $alokasi]);
                    $idProsem = $pdo->lastInsertId();
                }

                $noPsStmt = $pdo->prepare("SELECT COALESCE(MAX(nomor_urut), 0) + 1 FROM prosem_detail WHERE id_prosem = ?");
                $noPsStmt->execute([$idProsem]);
                $noUrutProsem = (int)$noPsStmt->fetchColumn();

                $insPsd = $pdo->prepare("INSERT INTO prosem_detail (id_prosem, id_tp, nomor_urut, kategori, nama_agenda, alokasi_waktu) VALUES (?, ?, ?, 'Materi KBM', ?, ?)");
                $insPsd->execute([$idProsem, $idTp, $noUrutProsem, $materiPokok ?: $uraianTp, $alokasi]);

                // Susun ulang jadwal mingguan otomatis (cascade shift)
                rebalanceProsemWeeklyGrid($pdo, $idProsem);
            }

            $pdo->commit();

            // Fetch newly created TP details
            $tpFetch = $pdo->prepare("
                SELECT t.*, cp.id_elemen, elem.nama_elemen 
                FROM tp t 
                LEFT JOIN cp ON t.id_cp = cp.id 
                LEFT JOIN elemen elem ON cp.id_elemen = elem.id 
                WHERE t.id = ?
            ");
            $tpFetch->execute([$idTp]);
            $tpRow = $tpFetch->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'message' => 'Tujuan Pembelajaran (TP) berhasil dibuat dari Elemen & CP terpilih serta terintegrasi ke Kaldik!',
                'data' => [
                    'id_tp'       => $idTp,
                    'tp'          => $tpRow,
                    'masuk_prota' => $masukProta,
                    'masuk_prosem'=> $masukProsem,
                    'semester'    => $semester,
                    'alokasi'     => $alokasi
                ]
            ]);
            exit;
        }

        // 7. SIMPAN / EDIT BARIS PROTA DETAIL
        if ($action === 'save_prota_row') {
            $idProta    = (int)($_POST['id_prota'] ?? 0);
            $idRow      = (int)($_POST['id_row'] ?? 0);
            $idElemen   = (int)($_POST['id_elemen'] ?? 0);
            $idTp       = (int)($_POST['id_tp'] ?? 0);
            $idItp      = !empty($_POST['id_itp']) ? (int)$_POST['id_itp'] : null;
            $semester   = trim((string)($_POST['semester'] ?? 'Ganjil'));
            $alokasi    = max(1, (int)($_POST['alokasi_waktu'] ?? 2));
            $nomorUrut  = max(1, (int)($_POST['nomor_urut'] ?? 1));
            $idKmp      = (int)($_POST['id_kmp'] ?? 0);
            $tahun      = trim((string)($_POST['tahun_pelajaran'] ?? '2026/2027'));

            if ($idElemen <= 0 || $idTp <= 0) {
                throw new Exception("Elemen Kurikulum dan Tujuan Pembelajaran wajib dipilih.");
            }

            // Auto-create PROTA header jika belum ada
            if ($idProta <= 0) {
                if ($idKmp <= 0) throw new Exception("Rombel dan mata pelajaran tidak valid.");
                $pStmt = $pdo->prepare("SELECT id FROM prota WHERE id_kelas_mata_pelajaran = ? AND tahun_pelajaran = ? LIMIT 1");
                $pStmt->execute([$idKmp, $tahun]);
                $idProta = $pStmt->fetchColumn();
                if (!$idProta) {
                    $ins = $pdo->prepare("INSERT INTO prota (id_kelas_mata_pelajaran, id_guru, tahun_pelajaran, fase, total_jtm_ganjil, total_jtm_genap, total_jtm_tahunan, status) VALUES (?, ?, ?, 'Fase F', 0, 0, 0, 'Approved')");
                    $ins->execute([$idKmp, ($idUserLogged > 0 ? $idUserLogged : null), $tahun]);
                    $idProta = $pdo->lastInsertId();
                }
            }

            if ($idRow > 0) {
                $stmt = $pdo->prepare("
                    UPDATE prota_detail 
                    SET id_elemen = ?, id_tp = ?, id_itp = ?, semester = ?, nomor_urut = ?, alokasi_waktu = ?
                    WHERE id = ? AND id_prota = ?
                ");
                $stmt->execute([$idElemen, $idTp, $idItp, $semester, $nomorUrut, $alokasi, $idRow, $idProta]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO prota_detail 
                        (id_prota, id_elemen, id_tp, id_itp, semester, nomor_urut, alokasi_waktu)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$idProta, $idElemen, $idTp, $idItp, $semester, $nomorUrut, $alokasi]);
            }

            // Hitung ulang total prota
            $calcStmt = $pdo->prepare("
                SELECT semester, SUM(alokasi_waktu) as subtotal 
                FROM prota_detail 
                WHERE id_prota = ? 
                GROUP BY semester
            ");
            $calcStmt->execute([$idProta]);
            $subs = $calcStmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $ganjil = (int)($subs['Ganjil'] ?? 0) + 8; // + AS, STS, Proyek
            $genap  = (int)($subs['Genap'] ?? 0) + 8;
            $tahunan = $ganjil + $genap;

            $pdo->prepare("UPDATE prota SET total_jtm_ganjil = ?, total_jtm_genap = ?, total_jtm_tahunan = ? WHERE id = ?")
                ->execute([$ganjil, $genap, $tahunan, $idProta]);

            if ($idRow <= 0) {
                $idRow = (int)$pdo->lastInsertId();
            }

            $rowStmt = $pdo->prepare("
                SELECT pd.*, elem.nama_elemen, tp.kode_tp, tp.tp as uraian_tp, tp.materi_pokok
                FROM prota_detail pd
                LEFT JOIN elemen elem ON pd.id_elemen = elem.id
                LEFT JOIN tp ON pd.id_tp = tp.id
                WHERE pd.id = ?
            ");
            $rowStmt->execute([$idRow]);
            $savedRow = $rowStmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'message' => 'Baris PROTA berhasil disimpan.',
                'data' => [
                    'row' => $savedRow,
                    'id_prota' => $idProta,
                    'total_tahunan' => $tahunan,
                    'total_ganjil' => $ganjil,
                    'total_genap' => $genap
                ]
            ]);
            exit;
        }

        // 8. HAPUS BARIS PROTA DETAIL
        if ($action === 'delete_prota_row') {
            $idRow   = (int)($_POST['id_row'] ?? 0);
            $idProta = (int)($_POST['id_prota'] ?? 0);

            if ($idRow <= 0) throw new Exception("ID baris tidak valid.");

            $pdo->prepare("DELETE FROM prota_detail WHERE id = ?")->execute([$idRow]);

            // Hitung ulang total
            if ($idProta > 0) {
                $calcStmt = $pdo->prepare("
                    SELECT semester, SUM(alokasi_waktu) as subtotal 
                    FROM prota_detail 
                    WHERE id_prota = ? 
                    GROUP BY semester
                ");
                $calcStmt->execute([$idProta]);
                $subs = $calcStmt->fetchAll(PDO::FETCH_KEY_PAIR);
                $ganjil = (int)($subs['Ganjil'] ?? 0) + 8;
                $genap  = (int)($subs['Genap'] ?? 0) + 8;
                $tahunan = $ganjil + $genap;

                $pdo->prepare("UPDATE prota SET total_jtm_ganjil = ?, total_jtm_genap = ?, total_jtm_tahunan = ? WHERE id = ?")
                    ->execute([$ganjil, $genap, $tahunan, $idProta]);
            }

            echo json_encode(['success' => true, 'message' => 'Baris PROTA berhasil dihapus.']);
            exit;
        }

        // 9. UPDATE PENGATURAN & STATUS PROTA
        if ($action === 'update_prota_settings') {
            $idProta = (int)($_POST['id_prota'] ?? 0);
            $fase = trim((string)($_POST['fase'] ?? 'Fase F'));
            $status = in_array($_POST['status'] ?? '', ['Draft', 'Approved', 'Archived']) ? $_POST['status'] : 'Approved';

            if ($idProta <= 0) throw new Exception("ID PROTA tidak valid.");

            $pdo->prepare("UPDATE prota SET fase = ?, status = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$fase, $status, $idProta]);

            echo json_encode(['success' => true, 'message' => 'Status dan Pengaturan PROTA berhasil diperbarui.']);
            exit;
        }

        // 10. GENERATE PROSEM OTOMATIS DARI PROTA & KALDIK
        if ($action === 'generate_prosem_otomatis') {
            $idKmp   = (int)($_POST['id_kmp'] ?? 0);
            $tahun   = trim((string)($_POST['tahun_pelajaran'] ?? '2025/2026'));
            $semester = trim((string)($_POST['semester'] ?? 'Ganjil'));

            if ($idKmp <= 0) throw new Exception("Pilih rombel dan mata pelajaran.");

            $pdo->beginTransaction();

            // 1. Ambil Prota
            $protaStmt = $pdo->prepare("SELECT id FROM prota WHERE id_kelas_mata_pelajaran = ? AND tahun_pelajaran = ? LIMIT 1");
            $protaStmt->execute([$idKmp, $tahun]);
            $idProta = $protaStmt->fetchColumn() ?: null;

            // 2. Ambil Total JTM dari Alokasi Mengajar
            $ahmStmt = $pdo->prepare("SELECT total_jtm, jp_per_minggu FROM alokasi_hari_mengajar WHERE id_kelas_mata_pelajaran = ? AND semester = ? LIMIT 1");
            $ahmStmt->execute([$idKmp, $semester]);
            $ahmRow = $ahmStmt->fetch(PDO::FETCH_ASSOC);
            $totalJtmSemester = $ahmRow ? (int)$ahmRow['total_jtm'] : ($semester === 'Ganjil' ? 36 : 38);
            $jpPerPekan = $ahmRow ? max(1, (int)$ahmRow['jp_per_minggu']) : 2;

            // 3. Ambil atau Buat Header Prosem
            $prosemStmt = $pdo->prepare("SELECT id FROM prosem WHERE id_kelas_mata_pelajaran = ? AND tahun_pelajaran = ? AND semester = ? LIMIT 1");
            $prosemStmt->execute([$idKmp, $tahun, $semester]);
            $idProsem = $prosemStmt->fetchColumn();

            if (!$idProsem) {
                $insPs = $pdo->prepare("
                    INSERT INTO prosem (id_prota, id_kelas_mata_pelajaran, id_guru, tahun_pelajaran, semester, total_alokasi_jp, status)
                    VALUES (?, ?, ?, ?, ?, ?, 'Approved')
                ");
                $insPs->execute([$idProta, $idKmp, ($idUserLogged > 0 ? $idUserLogged : null), $tahun, $semester, $totalJtmSemester]);
                $idProsem = $pdo->lastInsertId();
            } else {
                $pdo->prepare("UPDATE prosem SET id_prota = ?, total_alokasi_jp = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$idProta, $totalJtmSemester, $idProsem]);
            }

            // 4. Bersihkan detail & mingguan lama
            $oldDetails = $pdo->query("SELECT id FROM prosem_detail WHERE id_prosem = {$idProsem}")->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($oldDetails)) {
                $inD = implode(',', $oldDetails);
                $pdo->exec("DELETE FROM prosem_jadwal_mingguan WHERE id_prosem_detail IN ($inD)");
                $pdo->exec("DELETE FROM prosem_detail WHERE id_prosem = {$idProsem}");
            }

            // 5. Salin baris TP dari PROTA DETAIL untuk semester bersangkutan
            $tpItems = [];
            if ($idProta) {
                $pdStmt = $pdo->prepare("
                    SELECT pd.id_tp, pd.id_itp, pd.alokasi_waktu, tp.tp as uraian_tp, tp.materi_pokok
                    FROM prota_detail pd
                    LEFT JOIN tp ON pd.id_tp = tp.id
                    WHERE pd.id_prota = ? AND pd.semester = ?
                    ORDER BY pd.nomor_urut ASC, pd.id ASC
                ");
                $pdStmt->execute([$idProta, $semester]);
                $tpItems = $pdStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Jika belum ada di Prota, ambil langsung dari tabel TP
            if (empty($tpItems)) {
                $tpDirect = $pdo->prepare("
                    SELECT tp.id as id_tp, NULL as id_itp, COALESCE(tp.alokasi_jam, 4) as alokasi_waktu, tp.tp as uraian_tp, tp.materi_pokok
                    FROM tp
                    JOIN cp ON tp.id_cp = cp.id
                    WHERE cp.id_kelas_mata_pelajaran = ?
                    ORDER BY tp.id ASC LIMIT 5
                ");
                $tpDirect->execute([$idKmp]);
                $tpItems = $tpDirect->fetchAll(PDO::FETCH_ASSOC);
            }

            // Masukkan Baris KBM TP
            $insPsd = $pdo->prepare("
                INSERT INTO prosem_detail 
                    (id_prosem, id_tp, id_itp, nomor_urut, kategori, nama_agenda, alokasi_waktu, id_modul_ajar)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $createdDetails = [];
            $noUrut = 1;
            foreach ($tpItems as $item) {
                // Relasi modul ajar jika ada
                $maId = null;
                if (!empty($item['id_tp'])) {
                    $stmtMa = $pdo->prepare("SELECT id FROM modul_ajar WHERE id_tp = ? LIMIT 1");
                    $stmtMa->execute([$item['id_tp']]);
                    $maId = $stmtMa->fetchColumn() ?: null;
                }

                $insPsd->execute([
                    $idProsem, $item['id_tp'], $item['id_itp'], $noUrut,
                    'Materi KBM', null, (int)$item['alokasi_waktu'], $maId
                ]);
                $createdDetails[] = [
                    'id' => $pdo->lastInsertId(),
                    'kategori' => 'Materi KBM',
                    'jp' => (int)$item['alokasi_waktu']
                ];
                $noUrut++;

                // Setiap 2 TP, selipkan Asesmen Sumatif (AS)
                if ($noUrut % 2 === 0) {
                    $insPsd->execute([
                        $idProsem, null, null, $noUrut,
                        'Asesmen Sumatif', 'Asesmen Sumatif Lingkup Materi', 2, null
                    ]);
                    $createdDetails[] = [
                        'id' => $pdo->lastInsertId(),
                        'kategori' => 'Asesmen Sumatif',
                        'jp' => 2
                    ];
                    $noUrut++;
                }
            }

            // Tambahkan Sumatif Tengah Semester (STS)
            $insPsd->execute([
                $idProsem, null, null, $noUrut++,
                'Sumatif Tengah Semester', 'Sumatif Tengah Semester (STS)', 2, null
            ]);
            $createdDetails[] = ['id' => $pdo->lastInsertId(), 'kategori' => 'Sumatif Tengah Semester', 'jp' => 2];

            // Tambahkan Proyek Penguatan Profil Pelajar Pancasila (P5/PPRA)
            $insPsd->execute([
                $idProsem, null, null, $noUrut++,
                'Proyek', 'Proyek Penguatan Profil Pelajar Pancasila (P5 & PPRA)', 2, null
            ]);
            $createdDetails[] = ['id' => $pdo->lastInsertId(), 'kategori' => 'Proyek', 'jp' => 2];

            // Tambahkan Asesmen Sumatif Akhir Semester (ASAS)
            $insPsd->execute([
                $idProsem, null, null, $noUrut++,
                'Asesmen Sumatif Akhir Semester', 'Asesmen Sumatif Akhir Semester (ASAS)', 2, null
            ]);
            $createdDetails[] = ['id' => $pdo->lastInsertId(), 'kategori' => 'Asesmen Sumatif Akhir Semester', 'jp' => 2];

            // 6. DISTRIBUSI MATRIKS MINGGUAN KE KALDIK EFEKTIF SECARA OTOMATIS (CASCADE SHIFT)
            rebalanceProsemWeeklyGrid($pdo, $idProsem);

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Program Semester (PROSEM) beserta Matriks Mingguan berhasil digenerate otomatis dan diselaraskan dengan Kaldik!',
                'data' => ['id_prosem' => $idProsem]
            ]);
            exit;
        }

        // 11. SIMPAN/UPDATE SEL MATRIKS PROSEM MINGGUAN (DENGAN SINKRONISASI KE PROTA)
        if ($action === 'save_prosem_matrix_cell') {
            $idDetail = (int)($_POST['id_detail'] ?? 0);
            $bulan = trim((string)($_POST['bulan'] ?? ''));
            $urutanBulan = (int)($_POST['urutan_bulan'] ?? 1);
            $pekanKe = (int)($_POST['pekan_ke'] ?? 1);
            $jp = max(0, (int)($_POST['jam_tatap_muka'] ?? 0));
            $keterangan = trim((string)($_POST['keterangan'] ?? 'KBM'));

            if ($idDetail <= 0 || empty($bulan)) throw new Exception("Data sel tidak valid.");

            // Cek apakah sel sudah ada
            $checkStmt = $pdo->prepare("SELECT id FROM prosem_jadwal_mingguan WHERE id_prosem_detail = ? AND bulan = ? AND pekan_ke = ? LIMIT 1");
            $checkStmt->execute([$idDetail, $bulan, $pekanKe]);
            $existingId = $checkStmt->fetchColumn();

            if ($existingId) {
                if ($jp > 0) {
                    $pdo->prepare("UPDATE prosem_jadwal_mingguan SET jam_tatap_muka = ?, keterangan = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$jp, $keterangan, $existingId]);
                } else {
                    $pdo->prepare("DELETE FROM prosem_jadwal_mingguan WHERE id = ?")->execute([$existingId]);
                }
            } else {
                if ($jp > 0) {
                    $pdo->prepare("INSERT INTO prosem_jadwal_mingguan (id_prosem_detail, bulan, urutan_bulan, pekan_ke, jam_tatap_muka, keterangan) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$idDetail, $bulan, $urutanBulan, $pekanKe, $jp, $keterangan]);
                }
            }

            // Hitung ulang total alokasi jam untuk baris prosem_detail ini
            $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(jam_tatap_muka), 0) FROM prosem_jadwal_mingguan WHERE id_prosem_detail = ?");
            $sumStmt->execute([$idDetail]);
            $newRowTotal = (int)$sumStmt->fetchColumn();

            $pdo->prepare("UPDATE prosem_detail SET alokasi_waktu = ? WHERE id = ?")->execute([$newRowTotal, $idDetail]);

            // Sinkronkan ke PROTA dan rekap prosem
            $pdInfoStmt = $pdo->prepare("
                SELECT psd.id_prosem, psd.id_tp, ps.id_prota, ps.semester 
                FROM prosem_detail psd 
                JOIN prosem ps ON psd.id_prosem = ps.id 
                WHERE psd.id = ?
            ");
            $pdInfoStmt->execute([$idDetail]);
            $pdInfo = $pdInfoStmt->fetch(PDO::FETCH_ASSOC);

            if ($pdInfo) {
                $idProsem = (int)$pdInfo['id_prosem'];
                $idTp     = (int)$pdInfo['id_tp'];
                $idProta  = (int)$pdInfo['id_prota'];
                $sem      = $pdInfo['semester'];

                // Update total alokasi prosem
                $totJp = (int)$pdo->query("SELECT SUM(alokasi_waktu) FROM prosem_detail WHERE id_prosem = {$idProsem}")->fetchColumn();
                $pdo->prepare("UPDATE prosem SET total_alokasi_jp = ? WHERE id = ?")->execute([$totJp, $idProsem]);

                // SINKRONISASI OTOMATIS KE PROTA (alokasi waktu menyesuaikan)
                if ($idProta > 0 && $idTp > 0) {
                    $pdo->prepare("UPDATE prota_detail SET alokasi_waktu = ? WHERE id_prota = ? AND id_tp = ? AND semester = ?")
                        ->execute([$newRowTotal, $idProta, $idTp, $sem]);

                    $calcStmt = $pdo->prepare("SELECT semester, SUM(alokasi_waktu) FROM prota_detail WHERE id_prota = ? GROUP BY semester");
                    $calcStmt->execute([$idProta]);
                    $subs = $calcStmt->fetchAll(PDO::FETCH_KEY_PAIR);
                    $ganjil = (int)($subs['Ganjil'] ?? 0) + 8;
                    $genap  = (int)($subs['Genap'] ?? 0) + 8;
                    $tahunan = $ganjil + $genap;
                    $pdo->prepare("UPDATE prota SET total_jtm_ganjil = ?, total_jtm_genap = ?, total_jtm_tahunan = ? WHERE id = ?")
                        ->execute([$ganjil, $genap, $tahunan, $idProta]);
                }
            }

            echo json_encode(['success' => true, 'message' => 'Alokasi pekan berhasil diperbarui dan PROTA otomatis disinkronkan.']);
            exit;
        }

        // 12. SIMPAN / EDIT BARIS AGENDA PROSEM DETAIL (DENGAN CASCADE SHIFT & SYNC PROTA)
        if ($action === 'save_prosem_row') {
            $idProsem   = (int)($_POST['id_prosem'] ?? 0);
            $idRow      = (int)($_POST['id_row'] ?? 0);
            $kategori   = trim((string)($_POST['kategori'] ?? 'Materi KBM'));
            $idTp       = !empty($_POST['id_tp']) ? (int)$_POST['id_tp'] : null;
            $idItp      = !empty($_POST['id_itp']) ? (int)$_POST['id_itp'] : null;
            $namaAgenda = trim((string)($_POST['nama_agenda'] ?? ''));
            $alokasi    = max(1, (int)($_POST['alokasi_waktu'] ?? 2));
            $nomorUrut  = max(1, (int)($_POST['nomor_urut'] ?? 1));
            $idModul    = !empty($_POST['id_modul_ajar']) ? (int)$_POST['id_modul_ajar'] : null;
            $idKmp      = (int)($_POST['id_kmp'] ?? 0);
            $tahun      = trim((string)($_POST['tahun_pelajaran'] ?? '2026/2027'));
            $semester   = trim((string)($_POST['semester'] ?? 'Ganjil'));

            // Auto-create PROSEM header jika belum ada
            if ($idProsem <= 0) {
                if ($idKmp <= 0) throw new Exception("Rombel dan mata pelajaran tidak valid.");
                $psStmt = $pdo->prepare("SELECT id FROM prosem WHERE id_kelas_mata_pelajaran = ? AND tahun_pelajaran = ? AND semester = ? LIMIT 1");
                $psStmt->execute([$idKmp, $tahun, $semester]);
                $idProsem = $psStmt->fetchColumn();

                if (!$idProsem) {
                    $ins = $pdo->prepare("INSERT INTO prosem (id_kelas_mata_pelajaran, id_guru, tahun_pelajaran, semester, total_alokasi_jp, status) VALUES (?, ?, ?, ?, ?, 'Approved')");
                    $ins->execute([$idKmp, ($idUserLogged > 0 ? $idUserLogged : null), $tahun, $semester, $alokasi]);
                    $idProsem = $pdo->lastInsertId();
                }
            }

            if ($kategori === 'Materi KBM' && empty($idTp)) {
                throw new Exception("Tujuan Pembelajaran (TP) wajib dipilih untuk kategori Materi KBM.");
            }

            if ($idRow > 0) {
                $stmt = $pdo->prepare("
                    UPDATE prosem_detail 
                    SET id_tp = ?, id_itp = ?, nomor_urut = ?, kategori = ?, nama_agenda = ?, alokasi_waktu = ?, id_modul_ajar = ?
                    WHERE id = ? AND id_prosem = ?
                ");
                $stmt->execute([$idTp, $idItp, $nomorUrut, $kategori, $namaAgenda, $alokasi, $idModul, $idRow, $idProsem]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO prosem_detail 
                        (id_prosem, id_tp, id_itp, nomor_urut, kategori, nama_agenda, alokasi_waktu, id_modul_ajar)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$idProsem, $idTp, $idItp, $nomorUrut, $kategori, $namaAgenda, $alokasi, $idModul]);
            }

            // Susun ulang matriks mingguan secara berantai (cascade shift) dan sinkronkan ke PROTA
            rebalanceProsemWeeklyGrid($pdo, $idProsem);

            if ($idRow <= 0) {
                $idRow = (int)$pdo->lastInsertId();
            }

            $psdStmt = $pdo->prepare("
                SELECT psd.*, tp.kode_tp, tp.tp as uraian_tp, tp.materi_pokok
                FROM prosem_detail psd
                LEFT JOIN tp ON psd.id_tp = tp.id
                WHERE psd.id = ?
            ");
            $psdStmt->execute([$idRow]);
            $savedPsd = $psdStmt->fetch(PDO::FETCH_ASSOC);

            // Ambil mingguan untuk baris ini
            $pjmRows = $pdo->query("SELECT * FROM prosem_jadwal_mingguan WHERE id_prosem_detail = {$idRow}")->fetchAll(PDO::FETCH_ASSOC);
            $savedPjm = [];
            foreach ($pjmRows as $jm) {
                $savedPjm[$jm['bulan']][$jm['pekan_ke']] = (int)$jm['jam_tatap_muka'];
            }

            echo json_encode([
                'success' => true,
                'message' => 'Agenda PROSEM berhasil disimpan dan jadwal berantai disesuaikan.',
                'data' => [
                    'id_prosem' => $idProsem,
                    'row'       => $savedPsd,
                    'mingguan'  => $savedPjm
                ]
            ]);
            exit;
        }

        // 13. HAPUS BARIS AGENDA PROSEM DETAIL
        if ($action === 'delete_prosem_row') {
            $idRow    = (int)($_POST['id_row'] ?? 0);
            $idProsem = (int)($_POST['id_prosem'] ?? 0);

            if ($idRow <= 0) throw new Exception("ID agenda tidak valid.");

            $pdo->prepare("DELETE FROM prosem_jadwal_mingguan WHERE id_prosem_detail = ?")->execute([$idRow]);
            $pdo->prepare("DELETE FROM prosem_detail WHERE id = ?")->execute([$idRow]);

            if ($idProsem > 0) {
                rebalanceProsemWeeklyGrid($pdo, $idProsem);
            }

            echo json_encode(['success' => true, 'message' => 'Agenda PROSEM berhasil dihapus dan matriks jadwal berantai disesuaikan.']);
            exit;
        }

        // 13b. CASCADE REBALANCE / SESUAIKAN JADWAL PROSEM BERANTAI KE KALDIK
        if ($action === 'cascade_rebalance_prosem') {
            $idProsem = (int)($_POST['id_prosem'] ?? 0);
            if ($idProsem <= 0) throw new Exception("ID PROSEM tidak valid.");

            $pdo->beginTransaction();
            rebalanceProsemWeeklyGrid($pdo, $idProsem);
            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Matriks jadwal PROSEM berhasil disusun berantai (cascade shift) ke seluruh pekan efektif Kaldik dan disinkronkan dengan PROTA!'
            ]);
            exit;
        }

        // 14. UPDATE STATUS PROSEM
        if ($action === 'update_prosem_status') {
            $idProsem = (int)($_POST['id_prosem'] ?? 0);
            $status = in_array($_POST['status'] ?? '', ['Draft', 'Approved', 'Archived']) ? $_POST['status'] : 'Approved';

            if ($idProsem <= 0) throw new Exception("ID PROSEM tidak valid.");

            $pdo->prepare("UPDATE prosem SET status = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$status, $idProsem]);

            echo json_encode(['success' => true, 'message' => 'Status PROSEM berhasil diperbarui.']);
            exit;
        }

        // 15. DOWNLOAD PROTA PDF RESMI (METODE DIRECT BLOB DOWNLOAD DARI INCLUDES)
        if ($action === 'download_prota_pdf') {
            @set_time_limit(0);
            while (ob_get_level()) ob_end_clean();

            require_once BASE_PATH . '/includes/prota-prosem-pdf.php';

            $idKmp = (int)($_POST['id_kmp'] ?? 0);
            $tahun = trim((string)($_POST['tahun_pelajaran'] ?? '2026/2027'));

            if ($idKmp <= 0) throw new Exception("Pilih rombel dan mata pelajaran terlebih dahulu.");

            $kmpStmt = $pdo->prepare("
                SELECT kmp.*, k.nama_kelas, mp.nama_mata_pelajaran, mp.kode_mapel,
                       COALESCE(g.nama_guru, 'Dewan Guru') as nama_guru,
                       COALESCE(g.nip, '-') as nip_guru
                FROM kelas_mata_pelajaran kmp
                JOIN kelas k ON kmp.id_kelas = k.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
                LEFT JOIN guru g ON gmp.id_guru = g.id
                WHERE kmp.id = ?
            ");
            $kmpStmt->execute([$idKmp]);
            $kmpInfo = $kmpStmt->fetch(PDO::FETCH_ASSOC);
            if (!$kmpInfo) throw new Exception("Data kelas mata pelajaran tidak ditemukan.");

            $pStmt = $pdo->prepare("SELECT * FROM prota WHERE id_kelas_mata_pelajaran = ? AND tahun_pelajaran = ? LIMIT 1");
            $pStmt->execute([$idKmp, $tahun]);
            $prota = $pStmt->fetch(PDO::FETCH_ASSOC);

            $protaDetails = [];
            if ($prota) {
                $pdStmt = $pdo->prepare("
                    SELECT pd.*, e.nama_elemen, tp.kode_tp, tp.tp as uraian_tp, tp.materi_pokok
                    FROM prota_detail pd
                    LEFT JOIN elemen e ON pd.id_elemen = e.id
                    LEFT JOIN tp ON pd.id_tp = tp.id
                    WHERE pd.id_prota = ?
                    ORDER BY pd.semester ASC, pd.nomor_urut ASC, pd.id ASC
                ");
                $pdStmt->execute([$prota['id']]);
                $protaDetails = $pdStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            if (empty($protaDetails)) {
                throw new Exception("Belum ada rincian Tujuan Pembelajaran (TP) pada PROTA untuk diunduh.");
            }

            $pdfData = [
                'kmp'             => $kmpInfo,
                'prota'           => $prota,
                'details'         => $protaDetails,
                'sekolah'         => $identitasSekolah,
                'guru'            => $currentGuru ?: ['nama_guru' => $kmpInfo['nama_guru'], 'nip' => $kmpInfo['nip_guru']],
                'tahun_pelajaran' => $tahun
            ];

            $resPdf = ilmuna_generate_prota_pdf($pdfData);
            if (!$resPdf['ok'] || empty($resPdf['path']) || !file_exists($resPdf['path'])) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => $resPdf['error'] ?? 'Gagal membuat berkas PDF PROTA.']);
                exit;
            }

            $pdfPath = $resPdf['path'];
            $cleanKelas = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $kmpInfo['nama_kelas'] ?? 'Kelas');
            $cleanMapel = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $kmpInfo['nama_mata_pelajaran'] ?? 'Mapel');
            $cleanTahun = preg_replace('/[^0-9]/', '-', $tahun);
            $filename = "PROTA_{$cleanKelas}_{$cleanMapel}_{$cleanTahun}.pdf";

            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($pdfPath));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
            readfile($pdfPath);
            @unlink($pdfPath);
            exit;
        }

        // 16. DOWNLOAD PROSEM PDF RESMI (METODE DIRECT BLOB DOWNLOAD DARI INCLUDES)
        if ($action === 'download_prosem_pdf') {
            @set_time_limit(0);
            while (ob_get_level()) ob_end_clean();

            require_once BASE_PATH . '/includes/prota-prosem-pdf.php';

            $idKmp    = (int)($_POST['id_kmp'] ?? 0);
            $tahun    = trim((string)($_POST['tahun_pelajaran'] ?? '2026/2027'));
            $semester = trim((string)($_POST['semester'] ?? 'Ganjil'));

            if ($idKmp <= 0) throw new Exception("Pilih rombel dan mata pelajaran terlebih dahulu.");

            $kmpStmt = $pdo->prepare("
                SELECT kmp.*, k.nama_kelas, mp.nama_mata_pelajaran, mp.kode_mapel,
                       COALESCE(g.nama_guru, 'Dewan Guru') as nama_guru,
                       COALESCE(g.nip, '-') as nip_guru
                FROM kelas_mata_pelajaran kmp
                JOIN kelas k ON kmp.id_kelas = k.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
                LEFT JOIN guru g ON gmp.id_guru = g.id
                WHERE kmp.id = ?
            ");
            $kmpStmt->execute([$idKmp]);
            $kmpInfo = $kmpStmt->fetch(PDO::FETCH_ASSOC);
            if (!$kmpInfo) throw new Exception("Data kelas mata pelajaran tidak ditemukan.");

            $psStmt = $pdo->prepare("SELECT * FROM prosem WHERE id_kelas_mata_pelajaran = ? AND tahun_pelajaran = ? AND semester = ? LIMIT 1");
            $psStmt->execute([$idKmp, $tahun, $semester]);
            $prosem = $psStmt->fetch(PDO::FETCH_ASSOC);

            $prosemDetails = [];
            $prosemMingguan = [];
            if ($prosem) {
                $psdStmt = $pdo->prepare("
                    SELECT psd.*, tp.kode_tp, tp.tp as uraian_tp, tp.materi_pokok
                    FROM prosem_detail psd
                    LEFT JOIN tp ON psd.id_tp = tp.id
                    WHERE psd.id_prosem = ?
                    ORDER BY psd.nomor_urut ASC, psd.id ASC
                ");
                $psdStmt->execute([$prosem['id']]);
                $prosemDetails = $psdStmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($prosemDetails)) {
                    $detailIds = array_column($prosemDetails, 'id');
                    $inPlaceholders = implode(',', array_fill(0, count($detailIds), '?'));
                    $pjmStmt = $pdo->prepare("SELECT * FROM prosem_jadwal_mingguan WHERE id_prosem_detail IN ($inPlaceholders)");
                    $pjmStmt->execute($detailIds);
                    $rawPjm = $pjmStmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rawPjm as $jm) {
                        $prosemMingguan[$jm['id_prosem_detail']][$jm['bulan']][$jm['pekan_ke']] = (int)$jm['jam_tatap_muka'];
                    }
                }
            }

            if (empty($prosemDetails)) {
                throw new Exception("Belum ada agenda pada Program Semester (PROSEM) untuk diunduh.");
            }

            $pdfData = [
                'kmp'             => $kmpInfo,
                'prosem'          => $prosem,
                'details'         => $prosemDetails,
                'mingguan'        => $prosemMingguan,
                'sekolah'         => $identitasSekolah,
                'guru'            => $currentGuru ?: ['nama_guru' => $kmpInfo['nama_guru'], 'nip' => $kmpInfo['nip_guru']],
                'tahun_pelajaran' => $tahun,
                'semester'        => $semester
            ];

            $resPdf = ilmuna_generate_prosem_pdf($pdfData);
            if (!$resPdf['ok'] || empty($resPdf['path']) || !file_exists($resPdf['path'])) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => $resPdf['error'] ?? 'Gagal membuat berkas PDF PROSEM.']);
                exit;
            }

            $pdfPath = $resPdf['path'];
            $cleanKelas = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $kmpInfo['nama_kelas'] ?? 'Kelas');
            $cleanMapel = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $kmpInfo['nama_mata_pelajaran'] ?? 'Mapel');
            $cleanSem   = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $semester);
            $cleanTahun = preg_replace('/[^0-9]/', '-', $tahun);
            $filename = "PROSEM_" . strtoupper($cleanSem) . "_{$cleanKelas}_{$cleanMapel}_{$cleanTahun}.pdf";

            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($pdfPath));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
            readfile($pdfPath);
            @unlink($pdfPath);
            exit;
        }

        throw new Exception("Aksi backend tidak dikenali.");
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

/* ==============================================================================
 * RENDER VIEW UTAMA (HTML ILMUNA UI)
 * ============================================================================== */
$pageTitle = 'Program Tahunan & Semester (PROTA & PROSEM)';
$pageIcon  = 'solar:calendar-mark-bold';

require_once BASE_PATH . '/includes/header.php';
loadCentralTemplates(['modal', 'calendar', 'table']);

$tabActive = $_GET['tab'] ?? 'prota';
if (!in_array($tabActive, ['kaldik', 'prota', 'prosem', 'distribusi'], true)) {
    $tabActive = 'prota';
}
?>

<style>
/* ==============================================================================
 * ILMUNA LEARN — LUMINA GOLD & NUSANTARA STYLING FOR PROTA & PROSEM
 * ============================================================================== */
:root {
    --lumina-navy: #1D3B5E;
    --lumina-teal: #0D7A87;
    --lumina-sky: #52B2DC;
    --lumina-gold: #F59E0B;
    --lumina-gold-dark: #D97706;
    --lumina-bg-card: #FFFFFF;
    --lumina-border: #E2E8F0;
    --lumina-text-title: #0F172A;
    --lumina-text-muted: #64748B;
}

.ilmuna-prota-container {
    max-width: 1440px;
    margin: 0 auto;
    padding: 24px 20px 80px;
}

/* Top Banner Header */
.pdp-header-card {
    background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 100%);
    border-radius: 20px;
    padding: 28px 32px;
    color: #FFFFFF;
    box-shadow: 0 10px 30px rgba(13, 122, 135, 0.15);
    margin-bottom: 24px;
    position: relative;
    overflow: hidden;
}
.pdp-header-card::after {
    content: '';
    position: absolute;
    right: -20px;
    bottom: -30px;
    width: 220px;
    height: 220px;
    background-image: url('<?= BASE_URL ?>/assets/img/gunungan.png');
    background-size: contain;
    background-repeat: no-repeat;
    opacity: 0.12;
    pointer-events: none;
}
.pdp-header-title {
    font-size: 24px;
    font-weight: 800;
    letter-spacing: -0.02em;
    margin: 0 0 6px 0;
    display: flex;
    align-items: center;
    gap: 12px;
}
.pdp-header-subtitle {
    font-size: 14px;
    opacity: 0.9;
    max-width: 720px;
    margin: 0;
    line-height: 1.5;
}

/* Cascading Filter Bar */
.pdp-filter-bar {
    background: #FFFFFF;
    border: 1px solid var(--lumina-border);
    border-radius: 16px;
    padding: 18px 24px;
    box-shadow: 0 4px 18px rgba(15, 23, 42, 0.04);
    margin-bottom: 24px;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 16px;
}
.pdp-filter-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
    flex: 1;
    min-width: 180px;
}
.pdp-filter-label {
    font-size: 11.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--lumina-text-muted);
}
.pdp-select, .pdp-input {
    height: 42px;
    border-radius: 10px;
    border: 1px solid #CBD5E1;
    padding: 0 14px;
    font-size: 13.5px;
    color: #1E293B;
    background-color: #F8FAFC;
    transition: all 0.2s ease;
    outline: none;
    width: 100%;
}
.pdp-select:focus, .pdp-input:focus {
    border-color: var(--lumina-teal);
    background-color: #FFFFFF;
    box-shadow: 0 0 0 3px rgba(13, 122, 135, 0.15);
}

/* Nav Tabs */
.pdp-tabs-nav {
    display: flex;
    gap: 8px;
    border-bottom: 2px solid #E2E8F0;
    margin-bottom: 24px;
    overflow-x: auto;
    padding-bottom: 2px;
}
.pdp-tab-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 20px;
    border-radius: 12px 12px 0 0;
    font-size: 14px;
    font-weight: 600;
    color: #64748B;
    background: transparent;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    position: relative;
    white-space: nowrap;
}
.pdp-tab-btn:hover {
    color: var(--lumina-teal);
    background: rgba(13, 122, 135, 0.05);
}
.pdp-tab-btn.is-active {
    color: var(--lumina-teal);
    font-weight: 800;
    background: #FFFFFF;
    border: 1px solid #E2E8F0;
    border-bottom: 2px solid #FFFFFF;
    margin-bottom: -2px;
    box-shadow: 0 -4px 12px rgba(15, 23, 42, 0.03);
}
.pdp-tab-badge {
    padding: 2px 7px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    background: #F1F5F9;
    color: #475569;
}
.pdp-tab-btn.is-active .pdp-tab-badge {
    background: rgba(13, 122, 135, 0.15);
    color: var(--lumina-teal);
}

/* Stats Cards Grid */
.pdp-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.pdp-stat-box {
    background: #FFFFFF;
    border: 1px solid var(--lumina-border);
    border-radius: 16px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.03);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.pdp-stat-box:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
}
.pdp-stat-icon-wrap {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
}
.pdp-stat-info {
    display: flex;
    flex-direction: column;
}
.pdp-stat-val {
    font-size: 22px;
    font-weight: 800;
    color: var(--lumina-navy);
    line-height: 1.1;
}
.pdp-stat-lbl {
    font-size: 12px;
    font-weight: 600;
    color: var(--lumina-text-muted);
    margin-top: 4px;
}

/* Action Button Styles */
.pdp-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 18px;
    border-radius: 10px;
    font-size: 13.5px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
    border: 1px solid transparent;
    text-decoration: none !important;
}
.pdp-btn-primary {
    background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 14px rgba(13, 122, 135, 0.25);
}
.pdp-btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(13, 122, 135, 0.35);
}
.pdp-btn-gold {
    background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 14px rgba(245, 158, 11, 0.25);
}
.pdp-btn-gold:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(245, 158, 11, 0.35);
}
.pdp-btn-outline {
    background: #FFFFFF;
    border-color: #CBD5E1;
    color: #334155;
}
.pdp-btn-outline:hover {
    background: #F8FAFC;
    border-color: #94A3B8;
}

/* Card Section */
.pdp-card {
    background: #FFFFFF;
    border: 1px solid var(--lumina-border);
    border-radius: 20px;
    padding: 24px;
    box-shadow: 0 4px 20px rgba(15, 23, 42, 0.04);
    margin-bottom: 24px;
}
.pdp-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 12px;
}
.pdp-card-title {
    font-size: 17px;
    font-weight: 800;
    color: var(--lumina-navy);
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
}

/* Table Design */
.pdp-table-wrap {
    overflow-x: auto;
    border-radius: 14px;
    border: 1px solid #E2E8F0;
    background: #FFFFFF;
}
.pdp-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    color: #1E293B;
}
.pdp-table th {
    background: #F8FAFC;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.05em;
    color: #475569;
    padding: 12px 14px;
    border-bottom: 1px solid #E2E8F0;
    border-right: 1px solid #E2E8F0;
    vertical-align: middle;
    text-align: center;
}
.pdp-table th:last-child { border-right: none; }
.pdp-table td {
    padding: 12px 14px;
    border-bottom: 1px solid #F1F5F9;
    border-right: 1px solid #F1F5F9;
    vertical-align: middle;
}
.pdp-table td:last-child { border-right: none; }
.pdp-table tr:hover td {
    background: rgba(13, 122, 135, 0.02);
}

/* Interactive Weekly Matrix Grid */
.matrix-cell-input {
    width: 32px;
    height: 30px;
    text-align: center;
    border-radius: 6px;
    border: 1px solid #CBD5E1;
    font-weight: 700;
    font-size: 12.5px;
    background: #FFFFFF;
    color: var(--lumina-navy);
    transition: all 0.15s ease;
}
.matrix-cell-input:focus {
    border-color: var(--lumina-teal);
    background: #F0FDFA;
    outline: none;
    box-shadow: 0 0 0 2px rgba(13, 122, 135, 0.2);
}
.matrix-cell-none {
    background: repeating-linear-gradient(45deg, #F8FAFC, #F8FAFC 6px, #F1F5F9 6px, #F1F5F9 12px);
    color: #94A3B8;
    cursor: not-allowed;
    border-color: #E2E8F0;
}
.month-header-group {
    background: linear-gradient(135deg, rgba(29, 59, 94, 0.06) 0%, rgba(13, 122, 135, 0.08) 100%) !important;
    color: var(--lumina-navy) !important;
    font-weight: 800 !important;
    text-align: center;
}

/* Status Badges */
.pdp-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 700;
}
.pdp-badge-approved {
    background: rgba(16, 185, 129, 0.12);
    color: #059669;
}
.pdp-badge-draft {
    background: rgba(245, 158, 11, 0.12);
    color: #D97706;
}

/* Form Group inside Modal */
.pdp-form-group {
    margin-bottom: 16px;
}
.pdp-form-label {
    display: block;
    font-size: 12px;
    font-weight: 700;
    color: #334155;
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}

/* Print View Modal Styles */
@media print {
    body * {
        visibility: hidden !important;
    }
    #printContentArea, #printContentArea * {
        visibility: visible !important;
    }
    #printContentArea {
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #FFFFFF !important;
    }
    .no-print {
        display: none !important;
    }
}

/* ==============================================================================
 * ILMUNA MODAL COMPREHENSIVE STYLING & ACTIVE STATES
 * ============================================================================== */
.ilm-modal-backdrop {
    position: fixed !important;
    inset: 0 !important;
    background: rgba(15, 29, 47, 0.65) !important;
    backdrop-filter: blur(8px) !important;
    -webkit-backdrop-filter: blur(8px) !important;
    align-items: center !important;
    justify-content: center !important;
    z-index: 10500 !important;
    display: none !important;
    opacity: 0 !important;
    pointer-events: none !important;
    visibility: hidden !important;
    padding: 16px !important;
    box-sizing: border-box !important;
    transition: opacity 0.25s ease, visibility 0.25s !important;
}
.ilm-modal-backdrop.is-open,
.ilm-modal-backdrop.show,
.ilm-modal-backdrop.active {
    display: flex !important;
    opacity: 1 !important;
    pointer-events: auto !important;
    visibility: visible !important;
}
.ilm-modal-dialog {
    position: relative !important;
    width: 100% !important;
    background-color: #FFFFFF !important;
    border-radius: 24px !important;
    border: 1px solid rgba(226, 232, 240, 0.95) !important;
    box-shadow: 0 25px 65px -12px rgba(29, 59, 94, 0.28) !important;
    overflow: hidden !important;
    display: flex !important;
    flex-direction: column !important;
    max-height: calc(100vh - 40px) !important;
    box-sizing: border-box !important;
    text-align: left !important;
    z-index: 10600 !important;
    animation: ilmModalZoomIn 0.22s cubic-bezier(0.16, 1, 0.3, 1) forwards !important;
}
@keyframes ilmModalZoomIn {
    from { opacity: 0; transform: scale(0.95) translateY(8px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}
.ilm-modal-header {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    padding: 20px 24px 16px !important;
    border-bottom: 1px solid #E2E8F0 !important;
    background: #FFFFFF !important;
    position: relative !important;
    z-index: 2 !important;
}
.ilm-modal-header__brand {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
}
.ilm-modal-header__icon, .ilm-modal-icon-wrap {
    width: 42px !important;
    height: 42px !important;
    border-radius: 12px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 22px !important;
    flex-shrink: 0 !important;
}
.ilm-modal-header__title, .ilm-modal-title {
    margin: 0 !important;
    font-size: 17px !important;
    font-weight: 800 !important;
    color: var(--lumina-navy) !important;
}
.ilm-modal-header__subtitle, .ilm-modal-subtitle {
    margin: 2px 0 0 !important;
    font-size: 12px !important;
    color: #64748B !important;
}
.ilm-modal-header__close, .ilm-modal-close {
    width: 36px !important;
    height: 36px !important;
    border-radius: 50% !important;
    background: #F1F5F9 !important;
    border: 1px solid #E2E8F0 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    color: #64748B !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
    padding: 0 !important;
}
.ilm-modal-header__close:hover, .ilm-modal-close:hover {
    background: #FEE2E2 !important;
    border-color: #FCA5A5 !important;
    color: #DC2626 !important;
}
.ilm-modal-body {
    padding: 22px 26px !important;
    overflow-y: auto !important;
    flex: 1 1 auto !important;
    background: #FFFFFF !important;
}
.ilm-modal-footer {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 10px !important;
    padding: 16px 26px !important;
    border-top: 1px solid #E2E8F0 !important;
    background: #F8FAFC !important;
}
.ilm-modal-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    height: 40px;
    padding: 0 18px;
    border-radius: 10px;
    font-size: 13.5px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
    border: none;
    text-decoration: none;
}
.ilm-modal-btn--ghost {
    background: #F1F5F9;
    color: #475569;
    border: 1px solid #CBD5E1;
}
.ilm-modal-btn--ghost:hover {
    background: #E2E8F0;
    color: #0F172A;
}
.ilm-modal-btn--primary {
    background: linear-gradient(135deg, #0D7A87 0%, #1D3B5E 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 14px rgba(13, 122, 135, 0.28);
}
.ilm-modal-btn--primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(13, 122, 135, 0.38);
}
.ilm-modal-btn--gold {
    background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 14px rgba(245, 158, 11, 0.28);
}
.ilm-modal-btn--gold:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(245, 158, 11, 0.38);
}

/* ==============================================================================
 * README.md SECTION 4.4 — IN-PLACE DOM TRANSITION ANIMATIONS (ZERO PAGE RELOAD)
 * ============================================================================== */
@keyframes rowSmoothIn {
    0% {
        opacity: 0;
        transform: translateY(-8px) scale(0.98);
        background-color: rgba(13, 122, 135, 0.15);
    }
    100% {
        opacity: 1;
        transform: translateY(0) scale(1);
        background-color: transparent;
    }
}
@keyframes rowSmoothOut {
    0% {
        opacity: 1;
        transform: scale(1);
        max-height: 80px;
    }
    100% {
        opacity: 0;
        transform: scale(0.95);
        max-height: 0;
        padding-top: 0;
        padding-bottom: 0;
        margin: 0;
    }
}
@keyframes rowFlash {
    0%   { background-color: rgba(245, 158, 11, 0.32); }
    100% { background-color: transparent; }
}

.row-smooth-in {
    animation: rowSmoothIn 0.38s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
.row-smooth-out {
    animation: rowSmoothOut 0.32s cubic-bezier(0.4, 0, 0.2, 1) forwards;
    overflow: hidden;
    pointer-events: none;
}
.row-flash {
    animation: rowFlash 1.5s ease-out forwards;
}
</style>

<div class="ilmuna-prota-container">

    <!-- Cascading Filter Bar -->
    <div class="pdp-filter-bar">
        <div class="pdp-filter-group">
            <label class="pdp-filter-label" for="selectKelas">1. Rombel / Kelas</label>
            <select id="selectKelas" class="pdp-select">
                <option value="">Memuat daftar kelas...</option>
            </select>
        </div>

        <div class="pdp-filter-group">
            <label class="pdp-filter-label" for="selectMapel">2. Mata Pelajaran</label>
            <select id="selectMapel" class="pdp-select" disabled>
                <option value="">Pilih rombel dahulu</option>
            </select>
        </div>

        <div class="pdp-filter-group" style="max-width: 160px;">
            <label class="pdp-filter-label" for="selectTahun">3. Tahun Ajaran</label>
            <select id="selectTahun" class="pdp-select">
                <option value="2026/2027" selected>2026/2027</option>
                <option value="2025/2026">2025/2026</option>
                <option value="2027/2028">2027/2028</option>
            </select>
        </div>

        <div class="pdp-filter-group" style="max-width: 150px;">
            <label class="pdp-filter-label" for="selectSemester">4. Semester</label>
            <select id="selectSemester" class="pdp-select">
                <option value="Ganjil" selected>Ganjil</option>
                <option value="Genap">Genap</option>
            </select>
        </div>

        <div class="pdp-filter-group" style="max-width: 180px;">
            <label class="pdp-filter-label" for="selectHariMengajar">5. Hari KBM di Kelas</label>
            <select id="selectHariMengajar" class="pdp-select">
                <option value="Senin">Senin</option>
                <option value="Selasa">Selasa</option>
                <option value="Rabu">Rabu</option>
                <option value="Kamis">Kamis</option>
                <option value="Jumat">Jumat</option>
                <option value="Sabtu">Sabtu</option>
            </select>
        </div>

        <div style="display: flex; align-items: flex-end; gap: 8px;">
            <button type="button" id="btnSyncKaldik" class="pdp-btn pdp-btn-outline" title="Sinkronkan dari Kalender Pendidikan Sekolah">
                <iconify-icon icon="solar:refresh-bold" width="18"></iconify-icon>
                <span>Sync Kaldik</span>
            </button>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="pdp-tabs-nav">
        <button type="button" class="pdp-tab-btn <?= $tabActive === 'prota' ? 'is-active' : '' ?>" data-target="tabProta">
            <iconify-icon icon="solar:diploma-verified-bold" width="18"></iconify-icon>
            <span>Program Tahunan (PROTA)</span>
            <span class="pdp-tab-badge" id="badgeProtaCount">0 TP</span>
        </button>
        <button type="button" class="pdp-tab-btn <?= $tabActive === 'prosem' ? 'is-active' : '' ?>" data-target="tabProsem">
            <iconify-icon icon="solar:calendar-bold" width="18"></iconify-icon>
            <span>Program Semester (PROSEM)</span>
            <span class="pdp-tab-badge" id="badgeProsemJp">0 JP</span>
        </button>
        <button type="button" class="pdp-tab-btn <?= $tabActive === 'kaldik' ? 'is-active' : '' ?>" data-target="tabKaldik">
            <iconify-icon icon="solar:calendar-date-bold" width="18"></iconify-icon>
            <span>Pekan Efektif (Kaldik / RPE)</span>
            <span class="pdp-tab-badge" id="badgeHeCount">0 HE</span>
        </button>
        <button type="button" class="pdp-tab-btn <?= $tabActive === 'distribusi' ? 'is-active' : '' ?>" data-target="tabDistribusi">
            <iconify-icon icon="solar:pie-chart-2-bold" width="18"></iconify-icon>
            <span>Distribusi Alokasi JTM</span>
            <span class="pdp-tab-badge" id="badgeJtmCount">0 JTM</span>
        </button>
    </div>

    <!-- ======================================================================
     * TAB 1: PROGRAM TAHUNAN (PROTA)
     * ====================================================================== -->
    <div id="tabProta" class="pdp-tab-pane" style="<?= $tabActive === 'prota' ? '' : 'display:none;' ?>">
        
        <!-- Main Card PROTA Table -->
        <div class="pdp-card">
            <div class="pdp-card-head">
                <div>
                    <h3 class="pdp-card-title">
                        <iconify-icon icon="solar:diploma-verified-bold" width="22" style="color: var(--lumina-teal);"></iconify-icon>
                        Rincian Tujuan Pembelajaran & Alokasi Jam (PROTA)
                    </h3>
                    <div style="display: flex; gap: 8px; align-items: center; margin-top: 6px;">
                        <span class="pdp-badge pdp-badge-approved" id="badgeProtaFase">Fase F</span>
                        <span class="pdp-badge pdp-badge-draft" id="badgeProtaTotalJtm">Total: 0 JP</span>
                        <span class="pdp-badge pdp-badge-approved" id="badgeProtaStatus">Draft</span>
                    </div>
                </div>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <button type="button" id="btnOpenModalTambahTp" class="pdp-btn pdp-btn-gold" style="background: linear-gradient(135deg, #059669 0%, #0D7A87 100%);">
                        <iconify-icon icon="solar:add-circle-bold" width="18"></iconify-icon>
                        <span>+ Tambah TP Baru</span>
                    </button>
                    <button type="button" id="btnGenerateProta" class="pdp-btn pdp-btn-gold">
                        <iconify-icon icon="solar:magic-stick-3-bold" width="18"></iconify-icon>
                        <span>Generate dari TP</span>
                    </button>
                    <button type="button" id="btnAddProtaRow" class="pdp-btn pdp-btn-outline">
                        <iconify-icon icon="solar:list-check-bold" width="18"></iconify-icon>
                        <span>Pilih TP ke PROTA</span>
                    </button>
                    <button type="button" id="btnPrintProta" class="pdp-btn pdp-btn-primary" title="Unduh berkas PDF resmi PROTA langsung (Metode Direct Blob Download)">
                        <iconify-icon icon="solar:file-download-bold" width="18"></iconify-icon>
                        <span>Unduh PDF PROTA</span>
                    </button>
                </div>
            </div>

            <div class="pdp-table-wrap">
                <table class="pdp-table" id="tableProta">
                    <thead>
                        <tr>
                            <th style="width: 50px;">No</th>
                            <th style="width: 100px;">Semester</th>
                            <th style="width: 180px;">Elemen Capaian</th>
                            <th style="width: 110px;">Kode TP</th>
                            <th>Tujuan Pembelajaran (TP) & Pokok Materi</th>
                            <th style="width: 110px;">Alokasi JP</th>
                            <th style="width: 90px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyProta">
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px; color: #94A3B8;">
                                <iconify-icon icon="solar:info-circle-linear" width="28" style="vertical-align: middle; margin-right: 6px;"></iconify-icon>
                                Pilih rombel kelas dan mata pelajaran untuk melihat Program Tahunan (PROTA).
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ======================================================================
     * TAB 2: PROGRAM SEMESTER (PROSEM)
     * ====================================================================== -->
    <div id="tabProsem" class="pdp-tab-pane" style="<?= $tabActive === 'prosem' ? '' : 'display:none;' ?>">
        
        <!-- Main Card PROSEM Table (Interactive Weekly Schedule Grid) -->
        <div class="pdp-card">
            <div class="pdp-card-head">
                <div>
                    <h3 class="pdp-card-title">
                        <iconify-icon icon="solar:calendar-bold" width="22" style="color: var(--lumina-teal);"></iconify-icon>
                        Matriks Distribusi Jam Mengajar Mingguan (PROSEM)
                    </h3>
                    <p style="font-size: 13px; color: #64748B; margin: 4px 0 0 0;">
                        Matriks 6 Bulan x 5 Pekan. Ubah angka alokasi JP per sel secara realtime. Pekan bergaris abu-abu merupakan agenda non-efektif (MPLS, STS, ASAS, Libur) dari Kaldik.
                    </p>
                </div>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <button type="button" id="btnGenerateProsem" class="pdp-btn pdp-btn-gold">
                        <iconify-icon icon="solar:magic-stick-3-bold" width="18"></iconify-icon>
                        <span>Generate dari PROTA & Kaldik</span>
                    </button>
                    <button type="button" id="btnRebalanceProsem" class="pdp-btn pdp-btn-outline" title="Susun ulang distribusi pekan efektif secara berantai (cascade shift)">
                        <iconify-icon icon="solar:sort-vertical-bold" width="18"></iconify-icon>
                        <span>Sesuaikan / Geser Jadwal</span>
                    </button>
                    <button type="button" id="btnAddProsemAgenda" class="pdp-btn pdp-btn-outline">
                        <iconify-icon icon="solar:add-circle-bold" width="18"></iconify-icon>
                        <span>Tambah Agenda</span>
                    </button>
                    <button type="button" id="btnPrintProsem" class="pdp-btn pdp-btn-primary" title="Unduh berkas PDF resmi PROSEM langsung (Metode Direct Blob Download)">
                        <iconify-icon icon="solar:file-download-bold" width="18"></iconify-icon>
                        <span>Unduh PDF PROSEM</span>
                    </button>
                </div>
            </div>

            <div class="pdp-table-wrap">
                <table class="pdp-table" id="tableProsemMatrix">
                    <thead id="theadProsemMatrix">
                        <!-- Dynamic Month Headers -->
                    </thead>
                    <tbody id="tbodyProsemMatrix">
                        <tr>
                            <td colspan="35" style="text-align: center; padding: 40px; color: #94A3B8;">
                                <iconify-icon icon="solar:info-circle-linear" width="28" style="vertical-align: middle; margin-right: 6px;"></iconify-icon>
                                Pilih rombel kelas dan mata pelajaran untuk melihat Program Semester (PROSEM).
                            </td>
                        </tr>
                    </tbody>
                    <tfoot id="tfootProsemMatrix" style="font-weight: 800; background: #F8FAFC;">
                        <!-- Dynamic Summary Row -->
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- ======================================================================
     * TAB 3: PEKAN EFEKTIF (KALDIK & RPE)
     * ====================================================================== -->
    <div id="tabKaldik" class="pdp-tab-pane" style="<?= $tabActive === 'kaldik' ? '' : 'display:none;' ?>">
        
        <div class="pdp-card">
            <div class="pdp-card-head">
                <h3 class="pdp-card-title">
                    <iconify-icon icon="solar:calendar-date-bold" width="22" style="color: var(--lumina-teal);"></iconify-icon>
                    Rincian Pekan Efektif (RPE) Per Bulan dari Kalender Pendidikan
                </h3>
            </div>

            <div class="pdp-table-wrap">
                <table class="pdp-table" id="tableRpe">
                    <thead>
                        <tr>
                            <th rowspan="2" style="width: 50px;">No</th>
                            <th rowspan="2" style="width: 140px;">Bulan</th>
                            <th colspan="7">Distribusi Hari Efektif KBM</th>
                            <th rowspan="2" style="width: 90px;">Total HE</th>
                            <th rowspan="2" style="width: 80px;">Jml Pekan</th>
                            <th rowspan="2" style="width: 80px;">Pekan Efektif</th>
                            <th rowspan="2" style="width: 80px;">Pekan Non-Efektif</th>
                            <th rowspan="2">Keterangan / Agenda Non-Efektif</th>
                        </tr>
                        <tr>
                            <th style="width: 45px;">Sn</th>
                            <th style="width: 45px;">Sl</th>
                            <th style="width: 45px;">Rb</th>
                            <th style="width: 45px;">Km</th>
                            <th style="width: 45px;">Jm</th>
                            <th style="width: 45px;">Sb</th>
                            <th style="width: 45px;">Mg</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyRpe">
                        <tr>
                            <td colspan="14" style="text-align: center; padding: 40px; color: #94A3B8;">
                                Memuat data Rincian Pekan Efektif...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ======================================================================
     * TAB 4: DISTRIBUSI ALOKASI JTM
     * ====================================================================== -->
    <div id="tabDistribusi" class="pdp-tab-pane" style="<?= $tabActive === 'distribusi' ? '' : 'display:none;' ?>">
        <div class="pdp-card">
            <div class="pdp-card-head">
                <div>
                    <h3 class="pdp-card-title">
                        <iconify-icon icon="solar:pie-chart-2-bold" width="22" style="color: var(--lumina-teal);"></iconify-icon>
                        Perhitungan Jam Tatap Muka (JTM) & Hari Mengajar Guru
                    </h3>
                    <p style="font-size: 13px; color: #64748B; margin: 4px 0 0 0;">
                        Tabel `alokasi_hari_mengajar`: Mengkorelasikan jadwal hari tatap muka guru dengan total hari efektif sekolah di semester berjalan.
                    </p>
                </div>
                <button type="button" id="btnSaveAlokasi" class="pdp-btn pdp-btn-primary">
                    <iconify-icon icon="solar:diskette-bold" width="18"></iconify-icon>
                    <span>Simpan Alokasi Hari</span>
                </button>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; background: #F8FAFC; padding: 20px; border-radius: 14px; margin-bottom: 20px;">
                <div class="pdp-form-group">
                    <label class="pdp-form-label">Hari Mengajar KBM di Kelas</label>
                    <select id="formAlokasiHari" class="pdp-select">
                        <option value="Senin">Senin</option>
                        <option value="Selasa">Selasa</option>
                        <option value="Rabu">Rabu</option>
                        <option value="Kamis">Kamis</option>
                        <option value="Jumat">Jumat</option>
                        <option value="Sabtu">Sabtu</option>
                    </select>
                </div>

                <div class="pdp-form-group">
                    <label class="pdp-form-label">Alokasi JP per Minggu</label>
                    <input type="number" id="formAlokasiJp" class="pdp-input" value="2" min="1" max="10">
                </div>

                <div class="pdp-form-group">
                    <label class="pdp-form-label">Banyaknya Hari Efektif Terhitung</label>
                    <input type="text" id="formAlokasiBanyakHari" class="pdp-input" value="0 Hari" readonly style="background: #E2E8F0; font-weight: 700;">
                </div>

                <div class="pdp-form-group">
                    <label class="pdp-form-label">Total JTM (Hari Efektif × JP/Minggu)</label>
                    <input type="text" id="formAlokasiTotalJtm" class="pdp-input" value="0 JP" readonly style="background: #E2E8F0; font-weight: 800; color: var(--lumina-teal);">
                </div>
            </div>

            <div style="padding: 16px; background: rgba(13, 122, 135, 0.06); border-radius: 12px; border: 1px dashed rgba(13, 122, 135, 0.25);">
                <div style="display: flex; align-items: flex-start; gap: 12px;">
                    <iconify-icon icon="solar:info-circle-bold" width="24" style="color: var(--lumina-teal); flex-shrink: 0; margin-top: 2px;"></iconify-icon>
                    <div style="font-size: 13.5px; color: #1E293B; line-height: 1.5;">
                        <strong>Prinsip Sinkronisasi Kurikulum Merdeka:</strong><br>
                        Setiap perubahan pada Kalender Pendidikan (Kaldik) yang mengubah status hari libur atau KBM akan otomatis mengalkulasi ulang jumlah hari efektif pertemuan guru. Total JTM ini kemudian menjadi batas maksimal jam pada Program Semester (PROSEM).
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- ======================================================================
 * MODAL: TAMBAH TUJUAN PEMBELAJARAN (TP) BARU UNTUK MAPEL INI
 * ====================================================================== -->
<div class="ilm-modal-backdrop" id="modalTambahTpBackdrop">
    <div class="ilm-modal-dialog ilm-modal" id="modalTambahTpDialog" style="max-width: 640px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon ilm-modal-icon-wrap" style="background: rgba(5, 150, 105, 0.12); color: #059669;">
                    <iconify-icon icon="solar:diploma-verified-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title ilm-modal-title">Tambah Tujuan Pembelajaran (TP)</h3>
                    <p class="ilm-modal-header__subtitle ilm-modal-subtitle">Berbasis Elemen & Capaian Pembelajaran (CP) dari Database</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close ilm-modal-close" id="btnCloseModalTambahTp">
                <iconify-icon icon="solar:close-circle-bold" width="22"></iconify-icon>
            </button>
        </div>

        <div class="ilm-modal-body">
            <!-- STEP 1: Pilih Elemen -->
            <div class="pdp-form-group">
                <label class="pdp-form-label">
                    <span>1. Elemen Kurikulum (dari Database)</span>
                    <span style="color: #DC2626;">*</span>
                </label>
                <select id="inputTpElemen" class="pdp-select">
                    <!-- Populated dynamically -->
                </select>
            </div>

            <!-- STEP 2: Pilih CP -->
            <div class="pdp-form-group">
                <label class="pdp-form-label">
                    <span>2. Capaian Pembelajaran / CP (dari Database)</span>
                    <span style="color: #DC2626;">*</span>
                </label>
                <select id="inputTpCp" class="pdp-select">
                    <option value="">-- Pilih Elemen Terlebih Dahulu --</option>
                </select>
                <!-- Preview Box Teks CP -->
                <div id="boxPreviewCp" style="display: none; margin-top: 8px; padding: 10px 12px; background: #F0FDF4; border: 1px solid #BBF7D0; border-radius: 8px; font-size: 12px; color: #166534; line-height: 1.5;">
                    <div style="font-weight: 700; margin-bottom: 2px; display: flex; align-items: center; gap: 4px;">
                        <iconify-icon icon="solar:document-text-bold" width="16"></iconify-icon>
                        <span>Teks Capaian Pembelajaran (CP):</span>
                    </div>
                    <div id="textPreviewCp"></div>
                </div>
            </div>

            <!-- STEP 3: Box Kalkulasi Waktu & Pekan Efektif dari Database -->
            <div style="background: linear-gradient(135deg, #F8FAFC 0%, #F1F5F9 100%); border: 1px solid #E2E8F0; border-radius: 10px; padding: 12px 14px; margin-bottom: 16px;">
                <div style="font-weight: 700; font-size: 12.5px; color: var(--lumina-navy); margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                    <iconify-icon icon="solar:calculator-minimalistic-bold" width="18" style="color: var(--lumina-teal);"></iconify-icon>
                    <span>Kalkulasi Alokasi Waktu KBM (Pekan Efektif Kaldik)</span>
                </div>
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; text-align: center;">
                    <div style="background: #FFFFFF; border: 1px solid #E2E8F0; padding: 6px; border-radius: 6px;">
                        <div style="font-size: 10.5px; color: #64748B;">Pekan Efektif</div>
                        <div style="font-size: 13px; font-weight: 800; color: #059669;" id="calcBoxPekanEfektif">-</div>
                    </div>
                    <div style="background: #FFFFFF; border: 1px solid #E2E8F0; padding: 6px; border-radius: 6px;">
                        <div style="font-size: 10.5px; color: #64748B;">JTM / Pekan</div>
                        <div style="font-size: 13px; font-weight: 800; color: var(--lumina-navy);" id="calcBoxJpPerPekan">-</div>
                    </div>
                    <div style="background: #FFFFFF; border: 1px solid #E2E8F0; padding: 6px; border-radius: 6px;">
                        <div style="font-size: 10.5px; color: #64748B;">JP Terpakai</div>
                        <div style="font-size: 13px; font-weight: 800; color: #D97706;" id="calcBoxJpTerpakai">-</div>
                    </div>
                    <div style="background: #FFFFFF; border: 1px solid #BBF7D0; padding: 6px; border-radius: 6px;">
                        <div style="font-size: 10.5px; color: #166534;">Sisa JP Tersedia</div>
                        <div style="font-size: 13px; font-weight: 800; color: #16A34A;" id="calcBoxSisaJp">-</div>
                    </div>
                </div>
                <div style="font-size: 11px; color: #64748B; margin-top: 6px;">
                    * Hanya pekan efektif KBM yang dihitung. Pekan libur / non-efektif diabaikan sesuai Kalender Pendidikan.
                </div>
            </div>

            <!-- STEP 4: Input TP & Materi -->
            <div style="display: grid; grid-template-columns: 140px 1fr; gap: 14px;">
                <div class="pdp-form-group">
                    <label class="pdp-form-label">Kode TP</label>
                    <input type="text" id="inputTpKode" class="pdp-input" placeholder="Contoh: 1.1">
                </div>
                <div class="pdp-form-group">
                    <label class="pdp-form-label">Materi Pokok / Lingkup Materi</label>
                    <input type="text" id="inputTpMateri" class="pdp-input" placeholder="Contoh: Ketentuan Zakat, Infak, dan Sedekah">
                </div>
            </div>

            <div class="pdp-form-group">
                <label class="pdp-form-label">Uraian Tujuan Pembelajaran (TP)</label>
                <textarea id="inputTpUraian" class="pdp-input" rows="3" placeholder="Contoh: Peserta didik mampu menganalisis ketentuan zakat fitrah dan zakat mal serta mempraktikkannya dalam kehidupan sehari-hari..."></textarea>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                <div class="pdp-form-group">
                    <label class="pdp-form-label">Semester</label>
                    <select id="inputTpSemester" class="pdp-select">
                        <option value="Ganjil" selected>Semester Ganjil</option>
                        <option value="Genap">Semester Genap</option>
                    </select>
                </div>
                <div class="pdp-form-group">
                    <label class="pdp-form-label">
                        <span>Alokasi Jam Tatap Muka (JP)</span>
                        <span id="labelSisaJpHelper" style="font-size: 11px; color: #16A34A; font-weight: normal; margin-left: 4px;"></span>
                    </label>
                    <input type="number" id="inputTpAlokasi" class="pdp-input" value="4" min="1" max="24">
                </div>
            </div>

            <div style="background: #F8FAFC; border: 1px solid #E2E8F0; padding: 12px 14px; border-radius: 10px; display: flex; flex-direction: column; gap: 8px;">
                <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: #1E293B; cursor: pointer;">
                    <input type="checkbox" id="checkTpMasukProta" checked style="width: 16px; height: 16px;">
                    <span>Langsung masukkan ke Program Tahunan (PROTA)</span>
                </label>
                <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: #1E293B; cursor: pointer;">
                    <input type="checkbox" id="checkTpMasukProsem" checked style="width: 16px; height: 16px;">
                    <span>Langsung masukkan ke Program Semester (PROSEM) & Matriks Kaldik</span>
                </label>
            </div>
        </div>

        <div class="ilm-modal-footer">
            <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost ilm-modal-btn-cancel" id="btnCancelTambahTpModal">Batal</button>
            <button type="button" class="ilm-modal-btn ilm-modal-btn--gold pdp-btn-gold" id="btnSubmitNewTp" style="background: linear-gradient(135deg, #059669 0%, #0D7A87 100%);">
                <iconify-icon icon="solar:check-circle-bold" width="18"></iconify-icon>
                <span>Simpan TP</span>
            </button>
        </div>
    </div>
</div>

<!-- ======================================================================
 * MODAL: TAMBAH / EDIT BARIS PROTA DETAIL
 * ====================================================================== -->
<div class="ilm-modal-backdrop" id="modalProtaRowBackdrop">
    <div class="ilm-modal-dialog ilm-modal" id="modalProtaRowDialog" style="max-width: 600px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon ilm-modal-icon-wrap" style="background: rgba(13, 122, 135, 0.12); color: #0D7A87;">
                    <iconify-icon icon="solar:diploma-verified-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title ilm-modal-title" id="modalProtaTitle">Tambah Baris PROTA</h3>
                    <p class="ilm-modal-header__subtitle ilm-modal-subtitle">Tentukan Tujuan Pembelajaran (TP) & Alokasi Jam Pertemuan</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close ilm-modal-close" id="btnCloseModalProta">
                <iconify-icon icon="solar:close-circle-bold" width="22"></iconify-icon>
            </button>
        </div>

        <div class="ilm-modal-body">
            <input type="hidden" id="protaRowId" value="0">

            <div class="pdp-form-group">
                <label class="pdp-form-label">Semester</label>
                <select id="modalProtaSemester" class="pdp-select">
                    <option value="Ganjil">Semester Ganjil</option>
                    <option value="Genap">Semester Genap</option>
                </select>
            </div>

            <div class="pdp-form-group">
                <label class="pdp-form-label">Elemen Capaian Kurikulum</label>
                <select id="modalProtaElemen" class="pdp-select">
                    <option value="">-- Pilih Elemen --</option>
                </select>
            </div>

            <div class="pdp-form-group">
                <label class="pdp-form-label">Tujuan Pembelajaran (TP)</label>
                <select id="modalProtaTp" class="pdp-select">
                    <option value="">-- Pilih Tujuan Pembelajaran --</option>
                </select>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                <div class="pdp-form-group">
                    <label class="pdp-form-label">Nomor Urut</label>
                    <input type="number" id="modalProtaNomorUrut" class="pdp-input" value="1" min="1">
                </div>

                <div class="pdp-form-group">
                    <label class="pdp-form-label">Alokasi Waktu (JP)</label>
                    <input type="number" id="modalProtaAlokasi" class="pdp-input" value="4" min="1" max="20">
                </div>
            </div>
        </div>

        <div class="ilm-modal-footer">
            <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost ilm-modal-btn-cancel" id="btnCancelProtaModal">Batal</button>
            <button type="button" class="ilm-modal-btn ilm-modal-btn--primary ilm-modal-btn-confirm" id="btnSaveProtaRow">Simpan ke PROTA</button>
        </div>
    </div>
</div>

<!-- ======================================================================
 * MODAL: TAMBAH / EDIT AGENDA PROSEM DETAIL
 * ====================================================================== -->
<div class="ilm-modal-backdrop" id="modalProsemRowBackdrop">
    <div class="ilm-modal-dialog ilm-modal" id="modalProsemRowDialog" style="max-width: 600px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon ilm-modal-icon-wrap" style="background: rgba(13, 122, 135, 0.12); color: #0D7A87;">
                    <iconify-icon icon="solar:calendar-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title ilm-modal-title" id="modalProsemTitle">Tambah Agenda PROSEM</h3>
                    <p class="ilm-modal-header__subtitle ilm-modal-subtitle">Tambahkan Materi KBM atau Agenda Asesmen / Proyek</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close ilm-modal-close" id="btnCloseModalProsem">
                <iconify-icon icon="solar:close-circle-bold" width="22"></iconify-icon>
            </button>
        </div>

        <div class="ilm-modal-body">
            <input type="hidden" id="prosemRowId" value="0">

            <div class="pdp-form-group">
                <label class="pdp-form-label">Kategori Agenda</label>
                <select id="modalProsemKategori" class="pdp-select">
                    <option value="Materi KBM">Materi KBM (Tujuan Pembelajaran)</option>
                    <option value="Asesmen Sumatif">Asesmen Sumatif (AS)</option>
                    <option value="Sumatif Tengah Semester">Sumatif Tengah Semester (STS)</option>
                    <option value="Proyek">Proyek P5 / PPRA</option>
                    <option value="Asesmen Sumatif Akhir Semester">Asesmen Sumatif Akhir Semester (ASAS)</option>
                    <option value="Remedial">Remedial / Pendalaman</option>
                </select>
            </div>

            <div class="pdp-form-group" id="groupProsemTpSelect">
                <label class="pdp-form-label">Tujuan Pembelajaran (TP)</label>
                <select id="modalProsemTp" class="pdp-select">
                    <option value="">-- Pilih Tujuan Pembelajaran --</option>
                </select>
            </div>

            <div class="pdp-form-group" id="groupProsemNamaAgenda" style="display: none;">
                <label class="pdp-form-label">Nama / Judul Agenda</label>
                <input type="text" id="modalProsemNamaAgenda" class="pdp-input" placeholder="Contoh: Asesmen Sumatif Lingkup Materi 1">
            </div>

            <div class="pdp-form-group">
                <label class="pdp-form-label">Tautkan Modul Ajar (Opsional)</label>
                <select id="modalProsemModul" class="pdp-select">
                    <option value="">-- Tidak Ditautkan ke Modul Ajar --</option>
                </select>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                <div class="pdp-form-group">
                    <label class="pdp-form-label">Nomor Urut Pelaksanaan</label>
                    <input type="number" id="modalProsemNomorUrut" class="pdp-input" value="1" min="1">
                </div>

                <div class="pdp-form-group">
                    <label class="pdp-form-label">Alokasi Waktu (JP)</label>
                    <input type="number" id="modalProsemAlokasi" class="pdp-input" value="2" min="1" max="20">
                </div>
            </div>
        </div>

        <div class="ilm-modal-footer">
            <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost ilm-modal-btn-cancel" id="btnCancelProsemModal">Batal</button>
            <button type="button" class="ilm-modal-btn ilm-modal-btn--primary ilm-modal-btn-confirm" id="btnSaveProsemRow">Simpan Agenda</button>
        </div>
    </div>
</div>

<!-- ======================================================================
 * MODAL: PRATINJAU & CETAK DOKUMEN RESMI (PROTA / PROSEM)
 * ====================================================================== -->
<div class="ilm-modal-backdrop" id="modalPrintBackdrop">
    <div class="ilm-modal-dialog ilm-modal" id="modalPrintDialog" style="max-width: 1000px; width: 95%;">
        <div class="ilm-modal-header no-print">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon ilm-modal-icon-wrap" style="background: rgba(13, 122, 135, 0.12); color: #0D7A87;">
                    <iconify-icon icon="solar:printer-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title ilm-modal-title" id="modalPrintTitle">Pratinjau Dokumen Resmi</h3>
                    <p class="ilm-modal-header__subtitle ilm-modal-subtitle">Dokumen Kurikulum Merdeka Siap Cetak (A4)</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close ilm-modal-close" id="btnCloseModalPrint">
                <iconify-icon icon="solar:close-circle-bold" width="22"></iconify-icon>
            </button>
        </div>

        <div class="ilm-modal-body" style="max-height: 70vh; overflow-y: auto;">
            <div id="printContentArea" style="background: #FFFFFF; padding: 20px; font-family: 'Times New Roman', Times, serif; color: #000000;">
                <!-- Printable HTML Content Generated Dynamically -->
            </div>
        </div>

        <div class="ilm-modal-footer no-print">
            <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost ilm-modal-btn-cancel" id="btnCancelPrintModal">Tutup</button>
            <a href="#" target="_blank" class="ilm-modal-btn pdp-btn-outline" id="btnOpenDirectPrint" style="text-decoration: none;">
                <iconify-icon icon="solar:arrow-right-up-bold" width="18"></iconify-icon>
                <span>Buka Dokumen Penuh di Tab Baru</span>
            </a>
            <button type="button" class="ilm-modal-btn ilm-modal-btn--primary pdp-btn-primary" id="btnTriggerBrowserPrint">
                <iconify-icon icon="solar:printer-bold" width="18"></iconify-icon>
                <span>Cetak Sekarang (Print)</span>
            </button>
        </div>
    </div>
</div>

<!-- ======================================================================
 * JAVASCRIPT LOGIC & ASYNCHRONOUS ENGINE
 * ====================================================================== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Safe notification wrappers (works with or without Ilmuna UI)
    function notifyToast(type, title, msg) {
        if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
            window.Ilmuna.toast(type, title, msg);
        } else {
            console.log('[' + type.toUpperCase() + '] ' + title + ': ' + msg);
        }
    }
    function notifyAlert(type, title, msg) {
        if (window.Ilmuna && typeof window.Ilmuna.alert === 'function') {
            window.Ilmuna.alert(type, title, msg);
        } else {
            alert((title ? title + '\n\n' : '') + msg);
        }
    }
    function notifyConfirm(type, title, msg, btnText) {
        if (window.Ilmuna && typeof window.Ilmuna.confirm === 'function') {
            return window.Ilmuna.confirm(type, title, msg, btnText);
        } else {
            return Promise.resolve(confirm((title ? title + '\n\n' : '') + msg));
        }
    }

    if (!window.Ilmuna) {
        window.Ilmuna = {
            toast: notifyToast,
            alert: notifyAlert,
            confirm: notifyConfirm
        };
    }

    // Modal backdrop click to close
    document.querySelectorAll('.ilm-modal-backdrop').forEach(function(backdrop) {
        backdrop.addEventListener('click', function(e) {
            if (e.target === backdrop) {
                closeModal(backdrop);
            }
        });


    });



    // ESC key to close open modals
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' || e.keyCode === 27) {
            document.querySelectorAll('.ilm-modal-backdrop.is-open, .ilm-modal-backdrop.show').forEach(function(b) {
                closeModal(b);
            });


        }
    });



    window._classList = [];
    var state = {
        id_kelas: 0,
        id_kmp: 0,
        tahun: '2026/2027',
        semester: 'Ganjil',
        hari_mengajar: 'Senin',
        jp_per_pekan: 2,
        prota: null,
        prota_details: [],
        prosem: null,
        prosem_details: [],
        prosem_mingguan: {},
        rpe_rows: [],
        master_elemen: [],
        master_cp: [],
        master_tp: [],
        master_modul: [],
        perhitungan_alokasi: null,
        kmp_info: null
    };

    // DOM Elements
    var selKelas = document.getElementById('selectKelas');
    var selMapel = document.getElementById('selectMapel');
    var selTahun = document.getElementById('selectTahun');
    var selSem   = document.getElementById('selectSemester');
    var selHari  = document.getElementById('selectHariMengajar');

    // 1. Load Daftar Kelas
    async function loadKelas() {
        try {
            var fd = new FormData();
            fd.append('action', 'list_kelas_options');
            var res = await fetch('', { method: 'POST', body: fd });
            var j = await res.json();
            if (!j.success) throw new Error(j.message);

            window._classList = j.data.kelas || [];
            selKelas.innerHTML = '<option value="">-- Pilih Rombel Kelas --</option>';
            window._classList.forEach(function(k) {
                var opt = document.createElement('option');
                opt.value = k.id;
                opt.textContent = k.nama_kelas + ' (' + k.total_siswa + ' Siswa)';
                selKelas.appendChild(opt);
            });



            if (window._classList.length > 0) {
                var firstK = window._classList[0];
                selKelas.value = firstK.id;
                state.id_kelas = parseInt(firstK.id, 10);
                if (firstK.tahun_pelajaran) {
                    selTahun.value = firstK.tahun_pelajaran;
                    state.tahun = firstK.tahun_pelajaran;
                }
                loadMapel();
            }
        } catch (err) {
            console.error('Error loadKelas:', err);
            Ilmuna.toast('error', 'Gagal', 'Tidak dapat memuat daftar rombel kelas.');
        }
    }

    // 2. Load Daftar Mapel by Kelas
    async function loadMapel() {
        if (!state.id_kelas) {
            selMapel.innerHTML = '<option value="">Pilih rombel dahulu</option>';
            selMapel.disabled = true;
            return;
        }

        try {
            var fd = new FormData();
            fd.append('action', 'get_mapel_by_kelas');
            fd.append('id_kelas', state.id_kelas);
            var res = await fetch('', { method: 'POST', body: fd });
            var j = await res.json();
            if (!j.success) throw new Error(j.message);

            selMapel.innerHTML = '<option value="">-- Pilih Mata Pelajaran --</option>';
            j.data.mapel.forEach(function(m) {
                var opt = document.createElement('option');
                opt.value = m.id;
                opt.textContent = m.nama_mata_pelajaran + ' (' + (m.kode_mapel || 'MAPEL') + ')';
                selMapel.appendChild(opt);
            });


            selMapel.disabled = false;

            if (j.data.mapel.length > 0) {
                selMapel.value = j.data.mapel[0].id;
                state.id_kmp = parseInt(j.data.mapel[0].id, 10);
                loadState();
            }
        } catch (err) {
            console.error('Error loadMapel:', err);
            Ilmuna.toast('error', 'Gagal', 'Tidak dapat memuat daftar mata pelajaran.');
        }
    }

    // 3. Load State Lengkap (Kaldik, RPE, Prota, Prosem)
    async function loadState() {
        if (!state.id_kmp) return;

        state.tahun = selTahun.value || '2026/2027';
        state.semester = selSem.value || 'Ganjil';
        state.hari_mengajar = selHari.value || 'Senin';

        try {
            var fd = new FormData();
            fd.append('action', 'get_prota_prosem_state');
            fd.append('id_kelas', state.id_kelas);
            fd.append('id_kmp', state.id_kmp);
            fd.append('tahun_pelajaran', state.tahun);
            fd.append('semester', state.semester);

            var res = await fetch('', { method: 'POST', body: fd });
            var j = await res.json();
            if (!j.success) throw new Error(j.message);

            var d = j.data;
            state.kmp_info = d.kmp_info;
            state.prota = d.prota;
            state.prota_details = d.prota_details || [];
            state.prosem = d.prosem;
            state.prosem_details = d.prosem_details || [];
            state.prosem_mingguan = d.prosem_mingguan || {};
            state.rpe_rows = (d.rpe && d.rpe.rows) ? d.rpe.rows : [];
            state.master_elemen = d.master_elemen || [];
            state.master_cp = d.master_cp || [];
            state.master_tp = d.master_tp || [];
            state.master_modul = d.master_modul || [];
            state.perhitungan_alokasi = d.perhitungan_alokasi || null;

            if (d.alokasi_mengajar) {
                state.hari_mengajar = d.alokasi_mengajar.hari || 'Senin';
                state.jp_per_pekan = parseInt(d.alokasi_mengajar.jp_per_minggu, 10) || 2;
                selHari.value = state.hari_mengajar;
                var elFh = document.getElementById('formAlokasiHari');
                if (elFh) elFh.value = state.hari_mengajar;
                var elFjp = document.getElementById('formAlokasiJp');
                if (elFjp) elFjp.value = state.jp_per_pekan;
                var elFbh = document.getElementById('formAlokasiBanyakHari');
                if (elFbh) elFbh.value = (d.alokasi_mengajar.banyaknya_hari_efektif || 0) + ' Pertemuan';
                var elFtj = document.getElementById('formAlokasiTotalJtm');
                if (elFtj) elFtj.value = (d.alokasi_mengajar.total_jtm || 0) + ' JP';
            }

            // Update Badge Navigasi
            var bProta = document.getElementById('badgeProtaCount');
            if (bProta) bProta.textContent = state.prota_details.length + ' TP';
            var bProsem = document.getElementById('badgeProsemJp');
            if (bProsem) bProsem.textContent = (state.prosem ? state.prosem.total_alokasi_jp : 0) + ' JP';
            var bHe = document.getElementById('badgeHeCount');
            if (bHe) bHe.textContent = (d.rpe ? d.rpe.total_he : 0) + ' HE';
            var bJtm = document.getElementById('badgeJtmCount');
            if (bJtm) bJtm.textContent = (d.alokasi_mengajar ? d.alokasi_mengajar.total_jtm : 0) + ' JTM';

            // Render Views
            renderProtaView(d);
            renderProsemView(d);
            renderRpeView(d.rpe || { rows: [], total_he: 0, total_pe: 0, total_pte: 0 });

        } catch (err) {
            console.error('Error loadState:', err);
            Ilmuna.toast('error', 'Gagal', err.message || 'Tidak dapat memuat state kurikulum.');
        }
    }

    // 4. Render PROTA View
    function renderProtaView(d) {
        var p = d.prota;
        var elBadgeFase = document.getElementById('badgeProtaFase');
        if (elBadgeFase) elBadgeFase.textContent = p ? p.fase : (d.fase_default || 'Fase F');
        
        var elBadgeJtm = document.getElementById('badgeProtaTotalJtm');
        if (elBadgeJtm) elBadgeJtm.textContent = 'Total: ' + (p ? p.total_jtm_tahunan : 0) + ' JP';

        var elBadgeStatus = document.getElementById('badgeProtaStatus');
        if (elBadgeStatus) {
            elBadgeStatus.textContent = p ? p.status : 'Draft';
            elBadgeStatus.className = 'pdp-badge ' + (p && p.status === 'Approved' ? 'pdp-badge-approved' : 'pdp-badge-draft');
        }

        var tbody = document.getElementById('tbodyProta');
        if (!tbody) return;

        if (!d.prota_details || d.prota_details.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 40px; color: #64748B;">'
                + '<iconify-icon icon="solar:document-add-linear" width="36" style="color: var(--lumina-teal); margin-bottom: 8px;"></iconify-icon>'
                + '<div style="font-weight: 700; font-size: 14px; margin-bottom: 6px; color: var(--lumina-navy);">Belum ada data Program Tahunan (PROTA)</div>'
                + '<p style="font-size: 13px; max-width: 480px; margin: 0 auto 16px; color: #64748B;">'
                    + 'Tambahkan Tujuan Pembelajaran (TP) yang Anda ampu, atau klik Generate dari TP jika master TP sudah terdata.'
                + '</p>'
                + '<div style="display: flex; gap: 8px; justify-content: center; flex-wrap: wrap;">'
                    + '<button type="button" class="pdp-btn pdp-btn-gold btn-call-tambah-tp" style="background: linear-gradient(135deg, #059669 0%, #0D7A87 100%);">'
                        + '<iconify-icon icon="solar:add-circle-bold" width="18"></iconify-icon>'
                        + '<span>+ Tambah TP Baru</span>'
                    + '</button>'
                    + '<button type="button" class="pdp-btn pdp-btn-outline btn-call-gen-prota">'
                        + '<iconify-icon icon="solar:magic-stick-3-bold" width="18"></iconify-icon>'
                        + '<span>Generate dari TP</span>'
                    + '</button>'
                + '</div>'
            + '</td></tr>';

            var bAddTpCall = tbody.querySelector('.btn-call-tambah-tp');
            if (bAddTpCall) bAddTpCall.addEventListener('click', openModalTambahTp);
            var bGenProtaCall = tbody.querySelector('.btn-call-gen-prota');
            if (bGenProtaCall) bGenProtaCall.addEventListener('click', function() { document.getElementById('btnGenerateProta').click(); });
            return;
        }

        var html = '';
        d.prota_details.forEach(function(row, idx) {
            html += '<tr id="row-prota-' + row.id + '">'
                + '<td style="text-align: center; font-weight: 700;">' + (idx + 1) + '</td>'
                + '<td style="text-align: center;"><span class="pdp-badge ' + (row.semester === 'Ganjil' ? 'pdp-badge-draft' : 'pdp-badge-approved') + '">' + row.semester + '</span></td>'
                + '<td><strong>' + (row.nama_elemen || '-') + '</strong></td>'
                + '<td style="text-align: center; font-weight: 700; color: var(--lumina-teal);">' + (row.kode_tp || '-') + '</td>'
                + '<td>'
                + '<div style="font-weight: 600; margin-bottom: 3px;">' + (row.materi_pokok || '') + '</div>'
                + '<div style="font-size: 12px; color: #64748B;">' + (row.uraian_tp || '-') + '</div>'
                + '</td>'
                + '<td style="text-align: center; font-weight: 800; color: var(--lumina-navy);">' + row.alokasi_waktu + ' JP</td>'
                + '<td style="text-align: center;">'
                    + '<div style="display: flex; gap: 4px; justify-content: center;">'
                        + '<button type="button" class="btn-edit-prota" data-id="' + row.id + '" style="background: none; border: none; color: #0284C7; cursor: pointer; padding: 4px;" title="Edit">'
                            + '<iconify-icon icon="solar:pen-bold" width="18"></iconify-icon>'
                        + '</button>'
                        + '<button type="button" class="btn-del-prota" data-id="' + row.id + '" style="background: none; border: none; color: #DC2626; cursor: pointer; padding: 4px;" title="Hapus">'
                            + '<iconify-icon icon="solar:trash-bin-trash-bold" width="18"></iconify-icon>'
                        + '</button>'
                    + '</div>'
                + '</td>'
            + '</tr>';
        });

        tbody.innerHTML = html;

        // Event delegation on tbodyProta for Edit & Delete PROTA
        tbody.onclick = function(e) {
            var btnDel = e.target.closest('.btn-del-prota');
            if (btnDel) {
                e.preventDefault();
                var rId = btnDel.getAttribute('data-id');
                Ilmuna.confirm('warning', 'Hapus Baris PROTA?', 'Tujuan pembelajaran ini akan dihapus dari Program Tahunan.', 'Ya, Hapus').then(async function(ok) {
                    if (!ok) return;
                    try {
                        var fd = new FormData();
                        fd.append('action', 'delete_prota_row');
                        fd.append('id_row', rId);
                        fd.append('id_prota', state.prota ? state.prota.id : 0);
                        var res = await fetch('', { method: 'POST', body: fd });
                        var j = await res.json();
                        if (j.success) {
                            Ilmuna.toast('success', 'Berhasil', j.message);
                            // In-place smooth removal without full re-render (README.md Section 4.4)
                            var tr = document.getElementById('row-prota-' + rId) || btnDel.closest('tr');
                            if (tr) {
                                tr.classList.add('row-smooth-out');
                                setTimeout(function() {
                                    tr.remove();
                                    var bProta = document.getElementById('badgeProtaCount');
                                    if (bProta) {
                                        var count = Math.max(0, parseInt(bProta.textContent || 0, 10) - 1);
                                        bProta.textContent = count + ' TP';
                                    }
                                }, 320);
                            }
                        } else {
                            Ilmuna.alert('error', 'Gagal', j.message);
                        }
                    } catch (err) {
                        Ilmuna.alert('error', 'Gagal', err.message);
                    }
                });
                return;
            }

            var btnEdit = e.target.closest('.btn-edit-prota');
            if (btnEdit) {
                e.preventDefault();
                var rId = parseInt(btnEdit.getAttribute('data-id'), 10);
                var item = state.prota_details.find(function(x) { return parseInt(x.id, 10) === rId; });
                if (!item) return;

                document.getElementById('protaRowId').value = item.id;
                document.getElementById('modalProtaTitle').textContent = 'Edit Baris PROTA';
                document.getElementById('modalProtaSemester').value = item.semester;
                populateModalProtaElemen(item.id_elemen, item.id_tp);
                document.getElementById('modalProtaNomorUrut').value = item.nomor_urut;
                document.getElementById('modalProtaAlokasi').value = item.alokasi_waktu;
                openModal('modalProtaRowBackdrop', 'modalProtaRowDialog');
                return;
            }
        };
    }

    // Recalculate PROSEM Matrix Totals In-Place (Tanpa Reload / Tanpa Render Ulang)
    function recalcProsemTotalsInPlace() {
        var tbody = document.getElementById('tbodyProsemMatrix');
        var tfoot = document.getElementById('tfootProsemMatrix');
        if (!tbody || !tfoot) return;

        var bulanList = (state.semester === 'Ganjil')
            ? ['Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember']
            : ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni'];

        var colSums = {};
        bulanList.forEach(function(b) { colSums[b] = {1:0, 2:0, 3:0, 4:0, 5:0}; });

        var totalAllJp = 0;

        tbody.querySelectorAll('tr').forEach(function(row) {
            var rowSum = 0;
            row.querySelectorAll('.pjm-cell').forEach(function(cell) {
                var v = parseInt(cell.value, 10) || 0;
                var bln = cell.getAttribute('data-bulan');
                var pek = cell.getAttribute('data-pekan');
                if (v > 0) {
                    rowSum += v;
                    if (colSums[bln] && colSums[bln][pek] !== undefined) {
                        colSums[bln][pek] += v;
                    }
                }
            });

            // Update row JP column (index 2)
            var jpCol = row.children[2];
            if (jpCol) {
                jpCol.textContent = rowSum + ' JP';
            }
            totalAllJp += rowSum;
        });

        // Update footer row
        var fHtml = '<tr>'
            + '<td colspan="2" style="text-align: right; padding-right: 16px;">TOTAL JAM PELAJARAN (JP):</td>'
            + '<td style="text-align: center; color: var(--lumina-teal); font-weight: 800;">' + totalAllJp + ' JP</td>';

        bulanList.forEach(function(bName) {
            for (var w = 1; w <= 5; w++) {
                var cSum = colSums[bName][w];
                fHtml += '<td style="text-align: center; font-size: 12px; font-weight: 700; color: ' + (cSum > 0 ? 'var(--lumina-navy)' : '#94A3B8') + ';">'
                    + (cSum > 0 ? cSum : '0')
                + '</td>';
            }
        });
        fHtml += '<td></td></tr>';
        tfoot.innerHTML = fHtml;

        // Update badge tab
        var bProsem = document.getElementById('badgeProsemJp');
        if (bProsem) bProsem.textContent = totalAllJp + ' JP';
    }

    // 5. Render PROSEM View (Dynamic 6 Month Matrix Grid)
    function renderProsemView(d) {
        var ps = d.prosem;
        var thead = document.getElementById('theadProsemMatrix');
        var tbody = document.getElementById('tbodyProsemMatrix');
        var tfoot = document.getElementById('tfootProsemMatrix');
        if (!thead || !tbody) return;

        // Daftar 6 bulan untuk semester bersangkutan
        var bulanList = (state.semester === 'Ganjil')
            ? ['Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember']
            : ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni'];

        // Susun header bertingkat (Bulan di baris 1, Pekan 1-5 di baris 2)
        var hRow1 = '<tr>'
            + '<th rowspan="2" style="width: 45px;">No</th>'
            + '<th rowspan="2" style="width: 260px; text-align: left;">Materi Pokok / Agenda Pembelajaran</th>'
            + '<th rowspan="2" style="width: 60px;">Alokasi JP</th>';

        var hRow2 = '<tr>';

        bulanList.forEach(function(bName) {
            hRow1 += '<th colspan="5" class="month-header-group">' + bName + '</th>';
            for (var w = 1; w <= 5; w++) {
                hRow2 += '<th style="width: 32px; padding: 6px 2px; font-size: 10.5px;">' + w + '</th>';
            }
        });



        hRow1 += '<th rowspan="2" style="width: 70px;">Aksi</th></tr>';
        hRow2 += '</tr>';

        thead.innerHTML = hRow1 + hRow2;

        if (!d.prosem_details || d.prosem_details.length === 0) {
            tbody.innerHTML = '<tr><td colspan="34" style="text-align: center; padding: 40px; color: #64748B;">'
                + '<iconify-icon icon="solar:calendar-bold" width="36" style="color: var(--lumina-teal); margin-bottom: 8px;"></iconify-icon>'
                + '<div style="font-weight: 700; font-size: 14px; margin-bottom: 6px; color: var(--lumina-navy);">Belum ada data Program Semester (PROSEM)</div>'
                + '<p style="font-size: 13px; max-width: 480px; margin: 0 auto 16px; color: #64748B;">'
                    + 'Anda dapat men-generate otomatis dari PROTA & Kaldik, atau menambahkan agenda tatap muka dan asesmen secara manual.'
                + '</p>'
                + '<div style="display: flex; gap: 8px; justify-content: center; flex-wrap: wrap;">'
                    + '<button type="button" class="pdp-btn pdp-btn-gold btn-call-gen-prosem">'
                        + '<iconify-icon icon="solar:magic-stick-3-bold" width="18"></iconify-icon>'
                        + '<span>Generate dari PROTA & Kaldik</span>'
                    + '</button>'
                    + '<button type="button" class="pdp-btn pdp-btn-outline btn-call-tambah-agenda">'
                        + '<iconify-icon icon="solar:add-circle-bold" width="18"></iconify-icon>'
                        + '<span>Tambah Agenda</span>'
                    + '</button>'
                + '</div>'
            + '</td></tr>';
            if (tfoot) tfoot.innerHTML = '';

            var bGenPsCall = tbody.querySelector('.btn-call-gen-prosem');
            if (bGenPsCall) bGenPsCall.addEventListener('click', function() { document.getElementById('btnGenerateProsem').click(); });
            var bAddAgCall = tbody.querySelector('.btn-call-tambah-agenda');
            if (bAddAgCall) bAddAgCall.addEventListener('click', function() { document.getElementById('btnAddProsemAgenda').click(); });
            return;
        }

        // Susun baris per agenda
        var bHtml = '';
        var colSums = {}; // [bName][w] => sum
        bulanList.forEach(function(b) {
            colSums[b] = {1:0, 2:0, 3:0, 4:0, 5:0};
        });



        d.prosem_details.forEach(function(row, idx) {
            var labelAgenda = row.nama_agenda || row.materi_pokok || (row.uraian_tp ? row.uraian_tp.substring(0, 60) + '...' : 'Agenda KBM');
            var isKbm = row.kategori === 'Materi KBM';

            bHtml += '<tr id="row-prosem-' + row.id + '">'
                + '<td style="text-align: center; font-weight: 700;">' + (idx + 1) + '</td>'
                + '<td>'
                    + '<div style="font-weight: 700; color: var(--lumina-navy);">'
                        + (row.kode_tp ? '<span style="color: var(--lumina-teal); margin-right: 4px;">[' + row.kode_tp + ']</span>' : '')
                        + labelAgenda
                    + '</div>'
                    + '<div style="font-size: 11px; color: #64748B; margin-top: 2px;">'
                        + '<span class="pdp-badge ' + (isKbm ? 'pdp-badge-approved' : 'pdp-badge-draft') + '" style="font-size: 10px; padding: 2px 6px;">' + row.kategori + '</span>'
                        + (row.judul_modul ? ' &bull; Modul: ' + row.judul_modul : '')
                    + '</div>'
                + '</td>'
                + '<td style="text-align: center; font-weight: 800; color: var(--lumina-teal);">' + row.alokasi_waktu + ' JP</td>';

            // Loop 6 bulan x 5 pekan
            bulanList.forEach(function(bName) {
                for (var w = 1; w <= 5; w++) {
                    var cellVal = (state.prosem_mingguan[row.id] && state.prosem_mingguan[row.id][bName]) 
                        ? (state.prosem_mingguan[row.id][bName][w] || 0) : 0;

                    if (cellVal > 0) {
                        colSums[bName][w] += cellVal;
                    }

                    bHtml += '<td style="text-align: center; padding: 4px 2px;">'
                        + '<input type="text" class="matrix-cell-input pjm-cell" '
                            + 'data-detail="' + row.id + '" data-bulan="' + bName + '" data-pekan="' + w + '" '
                            + 'value="' + (cellVal > 0 ? cellVal : '') + '" placeholder="-">'
                    + '</td>';
                }
            });

            bHtml += '<td style="text-align: center;">'
                + '<div style="display: flex; gap: 4px; justify-content: center;">'
                    + '<button type="button" class="btn-edit-prosem" data-id="' + row.id + '" style="background: none; border: none; color: #0284C7; cursor: pointer; padding: 4px;" title="Edit Agenda">'
                        + '<iconify-icon icon="solar:pen-bold" width="18"></iconify-icon>'
                    + '</button>'
                    + '<button type="button" class="btn-del-prosem" data-id="' + row.id + '" style="background: none; border: none; color: #DC2626; cursor: pointer; padding: 4px;" title="Hapus Agenda">'
                        + '<iconify-icon icon="solar:trash-bin-trash-bold" width="18"></iconify-icon>'
                    + '</button>'
                + '</div>'
            + '</td></tr>';
        });

        tbody.innerHTML = bHtml;

        // Susun Baris Total Rekapitulasi Footer
        var fHtml = '<tr>'
            + '<td colspan="2" style="text-align: right; padding-right: 16px;">TOTAL JAM PELAJARAN (JP):</td>'
            + '<td style="text-align: center; color: var(--lumina-teal); font-weight: 800;">' + (ps ? ps.total_alokasi_jp : 0) + ' JP</td>';

        bulanList.forEach(function(bName) {
            for (var w = 1; w <= 5; w++) {
                var cSum = colSums[bName][w];
                fHtml += '<td style="text-align: center; font-size: 12px; font-weight: 700; color: ' + (cSum > 0 ? 'var(--lumina-navy)' : '#94A3B8') + ';">'
                    + (cSum > 0 ? cSum : '0')
                + '</td>';
            }
        });

        fHtml += '<td></td></tr>';
        tfoot.innerHTML = fHtml;

        // Bind interactive cell inputs (In-Place Auto-Save tanpa reload / tanpa render ulang)
        document.querySelectorAll('.pjm-cell').forEach(function(inp) {
            inp.addEventListener('change', async function() {
                var dId = this.getAttribute('data-detail');
                var bln = this.getAttribute('data-bulan');
                var pek = this.getAttribute('data-pekan');
                var val = parseInt(this.value, 10) || 0;
                var currentInput = this;

                try {
                    var fd = new FormData();
                    fd.append('action', 'save_prosem_matrix_cell');
                    fd.append('id_detail', dId);
                    fd.append('bulan', bln);
                    fd.append('pekan_ke', pek);
                    fd.append('jam_tatap_muka', val);

                    var res = await fetch('', { method: 'POST', body: fd });
                    var j = await res.json();
                    if (!j.success) throw new Error(j.message);

                    // Flash cell input and update totals in-place without re-render
                    currentInput.classList.remove('row-flash');
                    void currentInput.offsetWidth;
                    currentInput.classList.add('row-flash');

                    recalcProsemTotalsInPlace();
                    Ilmuna.toast('success', 'Tersimpan', bln + ' Mg ' + pek + ': ' + val + ' JP');
                } catch (err) {
                    console.error('Error save matrix cell:', err);
                    Ilmuna.toast('error', 'Gagal', err.message);
                }
            });
        });

        // Event delegation on tbodyProsemMatrix for Edit & Delete PROSEM
        tbody.onclick = function(e) {
            var btnDel = e.target.closest('.btn-del-prosem');
            if (btnDel) {
                e.preventDefault();
                var rId = btnDel.getAttribute('data-id');
                Ilmuna.confirm('warning', 'Hapus Agenda PROSEM?', 'Agenda beserta alokasi mingguan akan dihapus.', 'Ya, Hapus').then(async function(ok) {
                    if (!ok) return;
                    try {
                        var fd = new FormData();
                        fd.append('action', 'delete_prosem_row');
                        fd.append('id_row', rId);
                        fd.append('id_prosem', state.prosem ? state.prosem.id : 0);
                        var res = await fetch('', { method: 'POST', body: fd });
                        var j = await res.json();
                        if (j.success) {
                            Ilmuna.toast('success', 'Berhasil', j.message);
                            // In-place smooth removal without full re-render (README.md Section 4.4)
                            var tr = document.getElementById('row-prosem-' + rId) || btnDel.closest('tr');
                            if (tr) {
                                tr.classList.add('row-smooth-out');
                                setTimeout(function() {
                                    tr.remove();
                                    recalcProsemTotalsInPlace();
                                }, 320);
                            }
                        } else {
                            Ilmuna.alert('error', 'Gagal', j.message);
                        }
                    } catch (err) {
                        Ilmuna.alert('error', 'Gagal', err.message);
                    }
                });
                return;
            }

            var btnEdit = e.target.closest('.btn-edit-prosem');
            if (btnEdit) {
                e.preventDefault();
                var rId = btnEdit.getAttribute('data-id');
                editProsemRow(rId);
                return;
            }
        };
    }

    // Edit PROSEM Row Handler
    function editProsemRow(rId) {
        var item = state.prosem_details.find(function(x) { return parseInt(x.id, 10) === parseInt(rId, 10); });
        if (!item) return;

        document.getElementById('prosemRowId').value = item.id;
        document.getElementById('modalProsemTitle').textContent = 'Edit Agenda PROSEM';
        document.getElementById('modalProsemKategori').value = item.kategori;

        // Populate TP
        var tpSel = document.getElementById('modalProsemTp');
        tpSel.innerHTML = '<option value="">-- Pilih TP --</option>';
        state.master_tp.forEach(function(t) {
            var opt = document.createElement('option');
            opt.value = t.id;
            opt.textContent = (t.kode_tp ? '[' + t.kode_tp + '] ' : '') + (t.materi_pokok || t.tp.substring(0, 50));
            tpSel.appendChild(opt);
        });



        // Populate Modul
        var maSel = document.getElementById('modalProsemModul');
        maSel.innerHTML = '<option value="">-- Tidak Ditautkan ke Modul Ajar --</option>';
        state.master_modul.forEach(function(m) {
            var opt = document.createElement('option');
            opt.value = m.id;
            opt.textContent = m.judul_modul;
            maSel.appendChild(opt);
        });



        if (item.kategori === 'Materi KBM') {
            document.getElementById('groupProsemTpSelect').style.display = 'block';
            document.getElementById('groupProsemNamaAgenda').style.display = 'none';
            if (item.id_tp) document.getElementById('modalProsemTp').value = item.id_tp;
        } else {
            document.getElementById('groupProsemTpSelect').style.display = 'none';
            document.getElementById('groupProsemNamaAgenda').style.display = 'block';
            document.getElementById('modalProsemNamaAgenda').value = item.nama_agenda || item.kategori;
        }

        if (item.id_modul_ajar) document.getElementById('modalProsemModul').value = item.id_modul_ajar;
        document.getElementById('modalProsemNomorUrut').value = item.nomor_urut;
        document.getElementById('modalProsemAlokasi').value = item.alokasi_waktu;

        openModal('modalProsemRowBackdrop', 'modalProsemRowDialog');
    }

    // 6. Render RPE Kaldik Table
    function renderRpeView(rpe) {
        var elHe = document.getElementById('statRpeTotalHe');
        if (elHe) elHe.textContent = rpe.total_he + ' Hari';
        var elPe = document.getElementById('statRpeTotalPe');
        if (elPe) elPe.textContent = rpe.total_pe + ' Pekan';
        var elPte = document.getElementById('statRpeTotalPte');
        if (elPte) elPte.textContent = rpe.total_pte + ' Pekan';
        var elCalc = document.getElementById('statRpeJtmCalculated');
        if (elCalc) elCalc.textContent = (rpe.total_pe * state.jp_per_pekan) + ' JP';

        var tbody = document.getElementById('tbodyRpe');
        if (!rpe.rows || rpe.rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="14" style="text-align: center; padding: 40px; color: #94A3B8;">Belum ada data RPE. Klik "Sync Kaldik" di atas untuk sinkronisasi.</td></tr>';
            return;
        }

        var html = '';
        rpe.rows.forEach(function(r, idx) {
            html += '<tr>'
                + '<td style="text-align: center; font-weight: 700;">' + (idx + 1) + '</td>'
                + '<td><strong>' + r.bulan + '</strong></td>'
                + '<td style="text-align: center;">' + r.senin + '</td>'
                + '<td style="text-align: center;">' + r.selasa + '</td>'
                + '<td style="text-align: center;">' + r.rabu + '</td>'
                + '<td style="text-align: center;">' + r.kamis + '</td>'
                + '<td style="text-align: center;">' + r.jumat + '</td>'
                + '<td style="text-align: center;">' + r.sabtu + '</td>'
                + '<td style="text-align: center; color: #DC2626;">' + r.minggu + '</td>'
                + '<td style="text-align: center; font-weight: 800; color: var(--lumina-teal);">' + r.total_hari_efektif + '</td>'
                + '<td style="text-align: center;">' + r.jumlah_pekan_total + '</td>'
                + '<td style="text-align: center; font-weight: 700; color: #059669;">' + r.jumlah_pekan_efektif + '</td>'
                + '<td style="text-align: center; font-weight: 700; color: #DC2626;">' + r.jumlah_pekan_tidak_efektif + '</td>'
                + '<td style="font-size: 12px; color: #64748B;">' + (r.keterangan_tidak_efektif || '-') + '</td>'
            + '</tr>';
        });



        tbody.innerHTML = html;
    }

    // Universal Modal Helper (Robust & Compatible with ilm-modal-backdrop)
    function openModal(backdropId, dialogId) {
        var b = typeof backdropId === 'string' ? document.getElementById(backdropId) : backdropId;
        if (!b) return;
        var d = dialogId ? document.getElementById(dialogId) : b.querySelector('.ilm-modal-dialog, .ilm-modal');
        
        b.classList.add('is-open', 'show', 'active');
        b.style.setProperty('display', 'flex', 'important');
        b.style.setProperty('opacity', '1', 'important');
        b.style.setProperty('visibility', 'visible', 'important');
        b.style.setProperty('pointer-events', 'auto', 'important');
        
        if (d) {
            d.classList.add('is-open', 'show');
            d.style.setProperty('display', 'flex', 'important');
            d.style.setProperty('opacity', '1', 'important');
            d.style.setProperty('visibility', 'visible', 'important');
            d.style.setProperty('transform', 'translateY(0) scale(1)', 'important');
        }
        document.body.style.overflow = 'hidden';
    }

    function closeModal(backdropId, dialogId) {
        var b = typeof backdropId === 'string' ? document.getElementById(backdropId) : backdropId;
        if (!b) return;
        var d = dialogId ? document.getElementById(dialogId) : b.querySelector('.ilm-modal-dialog, .ilm-modal');
        
        b.classList.remove('is-open', 'show', 'active');
        b.style.setProperty('opacity', '0', 'important');
        b.style.setProperty('pointer-events', 'none', 'important');
        
        if (d) {
            d.classList.remove('is-open', 'show');
            d.style.setProperty('transform', 'translateY(-16px) scale(0.96)', 'important');
        }
        setTimeout(function() {
            if (!b.classList.contains('is-open')) {
                b.style.setProperty('display', 'none', 'important');
                b.style.setProperty('visibility', 'hidden', 'important');
            }
        }, 200);
        
        if (!document.querySelector('.ilm-modal-backdrop.is-open, .ilm-modal-backdrop.show')) {
            document.body.style.overflow = '';
        }
    }

    // Populate Modal PROTA Dropdowns
    function populateModalProtaElemen(selectedElemenId, selectedTpId) {
        var elSelect = document.getElementById('modalProtaElemen');
        elSelect.innerHTML = '<option value="">-- Pilih Elemen Kurikulum --</option>';
        state.master_elemen.forEach(function(e) {
            var opt = document.createElement('option');
            opt.value = e.id;
            opt.textContent = e.nama_elemen + ' (' + (e.fase || 'Merdeka') + ')';
            elSelect.appendChild(opt);
        });

        if (selectedElemenId) {
            elSelect.value = selectedElemenId;
        } else if (state.master_elemen && state.master_elemen.length > 0) {
            elSelect.value = state.master_elemen[0].id;
            selectedElemenId = state.master_elemen[0].id;
        }

        populateModalProtaTp(selectedElemenId, selectedTpId);
    }

    function populateModalProtaTp(elemenId, selectedTpId) {
        var tpSelect = document.getElementById('modalProtaTp');
        tpSelect.innerHTML = '<option value="">-- Pilih Tujuan Pembelajaran --</option>';
        var filteredTps = (state.master_tp || []).filter(function(t) {
            return !elemenId || parseInt(t.id_elemen, 10) === parseInt(elemenId, 10);
        });
        filteredTps.forEach(function(t) {
            var opt = document.createElement('option');
            opt.value = t.id;
            opt.textContent = (t.kode_tp ? '[' + t.kode_tp + '] ' : '') + (t.materi_pokok || t.tp.substring(0, 50));
            tpSelect.appendChild(opt);
        });

        if (selectedTpId) {
            tpSelect.value = selectedTpId;
        } else if (filteredTps.length > 0) {
            tpSelect.value = filteredTps[0].id;
        }
    }

    document.getElementById('modalProtaElemen').addEventListener('change', function() {
        populateModalProtaTp(this.value, null);
    });

    // =========================================================================
    // MODAL TAMBAH TUJUAN PEMBELAJARAN (TP) BARU UNTUK MAPEL INI (BERBASIS ELEMEN & CP DATABASE)
    // =========================================================================
    function updateCpOptions() {
        var elSelect = document.getElementById('inputTpElemen');
        var cpSelect = document.getElementById('inputTpCp');
        var boxPreview = document.getElementById('boxPreviewCp');
        var textPreview = document.getElementById('textPreviewCp');

        if (!elSelect || !cpSelect) return;
        cpSelect.innerHTML = '';
        var elVal = elSelect.value;

        var idElemen = parseInt(elVal, 10) || 0;
        var filteredCps = (state.master_cp || []).filter(function(c) {
            return parseInt(c.id_elemen, 10) === idElemen;
        });

        if (filteredCps.length > 0) {
            filteredCps.forEach(function(c, idx) {
                var opt = document.createElement('option');
                opt.value = c.id;
                var shortCp = c.cp ? (c.cp.length > 80 ? c.cp.substring(0, 80) + '...' : c.cp) : 'Capaian Pembelajaran';
                opt.textContent = 'CP ' + (idx + 1) + ': ' + shortCp;
                opt.setAttribute('data-full-cp', c.cp || '');
                cpSelect.appendChild(opt);
            });
            cpSelect.value = filteredCps[0].id;
        } else {
            var optEmpty = document.createElement('option');
            optEmpty.value = '0';
            optEmpty.textContent = '(Belum ada master CP untuk elemen ini - CP otomatis)';
            cpSelect.appendChild(optEmpty);
        }

        updateCpPreview();
    }

    function updateCpPreview() {
        var cpSelect = document.getElementById('inputTpCp');
        var boxPreview = document.getElementById('boxPreviewCp');
        var textPreview = document.getElementById('textPreviewCp');
        if (!cpSelect || !boxPreview || !textPreview) return;

        var selectedOpt = cpSelect.options[cpSelect.selectedIndex];
        if (selectedOpt && selectedOpt.getAttribute('data-full-cp')) {
            textPreview.textContent = selectedOpt.getAttribute('data-full-cp');
            boxPreview.style.display = 'block';
        } else {
            boxPreview.style.display = 'none';
        }
    }

    function openModalTambahTp() {
        if (!state.id_kmp) {
            Ilmuna.alert('warning', 'Peringatan', 'Pilih rombel dan mata pelajaran terlebih dahulu.');
            return;
        }

        var elSelect = document.getElementById('inputTpElemen');
        elSelect.innerHTML = '';

        if (state.master_elemen && state.master_elemen.length > 0) {
            state.master_elemen.forEach(function(e) {
                var opt = document.createElement('option');
                opt.value = e.id;
                opt.textContent = e.nama_elemen + ' (' + (e.fase || 'Merdeka') + ')';
                elSelect.appendChild(opt);
            });
            elSelect.value = state.master_elemen[0].id;
        } else {
            var optEmpty = document.createElement('option');
            optEmpty.value = '';
            optEmpty.textContent = '-- Belum ada elemen kurikulum --';
            elSelect.appendChild(optEmpty);
        }

        // Tampilkan kalkulasi alokasi waktu & pekan efektif murni dari Database (Kaldik & RPE)
        if (state.perhitungan_alokasi) {
            var p = state.perhitungan_alokasi;
            var elPe = document.getElementById('calcBoxPekanEfektif');
            if (elPe) elPe.textContent = p.total_pekan_efektif + ' Pekan';
            var elJp = document.getElementById('calcBoxJpPerPekan');
            if (elJp) elJp.textContent = p.jp_per_pekan + ' JP';
            var elTk = document.getElementById('calcBoxJpTerpakai');
            if (elTk) elTk.textContent = p.jp_terpakai_semester + ' JP';
            var elSisa = document.getElementById('calcBoxSisaJp');
            if (elSisa) elSisa.textContent = p.sisa_jp_semester + ' JP';
            var elHlp = document.getElementById('labelSisaJpHelper');
            if (elHlp) elHlp.textContent = '(Tersisa ' + p.sisa_jp_semester + ' JP)';
        } else {
            var elPe = document.getElementById('calcBoxPekanEfektif');
            if (elPe) elPe.textContent = '-';
            var elJp = document.getElementById('calcBoxJpPerPekan');
            if (elJp) elJp.textContent = '-';
            var elTk = document.getElementById('calcBoxJpTerpakai');
            if (elTk) elTk.textContent = '-';
            var elSisa = document.getElementById('calcBoxSisaJp');
            if (elSisa) elSisa.textContent = '-';
            var elHlp = document.getElementById('labelSisaJpHelper');
            if (elHlp) elHlp.textContent = '';
        }

        // Reset form inputs
        var sem = state.semester || 'Ganjil';
        document.getElementById('inputTpSemester').value = sem;

        // Auto-generate suggested kode TP
        var nextNo = (state.master_tp ? state.master_tp.length : 0) + 1;
        document.getElementById('inputTpKode').value = (sem === 'Ganjil' ? '1.' : '2.') + nextNo;
        document.getElementById('inputTpMateri').value = '';
        document.getElementById('inputTpUraian').value = '';

        // Default alokasi JP per TP disesuaikan dengan kapasitas JTM mingguan
        var defJp = 4;
        if (state.perhitungan_alokasi && state.perhitungan_alokasi.jp_per_pekan) {
            var sisa = state.perhitungan_alokasi.sisa_jp_semester;
            defJp = sisa > 0 ? Math.min(sisa, state.perhitungan_alokasi.jp_per_pekan * 2) : state.perhitungan_alokasi.jp_per_pekan * 2;
        }
        document.getElementById('inputTpAlokasi').value = Math.max(2, defJp);
        document.getElementById('checkTpMasukProta').checked = true;
        document.getElementById('checkTpMasukProsem').checked = true;

        // Cascade CP dari elemen yang terpilih
        updateCpOptions();

        openModal('modalTambahTpBackdrop', 'modalTambahTpDialog');
    }

    var btnOpenTambahTp = document.getElementById('btnOpenModalTambahTp');
    if (btnOpenTambahTp) btnOpenTambahTp.addEventListener('click', openModalTambahTp);

    var btnCloseTambahTp = document.getElementById('btnCloseModalTambahTp');
    if (btnCloseTambahTp) btnCloseTambahTp.addEventListener('click', function() { closeModal('modalTambahTpBackdrop', 'modalTambahTpDialog'); });

    var btnCancelTambahTp = document.getElementById('btnCancelTambahTpModal');
    if (btnCancelTambahTp) btnCancelTambahTp.addEventListener('click', function() { closeModal('modalTambahTpBackdrop', 'modalTambahTpDialog'); });

    var selInputTpElemen = document.getElementById('inputTpElemen');
    if (selInputTpElemen) {
        selInputTpElemen.addEventListener('change', updateCpOptions);
    }

    var selInputTpCp = document.getElementById('inputTpCp');
    if (selInputTpCp) {
        selInputTpCp.addEventListener('change', updateCpPreview);
    }

    var btnSubmitNewTp = document.getElementById('btnSubmitNewTp');
    if (btnSubmitNewTp) {
        btnSubmitNewTp.addEventListener('click', async function() {
            if (!state.id_kmp) {
                Ilmuna.alert('warning', 'Peringatan', 'Pilih rombel dan mata pelajaran.');
                return;
            }

            var elVal = document.getElementById('inputTpElemen').value;
            var cpVal = document.getElementById('inputTpCp').value;
            var kodeTp = document.getElementById('inputTpKode').value.trim();
            var materi = document.getElementById('inputTpMateri').value.trim();
            var uraian = document.getElementById('inputTpUraian').value.trim();
            var semester = document.getElementById('inputTpSemester').value;
            var alokasi = document.getElementById('inputTpAlokasi').value;
            var masukProta = document.getElementById('checkTpMasukProta').checked ? 1 : 0;
            var masukProsem = document.getElementById('checkTpMasukProsem').checked ? 1 : 0;

            if (!elVal || elVal === '0') {
                Ilmuna.alert('warning', 'Peringatan', 'Silakan pilih Elemen Kurikulum yang tersedia.');
                return;
            }
            if (!uraian && !materi) {
                Ilmuna.alert('warning', 'Peringatan', 'Materi Pokok atau Uraian TP wajib diisi.');
                return;
            }
            if (!uraian) uraian = materi;
            if (!materi) materi = uraian;

            var origHtml = btnSubmitNewTp.innerHTML;
            btnSubmitNewTp.disabled = true;
            btnSubmitNewTp.innerHTML = '<iconify-icon icon="solar:restart-bold" class="ilmuna-spin"></iconify-icon> Menyimpan...';

            try {
                var fd = new FormData();
                fd.append('action', 'create_tp_mapel');
                fd.append('id_kmp', state.id_kmp);
                fd.append('tahun_pelajaran', state.tahun);
                fd.append('semester', semester);
                fd.append('kode_tp', kodeTp);
                fd.append('materi_pokok', materi);
                fd.append('uraian_tp', uraian);
                fd.append('alokasi_jam', alokasi);
                fd.append('id_elemen', parseInt(elVal, 10) || 0);
                fd.append('id_cp', parseInt(cpVal, 10) || 0);
                fd.append('nama_elemen_baru', '');
                fd.append('masuk_prota', masukProta);
                fd.append('masuk_prosem', masukProsem);

                var res = await fetch('', { method: 'POST', body: fd });
                var j = await res.json();
                if (!j.success) throw new Error(j.message);

                closeModal('modalTambahTpBackdrop', 'modalTambahTpDialog');
                Ilmuna.toast('success', 'Berhasil', j.message);

                if (j.data && j.data.tp) {
                    var newTp = j.data.tp;
                    if (!state.master_tp) state.master_tp = [];
                    state.master_tp.push(newTp);

                    if (j.data.masuk_prota) {
                        var tbody = document.getElementById('tbodyProta');
                        if (tbody) {
                            var emptyRow = tbody.querySelector('tr td[colspan]');
                            if (emptyRow) emptyRow.closest('tr').remove();

                            var currentCount = tbody.querySelectorAll('tr').length + 1;
                            var newTr = document.createElement('tr');
                            newTr.id = 'row-prota-' + (newTp.id || 'new');
                            newTr.className = 'row-smooth-in';
                            newTr.innerHTML = '<td style="text-align: center; font-weight: 700;">' + currentCount + '</td>'
                                + '<td style="text-align: center;"><span class="pdp-badge ' + (j.data.semester === 'Ganjil' ? 'pdp-badge-draft' : 'pdp-badge-approved') + '">' + j.data.semester + '</span></td>'
                                + '<td><strong>' + (newTp.nama_elemen || '-') + '</strong></td>'
                                + '<td style="text-align: center; font-weight: 700; color: var(--lumina-teal);">' + (newTp.kode_tp || '-') + '</td>'
                                + '<td><div style="font-weight: 600; margin-bottom: 3px;">' + (newTp.materi_pokok || '') + '</div><div style="font-size: 12px; color: #64748B;">' + (newTp.tp || '-') + '</div></td>'
                                + '<td style="text-align: center; font-weight: 800; color: var(--lumina-navy);">' + (j.data.alokasi || 4) + ' JP</td>'
                                + '<td style="text-align: center;"><div style="display: flex; gap: 4px; justify-content: center;"><button type="button" class="btn-del-prota" data-id="' + newTp.id + '" style="background: none; border: none; color: #DC2626; cursor: pointer; padding: 4px;" title="Hapus"><iconify-icon icon="solar:trash-bin-trash-bold" width="18"></iconify-icon></button></div></td>';
                            tbody.appendChild(newTr);

                            var bProta = document.getElementById('badgeProtaCount');
                            if (bProta) bProta.textContent = currentCount + ' TP';
                        }
                    }

                    if (j.data.masuk_prosem) {
                        loadState(); // Refresh PROSEM matrix to reflect new TP and Kaldik alignment
                    }
                } else {
                    loadState();
                }
            } catch (err) {
                Ilmuna.alert('error', 'Gagal', err.message);
            } finally {
                btnSubmitNewTp.disabled = false;
                btnSubmitNewTp.innerHTML = origHtml;
            }
        });


    }

    // Event Handlers Filter
    selKelas.addEventListener('change', function() {
        state.id_kelas = parseInt(this.value, 10) || 0;
        if (window._classList) {
            var found = window._classList.find(function(k) { return parseInt(k.id, 10) === state.id_kelas; });
            if (found && found.tahun_pelajaran) {
                selTahun.value = found.tahun_pelajaran;
                state.tahun = found.tahun_pelajaran;
            }
        }
        loadMapel();
    });


    selMapel.addEventListener('change', function() {
        state.id_kmp = parseInt(this.value, 10) || 0;
        loadState();
    });


    selTahun.addEventListener('change', loadState);
    selSem.addEventListener('change', loadState);
    selHari.addEventListener('change', function() {
        state.hari_mengajar = this.value;
        document.getElementById('formAlokasiHari').value = this.value;
    });



    // Tab Navigation
    document.querySelectorAll('.pdp-tab-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.pdp-tab-btn').forEach(function(b) { b.classList.remove('is-active'); });
            document.querySelectorAll('.pdp-tab-pane').forEach(function(p) { p.style.display = 'none'; });

            this.classList.add('is-active');
            var targetId = this.getAttribute('data-target');
            var pane = document.getElementById(targetId);
            if (pane) pane.style.display = 'block';

            var tabMap = {
                'tabProta': 'prota',
                'tabProsem': 'prosem',
                'tabKaldik': 'kaldik',
                'tabDistribusi': 'distribusi'
            };
            var tabKey = tabMap[targetId] || 'prota';
            if (window.history && window.history.replaceState) {
                var url = new URL(window.location);
                url.searchParams.set('tab', tabKey);
                window.history.replaceState({}, '', url);
            }
        });


    });



    // Sync Kaldik Button
    document.getElementById('btnSyncKaldik').addEventListener('click', async function() {
        if (!state.id_kmp) {
            Ilmuna.alert('warning', 'Peringatan', 'Pilih rombel dan mata pelajaran terlebih dahulu.');
            return;
        }

        var btn = this;
        btn.disabled = true;
        btn.innerHTML = '<iconify-icon icon="solar:restart-bold" class="ilmuna-spin"></iconify-icon> Menyinkronkan...';

        try {
            var fd = new FormData();
            fd.append('action', 'sync_kaldik_ke_kmp');
            fd.append('id_kmp', state.id_kmp);
            fd.append('tahun_pelajaran', state.tahun);
            fd.append('semester', state.semester);

            var res = await fetch('', { method: 'POST', body: fd });
            var j = await res.json();
            if (!j.success) throw new Error(j.message);

            Ilmuna.toast('success', 'Berhasil', j.message);
            loadState();
        } catch (err) {
            Ilmuna.alert('error', 'Gagal', err.message);
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<iconify-icon icon="solar:refresh-bold" width="18"></iconify-icon> <span>Sync Kaldik</span>';
        }
    });



    // Simpan Alokasi Hari Mengajar (In-Place Update Tanpa Render Ulang)
    document.getElementById('btnSaveAlokasi').addEventListener('click', async function() {
        if (!state.id_kmp) {
            Ilmuna.alert('warning', 'Peringatan', 'Pilih rombel dan mata pelajaran terlebih dahulu.');
            return;
        }

        var hari = document.getElementById('formAlokasiHari').value;
        var jp = document.getElementById('formAlokasiJp').value;

        try {
            var fd = new FormData();
            fd.append('action', 'save_alokasi_hari_mengajar');
            fd.append('id_kmp', state.id_kmp);
            fd.append('semester', state.semester);
            fd.append('tahun_pelajaran', state.tahun);
            fd.append('hari', hari);
            fd.append('jp_per_minggu', jp);

            var res = await fetch('', { method: 'POST', body: fd });
            var j = await res.json();
            if (!j.success) throw new Error(j.message);

            Ilmuna.toast('success', 'Berhasil', j.message);

            // Mutasi in-place statistik alokasi tanpa re-render (README.md Section 4.4)
            state.hari_mengajar = hari;
            state.jp_per_pekan = parseInt(jp, 10) || 2;
            selHari.value = hari;

            var elFbh = document.getElementById('formAlokasiBanyakHari');
            var elFtj = document.getElementById('formAlokasiTotalJtm');
            var bJtm = document.getElementById('badgeJtmCount');
            var statCalc = document.getElementById('statRpeJtmCalculated');

            if (state.rpe_rows && state.rpe_rows.length > 0) {
                var dayKey = hari.toLowerCase();
                var totHari = 0;
                var totPe = 0;
                state.rpe_rows.forEach(function(r) { 
                    totHari += parseInt(r[dayKey] || 0, 10);
                    totPe += parseInt(r.jumlah_pekan_efektif || 0, 10);
                });
                if (elFbh && totHari > 0) elFbh.value = totHari + ' Pertemuan';
                if (elFtj && totHari > 0) elFtj.value = (totHari * state.jp_per_pekan) + ' JP';
                if (bJtm && totHari > 0) bJtm.textContent = (totHari * state.jp_per_pekan) + ' JTM';
                if (statCalc) statCalc.textContent = (totPe * state.jp_per_pekan) + ' JP';
            }
        } catch (err) {
            Ilmuna.alert('error', 'Gagal', err.message);
        }
    });



    // Generate Otomatis PROTA
    document.getElementById('btnGenerateProta').addEventListener('click', function() {
        if (!state.id_kmp) {
            Ilmuna.alert('warning', 'Peringatan', 'Pilih rombel dan mata pelajaran terlebih dahulu.');
            return;
        }

        Ilmuna.confirm('info', 'Generate Otomatis PROTA?', 'Sistem akan memetakan seluruh Capaian & Tujuan Pembelajaran (TP) dari database ke semester ganjil dan genap.', 'Ya, Generate').then(async function(ok) {
            if (!ok) return;

            var fd = new FormData();
            fd.append('action', 'generate_prota_otomatis');
            fd.append('id_kmp', state.id_kmp);
            fd.append('tahun_pelajaran', state.tahun);
            fd.append('fase', state.prota ? state.prota.fase : 'Fase F');

            var res = await fetch('', { method: 'POST', body: fd });
            var j = await res.json();
            if (j.success) {
                Ilmuna.toast('success', 'Berhasil', j.message);
                loadState();
            } else {
                Ilmuna.alert('error', 'Gagal', j.message);
            }
        });
    });

    // Tambah Baris PROTA Modal
    document.getElementById('btnAddProtaRow').addEventListener('click', function() {
        if (!state.id_kmp) {
            Ilmuna.alert('warning', 'Peringatan', 'Pilih rombel dan mata pelajaran terlebih dahulu.');
            return;
        }
        document.getElementById('protaRowId').value = 0;
        document.getElementById('modalProtaTitle').textContent = 'Tambah Baris PROTA';
        populateModalProtaElemen(null, null);
        document.getElementById('modalProtaNomorUrut').value = state.prota_details.length + 1;
        document.getElementById('modalProtaAlokasi').value = 4;
        openModal('modalProtaRowBackdrop', 'modalProtaRowDialog');
    });



    // Simpan Baris PROTA
    document.getElementById('btnSaveProtaRow').addEventListener('click', async function() {
        var elId = document.getElementById('modalProtaElemen').value;
        var tpId = document.getElementById('modalProtaTp').value;
        if (!elId || !tpId) {
            Ilmuna.alert('warning', 'Peringatan', 'Elemen dan TP wajib dipilih.');
            return;
        }

        var fd = new FormData();
        fd.append('action', 'save_prota_row');
        fd.append('id_kmp', state.id_kmp);
        fd.append('tahun_pelajaran', state.tahun);
        fd.append('id_prota', state.prota ? state.prota.id : 0);
        fd.append('id_row', document.getElementById('protaRowId').value);
        fd.append('id_elemen', elId);
        fd.append('id_tp', tpId);
        fd.append('semester', document.getElementById('modalProtaSemester').value);
        fd.append('nomor_urut', document.getElementById('modalProtaNomorUrut').value);
        fd.append('alokasi_waktu', document.getElementById('modalProtaAlokasi').value);

        var res = await fetch('', { method: 'POST', body: fd });
        var j = await res.json();
        if (j.success) {
            closeModal('modalProtaRowBackdrop', 'modalProtaRowDialog');
            Ilmuna.toast('success', 'Berhasil', j.message);

            // In-place DOM update tanpa render ulang tabel (README.md Section 4.4)
            if (j.data && j.data.row) {
                var savedRow = j.data.row;
                var rId = parseInt(document.getElementById('protaRowId').value, 10);
                var tbody = document.getElementById('tbodyProta');

                if (j.data.total_tahunan) {
                    var elBadgeJtm = document.getElementById('badgeProtaTotalJtm');
                    if (elBadgeJtm) elBadgeJtm.textContent = 'Total: ' + j.data.total_tahunan + ' JP';
                }

                if (rId > 0) {
                    // UPDATE: Differential in-place patch
                    var tr = document.getElementById('row-prota-' + rId);
                    if (tr) {
                        tr.children[1].innerHTML = '<span class="pdp-badge ' + (savedRow.semester === 'Ganjil' ? 'pdp-badge-draft' : 'pdp-badge-approved') + '">' + savedRow.semester + '</span>';
                        tr.children[2].innerHTML = '<strong>' + (savedRow.nama_elemen || '-') + '</strong>';
                        tr.children[3].textContent = savedRow.kode_tp || '-';
                        tr.children[4].innerHTML = '<div style="font-weight: 600; margin-bottom: 3px;">' + (savedRow.materi_pokok || '') + '</div><div style="font-size: 12px; color: #64748B;">' + (savedRow.uraian_tp || '-') + '</div>';
                        tr.children[5].textContent = savedRow.alokasi_waktu + ' JP';

                        tr.classList.remove('row-flash');
                        void tr.offsetWidth;
                        tr.classList.add('row-flash');
                    }
                } else if (tbody) {
                    // CREATE: Insert row with .row-smooth-in
                    var emptyRow = tbody.querySelector('tr td[colspan]');
                    if (emptyRow) emptyRow.closest('tr').remove();

                    var currentCount = tbody.querySelectorAll('tr').length + 1;
                    var newTr = document.createElement('tr');
                    newTr.id = 'row-prota-' + savedRow.id;
                    newTr.className = 'row-smooth-in';
                    newTr.innerHTML = '<td style="text-align: center; font-weight: 700;">' + currentCount + '</td>'
                        + '<td style="text-align: center;"><span class="pdp-badge ' + (savedRow.semester === 'Ganjil' ? 'pdp-badge-draft' : 'pdp-badge-approved') + '">' + savedRow.semester + '</span></td>'
                        + '<td><strong>' + (savedRow.nama_elemen || '-') + '</strong></td>'
                        + '<td style="text-align: center; font-weight: 700; color: var(--lumina-teal);">' + (savedRow.kode_tp || '-') + '</td>'
                        + '<td><div style="font-weight: 600; margin-bottom: 3px;">' + (savedRow.materi_pokok || '') + '</div><div style="font-size: 12px; color: #64748B;">' + (savedRow.uraian_tp || '-') + '</div></td>'
                        + '<td style="text-align: center; font-weight: 800; color: var(--lumina-navy);">' + savedRow.alokasi_waktu + ' JP</td>'
                        + '<td style="text-align: center;"><div style="display: flex; gap: 4px; justify-content: center;"><button type="button" class="btn-edit-prota" data-id="' + savedRow.id + '" style="background: none; border: none; color: #0284C7; cursor: pointer; padding: 4px;" title="Edit"><iconify-icon icon="solar:pen-bold" width="18"></iconify-icon></button><button type="button" class="btn-del-prota" data-id="' + savedRow.id + '" style="background: none; border: none; color: #DC2626; cursor: pointer; padding: 4px;" title="Hapus"><iconify-icon icon="solar:trash-bin-trash-bold" width="18"></iconify-icon></button></div></td>';
                    tbody.appendChild(newTr);

                    var bProta = document.getElementById('badgeProtaCount');
                    if (bProta) bProta.textContent = currentCount + ' TP';
                }
            } else {
                loadState();
            }
        } else {
            Ilmuna.alert('error', 'Gagal', j.message);
        }
    });



    document.getElementById('btnCloseModalProta').addEventListener('click', function() { closeModal('modalProtaRowBackdrop', 'modalProtaRowDialog'); });
    document.getElementById('btnCancelProtaModal').addEventListener('click', function() { closeModal('modalProtaRowBackdrop', 'modalProtaRowDialog'); });

    // Generate Otomatis PROSEM
    document.getElementById('btnGenerateProsem').addEventListener('click', function() {
        if (!state.id_kmp) {
            Ilmuna.alert('warning', 'Peringatan', 'Pilih rombel dan mata pelajaran terlebih dahulu.');
            return;
        }

        Ilmuna.confirm('info', 'Generate Otomatis PROSEM?', 'Sistem akan mendistribusikan materi KBM dari PROTA dan agenda ujian/proyek ke pekan-pekan efektif Kaldik.', 'Ya, Generate').then(async function(ok) {
            if (!ok) return;

            var fd = new FormData();
            fd.append('action', 'generate_prosem_otomatis');
            fd.append('id_kmp', state.id_kmp);
            fd.append('tahun_pelajaran', state.tahun);
            fd.append('semester', state.semester);

            var res = await fetch('', { method: 'POST', body: fd });
            var j = await res.json();
            if (j.success) {
                Ilmuna.toast('success', 'Berhasil', j.message);
                loadState();
            } else {
                Ilmuna.alert('error', 'Gagal', j.message);
            }
        });
    });

    // Sesuaikan / Geser Jadwal Mingguan PROSEM Secara Berantai (Cascade Shift)
    var btnRebal = document.getElementById('btnRebalanceProsem');
    if (btnRebal) {
        btnRebal.addEventListener('click', function() {
            if (!state.id_kmp) {
                Ilmuna.alert('warning', 'Peringatan', 'Pilih rombel dan mata pelajaran terlebih dahulu.');
                return;
            }
            if (!state.prosem) {
                Ilmuna.alert('warning', 'Peringatan', 'Belum ada data PROSEM untuk semester ini. Silakan generate terlebih dahulu.');
                return;
            }

            Ilmuna.confirm('question', 'Sesuaikan & Geser Jadwal PROSEM?', 'Sistem akan menyusun ulang distribusi jam mengajar mingguan secara berurutan pada pekan efektif Kaldik (mengabaikan pekan non-efektif/libur) dan otomatis menyinkronkan alokasi jam ke PROTA.', 'Ya, Susun Ulang').then(async function(ok) {
                if (!ok) return;

                var origHtml = btnRebal.innerHTML;
                btnRebal.disabled = true;
                btnRebal.innerHTML = '<iconify-icon icon="solar:restart-bold" class="ilmuna-spin"></iconify-icon> Menyesuaikan...';

                try {
                    var fd = new FormData();
                    fd.append('action', 'cascade_rebalance_prosem');
                    fd.append('id_prosem', state.prosem.id);
                    fd.append('id_kmp', state.id_kmp);
                    fd.append('tahun_pelajaran', state.tahun);
                    fd.append('semester', state.semester);

                    var res = await fetch('', { method: 'POST', body: fd });
                    var j = await res.json();
                    if (j.success) {
                        Ilmuna.toast('success', 'Berhasil', j.message);
                        loadState();
                    } else {
                        Ilmuna.alert('error', 'Gagal', j.message);
                    }
                } catch (err) {
                    Ilmuna.alert('error', 'Gagal', err.message);
                } finally {
                    btnRebal.disabled = false;
                    btnRebal.innerHTML = origHtml;
                }
            });
        });
    }



    // Tambah Agenda PROSEM Modal
    document.getElementById('btnAddProsemAgenda').addEventListener('click', function() {
        if (!state.id_kmp) {
            Ilmuna.alert('warning', 'Peringatan', 'Pilih rombel dan mata pelajaran terlebih dahulu.');
            return;
        }
        document.getElementById('prosemRowId').value = 0;
        document.getElementById('modalProsemKategori').value = 'Materi KBM';
        document.getElementById('groupProsemTpSelect').style.display = 'block';
        document.getElementById('groupProsemNamaAgenda').style.display = 'none';

        // Populate TP
        var tpSel = document.getElementById('modalProsemTp');
        tpSel.innerHTML = '<option value="">-- Pilih TP --</option>';
        state.master_tp.forEach(function(t) {
            var opt = document.createElement('option');
            opt.value = t.id;
            opt.textContent = (t.kode_tp ? '[' + t.kode_tp + '] ' : '') + (t.materi_pokok || t.tp.substring(0, 50));
            tpSel.appendChild(opt);
        });



        // Populate Modul
        var maSel = document.getElementById('modalProsemModul');
        maSel.innerHTML = '<option value="">-- Tidak Ditautkan ke Modul Ajar --</option>';
        state.master_modul.forEach(function(m) {
            var opt = document.createElement('option');
            opt.value = m.id;
            opt.textContent = m.judul_modul;
            maSel.appendChild(opt);
        });



        document.getElementById('modalProsemNomorUrut').value = state.prosem_details.length + 1;
        document.getElementById('modalProsemAlokasi').value = 2;
        openModal('modalProsemRowBackdrop', 'modalProsemRowDialog');
    });



    document.getElementById('modalProsemKategori').addEventListener('change', function() {
        if (this.value === 'Materi KBM') {
            document.getElementById('groupProsemTpSelect').style.display = 'block';
            document.getElementById('groupProsemNamaAgenda').style.display = 'none';
        } else {
            document.getElementById('groupProsemTpSelect').style.display = 'none';
            document.getElementById('groupProsemNamaAgenda').style.display = 'block';
            document.getElementById('modalProsemNamaAgenda').value = this.value;
        }
    });



    // Simpan Agenda PROSEM
    document.getElementById('btnSaveProsemRow').addEventListener('click', async function() {
        var kat = document.getElementById('modalProsemKategori').value;
        var tpId = document.getElementById('modalProsemTp').value;
        var namaAgenda = document.getElementById('modalProsemNamaAgenda').value.trim();

        if (kat === 'Materi KBM' && !tpId) {
            Ilmuna.alert('warning', 'Peringatan', 'Tujuan Pembelajaran wajib dipilih.');
            return;
        }

        if (kat !== 'Materi KBM' && !namaAgenda) {
            namaAgenda = kat;
        }

        var fd = new FormData();
        fd.append('action', 'save_prosem_row');
        fd.append('id_kmp', state.id_kmp);
        fd.append('tahun_pelajaran', state.tahun);
        fd.append('semester', state.semester);
        fd.append('id_prosem', state.prosem ? state.prosem.id : 0);
        fd.append('id_row', document.getElementById('prosemRowId').value);
        fd.append('kategori', kat);
        fd.append('id_tp', (kat === 'Materi KBM' ? tpId : ''));
        fd.append('nama_agenda', namaAgenda);
        fd.append('id_modul_ajar', document.getElementById('modalProsemModul').value);
        fd.append('nomor_urut', document.getElementById('modalProsemNomorUrut').value);
        fd.append('alokasi_waktu', document.getElementById('modalProsemAlokasi').value);

        var res = await fetch('', { method: 'POST', body: fd });
        var j = await res.json();
        if (j.success) {
            closeModal('modalProsemRowBackdrop', 'modalProsemRowDialog');
            Ilmuna.toast('success', 'Berhasil', j.message);

            if (j.data && j.data.row) {
                var savedRow = j.data.row;
                var rId = parseInt(document.getElementById('prosemRowId').value, 10);

                if (rId > 0) {
                    var tr = document.getElementById('row-prosem-' + rId);
                    if (tr) {
                        var labelAgenda = savedRow.nama_agenda || savedRow.materi_pokok || (savedRow.uraian_tp ? savedRow.uraian_tp.substring(0, 60) + '...' : 'Agenda KBM');
                        var isKbm = savedRow.kategori === 'Materi KBM';
                        tr.children[1].innerHTML = '<div style="font-weight: 700; color: var(--lumina-navy);">'
                            + (savedRow.kode_tp ? '<span style="color: var(--lumina-teal); margin-right: 4px;">[' + savedRow.kode_tp + ']</span>' : '')
                            + labelAgenda + '</div>'
                            + '<div style="font-size: 11px; color: #64748B; margin-top: 2px;"><span class="pdp-badge ' + (isKbm ? 'pdp-badge-approved' : 'pdp-badge-draft') + '" style="font-size: 10px; padding: 2px 6px;">' + savedRow.kategori + '</span></div>';
                        tr.children[2].textContent = savedRow.alokasi_waktu + ' JP';

                        tr.classList.remove('row-flash');
                        void tr.offsetWidth;
                        tr.classList.add('row-flash');
                    }
                    recalcProsemTotalsInPlace();
                } else {
                    loadState(); // Jika baris baru di-insert dengan cascade shifting
                }
            } else {
                loadState();
            }
        } else {
            Ilmuna.alert('error', 'Gagal', j.message);
        }
    });



    document.getElementById('btnCloseModalProsem').addEventListener('click', function() { closeModal('modalProsemRowBackdrop', 'modalProsemRowDialog'); });
    document.getElementById('btnCancelProsemModal').addEventListener('click', function() { closeModal('modalProsemRowBackdrop', 'modalProsemRowDialog'); });

    // =========================================================================
    // UNDUH DOKUMEN RESMI (PROTA & PROSEM) METODE DIRECT BLOB DOWNLOAD (DARI INCLUDES)
    // =========================================================================

    // 1. Unduh Dokumen PROTA PDF (Metode Direct Blob Download)
    var btnDlProta = document.getElementById('btnPrintProta');
    if (btnDlProta) {
        btnDlProta.addEventListener('click', async function() {
            if (!state.id_kmp) {
                Ilmuna.alert('warning', 'Peringatan', 'Pilih rombel dan mata pelajaran terlebih dahulu.');
                return;
            }
            if (!state.prota_details || state.prota_details.length === 0) {
                Ilmuna.alert('warning', 'Peringatan', 'Belum ada data PROTA untuk diunduh. Silakan generate atau tambahkan TP terlebih dahulu.');
                return;
            }

            var origHtml = btnDlProta.innerHTML;
            btnDlProta.disabled = true;
            btnDlProta.innerHTML = '<iconify-icon icon="solar:restart-bold" class="ilmuna-spin"></iconify-icon> Menyiapkan PDF...';

            try {
                var fd = new FormData();
                fd.append('action', 'download_prota_pdf');
                fd.append('id_kmp', state.id_kmp);
                fd.append('tahun_pelajaran', state.tahun);

                var res = await fetch('', { method: 'POST', body: fd });
                if (!res.ok) throw new Error('Gagal mengunduh dokumen PROTA PDF.');

                var contentType = res.headers.get('content-type') || '';
                if (contentType.includes('application/json')) {
                    var j = await res.json();
                    throw new Error(j.message || 'Gagal membuat berkas PDF PROTA.');
                }

                var blob = await res.blob();
                var cleanKelas = state.kmp_info ? state.kmp_info.nama_kelas.replace(/[^a-zA-Z0-9_\-]/g, '_') : 'Kelas';
                var cleanMapel = state.kmp_info ? state.kmp_info.nama_mata_pelajaran.replace(/[^a-zA-Z0-9_\-]/g, '_') : 'Mapel';
                var cleanTahun = state.tahun ? state.tahun.replace(/[^0-9]/g, '-') : '2026-2027';
                var filename = 'PROTA_' + cleanKelas + '_' + cleanMapel + '_' + cleanTahun + '.pdf';

                var blobUrl = window.URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.style.display = 'none';
                a.href = blobUrl;
                a.download = filename;
                document.body.appendChild(a);
                a.click();

                setTimeout(function() {
                    if (a.parentNode) document.body.removeChild(a);
                    window.URL.revokeObjectURL(blobUrl);
                }, 1000);

                Ilmuna.toast('success', 'Berhasil', 'Berkas PDF PROTA berhasil diunduh!');
            } catch (err) {
                console.error('Download PROTA PDF error:', err);
                Ilmuna.alert('error', 'Gagal Unduh PDF', err.message || 'Terjadi kesalahan saat mengunduh berkas PDF.');
            } finally {
                btnDlProta.disabled = false;
                btnDlProta.innerHTML = origHtml;
            }
        });
    }

    // 2. Unduh Dokumen PROSEM PDF (Metode Direct Blob Download)
    var btnDlProsem = document.getElementById('btnPrintProsem');
    if (btnDlProsem) {
        btnDlProsem.addEventListener('click', async function() {
            if (!state.id_kmp) {
                Ilmuna.alert('warning', 'Peringatan', 'Pilih rombel dan mata pelajaran terlebih dahulu.');
                return;
            }
            if (!state.prosem_details || state.prosem_details.length === 0) {
                Ilmuna.alert('warning', 'Peringatan', 'Belum ada data PROSEM untuk diunduh. Silakan generate PROSEM terlebih dahulu.');
                return;
            }

            var origHtml = btnDlProsem.innerHTML;
            btnDlProsem.disabled = true;
            btnDlProsem.innerHTML = '<iconify-icon icon="solar:restart-bold" class="ilmuna-spin"></iconify-icon> Menyiapkan PDF...';

            try {
                var fd = new FormData();
                fd.append('action', 'download_prosem_pdf');
                fd.append('id_kmp', state.id_kmp);
                fd.append('tahun_pelajaran', state.tahun);
                fd.append('semester', state.semester);

                var res = await fetch('', { method: 'POST', body: fd });
                if (!res.ok) throw new Error('Gagal mengunduh dokumen PROSEM PDF.');

                var contentType = res.headers.get('content-type') || '';
                if (contentType.includes('application/json')) {
                    var j = await res.json();
                    throw new Error(j.message || 'Gagal membuat berkas PDF PROSEM.');
                }

                var blob = await res.blob();
                var cleanKelas = state.kmp_info ? state.kmp_info.nama_kelas.replace(/[^a-zA-Z0-9_\-]/g, '_') : 'Kelas';
                var cleanMapel = state.kmp_info ? state.kmp_info.nama_mata_pelajaran.replace(/[^a-zA-Z0-9_\-]/g, '_') : 'Mapel';
                var cleanSem   = state.semester ? state.semester.toUpperCase() : 'GANJIL';
                var cleanTahun = state.tahun ? state.tahun.replace(/[^0-9]/g, '-') : '2026-2027';
                var filename = 'PROSEM_' + cleanSem + '_' + cleanKelas + '_' + cleanMapel + '_' + cleanTahun + '.pdf';

                var blobUrl = window.URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.style.display = 'none';
                a.href = blobUrl;
                a.download = filename;
                document.body.appendChild(a);
                a.click();

                setTimeout(function() {
                    if (a.parentNode) document.body.removeChild(a);
                    window.URL.revokeObjectURL(blobUrl);
                }, 1000);

                Ilmuna.toast('success', 'Berhasil', 'Berkas PDF PROSEM berhasil diunduh!');
            } catch (err) {
                console.error('Download PROSEM PDF error:', err);
                Ilmuna.alert('error', 'Gagal Unduh PDF', err.message || 'Terjadi kesalahan saat mengunduh berkas PDF.');
            } finally {
                btnDlProsem.disabled = false;
                btnDlProsem.innerHTML = origHtml;
            }
        });
    }



    // Inisialisasi awal
    loadKelas();
});
</script>

<?php
require_once BASE_PATH . '/includes/footer.php';
?>
