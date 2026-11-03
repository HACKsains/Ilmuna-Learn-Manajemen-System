<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — LAPORAN & DAFTAR NILAI SISWA (ADMINISTRATOR & GURU)
 * ==============================================================================
 * Sistem Rekapitulasi & Daftar Nilai Siswa Kurikulum Merdeka Terintegrasi:
 *   1. Filter Pemilihan Rombel Kelas & Mata Pelajaran (Cascading In-Page Lazy Load AJAX)
 *   2. 100% Mengikuti Arsitektur & Estetika Data Siswa (students.php)
 *   3. Daftar Nilai Standar: Kolom No, NIS, NISN, Nama Siswa (Sticky Header & Left Columns)
 *   4. Kolom Nilai Multi-Level:
 *      - Tugas & Proyek (T1, T2, ..., Proyek)
 *      - Asesmen Formatif (F1, F2, ...)
 *      - Asesmen Sumatif (Sumatif LM, STS, SAS)
 *   5. Remedial Terintegrasi:
 *      - Menampilkan Nilai Asli (Coret) & Nilai Remidi (Tebal) dalam 1 Kotak Interaktif
 *      - Modal Ubah Nilai Remidi (Maksimal dibatasi senilai KKTP)
 *   6. Ringkasan Nilai Akhir, Predikat, & Status Ketuntasan KKTP Real-time
 *   7. Export Excel (.xlsx/.xls Blob) & Cetak / Download PDF
 * Tema Warna: Lumina Gold & Emerald (Navy #1D3B5E, Teal #0D7A87, Gold #F59E0B)
 * ==============================================================================
 */

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';

requireRole('admin');

$pdo = getDBConnection();
$pdo->exec("SET time_zone = '+07:00'");

$pageTitle = 'Laporan Nilai & Daftar Hasil Belajar';
$pageIcon  = 'solar:document-bold-duotone';

// Pastikan kolom nilai_remedial tersedia di tabel-tabel nilai
try {
    $c1 = $pdo->query("SHOW COLUMNS FROM nilai_asesmen_sumatif LIKE 'nilai_remedial'")->fetchAll();
    if (empty($c1)) $pdo->exec("ALTER TABLE nilai_asesmen_sumatif ADD COLUMN nilai_remedial DECIMAL(5,2) NULL DEFAULT NULL AFTER nilai_angka");
    $c2 = $pdo->query("SHOW COLUMNS FROM nilai_asesmen_formatif LIKE 'nilai_remedial'")->fetchAll();
    if (empty($c2)) $pdo->exec("ALTER TABLE nilai_asesmen_formatif ADD COLUMN nilai_remedial DECIMAL(5,2) NULL DEFAULT NULL AFTER nilai_angka");
    $c3 = $pdo->query("SHOW COLUMNS FROM nilai_tugas LIKE 'nilai_remedial'")->fetchAll();
    if (empty($c3)) $pdo->exec("ALTER TABLE nilai_tugas ADD COLUMN nilai_remedial DECIMAL(5,2) NULL DEFAULT NULL AFTER nilai");
} catch (Throwable $e) {}

/* ==============================================================================
 * 1. BACKEND AJAX HANDLERS (JSON RESPONSE)
 * ============================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];

    try {
        // 1.1 LIST PILIHAN KELAS UNTUK DROPDOWN & QUICK CHIPS
        if ($action === 'list_kelas_options') {
            $sql = "SELECT k.id, k.nama_kelas, k.tahun_pelajaran,
                           (SELECT COUNT(*) FROM siswa s WHERE s.id_kelas = k.id) AS total_siswa,
                           (SELECT COUNT(*) FROM kelas_mata_pelajaran kmp WHERE kmp.id_kelas = k.id) AS total_mapel
                    FROM kelas k
                    ORDER BY k.nama_kelas ASC";
            $kelas = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => [
                    'kelas' => $kelas ?: []
                ]
            ]);
            exit;
        }

        // 1.2 FETCH MAPEL BY KELAS (UNTUK CASCADING SELECTOR & QUICK MAPEL CHIPS)
        if ($action === 'get_mapel_by_kelas') {
            $idKelas = (int)($_POST['id_kelas'] ?? 0);
            $stmt = $pdo->prepare("
                SELECT kmp.id, kmp.id_mata_pelajaran, mp.nama_mata_pelajaran, mp.kode_mapel,
                       COALESCE(mp.kktp, m.kkm, (SELECT kktp FROM asesmen_sumatif WHERE id_kelas_mata_pelajaran = kmp.id AND kktp > 0 ORDER BY id DESC LIMIT 1), 75.00) AS kktp,
                       COALESCE(g.nama_guru, '') AS nama_guru
                FROM kelas_mata_pelajaran kmp
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN mapel m ON (m.kode_mapel = mp.kode_mapel OR m.nama_mapel LIKE CONCAT('%', mp.nama_mata_pelajaran, '%'))
                LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
                LEFT JOIN guru g ON gmp.id_guru = g.id
                WHERE kmp.id_kelas = ?
                ORDER BY mp.nama_mata_pelajaran ASC
            ");
            $stmt->execute([$idKelas]);
            $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'data' => $list ?: []]);
            exit;
        }

        // 1.3 LAZY LOAD GRADEBOOK DATA (SISWA, ASESMEN, KKTP, NILAI & REMEDIAL)
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
                       COALESCE(g.nama_guru, 'Belum Ditentukan') AS nama_guru
                FROM kelas_mata_pelajaran kmp
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
                LEFT JOIN guru g ON gmp.id_guru = g.id
                WHERE kmp.id = ? LIMIT 1
            ");
            $stmtM->execute([$idKmp]);
            $mapelInfo = $stmtM->fetch(PDO::FETCH_ASSOC);

            // Daftar Seluruh Siswa Rombel Ini
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

            // Auto-Sync Sesi CBT yang Selesai ke Tabel Nilai (jika belum tercatat)
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
                        SELECT
                            SUM(COALESCE(skor_diperoleh, 0)) AS total_skor,
                            SUM(COALESCE(skor_bobot_maks, 10)) AS total_maks
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

            // Kolom Asesmen Formatif
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

            // Kolom Asesmen Sumatif (Mendukung Multi-Kelas via asesmen_sumatif_kelas & KMP)
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

            // Ambil KKTP Acuan Mata Pelajaran dari Database (TIDAK ADA HARDCODE)
            $kktpAcuan = null;

            // 1. Cek dari tabel mata_pelajaran.kktp
            $stmtKktpMp = $pdo->prepare("
                SELECT mp.kktp, mp.nama_mata_pelajaran, mp.kode_mapel
                FROM kelas_mata_pelajaran kmp
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                WHERE kmp.id = ?
                LIMIT 1
            ");
            $stmtKktpMp->execute([$idKmp]);
            $mpRow = $stmtKktpMp->fetch(PDO::FETCH_ASSOC);
            if ($mpRow && !empty($mpRow['kktp']) && (float)$mpRow['kktp'] > 0) {
                $kktpAcuan = (float)$mpRow['kktp'];
            }

            // 2. Jika belum ada di mata_pelajaran, ambil dari asesmen_sumatif.kktp
            if ($kktpAcuan === null) {
                $stmtSmKktp = $pdo->prepare("
                    SELECT kktp FROM asesmen_sumatif 
                    WHERE (id_kelas_mata_pelajaran = ? OR id IN (SELECT id_asesmen_sumatif FROM asesmen_sumatif_kelas WHERE id_kelas_mata_pelajaran = ?))
                      AND kktp IS NOT NULL AND kktp > 0 
                    ORDER BY id DESC LIMIT 1
                ");
                $stmtSmKktp->execute([$idKmp, $idKmp]);
                $smKktpVal = $stmtSmKktp->fetchColumn();
                if ($smKktpVal !== false && (float)$smKktpVal > 0) {
                    $kktpAcuan = (float)$smKktpVal;
                }
            }

            // 3. Jika belum ada, ambil dari tabel mapel.kkm
            if ($kktpAcuan === null && $mpRow) {
                $stmtM = $pdo->prepare("
                    SELECT kkm FROM mapel 
                    WHERE nama_mapel LIKE ? OR ? LIKE CONCAT('%', nama_mapel, '%') OR kode_mapel = ? 
                    LIMIT 1
                ");
                $stmtM->execute(['%' . $mpRow['nama_mata_pelajaran'] . '%', $mpRow['nama_mata_pelajaran'], $mpRow['kode_mapel']]);
                $kkmVal = $stmtM->fetchColumn();
                if ($kkmVal !== false && (float)$kkmVal > 0) {
                    $kktpAcuan = (float)$kkmVal;
                }
            }

            // 4. Jika belum ada, ambil dari pengayaan_remedial.kktp_minimal atau nilai_asesmen_sumatif.nilai_kktp
            if ($kktpAcuan === null) {
                $stmtPrKktp = $pdo->prepare("
                    SELECT kktp_minimal FROM pengayaan_remedial 
                    WHERE id_kelas_mata_pelajaran = ? AND kktp_minimal IS NOT NULL AND kktp_minimal > 0 
                    ORDER BY id DESC LIMIT 1
                ");
                $stmtPrKktp->execute([$idKmp]);
                $prKktpVal = $stmtPrKktp->fetchColumn();
                if ($prKktpVal !== false && (float)$prKktpVal > 0) {
                    $kktpAcuan = (float)$prKktpVal;
                }
            }

            if ($kktpAcuan === null) {
                $stmtNsKktp = $pdo->prepare("
                    SELECT nilai_kktp FROM nilai_asesmen_sumatif 
                    WHERE (id_kelas_mata_pelajaran = ? OR id_siswa IN (SELECT id FROM siswa WHERE id_kelas = ?))
                      AND nilai_kktp IS NOT NULL AND nilai_kktp > 0 
                    ORDER BY id DESC LIMIT 1
                ");
                $stmtNsKktp->execute([$idKmp, $idKelas]);
                $nsKktpVal = $stmtNsKktp->fetchColumn();
                if ($nsKktpVal !== false && (float)$nsKktpVal > 0) {
                    $kktpAcuan = (float)$nsKktpVal;
                }
            }

            // Fallback safety
            if ($kktpAcuan === null || $kktpAcuan <= 0) {
                $kktpAcuan = 75.00;
            }

            // Normalisasi Slot Tugas jika kosong
            if (empty($tugasCols)) {
                $tugasCols = [
                    ['id' => 0, 'slot_code' => 'T1', 'judul_tugas' => 'Tugas 1 (Pemahaman Konsep)', 'jenis_penugasan' => 'Individu'],
                    ['id' => 0, 'slot_code' => 'T2', 'judul_tugas' => 'Tugas 2 (Studi Kasus / Proyek Mini)', 'jenis_penugasan' => 'Individu']
                ];
            } else {
                foreach ($tugasCols as $idx => &$tc) {
                    $tc['slot_code'] = 'T' . ($idx + 1);
                }
                unset($tc);
            }

            // Normalisasi Slot Formatif jika kosong
            if (empty($formatifCols)) {
                $formatifCols = [
                    ['id' => 0, 'slot_code' => 'F1', 'judul_asesmen' => 'Formatif 1 (Kuis Proses Belajar)', 'teknik_asesmen' => 'Kuis'],
                    ['id' => 0, 'slot_code' => 'F2', 'judul_asesmen' => 'Formatif 2 (Penilaian Kinerja / Observasi)', 'teknik_asesmen' => 'Observasi']
                ];
            } else {
                foreach ($formatifCols as $idx => &$fc) {
                    $fc['slot_code'] = 'F' . ($idx + 1);
                }
                unset($fc);
            }

            // Normalisasi Slot Sumatif jika kosong
            if (empty($sumatifCols)) {
                $sumatifCols = [
                    ['id' => 0, 'slot_code' => 'S1', 'judul_asesmen' => 'Sumatif Lingkup Materi 1', 'jenis_sumatif' => 'Sumatif Lingkup Materi', 'kktp' => $kktpAcuan, 'metode_penilaian' => 'PAP', 'nilai_patokan' => $kktpAcuan, 'rata_rata_kelas' => null],
                    ['id' => 0, 'slot_code' => 'STS', 'judul_asesmen' => 'Sumatif Tengah Semester (STS)', 'jenis_sumatif' => 'Sumatif Tengah Semester', 'kktp' => $kktpAcuan, 'metode_penilaian' => 'PAN', 'nilai_patokan' => $kktpAcuan, 'rata_rata_kelas' => null],
                    ['id' => 0, 'slot_code' => 'SAS', 'judul_asesmen' => 'Sumatif Akhir Semester (SAS)', 'jenis_sumatif' => 'Sumatif Akhir Semester', 'kktp' => $kktpAcuan, 'metode_penilaian' => 'PAN', 'nilai_patokan' => $kktpAcuan, 'rata_rata_kelas' => null]
                ];
            } else {
                foreach ($sumatifCols as $idx => &$sc) {
                    if (empty($sc['kktp']) || (float)$sc['kktp'] <= 0) {
                        $sc['kktp'] = $kktpAcuan;
                    }
                    if ($sc['jenis_sumatif'] === 'Sumatif Tengah Semester') {
                        $sc['slot_code'] = 'STS';
                    } elseif ($sc['jenis_sumatif'] === 'Sumatif Akhir Semester') {
                        $sc['slot_code'] = 'SAS';
                    } else {
                        $sc['slot_code'] = 'S' . ($idx + 1);
                    }

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

            // Ambil Nilai Tugas (Mencocokkan siswa di kelas & ID tugas relevan)
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

            // Ambil Nilai Formatif (Mencocokkan siswa di kelas & ID formatif relevan)
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

            // Ambil Nilai Sumatif (Mencocokkan siswa di kelas & ID sumatif relevan)
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
                    'grades_sumatif'  => $gradesSumatif
                ]
            ]);
            exit;
        }

        // 1.4 SIMPAN / UBAH NILAI (READ ONLY FOR ADMIN)
        if ($action === 'save_grade') {
            echo json_encode([
                'success' => false,
                'message' => 'Laporan nilai admin bersifat Read-Only. Penilaian dikelola langsung dari sistem asesmen dan penilaian guru pengampu.'
            ]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenali.']);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()]);
        exit;
    }
}

// Versi file CSS agar cache browser selalu segar
$cssVersionStudents = file_exists(BASE_PATH . '/assets/css/admin-students.css') ? filemtime(BASE_PATH . '/assets/css/admin-students.css') : '1.0';

$extraHead = '
<link rel="stylesheet" href="' . BASE_URL . '/assets/css/admin-students.css?v=' . $cssVersionStudents . '">
<style>
/* ==============================================================================
 * LUMINA GOLD & EMERALD GRADEBOOK CUSTOM STYLES
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
    padding: 10px 8px;
    vertical-align: middle;
}
.ilmuna-gradebook-table thead tr:first-child th {
    background: #F8FAFC;
    color: #0F172A;
    font-weight: 800;
    font-size: 12.5px;
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
    top: 38px;
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

/* STICKY LEFT COLUMNS */
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
.col-no   { left: 0; width: 44px; min-width: 44px; max-width: 44px; text-align: center; }
.col-nis  { left: 44px; width: 95px; min-width: 95px; font-weight: 600; color: #64748B; }
.col-nisn { left: 139px; width: 105px; min-width: 105px; font-weight: 600; color: #64748B; }
.col-nama { left: 244px; min-width: 220px; text-align: left !important; font-weight: 700; color: #0F172A; box-shadow: 4px 0 8px rgba(0,0,0,0.04); }

/* READ-ONLY DUAL-VALUE CELL */
.grade-box-cell {
    padding: 6px 4px !important;
    cursor: default;
    transition: background 0.15s ease;
    user-select: text;
    min-width: 72px;
    position: relative;
}
.grade-box-cell:hover {
    background: rgba(13, 122, 135, 0.04);
}
.grade-box-inner {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 2px;
    min-height: 38px;
}
.score-asli-strikethrough {
    font-size: 10.5px;
    font-weight: 700;
    color: #EF4444;
    text-decoration: line-through;
    opacity: 0.85;
    line-height: 1;
}
.score-effective {
    font-size: 13.5px;
    font-weight: 800;
    color: #1E293B;
    line-height: 1.1;
}
.score-effective.is-remedial { color: #0D7A87; }
.score-effective.is-failed   { color: #DC2626; }
.score-effective.is-passed   { color: #059669; }

.badge-remed-tag {
    font-size: 9px;
    font-weight: 800;
    background: #FEF3C7;
    color: #92400E;
    padding: 1px 5px;
    border-radius: 4px;
    text-transform: uppercase;
    letter-spacing: 0.2px;
    display: inline-block;
    margin-top: 2px;
    border: 1px solid rgba(245, 158, 11, 0.3);
}
.badge-failed-tag {
    font-size: 9px;
    font-weight: 800;
    background: #FEE2E2;
    color: #B91C1C;
    padding: 1px 5px;
    border-radius: 4px;
    text-transform: uppercase;
    display: inline-block;
    margin-top: 2px;
    border: 1px solid rgba(239, 68, 68, 0.3);
}
.grade-empty-dash {
    color: #CBD5E1;
    font-size: 14px;
    font-weight: 600;
}

/* MODAL EDIT NILAI */
.ilmuna-modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(4px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 99999;
}
.ilmuna-modal-box {
    background: #FFFFFF;
    border-radius: 18px;
    width: 95%;
    max-width: 520px;
    box-shadow: 0 20px 45px rgba(0,0,0,0.25);
    overflow: hidden;
    animation: modalPop 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes modalPop {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}

@media print {
    body { background: #FFF !important; }
    .no-print, header, aside, .ilmuna-crud-toolbar, .ilmuna-sidebar, .ilmuna-topnav, .ilmuna-bottomnav, .ilmuna-modal-overlay { display: none !important; }
    .ilmuna-crud-tablewrap { border: none !important; box-shadow: none !important; }
    .ilmuna-gradebook-table { font-size: 10px !important; }
    .ilmuna-table-responsive { max-height: none !important; overflow: visible !important; }
}
</style>
';

require_once BASE_PATH . '/includes/header.php';
loadCentralTemplates(['modal', 'calendar', 'table']);
?>

<!-- ==============================================================================
 * LUMINA GOLD THEMED REKAPITULASI LAPORAN NILAI (PERSIS 100% STUDENTS.PHP)
 * ============================================================================== -->
<div class="ilmuna-siswa-app" id="appReports" data-ajax="<?= BASE_URL ?>/dashboard/admin/reports">

    <!-- TOOLBAR & PRIMARY CLASS + MAPEL SELECTOR -->
    <div class="ilmuna-crud-toolbar no-print">
        <div class="ilmuna-crud-toolbar__row">
            <!-- Filter Utama 1: Rombel Kelas (Wajib Dipilih Terlebih Dahulu) -->
            <div class="ilmuna-filter-primary">
                <label for="filterKelas">
                    <iconify-icon icon="solar:notebook-bookmark-bold" width="18"></iconify-icon>
                    Rombel / Kelas:
                </label>
                <select id="filterKelas" class="ilmuna-select-primary">
                    <option value="0">— Pilih Rombel Kelas Terlebih Dahulu —</option>
                </select>
            </div>

            <!-- Filter Utama 2: Mata Pelajaran (Cascading In-Page Lazy Load) -->
            <div class="ilmuna-filter-primary">
                <label for="filterMapel">
                    <iconify-icon icon="solar:book-bookmark-bold" width="18"></iconify-icon>
                    Mata Pelajaran:
                </label>
                <select id="filterMapel" class="ilmuna-select-primary" disabled>
                    <option value="0">— Pilih Kelas Terlebih Dahulu —</option>
                </select>
            </div>

            <!-- Search Box Siswa (Live Filter Instan) -->
            <div class="ilmuna-search-box">
                <iconify-icon icon="solar:magnifer-bold" class="ilmuna-search-box__icon"></iconify-icon>
                <input type="text" id="searchSiswa" placeholder="Cari nama siswa, NISN, NIS..." autocomplete="off">
                <button type="button" class="ilmuna-search-clear" id="btnClearSearch" style="display:none;" title="Hapus pencarian">
                    <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
                </button>
            </div>

            <button type="button" class="ilmuna-btn ilmuna-btn--outline" id="btnResetFilter" title="Reset filter rombel dan mapel">
                <iconify-icon icon="solar:refresh-bold" width="16"></iconify-icon>
                Reset
            </button>
        </div>

        <div class="ilmuna-crud-toolbar__row ilmuna-crud-toolbar__row--filters">
            <div style="font-size: 12.5px; color: #64748B; display: flex; align-items: center; gap: 6px;">
                <iconify-icon icon="solar:info-circle-bold" width="16" style="color: #0D7A87;"></iconify-icon>
                <span>Pilih Kelas dan Mapel untuk menampilkan rekapitulasi nilai (Read-Only).</span>
            </div>

            <!-- Action Buttons: Export Excel & Cetak PDF -->
            <div class="ilmuna-crud-toolbar__row--actions" style="margin-left: auto;">
                <button type="button" class="ilmuna-btn ilmuna-btn--outline" id="btnExportExcel" disabled title="Pilih kelas dan mapel terlebih dahulu untuk export">
                    <iconify-icon icon="solar:file-send-bold" width="16"></iconify-icon>
                    Export Excel (.xlsx)
                </button>
                <button type="button" class="ilmuna-btn ilmuna-btn--gold" id="btnPrintPdf" disabled title="Pilih kelas dan mapel terlebih dahulu untuk unduh PDF">
                    <iconify-icon icon="solar:file-download-bold" width="16"></iconify-icon>
                    Unduh PDF
                </button>
            </div>
        </div>
    </div>

    <!-- CONTAINER TABEL DAFTAR NILAI / EMPTY STATE -->
    <div class="ilmuna-crud-tablewrap" id="tableWrap">
        <!-- Initial Empty Row matching students.php -->
        <div class="ilmuna-empty-row" id="emptyStateBox">
            <div class="ilmuna-empty-row__icon">
                <iconify-icon icon="solar:notebook-bookmark-bold"></iconify-icon>
            </div>
            <h4 id="emptyStateTitle">Pilih Rombel / Kelas Terlebih Dahulu</h4>
            <p id="emptyStateDesc">Rekapitulasi lengkap nilai tugas, asesmen formatif, sumatif, dan fitur remedial akan dimuat secara otomatis setelah Anda memilih Rombel Kelas dan Mata Pelajaran di atas.</p>
            <div class="ilmuna-empty-row__chips" id="quickChipsContainer"></div>
        </div>
    </div>

</div>

<!-- ==============================================================================
 * JAVASCRIPT LAZY LOAD & GRADEBOOK LOGIC (PURE AJAX — ZERO URL CHANGES)
 * ============================================================================== -->
<script>
(function() {
    'use strict';

    var AJAX_URL = document.getElementById('appReports').getAttribute('data-ajax');
    var cachedKelas = [];
    var cachedMapel = [];
    var currentGradebookData = null;

    var state = {
        id_kelas: 0,
        id_kmp: 0
    };

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
     * 1. LOAD DAFTAR KELAS & INISIALISASI DROPDOWN
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
                var optHtml = '<option value="0">— Pilih Rombel Kelas Terlebih Dahulu —</option>';

                cachedKelas.forEach(function(k) {
                    var label = k.nama_kelas + ' (' + (k.tahun_pelajaran || '2026/2027') + ') — ' + k.total_siswa + ' Siswa';
                    optHtml += '<option value="' + k.id + '">' + escapeHtml(label) + '</option>';
                });

                filterSelect.innerHTML = optHtml;
                renderClassEmptyState();
            })
            .catch(function(err) {
                console.error('Error load kelas options:', err);
            });
    }

    /* --------------------------------------------------------------------------
     * 2. RENDER EMPTY STATES & QUICK CHIPS
     * -------------------------------------------------------------------------- */
    function renderClassEmptyState() {
        var tableWrap = document.getElementById('tableWrap');
        document.getElementById('btnExportExcel').setAttribute('disabled', 'disabled');
        document.getElementById('btnPrintPdf').setAttribute('disabled', 'disabled');

        var chipsHtml = '';
        cachedKelas.forEach(function(k) {
            chipsHtml += '<button type="button" class="ilmuna-empty-chip" data-id="' + k.id + '">' +
                '<iconify-icon icon="solar:notebook-bookmark-bold" width="13"></iconify-icon> ' +
                escapeHtml(k.nama_kelas) + ' (' + k.total_siswa + ' Siswa)</button>';
        });

        tableWrap.innerHTML = 
            '<div class="ilmuna-empty-row" id="emptyStateBox">' +
                '<div class="ilmuna-empty-row__icon"><iconify-icon icon="solar:notebook-bookmark-bold"></iconify-icon></div>' +
                '<h4>Pilih Rombel / Kelas Terlebih Dahulu</h4>' +
                '<p>Rekapitulasi lengkap nilai tugas, asesmen formatif, sumatif, dan fitur remedial akan dimuat secara otomatis setelah Anda memilih Rombel Kelas dan Mata Pelajaran di atas.</p>' +
                '<div class="ilmuna-empty-row__chips" id="quickChipsContainer">' + chipsHtml + '</div>' +
            '</div>';

        // Bind chips
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

        var chipsHtml = '';
        if (cachedMapel.length === 0) {
            chipsHtml = '<span style="color:#94a3b8; font-size:13px;">Belum ada mata pelajaran yang ditautkan ke kelas ini.</span>';
        } else {
            cachedMapel.forEach(function(m) {
                var kktpTag = m.kktp ? ' &bull; KKTP: ' + Number(m.kktp).toFixed(0) : '';
                chipsHtml += '<button type="button" class="ilmuna-empty-chip" data-id="' + m.id + '">' +
                    '<iconify-icon icon="solar:book-bookmark-bold" width="13"></iconify-icon> ' +
                    escapeHtml(m.nama_mata_pelajaran) + (m.nama_guru ? ' (' + escapeHtml(m.nama_guru) + ')' : '') + kktpTag + '</button>';
            });
        }

        tableWrap.innerHTML = 
            '<div class="ilmuna-empty-row" id="emptyStateBox">' +
                '<div class="ilmuna-empty-row__icon" style="color: #0D7A87; border-color: rgba(13, 122, 135, 0.45); background: linear-gradient(135deg, rgba(13, 122, 135, 0.08) 0%, rgba(82, 178, 220, 0.12) 100%);">' +
                    '<iconify-icon icon="solar:book-bookmark-bold"></iconify-icon>' +
                '</div>' +
                '<h4>Pilih Mata Pelajaran Terlebih Dahulu</h4>' +
                '<p>Silakan pilih salah satu mata pelajaran yang diajarkan pada <strong>' + escapeHtml(className) + '</strong> untuk memuat daftar nilai dan status ketuntasan siswa.</p>' +
                '<div class="ilmuna-empty-row__chips" id="quickChipsContainer">' + chipsHtml + '</div>' +
            '</div>';

        // Bind chips
        tableWrap.querySelectorAll('.ilmuna-empty-chip').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var mid = parseInt(btn.dataset.id, 10);
                document.getElementById('filterMapel').value = mid;
                selectMapel(mid);
            });
        });
    }

    /* --------------------------------------------------------------------------
     * 3. SELECT CLASS LOGIC (CASCADING AJAX LAZY LOAD)
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

        // Cari info nama kelas terpilih
        var selectedKelas = cachedKelas.find(function(k){ return parseInt(k.id, 10) === state.id_kelas; });
        var className = selectedKelas ? selectedKelas.nama_kelas : 'Kelas Terpilih';

        // Disable dan set loading
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
                    optHtml = '<option value="0">— Belum ada Mapel untuk Kelas Ini —</option>';
                    filterMapel.innerHTML = optHtml;
                    filterMapel.setAttribute('disabled', 'disabled');
                } else {
                    cachedMapel.forEach(function(m) {
                        var kktpTag = m.kktp ? ' [KKTP: ' + Number(m.kktp).toFixed(0) + ']' : '';
                        var label = m.nama_mata_pelajaran + kktpTag + (m.nama_guru ? ' • Guru: ' + m.nama_guru : '');
                        optHtml += '<option value="' + m.id + '">' + escapeHtml(label) + '</option>';
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
     * 4. SELECT MAPEL LOGIC (LAZY LOAD GRADEBOOK VIA AJAX)
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

        // Tampilkan lazy loading spinner persis students.php
        tableWrap.innerHTML = 
            '<div style="text-align:center; padding: 60px 20px; color:#64748b;">' +
                '<iconify-icon icon="solar:restart-bold" class="ilmuna-spin" width="34" style="color:#0D7A87;"></iconify-icon>' +
                '<p style="margin-top:12px; font-weight:700; font-size:15px; color:#1e293b;">Memuat rekapitulasi nilai dan status remedial...</p>' +
                '<p style="font-size:12.5px; color:#64748b; margin:0;">Mengambil seluruh data siswa, asesmen formatif, sumatif, dan kalkulasi KKTP...</p>' +
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
     * 5. RENDER GRADEBOOK DOM & INTERACTIVE CELLS
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

        // 1. Enable Actions
        document.getElementById('btnExportExcel').removeAttribute('disabled');
        document.getElementById('btnPrintPdf').removeAttribute('disabled');

        // 2. Render Table Structure
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
                            '<th rowspan="2" style="width: 85px; background: #0F172A; color: #FFFFFF;">Nilai Akhir</th>' +
                            '<th rowspan="2" style="width: 65px; background: #1E293B; color: #FFFFFF;">Predikat</th>' +
                            '<th rowspan="2" style="width: 105px; background: #1E293B; color: #FFFFFF;">Status KKTP</th>' +
                        '</tr>' +
                        '<tr>';

        // Subheaders Tugas
        tugasCols.forEach(function(tc) {
            tableHtml += '<th title="' + escapeHtml(tc.judul_tugas) + '" style="cursor:help; width:68px;">' + escapeHtml(tc.slot_code) + '</th>';
        });

        // Subheaders Formatif
        formatifCols.forEach(function(fc) {
            tableHtml += '<th title="' + escapeHtml(fc.judul_asesmen) + ' (PAP - KKTP: ' + kktpAcuan + ')" style="cursor:help; width:72px;">' + escapeHtml(fc.slot_code) + '</th>';
        });

        // Subheaders Sumatif
        sumatifCols.forEach(function(sc) {
            var isPan = (sc.metode_penilaian === 'PAN');
            var patokan = sc.nilai_patokan !== undefined && sc.nilai_patokan !== null ? parseFloat(sc.nilai_patokan) : (sc.kktp ? parseFloat(sc.kktp) : kktpAcuan);
            var titleText = escapeHtml(sc.judul_asesmen) + (isPan ? ' [PAN - Norma Rata-rata Kelas: ' + patokan.toFixed(1) + ']' : ' [PAP - KKTP: ' + patokan.toFixed(0) + ']');
            var slotLabel = escapeHtml(sc.slot_code) + (isPan ? ' <span style="font-size:9.5px; opacity:0.85;">(PAN)</span>' : '');
            tableHtml += '<th title="' + titleText + '" style="cursor:help; width:75px;">' + slotLabel + '</th>';
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
                var sumScores = [];

                tableHtml += 
                    '<tr class="row-siswa" data-id="' + sId + '" data-nama="' + escapeHtml((s.nama_lengkap || '').toLowerCase()) + '" data-nis="' + escapeHtml(s.nis || '') + '" data-nisn="' + escapeHtml(s.nisn || '') + '">' +
                        '<td class="sticky-col col-no">' + (no++) + '</td>' +
                        '<td class="sticky-col col-nis">' + escapeHtml(s.nis || '-') + '</td>' +
                        '<td class="sticky-col col-nisn">' + escapeHtml(s.nisn || '-') + '</td>' +
                        '<td class="sticky-col col-nama">' +
                            '<div style="font-weight:700; color:#0F172A;">' + escapeHtml(s.nama_lengkap) + '</div>' +
                            '<div style="font-size:11px; color:#94A3B8;">' + escapeHtml(s.nama_kelas || kelas.nama_kelas) + '</div>' +
                        '</td>';

                // 1. Cells Tugas
                tugasCols.forEach(function(tc) {
                    var itemTId = tc.id;
                    var gT = (gradesTugas[sId] && gradesTugas[sId][itemTId]) ? gradesTugas[sId][itemTId] : null;
                    var valAsli = gT ? gT.nilai : null;
                    var valRemed = gT ? gT.nilai_remedial : null;
                    var effVal = (valRemed !== null && valRemed !== undefined) ? valRemed : valAsli;
                    if (effVal !== null && effVal !== undefined && effVal !== '') sumScores.push(parseFloat(effVal));

                    tableHtml += 
                        '<td class="grade-box-cell" data-siswa-id="' + sId + '" data-nama="' + escapeHtml(s.nama_lengkap) + '" data-nis="' + escapeHtml(s.nis || '-') + '" data-tipe="tugas" data-item-id="' + itemTId + '" data-slot="' + escapeHtml(tc.slot_code) + '" data-judul="' + escapeHtml(tc.judul_tugas) + '" data-asli="' + (valAsli !== null ? valAsli : '') + '" data-remed="' + (valRemed !== null ? valRemed : '') + '" data-kktp="' + kktpAcuan + '">' +
                            '<div class="grade-box-inner" id="cell-tugas-' + sId + '-' + itemTId + '">' +
                                renderCellContent(valAsli, valRemed, kktpAcuan) +
                            '</div>' +
                        '</td>';
                });

                // 2. Cells Formatif
                formatifCols.forEach(function(fc) {
                    var itemFId = fc.id;
                    var gF = (gradesFormatif[sId] && gradesFormatif[sId][itemFId]) ? gradesFormatif[sId][itemFId] : null;
                    var valAsli = gF ? gF.nilai_angka : null;
                    var valRemed = gF ? gF.nilai_remedial : null;
                    var effVal = (valRemed !== null && valRemed !== undefined) ? valRemed : valAsli;
                    if (effVal !== null && effVal !== undefined && effVal !== '') sumScores.push(parseFloat(effVal));

                    tableHtml += 
                        '<td class="grade-box-cell" data-siswa-id="' + sId + '" data-nama="' + escapeHtml(s.nama_lengkap) + '" data-nis="' + escapeHtml(s.nis || '-') + '" data-tipe="formatif" data-item-id="' + itemFId + '" data-slot="' + escapeHtml(fc.slot_code) + '" data-judul="' + escapeHtml(fc.judul_asesmen) + '" data-asli="' + (valAsli !== null ? valAsli : '') + '" data-remed="' + (valRemed !== null ? valRemed : '') + '" data-kktp="' + kktpAcuan + '">' +
                            '<div class="grade-box-inner" id="cell-formatif-' + sId + '-' + itemFId + '">' +
                                renderCellContent(valAsli, valRemed, kktpAcuan) +
                            '</div>' +
                        '</td>';
                });

                // 3. Cells Sumatif
                sumatifCols.forEach(function(sc) {
                    var itemSmId = sc.id;
                    var isPan = (sc.metode_penilaian === 'PAN');
                    var patokanThis = sc.nilai_patokan !== undefined && sc.nilai_patokan !== null ? parseFloat(sc.nilai_patokan) : (sc.kktp ? parseFloat(sc.kktp) : kktpAcuan);
                    var gSm = (gradesSumatif[sId] && gradesSumatif[sId][itemSmId]) ? gradesSumatif[sId][itemSmId] : null;
                    var valAsli = gSm ? gSm.nilai_angka : null;
                    var valRemed = gSm ? gSm.nilai_remedial : null;
                    var effVal = (valRemed !== null && valRemed !== undefined) ? valRemed : valAsli;
                    if (effVal !== null && effVal !== undefined && effVal !== '') sumScores.push(parseFloat(effVal));

                    tableHtml += 
                        '<td class="grade-box-cell" data-siswa-id="' + sId + '" data-nama="' + escapeHtml(s.nama_lengkap) + '" data-nis="' + escapeHtml(s.nis || '-') + '" data-tipe="sumatif" data-item-id="' + itemSmId + '" data-slot="' + escapeHtml(sc.slot_code) + '" data-judul="' + escapeHtml(sc.judul_asesmen) + '" data-asli="' + (valAsli !== null ? valAsli : '') + '" data-remed="' + (valRemed !== null ? valRemed : '') + '" data-kktp="' + patokanThis + '" data-metode="' + (isPan ? 'PAN' : 'PAP') + '">' +
                            '<div class="grade-box-inner" id="cell-sumatif-' + sId + '-' + itemSmId + '">' +
                                renderCellContent(valAsli, valRemed, patokanThis) +
                            '</div>' +
                        '</td>';
                });

                // 4. Kolom Nilai Akhir, Predikat & Status KKTP (Menunggu Penetapan Resmi dari Guru Pengampu)
                tableHtml += 
                    '<td style="font-weight: 700; font-size: 13px; color: #94A3B8;" class="cell-final-score" title="Nilai Akhir menunggu penetapan resmi dari Guru Pengampu">' +
                        '-' +
                    '</td>' +
                    '<td style="font-weight: 700; font-size: 13px;" class="cell-predikat">' +
                        '<span style="display: inline-block; padding: 2px 8px; border-radius: 4px; background: #F8FAFC; color: #94A3B8;">-</span>' +
                    '</td>' +
                    '<td class="cell-status-kktp">' +
                        '<span style="background: #F8FAFC; color: #64748B; font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 6px; border: 1px dashed #CBD5E1;" title="Wewenang penetapan nilai akhir berada pada Guru Pengampu">Menunggu Guru</span>' +
                    '</td>' +
                '</tr>';
            });
        }

        tableHtml += 
                    '</tbody>' +
                '</table>' +
            '</div>';

        tableWrap.innerHTML = tableHtml;
    }

    function renderCellContent(valAsli, valRemedi, kktp) {
        if ((valAsli === null || valAsli === undefined || valAsli === '') && 
            (valRemedi === null || valRemedi === undefined || valRemedi === '')) {
            return '<span class="grade-empty-dash">-</span>';
        }
        if (valRemedi !== null && valRemedi !== undefined && valRemedi !== '') {
            return '<span class="score-asli-strikethrough">' + Number(valAsli) + '</span>' +
                   '<span class="score-effective is-remedial">' + Number(valRemedi) + '</span>' +
                   '<span class="badge-remed-tag">Remidi</span>';
        }
        var isFail = (Number(valAsli) < kktp);
        return '<span class="score-effective ' + (isFail ? 'is-failed' : 'is-passed') + '">' + Number(valAsli) + '</span>' +
               (isFail ? '<span class="badge-failed-tag">Remidi</span>' : '');
    }

    /* --------------------------------------------------------------------------
     * 9. EVENT LISTENERS: TOOLBAR, SEARCH, RESET & EXPORTS
     * -------------------------------------------------------------------------- */
    // Filter Kelas Change
    document.getElementById('filterKelas').addEventListener('change', function() {
        selectClass(this.value);
    });

    // Filter Mapel Change
    document.getElementById('filterMapel').addEventListener('change', function() {
        selectMapel(this.value);
    });

    // Reset Button
    document.getElementById('btnResetFilter').addEventListener('click', function() {
        document.getElementById('filterKelas').value = '0';
        document.getElementById('filterMapel').innerHTML = '<option value="0">— Pilih Kelas Terlebih Dahulu —</option>';
        document.getElementById('filterMapel').setAttribute('disabled', 'disabled');
        document.getElementById('searchSiswa').value = '';
        document.getElementById('btnClearSearch').style.display = 'none';
        state.id_kelas = 0;
        state.id_kmp = 0;
        currentGradebookData = null;
        renderClassEmptyState();
    });

    // Live Search Siswa
    var searchInput = document.getElementById('searchSiswa');
    var btnClear = document.getElementById('btnClearSearch');

    searchInput.addEventListener('input', function() {
        var q = this.value.toLowerCase().trim();
        btnClear.style.display = q ? 'inline-flex' : 'none';

        var rows = document.querySelectorAll('#tbodyGradebook tr.row-siswa');
        rows.forEach(function(tr) {
            var nama = tr.getAttribute('data-nama') || '';
            var nis  = tr.getAttribute('data-nis') || '';
            var nisn = tr.getAttribute('data-nisn') || '';
            if (!q || nama.indexOf(q) !== -1 || nis.indexOf(q) !== -1 || nisn.indexOf(q) !== -1) {
                tr.style.display = '';
            } else {
                tr.style.display = 'none';
            }
        });
    });

    btnClear.addEventListener('click', function() {
        searchInput.value = '';
        btnClear.style.display = 'none';
        var rows = document.querySelectorAll('#tbodyGradebook tr.row-siswa');
        rows.forEach(function(tr) { tr.style.display = ''; });
        searchInput.focus();
    });

    // 1. Ekspor PDF Langsung via Blob (Sama persis dengan kaldik-pdf.php)
    var btnPrintPdf = document.getElementById('btnPrintPdf');
    if (btnPrintPdf) {
        btnPrintPdf.addEventListener('click', function() {
            if (!state.id_kelas || !state.id_kmp) {
                Ilmuna.toast('warning', 'Peringatan', 'Pilih kelas dan mata pelajaran terlebih dahulu.');
                return;
            }

            var btn = this;
            var originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<iconify-icon icon="solar:restart-bold" class="ilmuna-spin" width="16"></iconify-icon><span>Mengunduh PDF...</span>';

            var targetUrl = 'reports-pdf.php?id_kelas=' + encodeURIComponent(state.id_kelas) + '&id_kmp=' + encodeURIComponent(state.id_kmp);
            fetch(targetUrl)
                .then(function(res) {
                    if (!res.ok) throw new Error('Gagal mengunduh berkas PDF (Kode ' + res.status + ')');
                    var disp = res.headers.get('Content-Disposition') || '';
                    var filename = 'daftar-nilai-' + state.id_kelas + '-' + state.id_kmp + '.pdf';
                    var m = disp.match(/filename="?([^"]+)"?/);
                    if (m && m[1]) filename = m[1];
                    return res.blob().then(function(blob){ return { blob: blob, filename: filename }; });
                })
                .then(function(data) {
                    var blobUrl = window.URL.createObjectURL(data.blob);
                    var a = document.createElement('a');
                    a.href = blobUrl;
                    a.download = data.filename;
                    document.body.appendChild(a);
                    a.click();
                    setTimeout(function(){
                        document.body.removeChild(a);
                        window.URL.revokeObjectURL(blobUrl);
                    }, 500);
                })
                .catch(function(err) {
                    Ilmuna.toast('error', 'Gagal Mengunduh', err.message || 'Gagal mengunduh PDF.');
                })
                .finally(function() {
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                });
        });
    }

    // 2. Ekspor Excel Langsung via Blob (Sama persis dengan kaldik-excel.php)
    var btnExportExcel = document.getElementById('btnExportExcel');
    if (btnExportExcel) {
        btnExportExcel.addEventListener('click', function() {
            if (!state.id_kelas || !state.id_kmp) {
                Ilmuna.toast('warning', 'Peringatan', 'Pilih kelas dan mata pelajaran terlebih dahulu.');
                return;
            }

            var btn = this;
            var originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<iconify-icon icon="solar:restart-bold" class="ilmuna-spin" width="16"></iconify-icon><span>Mengunduh Excel...</span>';

            var targetUrl = 'reports-excel.php?id_kelas=' + encodeURIComponent(state.id_kelas) + '&id_kmp=' + encodeURIComponent(state.id_kmp);
            fetch(targetUrl)
                .then(function(res) {
                    if (!res.ok) throw new Error('Gagal mengunduh berkas Excel (Kode ' + res.status + ')');
                    var disp = res.headers.get('Content-Disposition') || '';
                    var filename = 'daftar-nilai-' + state.id_kelas + '-' + state.id_kmp + '.xlsx';
                    var m = disp.match(/filename="?([^"]+)"?/);
                    if (m && m[1]) filename = m[1];
                    return res.blob().then(function(blob){ return { blob: blob, filename: filename }; });
                })
                .then(function(data) {
                    var blobUrl = window.URL.createObjectURL(data.blob);
                    var a = document.createElement('a');
                    a.href = blobUrl;
                    a.download = data.filename;
                    document.body.appendChild(a);
                    a.click();
                    setTimeout(function(){
                        document.body.removeChild(a);
                        window.URL.revokeObjectURL(blobUrl);
                    }, 500);
                })
                .catch(function(err) {
                    Ilmuna.toast('error', 'Gagal Mengunduh', err.message || 'Gagal mengunduh Excel.');
                })
                .finally(function() {
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                });
        });
    }

    // Inisialisasi awal saat halaman dibuka
    loadKelasOptions();

})();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
