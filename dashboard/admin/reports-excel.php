<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — EXPORT EXCEL LAPORAN & DAFTAR NILAI SISWA (.XLSX)
 * ==============================================================================
 * Menghasilkan Berkas Spreadsheet OpenXML (.xlsx) Resmi Standar Kurikulum Merdeka:
 *   - Header Judul Resmi & Metadata Kelas, Mapel, Guru Pengampu, Batas KKTP
 *   - Tabel Rekapitulasi Lengkap Nilai Tugas, Formatif, Sumatif (LM, STS, SAS)
 *   - Menampilkan Nilai Remedial & Nilai Asli
 *   - Perhitungan Nilai Akhir, Predikat (A/B/C/D/E), dan Status Kelulusan KKTP
 *
 * Menggunakan Arsitektur OpenXML Native ZipArchive & Pure-PHP Deflate (Persis kaldik-excel.php)
 * Serta Mendukung Pengunduhan via Metode JavaScript Blob.
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

/* ==============================================================================
 * HELPER: OPENXML XLSX UTILITIES & THEME ENGINE
 * ============================================================================== */

if (!function_exists('ilmuna_xlsx_col_letter')) {
    function ilmuna_xlsx_col_letter($idx) {
        $letter = '';
        $idx = (int)$idx + 1;
        while ($idx > 0) {
            $mod = ($idx - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $idx = intdiv($idx - $mod, 26);
        }
        return $letter;
    }
}

if (!class_exists('IlmunaZipArchive')) {
class IlmunaZipArchive {
    public const CREATE = 1;
    public const OVERWRITE = 8;

    private $nativeZip = null;
    private $filePath = '';
    private $files = [];
    private $zipData = null;

    public function __construct() {
        if (class_exists('ZipArchive')) {
            $this->nativeZip = new ZipArchive();
        }
    }

    public function open($filename, $flags = 0) {
        $this->filePath = $filename;
        if ($this->nativeZip) {
            $res = $this->nativeZip->open($filename, $flags);
            if ($res === true) {
                return true;
            }
        }

        if ($flags & (self::CREATE | self::OVERWRITE)) {
            $this->files = [];
            return true;
        }

        if (!file_exists($filename)) {
            return false;
        }
        $this->zipData = @file_get_contents($filename);
        return ($this->zipData !== false);
    }

    public function addFromString($localname, $contents) {
        if ($this->nativeZip) {
            return $this->nativeZip->addFromString($localname, $contents);
        }
        $this->files[$localname] = $contents;
        return true;
    }

    public function close() {
        if ($this->nativeZip) {
            $closed = $this->nativeZip->close();
            if ($closed) {
                return true;
            }
        }

        if (!empty($this->files)) {
            $binary = self::buildPureZipData($this->files);
            $bytes = @file_put_contents($this->filePath, $binary);
            return ($bytes !== false && $bytes > 0);
        }

        return true;
    }

    private static function buildPureZipData($files) {
        $time = time();
        $dostime = ((date('Y', $time) - 1980) << 25)
                 | (date('m', $time) << 21)
                 | (date('d', $time) << 16)
                 | (date('H', $time) << 11)
                 | (date('i', $time) << 5)
                 | (date('s', $time) >> 1);

        $entries = '';
        $cd = '';
        $offset = 0;

        foreach ($files as $name => $content) {
            $name = str_replace('\\', '/', $name);
            $nLen = strlen($name);
            $uSize = strlen($content);
            $crc = crc32($content);

            if (function_exists('gzdeflate')) {
                $cBody = gzdeflate($content);
                if (strlen($cBody) < $uSize) {
                    $method = 8;
                } else {
                    $cBody = $content;
                    $method = 0;
                }
            } else {
                $cBody = $content;
                $method = 0;
            }
            $cSize = strlen($cBody);

            $lh = pack('VvvvVVVVvv', 0x04034b50, 20, 0, $method, $dostime, $crc, $cSize, $uSize, $nLen, 0) . $name;
            $entries .= $lh . $cBody;

            $cdRecord = pack('VvvvvVVVVvvvvvVV', 0x02014b50, 20, 20, 0, $method, $dostime, $crc, $cSize, $uSize, $nLen, 0, 0, 0, 0, 0, $offset) . $name;
            $cd .= $cdRecord;

            $offset = strlen($entries);
        }

        $cdLen = strlen($cd);
        $eocd = pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), $cdLen, $offset, 0);

        return $entries . $cd . $eocd;
    }
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

// Jika data rekap belum digenerate/disimpan di DB, lakukan kalkulasi live (preview mode) agar excel tidak kosong!
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

// 8. Bangun Berkas OpenXML Spreadsheet
$strings = [];
$stringLookup = [];
$addString = function($str) use (&$strings, &$stringLookup) {
    $str = (string)$str;
    if (isset($stringLookup[$str])) {
        return $stringLookup[$str];
    }
    $idx = count($strings);
    $strings[] = $str;
    $stringLookup[$str] = $idx;
    return $idx;
};

// Styles XML untuk Excel
$stylesXml = '<?xml version="1.0" encoding="UTF-8"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <fonts count="7">
        <font><sz val="10"/><name val="Segoe UI"/></font>
        <font><b/><sz val="14"/><color rgb="FF1D3B5E"/><name val="Segoe UI"/></font>
        <font><b/><sz val="11"/><color rgb="FF0D7A87"/><name val="Segoe UI"/></font>
        <font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Segoe UI"/></font>
        <font><sz val="10"/><color rgb="FF0F172A"/><name val="Segoe UI"/></font>
        <font><b/><sz val="10"/><color rgb="FF047857"/><name val="Segoe UI"/></font>
        <font><b/><sz val="10"/><color rgb="FFB91C1C"/><name val="Segoe UI"/></font>
    </fonts>
    <fills count="7">
        <fill><patternFill patternType="none"/></fill>
        <fill><patternFill patternType="gray125"/></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FF1D3B5E"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FF0D7A87"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFEFF6FF"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFECFDF5"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFFFFBEB"/></patternFill></fill>
    </fills>
    <borders count="2">
        <border><left/><right/><top/><bottom/><diagonal/></border>
        <border>
            <left style="thin"><color rgb="FFCBD5E1"/></left>
            <right style="thin"><color rgb="FFCBD5E1"/></right>
            <top style="thin"><color rgb="FFCBD5E1"/></top>
            <bottom style="thin"><color rgb="FFCBD5E1"/></bottom>
        </border>
    </borders>
    <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
    <cellXfs count="9">
        <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
        <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>
        <xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>
        <xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
        <xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
        <xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
        <xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>
        <xf numFmtId="0" fontId="5" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
        <xf numFmtId="0" fontId="6" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    </cellXfs>
</styleSheet>';

// Bangun sheet1.xml
$sheetRows = [];
$rNum = 1;

// Baris 1: Judul
$sheetRows[] = '<row r="1" ht="25"><c r="A1" s="1" t="s"><v>' . $addString('ILMUNA LEARN — DAFTAR NILAI & HASIL BELAJAR SISWA') . '</v></c></row>';
// Baris 2: Subjudul
$sheetRows[] = '<row r="2" ht="20"><c r="A2" s="2" t="s"><v>' . $addString('Rekapitulasi Tugas, Formatif, Sumatif (Kurikulum Merdeka) — Kelas: ' . $kelasInfo['nama_kelas'] . ' | Mapel: ' . $mapelInfo['nama_mata_pelajaran']) . '</v></c></row>';
// Baris 3: Info KKTP & Guru
$sheetRows[] = '<row r="3" ht="18"><c r="A3" s="0" t="s"><v>' . $addString('Tahun Pelajaran: ' . ($kelasInfo['tahun_pelajaran'] ?: '2026/2027') . ' | Batas KKTP Acuan Database: ' . number_format($kktpAcuan, 0) . '.00 | Guru Pengampu: ' . $mapelInfo['nama_guru']) . '</v></c></row>';
$sheetRows[] = '<row r="4" ht="10"></row>';

// Baris 5: Header Tabel (Baris 1)
$totalCols = 4 + count($tugasCols) + count($formatifCols) + count($sumatifCols) + 2 + 2 + 3;
$lastColLetter = ilmuna_xlsx_col_letter($totalCols - 1);

$mergeCells = [
    'A1:' . $lastColLetter . '1',
    'A2:' . $lastColLetter . '2',
    'A3:' . $lastColLetter . '3',
    'A5:A6', 'B5:B6', 'C5:C6', 'D5:D6'
];

$hRow1 = '<row r="5" ht="24">';
$hRow1 .= '<c r="A5" s="3" t="s"><v>' . $addString('#') . '</v></c>';
$hRow1 .= '<c r="B5" s="3" t="s"><v>' . $addString('NIS') . '</v></c>';
$hRow1 .= '<c r="C5" s="3" t="s"><v>' . $addString('NISN') . '</v></c>';
$hRow1 .= '<c r="D5" s="3" t="s"><v>' . $addString('Nama Lengkap Siswa') . '</v></c>';

// Kolom Tugas
$colIdx = 4;
$tugasStart = ilmuna_xlsx_col_letter($colIdx);
foreach ($tugasCols as $tc) {
    $colLetter = ilmuna_xlsx_col_letter($colIdx++);
    $hRow1 .= '<c r="' . $colLetter . '5" s="4" t="s"><v>' . $addString('Tugas & Proyek') . '</v></c>';
}
$tugasEnd = ilmuna_xlsx_col_letter($colIdx - 1);
if (count($tugasCols) > 1) {
    $mergeCells[] = $tugasStart . '5:' . $tugasEnd . '5';
}

// Kolom Formatif
$fmtStart = ilmuna_xlsx_col_letter($colIdx);
foreach ($formatifCols as $fc) {
    $colLetter = ilmuna_xlsx_col_letter($colIdx++);
    $hRow1 .= '<c r="' . $colLetter . '5" s="4" t="s"><v>' . $addString('Asesmen Formatif') . '</v></c>';
}
$fmtEnd = ilmuna_xlsx_col_letter($colIdx - 1);
if (count($formatifCols) > 1) {
    $mergeCells[] = $fmtStart . '5:' . $fmtEnd . '5';
}

// Kolom Sumatif
$smStart = ilmuna_xlsx_col_letter($colIdx);
foreach ($sumatifCols as $sc) {
    $colLetter = ilmuna_xlsx_col_letter($colIdx++);
    $hRow1 .= '<c r="' . $colLetter . '5" s="4" t="s"><v>' . $addString('Asesmen Sumatif') . '</v></c>';
}
$smEnd = ilmuna_xlsx_col_letter($colIdx - 1);
if (count($sumatifCols) > 1) {
    $mergeCells[] = $smStart . '5:' . $smEnd . '5';
}

// Kolom Observasi Sikap (Afektif 40%)
$afkStart = ilmuna_xlsx_col_letter($colIdx);
$colLetterAfk1 = ilmuna_xlsx_col_letter($colIdx++);
$colLetterAfk2 = ilmuna_xlsx_col_letter($colIdx++);
$hRow1 .= '<c r="' . $colLetterAfk1 . '5" s="4" t="s"><v>' . $addString('Observasi Sikap (40%)') . '</v></c>';
$hRow1 .= '<c r="' . $colLetterAfk2 . '5" s="4" t="s"><v>' . $addString('Observasi Sikap (40%)') . '</v></c>';
$afkEnd = ilmuna_xlsx_col_letter($colIdx - 1);
$mergeCells[] = $afkStart . '5:' . $afkEnd . '5';

// Kolom Unjuk Kerja (Psikomotorik 10%)
$pskStart = ilmuna_xlsx_col_letter($colIdx);
$colLetterPsk1 = ilmuna_xlsx_col_letter($colIdx++);
$colLetterPsk2 = ilmuna_xlsx_col_letter($colIdx++);
$hRow1 .= '<c r="' . $colLetterPsk1 . '5" s="4" t="s"><v>' . $addString('Unjuk Kerja (10%)') . '</v></c>';
$hRow1 .= '<c r="' . $colLetterPsk2 . '5" s="4" t="s"><v>' . $addString('Unjuk Kerja (10%)') . '</v></c>';
$pskEnd = ilmuna_xlsx_col_letter($colIdx - 1);
$mergeCells[] = $pskStart . '5:' . $pskEnd . '5';

$cFinal = ilmuna_xlsx_col_letter($colIdx++);
$cPred  = ilmuna_xlsx_col_letter($colIdx++);
$cStat  = ilmuna_xlsx_col_letter($colIdx++);

$hRow1 .= '<c r="' . $cFinal . '5" s="3" t="s"><v>' . $addString('Nilai Akhir') . '</v></c>';
$hRow1 .= '<c r="' . $cPred . '5" s="3" t="s"><v>' . $addString('Predikat') . '</v></c>';
$hRow1 .= '<c r="' . $cStat . '5" s="3" t="s"><v>' . $addString('Status KKTP') . '</v></c>';
$hRow1 .= '</row>';
$sheetRows[] = $hRow1;

$mergeCells[] = $cFinal . '5:' . $cFinal . '6';
$mergeCells[] = $cPred . '5:' . $cPred . '6';
$mergeCells[] = $cStat . '5:' . $cStat . '6';

// Baris 6: Subheader (Baris 2)
$hRow2 = '<row r="6" ht="20">';
$hRow2 .= '<c r="A6" s="3"/>';
$hRow2 .= '<c r="B6" s="3"/>';
$hRow2 .= '<c r="C6" s="3"/>';
$hRow2 .= '<c r="D6" s="3"/>';

$colIdx = 4;
foreach ($tugasCols as $tc) {
    $colLetter = ilmuna_xlsx_col_letter($colIdx++);
    $hRow2 .= '<c r="' . $colLetter . '6" s="4" t="s"><v>' . $addString($tc['slot_code']) . '</v></c>';
}
foreach ($formatifCols as $fc) {
    $colLetter = ilmuna_xlsx_col_letter($colIdx++);
    $hRow2 .= '<c r="' . $colLetter . '6" s="4" t="s"><v>' . $addString($fc['slot_code']) . '</v></c>';
}
foreach ($sumatifCols as $sc) {
    $colLetter = ilmuna_xlsx_col_letter($colIdx++);
    $hRow2 .= '<c r="' . $colLetter . '6" s="4" t="s"><v>' . $addString($sc['slot_code']) . '</v></c>';
}
// Subheader Afektif
$hRow2 .= '<c r="' . ilmuna_xlsx_col_letter($colIdx++) . '6" s="4" t="s"><v>' . $addString('Skor') . '</v></c>';
$hRow2 .= '<c r="' . ilmuna_xlsx_col_letter($colIdx++) . '6" s="4" t="s"><v>' . $addString('Pred') . '</v></c>';

// Subheader Psikomotorik
$hRow2 .= '<c r="' . ilmuna_xlsx_col_letter($colIdx++) . '6" s="4" t="s"><v>' . $addString('Skor') . '</v></c>';
$hRow2 .= '<c r="' . ilmuna_xlsx_col_letter($colIdx++) . '6" s="4" t="s"><v>' . $addString('Pred') . '</v></c>';

$hRow2 .= '<c r="' . $cFinal . '6" s="3"/>';
$hRow2 .= '<c r="' . $cPred . '6" s="3"/>';
$hRow2 .= '<c r="' . $cStat . '6" s="3"/>';
$hRow2 .= '</row>';
$sheetRows[] = $hRow2;

// Baris Data Siswa
$rNum = 7;
$no = 1;

foreach ($siswaList as $s) {
    $sId = $s['id'];
    $scores = [];

    $dRow = '<row r="' . $rNum . '" ht="20">';
    $dRow .= '<c r="A' . $rNum . '" s="5"><v>' . ($no++) . '</v></c>';
    $dRow .= '<c r="B' . $rNum . '" s="5" t="s"><v>' . $addString($s['nis'] ?: '-') . '</v></c>';
    $dRow .= '<c r="C' . $rNum . '" s="5" t="s"><v>' . $addString($s['nisn'] ?: '-') . '</v></c>';
    $dRow .= '<c r="D' . $rNum . '" s="6" t="s"><v>' . $addString($s['nama_lengkap']) . '</v></c>';

    $colIdx = 4;
    // Tugas
    foreach ($tugasCols as $tc) {
        $colLetter = ilmuna_xlsx_col_letter($colIdx++);
        $g = $gradesTugas[$sId][$tc['id']] ?? null;
        $asli = $g ? $g['nilai'] : null;
        $remed = $g ? $g['nilai_remedial'] : null;
        $eff = ($remed !== null) ? $remed : $asli;
        if ($eff !== null && $eff !== '') $scores[] = (float)$eff;

        if ($eff !== null) {
            $valStr = ($remed !== null) ? "{$asli} [Rem: {$remed}]" : (string)$asli;
            $dRow .= '<c r="' . $colLetter . $rNum . '" s="5" t="s"><v>' . $addString($valStr) . '</v></c>';
        } else {
            $dRow .= '<c r="' . $colLetter . $rNum . '" s="5" t="s"><v>' . $addString('-') . '</v></c>';
        }
    }

    // Formatif
    foreach ($formatifCols as $fc) {
        $colLetter = ilmuna_xlsx_col_letter($colIdx++);
        $g = $gradesFormatif[$sId][$fc['id']] ?? null;
        $asli = $g ? $g['nilai_angka'] : null;
        $remed = $g ? $g['nilai_remedial'] : null;
        $eff = ($remed !== null) ? $remed : $asli;
        if ($eff !== null && $eff !== '') $scores[] = (float)$eff;

        if ($eff !== null) {
            $valStr = ($remed !== null) ? "{$asli} [Rem: {$remed}]" : (string)$asli;
            $dRow .= '<c r="' . $colLetter . $rNum . '" s="5" t="s"><v>' . $addString($valStr) . '</v></c>';
        } else {
            $dRow .= '<c r="' . $colLetter . $rNum . '" s="5" t="s"><v>' . $addString('-') . '</v></c>';
        }
    }

    // Sumatif
    foreach ($sumatifCols as $sc) {
        $colLetter = ilmuna_xlsx_col_letter($colIdx++);
        $g = $gradesSumatif[$sId][$sc['id']] ?? null;
        $asli = $g ? $g['nilai_angka'] : null;
        $remed = $g ? $g['nilai_remedial'] : null;
        $eff = ($remed !== null) ? $remed : $asli;
        if ($eff !== null && $eff !== '') $scores[] = (float)$eff;

        if ($eff !== null) {
            $valStr = ($remed !== null) ? "{$asli} [Rem: {$remed}]" : (string)$asli;
            $dRow .= '<c r="' . $colLetter . $rNum . '" s="5" t="s"><v>' . $addString($valStr) . '</v></c>';
        } else {
            $dRow .= '<c r="' . $colLetter . $rNum . '" s="5" t="s"><v>' . $addString('-') . '</v></c>';
        }
    }

    // Observasi Sikap (Afektif 40%)
    $rk = $rekapMap[$sId] ?? null;
    $rAfk = ($rk && $rk['rata_afektif'] !== null) ? (float)$rk['rata_afektif'] : null;
    $pAfk = ($rk && !empty($rk['predikat_afektif'])) ? $rk['predikat_afektif'] : '-';

    $colAfkSkor = ilmuna_xlsx_col_letter($colIdx++);
    $colAfkPred = ilmuna_xlsx_col_letter($colIdx++);
    $dRow .= '<c r="' . $colAfkSkor . $rNum . '" s="5" t="s"><v>' . $addString(($rAfk !== null && $rAfk > 0) ? number_format($rAfk, 1) : '-') . '</v></c>';
    $dRow .= '<c r="' . $colAfkPred . $rNum . '" s="5" t="s"><v>' . $addString($pAfk) . '</v></c>';

    // Unjuk Kerja (Psikomotorik 10%)
    $rPsk = ($rk && $rk['rata_psikomotorik'] !== null) ? (float)$rk['rata_psikomotorik'] : null;
    $pPsk = ($rk && !empty($rk['predikat_psikomotorik'])) ? $rk['predikat_psikomotorik'] : '-';

    $colPskSkor = ilmuna_xlsx_col_letter($colIdx++);
    $colPskPred = ilmuna_xlsx_col_letter($colIdx++);
    $dRow .= '<c r="' . $colPskSkor . $rNum . '" s="5" t="s"><v>' . $addString(($rPsk !== null && $rPsk > 0) ? number_format($rPsk, 1) : '-') . '</v></c>';
    $dRow .= '<c r="' . $colPskPred . $rNum . '" s="5" t="s"><v>' . $addString($pPsk) . '</v></c>';

    // Final, Predikat, Status KKTP
    $naFinal = ($rk && $rk['nilai_akhir_semester'] !== null) ? (float)$rk['nilai_akhir_semester'] : null;
    $tolVal  = ($rk && $rk['nilai_toleransi'] !== null) ? (float)$rk['nilai_toleransi'] : 0.00;
    $predAkhir = ($rk && !empty($rk['predikat_akhir'])) ? $rk['predikat_akhir'] : '-';
    $statKktp  = ($rk && !empty($rk['status_kelulusan_kktp'])) ? $rk['status_kelulusan_kktp'] : (($naFinal !== null && $naFinal >= $kktpAcuan) ? 'Tercapai' : 'Belum Tercapai');

    $isTerbantu = ($tolVal > 0);
    $naMurni = $isTerbantu ? max(0.0, round($naFinal - $tolVal, 2)) : $naFinal;
    $tolPct  = ($isTerbantu && $kktpAcuan > 0) ? round(($tolVal / $kktpAcuan) * 100, 1) : 0.0;

    if ($naFinal !== null && $naFinal > 0) {
        if ($isTerbantu) {
            $strNa = number_format($naFinal, 1) . " (Asli: " . number_format($naMurni, 1) . " + " . $tolPct . "%)";
        } else {
            $strNa = number_format($naFinal, 1);
        }
    } else {
        $strNa = '-';
    }

    $dRow .= '<c r="' . $cFinal . $rNum . '" s="5" t="s"><v>' . $addString($strNa) . '</v></c>';
    $dRow .= '<c r="' . $cPred . $rNum . '" s="5" t="s"><v>' . $addString($predAkhir) . '</v></c>';
    $sStyle = ($statKktp === 'Tercapai') ? '7' : '8';
    $dRow .= '<c r="' . $cStat . $rNum . '" s="' . $sStyle . '" t="s"><v>' . $addString($statKktp) . '</v></c>';

    $dRow .= '</row>';
    $sheetRows[] = $dRow;
    $rNum++;
}

$mergeXml = '<mergeCells count="' . count($mergeCells) . '">';
foreach ($mergeCells as $mc) {
    $mergeXml .= '<mergeCell ref="' . $mc . '"/>';
}
$mergeXml .= '</mergeCells>';

$sheetXml = '<?xml version="1.0" encoding="UTF-8"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <cols>
        <col min="1" max="1" width="6" customWidth="1"/>
        <col min="2" max="2" width="14" customWidth="1"/>
        <col min="3" max="3" width="16" customWidth="1"/>
        <col min="4" max="4" width="30" customWidth="1"/>
        <col min="5" max="' . $totalCols . '" width="12" customWidth="1"/>
    </cols>
    <sheetData>' . implode('', $sheetRows) . '</sheetData>' .
    $mergeXml . '
</worksheet>';

// Shared Strings XML
$sstXml = '<?xml version="1.0" encoding="UTF-8"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($strings) . '" uniqueCount="' . count($strings) . '">';
foreach ($strings as $s) {
    $clean = htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $sstXml .= '<si><t>' . $clean . '</t></si>';
}
$sstXml .= '</sst>';

// Buat berkas ZIP / XLSX
$tmpDir = __DIR__ . '/../../uploads';
if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);
$tmpFile = $tmpDir . '/reports_xlsx_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.xlsx';

$zip = new IlmunaZipArchive();
if (!$zip->open($tmpFile, IlmunaZipArchive::CREATE | IlmunaZipArchive::OVERWRITE)) {
    http_response_code(500);
    die('Gagal membuat berkas sementara Excel.');
}

$zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
    <Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
</Types>');

$zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>');

$zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
    <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
</Relationships>');

$zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="Daftar Nilai Siswa" sheetId="1" r:id="rId1"/>
    </sheets>
</workbook>');

$zip->addFromString('xl/styles.xml', $stylesXml);
$zip->addFromString('xl/sharedStrings.xml', $sstXml);
$zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);

$zip->close();

if (!file_exists($tmpFile) || filesize($tmpFile) === 0) {
    http_response_code(500);
    die('Berkas Excel gagal dibangun.');
}

// Kirim berkas untuk diunduh langsung via Blob
$filename = 'daftar-nilai-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $kelasInfo['nama_kelas']) . '-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $mapelInfo['nama_mata_pelajaran']) . '-' . date('Ymd-His') . '.xlsx';

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Access-Control-Expose-Headers: Content-Disposition');
header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($tmpFile));

readfile($tmpFile);
@unlink($tmpFile);
exit;
