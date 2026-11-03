<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — PENILAIAN AFEKTIF & PSIKOMOTORIK (PORTAL GURU)
 * ==============================================================================
 * Standar Desain Lumina Gold & Kurikulum Merdeka (Meniru Style reports.php):
 *   1. Filter Cascading: Rombel Kelas -> Mata Pelajaran Guru -> Paket Asesmen
 *   2. Ranah Afektif: Observasi Karakter & Sikap Profil Pelajar Pancasila (SB, B, C, K)
 *   3. Ranah Psikomotorik: Keterampilan & Unjuk Kerja Praktik (KKTP, A, B, C, D)
 *   4. Evaluasi Langsung Guru (Tanpa CBT/Login Siswa)
 *   5. Dual-View: Sticky Desktop Table + Mobile Card Stack + Quick Chips
 *   6. Sinkronisasi Otomatis ke `rekap_nilai_siswa_mapel` & Export/Print Ready
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
$idGuruLogged = 0;
$namaGuruLogged = 'Dewan Guru';
try {
    $gStmt = $pdo->prepare("SELECT id, nama_guru FROM guru WHERE id_user = ? LIMIT 1");
    $gStmt->execute([$currentUser['id']]);
    $gRow = $gStmt->fetch(PDO::FETCH_ASSOC);
    if ($gRow) {
        $idGuruLogged = (int)$gRow['id'];
        $namaGuruLogged = $gRow['nama_guru'];
    }
} catch (Exception $e) {}

/* ==============================================================================
 * BACKEND AJAX HANDLERS (JSON RESPONSE)
 * ============================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];

    try {
        // 1. DAFTAR KELAS (CASCADING FILTER 1)
        if ($action === 'list_kelas_options') {
            $params = [];
            $whereClause = "";

            if ($activeRole === 'guru' && $idGuruLogged > 0) {
                $whereClause = "WHERE k.id IN (
                    SELECT kmp.id_kelas 
                    FROM kelas_mata_pelajaran kmp
                    LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
                    WHERE gmp.id_guru = :id_guru 
                       OR kmp.id IN (SELECT id_kelas_mata_pelajaran FROM asesmen_afektif WHERE id_guru = :id_guru2)
                       OR kmp.id IN (SELECT id_kelas_mata_pelajaran FROM asesmen_psikomotorik WHERE id_guru = :id_guru3)
                )";
                $params[':id_guru']  = $idGuruLogged;
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

            // Fallback seluruh kelas
            if (empty($kelas)) {
                $kelas = $pdo->query("SELECT id, nama_kelas, tahun_pelajaran, 
                    (SELECT COUNT(*) FROM siswa s WHERE s.id_kelas = kelas.id) AS total_siswa 
                    FROM kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode(['success' => true, 'data' => ['kelas' => $kelas ?: []]]);
            exit;
        }

        // 2. DAFTAR MAPEL GURU BY KELAS (CASCADING FILTER 2)
        if ($action === 'get_mapel_by_kelas') {
            $idKelas = (int)($_POST['id_kelas'] ?? 0);
            $params = [$idKelas];
            $whereGuru = "";

            if ($activeRole === 'guru' && $idGuruLogged > 0) {
                $whereGuru = " AND (gmp.id_guru = ? OR kmp.id IN (SELECT id_kelas_mata_pelajaran FROM asesmen_afektif WHERE id_guru = ?) OR kmp.id IN (SELECT id_kelas_mata_pelajaran FROM asesmen_psikomotorik WHERE id_guru = ?))";
                $params[] = $idGuruLogged;
                $params[] = $idGuruLogged;
                $params[] = $idGuruLogged;
            }

            $stmt = $pdo->prepare("
                SELECT kmp.id, kmp.id_mata_pelajaran, mp.nama_mata_pelajaran, mp.kode_mapel,
                       COALESCE(mp.kktp, 75.00) AS kktp,
                       COALESCE(g.nama_guru, 'Guru Pengampu') AS nama_guru
                FROM kelas_mata_pelajaran kmp
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
                LEFT JOIN guru g ON gmp.id_guru = g.id
                WHERE kmp.id_kelas = ? {$whereGuru}
                ORDER BY mp.nama_mata_pelajaran ASC
            ");
            $stmt->execute($params);
            $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fallback seluruh mapel kelas
            if (empty($list)) {
                $stmtFallback = $pdo->prepare("
                    SELECT kmp.id, kmp.id_mata_pelajaran, mp.nama_mata_pelajaran, mp.kode_mapel,
                           COALESCE(mp.kktp, 75.00) AS kktp,
                           COALESCE(g.nama_guru, 'Guru Pengampu') AS nama_guru
                    FROM kelas_mata_pelajaran kmp
                    JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                    LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
                    LEFT JOIN guru g ON gmp.id_guru = g.id
                    WHERE kmp.id_kelas = ?
                    ORDER BY mp.nama_mata_pelajaran ASC
                ");
                $stmtFallback->execute([$idKelas]);
                $list = $stmtFallback->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode(['success' => true, 'data' => $list ?: []]);
            exit;
        }

        // 2B. DAFTAR MODUL AJAR PER KELAS & MATA PELAJARAN
        if ($action === 'get_modul_ajar_options') {
            $idKmp = (int)($_POST['id_kmp'] ?? 0);
            if ($idKmp <= 0) {
                echo json_encode(['success' => true, 'data' => []]);
                exit;
            }

            $stmt = $pdo->prepare("SELECT id, pertemuan_ke, judul_modul, profil_pelajar_pancasila, fase, target_peserta_didik
                FROM modul_ajar
                WHERE id_kelas_mata_pelajaran = ?
                ORDER BY pertemuan_ke ASC, id ASC");
            $stmt->execute([$idKmp]);
            $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'data' => $list ?: []]);
            exit;
        }

        // 3. DAFTAR ASESMEN AFEKTIF / PSIKOMOTORIK (CASCADING FILTER 3)
        if ($action === 'get_assessments') {
            $tipe = $_POST['tipe'] === 'psikomotorik' ? 'psikomotorik' : 'afektif';
            $idKmp = (int)($_POST['id_kmp'] ?? 0);

            if ($idKmp <= 0) {
                echo json_encode(['success' => true, 'data' => []]);
                exit;
            }

            if ($tipe === 'afektif') {
                $stmt = $pdo->prepare("SELECT a.*, ma.judul_modul,
                    (SELECT COUNT(*) FROM asesmen_afektif_indikator ind WHERE ind.id_asesmen_afektif = a.id) AS total_indikator,
                    (SELECT COUNT(*) FROM nilai_asesmen_afektif n WHERE n.id_asesmen_afektif = a.id) AS total_dinilai
                    FROM asesmen_afektif a
                    LEFT JOIN modul_ajar ma ON ma.id = a.id_modul_ajar
                    WHERE a.id_kelas_mata_pelajaran = ?
                    ORDER BY a.pertemuan_ke ASC, a.id ASC");
                $stmt->execute([$idKmp]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmt = $pdo->prepare("SELECT p.*, ma.judul_modul,
                    (SELECT COUNT(*) FROM asesmen_psikomotorik_rubrik r WHERE r.id_asesmen_psikomotorik = p.id) AS total_rubrik,
                    (SELECT COUNT(*) FROM nilai_asesmen_psikomotorik n WHERE n.id_asesmen_psikomotorik = p.id) AS total_dinilai
                    FROM asesmen_psikomotorik p
                    LEFT JOIN modul_ajar ma ON ma.id = p.id_modul_ajar
                    WHERE p.id_kelas_mata_pelajaran = ?
                    ORDER BY p.pertemuan_ke ASC, p.id ASC");
                $stmt->execute([$idKmp]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode(['success' => true, 'data' => $rows ?: []]);
            exit;
        }

        // 4. BUAT INSTRUMEN ASESMEN BARU
        if ($action === 'create_assessment') {
            $tipe = $_POST['tipe'] === 'psikomotorik' ? 'psikomotorik' : 'afektif';
            $idKmp = (int)($_POST['id_kmp'] ?? 0);
            $idModulAjar = !empty($_POST['id_modul_ajar']) ? (int)$_POST['id_modul_ajar'] : null;
            $judul = trim($_POST['judul_asesmen'] ?? '');
            $pertemuan = max(1, (int)($_POST['pertemuan_ke'] ?? 1));
            $teknik = trim($_POST['teknik_asesmen'] ?? '');
            $tujuan = trim($_POST['tujuan_asesmen'] ?? '');
            $petunjuk = trim($_POST['petunjuk'] ?? '');
            $tgl = !empty($_POST['tanggal_pelaksanaan']) ? $_POST['tanggal_pelaksanaan'] : date('Y-m-d');
            $idGuru = $idGuruLogged > 0 ? $idGuruLogged : ($currentUser['id'] ?? null);

            // Jika memilih modul ajar dan pertemuan_ke belum ditentukan, ambil dari modul ajar
            if ($idModulAjar > 0) {
                $stmtMa = $pdo->prepare("SELECT pertemuan_ke, judul_modul, profil_pelajar_pancasila FROM modul_ajar WHERE id = ?");
                $stmtMa->execute([$idModulAjar]);
                $maRow = $stmtMa->fetch(PDO::FETCH_ASSOC);
                if ($maRow && empty($_POST['pertemuan_ke'])) {
                    $pertemuan = (int)$maRow['pertemuan_ke'];
                }
            }

            if ($idKmp <= 0 || empty($judul)) {
                throw new Exception("Mata pelajaran dan judul asesmen wajib diisi!");
            }

            if ($tipe === 'afektif') {
                $dimensi = trim($_POST['dimensi_profil'] ?? 'Beriman dan Bertakwa, Mandiri, Gotong Royong, Bernalar Kritis, Kreatif, Berkebinekaan Global');
                $stmt = $pdo->prepare("INSERT INTO `asesmen_afektif` 
                    (`id_kelas_mata_pelajaran`, `id_guru`, `id_modul_ajar`, `pertemuan_ke`, `judul_asesmen`, `teknik_asesmen`, `dimensi_profil`, `tujuan_asesmen`, `petunjuk_penilaian`, `tanggal_pelaksanaan`, `status`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Aktif')");
                $stmt->execute([$idKmp, $idGuru, $idModulAjar, $pertemuan, $judul, $teknik ?: 'Observasi', $dimensi, $tujuan, $petunjuk, $tgl]);
                $newId = (int)$pdo->lastInsertId();

                // 4 Indikator Karakter Default
                $stmtInd = $pdo->prepare("INSERT INTO `asesmen_afektif_indikator` 
                    (`id_asesmen_afektif`, `nomor_urut`, `aspek_sikap`, `indikator_perilaku`, `skala_maks`, `rubrik_deskriptor`) 
                    VALUES (?, ?, ?, ?, 4, ?)");
                $defaultInd = [
                    [1, 'Kedisiplinan & Kerapian', 'Hadir tepat waktu dan mematuhi tata tertib pembelajaran di kelas.', '4=Sangat Disiplin, 3=Disiplin, 2=Cukup, 1=Kurang'],
                    [2, 'Kejujuran & Integritas', 'Menunjukkan kejujuran akademik dan sportivitas dalam bersikap.', '4=Selalu Jujur, 3=Sering Jujur, 2=Kadang, 1=Kurang'],
                    [3, 'Gotong Royong & Kerjasama', 'Aktif berkolaborasi dan membantu teman dalam diskusi/kelompok.', '4=Sangat Proaktif, 3=Aktif, 2=Cukup, 1=Pasif'],
                    [4, 'Adab, Santun & Hormat', 'Bertutur kata sopan kepada guru dan menghargai sesama teman.', '4=Sangat Santun, 3=Santun, 2=Cukup, 1=Kurang']
                ];
                foreach ($defaultInd as $row) {
                    $stmtInd->execute([$newId, $row[0], $row[1], $row[2], $row[3]]);
                }
            } else {
                $aspek = trim($_POST['aspek_keterampilan'] ?? 'Keterampilan Praktik / Unjuk Kerja');
                $kktp = !empty($_POST['kktp']) ? (float)$_POST['kktp'] : 75.00;
                $stmt = $pdo->prepare("INSERT INTO `asesmen_psikomotorik` 
                    (`id_kelas_mata_pelajaran`, `id_guru`, `id_modul_ajar`, `pertemuan_ke`, `judul_asesmen`, `teknik_asesmen`, `aspek_keterampilan`, `tujuan_asesmen`, `petunjuk_kegiatan`, `kktp`, `tanggal_pelaksanaan`, `status`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Aktif')");
                $stmt->execute([$idKmp, $idGuru, $idModulAjar, $pertemuan, $judul, $teknik ?: 'Praktik', $aspek, $tujuan, $petunjuk, $kktp, $tgl]);
                $newId = (int)$pdo->lastInsertId();

                // 4 Rubrik Keterampilan Default
                $stmtRub = $pdo->prepare("INSERT INTO `asesmen_psikomotorik_rubrik` 
                    (`id_asesmen_psikomotorik`, `nomor_urut`, `tahap_keterampilan`, `indikator_kinerja`, `bobot_skor`, `kriteria_skor_maks`, `rubrik_deskriptor`) 
                    VALUES (?, ?, ?, ?, ?, 4, ?)");
                $defaultRub = [
                    [1, 'Tahap Persiapan & Sikap Awal', 'Kesiapan instrumen, fokus, dan sikap tubuh saat memulai unjuk kerja.', 20.00, '4=Sangat Sempurna, 3=Baik, 2=Cukup, 1=Kurang'],
                    [2, 'Teknik & Kaidah Prosedur', 'Penguasaan langkah kerja teknis dan kepatuhan terhadap kaidah yang benar.', 40.00, '4=Sangat Fasih/Tepat, 3=Tepat, 2=Cukup, 1=Keliru'],
                    [3, 'Kelancaran & Ketepatan Hasil', 'Ketuntasan praktik tanpa ragu-ragu dan runtut dari awal hingga akhir.', 25.00, '4=Sangat Lancar, 3=Lancar, 2=Kurang Lancar, 1=Terbata'],
                    [4, 'Sikap Akhir & Etika Praktik', 'Kerapian, ketelitian mengecek kembali hasil, dan adab unjuk kerja.', 15.00, '4=Sangat Baik, 3=Baik, 2=Cukup, 1=Kurang']
                ];
                foreach ($defaultRub as $row) {
                    $stmtRub->execute([$newId, $row[0], $row[1], $row[2], $row[3], $row[4]]);
                }
            }

            echo json_encode(['success' => true, 'message' => 'Instrumen penilaian baru berhasil dibuat!', 'id_asesmen' => $newId, 'tipe' => $tipe]);
            exit;
        }

        // 4B. DAFTAR SEMUA INSTRUMEN UNTUK TAB KELOLA INSTRUMEN (FULL CRUD)
        if ($action === 'list_all_instruments') {
            $idKmp = (int)($_POST['id_kmp'] ?? 0);
            if ($idKmp <= 0) {
                echo json_encode(['success' => true, 'data' => [], 'all' => [], 'afektif' => [], 'psikomotorik' => [], 'total' => 0]);
                exit;
            }

            // Afektif
            $stmtA = $pdo->prepare("SELECT a.*, 'afektif' AS tipe, ma.judul_modul,
                (SELECT COUNT(*) FROM asesmen_afektif_indikator ind WHERE ind.id_asesmen_afektif = a.id) AS total_rubrik,
                (SELECT COUNT(*) FROM nilai_asesmen_afektif n WHERE n.id_asesmen_afektif = a.id) AS total_siswa_dinilai,
                (SELECT COUNT(*) FROM asesmen_afektif_indikator ind WHERE ind.id_asesmen_afektif = a.id) AS total_items,
                (SELECT COUNT(*) FROM nilai_asesmen_afektif n WHERE n.id_asesmen_afektif = a.id) AS total_dinilai
                FROM asesmen_afektif a
                LEFT JOIN modul_ajar ma ON ma.id = a.id_modul_ajar
                WHERE a.id_kelas_mata_pelajaran = ?
                ORDER BY a.pertemuan_ke ASC, a.id ASC");
            $stmtA->execute([$idKmp]);
            $afkList = $stmtA->fetchAll(PDO::FETCH_ASSOC);

            // Psikomotorik
            $stmtP = $pdo->prepare("SELECT p.*, 'psikomotorik' AS tipe, ma.judul_modul,
                (SELECT COUNT(*) FROM asesmen_psikomotorik_rubrik r WHERE r.id_asesmen_psikomotorik = p.id) AS total_rubrik,
                (SELECT COUNT(*) FROM nilai_asesmen_psikomotorik n WHERE n.id_asesmen_psikomotorik = p.id) AS total_siswa_dinilai,
                (SELECT COUNT(*) FROM asesmen_psikomotorik_rubrik r WHERE r.id_asesmen_psikomotorik = p.id) AS total_items,
                (SELECT COUNT(*) FROM nilai_asesmen_psikomotorik n WHERE n.id_asesmen_psikomotorik = p.id) AS total_dinilai
                FROM asesmen_psikomotorik p
                LEFT JOIN modul_ajar ma ON ma.id = p.id_modul_ajar
                WHERE p.id_kelas_mata_pelajaran = ?
                ORDER BY p.pertemuan_ke ASC, p.id ASC");
            $stmtP->execute([$idKmp]);
            $pskList = $stmtP->fetchAll(PDO::FETCH_ASSOC);

            $all = array_merge($afkList, $pskList);
            usort($all, function($x, $y) {
                if ($x['pertemuan_ke'] == $y['pertemuan_ke']) {
                    return $x['id'] <=> $y['id'];
                }
                return $x['pertemuan_ke'] <=> $y['pertemuan_ke'];
            });

            echo json_encode([
                'success' => true,
                'data' => $all,
                'all' => $all,
                'afektif' => $afkList,
                'psikomotorik' => $pskList,
                'total' => count($all)
            ]);
            exit;
        }

        // 4C. AMBIL DETAIL INSTRUMEN & BUTIR RUBRIK (UNTUK EDIT & KELOLA RUBRIK)
        if ($action === 'get_instrument_detail') {
            $tipe = $_POST['tipe'] === 'psikomotorik' ? 'psikomotorik' : 'afektif';
            $idAsesmen = (int)($_POST['id_asesmen'] ?? 0);

            if ($idAsesmen <= 0) {
                throw new Exception("ID Instrumen tidak valid.");
            }

            if ($tipe === 'afektif') {
                $stmt = $pdo->prepare("SELECT a.*, ma.judul_modul FROM asesmen_afektif a LEFT JOIN modul_ajar ma ON ma.id = a.id_modul_ajar WHERE a.id = ?");
                $stmt->execute([$idAsesmen]);
                $asesmen = $stmt->fetch(PDO::FETCH_ASSOC);

                $stmtI = $pdo->prepare("SELECT * FROM asesmen_afektif_indikator WHERE id_asesmen_afektif = ? ORDER BY nomor_urut ASC, id ASC");
                $stmtI->execute([$idAsesmen]);
                $items = $stmtI->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmt = $pdo->prepare("SELECT p.*, ma.judul_modul FROM asesmen_psikomotorik p LEFT JOIN modul_ajar ma ON ma.id = p.id_modul_ajar WHERE p.id = ?");
                $stmt->execute([$idAsesmen]);
                $asesmen = $stmt->fetch(PDO::FETCH_ASSOC);

                $stmtI = $pdo->prepare("SELECT * FROM asesmen_psikomotorik_rubrik WHERE id_asesmen_psikomotorik = ? ORDER BY nomor_urut ASC, id ASC");
                $stmtI->execute([$idAsesmen]);
                $items = $stmtI->fetchAll(PDO::FETCH_ASSOC);
            }

            if (!$asesmen) {
                throw new Exception("Instrumen tidak ditemukan.");
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'asesmen' => $asesmen,
                    'items' => $items,
                    'tipe' => $tipe
                ]
            ]);
            exit;
        }

        // 4D. UPDATE DATA INSTRUMEN ASESMEN
        if ($action === 'update_instrument') {
            $tipe = $_POST['tipe'] === 'psikomotorik' ? 'psikomotorik' : 'afektif';
            $idAsesmen = (int)($_POST['id_asesmen'] ?? 0);
            $idModulAjar = !empty($_POST['id_modul_ajar']) ? (int)$_POST['id_modul_ajar'] : null;
            $judul = trim($_POST['judul_asesmen'] ?? '');
            $pertemuan = max(1, (int)($_POST['pertemuan_ke'] ?? 1));
            $teknik = trim($_POST['teknik_asesmen'] ?? '');
            $tujuan = trim($_POST['tujuan_asesmen'] ?? '');
            $status = in_array($_POST['status'] ?? '', ['Draft', 'Aktif', 'Ditutup']) ? $_POST['status'] : 'Aktif';

            if ($idAsesmen <= 0 || empty($judul)) {
                throw new Exception("Judul asesmen wajib diisi!");
            }

            if ($tipe === 'afektif') {
                $dimensi = trim($_POST['dimensi_profil'] ?? '');
                $stmt = $pdo->prepare("UPDATE `asesmen_afektif` 
                    SET `id_modul_ajar` = ?, `pertemuan_ke` = ?, `judul_asesmen` = ?, `teknik_asesmen` = ?, `dimensi_profil` = ?, `tujuan_asesmen` = ?, `status` = ?, `updated_at` = NOW()
                    WHERE `id` = ?");
                $stmt->execute([$idModulAjar, $pertemuan, $judul, $teknik, $dimensi, $tujuan, $status, $idAsesmen]);
            } else {
                $kktp = !empty($_POST['kktp']) ? (float)$_POST['kktp'] : 75.00;
                $aspek = trim($_POST['aspek_keterampilan'] ?? 'Keterampilan Praktik / Unjuk Kerja');
                $stmt = $pdo->prepare("UPDATE `asesmen_psikomotorik` 
                    SET `id_modul_ajar` = ?, `pertemuan_ke` = ?, `judul_asesmen` = ?, `teknik_asesmen` = ?, `aspek_keterampilan` = ?, `tujuan_asesmen` = ?, `kktp` = ?, `status` = ?, `updated_at` = NOW()
                    WHERE `id` = ?");
                $stmt->execute([$idModulAjar, $pertemuan, $judul, $teknik, $aspek, $tujuan, $kktp, $status, $idAsesmen]);
            }

            echo json_encode(['success' => true, 'message' => 'Data instrumen berhasil diperbarui!']);
            exit;
        }

        // 4E. HAPUS INSTRUMEN ASESMEN (BESERTA NILAI & BUTIR RUBRIK)
        if ($action === 'delete_instrument') {
            $tipe = $_POST['tipe'] === 'psikomotorik' ? 'psikomotorik' : 'afektif';
            $idAsesmen = (int)($_POST['id_asesmen'] ?? 0);

            if ($idAsesmen <= 0) {
                throw new Exception("ID Instrumen tidak valid.");
            }

            $pdo->beginTransaction();
            if ($tipe === 'afektif') {
                $pdo->prepare("DELETE FROM `nilai_asesmen_afektif` WHERE `id_asesmen_afektif` = ?")->execute([$idAsesmen]);
                $pdo->prepare("DELETE FROM `asesmen_afektif_indikator` WHERE `id_asesmen_afektif` = ?")->execute([$idAsesmen]);
                $pdo->prepare("DELETE FROM `asesmen_afektif` WHERE `id` = ?")->execute([$idAsesmen]);
            } else {
                $pdo->prepare("DELETE FROM `nilai_asesmen_psikomotorik` WHERE `id_asesmen_psikomotorik` = ?")->execute([$idAsesmen]);
                $pdo->prepare("DELETE FROM `asesmen_psikomotorik_rubrik` WHERE `id_asesmen_psikomotorik` = ?")->execute([$idAsesmen]);
                $pdo->prepare("DELETE FROM `asesmen_psikomotorik` WHERE `id` = ?")->execute([$idAsesmen]);
            }
            $pdo->commit();

            echo json_encode(['success' => true, 'message' => 'Instrumen penilaian berhasil dihapus.']);
            exit;
        }

        // 4F. TAMBAH / EDIT BUTIR RUBRIK INDIKATOR
        if ($action === 'save_rubrik_item') {
            $tipe = $_POST['tipe'] === 'psikomotorik' ? 'psikomotorik' : 'afektif';
            $idAsesmen = (int)($_POST['id_asesmen'] ?? 0);
            $itemId = (int)($_POST['item_id'] ?? 0);
            $nomor = max(1, (int)($_POST['nomor_urut'] ?? 1));
            $aspek = trim($_POST['aspek'] ?? '');
            $indikator = trim($_POST['indikator'] ?? '');
            $deskriptor = trim($_POST['rubrik_deskriptor'] ?? '4=Sangat Baik/Sempurna, 3=Baik, 2=Cukup, 1=Kurang');

            if ($idAsesmen <= 0 || empty($aspek)) {
                throw new Exception("Nama aspek / tahap rubrik wajib diisi!");
            }

            if ($tipe === 'afektif') {
                if ($itemId > 0) {
                    $stmt = $pdo->prepare("UPDATE `asesmen_afektif_indikator` 
                        SET `nomor_urut` = ?, `aspek_sikap` = ?, `indikator_perilaku` = ?, `rubrik_deskriptor` = ?, `updated_at` = NOW()
                        WHERE `id` = ? AND `id_asesmen_afektif` = ?");
                    $stmt->execute([$nomor, $aspek, $indikator, $deskriptor, $itemId, $idAsesmen]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO `asesmen_afektif_indikator` 
                        (`id_asesmen_afektif`, `nomor_urut`, `aspek_sikap`, `indikator_perilaku`, `skala_maks`, `rubrik_deskriptor`) 
                        VALUES (?, ?, ?, ?, 4, ?)");
                    $stmt->execute([$idAsesmen, $nomor, $aspek, $indikator, $deskriptor]);
                }
            } else {
                $bobot = !empty($_POST['bobot_skor']) ? (float)$_POST['bobot_skor'] : 25.00;
                if ($itemId > 0) {
                    $stmt = $pdo->prepare("UPDATE `asesmen_psikomotorik_rubrik` 
                        SET `nomor_urut` = ?, `tahap_keterampilan` = ?, `indikator_kinerja` = ?, `bobot_skor` = ?, `rubrik_deskriptor` = ?, `updated_at` = NOW()
                        WHERE `id` = ? AND `id_asesmen_psikomotorik` = ?");
                    $stmt->execute([$nomor, $aspek, $indikator, $bobot, $deskriptor, $itemId, $idAsesmen]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO `asesmen_psikomotorik_rubrik` 
                        (`id_asesmen_psikomotorik`, `nomor_urut`, `tahap_keterampilan`, `indikator_kinerja`, `bobot_skor`, `kriteria_skor_maks`, `rubrik_deskriptor`) 
                        VALUES (?, ?, ?, ?, ?, 4, ?)");
                    $stmt->execute([$idAsesmen, $nomor, $aspek, $indikator, $bobot, $deskriptor]);
                }
            }

            echo json_encode(['success' => true, 'message' => 'Butir rubrik penilaian berhasil disimpan!']);
            exit;
        }

        // 4G. HAPUS BUTIR RUBRIK
        if ($action === 'delete_rubrik_item') {
            $tipe = $_POST['tipe'] === 'psikomotorik' ? 'psikomotorik' : 'afektif';
            $itemId = (int)($_POST['item_id'] ?? 0);

            if ($itemId <= 0) {
                throw new Exception("ID butir rubrik tidak valid.");
            }

            if ($tipe === 'afektif') {
                $pdo->prepare("DELETE FROM `asesmen_afektif_indikator` WHERE `id` = ?")->execute([$itemId]);
            } else {
                $pdo->prepare("DELETE FROM `asesmen_psikomotorik_rubrik` WHERE `id` = ?")->execute([$itemId]);
            }

            echo json_encode(['success' => true, 'message' => 'Butir rubrik berhasil dihapus.']);
            exit;
        }

        // 5. DATA SISWA, INDIKATOR/RUBRIK & PEROLEHAN NILAI (GRADEBOOK DIRECT EVALUATION)
        if ($action === 'get_assessment_data') {
            $tipe = $_POST['tipe'] === 'psikomotorik' ? 'psikomotorik' : 'afektif';
            $idAsesmen = (int)($_POST['id_asesmen'] ?? 0);
            $idKmp = (int)($_POST['id_kmp'] ?? 0);

            if ($idAsesmen <= 0 || $idKmp <= 0) {
                throw new Exception("Paket asesmen belum dipilih.");
            }

            // Validasi KMP aktif untuk memastikan rombel kelas dan mapel sesuai
            $stmtKmp = $pdo->prepare("SELECT kmp.id, kmp.id_kelas, k.nama_kelas, mp.nama_mata_pelajaran, mp.kode_mapel, COALESCE(mp.kktp, 75.00) AS default_kktp
                FROM kelas_mata_pelajaran kmp
                JOIN kelas k ON k.id = kmp.id_kelas
                JOIN mata_pelajaran mp ON mp.id = kmp.id_mata_pelajaran
                WHERE kmp.id = ?");
            $stmtKmp->execute([$idKmp]);
            $kmpInfo = $stmtKmp->fetch(PDO::FETCH_ASSOC);

            if (!$kmpInfo) {
                throw new Exception("Data Rombel & Mata Pelajaran tidak ditemukan.");
            }
            $activeKelasId = (int)$kmpInfo['id_kelas'];

            if ($tipe === 'afektif') {
                $stmtA = $pdo->prepare("SELECT a.*, kmp.id_kelas, k.nama_kelas, mp.nama_mata_pelajaran, mp.kode_mapel 
                    FROM asesmen_afektif a
                    JOIN kelas_mata_pelajaran kmp ON kmp.id = a.id_kelas_mata_pelajaran
                    JOIN kelas k ON k.id = kmp.id_kelas
                    JOIN mata_pelajaran mp ON mp.id = kmp.id_mata_pelajaran
                    WHERE a.id = ? AND a.id_kelas_mata_pelajaran = ?");
                $stmtA->execute([$idAsesmen, $idKmp]);
                $asesmen = $stmtA->fetch(PDO::FETCH_ASSOC);

                if (!$asesmen) {
                    throw new Exception("Instrumen asesmen afektif tidak ditemukan untuk rombel kelas ini.");
                }

                $stmtI = $pdo->prepare("SELECT * FROM asesmen_afektif_indikator WHERE id_asesmen_afektif = ? ORDER BY nomor_urut ASC, id ASC");
                $stmtI->execute([$idAsesmen]);
                $items = $stmtI->fetchAll(PDO::FETCH_ASSOC);

                $stmtN = $pdo->prepare("SELECT * FROM nilai_asesmen_afektif WHERE id_asesmen_afektif = ? AND id_kelas_mata_pelajaran = ?");
                $stmtN->execute([$idAsesmen, $idKmp]);
                $rawNilai = $stmtN->fetchAll(PDO::FETCH_ASSOC);
                $nilaiMap = [];
                foreach ($rawNilai as $n) {
                    $nilaiMap[$n['id_siswa']] = $n;
                }
            } else {
                $stmtA = $pdo->prepare("SELECT p.*, kmp.id_kelas, k.nama_kelas, mp.nama_mata_pelajaran, mp.kode_mapel 
                    FROM asesmen_psikomotorik p
                    JOIN kelas_mata_pelajaran kmp ON kmp.id = p.id_kelas_mata_pelajaran
                    JOIN kelas k ON k.id = kmp.id_kelas
                    JOIN mata_pelajaran mp ON mp.id = kmp.id_mata_pelajaran
                    WHERE p.id = ? AND p.id_kelas_mata_pelajaran = ?");
                $stmtA->execute([$idAsesmen, $idKmp]);
                $asesmen = $stmtA->fetch(PDO::FETCH_ASSOC);

                if (!$asesmen) {
                    throw new Exception("Instrumen asesmen psikomotorik tidak ditemukan untuk rombel kelas ini.");
                }

                $stmtI = $pdo->prepare("SELECT * FROM asesmen_psikomotorik_rubrik WHERE id_asesmen_psikomotorik = ? ORDER BY nomor_urut ASC, id ASC");
                $stmtI->execute([$idAsesmen]);
                $items = $stmtI->fetchAll(PDO::FETCH_ASSOC);

                $stmtN = $pdo->prepare("SELECT * FROM nilai_asesmen_psikomotorik WHERE id_asesmen_psikomotorik = ? AND id_kelas_mata_pelajaran = ?");
                $stmtN->execute([$idAsesmen, $idKmp]);
                $rawNilai = $stmtN->fetchAll(PDO::FETCH_ASSOC);
                $nilaiMap = [];
                foreach ($rawNilai as $n) {
                    $nilaiMap[$n['id_siswa']] = $n;
                }
            }

            // HANYA AMBIL SISWA YANG BENAR-BENAR TERDAFTAR DI KELAS ROMBEL INI (Mencegah kebocoran siswa kelas sebelah)
            $stmtSiswa = $pdo->prepare("SELECT s.id, s.nis, s.nisn, s.nama_lengkap, s.foto
                FROM siswa s
                WHERE s.id_kelas = ?
                ORDER BY s.nama_lengkap ASC");
            $stmtSiswa->execute([$activeKelasId]);
            $siswaList = $stmtSiswa->fetchAll(PDO::FETCH_ASSOC);

            $resultStudents = [];
            foreach ($siswaList as $s) {
                $existing = $nilaiMap[$s['id']] ?? null;
                $resultStudents[] = [
                    'id_siswa'         => (int)$s['id'],
                    'nis'              => $s['nis'] ?: '-',
                    'nisn'             => $s['nisn'] ?: '-',
                    'nama_lengkap'     => $s['nama_lengkap'],
                    'foto'             => $s['foto'] ?: '',
                    'sudah_dinilai'    => $existing ? true : false,
                    'nilai_angka'      => $existing ? (float)$existing['nilai_angka'] : null,
                    'skor_rata_skala'  => $existing ? (float)($existing['skor_rata_skala'] ?? 0) : null,
                    'nilai_kktp'       => $existing ? (float)($existing['nilai_kktp'] ?? ($asesmen['kktp'] ?? 75)) : (float)($asesmen['kktp'] ?? 75),
                    'predikat'         => $existing ? $existing['predikat'] : null,
                    'status_tuntas'    => $existing ? $existing['status_tuntas'] : 'Belum Dinilai',
                    'catatan'          => $existing ? ($existing['catatan_perilaku'] ?? $existing['catatan_kinerja'] ?? '') : '',
                ];
            }

            $savedCount = count($rawNilai);
            $isSaved = ($savedCount > 0);

            echo json_encode([
                'success' => true,
                'data' => [
                    'asesmen'     => $asesmen,
                    'items'       => $items,
                    'students'    => $resultStudents,
                    'is_saved'    => $isSaved,
                    'total_saved' => $savedCount
                ]
            ]);
            exit;
        }

        // 6. SIMPAN NILAI 1 SISWA (IN-PLACE VIA AJAX)
        if ($action === 'save_student_score') {
            $tipe = $_POST['tipe'] === 'psikomotorik' ? 'psikomotorik' : 'afektif';
            $idAsesmen = (int)($_POST['id_asesmen'] ?? 0);
            $idKmp = (int)($_POST['id_kmp'] ?? 0);
            $idSiswa = (int)($_POST['id_siswa'] ?? 0);
            $nilaiAngka = (float)($_POST['nilai_angka'] ?? 0);
            $catatan = trim($_POST['catatan'] ?? '');
            $idGuru = $idGuruLogged > 0 ? $idGuruLogged : ($currentUser['id'] ?? null);

            if ($idAsesmen <= 0 || $idKmp <= 0 || $idSiswa <= 0) {
                throw new Exception("Parameter penilaian tidak lengkap.");
            }

            if ($tipe === 'afektif') {
                $skala = (float)($_POST['skor_rata_skala'] ?? round($nilaiAngka / 25, 2));
                $predikat = 'B';
                if ($nilaiAngka >= 88) $predikat = 'SB';
                elseif ($nilaiAngka >= 75) $predikat = 'B';
                elseif ($nilaiAngka >= 60) $predikat = 'C';
                else $predikat = 'K';

                $statusTuntas = in_array($predikat, ['SB', 'B']) ? 'Tuntas' : 'Perlu Pembinaan';

                $stmt = $pdo->prepare("INSERT INTO `nilai_asesmen_afektif`
                    (`id_siswa`, `id_kelas_mata_pelajaran`, `id_asesmen_afektif`, `id_guru`, `pertemuan_ke`, `nilai_angka`, `skor_rata_skala`, `predikat`, `status_tuntas`, `catatan_perilaku`, `waktu_penilaian`)
                    VALUES (?, ?, ?, ?, (SELECT pertemuan_ke FROM asesmen_afektif WHERE id = ?), ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE 
                        `nilai_angka` = VALUES(`nilai_angka`),
                        `skor_rata_skala` = VALUES(`skor_rata_skala`),
                        `predikat` = VALUES(`predikat`),
                        `status_tuntas` = VALUES(`status_tuntas`),
                        `catatan_perilaku` = VALUES(`catatan_perilaku`),
                        `waktu_penilaian` = NOW()");
                $stmt->execute([$idSiswa, $idKmp, $idAsesmen, $idGuru, $idAsesmen, $nilaiAngka, $skala, $predikat, $statusTuntas, $catatan]);

                // Sinkronkan ke rekap_nilai_siswa_mapel
                $avgAfk = $pdo->prepare("SELECT AVG(nilai_angka) AS rata FROM nilai_asesmen_afektif WHERE id_siswa = ? AND id_kelas_mata_pelajaran = ?");
                $avgAfk->execute([$idSiswa, $idKmp]);
                $rataAfk = round((float)($avgAfk->fetchColumn() ?: $nilaiAngka), 2);
                $predAfk = ($rataAfk >= 88) ? 'SB' : (($rataAfk >= 75) ? 'B' : (($rataAfk >= 60) ? 'C' : 'K'));

                $pdo->prepare("UPDATE `rekap_nilai_siswa_mapel` 
                    SET `rata_afektif` = ?, `predikat_afektif` = ?, `deskripsi_afektif` = ?
                    WHERE `id_siswa` = ? AND `id_kelas_mata_pelajaran` = ?")
                    ->execute([$rataAfk, $predAfk, $catatan, $idSiswa, $idKmp]);

                echo json_encode(['success' => true, 'message' => 'Nilai observasi sikap berhasil disimpan!', 'predikat' => $predikat, 'status_tuntas' => $statusTuntas]);
                exit;
            } else {
                $kktp = (float)($_POST['nilai_kktp'] ?? 75.00);
                $statusTuntas = ($nilaiAngka >= $kktp) ? 'Tuntas' : 'Belum Tuntas';
                $predikat = 'B';
                if ($nilaiAngka >= 90) $predikat = 'A';
                elseif ($nilaiAngka >= 80) $predikat = 'B';
                elseif ($nilaiAngka >= 70) $predikat = 'C';
                elseif ($nilaiAngka >= 60) $predikat = 'D';
                else $predikat = 'E';

                $stmt = $pdo->prepare("INSERT INTO `nilai_asesmen_psikomotorik`
                    (`id_siswa`, `id_kelas_mata_pelajaran`, `id_asesmen_psikomotorik`, `id_guru`, `pertemuan_ke`, `nilai_angka`, `nilai_kktp`, `predikat`, `status_tuntas`, `catatan_kinerja`, `waktu_penilaian`)
                    VALUES (?, ?, ?, ?, (SELECT pertemuan_ke FROM asesmen_psikomotorik WHERE id = ?), ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE 
                        `nilai_angka` = VALUES(`nilai_angka`),
                        `nilai_kktp` = VALUES(`nilai_kktp`),
                        `predikat` = VALUES(`predikat`),
                        `status_tuntas` = VALUES(`status_tuntas`),
                        `catatan_kinerja` = VALUES(`catatan_kinerja`),
                        `waktu_penilaian` = NOW()");
                $stmt->execute([$idSiswa, $idKmp, $idAsesmen, $idGuru, $idAsesmen, $nilaiAngka, $kktp, $predikat, $statusTuntas, $catatan]);

                // Sinkronkan ke rekap_nilai_siswa_mapel
                $avgPsk = $pdo->prepare("SELECT AVG(nilai_angka) AS rata FROM nilai_asesmen_psikomotorik WHERE id_siswa = ? AND id_kelas_mata_pelajaran = ?");
                $avgPsk->execute([$idSiswa, $idKmp]);
                $rataPsk = round((float)($avgPsk->fetchColumn() ?: $nilaiAngka), 2);
                $predPsk = ($rataPsk >= 90) ? 'A' : (($rataPsk >= 80) ? 'B' : (($rataPsk >= 70) ? 'C' : 'D'));

                $pdo->prepare("UPDATE `rekap_nilai_siswa_mapel` 
                    SET `rata_psikomotorik` = ?, `predikat_psikomotorik` = ?, `deskripsi_psikomotorik` = ?
                    WHERE `id_siswa` = ? AND `id_kelas_mata_pelajaran` = ?")
                    ->execute([$rataPsk, $predPsk, $catatan, $idSiswa, $idKmp]);

                echo json_encode(['success' => true, 'message' => 'Nilai praktik kinerja berhasil disimpan!', 'predikat' => $predikat, 'status_tuntas' => $statusTuntas]);
                exit;
            }
        }

        // 7. SIMPAN SEMUA NILAI SERENTAK (BATCH SAVE)
        if ($action === 'save_all_scores') {
            $tipe = $_POST['tipe'] === 'psikomotorik' ? 'psikomotorik' : 'afektif';
            $idAsesmen = (int)($_POST['id_asesmen'] ?? 0);
            $idKmp = (int)($_POST['id_kmp'] ?? 0);
            $scoresRaw = $_POST['scores'] ?? '[]';
            $scores = is_array($scoresRaw) ? $scoresRaw : json_decode($scoresRaw, true);
            $idGuru = $idGuruLogged > 0 ? $idGuruLogged : ($currentUser['id'] ?? null);

            if ($idAsesmen <= 0 || $idKmp <= 0 || empty($scores)) {
                throw new Exception("Tidak ada data nilai yang dikirim.");
            }

            $pdo->beginTransaction();
            $count = 0;

            if ($tipe === 'afektif') {
                $stmt = $pdo->prepare("INSERT INTO `nilai_asesmen_afektif`
                    (`id_siswa`, `id_kelas_mata_pelajaran`, `id_asesmen_afektif`, `id_guru`, `pertemuan_ke`, `nilai_angka`, `skor_rata_skala`, `predikat`, `status_tuntas`, `catatan_perilaku`, `waktu_penilaian`)
                    VALUES (?, ?, ?, ?, (SELECT pertemuan_ke FROM asesmen_afektif WHERE id = ?), ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE 
                        `nilai_angka` = VALUES(`nilai_angka`),
                        `skor_rata_skala` = VALUES(`skor_rata_skala`),
                        `predikat` = VALUES(`predikat`),
                        `status_tuntas` = VALUES(`status_tuntas`),
                        `catatan_perilaku` = VALUES(`catatan_perilaku`),
                        `waktu_penilaian` = NOW()");

                foreach ($scores as $sc) {
                    $idSiswa = (int)($sc['id_siswa'] ?? 0);
                    $nilaiAngka = (float)($sc['nilai_angka'] ?? 0);
                    $skala = (float)($sc['skor_rata_skala'] ?? round($nilaiAngka / 25, 2));
                    $catatan = trim($sc['catatan'] ?? '');

                    $predikat = 'B';
                    if ($nilaiAngka >= 88) $predikat = 'SB';
                    elseif ($nilaiAngka >= 75) $predikat = 'B';
                    elseif ($nilaiAngka >= 60) $predikat = 'C';
                    else $predikat = 'K';

                    $statusTuntas = in_array($predikat, ['SB', 'B']) ? 'Tuntas' : 'Perlu Pembinaan';

                    if ($idSiswa > 0 && $nilaiAngka > 0) {
                        $stmt->execute([$idSiswa, $idKmp, $idAsesmen, $idGuru, $idAsesmen, $nilaiAngka, $skala, $predikat, $statusTuntas, $catatan]);
                        $count++;
                    }
                }
            } else {
                $stmt = $pdo->prepare("INSERT INTO `nilai_asesmen_psikomotorik`
                    (`id_siswa`, `id_kelas_mata_pelajaran`, `id_asesmen_psikomotorik`, `id_guru`, `pertemuan_ke`, `nilai_angka`, `nilai_kktp`, `predikat`, `status_tuntas`, `catatan_kinerja`, `waktu_penilaian`)
                    VALUES (?, ?, ?, ?, (SELECT pertemuan_ke FROM asesmen_psikomotorik WHERE id = ?), ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE 
                        `nilai_angka` = VALUES(`nilai_angka`),
                        `nilai_kktp` = VALUES(`nilai_kktp`),
                        `predikat` = VALUES(`predikat`),
                        `status_tuntas` = VALUES(`status_tuntas`),
                        `catatan_kinerja` = VALUES(`catatan_kinerja`),
                        `waktu_penilaian` = NOW()");

                foreach ($scores as $sc) {
                    $idSiswa = (int)($sc['id_siswa'] ?? 0);
                    $nilaiAngka = (float)($sc['nilai_angka'] ?? 0);
                    $kktp = (float)($sc['nilai_kktp'] ?? 75.00);
                    $catatan = trim($sc['catatan'] ?? '');

                    $statusTuntas = ($nilaiAngka >= $kktp) ? 'Tuntas' : 'Belum Tuntas';
                    $predikat = 'B';
                    if ($nilaiAngka >= 90) $predikat = 'A';
                    elseif ($nilaiAngka >= 80) $predikat = 'B';
                    elseif ($nilaiAngka >= 70) $predikat = 'C';
                    elseif ($nilaiAngka >= 60) $predikat = 'D';
                    else $predikat = 'E';

                    if ($idSiswa > 0 && $nilaiAngka > 0) {
                        $stmt->execute([$idSiswa, $idKmp, $idAsesmen, $idGuru, $idAsesmen, $nilaiAngka, $kktp, $predikat, $statusTuntas, $catatan]);
                        $count++;
                    }
                }
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => "Berhasil menyimpan {$count} nilai siswa serentak ke database dan buku rapor!"]);
            exit;
        }

        // 7B. RESET SEMUA NILAI ASESMEN 1 KELAS (HAPUS DARI DATABASE)
        if ($action === 'reset_assessment_scores') {
            $tipe = $_POST['tipe'] === 'psikomotorik' ? 'psikomotorik' : 'afektif';
            $idAsesmen = (int)($_POST['id_asesmen'] ?? 0);
            $idKmp = (int)($_POST['id_kmp'] ?? 0);

            if ($idAsesmen <= 0 || $idKmp <= 0) {
                throw new Exception("Paket asesmen atau kelas mata pelajaran tidak valid.");
            }

            $pdo->beginTransaction();

            if ($tipe === 'afektif') {
                $stmtSiswa = $pdo->prepare("SELECT DISTINCT id_siswa FROM `nilai_asesmen_afektif` WHERE `id_asesmen_afektif` = ? AND `id_kelas_mata_pelajaran` = ?");
                $stmtSiswa->execute([$idAsesmen, $idKmp]);
                $siswaIds = $stmtSiswa->fetchAll(PDO::FETCH_COLUMN);

                $delStmt = $pdo->prepare("DELETE FROM `nilai_asesmen_afektif` WHERE `id_asesmen_afektif` = ? AND `id_kelas_mata_pelajaran` = ?");
                $delStmt->execute([$idAsesmen, $idKmp]);

                if (!empty($siswaIds)) {
                    $inQuery = implode(',', array_map('intval', $siswaIds));
                    $avgStmt = $pdo->prepare("SELECT id_siswa, AVG(nilai_angka) AS rata FROM `nilai_asesmen_afektif` WHERE `id_kelas_mata_pelajaran` = ? AND id_siswa IN ($inQuery) GROUP BY id_siswa");
                    $avgStmt->execute([$idKmp]);
                    $avgRows = $avgStmt->fetchAll(PDO::FETCH_KEY_PAIR);

                    $updRekap = $pdo->prepare("UPDATE `rekap_nilai_siswa_mapel` SET `rata_afektif` = ?, `predikat_afektif` = ? WHERE `id_siswa` = ? AND `id_kelas_mata_pelajaran` = ?");
                    foreach ($siswaIds as $sid) {
                        $rata = isset($avgRows[$sid]) ? round((float)$avgRows[$sid], 2) : 0;
                        $pred = ($rata >= 88) ? 'SB' : (($rata >= 75) ? 'B' : (($rata >= 60) ? 'C' : ($rata > 0 ? 'K' : null)));
                        $updRekap->execute([$rata, $pred, $sid, $idKmp]);
                    }
                }
            } else {
                $stmtSiswa = $pdo->prepare("SELECT DISTINCT id_siswa FROM `nilai_asesmen_psikomotorik` WHERE `id_asesmen_psikomotorik` = ? AND `id_kelas_mata_pelajaran` = ?");
                $stmtSiswa->execute([$idAsesmen, $idKmp]);
                $siswaIds = $stmtSiswa->fetchAll(PDO::FETCH_COLUMN);

                $delStmt = $pdo->prepare("DELETE FROM `nilai_asesmen_psikomotorik` WHERE `id_asesmen_psikomotorik` = ? AND `id_kelas_mata_pelajaran` = ?");
                $delStmt->execute([$idAsesmen, $idKmp]);

                if (!empty($siswaIds)) {
                    $inQuery = implode(',', array_map('intval', $siswaIds));
                    $avgStmt = $pdo->prepare("SELECT id_siswa, AVG(nilai_angka) AS rata FROM `nilai_asesmen_psikomotorik` WHERE `id_kelas_mata_pelajaran` = ? AND id_siswa IN ($inQuery) GROUP BY id_siswa");
                    $avgStmt->execute([$idKmp]);
                    $avgRows = $avgStmt->fetchAll(PDO::FETCH_KEY_PAIR);

                    $updRekap = $pdo->prepare("UPDATE `rekap_nilai_siswa_mapel` SET `rata_psikomotorik` = ?, `predikat_psikomotorik` = ? WHERE `id_siswa` = ? AND `id_kelas_mata_pelajaran` = ?");
                    foreach ($siswaIds as $sid) {
                        $rata = isset($avgRows[$sid]) ? round((float)$avgRows[$sid], 2) : 0;
                        $pred = ($rata >= 90) ? 'A' : (($rata >= 80) ? 'B' : (($rata >= 70) ? 'C' : ($rata >= 60 ? 'D' : ($rata > 0 ? 'E' : null))));
                        $updRekap->execute([$rata, $pred, $sid, $idKmp]);
                    }
                }
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Nilai asesmen untuk 1 kelas ini berhasil direset. Seluruh nilai telah dihapus dari database.']);
            exit;
        }

        // 7C. RESET NILAI 1 SISWA (HAPUS RECORD PENILAIAN INDIVIDUAL SISWA DARI DATABASE)
        if ($action === 'reset_student_score') {
            $tipe = $_POST['tipe'] === 'psikomotorik' ? 'psikomotorik' : 'afektif';
            $idAsesmen = (int)($_POST['id_asesmen'] ?? 0);
            $idKmp = (int)($_POST['id_kmp'] ?? 0);
            $idSiswa = (int)($_POST['id_siswa'] ?? 0);

            if ($idAsesmen <= 0 || $idKmp <= 0 || $idSiswa <= 0) {
                throw new Exception("Parameter siswa atau paket asesmen tidak valid.");
            }

            $pdo->beginTransaction();

            if ($tipe === 'afektif') {
                $delStmt = $pdo->prepare("DELETE FROM `nilai_asesmen_afektif` WHERE `id_asesmen_afektif` = ? AND `id_kelas_mata_pelajaran` = ? AND `id_siswa` = ?");
                $delStmt->execute([$idAsesmen, $idKmp, $idSiswa]);

                // Sinkronkan ulang rekap_nilai_siswa_mapel
                $avgStmt = $pdo->prepare("SELECT AVG(nilai_angka) AS rata FROM `nilai_asesmen_afektif` WHERE `id_kelas_mata_pelajaran` = ? AND `id_siswa` = ?");
                $avgStmt->execute([$idKmp, $idSiswa]);
                $rataVal = $avgStmt->fetchColumn();
                $rata = ($rataVal !== false && $rataVal !== null) ? round((float)$rataVal, 2) : 0;
                $pred = ($rata >= 88) ? 'SB' : (($rata >= 75) ? 'B' : (($rata >= 60) ? 'C' : ($rata > 0 ? 'K' : null)));

                $updRekap = $pdo->prepare("UPDATE `rekap_nilai_siswa_mapel` SET `rata_afektif` = ?, `predikat_afektif` = ? WHERE `id_siswa` = ? AND `id_kelas_mata_pelajaran` = ?");
                $updRekap->execute([$rata, $pred, $idSiswa, $idKmp]);
            } else {
                $delStmt = $pdo->prepare("DELETE FROM `nilai_asesmen_psikomotorik` WHERE `id_asesmen_psikomotorik` = ? AND `id_kelas_mata_pelajaran` = ? AND `id_siswa` = ?");
                $delStmt->execute([$idAsesmen, $idKmp, $idSiswa]);

                // Sinkronkan ulang rekap_nilai_siswa_mapel
                $avgStmt = $pdo->prepare("SELECT AVG(nilai_angka) AS rata FROM `nilai_asesmen_psikomotorik` WHERE `id_kelas_mata_pelajaran` = ? AND `id_siswa` = ?");
                $avgStmt->execute([$idKmp, $idSiswa]);
                $rataVal = $avgStmt->fetchColumn();
                $rata = ($rataVal !== false && $rataVal !== null) ? round((float)$rataVal, 2) : 0;
                $pred = ($rata >= 90) ? 'A' : (($rata >= 80) ? 'B' : (($rata >= 70) ? 'C' : ($rata >= 60 ? 'D' : ($rata > 0 ? 'E' : null))));

                $updRekap = $pdo->prepare("UPDATE `rekap_nilai_siswa_mapel` SET `rata_psikomotorik` = ?, `predikat_psikomotorik` = ? WHERE `id_siswa` = ? AND `id_kelas_mata_pelajaran` = ?");
                $updRekap->execute([$rata, $pred, $idSiswa, $idKmp]);
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Nilai siswa berhasil direset dari database. Lembar penilaian siswa telah dibuka kembali.']);
            exit;
        }

        // 7D. DOWNLOAD LEMBAR PENILAIAN PDF RESMI (METODE DIRECT BLOB DOWNLOAD)
        if ($action === 'download_assessment_pdf') {
            @set_time_limit(0);
            @ignore_user_abort(true);
            while (ob_get_level()) ob_end_clean();

            require_once BASE_PATH . '/includes/penilaian-asesmen-pdf.php';

            $tipe = $_POST['tipe'] === 'psikomotorik' ? 'psikomotorik' : 'afektif';
            $idAsesmen = (int)($_POST['id_asesmen'] ?? 0);
            $idKmp = (int)($_POST['id_kmp'] ?? 0);

            if ($idAsesmen <= 0 || $idKmp <= 0) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Paket asesmen atau kelas mata pelajaran tidak valid.']);
                exit;
            }

            // Ambil detail KMP beserta Guru Pengampu Resmi yang Sah dari Database
            $stmtKmp = $pdo->prepare("
                SELECT kmp.id, kmp.id_kelas, k.nama_kelas, mp.nama_mata_pelajaran, mp.kode_mapel, 
                       COALESCE(mp.kktp, 75.00) AS default_kktp,
                       COALESCE(g.nama_guru, 'Guru Pengampu') AS nama_guru,
                       COALESCE(g.nip, '-') AS nip_guru
                FROM kelas_mata_pelajaran kmp
                JOIN kelas k ON k.id = kmp.id_kelas
                JOIN mata_pelajaran mp ON mp.id = kmp.id_mata_pelajaran
                LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
                LEFT JOIN guru g ON gmp.id_guru = g.id
                WHERE kmp.id = ?
            ");
            $stmtKmp->execute([$idKmp]);
            $kmpInfo = $stmtKmp->fetch(PDO::FETCH_ASSOC);

            if (!$kmpInfo) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Data Rombel & Mata Pelajaran tidak ditemukan.']);
                exit;
            }
            $activeKelasId = (int)$kmpInfo['id_kelas'];

            // Ambil Profil Identitas Sekolah Resmi dari Database
            $sekolah = [];
            try {
                $stmtSekolah = $pdo->query("SELECT * FROM identitas_sekolah WHERE is_active = 1 LIMIT 1");
                $sekolah = $stmtSekolah->fetch(PDO::FETCH_ASSOC);
                if (!$sekolah) {
                    $stmtSekolah = $pdo->query("SELECT * FROM identitas_sekolah ORDER BY id DESC LIMIT 1");
                    $sekolah = $stmtSekolah->fetch(PDO::FETCH_ASSOC);
                }
            } catch (Throwable $eSekolah) {
                $sekolah = [];
            }
            if (!$sekolah) {
                $sekolah = [];
            }

            // Guru Pengampu Resmi yang Sah dari KMP
            $guruNamaSah = $kmpInfo['nama_guru'] ?? ($currentUser['nama_lengkap'] ?? 'Guru Pengampu');
            $guruNipSah  = $kmpInfo['nip_guru'] ?? '-';

            if ($tipe === 'afektif') {
                $stmtA = $pdo->prepare("
                    SELECT a.*, kmp.id_kelas, k.nama_kelas, mp.nama_mata_pelajaran, mp.kode_mapel
                    FROM asesmen_afektif a
                    JOIN kelas_mata_pelajaran kmp ON kmp.id = a.id_kelas_mata_pelajaran
                    JOIN kelas k ON k.id = kmp.id_kelas
                    JOIN mata_pelajaran mp ON mp.id = kmp.id_mata_pelajaran
                    WHERE a.id = ? AND a.id_kelas_mata_pelajaran = ?
                ");
                $stmtA->execute([$idAsesmen, $idKmp]);
                $asesmen = $stmtA->fetch(PDO::FETCH_ASSOC);

                if (!$asesmen) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'message' => 'Instrumen asesmen afektif tidak ditemukan.']);
                    exit;
                }

                $stmtI = $pdo->prepare("SELECT * FROM asesmen_afektif_indikator WHERE id_asesmen_afektif = ? ORDER BY nomor_urut ASC, id ASC");
                $stmtI->execute([$idAsesmen]);
                $items = $stmtI->fetchAll(PDO::FETCH_ASSOC);

                $stmtN = $pdo->prepare("SELECT * FROM nilai_asesmen_afektif WHERE id_asesmen_afektif = ? AND id_kelas_mata_pelajaran = ?");
                $stmtN->execute([$idAsesmen, $idKmp]);
                $rawNilai = $stmtN->fetchAll(PDO::FETCH_ASSOC);
                $nilaiMap = [];
                foreach ($rawNilai as $n) $nilaiMap[$n['id_siswa']] = $n;
            } else {
                $stmtA = $pdo->prepare("
                    SELECT p.*, kmp.id_kelas, k.nama_kelas, mp.nama_mata_pelajaran, mp.kode_mapel
                    FROM asesmen_psikomotorik p
                    JOIN kelas_mata_pelajaran kmp ON kmp.id = p.id_kelas_mata_pelajaran
                    JOIN kelas k ON k.id = kmp.id_kelas
                    JOIN mata_pelajaran mp ON mp.id = kmp.id_mata_pelajaran
                    WHERE p.id = ? AND p.id_kelas_mata_pelajaran = ?
                ");
                $stmtA->execute([$idAsesmen, $idKmp]);
                $asesmen = $stmtA->fetch(PDO::FETCH_ASSOC);

                if (!$asesmen) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'message' => 'Instrumen asesmen psikomotorik tidak ditemukan.']);
                    exit;
                }

                $stmtI = $pdo->prepare("SELECT * FROM asesmen_psikomotorik_rubrik WHERE id_asesmen_psikomotorik = ? ORDER BY nomor_urut ASC, id ASC");
                $stmtI->execute([$idAsesmen]);
                $items = $stmtI->fetchAll(PDO::FETCH_ASSOC);

                $stmtN = $pdo->prepare("SELECT * FROM nilai_asesmen_psikomotorik WHERE id_asesmen_psikomotorik = ? AND id_kelas_mata_pelajaran = ?");
                $stmtN->execute([$idAsesmen, $idKmp]);
                $rawNilai = $stmtN->fetchAll(PDO::FETCH_ASSOC);
                $nilaiMap = [];
                foreach ($rawNilai as $n) $nilaiMap[$n['id_siswa']] = $n;
            }

            $asesmen['nama_guru'] = $guruNamaSah;
            $asesmen['nip_guru']  = $guruNipSah;

            $stmtSiswa = $pdo->prepare("SELECT s.id, s.nis, s.nisn, s.nama_lengkap, s.foto FROM siswa s WHERE s.id_kelas = ? ORDER BY s.nama_lengkap ASC");
            $stmtSiswa->execute([$activeKelasId]);
            $siswaList = $stmtSiswa->fetchAll(PDO::FETCH_ASSOC);

            $students = [];
            foreach ($siswaList as $s) {
                $ex = $nilaiMap[$s['id']] ?? null;
                $nAngka = ($ex && $ex['nilai_angka'] !== null && $ex['nilai_angka'] !== '') ? (float)$ex['nilai_angka'] : null;
                $sSkala = null;
                if ($ex && isset($ex['skor_rata_skala']) && (float)$ex['skor_rata_skala'] > 0) {
                    $sSkala = (float)$ex['skor_rata_skala'];
                } elseif ($nAngka !== null && $nAngka > 0) {
                    $sSkala = round($nAngka / 25, 1);
                }

                $students[] = [
                    'id_siswa'         => (int)$s['id'],
                    'nis'              => $s['nis'] ?: '-',
                    'nisn'             => $s['nisn'] ?: '-',
                    'nama_lengkap'     => $s['nama_lengkap'],
                    'nilai_angka'      => $nAngka,
                    'skor_rata_skala'  => $sSkala,
                    'predikat'         => ($ex && !empty($ex['predikat'])) ? $ex['predikat'] : '-',
                    'status_tuntas'    => ($ex && !empty($ex['status_tuntas'])) ? $ex['status_tuntas'] : 'Belum Dinilai',
                    'catatan'          => $ex ? ($ex['catatan_perilaku'] ?? $ex['catatan_kinerja'] ?? '-') : '-',
                ];
            }

            $pdfData = [
                'asesmen'   => $asesmen,
                'items'     => $items,
                'students'  => $students,
                'tipe'      => $tipe,
                'guru_nama' => $guruNamaSah,
                'guru_nip'  => $guruNipSah,
                'sekolah'   => $sekolah
            ];

            $resPdf = ilmuna_generate_penilaian_asesmen_pdf($pdfData);
            if (!$resPdf['ok'] || empty($resPdf['path']) || !file_exists($resPdf['path'])) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => $resPdf['error'] ?? 'Gagal membuat file PDF.']);
                exit;
            }

            $pdfPath = $resPdf['path'];
            $filename = 'Lembar_Penilaian_' . strtoupper($tipe) . '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $asesmen['nama_kelas'] ?? 'Kelas') . '_' . date('Ymd_His') . '.pdf';

            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($pdfPath));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
            readfile($pdfPath);
            @unlink($pdfPath);
            exit;
        }

        throw new Exception("Aksi tidak valid: {$action}");

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// 2. Muat Header Resmi
$pageTitle = 'Penilaian Afektif & Praktik (Observasi Langsung Guru)';
$pageIcon  = 'solar:medal-star-circle-bold';
require_once BASE_PATH . '/includes/header.php';

// 3. Muat Seluruh Template UI Sentral Sekaligus
loadCentralTemplates(['modal', 'calendar', 'table']);
?>

<style>
/* ==============================================================================
 * ILMUNA GURU — DESIGN SYSTEM SAMA DENGAN REPORTS.PHP
 * ============================================================================== */
.content-wrapper {
    max-width: 1440px;
    margin: 0 auto;
    padding: 18px 24px 80px;
}
.content-card {
    background: #FFFFFF;
    border-radius: 18px;
    border: 1px solid #E2E8F0;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.04);
    margin-bottom: 20px;
    overflow: hidden;
}
.content-card-body {
    padding: 20px 24px;
}

/* TABS ALA CURRICULUM & REPORTS */
.curriculum-main-tabs {
    display: inline-flex;
    background: #F1F5F9;
    border: 1px solid #E2E8F0;
    border-radius: 14px;
    padding: 6px;
    gap: 8px;
    margin-bottom: 18px;
    overflow-x: auto;
    scrollbar-width: none;
}
.curriculum-tab-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: 10px;
    font-size: 13.5px;
    font-weight: 700;
    color: #475569;
    background: transparent;
    border: none;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
.curriculum-tab-btn:hover {
    color: #0D7A87;
    background: rgba(255, 255, 255, 0.6);
}
.curriculum-tab-btn.is-active {
    background: #FFFFFF;
    color: #0D7A87;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.08);
}
.curriculum-tab-counter {
    background: #CCFBF1;
    color: #0F766E;
    font-size: 11px;
    font-weight: 800;
    border-radius: 10px;
    padding: 2px 8px;
    line-height: 1.4;
}

/* TABLE STYLING SAMA DENGAN REPORTS.PHP */
.ilmuna-gradebook-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 13px;
    text-align: center;
}
.ilmuna-gradebook-table th, 
.ilmuna-gradebook-table td {
    border-right: 1px solid #E2E8F0;
    border-bottom: 1px solid #E2E8F0;
    vertical-align: middle;
}
.ilmuna-gradebook-table thead tr:first-child th {
    background: #F8FAFC;
    color: #1E293B;
    font-weight: 800;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    padding: 12px 10px;
    position: sticky;
    top: 0;
    z-index: 10;
}
.ilmuna-gradebook-table thead tr:nth-child(2) th {
    background: #F1F5F9;
    color: #334155;
    font-weight: 700;
    font-size: 11.5px;
    padding: 8px 10px;
    position: sticky;
    top: 42px;
    z-index: 9;
}
.ilmuna-gradebook-table th.sticky-col,
.ilmuna-gradebook-table td.sticky-col {
    position: sticky;
    background: #FFFFFF;
    z-index: 5;
}
.ilmuna-gradebook-table thead th.sticky-col {
    z-index: 15 !important;
    background: #F8FAFC !important;
}
.ilmuna-gradebook-table tr:hover td.sticky-col {
    background: #F8FAFC !important;
}
.col-no   { left: 0; width: 44px; min-width: 44px; text-align: center; }
.col-nis  { left: 44px; width: 100px; min-width: 100px; font-weight: 600; color: #64748B; }
.col-nama { left: 144px; min-width: 230px; text-align: left !important; font-weight: 700; color: #0F172A; box-shadow: 4px 0 8px rgba(0,0,0,0.04); }

/* SKALA PILL BUTTONS (1, 2, 3, 4) */
.skala-btn-pill {
    width: 26px;
    height: 26px;
    border-radius: 6px;
    border: 1px solid #CBD5E1;
    background: #FFFFFF;
    color: #475569;
    font-weight: 800;
    font-size: 11.5px;
    cursor: pointer;
    transition: all 0.15s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.skala-btn-pill:hover {
    border-color: #0D7A87;
    color: #0D7A87;
}
.skala-btn-pill.is-selected {
    background: #0D7A87 !important;
    border-color: #0D7A87 !important;
    color: #FFFFFF !important;
    box-shadow: 0 2px 6px rgba(13, 122, 135, 0.35);
}

/* BADGES PREDIKAT & STATUS */
.badge-pred {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 6px;
    font-weight: 800;
    font-size: 11.5px;
}
.badge-pred-sb, .badge-pred-a { background: #DCFCE7; color: #15803D; }
.badge-pred-b { background: #E0F2FE; color: #0369A1; }
.badge-pred-c { background: #FEF3C7; color: #B45309; }
.badge-pred-k, .badge-pred-d, .badge-pred-e { background: #FEE2E2; color: #B91C1C; }

.badge-st-tuntas { background: #ECFDF5; color: #065F46; border: 1px solid #A7F3D0; font-size: 11px; font-weight: 800; padding: 3px 8px; border-radius: 6px; }
.badge-st-belum  { background: #FEF3C7; color: #92400E; border: 1px solid #FDE68A; font-size: 11px; font-weight: 800; padding: 3px 8px; border-radius: 6px; }

/* EMPTY STATE BOX SAMA PERSIS DENGAN REPORTS.PHP */
.ilmuna-empty-row {
    padding: 60px 24px;
    text-align: center;
    background: #FFFFFF;
    border: 1px dashed #CBD5E1;
    border-radius: 16px;
    margin: 12px 0;
}
.ilmuna-empty-row__icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    margin: 0 auto 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    background: rgba(13, 122, 135, 0.08);
    color: #0D7A87;
}
.ilmuna-empty-row__chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    justify-content: center;
    margin-top: 18px;
}
.ilmuna-empty-chip {
    padding: 6px 14px;
    border-radius: 999px;
    background: #F1F5F9;
    border: 1px solid #E2E8F0;
    color: #1E293B;
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
}
.ilmuna-empty-chip:hover {
    background: #0D7A87;
    color: #FFFFFF;
    border-color: #0D7A87;
}

/* BUTTONS */
.btn-gold {
    background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
}
.btn-gold:hover {
    background: linear-gradient(135deg, #D97706 0%, #B45309 100%);
    color: #FFFFFF;
}

/* TOMBOL AKSI ROW (SIMPAN & RESET PER SISWA) */
.ilmuna-btn-row-save {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    padding: 5px 12px;
    border-radius: 8px;
    background: #0D7A87;
    color: #FFFFFF;
    border: none;
    font-size: 11.5px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
}
.ilmuna-btn-row-save:hover {
    background: #095963;
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(13, 122, 135, 0.3);
}
.ilmuna-btn-row-reset {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    padding: 5px 12px;
    border-radius: 8px;
    background: #FEE2E2;
    color: #DC2626;
    border: 1px solid #FECACA;
    font-size: 11.5px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
}
.ilmuna-btn-row-reset:hover {
    background: #DC2626;
    color: #FFFFFF;
    border-color: #DC2626;
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(220, 38, 38, 0.3);
}

/* MOBILE & DESKTOP TOGGLE */
.gradebook-desktop-view[style*="display: none"],
.gradebook-mobile-view[style*="display: none"] {
    display: none !important;
}
@media (max-width: 900px) {
    .gradebook-desktop-view { display: none !important; }
    .gradebook-mobile-view:not([style*="display: none"]) { display: flex !important; }
}
@media (min-width: 901px) {
    .gradebook-desktop-view:not([style*="display: none"]) { display: block !important; }
    .gradebook-mobile-view { display: none !important; }
}
</style>

<!-- MAIN CONTENT WRAPPER -->
<div class="content-wrapper">

    <!-- 1. TABS STRIP SEGMENTED (AFEKTIF vs PSIKOMOTORIK vs BANK INSTRUMEN) -->
    <div class="curriculum-main-tabs" role="tablist">
        <button type="button" class="curriculum-tab-btn is-active" id="tabBtnAfektif">
            <iconify-icon icon="solar:heart-angle-bold" width="18"></iconify-icon>
            <span>Penilaian Afektif (Sikap)</span>
            <span class="curriculum-tab-counter" id="badgeAfektifCount">0</span>
        </button>
        <button type="button" class="curriculum-tab-btn" id="tabBtnPsikomotorik">
            <iconify-icon icon="solar:running-round-bold" width="18"></iconify-icon>
            <span>Penilaian Psikomotorik (Praktik)</span>
            <span class="curriculum-tab-counter" id="badgePsikomotorikCount">0</span>
        </button>
        <button type="button" class="curriculum-tab-btn" id="tabBtnInstrumen">
            <iconify-icon icon="solar:checklist-minimalistic-bold" width="18" style="color: #6366F1;"></iconify-icon>
            <span>Bank & Kelola Instrumen</span>
            <span class="curriculum-tab-counter" id="badgeInstrumenCount" style="background: rgba(99, 102, 241, 0.14); color: #4F46E5;">0</span>
        </button>
    </div>

    <!-- 2. TOOLBAR & FILTERS (CASCADING PERSIS REPORTS.PHP) -->
    <div class="content-card">
        <div class="content-card-body" style="padding: 18px 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
                
                <!-- Filter Kiri (Cascading: Rombel -> Mapel -> Paket Asesmen) -->
                <div style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center; flex: 1;">
                    <div style="min-width: 220px;">
                        <label style="display: block; font-size: 11.5px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 5px;">
                            <iconify-icon icon="solar:buildings-2-bold" style="color:#0D7A87;"></iconify-icon> 1. Rombel / Kelas
                        </label>
                        <select id="filterKelas" class="form-control" style="font-size: 13.5px; font-weight: 600; height: 40px; border-radius: 10px; border: 1px solid #CBD5E1; width: 100%; padding: 0 12px;">
                            <option value="0">Memuat pilihan kelas...</option>
                        </select>
                    </div>

                    <div style="min-width: 260px;">
                        <label style="display: block; font-size: 11.5px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 5px;">
                            <iconify-icon icon="solar:book-bookmark-bold" style="color:#0D7A87;"></iconify-icon> 2. Mata Pelajaran Anda
                        </label>
                        <select id="filterMapel" class="form-control" style="font-size: 13.5px; font-weight: 600; height: 40px; border-radius: 10px; border: 1px solid #CBD5E1; width: 100%; padding: 0 12px;" disabled>
                            <option value="0">— Pilih Kelas Dahulu —</option>
                        </select>
                    </div>

                    <div style="min-width: 280px;" id="boxFilterAsesmen">
                        <label style="display: block; font-size: 11.5px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 5px;">
                            <iconify-icon icon="solar:clipboard-list-bold" style="color:#0D7A87;"></iconify-icon> 3. Paket Asesmen / Pertemuan
                        </label>
                        <select id="filterAsesmen" class="form-control" style="font-size: 13.5px; font-weight: 600; height: 40px; border-radius: 10px; border: 1px solid #CBD5E1; width: 100%; padding: 0 12px;" disabled>
                            <option value="0">— Pilih Mapel Dahulu —</option>
                        </select>
                    </div>

                    <div style="padding-top: 20px;">
                        <button type="button" id="btnResetFilter" class="btn btn-secondary btn-sm" style="height: 40px; border-radius: 10px; font-weight: 600; padding: 0 14px; background: #F1F5F9; border: 1px solid #CBD5E1; color: #334155; cursor: pointer;">
                            <iconify-icon icon="solar:restart-bold"></iconify-icon> Reset
                        </button>
                    </div>
                </div>

                <!-- Aksi Kanan (Pencarian, Buat Asesmen, Simpan Semua & Cetak) -->
                <div style="display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">
                    <div style="position: relative; min-width: 180px;" id="boxSearchSiswa">
                        <label style="display: block; font-size: 11.5px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 5px;">
                            Cari Siswa
                        </label>
                        <input type="text" id="searchSiswa" placeholder="Ketik nama / NIS..." style="font-size: 13px; height: 40px; border-radius: 10px; padding-left: 32px; border: 1px solid #CBD5E1; width: 100%; box-sizing: border-box;">
                        <iconify-icon icon="solar:magnifer-bold" style="position: absolute; left: 10px; top: 33px; color: #94A3B8; font-size: 15px;"></iconify-icon>
                    </div>

                    <button type="button" id="btnBukaModalTambah" class="btn btn-primary" style="height: 40px; padding: 0 16px; font-weight: 700; font-size: 13px; border-radius: 10px; display: inline-flex; align-items: center; gap: 7px; background: #0D7A87; color: #FFFFFF; border: none; cursor: pointer;">
                        <iconify-icon icon="solar:add-circle-bold" width="17"></iconify-icon> Buat Instrumen
                    </button>

                    <button type="button" id="btnSimpanSemuaNilai" class="btn btn-gold" style="height: 40px; padding: 0 16px; font-weight: 800; font-size: 13px; border-radius: 10px; display: inline-flex; align-items: center; gap: 7px; border:none; cursor: pointer;">
                        <iconify-icon icon="solar:diskette-bold" width="17"></iconify-icon> Simpan Semua Nilai
                    </button>

                    <button type="button" id="btnPrintPdf" class="btn btn-secondary" style="height: 40px; border-radius: 10px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; background: #F1F5F9; border: 1px solid #CBD5E1; color: #334155; padding: 0 14px; cursor: pointer;" disabled>
                        <iconify-icon icon="solar:printer-bold" style="color: #0D7A87;"></iconify-icon> Cetak Lembar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. PETUNJUK WARNA & PANDUAN PENSKORAN GURU (ALA REPORTS.PHP) -->
    <div id="barPanduanGuru" style="display: flex; flex-wrap: wrap; gap: 14px; align-items: center; background: #F8FAFC; border: 1px solid #E2E8F0; padding: 12px 18px; border-radius: 12px; margin-bottom: 18px; font-size: 12px; color: #475569;">
        <div style="font-weight: 800; color: #0F172A; display: inline-flex; align-items: center; gap: 6px;">
            <iconify-icon icon="solar:info-circle-bold" style="color:#0D7A87; font-size: 16px;"></iconify-icon>
            <span id="txtPanduanTitle">Standar Observasi Sikap Guru (Afektif):</span>
        </div>
        <div id="badgePanduan1">
            <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: #15803D;"></span>
            <strong>SB (Sangat Baik):</strong> Skor &ge; 88.00
        </div>
        <div id="badgePanduan2">
            <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: #0369A1;"></span>
            <strong>B (Baik):</strong> 75.00 &ndash; 87.99
        </div>
        <div id="badgePanduan3">
            <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: #B45309;"></span>
            <strong>C (Cukup):</strong> 60.00 &ndash; 74.99
        </div>
        <div id="badgePanduan4">
            <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: #B91C1C;"></span>
            <strong>K (Kurang):</strong> &lt; 60.00
        </div>
        <div style="margin-left: auto; font-weight: 700; color: #0D7A87;">
            <iconify-icon icon="solar:check-read-bold"></iconify-icon> Otomatis Sinkron Rapor
        </div>
    </div>

    <!-- 4. LEMBAR EVALUASI UTAMA (GRADEBOOK TABLE WRAPPER) -->
    <div class="content-card" id="cardGradebookWrap">
        <div class="content-card-body" style="padding: 0;" id="tableWrap">
            
            <!-- Initial Empty State Box -->
            <div class="ilmuna-empty-row" id="emptyStateBox">
                <div class="ilmuna-empty-row__icon"><iconify-icon icon="solar:notebook-bookmark-bold"></iconify-icon></div>
                <h4 style="margin: 0 0 6px; font-size: 16px; font-weight: 800; color: #1E293B;">Pilih Rombel & Mata Pelajaran Terlebih Dahulu</h4>
                <p style="margin: 0 auto; max-width: 540px; color: #64748B; font-size: 13px;">
                    Silakan pilih salah satu rombel kelas dan mata pelajaran yang Anda ampu di atas untuk memulai penilaian observasi sikap atau unjuk kerja praktik siswa.
                </p>
                <div class="ilmuna-empty-row__chips" id="quickChipsContainer"></div>
            </div>

            <!-- Header Info Bar Asesmen Aktif (Tampil Saat Data Dimuat) -->
            <div id="headerInfoAsesmen" style="display: none; padding: 14px 20px; background: #F8FAFC; border-bottom: 2px solid #E2E8F0; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <span id="lblBadgeAsesmen" style="display: inline-block; padding: 2px 8px; border-radius: 6px; font-weight: 800; font-size: 11px; background: #CCFBF1; color: #0F766E;">AFEKTIF</span>
                    <strong id="lblJudulAsesmen" style="font-size: 15px; color: #0F172A;">-</strong>
                    <span id="lblMetaAsesmen" style="font-size: 12px; color: #64748B;">(Pertemuan Ke-1)</span>
                    <span id="lblLockStatusBadge" style="display: none; padding: 3px 10px; border-radius: 6px; font-weight: 800; font-size: 11px; align-items: center; gap: 4px;"></span>
                </div>
                <div id="lblRubrikSummaryPills" style="display: flex; gap: 6px; flex-wrap: wrap;">
                    <!-- Rubrik list pills -->
                </div>
            </div>

            <!-- Desktop View Table -->
            <div class="gradebook-desktop-view" id="desktopTableContainer" style="display: none; overflow-x: auto; max-height: 650px;">
                <table class="ilmuna-gradebook-table" id="tableGradebook">
                    <thead>
                        <tr>
                            <th class="sticky-col col-no" rowspan="2">No</th>
                            <th class="sticky-col col-nis" rowspan="2">NIS</th>
                            <th class="sticky-col col-nama" rowspan="2">Nama Peserta Didik</th>
                            <th id="thRubrikGroup" colspan="4" style="background: #EFF6FF; color: #1D4ED8; border-bottom: 2px solid #3B82F6;">
                                Butir Observasi Rubrik & Skala Skor (1 s.d 4)
                            </th>
                            <th style="width: 100px;" rowspan="2">Skor Angka</th>
                            <th style="width: 80px;" rowspan="2">Predikat</th>
                            <th style="width: 110px;" rowspan="2">Status</th>
                            <th style="min-width: 250px;" rowspan="2">Catatan Perkembangan Karakter / Kinerja</th>
                            <th style="width: 100px; text-align: center;" rowspan="2">Aksi</th>
                        </tr>
                        <tr id="trSubRubrikHeaders">
                            <!-- Subheader butir 1..4 di-render dinamis -->
                        </tr>
                    </thead>
                    <tbody id="tbodyGradebookRows">
                        <!-- Baris siswa di-render via JavaScript -->
                    </tbody>
                </table>
            </div>

            <!-- Mobile View Card Stack (< 900px) -->
            <div class="gradebook-mobile-view" id="mobileCardsContainer" style="display: none; padding: 14px; gap: 14px; flex-direction: column;">
                <!-- Kartu siswa mobile di-render via JavaScript -->
            </div>

        </div>
    </div>

    <!-- 5. PANEL KHUSUS BANK & MANAJEMEN INSTRUMEN (TAB 3: BANK INSTRUMEN) -->
    <div id="panelInstrumenManager" style="display: none;">
        <div class="content-card" style="margin-bottom: 24px;">
            <div class="content-card-body" style="padding: 22px 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px; margin-bottom: 20px; border-bottom: 1px solid #E2E8F0; padding-bottom: 16px;">
                    <div>
                        <h3 style="margin: 0; font-size: 18px; font-weight: 800; color: #1E293B; display: flex; align-items: center; gap: 8px;">
                            <iconify-icon icon="solar:folder-with-files-bold" style="color: #6366F1; font-size: 24px;"></iconify-icon>
                            Bank & Manajemen Instrumen Sesuai Modul Ajar
                        </h3>
                        <p style="margin: 4px 0 0; font-size: 13px; color: #64748B;">
                            Kelola instrumen observasi sikap dan uji kinerja praktik yang terhubung dengan modul ajar pertemuan KBM.
                        </p>
                    </div>
                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <div class="btn-group" style="display: inline-flex; background: #F1F5F9; padding: 4px; border-radius: 10px; border: 1px solid #CBD5E1;">
                            <button type="button" class="btn btn-sm btn-sub-ranah is-active" id="subBtnRanahAll" onclick="filterInstrumenSubRanah('all')" style="border-radius: 8px; font-size: 12px; font-weight: 700; padding: 6px 14px; border: none; cursor: pointer; background: #FFFFFF; color: #0D7A87; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">Semua Ranah</button>
                            <button type="button" class="btn btn-sm btn-sub-ranah" id="subBtnRanahAfk" onclick="filterInstrumenSubRanah('afektif')" style="border-radius: 8px; font-size: 12px; font-weight: 700; padding: 6px 14px; border: none; cursor: pointer; background: transparent; color: #64748B;">Afektif</button>
                            <button type="button" class="btn btn-sm btn-sub-ranah" id="subBtnRanahPsk" onclick="filterInstrumenSubRanah('psikomotorik')" style="border-radius: 8px; font-size: 12px; font-weight: 700; padding: 6px 14px; border: none; cursor: pointer; background: transparent; color: #64748B;">Psikomotorik</button>
                        </div>
                        <button type="button" class="btn btn-primary" onclick="btnBukaModalTambah.click()" style="height: 38px; padding: 0 16px; font-weight: 700; font-size: 13px; border-radius: 10px; background: #0D7A87; color: #FFFFFF; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                            <iconify-icon icon="solar:add-circle-bold" width="16"></iconify-icon> Buat Instrumen Baru
                        </button>
                    </div>
                </div>

                <!-- Kontainer List / Tabel Instrumen -->
                <div id="containerListInstrumen">
                    <div style="text-align: center; padding: 40px; color: #94A3B8;">Memuat data instrumen...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ==============================================================================
     MODAL POPUP 1: BUAT INSTRUMEN ASESMEN BARU (UNIVERSAL NUSANTARA MODAL)
     ============================================================================== -->
<div class="ilm-modal-backdrop" id="modalTambahAsesmen" role="dialog" aria-modal="true">
    <div class="ilm-modal-dialog" style="max-width: 650px;">
        <div class="ilm-modal-gunungan" aria-hidden="true"></div>
        <div class="ilm-modal-ornament-tl" aria-hidden="true"></div>
        <div class="ilm-modal-ornament-tr" aria-hidden="true"></div>

        <!-- Header Modal -->
        <div style="padding: 20px 24px 14px; border-bottom: 1px solid #E2E8F0; position: relative; z-index: 2;">
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="width: 38px; height: 38px; border-radius: 10px; background: rgba(13, 122, 135, 0.12); display: flex; align-items: center; justify-content: center; color: #0D7A87;">
                        <iconify-icon icon="solar:document-add-bold" width="22"></iconify-icon>
                    </div>
                    <div>
                        <h4 style="margin: 0; font-size: 16px; font-weight: 800; color: #1E293B;">Buat Instrumen Asesmen Langsung</h4>
                        <span style="font-size: 12px; color: #64748B;">Observasi Otentik Sikap & Praktik Selaras Modul Ajar</span>
                    </div>
                </div>
                <button type="button" onclick="safeCloseModal('modalTambahAsesmen')" style="background: transparent; border: none; font-size: 20px; color: #94A3B8; cursor: pointer;">
                    <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
                </button>
            </div>
        </div>

        <!-- Form Modal Body -->
        <form id="formTambahAsesmen" style="padding: 20px 24px; position: relative; z-index: 2;">
            <input type="hidden" name="action" value="create_assessment">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 5px;">Ranah Penilaian:</label>
                    <select name="tipe" id="modalInputTipe" class="form-control" style="width: 100%; height: 38px; border-radius: 8px; font-weight: 700; color: #0D7A87;">
                        <option value="afektif">Afektif (Sikap & Karakter)</option>
                        <option value="psikomotorik">Psikomotorik (Keterampilan / Praktik)</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 5px;">Pertemuan Ke:</label>
                    <input type="number" name="pertemuan_ke" id="modalInputPertemuan" value="1" min="1" max="36" class="form-control" style="width: 100%; height: 38px; border-radius: 8px;" required>
                </div>
            </div>

            <!-- Pilihan Modul Ajar Terkait -->
            <div style="margin-bottom: 14px;">
                <label style="display: block; font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 5px;">
                    <iconify-icon icon="solar:document-text-bold" style="color:#0D7A87;"></iconify-icon> Hubungkan dengan Modul Ajar (Pertemuan KBM):
                </label>
                <select name="id_modul_ajar" id="modalInputModulAjar" class="form-control" style="width: 100%; height: 38px; border-radius: 8px;" onchange="onSelectModulAjar(this, 'add')">
                    <option value="0">— Tidak Terikat Modul Ajar (Manual) —</option>
                </select>
                <small style="display: block; color: #64748B; font-size: 11px; margin-top: 3px;">
                    Memilih modul ajar akan otomatis mengisi pertemuan, judul instrumen, dan dimensi profil pelajar.
                </small>
            </div>

            <div style="margin-bottom: 14px;">
                <label style="display: block; font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 5px;">Judul Asesmen / Topik Kegiatan:</label>
                <input type="text" name="judul_asesmen" id="modalInputJudul" placeholder="Contoh: Modul Ajar 01: Merengkuh Martabat Insan / Praktik Bacaan Tartil" class="form-control" style="width: 100%; height: 38px; border-radius: 8px;" required>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 5px;">Teknik Penilaian Guru:</label>
                    <select name="teknik_asesmen" id="modalInputTeknik" class="form-control" style="width: 100%; height: 38px; border-radius: 8px;">
                        <option value="Observasi">Observasi Langsung Guru</option>
                        <option value="Jurnal Sikap">Jurnal Sikap & Catatan Anekdot</option>
                        <option value="Praktik">Praktik Kinerja Langsung</option>
                        <option value="Demonstrasi">Demonstrasi Unjuk Kerja</option>
                        <option value="Portofolio">Portofolio / Hasil Karya</option>
                    </select>
                </div>
                <div id="modalBoxKKTP" style="display: none;">
                    <label style="display: block; font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 5px;">Batas Patokan KKTP:</label>
                    <input type="number" step="0.5" name="kktp" value="75.00" class="form-control" style="width: 100%; height: 38px; border-radius: 8px;">
                </div>
                <div id="modalBoxDimensi">
                    <label style="display: block; font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 5px;">Dimensi Profil Pelajar:</label>
                    <input type="text" name="dimensi_profil" id="modalInputDimensi" value="Beriman, Mandiri, Gotong Royong, Bernalar Kritis" class="form-control" style="width: 100%; height: 38px; border-radius: 8px;">
                </div>
            </div>

            <div style="margin-bottom: 14px;">
                <label style="display: block; font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 5px;">Tujuan Asesmen / Catatan Pengamatan:</label>
                <textarea name="tujuan_asesmen" rows="2" placeholder="Tujuan yang diobservasi / diukur..." class="form-control" style="width: 100%; border-radius: 8px;"></textarea>
            </div>

            <div style="display: flex; align-items: center; justify-content: flex-end; gap: 10px; border-top: 1px solid #E2E8F0; padding-top: 14px;">
                <button type="button" onclick="safeCloseModal('modalTambahAsesmen')" style="padding: 8px 16px; border-radius: 8px; background: #F1F5F9; border: 1px solid #CBD5E1; color: #475569; font-weight: 600; cursor: pointer;">Batal</button>
                <button type="submit" id="btnSubmitModal" style="padding: 8px 18px; border-radius: 8px; background: #0D7A87; color: #FFFFFF; border: none; font-weight: 700; cursor: pointer;">
                    Simpan & Buat Butir
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ==============================================================================
     MODAL POPUP 2: EDIT INSTRUMEN ASESMEN (UNIVERSAL NUSANTARA MODAL)
     ============================================================================== -->
<div class="ilm-modal-backdrop" id="modalEditAsesmen" role="dialog" aria-modal="true">
    <div class="ilm-modal-dialog" style="max-width: 650px;">
        <div class="ilm-modal-gunungan" aria-hidden="true"></div>
        <div class="ilm-modal-ornament-tl" aria-hidden="true"></div>
        <div class="ilm-modal-ornament-tr" aria-hidden="true"></div>

        <div style="padding: 20px 24px 14px; border-bottom: 1px solid #E2E8F0; position: relative; z-index: 2;">
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="width: 38px; height: 38px; border-radius: 10px; background: rgba(99, 102, 241, 0.12); display: flex; align-items: center; justify-content: center; color: #6366F1;">
                        <iconify-icon icon="solar:pen-new-square-bold" width="22"></iconify-icon>
                    </div>
                    <div>
                        <h4 style="margin: 0; font-size: 16px; font-weight: 800; color: #1E293B;">Edit Data Instrumen Asesmen</h4>
                        <span style="font-size: 12px; color: #64748B;">Perbarui data instrumen dan keselarasan modul ajar</span>
                    </div>
                </div>
                <button type="button" onclick="safeCloseModal('modalEditAsesmen')" style="background: transparent; border: none; font-size: 20px; color: #94A3B8; cursor: pointer;">
                    <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
                </button>
            </div>
        </div>

        <form id="formEditAsesmen" style="padding: 20px 24px; position: relative; z-index: 2;">
            <input type="hidden" name="action" value="update_instrument">
            <input type="hidden" name="tipe" id="editModalTipe" value="afektif">
            <input type="hidden" name="id_asesmen" id="editModalIdAsesmen" value="0">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 5px;">Ranah Penilaian:</label>
                    <input type="text" id="editModalTipeLabel" class="form-control" style="width: 100%; height: 38px; border-radius: 8px; font-weight: 700; background: #F8FAFC; color: #0D7A87;" readonly>
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 5px;">Pertemuan Ke:</label>
                    <input type="number" name="pertemuan_ke" id="editModalPertemuan" min="1" max="36" class="form-control" style="width: 100%; height: 38px; border-radius: 8px;" required>
                </div>
            </div>

            <div style="margin-bottom: 14px;">
                <label style="display: block; font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 5px;">
                    <iconify-icon icon="solar:document-text-bold" style="color:#0D7A87;"></iconify-icon> Modul Ajar Terkait:
                </label>
                <select name="id_modul_ajar" id="editModalModulAjar" class="form-control" style="width: 100%; height: 38px; border-radius: 8px;" onchange="onSelectModulAjar(this, 'edit')">
                    <option value="0">— Tidak Terikat Modul Ajar (Manual) —</option>
                </select>
            </div>

            <div style="margin-bottom: 14px;">
                <label style="display: block; font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 5px;">Judul Asesmen / Topik Kegiatan:</label>
                <input type="text" name="judul_asesmen" id="editModalJudul" class="form-control" style="width: 100%; height: 38px; border-radius: 8px;" required>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 5px;">Teknik Penilaian:</label>
                    <select name="teknik_asesmen" id="editModalTeknik" class="form-control" style="width: 100%; height: 38px; border-radius: 8px;">
                        <option value="Observasi">Observasi Langsung Guru</option>
                        <option value="Jurnal Sikap">Jurnal Sikap & Catatan Anekdot</option>
                        <option value="Praktik">Praktik Kinerja Langsung</option>
                        <option value="Demonstrasi">Demonstrasi Unjuk Kerja</option>
                        <option value="Portofolio">Portofolio / Hasil Karya</option>
                    </select>
                </div>
                <div id="editModalBoxKKTP" style="display: none;">
                    <label style="display: block; font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 5px;">Batas Patokan KKTP:</label>
                    <input type="number" step="0.5" name="kktp" id="editModalKKTP" value="75.00" class="form-control" style="width: 100%; height: 38px; border-radius: 8px;">
                </div>
                <div id="editModalBoxDimensi">
                    <label style="display: block; font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 5px;">Dimensi Profil Pelajar:</label>
                    <input type="text" name="dimensi_profil" id="editModalDimensi" class="form-control" style="width: 100%; height: 38px; border-radius: 8px;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 5px;">Status Instrumen:</label>
                    <select name="status" id="editModalStatus" class="form-control" style="width: 100%; height: 38px; border-radius: 8px; font-weight: 700;">
                        <option value="Aktif">Aktif (Dapat Dinilai)</option>
                        <option value="Draft">Draft (Disembunyikan)</option>
                        <option value="Ditutup">Ditutup (Arsip)</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 5px;">Tujuan Asesmen / Catatan:</label>
                    <input type="text" name="tujuan_asesmen" id="editModalTujuan" class="form-control" style="width: 100%; height: 38px; border-radius: 8px;" placeholder="Tujuan yang diobservasi...">
                </div>
            </div>

            <div style="display: flex; align-items: center; justify-content: flex-end; gap: 10px; border-top: 1px solid #E2E8F0; padding-top: 14px;">
                <button type="button" onclick="safeCloseModal('modalEditAsesmen')" style="padding: 8px 16px; border-radius: 8px; background: #F1F5F9; border: 1px solid #CBD5E1; color: #475569; font-weight: 600; cursor: pointer;">Batal</button>
                <button type="submit" id="btnSubmitEditModal" style="padding: 8px 18px; border-radius: 8px; background: #0D7A87; color: #FFFFFF; border: none; font-weight: 700; cursor: pointer;">
                    Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ==============================================================================
     MODAL POPUP 3: KELOLA BUTIR RUBRIK & INDIKATOR (UNIVERSAL NUSANTARA MODAL)
     ============================================================================== -->
<div class="ilm-modal-backdrop" id="modalKelolaRubrik" role="dialog" aria-modal="true">
    <div class="ilm-modal-dialog" style="max-width: 800px;">
        <div class="ilm-modal-gunungan" aria-hidden="true"></div>
        <div class="ilm-modal-ornament-tl" aria-hidden="true"></div>
        <div class="ilm-modal-ornament-tr" aria-hidden="true"></div>

        <div style="padding: 20px 24px 14px; border-bottom: 1px solid #E2E8F0; position: relative; z-index: 2;">
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="width: 38px; height: 38px; border-radius: 10px; background: rgba(13, 122, 135, 0.12); display: flex; align-items: center; justify-content: center; color: #0D7A87;">
                        <iconify-icon icon="solar:checklist-minimalistic-bold" width="22"></iconify-icon>
                    </div>
                    <div>
                        <h4 style="margin: 0; font-size: 16px; font-weight: 800; color: #1E293B;" id="lblRubrikModalTitle">Kelola Butir Rubrik & Indikator</h4>
                        <span style="font-size: 12px; color: #64748B;" id="lblRubrikModalSubtitle">Tambah, edit, dan hapus indikator pengamatan</span>
                    </div>
                </div>
                <button type="button" onclick="safeCloseModal('modalKelolaRubrik')" style="background: transparent; border: none; font-size: 20px; color: #94A3B8; cursor: pointer;">
                    <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
                </button>
            </div>
        </div>

        <div style="padding: 20px 24px; position: relative; z-index: 2; max-height: 72vh; overflow-y: auto;">
            <!-- Form Tambah / Edit Butir Rubrik -->
            <form id="formRubrikItem" style="background: #F8FAFC; border: 1.5px dashed #CBD5E1; border-radius: 14px; padding: 16px; margin-bottom: 20px;">
                <input type="hidden" name="action" value="save_rubrik_item">
                <input type="hidden" name="tipe" id="rubrikItemTipe" value="afektif">
                <input type="hidden" name="id_asesmen" id="rubrikItemIdAsesmen" value="0">
                <input type="hidden" name="item_id" id="rubrikItemId" value="0">

                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <strong style="font-size: 13px; color: #0F172A;" id="lblFormRubrikHeader">
                        <iconify-icon icon="solar:add-circle-bold" style="color: #0D7A87;"></iconify-icon> Tambah Butir Indikator Baru
                    </strong>
                    <button type="button" id="btnCancelEditRubrik" onclick="resetRubrikForm()" style="display: none; background: transparent; border: none; color: #EF4444; font-size: 12px; font-weight: 700; cursor: pointer;">
                        Batal Edit
                    </button>
                </div>

                <div style="display: grid; grid-template-columns: 85px 1fr; gap: 12px; margin-bottom: 10px;">
                    <div>
                        <label style="display: block; font-size: 11px; font-weight: 700; color: #475569; margin-bottom: 4px;">No. Urut:</label>
                        <input type="number" name="nomor_urut" id="rubrikInputNomor" value="1" min="1" max="20" class="form-control" style="width: 100%; height: 36px; border-radius: 8px;" required>
                    </div>
                    <div>
                        <label style="display: block; font-size: 11px; font-weight: 700; color: #475569; margin-bottom: 4px;" id="lblRubrikAspek">Aspek Sikap / Tahap:</label>
                        <input type="text" name="aspek" id="rubrikInputAspek" placeholder="Contoh: Kejujuran & Disiplin / Persiapan Alat" class="form-control" style="width: 100%; height: 36px; border-radius: 8px;" required>
                    </div>
                </div>

                <div style="margin-bottom: 10px;">
                    <label style="display: block; font-size: 11px; font-weight: 700; color: #475569; margin-bottom: 4px;" id="lblRubrikIndikator">Deskripsi Perilaku / Kinerja yang Diamati:</label>
                    <input type="text" name="indikator" id="rubrikInputIndikator" placeholder="Contoh: Selalu datang tepat waktu dan mematuhi tata tertib kelas." class="form-control" style="width: 100%; height: 36px; border-radius: 8px;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr auto; gap: 12px; align-items: flex-end;">
                    <div>
                        <label style="display: block; font-size: 11px; font-weight: 700; color: #475569; margin-bottom: 4px;">Deskriptor Skala Rubrik (1 s.d 4):</label>
                        <input type="text" name="rubrik_deskriptor" id="rubrikInputDeskriptor" value="4=Sangat Baik, 3=Baik, 2=Cukup, 1=Kurang" class="form-control" style="width: 100%; height: 36px; border-radius: 8px;">
                    </div>
                    <button type="submit" id="btnSubmitRubrikItem" style="height: 36px; padding: 0 18px; border-radius: 8px; background: #0D7A87; color: #FFFFFF; border: none; font-weight: 700; font-size: 12.5px; cursor: pointer; white-space: nowrap;">
                        Simpan Butir
                    </button>
                </div>
            </form>

            <!-- Tabel Daftar Butir Aktif -->
            <div style="border: 1px solid #E2E8F0; border-radius: 12px; overflow: hidden;">
                <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                    <thead>
                        <tr style="background: #F8FAFC; border-bottom: 1px solid #E2E8F0; text-align: left; color: #475569;">
                            <th style="padding: 10px; width: 45px; text-align: center;">No</th>
                            <th style="padding: 10px; width: 180px;">Aspek / Tahap</th>
                            <th style="padding: 10px;">Indikator Perilaku & Rubrik</th>
                            <th style="padding: 10px; width: 85px; text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyRubrikItemList">
                        <!-- Render via JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ==============================================================================
     CLIENT LOGIC: CASCADING ENGINE & IN-PLACE EVALUATION
     ============================================================================== -->
<script>
// ==============================================================================
// 1. UNIVERSAL NOTIFICATION & MODAL BRIDGE
// ==============================================================================
function notifySuccess(msg, title = 'Berhasil') {
    if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
        window.Ilmuna.toast('success', title, msg, 4000);
    } else if (window.Ilmuna && typeof window.Ilmuna.success === 'function') {
        window.Ilmuna.success(title, msg, 4000);
    } else {
        alert(msg);
    }
}

function notifyError(msg, title = 'Perhatian') {
    if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
        window.Ilmuna.toast('error', title, msg, 4500);
    } else if (window.Ilmuna && typeof window.Ilmuna.error === 'function') {
        window.Ilmuna.error(title, msg, 4500);
    } else {
        alert(msg);
    }
}

function notifyWarning(msg, title = 'Peringatan') {
    if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
        window.Ilmuna.toast('warning', title, msg, 4000);
    } else if (window.Ilmuna && typeof window.Ilmuna.warning === 'function') {
        window.Ilmuna.warning(title, msg, 4000);
    } else {
        alert(msg);
    }
}

function confirmAction(opts) {
    opts = opts || {};
    const title = opts.title || 'Konfirmasi';
    const text = opts.text || opts.message || 'Lanjutkan proses ini?';
    const confirmBtn = opts.confirmButtonText || 'Ya, Lanjutkan';
    const cancelBtn = opts.cancelButtonText || 'Batal';

    if (window.Ilmuna && typeof window.Ilmuna.confirm === 'function') {
        window.Ilmuna.confirm('warning', title, text, confirmBtn, cancelBtn).then(function(ok) {
            if (ok && typeof opts.onConfirm === 'function') {
                opts.onConfirm();
            } else if (!ok && typeof opts.onCancel === 'function') {
                opts.onCancel();
            }
        });
    } else if (window.confirm(title + '\n\n' + text)) {
        if (typeof opts.onConfirm === 'function') opts.onConfirm();
    } else {
        if (typeof opts.onCancel === 'function') opts.onCancel();
    }
}

// Polyfill window.Ilmuna methods
window.Ilmuna = window.Ilmuna || {};
window.Ilmuna.showSuccess = notifySuccess;
window.Ilmuna.showError = notifyError;
window.Ilmuna.showWarning = notifyWarning;
window.Ilmuna.showConfirm = confirmAction;

// Universal Modal Helper Wrapper
function safeOpenModal(id) {
    if (typeof window.openIlmunaModal === 'function' && window.openIlmunaModal !== safeOpenModal) {
        window.openIlmunaModal(id);
    } else {
        const el = document.getElementById(id);
        if (el) {
            el.classList.add('ilm-modal-backdrop', 'is-open', 'show', 'active');
            el.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
    }
}
function safeCloseModal(id) {
    if (typeof window.closeIlmunaModal === 'function' && window.closeIlmunaModal !== safeCloseModal) {
        window.closeIlmunaModal(id);
    } else {
        const el = document.getElementById(id);
        if (el) {
            el.classList.remove('is-open', 'show', 'active');
            el.style.display = 'none';
            document.body.style.overflow = '';
        }
    }
}
window.safeOpenModal = safeOpenModal;
window.safeCloseModal = safeCloseModal;
if (!window.openIlmunaModal) window.openIlmunaModal = safeOpenModal;
if (!window.closeIlmunaModal) window.closeIlmunaModal = safeCloseModal;
if (!window.ilmunaOpenModal) window.ilmunaOpenModal = safeOpenModal;
if (!window.ilmunaCloseModal) window.ilmunaCloseModal = safeCloseModal;

document.addEventListener('DOMContentLoaded', function() {
    let currentTab = 'afektif';
    let currentKelasId = 0;
    let currentKmpId = 0;
    let currentAsesmenId = 0;
    let currentGradebookRequestId = 0;
    let isCurrentAssessmentSaved = false;
    let rawItems = [];
    let rawStudents = [];
    let rawModulAjar = [];
    let rawInstruments = [];
    let rawAsesmen = null;
    let currentSubRanahFilter = 'all';
    let currentManagingRubrik = { id: 0, tipe: 'afektif', title: '' };

    const filterKelas = document.getElementById('filterKelas');
    const filterMapel = document.getElementById('filterMapel');
    const filterAsesmen = document.getElementById('filterAsesmen');
    const btnResetFilter = document.getElementById('btnResetFilter');
    const searchSiswa = document.getElementById('searchSiswa');

    const btnBukaModalTambah = document.getElementById('btnBukaModalTambah');
    const btnSimpanSemuaNilai = document.getElementById('btnSimpanSemuaNilai');
    const btnPrintPdf = document.getElementById('btnPrintPdf');

    const emptyStateBox = document.getElementById('emptyStateBox');
    const headerInfoAsesmen = document.getElementById('headerInfoAsesmen');
    const desktopTableContainer = document.getElementById('desktopTableContainer');
    const mobileCardsContainer = document.getElementById('mobileCardsContainer');
    const quickChipsContainer = document.getElementById('quickChipsContainer');

    // Tab Navigation
    document.getElementById('tabBtnAfektif').addEventListener('click', () => switchTab('afektif'));
    document.getElementById('tabBtnPsikomotorik').addEventListener('click', () => switchTab('psikomotorik'));
    document.getElementById('tabBtnInstrumen').addEventListener('click', () => switchTab('instrumen'));

    function switchTab(tab, targetAsesmenId = null) {
        currentTab = tab;
        document.getElementById('tabBtnAfektif').classList.toggle('is-active', tab === 'afektif');
        document.getElementById('tabBtnPsikomotorik').classList.toggle('is-active', tab === 'psikomotorik');
        document.getElementById('tabBtnInstrumen').classList.toggle('is-active', tab === 'instrumen');

        const barPanduan = document.getElementById('barPanduanGuru');
        const cardGradebook = document.getElementById('cardGradebookWrap');
        const panelInstrumen = document.getElementById('panelInstrumenManager');
        const boxAsesmen = document.getElementById('boxFilterAsesmen');
        const boxSearch = document.getElementById('boxSearchSiswa');

        if (tab === 'instrumen') {
            if (barPanduan) barPanduan.style.display = 'none';
            if (cardGradebook) cardGradebook.style.display = 'none';
            if (panelInstrumen) panelInstrumen.style.display = 'block';
            if (boxAsesmen) boxAsesmen.style.display = 'none';
            if (boxSearch) boxSearch.style.display = 'none';
            if (btnSimpanSemuaNilai) btnSimpanSemuaNilai.style.display = 'none';
            if (btnPrintPdf) btnPrintPdf.style.display = 'none';

            if (currentKmpId > 0) {
                loadInstrumentsManager(currentKmpId);
            }
            return;
        }

        // Tab Afektif atau Psikomotorik
        if (barPanduan) barPanduan.style.display = 'flex';
        if (cardGradebook) cardGradebook.style.display = 'block';
        if (panelInstrumen) panelInstrumen.style.display = 'none';
        if (boxAsesmen) boxAsesmen.style.display = 'block';
        if (boxSearch) boxSearch.style.display = 'block';
        if (btnSimpanSemuaNilai) btnSimpanSemuaNilai.style.display = 'inline-flex';
        if (btnPrintPdf) btnPrintPdf.style.display = 'inline-flex';

        // Panduan Bar Update
        if (tab === 'afektif') {
            document.getElementById('txtPanduanTitle').innerText = 'Standar Observasi Sikap Guru (Afektif):';
            document.getElementById('badgePanduan1').innerHTML = '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#15803D;"></span> <strong>SB (Sangat Baik):</strong> Skor &ge; 88.00';
            document.getElementById('badgePanduan2').innerHTML = '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#0369A1;"></span> <strong>B (Baik):</strong> 75.00 &ndash; 87.99';
            document.getElementById('badgePanduan3').innerHTML = '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#B45309;"></span> <strong>C (Cukup):</strong> 60.00 &ndash; 74.99';
            document.getElementById('badgePanduan4').innerHTML = '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#B91C1C;"></span> <strong>K (Kurang):</strong> &lt; 60.00';
            document.getElementById('thRubrikGroup').innerText = 'Butir Observasi Sikap Profil Pelajar (Skala 1 s.d 4)';
            document.getElementById('thRubrikGroup').style.background = '#EFF6FF';
            document.getElementById('thRubrikGroup').style.color = '#1D4ED8';
            document.getElementById('thRubrikGroup').style.borderColor = '#3B82F6';
            document.getElementById('modalInputTipe').value = 'afektif';
            document.getElementById('modalBoxKKTP').style.display = 'none';
            document.getElementById('modalBoxDimensi').style.display = 'block';
        } else {
            document.getElementById('txtPanduanTitle').innerText = 'Standar Uji Kinerja Praktik Guru (Psikomotorik):';
            document.getElementById('badgePanduan1').innerHTML = '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#15803D;"></span> <strong>A (Sangat Mahir):</strong> Skor &ge; 90.00';
            document.getElementById('badgePanduan2').innerHTML = '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#0369A1;"></span> <strong>B (Mahir/Cakap):</strong> 80.00 &ndash; 89.99';
            document.getElementById('badgePanduan3').innerHTML = '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#B45309;"></span> <strong>C (Layak):</strong> 70.00 &ndash; 79.99';
            document.getElementById('badgePanduan4').innerHTML = '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#B91C1C;"></span> <strong>D/E (Perlu Bimbingan):</strong> &lt; 70.00';
            document.getElementById('thRubrikGroup').innerText = 'Rubrik Kinerja Praktik (Tahapan & Kaidah)';
            document.getElementById('thRubrikGroup').style.background = '#FFFBEB';
            document.getElementById('thRubrikGroup').style.color = '#B45309';
            document.getElementById('thRubrikGroup').style.borderColor = '#F59E0B';
            document.getElementById('modalInputTipe').value = 'psikomotorik';
            document.getElementById('modalBoxKKTP').style.display = 'block';
            document.getElementById('modalBoxDimensi').style.display = 'none';
        }

        if (currentKmpId > 0) {
            loadAssessments(currentKmpId, targetAsesmenId);
        }
    }

    // Modal Input Tipe Switcher
    document.getElementById('modalInputTipe').addEventListener('change', function() {
        if (this.value === 'afektif') {
            document.getElementById('modalBoxKKTP').style.display = 'none';
            document.getElementById('modalBoxDimensi').style.display = 'block';
        } else {
            document.getElementById('modalBoxKKTP').style.display = 'block';
            document.getElementById('modalBoxDimensi').style.display = 'none';
        }
    });

    // 1. Inisialisasi Pilihan Kelas (Step 1)
    loadClasses();

    async function loadClasses() {
        try {
            const formData = new FormData();
            formData.append('action', 'list_kelas_options');
            const res = await fetch('penilaian.php', { method: 'POST', body: formData });
            const json = await res.json();

            if (json.success && json.data.kelas.length > 0) {
                let optHtml = '<option value="0">— Pilih Rombel / Kelas —</option>';
                let chipHtml = '';

                json.data.kelas.forEach(k => {
                    optHtml += `<option value="${k.id}">${k.nama_kelas} (${k.total_siswa} Siswa)</option>`;
                    chipHtml += `<button type="button" class="ilmuna-empty-chip" onclick="selectQuickClass(${k.id})"><iconify-icon icon="solar:users-group-rounded-bold"></iconify-icon> ${k.nama_kelas}</button>`;
                });

                filterKelas.innerHTML = optHtml;
                quickChipsContainer.innerHTML = chipHtml;

                // Default Kosong: Biarkan guru memilih rombel secara eksplisit
                filterKelas.value = '0';
                resetGradebookView(true);
            } else {
                filterKelas.innerHTML = '<option value="0">Tidak ada rombel kelas</option>';
                resetGradebookView(true);
            }
        } catch (err) {
            console.error(err);
            filterKelas.innerHTML = '<option value="0">Gagal memuat kelas</option>';
            resetGradebookView(true);
        }
    }

    window.selectQuickClass = function(idKelas) {
        filterKelas.value = idKelas;
        filterKelas.dispatchEvent(new Event('change'));
    };

    // 2. Event OnChange Kelas -> Muat Mata Pelajaran (Step 2)
    filterKelas.addEventListener('change', async function() {
        currentKelasId = parseInt(this.value) || 0;
        currentKmpId = 0;
        currentAsesmenId = 0;

        filterMapel.innerHTML = '<option value="0">Memuat mata pelajaran...</option>';
        filterMapel.disabled = true;
        filterAsesmen.innerHTML = '<option value="0">— Pilih Mapel Dahulu —</option>';
        filterAsesmen.disabled = true;

        if (currentKelasId <= 0) {
            filterMapel.innerHTML = '<option value="0">— Pilih Kelas Dahulu —</option>';
            resetGradebookView(true);
            return;
        }

        resetGradebookView(true);

        try {
            const formData = new FormData();
            formData.append('action', 'get_mapel_by_kelas');
            formData.append('id_kelas', currentKelasId);

            const res = await fetch('penilaian.php', { method: 'POST', body: formData });
            const json = await res.json();

            if (json.success && json.data.length > 0) {
                let html = '<option value="0">— Pilih Mata Pelajaran —</option>';
                json.data.forEach(m => {
                    html += `<option value="${m.id}">${m.nama_mata_pelajaran} (${m.kode_mapel || 'MAPEL'}) &mdash; KKTP: ${m.kktp}</option>`;
                });
                filterMapel.innerHTML = html;
                filterMapel.disabled = false;
                filterMapel.value = '0';
                resetGradebookView(true);
            } else {
                filterMapel.innerHTML = '<option value="0">Tidak ada mata pelajaran di kelas ini</option>';
                resetGradebookView(true);
            }
        } catch (err) {
            console.error(err);
            filterMapel.innerHTML = '<option value="0">Gagal memuat mata pelajaran</option>';
            resetGradebookView(true);
        }
    });

    // 3. Event OnChange Mapel -> Muat Paket Asesmen & Modul Ajar (Step 3)
    filterMapel.addEventListener('change', function() {
        currentKmpId = parseInt(this.value) || 0;
        currentAsesmenId = 0;

        if (currentKmpId <= 0) {
            filterAsesmen.innerHTML = '<option value="0">— Pilih Mapel Dahulu —</option>';
            filterAsesmen.disabled = true;
            resetGradebookView(true);
            return;
        }

        resetGradebookView(true);
        loadModulAjarOptions(currentKmpId);

        if (currentTab === 'instrumen') {
            loadInstrumentsManager(currentKmpId);
        } else {
            loadAssessments(currentKmpId);
        }
    });

    // Muat Dropdown Pilihan Modul Ajar Berdasarkan Kelas & Mapel
    async function loadModulAjarOptions(idKmp) {
        const selAdd = document.getElementById('modalInputModulAjar');
        const selEdit = document.getElementById('editModalModulAjar');
        if (!selAdd && !selEdit) return;

        try {
            const formData = new FormData();
            formData.append('action', 'get_modul_ajar_options');
            formData.append('id_kmp', idKmp);

            const res = await fetch('penilaian.php', { method: 'POST', body: formData });
            const json = await res.json();

            if (json.success && Array.isArray(json.data)) {
                rawModulAjar = json.data;
                let optHtml = '<option value="0">— Tidak Terikat Modul Ajar (Manual) —</option>';
                rawModulAjar.forEach(m => {
                    optHtml += `<option value="${m.id}">Pertemuan ${m.pertemuan_ke}: ${escapeHtml(m.judul_modul)}</option>`;
                });
                if (selAdd) selAdd.innerHTML = optHtml;
                if (selEdit) selEdit.innerHTML = optHtml;
            } else {
                rawModulAjar = [];
                const fallback = '<option value="0">— Tidak Terikat Modul Ajar (Manual) —</option>';
                if (selAdd) selAdd.innerHTML = fallback;
                if (selEdit) selEdit.innerHTML = fallback;
            }
        } catch (err) {
            console.error('Error loading modul ajar:', err);
        }
    }

    // Auto-fill Pertemuan, Judul Asesmen (= Judul Modul), dan Profil Pelajar saat Modul Ajar Dipilih
    window.onSelectModulAjar = function(selectEl, mode) {
        const idModul = parseInt(selectEl.value) || 0;
        if (idModul <= 0) return;

        const modul = rawModulAjar.find(m => String(m.id) === String(idModul));
        if (!modul) return;

        if (mode === 'add') {
            const pertInput = document.getElementById('modalInputPertemuan');
            if (pertInput) pertInput.value = modul.pertemuan_ke;

            const judulInput = document.getElementById('modalInputJudul');
            if (judulInput) judulInput.value = modul.judul_modul;

            const dimInput = document.getElementById('modalInputDimensi');
            if (dimInput && modul.profil_pelajar_pancasila) {
                dimInput.value = modul.profil_pelajar_pancasila;
            }
        } else if (mode === 'edit') {
            const pertInput = document.getElementById('editModalPertemuan');
            if (pertInput) pertInput.value = modul.pertemuan_ke;

            const judulInput = document.getElementById('editModalJudul');
            if (judulInput) judulInput.value = modul.judul_modul;

            const dimInput = document.getElementById('editModalDimensi');
            if (dimInput && modul.profil_pelajar_pancasila) {
                dimInput.value = modul.profil_pelajar_pancasila;
            }
        }
    };

    async function loadAssessments(idKmp, targetAsesmenId = null) {
        try {
            filterAsesmen.innerHTML = '<option value="0">Memuat paket asesmen...</option>';
            filterAsesmen.disabled = true;

            const formData = new FormData();
            formData.append('action', 'get_assessments');
            formData.append('tipe', currentTab);
            formData.append('id_kmp', idKmp);

            const res = await fetch('penilaian.php', { method: 'POST', body: formData });
            const json = await res.json();

            if (json.success) {
                // Update badge counter
                if (currentTab === 'afektif') {
                    document.getElementById('badgeAfektifCount').innerText = json.data.length;
                } else {
                    document.getElementById('badgePsikomotorikCount').innerText = json.data.length;
                }

                if (json.data.length > 0) {
                    let html = '<option value="0">— Pilih Paket Asesmen —</option>';
                    json.data.forEach((a) => {
                        const sel = (targetAsesmenId && String(a.id) === String(targetAsesmenId)) ? 'selected' : '';
                        html += `<option value="${a.id}" ${sel}>P.${a.pertemuan_ke}: ${a.judul_asesmen} (${a.teknik_asesmen})</option>`;
                    });
                    filterAsesmen.innerHTML = html;
                    filterAsesmen.disabled = false;

                    // HANYA auto-load jika ada targetAsesmenId spesifik (misal setelah buat asesmen baru)
                    if (targetAsesmenId && json.data.some(a => String(a.id) === String(targetAsesmenId))) {
                        filterAsesmen.value = targetAsesmenId;
                        currentAsesmenId = parseInt(targetAsesmenId);
                        loadGradebookData(currentAsesmenId, idKmp);
                    } else {
                        filterAsesmen.value = '0';
                        resetGradebookView(true);
                    }
                } else {
                    filterAsesmen.innerHTML = '<option value="0">(Belum ada instrumen, klik "Buat Instrumen")</option>';
                    filterAsesmen.disabled = false;
                    resetGradebookView(true);
                }
            }
        } catch (err) {
            console.error(err);
            filterAsesmen.innerHTML = '<option value="0">Gagal memuat asesmen</option>';
            resetGradebookView(true);
        }
    }

    // 4. Event OnChange Paket Asesmen -> Muat Lembar Nilai Siswa (Step 4)
    filterAsesmen.addEventListener('change', function() {
        currentAsesmenId = parseInt(this.value) || 0;
        if (currentAsesmenId > 0 && currentKmpId > 0) {
            loadGradebookData(currentAsesmenId, currentKmpId);
        } else {
            resetGradebookView(true);
        }
    });

    // 5. Muat Data Lembar Penilaian Siswa (Gradebook Data-First)
    async function loadGradebookData(idAsesmen, idKmp) {
        const thisReqId = ++currentGradebookRequestId;
        try {
            emptyStateBox.style.display = 'none';
            desktopTableContainer.style.display = 'none';
            mobileCardsContainer.style.display = 'none';
            headerInfoAsesmen.style.display = 'none';

            const formData = new FormData();
            formData.append('action', 'get_assessment_data');
            formData.append('tipe', currentTab);
            formData.append('id_asesmen', idAsesmen);
            formData.append('id_kmp', idKmp);

            const res = await fetch('penilaian.php', { method: 'POST', body: formData });
            const json = await res.json();

            if (thisReqId !== currentGradebookRequestId) {
                return; // Stale request, abaikan!
            }

            if (json.success) {
                rawAsesmen = json.data.asesmen || null;
                const a = rawAsesmen || {};
                rawItems = json.data.items || [];
                rawStudents = json.data.students || [];
                isCurrentAssessmentSaved = Boolean(json.data.is_saved);

                emptyStateBox.style.display = 'none';

                // Render Header Info
                headerInfoAsesmen.style.display = 'flex';
                document.getElementById('lblBadgeAsesmen').innerText = currentTab.toUpperCase();
                document.getElementById('lblBadgeAsesmen').style.background = (currentTab === 'afektif') ? '#CCFBF1' : '#FEF3C7';
                document.getElementById('lblBadgeAsesmen').style.color = (currentTab === 'afektif') ? '#0F766E' : '#B45309';
                document.getElementById('lblJudulAsesmen').innerText = a.judul_asesmen;
                document.getElementById('lblMetaAsesmen').innerText = `(${a.nama_kelas} &bull; ${a.nama_mata_pelajaran} &bull; Pertemuan Ke-${a.pertemuan_ke} &bull; ${a.teknik_asesmen})` + (a.kktp ? ` &bull; KKTP: ${a.kktp}` : '');

                // Status Badge Terkunci / Mode Penilaian
                updateHeaderSummaryStatus();

                let pillSummary = '';
                rawItems.forEach(item => {
                    const title = item.aspek_sikap || item.tahap_keterampilan || `Butir ${item.nomor_urut}`;
                    pillSummary += `<span style="background: #FFFFFF; border: 1px solid #CBD5E1; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 700; color: #334155;">#${item.nomor_urut}: ${title}</span>`;
                });
                document.getElementById('lblRubrikSummaryPills').innerHTML = pillSummary;

                // Render Subheader Rubrik di Tabel Desktop
                let trSub = '';
                if (rawItems.length > 0) {
                    rawItems.forEach(item => {
                        const title = item.aspek_sikap || item.tahap_keterampilan || `Butir ${item.nomor_urut}`;
                        trSub += `<th style="padding: 6px 8px; font-size: 11px; min-width: 140px; text-align: center;">${title}</th>`;
                    });
                    document.getElementById('thRubrikGroup').setAttribute('colspan', rawItems.length);
                } else {
                    trSub = '<th style="padding: 6px 8px; font-size: 11px;">Skala Skor</th>';
                    document.getElementById('thRubrikGroup').setAttribute('colspan', 1);
                }
                document.getElementById('trSubRubrikHeaders').innerHTML = trSub;

                // Render Siswa Rows
                renderGradebookRows();

                btnPrintPdf.disabled = false;
            } else {
                notifyError(json.message || 'Gagal memuat lembar penilaian.');
                resetGradebookView(true);
            }
        } catch (err) {
            if (thisReqId !== currentGradebookRequestId) return;
            console.error(err);
            notifyError('Terjadi kesalahan koneksi.');
            resetGradebookView(true);
        }
    }

    // Helper Update Status Ringkasan Header & Tombol Simpan Massal
    function updateHeaderSummaryStatus() {
        const unsavedCount = rawStudents.filter(s => !s.sudah_dinilai).length;
        const badgeLock = document.getElementById('lblLockStatusBadge');
        
        if (rawStudents.length > 0 && unsavedCount === 0) {
            isCurrentAssessmentSaved = true;
            btnSimpanSemuaNilai.disabled = true;
            btnSimpanSemuaNilai.innerHTML = '<iconify-icon icon="solar:check-circle-bold" width="17"></iconify-icon> Semua Nilai Terkunci';
            btnSimpanSemuaNilai.style.background = '#64748B';
            btnSimpanSemuaNilai.style.color = '#FFFFFF';
            btnSimpanSemuaNilai.style.border = 'none';
            btnSimpanSemuaNilai.title = 'Semua nilai siswa pada paket asesmen ini sudah tersimpan di database';

            if (badgeLock) {
                badgeLock.style.display = 'inline-flex';
                badgeLock.style.background = '#DCFCE7';
                badgeLock.style.color = '#15803D';
                badgeLock.style.border = '1px solid #86EFAC';
                badgeLock.innerHTML = '<iconify-icon icon="solar:lock-bold"></iconify-icon> Nilai Sudah Disimpan (Terkunci)';
            }
        } else {
            isCurrentAssessmentSaved = false;
            btnSimpanSemuaNilai.disabled = false;
            btnSimpanSemuaNilai.innerHTML = '<iconify-icon icon="solar:diskette-bold" width="17"></iconify-icon> Simpan Semua Nilai (1 Kelas)';
            btnSimpanSemuaNilai.style.background = '#0D7A87';
            btnSimpanSemuaNilai.style.color = '#FFFFFF';
            btnSimpanSemuaNilai.style.border = 'none';
            btnSimpanSemuaNilai.title = 'Simpan nilai serentak pertama kali untuk seluruh siswa rombel kelas ini';

            if (badgeLock) {
                badgeLock.style.display = 'inline-flex';
                badgeLock.style.background = '#EFF6FF';
                badgeLock.style.color = '#1D4ED8';
                badgeLock.style.border = '1px solid #BFDBFE';
                badgeLock.innerHTML = '<iconify-icon icon="solar:pen-new-square-bold"></iconify-icon> Mode Penilaian (Belum Disimpan)';
            }
        }
    }

    // 6. Render Siswa Rows ke Tabel Desktop & Mobile Cards
    function renderGradebookRows() {
        emptyStateBox.style.display = 'none';

        const query = searchSiswa.value.toLowerCase().trim();
        const filtered = rawStudents.filter(s => {
            return s.nama_lengkap.toLowerCase().includes(query) || (s.nis && s.nis.toLowerCase().includes(query));
        });

        const tbody = document.getElementById('tbodyGradebookRows');
        const mobileContainer = document.getElementById('mobileCardsContainer');

        if (filtered.length === 0) {
            tbody.innerHTML = `<tr><td colspan="${7 + Math.max(1, rawItems.length)}" style="padding: 40px; text-align: center; color: #94A3B8;">Tidak ada peserta didik ditemukan.</td></tr>`;
            mobileContainer.innerHTML = `<div style="text-align: center; padding: 30px; color: #94A3B8;">Tidak ada data siswa.</div>`;
            desktopTableContainer.style.display = 'block';
            return;
        }

        let tableHtml = '';
        let mobileHtml = '';

        filtered.forEach((s, idx) => {
            const initialScore = (s.nilai_angka !== null && s.nilai_angka !== undefined) ? s.nilai_angka : 80;
            const pred = s.predikat || 'B';
            const status = s.status_tuntas || (pred === 'SB' || pred === 'A' || pred === 'B' ? 'Tuntas' : 'Belum Tuntas');
            const predClass = `badge-pred badge-pred-${pred.toLowerCase()}`;
            const statusClass = (status === 'Tuntas') ? 'badge-st-tuntas' : 'badge-st-belum';
            
            // Kunci Nilai Per Siswa: Hanya Terkunci Jika Nilai Siswa Ini Sudah Disimpan
            const isStudentSaved = Boolean(s.sudah_dinilai);
            const lockedAttr = isStudentSaved ? 'disabled' : '';
            const lockedStyle = isStudentSaved ? 'cursor: not-allowed; opacity: 0.85; pointer-events: none;' : '';

            // Skala terpilih per siswa
            const defaultSkalaScore = (s.nilai_angka !== null) ? Math.min(4, Math.max(1, Math.round((s.nilai_angka / 100) * 4))) : 3;

            // Kolom Skor Rubrik Interaktif (Desktop)
            let tdRubrik = '';
            let mRubrikPills = '';

            if (rawItems.length > 0) {
                rawItems.forEach(item => {
                    const itemLabel = item.aspek_sikap || item.tahap_keterampilan || `Butir ${item.nomor_urut}`;
                    tdRubrik += `
                        <td style="padding: 8px 6px;">
                            <div class="skala-group" data-item="${item.id}" style="display: inline-flex; gap: 3px; justify-content: center;">
                                <button type="button" class="skala-btn-pill ${defaultSkalaScore === 1 ? 'is-selected' : ''}" data-score="1" ${lockedAttr} style="${lockedStyle}" onclick="selectSkalaScore(this, ${s.id_siswa})">1</button>
                                <button type="button" class="skala-btn-pill ${defaultSkalaScore === 2 ? 'is-selected' : ''}" data-score="2" ${lockedAttr} style="${lockedStyle}" onclick="selectSkalaScore(this, ${s.id_siswa})">2</button>
                                <button type="button" class="skala-btn-pill ${defaultSkalaScore === 3 ? 'is-selected' : ''}" data-score="3" ${lockedAttr} style="${lockedStyle}" onclick="selectSkalaScore(this, ${s.id_siswa})">3</button>
                                <button type="button" class="skala-btn-pill ${defaultSkalaScore === 4 ? 'is-selected' : ''}" data-score="4" ${lockedAttr} style="${lockedStyle}" onclick="selectSkalaScore(this, ${s.id_siswa})">4</button>
                            </div>
                        </td>`;

                    mRubrikPills += `
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 11px; margin-bottom: 4px;">
                            <span style="color: #475569; max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${itemLabel}">#${item.nomor_urut} ${itemLabel}</span>
                            <div class="skala-group" data-item="${item.id}" style="display: inline-flex; gap: 3px;">
                                <button type="button" class="skala-btn-pill ${defaultSkalaScore === 1 ? 'is-selected' : ''}" data-score="1" ${lockedAttr} style="${lockedStyle}" onclick="selectSkalaScore(this, ${s.id_siswa})">1</button>
                                <button type="button" class="skala-btn-pill ${defaultSkalaScore === 2 ? 'is-selected' : ''}" data-score="2" ${lockedAttr} style="${lockedStyle}" onclick="selectSkalaScore(this, ${s.id_siswa})">2</button>
                                <button type="button" class="skala-btn-pill ${defaultSkalaScore === 3 ? 'is-selected' : ''}" data-score="3" ${lockedAttr} style="${lockedStyle}" onclick="selectSkalaScore(this, ${s.id_siswa})">3</button>
                                <button type="button" class="skala-btn-pill ${defaultSkalaScore === 4 ? 'is-selected' : ''}" data-score="4" ${lockedAttr} style="${lockedStyle}" onclick="selectSkalaScore(this, ${s.id_siswa})">4</button>
                            </div>
                        </div>`;
                });
            } else {
                tdRubrik = `<td>-</td>`;
            }

            // Catatan input style
            const catatanAttr = isStudentSaved 
                ? 'readonly style="width: 100%; height: 32px; border-radius: 8px; border: 1px solid #E2E8F0; padding: 0 8px; font-size: 12px; color: #64748B; background: #F8FAFC; cursor: not-allowed; box-sizing: border-box;"'
                : 'style="width: 100%; height: 32px; border-radius: 8px; border: 1px solid #E2E8F0; padding: 0 8px; font-size: 12px; color: #334155; box-sizing: border-box;"';

            // Tombol Aksi Row: Simpan satu-satu atau Reset satu-satu
            let btnAksiHtml = '';
            if (isStudentSaved) {
                btnAksiHtml = `
                    <button type="button" class="ilmuna-btn-row-reset" onclick="resetStudentScore(${s.id_siswa}, '${escapeAttr(s.nama_lengkap)}')" title="Reset Nilai Siswa Ini">
                        <iconify-icon icon="solar:restart-bold"></iconify-icon> Reset
                    </button>`;
            } else {
                btnAksiHtml = `
                    <button type="button" class="ilmuna-btn-row-save" onclick="saveStudentScore(${s.id_siswa})" title="Simpan Nilai Siswa Ini">
                        <iconify-icon icon="solar:diskette-bold"></iconify-icon> Simpan
                    </button>`;
            }

            // Row Desktop (Skor Angka READ-ONLY untuk Menjaga Kredibilitas Data)
            tableHtml += `
                <tr id="rowSiswa_${s.id_siswa}">
                    <td class="sticky-col col-no">${idx + 1}</td>
                    <td class="sticky-col col-nis">${s.nis}</td>
                    <td class="sticky-col col-nama">
                        <span style="display: block; font-weight: 700; color: #0F172A;">${s.nama_lengkap}</span>
                        <small style="color: #64748B; font-size: 11px;">NISN: ${s.nisn}</small>
                    </td>
                    ${tdRubrik}
                    <td style="padding: 6px; text-align: center;">
                        <input type="number" min="0" max="100" step="0.5" id="inputSkor_${s.id_siswa}" value="${initialScore}" readonly title="Nilai dihitung otomatis dari butir rubrik observasi" style="width: 70px; height: 32px; border-radius: 8px; border: 1px solid #CBD5E1; text-align: center; font-weight: 800; font-size: 13.5px; color: #0D7A87; background: #F8FAFC; cursor: not-allowed;">
                    </td>
                    <td style="padding: 6px; text-align: center;">
                        <span id="badgePred_${s.id_siswa}" class="${predClass}">${pred}</span>
                    </td>
                    <td style="padding: 6px; text-align: center;">
                        <span id="badgeStat_${s.id_siswa}" class="${statusClass}">${status}</span>
                    </td>
                    <td style="padding: 6px;">
                        <input type="text" id="inputCatatan_${s.id_siswa}" value="${s.catatan || ''}" placeholder="${isStudentSaved ? '-' : 'Catatan guru...'}" ${catatanAttr}>
                    </td>
                    <td style="padding: 6px; text-align: center;">
                        ${btnAksiHtml}
                    </td>
                </tr>`;

            // Card Mobile
            const mCatatanAttr = isStudentSaved
                ? 'readonly style="width: 100%; height: 34px; border-radius: 8px; border: 1px solid #E2E8F0; padding: 0 10px; font-size: 12px; color: #64748B; background: #F8FAFC; cursor: not-allowed; box-sizing: border-box;"'
                : 'style="width: 100%; height: 34px; border-radius: 8px; border: 1px solid #E2E8F0; padding: 0 10px; font-size: 12px; box-sizing: border-box;"';

            mobileHtml += `
                <div style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 14px; padding: 14px; box-shadow: 0 2px 8px rgba(15,23,42,0.03);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <div>
                            <strong style="font-size: 13.5px; color: #0F172A; display: block;">${s.nama_lengkap}</strong>
                            <small style="color: #64748B; font-size: 11px;">NIS: ${s.nis} &bull; NISN: ${s.nisn}</small>
                        </div>
                        <span id="mBadgeStat_${s.id_siswa}" class="${statusClass}">${status}</span>
                    </div>

                    <div style="background: #F8FAFC; border: 1px solid #F1F5F9; border-radius: 10px; padding: 8px; margin-bottom: 10px;">
                        ${mRubrikPills}
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 10px;">
                        <div>
                            <label style="display: block; font-size: 10.5px; font-weight: 700; color: #64748B; margin-bottom: 3px;">Nilai Angka (Otomatis):</label>
                            <input type="number" min="0" max="100" step="0.5" id="mInputSkor_${s.id_siswa}" value="${initialScore}" readonly style="width: 100%; height: 34px; border-radius: 8px; border: 1px solid #CBD5E1; text-align: center; font-weight: 800; font-size: 14px; color: #0D7A87; background: #F8FAFC; cursor: not-allowed;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 10.5px; font-weight: 700; color: #64748B; margin-bottom: 3px;">Predikat Mutu:</label>
                            <div style="height: 34px; display: flex; align-items: center; justify-content: center;">
                                <span id="mBadgePred_${s.id_siswa}" class="${predClass}">${pred}</span>
                            </div>
                        </div>
                    </div>

                    <div>
                        <input type="text" id="mInputCatatan_${s.id_siswa}" value="${s.catatan || ''}" onchange="syncMobileDesktop(${s.id_siswa}, 'catatan')" placeholder="${isStudentSaved ? '-' : 'Catatan perkembangan...'}" ${mCatatanAttr}>
                    </div>

                    <div style="margin-top: 10px; display: flex; justify-content: flex-end;">
                        ${btnAksiHtml}
                    </div>
                </div>`;
        });

        tbody.innerHTML = tableHtml;
        mobileContainer.innerHTML = mobileHtml;
        desktopTableContainer.style.display = 'block';
    }

    // 7. Interaksi Skala Skor (1, 2, 3, 4) - Hanya Terkunci Jika Nilai Siswa Ini Sudah Disimpan
    window.selectSkalaScore = function(btn, idSiswa) {
        const student = rawStudents.find(s => s.id_siswa === idSiswa);
        if (student && student.sudah_dinilai) {
            notifyWarning('Nilai siswa ini sudah tersimpan dan terkunci. Klik tombol Reset di kolom aksi jika ingin mengubah.');
            return;
        }

        const parent = btn.closest('.skala-group');
        parent.querySelectorAll('.skala-btn-pill').forEach(b => b.classList.remove('is-selected'));
        btn.classList.add('is-selected');

        const row = document.getElementById(`rowSiswa_${idSiswa}`);
        let total = 0;
        let count = 0;

        if (row) {
            row.querySelectorAll('.skala-group').forEach(grp => {
                const sel = grp.querySelector('.skala-btn-pill.is-selected');
                if (sel) {
                    total += parseInt(sel.dataset.score);
                    count++;
                }
            });
        }

        if (count > 0) {
            const max = count * 4;
            const finalScore = Math.round((total / max) * 100);
            const inputSkor = document.getElementById(`inputSkor_${idSiswa}`);
            if (inputSkor) {
                inputSkor.value = finalScore;
                recalcStudentScore(idSiswa);
            }
        }
    };

    // 8. Kalkulasi Live Nilai, Predikat & Status
    window.recalcStudentScore = function(idSiswa) {
        const inputSkor = document.getElementById(`inputSkor_${idSiswa}`);
        if (!inputSkor) return;

        const val = parseFloat(inputSkor.value) || 0;
        let pred = 'B';
        let status = 'Tuntas';

        if (currentTab === 'afektif') {
            if (val >= 88) pred = 'SB';
            else if (val >= 75) pred = 'B';
            else if (val >= 60) pred = 'C';
            else pred = 'K';

            status = (pred === 'SB' || pred === 'B') ? 'Tuntas' : 'Perlu Pembinaan';
        } else {
            const kktp = 75.00;
            if (val >= 90) pred = 'A';
            else if (val >= 80) pred = 'B';
            else if (val >= 70) pred = 'C';
            else if (val >= 60) pred = 'D';
            else pred = 'E';

            status = (val >= kktp) ? 'Tuntas' : 'Belum Tuntas';
        }

        // Update Desktop
        const bPred = document.getElementById(`badgePred_${idSiswa}`);
        if (bPred) {
            bPred.className = `badge-pred badge-pred-${pred.toLowerCase()}`;
            bPred.innerText = pred;
        }

        const bStat = document.getElementById(`badgeStat_${idSiswa}`);
        if (bStat) {
            bStat.className = (status === 'Tuntas') ? 'badge-st-tuntas' : 'badge-st-belum';
            bStat.innerText = status;
        }

        // Update Mobile
        const mInput = document.getElementById(`mInputSkor_${idSiswa}`);
        if (mInput) mInput.value = val;
        const mBPred = document.getElementById(`mBadgePred_${idSiswa}`);
        if (mBPred) {
            mBPred.className = `badge-pred badge-pred-${pred.toLowerCase()}`;
            mBPred.innerText = pred;
        }
        const mBStat = document.getElementById(`mBadgeStat_${idSiswa}`);
        if (mBStat) {
            mBStat.className = (status === 'Tuntas') ? 'badge-st-tuntas' : 'badge-st-belum';
            mBStat.innerText = status;
        }
    };

    window.syncMobileDesktop = function(idSiswa, field) {
        if (field === 'catatan') {
            const mVal = document.getElementById(`mInputCatatan_${idSiswa}`).value;
            const dInput = document.getElementById(`inputCatatan_${idSiswa}`);
            if (dInput) dInput.value = mVal;
        }
    };

    // 9. Simpan Nilai Satu-Satu Per Siswa (In-Place Save)
    window.saveStudentScore = async function(idSiswa) {
        const student = rawStudents.find(s => s.id_siswa === idSiswa);
        if (!student) return;

        const inputSkor = document.getElementById(`inputSkor_${idSiswa}`) || document.getElementById(`mInputSkor_${idSiswa}`);
        const inputCat = document.getElementById(`inputCatatan_${idSiswa}`) || document.getElementById(`mInputCatatan_${idSiswa}`);
        const nilaiAngka = inputSkor ? (parseFloat(inputSkor.value) || 0) : 0;
        const catatan = inputCat ? inputCat.value.trim() : '';

        if (!nilaiAngka || nilaiAngka <= 0) {
            notifyWarning('Silakan beri penilaian rubrik (skala 1 s.d 4) terlebih dahulu.');
            return;
        }

        const btnRow = document.querySelector(`#rowSiswa_${idSiswa} .ilmuna-btn-row-save`);
        const origBtnText = btnRow ? btnRow.innerHTML : '';
        if (btnRow) {
            btnRow.disabled = true;
            btnRow.innerHTML = '<iconify-icon icon="solar:restart-bold" class="rotate-anim"></iconify-icon> Menyimpan...';
        }

        try {
            const formData = new FormData();
            formData.append('action', 'save_student_score');
            formData.append('tipe', currentTab);
            formData.append('id_asesmen', currentAsesmenId);
            formData.append('id_kmp', currentKmpId);
            formData.append('id_siswa', idSiswa);
            formData.append('nilai_angka', nilaiAngka);
            formData.append('catatan', catatan);
            formData.append('skor_rata_skala', (nilaiAngka / 25).toFixed(2));
            formData.append('nilai_kktp', (rawAsesmen && rawAsesmen.kktp) ? rawAsesmen.kktp : 75);

            const res = await fetch('penilaian.php', { method: 'POST', body: formData });
            const json = await res.json();

            if (json.success) {
                notifySuccess(`Nilai ${student.nama_lengkap} berhasil disimpan!`);
                student.sudah_dinilai = true;
                student.nilai_angka = nilaiAngka;
                student.predikat = json.predikat || student.predikat;
                student.status_tuntas = json.status_tuntas || student.status_tuntas;
                student.catatan = catatan;

                renderGradebookRows();
                updateHeaderSummaryStatus();
            } else {
                if (btnRow) {
                    btnRow.disabled = false;
                    btnRow.innerHTML = origBtnText;
                }
                notifyError(json.message || 'Gagal menyimpan nilai.');
            }
        } catch (err) {
            if (btnRow) {
                btnRow.disabled = false;
                btnRow.innerHTML = origBtnText;
            }
            console.error(err);
            notifyError('Terjadi kesalahan koneksi.');
        }
    };

    // 9B. Reset Nilai Satu-Satu Per Siswa (Hapus Record dari Database & Buka Kunci)
    window.resetStudentScore = function(idSiswa, namaSiswa) {
        const student = rawStudents.find(s => s.id_siswa === idSiswa);
        if (!student) return;

        confirmAction({
            title: 'Reset Nilai Siswa?',
            text: `Apakah Anda yakin ingin mereset nilai untuk ${namaSiswa || student.nama_lengkap}? Nilai akan dihapus dari database dan lembar penilaian siswa ini akan dibuka kembali untuk dinilai ulang.`,
            confirmButtonText: 'Ya, Reset Nilai',
            cancelButtonText: 'Batal',
            isDanger: true,
            onConfirm: async () => {
                const btnRow = document.querySelector(`#rowSiswa_${idSiswa} .ilmuna-btn-row-reset`);
                const origBtnText = btnRow ? btnRow.innerHTML : '';
                if (btnRow) {
                    btnRow.disabled = true;
                    btnRow.innerHTML = '<iconify-icon icon="solar:restart-bold" class="rotate-anim"></iconify-icon> Mereset...';
                }

                try {
                    const formData = new FormData();
                    formData.append('action', 'reset_student_score');
                    formData.append('tipe', currentTab);
                    formData.append('id_asesmen', currentAsesmenId);
                    formData.append('id_kmp', currentKmpId);
                    formData.append('id_siswa', idSiswa);

                    const res = await fetch('penilaian.php', { method: 'POST', body: formData });
                    const json = await res.json();

                    if (json.success) {
                        notifySuccess(json.message || 'Nilai siswa berhasil direset.');
                        student.sudah_dinilai = false;
                        student.nilai_angka = 80;
                        student.predikat = 'B';
                        student.status_tuntas = 'Belum Disimpan';
                        student.catatan = '';

                        renderGradebookRows();
                        updateHeaderSummaryStatus();
                    } else {
                        if (btnRow) {
                            btnRow.disabled = false;
                            btnRow.innerHTML = origBtnText;
                        }
                        notifyError(json.message || 'Gagal mereset nilai siswa.');
                    }
                } catch (err) {
                    if (btnRow) {
                        btnRow.disabled = false;
                        btnRow.innerHTML = origBtnText;
                    }
                    console.error(err);
                    notifyError('Terjadi kesalahan koneksi.');
                }
            }
        });
    };

    // 10. Simpan Massal Nilai Pertama Kali (1 Kelas)
    btnSimpanSemuaNilai.addEventListener('click', function() {
        if (!currentAsesmenId || !currentKmpId) {
            notifyError('Pilih paket asesmen terlebih dahulu.');
            return;
        }

        const unsavedStudents = rawStudents.filter(s => !s.sudah_dinilai);
        if (unsavedStudents.length === 0) {
            notifyWarning('Semua nilai siswa pada paket asesmen ini sudah tersimpan dan terkunci. Untuk mengubah nilai, silakan klik tombol Reset per siswa di kolom aksi.');
            return;
        }

        const scores = [];
        unsavedStudents.forEach(s => {
            const inputSkor = document.getElementById(`inputSkor_${s.id_siswa}`) || document.getElementById(`mInputSkor_${s.id_siswa}`);
            const inputCat = document.getElementById(`inputCatatan_${s.id_siswa}`) || document.getElementById(`mInputCatatan_${s.id_siswa}`);
            if (inputSkor) {
                scores.push({
                    id_siswa: s.id_siswa,
                    nilai_angka: parseFloat(inputSkor.value) || 0,
                    catatan: inputCat ? inputCat.value.trim() : ''
                });
            }
        });

        if (scores.length === 0) {
            notifyError('Tidak ada siswa untuk dinilai.');
            return;
        }

        confirmAction({
            title: 'Simpan Semua Nilai (1 Kelas)?',
            text: `Yakin ingin menyimpan serentak nilai dari ${scores.length} siswa untuk 1 rombel kelas, mapel, dan modul ajar ini? Setelah disimpan data siswa akan terkunci dan dapat direset satu per satu.`,
            confirmButtonText: 'Ya, Simpan Semua Nilai',
            cancelButtonText: 'Batal',
            onConfirm: async () => {
                const originalHtml = btnSimpanSemuaNilai.innerHTML;
                btnSimpanSemuaNilai.disabled = true;
                btnSimpanSemuaNilai.innerHTML = '<iconify-icon icon="solar:restart-bold" class="rotate-anim"></iconify-icon> Menyimpan...';

                try {
                    const formData = new FormData();
                    formData.append('action', 'save_all_scores');
                    formData.append('tipe', currentTab);
                    formData.append('id_asesmen', currentAsesmenId);
                    formData.append('id_kmp', currentKmpId);
                    formData.append('scores', JSON.stringify(scores));

                    const res = await fetch('penilaian.php', { method: 'POST', body: formData });
                    const json = await res.json();

                    btnSimpanSemuaNilai.disabled = false;
                    btnSimpanSemuaNilai.innerHTML = originalHtml;

                    if (json.success) {
                        notifySuccess(json.message);
                        
                        // Tandai siswa yang baru saja disimpan
                        scores.forEach(sc => {
                            const found = rawStudents.find(s => s.id_siswa === sc.id_siswa);
                            if (found) {
                                found.sudah_dinilai = true;
                                found.nilai_angka = sc.nilai_angka;
                                found.catatan = sc.catatan;
                            }
                        });

                        renderGradebookRows();
                        updateHeaderSummaryStatus();

                        if (currentTab === 'instrumen') {
                            loadInstrumentsManager(currentKmpId);
                        }
                    } else {
                        notifyError(json.message || 'Gagal menyimpan seluruh nilai.');
                    }
                } catch (err) {
                    btnSimpanSemuaNilai.disabled = false;
                    btnSimpanSemuaNilai.innerHTML = originalHtml;
                    console.error(err);
                    notifyError('Terjadi kesalahan saat simpan serentak.');
                }
            }
        });
    });

    // 11. Modal Tambah Asesmen Baru (Terhubung Modul Ajar)
    btnBukaModalTambah.addEventListener('click', function() {
        if (!currentKmpId) {
            notifyError('Pilih Rombel Kelas & Mapel terlebih dahulu.');
            return;
        }
        document.getElementById('formTambahAsesmen').reset();
        const effectiveTipe = (currentTab === 'instrumen') ? 'afektif' : currentTab;
        document.getElementById('modalInputTipe').value = effectiveTipe;

        // Reset pilihan modul ajar ke 0
        const selModul = document.getElementById('modalInputModulAjar');
        if (selModul) selModul.value = '0';

        const pertInput = document.getElementById('modalInputPertemuan');
        if (pertInput) pertInput.value = 1;

        if (effectiveTipe === 'afektif') {
            document.getElementById('modalBoxKKTP').style.display = 'none';
            document.getElementById('modalBoxDimensi').style.display = 'block';
        } else {
            document.getElementById('modalBoxKKTP').style.display = 'block';
            document.getElementById('modalBoxDimensi').style.display = 'none';
        }
        safeOpenModal('modalTambahAsesmen');
    });

    document.getElementById('formTambahAsesmen').addEventListener('submit', async function(e) {
        e.preventDefault();
        const submitBtn = document.getElementById('btnSubmitModal');
        submitBtn.disabled = true;
        submitBtn.innerText = 'Menyimpan...';

        try {
            const formData = new FormData(this);
            formData.append('id_kmp', currentKmpId);

            const res = await fetch('penilaian.php', { method: 'POST', body: formData });
            const json = await res.json();
            submitBtn.disabled = false;
            submitBtn.innerText = 'Simpan & Buat Butir';

            if (json.success) {
                safeCloseModal('modalTambahAsesmen');
                notifySuccess(json.message);

                const targetTipe = json.tipe || formData.get('tipe') || currentTab;
                if (currentTab === 'instrumen') {
                    loadInstrumentsManager(currentKmpId);
                } else {
                    switchTab(targetTipe, json.id_asesmen);
                }
            } else {
                notifyError(json.message || 'Gagal membuat instrumen.');
            }
        } catch (err) {
            submitBtn.disabled = false;
            submitBtn.innerText = 'Simpan & Buat Butir';
            console.error(err);
            notifyError('Gagal membuat instrumen asesmen.');
        }
    });

    // ==========================================================================
    // 12. BANK & MANAJEMEN CRUD INSTRUMEN (TAB 3 ENGINE)
    // ==========================================================================
    async function loadInstrumentsManager(idKmp) {
        const container = document.getElementById('containerListInstrumen');
        if (!container) return;

        if (!idKmp || idKmp <= 0) {
            container.innerHTML = `
                <div style="text-align: center; padding: 40px; color: #64748B;">
                    <iconify-icon icon="solar:folder-error-bold" style="font-size: 40px; color: #CBD5E1; margin-bottom: 8px;"></iconify-icon>
                    <div style="font-weight: 700;">Silakan pilih rombel kelas & mata pelajaran terlebih dahulu.</div>
                </div>`;
            return;
        }

        container.innerHTML = `
            <div style="text-align: center; padding: 40px; color: #0D7A87;">
                <iconify-icon icon="solar:restart-bold" class="rotate-anim" style="font-size: 32px;"></iconify-icon>
                <div style="margin-top: 8px; font-weight: 600; font-size: 13px;">Memuat daftar bank instrumen...</div>
            </div>`;

        try {
            const formData = new FormData();
            formData.append('action', 'list_all_instruments');
            formData.append('id_kmp', idKmp);

            const res = await fetch('penilaian.php', { method: 'POST', body: formData });
            const json = await res.json();

            let list = null;
            if (json.success) {
                if (Array.isArray(json.data)) {
                    list = json.data;
                } else if (json.data && Array.isArray(json.data.all)) {
                    list = json.data.all;
                } else if (Array.isArray(json.all)) {
                    list = json.all;
                }
            }

            if (list !== null) {
                rawInstruments = list;
                const afkCount = rawInstruments.filter(x => x.tipe === 'afektif').length;
                const pskCount = rawInstruments.filter(x => x.tipe === 'psikomotorik').length;

                const badgeAfk = document.getElementById('badgeAfektifCount');
                if (badgeAfk) badgeAfk.innerText = afkCount;
                const badgePsk = document.getElementById('badgePsikomotorikCount');
                if (badgePsk) badgePsk.innerText = pskCount;
                const badgeInst = document.getElementById('badgeInstrumenCount');
                if (badgeInst) badgeInst.innerText = rawInstruments.length;

                renderInstrumentsManagerList(currentSubRanahFilter);
            } else {
                container.innerHTML = `<div style="text-align: center; padding: 30px; color: #EF4444;">Gagal memuat daftar instrumen: ${json.message || 'Format data instrumen tidak sesuai.'}</div>`;
            }
        } catch (err) {
            console.error(err);
            container.innerHTML = `<div style="text-align: center; padding: 30px; color: #EF4444;">Terjadi kesalahan jaringan saat memuat bank instrumen.</div>`;
        }
    }

    window.filterInstrumenSubRanah = function(sub) {
        currentSubRanahFilter = sub;
        const allBtn = document.getElementById('subBtnRanahAll');
        const afkBtn = document.getElementById('subBtnRanahAfk');
        const pskBtn = document.getElementById('subBtnRanahPsk');

        [allBtn, afkBtn, pskBtn].forEach(b => {
            if (b) {
                b.classList.remove('is-active');
                b.style.background = 'transparent';
                b.style.color = '#64748B';
                b.style.boxShadow = 'none';
            }
        });

        const activeBtn = sub === 'all' ? allBtn : (sub === 'afektif' ? afkBtn : pskBtn);
        if (activeBtn) {
            activeBtn.classList.add('is-active');
            activeBtn.style.background = '#FFFFFF';
            activeBtn.style.color = '#0D7A87';
            activeBtn.style.boxShadow = '0 1px 3px rgba(0,0,0,0.1)';
        }

        renderInstrumentsManagerList(sub);
    };

    function renderInstrumentsManagerList(subFilter) {
        const container = document.getElementById('containerListInstrumen');
        if (!container) return;

        const filtered = rawInstruments.filter(item => {
            if (subFilter === 'all') return true;
            return item.tipe === subFilter;
        });

        if (filtered.length === 0) {
            const filterLabel = subFilter === 'all' ? 'Afektif atau Psikomotorik' : (subFilter === 'afektif' ? 'Afektif (Sikap)' : 'Psikomotorik (Praktik)');
            container.innerHTML = `
                <div style="text-align: center; padding: 48px 20px; background: #F8FAFC; border: 1.5px dashed #CBD5E1; border-radius: 14px;">
                    <iconify-icon icon="solar:notes-bold" style="font-size: 44px; color: #94A3B8; margin-bottom: 8px;"></iconify-icon>
                    <h4 style="margin: 0 0 6px; font-size: 15px; font-weight: 800; color: #1E293B;">Belum Ada Instrumen ${filterLabel}</h4>
                    <p style="margin: 0 0 16px; font-size: 12.5px; color: #64748B; max-width: 480px; margin-inline: auto;">
                        Buat instrumen baru selaras dengan modul ajar untuk memulai observasi sikap atau uji unjuk kerja praktik siswa.
                    </p>
                    <button type="button" class="btn btn-primary" onclick="btnBukaModalTambah.click()" style="padding: 8px 18px; font-weight: 700; font-size: 13px; border-radius: 8px; background: #0D7A87; color: #FFF; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; margin: 0 auto;">
                        <iconify-icon icon="solar:add-circle-bold"></iconify-icon> Buat Instrumen Sekarang
                    </button>
                </div>`;
            return;
        }

        let cardsHtml = '<div style="display: flex; flex-direction: column; gap: 14px;">';
        filtered.forEach(item => {
            const isAfk = item.tipe === 'afektif';
            const ranahBadgeStyle = isAfk
                ? 'background: #CCFBF1; color: #0F766E; border: 1px solid #99F6E4;'
                : 'background: #FEF3C7; color: #B45309; border: 1px solid #FDE68A;';
            const ranahText = isAfk ? 'AFEKTIF (SIKAP)' : 'PSIKOMOTORIK (PRAKTIK)';
            const modulInfo = item.judul_modul
                ? `<span style="display: inline-flex; align-items: center; gap: 4px; color: #4338CA; font-weight: 700;"><iconify-icon icon="solar:document-text-bold"></iconify-icon> Modul: ${escapeHtml(item.judul_modul)}</span>`
                : `<span style="color: #94A3B8;">(Instrumen Mandiri / Manual)</span>`;
            const statusBadge = (item.status === 'Aktif')
                ? '<span style="background: #DCFCE7; color: #15803D; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 700;">Aktif</span>'
                : '<span style="background: #F1F5F9; color: #64748B; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 700;">' + escapeHtml(item.status || 'Draft') + '</span>';

            cardsHtml += `
                <div style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 12px; padding: 16px 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); display: flex; flex-direction: column; gap: 12px;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap;">
                        <div style="display: flex; flex-direction: column; gap: 4px;">
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <span style="display: inline-block; padding: 2px 8px; border-radius: 6px; font-weight: 800; font-size: 11px; ${ranahBadgeStyle}">${ranahText}</span>
                                <span style="background: #F1F5F9; color: #334155; font-size: 11.5px; font-weight: 800; padding: 2px 8px; border-radius: 6px;">Pertemuan Ke-${item.pertemuan_ke}</span>
                                ${statusBadge}
                            </div>
                            <h4 style="margin: 4px 0 0; font-size: 15.5px; font-weight: 800; color: #0F172A;">
                                ${escapeHtml(item.judul_asesmen)}
                            </h4>
                            <div style="font-size: 12px; color: #64748B; display: flex; gap: 12px; flex-wrap: wrap; margin-top: 2px;">
                                ${modulInfo}
                                <span>&bull;</span>
                                <span>Teknik: <strong>${escapeHtml(item.teknik_asesmen || 'Observasi')}</strong></span>
                                ${item.kktp ? `<span>&bull;</span><span>KKTP: <strong>${item.kktp}</strong></span>` : ''}
                            </div>
                        </div>

                        <!-- Counter Badges -->
                        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                            <span title="Jumlah Butir Rubrik" style="background: #EFF6FF; border: 1px solid #BFDBFE; color: #1D4ED8; padding: 4px 10px; border-radius: 8px; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px;">
                                <iconify-icon icon="solar:checklist-bold"></iconify-icon> ${item.total_rubrik || 0} Butir Rubrik
                            </span>
                            <span title="Jumlah Siswa yang Sudah Dinilai" style="background: #F0FDF4; border: 1px solid #BBF7D0; color: #15803D; padding: 4px 10px; border-radius: 8px; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px;">
                                <iconify-icon icon="solar:users-group-rounded-bold"></iconify-icon> ${item.total_siswa_dinilai || 0} Siswa Dinilai
                            </span>
                        </div>
                    </div>

                    <!-- Footer Action Buttons -->
                    <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #F1F5F9; padding-top: 10px; flex-wrap: wrap; gap: 8px;">
                        <div style="font-size: 11.5px; color: #94A3B8;">
                            ${item.dimensi_profil ? 'Dimensi: ' + escapeHtml(item.dimensi_profil) : (item.tujuan_asesmen ? escapeHtml(item.tujuan_asesmen) : '')}
                        </div>
                        <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
                            <button type="button" class="btn btn-sm" onclick="openAssessmentForGrading('${item.tipe}', ${item.id})" style="height: 32px; padding: 0 12px; border-radius: 8px; background: #0D7A87; color: #FFFFFF; border: none; font-weight: 700; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px;">
                                <iconify-icon icon="solar:pen-new-square-bold"></iconify-icon> Nilai Siswa
                            </button>
                            <button type="button" class="btn btn-sm" onclick="openManageRubrikModal('${item.tipe}', ${item.id}, '${escapeAttr(item.judul_asesmen)}')" style="height: 32px; padding: 0 12px; border-radius: 8px; background: #EEF2FF; border: 1px solid #C7D2FE; color: #4338CA; font-weight: 700; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px;">
                                <iconify-icon icon="solar:checklist-minimalistic-bold"></iconify-icon> Kelola Rubrik
                            </button>
                            <button type="button" class="btn btn-sm" onclick="openEditInstrumentModal('${item.tipe}', ${item.id})" style="height: 32px; padding: 0 10px; border-radius: 8px; background: #F1F5F9; border: 1px solid #CBD5E1; color: #334155; font-weight: 600; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;">
                                <iconify-icon icon="solar:pen-bold"></iconify-icon> Edit
                            </button>
                            <button type="button" class="btn btn-sm" onclick="deleteInstrumentAction('${item.tipe}', ${item.id}, '${escapeAttr(item.judul_asesmen)}')" title="Hapus Instrumen" style="height: 32px; width: 32px; border-radius: 8px; background: #FEF2F2; border: 1px solid #FECACA; color: #DC2626; font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center;">
                                <iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon>
                            </button>
                        </div>
                    </div>
                </div>`;
        });
        cardsHtml += '</div>';

        container.innerHTML = cardsHtml;
    }

    // Direct Shortcut: Pindah ke Tab Penilaian & Muat Gradebook Instrumen
    window.openAssessmentForGrading = function(tipe, id) {
        switchTab(tipe, id);
    };

    // Modal Edit Instrumen
    window.openEditInstrumentModal = async function(tipe, id) {
        try {
            const formData = new FormData();
            formData.append('action', 'get_instrument_detail');
            formData.append('tipe', tipe);
            formData.append('id_asesmen', id);

            const res = await fetch('penilaian.php', { method: 'POST', body: formData });
            const json = await res.json();

            if (json.success && json.data.asesmen) {
                const ins = json.data.asesmen;
                document.getElementById('editModalTipe').value = tipe;
                document.getElementById('editModalIdAsesmen').value = id;
                document.getElementById('editModalTipeLabel').value = (tipe === 'afektif') ? 'Afektif (Sikap & Karakter)' : 'Psikomotorik (Keterampilan / Praktik)';
                document.getElementById('editModalPertemuan').value = ins.pertemuan_ke || 1;
                document.getElementById('editModalJudul').value = ins.judul_asesmen || '';
                document.getElementById('editModalTeknik').value = ins.teknik_asesmen || 'Observasi';
                document.getElementById('editModalStatus').value = ins.status || 'Aktif';
                document.getElementById('editModalTujuan').value = ins.tujuan_asesmen || '';

                const editModulSelect = document.getElementById('editModalModulAjar');
                if (editModulSelect) {
                    editModulSelect.value = ins.id_modul_ajar || 0;
                }

                if (tipe === 'afektif') {
                    document.getElementById('editModalBoxKKTP').style.display = 'none';
                    document.getElementById('editModalBoxDimensi').style.display = 'block';
                    document.getElementById('editModalDimensi').value = ins.dimensi_profil || '';
                } else {
                    document.getElementById('editModalBoxKKTP').style.display = 'block';
                    document.getElementById('editModalBoxDimensi').style.display = 'none';
                    document.getElementById('editModalKKTP').value = ins.kktp || '75.00';
                }

                safeOpenModal('modalEditAsesmen');
            } else {
                notifyError(json.message || 'Gagal mengambil detail instrumen.');
            }
        } catch (err) {
            console.error(err);
            notifyError('Terjadi kesalahan saat memuat instrumen.');
        }
    };

    document.getElementById('formEditAsesmen').addEventListener('submit', async function(e) {
        e.preventDefault();
        const btnSubmit = document.getElementById('btnSubmitEditModal');
        btnSubmit.disabled = true;
        btnSubmit.innerText = 'Menyimpan...';

        try {
            const formData = new FormData(this);
            formData.append('id_kmp', currentKmpId);

            const res = await fetch('penilaian.php', { method: 'POST', body: formData });
            const json = await res.json();

            btnSubmit.disabled = false;
            btnSubmit.innerText = 'Simpan Perubahan';

            if (json.success) {
                safeCloseModal('modalEditAsesmen');
                notifySuccess(json.message);
                if (currentTab === 'instrumen') {
                    loadInstrumentsManager(currentKmpId);
                } else {
                    loadAssessments(currentKmpId, formData.get('id_asesmen'));
                }
            } else {
                notifyError(json.message || 'Gagal memperbarui instrumen.');
            }
        } catch (err) {
            btnSubmit.disabled = false;
            btnSubmit.innerText = 'Simpan Perubahan';
            console.error(err);
            notifyError('Terjadi kesalahan koneksi.');
        }
    });

    // Hapus Instrumen
    window.deleteInstrumentAction = function(tipe, id, judul) {
        confirmAction({
            title: 'Hapus Instrumen Asesmen?',
            text: `Apakah Anda yakin ingin menghapus instrumen "${judul}"? Seluruh butir rubrik dan nilai siswa yang terkait akan dihapus secara permanen.`,
            confirmButtonText: 'Ya, Hapus Permanen',
            cancelButtonText: 'Batal',
            onConfirm: async () => {
                try {
                    const formData = new FormData();
                    formData.append('action', 'delete_instrument');
                    formData.append('tipe', tipe);
                    formData.append('id_asesmen', id);
                    formData.append('id_kmp', currentKmpId);

                    const res = await fetch('penilaian.php', { method: 'POST', body: formData });
                    const json = await res.json();

                    if (json.success) {
                        notifySuccess(json.message);
                        if (currentTab === 'instrumen') {
                            loadInstrumentsManager(currentKmpId);
                        } else {
                            loadAssessments(currentKmpId);
                        }
                    } else {
                        notifyError(json.message || 'Gagal menghapus instrumen.');
                    }
                } catch (err) {
                    console.error(err);
                    notifyError('Terjadi kesalahan saat menghapus instrumen.');
                }
            }
        });
    };

    // ==========================================================================
    // 13. KELOLA BUTIR RUBRIK & INDIKATOR MODAL
    // ==========================================================================
    window.openManageRubrikModal = async function(tipe, id, judul) {
        currentManagingRubrik = { id, tipe, title: judul };
        document.getElementById('lblRubrikModalTitle').innerText = `Kelola Butir Rubrik: ${judul}`;
        document.getElementById('lblRubrikModalSubtitle').innerText = `Ranah ${tipe === 'afektif' ? 'Afektif (Observasi Sikap Siswa)' : 'Psikomotorik (Tahapan Unjuk Kerja Praktik)'}`;

        document.getElementById('rubrikItemTipe').value = tipe;
        document.getElementById('rubrikItemIdAsesmen').value = id;

        if (tipe === 'afektif') {
            document.getElementById('lblRubrikAspek').innerText = 'Aspek Sikap / Nilai Karakter:';
            document.getElementById('lblRubrikIndikator').innerText = 'Deskripsi Perilaku yang Diamati:';
        } else {
            document.getElementById('lblRubrikAspek').innerText = 'Tahapan Keterampilan / Kinerja Praktik:';
            document.getElementById('lblRubrikIndikator').innerText = 'Kaidah / Deskripsi Pelaksanaan Kinerja:';
        }

        resetRubrikForm();
        await loadRubrikItemList(tipe, id);
        safeOpenModal('modalKelolaRubrik');
    };

    async function loadRubrikItemList(tipe, id) {
        const tbody = document.getElementById('tbodyRubrikItemList');
        tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; padding: 20px; color: #94A3B8;">Memuat butir rubrik...</td></tr>';

        try {
            const formData = new FormData();
            formData.append('action', 'get_instrument_detail');
            formData.append('tipe', tipe);
            formData.append('id_asesmen', id);

            const res = await fetch('penilaian.php', { method: 'POST', body: formData });
            const json = await res.json();

            if (json.success && json.data) {
                const items = json.data.items || [];
                document.getElementById('rubrikInputNomor').value = items.length + 1;

                if (items.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; padding: 25px; color: #94A3B8;">Belum ada butir rubrik indikator. Tambahkan butir pertama pada form di atas.</td></tr>';
                    return;
                }

                let html = '';
                items.forEach((it, idx) => {
                    const aspek = escapeHtml(it.aspek_sikap || it.tahap_keterampilan || `Butir ${it.nomor_urut}`);
                    const indikator = escapeHtml(it.indikator_perilaku || it.deskripsi_kinerja || '-');
                    const deskriptor = escapeHtml(it.rubrik_deskriptor || '1 s.d 4');
                    const itemJson = escapeAttr(JSON.stringify(it));

                    html += `
                        <tr style="border-bottom: 1px solid #F1F5F9;">
                            <td style="padding: 10px; text-align: center; font-weight: 800; color: #0D7A87;">${it.nomor_urut || (idx + 1)}</td>
                            <td style="padding: 10px;">
                                <strong style="color: #0F172A; font-size: 12.5px;">${aspek}</strong>
                            </td>
                            <td style="padding: 10px;">
                                <div style="color: #334155; margin-bottom: 3px;">${indikator}</div>
                                <small style="color: #64748B; font-size: 10.5px; background: #F8FAFC; padding: 2px 6px; border-radius: 4px; border: 1px solid #E2E8F0; display: inline-block;">Rubrik: ${deskriptor}</small>
                            </td>
                            <td style="padding: 10px; text-align: center;">
                                <div style="display: inline-flex; gap: 4px;">
                                    <button type="button" onclick="editRubrikItemInline(${itemJson})" title="Edit Butir" style="width: 28px; height: 28px; border-radius: 6px; background: #EEF2FF; border: 1px solid #C7D2FE; color: #4338CA; cursor: pointer; display: inline-flex; align-items: center; justify-content: center;">
                                        <iconify-icon icon="solar:pen-bold" width="13"></iconify-icon>
                                    </button>
                                    <button type="button" onclick="deleteRubrikItemAction(${it.id})" title="Hapus Butir" style="width: 28px; height: 28px; border-radius: 6px; background: #FEF2F2; border: 1px solid #FECACA; color: #DC2626; cursor: pointer; display: inline-flex; align-items: center; justify-content: center;">
                                        <iconify-icon icon="solar:trash-bin-trash-bold" width="13"></iconify-icon>
                                    </button>
                                </div>
                            </td>
                        </tr>`;
                });

                tbody.innerHTML = html;
            } else {
                tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; padding: 20px; color: #EF4444;">Gagal memuat butir rubrik.</td></tr>';
            }
        } catch (err) {
            console.error(err);
            tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; padding: 20px; color: #EF4444;">Terjadi kesalahan jaringan.</td></tr>';
        }
    }

    window.resetRubrikForm = function() {
        document.getElementById('rubrikItemId').value = '0';
        document.getElementById('rubrikInputAspek').value = '';
        document.getElementById('rubrikInputIndikator').value = '';
        document.getElementById('rubrikInputDeskriptor').value = '4=Sangat Baik, 3=Baik, 2=Cukup, 1=Kurang';
        document.getElementById('btnCancelEditRubrik').style.display = 'none';
        document.getElementById('lblFormRubrikHeader').innerHTML = '<iconify-icon icon="solar:add-circle-bold" style="color: #0D7A87;"></iconify-icon> Tambah Butir Indikator Baru';
        document.getElementById('btnSubmitRubrikItem').innerText = 'Simpan Butir';
    };

    window.editRubrikItemInline = function(item) {
        document.getElementById('rubrikItemId').value = item.id;
        document.getElementById('rubrikInputNomor').value = item.nomor_urut;
        document.getElementById('rubrikInputAspek').value = item.aspek_sikap || item.tahap_keterampilan || '';
        document.getElementById('rubrikInputIndikator').value = item.indikator_perilaku || item.deskripsi_kinerja || '';
        document.getElementById('rubrikInputDeskriptor').value = item.rubrik_deskriptor || '';
        document.getElementById('btnCancelEditRubrik').style.display = 'inline-block';
        document.getElementById('lblFormRubrikHeader').innerHTML = '<iconify-icon icon="solar:pen-bold" style="color: #6366F1;"></iconify-icon> Edit Butir Rubrik Indikator';
        document.getElementById('btnSubmitRubrikItem').innerText = 'Perbarui Butir';
        document.getElementById('rubrikInputAspek').focus();
    };

    document.getElementById('formRubrikItem').addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = document.getElementById('btnSubmitRubrikItem');
        const origText = btn.innerText;
        btn.disabled = true;
        btn.innerText = 'Menyimpan...';

        try {
            const formData = new FormData(this);
            const res = await fetch('penilaian.php', { method: 'POST', body: formData });
            const json = await res.json();

            btn.disabled = false;
            btn.innerText = origText;

            if (json.success) {
                notifySuccess(json.message);
                resetRubrikForm();
                await loadRubrikItemList(currentManagingRubrik.tipe, currentManagingRubrik.id);
                if (currentTab === 'instrumen') {
                    loadInstrumentsManager(currentKmpId);
                }
            } else {
                notifyError(json.message || 'Gagal menyimpan butir rubrik.');
            }
        } catch (err) {
            btn.disabled = false;
            btn.innerText = origText;
            console.error(err);
            notifyError('Terjadi kesalahan saat simpan butir.');
        }
    });

    window.deleteRubrikItemAction = function(itemId) {
        confirmAction({
            title: 'Hapus Butir Rubrik?',
            text: 'Apakah Anda yakin ingin menghapus butir rubrik indikator ini?',
            confirmButtonText: 'Ya, Hapus',
            cancelButtonText: 'Batal',
            onConfirm: async () => {
                try {
                    const formData = new FormData();
                    formData.append('action', 'delete_rubrik_item');
                    formData.append('tipe', currentManagingRubrik.tipe);
                    formData.append('item_id', itemId);

                    const res = await fetch('penilaian.php', { method: 'POST', body: formData });
                    const json = await res.json();

                    if (json.success) {
                        notifySuccess(json.message);
                        await loadRubrikItemList(currentManagingRubrik.tipe, currentManagingRubrik.id);
                        if (currentTab === 'instrumen') {
                            loadInstrumentsManager(currentKmpId);
                        }
                    } else {
                        notifyError(json.message || 'Gagal menghapus butir rubrik.');
                    }
                } catch (err) {
                    console.error(err);
                    notifyError('Terjadi kesalahan koneksi.');
                }
            }
        });
    };

    // Helper sanitasi string untuk HTML & Attribute
    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
    function escapeAttr(str) {
        if (!str) return '';
        return String(str).replace(/'/g, "\\'").replace(/"/g, '&quot;');
    }

    // 14. Cetak / Unduh Lembar Penilaian ke PDF (Metode Direct Blob Download)
    btnPrintPdf.addEventListener('click', async function() {
        if (!currentAsesmenId || !currentKmpId || rawStudents.length === 0) {
            notifyError('Silakan pilih paket asesmen dan rombel kelas terlebih dahulu.');
            return;
        }

        const originalHtml = btnPrintPdf.innerHTML;
        btnPrintPdf.disabled = true;
        btnPrintPdf.innerHTML = '<iconify-icon icon="svg-spinners:ring-resize" style="font-size: 16px;"></iconify-icon> Mengunduh PDF...';

        try {
            const formData = new FormData();
            formData.append('action', 'download_assessment_pdf');
            formData.append('tipe', currentTab);
            formData.append('id_asesmen', currentAsesmenId);
            formData.append('id_kmp', currentKmpId);

            const res = await fetch('penilaian.php', { method: 'POST', body: formData });
            
            // Periksa jika respons berupa JSON error
            const contentType = res.headers.get('content-type') || '';
            if (contentType.includes('application/json')) {
                const json = await res.json();
                throw new Error(json.message || 'Gagal mengunduh berkas PDF.');
            }

            if (!res.ok) {
                throw new Error(`Gagal mengunduh PDF (HTTP ${res.status})`);
            }

            const disposition = res.headers.get('Content-Disposition') || '';
            let filename = `Lembar_Penilaian_${currentTab.toUpperCase()}_${Date.now()}.pdf`;
            const match = disposition.match(/filename="?([^"]+)"?/);
            if (match && match[1]) filename = match[1];

            const blob = await res.blob();
            const blobUrl = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.style.display = 'none';
            a.href = blobUrl;
            a.download = filename;
            document.body.appendChild(a);
            a.click();

            setTimeout(() => {
                if (a.parentNode) document.body.removeChild(a);
                window.URL.revokeObjectURL(blobUrl);
            }, 1000);

            notifySuccess('Berkas PDF Lembar Penilaian berhasil diunduh!');
        } catch (err) {
            console.error('Download PDF error:', err);
            notifyError(err.message || 'Gagal mengunduh berkas PDF.');
        } finally {
            btnPrintPdf.disabled = false;
            btnPrintPdf.innerHTML = originalHtml;
        }
    });

    // 15. Live Search & Reset
    searchSiswa.addEventListener('input', renderGradebookRows);

    btnResetFilter.addEventListener('click', function() {
        filterKelas.value = '0';
        filterKelas.dispatchEvent(new Event('change'));
    });

    function resetGradebookView(showEmptyBox = true) {
        headerInfoAsesmen.style.display = 'none';
        desktopTableContainer.style.display = 'none';
        mobileCardsContainer.style.display = 'none';

        // Bersihkan seluruh data tabel & kartu lama agar TIDAK MUNCUL DATA KELAS SEBELUMNYA
        const tbody = document.getElementById('tbodyGradebookRows');
        if (tbody) tbody.innerHTML = '';
        const mContainer = document.getElementById('mobileCardsContainer');
        if (mContainer) mContainer.innerHTML = '';
        rawStudents = [];
        rawItems = [];
        rawAsesmen = null;

        emptyStateBox.style.display = showEmptyBox ? 'block' : 'none';
        btnSimpanSemuaNilai.disabled = true;
        btnSimpanSemuaNilai.innerHTML = '<iconify-icon icon="solar:diskette-bold" width="17"></iconify-icon> Simpan Semua Nilai';
        btnSimpanSemuaNilai.style.background = '#0D7A87';
        btnSimpanSemuaNilai.style.color = '#FFFFFF';
        btnSimpanSemuaNilai.style.border = 'none';
        btnSimpanSemuaNilai.title = 'Pilih paket asesmen terlebih dahulu';
        btnPrintPdf.disabled = true;

        const badgeLock = document.getElementById('lblLockStatusBadge');
        if (badgeLock) badgeLock.style.display = 'none';
    }
});
</script>

<?php
// Muat Footer Resmi (BNB-25 Bottom Navigation & Scripts)
require_once BASE_PATH . '/includes/footer.php';
?>
