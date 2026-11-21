<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — PROYEK & P5 (PjBL) TERINTEGRASI MODUL AJAR
 * ==============================================================================
 * Portal Dewan Guru & Monitoring Admin:
 *   1. Daftar Proyek & P5 (PjBL) tertaut langsung ke Modul Ajar (`id_modul_ajar`),
 *      TP (`id_tp`), ITP (`id_itp`), Pertemuan (`pertemuan_ke`), & Jadwal (`id_jadwal`)
 *   2. Manajemen Kelompok & Peran Tim (`proyek_kelompok`, `proyek_kelompok_anggota`)
 *      + Fitur Auto-Generate Pembagian Kelompok Merata
 *   3. Hasil Karya & Penilaian P5 (`pengumpulan_proyek`) dengan Skor & Predikat
 *      (SB / BSH / MB / BB)
 *   4. Transparansi Anggaran & Biaya Proyek (`biaya_proyek`)
 *   5. 100% Lazy Load SPA (Zero Page Reload), Strict Guru Scope, PHP 7.4 Compatible
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
ilmuna_ensure_academic_sync_schema($pdo);

$currentUser = getCurrentUser();
$idUserLogged = (int)($currentUser['id'] ?? 0);
$isAdmin = ($activeRole === 'admin');
$canManage = true; // Guru mengelola mapel ampuannya; Admin dapat memantau/mengelola semua

$idGuruLogged = 0;
if ($idUserLogged > 0) {
    try {
        $gStmt = $pdo->prepare("SELECT id, nama_guru FROM guru WHERE id_user = ? LIMIT 1");
        $gStmt->execute([$idUserLogged]);
        $gRow = $gStmt->fetch(PDO::FETCH_ASSOC);
        if ($gRow) {
            $idGuruLogged = (int)$gRow['id'];
        }
    } catch (Exception $e) {}
}

/**
 * Helper: Daftar KMP sesuai Scope Ketat Guru / Admin
 */
function pryGetAccessibleKmps($pdo, $activeRole, $idGuruLogged) {
    $sql = "
        SELECT kmp.id AS id_kmp,
               kmp.id_kelas,
               kmp.id_mata_pelajaran,
               k.nama_kelas,
               mp.nama_mata_pelajaran,
               mp.kode_mapel,
               COALESCE(g.nama_guru, 'Dewan Guru') AS nama_guru
        FROM kelas_mata_pelajaran kmp
        JOIN kelas k ON kmp.id_kelas = k.id
        JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
        LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
        LEFT JOIN guru g ON gmp.id_guru = g.id
    ";
    if ($activeRole === 'guru') {
        if ($idGuruLogged <= 0) return [];
        $stmt = $pdo->prepare($sql . " WHERE gmp.id_guru = ? ORDER BY k.nama_kelas ASC, mp.nama_mata_pelajaran ASC");
        $stmt->execute([$idGuruLogged]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $pdo->query($sql . " ORDER BY k.nama_kelas ASC, mp.nama_mata_pelajaran ASC")->fetchAll(PDO::FETCH_ASSOC);
}

function pryGetStudentsByKelas($pdo, $idKelas) {
    $stmt = $pdo->prepare("
        SELECT id, nis, nisn, nama_lengkap AS nama_siswa
        FROM siswa
        WHERE id_kelas = ?
        ORDER BY nama_lengkap ASC
    ");
    $stmt->execute([(int)$idKelas]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function pryHitungPredikatHuruf($nilai) {
    $n = (float)$nilai;
    if ($n >= 90) return 'A';
    if ($n >= 80) return 'B';
    if ($n >= 70) return 'C';
    if ($n >= 60) return 'D';
    return 'E';
}

function pryHitungPredikatP5($nilai) {
    $n = (float)$nilai;
    if ($n >= 90) return 'Sangat Berkembang (SB)';
    if ($n >= 75) return 'Berkembang Sesuai Harapan (BSH)';
    if ($n >= 60) return 'Mulai Berkembang (MB)';
    return 'Belum Berkembang (BB)';
}

function pryUploadFile($fileInfo, $subDir, $allowedExts, $maxMb = 15) {
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
    $base = substr($base, 0, 48) ?: 'file';
    $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '_' . $base . '.' . $ext;
    $dest = $dir . '/' . $filename;
    if (!move_uploaded_file($fileInfo['tmp_name'], $dest)) {
        throw new Exception('Gagal menyimpan berkas ke server.');
    }
    return 'uploads/' . $subDir . '/' . $filename;
}

/* ==============================================================================
 * AJAX / JSON API HANDLER (?api=1)
 * ============================================================================== */
if (isset($_GET['api']) && $_GET['api'] == '1') {
    header('Content-Type: application/json; charset=utf-8');
    $action = trim((string)($_POST['action'] ?? $_GET['action'] ?? ''));

    try {
        $kmpRows = pryGetAccessibleKmps($pdo, $activeRole, $idGuruLogged);
        $allowedKmpIds = [];
        $kmpMap = [];
        foreach ($kmpRows as $kr) {
            $kid = (int)$kr['id_kmp'];
            $allowedKmpIds[] = $kid;
            $kmpMap[$kid] = $kr;
        }

        $assertKmp = function ($idKmp) use ($activeRole, $allowedKmpIds) {
            $idKmp = (int)$idKmp;
            if ($idKmp <= 0) throw new Exception('Mata pelajaran & kelas wajib dipilih.');
            if ($activeRole === 'guru' && !in_array($idKmp, $allowedKmpIds, true)) {
                throw new Exception('Anda tidak memiliki akses ke mata pelajaran kelas ini.');
            }
            return $idKmp;
        };

        $assertProyek = function ($idProyek) use ($pdo, $activeRole, $allowedKmpIds) {
            $stmt = $pdo->prepare("
                SELECT p.*, kmp.id_kelas, k.nama_kelas, mp.nama_mata_pelajaran
                FROM proyek p
                JOIN kelas_mata_pelajaran kmp ON p.id_kelas_mata_pelajaran = kmp.id
                JOIN kelas k ON kmp.id_kelas = k.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                WHERE p.id = ?
                LIMIT 1
            ");
            $stmt->execute([(int)$idProyek]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('Data proyek tidak ditemukan.');
            if ($activeRole === 'guru' && !in_array((int)$row['id_kelas_mata_pelajaran'], $allowedKmpIds, true)) {
                throw new Exception('Anda tidak memiliki akses ke proyek ini.');
            }
            return $row;
        };

        /* 1. BOOTSTRAP */
        if ($action === 'bootstrap') {
            $kelasSeen = [];
            $kelasList = [];
            foreach ($kmpRows as $kr) {
                $idk = (int)$kr['id_kelas'];
                if (!isset($kelasSeen[$idk])) {
                    $kelasSeen[$idk] = true;
                    $kelasList[] = [
                        'id_kelas'   => $idk,
                        'nama_kelas' => $kr['nama_kelas'],
                    ];
                }
            }
            echo json_encode([
                'success' => true,
                'data' => [
                    'kmp_list'   => $kmpRows,
                    'kelas_list' => $kelasList,
                ],
            ]);
            exit;
        }

        /* 2. LIST MODUL AJAR BY KMP */
        if ($action === 'list_modul_by_kmp') {
            $idKmp = $assertKmp($_POST['id_kmp'] ?? 0);
            $stmt = $pdo->prepare("
                SELECT ma.id, ma.pertemuan_ke, ma.judul_modul, ma.tanggal, ma.id_tp, ma.id_itp,
                       ma.id_jadwal_pelajaran, ma.profil_pelajar_pancasila, ma.status,
                       tp.kode_tp, tp.tp AS uraian_tp
                FROM modul_ajar ma
                LEFT JOIN tp ON ma.id_tp = tp.id
                WHERE ma.id_kelas_mata_pelajaran = ?
                ORDER BY ma.pertemuan_ke ASC, ma.id ASC
            ");
            $stmt->execute([$idKmp]);
            echo json_encode([
                'success' => true,
                'data'    => $stmt->fetchAll(PDO::FETCH_ASSOC),
            ]);
            exit;
        }

        /* 3. LIST PROYEK + STATS */
        if ($action === 'list_proyek') {
            $idKelas = (int)($_POST['id_kelas'] ?? 0);
            $idKmp = (int)($_POST['id_kmp'] ?? 0);
            $idModul = (int)($_POST['id_modul_ajar'] ?? 0);
            $status = trim((string)($_POST['status_proyek'] ?? ''));
            $search = trim((string)($_POST['search'] ?? ''));

            if ($activeRole === 'guru' && empty($allowedKmpIds)) {
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'items' => [],
                        'stats' => ['total_proyek' => 0, 'linked_modul' => 0, 'total_karya' => 0, 'karya_dinilai' => 0, 'total_biaya' => 0],
                    ],
                ]);
                exit;
            }

            $where = ['1=1'];
            $params = [];

            if ($activeRole === 'guru') {
                $in = implode(',', array_fill(0, count($allowedKmpIds), '?'));
                $where[] = "p.id_kelas_mata_pelajaran IN ($in)";
                $params = array_merge($params, $allowedKmpIds);
            }
            if ($idKelas > 0) {
                $where[] = "kmp.id_kelas = ?";
                $params[] = $idKelas;
            }
            if ($idKmp > 0) {
                $where[] = "p.id_kelas_mata_pelajaran = ?";
                $params[] = $idKmp;
            }
            if ($idModul > 0) {
                $where[] = "p.id_modul_ajar = ?";
                $params[] = $idModul;
            }
            if ($status !== '') {
                $where[] = "p.status_proyek = ?";
                $params[] = $status;
            }
            if ($search !== '') {
                $where[] = "(p.nama_proyek LIKE ? OR p.tema_proyek LIKE ? OR p.target_luaran LIKE ? OR ma.judul_modul LIKE ?)";
                $q = '%' . $search . '%';
                array_push($params, $q, $q, $q, $q);
            }

            $sql = "
                SELECT p.*,
                       kmp.id_kelas,
                       k.nama_kelas,
                       mp.nama_mata_pelajaran,
                       ma.judul_modul,
                       ma.pertemuan_ke AS modul_pertemuan_ke,
                       (SELECT COUNT(*) FROM proyek_kelompok pk WHERE pk.id_proyek = p.id) AS jumlah_kelompok,
                       (SELECT COUNT(*) FROM pengumpulan_proyek pp WHERE pp.id_proyek = p.id) AS jumlah_karya,
                       (SELECT COUNT(*) FROM pengumpulan_proyek pp WHERE pp.id_proyek = p.id AND pp.nilai_proyek IS NOT NULL) AS jumlah_dinilai,
                       (SELECT COALESCE(SUM(bp.total_biaya), 0) FROM biaya_proyek bp WHERE bp.id_proyek = p.id AND bp.status_verifikasi != 'Ditolak') AS total_biaya
                FROM proyek p
                JOIN kelas_mata_pelajaran kmp ON p.id_kelas_mata_pelajaran = kmp.id
                JOIN kelas k ON kmp.id_kelas = k.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN modul_ajar ma ON p.id_modul_ajar = ma.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY p.jadwal_mulai DESC, p.id DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stats = [
                'total_proyek'  => count($items),
                'linked_modul'  => 0,
                'total_karya'   => 0,
                'karya_dinilai' => 0,
                'total_biaya'   => 0,
            ];
            foreach ($items as $it) {
                if ((int)$it['id_modul_ajar'] > 0) $stats['linked_modul']++;
                $stats['total_karya'] += (int)$it['jumlah_karya'];
                $stats['karya_dinilai'] += (int)$it['jumlah_dinilai'];
                $stats['total_biaya'] += (float)$it['total_biaya'];
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'items' => $items,
                    'stats' => $stats,
                ],
            ]);
            exit;
        }

        /* 4. CREATE / UPDATE PROYEK */
        if ($action === 'save_proyek') {
            $id = (int)($_POST['id'] ?? 0);
            $idKmp = $assertKmp($_POST['id_kelas_mata_pelajaran'] ?? 0);
            $idModul = (int)($_POST['id_modul_ajar'] ?? 0) ?: null;
            $idTp = (int)($_POST['id_tp'] ?? 0) ?: null;
            $idItp = (int)($_POST['id_itp'] ?? 0) ?: null;
            $pertKe = (int)($_POST['pertemuan_ke'] ?? 0) ?: null;
            $idJadwal = (int)($_POST['id_jadwal'] ?? 0) ?: null;

            $nama = trim((string)($_POST['nama_proyek'] ?? ''));
            $tema = trim((string)($_POST['tema_proyek'] ?? ''));
            $deskripsi = trim((string)($_POST['deskripsi_tujuan'] ?? ''));
            $jenis = in_array(($_POST['jenis_proyek'] ?? ''), ['Individu', 'Kelompok'], true) ? $_POST['jenis_proyek'] : 'Kelompok';
            $dimensi = trim((string)($_POST['dimensi_profil_pancasila'] ?? ''));
            $luaran = trim((string)($_POST['target_luaran'] ?? ''));
            $mulai = trim((string)($_POST['jadwal_mulai'] ?? ''));
            $selesai = trim((string)($_POST['jadwal_selesai'] ?? ''));
            $rubrik = trim((string)($_POST['pedoman_penilaian_rubrik'] ?? ''));
            $linkRef = trim((string)($_POST['link_referensi'] ?? '')) ?: null;
            $nilaiMax = (float)($_POST['nilai_maksimal'] ?? 100);
            if ($nilaiMax <= 0 || $nilaiMax > 100) $nilaiMax = 100;
            $validStatus = ['Perencanaan', 'Sedang Berjalan', 'Tahap Pengujian', 'Selesai', 'Diarsipkan'];
            $status = in_array(($_POST['status_proyek'] ?? ''), $validStatus, true) ? $_POST['status_proyek'] : 'Sedang Berjalan';

            if ($nama === '' || $tema === '' || $luaran === '' || $mulai === '' || $selesai === '') {
                throw new Exception('Nama proyek, tema P5, target luaran, tanggal mulai, dan tenggat selesai wajib diisi.');
            }
            if ($deskripsi === '') {
                $deskripsi = 'Pelaksanaan proyek ' . $nama . ' (' . $tema . ') dengan target luaran ' . $luaran . '.';
            }

            // Sinkronisasi otomatis metadata dari Modul Ajar yang dipilih
            if ($idModul) {
                $mStmt = $pdo->prepare("SELECT id, id_kelas_mata_pelajaran, id_tp, id_itp, pertemuan_ke, id_jadwal_pelajaran FROM modul_ajar WHERE id = ? LIMIT 1");
                $mStmt->execute([$idModul]);
                $mRow = $mStmt->fetch(PDO::FETCH_ASSOC);
                if ($mRow && (int)$mRow['id_kelas_mata_pelajaran'] === $idKmp) {
                    if (!$idTp && !empty($mRow['id_tp'])) $idTp = (int)$mRow['id_tp'];
                    if (!$idItp && !empty($mRow['id_itp'])) $idItp = (int)$mRow['id_itp'];
                    if (!$pertKe && !empty($mRow['pertemuan_ke'])) $pertKe = (int)$mRow['pertemuan_ke'];
                    if (!$idJadwal && !empty($mRow['id_jadwal_pelajaran'])) $idJadwal = (int)$mRow['id_jadwal_pelajaran'];
                } else {
                    $idModul = null;
                }
            }

            $filePanduan = pryUploadFile($_FILES['file_panduan'] ?? null, 'proyek_panduan', ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'zip', 'png', 'jpg', 'jpeg'], 15);

            if ($id > 0) {
                $existing = $assertProyek($id);
                $finalFile = $filePanduan ?: ($existing['file_panduan'] ?? null);
                $upd = $pdo->prepare("
                    UPDATE proyek
                    SET id_kelas_mata_pelajaran = ?, id_modul_ajar = ?, pertemuan_ke = ?, id_jadwal = ?,
                        id_tp = ?, id_itp = ?, nama_proyek = ?, tema_proyek = ?, deskripsi_tujuan = ?,
                        jenis_proyek = ?, dimensi_profil_pancasila = ?, target_luaran = ?,
                        jadwal_mulai = ?, jadwal_selesai = ?, pedoman_penilaian_rubrik = ?,
                        file_panduan = ?, link_referensi = ?, nilai_maksimal = ?, status_proyek = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $upd->execute([
                    $idKmp, $idModul, $pertKe, $idJadwal,
                    $idTp, $idItp, $nama, $tema, $deskripsi,
                    $jenis, $dimensi ?: 'Bergotong Royong, Kreatif, Bernalar Kritis', $luaran,
                    $mulai, $selesai, $rubrik,
                    $finalFile, $linkRef, $nilaiMax, $status,
                    $id
                ]);
                echo json_encode(['success' => true, 'message' => 'Data proyek berhasil diperbarui dan tersinkron dengan Modul Ajar.', 'id' => $id]);
                exit;
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO proyek (
                        id_kelas_mata_pelajaran, id_guru, id_modul_ajar, pertemuan_ke, id_jadwal,
                        id_tp, id_itp, nama_proyek, tema_proyek, deskripsi_tujuan,
                        jenis_proyek, dimensi_profil_pancasila, target_luaran,
                        jadwal_mulai, jadwal_selesai, pedoman_penilaian_rubrik,
                        file_panduan, link_referensi, nilai_maksimal, status_proyek
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([
                    $idKmp, $idUserLogged ?: null, $idModul, $pertKe, $idJadwal,
                    $idTp, $idItp, $nama, $tema, $deskripsi,
                    $jenis, $dimensi ?: 'Bergotong Royong, Kreatif, Bernalar Kritis', $luaran,
                    $mulai, $selesai, $rubrik,
                    $filePanduan, $linkRef, $nilaiMax, $status
                ]);
                echo json_encode(['success' => true, 'message' => 'Proyek & P5 baru berhasil dibuat dan ditautkan ke Modul Ajar!', 'id' => (int)$pdo->lastInsertId()]);
                exit;
            }
        }

        /* 5. DELETE PROYEK */
        if ($action === 'delete_proyek') {
            $p = $assertProyek($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM proyek WHERE id = ?")->execute([(int)$p['id']]);
            echo json_encode(['success' => true, 'message' => 'Proyek berhasil dihapus.']);
            exit;
        }

        /* 6. GET KELOMPOK STATE (100% IDENTIK TAB KELOMPOK TUGAS.PHP) */
        if ($action === 'get_kelompok_state') {
            $p = $assertProyek($_POST['id_proyek'] ?? 0);
            $students = pryGetStudentsByKelas($pdo, (int)$p['id_kelas']);

            $tStmt = $pdo->prepare("
                SELECT pk.*, sK.nama_lengkap AS nama_ketua, sK.nisn AS nisn_ketua, sK.nis AS nis_ketua
                FROM proyek_kelompok pk
                LEFT JOIN siswa sK ON pk.id_ketua_kelompok = sK.id
                WHERE pk.id_proyek = ?
                ORDER BY pk.nomor_kelompok ASC, pk.id ASC
            ");
            $tStmt->execute([(int)$p['id']]);
            $teams = $tStmt->fetchAll(PDO::FETCH_ASSOC);

            $assignedIds = [];
            if (!empty($teams)) {
                $tIds = array_column($teams, 'id');
                $in = implode(',', array_fill(0, count($tIds), '?'));
                $aStmt = $pdo->prepare("
                    SELECT pka.*, s.nama_lengkap AS nama_siswa, s.nis, s.nisn
                    FROM proyek_kelompok_anggota pka
                    JOIN siswa s ON pka.id_siswa = s.id
                    WHERE pka.id_proyek_kelompok IN ($in)
                    ORDER BY s.nama_lengkap ASC
                ");
                $aStmt->execute($tIds);
                $byTeam = [];
                foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
                    $byTeam[(int)$a['id_proyek_kelompok']][] = $a;
                    $assignedIds[(int)$a['id_siswa']] = true;
                }
                foreach ($teams as &$t) {
                    $t['anggota'] = $byTeam[(int)$t['id']] ?? [];
                }
                unset($t);
            }

            $unassigned = [];
            foreach ($students as $st) {
                if (!isset($assignedIds[(int)$st['id']])) {
                    $unassigned[] = $st;
                }
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'proyek'            => $p,
                    'total_siswa_kelas' => count($students),
                    'kelompok'          => $teams,
                    'unassigned_siswa'  => $unassigned,
                    'students'          => $students,
                ],
            ]);
            exit;
        }

        /* 7. SAVE KELOMPOK */
        if ($action === 'save_kelompok') {
            $p = $assertProyek($_POST['id_proyek'] ?? 0);
            $nomor = max(1, (int)($_POST['nomor_kelompok'] ?? 1));
            $nama = trim((string)($_POST['nama_kelompok'] ?? '')) ?: ('Kelompok ' . $nomor);
            $idKetua = (int)($_POST['id_ketua_kelompok'] ?? 0) ?: null;

            $ins = $pdo->prepare("
                INSERT INTO proyek_kelompok (id_proyek, id_kelas_mata_pelajaran, nomor_kelompok, nama_kelompok, id_ketua_kelompok)
                VALUES (?, ?, ?, ?, ?)
            ");
            $ins->execute([(int)$p['id'], (int)$p['id_kelas_mata_pelajaran'], $nomor, $nama, $idKetua]);
            $newTeamId = (int)$pdo->lastInsertId();

            if ($idKetua) {
                // Pastikan ketua belum terdaftar di kelompok lain proyek ini
                $pdo->prepare("
                    DELETE pka FROM proyek_kelompok_anggota pka
                    JOIN proyek_kelompok pk ON pka.id_proyek_kelompok = pk.id
                    WHERE pk.id_proyek = ? AND pka.id_siswa = ?
                ")->execute([(int)$p['id'], $idKetua]);
                $pdo->prepare("
                    INSERT INTO proyek_kelompok_anggota (id_proyek_kelompok, id_siswa, id_kelas_mata_pelajaran, peran_anggota)
                    VALUES (?, ?, ?, 'Ketua Tim')
                ")->execute([$newTeamId, $idKetua, (int)$p['id_kelas_mata_pelajaran']]);
            }

            echo json_encode(['success' => true, 'message' => 'Kelompok "' . $nama . '" berhasil dibuat.']);
            exit;
        }

        /* 8. ADD ANGGOTA KE KELOMPOK (OTOMATIS PINDAH JIKA DARI KELOMPOK LAIN) */
        if ($action === 'add_anggota') {
            $idKelompok = (int)($_POST['id_proyek_kelompok'] ?? $_POST['id_kelompok'] ?? 0);
            $idSiswa = (int)($_POST['id_siswa'] ?? 0);
            $peran = trim((string)($_POST['peran_anggota'] ?? 'Anggota')) ?: 'Anggota';

            $tStmt = $pdo->prepare("SELECT * FROM proyek_kelompok WHERE id = ? LIMIT 1");
            $tStmt->execute([$idKelompok]);
            $team = $tStmt->fetch(PDO::FETCH_ASSOC);
            if (!$team) throw new Exception('Kelompok tidak ditemukan.');
            $p = $assertProyek($team['id_proyek']);

            // Hapus dari kelompok lain pada proyek ini jika sudah terdaftar
            $oldStmt = $pdo->prepare("
                SELECT pk.id FROM proyek_kelompok pk
                JOIN proyek_kelompok_anggota pka ON pk.id = pka.id_proyek_kelompok
                WHERE pk.id_proyek = ? AND pka.id_siswa = ? LIMIT 1
            ");
            $oldStmt->execute([(int)$p['id'], $idSiswa]);
            $oldKelId = (int)$oldStmt->fetchColumn();
            if ($oldKelId > 0) {
                $pdo->prepare("DELETE FROM proyek_kelompok_anggota WHERE id_proyek_kelompok = ? AND id_siswa = ?")->execute([$oldKelId, $idSiswa]);
                $pdo->prepare("UPDATE proyek_kelompok SET id_ketua_kelompok = NULL WHERE id = ? AND id_ketua_kelompok = ?")->execute([$oldKelId, $idSiswa]);
            }

            $pdo->prepare("
                INSERT INTO proyek_kelompok_anggota (id_proyek_kelompok, id_siswa, id_kelas_mata_pelajaran, peran_anggota)
                VALUES (?, ?, ?, ?)
            ")->execute([$idKelompok, $idSiswa, (int)$p['id_kelas_mata_pelajaran'], $peran]);

            if ($peran === 'Ketua Tim' && empty($team['id_ketua_kelompok'])) {
                $pdo->prepare("UPDATE proyek_kelompok SET id_ketua_kelompok = ? WHERE id = ?")->execute([$idSiswa, $idKelompok]);
            }

            echo json_encode(['success' => true, 'message' => 'Siswa berhasil ditambahkan ke kelompok!']);
            exit;
        }

        /* 8b. TENTUKAN / GANTI KETUA KELOMPOK (IDENTIK TUGAS.PHP) */
        if ($action === 'set_ketua_kelompok') {
            $idKelompok = (int)($_POST['id_kelompok'] ?? 0);
            $idKetua = (int)($_POST['id_ketua'] ?? 0);
            if ($idKelompok <= 0 || $idKetua <= 0) throw new Exception('Data kelompok atau calon ketua tidak valid.');

            $tStmt = $pdo->prepare("SELECT * FROM proyek_kelompok WHERE id = ? LIMIT 1");
            $tStmt->execute([$idKelompok]);
            $team = $tStmt->fetch(PDO::FETCH_ASSOC);
            if (!$team) throw new Exception('Kelompok tidak ditemukan.');
            $p = $assertProyek($team['id_proyek']);

            $cek = $pdo->prepare("SELECT id FROM proyek_kelompok_anggota WHERE id_proyek_kelompok = ? AND id_siswa = ? LIMIT 1");
            $cek->execute([$idKelompok, $idKetua]);
            if (!$cek->fetchColumn()) {
                $pdo->prepare("INSERT INTO proyek_kelompok_anggota (id_proyek_kelompok, id_siswa, id_kelas_mata_pelajaran, peran_anggota) VALUES (?, ?, ?, 'Ketua Tim')")
                    ->execute([$idKelompok, $idKetua, (int)$p['id_kelas_mata_pelajaran']]);
            } else {
                $pdo->prepare("UPDATE proyek_kelompok_anggota SET peran_anggota = 'Anggota' WHERE id_proyek_kelompok = ? AND peran_anggota = 'Ketua Tim'")->execute([$idKelompok]);
                $pdo->prepare("UPDATE proyek_kelompok_anggota SET peran_anggota = 'Ketua Tim' WHERE id_proyek_kelompok = ? AND id_siswa = ?")->execute([$idKelompok, $idKetua]);
            }

            $pdo->prepare("UPDATE proyek_kelompok SET id_ketua_kelompok = ? WHERE id = ?")->execute([$idKetua, $idKelompok]);
            echo json_encode(['success' => true, 'message' => 'Alhamdulillah! Ketua kelompok berhasil ditentukan.']);
            exit;
        }

        /* 8c. PINDAHKAN ANGGOTA KE KELOMPOK LAIN (IDENTIK TUGAS.PHP) */
        if ($action === 'move_anggota_kelompok') {
            $idSiswa = (int)($_POST['id_siswa'] ?? 0);
            $idTujuan = (int)($_POST['id_kelompok_tujuan'] ?? 0);
            $idProyek = (int)($_POST['id_proyek'] ?? 0);
            if ($idSiswa <= 0 || $idTujuan <= 0 || $idProyek <= 0) throw new Exception('Data pemindahan anggota tidak lengkap.');
            $p = $assertProyek($idProyek);

            $tStmt = $pdo->prepare("SELECT id FROM proyek_kelompok WHERE id = ? AND id_proyek = ? LIMIT 1");
            $tStmt->execute([$idTujuan, (int)$p['id']]);
            if (!$tStmt->fetchColumn()) throw new Exception('Kelompok tujuan tidak valid.');

            $oldStmt = $pdo->prepare("
                SELECT pk.id FROM proyek_kelompok pk
                JOIN proyek_kelompok_anggota pka ON pk.id = pka.id_proyek_kelompok
                WHERE pk.id_proyek = ? AND pka.id_siswa = ? LIMIT 1
            ");
            $oldStmt->execute([(int)$p['id'], $idSiswa]);
            $oldKelId = (int)$oldStmt->fetchColumn();
            if ($oldKelId > 0) {
                $pdo->prepare("DELETE FROM proyek_kelompok_anggota WHERE id_proyek_kelompok = ? AND id_siswa = ?")->execute([$oldKelId, $idSiswa]);
                $pdo->prepare("UPDATE proyek_kelompok SET id_ketua_kelompok = NULL WHERE id = ? AND id_ketua_kelompok = ?")->execute([$oldKelId, $idSiswa]);
            }

            $pdo->prepare("INSERT INTO proyek_kelompok_anggota (id_proyek_kelompok, id_siswa, id_kelas_mata_pelajaran, peran_anggota) VALUES (?, ?, ?, 'Anggota')")
                ->execute([$idTujuan, $idSiswa, (int)$p['id_kelas_mata_pelajaran']]);

            echo json_encode(['success' => true, 'message' => 'Siswa berhasil dipindahkan ke kelompok baru!']);
            exit;
        }

        /* 8d. UBAH NAMA KELOMPOK (IDENTIK TUGAS.PHP) */
        if ($action === 'rename_kelompok') {
            $idKelompok = (int)($_POST['id_kelompok'] ?? 0);
            $namaBaru = trim((string)($_POST['nama_kelompok'] ?? ''));
            if ($idKelompok <= 0 || $namaBaru === '') throw new Exception('Nama kelompok tidak boleh kosong.');

            $tStmt = $pdo->prepare("SELECT id_proyek FROM proyek_kelompok WHERE id = ? LIMIT 1");
            $tStmt->execute([$idKelompok]);
            $pid = (int)$tStmt->fetchColumn();
            if ($pid <= 0) throw new Exception('Kelompok tidak ditemukan.');
            $assertProyek($pid);

            $pdo->prepare("UPDATE proyek_kelompok SET nama_kelompok = ? WHERE id = ?")->execute([$namaBaru, $idKelompok]);
            echo json_encode(['success' => true, 'message' => 'Nama kelompok berhasil diperbarui!']);
            exit;
        }

        /* 8e. RESET SEMUA KELOMPOK PROYEK (IDENTIK TUGAS.PHP) */
        if ($action === 'reset_semua_kelompok') {
            $p = $assertProyek($_POST['id_proyek'] ?? 0);
            $pdo->prepare("DELETE FROM proyek_kelompok_anggota WHERE id_proyek_kelompok IN (SELECT id FROM proyek_kelompok WHERE id_proyek = ?)")->execute([(int)$p['id']]);
            $pdo->prepare("DELETE FROM proyek_kelompok WHERE id_proyek = ?")->execute([(int)$p['id']]);
            echo json_encode(['success' => true, 'message' => 'Seluruh kelompok pada proyek ini berhasil di-reset.']);
            exit;
        }

        /* 9. REMOVE ANGGOTA (MENDUKUNG BY id ATAU id_siswa + id_kelompok) */
        if ($action === 'remove_anggota') {
            $id = (int)($_POST['id'] ?? 0);
            $idSiswa = (int)($_POST['id_siswa'] ?? 0);
            $idKelompok = (int)($_POST['id_kelompok'] ?? 0);

            if ($id > 0) {
                $aStmt = $pdo->prepare("SELECT pka.*, pk.id_proyek FROM proyek_kelompok_anggota pka JOIN proyek_kelompok pk ON pka.id_proyek_kelompok = pk.id WHERE pka.id = ? LIMIT 1");
                $aStmt->execute([$id]);
                $row = $aStmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) throw new Exception('Data anggota tidak ditemukan.');
                $assertProyek($row['id_proyek']);

                $pdo->prepare("DELETE FROM proyek_kelompok_anggota WHERE id = ?")->execute([$id]);
                $pdo->prepare("UPDATE proyek_kelompok SET id_ketua_kelompok = NULL WHERE id = ? AND id_ketua_kelompok = ?")->execute([(int)$row['id_proyek_kelompok'], (int)$row['id_siswa']]);
            } elseif ($idSiswa > 0 && $idKelompok > 0) {
                $tStmt = $pdo->prepare("SELECT id_proyek FROM proyek_kelompok WHERE id = ? LIMIT 1");
                $tStmt->execute([$idKelompok]);
                $pid = (int)$tStmt->fetchColumn();
                if ($pid <= 0) throw new Exception('Kelompok tidak ditemukan.');
                $assertProyek($pid);

                $pdo->prepare("DELETE FROM proyek_kelompok_anggota WHERE id_proyek_kelompok = ? AND id_siswa = ?")->execute([$idKelompok, $idSiswa]);
                $pdo->prepare("UPDATE proyek_kelompok SET id_ketua_kelompok = NULL WHERE id = ? AND id_ketua_kelompok = ?")->execute([$idKelompok, $idSiswa]);
            } else {
                throw new Exception('Parameter penghapusan anggota tidak valid.');
            }
            echo json_encode(['success' => true, 'message' => 'Siswa dikeluarkan dari kelompok.']);
            exit;
        }

        /* 10. DELETE KELOMPOK */
        if ($action === 'delete_kelompok') {
            $id = (int)($_POST['id'] ?? $_POST['id_kelompok'] ?? 0);
            $tStmt = $pdo->prepare("SELECT id, id_proyek FROM proyek_kelompok WHERE id = ? LIMIT 1");
            $tStmt->execute([$id]);
            $team = $tStmt->fetch(PDO::FETCH_ASSOC);
            if (!$team) throw new Exception('Kelompok tidak ditemukan.');
            $assertProyek($team['id_proyek']);

            $pdo->prepare("DELETE FROM proyek_kelompok_anggota WHERE id_proyek_kelompok = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM proyek_kelompok WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Kelompok berhasil dihapus.']);
            exit;
        }

        /* 11. AUTO-GENERATE KELOMPOK ACAK MERATA (100% IDENTIK TUGAS.PHP — NAMA TOKOH SAHABAT NABI) */
        if ($action === 'auto_generate_kelompok') {
            $p = $assertProyek($_POST['id_proyek'] ?? 0);
            $jml = max(2, min(15, (int)($_POST['jumlah_kelompok'] ?? 4)));
            $students = pryGetStudentsByKelas($pdo, (int)$p['id_kelas']);
            if (empty($students)) {
                throw new Exception('Tidak ada siswa di kelas ' . $p['nama_kelas'] . '.');
            }
            if ($jml > count($students)) $jml = count($students);

            shuffle($students);

            $namaTokoh = [
                'Abu Bakar Ash-Shiddiq',
                'Umar bin Khattab',
                'Utsman bin Affan',
                'Ali bin Abi Thalib',
                'Thalhah bin Ubaidillah',
                'Zubair bin Awwam',
                'Abdurrahman bin Auf',
                "Sa'ad bin Abi Waqqash",
                'Said bin Zaid',
                'Abu Ubaidah bin al-Jarrah',
                'Salman Al-Farisi',
                'Bilal bin Rabah',
                'Khalid bin Walid',
                'Abdullah bin Mas\'ud',
                'Mu\'adz bin Jabal',
            ];

            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM proyek_kelompok_anggota WHERE id_proyek_kelompok IN (SELECT id FROM proyek_kelompok WHERE id_proyek = ?)")->execute([(int)$p['id']]);
                $pdo->prepare("DELETE FROM proyek_kelompok WHERE id_proyek = ?")->execute([(int)$p['id']]);

                $insTeam = $pdo->prepare("
                    INSERT INTO proyek_kelompok (id_proyek, id_kelas_mata_pelajaran, nomor_kelompok, nama_kelompok, id_ketua_kelompok)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $insMem = $pdo->prepare("
                    INSERT INTO proyek_kelompok_anggota (id_proyek_kelompok, id_siswa, id_kelas_mata_pelajaran, peran_anggota)
                    VALUES (?, ?, ?, ?)
                ");

                $buckets = array_fill(0, $jml, []);
                foreach ($students as $i => $s) {
                    $buckets[$i % $jml][] = $s;
                }

                $roles = ['Ketua Tim', 'Sekretaris', 'Bendahara', 'Peneliti', 'Desainer / Editor', 'Presenter', 'Anggota'];
                foreach ($buckets as $idx => $members) {
                    if (empty($members)) continue;
                    $no = $idx + 1;
                    $tokoh = $namaTokoh[$idx % count($namaTokoh)];
                    $namaKel = "Kelompok {$no} - {$tokoh}";
                    $ketuaId = (int)$members[0]['id'];
                    $insTeam->execute([(int)$p['id'], (int)$p['id_kelas_mata_pelajaran'], $no, $namaKel, $ketuaId]);
                    $teamId = (int)$pdo->lastInsertId();
                    foreach ($members as $mIdx => $m) {
                        $roleName = $roles[$mIdx] ?? 'Anggota';
                        $insMem->execute([$teamId, (int)$m['id'], (int)$p['id_kelas_mata_pelajaran'], $roleName]);
                    }
                }
                $pdo->commit();
            } catch (Exception $eTx) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $eTx;
            }

            echo json_encode(['success' => true, 'message' => "Alhamdulillah! Berhasil membentuk {$jml} kelompok secara merata untuk " . count($students) . " siswa."]);
            exit;
        }

        /* 12. LIST PENGUMPULAN KARYA (DENGAN RINCIAN NILAI KELOMPOK & INDIVIDU SETARA TUGAS) */
        if ($action === 'list_submissions') {
            $p = $assertProyek($_POST['id_proyek'] ?? 0);
            $stmt = $pdo->prepare("
                SELECT pp.*,
                       s.nama_lengkap AS nama_siswa, s.nis, s.nisn,
                       pk.nama_kelompok, pk.nomor_kelompok,
                       (SELECT COUNT(*) FROM nilai_proyek np WHERE np.id_pengumpulan_proyek = pp.id) AS jumlah_anggota_dinilai,
                       (SELECT ROUND(AVG(np.nilai), 2) FROM nilai_proyek np WHERE np.id_pengumpulan_proyek = pp.id) AS rata_nilai_anggota,
                       (SELECT np.nilai_kelompok FROM nilai_proyek np WHERE np.id_pengumpulan_proyek = pp.id AND np.nilai_kelompok IS NOT NULL LIMIT 1) AS nilai_kelompok_saved,
                       (SELECT np.status_penilaian FROM nilai_proyek np WHERE np.id_pengumpulan_proyek = pp.id ORDER BY np.updated_at DESC LIMIT 1) AS status_penilaian_saved,
                       (SELECT np.predikat FROM nilai_proyek np WHERE np.id_pengumpulan_proyek = pp.id ORDER BY np.updated_at DESC LIMIT 1) AS predikat_huruf_saved
                FROM pengumpulan_proyek pp
                JOIN siswa s ON pp.id_siswa = s.id
                LEFT JOIN proyek_kelompok pk ON pp.id_proyek_kelompok = pk.id
                WHERE pp.id_proyek = ?
                ORDER BY pp.waktu_pengumpulan DESC, pp.id DESC
            ");
            $stmt->execute([(int)$p['id']]);
            $tStmt = $pdo->prepare("SELECT id, nomor_kelompok, nama_kelompok FROM proyek_kelompok WHERE id_proyek = ? ORDER BY nomor_kelompok ASC");
            $tStmt->execute([(int)$p['id']]);

            echo json_encode([
                'success' => true,
                'data' => [
                    'proyek'   => $p,
                    'items'    => $stmt->fetchAll(PDO::FETCH_ASSOC),
                    'kelompok' => $tStmt->fetchAll(PDO::FETCH_ASSOC),
                    'students' => pryGetStudentsByKelas($pdo, (int)$p['id_kelas']),
                ],
            ]);
            exit;
        }

        /* 12b. DETAIL PENGUMPULAN + DAFTAR ANGGOTA KELOMPOK & NILAI INDIVIDU (IDENTIK TUGAS.PHP) */
        if ($action === 'get_submission_detail') {
            $idSub = (int)($_POST['id_pengumpulan'] ?? 0);
            $stmt = $pdo->prepare("
                SELECT pp.*,
                       s.nama_lengkap AS nama_siswa, s.nis, s.nisn, s.foto AS foto_siswa,
                       p.nama_proyek, p.jenis_proyek, p.nilai_maksimal, p.jadwal_selesai,
                       k.nama_kelas, mp.nama_mata_pelajaran,
                       pk.nama_kelompok, pk.nomor_kelompok, pk.id_ketua_kelompok
                FROM pengumpulan_proyek pp
                JOIN proyek p ON pp.id_proyek = p.id
                JOIN kelas_mata_pelajaran kmp ON p.id_kelas_mata_pelajaran = kmp.id
                JOIN kelas k ON kmp.id_kelas = k.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN siswa s ON pp.id_siswa = s.id
                LEFT JOIN proyek_kelompok pk ON pp.id_proyek_kelompok = pk.id
                WHERE pp.id = ?
                LIMIT 1
            ");
            $stmt->execute([$idSub]);
            $sub = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sub) throw new Exception('Data pengumpulan karya tidak ditemukan.');
            $assertProyek($sub['id_proyek']);

            // Ambil nilai siswa utama dari nilai_proyek
            $nStmt = $pdo->prepare("SELECT * FROM nilai_proyek WHERE id_proyek = ? AND id_siswa = ? LIMIT 1");
            $nStmt->execute([(int)$sub['id_proyek'], (int)$sub['id_siswa']]);
            $nRow = $nStmt->fetch(PDO::FETCH_ASSOC);

            $sub['nilai'] = $nRow ? $nRow['nilai'] : $sub['nilai_proyek'];
            $sub['nilai_individu'] = $nRow ? $nRow['nilai_individu'] : $sub['nilai_proyek'];
            $sub['nilai_kelompok'] = $nRow ? $nRow['nilai_kelompok'] : null;
            $sub['nilai_bagi_kelompok'] = $nRow ? $nRow['nilai_bagi_kelompok'] : null;
            $sub['nilai_remedial'] = $nRow ? $nRow['nilai_remedial'] : null;
            $sub['predikat'] = $nRow ? $nRow['predikat'] : ($sub['nilai_proyek'] !== null ? pryHitungPredikatHuruf($sub['nilai_proyek']) : null);
            $sub['predikat_p5'] = $nRow ? $nRow['predikat_p5'] : $sub['predikat_proyek'];
            $sub['aspek_itp_ketercapaian'] = $nRow ? $nRow['aspek_itp_ketercapaian'] : 'Tercapai';
            $sub['catatan_guru'] = $nRow ? $nRow['catatan_guru'] : ($sub['catatan_evaluasi_guru'] ?? '');
            $sub['status_penilaian'] = $nRow ? $nRow['status_penilaian'] : ($sub['status_pengumpulan'] === 'Revisi' ? 'Perlu Revisi' : ($sub['nilai_proyek'] !== null ? 'Dinilai' : 'Dinilai'));

            // Jika proyek kelompok, ambil seluruh anggota kelompok beserta nilai_proyek masing-masing
            $sub['anggota_kelompok'] = [];
            if (!empty($sub['id_proyek_kelompok'])) {
                $stmtAng = $pdo->prepare("
                    SELECT s.id, s.nama_lengkap, s.nisn, s.nis, s.foto,
                           pka.peran_anggota,
                           np.id AS id_nilai, np.nilai, np.nilai_individu, np.nilai_kelompok,
                           np.nilai_bagi_kelompok, np.predikat, np.predikat_p5
                    FROM proyek_kelompok_anggota pka
                    JOIN siswa s ON pka.id_siswa = s.id
                    LEFT JOIN nilai_proyek np ON np.id_proyek = ? AND np.id_siswa = s.id
                    WHERE pka.id_proyek_kelompok = ?
                    ORDER BY (s.id = ?) DESC, s.nama_lengkap ASC
                ");
                $stmtAng->execute([(int)$sub['id_proyek'], (int)$sub['id_proyek_kelompok'], (int)($sub['id_ketua_kelompok'] ?? 0)]);
                $sub['anggota_kelompok'] = $stmtAng->fetchAll(PDO::FETCH_ASSOC);

                foreach ($sub['anggota_kelompok'] as $ak) {
                    if ($ak['nilai_kelompok'] !== null) {
                        $sub['nilai_kelompok'] = $ak['nilai_kelompok'];
                        $sub['nilai_bagi_kelompok'] = $ak['nilai_bagi_kelompok'];
                        break;
                    }
                }
            }

            echo json_encode(['success' => true, 'data' => $sub]);
            exit;
        }

        /* 13. CATAT KARYA MANUAL OLEH GURU */
        if ($action === 'save_manual_submission') {
            $p = $assertProyek($_POST['id_proyek'] ?? 0);
            $idSiswa = (int)($_POST['id_siswa'] ?? 0);
            $idKelompok = (int)($_POST['id_proyek_kelompok'] ?? 0) ?: null;
            $judul = trim((string)($_POST['judul_laporan_produk'] ?? ''));
            $desk = trim((string)($_POST['deskripsi_produk'] ?? ''));
            $linkProduk = trim((string)($_POST['link_produk'] ?? '')) ?: null;
            $linkVideo = trim((string)($_POST['link_video_presentasi'] ?? '')) ?: null;

            if ($idSiswa <= 0 || $judul === '') {
                throw new Exception('Siswa pengumpul dan judul karya wajib diisi.');
            }
            if ($desk === '') $desk = 'Karya proyek ' . $judul . ' diserahkan kepada guru pembimbing.';

            $hasFile = !empty($_FILES['file_laporan']) && ($_FILES['file_laporan']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
            $origName = $hasFile ? basename((string)$_FILES['file_laporan']['name']) : null;
            $sizeKb = $hasFile ? (int)ceil(((int)$_FILES['file_laporan']['size']) / 1024) : null;
            $fileLaporan = pryUploadFile($_FILES['file_laporan'] ?? null, 'proyek_karya', ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'zip', 'jpg', 'jpeg', 'png'], 15);
            $tipePeng = ($hasFile && ($linkProduk || $linkVideo)) ? 'File dan Link' : ($hasFile ? 'File Upload' : (($linkProduk || $linkVideo) ? 'Link URL' : 'Teks / Essay'));

            $ins = $pdo->prepare("
                INSERT INTO pengumpulan_proyek (
                    id_proyek, id_siswa, id_kelas_mata_pelajaran, id_proyek_kelompok,
                    tipe_pengumpulan, judul_laporan_produk, deskripsi_produk, file_laporan, file_nama_asli, file_ukuran_kb,
                    link_produk, link_video_presentasi, status_pengumpulan
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Diserahkan')
            ");
            $ins->execute([
                (int)$p['id'], $idSiswa, (int)$p['id_kelas_mata_pelajaran'], $idKelompok,
                $tipePeng, $judul, $desk, $fileLaporan, $origName, $sizeKb, $linkProduk, $linkVideo
            ]);

            echo json_encode(['success' => true, 'message' => 'Pengumpulan karya siswa berhasil dicatat.']);
            exit;
        }

        /* 14. PENILAIAN KARYA PROYEK (100% IDENTIK SISTEM PENILAIAN KELOMPOK & INDIVIDU TUGAS.PHP) */
        if ($action === 'grade_submission') {
            $idSub = (int)($_POST['id'] ?? $_POST['id_pengumpulan'] ?? 0);
            $stmt = $pdo->prepare("
                SELECT pp.*, p.jenis_proyek, p.nilai_maksimal
                FROM pengumpulan_proyek pp
                JOIN proyek p ON pp.id_proyek = p.id
                WHERE pp.id = ? LIMIT 1
            ");
            $stmt->execute([$idSub]);
            $sub = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sub) throw new Exception('Data pengumpulan karya tidak ditemukan.');
            $assertProyek($sub['id_proyek']);

            $idProyek  = (int)$sub['id_proyek'];
            $idKmp     = (int)$sub['id_kelas_mata_pelajaran'];
            $idKel     = !empty($sub['id_proyek_kelompok']) ? (int)$sub['id_proyek_kelompok'] : null;
            $nilaiMax  = (float)($sub['nilai_maksimal'] ?: 100);
            $idGuruVal = $idUserLogged ?: null;

            $catatan     = trim((string)($_POST['catatan_evaluasi_guru'] ?? $_POST['catatan_guru'] ?? ''));
            $aspekItp    = in_array(($_POST['aspek_itp'] ?? ''), ['Tercapai Sangat Baik', 'Tercapai', 'Cukup Tercapai', 'Perlu Bimbingan'], true) ? $_POST['aspek_itp'] : 'Tercapai';
            $statusNilai = in_array(($_POST['status_penilaian'] ?? ''), ['Dinilai', 'Perlu Revisi', 'Tuntas'], true) ? $_POST['status_penilaian'] : 'Dinilai';
            $validSubStat = ['Diserahkan', 'Revisi', 'Disetujui', 'Dipublikasikan'];
            $subStatus   = in_array(($_POST['status_pengumpulan'] ?? ''), $validSubStat, true)
                ? $_POST['status_pengumpulan']
                : ($statusNilai === 'Perlu Revisi' ? 'Revisi' : 'Disetujui');
            if ($statusNilai === 'Perlu Revisi') {
                $subStatus = 'Revisi';
            }

            $isKelompok = ($sub['jenis_proyek'] === 'Kelompok' && !empty($idKel));

            if ($isKelompok) {
                // ==============================================================
                // PENILAIAN PROYEK KELOMPOK — IDENTIK 100% DENGAN TUGAS.PHP:
                // 1. Nilai Kelompok (Karya Bersama): Skala 0-100 untuk seluruh anggota
                // 2. Nilai Individu (Kontribusi Pribadi): Dinilai spesifik per siswa
                // 3. Nilai Akhir Siswa = (Nilai Kelompok + Nilai Individu) / 2
                // ==============================================================
                $nilaiKelompok = isset($_POST['nilai_kelompok']) ? (float)$_POST['nilai_kelompok'] : (float)($_POST['nilai_proyek'] ?? 0);
                $nilaiKelompok = min($nilaiMax, max(0, $nilaiKelompok));

                $stmtAng = $pdo->prepare("SELECT id_siswa FROM proyek_kelompok_anggota WHERE id_proyek_kelompok = ?");
                $stmtAng->execute([$idKel]);
                $dbAnggotaIds = array_map('intval', $stmtAng->fetchAll(PDO::FETCH_COLUMN));
                if (!empty($sub['id_siswa']) && !in_array((int)$sub['id_siswa'], $dbAnggotaIds, true)) {
                    $dbAnggotaIds[] = (int)$sub['id_siswa'];
                }
                $totalAnggota = count($dbAnggotaIds) ?: 1;
                $nilaiBagiKelompok = $nilaiKelompok;

                $anggotaScoresRaw = $_POST['anggota_scores'] ?? '';
                $scoreMap = [];
                if (is_string($anggotaScoresRaw) && $anggotaScoresRaw !== '') {
                    $decoded = json_decode($anggotaScoresRaw, true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $as) {
                            $sid = (int)($as['id_siswa'] ?? 0);
                            if ($sid > 0) $scoreMap[$sid] = (float)($as['nilai_individu'] ?? 0);
                        }
                    }
                } elseif (is_array($anggotaScoresRaw)) {
                    foreach ($anggotaScoresRaw as $sid => $val) {
                        $scoreMap[(int)$sid] = (float)$val;
                    }
                }

                $sumAkhir = 0.0;
                foreach ($dbAnggotaIds as $targetSid) {
                    $nilaiIndividu = isset($scoreMap[$targetSid]) ? (float)$scoreMap[$targetSid] : $nilaiKelompok;
                    $nilaiIndividu = min($nilaiMax, max(0, $nilaiIndividu));
                    $nilaiAkhir = round(($nilaiKelompok + $nilaiIndividu) / 2, 2);
                    $nilaiAkhir = min($nilaiMax, max(0, $nilaiAkhir));
                    $sumAkhir += $nilaiAkhir;

                    $predHuruf = pryHitungPredikatHuruf($nilaiAkhir);
                    $predP5    = pryHitungPredikatP5($nilaiAkhir);

                    $chk = $pdo->prepare("SELECT id FROM nilai_proyek WHERE id_proyek = ? AND id_siswa = ? LIMIT 1");
                    $chk->execute([$idProyek, $targetSid]);
                    $exId = $chk->fetchColumn();

                    if ($exId) {
                        $pdo->prepare("
                            UPDATE nilai_proyek SET
                                id_pengumpulan_proyek = ?, id_guru = ?, nilai = ?,
                                nilai_kelompok = ?, nilai_bagi_kelompok = ?, nilai_individu = ?, jumlah_anggota_kelompok = ?,
                                predikat = ?, predikat_p5 = ?, aspek_itp_ketercapaian = ?,
                                catatan_guru = ?, status_penilaian = ?, waktu_penilaian = NOW(), updated_at = NOW()
                            WHERE id = ?
                        ")->execute([
                            $idSub, $idGuruVal, $nilaiAkhir,
                            $nilaiKelompok, $nilaiBagiKelompok, $nilaiIndividu, $totalAnggota,
                            $predHuruf, $predP5, $aspekItp,
                            $catatan, $statusNilai, $exId
                        ]);
                    } else {
                        $pdo->prepare("
                            INSERT INTO nilai_proyek (
                                id_proyek, id_pengumpulan_proyek, id_siswa, id_kelas_mata_pelajaran, id_guru,
                                nilai, nilai_kelompok, nilai_bagi_kelompok, nilai_individu, jumlah_anggota_kelompok,
                                predikat, predikat_p5, aspek_itp_ketercapaian, catatan_guru, status_penilaian, waktu_penilaian
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ")->execute([
                            $idProyek, $idSub, $targetSid, $idKmp, $idGuruVal,
                            $nilaiAkhir, $nilaiKelompok, $nilaiBagiKelompok, $nilaiIndividu, $totalAnggota,
                            $predHuruf, $predP5, $aspekItp, $catatan, $statusNilai
                        ]);
                    }
                }

                $avgGroupFinal = round($sumAkhir / $totalAnggota, 2);
                $avgPredP5 = pryHitungPredikatP5($avgGroupFinal);

                $pdo->prepare("
                    UPDATE pengumpulan_proyek
                    SET nilai_proyek = ?, predikat_proyek = ?, status_pengumpulan = ?,
                        catatan_evaluasi_guru = ?, waktu_penilaian = NOW(), updated_at = NOW()
                    WHERE id = ?
                ")->execute([$avgGroupFinal, $avgPredP5, $subStatus, $catatan, $idSub]);

                echo json_encode([
                    'success' => true,
                    'message' => "Alhamdulillah! Nilai proyek kelompok tersimpan untuk {$totalAnggota} siswa (Nilai Kelompok: {$nilaiKelompok}, Rumus Seimbang (Kelompok + Individu) / 2).",
                ]);
                exit;
            } else {
                // ==============================================================
                // PENILAIAN PROYEK INDIVIDU (IDENTIK TUGAS.PHP)
                // ==============================================================
                $nilai = (float)($_POST['nilai_proyek'] ?? $_POST['nilai'] ?? 0);
                $nilai = min($nilaiMax, max(0, $nilai));
                $nilaiRemedial = (isset($_POST['nilai_remedial']) && $_POST['nilai_remedial'] !== '') ? min($nilaiMax, max(0, (float)$_POST['nilai_remedial'])) : null;
                $effectiveScore = ($nilaiRemedial !== null && $nilaiRemedial > $nilai) ? $nilaiRemedial : $nilai;
                $predHuruf = pryHitungPredikatHuruf($effectiveScore);
                $predP5    = in_array(($_POST['predikat_proyek'] ?? ''), ['Sangat Berkembang (SB)', 'Berkembang Sesuai Harapan (BSH)', 'Mulai Berkembang (MB)', 'Belum Berkembang (BB)'], true)
                    ? $_POST['predikat_proyek']
                    : pryHitungPredikatP5($effectiveScore);
                $targetSid = (int)$sub['id_siswa'];

                $chk = $pdo->prepare("SELECT id FROM nilai_proyek WHERE id_proyek = ? AND id_siswa = ? LIMIT 1");
                $chk->execute([$idProyek, $targetSid]);
                $exId = $chk->fetchColumn();

                if ($exId) {
                    $pdo->prepare("
                        UPDATE nilai_proyek SET
                            id_pengumpulan_proyek = ?, id_guru = ?, nilai = ?, nilai_individu = ?,
                            nilai_remedial = ?, nilai_akhir_remedial = ?,
                            predikat = ?, predikat_p5 = ?, aspek_itp_ketercapaian = ?,
                            catatan_guru = ?, status_penilaian = ?, waktu_penilaian = NOW(), updated_at = NOW()
                        WHERE id = ?
                    ")->execute([
                        $idSub, $idGuruVal, $nilai, $nilai,
                        $nilaiRemedial, ($nilaiRemedial !== null ? max($nilai, $nilaiRemedial) : null),
                        $predHuruf, $predP5, $aspekItp,
                        $catatan, $statusNilai, $exId
                    ]);
                } else {
                    $pdo->prepare("
                        INSERT INTO nilai_proyek (
                            id_proyek, id_pengumpulan_proyek, id_siswa, id_kelas_mata_pelajaran, id_guru,
                            nilai, nilai_individu, nilai_remedial, nilai_akhir_remedial,
                            predikat, predikat_p5, aspek_itp_ketercapaian, catatan_guru, status_penilaian, waktu_penilaian
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ")->execute([
                        $idProyek, $idSub, $targetSid, $idKmp, $idGuruVal,
                        $nilai, $nilai, $nilaiRemedial, ($nilaiRemedial !== null ? max($nilai, $nilaiRemedial) : null),
                        $predHuruf, $predP5, $aspekItp, $catatan, $statusNilai
                    ]);
                }

                $pdo->prepare("
                    UPDATE pengumpulan_proyek
                    SET nilai_proyek = ?, predikat_proyek = ?, status_pengumpulan = ?,
                        catatan_evaluasi_guru = ?, waktu_penilaian = NOW(), updated_at = NOW()
                    WHERE id = ?
                ")->execute([$effectiveScore, $predP5, $subStatus, $catatan, $idSub]);

                echo json_encode(['success' => true, 'message' => 'Alhamdulillah! Penilaian proyek individu & predikat P5 berhasil disimpan.']);
                exit;
            }
        }

        /* 14b. PENILAIAN MASSAL PROYEK (BULK GRADE — IDENTIK TUGAS.PHP) */
        if ($action === 'bulk_grade_submissions') {
            $ids = $_POST['ids'] ?? [];
            if (is_string($ids)) $ids = json_decode($ids, true) ?: explode(',', $ids);
            if (empty($ids) || !is_array($ids)) throw new Exception('Pilih minimal satu pengumpulan proyek.');

            $scoreVal = max(0, min(100, (float)($_POST['nilai'] ?? 100)));
            $catatan = trim((string)($_POST['catatan'] ?? ''));
            $statusNilai = in_array(($_POST['status_penilaian'] ?? ''), ['Dinilai', 'Tuntas', 'Perlu Revisi'], true) ? $_POST['status_penilaian'] : 'Tuntas';
            $subStatus = ($statusNilai === 'Perlu Revisi') ? 'Revisi' : 'Disetujui';
            $idGuruVal = $idUserLogged ?: null;
            $count = 0;

            foreach ($ids as $sidRaw) {
                $subId = (int)$sidRaw;
                if ($subId <= 0) continue;
                $st = $pdo->prepare("SELECT pp.*, p.nilai_maksimal, p.jenis_proyek FROM pengumpulan_proyek pp JOIN proyek p ON pp.id_proyek = p.id WHERE pp.id = ? LIMIT 1");
                $st->execute([$subId]);
                $subRow = $st->fetch(PDO::FETCH_ASSOC);
                if (!$subRow) continue;
                try { $assertProyek($subRow['id_proyek']); } catch (Exception $eA) { continue; }

                $eff = min((float)($subRow['nilai_maksimal'] ?: 100), $scoreVal);
                $predH = pryHitungPredikatHuruf($eff);
                $predP5 = pryHitungPredikatP5($eff);
                $idKel = !empty($subRow['id_proyek_kelompok']) ? (int)$subRow['id_proyek_kelompok'] : null;

                if ($idKel) {
                    $aSt = $pdo->prepare("SELECT id_siswa FROM proyek_kelompok_anggota WHERE id_proyek_kelompok = ?");
                    $aSt->execute([$idKel]);
                    $angIds = array_map('intval', $aSt->fetchAll(PDO::FETCH_COLUMN));
                    if (!in_array((int)$subRow['id_siswa'], $angIds, true)) $angIds[] = (int)$subRow['id_siswa'];
                    $totAng = count($angIds) ?: 1;
                    foreach ($angIds as $stId) {
                        $pdo->prepare("
                            INSERT INTO nilai_proyek (
                                id_proyek, id_pengumpulan_proyek, id_siswa, id_kelas_mata_pelajaran, id_guru,
                                nilai, nilai_kelompok, nilai_bagi_kelompok, nilai_individu, jumlah_anggota_kelompok,
                                predikat, predikat_p5, aspek_itp_ketercapaian, catatan_guru, status_penilaian, waktu_penilaian
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Tercapai', ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE
                                id_pengumpulan_proyek=VALUES(id_pengumpulan_proyek), id_guru=VALUES(id_guru),
                                nilai=VALUES(nilai), nilai_kelompok=VALUES(nilai_kelompok), nilai_bagi_kelompok=VALUES(nilai_bagi_kelompok),
                                nilai_individu=VALUES(nilai_individu), jumlah_anggota_kelompok=VALUES(jumlah_anggota_kelompok),
                                predikat=VALUES(predikat), predikat_p5=VALUES(predikat_p5), catatan_guru=VALUES(catatan_guru),
                                status_penilaian=VALUES(status_penilaian), waktu_penilaian=NOW(), updated_at=NOW()
                        ")->execute([(int)$subRow['id_proyek'], $subId, $stId, (int)$subRow['id_kelas_mata_pelajaran'], $idGuruVal, $eff, $eff, $eff, $eff, $totAng, $predH, $predP5, $catatan, $statusNilai]);
                    }
                } else {
                    $pdo->prepare("
                        INSERT INTO nilai_proyek (
                            id_proyek, id_pengumpulan_proyek, id_siswa, id_kelas_mata_pelajaran, id_guru,
                            nilai, nilai_individu, predikat, predikat_p5, aspek_itp_ketercapaian, catatan_guru, status_penilaian, waktu_penilaian
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Tercapai', ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE
                            id_pengumpulan_proyek=VALUES(id_pengumpulan_proyek), id_guru=VALUES(id_guru),
                            nilai=VALUES(nilai), nilai_individu=VALUES(nilai_individu),
                            predikat=VALUES(predikat), predikat_p5=VALUES(predikat_p5), catatan_guru=VALUES(catatan_guru),
                            status_penilaian=VALUES(status_penilaian), waktu_penilaian=NOW(), updated_at=NOW()
                    ")->execute([(int)$subRow['id_proyek'], $subId, (int)$subRow['id_siswa'], (int)$subRow['id_kelas_mata_pelajaran'], $idGuruVal, $eff, $eff, $predH, $predP5, $catatan, $statusNilai]);
                }

                $pdo->prepare("UPDATE pengumpulan_proyek SET nilai_proyek = ?, predikat_proyek = ?, status_pengumpulan = ?, catatan_evaluasi_guru = ?, waktu_penilaian = NOW() WHERE id = ?")
                    ->execute([$eff, $predP5, $subStatus, $catatan, $subId]);
                $count++;
            }

            echo json_encode(['success' => true, 'message' => "Alhamdulillah! Berhasil menilai massal {$count} pengumpulan proyek."]);
            exit;
        }

        /* 15. LIST ANGGARAN / BIAYA PROYEK (KALKULASI PER KELOMPOK) */
        if ($action === 'list_biaya') {
            $p = $assertProyek($_POST['id_proyek'] ?? 0);
            $pid = (int)$p['id'];

            $stmt = $pdo->prepare("
                SELECT bp.*, s.nama_lengkap AS nama_siswa, pk.nama_kelompok, pk.nomor_kelompok
                FROM biaya_proyek bp
                LEFT JOIN siswa s ON bp.id_siswa = s.id
                LEFT JOIN proyek_kelompok pk ON bp.id_proyek_kelompok = pk.id
                WHERE bp.id_proyek = ?
                ORDER BY bp.tanggal_pengeluaran DESC, bp.id DESC
            ");
            $stmt->execute([$pid]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Ambil semua kelompok proyek beserta jumlah anggota & nama ketua
            $tStmt = $pdo->prepare("
                SELECT pk.id, pk.nomor_kelompok, pk.nama_kelompok,
                       sK.nama_lengkap AS nama_ketua,
                       (SELECT COUNT(*) FROM proyek_kelompok_anggota pka WHERE pka.id_proyek_kelompok = pk.id) AS jumlah_anggota
                FROM proyek_kelompok pk
                LEFT JOIN siswa sK ON pk.id_ketua_kelompok = sK.id
                WHERE pk.id_proyek = ?
                ORDER BY pk.nomor_kelompok ASC, pk.id ASC
            ");
            $tStmt->execute([$pid]);
            $teams = $tStmt->fetchAll(PDO::FETCH_ASSOC);

            // Peta siswa -> kelompok pada proyek ini (auto-pilih kelompok saat input biaya)
            $mapStmt = $pdo->prepare("
                SELECT pka.id_siswa, pka.id_proyek_kelompok
                FROM proyek_kelompok_anggota pka
                JOIN proyek_kelompok pk ON pka.id_proyek_kelompok = pk.id
                WHERE pk.id_proyek = ?
            ");
            $mapStmt->execute([$pid]);
            $studentTeamMap = [];
            foreach ($mapStmt->fetchAll(PDO::FETCH_ASSOC) as $mr) {
                $studentTeamMap[(int)$mr['id_siswa']] = (int)$mr['id_proyek_kelompok'];
            }

            // Inisialisasi kalkulasi per kelompok
            $groupBuckets = [];
            foreach ($teams as $t) {
                $tid = (int)$t['id'];
                $groupBuckets[$tid] = [
                    'id_kelompok'       => $tid,
                    'nomor_kelompok'    => (int)$t['nomor_kelompok'],
                    'nama_kelompok'     => $t['nama_kelompok'],
                    'nama_ketua'        => $t['nama_ketua'] ?: '-',
                    'jumlah_anggota'    => max(0, (int)$t['jumlah_anggota']),
                    'jumlah_item'       => 0,
                    'total_verified'    => 0.0,
                    'total_pending'     => 0.0,
                    'total_rejected'    => 0.0,
                    'total_aktif'       => 0.0,
                    'biaya_per_anggota' => 0.0,
                ];
            }

            $unassignedBucket = [
                'id_kelompok'       => 0,
                'nomor_kelompok'    => 0,
                'nama_kelompok'     => 'Umum / Tanpa Kelompok',
                'nama_ketua'        => '-',
                'jumlah_anggota'    => 0,
                'jumlah_item'       => 0,
                'total_verified'    => 0.0,
                'total_pending'     => 0.0,
                'total_rejected'    => 0.0,
                'total_aktif'       => 0.0,
                'biaya_per_anggota' => 0.0,
            ];

            $verified = 0.0;
            $pending = 0.0;
            foreach ($rows as $r) {
                $amt = (float)$r['total_biaya'];
                $st = $r['status_verifikasi'];
                if ($st === 'Diverifikasi Guru') $verified += $amt;
                elseif ($st === 'Menunggu Verifikasi') $pending += $amt;

                $gid = (int)($r['id_proyek_kelompok'] ?? 0);
                if ($gid > 0 && isset($groupBuckets[$gid])) {
                    $b = &$groupBuckets[$gid];
                } else {
                    $b = &$unassignedBucket;
                }
                $b['jumlah_item']++;
                if ($st === 'Diverifikasi Guru') $b['total_verified'] += $amt;
                elseif ($st === 'Menunggu Verifikasi') $b['total_pending'] += $amt;
                else $b['total_rejected'] += $amt;
                unset($b);
            }

            $groupSummary = [];
            foreach ($groupBuckets as $gb) {
                $gb['total_aktif'] = round($gb['total_verified'] + $gb['total_pending'], 2);
                $gb['biaya_per_anggota'] = ($gb['jumlah_anggota'] > 0)
                    ? round($gb['total_aktif'] / $gb['jumlah_anggota'], 0)
                    : $gb['total_aktif'];
                $groupSummary[] = $gb;
            }
            if ($unassignedBucket['jumlah_item'] > 0) {
                $unassignedBucket['total_aktif'] = round($unassignedBucket['total_verified'] + $unassignedBucket['total_pending'], 2);
                $groupSummary[] = $unassignedBucket;
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'items'            => $rows,
                    'summary'          => ['verified' => $verified, 'pending' => $pending, 'total_aktif' => $verified + $pending],
                    'group_summary'    => $groupSummary,
                    'student_team_map' => $studentTeamMap,
                    'kelompok'         => $teams,
                    'students'         => pryGetStudentsByKelas($pdo, (int)$p['id_kelas']),
                ],
            ]);
            exit;
        }

        /* 16. SAVE BIAYA PROYEK (OTOMATIS DETEKSI KELOMPOK SISWA) */
        if ($action === 'save_biaya') {
            $p = $assertProyek($_POST['id_proyek'] ?? 0);
            $idSiswa = (int)($_POST['id_siswa'] ?? 0);
            $idKelompok = (int)($_POST['id_proyek_kelompok'] ?? 0) ?: null;
            if (!$idKelompok && $idSiswa > 0) {
                $fTeam = $pdo->prepare("
                    SELECT pk.id FROM proyek_kelompok pk
                    LEFT JOIN proyek_kelompok_anggota pka ON pka.id_proyek_kelompok = pk.id
                    WHERE pk.id_proyek = ? AND (pka.id_siswa = ? OR pk.id_ketua_kelompok = ?)
                    LIMIT 1
                ");
                $fTeam->execute([(int)$p['id'], $idSiswa, $idSiswa]);
                $foundKel = (int)$fTeam->fetchColumn();
                if ($foundKel > 0) $idKelompok = $foundKel;
            }
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

            if ($idSiswa <= 0 || $item === '') {
                throw new Exception('Pencatat siswa dan nama item pengeluaran wajib diisi.');
            }

            $ins = $pdo->prepare("
                INSERT INTO biaya_proyek (
                    id_proyek, id_proyek_kelompok, id_siswa, id_kelas_mata_pelajaran,
                    tanggal_pengeluaran, kategori_biaya, nama_item_biaya,
                    kuantitas, satuan, harga_satuan, total_biaya,
                    sumber_dana, status_verifikasi, catatan
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Diverifikasi Guru', ?)
            ");
            $ins->execute([
                (int)$p['id'], $idKelompok, $idSiswa, (int)$p['id_kelas_mata_pelajaran'],
                $tanggal, $kat, $item, $qty, $satuan, $harga, $total, $sumber, $catatan
            ]);

            echo json_encode(['success' => true, 'message' => 'Pengeluaran kelompok berhasil dicatat dan diverifikasi.']);
            exit;
        }

        /* 17. VERIFY / DELETE BIAYA */
        if ($action === 'verify_biaya') {
            $id = (int)($_POST['id'] ?? 0);
            $stat = in_array(($_POST['status_verifikasi'] ?? ''), ['Menunggu Verifikasi', 'Diverifikasi Guru', 'Ditolak'], true) ? $_POST['status_verifikasi'] : 'Diverifikasi Guru';
            $stmt = $pdo->prepare("SELECT id, id_proyek FROM biaya_proyek WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('Data biaya tidak ditemukan.');
            $assertProyek($row['id_proyek']);

            $pdo->prepare("UPDATE biaya_proyek SET status_verifikasi = ?, updated_at = NOW() WHERE id = ?")->execute([$stat, $id]);
            echo json_encode(['success' => true, 'message' => 'Status verifikasi biaya diperbarui menjadi "' . $stat . '".']);
            exit;
        }

        if ($action === 'delete_biaya') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT id, id_proyek FROM biaya_proyek WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('Data biaya tidak ditemukan.');
            $assertProyek($row['id_proyek']);

            $pdo->prepare("DELETE FROM biaya_proyek WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Catatan biaya proyek berhasil dihapus.']);
            exit;
        }

        throw new Exception('Aksi API tidak dikenali.');
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

/* ==============================================================================
 * PAGE SHELL RENDER (DATA-FIRST ZERO TITLE / SEGMENTED TABS / SOLAR ICONS)
 * ============================================================================== */
$pageTitle = 'Proyek & P5 (PjBL)';
$pageIcon  = 'solar:rocket-2-bold';
$cssVer    = @filemtime(BASE_PATH . '/assets/css/guru-proyek.css') ?: time();
$jsVer     = @filemtime(BASE_PATH . '/assets/js/guru-proyek.js') ?: time();

include BASE_PATH . '/includes/header.php';
include BASE_PATH . '/includes/modal-template.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/guru-proyek.css?v=<?= $cssVer ?>">

<div class="pry-app">
    <!-- SEGMENTED TAB NAVIGATION (STANDAR README.md SECTION 4.1) -->
    <div class="curriculum-main-tabs" id="pryMainTabs" role="tablist">
        <button type="button" class="curriculum-tab-btn is-active active" data-tab="proyek">
            <iconify-icon icon="solar:rocket-2-bold"></iconify-icon>
            <span class="curriculum-tab-label">Daftar Proyek &amp; P5</span>
            <span class="curriculum-tab-counter" id="badgeTabProyek">0</span>
        </button>
        <button type="button" class="curriculum-tab-btn" data-tab="kelompok">
            <iconify-icon icon="solar:users-group-rounded-bold"></iconify-icon>
            <span class="curriculum-tab-label">Kelompok &amp; Peran Tim</span>
        </button>
        <button type="button" class="curriculum-tab-btn" data-tab="karya">
            <iconify-icon icon="solar:medal-star-bold"></iconify-icon>
            <span class="curriculum-tab-label">Hasil Karya &amp; Penilaian P5</span>
            <span class="curriculum-tab-counter" id="badgeTabKarya">0</span>
        </button>
        <button type="button" class="curriculum-tab-btn" data-tab="anggaran">
            <iconify-icon icon="solar:wallet-money-bold"></iconify-icon>
            <span class="curriculum-tab-label">Anggaran &amp; Biaya</span>
        </button>
    </div>

    <!-- FILTER CASCADING TOOLBAR (DIRECT DATA-FIRST) -->
    <div class="pry-toolbar">
        <div class="pry-toolbar-row">
            <div class="pry-filter-group">
                <select id="pryFilterKelas" class="pry-select" aria-label="Filter Rombel Kelas">
                    <option value="0">Semua Rombel Kelas</option>
                </select>
                <select id="pryFilterKmp" class="pry-select" aria-label="Filter Mata Pelajaran">
                    <option value="0">Semua Mata Pelajaran</option>
                </select>
                <select id="pryFilterModul" class="pry-select" disabled aria-label="Filter Modul Ajar">
                    <option value="0">Semua Modul Ajar</option>
                </select>
                <select id="pryFilterStatus" class="pry-select" aria-label="Filter Status Proyek">
                    <option value="">Semua Status</option>
                    <option value="Perencanaan">Perencanaan</option>
                    <option value="Sedang Berjalan">Sedang Berjalan</option>
                    <option value="Tahap Pengujian">Tahap Pengujian</option>
                    <option value="Selesai">Selesai</option>
                    <option value="Diarsipkan">Diarsipkan</option>
                </select>
                <div class="pry-search-wrap">
                    <iconify-icon icon="solar:magnifer-linear"></iconify-icon>
                    <input type="text" id="prySearchInput" class="pry-input" placeholder="Cari proyek, tema, modul...">
                </div>
            </div>
            <?php if ($canManage): ?>
            <button type="button" class="ilmuna-btn ilmuna-btn--teal" onclick="PRY.openProyekModal(0)">
                <iconify-icon icon="solar:add-circle-bold"></iconify-icon>
                <span>Buat Proyek Baru</span>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div id="pryContextBanner" class="pry-context-banner" style="display:none;"></div>

    <!-- TAB 1: DAFTAR PROYEK & P5 -->
    <section id="pry-tab-proyek" class="pry-tab-pane" style="display:block;">
        <div class="pry-card-grid" id="pryCardGrid"></div>
    </section>

    <!-- TAB 2: KELOMPOK & PERAN TIM (100% IDENTIK SISTEM KELOMPOK TUGAS.PHP) -->
    <section id="pry-tab-kelompok" class="pry-tab-pane" style="display:none;">
        <div class="pry-panel">
            <div class="pry-panel-head">
                <div class="pry-panel-select-wrap">
                    <label for="pryTeamProyekSelect">Proyek Aktif:</label>
                    <select id="pryTeamProyekSelect" class="pry-select"></select>
                </div>
                <?php if ($canManage): ?>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" onclick="PRY.openAddTeamModal()">
                        <iconify-icon icon="solar:add-circle-bold"></iconify-icon>
                        <span>Tambah Kelompok</span>
                    </button>
                    <button type="button" class="ilmuna-btn ilmuna-btn--danger ilmuna-btn--sm" onclick="PRY.resetSemuaKelompok()">
                        <iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon>
                        <span>Reset Semua</span>
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <!-- GENERATOR PEMBAGIAN KELOMPOK OTOMATIS (IDENTIK TUGAS.PHP) -->
            <?php if ($canManage): ?>
            <div id="pryTeamGenBar" style="background:linear-gradient(135deg,#F5F3FF 0%,#EFF6FF 100%);border:1px solid #DDD6FE;border-radius:14px;padding:13px 16px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <div style="flex:1;min-width:240px;">
                    <div style="font-size:13px;font-weight:800;color:#4C1D95;display:flex;align-items:center;gap:6px;">
                        <iconify-icon icon="solar:magic-stick-3-bold" style="font-size:18px;color:#7C3AED;"></iconify-icon>
                        <span>Generator Pembagian Kelompok Adil &amp; Merata</span>
                    </div>
                    <div id="pryTeamGenInfo" style="font-size:12px;color:#475569;margin-top:3px;">
                        Pilih proyek untuk membagi siswa secara otomatis ke dalam kelompok dengan nama tokoh Islami.
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <div style="display:inline-flex;align-items:center;gap:4px;background:#FFFFFF;border:1.5px solid #C4B5FD;border-radius:10px;padding:3px 6px;">
                        <button type="button" class="pry-icon-btn" style="width:28px;height:28px;border:none;" onclick="PRY.adjustJmlKelompok(-1)" title="Kurangi">&minus;</button>
                        <input type="number" id="pryGenCountInput" min="2" max="15" value="4" oninput="PRY.updateJmlKelompokEstimate()" style="width:42px;text-align:center;border:none;font-weight:800;font-size:14px;color:#4C1D95;outline:none;">
                        <button type="button" class="pry-icon-btn" style="width:28px;height:28px;border:none;" onclick="PRY.adjustJmlKelompok(1)" title="Tambah">+</button>
                    </div>
                    <span id="pryGenEstimateBadge" class="pry-badge pry-badge--purple" style="padding:6px 10px;">&plusmn;0 siswa/klp</span>
                    <button type="button" class="ilmuna-btn ilmuna-btn--navy ilmuna-btn--sm" onclick="PRY.executeAutoGenerateInline()">
                        <iconify-icon icon="solar:magic-stick-3-bold"></iconify-icon>
                        <span>Acak &amp; Bagi Kelompok</span>
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <div id="pryTeamContainer"></div>
        </div>
    </section>

    <!-- TAB 3: HASIL KARYA & PENILAIAN P5 -->
    <section id="pry-tab-karya" class="pry-tab-pane" style="display:none;">
        <div class="pry-panel">
            <div class="pry-panel-head">
                <div class="pry-panel-select-wrap">
                    <label for="prySubProyekSelect">Proyek Aktif:</label>
                    <select id="prySubProyekSelect" class="pry-select"></select>
                </div>
                <?php if ($canManage): ?>
                <button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" onclick="PRY.openManualSubmissionModal()">
                    <iconify-icon icon="solar:upload-bold"></iconify-icon>
                    <span>Catat Karya Siswa</span>
                </button>
                <?php endif; ?>
            </div>
            <div id="prySubmissionsContainer"></div>
        </div>
    </section>

    <!-- TAB 4: ANGGARAN & BIAYA PROYEK -->
    <section id="pry-tab-anggaran" class="pry-tab-pane" style="display:none;">
        <div class="pry-panel">
            <div class="pry-panel-head">
                <div class="pry-panel-select-wrap">
                    <label for="pryBudgetProyekSelect">Proyek Aktif:</label>
                    <select id="pryBudgetProyekSelect" class="pry-select"></select>
                </div>
                <?php if ($canManage): ?>
                <button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" onclick="PRY.openAddBiayaModal()">
                    <iconify-icon icon="solar:add-circle-bold"></iconify-icon>
                    <span>Catat Pengeluaran</span>
                </button>
                <?php endif; ?>
            </div>
            <div id="pryBudgetContainer"></div>
        </div>
    </section>
</div>

<!-- ==========================================================================
     MODAL 1: CREATE / EDIT PROYEK & P5 (TERINTEGRASI MODUL AJAR)
     ========================================================================== -->
<div class="ilm-modal-backdrop" id="modalProyekForm" aria-hidden="true">
    <div class="ilm-modal-dialog" style="max-width:780px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon">
                    <iconify-icon icon="solar:rocket-2-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title" id="pryFormModalTitle">Buat Proyek &amp; P5 Baru</h3>
                    <p class="ilm-modal-header__subtitle">Sinkron otomatis dengan Modul Ajar, TP/ITP &amp; Jadwal Mengajar</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-close" onclick="closeIlmunaModal('modalProyekForm')" aria-label="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form id="pryForm" onsubmit="PRY.submitProyekForm(event)" enctype="multipart/form-data">
            <div class="ilm-modal-body">
                <input type="hidden" name="id" id="pryFormId" value="0">
                <input type="hidden" name="id_tp" id="pryFormTpId" value="">
                <input type="hidden" name="id_itp" id="pryFormItpId" value="">
                <input type="hidden" name="pertemuan_ke" id="pryFormPertemuanKe" value="">
                <input type="hidden" name="id_jadwal" id="pryFormJadwalId" value="">
                <input type="hidden" name="dimensi_profil_pancasila" id="pryFormDimensi" value="">

                <div class="pry-form-grid">
                    <div class="pry-field">
                        <label class="pry-label" for="pryFormKelas">Rombel Kelas <span class="req">*</span></label>
                        <select id="pryFormKelas" class="pry-select" required onchange="PRY.onFormKelasChange(0)"></select>
                    </div>
                    <div class="pry-field">
                        <label class="pry-label" for="pryFormKmp">Mata Pelajaran <span class="req">*</span></label>
                        <select name="id_kelas_mata_pelajaran" id="pryFormKmp" class="pry-select" required disabled onchange="PRY.onFormKmpChange(0)"></select>
                    </div>
                    <div class="pry-field pry-field--full">
                        <label class="pry-label" for="pryFormModul">Tautkan ke Modul Ajar &amp; Pertemuan</label>
                        <select name="id_modul_ajar" id="pryFormModul" class="pry-select" disabled onchange="PRY.onFormModulChange(false)">
                            <option value="0">-- Pilih Mata Pelajaran terlebih dahulu --</option>
                        </select>
                        <div id="pryFormModulInfo" class="pry-info-box" style="display:none;margin-top:6px;"></div>
                    </div>
                    <div class="pry-field pry-field--full">
                        <label class="pry-label" for="pryFormNama">Nama / Judul Proyek <span class="req">*</span></label>
                        <input type="text" name="nama_proyek" id="pryFormNama" class="pry-input" required placeholder="Contoh: Film Pendek Adab Digital Remaja Islami">
                    </div>
                    <div class="pry-field">
                        <label class="pry-label" for="pryFormTema">Tema P5 / Kurikulum Merdeka <span class="req">*</span></label>
                        <input type="text" name="tema_proyek" id="pryFormTema" class="pry-input" required placeholder="Contoh: Rekayasa &amp; Teknologi / Kearifan Lokal">
                    </div>
                    <div class="pry-field">
                        <label class="pry-label" for="pryFormLuaran">Target Luaran / Produk Akhir <span class="req">*</span></label>
                        <input type="text" name="target_luaran" id="pryFormLuaran" class="pry-input" required placeholder="Contoh: Video Dokumenter, Booklet PDF, Maket">
                    </div>
                    <div class="pry-field">
                        <label class="pry-label" for="pryFormJenis">Model Pengerjaan</label>
                        <select name="jenis_proyek" id="pryFormJenis" class="pry-select">
                            <option value="Kelompok">Kelompok / Kolaboratif Tim</option>
                            <option value="Individu">Individu</option>
                        </select>
                    </div>
                    <div class="pry-field">
                        <label class="pry-label" for="pryFormStatus">Status Proyek</label>
                        <select name="status_proyek" id="pryFormStatus" class="pry-select">
                            <option value="Perencanaan">Perencanaan</option>
                            <option value="Sedang Berjalan" selected>Sedang Berjalan</option>
                            <option value="Tahap Pengujian">Tahap Pengujian</option>
                            <option value="Selesai">Selesai</option>
                            <option value="Diarsipkan">Diarsipkan</option>
                        </select>
                    </div>
                    <div class="pry-field">
                        <label class="pry-label" for="pryFormMulai">Tanggal Mulai <span class="req">*</span></label>
                        <input type="text" name="jadwal_mulai" id="pryFormMulai" class="pry-input" data-ilm-calendar="date" required readonly placeholder="YYYY-MM-DD">
                    </div>
                    <div class="pry-field">
                        <label class="pry-label" for="pryFormSelesai">Tenggat Selesai <span class="req">*</span></label>
                        <input type="text" name="jadwal_selesai" id="pryFormSelesai" class="pry-input" data-ilm-calendar="date" required readonly placeholder="YYYY-MM-DD">
                    </div>
                    <div class="pry-field pry-field--full">
                        <label class="pry-label">Dimensi Profil Pelajar Pancasila (P5-PPRA)</label>
                        <div class="pry-p5-picker" id="pryP5Picker"></div>
                    </div>
                    <div class="pry-field pry-field--full">
                        <label class="pry-label" for="pryFormDeskripsi">Deskripsi &amp; Tujuan Proyek <span class="req">*</span></label>
                        <textarea name="deskripsi_tujuan" id="pryFormDeskripsi" class="pry-textarea" required placeholder="Uraikan latar belakang masalah, tahapan PjBL, dan tujuan proyek..."></textarea>
                    </div>
                    <div class="pry-field pry-field--full">
                        <label class="pry-label" for="pryFormRubrik">Rubrik &amp; Pedoman Penilaian</label>
                        <textarea name="pedoman_penilaian_rubrik" id="pryFormRubrik" class="pry-textarea" placeholder="Contoh: Perencanaan 25%, Proses Kolaborasi 35%, Produk Akhir &amp; Presentasi 40%"></textarea>
                    </div>
                    <div class="pry-field">
                        <label class="pry-label" for="pryFormNilaiMax">Nilai Maksimal</label>
                        <input type="number" name="nilai_maksimal" id="pryFormNilaiMax" class="pry-input" value="100" min="10" max="100">
                    </div>
                    <div class="pry-field">
                        <label class="pry-label" for="pryFormLinkRef">Tautan Referensi / Template (Opsional)</label>
                        <input type="url" name="link_referensi" id="pryFormLinkRef" class="pry-input" placeholder="https://...">
                    </div>
                    <div class="pry-field pry-field--full">
                        <label class="pry-label" for="pryFormFilePanduan">Unggah Berkas Panduan Proyek (PDF/DOCX/PPTX, maks 15MB)</label>
                        <input type="file" name="file_panduan" id="pryFormFilePanduan" class="pry-input" accept=".pdf,.doc,.docx,.ppt,.pptx,.zip,.png,.jpg,.jpeg">
                        <div id="pryFormExistingFile" style="margin-top:6px;"></div>
                    </div>
                </div>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" onclick="closeIlmunaModal('modalProyekForm')">Batal</button>
                <button type="submit" class="ilmuna-btn ilmuna-btn--teal" id="pryFormSubmitBtn">
                    <iconify-icon icon="solar:diskette-bold"></iconify-icon>
                    <span>Simpan Proyek</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ==========================================================================
     MODAL 2: TAMBAH KELOMPOK
     ========================================================================== -->
<div class="ilm-modal-backdrop" id="modalTeamForm" aria-hidden="true">
    <div class="ilm-modal-dialog" style="max-width:480px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon"><iconify-icon icon="solar:users-group-rounded-bold"></iconify-icon></div>
                <div>
                    <h3 class="ilm-modal-header__title">Tambah Kelompok Tim</h3>
                    <p class="ilm-modal-header__subtitle">Buat kelompok kolaborasi baru</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-close" onclick="closeIlmunaModal('modalTeamForm')" aria-label="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form id="teamForm" onsubmit="PRY.submitTeamForm(event)">
            <div class="ilm-modal-body">
                <div class="pry-form-grid">
                    <div class="pry-field">
                        <label class="pry-label" for="teamNomor">Nomor Kelompok</label>
                        <input type="number" id="teamNomor" class="pry-input" min="1" value="1" required>
                    </div>
                    <div class="pry-field">
                        <label class="pry-label" for="teamNama">Nama Tim <span class="req">*</span></label>
                        <input type="text" id="teamNama" class="pry-input" required placeholder="Contoh: Tim Al-Farabi">
                    </div>
                    <div class="pry-field pry-field--full">
                        <label class="pry-label" for="teamKetua">Ketua Kelompok (Opsional)</label>
                        <select id="teamKetua" class="pry-select"></select>
                    </div>
                </div>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" onclick="closeIlmunaModal('modalTeamForm')">Batal</button>
                <button type="submit" class="ilmuna-btn ilmuna-btn--teal"><iconify-icon icon="solar:diskette-bold"></iconify-icon> Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- ==========================================================================
     MODAL 3: TAMBAH ANGGOTA KELOMPOK
     ========================================================================== -->
<div class="ilm-modal-backdrop" id="modalMemberForm" aria-hidden="true">
    <div class="ilm-modal-dialog" style="max-width:460px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon"><iconify-icon icon="solar:user-plus-bold"></iconify-icon></div>
                <div>
                    <h3 class="ilm-modal-header__title">Tambah Anggota Tim</h3>
                    <p class="ilm-modal-header__subtitle" id="memberModalSubtitle">Pilih siswa dan peran</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-close" onclick="closeIlmunaModal('modalMemberForm')" aria-label="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form onsubmit="PRY.submitMemberForm(event)">
            <div class="ilm-modal-body">
                <input type="hidden" id="memberKelompokId" value="0">
                <div class="pry-form-grid">
                    <div class="pry-field pry-field--full">
                        <label class="pry-label" for="memberSiswaId">Pilih Siswa <span class="req">*</span></label>
                        <select id="memberSiswaId" class="pry-select" required></select>
                    </div>
                    <div class="pry-field pry-field--full">
                        <label class="pry-label" for="memberPeran">Peran dalam Proyek</label>
                        <select id="memberPeran" class="pry-select"></select>
                    </div>
                </div>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" onclick="closeIlmunaModal('modalMemberForm')">Batal</button>
                <button type="submit" class="ilmuna-btn ilmuna-btn--teal"><iconify-icon icon="solar:user-plus-bold"></iconify-icon> Tambahkan</button>
            </div>
        </form>
    </div>
</div>

<!-- ==========================================================================
     MODAL 4: AUTO-GENERATE KELOMPOK
     ========================================================================== -->
<div class="ilm-modal-backdrop" id="modalAutoGroup" aria-hidden="true">
    <div class="ilm-modal-dialog" style="max-width:420px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon"><iconify-icon icon="solar:magic-stick-3-bold"></iconify-icon></div>
                <div>
                    <h3 class="ilm-modal-header__title">Bagi Kelompok Otomatis</h3>
                    <p class="ilm-modal-header__subtitle">Distribusi siswa merata beserta peran tim</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-close" onclick="closeIlmunaModal('modalAutoGroup')" aria-label="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form onsubmit="PRY.submitAutoGroup(event)">
            <div class="ilm-modal-body">
                <div class="pry-field">
                    <label class="pry-label" for="autoGroupCount">Jumlah Kelompok yang Diinginkan</label>
                    <input type="number" id="autoGroupCount" class="pry-input" min="1" max="15" value="4" required>
                </div>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" onclick="closeIlmunaModal('modalAutoGroup')">Batal</button>
                <button type="submit" class="ilmuna-btn ilmuna-btn--teal"><iconify-icon icon="solar:magic-stick-3-bold"></iconify-icon> Bagi Sekarang</button>
            </div>
        </form>
    </div>
</div>

<!-- ==========================================================================
     MODAL 4b: UBAH NAMA KELOMPOK (IDENTIK TUGAS.PHP)
     ========================================================================== -->
<div class="ilm-modal-backdrop" id="modalRenameTeam" aria-hidden="true">
    <div class="ilm-modal-dialog" style="max-width:440px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon"><iconify-icon icon="solar:pen-bold"></iconify-icon></div>
                <div>
                    <h3 class="ilm-modal-header__title">Ubah Nama Kelompok</h3>
                    <p class="ilm-modal-header__subtitle">Perbarui nama tim proyek</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-close" onclick="closeIlmunaModal('modalRenameTeam')" aria-label="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form onsubmit="PRY.submitRenameTeam(event)">
            <div class="ilm-modal-body">
                <input type="hidden" id="renameTeamId" value="0">
                <div class="pry-field">
                    <label class="pry-label" for="renameTeamInput">Nama Kelompok Baru <span class="req">*</span></label>
                    <input type="text" id="renameTeamInput" class="pry-input" required placeholder="Contoh: Kelompok 1 - Abu Bakar Ash-Shiddiq">
                </div>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" onclick="closeIlmunaModal('modalRenameTeam')">Batal</button>
                <button type="submit" class="ilmuna-btn ilmuna-btn--teal"><iconify-icon icon="solar:diskette-bold"></iconify-icon> Simpan Nama</button>
            </div>
        </form>
    </div>
</div>

<!-- ==========================================================================
     MODAL 5: CATAT PENGUMPULAN KARYA MANUAL
     ========================================================================== -->
<div class="ilm-modal-backdrop" id="modalManualSubmission" aria-hidden="true">
    <div class="ilm-modal-dialog" style="max-width:600px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon"><iconify-icon icon="solar:upload-bold"></iconify-icon></div>
                <div>
                    <h3 class="ilm-modal-header__title">Catat Hasil Karya Siswa</h3>
                    <p class="ilm-modal-header__subtitle">Untuk karya fisik, presentasi kelas, atau tautan eksternal</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-close" onclick="closeIlmunaModal('modalManualSubmission')" aria-label="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form id="subManualForm" onsubmit="PRY.submitManualSubmission(event)" enctype="multipart/form-data">
            <div class="ilm-modal-body">
                <div class="pry-form-grid">
                    <div class="pry-field">
                        <label class="pry-label" for="subManualSiswa">Siswa / Perwakilan Tim <span class="req">*</span></label>
                        <select name="id_siswa" id="subManualSiswa" class="pry-select" required></select>
                    </div>
                    <div class="pry-field">
                        <label class="pry-label" for="subManualKelompok">Kelompok (Opsional)</label>
                        <select name="id_proyek_kelompok" id="subManualKelompok" class="pry-select"></select>
                    </div>
                    <div class="pry-field pry-field--full">
                        <label class="pry-label" for="subManualJudul">Judul Karya / Produk <span class="req">*</span></label>
                        <input type="text" name="judul_laporan_produk" id="subManualJudul" class="pry-input" required placeholder="Contoh: Film Pendek Bijak Bermedia Sosial">
                    </div>
                    <div class="pry-field pry-field--full">
                        <label class="pry-label" for="subManualDesc">Deskripsi Karya</label>
                        <textarea name="deskripsi_produk" id="subManualDesc" class="pry-textarea" placeholder="Ringkasan produk dan hasil karya siswa..."></textarea>
                    </div>
                    <div class="pry-field">
                        <label class="pry-label" for="subManualLinkProduk">Tautan Karya (Drive/Canva/Web)</label>
                        <input type="url" name="link_produk" id="subManualLinkProduk" class="pry-input" placeholder="https://...">
                    </div>
                    <div class="pry-field">
                        <label class="pry-label" for="subManualLinkVideo">Tautan Video Presentasi</label>
                        <input type="url" name="link_video_presentasi" id="subManualLinkVideo" class="pry-input" placeholder="https://...">
                    </div>
                    <div class="pry-field pry-field--full">
                        <label class="pry-label" for="subManualFile">Unggah Berkas Laporan (Opsional)</label>
                        <input type="file" name="file_laporan" id="subManualFile" class="pry-input" accept=".pdf,.doc,.docx,.ppt,.pptx,.zip,.jpg,.jpeg,.png">
                    </div>
                </div>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" onclick="closeIlmunaModal('modalManualSubmission')">Batal</button>
                <button type="submit" class="ilmuna-btn ilmuna-btn--teal"><iconify-icon icon="solar:diskette-bold"></iconify-icon> Simpan Karya</button>
            </div>
        </form>
    </div>
</div>

<!-- ==========================================================================
     MODAL 6: PENILAIAN KARYA PROYEK (IDENTIK SISTEM PENILAIAN KELOMPOK & INDIVIDU TUGAS)
     ========================================================================== -->
<div class="ilm-modal-backdrop" id="modalGradeSubmission" aria-hidden="true">
    <div class="ilm-modal-dialog" style="max-width:680px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon"><iconify-icon icon="solar:medal-star-bold"></iconify-icon></div>
                <div>
                    <h3 class="ilm-modal-header__title">Penilaian Hasil Karya &amp; P5</h3>
                    <p class="ilm-modal-header__subtitle" id="gradeModalSubtitle">Evaluasi capaian proyek siswa</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-close" onclick="closeIlmunaModal('modalGradeSubmission')" aria-label="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form onsubmit="PRY.submitGradeForm(event)">
            <div class="ilm-modal-body">
                <input type="hidden" id="gradeSubId" value="0">
                <input type="hidden" id="gradeIsGroup" value="0">
                <input type="hidden" id="gradeMaxScore" value="100">

                <div id="gradeSubSummaryBox" class="pry-info-box" style="margin-bottom:12px;"></div>

                <!-- MODE PENILAIAN KELOMPOK (IDENTIK TUGAS.PHP: (Nilai Kelompok + Nilai Individu) / 2) -->
                <div id="gradeBoxGroup" style="display:none;flex-direction:column;gap:12px;margin-bottom:14px;">
                    <div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:12px;padding:12px 14px;">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
                            <strong style="color:#1E3A8A;font-size:13px;">
                                <iconify-icon icon="solar:users-group-rounded-bold"></iconify-icon>
                                Penilaian Kelompok (Rumus Adil &amp; Seimbang)
                            </strong>
                            <span class="pry-badge pry-badge--navy" id="gradeGroupCountBadge">0 Siswa</span>
                        </div>
                        <div style="font-size:11.5px;color:#334155;margin-bottom:10px;">
                            Rumus sama persis dengan Tugas: <strong>Nilai Akhir = (Nilai Kelompok + Nilai Individu) &divide; 2</strong>
                        </div>
                        <div class="pry-field">
                            <label class="pry-label" for="gradeNilaiKelompok">Nilai Kelompok / Karya Bersama (0 &ndash; 100) <span class="req">*</span></label>
                            <input type="number" id="gradeNilaiKelompok" class="pry-input" min="0" max="100" step="0.5" value="85" oninput="PRY.recalcGroupScores()" style="font-weight:800;font-size:15px;">
                        </div>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:8px;" id="gradeGroupMembersList"></div>
                </div>

                <!-- MODE PENILAIAN INDIVIDU (IDENTIK TUGAS.PHP) -->
                <div id="gradeBoxIndividu" class="pry-form-grid" style="margin-bottom:12px;">
                    <div class="pry-field">
                        <label class="pry-label" for="gradeNilai">Skor Nilai Individu (0 &ndash; 100) <span class="req">*</span></label>
                        <input type="number" id="gradeNilai" class="pry-input" min="0" max="100" step="0.5" placeholder="88" style="font-weight:800;font-size:15px;">
                    </div>
                    <div class="pry-field">
                        <label class="pry-label" for="gradeRemedial">Nilai Remedial (Opsional)</label>
                        <input type="number" id="gradeRemedial" class="pry-input" min="0" max="100" step="0.5" placeholder="Kosongkan jika tidak remedial">
                    </div>
                    <div class="pry-field pry-field--full">
                        <label class="pry-label" for="gradePredikat">Predikat Capaian P5</label>
                        <select id="gradePredikat" class="pry-select">
                            <option value="Sangat Berkembang (SB)">Sangat Berkembang (SB) &bull; 90&ndash;100</option>
                            <option value="Berkembang Sesuai Harapan (BSH)">Berkembang Sesuai Harapan (BSH) &bull; 75&ndash;89</option>
                            <option value="Mulai Berkembang (MB)">Mulai Berkembang (MB) &bull; 60&ndash;74</option>
                            <option value="Belum Berkembang (BB)">Belum Berkembang (BB) &bull; &lt; 60</option>
                        </select>
                    </div>
                </div>

                <!-- PARAMETER EVALUASI UMUM (IDENTIK TUGAS.PHP) -->
                <div class="pry-form-grid">
                    <div class="pry-field">
                        <label class="pry-label" for="gradeAspekItp">Aspek Ketercapaian ITP</label>
                        <select id="gradeAspekItp" class="pry-select">
                            <option value="Tercapai Sangat Baik">Tercapai Sangat Baik</option>
                            <option value="Tercapai" selected>Tercapai</option>
                            <option value="Cukup Tercapai">Cukup Tercapai</option>
                            <option value="Perlu Bimbingan">Perlu Bimbingan</option>
                        </select>
                    </div>
                    <div class="pry-field">
                        <label class="pry-label" for="gradeStatusPenilaian">Status Penilaian</label>
                        <select id="gradeStatusPenilaian" class="pry-select">
                            <option value="Dinilai">Dinilai</option>
                            <option value="Tuntas">Tuntas</option>
                            <option value="Perlu Revisi">Perlu Revisi (Minta Siswa Perbaiki)</option>
                        </select>
                    </div>
                    <div class="pry-field pry-field--full">
                        <label class="pry-label" for="gradeStatus">Status Publikasi Karya</label>
                        <select id="gradeStatus" class="pry-select">
                            <option value="Disetujui">Disetujui</option>
                            <option value="Dipublikasikan">Dipublikasikan (Gelar Karya)</option>
                            <option value="Revisi">Perlu Revisi</option>
                            <option value="Diserahkan">Diserahkan</option>
                        </select>
                    </div>
                    <div class="pry-field pry-field--full">
                        <label class="pry-label" for="gradeCatatan">Umpan Balik &amp; Apresiasi Guru</label>
                        <textarea id="gradeCatatan" class="pry-textarea" placeholder="Berikan catatan evaluasi proses, kolaborasi, dan produk akhir siswa..."></textarea>
                    </div>
                </div>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" onclick="closeIlmunaModal('modalGradeSubmission')">Batal</button>
                <button type="submit" class="ilmuna-btn ilmuna-btn--teal" id="gradeSubmitBtn"><iconify-icon icon="solar:check-circle-bold"></iconify-icon> Simpan Penilaian</button>
            </div>
        </form>
    </div>
</div>

<!-- ==========================================================================
     MODAL 6b: RINCIAN PENGELUARAN PER KELOMPOK (ON-DEMAND SAAT KLIK RINCIAN)
     ========================================================================== -->
<div class="ilm-modal-backdrop" id="modalGroupBiayaDetail" aria-hidden="true">
    <div class="ilm-modal-dialog" style="max-width:780px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon"><iconify-icon icon="solar:wallet-money-bold"></iconify-icon></div>
                <div>
                    <h3 class="ilm-modal-header__title" id="groupBiayaModalTitle">Rincian Biaya Kelompok</h3>
                    <p class="ilm-modal-header__subtitle" id="groupBiayaModalSub">Daftar item pengeluaran &amp; verifikasi nota</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-close" onclick="closeIlmunaModal('modalGroupBiayaDetail')" aria-label="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <div class="ilm-modal-body" id="groupBiayaModalBody"></div>
        <div class="ilm-modal-footer">
            <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" onclick="closeIlmunaModal('modalGroupBiayaDetail')">Tutup</button>
            <?php if ($canManage): ?>
            <button type="button" class="ilmuna-btn ilmuna-btn--teal" id="groupBiayaAddBtn" onclick="PRY.openAddBiayaForActiveGroup()">
                <iconify-icon icon="solar:add-circle-bold"></iconify-icon>
                <span>Tambah Biaya Kelompok Ini</span>
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ==========================================================================
     MODAL 7: CATAT PENGELUARAN / BIAYA PROYEK
     ========================================================================== -->
<div class="ilm-modal-backdrop" id="modalBiayaForm" aria-hidden="true">
    <div class="ilm-modal-dialog" style="max-width:580px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon"><iconify-icon icon="solar:wallet-money-bold"></iconify-icon></div>
                <div>
                    <h3 class="ilm-modal-header__title">Catat Anggaran &amp; Biaya Proyek</h3>
                    <p class="ilm-modal-header__subtitle">Transparansi pengeluaran pelaksanaan PjBL</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-close" onclick="closeIlmunaModal('modalBiayaForm')" aria-label="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form id="biayaForm" onsubmit="PRY.submitBiayaForm(event)">
            <div class="ilm-modal-body">
                <div class="pry-form-grid">
                    <div class="pry-field">
                        <label class="pry-label" for="biayaTanggal">Tanggal Pengeluaran <span class="req">*</span></label>
                        <input type="text" name="tanggal_pengeluaran" id="biayaTanggal" class="pry-input" data-ilm-calendar="date" required readonly>
                    </div>
                    <div class="pry-field">
                        <label class="pry-label" for="biayaKategori">Kategori Biaya</label>
                        <select name="kategori_biaya" id="biayaKategori" class="pry-select">
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
                        <label class="pry-label" for="biayaItem">Nama Item / Keperluan <span class="req">*</span></label>
                        <input type="text" name="nama_item_biaya" id="biayaItem" class="pry-input" required placeholder="Contoh: Kertas Karton &amp; Cetak Poster A3">
                    </div>
                    <div class="pry-field">
                        <label class="pry-label" for="biayaSiswa">Pencatat / Bendahara <span class="req">*</span></label>
                        <select name="id_siswa" id="biayaSiswa" class="pry-select" required></select>
                    </div>
                    <div class="pry-field">
                        <label class="pry-label" for="biayaKelompok">Kelompok</label>
                        <select name="id_proyek_kelompok" id="biayaKelompok" class="pry-select"></select>
                    </div>
                    <div class="pry-field">
                        <label class="pry-label" for="biayaQty">Kuantitas &amp; Satuan</label>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <input type="number" name="kuantitas" id="biayaQty" class="pry-input" step="0.5" min="0.5" value="1" required>
                            <input type="text" name="satuan" id="biayaSatuan" class="pry-input" value="paket" placeholder="pcs/paket">
                        </div>
                    </div>
                    <div class="pry-field">
                        <label class="pry-label" for="biayaHarga">Harga Satuan (Rp) <span class="req">*</span></label>
                        <input type="number" name="harga_satuan" id="biayaHarga" class="pry-input" min="0" step="500" value="0" required>
                    </div>
                    <div class="pry-field">
                        <label class="pry-label" for="biayaSumber">Sumber Dana</label>
                        <select name="sumber_dana" id="biayaSumber" class="pry-select">
                            <option value="Iuran Mandiri Siswa">Iuran Mandiri Siswa</option>
                            <option value="BOS / Sekolah">BOS / Sekolah</option>
                            <option value="Kas Kelas">Kas Kelas</option>
                            <option value="Sponsor / Donatur">Sponsor / Donatur</option>
                        </select>
                    </div>
                    <div class="pry-field">
                        <label class="pry-label">Total Otomatis</label>
                        <div class="pry-info-box" style="font-weight:800;font-size:14px;" id="biayaTotalPreview">Rp 0</div>
                    </div>
                    <div class="pry-field pry-field--full">
                        <label class="pry-label" for="biayaCatatan">Catatan (Opsional)</label>
                        <textarea name="catatan" id="biayaCatatan" class="pry-textarea" style="min-height:60px;" placeholder="Keterangan tambahan..."></textarea>
                    </div>
                </div>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" onclick="closeIlmunaModal('modalBiayaForm')">Batal</button>
                <button type="submit" class="ilmuna-btn ilmuna-btn--teal"><iconify-icon icon="solar:diskette-bold"></iconify-icon> Simpan Biaya</button>
            </div>
        </form>
    </div>
</div>

<script>
    window.ILMUNA_PROYEK_CFG = {
        baseUrl: <?= json_encode(BASE_URL) ?>,
        endpoint: <?= json_encode(BASE_URL . '/dashboard/guru/proyek') ?>,
        activeRole: <?= json_encode($activeRole) ?>,
        canManage: <?= $canManage ? 'true' : 'false' ?>
    };
</script>
<script src="<?= BASE_URL ?>/assets/js/guru-proyek.js?v=<?= $jsVer ?>" defer></script>

<?php include BASE_PATH . '/includes/footer.php'; ?>
