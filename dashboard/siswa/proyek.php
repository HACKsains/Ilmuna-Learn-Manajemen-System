<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — PORTAL PROYEK & P5 (PjBL) SISWA
 * ==============================================================================
 * Fitur Portal Siswa:
 *   1. Daftar Proyek & P5 kelas siswa (terintegrasi Modul Ajar, TP & jadwal proyek)
 *   2. Pengumpulan Hasil Karya Proyek (Laporan, Dokumentasi, Link Produk & Video)
 *      — Mendukung Proyek Individu maupun Proyek Kelompok (otomatis terhubung ke tim)
 *      — Mendukung pembaruan / revisi karya serta tampilan Nilai & Predikat P5 (SB/BSH/MB/BB)
 *   3. Info Kelompok & Peran Anggota Tim siswa
 *   4. Pencatatan Transparansi Biaya Proyek (dengan bukti transaksi & status verifikasi guru)
 *   5. 100% Lazy Load SPA (Zero Page Reload), Strict Class Scope, PHP 7.4 Compatible
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

requireRole(['siswa', 'admin', 'guru']);

$pdo = getDBConnection();
$pdo->exec("SET time_zone = '+07:00'");
ilmuna_ensure_academic_sync_schema($pdo);

$currentUser = getCurrentUser();
$sessionRole = $_SESSION['active_role'] ?? '';
if ($sessionRole === 'siswa' && hasRole('siswa')) {
    $activeRole = 'siswa';
} elseif ($sessionRole === 'guru' && hasRole('guru')) {
    $activeRole = 'guru';
} elseif ($sessionRole === 'admin' && hasRole('admin')) {
    $activeRole = 'admin';
} else {
    $activeRole = hasRole('siswa') ? 'siswa' : (hasRole('guru') ? 'guru' : 'admin');
}
$GLOBALS['activeRole'] = $activeRole;

// ==============================================================================
// 1. RESOLUSI DATA SISWA & KELAS TERDAFTAR (STRICT CLASS SCOPE)
// ==============================================================================
$currentSiswa = null;
$overrideSiswaId = (int)($_GET['siswa_id'] ?? $_POST['siswa_id'] ?? 0);

if (($activeRole === 'admin' || $activeRole === 'guru') && $overrideSiswaId > 0) {
    $stmtS = $pdo->prepare("
        SELECT s.*, k.nama_kelas, k.kode_kelas, k.tahun_pelajaran
        FROM siswa s
        LEFT JOIN kelas k ON s.id_kelas = k.id
        WHERE s.id = ? LIMIT 1
    ");
    $stmtS->execute([$overrideSiswaId]);
    $currentSiswa = $stmtS->fetch(PDO::FETCH_ASSOC);
}

if (!$currentSiswa) {
    $stmtS = $pdo->prepare("
        SELECT s.*, k.nama_kelas, k.kode_kelas, k.tahun_pelajaran
        FROM siswa s
        LEFT JOIN kelas k ON s.id_kelas = k.id
        WHERE s.nis = ? OR s.nisn = ? OR s.gmail = ? OR s.gmail = ?
        LIMIT 1
    ");
    $stmtS->execute([
        $currentUser['username'] ?? '',
        $currentUser['nisn_nip'] ?? '',
        $currentUser['email'] ?? '',
        $currentUser['email_google'] ?? ''
    ]);
    $currentSiswa = $stmtS->fetch(PDO::FETCH_ASSOC);
}

if (!$currentSiswa && ($activeRole === 'admin' || $activeRole === 'guru')) {
    $currentSiswa = $pdo->query("
        SELECT s.*, k.nama_kelas, k.kode_kelas, k.tahun_pelajaran
        FROM siswa s
        LEFT JOIN kelas k ON s.id_kelas = k.id
        ORDER BY s.id ASC LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
}

$idSiswa   = (int)($currentSiswa['id'] ?? 0);
$idKelas   = (int)($currentSiswa['id_kelas'] ?? 0);
$namaKelas = $currentSiswa['nama_kelas'] ?? 'Kelas Belum Terdaftar';
$namaSiswa = $currentSiswa['nama_lengkap'] ?? ($currentUser['full_name'] ?: 'Siswa Ilmuna');
$nisSiswa  = $currentSiswa['nis'] ?? ($currentSiswa['nisn'] ?? '-');

function spryUploadFile($fileInfo, $subDir, $allowedExts, $maxMb = 15) {
    if (empty($fileInfo) || !isset($fileInfo['error']) || $fileInfo['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($fileInfo['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Gagal mengunggah berkas (kode error: ' . (int)$fileInfo['error'] . ').');
    }
    if ((int)$fileInfo['size'] > $maxMb * 1024 * 1024) {
        throw new Exception('Ukuran berkas melebihi batas maksimal ' . $maxMb . ' MB.');
    }
    $orig = basename((string)$fileInfo['name']);
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExts, true)) {
        throw new Exception('Format berkas .' . $ext . ' tidak diizinkan.');
    }
    $dir = BASE_PATH . '/uploads/' . $subDir;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $base = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($orig, PATHINFO_FILENAME));
    $base = substr($base, 0, 48) ?: 'karya';
    $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '_' . $base . '.' . $ext;
    $dest = $dir . '/' . $filename;
    if (!move_uploaded_file($fileInfo['tmp_name'], $dest)) {
        throw new Exception('Gagal menyimpan berkas ke server.');
    }
    return 'uploads/' . $subDir . '/' . $filename;
}

// ==============================================================================
// 2. AJAX / JSON API HANDLER (?api=1)
// ==============================================================================
if (isset($_GET['api']) && $_GET['api'] == '1') {
    header('Content-Type: application/json; charset=utf-8');
    $action = trim((string)($_POST['action'] ?? $_GET['action'] ?? ''));

    try {
        if ($idSiswa <= 0 || $idKelas <= 0) {
            throw new Exception('Akun Anda belum terhubung dengan data siswa atau kelas aktif.');
        }

        $assertClassProyek = function ($idProyek) use ($pdo, $idKelas) {
            $stmt = $pdo->prepare("
                SELECT p.*, kmp.id_kelas, k.nama_kelas, mp.nama_mata_pelajaran
                FROM proyek p
                JOIN kelas_mata_pelajaran kmp ON p.id_kelas_mata_pelajaran = kmp.id
                JOIN kelas k ON kmp.id_kelas = k.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                WHERE p.id = ? AND kmp.id_kelas = ?
                LIMIT 1
            ");
            $stmt->execute([(int)$idProyek, $idKelas]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new Exception('Proyek tidak ditemukan atau bukan milik kelas Anda.');
            }
            return $row;
        };

        $findMyTeamForProyek = function ($idProyek) use ($pdo, $idSiswa) {
            $stmt = $pdo->prepare("
                SELECT pk.*, pka.peran_anggota, sK.nama_lengkap AS nama_ketua
                FROM proyek_kelompok pk
                LEFT JOIN proyek_kelompok_anggota pka ON pka.id_proyek_kelompok = pk.id AND pka.id_siswa = ?
                LEFT JOIN siswa sK ON pk.id_ketua_kelompok = sK.id
                WHERE pk.id_proyek = ? AND (pka.id_siswa = ? OR pk.id_ketua_kelompok = ?)
                LIMIT 1
            ");
            $stmt->execute([$idSiswa, (int)$idProyek, $idSiswa, $idSiswa]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        };

        /* 1. LIST PROYEK SISWA + KELOMPOK + STATUS PENGUMPULAN */
        if ($action === 'list_my_proyek') {
            $filterKmp = (int)($_POST['id_kmp'] ?? 0);
            $filterSubStatus = trim((string)($_POST['sub_status'] ?? ''));
            $search = trim((string)($_POST['search'] ?? ''));

            // Daftar Mapel di kelas siswa untuk filter
            $mStmt = $pdo->prepare("
                SELECT kmp.id AS id_kmp, mp.nama_mata_pelajaran
                FROM kelas_mata_pelajaran kmp
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                WHERE kmp.id_kelas = ?
                ORDER BY mp.nama_mata_pelajaran ASC
            ");
            $mStmt->execute([$idKelas]);
            $mapelList = $mStmt->fetchAll(PDO::FETCH_ASSOC);

            $where = ["kmp.id_kelas = ?", "p.status_proyek != 'Diarsipkan'"];
            $params = [$idKelas];
            if ($filterKmp > 0) {
                $where[] = "p.id_kelas_mata_pelajaran = ?";
                $params[] = $filterKmp;
            }
            if ($search !== '') {
                $where[] = "(p.nama_proyek LIKE ? OR p.tema_proyek LIKE ? OR mp.nama_mata_pelajaran LIKE ? OR ma.judul_modul LIKE ?)";
                $q = '%' . $search . '%';
                array_push($params, $q, $q, $q, $q);
            }

            $sql = "
                SELECT p.*,
                       kmp.id_kelas,
                       k.nama_kelas,
                       mp.nama_mata_pelajaran,
                       COALESCE(g.nama_guru, u.full_name, 'Guru Pembimbing') AS nama_guru,
                       ma.judul_modul,
                       ma.pertemuan_ke AS modul_pertemuan_ke,
                       tp.kode_tp,
                       tp.tp AS uraian_tp
                FROM proyek p
                JOIN kelas_mata_pelajaran kmp ON p.id_kelas_mata_pelajaran = kmp.id
                JOIN kelas k ON kmp.id_kelas = k.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
                LEFT JOIN guru g ON gmp.id_guru = g.id
                LEFT JOIN users u ON p.id_guru = u.id
                LEFT JOIN modul_ajar ma ON p.id_modul_ajar = ma.id
                LEFT JOIN tp ON p.id_tp = tp.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY p.jadwal_selesai ASC, p.id DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stats = [
                'total'     => 0,
                'belum'     => 0,
                'terkumpul' => 0,
                'dinilai'   => 0,
            ];
            $items = [];

            foreach ($rows as $r) {
                $pid = (int)$r['id'];
                $myTeam = $findMyTeamForProyek($pid);
                $teamMembers = [];
                if ($myTeam) {
                    $aStmt = $pdo->prepare("
                        SELECT pka.id_siswa, pka.peran_anggota, s.nama_lengkap AS nama_siswa, s.nis
                        FROM proyek_kelompok_anggota pka
                        JOIN siswa s ON pka.id_siswa = s.id
                        WHERE pka.id_proyek_kelompok = ?
                        ORDER BY pka.id ASC
                    ");
                    $aStmt->execute([(int)$myTeam['id']]);
                    $teamMembers = $aStmt->fetchAll(PDO::FETCH_ASSOC);
                    $myTeam['anggota'] = $teamMembers;
                }

                // Cari pengumpulan karya siswa (atau milik kelompoknya)
                if ($myTeam) {
                    $subStmt = $pdo->prepare("
                        SELECT pp.*, s.nama_lengkap AS nama_pengumpul
                        FROM pengumpulan_proyek pp
                        LEFT JOIN siswa s ON pp.id_siswa = s.id
                        WHERE pp.id_proyek = ? AND (pp.id_proyek_kelompok = ? OR pp.id_siswa = ?)
                        ORDER BY pp.updated_at DESC, pp.id DESC
                        LIMIT 1
                    ");
                    $subStmt->execute([$pid, (int)$myTeam['id'], $idSiswa]);
                } else {
                    $subStmt = $pdo->prepare("
                        SELECT pp.*, s.nama_lengkap AS nama_pengumpul
                        FROM pengumpulan_proyek pp
                        LEFT JOIN siswa s ON pp.id_siswa = s.id
                        WHERE pp.id_proyek = ? AND pp.id_siswa = ?
                        ORDER BY pp.updated_at DESC, pp.id DESC
                        LIMIT 1
                    ");
                    $subStmt->execute([$pid, $idSiswa]);
                }
                $submission = $subStmt->fetch(PDO::FETCH_ASSOC) ?: null;

                // Ambil rincian nilai spesifik siswa dari `nilai_proyek` (setara `nilai_tugas`: Kelompok + Individu / 2)
                $nStmt = $pdo->prepare("
                    SELECT np.*, u.full_name AS nama_penilai
                    FROM nilai_proyek np
                    LEFT JOIN users u ON np.id_guru = u.id
                    WHERE np.id_proyek = ? AND np.id_siswa = ?
                    LIMIT 1
                ");
                $nStmt->execute([$pid, $idSiswa]);
                $myNilai = $nStmt->fetch(PDO::FETCH_ASSOC) ?: null;

                $isMeKetua = ($myTeam && ((int)($myTeam['id_ketua_kelompok'] ?? 0) === $idSiswa || empty($myTeam['id_ketua_kelompok'])));
                if ($myTeam) {
                    $myTeam['is_me_ketua'] = $isMeKetua;
                }

                $r['my_team'] = $myTeam;
                $r['is_me_ketua'] = $isMeKetua;
                $r['submission'] = $submission;
                $r['nilai_detail'] = $myNilai;

                $isGraded = ($myNilai !== null) || ($submission && $submission['nilai_proyek'] !== null && $submission['nilai_proyek'] !== '');
                $isRevisi = ($submission && $submission['status_pengumpulan'] === 'Revisi') || ($myNilai && $myNilai['status_penilaian'] === 'Perlu Revisi');

                $stats['total']++;
                if (!$submission) {
                    $stats['belum']++;
                } else {
                    $stats['terkumpul']++;
                    if ($isGraded) {
                        $stats['dinilai']++;
                    }
                }

                // Filter status pengumpulan
                if ($filterSubStatus === 'belum' && $submission) continue;
                if ($filterSubStatus === 'terkumpul' && !$submission) continue;
                if ($filterSubStatus === 'revisi' && !$isRevisi) continue;
                if ($filterSubStatus === 'dinilai' && !$isGraded) continue;

                $items[] = $r;
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'items'      => $items,
                    'stats'      => $stats,
                    'mapel_list' => $mapelList,
                ],
            ]);
            exit;
        }

        /* 2. KUMPULKAN / PERBARUI KARYA PROYEK SISWA (100% IDENTIK SISTEM PENGUMPULAN TUGAS.PHP) */
        if ($action === 'submit_karya') {
            $p = $assertClassProyek($_POST['id_proyek'] ?? 0);
            $pid = (int)$p['id'];
            $judul = trim((string)($_POST['judul_laporan_produk'] ?? ''));
            $desk = trim((string)($_POST['deskripsi_produk'] ?? ''));
            $linkProduk = trim((string)($_POST['link_produk'] ?? '')) ?: null;
            $linkVideo = trim((string)($_POST['link_video_presentasi'] ?? '')) ?: null;
            $catatanSiswa = trim((string)($_POST['catatan_siswa'] ?? '')) ?: null;

            if ($judul === '') {
                throw new Exception('Judul hasil karya proyek wajib diisi.');
            }
            if ($desk === '') {
                $desk = $catatanSiswa ?: ('Pengumpulan karya proyek ' . $judul . '.');
            }

            $isKelompok = ($p['jenis_proyek'] === 'Kelompok');
            $idKelompok = null;

            if ($isKelompok) {
                $myTeam = $findMyTeamForProyek($pid);
                if (!$myTeam) {
                    throw new Exception('Anda belum dimasukkan ke dalam kelompok proyek ini oleh Guru. Silakan hubungi guru pengampu.');
                }
                // ATURAN RESMI IDENTIK TUGAS.PHP: HANYA KETUA KELOMPOK YANG BERHAK MENGUNGGAH
                $idKetua = (int)($myTeam['id_ketua_kelompok'] ?? 0);
                if ($idKetua > 0 && $idKetua !== $idSiswa) {
                    $namaKetua = $myTeam['nama_ketua'] ?: 'Ketua Kelompok Anda';
                    throw new Exception("Akses Ditolak: Hanya Ketua Kelompok ({$namaKetua}) yang memiliki hak untuk mengunggah hasil karya kelompok ini.");
                }
                $idKelompok = (int)$myTeam['id'];
            }

            // Deteksi keterlambatan (identik tugas.php)
            $isLate = 0;
            if (!empty($p['jadwal_selesai']) && date('Y-m-d') > $p['jadwal_selesai']) {
                $isLate = 1;
            }

            // Cek apakah siswa / kelompok sudah pernah mengumpulkan pada proyek ini
            if ($idKelompok) {
                $cekStmt = $pdo->prepare("SELECT * FROM pengumpulan_proyek WHERE id_proyek = ? AND (id_proyek_kelompok = ? OR id_siswa = ?) ORDER BY id DESC LIMIT 1");
                $cekStmt->execute([$pid, $idKelompok, $idSiswa]);
            } else {
                $cekStmt = $pdo->prepare("SELECT * FROM pengumpulan_proyek WHERE id_proyek = ? AND id_siswa = ? ORDER BY id DESC LIMIT 1");
                $cekStmt->execute([$pid, $idSiswa]);
            }
            $existing = $cekStmt->fetch(PDO::FETCH_ASSOC);

            $hasFileLap = isset($_FILES['file_laporan']) && ($_FILES['file_laporan']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
            $hasFileDok = isset($_FILES['file_dokumentasi']) && ($_FILES['file_dokumentasi']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

            if (!$hasFileLap && !$hasFileDok && !$linkProduk && !$linkVideo && !$existing) {
                throw new Exception('Harap lampirkan minimal 1 berkas proyek atau masukkan tautan produk/video.');
            }

            // Validasi ukuran berkas maksimal 1 MB per file (identik tugas.php) & simpan di uploads/proyek/YYYY/MM/
            $subUploadDir = 'proyek/' . date('Y/m');
            $origLapName = null;
            $lapSizeKb = null;
            if ($hasFileLap) {
                $sz = (int)$_FILES['file_laporan']['size'];
                if ($sz > 1 * 1024 * 1024) {
                    $mb = round($sz / (1024 * 1024), 2);
                    throw new Exception("Ukuran berkas laporan ({$mb} MB) melebihi batas maksimal 1 MB. Harap kompres berkas terlebih dahulu atau gunakan Tautan Link.");
                }
                $origLapName = basename((string)$_FILES['file_laporan']['name']);
                $lapSizeKb = (int)ceil($sz / 1024);
            }
            if ($hasFileDok) {
                $szD = (int)$_FILES['file_dokumentasi']['size'];
                if ($szD > 1 * 1024 * 1024) {
                    $mbD = round($szD / (1024 * 1024), 2);
                    throw new Exception("Ukuran berkas dokumentasi ({$mbD} MB) melebihi batas maksimal 1 MB. Harap kompres berkas atau gunakan Tautan Link.");
                }
            }

            $fileLaporan = spryUploadFile($_FILES['file_laporan'] ?? null, $subUploadDir, ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'zip', 'rar', 'jpg', 'jpeg', 'png'], 1);
            $fileDok = spryUploadFile($_FILES['file_dokumentasi'] ?? null, $subUploadDir, ['pdf', 'zip', 'rar', 'jpg', 'jpeg', 'png', 'webp'], 1);

            $hasAnyFile = ($fileLaporan || $fileDok || ($existing && (!empty($existing['file_laporan']) || !empty($existing['file_dokumentasi']))));
            $hasAnyLink = (!empty($linkProduk) || !empty($linkVideo));
            if ($hasAnyFile && $hasAnyLink) $tipePeng = 'File dan Link';
            elseif ($hasAnyFile) $tipePeng = 'File Upload';
            elseif ($hasAnyLink) $tipePeng = 'Link URL';
            else $tipePeng = 'Teks / Essay';

            if ($existing) {
                if (in_array($existing['status_pengumpulan'], ['Disetujui', 'Dipublikasikan'], true) && $existing['nilai_proyek'] !== null) {
                    throw new Exception('Karya proyek ini sudah dinilai dan disetujui guru sehingga tidak dapat diubah lagi.');
                }
                $revisiKe = (int)($existing['revisi_ke'] ?? 1);
                if ($existing['status_pengumpulan'] === 'Revisi') {
                    $revisiKe++;
                }
                $finalLap = $fileLaporan ?: ($existing['file_laporan'] ?? null);
                $finalLapName = $origLapName ?: ($existing['file_nama_asli'] ?? null);
                $finalLapKb = $lapSizeKb ?: ($existing['file_ukuran_kb'] ?? null);
                $finalDok = $fileDok ?: ($existing['file_dokumentasi'] ?? null);

                $upd = $pdo->prepare("
                    UPDATE pengumpulan_proyek
                    SET id_siswa = ?, id_proyek_kelompok = COALESCE(?, id_proyek_kelompok),
                        tipe_pengumpulan = ?, judul_laporan_produk = ?, deskripsi_produk = ?,
                        file_laporan = ?, file_nama_asli = ?, file_ukuran_kb = ?, file_dokumentasi = ?,
                        link_produk = ?, link_video_presentasi = ?, catatan_siswa = ?,
                        status_pengumpulan = 'Diserahkan', is_terlambat = ?, revisi_ke = ?,
                        waktu_pengumpulan = NOW(), updated_at = NOW()
                    WHERE id = ?
                ");
                $upd->execute([
                    $idSiswa, $idKelompok, $tipePeng, $judul, $desk,
                    $finalLap, $finalLapName, $finalLapKb, $finalDok,
                    $linkProduk, $linkVideo, $catatanSiswa,
                    $isLate, $revisiKe, (int)$existing['id']
                ]);
                echo json_encode(['success' => true, 'message' => 'Alhamdulillah! Jawaban dan berkas proyek berhasil diperbarui (Revisi ke-' . $revisiKe . ').']);
                exit;
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO pengumpulan_proyek (
                        id_proyek, id_siswa, id_kelas_mata_pelajaran, id_proyek_kelompok,
                        tipe_pengumpulan, judul_laporan_produk, deskripsi_produk,
                        file_laporan, file_nama_asli, file_ukuran_kb, file_dokumentasi,
                        link_produk, link_video_presentasi, catatan_siswa,
                        waktu_pengumpulan, status_pengumpulan, is_terlambat, revisi_ke
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'Diserahkan', ?, 1)
                ");
                $ins->execute([
                    $pid, $idSiswa, (int)$p['id_kelas_mata_pelajaran'], $idKelompok,
                    $tipePeng, $judul, $desk,
                    $fileLaporan, $origLapName, $lapSizeKb, $fileDok,
                    $linkProduk, $linkVideo, $catatanSiswa, $isLate
                ]);
                $kelMsg = $isKelompok ? ' untuk kelompok Anda' : '';
                echo json_encode(['success' => true, 'message' => 'Alhamdulillah! Karya proyek berhasil dikumpulkan' . $kelMsg . '!']);
                exit;
            }
        }

        /* 3. LIST BIAYA PROYEK MILIK SISWA / TIM */
        if ($action === 'list_my_biaya') {
            $idProyek = (int)($_POST['id_proyek'] ?? 0);
            $where = ["kmp.id_kelas = ?"];
            $params = [$idKelas];
            if ($idProyek > 0) {
                $where[] = "bp.id_proyek = ?";
                $params[] = $idProyek;
            }

            // Hanya tampilkan biaya yang dicatat siswa ini atau milik kelompok siswa ini
            $myTeamIds = $pdo->prepare("
                SELECT DISTINCT pk.id
                FROM proyek_kelompok pk
                LEFT JOIN proyek_kelompok_anggota pka ON pka.id_proyek_kelompok = pk.id
                WHERE pka.id_siswa = ? OR pk.id_ketua_kelompok = ?
            ");
            $myTeamIds->execute([$idSiswa, $idSiswa]);
            $tIds = array_map('intval', $myTeamIds->fetchAll(PDO::FETCH_COLUMN));

            if (!empty($tIds)) {
                $inT = implode(',', $tIds);
                $where[] = "(bp.id_siswa = ? OR bp.id_proyek_kelompok IN ($inT))";
                $params[] = $idSiswa;
            } else {
                $where[] = "bp.id_siswa = ?";
                $params[] = $idSiswa;
            }

            $sql = "
                SELECT bp.*, p.nama_proyek, mp.nama_mata_pelajaran,
                       s.nama_lengkap AS nama_siswa, pk.nama_kelompok
                FROM biaya_proyek bp
                JOIN proyek p ON bp.id_proyek = p.id
                JOIN kelas_mata_pelajaran kmp ON bp.id_kelas_mata_pelajaran = kmp.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN siswa s ON bp.id_siswa = s.id
                LEFT JOIN proyek_kelompok pk ON bp.id_proyek_kelompok = pk.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY bp.tanggal_pengeluaran DESC, bp.id DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $verified = 0;
            $pending = 0;
            foreach ($rows as $r) {
                if ($r['status_verifikasi'] === 'Diverifikasi Guru') $verified += (float)$r['total_biaya'];
                elseif ($r['status_verifikasi'] === 'Menunggu Verifikasi') $pending += (float)$r['total_biaya'];
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'items'   => $rows,
                    'summary' => ['verified' => $verified, 'pending' => $pending],
                ],
            ]);
            exit;
        }

        /* 4. CATAT BIAYA PROYEK OLEH SISWA (STATUS: MENUNGGU VERIFIKASI GURU) */
        if ($action === 'save_my_biaya') {
            $p = $assertClassProyek($_POST['id_proyek'] ?? 0);
            $pid = (int)$p['id'];
            $myTeam = $findMyTeamForProyek($pid);
            $idKelompok = $myTeam ? (int)$myTeam['id'] : null;

            $tanggal = trim((string)($_POST['tanggal_pengeluaran'] ?? date('Y-m-d')));
            $validKat = ['Bahan Baku & Alat', 'Percetakan & Jilid', 'Transportasi', 'Konsumsi', 'Persewaan Perangkat', 'Digital / Cloud / Lisensi', 'Lain-lain'];
            $kat = in_array(($_POST['kategori_biaya'] ?? ''), $validKat, true) ? $_POST['kategori_biaya'] : 'Bahan Baku & Alat';
            $item = trim((string)($_POST['nama_item_biaya'] ?? ''));
            $qty = max(0.01, (float)($_POST['kuantitas'] ?? 1));
            $satuan = trim((string)($_POST['satuan'] ?? 'pcs')) ?: 'pcs';
            $harga = max(0, (float)($_POST['harga_satuan'] ?? 0));
            $total = round($qty * $harga, 2);
            $validSumber = ['Iuran Mandiri Siswa', 'BOS / Sekolah', 'Sponsor / Donatur', 'Kas Kelas'];
            $sumber = in_array(($_POST['sumber_dana'] ?? ''), $validSumber, true) ? $_POST['sumber_dana'] : 'Iuran Mandiri Siswa';
            $catatan = trim((string)($_POST['catatan'] ?? ''));

            if ($item === '') {
                throw new Exception('Nama item pengeluaran wajib diisi.');
            }

            $bukti = spryUploadFile($_FILES['bukti_transaksi'] ?? null, 'proyek_bukti', ['jpg', 'jpeg', 'png', 'webp', 'pdf'], 8);

            $ins = $pdo->prepare("
                INSERT INTO biaya_proyek (
                    id_proyek, id_proyek_kelompok, id_siswa, id_kelas_mata_pelajaran,
                    tanggal_pengeluaran, kategori_biaya, nama_item_biaya,
                    kuantitas, satuan, harga_satuan, total_biaya, bukti_transaksi,
                    sumber_dana, status_verifikasi, catatan
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Menunggu Verifikasi', ?)
            ");
            $ins->execute([
                $pid, $idKelompok, $idSiswa, (int)$p['id_kelas_mata_pelajaran'],
                $tanggal, $kat, $item, $qty, $satuan, $harga, $total, $bukti, $sumber, $catatan
            ]);

            echo json_encode(['success' => true, 'message' => 'Pengeluaran proyek tercatat dan menunggu verifikasi guru.']);
            exit;
        }

        throw new Exception('Aksi API tidak dikenali.');
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// ==============================================================================
// 3. PAGE SHELL RENDER (DATA-FIRST ZERO TITLE / SEGMENTED TABS / SOLAR ICONS)
// ==============================================================================
$pageTitle = 'Proyek & P5 Saya';
$pageIcon  = 'solar:rocket-2-bold';
$cssVer    = @filemtime(BASE_PATH . '/assets/css/guru-proyek.css') ?: time();
$jsVer     = @filemtime(BASE_PATH . '/assets/js/siswa-proyek.js') ?: time();

include BASE_PATH . '/includes/header.php';
include BASE_PATH . '/includes/modal-template.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/guru-proyek.css?v=<?= $cssVer ?>">

<div class="pry-app">
    <!-- SEGMENTED TABS -->
    <div class="curriculum-main-tabs" id="spryMainTabs">
        <button type="button" class="curriculum-tab-btn active" data-tab="proyek">
            <iconify-icon icon="solar:rocket-2-bold"></iconify-icon>
            <span>Proyek &amp; Pengumpulan Karya</span>
        </button>
        <button type="button" class="curriculum-tab-btn" data-tab="kelompok">
            <iconify-icon icon="solar:users-group-rounded-bold"></iconify-icon>
            <span>Kelompok &amp; Peran Saya</span>
        </button>
        <button type="button" class="curriculum-tab-btn" data-tab="biaya">
            <iconify-icon icon="solar:wallet-money-bold"></iconify-icon>
            <span>Catatan Biaya Proyek</span>
        </button>
    </div>

    <!-- KPI STRIP -->
    <div class="pry-kpi-grid">
        <div class="pry-kpi-card">
            <div class="pry-kpi-icon pry-kpi-icon--teal"><iconify-icon icon="solar:rocket-2-bold"></iconify-icon></div>
            <div class="pry-kpi-meta">
                <div class="pry-kpi-val" id="skpiTotal">0</div>
                <div class="pry-kpi-lbl">Proyek Kelas <?= htmlspecialchars($namaKelas) ?></div>
            </div>
        </div>
        <div class="pry-kpi-card">
            <div class="pry-kpi-icon pry-kpi-icon--amber"><iconify-icon icon="solar:clock-circle-bold"></iconify-icon></div>
            <div class="pry-kpi-meta">
                <div class="pry-kpi-val" id="skpiBelum">0</div>
                <div class="pry-kpi-lbl">Belum Dikumpulkan</div>
            </div>
        </div>
        <div class="pry-kpi-card">
            <div class="pry-kpi-icon pry-kpi-icon--navy"><iconify-icon icon="solar:folder-check-bold"></iconify-icon></div>
            <div class="pry-kpi-meta">
                <div class="pry-kpi-val" id="skpiTerkumpul">0</div>
                <div class="pry-kpi-lbl">Sudah Dikumpulkan</div>
            </div>
        </div>
        <div class="pry-kpi-card">
            <div class="pry-kpi-icon pry-kpi-icon--emerald"><iconify-icon icon="solar:medal-star-bold"></iconify-icon></div>
            <div class="pry-kpi-meta">
                <div class="pry-kpi-val" id="skpiDinilai">0</div>
                <div class="pry-kpi-lbl">Sudah Dinilai P5</div>
            </div>
        </div>
    </div>

    <!-- FILTER TOOLBAR -->
    <div class="pry-toolbar">
        <div class="pry-toolbar-row">
            <div class="pry-filter-group">
                <select id="spryFilterMapel" class="pry-select" aria-label="Filter Mata Pelajaran">
                    <option value="0">Semua Mata Pelajaran</option>
                </select>
                <select id="spryFilterStatus" class="pry-select" aria-label="Filter Status Pengumpulan">
                    <option value="">Semua Status</option>
                    <option value="belum">Belum Dikumpulkan</option>
                    <option value="terkumpul">Sudah Dikumpulkan</option>
                    <option value="revisi">Perlu Revisi</option>
                    <option value="dinilai">Sudah Dinilai</option>
                </select>
                <div class="pry-search-wrap">
                    <iconify-icon icon="solar:magnifer-linear"></iconify-icon>
                    <input type="text" id="sprySearchInput" class="pry-input" placeholder="Cari proyek, tema P5, modul...">
                </div>
            </div>
            <span class="pry-badge pry-badge--navy" style="padding:7px 12px;font-size:12px;">
                <iconify-icon icon="solar:square-academic-cap-2-bold"></iconify-icon>
                <?= htmlspecialchars($namaSiswa) ?> &bull; <?= htmlspecialchars($namaKelas) ?>
            </span>
        </div>
    </div>

    <!-- TAB 1: DAFTAR PROYEK & PENGUMPULAN -->
    <section id="spry-tab-proyek" class="pry-tab-pane" style="display:block;">
        <div class="pry-card-grid" id="spryCardGrid"></div>
    </section>

    <!-- TAB 2: KELOMPOK & PERAN SAYA -->
    <section id="spry-tab-kelompok" class="pry-tab-pane" style="display:none;">
        <div class="pry-panel">
            <div id="spryTeamContainer"></div>
        </div>
    </section>

    <!-- TAB 3: CATATAN BIAYA PROYEK -->
    <section id="spry-tab-biaya" class="pry-tab-pane" style="display:none;">
        <div class="pry-panel">
            <div class="pry-panel-head">
                <div class="pry-panel-select-wrap">
                    <label for="spryBiayaProyekFilter">Filter Proyek:</label>
                    <select id="spryBiayaProyekFilter" class="pry-select"></select>
                </div>
                <button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" onclick="SPRY.openBiayaModal()">
                    <iconify-icon icon="solar:add-circle-bold"></iconify-icon>
                    <span>Catat Pengeluaran</span>
                </button>
            </div>
            <div id="spryBiayaContainer"></div>
        </div>
    </section>
</div>

<!-- ==========================================================================
     MODAL 1: KUMPULKAN / PERBARUI KARYA PROYEK SISWA
     ========================================================================== -->
<div class="ilm-modal-backdrop" id="modalSiswaSubmitProyek" aria-hidden="true">
    <div class="ilm-modal-dialog" style="max-width:680px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon"><iconify-icon icon="solar:upload-bold"></iconify-icon></div>
                <div>
                    <h3 class="ilm-modal-header__title" id="sprySubModalTitle">Kumpulkan Hasil Karya Proyek</h3>
                    <p class="ilm-modal-header__subtitle" id="sprySubModalSub">Lengkapi laporan, tautan produk &amp; dokumentasi</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-close" onclick="closeIlmunaModal('modalSiswaSubmitProyek')" aria-label="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form id="sprySubForm" onsubmit="SPRY.submitKarya(event)" enctype="multipart/form-data">
            <div class="ilm-modal-body">
                <input type="hidden" name="id_proyek" id="sprySubProyekId" value="0">
                <div id="sprySubContextInfo" class="pry-info-box" style="margin-bottom:12px;"></div>
                <div class="pry-form-grid">
                    <div class="pry-field pry-field--full">
                        <label class="pry-label" for="sprySubJudul">Judul Hasil Karya / Laporan Akhir <span class="req">*</span></label>
                        <input type="text" name="judul_laporan_produk" id="sprySubJudul" class="pry-input" required placeholder="Contoh: Film Pendek Bijak Bermedia Sosial — Tim 1">
                    </div>
                    <div class="pry-field pry-field--full">
                        <label class="pry-label" for="sprySubDesc">Deskripsi Produk, Inovasi &amp; Manfaat <span class="req">*</span></label>
                        <textarea name="deskripsi_produk" id="sprySubDesc" class="pry-textarea" required placeholder="Jelaskan hasil karya yang dibuat, proses pengerjaan, dan manfaatnya..."></textarea>
                    </div>
                    <div class="pry-field">
                        <label class="pry-label" for="sprySubLinkProduk">Tautan Hasil Karya Digital</label>
                        <input type="url" name="link_produk" id="sprySubLinkProduk" class="pry-input" placeholder="https://drive.google.com/... atau Canva/Web">
                    </div>
                    <div class="pry-field">
                        <label class="pry-label" for="sprySubLinkVideo">Tautan Video Presentasi / Gelar Karya</label>
                        <input type="url" name="link_video_presentasi" id="sprySubLinkVideo" class="pry-input" placeholder="https://youtube.com/... atau Drive">
                    </div>
                    <div class="pry-field">
                        <label class="pry-label" for="sprySubFileLap">Unggah Berkas Laporan (Maks 1 MB — PDF/DOCX/PPTX/ZIP)</label>
                        <input type="file" name="file_laporan" id="sprySubFileLap" class="pry-input" accept=".pdf,.doc,.docx,.ppt,.pptx,.zip,.rar,.jpg,.jpeg,.png">
                        <div id="sprySubExistingLap" style="margin-top:5px;"></div>
                    </div>
                    <div class="pry-field">
                        <label class="pry-label" for="sprySubFileDok">Unggah Dokumentasi Proses (Maks 1 MB, opsional)</label>
                        <input type="file" name="file_dokumentasi" id="sprySubFileDok" class="pry-input" accept=".pdf,.zip,.rar,.jpg,.jpeg,.png,.webp">
                        <div id="sprySubExistingDok" style="margin-top:5px;"></div>
                    </div>
                    <div class="pry-field pry-field--full">
                        <label class="pry-label" for="sprySubCatatan">Catatan Pengantar untuk Guru (Opsional)</label>
                        <textarea name="catatan_siswa" id="sprySubCatatan" class="pry-textarea" style="min-height:60px;" placeholder="Sampaikan pesan atau keterangan tambahan saat mengumpulkan karya..."></textarea>
                    </div>
                </div>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" onclick="closeIlmunaModal('modalSiswaSubmitProyek')">Batal</button>
                <button type="submit" class="ilmuna-btn ilmuna-btn--teal" id="sprySubSubmitBtn">
                    <iconify-icon icon="solar:plain-bold"></iconify-icon>
                    <span>Kirim Karya Proyek</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ==========================================================================
     MODAL 2: CATAT BIAYA PROYEK SISWA
     ========================================================================== -->
<div class="ilm-modal-backdrop" id="modalSiswaBiaya" aria-hidden="true">
    <div class="ilm-modal-dialog" style="max-width:560px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon"><iconify-icon icon="solar:wallet-money-bold"></iconify-icon></div>
                <div>
                    <h3 class="ilm-modal-header__title">Catat Pengeluaran Proyek</h3>
                    <p class="ilm-modal-header__subtitle">Transparansi biaya tim — akan diverifikasi oleh guru</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-close" onclick="closeIlmunaModal('modalSiswaBiaya')" aria-label="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form id="spryBiayaForm" onsubmit="SPRY.submitBiaya(event)" enctype="multipart/form-data">
            <div class="ilm-modal-body">
                <div class="pry-form-grid">
                    <div class="pry-field pry-field--full">
                        <label class="pry-label" for="spryBiayaProyek">Pilih Proyek <span class="req">*</span></label>
                        <select name="id_proyek" id="spryBiayaProyek" class="pry-select" required></select>
                    </div>
                    <div class="pry-field">
                        <label class="pry-label" for="spryBiayaTgl">Tanggal Belanja <span class="req">*</span></label>
                        <input type="text" name="tanggal_pengeluaran" id="spryBiayaTgl" class="pry-input" data-ilm-calendar="date" required readonly>
                    </div>
                    <div class="pry-field">
                        <label class="pry-label" for="spryBiayaKat">Kategori</label>
                        <select name="kategori_biaya" id="spryBiayaKat" class="pry-select">
                            <option value="Bahan Baku & Alat">Bahan Baku &amp; Alat</option>
                            <option value="Percetakan & Jilid">Percetakan &amp; Jilid</option>
                            <option value="Transportasi">Transportasi</option>
                            <option value="Konsumsi">Konsumsi</option>
                            <option value="Persewaan Perangkat">Persewaan Perangkat</option>
                            <option value="Digital / Cloud / Lisensi">Digital / Cloud / Lisensi</option>
                            <option value="Lain-lain">Lain-lain</option>
                        </select>
                    </div>
                    <div class="pry-field pry-field--full">
                        <label class="pry-label" for="spryBiayaItem">Nama Item / Keperluan <span class="req">*</span></label>
                        <input type="text" name="nama_item_biaya" id="spryBiayaItem" class="pry-input" required placeholder="Contoh: Kertas Karton &amp; Print Poster">
                    </div>
                    <div class="pry-field">
                        <label class="pry-label" for="spryBiayaQty">Jumlah &amp; Satuan</label>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <input type="number" name="kuantitas" id="spryBiayaQty" class="pry-input" step="0.5" min="0.5" value="1" required>
                            <input type="text" name="satuan" id="spryBiayaSatuan" class="pry-input" value="paket">
                        </div>
                    </div>
                    <div class="pry-field">
                        <label class="pry-label" for="spryBiayaHarga">Harga Satuan (Rp) <span class="req">*</span></label>
                        <input type="number" name="harga_satuan" id="spryBiayaHarga" class="pry-input" min="0" step="500" value="0" required>
                    </div>
                    <div class="pry-field">
                        <label class="pry-label" for="spryBiayaSumber">Sumber Dana</label>
                        <select name="sumber_dana" id="spryBiayaSumber" class="pry-select">
                            <option value="Iuran Mandiri Siswa">Iuran Mandiri Siswa</option>
                            <option value="Kas Kelas">Kas Kelas</option>
                            <option value="BOS / Sekolah">BOS / Sekolah</option>
                            <option value="Sponsor / Donatur">Sponsor / Donatur</option>
                        </select>
                    </div>
                    <div class="pry-field">
                        <label class="pry-label">Total Otomatis</label>
                        <div class="pry-info-box" style="font-weight:800;font-size:14px;" id="spryBiayaTotalPreview">Rp 0</div>
                    </div>
                    <div class="pry-field pry-field--full">
                        <label class="pry-label" for="spryBiayaBukti">Foto Nota / Struk (JPG/PNG/PDF, opsional)</label>
                        <input type="file" name="bukti_transaksi" id="spryBiayaBukti" class="pry-input" accept=".jpg,.jpeg,.png,.webp,.pdf">
                    </div>
                    <div class="pry-field pry-field--full">
                        <label class="pry-label" for="spryBiayaCatatan">Catatan (Opsional)</label>
                        <textarea name="catatan" id="spryBiayaCatatan" class="pry-textarea" style="min-height:58px;"></textarea>
                    </div>
                </div>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" onclick="closeIlmunaModal('modalSiswaBiaya')">Batal</button>
                <button type="submit" class="ilmuna-btn ilmuna-btn--teal" id="spryBiayaSubmitBtn"><iconify-icon icon="solar:diskette-bold"></iconify-icon> Simpan Pengeluaran</button>
            </div>
        </form>
    </div>
</div>

<script>
    window.ILMUNA_SISWA_PROYEK_CFG = {
        baseUrl: <?= json_encode(BASE_URL) ?>,
        endpoint: <?= json_encode(BASE_URL . '/dashboard/siswa/proyek') ?>,
        siswaId: <?= (int)$idSiswa ?>
    };
</script>
<script src="<?= BASE_URL ?>/assets/js/siswa-proyek.js?v=<?= $jsVer ?>" defer></script>

<?php include BASE_PATH . '/includes/footer.php'; ?>
