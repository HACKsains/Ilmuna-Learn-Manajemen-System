<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — DAFTAR NILAI & APPROVAL REMEDIAL SISWA (PORTAL GURU)
 * ==============================================================================
 * Sistem Rekapitulasi & Manajemen Nilai Kurikulum Merdeka Khusus Dewan Guru:
 *   1. Filter Rombel Kelas & Mapel yang Diampu Guru Pengampu
 *   2. Proteksi Nilai: Nilai >= KKTP Terkunci (Read-Only)
 *   3. Remedial Workflow:
 *      - Nilai < KKTP & telah mengikuti remedial dapat diklik
 *      - Pop-up Modal Evaluasi: Nilai Asli vs Nilai Remedial vs Nilai KKTP
 *      - Aksi 1: "Setujui Remedial" -> Nilai diakui = KKTP (Nilai asli TIDAK DIRUBAH)
 *      - Aksi 2: "Remidi Ulang" -> Siswa wajib mengulang sesi perbaikan
 *   4. Engine Generator Nilai Akhir (terhubung ke includes/rekap-nilai-calculator.php)
 *      dan tersimpan resmi ke tabel database `rekap_nilai_siswa_mapel`.
 *   5. Cetak PDF & Ekspor Excel Laporan Nilai Resmi Guru.
 * ==============================================================================
 */

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';
require_once __DIR__ . '/../../includes/rekap-nilai-calculator.php';

requireRole(['guru', 'admin']);
$activeRole = hasRole('guru') ? 'guru' : 'admin';
$GLOBALS['activeRole'] = $activeRole;

$pdo = getDBConnection();
$pdo->exec("SET time_zone = '+07:00'");
$currentUser = getCurrentUser();

// Identifikasi profil guru pengampu
$currentGuru = null;
$idGuruLogged = 0;
try {
    $gStmt = $pdo->prepare("SELECT * FROM guru WHERE id_user = ? LIMIT 1");
    $gStmt->execute([$currentUser['id']]);
    $currentGuru = $gStmt->fetch(PDO::FETCH_ASSOC);
    if ($currentGuru) {
        $idGuruLogged = (int)$currentGuru['id'];
    }
} catch (Exception $e) {}

// Pastikan kolom remedial tersedia pada tabel nilai
try {
    $tables = ['nilai_asesmen_sumatif', 'nilai_asesmen_formatif', 'nilai_tugas'];
    foreach ($tables as $tbl) {
        $cRemed = $pdo->query("SHOW COLUMNS FROM `$tbl` LIKE 'nilai_akhir_remedial'")->fetchAll();
        if (empty($cRemed)) {
            $pdo->exec("ALTER TABLE `$tbl` ADD COLUMN nilai_akhir_remedial DECIMAL(5,2) NULL DEFAULT NULL AFTER nilai_remedial");
        }
        $cStat = $pdo->query("SHOW COLUMNS FROM `$tbl` LIKE 'status_remedial'")->fetchAll();
        if (empty($cStat)) {
            $pdo->exec("ALTER TABLE `$tbl` ADD COLUMN status_remedial VARCHAR(35) NULL DEFAULT NULL AFTER nilai_akhir_remedial");
        }
        $cCat = $pdo->query("SHOW COLUMNS FROM `$tbl` LIKE 'catatan_remedial'")->fetchAll();
        if (empty($cCat)) {
            $pdo->exec("ALTER TABLE `$tbl` ADD COLUMN catatan_remedial VARCHAR(255) NULL DEFAULT NULL AFTER status_remedial");
        }
    }
} catch (Throwable $e) {}

/* ==============================================================================
 * BACKEND AJAX HANDLERS (JSON RESPONSE)
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

            if ($activeRole === 'guru' && $idGuruLogged > 0) {
                $whereClause = "WHERE k.id IN (
                    SELECT kmp.id_kelas 
                    FROM kelas_mata_pelajaran kmp
                    LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
                    WHERE gmp.id_guru = :id_guru 
                       OR kmp.id IN (SELECT id_kelas_mata_pelajaran FROM asesmen_sumatif WHERE id_guru = :id_guru2)
                )";
                $params[':id_guru']  = $idGuruLogged;
                $params[':id_guru2'] = $idGuruLogged;
            }

            $sql = "SELECT k.id, k.nama_kelas, k.tahun_pelajaran,
                           (SELECT COUNT(*) FROM siswa s WHERE s.id_kelas = k.id) AS total_siswa
                    FROM kelas k
                    {$whereClause}
                    ORDER BY k.nama_kelas ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $kelas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fallback jika belum ditautkan spesifik guru, tampilkan seluruh rombel
            if (empty($kelas) && $activeRole === 'guru') {
                $kelas = $pdo->query("SELECT id, nama_kelas, tahun_pelajaran, (SELECT COUNT(*) FROM siswa s WHERE s.id_kelas = kelas.id) AS total_siswa FROM kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode(['success' => true, 'data' => ['kelas' => $kelas ?: []]]);
            exit;
        }

        // 2. FETCH MAPEL GURU BY KELAS
        if ($action === 'get_mapel_by_kelas') {
            $idKelas = (int)($_POST['id_kelas'] ?? 0);
            $params = [$idKelas];
            $whereGuru = "";

            if ($activeRole === 'guru' && $idGuruLogged > 0) {
                // Prioritaskan mapel guru
                $whereGuru = " AND (gmp.id_guru = ? OR kmp.id IN (SELECT id_kelas_mata_pelajaran FROM asesmen_sumatif WHERE id_guru = ?))";
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

            // Jika kosong untuk filter guru tertentu, fallback tampilkan seluruh mapel kelas
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

        // 3. LAZY LOAD GRADEBOOK GURU
        if ($action === 'load_gradebook') {
            $idKelas = (int)($_POST['id_kelas'] ?? 0);
            $idKmp   = (int)($_POST['id_kmp'] ?? 0);

            if ($idKelas <= 0 || $idKmp <= 0) {
                echo json_encode(['success' => false, 'message' => 'Parameter kelas atau mata pelajaran tidak valid.']);
                exit;
            }

            // Info Kelas
            $stmtK = $pdo->prepare("SELECT id, nama_kelas, tahun_pelajaran FROM kelas WHERE id = ? LIMIT 1");
            $stmtK->execute([$idKelas]);
            $kelasInfo = $stmtK->fetch(PDO::FETCH_ASSOC);

            // Info Mapel & Guru
            $stmtM = $pdo->prepare("
                SELECT kmp.id, kmp.id_mata_pelajaran, mp.nama_mata_pelajaran, mp.kode_mapel,
                       COALESCE(g.nama_guru, 'Guru Pengampu') AS nama_guru
                FROM kelas_mata_pelajaran kmp
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
                LEFT JOIN guru g ON gmp.id_guru = g.id
                WHERE kmp.id = ? LIMIT 1
            ");
            $stmtM->execute([$idKmp]);
            $mapelInfo = $stmtM->fetch(PDO::FETCH_ASSOC);
            $idMapel = (int)($mapelInfo['id_mata_pelajaran'] ?? 0);

            // Daftar Siswa
            $stmtS = $pdo->prepare("
                SELECT s.id, s.nis, s.nisn, s.nama_lengkap, s.foto, k.nama_kelas
                FROM siswa s
                JOIN kelas k ON s.id_kelas = k.id
                WHERE s.id_kelas = ?
                ORDER BY s.nama_lengkap ASC
            ");
            $stmtS->execute([$idKelas]);
            $siswaList = $stmtS->fetchAll(PDO::FETCH_ASSOC);

            // Auto-Sync Sesi CBT Selesai
            try {
                $stmtSesiSync = $pdo->prepare("
                    SELECT csu.*
                    FROM cbt_sesi_ujian csu
                    JOIN siswa s ON csu.id_siswa = s.id
                    WHERE s.id_kelas = ? AND csu.status_sesi = 'Selesai'
                ");
                $stmtSesiSync->execute([$idKelas]);
                $sesiSyncList = $stmtSesiSync->fetchAll(PDO::FETCH_ASSOC);

                foreach ($sesiSyncList as $sSync) {
                    $tipeSync = $sSync['tipe_asesmen'];
                    $idAsmSync = (int)$sSync['id_asesmen'];
                    $idSwSync = (int)$sSync['id_siswa'];
                    $kmpSync = (int)$sSync['id_kelas_mata_pelajaran'] ?: $idKmp;

                    if ($tipeSync === 'sumatif') {
                        $tbNl = 'nilai_asesmen_sumatif';
                        $colAsm = 'id_asesmen_sumatif';
                        $tbAsm = 'asesmen_sumatif';
                    } elseif ($tipeSync === 'formatif') {
                        $tbNl = 'nilai_asesmen_formatif';
                        $colAsm = 'id_asesmen_formatif';
                        $tbAsm = 'asesmen_formatif';
                    } else {
                        continue;
                    }

                    $checkAsm = $pdo->prepare("SELECT id, kktp FROM `$tbAsm` WHERE id = ? LIMIT 1");
                    $checkAsm->execute([$idAsmSync]);
                    $asmRow = $checkAsm->fetch(PDO::FETCH_ASSOC);
                    if (!$asmRow) continue;

                    $checkNl = $pdo->prepare("SELECT id FROM `$tbNl` WHERE `$colAsm` = ? AND id_siswa = ? LIMIT 1");
                    $checkNl->execute([$idAsmSync, $idSwSync]);
                    if ($checkNl->fetchColumn()) continue;

                    $kktpValSync = (float)($asmRow['kktp'] ?? 75.00);
                    if ($kktpValSync <= 0) $kktpValSync = 75.00;

                    $pdo->prepare("
                        UPDATE cbt_jawaban_siswa
                        SET skor_diperoleh = skor_bobot_maks, is_benar = 1
                        WHERE tipe_asesmen = ? AND id_asesmen = ? AND id_siswa = ?
                          AND (LOWER(tipe_soal) LIKE '%essay%' OR LOWER(tipe_soal) LIKE '%uraian%')
                          AND TRIM(COALESCE(jawaban_teks, '')) != ''
                          AND (skor_diperoleh IS NULL OR skor_diperoleh = 0)
                    ")->execute([$tipeSync, $idAsmSync, $idSwSync]);

                    $stmtSkSync = $pdo->prepare("
                        SELECT SUM(COALESCE(skor_diperoleh, 0)) AS total_skor, SUM(COALESCE(skor_bobot_maks, 10)) AS total_maks
                        FROM cbt_jawaban_siswa
                        WHERE tipe_asesmen = ? AND id_asesmen = ? AND id_siswa = ?
                    ");
                    $stmtSkSync->execute([$tipeSync, $idAsmSync, $idSwSync]);
                    $skSyncRow = $stmtSkSync->fetch(PDO::FETCH_ASSOC);

                    $skorDip = (float)($skSyncRow['total_skor'] ?? 0.00);
                    $totMaks = (float)($skSyncRow['total_maks'] ?? 100.00);
                    $nlAkhir = ($totMaks > 0) ? round(($skorDip / $totMaks) * 100, 2) : 0.00;
                    $stTuntas = ($nlAkhir >= $kktpValSync) ? 'Tuntas' : 'Belum Tuntas';

                    $predSync = 'C';
                    if ($nlAkhir >= 90) $predSync = 'A';
                    elseif ($nlAkhir >= 80) $predSync = 'B';
                    elseif ($nlAkhir >= 70) $predSync = 'C';
                    elseif ($nlAkhir >= 60) $predSync = 'D';
                    else $predSync = 'E';

                    $sqlIns = "
                        INSERT INTO `$tbNl`
                        (id_siswa, id_kelas_mata_pelajaran, `$colAsm`, pertemuan_ke, nilai_angka, nilai_kktp, predikat, status_tuntas, catatan_evaluasi, waktu_penilaian)
                        VALUES (?, ?, ?, 1, ?, ?, ?, ?, 'Hasil pengerjaan mandiri melalui aplikasi Ilmuna Exam Browser CBT.', NOW())
                        ON DUPLICATE KEY UPDATE
                            nilai_angka = VALUES(nilai_angka),
                            predikat = VALUES(predikat),
                            status_tuntas = VALUES(status_tuntas),
                            waktu_penilaian = NOW()
                    ";
                    $pdo->prepare($sqlIns)->execute([$idSwSync, $kmpSync, $idAsmSync, $nlAkhir, $kktpValSync, $predSync, $stTuntas]);
                }
            } catch (Throwable $eSync) {}

            // Kolom Tugas & Proyek
            $stmtT = $pdo->prepare("
                SELECT DISTINCT t.id, t.judul_tugas, t.jenis_penugasan, t.nilai_maksimal
                FROM tugas t
                LEFT JOIN kelas_mata_pelajaran kmp_t ON t.id_kelas_mata_pelajaran = kmp_t.id
                WHERE (t.id_kelas_mata_pelajaran = :id_kmp OR (kmp_t.id_kelas = :id_kelas AND kmp_t.id_mata_pelajaran = :id_mapel))
                ORDER BY t.id ASC
            ");
            $stmtT->execute([':id_kmp' => $idKmp, ':id_kelas' => $idKelas, ':id_mapel' => $idMapel]);
            $tugasCols = $stmtT->fetchAll(PDO::FETCH_ASSOC);

            // Kolom Asesmen Formatif
            $stmtF = $pdo->prepare("
                SELECT DISTINCT asf.id, asf.judul_asesmen, asf.teknik_asesmen
                FROM asesmen_formatif asf
                LEFT JOIN kelas_mata_pelajaran kmp_f ON asf.id_kelas_mata_pelajaran = kmp_f.id
                WHERE (asf.id_kelas_mata_pelajaran = :id_kmp OR (kmp_f.id_kelas = :id_kelas AND kmp_f.id_mata_pelajaran = :id_mapel))
                ORDER BY asf.id ASC
            ");
            $stmtF->execute([':id_kmp' => $idKmp, ':id_kelas' => $idKelas, ':id_mapel' => $idMapel]);
            $formatifCols = $stmtF->fetchAll(PDO::FETCH_ASSOC);

            // Kolom Asesmen Sumatif (Multi-Kelas)
            $stmtSm = $pdo->prepare("
                SELECT DISTINCT
                    asum.id, asum.judul_asesmen, asum.jenis_sumatif, asum.kktp, asum.pertemuan_ke, asum.created_at
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
            $stmtSm->execute([':id_kmp' => $idKmp, ':id_kelas' => $idKelas, ':id_mapel' => $idMapel]);
            $sumatifCols = $stmtSm->fetchAll(PDO::FETCH_ASSOC);

            // Resolusi KKTP Acuan
            $stmtKktpMp = $pdo->prepare("SELECT kktp FROM mata_pelajaran WHERE id = ? LIMIT 1");
            $stmtKktpMp->execute([$idMapel]);
            $kktpAcuan = (float)$stmtKktpMp->fetchColumn();
            if ($kktpAcuan <= 0) $kktpAcuan = 75.00;

            // Normalisasi slot label
            if (empty($tugasCols)) {
                $tugasCols = [
                    ['id' => 0, 'slot_code' => 'T1', 'judul_tugas' => 'Tugas 1 (Pemahaman Konsep)', 'jenis_penugasan' => 'Individu'],
                    ['id' => 0, 'slot_code' => 'T2', 'judul_tugas' => 'Tugas 2 (Studi Kasus / Proyek)', 'jenis_penugasan' => 'Individu']
                ];
            } else {
                foreach ($tugasCols as $idx => &$tc) { $tc['slot_code'] = 'T' . ($idx + 1); }
                unset($tc);
            }

            if (empty($formatifCols)) {
                $formatifCols = [
                    ['id' => 0, 'slot_code' => 'F1', 'judul_asesmen' => 'Formatif 1 (Kuis Proses Belajar)', 'teknik_asesmen' => 'Kuis'],
                    ['id' => 0, 'slot_code' => 'F2', 'judul_asesmen' => 'Formatif 2 (Observasi / Kinerja)', 'teknik_asesmen' => 'Observasi']
                ];
            } else {
                foreach ($formatifCols as $idx => &$fc) { $fc['slot_code'] = 'F' . ($idx + 1); }
                unset($fc);
            }

            if (empty($sumatifCols)) {
                $sumatifCols = [
                    ['id' => 0, 'slot_code' => 'S1', 'judul_asesmen' => 'Sumatif Lingkup Materi 1', 'jenis_sumatif' => 'Sumatif Lingkup Materi', 'kktp' => $kktpAcuan],
                    ['id' => 0, 'slot_code' => 'STS', 'judul_asesmen' => 'Sumatif Tengah Semester (STS)', 'jenis_sumatif' => 'Sumatif Tengah Semester', 'kktp' => $kktpAcuan],
                    ['id' => 0, 'slot_code' => 'SAS', 'judul_asesmen' => 'Sumatif Akhir Semester (SAS)', 'jenis_sumatif' => 'Sumatif Akhir Semester', 'kktp' => $kktpAcuan]
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

            // AMBIL DATA NILAI LENGKAP DENGAN FIELD REMEDIAL
            // 1. Tugas
            $gradesTugas = [];
            $tgsIds = array_filter(array_map(function($tc) { return (int)($tc['id'] ?? 0); }, $tugasCols));
            if (!empty($tgsIds)) {
                $inTgs = implode(',', array_fill(0, count($tgsIds), '?'));
                $sqlTgs = "SELECT nt.id_tugas, nt.id_siswa, nt.nilai, nt.nilai_remedial, nt.nilai_akhir_remedial, nt.status_remedial, nt.catatan_remedial
                           FROM nilai_tugas nt JOIN siswa s ON nt.id_siswa = s.id
                           WHERE s.id_kelas = ? AND (nt.id_kelas_mata_pelajaran = ? OR nt.id_tugas IN ($inTgs)) ORDER BY nt.id DESC";
                $stmtGrT = $pdo->prepare($sqlTgs);
                $stmtGrT->execute(array_merge([$idKelas, $idKmp], array_values($tgsIds)));
            } else {
                $stmtGrT = $pdo->prepare("SELECT nt.id_tugas, nt.id_siswa, nt.nilai, nt.nilai_remedial, nt.nilai_akhir_remedial, nt.status_remedial, nt.catatan_remedial 
                                           FROM nilai_tugas nt JOIN siswa s ON nt.id_siswa = s.id
                                           WHERE s.id_kelas = ? AND nt.id_kelas_mata_pelajaran = ? ORDER BY nt.id DESC");
                $stmtGrT->execute([$idKelas, $idKmp]);
            }
            while ($row = $stmtGrT->fetch(PDO::FETCH_ASSOC)) {
                $sId = (int)$row['id_siswa']; $tId = (int)$row['id_tugas'];
                if (!isset($gradesTugas[$sId][$tId]) || (float)$row['nilai'] > (float)$gradesTugas[$sId][$tId]['nilai']) {
                    $gradesTugas[$sId][$tId] = $row;
                }
            }

            // 2. Formatif
            $gradesFormatif = [];
            $fmtIds = array_filter(array_map(function($fc) { return (int)($fc['id'] ?? 0); }, $formatifCols));
            if (!empty($fmtIds)) {
                $inFmt = implode(',', array_fill(0, count($fmtIds), '?'));
                $sqlFmt = "SELECT nf.id_asesmen_formatif, nf.id_siswa, nf.nilai_angka, nf.nilai_remedial, nf.nilai_akhir_remedial, nf.status_remedial, nf.nilai_kktp, nf.status_tuntas, nf.catatan_remedial
                           FROM nilai_asesmen_formatif nf JOIN siswa s ON nf.id_siswa = s.id
                           WHERE s.id_kelas = ? AND (nf.id_kelas_mata_pelajaran = ? OR nf.id_asesmen_formatif IN ($inFmt)) ORDER BY nf.id DESC";
                $stmtGrF = $pdo->prepare($sqlFmt);
                $stmtGrF->execute(array_merge([$idKelas, $idKmp], array_values($fmtIds)));
            } else {
                $stmtGrF = $pdo->prepare("SELECT nf.id_asesmen_formatif, nf.id_siswa, nf.nilai_angka, nf.nilai_remedial, nf.nilai_akhir_remedial, nf.status_remedial, nf.nilai_kktp, nf.status_tuntas, nf.catatan_remedial
                                           FROM nilai_asesmen_formatif nf JOIN siswa s ON nf.id_siswa = s.id
                                           WHERE s.id_kelas = ? AND nf.id_kelas_mata_pelajaran = ? ORDER BY nf.id DESC");
                $stmtGrF->execute([$idKelas, $idKmp]);
            }
            while ($row = $stmtGrF->fetch(PDO::FETCH_ASSOC)) {
                $sId = (int)$row['id_siswa']; $fId = (int)$row['id_asesmen_formatif'];
                if (!isset($gradesFormatif[$sId][$fId]) || (float)$row['nilai_angka'] > (float)$gradesFormatif[$sId][$fId]['nilai_angka']) {
                    $gradesFormatif[$sId][$fId] = $row;
                }
            }

            // 3. Sumatif
            $gradesSumatif = [];
            $smIds = array_filter(array_map(function($sc) { return (int)($sc['id'] ?? 0); }, $sumatifCols));
            if (!empty($smIds)) {
                $inSm = implode(',', array_fill(0, count($smIds), '?'));
                $sqlSm = "SELECT ns.id_asesmen_sumatif, ns.id_siswa, ns.nilai_angka, ns.nilai_remedial, ns.nilai_akhir_remedial, ns.status_remedial, ns.nilai_kktp, ns.status_tuntas, ns.catatan_remedial
                          FROM nilai_asesmen_sumatif ns JOIN siswa s ON ns.id_siswa = s.id
                          WHERE s.id_kelas = ? AND (ns.id_kelas_mata_pelajaran = ? OR ns.id_asesmen_sumatif IN ($inSm)) ORDER BY ns.id DESC";
                $stmtGrSm = $pdo->prepare($sqlSm);
                $stmtGrSm->execute(array_merge([$idKelas, $idKmp], array_values($smIds)));
            } else {
                $stmtGrSm = $pdo->prepare("SELECT ns.id_asesmen_sumatif, ns.id_siswa, ns.nilai_angka, ns.nilai_remedial, ns.nilai_akhir_remedial, ns.status_remedial, ns.nilai_kktp, ns.status_tuntas, ns.catatan_remedial
                                           FROM nilai_asesmen_sumatif ns JOIN siswa s ON ns.id_siswa = s.id
                                           WHERE s.id_kelas = ? AND ns.id_kelas_mata_pelajaran = ? ORDER BY ns.id DESC");
                $stmtGrSm->execute([$idKelas, $idKmp]);
            }
            while ($row = $stmtGrSm->fetch(PDO::FETCH_ASSOC)) {
                $sId = (int)$row['id_siswa']; $smId = (int)$row['id_asesmen_sumatif'];
                if (!isset($gradesSumatif[$sId][$smId]) || (float)$row['nilai_angka'] > (float)$gradesSumatif[$sId][$smId]['nilai_angka']) {
                    $gradesSumatif[$sId][$smId] = $row;
                }
            }

            // 4. AMBIL DATA NILAI AKHIR DARI TABEL rekap_nilai_siswa_mapel (JIKA SUDAH DIGENERATE GURU)
            $rekapRaporMap = [];
            try {
                $stmtRekap = $pdo->prepare("
                    SELECT id_siswa, nilai_akhir_semester, predikat_akhir, status_kelulusan_kktp,
                           rata_sumatif_materi, nilai_sts, nilai_asas, updated_at
                    FROM rekap_nilai_siswa_mapel
                    WHERE id_kelas_mata_pelajaran = ?
                ");
                $stmtRekap->execute([$idKmp]);
                while ($rk = $stmtRekap->fetch(PDO::FETCH_ASSOC)) {
                    $rekapRaporMap[(int)$rk['id_siswa']] = $rk;
                }
            } catch (Throwable $eRk) {}

            echo json_encode([
                'success' => true,
                'data' => [
                    'kelas'           => $kelasInfo,
                    'mapel'           => $mapelInfo,
                    'kktp'            => $kktpAcuan,
                    'siswa_list'      => $siswaList ?: [],
                    'tugas_cols'      => $tugasCols,
                    'formatif_cols'   => $formatifCols,
                    'sumatif_cols'    => $sumatifCols,
                    'grades_tugas'    => $gradesTugas,
                    'grades_formatif' => $gradesFormatif,
                    'grades_sumatif'  => $gradesSumatif,
                    'rekap_rapor'     => $rekapRaporMap
                ]
            ]);
            exit;
        }

        // 4. ACTION APPROVAL REMEDIAL (SETUJUI REMEDIAL -> NILAI DIAKUI = KKTP)
        if ($action === 'approve_remedial') {
            $tipe    = trim($_POST['tipe'] ?? 'sumatif');
            $itemId  = (int)($_POST['item_id'] ?? 0);
            $idSiswa = (int)($_POST['id_siswa'] ?? 0);
            $kktp    = (float)($_POST['kktp'] ?? 75.00);
            $catatan = trim($_POST['catatan'] ?? '');

            $result = ilmuna_setujui_remedial($pdo, $tipe, $itemId, $idSiswa, $kktp, $catatan);
            echo json_encode($result);
            exit;
        }

        // 5. ACTION REMIDI ULANG (SISWA HARUS REMEDIAL KEMBALI)
        if ($action === 'repeat_remedial') {
            $tipe    = trim($_POST['tipe'] ?? 'sumatif');
            $itemId  = (int)($_POST['item_id'] ?? 0);
            $idSiswa = (int)($_POST['id_siswa'] ?? 0);
            $catatan = trim($_POST['catatan'] ?? '');

            $result = ilmuna_remidi_ulang($pdo, $tipe, $itemId, $idSiswa, $catatan);
            echo json_encode($result);
            exit;
        }

        // 6. GENERATE NILAI AKHIR RAPOR (SIMPAN KE rekap_nilai_siswa_mapel)
        if ($action === 'generate_nilai_akhir') {
            $idKelas        = (int)($_POST['id_kelas'] ?? 0);
            $idKmp          = (int)($_POST['id_kmp'] ?? 0);
            $semester       = trim($_POST['semester'] ?? 'Ganjil');
            $tahunPelajaran = trim($_POST['tahun_pelajaran'] ?? '2026/2027');

            $result = ilmuna_generate_rekap_nilai_akhir($pdo, $idKelas, $idKmp, $semester, $tahunPelajaran);
            echo json_encode($result);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenali.']);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()]);
        exit;
    }
}

$pageTitle = 'Penilaian & Rekap Nilai Siswa (KKTP)';
$pageIcon  = 'solar:diploma-verified-bold';

require_once BASE_PATH . '/includes/header.php';
loadCentralTemplates(['modal', 'calendar', 'table']);
?>

<style>
/* ==============================================================================
 * LUMINA GRADEBOOK & REMEDIAL WORKFLOW CUSTOM STYLES (PORTAL GURU)
 * ============================================================================== */
.ilmuna-table-responsive {
    overflow-x: auto;
    position: relative;
    max-height: 720px;
    scrollbar-width: thin;
    scrollbar-color: #CBD5E1 #F1F5F9;
}
.ilmuna-gradebook-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 13px;
    text-align: center;
    background: #FFFFFF;
}
.ilmuna-gradebook-table th,
.ilmuna-gradebook-table td {
    border-right: 1px solid #E2E8F0;
    border-bottom: 1px solid #E2E8F0;
    padding: 9px 7px;
    vertical-align: middle;
}
.ilmuna-gradebook-table thead tr:first-child th {
    background: #F8FAFC;
    color: #0F172A;
    font-weight: 800;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    position: sticky;
    top: 0;
    z-index: 10;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
}
.ilmuna-gradebook-table thead tr:nth-child(2) th {
    background: #F1F5F9;
    color: #334155;
    font-weight: 700;
    font-size: 11.5px;
    position: sticky;
    top: 36px;
    z-index: 9;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
}
.ilmuna-gradebook-table thead th.group-tugas {
    background: #EFF6FF !important;
    color: #1D4ED8 !important;
    border-bottom: 2.5px solid #3B82F6 !important;
}
.ilmuna-gradebook-table thead th.group-formatif {
    background: #ECFDF5 !important;
    color: #047857 !important;
    border-bottom: 2.5px solid #10B981 !important;
}
.ilmuna-gradebook-table thead th.group-sumatif {
    background: #FFFBEB !important;
    color: #B45309 !important;
    border-bottom: 2.5px solid #F59E0B !important;
}

/* STICKY COLUMNS */
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
.col-no   { left: 0; width: 42px; min-width: 42px; text-align: center; }
.col-nis  { left: 42px; width: 90px; min-width: 90px; font-weight: 600; color: #64748B; }
.col-nisn { left: 132px; width: 100px; min-width: 100px; font-weight: 600; color: #64748B; }
.col-nama { left: 232px; min-width: 220px; text-align: left !important; font-weight: 700; color: #0F172A; box-shadow: 4px 0 8px rgba(0,0,0,0.04); }

/* CELL STYLES */
.grade-box-cell {
    padding: 5px 3px !important;
    min-width: 76px;
    position: relative;
    user-select: none;
}
.grade-box-cell.is-locked {
    background: #FAFAFA;
    cursor: not-allowed;
}
.grade-box-cell.is-remedial-clickable {
    cursor: pointer;
    background: #FFFBEB;
    transition: all 0.2s ease;
    border: 1px dashed #F59E0B !important;
}
.grade-box-cell.is-remedial-clickable:hover {
    background: #FEF3C7 !important;
    transform: scale(1.02);
    box-shadow: 0 4px 10px rgba(245, 158, 11, 0.25);
    z-index: 8;
}

/* CELL INNER SCORE BADGES */
.grade-box-inner {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 2px;
}
.score-asli-strikethrough {
    font-size: 11px;
    color: #DC2626;
    text-decoration: line-through;
    opacity: 0.75;
}
.score-effective {
    font-size: 13px;
    font-weight: 800;
}
.score-effective.is-passed {
    color: #065F46;
}
.score-effective.is-failed {
    color: #DC2626;
}
.score-effective.is-remedial-approved {
    color: #0D7A87;
}

.badge-remed-tag {
    display: inline-block;
    padding: 1px 6px;
    border-radius: 4px;
    font-size: 9.5px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.2px;
}
.badge-need-remed {
    background: #FEE2E2;
    color: #991B1B;
    border: 1px solid #FECACA;
}
.badge-review-remed {
    background: #FEF3C7;
    color: #92400E;
    border: 1px solid #FDE68A;
    animation: pulseBadge 1.8s infinite;
}
.badge-approved-remed {
    background: #ECFDF5;
    color: #065F46;
    border: 1px solid #A7F3D0;
}
.badge-repeat-remed {
    background: #F3F4F6;
    color: #4B5563;
    border: 1px solid #E5E7EB;
}

@keyframes pulseBadge {
    0% { transform: scale(1); }
    50% { transform: scale(1.04); }
    100% { transform: scale(1); }
}

/* EMPTY ROW BOX */
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

/* MODAL OVERLAY & BOX */
.ilmuna-modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(4px);
    z-index: 9999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.ilmuna-modal-box {
    background: #FFFFFF;
    border-radius: 18px;
    max-width: 520px;
    width: 100%;
    box-shadow: 0 20px 40px rgba(15, 23, 42, 0.25);
    overflow: hidden;
    animation: modalScaleIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes modalScaleIn {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}
.ilmuna-modal-header {
    background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 100%);
    padding: 20px 24px;
    color: #FFFFFF;
    position: relative;
}
.ilmuna-modal-body {
    padding: 24px;
}
.ilmuna-modal-footer {
    padding: 16px 24px;
    background: #F8FAFC;
    border-top: 1px solid #E2E8F0;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    flex-wrap: wrap;
}

/* SCORE COMPARISON CARDS */
.score-compare-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
    margin: 16px 0;
}
.score-card {
    border-radius: 12px;
    padding: 12px 8px;
    text-align: center;
    border: 1px solid #E2E8F0;
}
.score-card.asli { background: #FEF2F2; border-color: #FECACA; }
.score-card.remed { background: #EFF6FF; border-color: #BFDBFE; }
.score-card.kktp { background: #FFFBEB; border-color: #FDE68A; }
.score-card-label {
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 4px;
}
.score-card.asli .score-card-label { color: #991B1B; }
.score-card.remed .score-card-label { color: #1E40AF; }
.score-card.kktp .score-card-label { color: #92400E; }
.score-card-val {
    font-size: 18px;
    font-weight: 900;
    font-family: monospace;
}
</style>

<!-- MAIN CONTENT WRAPPER -->
<div class="content-wrapper">
    <!-- TOOLBAR & FILTERS -->
    <div class="content-card" style="margin-bottom: 20px;">
        <div class="content-card-body" style="padding: 18px 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
                <!-- Filter Kiri (Cascading Kelas & Mapel) -->
                <div style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center; flex: 1;">
                    <div style="min-width: 240px;">
                        <label style="display: block; font-size: 11.5px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 5px;">
                            <iconify-icon icon="solar:buildings-2-bold" style="color:#0D7A87;"></iconify-icon> Rombel / Kelas
                        </label>
                        <select id="filterKelas" class="form-control" style="font-size: 13.5px; font-weight: 600; height: 40px; border-radius: 10px;">
                            <option value="0">Memuat pilihan kelas...</option>
                        </select>
                    </div>

                    <div style="min-width: 280px;">
                        <label style="display: block; font-size: 11.5px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 5px;">
                            <iconify-icon icon="solar:book-bookmark-bold" style="color:#0D7A87;"></iconify-icon> Mata Pelajaran yang Anda Ampu
                        </label>
                        <select id="filterMapel" class="form-control" style="font-size: 13.5px; font-weight: 600; height: 40px; border-radius: 10px;" disabled>
                            <option value="0">— Pilih Kelas Terlebih Dahulu —</option>
                        </select>
                    </div>

                    <div style="padding-top: 20px;">
                        <button type="button" id="btnResetFilter" class="btn btn-secondary btn-sm" style="height: 40px; border-radius: 10px; font-weight: 600;">
                            <iconify-icon icon="solar:restart-bold"></iconify-icon> Reset
                        </button>
                    </div>
                </div>

                <!-- Aksi Kanan (Pencarian, Generate Nilai Akhir & Export) -->
                <div style="display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">
                    <div style="position: relative; min-width: 190px;">
                        <label style="display: block; font-size: 11.5px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 5px;">
                            Cari Siswa
                        </label>
                        <input type="text" id="searchSiswa" class="form-control" placeholder="Ketik nama atau NIS..." style="font-size: 13px; height: 40px; border-radius: 10px; padding-left: 32px;">
                        <iconify-icon icon="solar:magnifer-bold" style="position: absolute; left: 10px; top: 33px; color: #94A3B8; font-size: 15px;"></iconify-icon>
                    </div>

                    <button type="button" id="btnGenerateNilaiAkhir" class="btn btn-gold" style="height: 40px; padding: 0 16px; font-weight: 800; font-size: 13px; border-radius: 10px; display: inline-flex; align-items: center; gap: 7px; border:none; box-shadow: 0 4px 12px rgba(245,158,11,.3);" disabled>
                        <iconify-icon icon="solar:magic-stick-3-bold" width="17"></iconify-icon> Generate Nilai Akhir
                    </button>

                    <a id="btnExportExcel" href="#" class="btn btn-secondary" style="height: 40px; border-radius: 10px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;" disabled>
                        <iconify-icon icon="solar:file-download-bold" style="color: #10B981;"></iconify-icon> Export Excel
                    </a>

                    <a id="btnPrintPdf" href="#" target="_blank" class="btn btn-secondary" style="height: 40px; border-radius: 10px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;" disabled>
                        <iconify-icon icon="solar:printer-bold" style="color: #0D7A87;"></iconify-icon> Cetak PDF
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- PETUNJUK WARNA & ATURAN GURU -->
    <div style="display: flex; flex-wrap: wrap; gap: 14px; align-items: center; background: #F8FAFC; border: 1px solid #E2E8F0; padding: 10px 18px; border-radius: 12px; margin-bottom: 16px; font-size: 12px; color: #475569;">
        <div style="font-weight: 800; color: #0F172A; display: inline-flex; align-items: center; gap: 5px;">
            <iconify-icon icon="solar:info-circle-bold" style="color:#0D7A87;"></iconify-icon> Aturan Wewenang Guru:
        </div>
        <div>
            <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: #10B981;"></span>
            <strong>Nilai &ge; KKTP:</strong> Terkunci (Read-Only)
        </div>
        <div>
            <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: #F59E0B;"></span>
            <strong>Nilai &lt; KKTP + Ada Remidi:</strong> <em>Bisa diklik untuk Evaluasi / Setujui</em>
        </div>
        <div>
            <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: #0D7A87;"></span>
            <strong>Setujui Remedial:</strong> Nilai diakui = KKTP (Nilai asli utuh)
        </div>
        <div>
            <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: #64748B;"></span>
            <strong>Generate Rapor:</strong> Menyimpan resmi ke tabel database
        </div>
    </div>

    <!-- GRADEBOOK TABLE CONTAINER -->
    <div class="content-card">
        <div class="content-card-body" style="padding: 0;" id="tableWrap">
            <!-- Initial Empty State -->
            <div class="ilmuna-empty-row" id="emptyStateBox">
                <div class="ilmuna-empty-row__icon"><iconify-icon icon="solar:notebook-bookmark-bold"></iconify-icon></div>
                <h4 style="margin: 0 0 6px; font-size: 16px; font-weight: 800; color: #1E293B;">Pilih Rombel / Kelas Terlebih Dahulu</h4>
                <p style="margin: 0 auto; max-width: 520px; color: #64748B; font-size: 13px;">
                    Silakan pilih salah satu rombel kelas dan mata pelajaran yang Anda ampu di atas untuk mengelola nilai formatif, sumatif, persetujuan remedial, dan penetapan nilai akhir rapor.
                </p>
                <div class="ilmuna-empty-row__chips" id="quickChipsContainer"></div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL EVALUASI & APPROVAL REMEDIAL GURU -->
<div class="ilmuna-modal-overlay" id="modalRemedialApproval">
    <div class="ilmuna-modal-box">
        <div class="ilmuna-modal-header">
            <h3 style="margin: 0; font-size: 16px; font-weight: 800; display: flex; align-items: center; gap: 8px;">
                <iconify-icon icon="solar:diploma-verified-bold" width="20"></iconify-icon> Evaluasi Remedial Peserta Didik
            </h3>
            <p style="margin: 4px 0 0; font-size: 12px; color: rgba(255,255,255,0.85);" id="modalSubHeader">
                Wewenang Penuh Guru Pengampu Mata Pelajaran
            </p>
        </div>
        <div class="ilmuna-modal-body">
            <!-- Info Siswa -->
            <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 12px; padding: 12px 14px; margin-bottom: 14px;">
                <div style="font-size: 14px; font-weight: 800; color: #0F172A;" id="modalSiswaNama">-</div>
                <div style="font-size: 12px; color: #64748B; margin-top: 2px;" id="modalSiswaNis">-</div>
                <div style="font-size: 12px; color: #0D7A87; font-weight: 700; margin-top: 4px;" id="modalAsesmenJudul">-</div>
            </div>

            <!-- Kartu Komparasi Nilai -->
            <div class="score-compare-grid">
                <div class="score-card asli">
                    <div class="score-card-label">Nilai Asli</div>
                    <div class="score-card-val" id="modalValAsli" style="color: #DC2626;">-</div>
                    <div style="font-size: 10px; color: #991B1B; margin-top: 2px;">Tetap tersimpan utuh</div>
                </div>
                <div class="score-card remed">
                    <div class="score-card-label">Hasil Remedial</div>
                    <div class="score-card-val" id="modalValRemedi" style="color: #2563EB;">-</div>
                    <div style="font-size: 10px; color: #1E40AF; margin-top: 2px;">CBT Perbaikan Siswa</div>
                </div>
                <div class="score-card kktp">
                    <div class="score-card-label">Batas KKTP</div>
                    <div class="score-card-val" id="modalValKktp" style="color: #D97706;">-</div>
                    <div style="font-size: 10px; color: #92400E; margin-top: 2px;">Nilai yang Diakui</div>
                </div>
            </div>

            <!-- Status Saat Ini -->
            <div id="modalStatusBox" style="padding: 10px 14px; border-radius: 10px; font-size: 12.5px; margin-bottom: 14px; background: #FFFBEB; border: 1px solid #FDE68A; color: #92400E;">
                <iconify-icon icon="solar:info-circle-bold" style="vertical-align: middle;"></iconify-icon>
                <span id="modalStatusText">Menunggu keputusan Guru Pengampu.</span>
            </div>

            <!-- Catatan Evaluasi Guru (Opsional) -->
            <div>
                <label style="display: block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 4px;">
                    Catatan Evaluasi / Umpan Balik Guru (Opsional):
                </label>
                <textarea id="modalCatatanGuru" class="form-control" rows="2" placeholder="Tuliskan catatan perbaikan untuk siswa..." style="font-size: 12.5px; border-radius: 8px;"></textarea>
            </div>
        </div>
        <div class="ilmuna-modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" id="btnModalClose" style="border-radius: 8px; font-weight: 600;">
                Batal
            </button>
            <button type="button" class="btn btn-danger btn-sm" id="btnActionRemidiUlang" style="border-radius: 8px; font-weight: 700; background: #DC2626; display: inline-flex; align-items: center; gap: 5px;">
                <iconify-icon icon="solar:restart-bold"></iconify-icon> Remidi Ulang
            </button>
            <button type="button" class="btn btn-primary btn-sm" id="btnActionApprove" style="border-radius: 8px; font-weight: 700; background: #0D7A87; display: inline-flex; align-items: center; gap: 5px;">
                <iconify-icon icon="solar:check-circle-bold"></iconify-icon> Setujui (Nilai = KKTP)
            </button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var AJAX_URL = '<?= BASE_URL ?>/dashboard/guru/reports.php';
    var cachedKelas = [];
    var cachedMapel = [];
    var currentGradebookData = null;

    var state = {
        id_kelas: 0,
        id_kmp: 0
    };

    // Modal Context State
    var activeModalContext = null;

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /* --------------------------------------------------------------------------
     * 1. LOAD DAFTAR KELAS
     * -------------------------------------------------------------------------- */
    function loadKelasOptions() {
        var fd = new FormData();
        fd.append('action', 'list_kelas_options');

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (!res || !res.success) return;
                cachedKelas = res.data.kelas || [];

                var filterSelect = document.getElementById('filterKelas');
                var optHtml = '<option value="0">— Pilih Rombel Kelas —</option>';

                cachedKelas.forEach(function(k) {
                    var label = k.nama_kelas + ' (' + (k.tahun_pelajaran || '2026/2027') + ') — ' + k.total_siswa + ' Siswa';
                    optHtml += '<option value="' + k.id + '">' + escapeHtml(label) + '</option>';
                });

                filterSelect.innerHTML = optHtml;
                renderClassEmptyState();
            })
            .catch(function(err) {
                console.error('Error load kelas:', err);
            });
    }

    function renderClassEmptyState() {
        var tableWrap = document.getElementById('tableWrap');
        document.getElementById('btnExportExcel').setAttribute('disabled', 'disabled');
        document.getElementById('btnPrintPdf').setAttribute('disabled', 'disabled');
        document.getElementById('btnGenerateNilaiAkhir').setAttribute('disabled', 'disabled');

        var chipsHtml = '';
        cachedKelas.forEach(function(k) {
            chipsHtml += '<button type="button" class="ilmuna-empty-chip" data-id="' + k.id + '">' +
                '<iconify-icon icon="solar:notebook-bookmark-bold" width="13"></iconify-icon> ' +
                escapeHtml(k.nama_kelas) + ' (' + k.total_siswa + ' Siswa)</button>';
        });

        tableWrap.innerHTML = 
            '<div class="ilmuna-empty-row" id="emptyStateBox">' +
                '<div class="ilmuna-empty-row__icon"><iconify-icon icon="solar:notebook-bookmark-bold"></iconify-icon></div>' +
                '<h4 style="margin:0 0 6px; font-weight:800; color:#1E293B;">Pilih Rombel / Kelas Terlebih Dahulu</h4>' +
                '<p style="margin:0 auto; max-width:520px; color:#64748B; font-size:13px;">Daftar nilai tugas, formatif, sumatif, dan wewenang remedial akan ditampilkan setelah Anda memilih Rombel Kelas dan Mata Pelajaran.</p>' +
                '<div class="ilmuna-empty-row__chips">' + chipsHtml + '</div>' +
            '</div>';

        tableWrap.querySelectorAll('.ilmuna-empty-chip').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var cid = parseInt(btn.dataset.id, 10);
                document.getElementById('filterKelas').value = cid;
                selectClass(cid);
            });
        });
    }

    function renderMapelEmptyState(className) {
        var tableWrap = document.getElementById('tableWrap');
        document.getElementById('btnExportExcel').setAttribute('disabled', 'disabled');
        document.getElementById('btnPrintPdf').setAttribute('disabled', 'disabled');
        document.getElementById('btnGenerateNilaiAkhir').setAttribute('disabled', 'disabled');

        var chipsHtml = '';
        if (cachedMapel.length === 0) {
            chipsHtml = '<span style="color:#94a3b8; font-size:13px;">Belum ada mata pelajaran yang ditautkan ke Anda untuk kelas ini.</span>';
        } else {
            cachedMapel.forEach(function(m) {
                var kktpTag = m.kktp ? ' &bull; KKTP: ' + Number(m.kktp).toFixed(0) : '';
                chipsHtml += '<button type="button" class="ilmuna-empty-chip" data-id="' + m.id + '">' +
                    '<iconify-icon icon="solar:book-bookmark-bold" width="13"></iconify-icon> ' +
                    escapeHtml(m.nama_mata_pelajaran) + kktpTag + '</button>';
            });
        }

        tableWrap.innerHTML = 
            '<div class="ilmuna-empty-row" id="emptyStateBox">' +
                '<div class="ilmuna-empty-row__icon" style="color:#0D7A87; background:rgba(13,122,135,0.08);"><iconify-icon icon="solar:book-bookmark-bold"></iconify-icon></div>' +
                '<h4 style="margin:0 0 6px; font-weight:800; color:#1E293B;">Pilih Mata Pelajaran Terlebih Dahulu</h4>' +
                '<p style="margin:0 auto; max-width:520px; color:#64748B; font-size:13px;">Silakan pilih salah satu mata pelajaran yang Anda ampu pada <strong>' + escapeHtml(className) + '</strong>.</p>' +
                '<div class="ilmuna-empty-row__chips">' + chipsHtml + '</div>' +
            '</div>';

        tableWrap.querySelectorAll('.ilmuna-empty-chip').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var mid = parseInt(btn.dataset.id, 10);
                document.getElementById('filterMapel').value = mid;
                selectMapel(mid);
            });
        });
    }

    /* --------------------------------------------------------------------------
     * 2. SELECT CLASS LOGIC
     * -------------------------------------------------------------------------- */
    function selectClass(classId) {
        state.id_kelas = parseInt(classId, 10) || 0;
        state.id_kmp = 0;
        currentGradebookData = null;

        var filterMapel = document.getElementById('filterMapel');

        if (state.id_kelas <= 0) {
            filterMapel.innerHTML = '<option value="0">— Pilih Kelas Terlebih Dahulu —</option>';
            filterMapel.setAttribute('disabled', 'disabled');
            renderClassEmptyState();
            return;
        }

        var selectedKelas = cachedKelas.find(function(k){ return parseInt(k.id, 10) === state.id_kelas; });
        var className = selectedKelas ? selectedKelas.nama_kelas : 'Kelas Terpilih';

        filterMapel.innerHTML = '<option value="0">Memuat mata pelajaran...</option>';
        filterMapel.setAttribute('disabled', 'disabled');

        var fd = new FormData();
        fd.append('action', 'get_mapel_by_kelas');
        fd.append('id_kelas', state.id_kelas);

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (!res || !res.success) {
                    filterMapel.innerHTML = '<option value="0">Gagal memuat mapel</option>';
                    return;
                }

                cachedMapel = res.data || [];
                var optHtml = '<option value="0">— Pilih Mata Pelajaran —</option>';

                if (cachedMapel.length === 0) {
                    optHtml = '<option value="0">— Tidak ada mapel yang diampu pada kelas ini —</option>';
                    filterMapel.innerHTML = optHtml;
                    filterMapel.setAttribute('disabled', 'disabled');
                } else {
                    cachedMapel.forEach(function(m) {
                        var kktpTag = m.kktp ? ' [KKTP: ' + Number(m.kktp).toFixed(0) + ']' : '';
                        optHtml += '<option value="' + m.id + '">' + escapeHtml(m.nama_mata_pelajaran) + kktpTag + '</option>';
                    });
                    filterMapel.innerHTML = optHtml;
                    filterMapel.removeAttribute('disabled');
                }

                renderMapelEmptyState(className);
            })
            .catch(function(err) {
                console.error('Error load mapel:', err);
                filterMapel.innerHTML = '<option value="0">Gagal memuat mapel</option>';
            });
    }

    /* --------------------------------------------------------------------------
     * 3. SELECT MAPEL LOGIC (FETCH GRADEBOOK)
     * -------------------------------------------------------------------------- */
    function selectMapel(kmpId) {
        state.id_kmp = parseInt(kmpId, 10) || 0;

        if (state.id_kmp <= 0) {
            var selectedKelas = cachedKelas.find(function(k){ return parseInt(k.id, 10) === state.id_kelas; });
            var className = selectedKelas ? selectedKelas.nama_kelas : 'Kelas Terpilih';
            renderMapelEmptyState(className);
            return;
        }

        fetchGradebook();
    }

    function fetchGradebook() {
        var tableWrap = document.getElementById('tableWrap');

        tableWrap.innerHTML = 
            '<div style="text-align:center; padding: 60px 20px; color:#64748b;">' +
                '<iconify-icon icon="solar:restart-bold" class="ilmuna-spin" width="34" style="color:#0D7A87;"></iconify-icon>' +
                '<p style="margin-top:12px; font-weight:700; font-size:15px; color:#1e293b;">Memuat data nilai &amp; sesi remedial...</p>' +
                '<p style="font-size:12.5px; color:#64748b; margin:0;">Menghubungkan seluruh data tugas, formatif, sumatif, dan status ketuntasan siswa...</p>' +
            '</div>';

        var fd = new FormData();
        fd.append('action', 'load_gradebook');
        fd.append('id_kelas', state.id_kelas);
        fd.append('id_kmp', state.id_kmp);

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (!res || !res.success) {
                    tableWrap.innerHTML = '<div style="padding:40px; text-align:center; color:#dc2626;">' +
                        escapeHtml(res ? res.message : 'Gagal memuat rekap nilai.') + '</div>';
                    return;
                }

                currentGradebookData = res.data;
                renderGradebook(res.data);
            })
            .catch(function(err) {
                console.error('Error fetch gradebook:', err);
                tableWrap.innerHTML = '<div style="padding:40px; text-align:center; color:#dc2626;">Terjadi kesalahan jaringan saat memuat rekap nilai.</div>';
            });
    }

    /* --------------------------------------------------------------------------
     * 4. RENDER GRADEBOOK DOM & ATURAN EDITING GURU
     * -------------------------------------------------------------------------- */
    function renderGradebook(data) {
        var kelas       = data.kelas || {};
        var mapel       = data.mapel || {};
        var kktpAcuan   = parseFloat(data.kktp) || 75.00;
        var siswaList   = data.siswa_list || [];
        var tugasCols   = data.tugas_cols || [];
        var formatifCols= data.formatif_cols || [];
        var sumatifCols = data.sumatif_cols || [];
        var gradesTugas = data.grades_tugas || {};
        var gradesFormatif = data.grades_formatif || {};
        var gradesSumatif  = data.grades_sumatif || {};
        var rekapRaporMap  = data.rekap_rapor || {};

        // 1. Enable Toolbar Actions
        document.getElementById('btnExportExcel').removeAttribute('disabled');
        document.getElementById('btnExportExcel').href = '<?= BASE_URL ?>/dashboard/admin/reports-excel.php?id_kelas=' + state.id_kelas + '&id_kmp=' + state.id_kmp;
        document.getElementById('btnPrintPdf').removeAttribute('disabled');
        document.getElementById('btnPrintPdf').href = '<?= BASE_URL ?>/dashboard/admin/reports-pdf.php?id_kelas=' + state.id_kelas + '&id_kmp=' + state.id_kmp;
        document.getElementById('btnGenerateNilaiAkhir').removeAttribute('disabled');

        var tableWrap = document.getElementById('tableWrap');

        var tableHtml = 
            '<div class="ilmuna-table-responsive">' +
                '<table class="ilmuna-gradebook-table" id="tableGradebook">' +
                    '<thead>' +
                        '<tr>' +
                            '<th rowspan="2" class="sticky-col col-no">#</th>' +
                            '<th rowspan="2" class="sticky-col col-nis">NIS</th>' +
                            '<th rowspan="2" class="sticky-col col-nisn">NISN</th>' +
                            '<th rowspan="2" class="sticky-col col-nama">Nama Lengkap Siswa</th>' +
                            '<th colspan="' + tugasCols.length + '" class="group-tugas">Tugas &amp; Proyek</th>' +
                            '<th colspan="' + formatifCols.length + '" class="group-formatif">Asesmen Formatif</th>' +
                            '<th colspan="' + sumatifCols.length + '" class="group-sumatif">Asesmen Sumatif (LM &bull; STS &bull; SAS)</th>' +
                            '<th rowspan="2" style="width: 90px; background: #0F172A; color: #FFFFFF;">Nilai Akhir</th>' +
                            '<th rowspan="2" style="width: 65px; background: #1E293B; color: #FFFFFF;">Predikat</th>' +
                            '<th rowspan="2" style="width: 110px; background: #1E293B; color: #FFFFFF;">Status Rapor</th>' +
                        '</tr>' +
                        '<tr>';

        // Subheaders
        tugasCols.forEach(function(tc) {
            tableHtml += '<th title="' + escapeHtml(tc.judul_tugas) + '" style="cursor:help; width:68px;">' + escapeHtml(tc.slot_code) + '</th>';
        });
        formatifCols.forEach(function(fc) {
            tableHtml += '<th title="' + escapeHtml(fc.judul_asesmen) + '" style="cursor:help; width:72px;">' + escapeHtml(fc.slot_code) + '</th>';
        });
        sumatifCols.forEach(function(sc) {
            var k = sc.kktp ? parseFloat(sc.kktp) : kktpAcuan;
            tableHtml += '<th title="' + escapeHtml(sc.judul_asesmen) + ' (KKTP: ' + k + ')" style="cursor:help; width:75px;">' + escapeHtml(sc.slot_code) + '</th>';
        });

        tableHtml += 
                        '</tr>' +
                    '</thead>' +
                    '<tbody id="tbodyGradebook">';

        if (siswaList.length === 0) {
            var totalColspan = 7 + tugasCols.length + formatifCols.length + sumatifCols.length;
            tableHtml += '<tr><td colspan="' + totalColspan + '" style="padding:40px; color:#64748B; font-weight:600;">Tidak ada data siswa pada rombel kelas ini.</td></tr>';
        } else {
            var no = 1;
            siswaList.forEach(function(s) {
                var sId = s.id;

                tableHtml += 
                    '<tr class="row-siswa" data-id="' + sId + '" data-nama="' + escapeHtml((s.nama_lengkap || '').toLowerCase()) + '" data-nis="' + escapeHtml(s.nis || '') + '">' +
                        '<td class="sticky-col col-no">' + (no++) + '</td>' +
                        '<td class="sticky-col col-nis">' + escapeHtml(s.nis || '-') + '</td>' +
                        '<td class="sticky-col col-nisn">' + escapeHtml(s.nisn || '-') + '</td>' +
                        '<td class="sticky-col col-nama">' +
                            '<div style="font-weight:700; color:#0F172A;">' + escapeHtml(s.nama_lengkap) + '</div>' +
                            '<div style="font-size:11px; color:#94A3B8;">' + escapeHtml(s.nama_kelas || kelas.nama_kelas) + '</div>' +
                        '</td>';

                // 1. Tugas
                tugasCols.forEach(function(tc) {
                    var tId = tc.id;
                    var gT = gradesTugas[sId] ? gradesTugas[sId][tId] : null;
                    tableHtml += renderCellGuru(s, 'tugas', tId, tc.slot_code, tc.judul_tugas, gT, kktpAcuan);
                });

                // 2. Formatif
                formatifCols.forEach(function(fc) {
                    var fId = fc.id;
                    var gF = gradesFormatif[sId] ? gradesFormatif[sId][fId] : null;
                    tableHtml += renderCellGuru(s, 'formatif', fId, fc.slot_code, fc.judul_asesmen, gF, kktpAcuan);
                });

                // 3. Sumatif
                sumatifCols.forEach(function(sc) {
                    var smId = sc.id;
                    var kktpThis = sc.kktp ? parseFloat(sc.kktp) : kktpAcuan;
                    var gSm = gradesSumatif[sId] ? gradesSumatif[sId][smId] : null;
                    tableHtml += renderCellGuru(s, 'sumatif', smId, sc.slot_code, sc.judul_asesmen, gSm, kktpThis);
                });

                // 4. Rekap Nilai Akhir (dari rekap_nilai_siswa_mapel jika sudah digenerate)
                var rk = rekapRaporMap[sId] || null;
                var naVal = rk ? rk.nilai_akhir_semester : null;
                var predVal = rk ? rk.predikat_akhir : '-';
                var statVal = rk ? rk.status_kelulusan_kktp : null;

                tableHtml += 
                    '<td style="font-weight: 800; font-size: 14px; color: #0F172A;" class="cell-final-score">' +
                        (naVal !== null ? Number(naVal).toFixed(1) : '<span style="color:#94A3B8; font-size:12px;">-</span>') +
                    '</td>' +
                    '<td style="font-weight: 800; font-size: 13px;" class="cell-predikat">' +
                        (predVal && predVal !== '-' ? '<span style="display:inline-block; padding:2px 8px; border-radius:4px; background:#F1F5F9; color:#0F172A;">' + predVal + '</span>' : '<span style="color:#94A3B8;">-</span>') +
                    '</td>' +
                    '<td class="cell-status-kktp">' +
                        (statVal === 'Tercapai' ? 
                            '<span style="background:#ECFDF5; color:#065F46; font-size:11px; font-weight:800; padding:3px 8px; border-radius:6px;">TERCAPAI</span>' :
                            (statVal === 'Belum Tercapai' ? 
                                '<span style="background:#FEE2E2; color:#991B1B; font-size:11px; font-weight:800; padding:3px 8px; border-radius:6px;">BELUM TERCAPAI</span>' :
                                '<span style="background:#F8FAFC; color:#64748B; font-size:10.5px; font-weight:700; padding:3px 8px; border-radius:6px; border:1px dashed #CBD5E1;">Belum Generate</span>'
                            )
                        ) +
                    '</td>' +
                '</tr>';
            });
        }

        tableHtml += 
                    '</tbody>' +
                '</table>' +
            '</div>';

        tableWrap.innerHTML = tableHtml;

        // BIND EVENT KLIK PADA SEL REMEDIAL YANG BISA DI-EVALUASI
        tableWrap.querySelectorAll('.is-remedial-clickable').forEach(function(cell) {
            cell.addEventListener('click', function() {
                openRemedialModal(cell);
            });
        });
    }

    /**
     * RENDER INDIVIDUAL CELL SESUAI LOGIKA KHUSUS GURU
     */
    function renderCellGuru(siswa, tipe, itemId, slotCode, judul, gradeRow, kktp) {
        var valAsli = null;
        var valRemedi = null;
        var valAkhirRemed = null;
        var statusRemed = null;

        if (gradeRow) {
            valAsli = (gradeRow.nilai_angka !== undefined) ? gradeRow.nilai_angka : gradeRow.nilai;
            valRemedi = gradeRow.nilai_remedial;
            valAkhirRemed = gradeRow.nilai_akhir_remedial;
            statusRemed = gradeRow.status_remedial || null;
        }

        var isGraded = (valAsli !== null && valAsli !== undefined && valAsli !== '');
        var numAsli = isGraded ? parseFloat(valAsli) : null;
        var hasRemediAttempt = (valRemedi !== null && valRemedi !== undefined && valRemedi !== '');

        // KASUS 1: Belum ada nilai sama sekali
        if (!isGraded) {
            return '<td class="grade-box-cell is-locked" title="Belum ada pengerjaan asesmen"><span style="color:#CBD5E1;">-</span></td>';
        }

        // KASUS 2: Nilai Asli >= KKTP (TIDAK DAPAT DIEDIT / TERKUNCI)
        if (numAsli >= kktp) {
            return '<td class="grade-box-cell is-locked" title="Nilai asli (' + numAsli + ') sudah mencapai KKTP (' + kktp + '). Terkunci read-only.">' +
                '<div class="grade-box-inner">' +
                    '<span class="score-effective is-passed">' + numAsli + '</span>' +
                '</div>' +
            '</td>';
        }

        // KASUS 3: Nilai Asli < KKTP tapi BELUM REMEDIAL
        if (!hasRemediAttempt) {
            return '<td class="grade-box-cell is-locked" title="Nilai asli (' + numAsli + ') < KKTP (' + kktp + '). Siswa belum mengikuti sesi remedial.">' +
                '<div class="grade-box-inner">' +
                    '<span class="score-effective is-failed">' + numAsli + '</span>' +
                    '<span class="badge-remed-tag badge-need-remed">Belum Remidi</span>' +
                '</div>' +
            '</td>';
        }

        // KASUS 4: Nilai Asli < KKTP dan SUDAH ADA NILAI REMEDIAL (BISA DIKLIK OLEH GURU)
        var cellContent = '';
        var numRemed = parseFloat(valRemedi);

        if (statusRemed === 'Disetujui' && valAkhirRemed !== null) {
            // Disetujui: Nilai Asli dicoret, Nilai Remedi tampil, dan nilai efektif diakui = KKTP
            cellContent = 
                '<span class="score-asli-strikethrough" title="Nilai Asli">' + numAsli + '</span>' +
                '<span class="score-effective is-remedial-approved" title="Nilai Diakui = KKTP">' + Number(valAkhirRemed).toFixed(0) + '</span>' +
                '<span class="badge-remed-tag badge-approved-remed">Disetujui</span>';
        } else if (statusRemed === 'Remedial Ulang') {
            cellContent = 
                '<span class="score-asli-strikethrough" title="Nilai Asli">' + numAsli + '</span>' +
                '<span style="font-size:11.5px; color:#64748B; text-decoration:line-through;" title="Nilai Remidi Sebelumnya">' + numRemed + '</span>' +
                '<span class="badge-remed-tag badge-repeat-remed">Remidi Ulang</span>';
        } else {
            // Pending / Menunggu Review Guru
            cellContent = 
                '<span class="score-asli-strikethrough" title="Nilai Asli">' + numAsli + '</span>' +
                '<span class="score-effective is-failed" title="Nilai Remedial Siswa">' + numRemed + '</span>' +
                '<span class="badge-remed-tag badge-review-remed">Review Guru</span>';
        }

        return '<td class="grade-box-cell is-remedial-clickable" ' +
            'data-siswa-id="' + siswa.id + '" ' +
            'data-siswa-nama="' + escapeHtml(siswa.nama_lengkap) + '" ' +
            'data-siswa-nis="' + escapeHtml(siswa.nis || '-') + '" ' +
            'data-tipe="' + tipe + '" ' +
            'data-item-id="' + itemId + '" ' +
            'data-slot="' + escapeHtml(slotCode) + '" ' +
            'data-judul="' + escapeHtml(judul) + '" ' +
            'data-asli="' + numAsli + '" ' +
            'data-remedi="' + numRemed + '" ' +
            'data-akhir-remed="' + (valAkhirRemed !== null ? valAkhirRemed : '') + '" ' +
            'data-status-remed="' + escapeHtml(statusRemed || '') + '" ' +
            'data-catatan="' + escapeHtml(gradeRow ? (gradeRow.catatan_remedial || '') : '') + '" ' +
            'data-kktp="' + kktp + '" ' +
            'title="Klik untuk mengevaluasi &amp; menyetujui remedial siswa">' +
                '<div class="grade-box-inner">' + cellContent + '</div>' +
            '</td>';
    }

    /* --------------------------------------------------------------------------
     * 5. MODAL EVALUASI & APPROVAL REMEDIAL
     * -------------------------------------------------------------------------- */
    function openRemedialModal(cell) {
        var d = cell.dataset;
        activeModalContext = {
            cell: cell,
            tipe: d.tipe,
            itemId: parseInt(d.itemId, 10),
            idSiswa: parseInt(d.siswaId, 10),
            kktp: parseFloat(d.kktp) || 75.00,
            valAsli: d.asli,
            valRemedi: d.remedi,
            valAkhirRemed: d.akhirRemed,
            statusRemed: d.statusRemed
        };

        document.getElementById('modalSiswaNama').textContent = d.siswaNama;
        document.getElementById('modalSiswaNis').textContent = 'NIS: ' + d.siswaNis;
        document.getElementById('modalAsesmenJudul').textContent = d.slot + ' — ' + d.judul;

        document.getElementById('modalValAsli').textContent = d.asli;
        document.getElementById('modalValRemedi').textContent = d.remedi;
        document.getElementById('modalValKktp').textContent = d.kktp;
        document.getElementById('modalCatatanGuru').value = d.catatan || '';

        var statusBox = document.getElementById('modalStatusBox');
        var statusText = document.getElementById('modalStatusText');

        if (d.statusRemed === 'Disetujui') {
            statusBox.style.background = '#ECFDF5';
            statusBox.style.borderColor = '#A7F3D0';
            statusBox.style.color = '#065F46';
            statusText.textContent = 'Status: Remedial telah disetujui sebelumnya. Nilai efektif diakui setara KKTP (' + d.kktp + ').';
        } else if (d.statusRemed === 'Remedial Ulang') {
            statusBox.style.background = '#F3F4F6';
            statusBox.style.borderColor = '#E5E7EB';
            statusBox.style.color = '#4B5563';
            statusText.textContent = 'Status: Siswa diwajibkan mengikuti remedial ulang.';
        } else {
            statusBox.style.background = '#FFFBEB';
            statusBox.style.borderColor = '#FDE68A';
            statusBox.style.color = '#92400E';
            statusText.textContent = 'Siswa telah menyelesaikan perbaikan nilai. Silakan klik "Setujui" agar nilai berubah menjadi setara batas KKTP (' + d.kktp + ') tanpa mengubah nilai asli.';
        }

        document.getElementById('modalRemedialApproval').style.display = 'flex';
    }

    function closeRemedialModal() {
        document.getElementById('modalRemedialApproval').style.display = 'none';
        activeModalContext = null;
    }

    document.getElementById('btnModalClose').addEventListener('click', closeRemedialModal);

    // ACTION: SETUJUI REMEDIAL (NILAI = KKTP)
    document.getElementById('btnActionApprove').addEventListener('click', function() {
        if (!activeModalContext) return;
        var ctx = activeModalContext;

        var btn = this;
        btn.setAttribute('disabled', 'disabled');
        btn.innerHTML = '<iconify-icon icon="solar:restart-bold" class="ilmuna-spin"></iconify-icon> Menyimpan...';

        var fd = new FormData();
        fd.append('action', 'approve_remedial');
        fd.append('tipe', ctx.tipe);
        fd.append('item_id', ctx.itemId);
        fd.append('id_siswa', ctx.idSiswa);
        fd.append('kktp', ctx.kktp);
        fd.append('catatan', document.getElementById('modalCatatanGuru').value.trim());

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                btn.removeAttribute('disabled');
                btn.innerHTML = '<iconify-icon icon="solar:check-circle-bold"></iconify-icon> Setujui (Nilai = KKTP)';

                if (!res || !res.success) {
                    Ilmuna.alert('error', 'Gagal', res ? res.message : 'Gagal menyetujui remedial.');
                    return;
                }

                closeRemedialModal();
                // Reload data gradebook segar
                fetchGradebook();
            })
            .catch(function(err) {
                console.error('Error approve remedial:', err);
                btn.removeAttribute('disabled');
                btn.innerHTML = '<iconify-icon icon="solar:check-circle-bold"></iconify-icon> Setujui (Nilai = KKTP)';
                Ilmuna.alert('error', 'Kesalahan', 'Terjadi kesalahan jaringan.');
            });
    });

    // ACTION: REMIDI ULANG
    document.getElementById('btnActionRemidiUlang').addEventListener('click', function() {
        if (!activeModalContext) return;
        var ctx = activeModalContext;

        var doRemidiUlang = function() {
            var btn = document.getElementById('btnActionRemidiUlang');
            btn.setAttribute('disabled', 'disabled');
            btn.innerHTML = '<iconify-icon icon="solar:restart-bold" class="ilmuna-spin"></iconify-icon> Menyimpan...';

            var fd = new FormData();
            fd.append('action', 'repeat_remedial');
            fd.append('tipe', ctx.tipe);
            fd.append('item_id', ctx.itemId);
            fd.append('id_siswa', ctx.idSiswa);
            fd.append('catatan', document.getElementById('modalCatatanGuru').value.trim());

            fetch(AJAX_URL, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    btn.removeAttribute('disabled');
                    btn.innerHTML = '<iconify-icon icon="solar:restart-bold"></iconify-icon> Remidi Ulang';

                    if (!res || !res.success) {
                        Ilmuna.alert('error', 'Gagal', res ? res.message : 'Gagal menyetel remedial ulang.');
                        return;
                    }

                    closeRemedialModal();
                    fetchGradebook();
                })
                .catch(function(err) {
                    console.error('Error repeat remedial:', err);
                    btn.removeAttribute('disabled');
                    btn.innerHTML = '<iconify-icon icon="solar:restart-bold"></iconify-icon> Remidi Ulang';
                    Ilmuna.alert('error', 'Kesalahan', 'Terjadi kesalahan jaringan.');
                });
        };

        Ilmuna.confirm('warning', 'Remedial Ulang', 'Apakah Anda yakin ingin mewajibkan siswa ini mengikuti remedial ulang?', 'Ya, Remidi Ulang', 'Batal').then(function(ok) {
            if (ok) doRemidiUlang();
        });
    });

    /* --------------------------------------------------------------------------
     * 6. ACTION GENERATE NILAI AKHIR RAPOR (SIMPAN KE rekap_nilai_siswa_mapel)
     * -------------------------------------------------------------------------- */
    document.getElementById('btnGenerateNilaiAkhir').addEventListener('click', function() {
        if (state.id_kelas <= 0 || state.id_kmp <= 0) {
            Ilmuna.alert('warning', 'Peringatan', 'Pilih kelas dan mata pelajaran terlebih dahulu.');
            return;
        }

        var doGenerate = function() {
            var btn = document.getElementById('btnGenerateNilaiAkhir');
            btn.setAttribute('disabled', 'disabled');
            btn.innerHTML = '<iconify-icon icon="solar:restart-bold" class="ilmuna-spin"></iconify-icon> Mengkalkulasi Nilai Rapor...';

            var fd = new FormData();
            fd.append('action', 'generate_nilai_akhir');
            fd.append('id_kelas', state.id_kelas);
            fd.append('id_kmp', state.id_kmp);

            fetch(AJAX_URL, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    btn.removeAttribute('disabled');
                    btn.innerHTML = '<iconify-icon icon="solar:magic-stick-3-bold" width="18"></iconify-icon> Generate Nilai Akhir Rapor';

                    if (!res || !res.success) {
                        Ilmuna.alert('error', 'Gagal', res ? res.message : 'Gagal men-generate nilai akhir.');
                        return;
                    }

                    Ilmuna.toast('success', 'Berhasil', res.message);
                    fetchGradebook();
                })
                .catch(function(err) {
                    console.error('Error generate nilai akhir:', err);
                    btn.removeAttribute('disabled');
                    btn.innerHTML = '<iconify-icon icon="solar:magic-stick-3-bold" width="18"></iconify-icon> Generate Nilai Akhir Rapor';
                    Ilmuna.alert('error', 'Kesalahan', 'Terjadi kesalahan jaringan.');
                });
        };

        Ilmuna.confirm('warning', 'Generate Nilai Akhir Rapor', 'Kalkulasi dan simpan Nilai Akhir Rapor untuk seluruh siswa rombel ini ke tabel resmi rekap nilai?', 'Ya, Generate Rapor', 'Batal').then(function(ok) {
            if (ok) doGenerate();
        });
    });

    /* --------------------------------------------------------------------------
     * 7. EVENT LISTENERS FILTER & SEARCH
     * -------------------------------------------------------------------------- */
    document.getElementById('filterKelas').addEventListener('change', function() {
        selectClass(this.value);
    });

    document.getElementById('filterMapel').addEventListener('change', function() {
        selectMapel(this.value);
    });

    document.getElementById('btnResetFilter').addEventListener('click', function() {
        document.getElementById('filterKelas').value = '0';
        document.getElementById('filterMapel').innerHTML = '<option value="0">— Pilih Kelas Terlebih Dahulu —</option>';
        document.getElementById('filterMapel').setAttribute('disabled', 'disabled');
        document.getElementById('searchSiswa').value = '';
        state.id_kelas = 0;
        state.id_kmp = 0;
        renderClassEmptyState();
    });

    document.getElementById('searchSiswa').addEventListener('input', function() {
        var query = this.value.toLowerCase().trim();
        var rows = document.querySelectorAll('#tbodyGradebook .row-siswa');
        rows.forEach(function(r) {
            var nama = r.dataset.nama || '';
            var nis = r.dataset.nis || '';
            if (query === '' || nama.indexOf(query) !== -1 || nis.indexOf(query) !== -1) {
                r.style.display = '';
            } else {
                r.style.display = 'none';
            }
        });
    });

    // Inisialisasi awal
    loadKelasOptions();
});
</script>

<?php
require_once BASE_PATH . '/includes/footer.php';
?>
