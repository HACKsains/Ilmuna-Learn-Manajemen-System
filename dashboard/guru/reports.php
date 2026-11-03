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
                    ['id' => 0, 'slot_code' => 'S1', 'judul_asesmen' => 'Sumatif Lingkup Materi 1', 'jenis_sumatif' => 'Sumatif Lingkup Materi', 'kktp' => $kktpAcuan, 'metode_penilaian' => 'PAP', 'nilai_patokan' => $kktpAcuan, 'rata_rata_kelas' => null],
                    ['id' => 0, 'slot_code' => 'STS', 'judul_asesmen' => 'Sumatif Tengah Semester (STS)', 'jenis_sumatif' => 'Sumatif Tengah Semester', 'kktp' => $kktpAcuan, 'metode_penilaian' => 'PAN', 'nilai_patokan' => $kktpAcuan, 'rata_rata_kelas' => null],
                    ['id' => 0, 'slot_code' => 'SAS', 'judul_asesmen' => 'Sumatif Akhir Semester (SAS)', 'jenis_sumatif' => 'Sumatif Akhir Semester', 'kktp' => $kktpAcuan, 'metode_penilaian' => 'PAN', 'nilai_patokan' => $kktpAcuan, 'rata_rata_kelas' => null]
                ];
            } else {
                foreach ($sumatifCols as $idx => &$sc) {
                    if (empty($sc['kktp']) || (float)$sc['kktp'] <= 0) $sc['kktp'] = $kktpAcuan;
                    if ($sc['jenis_sumatif'] === 'Sumatif Tengah Semester') $sc['slot_code'] = 'STS';
                    elseif ($sc['jenis_sumatif'] === 'Sumatif Akhir Semester') $sc['slot_code'] = 'SAS';
                    else $sc['slot_code'] = 'S' . ($idx + 1);

                    $isPan = isAssessmentPAN('sumatif', $sc['jenis_sumatif'] ?? '');
                    $sc['metode_penilaian'] = $isPan ? 'PAN' : 'PAP';
                    if ($isPan && !empty($sc['id'])) {
                        $stmtMean = $pdo->prepare("
                            SELECT ROUND(AVG(ns.nilai_angka), 1)
                            FROM nilai_asesmen_sumatif ns
                            JOIN siswa s ON ns.id_siswa = s.id
                            WHERE s.id_kelas = ? AND ns.id_asesmen_sumatif = ? AND ns.nilai_angka IS NOT NULL
                        ");
                        $stmtMean->execute([$idKelas, (int)$sc['id']]);
                        $meanVal = $stmtMean->fetchColumn();
                        $sc['rata_rata_kelas'] = ($meanVal !== false && $meanVal !== null && (float)$meanVal > 0) ? (float)$meanVal : (float)$sc['kktp'];
                        $sc['nilai_patokan'] = $sc['rata_rata_kelas'];
                    } else {
                        $sc['rata_rata_kelas'] = null;
                        $sc['nilai_patokan'] = (float)$sc['kktp'];
                    }
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

            // 4. OBSERVASI SIKAP (AFEKTIF)
            $gradesAfektif = [];
            try {
                $stmtAfk = $pdo->prepare("
                    SELECT id_siswa,
                           ROUND(AVG(nilai_angka), 1) AS rata_afektif,
                           COUNT(id) AS total_observasi,
                           GROUP_CONCAT(DISTINCT NULLIF(catatan_perilaku, '-') SEPARATOR ' | ') AS catatan_afektif
                    FROM nilai_asesmen_afektif
                    WHERE id_kelas_mata_pelajaran = ?
                    GROUP BY id_siswa
                ");
                $stmtAfk->execute([$idKmp]);
                while ($ra = $stmtAfk->fetch(PDO::FETCH_ASSOC)) {
                    $sId = (int)$ra['id_siswa'];
                    $rt = (float)$ra['rata_afektif'];
                    // SB (>=88), B (75-87.9), C (60-74.9), K (<60)
                    $pred = ($rt >= 88.0) ? 'SB' : (($rt >= 75.0) ? 'B' : (($rt >= 60.0) ? 'C' : ($rt > 0 ? 'K' : '-')));
                    $ra['predikat'] = $pred;
                    $gradesAfektif[$sId] = $ra;
                }
            } catch (Throwable $eAfk) {}

            // 5. UNJUK KERJA / PRAKTIK (PSIKOMOTORIK)
            $gradesPsikomotorik = [];
            try {
                $stmtPsk = $pdo->prepare("
                    SELECT id_siswa,
                           ROUND(AVG(COALESCE(nilai_akhir_remedial, nilai_angka)), 1) AS rata_psikomotorik,
                           COUNT(id) AS total_praktik,
                           GROUP_CONCAT(DISTINCT NULLIF(catatan_kinerja, '-') SEPARATOR ' | ') AS catatan_psikomotorik
                    FROM nilai_asesmen_psikomotorik
                    WHERE id_kelas_mata_pelajaran = ?
                    GROUP BY id_siswa
                ");
                $stmtPsk->execute([$idKmp]);
                while ($rp = $stmtPsk->fetch(PDO::FETCH_ASSOC)) {
                    $sId = (int)$rp['id_siswa'];
                    $rt = (float)$rp['rata_psikomotorik'];
                    // Predikat Praktik: A (>=90), B (80-89.9), C (70-79.9), D (60-69.9), E (<60)
                    $pred = ($rt >= 90.0) ? 'A' : (($rt >= 80.0) ? 'B' : (($rt >= 70.0) ? 'C' : ($rt >= 60.0 ? 'D' : ($rt > 0 ? 'E' : '-'))));
                    $rp['predikat'] = $pred;
                    $gradesPsikomotorik[$sId] = $rp;
                }
            } catch (Throwable $ePsk) {}

            // 6. AMBIL DATA NILAI AKHIR DARI TABEL rekap_nilai_siswa_mapel (JIKA SUDAH DIGENERATE GURU)
            $rekapRaporMap = [];
            try {
                $stmtRekap = $pdo->prepare("
                    SELECT id_siswa, nilai_akhir_semester, nilai_toleransi, predikat_akhir, status_kelulusan_kktp,
                           rata_sumatif_materi, nilai_sts, nilai_asas,
                           rata_afektif, predikat_afektif, deskripsi_afektif,
                           rata_psikomotorik, predikat_psikomotorik, deskripsi_psikomotorik,
                           updated_at
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
                    'kelas'               => $kelasInfo,
                    'mapel'               => $mapelInfo,
                    'kktp'                => $kktpAcuan,
                    'siswa_list'          => $siswaList ?: [],
                    'tugas_cols'          => $tugasCols,
                    'formatif_cols'       => $formatifCols,
                    'sumatif_cols'        => $sumatifCols,
                    'grades_tugas'        => $gradesTugas,
                    'grades_formatif'     => $gradesFormatif,
                    'grades_sumatif'      => $gradesSumatif,
                    'grades_afektif'      => $gradesAfektif,
                    'grades_psikomotorik' => $gradesPsikomotorik,
                    'rekap_rapor'         => $rekapRaporMap
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

        // 6. PRATINJAU KALKULASI NILAI AKHIR (TIDAK LANGSUNG SIMPAN KE DB)
        if ($action === 'preview_nilai_akhir') {
            $idKelas         = (int)($_POST['id_kelas'] ?? 0);
            $idKmp           = (int)($_POST['id_kmp'] ?? 0);
            $semester        = trim($_POST['semester'] ?? 'Ganjil');
            $tahunPelajaran  = trim($_POST['tahun_pelajaran'] ?? '2026/2027');

            $rawTol = $_POST['toleransi_siswa'] ?? null;
            $toleransiInput = null;
            if ($rawTol !== null && $rawTol !== '') {
                $decoded = json_decode($rawTol, true);
                if (is_array($decoded)) {
                    $toleransiInput = $decoded;
                }
            }
            if ($toleransiInput === null && isset($_POST['toleransi_persen'])) {
                $toleransiInput = (float)$_POST['toleransi_persen'];
            }

            $result = ilmuna_preview_rekap_nilai_akhir($pdo, $idKelas, $idKmp, $semester, $tahunPelajaran, $toleransiInput);
            echo json_encode($result);
            exit;
        }

        // 7. GENERATE & SIMPAN RESMI NILAI AKHIR RAPOR (SIMPAN KE rekap_nilai_siswa_mapel)
        if ($action === 'generate_nilai_akhir') {
            $idKelas         = (int)($_POST['id_kelas'] ?? 0);
            $idKmp           = (int)($_POST['id_kmp'] ?? 0);
            $semester        = trim($_POST['semester'] ?? 'Ganjil');
            $tahunPelajaran  = trim($_POST['tahun_pelajaran'] ?? '2026/2027');

            $rawTol = $_POST['toleransi_siswa'] ?? null;
            $toleransiInput = null;
            if ($rawTol !== null && $rawTol !== '') {
                $decoded = json_decode($rawTol, true);
                if (is_array($decoded)) {
                    $toleransiInput = $decoded;
                }
            }
            if ($toleransiInput === null && isset($_POST['toleransi_persen'])) {
                $toleransiInput = (float)$_POST['toleransi_persen'];
            }

            $result = ilmuna_generate_rekap_nilai_akhir($pdo, $idKelas, $idKmp, $semester, $tahunPelajaran, $toleransiInput, true);
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
.ilmuna-gradebook-table thead th.group-afektif {
    background: #F0FDF4 !important;
    color: #166534 !important;
    border-bottom: 2.5px solid #22C55E !important;
}
.ilmuna-gradebook-table thead th.group-psikomotorik {
    background: #FAF5FF !important;
    color: #6B21A8 !important;
    border-bottom: 2.5px solid #A855F7 !important;
}

/* BADGES AFEKTIF & PSIKOMOTORIK */
.badge-afk-sb { background: #DCFCE7; color: #166534; font-weight: 800; padding: 2px 7px; border-radius: 4px; font-size: 11px; display: inline-block; }
.badge-afk-b  { background: #DBEAFE; color: #1E40AF; font-weight: 800; padding: 2px 7px; border-radius: 4px; font-size: 11px; display: inline-block; }
.badge-afk-c  { background: #FEF3C7; color: #92400E; font-weight: 800; padding: 2px 7px; border-radius: 4px; font-size: 11px; display: inline-block; }
.badge-afk-k  { background: #FEE2E2; color: #991B1B; font-weight: 800; padding: 2px 7px; border-radius: 4px; font-size: 11px; display: inline-block; }

.badge-psk-a { background: #EDE9FE; color: #5B21B6; font-weight: 800; padding: 2px 7px; border-radius: 4px; font-size: 11px; display: inline-block; }
.badge-psk-b { background: #E0E7FF; color: #3730A3; font-weight: 800; padding: 2px 7px; border-radius: 4px; font-size: 11px; display: inline-block; }
.badge-psk-c { background: #FEF3C7; color: #92400E; font-weight: 800; padding: 2px 7px; border-radius: 4px; font-size: 11px; display: inline-block; }
.badge-psk-d { background: #FFEDD5; color: #9A3412; font-weight: 800; padding: 2px 7px; border-radius: 4px; font-size: 11px; display: inline-block; }
.badge-psk-e { background: #FEE2E2; color: #991B1B; font-weight: 800; padding: 2px 7px; border-radius: 4px; font-size: 11px; display: inline-block; }

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
.ilmuna-modal-box--wide {
    max-width: 1060px !important;
    max-height: 92vh;
    display: flex;
    flex-direction: column;
}
.ilmuna-modal-box--wide .ilmuna-modal-body {
    overflow-y: auto;
    padding: 20px 24px;
    max-height: calc(92vh - 145px);
}
.ilmuna-preview-table-wrap {
    overflow-x: auto;
    max-height: 420px;
    border: 1px solid #E2E8F0;
    border-radius: 12px;
}
.ilmuna-preview-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 12px;
    text-align: center;
    background: #FFFFFF;
}
.ilmuna-preview-table th, .ilmuna-preview-table td {
    padding: 8px 6px;
    border-bottom: 1px solid #E2E8F0;
    border-right: 1px solid #E2E8F0;
    vertical-align: middle;
}
.ilmuna-preview-table thead tr:first-child th {
    background: #0F172A;
    color: #FFFFFF;
    font-size: 11px;
    font-weight: 800;
    position: sticky;
    top: 0;
    z-index: 6;
}
.ilmuna-preview-table thead tr:nth-child(2) th {
    background: #1E293B;
    color: #94A3B8;
    font-size: 10px;
    font-weight: 700;
    position: sticky;
    top: 32px;
    z-index: 5;
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
        <div style="margin-left: auto; display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <span><strong style="color:#166534;"><iconify-icon icon="solar:heart-bold" style="vertical-align:-1px;"></iconify-icon> Sikap (Afektif):</strong> SB (&ge;88), B (75-87), C (60-74), K (&lt;60)</span>
            <span><strong style="color:#6B21A8;"><iconify-icon icon="solar:star-bold" style="vertical-align:-1px;"></iconify-icon> Praktik (Psikomotorik):</strong> A (&ge;90), B (80-89), C (70-79), D (60-69)</span>
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

<!-- MODAL POPUP PRATINJAU & KONFIRMASI GENERATE NILAI AKHIR RAPOR -->
<div class="ilmuna-modal-overlay" id="modalGeneratePreview">
    <div class="ilmuna-modal-box ilmuna-modal-box--wide">
        <div class="ilmuna-modal-header" style="background: linear-gradient(135deg, #0F172A 0%, #1E3A8A 50%, #0D7A87 100%);">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px;">
                <div>
                    <h3 style="margin: 0; font-size: 17px; font-weight: 800; display: flex; align-items: center; gap: 8px;">
                        <iconify-icon icon="solar:magic-stick-3-bold" width="22" style="color: #F59E0B;"></iconify-icon>
                        Pratinjau &amp; Konfirmasi Nilai Akhir Rapor
                    </h3>
                    <p style="margin: 4px 0 0; font-size: 12px; color: rgba(255,255,255,0.85);" id="modalPreviewSubHeader">
                        Formula Resmi: 50% Kognitif &bull; 40% Afektif &bull; 10% Psikomotorik
                    </p>
                </div>
                <button type="button" id="btnPreviewCloseX" style="background:none; border:none; color:#FFFFFF; font-size:22px; cursor:pointer; opacity:0.8; padding:0; line-height:1;">
                    <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
                </button>
            </div>
        </div>

        <div class="ilmuna-modal-body">
            <!-- 1. KARTU PROPORSI BOBOT PENILAIAN -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px; margin-bottom: 16px;">
                <div style="background: #EFF6FF; border: 1.5px solid #BFDBFE; border-radius: 12px; padding: 12px 14px;">
                    <div style="font-size: 11px; font-weight: 800; color: #1D4ED8; text-transform: uppercase; letter-spacing: 0.3px;">
                        Kognitif &bull; 50%
                    </div>
                    <div style="font-size: 18px; font-weight: 800; color: #1E3A8A; margin: 4px 0;">
                        Tugas, Formatif &amp; Sumatif
                    </div>
                    <div style="font-size: 11px; color: #64748B;">
                        Gabungan tes materi, LM, STS &amp; SAS
                    </div>
                </div>

                <div style="background: #F0FDF4; border: 1.5px solid #BBF7D0; border-radius: 12px; padding: 12px 14px;">
                    <div style="font-size: 11px; font-weight: 800; color: #166534; text-transform: uppercase; letter-spacing: 0.3px;">
                        Afektif &bull; 40%
                    </div>
                    <div style="font-size: 18px; font-weight: 800; color: #14532D; margin: 4px 0;">
                        Observasi Sikap Profil
                    </div>
                    <div style="font-size: 11px; color: #64748B;">
                        Karakter, Disiplin &amp; Integritas Siswa
                    </div>
                </div>

                <div style="background: #FAF5FF; border: 1.5px solid #E9D5FF; border-radius: 12px; padding: 12px 14px;">
                    <div style="font-size: 11px; font-weight: 800; color: #6B21A8; text-transform: uppercase; letter-spacing: 0.3px;">
                        Psikomotorik &bull; 10%
                    </div>
                    <div style="font-size: 18px; font-weight: 800; color: #581C87; margin: 4px 0;">
                        Kinerja / Praktik Siswa
                    </div>
                    <div style="font-size: 11px; color: #64748B;">
                        Unjuk kerja &amp; keterampilan psikomotorik
                    </div>
                </div>

                <div style="background: #FFFBEB; border: 1.5px solid #FDE68A; border-radius: 12px; padding: 12px 14px;">
                    <div style="font-size: 11px; font-weight: 800; color: #B45309; text-transform: uppercase; letter-spacing: 0.3px;">
                        Batas KKTP Mapel
                    </div>
                    <div style="font-size: 20px; font-weight: 800; color: #92400E; margin: 2px 0;" id="modalPreviewKktp">
                        75.00
                    </div>
                    <div style="font-size: 11px; color: #78350F;">
                        Sempurna = 100 (50% + 40% + 10%)
                    </div>
                </div>
            </div>

            <!-- 2. KONTROL KEBIJAKAN TOLERANSI GURU -->
            <div style="background: #F8FAFC; border: 1.5px dashed #CBD5E1; border-radius: 14px; padding: 14px 18px; margin-bottom: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                    <div>
                        <div style="font-size: 13px; font-weight: 800; color: #0F172A; display: flex; align-items: center; gap: 6px;">
                            <iconify-icon icon="solar:tuning-bold" style="color: #0D7A87; font-size: 16px;"></iconify-icon>
                            Kebijakan Toleransi Guru (Per Siswa yang Belum Tuntas)
                        </div>
                        <div style="font-size: 12px; color: #64748B; margin-top: 2px;">
                            Isi toleransi pada siswa yang belum tuntas / nilai kosong. <strong style="color: #DC2626;">Batas maksimal nilai akhir setelah toleransi = Batas KKTP (<span id="modalPreviewKktpCap">75</span>)</strong>.
                        </div>
                    </div>

                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                        <button type="button" id="btnBantuSemuaPasKKTP" class="btn btn-warning btn-sm" style="font-weight: 700; height: 36px; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px; background: #FEF3C7; color: #92400E; border: 1px solid #FDE68A;">
                            <iconify-icon icon="solar:check-square-bold"></iconify-icon> Bantu Semua Siswa Belum Tuntas Pas KKTP
                        </button>
                        <button type="button" id="btnResetSemuaToleransi" class="btn btn-secondary btn-sm" style="font-weight: 600; height: 36px; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px;">
                            <iconify-icon icon="solar:restart-bold"></iconify-icon> Reset Toleransi ke 0
                        </button>
                    </div>
                </div>

                <!-- Info Ketentuan -->
                <div style="margin-top: 10px; padding: 8px 12px; background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 11.5px; color: #475569; display: flex; gap: 8px; align-items: center;">
                    <iconify-icon icon="solar:shield-check-bold" style="color: #10B981; font-size: 16px; flex-shrink: 0;"></iconify-icon>
                    <span>
                        <strong>Integritas Data Terjaga:</strong> Siswa yang sudah tuntas murni terkunci tidak memerlukan toleransi. Pada siswa yang belum tuntas, input toleransi dibatasi ketat sehingga nilai akhir <strong>tidak bisa lebih dari nilai KKTP</strong> dan terekam resmi di database.
                    </span>
                </div>
            </div>

            <!-- 3. TABEL PRATINJAU SELURUH SISWA -->
            <div class="ilmuna-preview-table-wrap">
                <table class="ilmuna-preview-table" id="tablePreviewSiswa">
                    <thead>
                        <tr>
                            <th rowspan="2" style="width: 38px;">#</th>
                            <th rowspan="2" style="width: 80px;">NIS</th>
                            <th rowspan="2" style="text-align: left; min-width: 180px;">Nama Siswa</th>
                            <th colspan="2" style="background: #1E3A8A;">Kognitif (50%)</th>
                            <th colspan="2" style="background: #14532D;">Afektif (40%)</th>
                            <th colspan="2" style="background: #581C87;">Praktik (10%)</th>
                            <th rowspan="2" style="width: 70px; background: #334155;">NA Murni</th>
                            <th rowspan="2" style="min-width: 140px; background: #D97706; color: #FFFFFF;" title="Toleransi guru per siswa belum tuntas. Maksimal nilai akhir = KKTP">Toleransi Guru (+ Poin)</th>
                            <th rowspan="2" style="width: 75px; background: #0F172A; color: #FCD34D;">NA Final</th>
                            <th rowspan="2" style="width: 55px; background: #0F172A;">Pred</th>
                            <th rowspan="2" style="width: 95px; background: #0F172A;">Status</th>
                            <th rowspan="2" style="min-width: 140px; background: #1E293B;">Keterangan Kebijakan</th>
                        </tr>
                        <tr>
                            <th style="width: 60px; background: #2563EB;">Rata</th>
                            <th style="width: 60px; background: #1D4ED8;">&times;50%</th>
                            <th style="width: 60px; background: #16A34A;">Rata</th>
                            <th style="width: 60px; background: #15803D;">&times;40%</th>
                            <th style="width: 60px; background: #9333EA;">Rata</th>
                            <th style="width: 60px; background: #7E22CE;">&times;10%</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyPreviewSiswa">
                        <tr>
                            <td colspan="15" style="padding: 40px; color: #64748B;">Memuat pratinjau kalkulasi nilai akhir...</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- 4. STATISTIK RINGKASAN HASIL KALKULASI -->
            <div style="display: flex; gap: 14px; align-items: center; flex-wrap: wrap; margin-top: 14px; font-size: 12px; color: #475569;">
                <div>Total Siswa: <strong id="statPreviewTotal" style="color: #0F172A;">0</strong></div>
                <div>&bull;</div>
                <div>Tuntas Murni: <strong id="statPreviewTuntasMurni" style="color: #166534;">0</strong></div>
                <div>&bull;</div>
                <div>Terbantu Toleransi (Cap KKTP): <strong id="statPreviewTerbantu" style="color: #B45309;">0</strong></div>
                <div>&bull;</div>
                <div>Belum Tuntas: <strong id="statPreviewBelumTuntas" style="color: #DC2626;">0</strong></div>
            </div>
        </div>

        <div class="ilmuna-modal-footer" style="display: flex; justify-content: space-between; align-items: center;">
            <div style="font-size: 11.5px; color: #64748B;">
                <em>Periksa seluruh hasil kalkulasi sebelum menyimpan resmi ke database.</em>
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="button" class="btn btn-secondary btn-sm" id="btnPreviewCancel" style="border-radius: 8px; font-weight: 600;">
                    Tutup / Batal
                </button>
                <button type="button" class="btn btn-gold btn-sm" id="btnConfirmSaveGradebook" style="border-radius: 8px; font-weight: 800; padding: 6px 18px; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 4px 12px rgba(245,158,11,.3);">
                    <iconify-icon icon="solar:diskette-bold" width="16"></iconify-icon>
                    Terapkan &amp; Simpan Resmi ke Database
                </button>
            </div>
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
        var kelas          = data.kelas || {};
        var mapel          = data.mapel || {};
        var kktpAcuan      = parseFloat(data.kktp) || 75.00;
        var siswaList      = data.siswa_list || [];
        var tugasCols      = data.tugas_cols || [];
        var formatifCols   = data.formatif_cols || [];
        var sumatifCols    = data.sumatif_cols || [];
        var gradesTugas    = data.grades_tugas || {};
        var gradesFormatif = data.grades_formatif || {};
        var gradesSumatif  = data.grades_sumatif || {};
        var gradesAfektif  = data.grades_afektif || {};
        var gradesPsikomotorik = data.grades_psikomotorik || {};
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
                            '<th colspan="' + formatifCols.length + '" class="group-formatif">Asesmen Formatif (PAP &bull; KKTP)</th>' +
                            '<th colspan="' + sumatifCols.length + '" class="group-sumatif">Asesmen Sumatif (LM: PAP &bull; STS/SAS: PAN)</th>' +
                            '<th colspan="2" class="group-afektif">Observasi Sikap (Afektif)</th>' +
                            '<th colspan="2" class="group-psikomotorik">Unjuk Kerja (Psikomotorik)</th>' +
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
            tableHtml += '<th title="' + escapeHtml(fc.judul_asesmen) + ' (PAP - KKTP: ' + kktpAcuan + ')" style="cursor:help; width:72px;">' + escapeHtml(fc.slot_code) + '</th>';
        });
        sumatifCols.forEach(function(sc) {
            var isPan = (sc.metode_penilaian === 'PAN');
            var patokan = sc.nilai_patokan !== undefined && sc.nilai_patokan !== null ? parseFloat(sc.nilai_patokan) : (sc.kktp ? parseFloat(sc.kktp) : kktpAcuan);
            var titleText = escapeHtml(sc.judul_asesmen) + (isPan ? ' [PAN - Norma Rata-rata Kelas: ' + patokan.toFixed(1) + ']' : ' [PAP - KKTP: ' + patokan.toFixed(0) + ']');
            var slotLabel = escapeHtml(sc.slot_code) + (isPan ? ' <span style="font-size:9.5px; opacity:0.85;">(PAN)</span>' : '');
            tableHtml += '<th title="' + titleText + '" style="cursor:help; width:75px;">' + slotLabel + '</th>';
        });

        // Subheaders Afektif & Psikomotorik
        tableHtml += '<th title="Rata-rata Observasi Sikap Profil Pelajar (Skala 100)" style="cursor:help; width:65px; background:#F0FDF4; color:#166534;">Rata</th>';
        tableHtml += '<th title="Predikat Sikap Profil Pelajar: SB (Sangat Baik), B (Baik), C (Cukup), K (Kurang)" style="cursor:help; width:55px; background:#F0FDF4; color:#166534;">Pred</th>';
        tableHtml += '<th title="Rata-rata Penilaian Kinerja / Unjuk Kerja Praktik (Skala 100)" style="cursor:help; width:65px; background:#FAF5FF; color:#6B21A8;">Rata</th>';
        tableHtml += '<th title="Predikat Kinerja Praktik Siswa (A, B, C, D, E)" style="cursor:help; width:55px; background:#FAF5FF; color:#6B21A8;">Pred</th>';

        tableHtml += 
                        '</tr>' +
                    '</thead>' +
                    '<tbody id="tbodyGradebook">';

        if (siswaList.length === 0) {
            var totalColspan = 7 + tugasCols.length + formatifCols.length + sumatifCols.length + 4;
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
                    var isPan = (sc.metode_penilaian === 'PAN');
                    var patokanThis = sc.nilai_patokan !== undefined && sc.nilai_patokan !== null ? parseFloat(sc.nilai_patokan) : (sc.kktp ? parseFloat(sc.kktp) : kktpAcuan);
                    var gSm = gradesSumatif[sId] ? gradesSumatif[sId][smId] : null;
                    tableHtml += renderCellGuru(s, 'sumatif', smId, sc.slot_code, sc.judul_asesmen, gSm, patokanThis, isPan ? 'PAN' : 'PAP');
                });

                // 4. Rekap Data Rapor
                var rk = rekapRaporMap[sId] || null;

                // 4a. Observasi Sikap (Afektif)
                var afk = gradesAfektif[sId] || (rk && (rk.rata_afektif !== null && rk.rata_afektif !== undefined) ? {
                    rata_afektif: rk.rata_afektif,
                    predikat: rk.predikat_afektif,
                    catatan_afektif: rk.deskripsi_afektif
                } : null);
                var afkRata = afk && afk.rata_afektif !== null && afk.rata_afektif !== undefined ? parseFloat(afk.rata_afektif) : null;
                var afkPred = afk && afk.predikat ? afk.predikat : (afkRata !== null ? (afkRata >= 88 ? 'SB' : (afkRata >= 75 ? 'B' : (afkRata >= 60 ? 'C' : 'K'))) : '-');
                var afkNote = (afk && afk.catatan_afektif) ? afk.catatan_afektif : 'Observasi Sikap Profil Pelajar';
                var afkBadgeCls = afkPred === 'SB' ? 'badge-afk-sb' : (afkPred === 'B' ? 'badge-afk-b' : (afkPred === 'C' ? 'badge-afk-c' : (afkPred === 'K' ? 'badge-afk-k' : '')));

                tableHtml += 
                    '<td class="cell-afektif-rata" title="' + escapeHtml(afkNote) + '" style="font-weight:700; color:#166534; background:#F0FDF4; cursor:help;">' +
                        (afkRata !== null ? afkRata.toFixed(1) : '<span style="color:#CBD5E1;">-</span>') +
                    '</td>' +
                    '<td class="cell-afektif-pred" title="' + escapeHtml(afkNote) + '" style="cursor:help;">' +
                        (afkPred && afkPred !== '-' ? '<span class="' + afkBadgeCls + '">' + escapeHtml(afkPred) + '</span>' : '<span style="color:#CBD5E1;">-</span>') +
                    '</td>';

                // 4b. Unjuk Kerja (Psikomotorik)
                var psk = gradesPsikomotorik[sId] || (rk && (rk.rata_psikomotorik !== null && rk.rata_psikomotorik !== undefined) ? {
                    rata_psikomotorik: rk.rata_psikomotorik,
                    predikat: rk.predikat_psikomotorik,
                    catatan_psikomotorik: rk.deskripsi_psikomotorik
                } : null);
                var pskRata = psk && psk.rata_psikomotorik !== null && psk.rata_psikomotorik !== undefined ? parseFloat(psk.rata_psikomotorik) : null;
                var pskPred = psk && psk.predikat ? psk.predikat : (pskRata !== null ? (pskRata >= 90 ? 'A' : (pskRata >= 80 ? 'B' : (pskRata >= 70 ? 'C' : (pskRata >= 60 ? 'D' : 'E')))) : '-');
                var pskNote = (psk && psk.catatan_psikomotorik) ? psk.catatan_psikomotorik : 'Penilaian Unjuk Kerja / Praktik Siswa';
                var pskBadgeCls = pskPred === 'A' ? 'badge-psk-a' : (pskPred === 'B' ? 'badge-psk-b' : (pskPred === 'C' ? 'badge-psk-c' : (pskPred === 'D' ? 'badge-psk-d' : (pskPred === 'E' ? 'badge-psk-e' : ''))));

                tableHtml += 
                    '<td class="cell-psikomotorik-rata" title="' + escapeHtml(pskNote) + '" style="font-weight:700; color:#5B21B6; background:#FAF5FF; cursor:help;">' +
                        (pskRata !== null ? pskRata.toFixed(1) : '<span style="color:#CBD5E1;">-</span>') +
                    '</td>' +
                    '<td class="cell-psikomotorik-pred" title="' + escapeHtml(pskNote) + '" style="cursor:help;">' +
                        (pskPred && pskPred !== '-' ? '<span class="' + pskBadgeCls + '">' + escapeHtml(pskPred) + '</span>' : '<span style="color:#CBD5E1;">-</span>') +
                    '</td>';

                // 5. Rekap Nilai Akhir (dari rekap_nilai_siswa_mapel jika sudah digenerate)
                var naVal = rk ? rk.nilai_akhir_semester : null;
                var predVal = rk ? rk.predikat_akhir : '-';
                var statVal = rk ? rk.status_kelulusan_kktp : null;
                var tolVal = (rk && rk.nilai_toleransi !== null && rk.nilai_toleransi !== undefined) ? parseFloat(rk.nilai_toleransi) : 0;
                var tolTagHtml = (tolVal > 0)
                    ? '<div style="font-size:10px; color:#D97706; font-weight:700;" title="Tercatat toleransi guru: +' + tolVal.toFixed(1) + ' poin"><iconify-icon icon="solar:shield-check-bold" style="vertical-align:-1px;"></iconify-icon> Tol: +' + tolVal.toFixed(1) + '</div>'
                    : '';

                tableHtml += 
                    '<td style="font-weight: 800; font-size: 14px; color: #0F172A;" class="cell-final-score">' +
                        (naVal !== null ? Number(naVal).toFixed(1) + tolTagHtml : '<span style="color:#94A3B8; font-size:12px;">-</span>') +
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
    function renderCellGuru(siswa, tipe, itemId, slotCode, judul, gradeRow, kktp, metode) {
        metode = metode || 'PAP';
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

        // KASUS 2: Nilai Asli >= Batas Kelulusan (TIDAK DAPAT DIEDIT / TERKUNCI)
        if (numAsli >= kktp) {
            var infoTitle = (metode === 'PAN') 
                ? 'Nilai asli (' + numAsli + ') sudah mencapai/melebihi batas Norma Kelas PAN (' + Number(kktp).toFixed(1) + '). Terkunci read-only.'
                : 'Nilai asli (' + numAsli + ') sudah mencapai KKTP (' + kktp + '). Terkunci read-only.';
            return '<td class="grade-box-cell is-locked" title="' + infoTitle + '">' +
                '<div class="grade-box-inner">' +
                    '<span class="score-effective is-passed">' + numAsli + '</span>' +
                '</div>' +
            '</td>';
        }

        // KASUS 3: Nilai Asli < Batas tapi BELUM REMEDIAL
        if (!hasRemediAttempt) {
            var infoTitle = (metode === 'PAN') 
                ? 'Nilai asli (' + numAsli + ') < Norma Kelas PAN (' + Number(kktp).toFixed(1) + '). Siswa berhak mengikuti sesi remedial.'
                : 'Nilai asli (' + numAsli + ') < KKTP (' + kktp + '). Siswa belum mengikuti sesi remedial.';
            return '<td class="grade-box-cell is-locked" title="' + infoTitle + '">' +
                '<div class="grade-box-inner">' +
                    '<span class="score-effective is-failed">' + numAsli + '</span>' +
                    '<span class="badge-remed-tag badge-need-remed">Belum Remidi</span>' +
                '</div>' +
            '</td>';
        }

        // KASUS 4: Nilai Asli < Batas dan SUDAH ADA NILAI REMEDIAL (BISA DIKLIK OLEH GURU)
        var cellContent = '';
        var numRemed = parseFloat(valRemedi);

        if (statusRemed === 'Disetujui' && valAkhirRemed !== null) {
            // Disetujui: Nilai Asli dicoret, Nilai Remedi tampil, dan nilai efektif diakui = Batas
            cellContent = 
                '<span class="score-asli-strikethrough" title="Nilai Asli">' + numAsli + '</span>' +
                '<span class="score-effective is-remedial-approved" title="' + (metode === 'PAN' ? 'Nilai Diakui = Norma Kelas' : 'Nilai Diakui = KKTP') + '">' + Number(valAkhirRemed).toFixed(0) + '</span>' +
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
            'data-metode="' + metode + '" ' +
            'title="Klik untuk mengevaluasi &amp; menyetujui remedial siswa">' +
                '<div class="grade-box-inner">' + cellContent + '</div>' +
            '</td>';
    }

    /* --------------------------------------------------------------------------
     * 5. MODAL EVALUASI & APPROVAL REMEDIAL
     * -------------------------------------------------------------------------- */
    function openRemedialModal(cell) {
        var d = cell.dataset;
        var isPan = (d.metode === 'PAN');
        activeModalContext = {
            cell: cell,
            tipe: d.tipe,
            itemId: parseInt(d.itemId, 10),
            idSiswa: parseInt(d.siswaId, 10),
            kktp: parseFloat(d.kktp) || 75.00,
            metode: d.metode || 'PAP',
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
        document.getElementById('modalValKktp').textContent = isPan ? d.kktp + ' (Norma Kelas)' : d.kktp;
        document.getElementById('modalCatatanGuru').value = d.catatan || '';

        var statusBox = document.getElementById('modalStatusBox');
        var statusText = document.getElementById('modalStatusText');

        if (d.statusRemed === 'Disetujui') {
            statusBox.style.background = '#ECFDF5';
            statusBox.style.borderColor = '#A7F3D0';
            statusBox.style.color = '#065F46';
            statusText.textContent = isPan 
                ? 'Status: Remedial telah disetujui sebelumnya. Nilai efektif diakui setara batas Norma Kelas (' + d.kktp + ').'
                : 'Status: Remedial telah disetujui sebelumnya. Nilai efektif diakui setara KKTP (' + d.kktp + ').';
        } else if (d.statusRemed === 'Remedial Ulang') {
            statusBox.style.background = '#F3F4F6';
            statusBox.style.borderColor = '#E5E7EB';
            statusBox.style.color = '#4B5563';
            statusText.textContent = 'Status: Siswa diwajibkan mengikuti remedial ulang.';
        } else {
            statusBox.style.background = '#FFFBEB';
            statusBox.style.borderColor = '#FDE68A';
            statusBox.style.color = '#92400E';
            statusText.textContent = isPan 
                ? 'Siswa telah menyelesaikan perbaikan nilai. Silakan klik "Setujui" agar nilai berubah menjadi setara batas Norma Kelas (' + d.kktp + ') tanpa mengubah nilai asli.'
                : 'Siswa telah menyelesaikan perbaikan nilai. Silakan klik "Setujui" agar nilai berubah menjadi setara batas KKTP (' + d.kktp + ') tanpa mengubah nilai asli.';
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
                btn.innerHTML = '<iconify-icon icon="solar:check-circle-bold"></iconify-icon> Setujui Remedial';

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
                btn.innerHTML = '<iconify-icon icon="solar:check-circle-bold"></iconify-icon> Setujui Remedial';
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
     * 6. ALUR PRATINJAU & SIMPAN NILAI AKHIR RAPOR (TOLERANSI PER SISWA)
     * -------------------------------------------------------------------------- */
    function openGeneratePreviewModal() {
        var modal = document.getElementById('modalGeneratePreview');
        modal.style.display = 'flex';

        var kktpAcuan = currentGradebookData && currentGradebookData.kktp ? parseFloat(currentGradebookData.kktp) : 75.00;
        document.getElementById('modalPreviewKktp').textContent = kktpAcuan.toFixed(2);
        document.getElementById('modalPreviewKktpCap').textContent = kktpAcuan.toFixed(0);

        loadGeneratePreview(null);
    }

    function closeGeneratePreviewModal() {
        document.getElementById('modalGeneratePreview').style.display = 'none';
    }

    document.getElementById('btnPreviewCloseX').addEventListener('click', closeGeneratePreviewModal);
    document.getElementById('btnPreviewCancel').addEventListener('click', closeGeneratePreviewModal);

    function loadGeneratePreview(toleransiPerSiswa) {
        var tbody = document.getElementById('tbodyPreviewSiswa');
        tbody.innerHTML = '<tr><td colspan="15" style="padding:32px; color:#64748B;"><iconify-icon icon="solar:restart-bold" class="ilmuna-spin" style="font-size:20px; vertical-align:-3px;"></iconify-icon> Menghitung kalkulasi pratinjau nilai akhir...</td></tr>';

        var fd = new FormData();
        fd.append('action', 'preview_nilai_akhir');
        fd.append('id_kelas', state.id_kelas);
        fd.append('id_kmp', state.id_kmp);

        if (toleransiPerSiswa && typeof toleransiPerSiswa === 'object') {
            fd.append('toleransi_siswa', JSON.stringify(toleransiPerSiswa));
        }

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (!res || !res.success || !res.data) {
                    tbody.innerHTML = '<tr><td colspan="15" style="padding:24px; color:#DC2626;">' + escapeHtml(res ? res.message : 'Gagal memuat pratinjau nilai akhir.') + '</td></tr>';
                    return;
                }

                var d = res.data;
                var list = d.rekap_siswa || [];
                var kktpVal = parseFloat(d.kktp) || 75.00;

                if (list.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="15" style="padding:32px; color:#64748B;">Tidak ada siswa pada rombel ini.</td></tr>';
                    return;
                }

                var html = '';
                var no = 1;
                list.forEach(function(item) {
                    var sId = item.id_siswa;
                    var naMurni = parseFloat(item.nilai_akhir_murni) || 0;
                    var maxTol = parseFloat(item.max_toleransi) || 0;
                    var curTol = parseFloat(item.nilai_toleransi) || 0;
                    var naFinal = parseFloat(item.nilai_akhir) || 0;
                    var isTuntasMurni = (item.is_tuntas_murni === true);

                    var badgeStatus = (naFinal >= kktpVal)
                        ? '<span class="cell-status-badge" style="background:#ECFDF5; color:#065F46; font-size:10.5px; font-weight:800; padding:2px 7px; border-radius:4px;">TERCAPAI</span>'
                        : '<span class="cell-status-badge" style="background:#FEE2E2; color:#991B1B; font-size:10.5px; font-weight:800; padding:2px 7px; border-radius:4px;">BELUM</span>';

                    var badgePolicy = '';
                    if (isTuntasMurni) {
                        badgePolicy = '<span class="cell-policy-badge" style="background:#DCFCE7; color:#166534; font-size:10.5px; font-weight:700; padding:2px 8px; border-radius:4px;">Tuntas Murni</span>';
                    } else if (curTol > 0 && naFinal >= kktpVal) {
                        badgePolicy = '<span class="cell-policy-badge" style="background:#FEF3C7; color:#92400E; font-size:10.5px; font-weight:700; padding:2px 8px; border-radius:4px; border:1px solid #FDE68A;">' +
                            '<iconify-icon icon="solar:shield-check-bold" style="vertical-align:-1px;"></iconify-icon> Cap KKTP (' + kktpVal.toFixed(0) + ')</span>';
                    } else if (curTol > 0) {
                        badgePolicy = '<span class="cell-policy-badge" style="background:#FFF7ED; color:#C2410C; font-size:10.5px; font-weight:700; padding:2px 8px; border-radius:4px;">Terbantu Sebagian</span>';
                    } else {
                        badgePolicy = '<span class="cell-policy-badge" style="background:#F1F5F9; color:#64748B; font-size:10.5px; font-weight:600; padding:2px 8px; border-radius:4px;">Belum Tercapai</span>';
                    }

                    var naColor = (naFinal >= kktpVal) ? 'color:#065F46;' : 'color:#DC2626;';

                    var tolControlHtml = '';
                    if (isTuntasMurni) {
                        tolControlHtml = '<span style="color:#166534; font-size:11px; font-weight:700;"><iconify-icon icon="solar:check-circle-bold" style="vertical-align:-1px;"></iconify-icon> Tuntas (0)</span>';
                    } else {
                        tolControlHtml = 
                            '<div style="display:flex; align-items:center; justify-content:center; gap:4px;">' +
                                '<input type="number" class="form-control input-tol-siswa" ' +
                                    'data-siswa-id="' + sId + '" ' +
                                    'data-na-murni="' + naMurni + '" ' +
                                    'data-max-tol="' + maxTol + '" ' +
                                    'min="0" ' +
                                    'max="' + maxTol + '" ' +
                                    'step="0.5" ' +
                                    'value="' + curTol + '" ' +
                                    'style="width:62px; height:28px; text-align:center; font-weight:800; font-size:12px; border:1.5px solid #F59E0B; border-radius:6px; padding:2px;" ' +
                                    'title="Maksimal toleransi: +' + maxTol + ' (Nilai akhir tidak bisa melebihi KKTP ' + kktpVal + ')">' +
                                '<button type="button" class="btn-cap-kktp" ' +
                                    'data-siswa-id="' + sId + '" ' +
                                    'data-max-tol="' + maxTol + '" ' +
                                    'title="Set otomatis pas di KKTP (' + kktpVal + ')" ' +
                                    'style="padding:2px 6px; font-size:10px; font-weight:800; border-radius:5px; background:#FEF3C7; color:#92400E; border:1px solid #FDE68A; cursor:pointer;">' +
                                    'Pas KKTP' +
                                '</button>' +
                            '</div>';
                    }

                    html += '<tr class="tr-preview-siswa" data-siswa-id="' + sId + '" data-na-murni="' + naMurni + '" data-is-tuntas="' + (isTuntasMurni ? '1' : '0') + '">' +
                        '<td>' + (no++) + '</td>' +
                        '<td style="color:#64748B; font-weight:600;">' + escapeHtml(item.nis) + '</td>' +
                        '<td style="text-align:left; font-weight:700; color:#0F172A;">' + escapeHtml(item.nama_lengkap) + '</td>' +
                        // Kognitif
                        '<td style="background:#EFF6FF; font-weight:600;">' + Number(item.rata_kognitif).toFixed(1) + '</td>' +
                        '<td style="background:#DBEAFE; font-weight:800; color:#1E40AF;">' + Number(item.kontribusi_kognitif).toFixed(1) + '</td>' +
                        // Afektif
                        '<td style="background:#F0FDF4; font-weight:600;">' + Number(item.rata_afektif).toFixed(1) + '</td>' +
                        '<td style="background:#DCFCE7; font-weight:800; color:#166534;">' + Number(item.kontribusi_afektif).toFixed(1) + ' <span style="font-size:10px;">(' + escapeHtml(item.predikat_afektif) + ')</span></td>' +
                        // Psikomotorik
                        '<td style="background:#FAF5FF; font-weight:600;">' + Number(item.rata_psikomotorik).toFixed(1) + '</td>' +
                        '<td style="background:#F3E8FF; font-weight:800; color:#6B21A8;">' + Number(item.kontribusi_psikomotorik).toFixed(1) + ' <span style="font-size:10px;">(' + escapeHtml(item.predikat_psikomotorik) + ')</span></td>' +
                        // NA Murni
                        '<td style="font-weight:700; color:#475569;">' + Number(naMurni).toFixed(1) + '</td>' +
                        // Toleransi Guru Input
                        '<td style="background:#FFFBEB;">' + tolControlHtml + '</td>' +
                        // NA Final
                        '<td style="font-size:13px; font-weight:800; ' + naColor + ' background:#FFFBEB;" class="cell-na-final">' + Number(naFinal).toFixed(1) + '</td>' +
                        // Predikat & Status
                        '<td><span class="cell-pred-final" style="display:inline-block; padding:1px 6px; border-radius:4px; background:#F1F5F9; font-weight:800; font-size:11px;">' + escapeHtml(item.predikat_akhir) + '</span></td>' +
                        '<td class="cell-status-wrap">' + badgeStatus + '</td>' +
                        '<td class="cell-policy-wrap">' + badgePolicy + '</td>' +
                    '</tr>';
                });

                tbody.innerHTML = html;
                bindPreviewRowEvents(kktpVal);
                updatePreviewSummaryStats(kktpVal);
            })
            .catch(function(err) {
                console.error('Error load preview:', err);
                tbody.innerHTML = '<tr><td colspan="15" style="padding:24px; color:#DC2626;">Terjadi kesalahan jaringan saat memuat pratinjau.</td></tr>';
            });
    }

    /**
     * BIND EVENT REAL-TIME PADA INPUT TOLERANSI PER SISWA
     */
    function bindPreviewRowEvents(kktpVal) {
        var tbody = document.getElementById('tbodyPreviewSiswa');

        tbody.querySelectorAll('.input-tol-siswa').forEach(function(inp) {
            inp.addEventListener('input', function() {
                var val = parseFloat(this.value);
                var maxTol = parseFloat(this.dataset.maxTol) || 0;

                if (isNaN(val) || val < 0) {
                    val = 0;
                    this.value = 0;
                }
                // CLAMPING KETAT: TIDAK BISA MELEBIHI BATAS TOLERANSI!
                if (val > maxTol) {
                    val = maxTol;
                    this.value = maxTol;
                }

                var tr = this.closest('tr');
                updateSingleRowView(tr, val, kktpVal);
                updatePreviewSummaryStats(kktpVal);
            });
        });

        tbody.querySelectorAll('.btn-cap-kktp').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var maxTol = parseFloat(this.dataset.maxTol) || 0;
                var tr = this.closest('tr');
                var inp = tr.querySelector('.input-tol-siswa');
                if (inp) {
                    inp.value = maxTol;
                    updateSingleRowView(tr, maxTol, kktpVal);
                    updatePreviewSummaryStats(kktpVal);
                }
            });
        });
    }

    /**
     * UPDATE TAMPILAN 1 BARIS SISWA SECARA INSTAN
     */
    function updateSingleRowView(tr, tolVal, kktpVal) {
        var naMurni = parseFloat(tr.dataset.naMurni) || 0;
        var naFinal = Math.min(kktpVal, naMurni + tolVal);
        naFinal = Math.round(naFinal * 10) / 10;

        // NA Final
        var cellNa = tr.querySelector('.cell-na-final');
        if (cellNa) {
            cellNa.textContent = naFinal.toFixed(1);
            if (naFinal >= kktpVal) {
                cellNa.style.color = '#065F46';
            } else {
                cellNa.style.color = '#DC2626';
            }
        }

        // Predikat
        var pred = 'D';
        if (naFinal >= 90) pred = 'A';
        else if (naFinal >= 80) pred = 'B';
        else if (naFinal >= 70) pred = 'C';
        else pred = 'D';

        var cellPred = tr.querySelector('.cell-pred-final');
        if (cellPred) {
            cellPred.textContent = pred;
        }

        // Status
        var cellStatus = tr.querySelector('.cell-status-wrap');
        if (cellStatus) {
            cellStatus.innerHTML = (naFinal >= kktpVal)
                ? '<span style="background:#ECFDF5; color:#065F46; font-size:10.5px; font-weight:800; padding:2px 7px; border-radius:4px;">TERCAPAI</span>'
                : '<span style="background:#FEE2E2; color:#991B1B; font-size:10.5px; font-weight:800; padding:2px 7px; border-radius:4px;">BELUM</span>';
        }

        // Kebijakan
        var cellPolicy = tr.querySelector('.cell-policy-wrap');
        if (cellPolicy) {
            if (naMurni >= kktpVal) {
                cellPolicy.innerHTML = '<span style="background:#DCFCE7; color:#166534; font-size:10.5px; font-weight:700; padding:2px 8px; border-radius:4px;">Tuntas Murni</span>';
            } else if (tolVal > 0 && naFinal >= kktpVal) {
                cellPolicy.innerHTML = '<span style="background:#FEF3C7; color:#92400E; font-size:10.5px; font-weight:700; padding:2px 8px; border-radius:4px; border:1px solid #FDE68A;">' +
                    '<iconify-icon icon="solar:shield-check-bold" style="vertical-align:-1px;"></iconify-icon> Cap KKTP (' + kktpVal.toFixed(0) + ')</span>';
            } else if (tolVal > 0) {
                cellPolicy.innerHTML = '<span style="background:#FFF7ED; color:#C2410C; font-size:10.5px; font-weight:700; padding:2px 8px; border-radius:4px;">Terbantu Sebagian</span>';
            } else {
                cellPolicy.innerHTML = '<span style="background:#F1F5F9; color:#64748B; font-size:10.5px; font-weight:600; padding:2px 8px; border-radius:4px;">Belum Tercapai</span>';
            }
        }
    }

    /**
     * UPDATE RINGKASAN STATISTIK PRATINJAU DI FOOTER MODAL
     */
    function updatePreviewSummaryStats(kktpVal) {
        var total = 0;
        var tuntasMurni = 0;
        var terbantu = 0;
        var belum = 0;

        document.querySelectorAll('#tbodyPreviewSiswa .tr-preview-siswa').forEach(function(tr) {
            total++;
            var isMurni = (tr.dataset.isTuntas === '1');
            if (isMurni) {
                tuntasMurni++;
            } else {
                var cellNa = tr.querySelector('.cell-na-final');
                var na = cellNa ? parseFloat(cellNa.textContent) : 0;
                var inp = tr.querySelector('.input-tol-siswa');
                var tol = inp ? parseFloat(inp.value) : 0;

                if (na >= kktpVal && tol > 0) {
                    terbantu++;
                } else {
                    belum++;
                }
            }
        });

        document.getElementById('statPreviewTotal').textContent = total;
        document.getElementById('statPreviewTuntasMurni').textContent = tuntasMurni;
        document.getElementById('statPreviewTerbantu').textContent = terbantu;
        document.getElementById('statPreviewBelumTuntas').textContent = belum;
    }

    // Tombol Cepat: Bantu Semua Siswa Belum Tuntas Pas KKTP
    document.getElementById('btnBantuSemuaPasKKTP').addEventListener('click', function() {
        var kktpAcuan = currentGradebookData && currentGradebookData.kktp ? parseFloat(currentGradebookData.kktp) : 75.00;
        document.querySelectorAll('#tbodyPreviewSiswa .tr-preview-siswa').forEach(function(tr) {
            var inp = tr.querySelector('.input-tol-siswa');
            if (inp) {
                var maxTol = parseFloat(inp.dataset.maxTol) || 0;
                inp.value = maxTol;
                updateSingleRowView(tr, maxTol, kktpAcuan);
            }
        });
        updatePreviewSummaryStats(kktpAcuan);
    });

    // Tombol Cepat: Reset Semua Toleransi ke 0
    document.getElementById('btnResetSemuaToleransi').addEventListener('click', function() {
        var kktpAcuan = currentGradebookData && currentGradebookData.kktp ? parseFloat(currentGradebookData.kktp) : 75.00;
        document.querySelectorAll('#tbodyPreviewSiswa .tr-preview-siswa').forEach(function(tr) {
            var inp = tr.querySelector('.input-tol-siswa');
            if (inp) {
                inp.value = 0;
                updateSingleRowView(tr, 0, kktpAcuan);
            }
        });
        updatePreviewSummaryStats(kktpAcuan);
    });

    // Buka Modal Pratinjau saat tombol Generate Nilai Akhir diklik
    document.getElementById('btnGenerateNilaiAkhir').addEventListener('click', function() {
        if (state.id_kelas <= 0 || state.id_kmp <= 0) {
            Ilmuna.alert('warning', 'Peringatan', 'Pilih kelas dan mata pelajaran terlebih dahulu.');
            return;
        }
        openGeneratePreviewModal();
    });

    // Simpan Resmi ke Database dari dalam Modal
    document.getElementById('btnConfirmSaveGradebook').addEventListener('click', function() {
        if (state.id_kelas <= 0 || state.id_kmp <= 0) return;

        var btn = this;
        btn.setAttribute('disabled', 'disabled');
        btn.innerHTML = '<iconify-icon icon="solar:restart-bold" class="ilmuna-spin"></iconify-icon> Menyimpan ke Database...';

        // Kumpulkan data toleransi yang diinput per siswa
        var tolMap = {};
        document.querySelectorAll('#tbodyPreviewSiswa .input-tol-siswa').forEach(function(inp) {
            var sId = inp.dataset.siswaId;
            var val = parseFloat(inp.value) || 0;
            tolMap[sId] = val;
        });

        var fd = new FormData();
        fd.append('action', 'generate_nilai_akhir');
        fd.append('id_kelas', state.id_kelas);
        fd.append('id_kmp', state.id_kmp);
        fd.append('toleransi_siswa', JSON.stringify(tolMap));

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                btn.removeAttribute('disabled');
                btn.innerHTML = '<iconify-icon icon="solar:diskette-bold" width="16"></iconify-icon> Terapkan &amp; Simpan Resmi ke Database';

                if (!res || !res.success) {
                    Ilmuna.alert('error', 'Gagal', res ? res.message : 'Gagal menyimpan nilai akhir.');
                    return;
                }

                Ilmuna.toast('success', 'Tersimpan Resmi', res.message);
                closeGeneratePreviewModal();
                fetchGradebook();
            })
            .catch(function(err) {
                console.error('Error save final grades:', err);
                btn.removeAttribute('disabled');
                btn.innerHTML = '<iconify-icon icon="solar:diskette-bold" width="16"></iconify-icon> Terapkan &amp; Simpan Resmi ke Database';
                Ilmuna.alert('error', 'Kesalahan', 'Terjadi kesalahan jaringan.');
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

    // Download PDF via Blob Langsung
    document.getElementById('btnPrintPdf').addEventListener('click', async function(e) {
        e.preventDefault();
        if (state.id_kelas <= 0 || state.id_kmp <= 0) {
            Ilmuna.alert('warning', 'Peringatan', 'Pilih rombel kelas dan mata pelajaran terlebih dahulu.');
            return;
        }

        var btn = this;
        var originalHtml = btn.innerHTML;
        btn.setAttribute('disabled', 'disabled');
        btn.innerHTML = '<iconify-icon icon="solar:restart-bold" class="ilmuna-spin"></iconify-icon> Memproses PDF...';

        try {
            var url = '<?= BASE_URL ?>/dashboard/admin/reports-pdf.php?id_kelas=' + state.id_kelas + '&id_kmp=' + state.id_kmp;
            const res = await fetch(url);
            if (!res.ok) throw new Error('Gagal mengunduh berkas PDF (HTTP ' + res.status + ')');

            const disposition = res.headers.get('Content-Disposition') || '';
            let filename = 'Daftar_Nilai_Siswa_' + Date.now() + '.pdf';
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

            setTimeout(function() {
                if (a.parentNode) document.body.removeChild(a);
                window.URL.revokeObjectURL(blobUrl);
            }, 1000);

            Ilmuna.toast('success', 'Berhasil', 'Berkas PDF daftar nilai berhasil diunduh.');
        } catch (err) {
            console.error('Download PDF error:', err);
            Ilmuna.alert('error', 'Gagal', err.message || 'Gagal memproses berkas PDF.');
        } finally {
            btn.removeAttribute('disabled');
            btn.innerHTML = originalHtml;
        }
    });

    // Download Excel via Blob Langsung
    document.getElementById('btnExportExcel').addEventListener('click', async function(e) {
        e.preventDefault();
        if (state.id_kelas <= 0 || state.id_kmp <= 0) {
            Ilmuna.alert('warning', 'Peringatan', 'Pilih rombel kelas dan mata pelajaran terlebih dahulu.');
            return;
        }

        var btn = this;
        var originalHtml = btn.innerHTML;
        btn.setAttribute('disabled', 'disabled');
        btn.innerHTML = '<iconify-icon icon="solar:restart-bold" class="ilmuna-spin"></iconify-icon> Memproses Excel...';

        try {
            var url = '<?= BASE_URL ?>/dashboard/admin/reports-excel.php?id_kelas=' + state.id_kelas + '&id_kmp=' + state.id_kmp;
            const res = await fetch(url);
            if (!res.ok) throw new Error('Gagal mengunduh berkas Excel (HTTP ' + res.status + ')');

            const disposition = res.headers.get('Content-Disposition') || '';
            let filename = 'Daftar_Nilai_Siswa_' + Date.now() + '.xlsx';
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

            setTimeout(function() {
                if (a.parentNode) document.body.removeChild(a);
                window.URL.revokeObjectURL(blobUrl);
            }, 1000);

            Ilmuna.toast('success', 'Berhasil', 'Berkas Excel daftar nilai berhasil diunduh.');
        } catch (err) {
            console.error('Download Excel error:', err);
            Ilmuna.alert('error', 'Gagal', err.message || 'Gagal memproses berkas Excel.');
        } finally {
            btn.removeAttribute('disabled');
            btn.innerHTML = originalHtml;
        }
    });

    // Inisialisasi awal
    loadKelasOptions();
});
</script>

<?php
require_once BASE_PATH . '/includes/footer.php';
?>
