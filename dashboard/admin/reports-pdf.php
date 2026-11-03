<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — EXPORT PDF LAPORAN & DAFTAR NILAI SISWA
 * ==============================================================================
 * Menghasilkan Berkas PDF Daftar Nilai Siswa (Kurikulum Merdeka) untuk Diunduh Langsung:
 *   - Layout: A4 Landscape Presisi Tinggi
 *   - Header Satuan Pendidikan, Info Rombel, Mata Pelajaran, Guru, dan KKTP Database
 *   - Rekapitulasi Lengkap Nilai Tugas, Formatif, Sumatif (LM, STS, SAS), dan Remedial
 *   - Nilai Akhir, Predikat, Status Ketuntasan KKTP, dan Titimangsa Tanda Tangan
 *
 * Menggunakan Arsitektur Headless Browser Rendering (Persis kaldik-pdf.php)
 * dan Diunduh Langsung via JavaScript Blob Method.
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';

if (php_sapi_name() !== 'cli') {
    requireRole(['admin', 'guru']);
}

$pdo = getDBConnection();
$pdo->exec("SET time_zone = '+07:00'");

if (!function_exists('ilmuna_get_headless_browser_bin')) {
    function ilmuna_get_headless_browser_bin() {
        $candidates = [
            'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
            'C:\Program Files\Microsoft\Edge\Application\msedge.exe',
            'C:\Program Files\Google\Chrome\Application\chrome.exe',
            'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/snap/bin/chromium'
        ];
        foreach ($candidates as $bin) {
            if ($bin && file_exists($bin)) {
                return $bin;
            }
        }
        return null;
    }
}

$idKelas = (int)($_GET['id_kelas'] ?? 0);
$idKmp   = (int)($_GET['id_kmp'] ?? 0);

if ($idKelas <= 0 || $idKmp <= 0) {
    http_response_code(400);
    die('Parameter id_kelas dan id_kmp wajib disertakan.');
}

// 1. Ambil Info Kelas
$stmtK = $pdo->prepare("SELECT id, nama_kelas, tahun_pelajaran FROM kelas WHERE id = ? LIMIT 1");
$stmtK->execute([$idKelas]);
$kelasInfo = $stmtK->fetch(PDO::FETCH_ASSOC);
if (!$kelasInfo) {
    http_response_code(404);
    die('Data kelas tidak ditemukan.');
}

// 1b. Ambil Profil Identitas Sekolah
$sekolah = null;
try {
    $stmtSekolah = $pdo->query("SELECT * FROM identitas_sekolah WHERE is_active = 1 LIMIT 1");
    $sekolah = $stmtSekolah->fetch(PDO::FETCH_ASSOC);
    if (!$sekolah) {
        $stmtSekolah = $pdo->query("SELECT * FROM identitas_sekolah ORDER BY id DESC LIMIT 1");
        $sekolah = $stmtSekolah->fetch(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $sekolah = [];
}
if (!$sekolah) {
    $sekolah = [];
}

// Helper konversi file gambar ke Base64 URI agar render headless browser 100% presisi
if (!function_exists('ilmuna_file_to_b64')) {
    function ilmuna_file_to_b64($relPath) {
        if (empty($relPath)) return '';
        if (strpos($relPath, '<svg') !== false) {
            return 'data:image/svg+xml;base64,' . base64_encode(trim($relPath));
        }
        if (strpos($relPath, 'data:image') === 0) return $relPath;
        $baseDir = dirname(__DIR__, 2);
        $candidates = [
            $baseDir . '/' . ltrim($relPath, '/\\'),
            $baseDir . '/uploads/' . ltrim($relPath, '/\\'),
            $baseDir . '/uploads/school/' . ltrim($relPath, '/\\'),
            $relPath
        ];
        foreach ($candidates as $cand) {
            if (file_exists($cand) && is_file($cand)) {
                $ext = strtolower(pathinfo($cand, PATHINFO_EXTENSION));
                $mime = 'image/png';
                if (in_array($ext, ['jpg', 'jpeg'])) $mime = 'image/jpeg';
                elseif ($ext === 'svg') $mime = 'image/svg+xml';
                elseif ($ext === 'webp') $mime = 'image/webp';
                return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($cand));
            }
        }
        return '';
    }
}

// 2. Ambil Info Mapel & Guru
$stmtM = $pdo->prepare("
    SELECT kmp.id, kmp.id_mata_pelajaran, mp.nama_mata_pelajaran, mp.kode_mapel, mp.kktp AS mp_kktp,
           COALESCE(g.nama_guru, 'Belum Ditentukan') AS nama_guru,
           COALESCE(g.nip, '-') AS nip_guru
    FROM kelas_mata_pelajaran kmp
    JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
    LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
    LEFT JOIN guru g ON gmp.id_guru = g.id
    WHERE kmp.id = ? LIMIT 1
");
$stmtM->execute([$idKmp]);
$mapelInfo = $stmtM->fetch(PDO::FETCH_ASSOC);
if (!$mapelInfo) {
    http_response_code(404);
    die('Data mata pelajaran tidak ditemukan.');
}

// 3. Resolusi KKTP Dinamis dari Database
$kktpAcuan = null;
if (!empty($mapelInfo['mp_kktp']) && (float)$mapelInfo['mp_kktp'] > 0) {
    $kktpAcuan = (float)$mapelInfo['mp_kktp'];
}
if ($kktpAcuan === null) {
    $stmtSmKktp = $pdo->prepare("SELECT kktp FROM asesmen_sumatif WHERE id_kelas_mata_pelajaran = ? AND kktp IS NOT NULL AND kktp > 0 ORDER BY id DESC LIMIT 1");
    $stmtSmKktp->execute([$idKmp]);
    $val = $stmtSmKktp->fetchColumn();
    if ($val !== false && (float)$val > 0) $kktpAcuan = (float)$val;
}
if ($kktpAcuan === null) {
    $stmtM = $pdo->prepare("SELECT kkm FROM mapel WHERE nama_mapel LIKE ? OR ? LIKE CONCAT('%', nama_mapel, '%') OR kode_mapel = ? LIMIT 1");
    $stmtM->execute(['%' . $mapelInfo['nama_mata_pelajaran'] . '%', $mapelInfo['nama_mata_pelajaran'], $mapelInfo['kode_mapel']]);
    $val = $stmtM->fetchColumn();
    if ($val !== false && (float)$val > 0) $kktpAcuan = (float)$val;
}
if ($kktpAcuan === null) {
    $stmtPrKktp = $pdo->prepare("SELECT kktp_minimal FROM pengayaan_remedial WHERE id_kelas_mata_pelajaran = ? AND kktp_minimal IS NOT NULL AND kktp_minimal > 0 ORDER BY id DESC LIMIT 1");
    $stmtPrKktp->execute([$idKmp]);
    $val = $stmtPrKktp->fetchColumn();
    if ($val !== false && (float)$val > 0) $kktpAcuan = (float)$val;
}
if ($kktpAcuan === null || $kktpAcuan <= 0) {
    $kktpAcuan = 75.00;
}

// 4. Ambil Daftar Siswa
$stmtS = $pdo->prepare("
    SELECT s.id, s.nis, s.nisn, s.nama_lengkap, s.foto, k.nama_kelas
    FROM siswa s
    JOIN kelas k ON s.id_kelas = k.id
    WHERE s.id_kelas = ?
    ORDER BY s.nama_lengkap ASC
");
$stmtS->execute([$idKelas]);
$siswaList = $stmtS->fetchAll(PDO::FETCH_ASSOC);

$idMapel = (int)($mapelInfo['id_mata_pelajaran'] ?? 0);

// 5. Kolom Tugas, Formatif, Sumatif
$stmtT = $pdo->prepare("
    SELECT DISTINCT
        t.id, t.judul_tugas, t.jenis_penugasan, t.nilai_maksimal
    FROM tugas t
    LEFT JOIN kelas_mata_pelajaran kmp_t ON t.id_kelas_mata_pelajaran = kmp_t.id
    WHERE (
        t.id_kelas_mata_pelajaran = :id_kmp
        OR (kmp_t.id_kelas = :id_kelas AND kmp_t.id_mata_pelajaran = :id_mapel)
    )
    ORDER BY t.id ASC
");
$stmtT->execute([
    ':id_kmp'   => $idKmp,
    ':id_kelas' => $idKelas,
    ':id_mapel' => $idMapel
]);
$tugasCols = $stmtT->fetchAll(PDO::FETCH_ASSOC);
if (empty($tugasCols)) {
    $tugasCols = [
        ['id' => 0, 'slot_code' => 'T1', 'judul_tugas' => 'Tugas 1'],
        ['id' => 0, 'slot_code' => 'T2', 'judul_tugas' => 'Tugas 2']
    ];
} else {
    foreach ($tugasCols as $idx => &$tc) { $tc['slot_code'] = 'T' . ($idx + 1); }
    unset($tc);
}

$stmtF = $pdo->prepare("
    SELECT DISTINCT
        asf.id, asf.judul_asesmen, asf.teknik_asesmen
    FROM asesmen_formatif asf
    LEFT JOIN kelas_mata_pelajaran kmp_f ON asf.id_kelas_mata_pelajaran = kmp_f.id
    WHERE (
        asf.id_kelas_mata_pelajaran = :id_kmp
        OR (kmp_f.id_kelas = :id_kelas AND kmp_f.id_mata_pelajaran = :id_mapel)
    )
    ORDER BY asf.id ASC
");
$stmtF->execute([
    ':id_kmp'   => $idKmp,
    ':id_kelas' => $idKelas,
    ':id_mapel' => $idMapel
]);
$formatifCols = $stmtF->fetchAll(PDO::FETCH_ASSOC);
if (empty($formatifCols)) {
    $formatifCols = [
        ['id' => 0, 'slot_code' => 'F1', 'judul_asesmen' => 'Formatif 1'],
        ['id' => 0, 'slot_code' => 'F2', 'judul_asesmen' => 'Formatif 2']
    ];
} else {
    foreach ($formatifCols as $idx => &$fc) { $fc['slot_code'] = 'F' . ($idx + 1); }
    unset($fc);
}

$stmtSm = $pdo->prepare("
    SELECT DISTINCT
        asum.id,
        asum.judul_asesmen,
        asum.jenis_sumatif,
        asum.kktp,
        asum.pertemuan_ke,
        asum.created_at
    FROM asesmen_sumatif asum
    LEFT JOIN asesmen_sumatif_kelas ask ON asum.id = ask.id_asesmen_sumatif
    LEFT JOIN kelas_mata_pelajaran kmp_as ON asum.id_kelas_mata_pelajaran = kmp_as.id
    LEFT JOIN kelas_mata_pelajaran ask_mp ON ask.id_kelas_mata_pelajaran = ask_mp.id
    WHERE (
        asum.id_kelas_mata_pelajaran = :id_kmp 
        OR ask.id_kelas_mata_pelajaran = :id_kmp
        OR (
            (kmp_as.id_kelas = :id_kelas AND kmp_as.id_mata_pelajaran = :id_mapel)
            OR (ask_mp.id_kelas = :id_kelas AND ask_mp.id_mata_pelajaran = :id_mapel)
        )
    )
    ORDER BY asum.id ASC
");
$stmtSm->execute([
    ':id_kmp'   => $idKmp,
    ':id_kelas' => $idKelas,
    ':id_mapel' => $idMapel
]);
$sumatifCols = $stmtSm->fetchAll(PDO::FETCH_ASSOC);
if (empty($sumatifCols)) {
    $sumatifCols = [
        ['id' => 0, 'slot_code' => 'S1', 'judul_asesmen' => 'Sumatif LM 1', 'kktp' => $kktpAcuan],
        ['id' => 0, 'slot_code' => 'STS', 'judul_asesmen' => 'Sumatif Tengah Semester', 'kktp' => $kktpAcuan],
        ['id' => 0, 'slot_code' => 'SAS', 'judul_asesmen' => 'Sumatif Akhir Semester', 'kktp' => $kktpAcuan]
    ];
} else {
    foreach ($sumatifCols as $idx => &$sc) {
        if (empty($sc['kktp']) || (float)$sc['kktp'] <= 0) $sc['kktp'] = $kktpAcuan;
        if ($sc['jenis_sumatif'] === 'Sumatif Tengah Semester') $sc['slot_code'] = 'STS';
        elseif ($sc['jenis_sumatif'] === 'Sumatif Akhir Semester') $sc['slot_code'] = 'SAS';
        else $sc['slot_code'] = 'S' . ($idx + 1);
    }
    unset($sc);
}

// 6. Nilai Tugas, Formatif, Sumatif (Mencocokkan siswa di kelas & ID asesmen relevan)
$gradesTugas = [];
$tgsIds = array_filter(array_map(function($tc) { return (int)($tc['id'] ?? 0); }, $tugasCols));
if (!empty($tgsIds)) {
    $inTgs = implode(',', array_fill(0, count($tgsIds), '?'));
    $sqlTgs = "
        SELECT nt.id_tugas, nt.id_siswa, nt.nilai, nt.nilai_remedial
        FROM nilai_tugas nt
        JOIN siswa s ON nt.id_siswa = s.id
        WHERE s.id_kelas = ? AND (nt.id_kelas_mata_pelajaran = ? OR nt.id_tugas IN ($inTgs))
        ORDER BY nt.id DESC
    ";
    $stmtGrT = $pdo->prepare($sqlTgs);
    $stmtGrT->execute(array_merge([$idKelas, $idKmp], array_values($tgsIds)));
} else {
    $stmtGrT = $pdo->prepare("
        SELECT nt.id_tugas, nt.id_siswa, nt.nilai, nt.nilai_remedial 
        FROM nilai_tugas nt
        JOIN siswa s ON nt.id_siswa = s.id
        WHERE s.id_kelas = ? AND nt.id_kelas_mata_pelajaran = ?
        ORDER BY nt.id DESC
    ");
    $stmtGrT->execute([$idKelas, $idKmp]);
}
while ($row = $stmtGrT->fetch(PDO::FETCH_ASSOC)) {
    $sId = (int)$row['id_siswa'];
    $tId = (int)$row['id_tugas'];
    if (!isset($gradesTugas[$sId][$tId]) || (float)$row['nilai'] > (float)$gradesTugas[$sId][$tId]['nilai']) {
        $gradesTugas[$sId][$tId] = $row;
    }
}

$gradesFormatif = [];
$fmtIds = array_filter(array_map(function($fc) { return (int)($fc['id'] ?? 0); }, $formatifCols));
if (!empty($fmtIds)) {
    $inFmt = implode(',', array_fill(0, count($fmtIds), '?'));
    $sqlFmt = "
        SELECT nf.id_asesmen_formatif, nf.id_siswa, nf.nilai_angka, nf.nilai_remedial, nf.nilai_kktp, nf.status_tuntas
        FROM nilai_asesmen_formatif nf
        JOIN siswa s ON nf.id_siswa = s.id
        WHERE s.id_kelas = ? AND (nf.id_kelas_mata_pelajaran = ? OR nf.id_asesmen_formatif IN ($inFmt))
        ORDER BY nf.id DESC
    ";
    $stmtGrF = $pdo->prepare($sqlFmt);
    $stmtGrF->execute(array_merge([$idKelas, $idKmp], array_values($fmtIds)));
} else {
    $stmtGrF = $pdo->prepare("
        SELECT nf.id_asesmen_formatif, nf.id_siswa, nf.nilai_angka, nf.nilai_remedial, nf.nilai_kktp, nf.status_tuntas 
        FROM nilai_asesmen_formatif nf
        JOIN siswa s ON nf.id_siswa = s.id
        WHERE s.id_kelas = ? AND nf.id_kelas_mata_pelajaran = ?
        ORDER BY nf.id DESC
    ");
    $stmtGrF->execute([$idKelas, $idKmp]);
}
while ($row = $stmtGrF->fetch(PDO::FETCH_ASSOC)) {
    $sId = (int)$row['id_siswa'];
    $fId = (int)$row['id_asesmen_formatif'];
    if (!isset($gradesFormatif[$sId][$fId]) || (float)$row['nilai_angka'] > (float)$gradesFormatif[$sId][$fId]['nilai_angka']) {
        $gradesFormatif[$sId][$fId] = $row;
    }
}

$gradesSumatif = [];
$smIds = array_filter(array_map(function($sc) { return (int)($sc['id'] ?? 0); }, $sumatifCols));
if (!empty($smIds)) {
    $inSm = implode(',', array_fill(0, count($smIds), '?'));
    $sqlSm = "
        SELECT ns.id_asesmen_sumatif, ns.id_siswa, ns.nilai_angka, ns.nilai_remedial, ns.nilai_kktp, ns.status_tuntas
        FROM nilai_asesmen_sumatif ns
        JOIN siswa s ON ns.id_siswa = s.id
        WHERE s.id_kelas = ? AND (ns.id_kelas_mata_pelajaran = ? OR ns.id_asesmen_sumatif IN ($inSm))
        ORDER BY ns.id DESC
    ";
    $stmtGrSm = $pdo->prepare($sqlSm);
    $stmtGrSm->execute(array_merge([$idKelas, $idKmp], array_values($smIds)));
} else {
    $stmtGrSm = $pdo->prepare("
        SELECT ns.id_asesmen_sumatif, ns.id_siswa, ns.nilai_angka, ns.nilai_remedial, ns.nilai_kktp, ns.status_tuntas 
        FROM nilai_asesmen_sumatif ns
        JOIN siswa s ON ns.id_siswa = s.id
        WHERE s.id_kelas = ? AND ns.id_kelas_mata_pelajaran = ?
        ORDER BY ns.id DESC
    ");
    $stmtGrSm->execute([$idKelas, $idKmp]);
}
while ($row = $stmtGrSm->fetch(PDO::FETCH_ASSOC)) {
    $sId = (int)$row['id_siswa'];
    $smId = (int)$row['id_asesmen_sumatif'];
    if (!isset($gradesSumatif[$sId][$smId]) || (float)$row['nilai_angka'] > (float)$gradesSumatif[$sId][$smId]['nilai_angka']) {
        $gradesSumatif[$sId][$smId] = $row;
    }
}

// 7. Ambil Rekapitulasi Nilai Akhir Siswa (50% Kognitif, 40% Afektif, 10% Psikomotorik)
require_once __DIR__ . '/../../includes/rekap-nilai-calculator.php';
$stmtRekap = $pdo->prepare("
    SELECT id_siswa, rata_afektif, predikat_afektif, rata_psikomotorik, predikat_psikomotorik,
           nilai_akhir_semester, nilai_toleransi, predikat_akhir, status_kelulusan_kktp
    FROM rekap_nilai_siswa_mapel
    WHERE id_kelas_mata_pelajaran = ?
");
$stmtRekap->execute([$idKmp]);
$rekapMap = [];
while ($rk = $stmtRekap->fetch(PDO::FETCH_ASSOC)) {
    $rekapMap[(int)$rk['id_siswa']] = $rk;
}

// Jika data rekap belum digenerate/disimpan di DB, lakukan kalkulasi live (preview mode) agar cetak tidak kosong!
if (empty($rekapMap)) {
    $calcResult = ilmuna_generate_rekap_nilai_akhir($pdo, $idKelas, $idKmp, 'Ganjil', $kelasInfo['tahun_pelajaran'] ?? '2026/2027', null, false);
    if (!empty($calcResult['success']) && !empty($calcResult['data'])) {
        foreach ($calcResult['data'] as $rowC) {
            $rekapMap[(int)$rowC['id_siswa']] = [
                'id_siswa' => $rowC['id_siswa'],
                'rata_afektif' => $rowC['rata_afektif'],
                'predikat_afektif' => $rowC['predikat_afektif'],
                'rata_psikomotorik' => $rowC['rata_psikomotorik'],
                'predikat_psikomotorik' => $rowC['predikat_psikomotorik'],
                'nilai_akhir_semester' => $rowC['nilai_akhir_semester'],
                'nilai_toleransi' => $rowC['nilai_toleransi'],
                'predikat_akhir' => $rowC['predikat_akhir'],
                'status_kelulusan_kktp' => $rowC['status_kelulusan_kktp']
            ];
        }
    }
}

// 8. Mulai Render HTML Template
ob_start();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Daftar Nilai Siswa - <?= htmlspecialchars($kelasInfo['nama_kelas']) ?> - <?= htmlspecialchars($mapelInfo['nama_mata_pelajaran']) ?></title>
    <style>
        @page {
            size: A4 landscape;
            margin: 10mm 12mm 12mm 12mm;
        }
        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
            font-size: 11px;
            color: #1e293b;
            margin: 0;
            padding: 0;
            background: #ffffff;
        }
        .header-wrap {
            border-bottom: 2.5px solid #0D7A87;
            padding-bottom: 8px;
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }
        .header-title h1 {
            font-size: 16px;
            font-weight: 800;
            color: #1D3B5E;
            margin: 0 0 3px 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .header-title p {
            font-size: 11.5px;
            font-weight: 600;
            color: #0D7A87;
            margin: 0;
        }
        .meta-box {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 6px;
            padding: 8px 12px;
            margin-bottom: 12px;
            font-size: 10.5px;
        }
        .meta-item strong {
            color: #64748B;
            font-size: 9.5px;
            text-transform: uppercase;
            display: block;
            margin-bottom: 2px;
        }
        .meta-item span {
            color: #0F172A;
            font-weight: 700;
        }
        .badge-kktp {
            background: #ECFDF5;
            color: #065F46;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: 800;
            border: 1px solid #A7F3D0;
            display: inline-block;
        }

        /* TABLE */
        table.grade-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
            margin-bottom: 14px;
        }
        table.grade-table th,
        table.grade-table td {
            border: 1px solid #CBD5E1;
            padding: 5px 4px;
            text-align: center;
            vertical-align: middle;
        }
        table.grade-table th {
            background: #F1F5F9;
            color: #1E293B;
            font-weight: 800;
            font-size: 9.5px;
            text-transform: uppercase;
        }
        table.grade-table th.th-tugas { background: #EFF6FF !important; color: #1D4ED8 !important; }
        table.grade-table th.th-formatif { background: #ECFDF5 !important; color: #047857 !important; }
        table.grade-table th.th-sumatif { background: #FFFBEB !important; color: #B45309 !important; }
        table.grade-table th.th-afektif { background: #FAF5FF !important; color: #7E22CE !important; }
        table.grade-table th.th-psikomotorik { background: #ECFEFF !important; color: #0E7490 !important; }
        table.grade-table th.th-final { background: #1D3B5E !important; color: #FFFFFF !important; }

        .cell-asli-strikethrough {
            font-size: 8.5px;
            color: #DC2626;
            text-decoration: line-through;
            display: block;
            line-height: 1;
        }
        .cell-remed {
            font-weight: 800;
            color: #0D7A87;
            font-size: 10.5px;
        }
        .cell-pass {
            font-weight: 700;
            color: #059669;
        }
        .cell-fail {
            font-weight: 700;
            color: #DC2626;
        }
        .badge-rem-tag {
            font-size: 7.5px;
            font-weight: 800;
            background: #FEF3C7;
            color: #92400E;
            padding: 1px 3px;
            border-radius: 3px;
            display: inline-block;
            margin-top: 1px;
        }
        .status-badge-tuntas {
            background: #ECFDF5;
            color: #065F46;
            font-weight: 800;
            font-size: 8.5px;
            padding: 2px 5px;
            border-radius: 4px;
            display: inline-block;
        }
        .status-badge-belum {
            background: #FEE2E2;
            color: #991B1B;
            font-weight: 800;
            font-size: 8.5px;
            padding: 2px 5px;
            border-radius: 4px;
            display: inline-block;
        }

        /* SIGNATURE */
        .signature-wrap {
            display: flex;
            justify-content: space-between;
            margin-top: 18px;
            page-break-inside: avoid;
            font-size: 10.5px;
        }
        .sig-box {
            text-align: center;
            width: 250px;
        }
        .sig-space {
            height: 55px;
        }
        .sig-name {
            font-weight: 800;
            text-decoration: underline;
            color: #0F172A;
        }
    </style>
</head>
<body>

    <div class="header-wrap">
        <div class="header-title">
            <h1><?= htmlspecialchars($sekolah['nama_sekolah'] ?? 'ILMUNA LEARN') ?> — DAFTAR NILAI SISWA</h1>
            <p>Standar Kurikulum Merdeka Terintegrasi &bull; <?= !empty($sekolah['npsn']) ? 'NPSN: ' . htmlspecialchars($sekolah['npsn']) . ' &bull; ' : '' ?>Rekapitulasi Tugas, Formatif, Sumatif &amp; Remedial</p>
        </div>
        <div style="text-align: right; font-size: 10px; color: #64748B;">
            Dicetak: <?= date('d/m/Y H:i') ?> WIB
        </div>
    </div>

    <div class="meta-box">
        <div class="meta-item">
            <strong>Rombel / Kelas:</strong>
            <span><?= htmlspecialchars($kelasInfo['nama_kelas']) ?></span>
        </div>
        <div class="meta-item">
            <strong>Tahun Pelajaran:</strong>
            <span><?= htmlspecialchars($kelasInfo['tahun_pelajaran'] ?: '2026/2027') ?></span>
        </div>
        <div class="meta-item">
            <strong>Mata Pelajaran:</strong>
            <span><?= htmlspecialchars($mapelInfo['nama_mata_pelajaran']) ?></span>
        </div>
        <div class="meta-item">
            <strong>Batas KKTP Database:</strong>
            <span class="badge-kktp"><?= number_format($kktpAcuan, 0) ?>.00</span>
        </div>
    </div>

    <table class="grade-table">
        <thead>
            <tr>
                <th rowspan="2" style="width: 24px;">#</th>
                <th rowspan="2" style="width: 70px;">NIS</th>
                <th rowspan="2" style="width: 80px;">NISN</th>
                <th rowspan="2" style="text-align: left; padding-left: 6px;">Nama Lengkap Siswa</th>
                <th colspan="<?= count($tugasCols) ?>" class="th-tugas">Tugas &amp; Proyek</th>
                <th colspan="<?= count($formatifCols) ?>" class="th-formatif">Asesmen Formatif</th>
                <th colspan="<?= count($sumatifCols) ?>" class="th-sumatif">Asesmen Sumatif</th>
                <th colspan="2" class="th-afektif">Observasi Sikap (40%)</th>
                <th colspan="2" class="th-psikomotorik">Unjuk Kerja (10%)</th>
                <th rowspan="2" class="th-final" style="width: 65px;">Nilai Akhir</th>
                <th rowspan="2" class="th-final" style="width: 38px;">Pred</th>
                <th rowspan="2" class="th-final" style="width: 70px;">Status KKTP</th>
            </tr>
            <tr>
                <?php foreach ($tugasCols as $tc): ?>
                    <th style="width: 38px;"><?= htmlspecialchars($tc['slot_code']) ?></th>
                <?php endforeach; ?>
                <?php foreach ($formatifCols as $fc): ?>
                    <th style="width: 38px;"><?= htmlspecialchars($fc['slot_code']) ?></th>
                <?php endforeach; ?>
                <?php foreach ($sumatifCols as $sc): ?>
                    <th style="width: 42px;"><?= htmlspecialchars($sc['slot_code']) ?></th>
                <?php endforeach; ?>
                <th style="width: 38px;">Skor</th>
                <th style="width: 32px;">Pred</th>
                <th style="width: 38px;">Skor</th>
                <th style="width: 32px;">Pred</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $no = 1;
            $countTuntasMurni = 0;
            $countTerbantu = 0;
            $countBelumTuntas = 0;

            foreach ($siswaList as $s):
                $sId = $s['id'];
                $scores = [];
                $hasRem = false;

                $rk = $rekapMap[$sId] ?? null;
                $naFinal = ($rk && $rk['nilai_akhir_semester'] !== null) ? (float)$rk['nilai_akhir_semester'] : null;
                $tolVal  = ($rk && $rk['nilai_toleransi'] !== null) ? (float)$rk['nilai_toleransi'] : 0.00;
                $predAkhir = ($rk && !empty($rk['predikat_akhir'])) ? $rk['predikat_akhir'] : '-';
                $statKktp  = ($rk && !empty($rk['status_kelulusan_kktp'])) ? $rk['status_kelulusan_kktp'] : (($naFinal !== null && $naFinal >= $kktpAcuan) ? 'Tercapai' : 'Belum Tercapai');

                $isTerbantu = ($tolVal > 0);
                $naMurni = $isTerbantu ? max(0.0, round($naFinal - $tolVal, 2)) : $naFinal;
                $tolPct  = ($isTerbantu && $kktpAcuan > 0) ? round(($tolVal / $kktpAcuan) * 100, 1) : 0.0;

                if ($naFinal !== null && $naFinal >= $kktpAcuan) {
                    if ($isTerbantu) $countTerbantu++;
                    else $countTuntasMurni++;
                } else {
                    $countBelumTuntas++;
                }
            ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td><?= htmlspecialchars($s['nis'] ?: '-') ?></td>
                    <td><?= htmlspecialchars($s['nisn'] ?: '-') ?></td>
                    <td style="text-align: left; padding-left: 6px; font-weight: 700; color: #0F172A;">
                        <?= htmlspecialchars($s['nama_lengkap']) ?>
                    </td>

                    <!-- TUGAS -->
                    <?php foreach ($tugasCols as $tc):
                        $tId = $tc['id'];
                        $g = $gradesTugas[$sId][$tId] ?? null;
                        $asli = $g ? $g['nilai'] : null;
                        $remed = $g ? $g['nilai_remedial'] : null;
                        $eff = ($remed !== null) ? $remed : $asli;
                        if ($eff !== null && $eff !== '') $scores[] = (float)$eff;
                    ?>
                        <td>
                            <?php if ($asli === null && $remed === null): ?>
                                <span style="color:#CBD5E1;">-</span>
                            <?php elseif ($remed !== null): $hasRem = true; ?>
                                <span class="cell-asli-strikethrough"><?= (float)$asli ?></span>
                                <span class="cell-remed"><?= (float)$remed ?></span>
                                <span class="badge-rem-tag">Rem</span>
                            <?php else: ?>
                                <span class="<?= ((float)$asli >= $kktpAcuan) ? 'cell-pass' : 'cell-fail' ?>"><?= (float)$asli ?></span>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>

                    <!-- FORMATIF -->
                    <?php foreach ($formatifCols as $fc):
                        $fId = $fc['id'];
                        $g = $gradesFormatif[$sId][$fId] ?? null;
                        $asli = $g ? $g['nilai_angka'] : null;
                        $remed = $g ? $g['nilai_remedial'] : null;
                        $eff = ($remed !== null) ? $remed : $asli;
                        if ($eff !== null && $eff !== '') $scores[] = (float)$eff;
                    ?>
                        <td>
                            <?php if ($asli === null && $remed === null): ?>
                                <span style="color:#CBD5E1;">-</span>
                            <?php elseif ($remed !== null): $hasRem = true; ?>
                                <span class="cell-asli-strikethrough"><?= (float)$asli ?></span>
                                <span class="cell-remed"><?= (float)$remed ?></span>
                                <span class="badge-rem-tag">Rem</span>
                            <?php else: ?>
                                <span class="<?= ((float)$asli >= $kktpAcuan) ? 'cell-pass' : 'cell-fail' ?>"><?= (float)$asli ?></span>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>

                    <!-- SUMATIF -->
                    <?php foreach ($sumatifCols as $sc):
                        $smId = $sc['id'];
                        $kktpThis = (float)($sc['kktp'] ?? $kktpAcuan);
                        $g = $gradesSumatif[$sId][$smId] ?? null;
                        $asli = $g ? $g['nilai_angka'] : null;
                        $remed = $g ? $g['nilai_remedial'] : null;
                        $eff = ($remed !== null) ? $remed : $asli;
                        if ($eff !== null && $eff !== '') $scores[] = (float)$eff;
                    ?>
                        <td>
                            <?php if ($asli === null && $remed === null): ?>
                                <span style="color:#CBD5E1;">-</span>
                            <?php elseif ($remed !== null): $hasRem = true; ?>
                                <span class="cell-asli-strikethrough"><?= (float)$asli ?></span>
                                <span class="cell-remed"><?= (float)$remed ?></span>
                                <span class="badge-rem-tag">Rem</span>
                            <?php else: ?>
                                <?php $isF = ((float)$asli < $kktpThis); ?>
                                <span class="<?= $isF ? 'cell-fail' : 'cell-pass' ?>"><?= (float)$asli ?></span>
                                <?php if ($isF): ?><span class="badge-rem-tag">Rem</span><?php $hasRem = true; endif; ?>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>

                    <!-- SIKAP / AFEKTIF (40%) -->
                    <?php 
                    $rAfk = ($rk && $rk['rata_afektif'] !== null) ? (float)$rk['rata_afektif'] : null;
                    $pAfk = ($rk && !empty($rk['predikat_afektif'])) ? $rk['predikat_afektif'] : '-';
                    ?>
                    <td>
                        <?= ($rAfk !== null && $rAfk > 0) ? number_format($rAfk, 1) : '<span style="color:#CBD5E1;">-</span>' ?>
                    </td>
                    <td style="font-weight: 700; color: #7E22CE;">
                        <?= htmlspecialchars($pAfk) ?>
                    </td>

                    <!-- PRAKTIK / PSIKOMOTORIK (10%) -->
                    <?php 
                    $rPsk = ($rk && $rk['rata_psikomotorik'] !== null) ? (float)$rk['rata_psikomotorik'] : null;
                    $pPsk = ($rk && !empty($rk['predikat_psikomotorik'])) ? $rk['predikat_psikomotorik'] : '-';
                    ?>
                    <td>
                        <?= ($rPsk !== null && $rPsk > 0) ? number_format($rPsk, 1) : '<span style="color:#CBD5E1;">-</span>' ?>
                    </td>
                    <td style="font-weight: 700; color: #0E7490;">
                        <?= htmlspecialchars($pPsk) ?>
                    </td>

                    <!-- FINAL: NILAI AKHIR (50% Kognitif, 40% Afektif, 10% Psikomotorik) -->
                    <td>
                        <?php if ($naFinal === null || $naFinal <= 0): ?>
                            <span style="color:#CBD5E1;">-</span>
                        <?php else: ?>
                            <div style="font-weight: 800; font-size: 11px; color: #1E293B;">
                                <?= number_format($naFinal, 1) ?>
                            </div>
                            <?php if ($isTerbantu): ?>
                                <div style="font-size: 7.5px; color: #B45309; font-weight: 700; line-height: 1.1; margin-top: 1px;" title="Terbantu Toleransi Guru">
                                    Asli: <?= number_format($naMurni, 1) ?> (+<?= $tolPct ?>%)
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td style="font-weight: 800; font-size: 11px; color: <?= ($statKktp === 'Tercapai') ? '#059669' : '#DC2626' ?>;">
                        <?= htmlspecialchars($predAkhir) ?>
                    </td>
                    <td>
                        <?php if ($naFinal === null || $naFinal <= 0): ?>
                            <span style="display:inline-block; padding:2px 4px; border-radius:3px; font-size:8px; font-weight:700; background:#F1F5F9; color:#475569; border:1px solid #CBD5E1;">Belum Ada</span>
                        <?php elseif ($statKktp === 'Tercapai'): ?>
                            <span class="status-badge-tuntas">Tercapai</span>
                            <?php if ($isTerbantu): ?>
                                <span style="font-size: 7px; color: #B45309; display: block; font-weight: 700;">(Toleransi)</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="status-badge-belum">Belum Tercapai</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- SUMMARY & TITIMANGSA -->
    <div class="signature-wrap">
        <div class="sig-box" style="text-align: left;">
            <div style="font-size: 10px; color: #64748B; margin-bottom: 4px;"><strong>Rekapitulasi Capaian Rombel:</strong></div>
            <div>&bull; Total Peserta Didik: <strong><?= count($siswaList) ?> Siswa</strong></div>
            <div>&bull; Tuntas KKTP (Murni): <strong><?= $countTuntasMurni ?> Siswa</strong></div>
            <div>&bull; Terbantu Toleransi Guru: <strong><?= $countTerbantu ?> Siswa</strong></div>
            <div>&bull; Belum Tuntas: <strong><?= $countBelumTuntas ?> Siswa</strong></div>
            <div style="margin-top: 2px; font-size: 9.5px; color: #475569;">&bull; Pembobotan: <strong>50% Kognitif &bull; 40% Afektif (Sikap) &bull; 10% Psikomotorik (Praktik)</strong></div>
        </div>

        <div class="sig-box">
            <div>Mengetahui,</div>
            <div style="font-weight: 700; color: #1D3B5E;">Kepala Satuan Pendidikan</div>
            <div class="sig-space" style="position: relative; display: flex; align-items: center; justify-content: center;">
                <?php 
                $ttdKepsekB64 = ilmuna_file_to_b64($sekolah['ttd_kepala_sekolah'] ?? '');
                $stempelB64   = ilmuna_file_to_b64($sekolah['cap_stempel_path'] ?? '');
                ?>
                <?php if ($stempelB64): ?>
                    <img src="<?= $stempelB64 ?>" alt="Stempel" style="position: absolute; left: 20px; top: 0; height: 55px; opacity: 0.85; pointer-events: none;">
                <?php endif; ?>
                <?php if ($ttdKepsekB64): ?>
                    <img src="<?= $ttdKepsekB64 ?>" alt="TTD Kepala Sekolah" style="max-height: 52px; max-width: 140px; position: relative; z-index: 2;">
                <?php endif; ?>
            </div>
            <div class="sig-name"><?= htmlspecialchars($sekolah['nama_kepala_sekolah'] ?: 'Dr. H. Muhammad Arifin, M.Pd.') ?></div>
            <div style="color: #64748B; font-size: 9.5px;">
                <?= !empty($sekolah['nip_kepala_sekolah']) ? 'NIP. ' . htmlspecialchars($sekolah['nip_kepala_sekolah']) : 'NIP. -' ?>
            </div>
        </div>

        <div class="sig-box">
            <div><?= htmlspecialchars($sekolah['titimangsa_tempat'] ?: 'Bojonegoro') ?>, <?= date('d F Y') ?></div>
            <div style="font-weight: 700; color: #0D7A87;">Guru Mata Pelajaran</div>
            <div class="sig-space"></div>
            <div class="sig-name"><?= htmlspecialchars($mapelInfo['nama_guru']) ?></div>
            <div style="color: #64748B; font-size: 9.5px;">NIP. <?= htmlspecialchars($mapelInfo['nip_guru'] ?: '-') ?></div>
        </div>
    </div>

</body>
</html>
<?php
$html = ob_get_clean();

// Mode preview HTML jika diminta
if (isset($_GET['preview']) && $_GET['preview'] === '1') {
    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
    exit;
}

// ==============================================================================
// MODE DOWNLOAD LANGSUNG SEBAGAI BERKAS .PDF (VIA HEADLESS BROWSER RENDERING)
// ==============================================================================
$bin = ilmuna_get_headless_browser_bin();
if ($bin) {
    $tempDir = __DIR__ . '/../../uploads';
    if (!is_dir($tempDir)) @mkdir($tempDir, 0777, true);
    $unique = 'reports_pdf_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    $htmlTmp = $tempDir . '/' . $unique . '.html';
    $pdfTmp  = $tempDir . '/' . $unique . '.pdf';

    file_put_contents($htmlTmp, $html);

    $cmd = '"' . $bin . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --no-pdf-header-footer --print-to-pdf="' . $pdfTmp . '" "file:///' . str_replace('\\', '/', $htmlTmp) . '"';
    exec($cmd, $o, $ret);

    if (file_exists($pdfTmp) && filesize($pdfTmp) > 0) {
        $filename = 'daftar-nilai-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $kelasInfo['nama_kelas']) . '-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $mapelInfo['nama_mata_pelajaran']) . '-' . date('Ymd-His') . '.pdf';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Access-Control-Expose-Headers: Content-Disposition');
        header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($pdfTmp));

        readfile($pdfTmp);
        @unlink($pdfTmp);
        @unlink($htmlTmp);
        exit;
    }
    @unlink($htmlTmp);
}

// Fallback HTML download jika headless tidak tersedia
$filename = 'daftar-nilai-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $kelasInfo['nama_kelas']) . '.html';
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: text/html; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo $html;
exit;
