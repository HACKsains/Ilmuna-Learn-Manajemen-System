<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — MODUL AJAR KURIKULUM MERDEKA (CP -> TP/ATP/ITP -> MODUL AJAR)
 * ==============================================================================
 * Portal Dewan Guru & Administrator Kurikulum Merdeka
 * Alur Terintegrasi:
 *   1. Capaian Pembelajaran (CP) & Elemen (dari Database + Sinkronisasi Rombel)
 *   2. Tujuan Pembelajaran (TP), Alur (ATP) & Indikator Ketercapaian (ITP)
 *   3. Modul Ajar Digital:
 *      - Grid Mata Pelajaran & Rombel Kelas dengan Progress Bar
 *      - Daftar Pertemuan + Smart Readiness Guidance (CP / TP)
 *      - Wizard 6 Langkah Pembuatan Modul Ajar (Multi-Kelas + Auto-Sintaks Model)
 *      - Detail & Editor Lengkap + Publish/Unpublish + Keterlaksanaan + Finalisasi
 *      - Cetak / Unduh PDF A4 Resmi (Modul Ajar & Laporan Keterlaksanaan)
 * ==============================================================================
 */

date_default_timezone_set('Asia/Jakarta');

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';

requireRole(['guru', 'admin']);

// Hormati active_role dari session agar Admin tetap memakai sidebar Admin saat membuka Modul Ajar
$sessionRole = $_SESSION['active_role'] ?? '';
if ($sessionRole === 'admin' && hasRole('admin')) {
    $activeRole = 'admin';
} elseif ($sessionRole === 'guru' && hasRole('guru')) {
    $activeRole = 'guru';
} else {
    $activeRole = hasRole('guru') ? 'guru' : 'admin';
}
$GLOBALS['activeRole'] = $activeRole;

$pdo = getDBConnection();
$pdo->exec("SET time_zone = '+07:00'");
$currentUser = getCurrentUser();
$isAdmin = hasRole('admin');
$idUserLogged = (int)($currentUser['id'] ?? 0);

// Identifikasi profil guru pengampu
$currentGuru = null;
$idGuruLogged = 0;
$namaGuruLogged = $currentUser['full_name'] ?: 'Dewan Guru';
try {
    $gStmt = $pdo->prepare("SELECT * FROM guru WHERE id_user = ? LIMIT 1");
    $gStmt->execute([$idUserLogged]);
    $currentGuru = $gStmt->fetch(PDO::FETCH_ASSOC);
    if (!$currentGuru && !empty($currentUser['email'])) {
        $gStmt2 = $pdo->prepare("SELECT * FROM guru WHERE email = ? LIMIT 1");
        $gStmt2->execute([$currentUser['email']]);
        $currentGuru = $gStmt2->fetch(PDO::FETCH_ASSOC);
    }
    if ($currentGuru) {
        $idGuruLogged = (int)$currentGuru['id'];
        $namaGuruLogged = $currentGuru['nama_guru'] ?: $namaGuruLogged;
    }
} catch (Exception $e) {}

// Identitas sekolah untuk dokumen cetak resmi
$identitasSekolah = [];
try {
    $stmtSekolah = $pdo->query("SELECT * FROM identitas_sekolah LIMIT 1");
    $identitasSekolah = $stmtSekolah->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

/* ==============================================================================
 * HELPER: AUTO-MIGRASI KOLOM EKSTENSI MODUL AJAR (IDEMPOTENT & AMAN PHP 7.4)
 * ============================================================================== */
function ensureModulAjarSchema($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $cols = $pdo->query("SHOW COLUMNS FROM modul_ajar")->fetchAll(PDO::FETCH_COLUMN);
        $additions = [
            'tahun_pelajaran'        => "VARCHAR(30) DEFAULT '2026/2027'",
            'semester'               => "VARCHAR(20) DEFAULT 'Ganjil'",
            'tanggal'                => "DATE DEFAULT NULL",
            'jam_mulai'              => "VARCHAR(10) DEFAULT NULL",
            'jam_selesai'            => "VARCHAR(10) DEFAULT NULL",
            'alokasi_per_jp'         => "INT DEFAULT 45",
            'jumlah_jp'              => "INT DEFAULT 2",
            'durasi_pembukaan'       => "INT DEFAULT 15",
            'durasi_inti'            => "INT DEFAULT 60",
            'durasi_penutup'         => "INT DEFAULT 15",
            'persiapan_pembelajaran' => "TEXT DEFAULT NULL",
            'refleksi_guru'          => "TEXT DEFAULT NULL",
            'refleksi_siswa'         => "TEXT DEFAULT NULL",
            'cp_ids_json'            => "TEXT DEFAULT NULL",
            'tp_ids_json'            => "TEXT DEFAULT NULL",
            'tp_custom'              => "TEXT DEFAULT NULL",
            'itp_custom'             => "TEXT DEFAULT NULL",
            'atp_custom'             => "TEXT DEFAULT NULL",
            'model_ids_json'         => "TEXT DEFAULT NULL",
            'metode_ids_json'        => "TEXT DEFAULT NULL",
            'media_pembelajaran'     => "TEXT DEFAULT NULL",
            'sumber_belajar'         => "TEXT DEFAULT NULL",
            'status_pembukaan'       => "VARCHAR(20) DEFAULT 'belum'",
            'status_inti'            => "VARCHAR(20) DEFAULT 'belum'",
            'status_penutup'         => "VARCHAR(20) DEFAULT 'belum'",
            'catatan_pembukaan'      => "TEXT DEFAULT NULL",
            'catatan_inti'           => "TEXT DEFAULT NULL",
            'catatan_penutup'        => "TEXT DEFAULT NULL",
            'meeting_status'         => "VARCHAR(20) DEFAULT 'berjalan'",
            'lampiran_json'          => "LONGTEXT DEFAULT NULL",
        ];
        foreach ($additions as $colName => $colDef) {
            if (!in_array($colName, $cols, true)) {
                try {
                    $pdo->exec("ALTER TABLE `modul_ajar` ADD COLUMN `{$colName}` {$colDef}");
                } catch (Exception $eCol) {}
            }
        }
    } catch (Exception $e) {}
}

ensureModulAjarSchema($pdo);

/* ==============================================================================
 * HELPERS: FASE, GRADE, TANGGAL INDO, JSON, UPLOAD, ACCESS CONTROL
 * ============================================================================== */
function detectFaseFromKelas($namaKelas) {
    $k = strtoupper(trim((string)$namaKelas));
    if (preg_match('/\b(XII|XI|12|11)\b/', $k)) return 'Fase F';
    if (preg_match('/\b(X|10)\b/', $k)) return 'Fase E';
    if (preg_match('/\b(IX|VIII|VII|9|8|7)\b/', $k)) return 'Fase D';
    if (preg_match('/\b(VI|V|6|5)\b/', $k)) return 'Fase C';
    if (preg_match('/\b(IV|III|4|3)\b/', $k)) return 'Fase B';
    return 'Fase E';
}

function detectGradeLabel($namaKelas) {
    $k = strtoupper(trim((string)$namaKelas));
    if (preg_match('/\b(XII|12)\b/', $k)) return 'XII';
    if (preg_match('/\b(XI|11)\b/', $k)) return 'XI';
    if (preg_match('/\b(X|10)\b/', $k)) return 'X';
    if (preg_match('/\b(IX|9)\b/', $k)) return 'IX';
    if (preg_match('/\b(VIII|8)\b/', $k)) return 'VIII';
    if (preg_match('/\b(VII|7)\b/', $k)) return 'VII';
    return 'Lainnya';
}

function formatTanggalIndoMA($dateStr) {
    if (!$dateStr) return '-';
    $ts = strtotime($dateStr);
    if (!$ts) return $dateStr;
    $bulan = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    return date('d', $ts) . ' ' . ($bulan[(int)date('n', $ts)] ?? '') . ' ' . date('Y', $ts);
}

function parseJsonIdArray($raw, $fallbackSingleId = null) {
    $out = [];
    if (is_array($raw)) {
        $arr = $raw;
    } elseif (is_string($raw) && trim($raw) !== '') {
        $dec = json_decode($raw, true);
        if (is_array($dec)) {
            $arr = $dec;
        } else {
            $arr = explode(',', $raw);
        }
    } else {
        $arr = [];
    }
    foreach ($arr as $v) {
        $iv = (int)$v;
        if ($iv > 0 && !in_array($iv, $out, true)) {
            $out[] = $iv;
        }
    }
    if (empty($out) && $fallbackSingleId && (int)$fallbackSingleId > 0) {
        $out[] = (int)$fallbackSingleId;
    }
    return $out;
}

function parseLampiranList($row) {
    $items = [];
    if (!empty($row['lampiran_json'])) {
        $dec = json_decode($row['lampiran_json'], true);
        if (is_array($dec)) {
            foreach ($dec as $f) {
                if (!empty($f['file_path'])) {
                    $items[] = $f;
                }
            }
        }
    }
    if (empty($items) && !empty($row['file_lampiran'])) {
        $fp = (string)$row['file_lampiran'];
        $items[] = [
            'id'          => 'legacy_' . md5($fp),
            'file_name'   => basename($fp),
            'file_path'   => $fp,
            'file_size'   => 0,
            'uploaded_at' => $row['created_at'] ?? date('Y-m-d H:i:s'),
        ];
    }
    return $items;
}

function getAccessibleKmpRows($pdo, $isAdmin, $activeRole, $idGuruLogged) {
    $baseSql = "
        SELECT kmp.id AS id_kmp,
               kmp.id_kelas,
               kmp.id_mata_pelajaran,
               kmp.id_guru_mata_pelajaran,
               kmp.kode_kelas_mapel,
               k.nama_kelas,
               k.kode_kelas,
               k.tahun_pelajaran AS kelas_tahun,
               mp.nama_mata_pelajaran,
               mp.kode_mapel,
               mp.kktp,
               mp.tahun_pelajaran AS mapel_tahun,
               mp.semester AS mapel_semester,
               COALESCE(g.id, 0) AS id_guru,
               COALESCE(g.nama_guru, 'Belum Ditentukan') AS nama_guru,
               COALESCE(g.nip, '') AS nip_guru
        FROM kelas_mata_pelajaran kmp
        JOIN kelas k ON kmp.id_kelas = k.id
        JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
        LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
        LEFT JOIN guru g ON gmp.id_guru = g.id
    ";

    $rows = [];
    if ($activeRole === 'guru' && $idGuruLogged > 0) {
        $stmt = $pdo->prepare($baseSql . " WHERE gmp.id_guru = ? ORDER BY mp.nama_mata_pelajaran ASC, k.nama_kelas ASC");
        $stmt->execute([$idGuruLogged]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Jika user juga admin dan tidak punya relasi guru_mapel, tampilkan semua agar tidak kosong
        if (empty($rows) && $isAdmin) {
            $rows = $pdo->query($baseSql . " ORDER BY mp.nama_mata_pelajaran ASC, k.nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        $rows = $pdo->query($baseSql . " ORDER BY mp.nama_mata_pelajaran ASC, k.nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
    }

    foreach ($rows as &$r) {
        $r['id_kmp'] = (int)$r['id_kmp'];
        $r['id_kelas'] = (int)$r['id_kelas'];
        $r['id_mata_pelajaran'] = (int)$r['id_mata_pelajaran'];
        $r['id_guru'] = (int)$r['id_guru'];
        $r['fase'] = detectFaseFromKelas($r['nama_kelas']);
        $r['grade_label'] = detectGradeLabel($r['nama_kelas']);
        $r['tahun_pelajaran'] = $r['kelas_tahun'] ?: ($r['mapel_tahun'] ?: '2026/2027');
    }
    unset($r);
    return $rows;
}

function getAllowedKmpIdSet($pdo, $isAdmin, $activeRole, $idGuruLogged) {
    $rows = getAccessibleKmpRows($pdo, $isAdmin, $activeRole, $idGuruLogged);
    $ids = [];
    foreach ($rows as $r) {
        $ids[] = (int)$r['id_kmp'];
    }
    return $ids;
}

function handleModulFileUpload($fileInfo) {
    if (empty($fileInfo) || !isset($fileInfo['error']) || $fileInfo['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($fileInfo['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Gagal mengunggah berkas lampiran (kode error: ' . $fileInfo['error'] . ').');
    }
    $maxBytes = 15 * 1024 * 1024; // 15 MB
    if ((int)$fileInfo['size'] > $maxBytes) {
        throw new Exception('Ukuran berkas lampiran maksimal 15 MB.');
    }
    $origName = basename((string)$fileInfo['name']);
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowed = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'webp', 'zip', 'rar'];
    if (!in_array($ext, $allowed, true)) {
        throw new Exception('Format berkas .' . $ext . ' tidak didukung. Gunakan PDF, Office, gambar, atau ZIP.');
    }

    $uploadDir = BASE_PATH . '/uploads/modul_ajar';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    $safeBase = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($origName, PATHINFO_FILENAME));
    $safeBase = substr($safeBase, 0, 60) ?: 'lampiran';
    $newFilename = date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '_' . $safeBase . '.' . $ext;
    $destPath = $uploadDir . '/' . $newFilename;

    if (!move_uploaded_file($fileInfo['tmp_name'], $destPath)) {
        throw new Exception('Gagal menyimpan berkas lampiran ke direktori server.');
    }

    return [
        'id'          => 'att_' . bin2hex(random_bytes(5)),
        'file_name'   => $origName,
        'file_path'   => 'uploads/modul_ajar/' . $newFilename,
        'file_size'   => (int)$fileInfo['size'],
        'uploaded_at' => date('Y-m-d H:i:s'),
    ];
}

function normalizeModulRow($pdo, $row) {
    $cpIds = parseJsonIdArray($row['cp_ids_json'] ?? null, $row['id_cp'] ?? null);
    $tpIds = parseJsonIdArray($row['tp_ids_json'] ?? null, $row['id_tp'] ?? null);
    $modelIds = parseJsonIdArray($row['model_ids_json'] ?? null, $row['id_model_pembelajaran'] ?? null);
    $metodeIds = parseJsonIdArray($row['metode_ids_json'] ?? null, $row['id_metode_pembelajaran'] ?? null);

    $alokasiPerJp = (int)($row['alokasi_per_jp'] ?? 0);
    $jumlahJp = (int)($row['jumlah_jp'] ?? 0);
    if ($alokasiPerJp <= 0 || $jumlahJp <= 0) {
        if (!empty($row['alokasi_waktu']) && preg_match('/(\d+)\s*[xX×]\s*(\d+)/', $row['alokasi_waktu'], $m)) {
            $jumlahJp = (int)$m[1];
            $alokasiPerJp = (int)$m[2];
        } else {
            $jumlahJp = $jumlahJp > 0 ? $jumlahJp : 2;
            $alokasiPerJp = $alokasiPerJp > 0 ? $alokasiPerJp : 45;
        }
    }
    $totalMenit = $alokasiPerJp * $jumlahJp;
    $durPembukaan = isset($row['durasi_pembukaan']) && (int)$row['durasi_pembukaan'] > 0 ? (int)$row['durasi_pembukaan'] : (int)round($totalMenit * 0.15);
    $durInti = isset($row['durasi_inti']) && (int)$row['durasi_inti'] > 0 ? (int)$row['durasi_inti'] : (int)round($totalMenit * 0.70);
    $durPenutup = isset($row['durasi_penutup']) && (int)$row['durasi_penutup'] > 0 ? (int)$row['durasi_penutup'] : max(5, $totalMenit - $durPembukaan - $durInti);

    $refleksiGuru = trim((string)($row['refleksi_guru'] ?? ''));
    $refleksiSiswa = trim((string)($row['refleksi_siswa'] ?? ''));
    if ($refleksiGuru === '' && $refleksiSiswa === '' && !empty($row['refleksi_guru_siswa'])) {
        $refleksiGuru = (string)$row['refleksi_guru_siswa'];
    }

    $mediaStr = trim((string)($row['media_pembelajaran'] ?? ''));
    if ($mediaStr === '' && !empty($row['sarana_prasarana'])) {
        $mediaStr = (string)$row['sarana_prasarana'];
    }
    $sumberStr = trim((string)($row['sumber_belajar'] ?? ''));
    if ($sumberStr === '' && !empty($row['daftar_pustaka'])) {
        $sumberStr = (string)$row['daftar_pustaka'];
    }

    $row['id'] = (int)$row['id'];
    $row['id_kelas_mata_pelajaran'] = (int)$row['id_kelas_mata_pelajaran'];
    $row['pertemuan_ke'] = (int)$row['pertemuan_ke'];
    $row['cp_ids'] = $cpIds;
    $row['tp_ids'] = $tpIds;
    $row['model_ids'] = $modelIds;
    $row['metode_ids'] = $metodeIds;
    $row['alokasi_per_jp'] = $alokasiPerJp;
    $row['jumlah_jp'] = $jumlahJp;
    $row['total_menit'] = $totalMenit;
    $row['durasi_pembukaan'] = $durPembukaan;
    $row['durasi_inti'] = $durInti;
    $row['durasi_penutup'] = $durPenutup;
    $row['refleksi_guru'] = $refleksiGuru;
    $row['refleksi_siswa'] = $refleksiSiswa;
    $row['media_pembelajaran'] = $mediaStr;
    $row['sumber_belajar'] = $sumberStr;
    $row['status_pembukaan'] = $row['status_pembukaan'] ?: 'belum';
    $row['status_inti'] = $row['status_inti'] ?: 'belum';
    $row['status_penutup'] = $row['status_penutup'] ?: 'belum';
    $row['meeting_status'] = $row['meeting_status'] ?: 'berjalan';
    $row['tahun_pelajaran'] = $row['tahun_pelajaran'] ?: '2026/2027';
    $row['semester'] = $row['semester'] ?: 'Ganjil';
    $row['lampiran'] = parseLampiranList($row);

    return $row;
}

/* ==============================================================================
 * DIRECT PRINT ROUTES: PRINT MODUL AJAR & LAPORAN KETERLAKSANAAN (A4 RESMI)
 * ============================================================================== */
if (isset($_GET['action']) && in_array($_GET['action'], ['print_modul', 'print_laporan'], true) && empty($_GET['api'])) {
    $printAction = $_GET['action'];
    $modulId = (int)($_GET['id'] ?? 0);

    $stmt = $pdo->prepare("
        SELECT ma.*, k.nama_kelas, mp.nama_mata_pelajaran, mp.kode_mapel,
               COALESCE(g.nama_guru, 'Dewan Guru') AS nama_guru_pengampu,
               COALESCE(g.nip, '') AS nip_guru_pengampu
        FROM modul_ajar ma
        JOIN kelas_mata_pelajaran kmp ON ma.id_kelas_mata_pelajaran = kmp.id
        JOIN kelas k ON kmp.id_kelas = k.id
        JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
        LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
        LEFT JOIN guru g ON gmp.id_guru = g.id
        WHERE ma.id = ?
        LIMIT 1
    ");
    $stmt->execute([$modulId]);
    $rawModul = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rawModul) {
        die("Dokumen Modul Ajar tidak ditemukan.");
    }

    $allowedIds = getAllowedKmpIdSet($pdo, $isAdmin, $activeRole, $idGuruLogged);
    if (!$isAdmin && !in_array((int)$rawModul['id_kelas_mata_pelajaran'], $allowedIds, true)) {
        die("Anda tidak memiliki akses untuk mencetak modul ajar ini.");
    }

    $modul = normalizeModulRow($pdo, $rawModul);

    // Resolve CP, TP, ATP, ITP, Model, Metode
    $cpRows = [];
    if (!empty($modul['cp_ids'])) {
        $in = implode(',', array_fill(0, count($modul['cp_ids']), '?'));
        $s = $pdo->prepare("SELECT c.id, c.cp, e.nama_elemen, e.fase FROM cp c LEFT JOIN elemen e ON c.id_elemen = e.id WHERE c.id IN ($in)");
        $s->execute($modul['cp_ids']);
        $cpRows = $s->fetchAll(PDO::FETCH_ASSOC);
    }

    $tpRows = [];
    if (!empty($modul['tp_ids'])) {
        $in = implode(',', array_fill(0, count($modul['tp_ids']), '?'));
        $s = $pdo->prepare("SELECT id, kode_tp, tp, materi_pokok, alokasi_jam FROM tp WHERE id IN ($in)");
        $s->execute($modul['tp_ids']);
        $tpRows = $s->fetchAll(PDO::FETCH_ASSOC);
    }

    $modelNames = [];
    if (!empty($modul['model_ids'])) {
        $in = implode(',', array_fill(0, count($modul['model_ids']), '?'));
        $s = $pdo->prepare("SELECT nama_model FROM model_pembelajaran WHERE id IN ($in)");
        $s->execute($modul['model_ids']);
        $modelNames = $s->fetchAll(PDO::FETCH_COLUMN);
    }

    $metodeNames = [];
    if (!empty($modul['metode_ids'])) {
        $in = implode(',', array_fill(0, count($modul['metode_ids']), '?'));
        $s = $pdo->prepare("SELECT nama_metode FROM metode_pembelajaran WHERE id IN ($in)");
        $s->execute($modul['metode_ids']);
        $metodeNames = $s->fetchAll(PDO::FETCH_COLUMN);
    }

    $namaSekolah = $identitasSekolah['nama_sekolah'] ?? 'ILMUNA LEARN ACADEMY';
    $alamatParts = array_filter([
        $identitasSekolah['alamat_jalan'] ?? '',
        $identitasSekolah['desa_kelurahan'] ?? '',
        $identitasSekolah['kecamatan'] ?? '',
        $identitasSekolah['kabupaten_kota'] ?? '',
        $identitasSekolah['provinsi'] ?? ''
    ]);
    $alamatSekolah = !empty($alamatParts) ? implode(', ', $alamatParts) : ($identitasSekolah['alamat'] ?? '');
    $kontakParts = array_filter([
        !empty($identitasSekolah['telepon']) ? 'Telp. ' . $identitasSekolah['telepon'] : '',
        !empty($identitasSekolah['email']) ? 'Email: ' . $identitasSekolah['email'] : '',
        !empty($identitasSekolah['website']) ? $identitasSekolah['website'] : '',
    ]);
    $kontakSekolah = implode(' | ', $kontakParts);
    $titimangsa = $identitasSekolah['titimangsa_tempat'] ?? 'Bojonegoro';
    $kepsek = trim(($identitasSekolah['nama_kepala_sekolah'] ?? 'Kepala Sekolah') . ' ' . ($identitasSekolah['gelar_kepala_sekolah'] ?? ''));
    $nipKepsek = !empty($identitasSekolah['nip_kepala_sekolah']) ? 'NIP. ' . $identitasSekolah['nip_kepala_sekolah'] : 'NIP. -';
    $guruNama = ($rawModul['nama_guru_pengampu'] && $rawModul['nama_guru_pengampu'] !== 'Dewan Guru') ? $rawModul['nama_guru_pengampu'] : $namaGuruLogged;
    $nipGuruRaw = !empty($rawModul['nip_guru_pengampu']) ? $rawModul['nip_guru_pengampu'] : ($currentGuru['nip'] ?? '');
    $nipGuru = $nipGuruRaw !== '' ? 'NIP. ' . $nipGuruRaw : 'NIP. -';

    $statusExecMap = [
        'terlaksana' => ['label' => 'Terlaksana Penuh', 'bg' => '#DCFCE7', 'color' => '#15803D', 'score' => 100],
        'sebagian'   => ['label' => 'Terlaksana Sebagian', 'bg' => '#FEF3C7', 'color' => '#B45309', 'score' => 50],
        'belum'      => ['label' => 'Belum Terlaksana', 'bg' => '#F1F5F9', 'color' => '#475569', 'score' => 0],
    ];
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= $printAction === 'print_laporan' ? 'Laporan Keterlaksanaan' : 'Modul Ajar' ?> - <?= htmlspecialchars($modul['judul_modul']) ?></title>
        <style>
            @page { size: A4 portrait; margin: 15mm 15mm 15mm 15mm; }
            body { font-family: 'Times New Roman', Times, serif; font-size: 11pt; color: #111; line-height: 1.48; margin: 0; padding: 20px; background: #fff; }
            .no-print { max-width: 820px; margin: 0 auto 18px; background: #F8FAFC; padding: 12px 16px; border: 1px solid #CBD5E1; border-radius: 10px; display: flex; gap: 10px; justify-content: space-between; align-items: center; font-family: 'Plus Jakarta Sans', Arial, sans-serif; }
            .no-print-actions { display: flex; gap: 8px; }
            .btn-p { padding: 8px 16px; font-size: 13px; font-weight: 700; border-radius: 8px; cursor: pointer; border: 1px solid #0D7A87; background: #0D7A87; color: #fff; }
            .btn-c { padding: 8px 16px; font-size: 13px; font-weight: 700; border-radius: 8px; cursor: pointer; border: 1px solid #CBD5E1; background: #fff; color: #334155; }
            .sheet { max-width: 820px; margin: 0 auto; }
            .kop { text-align: center; border-bottom: 3px double #000; padding-bottom: 10px; margin-bottom: 16px; }
            .kop h1 { font-size: 15pt; margin: 0; text-transform: uppercase; letter-spacing: 0.4px; }
            .kop p { margin: 2px 0 0; font-size: 9.5pt; }
            .doc-title { text-align: center; font-size: 13.5pt; font-weight: bold; text-transform: uppercase; margin: 10px 0 3px; }
            .doc-sub { text-align: center; font-size: 10.5pt; margin: 0 0 16px; color: #333; }
            .sec-head { background: #1D3B5E; color: #fff; font-weight: bold; font-size: 10.5pt; padding: 5px 10px; text-transform: uppercase; margin: 14px 0 8px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            table.meta { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 10.5pt; }
            table.meta td { border: 1px solid #333; padding: 5px 8px; vertical-align: top; }
            table.meta td.lbl { width: 22%; font-weight: bold; background: #F1F5F9; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            table.grid { width: 100%; border-collapse: collapse; margin-bottom: 12px; font-size: 10.5pt; }
            table.grid th, table.grid td { border: 1px solid #333; padding: 6px 8px; vertical-align: top; }
            table.grid th { background: #F1F5F9; font-weight: bold; text-align: left; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .text-block { white-space: pre-line; }
            .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 9.5pt; font-weight: bold; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            table.sig { width: 100%; margin-top: 26px; border-collapse: collapse; page-break-inside: avoid; }
            table.sig td { width: 50%; text-align: center; vertical-align: top; padding: 4px 12px; }
            @media print { .no-print { display: none !important; } body { padding: 0; } .sheet { max-width: 100%; } }
        </style>
    </head>
    <body>
        <div class="no-print">
            <div><strong>Pratinjau Cetak Resmi A4</strong> &bull; Gunakan "Save as PDF" untuk mengunduh PDF</div>
            <div class="no-print-actions">
                <button type="button" class="btn-p" onclick="window.print()">Cetak / Unduh PDF</button>
                <button type="button" class="btn-c" onclick="window.close()">Tutup</button>
            </div>
        </div>

        <div class="sheet">
            <div class="kop">
                <h1><?= htmlspecialchars($namaSekolah) ?></h1>
                <?php if ($alamatSekolah): ?><p><?= htmlspecialchars($alamatSekolah) ?></p><?php endif; ?>
                <?php if ($kontakSekolah): ?><p><?= htmlspecialchars($kontakSekolah) ?></p><?php endif; ?>
            </div>

            <?php if ($printAction === 'print_modul'): ?>
                <div class="doc-title">MODUL AJAR KURIKULUM MERDEKA</div>
                <div class="doc-sub"><?= htmlspecialchars($modul['nama_mata_pelajaran']) ?> &bull; <?= htmlspecialchars($modul['nama_kelas']) ?> &bull; Pertemuan ke-<?= (int)$modul['pertemuan_ke'] ?></div>

                <div class="sec-head">I. Informasi Umum & Identitas Modul</div>
                <table class="meta">
                    <tr>
                        <td class="lbl">Satuan Pendidikan</td><td><?= htmlspecialchars($namaSekolah) ?></td>
                        <td class="lbl">Tahun Pelajaran / Sem</td><td><?= htmlspecialchars($modul['tahun_pelajaran']) ?> / <?= htmlspecialchars($modul['semester']) ?></td>
                    </tr>
                    <tr>
                        <td class="lbl">Mata Pelajaran</td><td><?= htmlspecialchars($modul['nama_mata_pelajaran']) ?></td>
                        <td class="lbl">Kelas / Fase</td><td><?= htmlspecialchars($modul['nama_kelas']) ?> / <?= htmlspecialchars($modul['fase']) ?></td>
                    </tr>
                    <tr>
                        <td class="lbl">Guru Penyusun</td><td><?= htmlspecialchars($guruNama) ?></td>
                        <td class="lbl">Pertemuan Ke-</td><td>Pertemuan ke-<?= (int)$modul['pertemuan_ke'] ?></td>
                    </tr>
                    <tr>
                        <td class="lbl">Judul / Materi Pokok</td><td colspan="3"><strong><?= htmlspecialchars($modul['judul_modul']) ?></strong></td>
                    </tr>
                    <tr>
                        <td class="lbl">Alokasi Waktu</td><td><?= (int)$modul['jumlah_jp'] ?> JP &times; <?= (int)$modul['alokasi_per_jp'] ?> Menit (<?= (int)$modul['total_menit'] ?> Menit)</td>
                        <td class="lbl">Tanggal / Jam</td><td><?= formatTanggalIndoMA($modul['tanggal']) ?><?= ($modul['jam_mulai'] || $modul['jam_selesai']) ? ' (' . htmlspecialchars(trim($modul['jam_mulai'] . ' - ' . $modul['jam_selesai'], ' -')) . ')' : '' ?></td>
                    </tr>
                    <tr>
                        <td class="lbl">Target Peserta Didik</td><td><?= htmlspecialchars($modul['target_peserta_didik'] ?: 'Peserta Didik Reguler / Tipikal') ?></td>
                        <td class="lbl">Model Pembelajaran</td><td><?= htmlspecialchars(!empty($modelNames) ? implode(', ', $modelNames) : '-') ?></td>
                    </tr>
                    <tr>
                        <td class="lbl">Metode Pembelajaran</td><td><?= htmlspecialchars(!empty($metodeNames) ? implode(', ', $metodeNames) : '-') ?></td>
                        <td class="lbl">Status Modul</td><td><?= htmlspecialchars($modul['status']) ?><?= $modul['meeting_status'] === 'final' ? ' (Final)' : '' ?></td>
                    </tr>
                    <tr>
                        <td class="lbl">Profil Pelajar Pancasila</td><td colspan="3" class="text-block"><?= htmlspecialchars($modul['profil_pelajar_pancasila'] ?: '-') ?></td>
                    </tr>
                    <tr>
                        <td class="lbl">Sarana & Media</td><td colspan="3" class="text-block"><?= htmlspecialchars($modul['media_pembelajaran'] ?: '-') ?></td>
                    </tr>
                    <tr>
                        <td class="lbl">Sumber Belajar</td><td colspan="3" class="text-block"><?= htmlspecialchars($modul['sumber_belajar'] ?: '-') ?></td>
                    </tr>
                </table>

                <div class="sec-head">II. Kompetensi Inti (CP, TP, ITP & ATP)</div>
                <table class="grid">
                    <tr>
                        <th style="width: 26%;">Capaian Pembelajaran (CP)</th>
                        <td>
                            <?php if (!empty($cpRows)): ?>
                                <?php foreach ($cpRows as $idx => $c): ?>
                                    <div style="margin-bottom: 6px;">
                                        <?php if (!empty($c['nama_elemen'])): ?><strong>[<?= htmlspecialchars($c['nama_elemen']) ?>]</strong> <?php endif; ?>
                                        <?= nl2br(htmlspecialchars($c['cp'])) ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Tujuan Pembelajaran (TP)</th>
                        <td>
                            <?php if (!empty($tpRows)): ?>
                                <?php foreach ($tpRows as $t): ?>
                                    <div style="margin-bottom: 5px;">
                                        <?php if (!empty($t['kode_tp'])): ?><strong>(<?= htmlspecialchars($t['kode_tp']) ?>)</strong> <?php endif; ?>
                                        <?= nl2br(htmlspecialchars($t['tp'])) ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if (!empty($modul['tp_custom'])): ?>
                                <div class="text-block"><?= htmlspecialchars($modul['tp_custom']) ?></div>
                            <?php elseif (empty($tpRows)): ?>-<?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Indikator Ketercapaian (ITP)</th>
                        <td class="text-block"><?= htmlspecialchars($modul['itp_custom'] ?: '-') ?></td>
                    </tr>
                    <tr>
                        <th>Alur Tujuan Pembelajaran (ATP)</th>
                        <td class="text-block"><?= htmlspecialchars($modul['atp_custom'] ?: '-') ?></td>
                    </tr>
                    <tr>
                        <th>Pemahaman Bermakna</th>
                        <td class="text-block"><?= htmlspecialchars($modul['pemahaman_bermakna'] ?: '-') ?></td>
                    </tr>
                    <tr>
                        <th>Pertanyaan Pemantik</th>
                        <td class="text-block"><?= htmlspecialchars($modul['pertanyaan_pemantik'] ?: '-') ?></td>
                    </tr>
                    <?php if (!empty($modul['persiapan_pembelajaran'])): ?>
                    <tr>
                        <th>Persiapan Pembelajaran</th>
                        <td class="text-block"><?= htmlspecialchars($modul['persiapan_pembelajaran']) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>

                <div class="sec-head">III. Kegiatan Pembelajaran</div>
                <table class="grid">
                    <thead>
                        <tr>
                            <th style="width: 22%;">Tahap Kegiatan</th>
                            <th>Uraian Aktivitas Pembelajaran</th>
                            <th style="width: 14%; text-align: center;">Alokasi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>1. Pendahuluan / Pembukaan</strong></td>
                            <td class="text-block"><?= htmlspecialchars($modul['kegiatan_pendahuluan'] ?: '-') ?></td>
                            <td style="text-align: center; font-weight: bold;"><?= (int)$modul['durasi_pembukaan'] ?> Menit</td>
                        </tr>
                        <tr>
                            <td><strong>2. Kegiatan Inti</strong></td>
                            <td class="text-block"><?= htmlspecialchars($modul['kegiatan_inti'] ?: '-') ?></td>
                            <td style="text-align: center; font-weight: bold;"><?= (int)$modul['durasi_inti'] ?> Menit</td>
                        </tr>
                        <tr>
                            <td><strong>3. Penutup</strong></td>
                            <td class="text-block"><?= htmlspecialchars($modul['kegiatan_penutup'] ?: '-') ?></td>
                            <td style="text-align: center; font-weight: bold;"><?= (int)$modul['durasi_penutup'] ?> Menit</td>
                        </tr>
                    </tbody>
                </table>

                <div class="sec-head">IV. Asesmen, Pengayaan & Refleksi</div>
                <table class="grid">
                    <?php if (!empty($modul['asesmen_diagnostik'])): ?>
                    <tr><th style="width: 26%;">Asesmen Diagnostik</th><td class="text-block"><?= htmlspecialchars($modul['asesmen_diagnostik']) ?></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($modul['asesmen_formatif'])): ?>
                    <tr><th style="width: 26%;">Asesmen Formatif</th><td class="text-block"><?= htmlspecialchars($modul['asesmen_formatif']) ?></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($modul['asesmen_sumatif'])): ?>
                    <tr><th style="width: 26%;">Asesmen Sumatif</th><td class="text-block"><?= htmlspecialchars($modul['asesmen_sumatif']) ?></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($modul['pengayaan_remedial'])): ?>
                    <tr><th style="width: 26%;">Pengayaan & Remedial</th><td class="text-block"><?= htmlspecialchars($modul['pengayaan_remedial']) ?></td></tr>
                    <?php endif; ?>
                    <tr><th style="width: 26%;">Refleksi Guru</th><td class="text-block"><?= htmlspecialchars($modul['refleksi_guru'] ?: '-') ?></td></tr>
                    <tr><th style="width: 26%;">Refleksi Peserta Didik</th><td class="text-block"><?= htmlspecialchars($modul['refleksi_siswa'] ?: '-') ?></td></tr>
                </table>

                <div class="sec-head">V. Lampiran, LKPD & Glosarium</div>
                <table class="grid">
                    <?php if (!empty($modul['lkpd'])): ?>
                    <tr><th style="width: 26%;">LKPD</th><td class="text-block"><?= htmlspecialchars($modul['lkpd']) ?></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($modul['bahan_bacaan'])): ?>
                    <tr><th style="width: 26%;">Bahan Bacaan</th><td class="text-block"><?= htmlspecialchars($modul['bahan_bacaan']) ?></td></tr>
                    <?php endif; ?>
                    <tr><th style="width: 26%;">Glosarium</th><td class="text-block"><?= htmlspecialchars($modul['glosarium'] ?: '-') ?></td></tr>
                    <tr>
                        <th style="width: 26%;">Daftar Berkas Lampiran</th>
                        <td>
                            <?php if (!empty($modul['lampiran'])): ?>
                                <?php foreach ($modul['lampiran'] as $lf): ?>
                                    <div>&bull; <?= htmlspecialchars($lf['file_name']) ?></div>
                                <?php endforeach; ?>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                    </tr>
                </table>
            <?php else: ?>
                <?php
                $stP = $statusExecMap[$modul['status_pembukaan']] ?? $statusExecMap['belum'];
                $stI = $statusExecMap[$modul['status_inti']] ?? $statusExecMap['belum'];
                $stN = $statusExecMap[$modul['status_penutup']] ?? $statusExecMap['belum'];
                $avgPct = (int)round(($stP['score'] + $stI['score'] + $stN['score']) / 3);
                ?>
                <div class="doc-title">LAPORAN KETERLAKSANAAN PEMBELAJARAN</div>
                <div class="doc-sub">BERDASARKAN MODUL AJAR &bull; <?= htmlspecialchars($modul['nama_mata_pelajaran']) ?> &bull; <?= htmlspecialchars($modul['nama_kelas']) ?></div>

                <div class="sec-head">I. Identitas Pertemuan</div>
                <table class="meta">
                    <tr>
                        <td class="lbl">Mata Pelajaran</td><td><?= htmlspecialchars($modul['nama_mata_pelajaran']) ?></td>
                        <td class="lbl">Kelas / Fase</td><td><?= htmlspecialchars($modul['nama_kelas']) ?> / <?= htmlspecialchars($modul['fase']) ?></td>
                    </tr>
                    <tr>
                        <td class="lbl">Pertemuan Ke-</td><td>Pertemuan ke-<?= (int)$modul['pertemuan_ke'] ?></td>
                        <td class="lbl">Tanggal Pelaksanaan</td><td><?= formatTanggalIndoMA($modul['tanggal']) ?></td>
                    </tr>
                    <tr>
                        <td class="lbl">Topik / Materi</td><td colspan="3"><strong><?= htmlspecialchars($modul['judul_modul']) ?></strong></td>
                    </tr>
                    <tr>
                        <td class="lbl">Status Pertemuan</td><td><strong><?= $modul['meeting_status'] === 'final' ? 'FINAL (TERKUNCI)' : 'BERJALAN' ?></strong></td>
                        <td class="lbl">Capaian Pelaksanaan</td><td><strong><?= $avgPct ?>%</strong></td>
                    </tr>
                </table>

                <div class="sec-head">II. Rincian Keterlaksanaan Tahapan Kegiatan</div>
                <table class="grid">
                    <thead>
                        <tr>
                            <th style="width: 22%;">Tahapan</th>
                            <th style="width: 14%; text-align: center;">Durasi</th>
                            <th style="width: 22%; text-align: center;">Status Keterlaksanaan</th>
                            <th>Catatan Evaluasi / Kendala Guru</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>1. Pembukaan / Pendahuluan</strong></td>
                            <td style="text-align: center;"><?= (int)$modul['durasi_pembukaan'] ?> Menit</td>
                            <td style="text-align: center;"><span class="badge" style="background:<?= $stP['bg'] ?>;color:<?= $stP['color'] ?>;"><?= $stP['label'] ?></span></td>
                            <td class="text-block"><?= htmlspecialchars($modul['catatan_pembukaan'] ?: '-') ?></td>
                        </tr>
                        <tr>
                            <td><strong>2. Kegiatan Inti</strong></td>
                            <td style="text-align: center;"><?= (int)$modul['durasi_inti'] ?> Menit</td>
                            <td style="text-align: center;"><span class="badge" style="background:<?= $stI['bg'] ?>;color:<?= $stI['color'] ?>;"><?= $stI['label'] ?></span></td>
                            <td class="text-block"><?= htmlspecialchars($modul['catatan_inti'] ?: '-') ?></td>
                        </tr>
                        <tr>
                            <td><strong>3. Penutup</strong></td>
                            <td style="text-align: center;"><?= (int)$modul['durasi_penutup'] ?> Menit</td>
                            <td style="text-align: center;"><span class="badge" style="background:<?= $stN['bg'] ?>;color:<?= $stN['color'] ?>;"><?= $stN['label'] ?></span></td>
                            <td class="text-block"><?= htmlspecialchars($modul['catatan_penutup'] ?: '-') ?></td>
                        </tr>
                    </tbody>
                </table>

                <div class="sec-head">III. Refleksi Pembelajaran</div>
                <table class="grid">
                    <tr><th style="width: 26%;">Refleksi Guru</th><td class="text-block"><?= htmlspecialchars($modul['refleksi_guru'] ?: '-') ?></td></tr>
                    <tr><th style="width: 26%;">Refleksi Peserta Didik</th><td class="text-block"><?= htmlspecialchars($modul['refleksi_siswa'] ?: '-') ?></td></tr>
                </table>
            <?php endif; ?>

            <table class="sig">
                <tr>
                    <td>
                        Mengetahui,<br>Kepala Sekolah<br><br><br><br><br>
                        <strong><?= htmlspecialchars($kepsek) ?></strong><br>
                        <?= htmlspecialchars($nipKepsek) ?>
                    </td>
                    <td>
                        <?= htmlspecialchars($titimangsa) ?>, <?= formatTanggalIndoMA(date('Y-m-d')) ?><br>
                        Guru Mata Pelajaran<br><br><br><br><br>
                        <strong><?= htmlspecialchars($guruNama) ?></strong><br>
                        <?= htmlspecialchars($nipGuru) ?>
                    </td>
                </tr>
            </table>
        </div>
    </body>
    </html>
    <?php
    exit;
}

/* ==============================================================================
 * REST / AJAX JSON API ENDPOINT (?api=1&action=...)
 * ============================================================================== */
if (isset($_GET['api']) && $_GET['api'] == '1') {
    header('Content-Type: application/json; charset=utf-8');

    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $rawBody = file_get_contents('php://input');
    $jsonBody = [];
    if ($rawBody !== '' && isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        $dec = json_decode($rawBody, true);
        if (is_array($dec)) $jsonBody = $dec;
    }
    $in = array_merge($_GET, $_POST, $jsonBody);
    if (!$action && !empty($in['action'])) {
        $action = $in['action'];
    }

    $sendJson = function ($status, $message, $data = null, $httpCode = 200) {
        http_response_code($httpCode);
        echo json_encode([
            'status'  => $status,
            'message' => $message,
            'data'    => $data,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    };

    try {
        $kmpRows = getAccessibleKmpRows($pdo, $isAdmin, $activeRole, $idGuruLogged);
        $allowedKmpIds = [];
        $kmpById = [];
        foreach ($kmpRows as $kr) {
            $allowedKmpIds[] = (int)$kr['id_kmp'];
            $kmpById[(int)$kr['id_kmp']] = $kr;
        }

        $assertKmpAccess = function ($idKmp) use ($isAdmin, $allowedKmpIds, $sendJson) {
            $idKmp = (int)$idKmp;
            if ($idKmp <= 0) {
                $sendJson('error', 'Kelas & Mata Pelajaran wajib dipilih.', null, 400);
            }
            if (!$isAdmin && !in_array($idKmp, $allowedKmpIds, true)) {
                $sendJson('error', 'Akses ditolak untuk kelas & mata pelajaran ini.', null, 403);
            }
            return $idKmp;
        };

        /* ----------------------------------------------------------------------
         * 1. BOOTSTRAP: Master KMP, Subjects Grouped, Model+Sintaks, Metode, Counts
         * ---------------------------------------------------------------------- */
        if ($action === 'bootstrap') {
            $inKmpSql = !empty($allowedKmpIds) ? implode(',', array_map('intval', $allowedKmpIds)) : '0';

            // Counts per KMP: CP, TP, Modul (total, draft, published, final)
            $cpPerKmp = [];
            $tpPerKmp = [];
            $maPerKmp = [];
            if (!empty($allowedKmpIds)) {
                foreach ($pdo->query("SELECT id_kelas_mata_pelajaran, COUNT(*) AS cnt FROM cp WHERE id_kelas_mata_pelajaran IN ($inKmpSql) GROUP BY id_kelas_mata_pelajaran")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $cpPerKmp[(int)$r['id_kelas_mata_pelajaran']] = (int)$r['cnt'];
                }
                foreach ($pdo->query("SELECT c.id_kelas_mata_pelajaran, COUNT(t.id) AS cnt FROM tp t JOIN cp c ON t.id_cp = c.id WHERE c.id_kelas_mata_pelajaran IN ($inKmpSql) GROUP BY c.id_kelas_mata_pelajaran")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $tpPerKmp[(int)$r['id_kelas_mata_pelajaran']] = (int)$r['cnt'];
                }
                $maStatsSql = "
                    SELECT id_kelas_mata_pelajaran,
                           COUNT(*) AS total,
                           SUM(CASE WHEN status = 'Draft' THEN 1 ELSE 0 END) AS draft_cnt,
                           SUM(CASE WHEN status = 'Published' AND COALESCE(meeting_status,'berjalan') != 'final' THEN 1 ELSE 0 END) AS pub_cnt,
                           SUM(CASE WHEN COALESCE(meeting_status,'berjalan') = 'final' THEN 1 ELSE 0 END) AS final_cnt
                    FROM modul_ajar
                    WHERE id_kelas_mata_pelajaran IN ($inKmpSql)
                    GROUP BY id_kelas_mata_pelajaran
                ";
                foreach ($pdo->query($maStatsSql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $maPerKmp[(int)$r['id_kelas_mata_pelajaran']] = [
                        'total'     => (int)$r['total'],
                        'draft'     => (int)$r['draft_cnt'],
                        'published' => (int)$r['pub_cnt'],
                        'final'     => (int)$r['final_cnt'],
                    ];
                }
            }

            // Group subjects like ModulAjarListPage
            $groupedMap = [];
            $totalCp = 0;
            $totalTp = 0;
            $totalModul = 0;
            $tahunSet = [];
            $gradeSet = [];

            foreach ($kmpRows as $kr) {
                $kid = (int)$kr['id_kmp'];
                $mid = (int)$kr['id_mata_pelajaran'];
                $cCnt = $cpPerKmp[$kid] ?? 0;
                $tCnt = $tpPerKmp[$kid] ?? 0;
                $mStat = $maPerKmp[$kid] ?? ['total' => 0, 'draft' => 0, 'published' => 0, 'final' => 0];

                $totalCp += $cCnt;
                $totalTp += $tCnt;
                $totalModul += $mStat['total'];
                if (!empty($kr['tahun_pelajaran'])) $tahunSet[$kr['tahun_pelajaran']] = true;
                if (!empty($kr['grade_label'])) $gradeSet[$kr['grade_label']] = true;

                if (!isset($groupedMap[$mid])) {
                    $groupedMap[$mid] = [
                        'id_mata_pelajaran'   => $mid,
                        'nama_mata_pelajaran' => $kr['nama_mata_pelajaran'],
                        'kode_mapel'          => $kr['kode_mapel'] ?: ('MP-' . $mid),
                        'kktp'                => $kr['kktp'] !== null ? (float)$kr['kktp'] : 75,
                        'total_cp'            => 0,
                        'total_tp'            => 0,
                        'total_modul'         => 0,
                        'classes'             => [],
                    ];
                }
                $readyDone = $mStat['published'] + $mStat['final'];
                $targetPertemuan = max($mStat['total'], $tCnt, 1);
                $progressPct = $mStat['total'] > 0
                    ? (int)min(100, round(($readyDone / max($mStat['total'], 1)) * 100))
                    : 0;

                $groupedMap[$mid]['total_cp'] += $cCnt;
                $groupedMap[$mid]['total_tp'] += $tCnt;
                $groupedMap[$mid]['total_modul'] += $mStat['total'];
                $groupedMap[$mid]['classes'][] = [
                    'id_kmp'          => $kid,
                    'id_kelas'        => (int)$kr['id_kelas'],
                    'nama_kelas'      => $kr['nama_kelas'],
                    'kode_kelas'      => $kr['kode_kelas'],
                    'grade_label'     => $kr['grade_label'],
                    'fase'            => $kr['fase'],
                    'tahun_pelajaran' => $kr['tahun_pelajaran'],
                    'semester'        => $kr['mapel_semester'] ?: 'Ganjil',
                    'nama_guru'       => $kr['nama_guru'],
                    'cp_count'        => $cCnt,
                    'tp_count'        => $tCnt,
                    'modul_total'     => $mStat['total'],
                    'modul_draft'     => $mStat['draft'],
                    'modul_published' => $mStat['published'],
                    'modul_final'     => $mStat['final'],
                    'progress_pct'    => $progressPct,
                ];
            }

            // Master Model Pembelajaran + Sintaks
            $models = $pdo->query("SELECT id, nama_model, slug, kategori, deskripsi, sintaks FROM model_pembelajaran ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
            $sintaksRows = $pdo->query("SELECT id, id_model_pembelajaran, tahap_ke, nama_fase, aktivitas_guru, aktivitas_siswa FROM sintaks_model_pembelajaran ORDER BY id_model_pembelajaran ASC, tahap_ke ASC")->fetchAll(PDO::FETCH_ASSOC);
            $sintaksByModel = [];
            foreach ($sintaksRows as $sr) {
                $sintaksByModel[(int)$sr['id_model_pembelajaran']][] = $sr;
            }
            foreach ($models as &$m) {
                $m['id'] = (int)$m['id'];
                $m['sintaks_steps'] = $sintaksByModel[$m['id']] ?? [];
            }
            unset($m);

            // Master Metode Pembelajaran
            $metodes = $pdo->query("SELECT id, nama_metode, slug, deskripsi, langkah_langkah, rekomendasi_penerapan FROM metode_pembelajaran ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($metodes as &$mt) {
                $mt['id'] = (int)$mt['id'];
            }
            unset($mt);

            // Bank Materi (opsional link)
            $materiList = [];
            try {
                $materiList = $pdo->query("SELECT id, id_kelas_mata_pelajaran, judul_materi, tipe_materi FROM materi ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $eM) {}

            $defaultTahun = $identitasSekolah['tahun_pelajaran_aktif'] ?? '2026/2027';
            $tahunSet[$defaultTahun] = true;
            $tahunList = array_keys($tahunSet);
            rsort($tahunList);

            $gradeOrder = ['X', 'XI', 'XII', 'VII', 'VIII', 'IX', 'Lainnya'];
            $uniqueGrades = [];
            foreach ($gradeOrder as $g) {
                if (isset($gradeSet[$g])) $uniqueGrades[] = $g;
            }

            $sendJson('success', 'Data modul ajar berhasil dimuat.', [
                'kmp_list'        => $kmpRows,
                'subjects'        => array_values($groupedMap),
                'models'          => $models,
                'metodes'         => $metodes,
                'materi_list'     => $materiList,
                'tahun_list'      => $tahunList,
                'default_tahun'   => $defaultTahun,
                'default_sem'     => $identitasSekolah['semester_aktif'] ?? 'Ganjil',
                'unique_grades'   => $uniqueGrades,
                'counts'          => [
                    'cp'    => $totalCp,
                    'tp'    => $totalTp,
                    'modul' => $totalModul,
                ],
                'active_role'     => $activeRole,
                'is_admin'        => $isAdmin,
                'nama_guru'       => $namaGuruLogged,
            ]);
        }

        /* ----------------------------------------------------------------------
         * 2. LIST CP & ELEMEN (TAB 1)
         * ---------------------------------------------------------------------- */
        if ($action === 'list_cp') {
            $filterKmp = (int)($in['id_kmp'] ?? 0);
            $filterMapel = (int)($in['id_mapel'] ?? 0);
            $q = trim((string)($in['q'] ?? ''));

            if (empty($allowedKmpIds)) {
                $sendJson('success', 'OK', ['elemen_list' => [], 'cp_list' => []]);
            }
            $inSql = implode(',', array_map('intval', $allowedKmpIds));

            // Elemen
            $elWhere = ["e.id_kelas_mata_pelajaran IN ($inSql)"];
            $elParams = [];
            if ($filterKmp > 0) {
                $elWhere[] = "e.id_kelas_mata_pelajaran = ?";
                $elParams[] = $filterKmp;
            } elseif ($filterMapel > 0) {
                $elWhere[] = "kmp.id_mata_pelajaran = ?";
                $elParams[] = $filterMapel;
            }
            $elStmt = $pdo->prepare("
                SELECT e.*, k.nama_kelas, mp.nama_mata_pelajaran, kmp.id_mata_pelajaran,
                       (SELECT COUNT(*) FROM cp WHERE cp.id_elemen = e.id) AS cp_count
                FROM elemen e
                JOIN kelas_mata_pelajaran kmp ON e.id_kelas_mata_pelajaran = kmp.id
                JOIN kelas k ON kmp.id_kelas = k.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                WHERE " . implode(' AND ', $elWhere) . "
                ORDER BY mp.nama_mata_pelajaran ASC, k.nama_kelas ASC, e.id ASC
            ");
            $elStmt->execute($elParams);
            $elemenList = $elStmt->fetchAll(PDO::FETCH_ASSOC);

            // CP
            $cpWhere = ["c.id_kelas_mata_pelajaran IN ($inSql)"];
            $cpParams = [];
            if ($filterKmp > 0) {
                $cpWhere[] = "c.id_kelas_mata_pelajaran = ?";
                $cpParams[] = $filterKmp;
            } elseif ($filterMapel > 0) {
                $cpWhere[] = "kmp.id_mata_pelajaran = ?";
                $cpParams[] = $filterMapel;
            }
            if ($q !== '') {
                $cpWhere[] = "(c.cp LIKE ? OR e.nama_elemen LIKE ? OR mp.nama_mata_pelajaran LIKE ? OR k.nama_kelas LIKE ?)";
                $like = '%' . $q . '%';
                array_push($cpParams, $like, $like, $like, $like);
            }
            $cpStmt = $pdo->prepare("
                SELECT c.*, e.nama_elemen, e.fase, k.nama_kelas, mp.nama_mata_pelajaran, kmp.id_mata_pelajaran,
                       (SELECT COUNT(*) FROM tp WHERE tp.id_cp = c.id) AS tp_count
                FROM cp c
                LEFT JOIN elemen e ON c.id_elemen = e.id
                JOIN kelas_mata_pelajaran kmp ON c.id_kelas_mata_pelajaran = kmp.id
                JOIN kelas k ON kmp.id_kelas = k.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                WHERE " . implode(' AND ', $cpWhere) . "
                ORDER BY mp.nama_mata_pelajaran ASC, k.nama_kelas ASC, c.id ASC
            ");
            $cpStmt->execute($cpParams);
            $cpList = $cpStmt->fetchAll(PDO::FETCH_ASSOC);

            $sendJson('success', 'Daftar CP berhasil dimuat.', [
                'elemen_list' => $elemenList,
                'cp_list'     => $cpList,
            ]);
        }

        /* ----------------------------------------------------------------------
         * 3. CRUD ELEMEN & CP + SINKRONISASI CP ANTAR KELAS
         * ---------------------------------------------------------------------- */
        if ($action === 'save_elemen') {
            $id = (int)($in['id'] ?? 0);
            $idKmp = $assertKmpAccess($in['id_kelas_mata_pelajaran'] ?? 0);
            $nama = trim((string)($in['nama_elemen'] ?? ''));
            $fase = trim((string)($in['fase'] ?? '')) ?: ($kmpById[$idKmp]['fase'] ?? 'Fase E');
            $desc = trim((string)($in['deskripsi'] ?? ''));
            if ($nama === '') $sendJson('error', 'Nama elemen wajib diisi.', null, 400);

            if ($id > 0) {
                $pdo->prepare("UPDATE elemen SET id_kelas_mata_pelajaran = ?, nama_elemen = ?, fase = ?, deskripsi = ? WHERE id = ?")
                    ->execute([$idKmp, $nama, $fase, $desc, $id]);
                $sendJson('success', 'Elemen berhasil diperbarui.', ['id' => $id]);
            } else {
                $pdo->prepare("INSERT INTO elemen (id_kelas_mata_pelajaran, nama_elemen, fase, deskripsi) VALUES (?, ?, ?, ?)")
                    ->execute([$idKmp, $nama, $fase, $desc]);
                $sendJson('success', 'Elemen baru berhasil ditambahkan.', ['id' => (int)$pdo->lastInsertId()]);
            }
        }

        if ($action === 'delete_elemen') {
            $id = (int)($in['id'] ?? 0);
            $row = $pdo->prepare("SELECT id, id_kelas_mata_pelajaran FROM elemen WHERE id = ?");
            $row->execute([$id]);
            $el = $row->fetch(PDO::FETCH_ASSOC);
            if (!$el) $sendJson('error', 'Elemen tidak ditemukan.', null, 404);
            $assertKmpAccess($el['id_kelas_mata_pelajaran']);

            $cnt = $pdo->prepare("SELECT COUNT(*) FROM cp WHERE id_elemen = ?");
            $cnt->execute([$id]);
            if ((int)$cnt->fetchColumn() > 0) {
                $sendJson('error', 'Elemen ini masih memiliki Capaian Pembelajaran (CP). Hapus CP terkait terlebih dahulu.', null, 400);
            }
            $pdo->prepare("DELETE FROM elemen WHERE id = ?")->execute([$id]);
            $sendJson('success', 'Elemen berhasil dihapus.');
        }

        if ($action === 'save_cp') {
            $id = (int)($in['id'] ?? 0);
            $idKmp = $assertKmpAccess($in['id_kelas_mata_pelajaran'] ?? 0);
            $idElemen = (int)($in['id_elemen'] ?? 0);
            $newElemenNama = trim((string)($in['new_elemen_nama'] ?? ''));
            $fase = trim((string)($in['fase'] ?? '')) ?: ($kmpById[$idKmp]['fase'] ?? 'Fase E');
            $cpText = trim((string)($in['cp'] ?? ''));

            if ($cpText === '') {
                $sendJson('error', 'Deskripsi Capaian Pembelajaran (CP) wajib diisi.', null, 400);
            }

            if ($idElemen <= 0 && $newElemenNama !== '') {
                $cekEl = $pdo->prepare("SELECT id FROM elemen WHERE id_kelas_mata_pelajaran = ? AND nama_elemen = ? LIMIT 1");
                $cekEl->execute([$idKmp, $newElemenNama]);
                $exEl = $cekEl->fetchColumn();
                if ($exEl) {
                    $idElemen = (int)$exEl;
                } else {
                    $pdo->prepare("INSERT INTO elemen (id_kelas_mata_pelajaran, nama_elemen, fase, deskripsi) VALUES (?, ?, ?, ?)")
                        ->execute([$idKmp, $newElemenNama, $fase, '']);
                    $idElemen = (int)$pdo->lastInsertId();
                }
            }

            $elVal = $idElemen > 0 ? $idElemen : null;
            if ($id > 0) {
                $pdo->prepare("UPDATE cp SET id_kelas_mata_pelajaran = ?, id_elemen = ?, cp = ? WHERE id = ?")
                    ->execute([$idKmp, $elVal, $cpText, $id]);
                $sendJson('success', 'Capaian Pembelajaran (CP) berhasil diperbarui.', ['id' => $id, 'id_elemen' => $elVal]);
            } else {
                $pdo->prepare("INSERT INTO cp (id_kelas_mata_pelajaran, id_elemen, cp) VALUES (?, ?, ?)")
                    ->execute([$idKmp, $elVal, $cpText]);
                $sendJson('success', 'Capaian Pembelajaran (CP) baru berhasil disimpan.', ['id' => (int)$pdo->lastInsertId(), 'id_elemen' => $elVal]);
            }
        }

        if ($action === 'delete_cp') {
            $id = (int)($in['id'] ?? 0);
            $s = $pdo->prepare("SELECT id, id_kelas_mata_pelajaran FROM cp WHERE id = ?");
            $s->execute([$id]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if (!$row) $sendJson('error', 'CP tidak ditemukan.', null, 404);
            $assertKmpAccess($row['id_kelas_mata_pelajaran']);

            $cnt = $pdo->prepare("SELECT COUNT(*) FROM tp WHERE id_cp = ?");
            $cnt->execute([$id]);
            if ((int)$cnt->fetchColumn() > 0) {
                $sendJson('error', 'CP ini masih digunakan oleh Tujuan Pembelajaran (TP). Hapus TP terkait terlebih dahulu.', null, 400);
            }
            $pdo->prepare("DELETE FROM cp WHERE id = ?")->execute([$id]);
            $sendJson('success', 'Capaian Pembelajaran (CP) berhasil dihapus.');
        }

        if ($action === 'sync_cp_mapel') {
            $sourceKmp = $assertKmpAccess($in['source_kmp'] ?? 0);
            $targetKmp = $assertKmpAccess($in['target_kmp'] ?? 0);
            $includeTp = !empty($in['include_tp']);

            if ($sourceKmp === $targetKmp) {
                $sendJson('error', 'Kelas sumber dan kelas tujuan tidak boleh sama.', null, 400);
            }

            $srcCps = $pdo->prepare("
                SELECT c.*, e.nama_elemen, e.fase, e.deskripsi AS elemen_desc
                FROM cp c
                LEFT JOIN elemen e ON c.id_elemen = e.id
                WHERE c.id_kelas_mata_pelajaran = ?
                ORDER BY c.id ASC
            ");
            $srcCps->execute([$sourceKmp]);
            $rows = $srcCps->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) {
                $sendJson('error', 'Kelas sumber belum memiliki data CP untuk disalin.', null, 400);
            }

            $copiedCp = 0;
            $copiedTp = 0;
            $targetFase = $kmpById[$targetKmp]['fase'] ?? 'Fase E';

            $pdo->beginTransaction();
            try {
                foreach ($rows as $rc) {
                    $newElId = null;
                    if (!empty($rc['nama_elemen'])) {
                        $findEl = $pdo->prepare("SELECT id FROM elemen WHERE id_kelas_mata_pelajaran = ? AND nama_elemen = ? LIMIT 1");
                        $findEl->execute([$targetKmp, $rc['nama_elemen']]);
                        $exEl = $findEl->fetchColumn();
                        if ($exEl) {
                            $newElId = (int)$exEl;
                        } else {
                            $pdo->prepare("INSERT INTO elemen (id_kelas_mata_pelajaran, nama_elemen, fase, deskripsi) VALUES (?, ?, ?, ?)")
                                ->execute([$targetKmp, $rc['nama_elemen'], $targetFase, $rc['elemen_desc'] ?? '']);
                            $newElId = (int)$pdo->lastInsertId();
                        }
                    }

                    $findCp = $pdo->prepare("SELECT id FROM cp WHERE id_kelas_mata_pelajaran = ? AND cp = ? LIMIT 1");
                    $findCp->execute([$targetKmp, $rc['cp']]);
                    $newCpId = $findCp->fetchColumn();
                    if (!$newCpId) {
                        $pdo->prepare("INSERT INTO cp (id_kelas_mata_pelajaran, id_elemen, cp) VALUES (?, ?, ?)")
                            ->execute([$targetKmp, $newElId, $rc['cp']]);
                        $newCpId = (int)$pdo->lastInsertId();
                        $copiedCp++;
                    } else {
                        $newCpId = (int)$newCpId;
                    }

                    if ($includeTp) {
                        $srcTps = $pdo->prepare("SELECT * FROM tp WHERE id_cp = ? ORDER BY id ASC");
                        $srcTps->execute([$rc['id']]);
                        foreach ($srcTps->fetchAll(PDO::FETCH_ASSOC) as $rt) {
                            $findTp = $pdo->prepare("SELECT id FROM tp WHERE id_cp = ? AND tp = ? LIMIT 1");
                            $findTp->execute([$newCpId, $rt['tp']]);
                            if (!$findTp->fetchColumn()) {
                                $pdo->prepare("INSERT INTO tp (id_cp, kode_tp, tp, materi_pokok, alokasi_jam) VALUES (?, ?, ?, ?, ?)")
                                    ->execute([$newCpId, $rt['kode_tp'], $rt['tp'], $rt['materi_pokok'], (int)($rt['alokasi_jam'] ?: 4)]);
                                $newTpId = (int)$pdo->lastInsertId();
                                $copiedTp++;

                                // Copy ATP & ITP ikut serta
                                $sAtp = $pdo->prepare("SELECT * FROM atp WHERE id_tp = ? ORDER BY urutan ASC, id ASC");
                                $sAtp->execute([$rt['id']]);
                                $atpMap = [];
                                foreach ($sAtp->fetchAll(PDO::FETCH_ASSOC) as $ra) {
                                    $pdo->prepare("INSERT INTO atp (id_tp, atp, urutan) VALUES (?, ?, ?)")
                                        ->execute([$newTpId, $ra['atp'], (int)($ra['urutan'] ?: 1)]);
                                    $atpMap[(int)$ra['id']] = (int)$pdo->lastInsertId();
                                }
                                $sItp = $pdo->prepare("SELECT * FROM itp WHERE id_tp = ? ORDER BY urutan ASC, id ASC");
                                $sItp->execute([$rt['id']]);
                                foreach ($sItp->fetchAll(PDO::FETCH_ASSOC) as $ri) {
                                    $mappedAtp = (!empty($ri['id_atp']) && isset($atpMap[(int)$ri['id_atp']])) ? $atpMap[(int)$ri['id_atp']] : null;
                                    $pdo->prepare("INSERT INTO itp (id_tp, id_atp, kode_itp, itp, deskripsi_ketercapaian, urutan) VALUES (?, ?, ?, ?, ?, ?)")
                                        ->execute([$newTpId, $mappedAtp, $ri['kode_itp'], $ri['itp'], $ri['deskripsi_ketercapaian'], (int)($ri['urutan'] ?: 1)]);
                                }
                            }
                        }
                    }
                }
                $pdo->commit();
            } catch (Exception $eTx) {
                $pdo->rollBack();
                throw $eTx;
            }

            $sendJson('success', "Sinkronisasi selesai: {$copiedCp} CP" . ($includeTp ? " & {$copiedTp} TP" : '') . " berhasil disalin.", [
                'copied_cp' => $copiedCp,
                'copied_tp' => $copiedTp,
            ]);
        }

        /* ----------------------------------------------------------------------
         * 4. LIST & CRUD TP, ATP, ITP (TAB 2)
         * ---------------------------------------------------------------------- */
        if ($action === 'list_tp') {
            $filterKmp = (int)($in['id_kmp'] ?? 0);
            $filterCp = (int)($in['id_cp'] ?? 0);
            $q = trim((string)($in['q'] ?? ''));

            if (empty($allowedKmpIds)) {
                $sendJson('success', 'OK', ['tp_list' => [], 'cp_options' => []]);
            }
            $inSql = implode(',', array_map('intval', $allowedKmpIds));

            // Available CPs for dropdown
            $cpOptWhere = ["c.id_kelas_mata_pelajaran IN ($inSql)"];
            $cpOptParams = [];
            if ($filterKmp > 0) {
                $cpOptWhere[] = "c.id_kelas_mata_pelajaran = ?";
                $cpOptParams[] = $filterKmp;
            }
            $cpOptStmt = $pdo->prepare("
                SELECT c.id, c.id_kelas_mata_pelajaran, c.cp, e.nama_elemen, e.fase, k.nama_kelas, mp.nama_mata_pelajaran
                FROM cp c
                LEFT JOIN elemen e ON c.id_elemen = e.id
                JOIN kelas_mata_pelajaran kmp ON c.id_kelas_mata_pelajaran = kmp.id
                JOIN kelas k ON kmp.id_kelas = k.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                WHERE " . implode(' AND ', $cpOptWhere) . "
                ORDER BY mp.nama_mata_pelajaran ASC, k.nama_kelas ASC, c.id ASC
            ");
            $cpOptStmt->execute($cpOptParams);
            $cpOptions = $cpOptStmt->fetchAll(PDO::FETCH_ASSOC);

            // TP rows
            $tpWhere = ["c.id_kelas_mata_pelajaran IN ($inSql)"];
            $tpParams = [];
            if ($filterKmp > 0) {
                $tpWhere[] = "c.id_kelas_mata_pelajaran = ?";
                $tpParams[] = $filterKmp;
            }
            if ($filterCp > 0) {
                $tpWhere[] = "t.id_cp = ?";
                $tpParams[] = $filterCp;
            }
            if ($q !== '') {
                $tpWhere[] = "(t.tp LIKE ? OR t.kode_tp LIKE ? OR t.materi_pokok LIKE ? OR c.cp LIKE ? OR e.nama_elemen LIKE ?)";
                $like = '%' . $q . '%';
                array_push($tpParams, $like, $like, $like, $like, $like);
            }

            $tpStmt = $pdo->prepare("
                SELECT t.*, c.id_kelas_mata_pelajaran, c.cp AS cp_text, e.nama_elemen, e.fase,
                       k.nama_kelas, mp.nama_mata_pelajaran, kmp.id_mata_pelajaran
                FROM tp t
                JOIN cp c ON t.id_cp = c.id
                LEFT JOIN elemen e ON c.id_elemen = e.id
                JOIN kelas_mata_pelajaran kmp ON c.id_kelas_mata_pelajaran = kmp.id
                JOIN kelas k ON kmp.id_kelas = k.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                WHERE " . implode(' AND ', $tpWhere) . "
                ORDER BY mp.nama_mata_pelajaran ASC, k.nama_kelas ASC, t.id ASC
            ");
            $tpStmt->execute($tpParams);
            $tpList = $tpStmt->fetchAll(PDO::FETCH_ASSOC);

            // Attach ATP & ITP
            if (!empty($tpList)) {
                $tpIds = array_map(function ($r) { return (int)$r['id']; }, $tpList);
                $inTp = implode(',', $tpIds);

                $atpByTp = [];
                foreach ($pdo->query("SELECT * FROM atp WHERE id_tp IN ($inTp) ORDER BY urutan ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) as $a) {
                    $atpByTp[(int)$a['id_tp']][] = $a;
                }
                $itpByTp = [];
                foreach ($pdo->query("SELECT * FROM itp WHERE id_tp IN ($inTp) ORDER BY urutan ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) as $i) {
                    $itpByTp[(int)$i['id_tp']][] = $i;
                }

                foreach ($tpList as &$t) {
                    $tid = (int)$t['id'];
                    $t['id'] = $tid;
                    $t['id_cp'] = (int)$t['id_cp'];
                    $t['id_kelas_mata_pelajaran'] = (int)$t['id_kelas_mata_pelajaran'];
                    $t['alokasi_jam'] = (int)($t['alokasi_jam'] ?: 2);
                    $t['atp_list'] = $atpByTp[$tid] ?? [];
                    $t['itp_list'] = $itpByTp[$tid] ?? [];
                }
                unset($t);
            }

            $sendJson('success', 'Daftar TP berhasil dimuat.', [
                'tp_list'    => $tpList,
                'cp_options' => $cpOptions,
            ]);
        }

        if ($action === 'save_tp') {
            $id = (int)($in['id'] ?? 0);
            $idCp = (int)($in['id_cp'] ?? 0);
            $kodeTp = trim((string)($in['kode_tp'] ?? ''));
            $materiPokok = trim((string)($in['materi_pokok'] ?? ''));
            $tpText = trim((string)($in['tp'] ?? ''));
            $alokasiJam = max(1, (int)($in['alokasi_jam'] ?? 2));

            if ($idCp <= 0 || $tpText === '') {
                $sendJson('error', 'Capaian Pembelajaran (CP) dan deskripsi Tujuan Pembelajaran (TP) wajib diisi.', null, 400);
            }

            $cpCheck = $pdo->prepare("SELECT id, id_kelas_mata_pelajaran FROM cp WHERE id = ?");
            $cpCheck->execute([$idCp]);
            $cpRow = $cpCheck->fetch(PDO::FETCH_ASSOC);
            if (!$cpRow) $sendJson('error', 'CP yang dipilih tidak ditemukan.', null, 404);
            $assertKmpAccess($cpRow['id_kelas_mata_pelajaran']);

            if ($kodeTp === '') {
                $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM tp WHERE id_cp = ?");
                $cntStmt->execute([$idCp]);
                $kodeTp = 'TP.' . ((int)$cntStmt->fetchColumn() + 1);
            }

            if ($id > 0) {
                $pdo->prepare("UPDATE tp SET id_cp = ?, kode_tp = ?, tp = ?, materi_pokok = ?, alokasi_jam = ? WHERE id = ?")
                    ->execute([$idCp, $kodeTp, $tpText, $materiPokok, $alokasiJam, $id]);
                $savedId = $id;
                $msg = 'Tujuan Pembelajaran (TP) berhasil diperbarui.';
            } else {
                $pdo->prepare("INSERT INTO tp (id_cp, kode_tp, tp, materi_pokok, alokasi_jam) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$idCp, $kodeTp, $tpText, $materiPokok, $alokasiJam]);
                $savedId = (int)$pdo->lastInsertId();
                $msg = 'Tujuan Pembelajaran (TP) baru berhasil disimpan.';
            }

            // Opsional: langsung tambah ATP & ITP bila dikirim bersamaan dari form
            $initAtp = trim((string)($in['initial_atp'] ?? ''));
            if ($initAtp !== '' && $id === 0) {
                $pdo->prepare("INSERT INTO atp (id_tp, atp, urutan) VALUES (?, ?, 1)")
                    ->execute([$savedId, $initAtp]);
            }
            $initItp = trim((string)($in['initial_itp'] ?? ''));
            if ($initItp !== '' && $id === 0) {
                $pdo->prepare("INSERT INTO itp (id_tp, kode_itp, itp, urutan) VALUES (?, ?, ?, 1)")
                    ->execute([$savedId, $kodeTp . '.1', $initItp]);
            }

            $sendJson('success', $msg, [
                'id'                      => $savedId,
                'id_cp'                   => $idCp,
                'id_kelas_mata_pelajaran' => (int)$cpRow['id_kelas_mata_pelajaran'],
                'kode_tp'                 => $kodeTp,
                'tp'                      => $tpText,
                'materi_pokok'            => $materiPokok,
                'alokasi_jam'             => $alokasiJam,
            ]);
        }

        if ($action === 'delete_tp') {
            $id = (int)($in['id'] ?? 0);
            $s = $pdo->prepare("SELECT t.id, c.id_kelas_mata_pelajaran FROM tp t JOIN cp c ON t.id_cp = c.id WHERE t.id = ?");
            $s->execute([$id]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if (!$row) $sendJson('error', 'TP tidak ditemukan.', null, 404);
            $assertKmpAccess($row['id_kelas_mata_pelajaran']);

            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM itp WHERE id_tp = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM atp WHERE id_tp = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM tp WHERE id = ?")->execute([$id]);
                $pdo->commit();
            } catch (Exception $eTx) {
                $pdo->rollBack();
                throw $eTx;
            }
            $sendJson('success', 'Tujuan Pembelajaran (TP) beserta ATP & ITP terkait berhasil dihapus.');
        }

        if ($action === 'save_atp') {
            $id = (int)($in['id'] ?? 0);
            $idTp = (int)($in['id_tp'] ?? 0);
            $atpText = trim((string)($in['atp'] ?? ''));
            $urutan = max(1, (int)($in['urutan'] ?? 1));
            if ($idTp <= 0 || $atpText === '') $sendJson('error', 'TP dan uraian ATP wajib diisi.', null, 400);

            $chk = $pdo->prepare("SELECT c.id_kelas_mata_pelajaran FROM tp t JOIN cp c ON t.id_cp = c.id WHERE t.id = ?");
            $chk->execute([$idTp]);
            $kmpId = (int)$chk->fetchColumn();
            if ($kmpId <= 0) $sendJson('error', 'TP tidak ditemukan.', null, 404);
            $assertKmpAccess($kmpId);

            if ($id > 0) {
                $pdo->prepare("UPDATE atp SET atp = ?, urutan = ? WHERE id = ?")->execute([$atpText, $urutan, $id]);
                $sendJson('success', 'ATP berhasil diperbarui.', ['id' => $id]);
            } else {
                $pdo->prepare("INSERT INTO atp (id_tp, atp, urutan) VALUES (?, ?, ?)")->execute([$idTp, $atpText, $urutan]);
                $sendJson('success', 'ATP baru berhasil ditambahkan.', ['id' => (int)$pdo->lastInsertId()]);
            }
        }

        if ($action === 'delete_atp') {
            $id = (int)($in['id'] ?? 0);
            $chk = $pdo->prepare("SELECT c.id_kelas_mata_pelajaran FROM atp a JOIN tp t ON a.id_tp = t.id JOIN cp c ON t.id_cp = c.id WHERE a.id = ?");
            $chk->execute([$id]);
            $kmpId = (int)$chk->fetchColumn();
            if ($kmpId <= 0) $sendJson('error', 'ATP tidak ditemukan.', null, 404);
            $assertKmpAccess($kmpId);

            $pdo->prepare("DELETE FROM atp WHERE id = ?")->execute([$id]);
            $sendJson('success', 'ATP berhasil dihapus.');
        }

        if ($action === 'save_itp') {
            $id = (int)($in['id'] ?? 0);
            $idTp = (int)($in['id_tp'] ?? 0);
            $kodeItp = trim((string)($in['kode_itp'] ?? ''));
            $itpText = trim((string)($in['itp'] ?? ''));
            $descKet = trim((string)($in['deskripsi_ketercapaian'] ?? ''));
            $urutan = max(1, (int)($in['urutan'] ?? 1));
            if ($idTp <= 0 || $itpText === '') $sendJson('error', 'TP dan uraian Indikator (ITP) wajib diisi.', null, 400);

            $chk = $pdo->prepare("SELECT c.id_kelas_mata_pelajaran FROM tp t JOIN cp c ON t.id_cp = c.id WHERE t.id = ?");
            $chk->execute([$idTp]);
            $kmpId = (int)$chk->fetchColumn();
            if ($kmpId <= 0) $sendJson('error', 'TP tidak ditemukan.', null, 404);
            $assertKmpAccess($kmpId);

            if ($id > 0) {
                $pdo->prepare("UPDATE itp SET kode_itp = ?, itp = ?, deskripsi_ketercapaian = ?, urutan = ? WHERE id = ?")
                    ->execute([$kodeItp, $itpText, $descKet, $urutan, $id]);
                $sendJson('success', 'Indikator Ketercapaian (ITP) berhasil diperbarui.', ['id' => $id]);
            } else {
                $pdo->prepare("INSERT INTO itp (id_tp, kode_itp, itp, deskripsi_ketercapaian, urutan) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$idTp, $kodeItp, $itpText, $descKet, $urutan]);
                $sendJson('success', 'Indikator Ketercapaian (ITP) baru berhasil ditambahkan.', ['id' => (int)$pdo->lastInsertId()]);
            }
        }

        if ($action === 'delete_itp') {
            $id = (int)($in['id'] ?? 0);
            $chk = $pdo->prepare("SELECT c.id_kelas_mata_pelajaran FROM itp i JOIN tp t ON i.id_tp = t.id JOIN cp c ON t.id_cp = c.id WHERE i.id = ?");
            $chk->execute([$id]);
            $kmpId = (int)$chk->fetchColumn();
            if ($kmpId <= 0) $sendJson('error', 'ITP tidak ditemukan.', null, 404);
            $assertKmpAccess($kmpId);

            $pdo->prepare("DELETE FROM itp WHERE id = ?")->execute([$id]);
            $sendJson('success', 'Indikator Ketercapaian (ITP) berhasil dihapus.');
        }

        /* ----------------------------------------------------------------------
         * 5. LIST MODUL AJAR PER KMP (SUB-VIEW PERTEMUAN)
         * ---------------------------------------------------------------------- */
        if ($action === 'list_modul') {
            $idKmp = $assertKmpAccess($in['id_kmp'] ?? 0);
            $statusFilter = trim((string)($in['status'] ?? ''));
            $tahunFilter = trim((string)($in['tahun'] ?? ''));
            $q = trim((string)($in['q'] ?? ''));

            $kmpInfo = $kmpById[$idKmp] ?? null;
            if (!$kmpInfo) $sendJson('error', 'Data kelas & mata pelajaran tidak ditemukan.', null, 404);

            // Readiness counts
            $cpCntStmt = $pdo->prepare("SELECT COUNT(*) FROM cp WHERE id_kelas_mata_pelajaran = ?");
            $cpCntStmt->execute([$idKmp]);
            $cpCount = (int)$cpCntStmt->fetchColumn();

            $tpCntStmt = $pdo->prepare("SELECT COUNT(t.id) FROM tp t JOIN cp c ON t.id_cp = c.id WHERE c.id_kelas_mata_pelajaran = ?");
            $tpCntStmt->execute([$idKmp]);
            $tpCount = (int)$tpCntStmt->fetchColumn();

            // Check if sibling classes of same mapel have CP (for 1-click sync banner!)
            $siblingWithCp = null;
            if ($cpCount === 0) {
                $sibStmt = $pdo->prepare("
                    SELECT kmp.id AS id_kmp, k.nama_kelas, COUNT(c.id) AS cnt
                    FROM kelas_mata_pelajaran kmp
                    JOIN kelas k ON kmp.id_kelas = k.id
                    JOIN cp c ON c.id_kelas_mata_pelajaran = kmp.id
                    WHERE kmp.id_mata_pelajaran = ? AND kmp.id != ?
                    GROUP BY kmp.id, k.nama_kelas
                    HAVING cnt > 0
                    ORDER BY cnt DESC
                    LIMIT 1
                ");
                $sibStmt->execute([$kmpInfo['id_mata_pelajaran'], $idKmp]);
                $siblingWithCp = $sibStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            // Next pertemuan number
            $maxPStmt = $pdo->prepare("SELECT COALESCE(MAX(pertemuan_ke), 0) FROM modul_ajar WHERE id_kelas_mata_pelajaran = ?");
            $maxPStmt->execute([$idKmp]);
            $nextPertemuan = (int)$maxPStmt->fetchColumn() + 1;

            // Query modul_ajar
            $where = ["ma.id_kelas_mata_pelajaran = ?"];
            $params = [$idKmp];
            if ($statusFilter === 'Final') {
                $where[] = "COALESCE(ma.meeting_status, 'berjalan') = 'final'";
            } elseif (in_array($statusFilter, ['Draft', 'Published', 'Archived'], true)) {
                $where[] = "ma.status = ?";
                $params[] = $statusFilter;
                if ($statusFilter === 'Published') {
                    $where[] = "COALESCE(ma.meeting_status, 'berjalan') != 'final'";
                }
            }
            if ($tahunFilter !== '') {
                $where[] = "COALESCE(ma.tahun_pelajaran, '2026/2027') = ?";
                $params[] = $tahunFilter;
            }
            if ($q !== '') {
                $where[] = "(ma.judul_modul LIKE ? OR CAST(ma.pertemuan_ke AS CHAR) LIKE ? OR ma.tp_custom LIKE ?)";
                $like = '%' . $q . '%';
                array_push($params, $like, $like, $like);
            }

            $stmt = $pdo->prepare("
                SELECT ma.*,
                       mp_mod.nama_model AS primary_model_name,
                       mt_mod.nama_metode AS primary_metode_name,
                       tp_rel.kode_tp AS primary_kode_tp
                FROM modul_ajar ma
                LEFT JOIN model_pembelajaran mp_mod ON ma.id_model_pembelajaran = mp_mod.id
                LEFT JOIN metode_pembelajaran mt_mod ON ma.id_metode_pembelajaran = mt_mod.id
                LEFT JOIN tp tp_rel ON ma.id_tp = tp_rel.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY ma.pertemuan_ke ASC, ma.id DESC
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $modulList = [];
            foreach ($rows as $r) {
                $modulList[] = normalizeModulRow($pdo, $r);
            }

            // Sibling KMPs in same mapel for quick switcher
            $siblings = [];
            foreach ($kmpRows as $kr) {
                if ((int)$kr['id_mata_pelajaran'] === (int)$kmpInfo['id_mata_pelajaran']) {
                    $siblings[] = [
                        'id_kmp'     => (int)$kr['id_kmp'],
                        'nama_kelas' => $kr['nama_kelas'],
                        'fase'       => $kr['fase'],
                    ];
                }
            }

            $sendJson('success', 'Daftar pertemuan modul ajar dimuat.', [
                'kmp_info'        => $kmpInfo,
                'siblings'        => $siblings,
                'cp_count'        => $cpCount,
                'tp_count'        => $tpCount,
                'sibling_with_cp' => $siblingWithCp,
                'next_pertemuan'  => $nextPertemuan,
                'modul_list'      => $modulList,
            ]);
        }

        /* ----------------------------------------------------------------------
         * 6. GET CURICULUM OPTIONS FOR WIZARD / DETAIL (CP, TP, ATP, ITP BY KMP)
         * ---------------------------------------------------------------------- */
        if ($action === 'get_kmp_curriculum') {
            $idKmp = $assertKmpAccess($in['id_kmp'] ?? 0);

            $cps = $pdo->prepare("
                SELECT c.id, c.id_elemen, c.cp, e.nama_elemen, e.fase
                FROM cp c
                LEFT JOIN elemen e ON c.id_elemen = e.id
                WHERE c.id_kelas_mata_pelajaran = ?
                ORDER BY c.id ASC
            ");
            $cps->execute([$idKmp]);
            $cpList = $cps->fetchAll(PDO::FETCH_ASSOC);

            $tps = $pdo->prepare("
                SELECT t.id, t.id_cp, t.kode_tp, t.tp, t.materi_pokok, t.alokasi_jam
                FROM tp t
                JOIN cp c ON t.id_cp = c.id
                WHERE c.id_kelas_mata_pelajaran = ?
                ORDER BY t.id ASC
            ");
            $tps->execute([$idKmp]);
            $tpList = $tps->fetchAll(PDO::FETCH_ASSOC);

            $atpByTp = [];
            $itpByTp = [];
            if (!empty($tpList)) {
                $tpIds = array_map(function ($r) { return (int)$r['id']; }, $tpList);
                $inTp = implode(',', $tpIds);
                foreach ($pdo->query("SELECT * FROM atp WHERE id_tp IN ($inTp) ORDER BY urutan ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) as $a) {
                    $atpByTp[(int)$a['id_tp']][] = $a;
                }
                foreach ($pdo->query("SELECT * FROM itp WHERE id_tp IN ($inTp) ORDER BY urutan ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) as $i) {
                    $itpByTp[(int)$i['id_tp']][] = $i;
                }
            }
            foreach ($tpList as &$t) {
                $t['id'] = (int)$t['id'];
                $t['id_cp'] = (int)$t['id_cp'];
                $t['atp_list'] = $atpByTp[$t['id']] ?? [];
                $t['itp_list'] = $itpByTp[$t['id']] ?? [];
            }
            unset($t);

            $maxPStmt = $pdo->prepare("SELECT COALESCE(MAX(pertemuan_ke), 0) FROM modul_ajar WHERE id_kelas_mata_pelajaran = ?");
            $maxPStmt->execute([$idKmp]);
            $nextP = (int)$maxPStmt->fetchColumn() + 1;

            $sendJson('success', 'Kurikulum KMP dimuat.', [
                'cp_list'        => $cpList,
                'tp_list'        => $tpList,
                'next_pertemuan' => $nextP,
                'fase'           => $kmpById[$idKmp]['fase'] ?? 'Fase E',
            ]);
        }

        /* ----------------------------------------------------------------------
         * 7. GET MODUL DETAIL
         * ---------------------------------------------------------------------- */
        if ($action === 'get_modul_detail') {
            $id = (int)($in['id'] ?? 0);
            $stmt = $pdo->prepare("
                SELECT ma.*, k.nama_kelas, mp.nama_mata_pelajaran, mp.kode_mapel, kmp.id_mata_pelajaran,
                       COALESCE(g.nama_guru, 'Dewan Guru') AS nama_guru
                FROM modul_ajar ma
                JOIN kelas_mata_pelajaran kmp ON ma.id_kelas_mata_pelajaran = kmp.id
                JOIN kelas k ON kmp.id_kelas = k.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
                LEFT JOIN guru g ON gmp.id_guru = g.id
                WHERE ma.id = ?
                LIMIT 1
            ");
            $stmt->execute([$id]);
            $raw = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$raw) $sendJson('error', 'Modul ajar tidak ditemukan.', null, 404);

            $idKmp = $assertKmpAccess($raw['id_kelas_mata_pelajaran']);
            $modul = normalizeModulRow($pdo, $raw);

            // Load KMP's CP & TP options
            $cps = $pdo->prepare("SELECT c.id, c.id_elemen, c.cp, e.nama_elemen, e.fase FROM cp c LEFT JOIN elemen e ON c.id_elemen = e.id WHERE c.id_kelas_mata_pelajaran = ? ORDER BY c.id ASC");
            $cps->execute([$idKmp]);
            $cpList = $cps->fetchAll(PDO::FETCH_ASSOC);

            $tps = $pdo->prepare("SELECT t.id, t.id_cp, t.kode_tp, t.tp, t.materi_pokok, t.alokasi_jam FROM tp t JOIN cp c ON t.id_cp = c.id WHERE c.id_kelas_mata_pelajaran = ? ORDER BY t.id ASC");
            $tps->execute([$idKmp]);
            $tpList = $tps->fetchAll(PDO::FETCH_ASSOC);

            $atpByTp = [];
            $itpByTp = [];
            if (!empty($tpList)) {
                $tpIds = array_map(function ($r) { return (int)$r['id']; }, $tpList);
                $inTp = implode(',', $tpIds);
                foreach ($pdo->query("SELECT * FROM atp WHERE id_tp IN ($inTp) ORDER BY urutan ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) as $a) {
                    $atpByTp[(int)$a['id_tp']][] = $a;
                }
                foreach ($pdo->query("SELECT * FROM itp WHERE id_tp IN ($inTp) ORDER BY urutan ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) as $i) {
                    $itpByTp[(int)$i['id_tp']][] = $i;
                }
            }
            foreach ($tpList as &$t) {
                $t['id'] = (int)$t['id'];
                $t['id_cp'] = (int)$t['id_cp'];
                $t['atp_list'] = $atpByTp[$t['id']] ?? [];
                $t['itp_list'] = $itpByTp[$t['id']] ?? [];
            }
            unset($t);

            $sendJson('success', 'Detail modul ajar berhasil dimuat.', [
                'modul'   => $modul,
                'cp_list' => $cpList,
                'tp_list' => $tpList,
            ]);
        }

        /* ----------------------------------------------------------------------
         * 8. CREATE MODUL AJAR (6-STEP WIZARD — SUPPORT MULTI-KELAS & UPLOAD)
         * ---------------------------------------------------------------------- */
        if ($action === 'create_modul') {
            $kmpIds = parseJsonIdArray($in['kmp_ids'] ?? ($in['id_kelas_mata_pelajaran'] ?? []));
            if (empty($kmpIds)) {
                $sendJson('error', 'Pilih minimal 1 Kelas & Mata Pelajaran.', null, 400);
            }
            foreach ($kmpIds as $kid) {
                $assertKmpAccess($kid);
            }

            $judul = trim((string)($in['judul_modul'] ?? ''));
            if ($judul === '') {
                $sendJson('error', 'Judul Modul Ajar / Materi Pokok wajib diisi.', null, 400);
            }

            $pertemuanInput = max(1, (int)($in['pertemuan_ke'] ?? 1));
            $tahun = trim((string)($in['tahun_pelajaran'] ?? '2026/2027')) ?: '2026/2027';
            $semester = trim((string)($in['semester'] ?? 'Ganjil')) ?: 'Ganjil';
            $tanggal = trim((string)($in['tanggal'] ?? '')) ?: null;
            $jamMulai = trim((string)($in['jam_mulai'] ?? '')) ?: null;
            $jamSelesai = trim((string)($in['jam_selesai'] ?? '')) ?: null;

            $alokasiPerJp = max(10, (int)($in['alokasi_per_jp'] ?? 45));
            $jumlahJp = max(1, (int)($in['jumlah_jp'] ?? 2));
            $totalMenit = $alokasiPerJp * $jumlahJp;
            $alokasiStr = $jumlahJp . ' x ' . $alokasiPerJp . ' Menit';

            $durPembukaan = max(0, (int)($in['durasi_pembukaan'] ?? round($totalMenit * 0.15)));
            $durInti = max(0, (int)($in['durasi_inti'] ?? round($totalMenit * 0.70)));
            $durPenutup = max(0, (int)($in['durasi_penutup'] ?? max(5, $totalMenit - $durPembukaan - $durInti)));

            $cpIds = parseJsonIdArray($in['cp_ids'] ?? []);
            $tpIds = parseJsonIdArray($in['tp_ids'] ?? []);
            $modelIds = parseJsonIdArray($in['model_ids'] ?? []);
            $metodeIds = parseJsonIdArray($in['metode_ids'] ?? []);
            $idMateri = (int)($in['id_materi'] ?? 0) ?: null;

            $tpCustom = trim((string)($in['tp_custom'] ?? ''));
            $itpCustom = trim((string)($in['itp_custom'] ?? ''));
            $atpCustom = trim((string)($in['atp_custom'] ?? ''));
            $mediaStr = trim((string)($in['media_pembelajaran'] ?? ''));
            $sumberStr = trim((string)($in['sumber_belajar'] ?? ''));
            $p5Str = trim((string)($in['profil_pelajar_pancasila'] ?? ''));
            $targetDidik = trim((string)($in['target_peserta_didik'] ?? '')) ?: 'Peserta Didik Reguler / Tipikal';
            $pemahaman = trim((string)($in['pemahaman_bermakna'] ?? ''));
            $pemantik = trim((string)($in['pertanyaan_pemantik'] ?? ''));
            $persiapan = trim((string)($in['persiapan_pembelajaran'] ?? ''));
            $kegPendahuluan = trim((string)($in['kegiatan_pendahuluan'] ?? ''));
            $kegInti = trim((string)($in['kegiatan_inti'] ?? ''));
            $kegPenutup = trim((string)($in['kegiatan_penutup'] ?? ''));
            $asDiag = trim((string)($in['asesmen_diagnostik'] ?? ''));
            $asForm = trim((string)($in['asesmen_formatif'] ?? ''));
            $asSum = trim((string)($in['asesmen_sumatif'] ?? ''));
            $pengRem = trim((string)($in['pengayaan_remedial'] ?? ''));
            $refGuru = trim((string)($in['refleksi_guru'] ?? ''));
            $refSiswa = trim((string)($in['refleksi_siswa'] ?? ''));
            $refGabung = trim(($refGuru ? "Refleksi Guru:\n" . $refGuru : '') . ($refGuru && $refSiswa ? "\n\n" : '') . ($refSiswa ? "Refleksi Siswa:\n" . $refSiswa : ''));
            $lkpd = trim((string)($in['lkpd'] ?? ''));
            $bahanBacaan = trim((string)($in['bahan_bacaan'] ?? ''));
            $glosarium = trim((string)($in['glosarium'] ?? ''));
            $statusInit = in_array(($in['status'] ?? 'Draft'), ['Draft', 'Published'], true) ? $in['status'] : 'Draft';

            // Handle optional file upload
            $lampiranArr = [];
            $primaryLampiranPath = null;
            if (!empty($_FILES['file_lampiran'])) {
                $uploaded = handleModulFileUpload($_FILES['file_lampiran']);
                if ($uploaded) {
                    $lampiranArr[] = $uploaded;
                    $primaryLampiranPath = $uploaded['file_path'];
                }
            }
            $lampiranJson = !empty($lampiranArr) ? json_encode($lampiranArr, JSON_UNESCAPED_UNICODE) : null;

            $primaryCp = !empty($cpIds) ? $cpIds[0] : null;
            $primaryTp = !empty($tpIds) ? $tpIds[0] : null;
            $primaryModel = !empty($modelIds) ? $modelIds[0] : null;
            $primaryMetode = !empty($metodeIds) ? $metodeIds[0] : null;

            // Lookup optional primary ATP & ITP from primary TP
            $primaryAtp = null;
            $primaryItp = null;
            if ($primaryTp) {
                $sA = $pdo->prepare("SELECT id FROM atp WHERE id_tp = ? ORDER BY urutan ASC, id ASC LIMIT 1");
                $sA->execute([$primaryTp]);
                $primaryAtp = $sA->fetchColumn() ?: null;
                $sI = $pdo->prepare("SELECT id FROM itp WHERE id_tp = ? ORDER BY urutan ASC, id ASC LIMIT 1");
                $sI->execute([$primaryTp]);
                $primaryItp = $sI->fetchColumn() ?: null;
            }

            $createdIds = [];
            $pdo->beginTransaction();
            try {
                $insSql = "
                    INSERT INTO modul_ajar (
                        id_kelas_mata_pelajaran, id_cp, id_tp, id_atp, id_itp,
                        id_model_pembelajaran, id_metode_pembelajaran, id_materi, id_guru,
                        pertemuan_ke, judul_modul, fase, alokasi_waktu,
                        tahun_pelajaran, semester, tanggal, jam_mulai, jam_selesai,
                        alokasi_per_jp, jumlah_jp, durasi_pembukaan, durasi_inti, durasi_penutup,
                        profil_pelajar_pancasila, sarana_prasarana, target_peserta_didik,
                        pemahaman_bermakna, pertanyaan_pemantik, persiapan_pembelajaran,
                        kegiatan_pendahuluan, kegiatan_inti, kegiatan_penutup,
                        asesmen_diagnostik, asesmen_formatif, asesmen_sumatif, pengayaan_remedial,
                        refleksi_guru_siswa, refleksi_guru, refleksi_siswa,
                        lkpd, bahan_bacaan, glosarium, daftar_pustaka,
                        cp_ids_json, tp_ids_json, tp_custom, itp_custom, atp_custom,
                        model_ids_json, metode_ids_json, media_pembelajaran, sumber_belajar,
                        status_pembukaan, status_inti, status_penutup, meeting_status,
                        file_lampiran, lampiran_json, status
                    ) VALUES (
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        'belum', 'belum', 'belum', 'berjalan',
                        ?, ?, ?
                    )
                ";
                $stmtIns = $pdo->prepare($insSql);

                foreach ($kmpIds as $idx => $kid) {
                    $faseRow = trim((string)($in['fase'] ?? '')) ?: ($kmpById[$kid]['fase'] ?? 'Fase E');
                    // Jika multi-kelas, gunakan pertemuan_ke input untuk kelas pertama, dan cek auto jika bentrok pada kelas lain
                    $pertKe = $pertemuanInput;
                    if ($idx > 0) {
                        $chkP = $pdo->prepare("SELECT COUNT(*) FROM modul_ajar WHERE id_kelas_mata_pelajaran = ? AND pertemuan_ke = ?");
                        $chkP->execute([$kid, $pertKe]);
                        if ((int)$chkP->fetchColumn() > 0) {
                            $mx = $pdo->prepare("SELECT COALESCE(MAX(pertemuan_ke), 0) FROM modul_ajar WHERE id_kelas_mata_pelajaran = ?");
                            $mx->execute([$kid]);
                            $pertKe = (int)$mx->fetchColumn() + 1;
                        }
                    }

                    $stmtIns->execute([
                        $kid, $primaryCp, $primaryTp, $primaryAtp, $primaryItp,
                        $primaryModel, $primaryMetode, $idMateri, $idUserLogged,
                        $pertKe, $judul, $faseRow, $alokasiStr,
                        $tahun, $semester, $tanggal, $jamMulai, $jamSelesai,
                        $alokasiPerJp, $jumlahJp, $durPembukaan, $durInti, $durPenutup,
                        $p5Str, $mediaStr, $targetDidik,
                        $pemahaman, $pemantik, $persiapan,
                        $kegPendahuluan, $kegInti, $kegPenutup,
                        $asDiag, $asForm, $asSum, $pengRem,
                        $refGabung, $refGuru, $refSiswa,
                        $lkpd, $bahanBacaan, $glosarium, $sumberStr,
                        json_encode($cpIds), json_encode($tpIds), $tpCustom, $itpCustom, $atpCustom,
                        json_encode($modelIds), json_encode($metodeIds), $mediaStr, $sumberStr,
                        $primaryLampiranPath, $lampiranJson, $statusInit
                    ]);
                    $createdIds[] = (int)$pdo->lastInsertId();
                }
                $pdo->commit();
            } catch (Exception $eTx) {
                $pdo->rollBack();
                throw $eTx;
            }

            $cntCreated = count($createdIds);
            $sendJson('success', "Berhasil menyimpan {$cntCreated} Modul Ajar.", [
                'ids'         => $createdIds,
                'primary_id'  => $createdIds[0] ?? 0,
                'primary_kmp' => $kmpIds[0],
            ]);
        }

        /* ----------------------------------------------------------------------
         * 9. UPDATE MODUL AJAR (HANYA SAAT DRAFT & BELUM FINAL)
         * ---------------------------------------------------------------------- */
        if ($action === 'update_modul') {
            $id = (int)($in['id'] ?? 0);
            $cur = $pdo->prepare("SELECT * FROM modul_ajar WHERE id = ? LIMIT 1");
            $cur->execute([$id]);
            $existing = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$existing) $sendJson('error', 'Modul ajar tidak ditemukan.', null, 404);
            $assertKmpAccess($existing['id_kelas_mata_pelajaran']);

            if (($existing['meeting_status'] ?? 'berjalan') === 'final') {
                $sendJson('error', 'Modul ajar sudah difinalisasi dan terkunci permanen.', null, 400);
            }
            if ($existing['status'] !== 'Draft') {
                $sendJson('error', 'Hanya modul berstatus Draft yang dapat diedit. Kembalikan ke Draft terlebih dahulu.', null, 400);
            }

            $judul = trim((string)($in['judul_modul'] ?? $existing['judul_modul']));
            if ($judul === '') $sendJson('error', 'Judul modul ajar wajib diisi.', null, 400);

            $pertKe = max(1, (int)($in['pertemuan_ke'] ?? $existing['pertemuan_ke']));
            $fase = trim((string)($in['fase'] ?? $existing['fase'])) ?: 'Fase E';
            $tahun = trim((string)($in['tahun_pelajaran'] ?? $existing['tahun_pelajaran'])) ?: '2026/2027';
            $semester = trim((string)($in['semester'] ?? $existing['semester'])) ?: 'Ganjil';
            $tanggal = trim((string)($in['tanggal'] ?? '')) ?: null;
            $jamMulai = trim((string)($in['jam_mulai'] ?? '')) ?: null;
            $jamSelesai = trim((string)($in['jam_selesai'] ?? '')) ?: null;

            $alokasiPerJp = max(10, (int)($in['alokasi_per_jp'] ?? 45));
            $jumlahJp = max(1, (int)($in['jumlah_jp'] ?? 2));
            $alokasiStr = $jumlahJp . ' x ' . $alokasiPerJp . ' Menit';
            $durPembukaan = max(0, (int)($in['durasi_pembukaan'] ?? 15));
            $durInti = max(0, (int)($in['durasi_inti'] ?? 60));
            $durPenutup = max(0, (int)($in['durasi_penutup'] ?? 15));

            $cpIds = parseJsonIdArray($in['cp_ids'] ?? []);
            $tpIds = parseJsonIdArray($in['tp_ids'] ?? []);
            $modelIds = parseJsonIdArray($in['model_ids'] ?? []);
            $metodeIds = parseJsonIdArray($in['metode_ids'] ?? []);
            $idMateri = (int)($in['id_materi'] ?? 0) ?: null;

            $primaryCp = !empty($cpIds) ? $cpIds[0] : null;
            $primaryTp = !empty($tpIds) ? $tpIds[0] : null;
            $primaryModel = !empty($modelIds) ? $modelIds[0] : null;
            $primaryMetode = !empty($metodeIds) ? $metodeIds[0] : null;

            $mediaStr = trim((string)($in['media_pembelajaran'] ?? ''));
            $sumberStr = trim((string)($in['sumber_belajar'] ?? ''));
            $refGuru = trim((string)($in['refleksi_guru'] ?? ''));
            $refSiswa = trim((string)($in['refleksi_siswa'] ?? ''));
            $refGabung = trim(($refGuru ? "Refleksi Guru:\n" . $refGuru : '') . ($refGuru && $refSiswa ? "\n\n" : '') . ($refSiswa ? "Refleksi Siswa:\n" . $refSiswa : ''));

            $updSql = "
                UPDATE modul_ajar SET
                    id_cp = ?, id_tp = ?, id_model_pembelajaran = ?, id_metode_pembelajaran = ?, id_materi = ?,
                    pertemuan_ke = ?, judul_modul = ?, fase = ?, alokasi_waktu = ?,
                    tahun_pelajaran = ?, semester = ?, tanggal = ?, jam_mulai = ?, jam_selesai = ?,
                    alokasi_per_jp = ?, jumlah_jp = ?, durasi_pembukaan = ?, durasi_inti = ?, durasi_penutup = ?,
                    profil_pelajar_pancasila = ?, sarana_prasarana = ?, target_peserta_didik = ?,
                    pemahaman_bermakna = ?, pertanyaan_pemantik = ?, persiapan_pembelajaran = ?,
                    kegiatan_pendahuluan = ?, kegiatan_inti = ?, kegiatan_penutup = ?,
                    asesmen_diagnostik = ?, asesmen_formatif = ?, asesmen_sumatif = ?, pengayaan_remedial = ?,
                    refleksi_guru_siswa = ?, refleksi_guru = ?, refleksi_siswa = ?,
                    lkpd = ?, bahan_bacaan = ?, glosarium = ?, daftar_pustaka = ?,
                    cp_ids_json = ?, tp_ids_json = ?, tp_custom = ?, itp_custom = ?, atp_custom = ?,
                    model_ids_json = ?, metode_ids_json = ?, media_pembelajaran = ?, sumber_belajar = ?
                WHERE id = ?
            ";
            $pdo->prepare($updSql)->execute([
                $primaryCp, $primaryTp, $primaryModel, $primaryMetode, $idMateri,
                $pertKe, $judul, $fase, $alokasiStr,
                $tahun, $semester, $tanggal, $jamMulai, $jamSelesai,
                $alokasiPerJp, $jumlahJp, $durPembukaan, $durInti, $durPenutup,
                trim((string)($in['profil_pelajar_pancasila'] ?? '')), $mediaStr, trim((string)($in['target_peserta_didik'] ?? '')) ?: 'Peserta Didik Reguler / Tipikal',
                trim((string)($in['pemahaman_bermakna'] ?? '')), trim((string)($in['pertanyaan_pemantik'] ?? '')), trim((string)($in['persiapan_pembelajaran'] ?? '')),
                trim((string)($in['kegiatan_pendahuluan'] ?? '')), trim((string)($in['kegiatan_inti'] ?? '')), trim((string)($in['kegiatan_penutup'] ?? '')),
                trim((string)($in['asesmen_diagnostik'] ?? '')), trim((string)($in['asesmen_formatif'] ?? '')), trim((string)($in['asesmen_sumatif'] ?? '')), trim((string)($in['pengayaan_remedial'] ?? '')),
                $refGabung, $refGuru, $refSiswa,
                trim((string)($in['lkpd'] ?? '')), trim((string)($in['bahan_bacaan'] ?? '')), trim((string)($in['glosarium'] ?? '')), $sumberStr,
                json_encode($cpIds), json_encode($tpIds), trim((string)($in['tp_custom'] ?? '')), trim((string)($in['itp_custom'] ?? '')), trim((string)($in['atp_custom'] ?? '')),
                json_encode($modelIds), json_encode($metodeIds), $mediaStr, $sumberStr,
                $id
            ]);

            $sendJson('success', 'Perubahan modul ajar berhasil disimpan.', ['id' => $id]);
        }

        /* ----------------------------------------------------------------------
         * 10. PUBLISH / UNPUBLISH, KETERLAKSANAAN, FINALISASI, DUPLIKAT, HAPUS
         * ---------------------------------------------------------------------- */
        if ($action === 'toggle_publish') {
            $id = (int)($in['id'] ?? 0);
            $cur = $pdo->prepare("SELECT id, id_kelas_mata_pelajaran, status, meeting_status FROM modul_ajar WHERE id = ?");
            $cur->execute([$id]);
            $row = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$row) $sendJson('error', 'Modul ajar tidak ditemukan.', null, 404);
            $assertKmpAccess($row['id_kelas_mata_pelajaran']);

            if (($row['meeting_status'] ?? 'berjalan') === 'final') {
                $sendJson('error', 'Modul yang sudah difinalisasi tidak dapat diubah statusnya.', null, 400);
            }

            $target = ($row['status'] === 'Published') ? 'Draft' : 'Published';
            $pdo->prepare("UPDATE modul_ajar SET status = ? WHERE id = ?")->execute([$target, $id]);
            $msg = ($target === 'Published')
                ? 'Modul ajar berhasil diterbitkan! Sekarang Anda dapat mencatat status keterlaksanaan kegiatan.'
                : 'Modul ajar dikembalikan ke status Draft dan dapat diedit kembali.';
            $sendJson('success', $msg, ['status' => $target]);
        }

        if ($action === 'save_keterlaksanaan') {
            $id = (int)($in['id'] ?? 0);
            $cur = $pdo->prepare("SELECT id, id_kelas_mata_pelajaran, status, meeting_status FROM modul_ajar WHERE id = ?");
            $cur->execute([$id]);
            $row = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$row) $sendJson('error', 'Modul ajar tidak ditemukan.', null, 404);
            $assertKmpAccess($row['id_kelas_mata_pelajaran']);

            if (($row['meeting_status'] ?? 'berjalan') === 'final') {
                $sendJson('error', 'Modul sudah difinalisasi, status keterlaksanaan tidak dapat diubah.', null, 400);
            }
            if ($row['status'] !== 'Published') {
                $sendJson('error', 'Terbitkan (Publish) modul ajar terlebih dahulu sebelum mengisi keterlaksanaan.', null, 400);
            }

            $validSt = ['belum', 'terlaksana', 'sebagian'];
            $sp = in_array(($in['status_pembukaan'] ?? 'belum'), $validSt, true) ? $in['status_pembukaan'] : 'belum';
            $si = in_array(($in['status_inti'] ?? 'belum'), $validSt, true) ? $in['status_inti'] : 'belum';
            $sn = in_array(($in['status_penutup'] ?? 'belum'), $validSt, true) ? $in['status_penutup'] : 'belum';

            $pdo->prepare("
                UPDATE modul_ajar
                SET status_pembukaan = ?, status_inti = ?, status_penutup = ?,
                    catatan_pembukaan = ?, catatan_inti = ?, catatan_penutup = ?
                WHERE id = ?
            ")->execute([
                $sp, $si, $sn,
                trim((string)($in['catatan_pembukaan'] ?? '')),
                trim((string)($in['catatan_inti'] ?? '')),
                trim((string)($in['catatan_penutup'] ?? '')),
                $id
            ]);

            $sendJson('success', 'Status keterlaksanaan kegiatan berhasil disimpan.');
        }

        if ($action === 'finalize_modul') {
            $id = (int)($in['id'] ?? 0);
            $cur = $pdo->prepare("SELECT id, id_kelas_mata_pelajaran, status, meeting_status FROM modul_ajar WHERE id = ?");
            $cur->execute([$id]);
            $row = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$row) $sendJson('error', 'Modul ajar tidak ditemukan.', null, 404);
            $assertKmpAccess($row['id_kelas_mata_pelajaran']);

            if ($row['status'] !== 'Published') {
                $sendJson('error', 'Modul ajar harus berstatus Published terlebih dahulu sebelum difinalisasi.', null, 400);
            }
            $pdo->prepare("UPDATE modul_ajar SET meeting_status = 'final' WHERE id = ?")->execute([$id]);
            $sendJson('success', 'Pertemuan modul ajar berhasil difinalisasi dan dikunci secara permanen.', ['meeting_status' => 'final']);
        }

        if ($action === 'duplicate_modul') {
            $id = (int)($in['id'] ?? 0);
            $cur = $pdo->prepare("SELECT * FROM modul_ajar WHERE id = ?");
            $cur->execute([$id]);
            $src = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$src) $sendJson('error', 'Modul ajar sumber tidak ditemukan.', null, 404);
            $idKmp = $assertKmpAccess($src['id_kelas_mata_pelajaran']);

            $mx = $pdo->prepare("SELECT COALESCE(MAX(pertemuan_ke), 0) FROM modul_ajar WHERE id_kelas_mata_pelajaran = ?");
            $mx->execute([$idKmp]);
            $nextP = (int)$mx->fetchColumn() + 1;

            unset($src['id'], $src['created_at'], $src['updated_at']);
            $src['pertemuan_ke'] = $nextP;
            $src['judul_modul'] = $src['judul_modul'] . ' (Salinan)';
            $src['status'] = 'Draft';
            $src['meeting_status'] = 'berjalan';
            $src['status_pembukaan'] = 'belum';
            $src['status_inti'] = 'belum';
            $src['status_penutup'] = 'belum';
            $src['catatan_pembukaan'] = null;
            $src['catatan_inti'] = null;
            $src['catatan_penutup'] = null;
            $src['id_guru'] = $idUserLogged;

            $cols = array_keys($src);
            $colList = '`' . implode('`,`', $cols) . '`';
            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $pdo->prepare("INSERT INTO modul_ajar ($colList) VALUES ($placeholders)")->execute(array_values($src));
            $newId = (int)$pdo->lastInsertId();
            $sendJson('success', "Modul berhasil diduplikasi sebagai Draft Pertemuan ke-{$nextP}.", ['id' => $newId]);
        }

        if ($action === 'delete_modul') {
            $id = (int)($in['id'] ?? 0);
            $cur = $pdo->prepare("SELECT id, id_kelas_mata_pelajaran, meeting_status, file_lampiran, lampiran_json FROM modul_ajar WHERE id = ?");
            $cur->execute([$id]);
            $row = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$row) $sendJson('error', 'Modul ajar tidak ditemukan.', null, 404);
            $assertKmpAccess($row['id_kelas_mata_pelajaran']);

            if (($row['meeting_status'] ?? 'berjalan') === 'final') {
                $sendJson('error', 'Modul ajar yang sudah difinalisasi tidak dapat dihapus.', null, 400);
            }

            $files = parseLampiranList($row);
            $pdo->prepare("DELETE FROM modul_ajar WHERE id = ?")->execute([$id]);
            foreach ($files as $f) {
                $fp = BASE_PATH . '/' . ltrim((string)$f['file_path'], '/');
                if (strpos($fp, BASE_PATH . '/uploads/modul_ajar/') === 0 && is_file($fp)) {
                    @unlink($fp);
                }
            }
            $sendJson('success', 'Modul ajar berhasil dihapus.');
        }

        /* ----------------------------------------------------------------------
         * 11. LAMPIRAN UPLOAD & DELETE
         * ---------------------------------------------------------------------- */
        if ($action === 'upload_lampiran') {
            $id = (int)($in['id'] ?? 0);
            $cur = $pdo->prepare("SELECT id, id_kelas_mata_pelajaran, status, meeting_status, file_lampiran, lampiran_json FROM modul_ajar WHERE id = ?");
            $cur->execute([$id]);
            $row = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$row) $sendJson('error', 'Modul ajar tidak ditemukan.', null, 404);
            $assertKmpAccess($row['id_kelas_mata_pelajaran']);

            if (($row['meeting_status'] ?? 'berjalan') === 'final') {
                $sendJson('error', 'Modul yang sudah final tidak dapat menambah lampiran.', null, 400);
            }

            $uploaded = handleModulFileUpload($_FILES['file_lampiran'] ?? null);
            if (!$uploaded) {
                $sendJson('error', 'Pilih berkas lampiran terlebih dahulu.', null, 400);
            }

            $list = parseLampiranList($row);
            $list[] = $uploaded;
            $firstPath = $list[0]['file_path'] ?? $uploaded['file_path'];
            $pdo->prepare("UPDATE modul_ajar SET file_lampiran = ?, lampiran_json = ? WHERE id = ?")
                ->execute([$firstPath, json_encode($list, JSON_UNESCAPED_UNICODE), $id]);

            $sendJson('success', 'Lampiran berhasil diunggah.', ['lampiran' => $list]);
        }

        if ($action === 'delete_lampiran') {
            $id = (int)($in['id'] ?? 0);
            $attId = trim((string)($in['attachment_id'] ?? ''));
            $cur = $pdo->prepare("SELECT id, id_kelas_mata_pelajaran, meeting_status, file_lampiran, lampiran_json FROM modul_ajar WHERE id = ?");
            $cur->execute([$id]);
            $row = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$row) $sendJson('error', 'Modul ajar tidak ditemukan.', null, 404);
            $assertKmpAccess($row['id_kelas_mata_pelajaran']);

            if (($row['meeting_status'] ?? 'berjalan') === 'final') {
                $sendJson('error', 'Lampiran pada modul final tidak dapat dihapus.', null, 400);
            }

            $list = parseLampiranList($row);
            $newList = [];
            foreach ($list as $f) {
                if (($f['id'] ?? '') === $attId) {
                    $fp = BASE_PATH . '/' . ltrim((string)$f['file_path'], '/');
                    if (strpos($fp, BASE_PATH . '/uploads/modul_ajar/') === 0 && is_file($fp)) {
                        @unlink($fp);
                    }
                    continue;
                }
                $newList[] = $f;
            }
            $firstPath = !empty($newList) ? $newList[0]['file_path'] : null;
            $pdo->prepare("UPDATE modul_ajar SET file_lampiran = ?, lampiran_json = ? WHERE id = ?")
                ->execute([$firstPath, !empty($newList) ? json_encode($newList, JSON_UNESCAPED_UNICODE) : null, $id]);

            $sendJson('success', 'Lampiran berhasil dihapus.', ['lampiran' => $newList]);
        }

        $sendJson('error', 'Action API tidak dikenali: ' . $action, null, 400);
    } catch (Exception $e) {
        $sendJson('error', $e->getMessage(), null, 500);
    }
}

/* ==============================================================================
 * RENDER HALAMAN UTAMA (DATA-FIRST ZERO TITLE — STANDAR README.MD)
 * ============================================================================== */
$pageTitle = 'Modul Ajar (CP & TP)';
$pageIcon  = 'solar:book-bookmark-bold';

$accessCssVer = file_exists(BASE_PATH . '/assets/css/admin-assessment-access.css') ? filemtime(BASE_PATH . '/assets/css/admin-assessment-access.css') : '1.0';
$maCssVer     = file_exists(BASE_PATH . '/assets/css/guru-modul-ajar.css') ? filemtime(BASE_PATH . '/assets/css/guru-modul-ajar.css') : '1.0';
$maJsVer      = file_exists(BASE_PATH . '/assets/js/guru-modul-ajar.js') ? filemtime(BASE_PATH . '/assets/js/guru-modul-ajar.js') : '1.0';

$extraHead = '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/admin-assessment-access.css?v=' . $accessCssVer . '">'
           . '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/guru-modul-ajar.css?v=' . $maCssVer . '">';

include BASE_PATH . '/includes/header.php';
include BASE_PATH . '/includes/sidebar.php';
?>

<main class="ilmuna-main">
    <div class="ilmuna-ma-app" id="modulAjarApp" data-api-url="<?= htmlspecialchars(BASE_URL . '/dashboard/guru/modul-ajar') ?>">

        <!-- ==================================================================
             MAIN SEGMENTED TABS (IDENTIK CURRICULUM.PHP — DATA-FIRST ZERO TITLE)
             ALUR: 1. CP (Sudah Ada) -> 2. Buat/Kelola TP -> 3. Modul Ajar
             ================================================================== -->
        <div class="curriculum-main-tabs" id="maMainTabs" role="tablist">
            <button type="button" class="curriculum-tab-btn is-active" data-tab="modul" role="tab" aria-selected="true">
                <span class="curriculum-tab-step">3</span>
                <iconify-icon icon="solar:book-bookmark-bold"></iconify-icon>
                <span class="curriculum-tab-label">Modul Ajar</span>
                <span class="curriculum-tab-counter" id="cntTabModul">0</span>
            </button>
            <button type="button" class="curriculum-tab-btn" data-tab="cp" role="tab" aria-selected="false">
                <span class="curriculum-tab-step">1</span>
                <iconify-icon icon="solar:diploma-verified-bold"></iconify-icon>
                <span class="curriculum-tab-label">Capaian Pembelajaran (CP)</span>
                <span class="curriculum-tab-counter" id="cntTabCp">0</span>
            </button>
            <button type="button" class="curriculum-tab-btn" data-tab="tp" role="tab" aria-selected="false">
                <span class="curriculum-tab-step">2</span>
                <iconify-icon icon="solar:target-bold"></iconify-icon>
                <span class="curriculum-tab-label">Tujuan Pembelajaran (TP &amp; ATP/ITP)</span>
                <span class="curriculum-tab-counter" id="cntTabTp">0</span>
            </button>
        </div>

        <!-- ALUR KERJA RINGKAS INTERAKTIF (CP -> TP -> MODUL AJAR) -->
        <div class="ma-flow-ribbon">
            <div class="ma-flow-steps">
                <button type="button" class="ma-flow-pill" id="flowStep1" onclick="MA.switchTab('cp')">
                    <iconify-icon icon="solar:check-circle-bold"></iconify-icon>
                    <span>1. Cek / Pilih CP</span>
                </button>
                <iconify-icon icon="solar:arrow-right-linear" class="ma-flow-arrow"></iconify-icon>
                <button type="button" class="ma-flow-pill" id="flowStep2" onclick="MA.switchTab('tp')">
                    <iconify-icon icon="solar:target-bold"></iconify-icon>
                    <span>2. Susun TP, ATP &amp; ITP</span>
                </button>
                <iconify-icon icon="solar:arrow-right-linear" class="ma-flow-arrow"></iconify-icon>
                <button type="button" class="ma-flow-pill is-current" id="flowStep3" onclick="MA.switchTab('modul')">
                    <iconify-icon icon="solar:book-bookmark-bold"></iconify-icon>
                    <span>3. Buat &amp; Kelola Modul Ajar</span>
                </button>
            </div>
            <button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" onclick="MA.openCreateWizard()">
                <iconify-icon icon="solar:add-circle-bold"></iconify-icon>
                <span>Buat Modul Ajar Baru</span>
            </button>
        </div>

        <!-- ==================================================================
             TAB 3 (DEFAULT): MODUL AJAR (3 SPA SUB-VIEWS)
             ================================================================== -->
        <section class="ma-tab-pane is-active" id="paneModul">

            <!-- SUB-VIEW A: SUBJECT & CLASS GRID (ALA MODULAJARLISTPAGE) -->
            <div id="maViewSubjects">
                <div class="ilmuna-filter-box">
                    <div class="ilmuna-filter-row">
                        <div class="ilmuna-search-wrap">
                            <iconify-icon icon="solar:magnifer-bold"></iconify-icon>
                            <input type="text" class="ilmuna-search-input" id="subjSearchInput" placeholder="Cari mata pelajaran, kode mapel, atau kelas...">
                        </div>
                        <div class="ilmuna-filter-selects">
                            <select class="ilmuna-select" id="subjGradeFilter">
                                <option value="">Semua Tingkat Kelas</option>
                            </select>
                            <select class="ilmuna-select" id="subjTahunFilter">
                                <option value="">Semua Tahun Pelajaran</option>
                            </select>
                            <button type="button" class="ilmuna-btn ilmuna-btn--gold" onclick="MA.openCreateWizard()">
                                <iconify-icon icon="solar:document-add-bold"></iconify-icon>
                                <span>Tambah Modul Ajar</span>
                            </button>
                        </div>
                    </div>
                </div>

                <div id="maSubjectsContainer">
                    <div class="ma-skeleton-grid">
                        <div class="ma-skeleton-card"></div>
                        <div class="ma-skeleton-card"></div>
                        <div class="ma-skeleton-card"></div>
                    </div>
                </div>
            </div>

            <!-- SUB-VIEW B: PERTEMUAN LIST PER KELAS-MAPEL (ALA PERTEMUANLISTPAGE) -->
            <div id="maViewMeetings" style="display:none;">
                <div class="ma-subhead-bar">
                    <div class="ma-subhead-left">
                        <button type="button" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" onclick="MA.backToSubjects()">
                            <iconify-icon icon="solar:arrow-left-bold"></iconify-icon>
                            <span>Semua Mapel</span>
                        </button>
                        <div class="ma-subhead-info">
                            <h2 class="ma-subhead-title" id="meetHeadTitle">Mata Pelajaran</h2>
                            <div class="ma-subhead-meta" id="meetHeadMeta"></div>
                        </div>
                    </div>
                    <div class="ma-subhead-actions">
                        <select class="ilmuna-select" id="meetSiblingSwitch" title="Ganti Rombel Kelas"></select>
                        <button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" onclick="MA.openCreateWizard(MA.state.activeKmpId)">
                            <iconify-icon icon="solar:add-circle-bold"></iconify-icon>
                            <span>Tambah Pertemuan</span>
                        </button>
                    </div>
                </div>

                <!-- Smart Readiness Guidance (CP / TP) -->
                <div id="meetReadinessBanners"></div>

                <!-- Filter Pertemuan -->
                <div class="ilmuna-filter-box">
                    <div class="ilmuna-filter-row">
                        <div class="ilmuna-search-wrap">
                            <iconify-icon icon="solar:magnifer-bold"></iconify-icon>
                            <input type="text" class="ilmuna-search-input" id="meetSearchInput" placeholder="Cari judul materi atau nomor pertemuan...">
                        </div>
                        <div class="ilmuna-filter-selects">
                            <select class="ilmuna-select" id="meetStatusFilter">
                                <option value="">Semua Status</option>
                                <option value="Draft">Draft</option>
                                <option value="Published">Published (Aktif)</option>
                                <option value="Final">Final (Terkunci)</option>
                                <option value="Archived">Archived</option>
                            </select>
                            <select class="ilmuna-select" id="meetTahunFilter">
                                <option value="">Semua Tahun</option>
                            </select>
                            <button type="button" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" onclick="MA.jumpToTabWithKmp('cp', MA.state.activeKmpId)">
                                <iconify-icon icon="solar:diploma-verified-bold"></iconify-icon>
                                <span>Kelola CP</span>
                            </button>
                            <button type="button" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" onclick="MA.jumpToTabWithKmp('tp', MA.state.activeKmpId)">
                                <iconify-icon icon="solar:target-bold"></iconify-icon>
                                <span>Kelola TP</span>
                            </button>
                        </div>
                    </div>
                </div>

                <div id="maMeetingsContainer"></div>
            </div>

            <!-- SUB-VIEW C: DETAIL & EDITOR MODUL AJAR (ALA MODULAJARDETAILPAGE) -->
            <div id="maViewDetail" style="display:none;">
                <div class="ma-subhead-bar" id="detailActionBar">
                    <div class="ma-subhead-left">
                        <button type="button" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" onclick="MA.backToMeetings()">
                            <iconify-icon icon="solar:arrow-left-bold"></iconify-icon>
                            <span>Daftar Pertemuan</span>
                        </button>
                        <div class="ma-subhead-info">
                            <h2 class="ma-subhead-title" id="detTitle">Detail Modul Ajar</h2>
                            <div class="ma-subhead-meta" id="detSubMeta"></div>
                        </div>
                    </div>
                    <div class="ma-subhead-actions" id="detTopActions"></div>
                </div>

                <div id="maDetailContainer" class="ma-detail-layout"></div>
            </div>

        </section>

        <!-- ==================================================================
             TAB 1: CAPAIAN PEMBELAJARAN (CP) & ELEMEN
             ================================================================== -->
        <section class="ma-tab-pane" id="paneCp">
            <div class="ilmuna-filter-box">
                <div class="ilmuna-filter-row">
                    <div class="ilmuna-search-wrap">
                        <iconify-icon icon="solar:magnifer-bold"></iconify-icon>
                        <input type="text" class="ilmuna-search-input" id="cpSearchInput" placeholder="Cari deskripsi CP, elemen, atau mapel...">
                    </div>
                    <div class="ilmuna-filter-selects">
                        <select class="ilmuna-select" id="cpKmpFilter">
                            <option value="">Semua Kelas &amp; Mata Pelajaran</option>
                        </select>
                        <button type="button" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" onclick="MA.openSyncCpModal()">
                            <iconify-icon icon="solar:copy-bold"></iconify-icon>
                            <span>Sinkron CP antar Kelas</span>
                        </button>
                        <button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" onclick="MA.openCpModal()">
                            <iconify-icon icon="solar:add-circle-bold"></iconify-icon>
                            <span>Tambah CP</span>
                        </button>
                    </div>
                </div>
            </div>

            <div id="maCpContainer"></div>
        </section>

        <!-- ==================================================================
             TAB 2: TUJUAN PEMBELAJARAN (TP), ATP & ITP
             ================================================================== -->
        <section class="ma-tab-pane" id="paneTp">
            <div class="ilmuna-filter-box">
                <div class="ilmuna-filter-row">
                    <div class="ilmuna-search-wrap">
                        <iconify-icon icon="solar:magnifer-bold"></iconify-icon>
                        <input type="text" class="ilmuna-search-input" id="tpSearchInput" placeholder="Cari kode TP, materi pokok, atau deskripsi TP...">
                    </div>
                    <div class="ilmuna-filter-selects">
                        <select class="ilmuna-select" id="tpKmpFilter">
                            <option value="">Semua Kelas &amp; Mata Pelajaran</option>
                        </select>
                        <select class="ilmuna-select" id="tpCpFilter">
                            <option value="">Semua CP</option>
                        </select>
                        <button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" onclick="MA.openTpModal()">
                            <iconify-icon icon="solar:add-circle-bold"></iconify-icon>
                            <span>Buat TP Baru</span>
                        </button>
                    </div>
                </div>
            </div>

            <div id="maTpContainer"></div>
        </section>

    </div>
</main>

<!-- ==========================================================================
     MODAL 1: 6-STEP WIZARD BUAT MODUL AJAR (ALA MODULAJARCREATE)
     ========================================================================== -->
<div class="ilm-modal-backdrop" id="modalCreateModulAjar" aria-hidden="true">
    <div class="ilm-modal-dialog ma-wizard-dialog">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon">
                    <iconify-icon icon="solar:book-bookmark-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title">Buat Modul Ajar Baru</h3>
                    <p class="ilm-modal-header__subtitle" id="wizSubtitle">Langkah 1 dari 6 &bull; Informasi Dasar Pertemuan</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-close" onclick="closeIlmunaModal('modalCreateModulAjar')" aria-label="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>

        <!-- 6-Step Stepper Navigation -->
        <div class="ma-stepper" id="wizStepper">
            <button type="button" class="ma-step-pill is-active" data-step="1" onclick="MA.wizGoStep(1)"><span class="step-num">1</span>Info Dasar</button>
            <button type="button" class="ma-step-pill" data-step="2" onclick="MA.wizGoStep(2)"><span class="step-num">2</span>CP, TP &amp; Model</button>
            <button type="button" class="ma-step-pill" data-step="3" onclick="MA.wizGoStep(3)"><span class="step-num">3</span>Pemantik</button>
            <button type="button" class="ma-step-pill" data-step="4" onclick="MA.wizGoStep(4)"><span class="step-num">4</span>Kegiatan &amp; JP</button>
            <button type="button" class="ma-step-pill" data-step="5" onclick="MA.wizGoStep(5)"><span class="step-num">5</span>Asesmen &amp; P5</button>
            <button type="button" class="ma-step-pill" data-step="6" onclick="MA.wizGoStep(6)"><span class="step-num">6</span>Lampiran &amp; Simpan</button>
        </div>

        <form id="formCreateModul" onsubmit="return false;">
            <div class="ilm-modal-body" id="wizBodyScroll">

                <!-- STEP 1: INFORMASI DASAR -->
                <div class="ma-wizard-step is-active" data-step="1">
                    <div class="ma-form-grid">
                        <div class="ma-field">
                            <label class="ma-label">Mata Pelajaran <span class="req">*</span></label>
                            <select class="ma-select" id="wizMapel" onchange="MA.onWizMapelChange()"></select>
                        </div>
                        <div class="ma-field">
                            <label class="ma-label">Fase Kurikulum Merdeka</label>
                            <select class="ma-select" id="wizFase">
                                <option value="Fase E">Fase E (Kelas X)</option>
                                <option value="Fase F">Fase F (Kelas XI / XII)</option>
                                <option value="Fase D">Fase D (Kelas VII - IX)</option>
                                <option value="Fase C">Fase C (Kelas V - VI)</option>
                            </select>
                        </div>

                        <div class="ma-field ma-field--full">
                            <div class="ma-label-row">
                                <label class="ma-label">Pilih Kelas / Rombel (Bisa Multi-Kelas) <span class="req">*</span></label>
                                <button type="button" class="ma-expand-btn" onclick="MA.wizToggleAllClasses()">
                                    <iconify-icon icon="solar:checklist-bold"></iconify-icon> Pilih Semua
                                </button>
                            </div>
                            <div class="ma-check-grid" id="wizClassCheckGrid"></div>
                        </div>

                        <div class="ma-field">
                            <label class="ma-label">Pertemuan Ke- <span class="req">*</span></label>
                            <input type="number" class="ma-input" id="wizPertemuanKe" min="1" value="1">
                        </div>
                        <div class="ma-field">
                            <label class="ma-label">Tautkan Bank Materi (Opsional)</label>
                            <select class="ma-select" id="wizMateriId">
                                <option value="">-- Tidak ditautkan --</option>
                            </select>
                        </div>

                        <div class="ma-field ma-field--full">
                            <label class="ma-label">Judul Modul Ajar / Topik Materi Pokok <span class="req">*</span></label>
                            <input type="text" class="ma-input" id="wizJudul" placeholder="Contoh: Pertemuan 1 - Memahami Ayat Al-Qur'an tentang Berpikir Kritis">
                        </div>

                        <div class="ma-field">
                            <label class="ma-label">Tahun Pelajaran</label>
                            <select class="ma-select" id="wizTahun"></select>
                        </div>
                        <div class="ma-field">
                            <label class="ma-label">Semester</label>
                            <select class="ma-select" id="wizSemester">
                                <option value="Ganjil">Ganjil</option>
                                <option value="Genap">Genap</option>
                            </select>
                        </div>

                        <div class="ma-field">
                            <label class="ma-label">Tanggal Pelaksanaan</label>
                            <input type="text" class="ma-input" id="wizTanggal" data-ilm-calendar="date" placeholder="Pilih tanggal..." readonly>
                        </div>
                        <div class="ma-form-grid">
                            <div class="ma-field">
                                <label class="ma-label">Jam Mulai</label>
                                <input type="time" class="ma-input" id="wizJamMulai" value="07:30">
                            </div>
                            <div class="ma-field">
                                <label class="ma-label">Jam Selesai</label>
                                <input type="time" class="ma-input" id="wizJamSelesai" value="09:00">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- STEP 2: CP, TP, ITP, ATP & KOMPONEN PEMBELAJARAN -->
                <div class="ma-wizard-step" data-step="2">
                    <div id="wizCpEmptyWarn"></div>
                    <div class="ma-form-grid">
                        <div class="ma-field ma-field--full">
                            <div class="ma-label-row">
                                <label class="ma-label">1. Pilih Capaian Pembelajaran (CP) dari Database</label>
                                <button type="button" class="ma-expand-btn" onclick="MA.openQuickCpFromWizard()">
                                    <iconify-icon icon="solar:add-circle-bold"></iconify-icon> Tambah CP Baru
                                </button>
                            </div>
                            <div class="ma-check-list" id="wizCpList"></div>
                        </div>

                        <div class="ma-field ma-field--full">
                            <div class="ma-label-row">
                                <label class="ma-label">2. Pilih Tujuan Pembelajaran (TP) dari CP Terpilih</label>
                                <button type="button" class="ma-expand-btn" onclick="MA.openQuickTpFromWizard()">
                                    <iconify-icon icon="solar:add-circle-bold"></iconify-icon> Buat TP Cepat
                                </button>
                            </div>
                            <div class="ma-check-list" id="wizTpList"></div>
                        </div>

                        <div class="ma-field ma-field--full">
                            <div class="ma-label-row">
                                <label class="ma-label">Narasi Tujuan Pembelajaran (Otomatis dari TP Terpilih / Kustom)</label>
                                <button type="button" class="ma-expand-btn" onclick="MA.openTextPopup('Narasi Tujuan Pembelajaran (TP)', 'wizTpCustom')">
                                    <iconify-icon icon="solar:maximize-square-bold"></iconify-icon> Perbesar
                                </button>
                            </div>
                            <textarea class="ma-textarea" id="wizTpCustom" placeholder="Akan terisi otomatis saat mencentang TP di atas, atau ketik tambahan..."></textarea>
                        </div>

                        <div class="ma-field">
                            <div class="ma-label-row">
                                <label class="ma-label">Indikator Ketercapaian (ITP / IKTP)</label>
                                <button type="button" class="ma-expand-btn" onclick="MA.openTextPopup('Indikator Ketercapaian (ITP)', 'wizItpCustom')">
                                    <iconify-icon icon="solar:maximize-square-bold"></iconify-icon> Perbesar
                                </button>
                            </div>
                            <textarea class="ma-textarea" id="wizItpCustom" placeholder="Indikator keberhasilan siswa (otomatis dimuat dari ITP jika tersedia)..."></textarea>
                        </div>
                        <div class="ma-field">
                            <div class="ma-label-row">
                                <label class="ma-label">Alur Tujuan Pembelajaran (ATP)</label>
                                <button type="button" class="ma-expand-btn" onclick="MA.openTextPopup('Alur Tujuan Pembelajaran (ATP)', 'wizAtpCustom')">
                                    <iconify-icon icon="solar:maximize-square-bold"></iconify-icon> Perbesar
                                </button>
                            </div>
                            <textarea class="ma-textarea" id="wizAtpCustom" placeholder="Alur urutan pencapaian kompetensi (otomatis dimuat dari ATP jika tersedia)..."></textarea>
                        </div>

                        <div class="ma-field ma-field--full">
                            <div class="ma-label-row">
                                <label class="ma-label">Model Pembelajaran (Otomatis Memuat Sintaks ke Kegiatan Inti)</label>
                                <button type="button" class="ma-expand-btn" onclick="MA.applySintaksToWizard()">
                                    <iconify-icon icon="solar:magic-stick-3-bold"></iconify-icon> Terapkan Sintaks ke Kegiatan
                                </button>
                            </div>
                            <div class="ma-check-grid" id="wizModelGrid"></div>
                        </div>

                        <div class="ma-field ma-field--full">
                            <label class="ma-label">Metode Pembelajaran</label>
                            <div class="ma-check-grid" id="wizMetodeGrid"></div>
                        </div>

                        <div class="ma-field">
                            <label class="ma-label">Media &amp; Sarana Pembelajaran</label>
                            <div class="ma-chip-group" id="wizMediaChips" style="margin-bottom:6px;"></div>
                            <input type="text" class="ma-input" id="wizMedia" placeholder="Contoh: LCD Proyektor, PPT Interaktif, Al-Qur'an, LKPD Digital">
                        </div>
                        <div class="ma-field">
                            <label class="ma-label">Sumber Belajar / Daftar Pustaka</label>
                            <div class="ma-chip-group" id="wizSumberChips" style="margin-bottom:6px;"></div>
                            <input type="text" class="ma-input" id="wizSumber" placeholder="Contoh: Buku Paket PAI Kemendikbud, Tafsir Al-Misbah, Artikel Ilmiah">
                        </div>
                    </div>
                </div>

                <!-- STEP 3: PEMAHAMAN BERMAKNA & PERTANYAAN PEMANTIK -->
                <div class="ma-wizard-step" data-step="3">
                    <div class="ma-form-grid">
                        <div class="ma-field ma-field--full">
                            <div class="ma-label-row">
                                <label class="ma-label">Pemahaman Bermakna</label>
                                <button type="button" class="ma-expand-btn" onclick="MA.openTextPopup('Pemahaman Bermakna', 'wizPemahaman')">
                                    <iconify-icon icon="solar:maximize-square-bold"></iconify-icon> Perbesar
                                </button>
                            </div>
                            <textarea class="ma-textarea ma-textarea--lg" id="wizPemahaman" placeholder="Tuliskan manfaat kontekstual dan makna mendalam yang akan diperoleh peserta didik dalam kehidupan nyata..."></textarea>
                        </div>
                        <div class="ma-field ma-field--full">
                            <div class="ma-label-row">
                                <label class="ma-label">Pertanyaan Pemantik</label>
                                <button type="button" class="ma-expand-btn" onclick="MA.openTextPopup('Pertanyaan Pemantik', 'wizPemantik')">
                                    <iconify-icon icon="solar:maximize-square-bold"></iconify-icon> Perbesar
                                </button>
                            </div>
                            <textarea class="ma-textarea ma-textarea--lg" id="wizPemantik" placeholder="Tuliskan 2-4 pertanyaan terbuka untuk menumbuhkan rasa ingin tahu dan nalar kritis siswa..."></textarea>
                        </div>
                    </div>
                </div>

                <!-- STEP 4: KEGIATAN PEMBELAJARAN & ALOKASI WAKTU -->
                <div class="ma-wizard-step" data-step="4">
                    <div class="ma-form-grid ma-form-grid--4" style="margin-bottom:14px;">
                        <div class="ma-field">
                            <label class="ma-label">Menit per JP</label>
                            <input type="number" class="ma-input" id="wizAlokasiPerJp" value="45" min="15" max="120" oninput="MA.recalcWizDuration(true)">
                        </div>
                        <div class="ma-field">
                            <label class="ma-label">Jumlah JP</label>
                            <input type="number" class="ma-input" id="wizJumlahJp" value="2" min="1" max="12" oninput="MA.recalcWizDuration(true)">
                        </div>
                        <div class="ma-field">
                            <label class="ma-label">Target Peserta Didik</label>
                            <input type="text" class="ma-input" id="wizTargetDidik" value="Peserta Didik Reguler / Tipikal">
                        </div>
                        <div class="ma-field">
                            <label class="ma-label">Distribusi Otomatis</label>
                            <button type="button" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" style="height:41px;justify-content:center;" onclick="MA.recalcWizDuration(true)">
                                <iconify-icon icon="solar:clock-circle-bold"></iconify-icon> Hitung 15/70/15%
                            </button>
                        </div>
                    </div>

                    <div class="ma-duration-box" id="wizDurationSummary" style="margin-bottom:14px;">
                        <span>Total Alokasi: <strong>90 Menit</strong> (2 JP &times; 45 Menit)</span>
                        <span>Terpakai: <strong>90 Menit</strong> (Pembukaan 14m + Inti 63m + Penutup 13m)</span>
                    </div>

                    <div class="ma-form-grid ma-form-grid--3" style="margin-bottom:14px;">
                        <div class="ma-field">
                            <label class="ma-label">Durasi Pembukaan (Menit)</label>
                            <input type="number" class="ma-input" id="wizDurPembukaan" value="14" min="0" oninput="MA.recalcWizDuration(false)">
                        </div>
                        <div class="ma-field">
                            <label class="ma-label">Durasi Kegiatan Inti (Menit)</label>
                            <input type="number" class="ma-input" id="wizDurInti" value="63" min="0" oninput="MA.recalcWizDuration(false)">
                        </div>
                        <div class="ma-field">
                            <label class="ma-label">Durasi Penutup (Menit)</label>
                            <input type="number" class="ma-input" id="wizDurPenutup" value="13" min="0" oninput="MA.recalcWizDuration(false)">
                        </div>
                    </div>

                    <div class="ma-form-grid">
                        <div class="ma-field ma-field--full">
                            <label class="ma-label">Persiapan Pembelajaran</label>
                            <textarea class="ma-textarea" id="wizPersiapan" placeholder="Menyiapkan ruangan, perangkat proyektor, LKPD, daftar presensi, dan kesiapan belajar siswa..."></textarea>
                        </div>

                        <div class="ma-field ma-field--full">
                            <div class="ma-label-row">
                                <label class="ma-label">Kegiatan Pendahuluan / Pembukaan</label>
                                <button type="button" class="ma-expand-btn" onclick="MA.openTextPopup('Kegiatan Pendahuluan / Pembukaan', 'wizKegPendahuluan')">
                                    <iconify-icon icon="solar:maximize-square-bold"></iconify-icon> Perbesar
                                </button>
                            </div>
                            <textarea class="ma-textarea" id="wizKegPendahuluan" placeholder="1. Guru membuka pembelajaran dengan salam dan doa bersama.&#10;2. Apersepsi mengaitkan materi sebelumnya.&#10;3. Menyampaikan tujuan pembelajaran dan pertanyaan pemantik."></textarea>
                        </div>

                        <div class="ma-field ma-field--full">
                            <div class="ma-label-row">
                                <label class="ma-label">Kegiatan Inti (Sintaks Model Pembelajaran)</label>
                                <div style="display:flex;gap:6px;">
                                    <button type="button" class="ma-expand-btn" onclick="MA.applySintaksToWizard()">
                                        <iconify-icon icon="solar:magic-stick-3-bold"></iconify-icon> Muat Sintaks Model
                                    </button>
                                    <button type="button" class="ma-expand-btn" onclick="MA.openTextPopup('Kegiatan Inti', 'wizKegInti')">
                                        <iconify-icon icon="solar:maximize-square-bold"></iconify-icon> Perbesar
                                    </button>
                                </div>
                            </div>
                            <textarea class="ma-textarea ma-textarea--lg" id="wizKegInti" placeholder="Langkah-langkah pembelajaran inti sesuai sintaks model yang dipilih..."></textarea>
                        </div>

                        <div class="ma-field ma-field--full">
                            <div class="ma-label-row">
                                <label class="ma-label">Kegiatan Penutup</label>
                                <button type="button" class="ma-expand-btn" onclick="MA.openTextPopup('Kegiatan Penutup', 'wizKegPenutup')">
                                    <iconify-icon icon="solar:maximize-square-bold"></iconify-icon> Perbesar
                                </button>
                            </div>
                            <textarea class="ma-textarea" id="wizKegPenutup" placeholder="1. Guru dan peserta didik menyimpulkan materi pembelajaran.&#10;2. Refleksi dan umpan balik singkat.&#10;3. Penyampaian agenda pertemuan berikutnya dan doa penutup."></textarea>
                        </div>
                    </div>
                </div>

                <!-- STEP 5: ASESMEN, PROFIL PELAJAR PANCASILA & REFLEKSI -->
                <div class="ma-wizard-step" data-step="5">
                    <div class="ma-form-grid">
                        <div class="ma-field ma-field--full">
                            <label class="ma-label">Dimensi Profil Pelajar Pancasila &amp; Rahmatan Lil Alamin (Klik untuk Memilih)</label>
                            <div class="ma-chip-group" id="wizP5Chips" style="margin-bottom:8px;"></div>
                            <textarea class="ma-textarea" id="wizP5" placeholder="Dimensi P5 & PPRA yang dikembangkan..."></textarea>
                        </div>

                        <div class="ma-field">
                            <label class="ma-label">Asesmen Diagnostik (Awal)</label>
                            <textarea class="ma-textarea" id="wizAsDiag" placeholder="Pertanyaan lisan / kuis awal kesiapan belajar siswa..."></textarea>
                        </div>
                        <div class="ma-field">
                            <label class="ma-label">Asesmen Formatif (Proses)</label>
                            <textarea class="ma-textarea" id="wizAsForm" placeholder="Observasi diskusi, penilaian LKPD, presentasi kelompok..."></textarea>
                        </div>
                        <div class="ma-field">
                            <label class="ma-label">Asesmen Sumatif (Akhir)</label>
                            <textarea class="ma-textarea" id="wizAsSum" placeholder="Tes tertulis / proyek / unjuk kerja di akhir lingkup materi..."></textarea>
                        </div>
                        <div class="ma-field">
                            <label class="ma-label">Pengayaan &amp; Remedial</label>
                            <textarea class="ma-textarea" id="wizPengRem" placeholder="Bimbingan ulang bagi siswa di bawah KKTP dan tantangan proyek bagi siswa mahir..."></textarea>
                        </div>

                        <div class="ma-field">
                            <label class="ma-label">Refleksi Guru</label>
                            <textarea class="ma-textarea" id="wizRefGuru" placeholder="Apakah metode dan alokasi waktu sudah berjalan efektif?"></textarea>
                        </div>
                        <div class="ma-field">
                            <label class="ma-label">Refleksi Peserta Didik</label>
                            <textarea class="ma-textarea" id="wizRefSiswa" placeholder="Apa hal baru yang dipahami dan bagian mana yang masih perlu diperdalam?"></textarea>
                        </div>
                    </div>
                </div>

                <!-- STEP 6: GLOSARIUM, LKPD, LAMPIRAN & SIMPAN -->
                <div class="ma-wizard-step" data-step="6">
                    <div class="ma-form-grid">
                        <div class="ma-field">
                            <label class="ma-label">Panduan LKPD (Opsional)</label>
                            <textarea class="ma-textarea" id="wizLkpd" placeholder="Ringkasan instruksi Lembar Kerja Peserta Didik..."></textarea>
                        </div>
                        <div class="ma-field">
                            <label class="ma-label">Bahan Bacaan Guru &amp; Siswa (Opsional)</label>
                            <textarea class="ma-textarea" id="wizBahanBacaan" placeholder="Ringkasan pokok materi literasi..."></textarea>
                        </div>
                        <div class="ma-field ma-field--full">
                            <label class="ma-label">Glosarium (Daftar Istilah Penting)</label>
                            <textarea class="ma-textarea" id="wizGlosarium" placeholder="Contoh: Ulil Albab = Orang yang berakal sehat dan senantiasa berpikir kritis..."></textarea>
                        </div>
                        <div class="ma-field">
                            <label class="ma-label">Upload Berkas Lampiran (PDF/DOCX/PPTX/Gambar, Maks 15MB)</label>
                            <input type="file" class="ma-input" id="wizFileLampiran" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.webp,.zip">
                        </div>
                        <div class="ma-field">
                            <label class="ma-label">Status Awal Penyimpanan</label>
                            <select class="ma-select" id="wizStatusInit">
                                <option value="Draft">Draft (Masih Bisa Diedit)</option>
                                <option value="Published">Langsung Terbitkan (Published)</option>
                            </select>
                        </div>
                    </div>

                    <div class="ma-alert-banner ma-alert-banner--teal" id="wizFinalSummary" style="margin-top:16px;margin-bottom:0;"></div>
                </div>

            </div>

            <div class="ilm-modal-footer" style="justify-content:space-between;">
                <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" id="wizBtnPrev" onclick="MA.wizPrev()">
                    <iconify-icon icon="solar:arrow-left-bold"></iconify-icon>
                    <span>Sebelumnya</span>
                </button>
                <div style="display:flex;gap:8px;align-items:center;">
                    <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" onclick="closeIlmunaModal('modalCreateModulAjar')">Batal</button>
                    <button type="button" class="ilmuna-btn ilmuna-btn--teal" id="wizBtnNext" onclick="MA.wizNext()">
                        <span>Lanjut</span>
                        <iconify-icon icon="solar:arrow-right-bold"></iconify-icon>
                    </button>
                    <button type="button" class="ilmuna-btn ilmuna-btn--gold" id="wizBtnSave" style="display:none;" onclick="MA.submitCreateModul()">
                        <iconify-icon icon="solar:diskette-bold"></iconify-icon>
                        <span>Simpan Modul Ajar</span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ==========================================================================
     MODAL 2: TAMBAH / EDIT CAPAIAN PEMBELAJARAN (CP)
     ========================================================================== -->
<div class="ilm-modal-backdrop" id="modalCpForm" aria-hidden="true">
    <div class="ilm-modal-dialog" style="max-width:680px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon">
                    <iconify-icon icon="solar:diploma-verified-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title" id="cpModalTitle">Tambah Capaian Pembelajaran (CP)</h3>
                    <p class="ilm-modal-header__subtitle">Langkah 1 Kurikulum Merdeka &bull; Elemen &amp; CP per Kelas-Mapel</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-close" onclick="closeIlmunaModal('modalCpForm')" aria-label="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form id="formCp" onsubmit="MA.submitCp(event)">
            <div class="ilm-modal-body">
                <input type="hidden" id="cpFormId" value="0">
                <div class="ma-form-grid">
                    <div class="ma-field ma-field--full">
                        <label class="ma-label">Kelas &amp; Mata Pelajaran <span class="req">*</span></label>
                        <select class="ma-select" id="cpFormKmp" required onchange="MA.populateElemenForCpForm()"></select>
                    </div>
                    <div class="ma-field">
                        <label class="ma-label">Pilih Elemen yang Sudah Ada</label>
                        <select class="ma-select" id="cpFormElemen">
                            <option value="0">-- Buat Elemen Baru di Samping --</option>
                        </select>
                    </div>
                    <div class="ma-field">
                        <label class="ma-label">Atau Nama Elemen Baru</label>
                        <input type="text" class="ma-input" id="cpFormNewElemen" placeholder="Contoh: Al-Qur'an Hadis / Akidah / Fikih">
                    </div>
                    <div class="ma-field ma-field--full">
                        <label class="ma-label">Deskripsi Capaian Pembelajaran (CP) <span class="req">*</span></label>
                        <textarea class="ma-textarea ma-textarea--lg" id="cpFormText" required placeholder="Tuliskan rumusan lengkap Capaian Pembelajaran (CP)..."></textarea>
                    </div>
                </div>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" onclick="closeIlmunaModal('modalCpForm')">Batal</button>
                <button type="submit" class="ilmuna-btn ilmuna-btn--teal" id="cpBtnSubmit">
                    <iconify-icon icon="solar:diskette-bold"></iconify-icon>
                    <span>Simpan CP</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ==========================================================================
     MODAL 3: SINKRONISASI CP (DAN TP) ANTAR KELAS
     ========================================================================== -->
<div class="ilm-modal-backdrop" id="modalSyncCp" aria-hidden="true">
    <div class="ilm-modal-dialog" style="max-width:600px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon">
                    <iconify-icon icon="solar:copy-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title">Sinkronisasi CP antar Rombel</h3>
                    <p class="ilm-modal-header__subtitle">Salin Elemen &amp; CP (beserta TP) dari kelas yang sudah memiliki data</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-close" onclick="closeIlmunaModal('modalSyncCp')" aria-label="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form onsubmit="MA.submitSyncCp(event)">
            <div class="ilm-modal-body">
                <div class="ma-form-grid">
                    <div class="ma-field ma-field--full">
                        <label class="ma-label">Kelas Sumber (Yang Sudah Punya CP) <span class="req">*</span></label>
                        <select class="ma-select" id="syncSourceKmp" required></select>
                    </div>
                    <div class="ma-field ma-field--full">
                        <label class="ma-label">Kelas Tujuan <span class="req">*</span></label>
                        <select class="ma-select" id="syncTargetKmp" required></select>
                    </div>
                    <div class="ma-field ma-field--full">
                        <label class="ma-check-card">
                            <input type="checkbox" id="syncIncludeTp" checked>
                            <div class="ma-check-card__body">
                                <span class="ma-check-card__title">Ikut Salin Tujuan Pembelajaran (TP), ATP &amp; ITP</span>
                                <span class="ma-check-card__desc">Semua TP, ATP, dan ITP di bawah CP sumber akan disalin otomatis tanpa menduplikasi data yang sudah ada.</span>
                            </div>
                        </label>
                    </div>
                </div>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" onclick="closeIlmunaModal('modalSyncCp')">Batal</button>
                <button type="submit" class="ilmuna-btn ilmuna-btn--teal" id="btnSyncSubmit">
                    <iconify-icon icon="solar:refresh-bold"></iconify-icon>
                    <span>Mulai Sinkronisasi</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ==========================================================================
     MODAL 4: TAMBAH / EDIT TUJUAN PEMBELAJARAN (TP)
     ========================================================================== -->
<div class="ilm-modal-backdrop" id="modalTpForm" aria-hidden="true">
    <div class="ilm-modal-dialog" style="max-width:700px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon">
                    <iconify-icon icon="solar:target-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title" id="tpModalTitle">Buat Tujuan Pembelajaran (TP)</h3>
                    <p class="ilm-modal-header__subtitle">Langkah 2 Kurikulum Merdeka &bull; Diturunkan dari CP Terpilih</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-close" onclick="closeIlmunaModal('modalTpForm')" aria-label="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form id="formTp" onsubmit="MA.submitTp(event)">
            <div class="ilm-modal-body">
                <input type="hidden" id="tpFormId" value="0">
                <div class="ma-form-grid">
                    <div class="ma-field ma-field--full">
                        <label class="ma-label">Pilih Capaian Pembelajaran (CP) Induk <span class="req">*</span></label>
                        <select class="ma-select" id="tpFormCp" required></select>
                    </div>
                    <div class="ma-field">
                        <label class="ma-label">Kode TP (Opsional, Otomatis jika kosong)</label>
                        <input type="text" class="ma-input" id="tpFormKode" placeholder="Contoh: 1.1 atau TP.1">
                    </div>
                    <div class="ma-field">
                        <label class="ma-label">Alokasi Waktu (JP)</label>
                        <input type="number" class="ma-input" id="tpFormJp" value="4" min="1" max="40">
                    </div>
                    <div class="ma-field ma-field--full">
                        <label class="ma-label">Materi Pokok / Lingkup Materi</label>
                        <input type="text" class="ma-input" id="tpFormMateri" placeholder="Contoh: Berpikir Kritis & Semangat Mencintai IPTEK">
                    </div>
                    <div class="ma-field ma-field--full">
                        <label class="ma-label">Uraian Tujuan Pembelajaran (TP) <span class="req">*</span></label>
                        <textarea class="ma-textarea ma-textarea--lg" id="tpFormText" required placeholder="Peserta didik mampu membaca, menganalisis, dan menerapkan..."></textarea>
                    </div>
                    <div class="ma-field" id="tpInitAtpWrap">
                        <label class="ma-label">Alur Tujuan Pembelajaran Awal (ATP - Opsional)</label>
                        <textarea class="ma-textarea" id="tpFormInitAtp" placeholder="Langkah alur pembelajaran pertama..."></textarea>
                    </div>
                    <div class="ma-field" id="tpInitItpWrap">
                        <label class="ma-label">Indikator Ketercapaian Awal (ITP - Opsional)</label>
                        <textarea class="ma-textarea" id="tpFormInitItp" placeholder="Kriteria bukti ketercapaian TP..."></textarea>
                    </div>
                </div>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" onclick="closeIlmunaModal('modalTpForm')">Batal</button>
                <button type="submit" class="ilmuna-btn ilmuna-btn--teal" id="tpBtnSubmit">
                    <iconify-icon icon="solar:diskette-bold"></iconify-icon>
                    <span>Simpan TP</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ==========================================================================
     MODAL 5: TAMBAH ATP / ITP PADA TP
     ========================================================================== -->
<div class="ilm-modal-backdrop" id="modalSubTpForm" aria-hidden="true">
    <div class="ilm-modal-dialog" style="max-width:580px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon">
                    <iconify-icon icon="solar:checklist-minimalistic-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title" id="subTpModalTitle">Tambah ATP / ITP</h3>
                    <p class="ilm-modal-header__subtitle" id="subTpModalSub">Lengkapi rincian alur dan indikator ketercapaian</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-close" onclick="closeIlmunaModal('modalSubTpForm')" aria-label="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form onsubmit="MA.submitSubTp(event)">
            <div class="ilm-modal-body">
                <input type="hidden" id="subTpMode" value="atp">
                <input type="hidden" id="subTpId" value="0">
                <input type="hidden" id="subTpParentId" value="0">
                <div class="ma-form-grid">
                    <div class="ma-field" id="subTpKodeWrap" style="display:none;">
                        <label class="ma-label">Kode Indikator (ITP)</label>
                        <input type="text" class="ma-input" id="subTpKode" placeholder="Contoh: 1.1.1">
                    </div>
                    <div class="ma-field">
                        <label class="ma-label">Urutan</label>
                        <input type="number" class="ma-input" id="subTpUrutan" value="1" min="1">
                    </div>
                    <div class="ma-field ma-field--full">
                        <label class="ma-label" id="subTpTextLabel">Uraian <span class="req">*</span></label>
                        <textarea class="ma-textarea ma-textarea--lg" id="subTpText" required></textarea>
                    </div>
                    <div class="ma-field ma-field--full" id="subTpDescWrap" style="display:none;">
                        <label class="ma-label">Kriteria / Deskripsi Ketercapaian (Opsional)</label>
                        <textarea class="ma-textarea" id="subTpDesc" placeholder="Rubrik / bukti ketercapaian peserta didik..."></textarea>
                    </div>
                </div>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" onclick="closeIlmunaModal('modalSubTpForm')">Batal</button>
                <button type="submit" class="ilmuna-btn ilmuna-btn--teal">
                    <iconify-icon icon="solar:diskette-bold"></iconify-icon>
                    <span>Simpan</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ==========================================================================
     MODAL 6: TEXT POPUP EDITOR LAYAR LEBAR (ALA TEXTPOPUPMODAL)
     ========================================================================== -->
<div class="ilm-modal-backdrop" id="modalTextPopup" aria-hidden="true">
    <div class="ilm-modal-dialog" style="max-width:820px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon">
                    <iconify-icon icon="solar:document-text-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title" id="textPopupTitle">Editor Teks</h3>
                    <p class="ilm-modal-header__subtitle">Mode penyuntingan luas &amp; nyaman</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-close" onclick="closeIlmunaModal('modalTextPopup')" aria-label="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <div class="ilm-modal-body">
            <textarea class="ma-textarea" id="textPopupArea" style="min-height:360px;font-size:14px;line-height:1.65;"></textarea>
        </div>
        <div class="ilm-modal-footer">
            <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" onclick="closeIlmunaModal('modalTextPopup')">Tutup</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--teal" id="textPopupSaveBtn" onclick="MA.saveTextPopup()">
                <iconify-icon icon="solar:check-circle-bold"></iconify-icon>
                <span>Terapkan Teks</span>
            </button>
        </div>
    </div>
</div>

<script>
    window.ILMUNA_MODUL_AJAR_CFG = {
        baseUrl: <?= json_encode(BASE_URL) ?>,
        endpoint: <?= json_encode(BASE_URL . '/dashboard/guru/modul-ajar') ?>,
        activeRole: <?= json_encode($activeRole) ?>,
        isAdmin: <?= $isAdmin ? 'true' : 'false' ?>
    };
</script>
<script src="<?= BASE_URL ?>/assets/js/guru-modul-ajar.js?v=<?= $maJsVer ?>" defer></script>

<?php include BASE_PATH . '/includes/footer.php'; ?>
