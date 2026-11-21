<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — MODUL AJAR KURIKULUM MERDEKA (TERINTEGRASI PROTA/PROSEM/KALDIK/JADWAL)
 * ==============================================================================
 * Pemisahan Peran Tegas:
 *   - ADMIN : Tambah/Kelola CP & Elemen (Multi-Pilih Kelas/Rombel), Monitoring Modul Ajar,
 *             Buka Kunci Final (Kembalikan ke Guru), Unduh PDF Modul Ajar & Laporan.
 *             (Admin TIDAK memiliki menu TP dan TIDAK membuat Modul Ajar.)
 *   - GURU  : Hanya melihat Mapel & Kelas yang diampu, memilih CP dari Admin (read-only),
 *             membuat/mengelola TP, ATP & ITP, menyusun Modul Ajar dengan alur:
 *             CP (1 Dropdown) -> TP (1 Dropdown) -> ATP/ITP -> Pertemuan dari PROTA/PROSEM/Kaldik/Jadwal,
 *             Multi-Lampiran + QR Code otomatis di PDF (`includes/modul-ajar-pdf.php`).
 * ==============================================================================
 */

date_default_timezone_set('Asia/Jakarta');

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';
require_once __DIR__ . '/../../includes/academic-sync-engine.php';

requireRole(['guru', 'admin']);

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

// Matriks Hak Akses Tegas
$canManageCp    = ($activeRole === 'admin' && $isAdmin); // Admin tambah/kelola CP & Elemen
$canManageTp    = ($activeRole === 'guru');              // Guru buat/kelola TP, ATP & ITP
$canManageModul = ($activeRole === 'guru');              // Guru buat/isi/edit/publish/finalisasi Modul Ajar
$canUnlockFinal = ($activeRole === 'admin' && $isAdmin); // Admin buka kunci Final -> kembalikan ke Guru

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

// Identitas sekolah & tahun/semester aktif
$identitasSekolah = [];
try {
    $stmtSekolah = $pdo->query("SELECT * FROM identitas_sekolah LIMIT 1");
    $identitasSekolah = $stmtSekolah->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

$defaultTahunAktif = !empty($identitasSekolah['tahun_pelajaran_aktif']) ? $identitasSekolah['tahun_pelajaran_aktif'] : '2026/2027';
$defaultSemAktif   = !empty($identitasSekolah['semester_aktif']) ? $identitasSekolah['semester_aktif'] : 'Ganjil';

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
            'id_jadwal_pelajaran'    => "INT UNSIGNED DEFAULT NULL",
            'id_prosem_detail'       => "INT UNSIGNED DEFAULT NULL",
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
 * HELPERS: FASE, GRADE, AUTO TITLE, JSON, MULTI-UPLOAD, SCOPE, PLANNING SLOTS
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

function maSafeTruncate($str, $maxLen) {
    $s = (string)$str;
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($s, 'UTF-8') > $maxLen ? (mb_substr($s, 0, max(1, $maxLen - 3), 'UTF-8') . '...') : $s;
    }
    return strlen($s) > $maxLen ? (substr($s, 0, max(1, $maxLen - 3)) . '...') : $s;
}

function generateAutoModulTitle($pdo, $pertemuanKe, $cpIds = [], $tpIds = [], $tpCustom = '') {
    $pertKe = max(1, (int)$pertemuanKe);
    $cpLabels = [];
    $cleanCpIds = array_values(array_unique(array_filter(array_map('intval', (array)$cpIds))));
    if (!empty($cleanCpIds)) {
        $in = implode(',', array_fill(0, count($cleanCpIds), '?'));
        $s = $pdo->prepare("SELECT c.id, c.cp, e.nama_elemen FROM cp c LEFT JOIN elemen e ON c.id_elemen = e.id WHERE c.id IN ($in) ORDER BY c.id ASC");
        $s->execute($cleanCpIds);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $lbl = trim((string)($r['nama_elemen'] ?? ''));
            if ($lbl === '') {
                $rawCp = preg_replace('/\s+/', ' ', trim((string)($r['cp'] ?? '')));
                $lbl = maSafeTruncate($rawCp, 45);
            }
            if ($lbl !== '' && !in_array($lbl, $cpLabels, true)) {
                $cpLabels[] = $lbl;
            }
        }
    }

    $tpLabels = [];
    $cleanTpIds = array_values(array_unique(array_filter(array_map('intval', (array)$tpIds))));
    if (!empty($cleanTpIds)) {
        $in = implode(',', array_fill(0, count($cleanTpIds), '?'));
        $s = $pdo->prepare("SELECT id, kode_tp, materi_pokok, tp FROM tp WHERE id IN ($in) ORDER BY id ASC");
        $s->execute($cleanTpIds);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $kode = trim((string)($r['kode_tp'] ?? ''));
            $materi = trim((string)($r['materi_pokok'] ?? ''));
            if ($kode !== '' && $materi !== '') {
                $lbl = 'TP ' . $kode . ' (' . $materi . ')';
            } elseif ($materi !== '') {
                $lbl = $materi;
            } elseif ($kode !== '') {
                $lbl = 'TP ' . $kode;
            } else {
                $rawTp = preg_replace('/\s+/', ' ', trim((string)($r['tp'] ?? '')));
                $lbl = maSafeTruncate($rawTp, 55);
            }
            if ($lbl !== '' && !in_array($lbl, $tpLabels, true)) {
                $tpLabels[] = $lbl;
            }
        }
    } elseif (trim((string)$tpCustom) !== '') {
        $firstLine = trim(strtok((string)$tpCustom, "\r\n"));
        $firstLine = preg_replace('/\s+/', ' ', $firstLine);
        if ($firstLine !== '') {
            $tpLabels[] = maSafeTruncate($firstLine, 60);
        }
    }

    $parts = ['Modul Pertemuan ' . $pertKe];
    if (!empty($cpLabels)) $parts[] = implode(', ', $cpLabels);
    if (!empty($tpLabels)) $parts[] = implode(', ', $tpLabels);
    $title = implode(' - ', $parts);
    return maSafeTruncate($title, 245);
}

function parseJsonIdArray($raw, $fallbackSingleId = null) {
    $out = [];
    if (is_array($raw)) {
        $arr = $raw;
    } elseif (is_string($raw) && trim($raw) !== '') {
        $dec = json_decode($raw, true);
        $arr = is_array($dec) ? $dec : explode(',', $raw);
    } else {
        $arr = [];
    }
    foreach ($arr as $v) {
        $iv = (int)$v;
        if ($iv > 0 && !in_array($iv, $out, true)) $out[] = $iv;
    }
    if (empty($out) && $fallbackSingleId && (int)$fallbackSingleId > 0) {
        $out[] = (int)$fallbackSingleId;
    }
    return $out;
}

function resolveAttachmentFullUrl($item) {
    if (!empty($item['url']) && preg_match('#^https?://#i', (string)$item['url'])) {
        return trim((string)$item['url']);
    }
    $fp = trim((string)($item['file_path'] ?? ''));
    if ($fp === '') return '';
    if (preg_match('#^https?://#i', $fp)) return $fp;
    return rtrim(BASE_URL, '/') . '/' . ltrim($fp, '/');
}

function parseLampiranList($row) {
    $items = [];
    if (!empty($row['lampiran_json'])) {
        $dec = json_decode($row['lampiran_json'], true);
        if (is_array($dec)) {
            foreach ($dec as $f) {
                if (!empty($f['file_path']) || !empty($f['url'])) {
                    $f['url'] = resolveAttachmentFullUrl($f);
                    $items[] = $f;
                }
            }
        }
    }
    if (empty($items) && !empty($row['file_lampiran'])) {
        $fp = (string)$row['file_lampiran'];
        $item = [
            'id'          => 'legacy_' . md5($fp),
            'file_name'   => basename($fp),
            'file_path'   => $fp,
            'file_size'   => 0,
            'uploaded_at' => $row['created_at'] ?? date('Y-m-d H:i:s'),
        ];
        $item['url'] = resolveAttachmentFullUrl($item);
        $items[] = $item;
    }
    return $items;
}

/**
 * Scope KMP Ketat:
 * - GURU  : HANYA mata pelajaran & rombel yang benar-benar ia ampu (guru_mata_pelajaran -> kelas_mata_pelajaran).
 * - ADMIN : Seluruh mata pelajaran & rombel untuk monitoring & pengelolaan CP.
 */
function getAccessibleKmpRows($pdo, $activeRole, $idGuruLogged) {
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
    if ($activeRole === 'guru') {
        if ($idGuruLogged <= 0) {
            return [];
        }
        $stmt = $pdo->prepare($baseSql . " WHERE gmp.id_guru = ? ORDER BY mp.nama_mata_pelajaran ASC, k.nama_kelas ASC");
        $stmt->execute([$idGuruLogged]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
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

function getAllowedKmpIdSet($pdo, $activeRole, $idGuruLogged) {
    $rows = getAccessibleKmpRows($pdo, $activeRole, $idGuruLogged);
    $ids = [];
    foreach ($rows as $r) $ids[] = (int)$r['id_kmp'];
    return $ids;
}

/**
 * Multi-File Upload Handler (Mendukung 1 atau Banyak Lampiran Sekaligus)
 */
function handleModulMultiFileUpload($fileBag) {
    if (empty($fileBag) || !isset($fileBag['name'])) return [];

    $normalized = [];
    if (is_array($fileBag['name'])) {
        $count = count($fileBag['name']);
        for ($i = 0; $i < $count; $i++) {
            $normalized[] = [
                'name'     => $fileBag['name'][$i] ?? '',
                'tmp_name' => $fileBag['tmp_name'][$i] ?? '',
                'error'    => $fileBag['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $fileBag['size'][$i] ?? 0,
            ];
        }
    } else {
        $normalized[] = $fileBag;
    }

    $allowed = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'webp', 'zip', 'rar'];
    $maxBytes = 15 * 1024 * 1024;
    $uploadDir = BASE_PATH . '/uploads/modul_ajar';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    $saved = [];
    foreach ($normalized as $fileInfo) {
        if (!isset($fileInfo['error']) || $fileInfo['error'] === UPLOAD_ERR_NO_FILE) continue;
        if ($fileInfo['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Gagal mengunggah berkas "' . basename((string)$fileInfo['name']) . '" (kode error: ' . $fileInfo['error'] . ').');
        }
        if ((int)$fileInfo['size'] > $maxBytes) {
            throw new Exception('Ukuran berkas "' . basename((string)$fileInfo['name']) . '" melebihi batas maksimal 15 MB.');
        }
        $origName = basename((string)$fileInfo['name']);
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            throw new Exception('Format berkas .' . $ext . ' pada "' . $origName . '" tidak didukung.');
        }
        $safeBase = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($origName, PATHINFO_FILENAME));
        $safeBase = substr($safeBase, 0, 55) ?: 'lampiran';
        $newFilename = date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '_' . $safeBase . '.' . $ext;
        $destPath = $uploadDir . '/' . $newFilename;

        if (!move_uploaded_file($fileInfo['tmp_name'], $destPath)) {
            throw new Exception('Gagal menyimpan berkas "' . $origName . '" ke server.');
        }
        $relPath = 'uploads/modul_ajar/' . $newFilename;
        $saved[] = [
            'id'          => 'att_' . bin2hex(random_bytes(5)),
            'file_name'   => $origName,
            'file_path'   => $relPath,
            'url'         => rtrim(BASE_URL, '/') . '/' . $relPath,
            'file_size'   => (int)$fileInfo['size'],
            'uploaded_at' => date('Y-m-d H:i:s'),
        ];
    }
    return $saved;
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
    if ($mediaStr === '' && !empty($row['sarana_prasarana'])) $mediaStr = (string)$row['sarana_prasarana'];
    $sumberStr = trim((string)($row['sumber_belajar'] ?? ''));
    if ($sumberStr === '' && !empty($row['daftar_pustaka'])) $sumberStr = (string)$row['daftar_pustaka'];

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

/**
 * Helper: Konversi Nama Bulan Indonesia -> Nomor Bulan (1..12)
 */
function bulanIndoToNum($bulanNama) {
    $map = [
        'januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4,
        'mei' => 5, 'juni' => 6, 'juli' => 7, 'agustus' => 8,
        'september' => 9, 'oktober' => 10, 'november' => 11, 'nopember' => 11, 'desember' => 12
    ];
    return $map[strtolower(trim((string)$bulanNama))] ?? 0;
}

function getIndoDayFromDate($ymd) {
    $days = [
        'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'
    ];
    $ts = strtotime((string)$ymd);
    return $ts ? ($days[date('l', $ts)] ?? 'Senin') : 'Senin';
}

/* ==============================================================================
 * DIRECT PDF DOWNLOAD & PRINT ROUTES (TERPUSAT DI includes/modul-ajar-pdf.php)
 * ============================================================================== */
if (isset($_GET['action']) && in_array($_GET['action'], ['print_modul', 'download_modul_pdf', 'print_laporan', 'download_laporan_pdf'], true) && empty($_GET['api'])) {
    require_once BASE_PATH . '/includes/modul-ajar-pdf.php';

    $mode = (strpos($_GET['action'], 'laporan') !== false) ? 'laporan' : 'modul';
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

    $allowedIds = getAllowedKmpIdSet($pdo, $activeRole, $idGuruLogged);
    if ($activeRole === 'guru' && !in_array((int)$rawModul['id_kelas_mata_pelajaran'], $allowedIds, true)) {
        die("Anda tidak memiliki akses untuk mengunduh modul ajar mata pelajaran ini.");
    }

    $modul = normalizeModulRow($pdo, $rawModul);

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

    $guruNama = ($rawModul['nama_guru_pengampu'] && $rawModul['nama_guru_pengampu'] !== 'Dewan Guru')
        ? $rawModul['nama_guru_pengampu'] : $namaGuruLogged;
    $guruNip = !empty($rawModul['nip_guru_pengampu']) ? $rawModul['nip_guru_pengampu'] : ($currentGuru['nip'] ?? '');

    $pdfData = [
        'modul'        => $modul,
        'cp_rows'      => $cpRows,
        'tp_rows'      => $tpRows,
        'model_names'  => $modelNames,
        'metode_names' => $metodeNames,
        'sekolah'      => $identitasSekolah,
        'guru_nama'    => $guruNama,
        'guru_nip'     => $guruNip,
    ];

    $forceHtml = !empty($_GET['preview']);
    ilmuna_output_modul_ajar_pdf($pdfData, $mode, $forceHtml);
    exit;
}

/* ==============================================================================
 * REST / AJAX JSON API ENDPOINT (?api=1&action=...)
 * ============================================================================== */
if (isset($_GET['api']) && $_GET['api'] == '1') {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    // Endpoint QR Code SVG untuk Lampiran di UI (offline via includes/qrcode_engine.php)
    if ($action === 'qr_svg') {
        require_once BASE_PATH . '/includes/modul-ajar-pdf.php';
        $url = trim((string)($_GET['url'] ?? ''));
        $svg = ilmuna_ma_generate_qr_svg($url ?: BASE_URL, 140);
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: public, max-age=86400');
        echo $svg ?: '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80"></svg>';
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');

    $rawBody = file_get_contents('php://input');
    $jsonBody = [];
    if ($rawBody !== '' && isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        $dec = json_decode($rawBody, true);
        if (is_array($dec)) $jsonBody = $dec;
    }
    $in = array_merge($_GET, $_POST, $jsonBody);
    if (!$action && !empty($in['action'])) $action = $in['action'];

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
        $kmpRows = getAccessibleKmpRows($pdo, $activeRole, $idGuruLogged);
        $allowedKmpIds = [];
        $allowedMapelIds = [];
        $kmpById = [];
        foreach ($kmpRows as $kr) {
            $allowedKmpIds[] = (int)$kr['id_kmp'];
            $allowedMapelIds[(int)$kr['id_mata_pelajaran']] = (int)$kr['id_mata_pelajaran'];
            $kmpById[(int)$kr['id_kmp']] = $kr;
        }
        $allowedMapelIds = array_values($allowedMapelIds);

        $assertKmpAccess = function ($idKmp) use ($activeRole, $allowedKmpIds, $sendJson) {
            $idKmp = (int)$idKmp;
            if ($idKmp <= 0) $sendJson('error', 'Kelas & Mata Pelajaran wajib dipilih.', null, 400);
            if ($activeRole === 'guru' && !in_array($idKmp, $allowedKmpIds, true)) {
                $sendJson('error', 'Akses ditolak. Anda hanya dapat mengelola mata pelajaran yang Anda ampu.', null, 403);
            }
            return $idKmp;
        };

        $assertAdminCp = function () use ($canManageCp, $sendJson) {
            if (!$canManageCp) {
                $sendJson('error', 'Aksi ini khusus untuk Administrator Kurikulum.', null, 403);
            }
        };

        $assertCanManageTp = function () use ($canManageTp, $sendJson) {
            if (!$canManageTp) {
                $sendJson('error', 'Tujuan Pembelajaran (TP), ATP, dan ITP hanya dikelola oleh Guru Mata Pelajaran.', null, 403);
            }
        };

        $assertCanManageModul = function () use ($canManageModul, $sendJson) {
            if (!$canManageModul) {
                $sendJson('error', 'Administrator hanya memantau, membuka kunci Final, dan mengunduh PDF. Pembuatan & pengisian Modul Ajar dilakukan oleh Guru.', null, 403);
            }
        };

        $assertCpReadable = function ($idCp) use ($pdo, $activeRole, $allowedKmpIds, $allowedMapelIds, $sendJson) {
            $s = $pdo->prepare("
                SELECT c.id, c.id_kelas_mata_pelajaran, kmp.id_mata_pelajaran
                FROM cp c
                JOIN kelas_mata_pelajaran kmp ON c.id_kelas_mata_pelajaran = kmp.id
                WHERE c.id = ?
                LIMIT 1
            ");
            $s->execute([(int)$idCp]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if (!$row) $sendJson('error', 'CP yang dipilih tidak ditemukan.', null, 404);
            if ($activeRole === 'guru'
                && !in_array((int)$row['id_kelas_mata_pelajaran'], $allowedKmpIds, true)
                && !in_array((int)$row['id_mata_pelajaran'], $allowedMapelIds, true)
            ) {
                $sendJson('error', 'Anda tidak mengampu mata pelajaran untuk CP ini.', null, 403);
            }
            return $row;
        };

        // Helper: Ambil CP & TP untuk KMP (fallback ke CP se-mapel buatan Admin bila rombel belum punya salinan CP)
        $fetchCurriculumForKmp = function ($idKmp) use ($pdo, $kmpById) {
            $cps = $pdo->prepare("
                SELECT c.id, c.id_kelas_mata_pelajaran, c.id_elemen, c.cp, e.nama_elemen, e.fase, k.nama_kelas
                FROM cp c
                LEFT JOIN elemen e ON c.id_elemen = e.id
                JOIN kelas_mata_pelajaran kmp ON c.id_kelas_mata_pelajaran = kmp.id
                JOIN kelas k ON kmp.id_kelas = k.id
                WHERE c.id_kelas_mata_pelajaran = ?
                ORDER BY c.id ASC
            ");
            $cps->execute([$idKmp]);
            $cpList = $cps->fetchAll(PDO::FETCH_ASSOC);

            $usedFallback = false;
            if (empty($cpList) && isset($kmpById[$idKmp])) {
                $idMapel = (int)$kmpById[$idKmp]['id_mata_pelajaran'];
                $cpsFb = $pdo->prepare("
                    SELECT c.id, c.id_kelas_mata_pelajaran, c.id_elemen, c.cp, e.nama_elemen, e.fase, k.nama_kelas
                    FROM cp c
                    LEFT JOIN elemen e ON c.id_elemen = e.id
                    JOIN kelas_mata_pelajaran kmp ON c.id_kelas_mata_pelajaran = kmp.id
                    JOIN kelas k ON kmp.id_kelas = k.id
                    WHERE kmp.id_mata_pelajaran = ?
                    ORDER BY c.id ASC
                ");
                $cpsFb->execute([$idMapel]);
                $cpList = $cpsFb->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($cpList)) $usedFallback = true;
            }

            $tpList = [];
            if (!empty($cpList)) {
                $cpIdsIn = implode(',', array_map(function ($r) { return (int)$r['id']; }, $cpList));
                $tpList = $pdo->query("
                    SELECT t.id, t.id_cp, t.kode_tp, t.tp, t.materi_pokok, t.alokasi_jam
                    FROM tp t
                    WHERE t.id_cp IN ($cpIdsIn)
                    ORDER BY t.id ASC
                ")->fetchAll(PDO::FETCH_ASSOC);
            }

            $atpByTp = [];
            $itpByTp = [];
            if (!empty($tpList)) {
                $tpIdsIn = implode(',', array_map(function ($r) { return (int)$r['id']; }, $tpList));
                foreach ($pdo->query("SELECT * FROM atp WHERE id_tp IN ($tpIdsIn) ORDER BY urutan ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) as $a) {
                    $atpByTp[(int)$a['id_tp']][] = $a;
                }
                foreach ($pdo->query("SELECT * FROM itp WHERE id_tp IN ($tpIdsIn) ORDER BY urutan ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) as $i) {
                    $itpByTp[(int)$i['id_tp']][] = $i;
                }
            }
            foreach ($tpList as &$t) {
                $t['id'] = (int)$t['id'];
                $t['id_cp'] = (int)$t['id_cp'];
                $t['alokasi_jam'] = (int)($t['alokasi_jam'] ?: 2);
                $t['atp_list'] = $atpByTp[$t['id']] ?? [];
                $t['itp_list'] = $itpByTp[$t['id']] ?? [];
            }
            unset($t);

            return [
                'cp_list'        => $cpList,
                'tp_list'        => $tpList,
                'mapel_fallback' => $usedFallback,
            ];
        };

        /* ----------------------------------------------------------------------
         * 1. BOOTSTRAP
         * ---------------------------------------------------------------------- */
        if ($action === 'bootstrap') {
            $inKmpSql = !empty($allowedKmpIds) ? implode(',', array_map('intval', $allowedKmpIds)) : '0';
            $inMapelSql = !empty($allowedMapelIds) ? implode(',', array_map('intval', $allowedMapelIds)) : '0';

            $cpPerKmp = [];
            $cpPerMapel = [];
            $tpPerKmp = [];
            $tpPerMapel = [];
            $maPerKmp = [];
            if (!empty($allowedKmpIds)) {
                foreach ($pdo->query("SELECT id_kelas_mata_pelajaran, COUNT(*) AS cnt FROM cp WHERE id_kelas_mata_pelajaran IN ($inKmpSql) GROUP BY id_kelas_mata_pelajaran")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $cpPerKmp[(int)$r['id_kelas_mata_pelajaran']] = (int)$r['cnt'];
                }
                foreach ($pdo->query("SELECT kmp.id_mata_pelajaran, COUNT(c.id) AS cnt FROM cp c JOIN kelas_mata_pelajaran kmp ON c.id_kelas_mata_pelajaran = kmp.id WHERE kmp.id_mata_pelajaran IN ($inMapelSql) GROUP BY kmp.id_mata_pelajaran")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $cpPerMapel[(int)$r['id_mata_pelajaran']] = (int)$r['cnt'];
                }
                foreach ($pdo->query("SELECT c.id_kelas_mata_pelajaran, COUNT(t.id) AS cnt FROM tp t JOIN cp c ON t.id_cp = c.id WHERE c.id_kelas_mata_pelajaran IN ($inKmpSql) GROUP BY c.id_kelas_mata_pelajaran")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $tpPerKmp[(int)$r['id_kelas_mata_pelajaran']] = (int)$r['cnt'];
                }
                foreach ($pdo->query("SELECT kmp.id_mata_pelajaran, COUNT(t.id) AS cnt FROM tp t JOIN cp c ON t.id_cp = c.id JOIN kelas_mata_pelajaran kmp ON c.id_kelas_mata_pelajaran = kmp.id WHERE kmp.id_mata_pelajaran IN ($inMapelSql) GROUP BY kmp.id_mata_pelajaran")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $tpPerMapel[(int)$r['id_mata_pelajaran']] = (int)$r['cnt'];
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

            $groupedMap = [];
            $totalModul = 0;
            $tahunSet = [$defaultTahunAktif => true];
            $gradeSet = [];

            foreach ($kmpRows as $kr) {
                $kid = (int)$kr['id_kmp'];
                $mid = (int)$kr['id_mata_pelajaran'];
                $cCntDirect = $cpPerKmp[$kid] ?? 0;
                $cCnt = $cCntDirect > 0 ? $cCntDirect : ($cpPerMapel[$mid] ?? 0);
                $tCntDirect = $tpPerKmp[$kid] ?? 0;
                $tCnt = $tCntDirect > 0 ? $tCntDirect : ($cCntDirect === 0 ? ($tpPerMapel[$mid] ?? 0) : 0);
                $mStat = $maPerKmp[$kid] ?? ['total' => 0, 'draft' => 0, 'published' => 0, 'final' => 0];

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
                    'semester'        => $kr['mapel_semester'] ?: $defaultSemAktif,
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
            foreach ($metodes as &$mt) $mt['id'] = (int)$mt['id'];
            unset($mt);

            // Daftar Nama Elemen unik untuk pilihan cepat Admin saat Tambah CP
            $elemenNames = $pdo->query("SELECT DISTINCT nama_elemen FROM elemen WHERE nama_elemen IS NOT NULL AND nama_elemen != '' ORDER BY nama_elemen ASC")->fetchAll(PDO::FETCH_COLUMN);

            // Bank Materi
            $materiList = [];
            try {
                $materiList = $pdo->query("SELECT id, id_kelas_mata_pelajaran, judul_materi, tipe_materi FROM materi ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $eM) {}

            // Tahun dari prota/prosem/jadwal juga disertakan
            try {
                foreach ($pdo->query("SELECT DISTINCT tahun_pelajaran FROM prosem UNION SELECT DISTINCT tahun_pelajaran FROM jadwal_pelajaran")->fetchAll(PDO::FETCH_COLUMN) as $ty) {
                    if (trim((string)$ty) !== '') $tahunSet[$ty] = true;
                }
            } catch (Exception $eT) {}

            $tahunList = array_keys($tahunSet);
            rsort($tahunList);

            $gradeOrder = ['X', 'XI', 'XII', 'VII', 'VIII', 'IX', 'Lainnya'];
            $uniqueGrades = [];
            foreach ($gradeOrder as $g) {
                if (isset($gradeSet[$g])) $uniqueGrades[] = $g;
            }

            $totalCp = 0;
            foreach ($cpPerMapel as $v) $totalCp += (int)$v;
            $totalTp = 0;
            foreach ($tpPerMapel as $v) $totalTp += (int)$v;

            $sendJson('success', 'Data modul ajar berhasil dimuat.', [
                'kmp_list'      => $kmpRows,
                'subjects'      => array_values($groupedMap),
                'models'        => $models,
                'metodes'       => $metodes,
                'elemen_names'  => $elemenNames,
                'materi_list'   => $materiList,
                'tahun_list'    => $tahunList,
                'default_tahun' => $defaultTahunAktif,
                'default_sem'   => $defaultSemAktif,
                'unique_grades' => $uniqueGrades,
                'counts'        => [
                    'cp'    => $totalCp,
                    'tp'    => $totalTp,
                    'modul' => $totalModul,
                ],
                'active_role'   => $activeRole,
                'is_admin'      => $isAdmin,
                'nama_guru'     => $namaGuruLogged,
            ]);
        }

        /* ----------------------------------------------------------------------
         * 2. PROTA + PROSEM + KALDIK + JADWAL PLANNING SLOTS & QUOTA ENGINE
         * ---------------------------------------------------------------------- */
        if ($action === 'get_planning_slots') {
            $idKmp = $assertKmpAccess($in['id_kmp'] ?? 0);
            $idCp  = (int)($in['id_cp'] ?? 0);
            $idTp  = (int)($in['id_tp'] ?? 0);

            // Pastikan jadwal pertemuan KMP sudah sinkron dengan Jadwal Mingguan Admin & Kaldik
            if (function_exists('ilmuna_auto_ensure_kmp_synced')) {
                try {
                    ilmuna_auto_ensure_kmp_synced($pdo, $idKmp);
                } catch (Exception $eSyncMa) {}
            }

            $kmpInfo = $kmpById[$idKmp] ?? null;
            $cur = $fetchCurriculumForKmp($idKmp);

            // 1. Cari default jam mulai/selesai, hari mengajar, & JP per pertemuan untuk KMP ini
            $defaultHari = 'Senin';
            $defaultJamMulai = '07:30';
            $defaultJamSelesai = '09:00';
            $defaultJpPerPertemuan = 2;
            $defaultRuangan = '';

            $jpSample = $pdo->prepare("
                SELECT hari, jam_mulai, jam_selesai, alokasi_jp, ruangan, tahun_pelajaran, semester
                FROM jadwal_pelajaran
                WHERE id_kelas_mata_pelajaran = ?
                ORDER BY tahun_pelajaran DESC, tanggal ASC
                LIMIT 1
            ");
            $jpSample->execute([$idKmp]);
            $jDef = $jpSample->fetch(PDO::FETCH_ASSOC);
            if ($jDef) {
                $defaultHari = $jDef['hari'] ?: $defaultHari;
                $defaultJamMulai = substr((string)$jDef['jam_mulai'], 0, 5) ?: $defaultJamMulai;
                $defaultJamSelesai = substr((string)$jDef['jam_selesai'], 0, 5) ?: $defaultJamSelesai;
                $defaultJpPerPertemuan = max(1, (int)($jDef['alokasi_jp'] ?: 2));
                $defaultRuangan = (string)($jDef['ruangan'] ?? '');
            } else {
                $ahmDef = $pdo->prepare("SELECT hari, jp_per_minggu, semester FROM alokasi_hari_mengajar WHERE id_kelas_mata_pelajaran = ? ORDER BY id ASC LIMIT 1");
                $ahmDef->execute([$idKmp]);
                $aRow = $ahmDef->fetch(PDO::FETCH_ASSOC);
                if ($aRow) {
                    $defaultHari = $aRow['hari'] ?: $defaultHari;
                    $defaultJpPerPertemuan = max(1, (int)($aRow['jp_per_minggu'] ?: 2));
                }
            }

            // 2. Modul Ajar yang sudah dibuat pada KMP ini (untuk hitung terpakai & tandai slot terpakai)
            $exModStmt = $pdo->prepare("
                SELECT id, id_cp, id_tp, cp_ids_json, tp_ids_json, pertemuan_ke, tanggal, id_jadwal_pelajaran, judul_modul, status, meeting_status
                FROM modul_ajar
                WHERE id_kelas_mata_pelajaran = ?
                ORDER BY pertemuan_ke ASC
            ");
            $exModStmt->execute([$idKmp]);
            $existingModuls = $exModStmt->fetchAll(PDO::FETCH_ASSOC);

            $usedByPertemuan = [];
            $usedByJadwalId = [];
            $cpUsedCount = 0;
            $tpUsedCount = 0;
            $maxPertemuanUsed = 0;

            foreach ($existingModuls as $em) {
                $pKe = (int)$em['pertemuan_ke'];
                if ($pKe > $maxPertemuanUsed) $maxPertemuanUsed = $pKe;
                $usedByPertemuan[$pKe] = (int)$em['id'];
                if (!empty($em['id_jadwal_pelajaran'])) {
                    $usedByJadwalId[(int)$em['id_jadwal_pelajaran']] = (int)$em['id'];
                }
                $mCpIds = parseJsonIdArray($em['cp_ids_json'] ?? null, $em['id_cp'] ?? null);
                $mTpIds = parseJsonIdArray($em['tp_ids_json'] ?? null, $em['id_tp'] ?? null);
                if ($idCp > 0 && in_array($idCp, $mCpIds, true)) $cpUsedCount++;
                if ($idTp > 0 && in_array($idTp, $mTpIds, true)) $tpUsedCount++;
            }

            // 3. Hitung Kuota CP (akumulasi JP & pertemuan seluruh TP milik CP ini dari Prosem / Prota / TP)
            $cpTotalJp = 0;
            $cpKuotaPertemuan = 0;
            if ($idCp > 0) {
                $cpTps = array_filter($cur['tp_list'], function ($t) use ($idCp) {
                    return (int)$t['id_cp'] === $idCp;
                });
                foreach ($cpTps as $ct) {
                    $tid = (int)$ct['id'];
                    // Cek alokasi di prosem_detail / prota_detail untuk KMP ini
                    $qPs = $pdo->prepare("SELECT pd.alokasi_waktu FROM prosem_detail pd JOIN prosem ps ON pd.id_prosem = ps.id WHERE ps.id_kelas_mata_pelajaran = ? AND pd.id_tp = ? ORDER BY ps.tahun_pelajaran DESC, pd.id DESC LIMIT 1");
                    $qPs->execute([$idKmp, $tid]);
                    $jpVal = (int)$qPs->fetchColumn();
                    if ($jpVal <= 0) {
                        $qPt = $pdo->prepare("SELECT pd.alokasi_waktu FROM prota_detail pd JOIN prota pt ON pd.id_prota = pt.id WHERE pt.id_kelas_mata_pelajaran = ? AND pd.id_tp = ? ORDER BY pt.tahun_pelajaran DESC, pd.id DESC LIMIT 1");
                        $qPt->execute([$idKmp, $tid]);
                        $jpVal = (int)$qPt->fetchColumn();
                    }
                    if ($jpVal <= 0) $jpVal = max(2, (int)($ct['alokasi_jam'] ?: 2));
                    $cpTotalJp += $jpVal;
                    $cpKuotaPertemuan += max(1, (int)ceil($jpVal / $defaultJpPerPertemuan));
                }
            }

            // 4. Hitung Kuota TP + Kumpulkan Slot Pertemuan dari JADWAL -> PROSEM -> KALDIK
            $tpTotalJp = 0;
            $tpKuotaPertemuan = 0;
            $planTahun = $defaultTahunAktif;
            $planSem = $defaultSemAktif;
            $atpList = [];
            $itpList = [];
            $slots = [];
            $planSource = 'kaldik';

            if ($idTp > 0) {
                // Ambil TP info, ATP, ITP
                foreach ($cur['tp_list'] as $tRow) {
                    if ((int)$tRow['id'] === $idTp) {
                        $atpList = $tRow['atp_list'] ?? [];
                        $itpList = $tRow['itp_list'] ?? [];
                        $tpTotalJp = max(2, (int)($tRow['alokasi_jam'] ?: 2));
                        break;
                    }
                }

                // Cek PROSEM & PROTA untuk TP ini pada KMP ini
                $psRow = null;
                $qPsDet = $pdo->prepare("
                    SELECT pd.id AS prosem_detail_id, pd.alokasi_waktu, ps.tahun_pelajaran, ps.semester, ps.id AS prosem_id
                    FROM prosem_detail pd
                    JOIN prosem ps ON pd.id_prosem = ps.id
                    WHERE ps.id_kelas_mata_pelajaran = ? AND pd.id_tp = ?
                    ORDER BY (ps.tahun_pelajaran = ?) DESC, ps.tahun_pelajaran DESC, pd.id DESC
                    LIMIT 1
                ");
                $qPsDet->execute([$idKmp, $idTp, $defaultTahunAktif]);
                $psRow = $qPsDet->fetch(PDO::FETCH_ASSOC);

                if ($psRow) {
                    $tpTotalJp = max(1, (int)$psRow['alokasi_waktu']);
                    $planTahun = $psRow['tahun_pelajaran'] ?: $planTahun;
                    $planSem   = $psRow['semester'] ?: $planSem;
                } else {
                    $qPtDet = $pdo->prepare("
                        SELECT pd.alokasi_waktu, pd.semester, pt.tahun_pelajaran
                        FROM prota_detail pd
                        JOIN prota pt ON pd.id_prota = pt.id
                        WHERE pt.id_kelas_mata_pelajaran = ? AND pd.id_tp = ?
                        ORDER BY (pt.tahun_pelajaran = ?) DESC, pt.tahun_pelajaran DESC, pd.id DESC
                        LIMIT 1
                    ");
                    $qPtDet->execute([$idKmp, $idTp, $defaultTahunAktif]);
                    $ptRow = $qPtDet->fetch(PDO::FETCH_ASSOC);
                    if ($ptRow) {
                        $tpTotalJp = max(1, (int)$ptRow['alokasi_waktu']);
                        $planTahun = $ptRow['tahun_pelajaran'] ?: $planTahun;
                        $planSem   = $ptRow['semester'] ?: $planSem;
                    }
                }

                // PRIORITAS 1: Slot nyata di `jadwal_pelajaran` untuk (id_kmp, id_tp)
                $jStmt = $pdo->prepare("
                    SELECT id, pertemuan_ke, tahun_pelajaran, semester, hari, tanggal,
                           jam_mulai, jam_selesai, alokasi_jp, ruangan, materi_pembahasan, id_modul_ajar
                    FROM jadwal_pelajaran
                    WHERE id_kelas_mata_pelajaran = ? AND id_tp = ?
                    ORDER BY (tahun_pelajaran = ?) DESC, tanggal ASC, pertemuan_ke ASC
                ");
                $jStmt->execute([$idKmp, $idTp, $defaultTahunAktif]);
                $jRows = $jStmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($jRows)) {
                    $planSource = 'jadwal_pelajaran';
                    $planTahun = $jRows[0]['tahun_pelajaran'] ?: $planTahun;
                    $planSem   = $jRows[0]['semester'] ?: $planSem;
                    foreach ($jRows as $jr) {
                        $pKe = (int)$jr['pertemuan_ke'];
                        $jid = (int)$jr['id'];
                        $existingId = $usedByJadwalId[$jid] ?? ($usedByPertemuan[$pKe] ?? (!empty($jr['id_modul_ajar']) ? (int)$jr['id_modul_ajar'] : 0));
                        $slots[] = [
                            'slot_key'          => 'jadwal_' . $jid,
                            'source'            => 'Jadwal Pelajaran',
                            'jadwal_id'         => $jid,
                            'prosem_detail_id'  => $psRow ? (int)$psRow['prosem_detail_id'] : 0,
                            'pertemuan_ke'      => $pKe,
                            'tahun_pelajaran'   => $jr['tahun_pelajaran'] ?: $planTahun,
                            'semester'          => $jr['semester'] ?: $planSem,
                            'hari'              => $jr['hari'] ?: getIndoDayFromDate($jr['tanggal']),
                            'tanggal'           => $jr['tanggal'],
                            'jam_mulai'         => substr((string)$jr['jam_mulai'], 0, 5),
                            'jam_selesai'       => substr((string)$jr['jam_selesai'], 0, 5),
                            'alokasi_jp'        => max(1, (int)($jr['alokasi_jp'] ?: $defaultJpPerPertemuan)),
                            'ruangan'           => (string)($jr['ruangan'] ?? $defaultRuangan),
                            'keterangan'        => (string)($jr['materi_pembahasan'] ?? ''),
                            'is_used'           => $existingId > 0,
                            'existing_modul_id' => $existingId,
                        ];
                    }
                    $tpKuotaPertemuan = max(count($slots), (int)ceil($tpTotalJp / $defaultJpPerPertemuan));
                }

                // PRIORITAS 2: Jika tidak ada di jadwal_pelajaran, ambil dari `prosem_jadwal_mingguan` + Kaldik (`hari_efektif` / `kalender_pendidikan`)
                if (empty($slots) && $psRow) {
                    $pmStmt = $pdo->prepare("
                        SELECT id, bulan, urutan_bulan, pekan_ke, jam_tatap_muka, keterangan
                        FROM prosem_jadwal_mingguan
                        WHERE id_prosem_detail = ?
                        ORDER BY urutan_bulan ASC, pekan_ke ASC, id ASC
                    ");
                    $pmStmt->execute([(int)$psRow['prosem_detail_id']]);
                    $pmRows = $pmStmt->fetchAll(PDO::FETCH_ASSOC);

                    if (!empty($pmRows)) {
                        $planSource = 'prosem_kaldik';
                        // Hitung urutan pertemuan awal di prosem (sebelum prosem_detail ini)
                        $prevCntStmt = $pdo->prepare("
                            SELECT COUNT(pjm.id)
                            FROM prosem_jadwal_mingguan pjm
                            JOIN prosem_detail pd ON pjm.id_prosem_detail = pd.id
                            WHERE pd.id_prosem = ? AND pd.kategori = 'Materi KBM' AND pd.nomor_urut < (
                                SELECT nomor_urut FROM prosem_detail WHERE id = ? LIMIT 1
                            )
                        ");
                        $prevCntStmt->execute([(int)$psRow['prosem_id'], (int)$psRow['prosem_detail_id']]);
                        $offsetPert = (int)$prevCntStmt->fetchColumn();

                        // Ambil hari mengajar untuk semester ini dari alokasi_hari_mengajar
                        $ahmSem = $pdo->prepare("SELECT hari, jp_per_minggu FROM alokasi_hari_mengajar WHERE id_kelas_mata_pelajaran = ? AND semester = ? ORDER BY id ASC LIMIT 1");
                        $ahmSem->execute([$idKmp, $planSem]);
                        $aSemRow = $ahmSem->fetch(PDO::FETCH_ASSOC);
                        $teachDay = ($aSemRow && !empty($aSemRow['hari'])) ? $aSemRow['hari'] : $defaultHari;

                        foreach ($pmRows as $idx => $pm) {
                            $pKe = $offsetPert + $idx + 1;
                            if (!empty($pm['keterangan']) && preg_match('/Pertemuan\s*(\d+)/i', $pm['keterangan'], $mp)) {
                                $pKe = (int)$mp[1];
                            }
                            $mNum = bulanIndoToNum($pm['bulan']);
                            $pekanKe = max(1, (int)$pm['pekan_ke']);
                            $resolvedDate = '';

                            if ($mNum > 0) {
                                // Cari tanggal efektif pada bulan & hari mengajar dari hari_efektif lalu fallback kalender_pendidikan
                                $qHe = $pdo->prepare("
                                    SELECT tanggal FROM hari_efektif
                                    WHERE id_kelas_mata_pelajaran = ? AND tahun_pelajaran = ? AND semester = ?
                                      AND MONTH(tanggal) = ? AND hari = ? AND status_hari = 'Efektif KBM'
                                    ORDER BY tanggal ASC
                                ");
                                $qHe->execute([$idKmp, $planTahun, $planSem, $mNum, $teachDay]);
                                $dates = $qHe->fetchAll(PDO::FETCH_COLUMN);

                                if (empty($dates)) {
                                    $qKp = $pdo->prepare("
                                        SELECT tanggal FROM kalender_pendidikan
                                        WHERE tahun_pelajaran = ? AND semester = ?
                                          AND MONTH(tanggal) = ? AND hari = ? AND status_hari = 'Efektif KBM'
                                        ORDER BY tanggal ASC
                                    ");
                                    $qKp->execute([$planTahun, $planSem, $mNum, $teachDay]);
                                    $dates = $qKp->fetchAll(PDO::FETCH_COLUMN);
                                }
                                if (!empty($dates)) {
                                    $pickIdx = min(count($dates) - 1, max(0, $pekanKe - 1));
                                    $resolvedDate = $dates[$pickIdx];
                                }
                            }

                            $existingId = $usedByPertemuan[$pKe] ?? 0;
                            $slots[] = [
                                'slot_key'          => 'prosem_' . $pm['id'],
                                'source'            => 'PROSEM & Kaldik (' . $pm['bulan'] . ' P' . $pekanKe . ')',
                                'jadwal_id'         => 0,
                                'prosem_detail_id'  => (int)$psRow['prosem_detail_id'],
                                'pertemuan_ke'      => $pKe,
                                'tahun_pelajaran'   => $planTahun,
                                'semester'          => $planSem,
                                'hari'              => $resolvedDate ? getIndoDayFromDate($resolvedDate) : $teachDay,
                                'tanggal'           => $resolvedDate,
                                'jam_mulai'         => $defaultJamMulai,
                                'jam_selesai'       => $defaultJamSelesai,
                                'alokasi_jp'        => max(1, (int)($pm['jam_tatap_muka'] ?: $defaultJpPerPertemuan)),
                                'ruangan'           => $defaultRuangan,
                                'keterangan'        => (string)($pm['keterangan'] ?: ($pm['bulan'] . ' Pekan ' . $pekanKe)),
                                'is_used'           => $existingId > 0,
                                'existing_modul_id' => $existingId,
                            ];
                        }
                        $tpKuotaPertemuan = count($slots);
                    }
                }

                // PRIORITAS 3: Fallback ke Kaldik / Hari Efektif + Kuota JP TP (agar selalu ada slot tanggal valid!)
                if (empty($slots)) {
                    $tpKuotaPertemuan = max(1, (int)ceil($tpTotalJp / $defaultJpPerPertemuan));
                    // Ambil daftar tanggal Efektif KBM dari hari_efektif atau kalender_pendidikan pada hari mengajar
                    $qCal = $pdo->prepare("
                        SELECT tanggal, hari FROM hari_efektif
                        WHERE id_kelas_mata_pelajaran = ? AND tahun_pelajaran = ? AND semester = ?
                          AND hari = ? AND status_hari = 'Efektif KBM'
                        ORDER BY tanggal ASC
                        LIMIT 40
                    ");
                    $qCal->execute([$idKmp, $planTahun, $planSem, $defaultHari]);
                    $calDates = $qCal->fetchAll(PDO::FETCH_ASSOC);

                    if (empty($calDates)) {
                        $qCal2 = $pdo->prepare("
                            SELECT tanggal, hari FROM kalender_pendidikan
                            WHERE tahun_pelajaran = ? AND semester = ?
                              AND hari = ? AND status_hari = 'Efektif KBM'
                            ORDER BY tanggal ASC
                            LIMIT 40
                        ");
                        $qCal2->execute([$planTahun, $planSem, $defaultHari]);
                        $calDates = $qCal2->fetchAll(PDO::FETCH_ASSOC);
                    }

                    // Hitung offset pertemuan berdasarkan urutan TP pada CP/mapel ini
                    $startPert = $maxPertemuanUsed + 1;
                    // Juga cek jika ada pertemuan yang sudah dibuat untuk TP ini agar tetap terlihat
                    $tpExistingPerts = [];
                    foreach ($existingModuls as $em) {
                        $mTpIds = parseJsonIdArray($em['tp_ids_json'] ?? null, $em['id_tp'] ?? null);
                        if (in_array($idTp, $mTpIds, true)) {
                            $tpExistingPerts[] = $em;
                        }
                    }
                    foreach ($tpExistingPerts as $em) {
                        $pKe = (int)$em['pertemuan_ke'];
                        $slots[] = [
                            'slot_key'          => 'existing_' . $em['id'],
                            'source'            => 'Modul Sudah Dibuat',
                            'jadwal_id'         => (int)($em['id_jadwal_pelajaran'] ?? 0),
                            'prosem_detail_id'  => $psRow ? (int)$psRow['prosem_detail_id'] : 0,
                            'pertemuan_ke'      => $pKe,
                            'tahun_pelajaran'   => $planTahun,
                            'semester'          => $planSem,
                            'hari'              => $em['tanggal'] ? getIndoDayFromDate($em['tanggal']) : $defaultHari,
                            'tanggal'           => $em['tanggal'] ?: '',
                            'jam_mulai'         => $defaultJamMulai,
                            'jam_selesai'       => $defaultJamSelesai,
                            'alokasi_jp'        => $defaultJpPerPertemuan,
                            'ruangan'           => $defaultRuangan,
                            'keterangan'        => 'Pertemuan ke-' . $pKe,
                            'is_used'           => true,
                            'existing_modul_id' => (int)$em['id'],
                        ];
                    }

                    $needMore = max(1, $tpKuotaPertemuan - count($tpExistingPerts));
                    for ($k = 0; $k < $needMore; $k++) {
                        $pKe = $startPert + $k;
                        $calIdx = min(max(0, count($calDates) - 1), $pKe - 1);
                        $dt = !empty($calDates[$calIdx]['tanggal']) ? $calDates[$calIdx]['tanggal'] : date('Y-m-d', strtotime('+' . ($k * 7) . ' days'));
                        $hr = !empty($calDates[$calIdx]['hari']) ? $calDates[$calIdx]['hari'] : getIndoDayFromDate($dt);
                        $existingId = $usedByPertemuan[$pKe] ?? 0;
                        $slots[] = [
                            'slot_key'          => 'kaldik_' . $pKe,
                            'source'            => !empty($calDates) ? 'Kaldik & Alokasi TP' : 'Estimasi Alokasi TP',
                            'jadwal_id'         => 0,
                            'prosem_detail_id'  => $psRow ? (int)$psRow['prosem_detail_id'] : 0,
                            'pertemuan_ke'      => $pKe,
                            'tahun_pelajaran'   => $planTahun,
                            'semester'          => $planSem,
                            'hari'              => $hr,
                            'tanggal'           => $dt,
                            'jam_mulai'         => $defaultJamMulai,
                            'jam_selesai'       => $defaultJamSelesai,
                            'alokasi_jp'        => $defaultJpPerPertemuan,
                            'ruangan'           => $defaultRuangan,
                            'keterangan'        => 'Kuota Pertemuan TP (' . ($k + 1) . '/' . $tpKuotaPertemuan . ')',
                            'is_used'           => $existingId > 0,
                            'existing_modul_id' => $existingId,
                        ];
                    }
                }
            }

            $sendJson('success', 'Slot pertemuan & kuota PROTA/PROSEM/Kaldik berhasil dikalkulasi.', [
                'source'   => $planSource,
                'cp_quota' => [
                    'total_jp'        => $cpTotalJp,
                    'kuota_pertemuan' => $cpKuotaPertemuan,
                    'terpakai'        => $cpUsedCount,
                    'sisa'            => max(0, $cpKuotaPertemuan - $cpUsedCount),
                ],
                'tp_quota' => [
                    'total_jp'         => $tpTotalJp,
                    'jp_per_pertemuan' => $defaultJpPerPertemuan,
                    'kuota_pertemuan'  => $tpKuotaPertemuan,
                    'terpakai'         => $tpUsedCount,
                    'sisa'             => max(0, $tpKuotaPertemuan - $tpUsedCount),
                    'tahun_pelajaran'  => $planTahun,
                    'semester'         => $planSem,
                ],
                'atp_list' => $atpList,
                'itp_list' => $itpList,
                'slots'    => $slots,
                'defaults' => [
                    'tahun_pelajaran' => $planTahun,
                    'semester'        => $planSem,
                    'hari'            => $defaultHari,
                    'jam_mulai'       => $defaultJamMulai,
                    'jam_selesai'     => $defaultJamSelesai,
                    'alokasi_jp'      => $defaultJpPerPertemuan,
                    'next_pertemuan'  => $maxPertemuanUsed + 1,
                ],
            ]);
        }

        /* ----------------------------------------------------------------------
         * 3. LIST CP & ELEMEN
         * ---------------------------------------------------------------------- */
        if ($action === 'list_cp') {
            $filterKmp = (int)($in['id_kmp'] ?? 0);
            $filterMapel = (int)($in['id_mapel'] ?? 0);
            $q = trim((string)($in['q'] ?? ''));

            if (empty($allowedKmpIds)) {
                $sendJson('success', 'OK', ['elemen_list' => [], 'cp_list' => []]);
            }
            $inKmpSql = implode(',', array_map('intval', $allowedKmpIds));
            $inMapelSql = !empty($allowedMapelIds) ? implode(',', array_map('intval', $allowedMapelIds)) : '0';

            $elWhere = ["(e.id_kelas_mata_pelajaran IN ($inKmpSql) OR kmp.id_mata_pelajaran IN ($inMapelSql))"];
            $elParams = [];
            if ($filterKmp > 0 && isset($kmpById[$filterKmp])) {
                $elWhere[] = "(e.id_kelas_mata_pelajaran = ? OR kmp.id_mata_pelajaran = ?)";
                $elParams[] = $filterKmp;
                $elParams[] = (int)$kmpById[$filterKmp]['id_mata_pelajaran'];
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

            $cpWhere = ["(c.id_kelas_mata_pelajaran IN ($inKmpSql) OR kmp.id_mata_pelajaran IN ($inMapelSql))"];
            $cpParams = [];
            if ($filterKmp > 0 && isset($kmpById[$filterKmp])) {
                $cpWhere[] = "(c.id_kelas_mata_pelajaran = ? OR kmp.id_mata_pelajaran = ?)";
                $cpParams[] = $filterKmp;
                $cpParams[] = (int)$kmpById[$filterKmp]['id_mata_pelajaran'];
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
         * 4. CRUD CP & ELEMEN (KHUSUS ADMIN — SUPPORT MULTI-KELAS CHECKBOX!)
         * ---------------------------------------------------------------------- */
        if ($action === 'save_elemen') {
            $assertAdminCp();
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
            $assertAdminCp();
            $id = (int)($in['id'] ?? 0);
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM cp WHERE id_elemen = ?");
            $cnt->execute([$id]);
            if ((int)$cnt->fetchColumn() > 0) {
                $sendJson('error', 'Elemen masih memiliki CP terkait.', null, 400);
            }
            $pdo->prepare("DELETE FROM elemen WHERE id = ?")->execute([$id]);
            $sendJson('success', 'Elemen berhasil dihapus.');
        }

        if ($action === 'save_cp') {
            $assertAdminCp();
            $id = (int)($in['id'] ?? 0);
            $cpText = trim((string)($in['cp'] ?? ''));
            if ($cpText === '') {
                $sendJson('error', 'Deskripsi Capaian Pembelajaran (CP) wajib diisi.', null, 400);
            }

            $elemenNamaPilih = trim((string)($in['elemen_nama'] ?? ''));
            $newElemenNama   = trim((string)($in['new_elemen_nama'] ?? ''));
            $finalElemenNama = $newElemenNama !== '' ? $newElemenNama : $elemenNamaPilih;
            $faseInput       = trim((string)($in['fase'] ?? 'AUTO'));

            // Mode Edit 1 CP
            if ($id > 0) {
                $idKmp = $assertKmpAccess($in['id_kelas_mata_pelajaran'] ?? 0);
                $idElemen = (int)($in['id_elemen'] ?? 0);
                $faseRow = ($faseInput === '' || $faseInput === 'AUTO') ? ($kmpById[$idKmp]['fase'] ?? 'Fase E') : $faseInput;

                if ($finalElemenNama !== '') {
                    $cekEl = $pdo->prepare("SELECT id FROM elemen WHERE id_kelas_mata_pelajaran = ? AND nama_elemen = ? LIMIT 1");
                    $cekEl->execute([$idKmp, $finalElemenNama]);
                    $exEl = $cekEl->fetchColumn();
                    if ($exEl) {
                        $idElemen = (int)$exEl;
                    } else {
                        $pdo->prepare("INSERT INTO elemen (id_kelas_mata_pelajaran, nama_elemen, fase, deskripsi) VALUES (?, ?, ?, ?)")
                            ->execute([$idKmp, $finalElemenNama, $faseRow, '']);
                        $idElemen = (int)$pdo->lastInsertId();
                    }
                }

                $pdo->prepare("UPDATE cp SET id_kelas_mata_pelajaran = ?, id_elemen = ?, cp = ? WHERE id = ?")
                    ->execute([$idKmp, $idElemen > 0 ? $idElemen : null, $cpText, $id]);
                $sendJson('success', 'Capaian Pembelajaran (CP) berhasil diperbarui.', ['id' => $id]);
            }

            // Mode Tambah CP: MULTIPLE CHOICE KELAS/ROMBEL!
            $kmpIds = parseJsonIdArray($in['kmp_ids'] ?? ($in['id_kelas_mata_pelajaran'] ?? []));
            if (empty($kmpIds)) {
                $sendJson('error', 'Centang minimal 1 Kelas / Rombel untuk menambahkan CP.', null, 400);
            }
            foreach ($kmpIds as $kid) $assertKmpAccess($kid);

            $inserted = 0;
            $skipped = 0;
            $createdIds = [];

            $pdo->beginTransaction();
            try {
                foreach ($kmpIds as $kid) {
                    $faseRow = ($faseInput === '' || $faseInput === 'AUTO') ? ($kmpById[$kid]['fase'] ?? 'Fase E') : $faseInput;
                    $elIdForKmp = null;

                    if ($finalElemenNama !== '') {
                        $cekEl = $pdo->prepare("SELECT id FROM elemen WHERE id_kelas_mata_pelajaran = ? AND nama_elemen = ? LIMIT 1");
                        $cekEl->execute([$kid, $finalElemenNama]);
                        $exEl = $cekEl->fetchColumn();
                        if ($exEl) {
                            $elIdForKmp = (int)$exEl;
                        } else {
                            $pdo->prepare("INSERT INTO elemen (id_kelas_mata_pelajaran, nama_elemen, fase, deskripsi) VALUES (?, ?, ?, ?)")
                                ->execute([$kid, $finalElemenNama, $faseRow, '']);
                            $elIdForKmp = (int)$pdo->lastInsertId();
                        }
                    }

                    // Cegah duplikasi identik pada rombel yang sama
                    $cekCp = $pdo->prepare("SELECT id FROM cp WHERE id_kelas_mata_pelajaran = ? AND cp = ? LIMIT 1");
                    $cekCp->execute([$kid, $cpText]);
                    if ($cekCp->fetchColumn()) {
                        $skipped++;
                        continue;
                    }

                    $pdo->prepare("INSERT INTO cp (id_kelas_mata_pelajaran, id_elemen, cp) VALUES (?, ?, ?)")
                        ->execute([$kid, $elIdForKmp, $cpText]);
                    $createdIds[] = (int)$pdo->lastInsertId();
                    $inserted++;
                }
                $pdo->commit();
            } catch (Exception $eTx) {
                $pdo->rollBack();
                throw $eTx;
            }

            $msg = "Berhasil menambahkan CP ke {$inserted} kelas/rombel." . ($skipped > 0 ? " ({$skipped} kelas sudah memiliki CP yang sama.)" : '');
            $sendJson('success', $msg, [
                'inserted' => $inserted,
                'skipped'  => $skipped,
                'ids'      => $createdIds,
            ]);
        }

        if ($action === 'delete_cp') {
            $assertAdminCp();
            $id = (int)($in['id'] ?? 0);
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM tp WHERE id_cp = ?");
            $cnt->execute([$id]);
            if ((int)$cnt->fetchColumn() > 0) {
                $sendJson('error', 'CP ini masih digunakan oleh Tujuan Pembelajaran (TP) Guru.', null, 400);
            }
            $pdo->prepare("DELETE FROM cp WHERE id = ?")->execute([$id]);
            $sendJson('success', 'Capaian Pembelajaran (CP) berhasil dihapus.');
        }

        if ($action === 'sync_cp_mapel') {
            $assertAdminCp();
            $sourceKmp = $assertKmpAccess($in['source_kmp'] ?? 0);
            $targetKmps = parseJsonIdArray($in['target_kmps'] ?? ($in['target_kmp'] ?? []));
            if (empty($targetKmps)) $sendJson('error', 'Pilih minimal 1 kelas tujuan.', null, 400);

            $srcCps = $pdo->prepare("
                SELECT c.*, e.nama_elemen, e.fase, e.deskripsi AS elemen_desc
                FROM cp c
                LEFT JOIN elemen e ON c.id_elemen = e.id
                WHERE c.id_kelas_mata_pelajaran = ?
                ORDER BY c.id ASC
            ");
            $srcCps->execute([$sourceKmp]);
            $rows = $srcCps->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) $sendJson('error', 'Kelas sumber belum memiliki data CP untuk disalin.', null, 400);

            $copiedCp = 0;
            $pdo->beginTransaction();
            try {
                foreach ($targetKmps as $targetKmp) {
                    if ($targetKmp === $sourceKmp) continue;
                    $targetFase = $kmpById[$targetKmp]['fase'] ?? 'Fase E';
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
                        if (!$findCp->fetchColumn()) {
                            $pdo->prepare("INSERT INTO cp (id_kelas_mata_pelajaran, id_elemen, cp) VALUES (?, ?, ?)")
                                ->execute([$targetKmp, $newElId, $rc['cp']]);
                            $copiedCp++;
                        }
                    }
                }
                $pdo->commit();
            } catch (Exception $eTx) {
                $pdo->rollBack();
                throw $eTx;
            }
            $sendJson('success', "Sinkronisasi selesai: {$copiedCp} CP berhasil disalin ke kelas tujuan.", ['copied_cp' => $copiedCp]);
        }

        /* ----------------------------------------------------------------------
         * 5. LIST & CRUD TP, ATP, ITP (KHUSUS GURU — TIDAK ADA DI ADMIN)
         * ---------------------------------------------------------------------- */
        if ($action === 'list_tp') {
            $assertCanManageTp();
            $filterKmp = (int)($in['id_kmp'] ?? 0);
            $filterCp = (int)($in['id_cp'] ?? 0);
            $q = trim((string)($in['q'] ?? ''));

            if (empty($allowedKmpIds)) {
                $sendJson('success', 'OK', ['tp_list' => [], 'cp_options' => []]);
            }
            $inKmpSql = implode(',', array_map('intval', $allowedKmpIds));
            $inMapelSql = !empty($allowedMapelIds) ? implode(',', array_map('intval', $allowedMapelIds)) : '0';

            $cpOptWhere = ["(c.id_kelas_mata_pelajaran IN ($inKmpSql) OR kmp.id_mata_pelajaran IN ($inMapelSql))"];
            $cpOptParams = [];
            if ($filterKmp > 0 && isset($kmpById[$filterKmp])) {
                $cpOptWhere[] = "(c.id_kelas_mata_pelajaran = ? OR kmp.id_mata_pelajaran = ?)";
                $cpOptParams[] = $filterKmp;
                $cpOptParams[] = (int)$kmpById[$filterKmp]['id_mata_pelajaran'];
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

            $tpWhere = ["(c.id_kelas_mata_pelajaran IN ($inKmpSql) OR kmp.id_mata_pelajaran IN ($inMapelSql))"];
            $tpParams = [];
            if ($filterKmp > 0 && isset($kmpById[$filterKmp])) {
                $tpWhere[] = "(c.id_kelas_mata_pelajaran = ? OR kmp.id_mata_pelajaran = ?)";
                $tpParams[] = $filterKmp;
                $tpParams[] = (int)$kmpById[$filterKmp]['id_mata_pelajaran'];
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
            $assertCanManageTp();
            $id = (int)($in['id'] ?? 0);
            $idCp = (int)($in['id_cp'] ?? 0);
            $kodeTp = trim((string)($in['kode_tp'] ?? ''));
            $materiPokok = trim((string)($in['materi_pokok'] ?? ''));
            $tpText = trim((string)($in['tp'] ?? ''));
            $alokasiJam = max(1, (int)($in['alokasi_jam'] ?? 2));

            if ($idCp <= 0 || $tpText === '') {
                $sendJson('error', 'CP induk dan deskripsi Tujuan Pembelajaran (TP) wajib diisi.', null, 400);
            }
            $cpRow = $assertCpReadable($idCp);

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

            $initAtp = trim((string)($in['initial_atp'] ?? ''));
            if ($initAtp !== '' && $id === 0) {
                $pdo->prepare("INSERT INTO atp (id_tp, atp, urutan) VALUES (?, ?, 1)")->execute([$savedId, $initAtp]);
            }
            $initItp = trim((string)($in['initial_itp'] ?? ''));
            if ($initItp !== '' && $id === 0) {
                $pdo->prepare("INSERT INTO itp (id_tp, kode_itp, itp, urutan) VALUES (?, ?, ?, 1)")->execute([$savedId, $kodeTp . '.1', $initItp]);
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
            $assertCanManageTp();
            $id = (int)($in['id'] ?? 0);
            $s = $pdo->prepare("SELECT id, id_cp FROM tp WHERE id = ?");
            $s->execute([$id]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if (!$row) $sendJson('error', 'TP tidak ditemukan.', null, 404);
            $assertCpReadable($row['id_cp']);

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
            $assertCanManageTp();
            $id = (int)($in['id'] ?? 0);
            $idTp = (int)($in['id_tp'] ?? 0);
            $atpText = trim((string)($in['atp'] ?? ''));
            $urutan = max(1, (int)($in['urutan'] ?? 1));
            if ($idTp <= 0 || $atpText === '') $sendJson('error', 'TP dan uraian ATP wajib diisi.', null, 400);

            $chk = $pdo->prepare("SELECT id_cp FROM tp WHERE id = ?");
            $chk->execute([$idTp]);
            $idCpFound = (int)$chk->fetchColumn();
            if ($idCpFound <= 0) $sendJson('error', 'TP tidak ditemukan.', null, 404);
            $assertCpReadable($idCpFound);

            if ($id > 0) {
                $pdo->prepare("UPDATE atp SET atp = ?, urutan = ? WHERE id = ?")->execute([$atpText, $urutan, $id]);
                $sendJson('success', 'ATP berhasil diperbarui.', ['id' => $id]);
            } else {
                $pdo->prepare("INSERT INTO atp (id_tp, atp, urutan) VALUES (?, ?, ?)")->execute([$idTp, $atpText, $urutan]);
                $sendJson('success', 'ATP baru berhasil ditambahkan.', ['id' => (int)$pdo->lastInsertId()]);
            }
        }

        if ($action === 'delete_atp') {
            $assertCanManageTp();
            $id = (int)($in['id'] ?? 0);
            $chk = $pdo->prepare("SELECT t.id_cp FROM atp a JOIN tp t ON a.id_tp = t.id WHERE a.id = ?");
            $chk->execute([$id]);
            $idCpFound = (int)$chk->fetchColumn();
            if ($idCpFound <= 0) $sendJson('error', 'ATP tidak ditemukan.', null, 404);
            $assertCpReadable($idCpFound);
            $pdo->prepare("DELETE FROM atp WHERE id = ?")->execute([$id]);
            $sendJson('success', 'ATP berhasil dihapus.');
        }

        if ($action === 'save_itp') {
            $assertCanManageTp();
            $id = (int)($in['id'] ?? 0);
            $idTp = (int)($in['id_tp'] ?? 0);
            $kodeItp = trim((string)($in['kode_itp'] ?? ''));
            $itpText = trim((string)($in['itp'] ?? ''));
            $descKet = trim((string)($in['deskripsi_ketercapaian'] ?? ''));
            $urutan = max(1, (int)($in['urutan'] ?? 1));
            if ($idTp <= 0 || $itpText === '') $sendJson('error', 'TP dan uraian Indikator (ITP) wajib diisi.', null, 400);

            $chk = $pdo->prepare("SELECT id_cp FROM tp WHERE id = ?");
            $chk->execute([$idTp]);
            $idCpFound = (int)$chk->fetchColumn();
            if ($idCpFound <= 0) $sendJson('error', 'TP tidak ditemukan.', null, 404);
            $assertCpReadable($idCpFound);

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
            $assertCanManageTp();
            $id = (int)($in['id'] ?? 0);
            $chk = $pdo->prepare("SELECT t.id_cp FROM itp i JOIN tp t ON i.id_tp = t.id WHERE i.id = ?");
            $chk->execute([$id]);
            $idCpFound = (int)$chk->fetchColumn();
            if ($idCpFound <= 0) $sendJson('error', 'ITP tidak ditemukan.', null, 404);
            $assertCpReadable($idCpFound);
            $pdo->prepare("DELETE FROM itp WHERE id = ?")->execute([$id]);
            $sendJson('success', 'Indikator Ketercapaian (ITP) berhasil dihapus.');
        }

        /* ----------------------------------------------------------------------
         * 6. LIST MODUL AJAR PER KMP & KURIKULUM KMP & DETAIL MODUL
         * ---------------------------------------------------------------------- */
        if ($action === 'list_modul') {
            $idKmp = $assertKmpAccess($in['id_kmp'] ?? 0);
            $statusFilter = trim((string)($in['status'] ?? ''));
            $tahunFilter = trim((string)($in['tahun'] ?? ''));
            $q = trim((string)($in['q'] ?? ''));

            $kmpInfo = $kmpById[$idKmp] ?? null;
            if (!$kmpInfo) $sendJson('error', 'Data kelas & mata pelajaran tidak ditemukan.', null, 404);

            $curReady = $fetchCurriculumForKmp($idKmp);
            $cpCount = count($curReady['cp_list']);
            $tpCount = count($curReady['tp_list']);

            $maxPStmt = $pdo->prepare("SELECT COALESCE(MAX(pertemuan_ke), 0) FROM modul_ajar WHERE id_kelas_mata_pelajaran = ?");
            $maxPStmt->execute([$idKmp]);
            $nextPertemuan = (int)$maxPStmt->fetchColumn() + 1;

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
            foreach ($rows as $r) $modulList[] = normalizeModulRow($pdo, $r);

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
                'kmp_info'       => $kmpInfo,
                'siblings'       => $siblings,
                'cp_count'       => $cpCount,
                'tp_count'       => $tpCount,
                'next_pertemuan' => $nextPertemuan,
                'modul_list'     => $modulList,
            ]);
        }

        if ($action === 'get_kmp_curriculum') {
            $idKmp = $assertKmpAccess($in['id_kmp'] ?? 0);
            $cur = $fetchCurriculumForKmp($idKmp);
            $maxPStmt = $pdo->prepare("SELECT COALESCE(MAX(pertemuan_ke), 0) FROM modul_ajar WHERE id_kelas_mata_pelajaran = ?");
            $maxPStmt->execute([$idKmp]);
            $nextP = (int)$maxPStmt->fetchColumn() + 1;

            $sendJson('success', 'Kurikulum KMP dimuat.', [
                'cp_list'        => $cur['cp_list'],
                'tp_list'        => $cur['tp_list'],
                'mapel_fallback' => $cur['mapel_fallback'],
                'next_pertemuan' => $nextP,
                'fase'           => $kmpById[$idKmp]['fase'] ?? 'Fase E',
            ]);
        }

        if ($action === 'get_modul_detail') {
            $id = (int)($in['id'] ?? 0);
            $stmt = $pdo->prepare("
                SELECT ma.*, kmp.id_kelas, k.nama_kelas, mp.nama_mata_pelajaran, mp.kode_mapel, kmp.id_mata_pelajaran,
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
            $cur = $fetchCurriculumForKmp($idKmp);

            // Ambil Tugas & Proyek yang tertaut dengan Modul Ajar ini
            $tugasList = [];
            try {
                $tStmt = $pdo->prepare("SELECT id, judul_tugas, jenis_tugas, tanggal_deadline, status_tugas FROM tugas WHERE id_modul_ajar = ? ORDER BY tanggal_deadline ASC, id DESC");
                $tStmt->execute([$id]);
                $tugasList = $tStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $eT) {}

            $proyekList = [];
            try {
                $pStmt = $pdo->prepare("SELECT id, nama_proyek, tema_proyek, jenis_proyek, jadwal_mulai, jadwal_selesai, status_proyek FROM proyek WHERE id_modul_ajar = ? ORDER BY jadwal_mulai ASC, id DESC");
                $pStmt->execute([$id]);
                $proyekList = $pStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $eP) {}

            $sendJson('success', 'Detail modul ajar berhasil dimuat.', [
                'modul'       => $modul,
                'cp_list'     => $cur['cp_list'],
                'tp_list'     => $cur['tp_list'],
                'tugas_list'  => $tugasList,
                'proyek_list' => $proyekList,
            ]);
        }

        /* ----------------------------------------------------------------------
         * 7. CREATE, UPDATE, PUBLISH, KETERLAKSANAAN, FINALIZE, DUPLICATE, DELETE (GURU ONLY)
         * ---------------------------------------------------------------------- */
        if ($action === 'create_modul') {
            $assertCanManageModul();
            $idKmp = $assertKmpAccess($in['id_kelas_mata_pelajaran'] ?? 0);

            $cpIds = parseJsonIdArray($in['cp_ids'] ?? ($in['id_cp'] ?? []));
            $tpIds = parseJsonIdArray($in['tp_ids'] ?? ($in['id_tp'] ?? []));
            if (empty($cpIds)) $sendJson('error', 'Pilih Capaian Pembelajaran (CP) terlebih dahulu.', null, 400);
            if (empty($tpIds)) $sendJson('error', 'Pilih Tujuan Pembelajaran (TP) terlebih dahulu.', null, 400);

            $pertKe = max(1, (int)($in['pertemuan_ke'] ?? 1));
            $idJadwal = (int)($in['id_jadwal_pelajaran'] ?? 0) ?: null;
            $idProsemDetail = (int)($in['id_prosem_detail'] ?? 0) ?: null;

            $tahun = trim((string)($in['tahun_pelajaran'] ?? $defaultTahunAktif)) ?: $defaultTahunAktif;
            $semester = trim((string)($in['semester'] ?? $defaultSemAktif)) ?: $defaultSemAktif;
            $tanggal = trim((string)($in['tanggal'] ?? '')) ?: null;
            $jamMulai = trim((string)($in['jam_mulai'] ?? '')) ?: null;
            $jamSelesai = trim((string)($in['jam_selesai'] ?? '')) ?: null;
            $faseRow = trim((string)($in['fase'] ?? '')) ?: ($kmpById[$idKmp]['fase'] ?? 'Fase E');

            $alokasiPerJp = max(10, (int)($in['alokasi_per_jp'] ?? 45));
            $jumlahJp = max(1, (int)($in['jumlah_jp'] ?? 2));
            $totalMenit = $alokasiPerJp * $jumlahJp;
            $alokasiStr = $jumlahJp . ' x ' . $alokasiPerJp . ' Menit';

            $durPembukaan = max(0, (int)($in['durasi_pembukaan'] ?? round($totalMenit * 0.15)));
            $durInti = max(0, (int)($in['durasi_inti'] ?? round($totalMenit * 0.70)));
            $durPenutup = max(0, (int)($in['durasi_penutup'] ?? max(5, $totalMenit - $durPembukaan - $durInti)));

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

            // Multi-file upload + optional external links
            $lampiranArr = [];
            if (!empty($_FILES['file_lampiran'])) {
                $lampiranArr = handleModulMultiFileUpload($_FILES['file_lampiran']);
            }
            if (!empty($in['lampiran_links'])) {
                $decLinks = is_array($in['lampiran_links']) ? $in['lampiran_links'] : json_decode((string)$in['lampiran_links'], true);
                if (is_array($decLinks)) {
                    foreach ($decLinks as $lk) {
                        $lUrl = trim((string)($lk['url'] ?? ''));
                        $lName = trim((string)($lk['name'] ?? '')) ?: $lUrl;
                        if ($lUrl !== '' && preg_match('#^https?://#i', $lUrl)) {
                            $lampiranArr[] = [
                                'id'          => 'lnk_' . bin2hex(random_bytes(5)),
                                'file_name'   => $lName,
                                'file_path'   => $lUrl,
                                'url'         => $lUrl,
                                'file_size'   => 0,
                                'uploaded_at' => date('Y-m-d H:i:s'),
                            ];
                        }
                    }
                }
            }
            $primaryLampiranPath = !empty($lampiranArr) ? $lampiranArr[0]['file_path'] : null;
            $lampiranJson = !empty($lampiranArr) ? json_encode($lampiranArr, JSON_UNESCAPED_UNICODE) : null;

            $primaryCp = $cpIds[0];
            $primaryTp = $tpIds[0];
            $primaryModel = !empty($modelIds) ? $modelIds[0] : null;
            $primaryMetode = !empty($metodeIds) ? $metodeIds[0] : null;
            $primaryAtp = (int)($in['id_atp'] ?? 0) ?: null;
            $primaryItp = (int)($in['id_itp'] ?? 0) ?: null;

            if (!$primaryAtp) {
                $sA = $pdo->prepare("SELECT id FROM atp WHERE id_tp = ? ORDER BY urutan ASC, id ASC LIMIT 1");
                $sA->execute([$primaryTp]);
                $primaryAtp = $sA->fetchColumn() ?: null;
            }
            if (!$primaryItp) {
                $sI = $pdo->prepare("SELECT id FROM itp WHERE id_tp = ? ORDER BY urutan ASC, id ASC LIMIT 1");
                $sI->execute([$primaryTp]);
                $primaryItp = $sI->fetchColumn() ?: null;
            }

            $autoJudul = generateAutoModulTitle($pdo, $pertKe, $cpIds, $tpIds, $tpCustom);

            $pdo->beginTransaction();
            try {
                $insSql = "
                    INSERT INTO modul_ajar (
                        id_kelas_mata_pelajaran, id_jadwal_pelajaran, id_prosem_detail,
                        id_cp, id_tp, id_atp, id_itp,
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
                        ?, ?, ?,
                        ?, ?, ?, ?,
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
                $pdo->prepare($insSql)->execute([
                    $idKmp, $idJadwal, $idProsemDetail,
                    $primaryCp, $primaryTp, $primaryAtp, $primaryItp,
                    $primaryModel, $primaryMetode, $idMateri, $idUserLogged,
                    $pertKe, $autoJudul, $faseRow, $alokasiStr,
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
                $newId = (int)$pdo->lastInsertId();

                // Sinkron balik ke jadwal_pelajaran & prosem_detail jika berasal dari slot terjadwal
                if ($idJadwal) {
                    $pdo->prepare("UPDATE jadwal_pelajaran SET id_modul_ajar = ? WHERE id = ?")->execute([$newId, $idJadwal]);
                }
                if ($idProsemDetail) {
                    $pdo->prepare("UPDATE prosem_detail SET id_modul_ajar = COALESCE(id_modul_ajar, ?) WHERE id = ?")->execute([$newId, $idProsemDetail]);
                }
                $pdo->commit();
            } catch (Exception $eTx) {
                $pdo->rollBack();
                throw $eTx;
            }

            $sendJson('success', 'Modul Ajar berhasil disimpan.', [
                'primary_id'  => $newId,
                'primary_kmp' => $idKmp,
                'judul_modul' => $autoJudul,
            ]);
        }

        if ($action === 'update_modul') {
            $assertCanManageModul();
            $id = (int)($in['id'] ?? 0);
            $cur = $pdo->prepare("SELECT * FROM modul_ajar WHERE id = ? LIMIT 1");
            $cur->execute([$id]);
            $existing = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$existing) $sendJson('error', 'Modul ajar tidak ditemukan.', null, 404);
            $assertKmpAccess($existing['id_kelas_mata_pelajaran']);

            if (($existing['meeting_status'] ?? 'berjalan') === 'final') {
                $sendJson('error', 'Modul ajar sudah difinalisasi dan terkunci. Minta Admin untuk membuka kunci Final bila perlu direvisi.', null, 400);
            }
            if ($existing['status'] !== 'Draft') {
                $sendJson('error', 'Hanya modul berstatus Draft yang dapat diedit. Kembalikan ke Draft terlebih dahulu.', null, 400);
            }

            $pertKe = max(1, (int)($in['pertemuan_ke'] ?? $existing['pertemuan_ke']));
            $fase = trim((string)($in['fase'] ?? $existing['fase'])) ?: 'Fase E';
            $tahun = trim((string)($in['tahun_pelajaran'] ?? $existing['tahun_pelajaran'])) ?: $defaultTahunAktif;
            $semester = trim((string)($in['semester'] ?? $existing['semester'])) ?: $defaultSemAktif;
            $tanggal = trim((string)($in['tanggal'] ?? '')) ?: null;
            $jamMulai = trim((string)($in['jam_mulai'] ?? '')) ?: null;
            $jamSelesai = trim((string)($in['jam_selesai'] ?? '')) ?: null;

            $alokasiPerJp = max(10, (int)($in['alokasi_per_jp'] ?? 45));
            $jumlahJp = max(1, (int)($in['jumlah_jp'] ?? 2));
            $alokasiStr = $jumlahJp . ' x ' . $alokasiPerJp . ' Menit';
            $durPembukaan = max(0, (int)($in['durasi_pembukaan'] ?? 15));
            $durInti = max(0, (int)($in['durasi_inti'] ?? 60));
            $durPenutup = max(0, (int)($in['durasi_penutup'] ?? 15));

            $cpIds = parseJsonIdArray($in['cp_ids'] ?? ($in['id_cp'] ?? []));
            $tpIds = parseJsonIdArray($in['tp_ids'] ?? ($in['id_tp'] ?? []));
            $tpCustomVal = trim((string)($in['tp_custom'] ?? ''));
            $judul = generateAutoModulTitle($pdo, $pertKe, $cpIds, $tpIds, $tpCustomVal);

            $modelIds = parseJsonIdArray($in['model_ids'] ?? []);
            $metodeIds = parseJsonIdArray($in['metode_ids'] ?? []);
            $idMateri = (int)($in['id_materi'] ?? 0) ?: null;

            $primaryCp = !empty($cpIds) ? $cpIds[0] : null;
            $primaryTp = !empty($tpIds) ? $tpIds[0] : null;
            $primaryModel = !empty($modelIds) ? $modelIds[0] : null;
            $primaryMetode = !empty($metodeIds) ? $metodeIds[0] : null;
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

            $mediaStr = trim((string)($in['media_pembelajaran'] ?? ''));
            $sumberStr = trim((string)($in['sumber_belajar'] ?? ''));
            $refGuru = trim((string)($in['refleksi_guru'] ?? ''));
            $refSiswa = trim((string)($in['refleksi_siswa'] ?? ''));
            $refGabung = trim(($refGuru ? "Refleksi Guru:\n" . $refGuru : '') . ($refGuru && $refSiswa ? "\n\n" : '') . ($refSiswa ? "Refleksi Siswa:\n" . $refSiswa : ''));

            $updSql = "
                UPDATE modul_ajar SET
                    id_cp = ?, id_tp = ?, id_atp = ?, id_itp = ?, id_model_pembelajaran = ?, id_metode_pembelajaran = ?, id_materi = ?,
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
                $primaryCp, $primaryTp, $primaryAtp, $primaryItp, $primaryModel, $primaryMetode, $idMateri,
                $pertKe, $judul, $fase, $alokasiStr,
                $tahun, $semester, $tanggal, $jamMulai, $jamSelesai,
                $alokasiPerJp, $jumlahJp, $durPembukaan, $durInti, $durPenutup,
                trim((string)($in['profil_pelajar_pancasila'] ?? '')), $mediaStr, trim((string)($in['target_peserta_didik'] ?? '')) ?: 'Peserta Didik Reguler / Tipikal',
                trim((string)($in['pemahaman_bermakna'] ?? '')), trim((string)($in['pertanyaan_pemantik'] ?? '')), trim((string)($in['persiapan_pembelajaran'] ?? '')),
                trim((string)($in['kegiatan_pendahuluan'] ?? '')), trim((string)($in['kegiatan_inti'] ?? '')), trim((string)($in['kegiatan_penutup'] ?? '')),
                trim((string)($in['asesmen_diagnostik'] ?? '')), trim((string)($in['asesmen_formatif'] ?? '')), trim((string)($in['asesmen_sumatif'] ?? '')), trim((string)($in['pengayaan_remedial'] ?? '')),
                $refGabung, $refGuru, $refSiswa,
                trim((string)($in['lkpd'] ?? '')), trim((string)($in['bahan_bacaan'] ?? '')), trim((string)($in['glosarium'] ?? '')), $sumberStr,
                json_encode($cpIds), json_encode($tpIds), $tpCustomVal, trim((string)($in['itp_custom'] ?? '')), trim((string)($in['atp_custom'] ?? '')),
                json_encode($modelIds), json_encode($metodeIds), $mediaStr, $sumberStr,
                $id
            ]);

            $sendJson('success', 'Perubahan modul ajar berhasil disimpan.', ['id' => $id, 'judul_modul' => $judul]);
        }

        if ($action === 'toggle_publish') {
            $assertCanManageModul();
            $id = (int)($in['id'] ?? 0);
            $cur = $pdo->prepare("SELECT id, id_kelas_mata_pelajaran, status, meeting_status FROM modul_ajar WHERE id = ?");
            $cur->execute([$id]);
            $row = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$row) $sendJson('error', 'Modul ajar tidak ditemukan.', null, 404);
            $assertKmpAccess($row['id_kelas_mata_pelajaran']);

            if (($row['meeting_status'] ?? 'berjalan') === 'final') {
                $sendJson('error', 'Modul yang sudah difinalisasi terkunci. Hubungi Admin untuk membuka kunci Final.', null, 400);
            }
            $target = ($row['status'] === 'Published') ? 'Draft' : 'Published';
            $pdo->prepare("UPDATE modul_ajar SET status = ? WHERE id = ?")->execute([$target, $id]);
            $msg = ($target === 'Published')
                ? 'Modul ajar berhasil diterbitkan! Anda kini dapat mengisi status keterlaksanaan kegiatan.'
                : 'Modul ajar dikembalikan ke Draft dan dapat diedit kembali.';
            $sendJson('success', $msg, ['status' => $target]);
        }

        if ($action === 'save_keterlaksanaan') {
            $assertCanManageModul();
            $id = (int)($in['id'] ?? 0);
            $cur = $pdo->prepare("SELECT id, id_kelas_mata_pelajaran, status, meeting_status FROM modul_ajar WHERE id = ?");
            $cur->execute([$id]);
            $row = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$row) $sendJson('error', 'Modul ajar tidak ditemukan.', null, 404);
            $assertKmpAccess($row['id_kelas_mata_pelajaran']);

            if (($row['meeting_status'] ?? 'berjalan') === 'final') {
                $sendJson('error', 'Modul sudah difinalisasi dan terkunci.', null, 400);
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
            $assertCanManageModul();
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
            $sendJson('success', 'Pertemuan modul ajar berhasil difinalisasi dan dikunci. (Hanya Admin yang dapat membuka kunci Final.)', ['meeting_status' => 'final']);
        }

        /* ----------------------------------------------------------------------
         * 8. BUKA KUNCI FINAL (KHUSUS ADMIN — OTOMATIS TERBUKA KEMBALI DI GURU!)
         * ---------------------------------------------------------------------- */
        if ($action === 'unlock_final') {
            $assertAdminCp();
            $id = (int)($in['id'] ?? 0);
            $cur = $pdo->prepare("SELECT id, meeting_status FROM modul_ajar WHERE id = ?");
            $cur->execute([$id]);
            $row = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$row) $sendJson('error', 'Modul ajar tidak ditemukan.', null, 404);
            if (($row['meeting_status'] ?? 'berjalan') !== 'final') {
                $sendJson('error', 'Modul ajar ini tidak sedang dalam status Final (Terkunci).', null, 400);
            }
            // Buka kunci final & kembalikan ke Draft agar Guru bisa langsung menyunting / mengisi ulang
            $pdo->prepare("UPDATE modul_ajar SET meeting_status = 'berjalan', status = 'Draft' WHERE id = ?")->execute([$id]);
            $sendJson('success', 'Kunci Final berhasil dibuka dan modul ajar dikembalikan ke Guru (status Draft).', [
                'status'         => 'Draft',
                'meeting_status' => 'berjalan',
            ]);
        }

        if ($action === 'duplicate_modul') {
            $assertCanManageModul();
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
            $src['id_jadwal_pelajaran'] = null;
            $dupCpIds = parseJsonIdArray($src['cp_ids_json'] ?? null, $src['id_cp'] ?? null);
            $dupTpIds = parseJsonIdArray($src['tp_ids_json'] ?? null, $src['id_tp'] ?? null);
            $src['judul_modul'] = generateAutoModulTitle($pdo, $nextP, $dupCpIds, $dupTpIds, (string)($src['tp_custom'] ?? ''));
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
            $sendJson('success', "Modul berhasil diduplikasi sebagai Draft Pertemuan ke-{$nextP}.", ['id' => (int)$pdo->lastInsertId()]);
        }

        if ($action === 'delete_modul') {
            $assertCanManageModul();
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
            $pdo->prepare("UPDATE jadwal_pelajaran SET id_modul_ajar = NULL WHERE id_modul_ajar = ?")->execute([$id]);
            $pdo->prepare("UPDATE prosem_detail SET id_modul_ajar = NULL WHERE id_modul_ajar = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM modul_ajar WHERE id = ?")->execute([$id]);

            foreach ($files as $f) {
                $fp = BASE_PATH . '/' . ltrim((string)($f['file_path'] ?? ''), '/');
                if (strpos($fp, BASE_PATH . '/uploads/modul_ajar/') === 0 && is_file($fp)) {
                    @unlink($fp);
                }
            }
            $sendJson('success', 'Modul ajar berhasil dihapus.');
        }

        /* ----------------------------------------------------------------------
         * 9. MULTI-LAMPIRAN UPLOAD, LINK EKSTERNAL & DELETE (GURU ONLY)
         * ---------------------------------------------------------------------- */
        if ($action === 'upload_lampiran') {
            $assertCanManageModul();
            $id = (int)($in['id'] ?? 0);
            $cur = $pdo->prepare("SELECT id, id_kelas_mata_pelajaran, meeting_status, file_lampiran, lampiran_json FROM modul_ajar WHERE id = ?");
            $cur->execute([$id]);
            $row = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$row) $sendJson('error', 'Modul ajar tidak ditemukan.', null, 404);
            $assertKmpAccess($row['id_kelas_mata_pelajaran']);
            if (($row['meeting_status'] ?? 'berjalan') === 'final') {
                $sendJson('error', 'Modul yang sudah final tidak dapat menambah lampiran.', null, 400);
            }

            $uploadedList = handleModulMultiFileUpload($_FILES['file_lampiran'] ?? null);
            if (empty($uploadedList)) $sendJson('error', 'Pilih minimal 1 berkas lampiran.', null, 400);

            $list = parseLampiranList($row);
            foreach ($uploadedList as $u) $list[] = $u;
            $firstPath = $list[0]['file_path'] ?? null;

            $pdo->prepare("UPDATE modul_ajar SET file_lampiran = ?, lampiran_json = ? WHERE id = ?")
                ->execute([$firstPath, json_encode($list, JSON_UNESCAPED_UNICODE), $id]);
            $sendJson('success', count($uploadedList) . ' berkas lampiran berhasil diunggah dan QR Code telah dibuat.', ['lampiran' => $list]);
        }

        if ($action === 'add_lampiran_link') {
            $assertCanManageModul();
            $id = (int)($in['id'] ?? 0);
            $linkName = trim((string)($in['link_name'] ?? ''));
            $linkUrl  = trim((string)($in['link_url'] ?? ''));
            if ($linkUrl === '' || !preg_match('#^https?://#i', $linkUrl)) {
                $sendJson('error', 'URL tautan lampiran harus diawali http:// atau https://', null, 400);
            }
            if ($linkName === '') $linkName = $linkUrl;

            $cur = $pdo->prepare("SELECT id, id_kelas_mata_pelajaran, meeting_status, file_lampiran, lampiran_json FROM modul_ajar WHERE id = ?");
            $cur->execute([$id]);
            $row = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$row) $sendJson('error', 'Modul ajar tidak ditemukan.', null, 404);
            $assertKmpAccess($row['id_kelas_mata_pelajaran']);
            if (($row['meeting_status'] ?? 'berjalan') === 'final') {
                $sendJson('error', 'Modul yang sudah final tidak dapat menambah lampiran.', null, 400);
            }

            $list = parseLampiranList($row);
            $list[] = [
                'id'          => 'lnk_' . bin2hex(random_bytes(5)),
                'file_name'   => $linkName,
                'file_path'   => $linkUrl,
                'url'         => $linkUrl,
                'file_size'   => 0,
                'uploaded_at' => date('Y-m-d H:i:s'),
            ];
            $firstPath = $list[0]['file_path'] ?? null;
            $pdo->prepare("UPDATE modul_ajar SET file_lampiran = ?, lampiran_json = ? WHERE id = ?")
                ->execute([$firstPath, json_encode($list, JSON_UNESCAPED_UNICODE), $id]);
            $sendJson('success', 'Tautan lampiran berhasil ditambahkan beserta QR Code.', ['lampiran' => $list]);
        }

        if ($action === 'delete_lampiran') {
            $assertCanManageModul();
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
                    $fp = BASE_PATH . '/' . ltrim((string)($f['file_path'] ?? ''), '/');
                    if (strpos($fp, BASE_PATH . '/uploads/modul_ajar/') === 0 && is_file($fp)) @unlink($fp);
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
$pageTitle = $canManageCp ? 'Modul Ajar & Capaian Pembelajaran (CP)' : 'Modul Ajar (CP & TP)';
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
             ADMIN : 1. Capaian Pembelajaran (CP) | 2. Monitoring Modul Ajar
             GURU  : 1. CP (Pilih) | 2. TP & ATP/ITP | 3. Modul Ajar
             ================================================================== -->
        <div class="curriculum-main-tabs" id="maMainTabs" role="tablist">
            <?php if ($canManageCp): ?>
            <button type="button" class="curriculum-tab-btn is-active" data-tab="cp" role="tab" aria-selected="true">
                <span class="curriculum-tab-step">1</span>
                <iconify-icon icon="solar:diploma-verified-bold"></iconify-icon>
                <span class="curriculum-tab-label">Capaian Pembelajaran (CP)</span>
                <span class="curriculum-tab-counter" id="cntTabCp">0</span>
            </button>
            <button type="button" class="curriculum-tab-btn" data-tab="modul" role="tab" aria-selected="false">
                <span class="curriculum-tab-step">2</span>
                <iconify-icon icon="solar:book-bookmark-bold"></iconify-icon>
                <span class="curriculum-tab-label">Monitoring Modul Ajar</span>
                <span class="curriculum-tab-counter" id="cntTabModul">0</span>
            </button>
            <?php else: ?>
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
            <?php endif; ?>
        </div>

        <!-- ALUR KERJA RINGKAS INTERAKTIF -->
        <div class="ma-flow-ribbon">
            <?php if ($canManageCp): ?>
            <div class="ma-flow-steps">
                <button type="button" class="ma-flow-pill is-current" id="flowStep1" onclick="MA.switchTab('cp')">
                    <iconify-icon icon="solar:diploma-verified-bold"></iconify-icon>
                    <span>1. Tambah &amp; Kelola CP (Multi-Kelas)</span>
                </button>
                <iconify-icon icon="solar:arrow-right-linear" class="ma-flow-arrow"></iconify-icon>
                <button type="button" class="ma-flow-pill" id="flowStep3" onclick="MA.switchTab('modul')">
                    <iconify-icon icon="solar:eye-bold"></iconify-icon>
                    <span>2. Monitoring, Unduh PDF &amp; Buka Kunci Final</span>
                </button>
            </div>
            <button type="button" class="ilmuna-btn ilmuna-btn--gold ilmuna-btn--sm" onclick="MA.openCpModal()">
                <iconify-icon icon="solar:add-circle-bold"></iconify-icon>
                <span>Tambah CP (Multi-Kelas)</span>
            </button>
            <?php else: ?>
            <div class="ma-flow-steps">
                <button type="button" class="ma-flow-pill" id="flowStep1" onclick="MA.switchTab('cp')">
                    <iconify-icon icon="solar:check-circle-bold"></iconify-icon>
                    <span>1. Pilih CP (Admin)</span>
                </button>
                <iconify-icon icon="solar:arrow-right-linear" class="ma-flow-arrow"></iconify-icon>
                <button type="button" class="ma-flow-pill" id="flowStep2" onclick="MA.switchTab('tp')">
                    <iconify-icon icon="solar:target-bold"></iconify-icon>
                    <span>2. Susun TP, ATP &amp; ITP</span>
                </button>
                <iconify-icon icon="solar:arrow-right-linear" class="ma-flow-arrow"></iconify-icon>
                <button type="button" class="ma-flow-pill is-current" id="flowStep3" onclick="MA.switchTab('modul')">
                    <iconify-icon icon="solar:book-bookmark-bold"></iconify-icon>
                    <span>3. Susun Modul Ajar (PROTA/PROSEM/Jadwal)</span>
                </button>
            </div>
            <button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" onclick="MA.openCreateWizard()">
                <iconify-icon icon="solar:add-circle-bold"></iconify-icon>
                <span>Buat Modul Ajar Baru</span>
            </button>
            <?php endif; ?>
        </div>

        <!-- ==================================================================
             TAB MODUL AJAR (3 SPA SUB-VIEWS)
             ================================================================== -->
        <section class="ma-tab-pane<?= !$canManageCp ? ' is-active' : '' ?>" id="paneModul">

            <!-- SUB-VIEW A: SUBJECT & CLASS GRID -->
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
                            <?php if ($canManageModul): ?>
                            <button type="button" class="ilmuna-btn ilmuna-btn--gold" onclick="MA.openCreateWizard()">
                                <iconify-icon icon="solar:document-add-bold"></iconify-icon>
                                <span>Tambah Modul Ajar</span>
                            </button>
                            <?php else: ?>
                            <span class="ma-tag ma-tag--navy" style="padding:7px 12px;font-size:12px;">
                                <iconify-icon icon="solar:eye-bold"></iconify-icon>
                                <span>Mode Monitoring Admin (Unduh PDF &amp; Buka Kunci Final)</span>
                            </span>
                            <?php endif; ?>
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

            <!-- SUB-VIEW B: PERTEMUAN LIST PER KELAS-MAPEL -->
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
                        <?php if ($canManageModul): ?>
                        <button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" onclick="MA.openCreateWizard(MA.state.activeKmpId)">
                            <iconify-icon icon="solar:add-circle-bold"></iconify-icon>
                            <span>Tambah Pertemuan</span>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="meetReadinessBanners"></div>

                <div class="ilmuna-filter-box">
                    <div class="ilmuna-filter-row">
                        <div class="ilmuna-search-wrap">
                            <iconify-icon icon="solar:magnifer-bold"></iconify-icon>
                            <input type="text" class="ilmuna-search-input" id="meetSearchInput" placeholder="Cari judul modul atau nomor pertemuan...">
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
                                <span><?= $canManageCp ? 'Kelola CP' : 'Lihat CP' ?></span>
                            </button>
                            <?php if ($canManageTp): ?>
                            <button type="button" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" onclick="MA.jumpToTabWithKmp('tp', MA.state.activeKmpId)">
                                <iconify-icon icon="solar:target-bold"></iconify-icon>
                                <span>Kelola TP &amp; ATP</span>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div id="maMeetingsContainer"></div>
            </div>

            <!-- SUB-VIEW C: DETAIL & EDITOR MODUL AJAR -->
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
             TAB CAPAIAN PEMBELAJARAN (CP) & ELEMEN
             ================================================================== -->
        <section class="ma-tab-pane<?= $canManageCp ? ' is-active' : '' ?>" id="paneCp">
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
                        <?php if ($canManageCp): ?>
                        <button type="button" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" onclick="MA.openSyncCpModal()">
                            <iconify-icon icon="solar:copy-bold"></iconify-icon>
                            <span>Sinkron CP antar Kelas</span>
                        </button>
                        <button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" onclick="MA.openCpModal()">
                            <iconify-icon icon="solar:add-circle-bold"></iconify-icon>
                            <span>Tambah CP (Multi-Kelas)</span>
                        </button>
                        <?php else: ?>
                        <span class="ma-tag ma-tag--navy" style="padding:7px 12px;font-size:12px;">
                            <iconify-icon icon="solar:shield-check-bold"></iconify-icon>
                            <span>CP &amp; Elemen ditetapkan Admin (Guru hanya memilih)</span>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div id="maCpContainer"></div>
        </section>

        <!-- ==================================================================
             TAB TUJUAN PEMBELAJARAN (TP), ATP & ITP (KHUSUS GURU)
             ================================================================== -->
        <?php if ($canManageTp): ?>
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
                            <span>Buat TP &amp; ATP Baru</span>
                        </button>
                    </div>
                </div>
            </div>

            <div id="maTpContainer"></div>
        </section>
        <?php endif; ?>

    </div>
</main>

<!-- ==========================================================================
     MODAL 1: 6-STEP WIZARD BUAT MODUL AJAR (KHUSUS GURU — URUTAN CP -> TP -> ATP/ITP -> PERTEMUAN)
     ========================================================================== -->
<?php if ($canManageModul): ?>
<div class="ilm-modal-backdrop" id="modalCreateModulAjar" aria-hidden="true">
    <div class="ilm-modal-dialog ma-wizard-dialog">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon">
                    <iconify-icon icon="solar:book-bookmark-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title">Buat Modul Ajar Baru</h3>
                    <p class="ilm-modal-header__subtitle" id="wizSubtitle">Langkah 1 dari 6 &bull; Pilih CP, TP, ATP/ITP &amp; Pertemuan PROTA/PROSEM</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-close" onclick="closeIlmunaModal('modalCreateModulAjar')" aria-label="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>

        <div class="ma-stepper" id="wizStepper">
            <button type="button" class="ma-step-pill is-active" data-step="1" onclick="MA.wizGoStep(1)"><span class="step-num">1</span>CP, TP &amp; Jadwal</button>
            <button type="button" class="ma-step-pill" data-step="2" onclick="MA.wizGoStep(2)"><span class="step-num">2</span>Model &amp; Media</button>
            <button type="button" class="ma-step-pill" data-step="3" onclick="MA.wizGoStep(3)"><span class="step-num">3</span>Pemantik</button>
            <button type="button" class="ma-step-pill" data-step="4" onclick="MA.wizGoStep(4)"><span class="step-num">4</span>Kegiatan &amp; JP</button>
            <button type="button" class="ma-step-pill" data-step="5" onclick="MA.wizGoStep(5)"><span class="step-num">5</span>Asesmen &amp; P5</button>
            <button type="button" class="ma-step-pill" data-step="6" onclick="MA.wizGoStep(6)"><span class="step-num">6</span>Multi-Lampiran</button>
        </div>

        <form id="formCreateModul" onsubmit="return false;">
            <div class="ilm-modal-body" id="wizBodyScroll">

                <!-- STEP 1: MAPEL & KELAS -> CP (1 DROPDOWN) -> TP (1 DROPDOWN) -> ATP/ITP -> PERTEMUAN (PROTA/PROSEM/KALDIK/JADWAL) -->
                <div class="ma-wizard-step is-active" data-step="1">
                    <input type="hidden" id="wizJadwalId" value="0">
                    <input type="hidden" id="wizProsemDetailId" value="0">

                    <div class="ma-form-grid">
                        <div class="ma-field">
                            <label class="ma-label">Mata Pelajaran (Diampu) <span class="req">*</span></label>
                            <select class="ma-select" id="wizMapel" onchange="MA.onWizMapelChange()"></select>
                        </div>
                        <div class="ma-field">
                            <label class="ma-label">Kelas / Rombel <span class="req">*</span></label>
                            <select class="ma-select" id="wizKmp" onchange="MA.onWizKmpChange()"></select>
                        </div>

                        <!-- 1. PILIH CP (SINGLE DROPDOWN) -->
                        <div class="ma-field ma-field--full">
                            <div class="ma-label-row">
                                <label class="ma-label">1. Pilih Capaian Pembelajaran (CP) — Ditetapkan Admin <span class="req">*</span></label>
                                <span class="ma-tag ma-tag--navy">Pilih 1 CP</span>
                            </div>
                            <select class="ma-select" id="wizCpSelect" onchange="MA.onWizCpSelectChange()">
                                <option value="">-- Pilih Capaian Pembelajaran (CP) --</option>
                            </select>
                            <div id="wizCpPreviewBox" style="display:none;margin-top:4px;font-size:12px;color:#334155;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:9px;padding:8px 11px;line-height:1.5;"></div>
                        </div>

                        <!-- 2. PILIH TP (SINGLE DROPDOWN) -->
                        <div class="ma-field ma-field--full">
                            <div class="ma-label-row">
                                <label class="ma-label">2. Pilih Tujuan Pembelajaran (TP) <span class="req">*</span></label>
                                <button type="button" class="ma-expand-btn" onclick="MA.openQuickTpFromWizard()">
                                    <iconify-icon icon="solar:add-circle-bold"></iconify-icon> Buat TP &amp; ATP Baru
                                </button>
                            </div>
                            <select class="ma-select" id="wizTpSelect" onchange="MA.onWizTpSelectChange()">
                                <option value="">-- Pilih CP terlebih dahulu --</option>
                            </select>
                        </div>

                        <!-- INFO KUOTA PERTEMUAN CP & TP DARI PROTA/PROSEM -->
                        <div class="ma-field ma-field--full" id="wizQuotaWrap" style="display:none;">
                            <div class="ma-quota-grid" id="wizQuotaCards"></div>
                        </div>

                        <!-- 3. ATP & ITP (OTOMATIS DARI TP TERPILIH) -->
                        <div class="ma-field">
                            <div class="ma-label-row">
                                <label class="ma-label">3. Alur Tujuan Pembelajaran (ATP)</label>
                                <button type="button" class="ma-expand-btn" onclick="MA.openTextPopup('Alur Tujuan Pembelajaran (ATP)', 'wizAtpCustom')">
                                    <iconify-icon icon="solar:maximize-square-bold"></iconify-icon> Perbesar
                                </button>
                            </div>
                            <textarea class="ma-textarea" id="wizAtpCustom" placeholder="Otomatis terisi dari ATP milik TP terpilih..."></textarea>
                        </div>
                        <div class="ma-field">
                            <div class="ma-label-row">
                                <label class="ma-label">4. Indikator Ketercapaian (ITP / IKTP)</label>
                                <button type="button" class="ma-expand-btn" onclick="MA.openTextPopup('Indikator Ketercapaian (ITP)', 'wizItpCustom')">
                                    <iconify-icon icon="solar:maximize-square-bold"></iconify-icon> Perbesar
                                </button>
                            </div>
                            <textarea class="ma-textarea" id="wizItpCustom" placeholder="Otomatis terisi dari ITP milik TP terpilih..."></textarea>
                        </div>

                        <!-- 4. PILIH SLOT PERTEMUAN DARI PROTA / PROSEM / KALDIK / JADWAL -->
                        <div class="ma-field ma-field--full">
                            <div class="ma-label-row">
                                <label class="ma-label">5. Pilih Pertemuan (Dari PROTA, PROSEM, Kaldik &amp; Jadwal Mengajar) <span class="req">*</span></label>
                                <span class="ma-tag ma-tag--teal" id="wizSlotSourceBadge">Tersinkron Otomatis</span>
                            </div>
                            <select class="ma-select" id="wizSlotSelect" onchange="MA.onWizSlotSelectChange()">
                                <option value="">-- Pilih TP untuk memuat slot pertemuan --</option>
                            </select>
                        </div>

                        <!-- NAMA MODUL OTOMATIS -->
                        <div class="ma-field ma-field--full">
                            <div class="ma-label-row">
                                <label class="ma-label">Nama Modul Ajar (Otomatis)</label>
                                <span class="ma-tag ma-tag--teal"><iconify-icon icon="solar:magic-stick-3-bold"></iconify-icon> Modul Pertemuan (ke) - CP - TP</span>
                            </div>
                            <input type="text" class="ma-input" id="wizJudul" readonly placeholder="Otomatis terisi setelah memilih CP, TP & Pertemuan">
                        </div>

                        <!-- DETAIL JADWAL OTOMATIS DARI SLOT -->
                        <div class="ma-field">
                            <label class="ma-label">Pertemuan Ke- <span class="req">*</span></label>
                            <input type="number" class="ma-input" id="wizPertemuanKe" min="1" value="1" oninput="MA.updateWizAutoTitle()">
                        </div>
                        <div class="ma-field">
                            <label class="ma-label">Fase Kurikulum Merdeka</label>
                            <input type="text" class="ma-input" id="wizFase" value="Fase E" readonly>
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
                            <label class="ma-label">Tanggal Pelaksanaan (Kaldik / Jadwal)</label>
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
                        <textarea id="wizTpCustom" style="display:none;"></textarea>
                    </div>
                </div>

                <!-- STEP 2: MODEL, METODE, MEDIA, SUMBER BELAJAR & BANK MATERI -->
                <div class="ma-wizard-step" data-step="2">
                    <div class="ma-form-grid">
                        <div class="ma-field ma-field--full">
                            <label class="ma-label">Tautkan Bank Materi (Opsional)</label>
                            <select class="ma-select" id="wizMateriId">
                                <option value="">-- Tidak ditautkan --</option>
                            </select>
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
                            <input type="text" class="ma-input" id="wizSumber" placeholder="Contoh: Buku Paket PAI Kemendikbud, Tafsir Al-Misbah">
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
                            <textarea class="ma-textarea ma-textarea--lg" id="wizPemahaman" placeholder="Manfaat kontekstual dan makna mendalam yang akan diperoleh peserta didik..."></textarea>
                        </div>
                        <div class="ma-field ma-field--full">
                            <div class="ma-label-row">
                                <label class="ma-label">Pertanyaan Pemantik</label>
                                <button type="button" class="ma-expand-btn" onclick="MA.openTextPopup('Pertanyaan Pemantik', 'wizPemantik')">
                                    <iconify-icon icon="solar:maximize-square-bold"></iconify-icon> Perbesar
                                </button>
                            </div>
                            <textarea class="ma-textarea ma-textarea--lg" id="wizPemantik" placeholder="2-4 pertanyaan terbuka untuk menumbuhkan rasa ingin tahu dan nalar kritis siswa..."></textarea>
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
                            <label class="ma-label">Jumlah JP (Dari Jadwal/PROSEM)</label>
                            <input type="number" class="ma-input" id="wizJumlahJp" value="2" min="1" max="12" oninput="MA.recalcWizDuration(true)">
                        </div>
                        <div class="ma-field">
                            <label class="ma-label">Target Peserta Didik</label>
                            <input type="text" class="ma-input" id="wizTargetDidik" value="Peserta Didik Reguler / Tipikal">
                        </div>
                        <div class="ma-field">
                            <label class="ma-label">Distribusi Otomatis</label>
                            <button type="button" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" style="height:41px;justify-content:center;" onclick="MA.recalcWizDuration(true)">
                                <iconify-icon icon="solar:clock-circle-bold"></iconify-icon> 15/70/15%
                            </button>
                        </div>
                    </div>

                    <div class="ma-duration-box" id="wizDurationSummary" style="margin-bottom:14px;"></div>

                    <div class="ma-form-grid ma-form-grid--3" style="margin-bottom:14px;">
                        <div class="ma-field"><label class="ma-label">Durasi Pembukaan (Menit)</label><input type="number" class="ma-input" id="wizDurPembukaan" value="14" min="0" oninput="MA.recalcWizDuration(false)"></div>
                        <div class="ma-field"><label class="ma-label">Durasi Kegiatan Inti (Menit)</label><input type="number" class="ma-input" id="wizDurInti" value="63" min="0" oninput="MA.recalcWizDuration(false)"></div>
                        <div class="ma-field"><label class="ma-label">Durasi Penutup (Menit)</label><input type="number" class="ma-input" id="wizDurPenutup" value="13" min="0" oninput="MA.recalcWizDuration(false)"></div>
                    </div>

                    <div class="ma-form-grid">
                        <div class="ma-field ma-field--full">
                            <label class="ma-label">Persiapan Pembelajaran</label>
                            <textarea class="ma-textarea" id="wizPersiapan" placeholder="Menyiapkan ruangan, proyektor, LKPD, daftar presensi..."></textarea>
                        </div>
                        <div class="ma-field ma-field--full">
                            <div class="ma-label-row">
                                <label class="ma-label">Kegiatan Pendahuluan / Pembukaan</label>
                                <button type="button" class="ma-expand-btn" onclick="MA.openTextPopup('Kegiatan Pendahuluan', 'wizKegPendahuluan')"><iconify-icon icon="solar:maximize-square-bold"></iconify-icon> Perbesar</button>
                            </div>
                            <textarea class="ma-textarea" id="wizKegPendahuluan"></textarea>
                        </div>
                        <div class="ma-field ma-field--full">
                            <div class="ma-label-row">
                                <label class="ma-label">Kegiatan Inti (Sintaks Model Pembelajaran)</label>
                                <div style="display:flex;gap:6px;">
                                    <button type="button" class="ma-expand-btn" onclick="MA.applySintaksToWizard()"><iconify-icon icon="solar:magic-stick-3-bold"></iconify-icon> Muat Sintaks</button>
                                    <button type="button" class="ma-expand-btn" onclick="MA.openTextPopup('Kegiatan Inti', 'wizKegInti')"><iconify-icon icon="solar:maximize-square-bold"></iconify-icon> Perbesar</button>
                                </div>
                            </div>
                            <textarea class="ma-textarea ma-textarea--lg" id="wizKegInti"></textarea>
                        </div>
                        <div class="ma-field ma-field--full">
                            <div class="ma-label-row">
                                <label class="ma-label">Kegiatan Penutup</label>
                                <button type="button" class="ma-expand-btn" onclick="MA.openTextPopup('Kegiatan Penutup', 'wizKegPenutup')"><iconify-icon icon="solar:maximize-square-bold"></iconify-icon> Perbesar</button>
                            </div>
                            <textarea class="ma-textarea" id="wizKegPenutup"></textarea>
                        </div>
                    </div>
                </div>

                <!-- STEP 5: ASESMEN, PROFIL PELAJAR PANCASILA & REFLEKSI -->
                <div class="ma-wizard-step" data-step="5">
                    <div class="ma-form-grid">
                        <div class="ma-field ma-field--full">
                            <label class="ma-label">Dimensi Profil Pelajar Pancasila &amp; Rahmatan Lil Alamin</label>
                            <div class="ma-chip-group" id="wizP5Chips" style="margin-bottom:8px;"></div>
                            <textarea class="ma-textarea" id="wizP5"></textarea>
                        </div>
                        <div class="ma-field"><label class="ma-label">Asesmen Diagnostik</label><textarea class="ma-textarea" id="wizAsDiag"></textarea></div>
                        <div class="ma-field"><label class="ma-label">Asesmen Formatif</label><textarea class="ma-textarea" id="wizAsForm"></textarea></div>
                        <div class="ma-field"><label class="ma-label">Asesmen Sumatif</label><textarea class="ma-textarea" id="wizAsSum"></textarea></div>
                        <div class="ma-field"><label class="ma-label">Pengayaan &amp; Remedial</label><textarea class="ma-textarea" id="wizPengRem"></textarea></div>
                        <div class="ma-field"><label class="ma-label">Refleksi Guru</label><textarea class="ma-textarea" id="wizRefGuru"></textarea></div>
                        <div class="ma-field"><label class="ma-label">Refleksi Peserta Didik</label><textarea class="ma-textarea" id="wizRefSiswa"></textarea></div>
                    </div>
                </div>

                <!-- STEP 6: GLOSARIUM, LKPD & MULTI-LAMPIRAN (OTOMATIS QR CODE DI PDF) -->
                <div class="ma-wizard-step" data-step="6">
                    <div class="ma-form-grid">
                        <div class="ma-field"><label class="ma-label">Panduan LKPD (Opsional)</label><textarea class="ma-textarea" id="wizLkpd"></textarea></div>
                        <div class="ma-field"><label class="ma-label">Bahan Bacaan (Opsional)</label><textarea class="ma-textarea" id="wizBahanBacaan"></textarea></div>
                        <div class="ma-field ma-field--full"><label class="ma-label">Glosarium</label><textarea class="ma-textarea" id="wizGlosarium"></textarea></div>

                        <div class="ma-field ma-field--full">
                            <div class="ma-label-row">
                                <label class="ma-label">Upload Berkas Lampiran (Bisa Pilih Lebih Dari 1 File — Otomatis Menjadi QR Code di PDF)</label>
                                <span class="ma-tag ma-tag--teal"><iconify-icon icon="solar:qr-code-bold"></iconify-icon> Auto QR Code</span>
                            </div>
                            <input type="file" class="ma-input" id="wizFileLampiran" multiple accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.webp,.zip" onchange="MA.previewWizFiles()">
                            <div id="wizSelectedFilesInfo" style="font-size:12px;color:#0D7A87;font-weight:600;margin-top:4px;"></div>
                        </div>

                        <div class="ma-field ma-field--full">
                            <div class="ma-label-row">
                                <label class="ma-label">Atau Tambahkan Tautan Lampiran Eksternal (Google Drive / Video / LKPD Online)</label>
                                <button type="button" class="ma-expand-btn" onclick="MA.addWizLinkRow()">+ Tambah Tautan</button>
                            </div>
                            <div id="wizLinksList" style="display:flex;flex-direction:column;gap:8px;"></div>
                        </div>

                        <div class="ma-field ma-field--full">
                            <label class="ma-label">Status Awal Penyimpanan</label>
                            <select class="ma-select" id="wizStatusInit">
                                <option value="Draft">Draft (Masih Bisa Diedit)</option>
                                <option value="Published">Langsung Terbitkan (Published)</option>
                            </select>
                        </div>
                    </div>

                    <div class="ma-alert-banner ma-alert-banner--teal" id="wizFinalSummary" style="margin-top:14px;margin-bottom:0;"></div>
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
<?php endif; ?>

<!-- ==========================================================================
     MODAL 2 & 3: TAMBAH / EDIT / SINKRON CP (KHUSUS ADMIN — MULTIPLE CHOICE KELAS!)
     ========================================================================== -->
<?php if ($canManageCp): ?>
<div class="ilm-modal-backdrop" id="modalCpForm" aria-hidden="true">
    <div class="ilm-modal-dialog" style="max-width:760px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon">
                    <iconify-icon icon="solar:diploma-verified-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title" id="cpModalTitle">Tambah Capaian Pembelajaran (CP)</h3>
                    <p class="ilm-modal-header__subtitle">Centang banyak kelas/rombel sekaligus agar CP otomatis tersimpan ke seluruh kelas terpilih</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-close" onclick="closeIlmunaModal('modalCpForm')" aria-label="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form id="formCp" onsubmit="MA.submitCp(event)">
            <div class="ilm-modal-body">
                <input type="hidden" id="cpFormId" value="0">
                <input type="hidden" id="cpFormSingleKmp" value="0">
                <input type="hidden" id="cpFormEditElemenId" value="0">
                <div class="ma-form-grid">

                    <!-- Mode Tambah: Filter Mapel + Multiple Choice Kelas -->
                    <div class="ma-field ma-field--full" id="cpMultiClassSection">
                        <div class="ma-label-row" style="margin-bottom:4px;">
                            <label class="ma-label">Filter Mata Pelajaran &amp; Centang Kelas / Rombel (Multiple Choice) <span class="req">*</span></label>
                            <button type="button" class="ma-expand-btn" onclick="MA.toggleAllCpClasses()">
                                <iconify-icon icon="solar:checklist-bold"></iconify-icon> Pilih Semua Kelas Tampil
                            </button>
                        </div>
                        <select class="ma-select" id="cpFormMapelFilter" onchange="MA.renderCpMultiClassGrid()" style="margin-bottom:8px;">
                            <option value="">Semua Mata Pelajaran</option>
                        </select>
                        <div class="ma-check-grid" id="cpFormKmpGrid" style="max-height:200px;overflow-y:auto;padding:2px;"></div>
                    </div>

                    <!-- Mode Edit: Info Kelas -->
                    <div class="ma-field ma-field--full" id="cpEditClassBadge" style="display:none;">
                        <label class="ma-label">Kelas &amp; Mata Pelajaran</label>
                        <input type="text" class="ma-input" id="cpEditClassLabel" readonly>
                    </div>

                    <div class="ma-field">
                        <label class="ma-label">Pilih Nama Elemen yang Sudah Ada</label>
                        <select class="ma-select" id="cpFormElemenNama">
                            <option value="">-- Atau ketik Elemen baru di samping --</option>
                        </select>
                    </div>
                    <div class="ma-field">
                        <label class="ma-label">Atau Nama Elemen Baru</label>
                        <input type="text" class="ma-input" id="cpFormNewElemen" placeholder="Contoh: Al-Qur'an Hadis / Akidah / Fikih">
                    </div>
                    <div class="ma-field ma-field--full">
                        <label class="ma-label">Fase Kurikulum Merdeka</label>
                        <select class="ma-select" id="cpFormFase">
                            <option value="AUTO">Otomatis Sesuai Jenjang Masing-masing Kelas (Disarankan)</option>
                            <option value="Fase E">Fase E (Kelas X)</option>
                            <option value="Fase F">Fase F (Kelas XI / XII)</option>
                            <option value="Fase D">Fase D (Kelas VII - IX)</option>
                            <option value="Fase C">Fase C (Kelas V - VI)</option>
                        </select>
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

<div class="ilm-modal-backdrop" id="modalSyncCp" aria-hidden="true">
    <div class="ilm-modal-dialog" style="max-width:640px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon">
                    <iconify-icon icon="solar:copy-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title">Sinkronisasi CP antar Rombel</h3>
                    <p class="ilm-modal-header__subtitle">Salin Elemen &amp; CP dari 1 kelas sumber ke banyak kelas tujuan sekaligus</p>
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
                        <label class="ma-label">Centang Kelas Tujuan (Bisa Lebih Dari 1) <span class="req">*</span></label>
                        <div class="ma-check-grid" id="syncTargetKmpGrid" style="max-height:220px;overflow-y:auto;"></div>
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
<?php endif; ?>

<!-- ==========================================================================
     MODAL 4 & 5: TP, ATP & ITP (KHUSUS GURU — TIDAK ADA DI PANEL ADMIN)
     ========================================================================== -->
<?php if ($canManageTp): ?>
<div class="ilm-modal-backdrop" id="modalTpForm" aria-hidden="true">
    <div class="ilm-modal-dialog" style="max-width:700px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon">
                    <iconify-icon icon="solar:target-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title" id="tpModalTitle">Buat Tujuan Pembelajaran (TP)</h3>
                    <p class="ilm-modal-header__subtitle">Diturunkan dari CP yang ditetapkan Admin Kurikulum</p>
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
                        <label class="ma-label">Kode TP (Otomatis jika kosong)</label>
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
                        <textarea class="ma-textarea ma-textarea--lg" id="tpFormText" required placeholder="Peserta didik mampu..."></textarea>
                    </div>
                    <div class="ma-field" id="tpInitAtpWrap">
                        <label class="ma-label">Alur Tujuan Pembelajaran Awal (ATP - Opsional)</label>
                        <textarea class="ma-textarea" id="tpFormInitAtp"></textarea>
                    </div>
                    <div class="ma-field" id="tpInitItpWrap">
                        <label class="ma-label">Indikator Ketercapaian Awal (ITP - Opsional)</label>
                        <textarea class="ma-textarea" id="tpFormInitItp"></textarea>
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
                        <textarea class="ma-textarea" id="subTpDesc"></textarea>
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
<?php endif; ?>

<!-- ==========================================================================
     MODAL 6: TEXT POPUP EDITOR LAYAR LEBAR
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
        isAdmin: <?= $isAdmin ? 'true' : 'false' ?>,
        canManageCp: <?= $canManageCp ? 'true' : 'false' ?>,
        canManageTp: <?= $canManageTp ? 'true' : 'false' ?>,
        canManageModul: <?= $canManageModul ? 'true' : 'false' ?>,
        canUnlockFinal: <?= $canUnlockFinal ? 'true' : 'false' ?>
    };
</script>
<script src="<?= BASE_URL ?>/assets/js/guru-modul-ajar.js?v=<?= $maJsVer ?>" defer></script>

<?php include BASE_PATH . '/includes/footer.php'; ?>
