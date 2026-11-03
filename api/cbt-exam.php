<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — NATIVE CBT EXAM BROWSER REST API
 * ==============================================================================
 * Endpoint khusus untuk aplikasi native Android Exam Browser.
 * Mengelola:
 * 1. Autentikasi Kredensial Akses Asesmen (Sumatif & Formatif)
 * 2. Pengambilan Butir Soal & Opsi (asesmen_sumatif_soal & asesmen_formatif_soal)
 * 3. Autosave Jawaban Siswa secara Realtime (cbt_jawaban_siswa)
 * 4. Sinkronisasi Sisa Waktu Detik (cbt_sesi_ujian)
 * 5. Kumpul & Penilaian Otomatis (nilai_asesmen_sumatif & nilai_asesmen_formatif)
 * 6. Keamanan Kiosk & Freeze Sesi saat Pelanggaran Keluar Aplikasi
 * ==============================================================================
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

date_default_timezone_set('Asia/Jakarta');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../includes/essay_evaluator.php';

try {
    $pdo = getDBConnection();
    $pdo->exec("SET time_zone = '+07:00'");

    // Pastikan kolom & ENUM pendukung Remedial/Pengayaan tersedia di tabel terkait (jalankan sekali saja via flag file agar tidak memicu metadata lock saat request paralel)
    $schemaFlagFile = sys_get_temp_dir() . '/ilmuna_cbt_remedial_schema_v2.flag';
    if (!is_file($schemaFlagFile)) {
        try {
            $colPr = $pdo->query("SHOW COLUMNS FROM `pengayaan_remedial` LIKE 'durasi_menit'")->fetch();
            if (!$colPr) {
                $pdo->exec("ALTER TABLE `pengayaan_remedial` 
                    ADD COLUMN `durasi_menit` INT(11) NOT NULL DEFAULT 60 AFTER `petunjuk_pelaksanaan`,
                    ADD COLUMN `tanggal_pelaksanaan` DATE DEFAULT NULL AFTER `durasi_menit`,
                    ADD COLUMN `jam_mulai` TIME DEFAULT NULL AFTER `tanggal_pelaksanaan`,
                    ADD COLUMN `jam_selesai` TIME DEFAULT NULL AFTER `jam_mulai`");
            }
            $colPrSoal = $pdo->query("SHOW COLUMNS FROM `pengayaan_remedial_soal` LIKE 'link_video'")->fetch();
            if (!$colPrSoal) {
                $pdo->exec("ALTER TABLE `pengayaan_remedial_soal` ADD COLUMN `link_video` VARCHAR(255) DEFAULT NULL AFTER `gambar_stimulus`");
            }
            $pdo->exec("ALTER TABLE `cbt_sesi_ujian` MODIFY COLUMN `tipe_asesmen` ENUM('diagnostik', 'formatif', 'sumatif', 'remedial') NOT NULL DEFAULT 'sumatif'");
            $pdo->exec("ALTER TABLE `cbt_sesi_ujian` MODIFY COLUMN `status_sesi` ENUM('Belum Mulai', 'Sedang Mengerjakan', 'Terkunci / Freeze', 'Selesai', 'Izin Keluar', 'Dibuka Ulang') NOT NULL DEFAULT 'Sedang Mengerjakan'");
            $pdo->exec("ALTER TABLE `cbt_jawaban_siswa` MODIFY COLUMN `tipe_asesmen` ENUM('diagnostik', 'formatif', 'sumatif', 'remedial') NOT NULL DEFAULT 'sumatif'");
            $pdo->exec("ALTER TABLE `cbt_jawaban_siswa` MODIFY COLUMN `tipe_soal` ENUM('Pilihan Ganda', 'Pilihan Ganda Kompleks', 'Essay / Uraian', 'Benar Salah', 'Menjodohkan', 'Tugas Terbimbing', 'Studi Kasus Khusus') NOT NULL DEFAULT 'Pilihan Ganda'");
            $pdo->exec("ALTER TABLE `cbt_log_pelanggaran_exambro` MODIFY COLUMN `tipe_asesmen` ENUM('diagnostik', 'formatif', 'sumatif', 'remedial') NOT NULL DEFAULT 'sumatif'");
            @file_put_contents($schemaFlagFile, date('c'));
        } catch (Throwable $ignore) {}
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Koneksi database gagal: ' . $e->getMessage()
    ]);
    exit;
}

$action = $_REQUEST['action'] ?? '';

// ------------------------------------------------------------------------------
// 1. LOGIN AKSES ASESMEN (KREDENSIAL KARTU UJIAN SISWA)
// ------------------------------------------------------------------------------
if ($action === 'login') {
    $identifier = trim($_REQUEST['identifier'] ?? '');
    $password   = trim($_REQUEST['password'] ?? '');
    $tokenInput = trim($_REQUEST['token'] ?? '');

    if (empty($identifier) || empty($password)) {
        echo json_encode([
            'success' => false,
            'message' => 'Silakan masukkan Nomor Peserta / Username Ujian dan Password Anda.'
        ]);
        exit;
    }

    $tiket = null;
    $tipeAsesmen = 'sumatif';

    // 1.1 Cek Sumatif (Klaster 13)
    $sqlSumatif = "
        SELECT 
            aas.id AS id_akses, 'sumatif' AS tipe_asesmen,
            aas.id_asesmen_sumatif AS id_asesmen,
            aas.id_siswa,
            COALESCE(
                NULLIF(aas.id_kelas_mata_pelajaran, 0),
                (SELECT ask3.id_kelas_mata_pelajaran 
                 FROM asesmen_sumatif_kelas ask3 
                 JOIN kelas_mata_pelajaran kmp3 ON ask3.id_kelas_mata_pelajaran = kmp3.id 
                 WHERE ask3.id_asesmen_sumatif = asum.id AND kmp3.id_kelas = s.id_kelas LIMIT 1),
                asum.id_kelas_mata_pelajaran,
                0
            ) AS id_kelas_mata_pelajaran,
            aas.nomor_peserta, aas.username_ujian, aas.password_ujian, aas.token_sesi,
            aas.status_akses, aas.sisa_waktu_detik,
            s.nama_lengkap AS nama_siswa, s.nis, s.nisn, s.foto, s.id_kelas,
            k.nama_kelas,
            COALESCE(
                mp.nama_mata_pelajaran,
                (SELECT mp2.nama_mata_pelajaran 
                 FROM asesmen_sumatif_kelas ask2 
                 JOIN kelas_mata_pelajaran kmp2 ON ask2.id_kelas_mata_pelajaran = kmp2.id 
                 JOIN mata_pelajaran mp2 ON kmp2.id_mata_pelajaran = mp2.id 
                 WHERE ask2.id_asesmen_sumatif = asum.id LIMIT 1),
                'Pendidikan Agama Islam'
            ) AS nama_pelajaran,
            asum.judul_asesmen, asum.jenis_sumatif AS subtipe, asum.durasi_menit, asum.kktp, asum.petunjuk_pengerjaan,
            COALESCE(aas.semester, asum.semester, 'Ganjil') AS semester,
            COALESCE(aas.tahun_pelajaran, asum.tahun_pelajaran, '2026/2027') AS tahun_pelajaran,
            asum.tanggal_pelaksanaan, asum.jam_mulai, asum.jam_selesai
        FROM akses_asesmen_sumatif aas
        JOIN siswa s ON aas.id_siswa = s.id
        JOIN kelas k ON s.id_kelas = k.id
        JOIN asesmen_sumatif asum ON aas.id_asesmen_sumatif = asum.id
        LEFT JOIN kelas_mata_pelajaran kmp ON aas.id_kelas_mata_pelajaran = kmp.id
        LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
        WHERE (aas.username_ujian = ? OR aas.nomor_peserta = ?)
        ORDER BY (CASE 
            WHEN ? != '' AND UPPER(COALESCE(aas.token_sesi, '')) = UPPER(?) THEN 1 
            WHEN ? = '' THEN 2 
            ELSE 3 
        END) ASC, aas.id DESC
        LIMIT 10
    ";
    $stmt = $pdo->prepare($sqlSumatif);
    $stmt->execute([$identifier, $identifier, $tokenInput, $tokenInput, $tokenInput]);
    $candidatesSum = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Cari kandidat yang password dan token-nya paling cocok
    foreach ($candidatesSum as $c) {
        $pMatch = ($c['password_ujian'] === $password || password_verify($password, $c['password_ujian']));
        $tMatch = (empty($tokenInput) || empty($c['token_sesi']) || strtoupper($tokenInput) === strtoupper($c['token_sesi']));
        if ($pMatch && $tMatch) {
            $tiket = $c;
            $tipeAsesmen = 'sumatif';
            break;
        }
    }
    if (!$tiket) {
        foreach ($candidatesSum as $c) {
            $pMatch = ($c['password_ujian'] === $password || password_verify($password, $c['password_ujian']));
            if ($pMatch) {
                $tiket = $c;
                $tipeAsesmen = 'sumatif';
                break;
            }
        }
    }
    if (!$tiket && !empty($candidatesSum)) {
        $tiket = $candidatesSum[0];
        $tipeAsesmen = 'sumatif';
    }

    if (!$tiket) {
        // 1.2 Cek Formatif (Klaster 12)
        $sqlFormatif = "
            SELECT 
                aaf.id AS id_akses, 'formatif' AS tipe_asesmen,
                aaf.id_asesmen_formatif AS id_asesmen,
                aaf.id_siswa, aaf.id_kelas_mata_pelajaran,
                aaf.nomor_peserta, aaf.username_ujian, aaf.password_ujian, aaf.token_sesi,
                aaf.status_akses, aaf.sisa_waktu_detik,
                s.nama_lengkap AS nama_siswa, s.nis, s.nisn, s.foto, s.id_kelas,
                k.nama_kelas,
                COALESCE(mp.nama_mata_pelajaran, 'Mata Pelajaran') AS nama_pelajaran,
                af.judul_asesmen, af.teknik_asesmen AS subtipe, af.durasi_menit, 75.00 AS kktp, af.petunjuk_pengerjaan,
                af.tanggal_pelaksanaan, af.jam_mulai, af.jam_selesai
            FROM akses_asesmen_formatif aaf
            JOIN siswa s ON aaf.id_siswa = s.id
            JOIN kelas k ON s.id_kelas = k.id
            JOIN asesmen_formatif af ON aaf.id_asesmen_formatif = af.id
            JOIN kelas_mata_pelajaran kmp ON aaf.id_kelas_mata_pelajaran = kmp.id
            LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            WHERE (aaf.username_ujian = ? OR aaf.nomor_peserta = ?)
            ORDER BY aaf.id DESC
            LIMIT 5
        ";
        $stmtF = $pdo->prepare($sqlFormatif);
        $stmtF->execute([$identifier, $identifier]);
        $candidatesFmt = $stmtF->fetchAll(PDO::FETCH_ASSOC);
        foreach ($candidatesFmt as $c) {
            $pMatch = ($c['password_ujian'] === $password || password_verify($password, $c['password_ujian']));
            if ($pMatch) {
                $tiket = $c;
                $tipeAsesmen = 'formatif';
                break;
            }
        }
        if (!$tiket && !empty($candidatesFmt)) {
            $tiket = $candidatesFmt[0];
            $tipeAsesmen = 'formatif';
        }
    }

    if (!$tiket) {
        // 1.3 Cek Diagnostik (Klaster 11)
        $sqlDiagnostik = "
            SELECT 
                aad.id AS id_akses, 'diagnostik' AS tipe_asesmen,
                aad.id_asesmen_diagnostik AS id_asesmen,
                aad.id_siswa, aad.id_kelas_mata_pelajaran,
                aad.nomor_peserta, aad.username_ujian, aad.password_ujian, aad.token_sesi,
                aad.status_akses, aad.sisa_waktu_detik,
                s.nama_lengkap AS nama_siswa, s.nis, s.nisn, s.foto, s.id_kelas,
                k.nama_kelas,
                COALESCE(mp.nama_mata_pelajaran, 'Mata Pelajaran') AS nama_pelajaran,
                ad.judul_asesmen, ad.jenis_diagnostik AS subtipe, ad.durasi_menit, 75.00 AS kktp, ad.petunjuk_pengerjaan,
                ad.tanggal_pelaksanaan, ad.jam_mulai, ad.jam_selesai
            FROM akses_asesmen_diagnostik aad
            JOIN siswa s ON aad.id_siswa = s.id
            JOIN kelas k ON s.id_kelas = k.id
            JOIN asesmen_diagnostik ad ON aad.id_asesmen_diagnostik = ad.id
            JOIN kelas_mata_pelajaran kmp ON aad.id_kelas_mata_pelajaran = kmp.id
            LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            WHERE (aad.username_ujian = ? OR aad.nomor_peserta = ?)
            ORDER BY aad.id DESC
            LIMIT 5
        ";
        $stmtD = $pdo->prepare($sqlDiagnostik);
        $stmtD->execute([$identifier, $identifier]);
        $candidatesDgn = $stmtD->fetchAll(PDO::FETCH_ASSOC);
        foreach ($candidatesDgn as $c) {
            $pMatch = ($c['password_ujian'] === $password || password_verify($password, $c['password_ujian']));
            if ($pMatch) {
                $tiket = $c;
                $tipeAsesmen = 'diagnostik';
                break;
            }
        }
        if (!$tiket && !empty($candidatesDgn)) {
            $tiket = $candidatesDgn[0];
            $tipeAsesmen = 'diagnostik';
        }
    }

    if (!$tiket) {
        // 1.4 Cek Remedial / Pengayaan (Klaster 14) - Wajib sesuai kelas siswa (s.id_kelas = kmp.id_kelas)
        ensureRemedialDatabaseTriggers($pdo);
        $sqlRemedial = "
            SELECT 
                apr.id AS id_akses, 'remedial' AS tipe_asesmen,
                apr.id_pengayaan_remedial AS id_asesmen,
                apr.id_siswa,
                COALESCE(NULLIF(apr.id_kelas_mata_pelajaran, 0), pr.id_kelas_mata_pelajaran, 0) AS id_kelas_mata_pelajaran,
                apr.nomor_peserta, apr.username_ujian, apr.password_ujian, apr.token_sesi,
                apr.status_akses, apr.sisa_waktu_detik,
                s.nama_lengkap AS nama_siswa, s.nis, s.nisn, s.foto, s.id_kelas,
                k.nama_kelas,
                COALESCE(mp.nama_mata_pelajaran, 'Mata Pelajaran') AS nama_pelajaran,
                pr.judul_program AS judul_asesmen,
                pr.kategori AS subtipe,
                COALESCE(NULLIF(pr.durasi_menit, 0), 60) AS durasi_menit,
                COALESCE(NULLIF(pr.kktp_minimal, 0), apr.kktp_acuan, 75.00) AS kktp,
                pr.petunjuk_pelaksanaan AS petunjuk_pengerjaan,
                pr.tanggal_pelaksanaan, pr.jam_mulai, pr.jam_selesai
            FROM akses_pengayaan_remedial apr
            JOIN siswa s ON apr.id_siswa = s.id
            JOIN kelas k ON s.id_kelas = k.id
            JOIN pengayaan_remedial pr ON apr.id_pengayaan_remedial = pr.id
            JOIN kelas_mata_pelajaran kmp ON pr.id_kelas_mata_pelajaran = kmp.id AND s.id_kelas = kmp.id_kelas
            LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            WHERE (apr.username_ujian = ? OR apr.nomor_peserta = ?)
            ORDER BY (CASE 
                WHEN ? != '' AND UPPER(COALESCE(apr.token_sesi, '')) = UPPER(?) THEN 1 
                WHEN ? = '' THEN 2 
                ELSE 3 
            END) ASC, apr.id DESC
            LIMIT 10
        ";
        $stmtR = $pdo->prepare($sqlRemedial);
        $stmtR->execute([$identifier, $identifier, $tokenInput, $tokenInput, $tokenInput]);
        $candidatesRem = $stmtR->fetchAll(PDO::FETCH_ASSOC);

        foreach ($candidatesRem as $c) {
            $pMatch = ($c['password_ujian'] === $password || password_verify($password, $c['password_ujian']));
            $tMatch = (empty($tokenInput) || empty($c['token_sesi']) || strtoupper($tokenInput) === strtoupper($c['token_sesi']));
            if ($pMatch && $tMatch) {
                $tiket = $c;
                $tipeAsesmen = 'remedial';
                break;
            }
        }
        if (!$tiket) {
            foreach ($candidatesRem as $c) {
                $pMatch = ($c['password_ujian'] === $password || password_verify($password, $c['password_ujian']));
                if ($pMatch) {
                    $tiket = $c;
                    $tipeAsesmen = 'remedial';
                    break;
                }
            }
        }
        if (!$tiket && !empty($candidatesRem)) {
            $tiket = $candidatesRem[0];
            $tipeAsesmen = 'remedial';
        }

        // Validasi kelayakan kelas & nilai < KKTP untuk Remedial / Pengayaan
        if ($tiket && $tipeAsesmen === 'remedial' && ($tiket['status_akses'] ?? '') !== 'Selesai') {
            $eligibleCheck = getEligibleRemedialStudents($pdo, (int)$tiket['id_asesmen'], (int)$tiket['id_siswa']);
            if (empty($eligibleCheck)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Akses ditolak! Program remedial/pengayaan ini tidak sesuai dengan kelas atau asesmen Anda, atau nilai Anda tidak memenuhi syarat (nilai < KKTP).'
                ]);
                exit;
            }
        }
    }

    if (!$tiket) {
        echo json_encode([
            'success' => false,
            'message' => 'Kredensial tidak ditemukan. Pastikan Nomor Peserta / Username Ujian Anda benar.'
        ]);
        exit;
    }

    // Validasi Password
    $passValid = ($tiket['password_ujian'] === $password || password_verify($password, $tiket['password_ujian']));
    if (!$passValid) {
        echo json_encode([
            'success' => false,
            'message' => 'Password / PIN Ujian salah! Silakan periksa kembali kartu ujian Anda.'
        ]);
        exit;
    }

    // Validasi Jadwal Pelaksanaan Ujian (Hari/Tanggal & Jam Mulai - Selesai WIB)
    if (!empty($tiket['tanggal_pelaksanaan'])) {
        $today = date('Y-m-d');
        $nowTime = date('H:i:s');
        $tglPelaksanaan = $tiket['tanggal_pelaksanaan'];

        if ($today < $tglPelaksanaan) {
            $tglIndo = date('d-m-Y', strtotime($tglPelaksanaan));
            $jmMulai = !empty($tiket['jam_mulai']) ? substr($tiket['jam_mulai'], 0, 5) : '07:30';
            echo json_encode([
                'success' => false,
                'message' => "Ujian ini belum dimulai! Sesi dijadwalkan pada hari/tanggal {$tglIndo} pukul {$jmMulai} WIB."
            ]);
            exit;
        } elseif ($today > $tglPelaksanaan) {
            $tglIndo = date('d-m-Y', strtotime($tglPelaksanaan));
            echo json_encode([
                'success' => false,
                'message' => "Jadwal pelaksanaan ujian telah terlewat (Pelaksanaan: {$tglIndo}). Silakan hubungi Proktor / Guru Pengawas."
            ]);
            exit;
        } else {
            // Hari ini - Cek jam mulai & jam selesai (WIB)
            if (!empty($tiket['jam_mulai']) && $nowTime < $tiket['jam_mulai']) {
                $jmMulai = substr($tiket['jam_mulai'], 0, 5);
                echo json_encode([
                    'success' => false,
                    'message' => "Sesi ujian belum dibuka! Akses dimulai pada pukul {$jmMulai} WIB."
                ]);
                exit;
            }
            if (!empty($tiket['jam_selesai']) && $nowTime >= $tiket['jam_selesai']) {
                $jmSelesai = substr($tiket['jam_selesai'], 0, 5);
                echo json_encode([
                    'success' => false,
                    'message' => "Waktu pelaksanaan ujian telah berakhir pada pukul {$jmSelesai} WIB."
                ]);
                exit;
            }
        }
    }

    // Validasi Token Sesi jika diperlukan
    if (!empty($tiket['token_sesi'])) {
        if (empty($tokenInput) || strtoupper($tokenInput) !== strtoupper($tiket['token_sesi'])) {
            echo json_encode([
                'success' => false,
                'is_token_error' => true,
                'message' => 'Token sesi ujian tidak cocok! Silakan minta token aktif kepada Pengawas / Proktor ujian.'
            ]);
            exit;
        }
    }

    // Validasi Absensi Khusus Ujian Sumatif
    // Siswa hanya diizinkan mengerjakan ujian sumatif jika sudah melakukan absensi (status Hadir / Terlambat).
    // Asesmen formatif & diagnostik tidak memerlukan absensi (bebas masuk).
    if ($tipeAsesmen === 'sumatif') {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `absensi_asesmen_sumatif` (
                `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_asesmen_sumatif` INT(11) UNSIGNED NOT NULL,
                `nomor_peserta` VARCHAR(50) NOT NULL,
                `id_siswa` INT(11) UNSIGNED NOT NULL,
                `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL,
                `status_kehadiran` ENUM('Hadir','Izin','Sakit','Alpha','Terlambat') NOT NULL DEFAULT 'Hadir',
                `metode_absen` ENUM('Scan QR','Manual') NOT NULL DEFAULT 'Scan QR',
                `waktu_absen` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `diabsen_oleh` INT(11) DEFAULT NULL,
                `keterangan` TEXT DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unique_aasum_ujian_siswa` (`id_asesmen_sumatif`, `id_siswa`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $stmtAbsen = $pdo->prepare("
                SELECT id, status_kehadiran, waktu_absen 
                FROM absensi_asesmen_sumatif 
                WHERE id_asesmen_sumatif = ? AND id_siswa = ? 
                LIMIT 1
            ");
            $stmtAbsen->execute([(int)$tiket['id_asesmen'], (int)$tiket['id_siswa']]);
            $dataAbsen = $stmtAbsen->fetch(PDO::FETCH_ASSOC);

            if (!$dataAbsen) {
                echo json_encode([
                    'success' => false,
                    'is_attendance_required' => true,
                    'message' => 'Anda belum dapat mengerjakan ujian sumatif ini karena belum melakukan absensi. Silakan temui Guru / Pengawas Ujian untuk melakukan absensi kehadiran terlebih dahulu.'
                ]);
                exit;
            }

            if (!in_array($dataAbsen['status_kehadiran'], ['Hadir', 'Terlambat'], true)) {
                $statusTeks = $dataAbsen['status_kehadiran'];
                echo json_encode([
                    'success' => false,
                    'is_attendance_required' => true,
                    'message' => "Anda tidak dapat mengerjakan ujian sumatif ini karena status kehadiran Anda saat ini tercatat: {$statusTeks}. Silakan hubungi Guru / Pengawas Ujian jika memerlukan penyesuaian."
                ]);
                exit;
            }
        } catch (Exception $e) {
            error_log("Error validasi absensi sumatif: " . $e->getMessage());
        }
    }

    // Cek Status Freeze & Kunci di Sesi CBT (waktu tetap berjalan meskipun sesi sedang Terkunci / Freeze)
    $stmtSesi = $pdo->prepare("
        SELECT id, status_sesi, sisa_waktu_detik, is_frozen, is_locked_admin, alasan_freeze,
               CASE
                   WHEN status_sesi IN ('Sedang Mengerjakan', 'Terkunci / Freeze')
                        AND sisa_waktu_detik > 0
                        AND COALESCE(updated_at, waktu_freeze, waktu_mulai) IS NOT NULL THEN
                       GREATEST(0, CAST(sisa_waktu_detik AS SIGNED) - TIMESTAMPDIFF(SECOND, COALESCE(updated_at, waktu_freeze, waktu_mulai), NOW()))
                   ELSE sisa_waktu_detik
               END AS sisa_waktu_berjalan
        FROM cbt_sesi_ujian 
        WHERE tipe_asesmen = ? AND id_asesmen = ? AND id_siswa = ? 
        LIMIT 1
    ");
    $stmtSesi->execute([$tipeAsesmen, $tiket['id_asesmen'], $tiket['id_siswa']]);
    $sesiCbt = $stmtSesi->fetch(PDO::FETCH_ASSOC);

    // Cek jika sesi dibuka ulang (Remedial Ulang / Ujian Ulang oleh Guru/Admin)
    $isReopened = ($sesiCbt && $sesiCbt['status_sesi'] === 'Dibuka Ulang');

    // Jika sesi sedang berjalan atau Freeze namun sisa waktu berjalan sudah habis (<= 0), otomatis selesaikan & nilai
    if ($sesiCbt && !$isReopened && $sesiCbt['status_sesi'] !== 'Selesai' && isset($sesiCbt['sisa_waktu_berjalan']) && (int)$sesiCbt['sisa_waktu_berjalan'] <= 0) {
        autoProcessFinishAndGrade($pdo, (int)$sesiCbt['id'], $tipeAsesmen, (int)$tiket['id_asesmen'], (int)$tiket['id_siswa'], (int)$tiket['id_kelas_mata_pelajaran']);
        echo json_encode([
            'success' => false,
            'is_completed' => true,
            'message' => 'Waktu pengerjaan ujian Anda telah habis (termasuk selama sesi terkunci/freeze). Jawaban Anda telah dikumpulkan dan dinilai secara otomatis.'
        ]);
        exit;
    }

    // Cek jika sudah selesai (dan tidak sedang dibuka ulang)
    if (!$isReopened && ($tiket['status_akses'] === 'Selesai' || ($sesiCbt && $sesiCbt['status_sesi'] === 'Selesai'))) {
        echo json_encode([
            'success' => false,
            'is_completed' => true,
            'message' => 'Anda telah menyelesaikan ujian ini. Jawaban telah tersimpan permanen dan tidak dapat diulang.'
        ]);
        exit;
    }

    // Cek jika akun sedang dibekukan / terkunci
    if (($sesiCbt && ((int)$sesiCbt['is_frozen'] === 1 || (int)$sesiCbt['is_locked_admin'] === 1)) || $tiket['status_akses'] === 'Terkunci') {
        $alasan = $sesiCbt['alasan_freeze'] ?? 'Keluar dari aplikasi ujian / kehilangan fokus layar';
        echo json_encode([
            'success' => false,
            'is_frozen' => true,
            'message' => "Sesi Anda DIBEKUKAN (Terkunci)! Terdeteksi: $alasan. Silakan temui Pengawas Ujian untuk membuka kunci (Unfreeze) melalui Dashboard Akses Asesmen."
        ]);
        exit;
    }

    // Hitung Sisa Waktu Detik (Dipotong otomatis sesuai keterlambatan dari jam_mulai)
    $durasiMenit = max(1, (int)($tiket['durasi_menit'] ?: 60));
    $sisaDetik = $durasiMenit * 60;

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    if (!$sesiCbt) {
        // Jika siswa terlambat N detik dari jam_mulai (pada hari H):
        if (!empty($tiket['jam_mulai'])) {
            $jamMulaiTs = strtotime(date('Y-m-d') . ' ' . $tiket['jam_mulai']);
            $nowTs = time();
            if ($nowTs > $jamMulaiTs) {
                $terlambatDetik = $nowTs - $jamMulaiTs;
                // Potong durasi pengerjaan sebesar detik keterlambatan
                $sisaDetik = max(0, $sisaDetik - $terlambatDetik);
            }
        }

        // Batasi sisa detik agar tidak melebihi jam_selesai jika diset
        if (!empty($tiket['jam_selesai'])) {
            $jamSelesaiTs = strtotime(date('Y-m-d') . ' ' . $tiket['jam_selesai']);
            $sisaSampaiSelesai = max(0, $jamSelesaiTs - time());
            $sisaDetik = min($sisaDetik, $sisaSampaiSelesai);
        }

        if ($sisaDetik <= 0) {
            echo json_encode([
                'success' => false,
                'message' => 'Waktu pengerjaan ujian telah berakhir.'
            ]);
            exit;
        }

        $insSql = "
            INSERT INTO cbt_sesi_ujian 
            (tipe_asesmen, id_asesmen, id_siswa, id_kelas_mata_pelajaran, nomor_peserta, token_sesi, status_sesi, sisa_waktu_detik, waktu_mulai, is_frozen, is_locked_admin, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, 'Sedang Mengerjakan', ?, NOW(), 0, 0, ?, ?)
        ";
        $pdo->prepare($insSql)->execute([
            $tipeAsesmen, $tiket['id_asesmen'], $tiket['id_siswa'], $tiket['id_kelas_mata_pelajaran'],
            $tiket['nomor_peserta'], $tokenInput, $sisaDetik, $ip, $ua
        ]);
        $idSesiCbt = (int)$pdo->lastInsertId();
    } else {
        // Sesi sudah ada di database, gunakan sisa_waktu_berjalan (yang tetap terhitung berjalan selama freeze/keluar) kecuali jika dibuka ulang
        if (!$isReopened && isset($sesiCbt['sisa_waktu_berjalan']) && (int)$sesiCbt['sisa_waktu_berjalan'] > 0) {
            $sisaDetik = (int)$sesiCbt['sisa_waktu_berjalan'];
        } elseif (!$isReopened && $sesiCbt['sisa_waktu_detik'] > 0) {
            $sisaDetik = (int)$sesiCbt['sisa_waktu_detik'];
        }

        // KETATKAN DENGAN BATAS JAM SELESAI PADA HARI H
        if (!empty($tiket['jam_selesai'])) {
            $jamSelesaiTs = strtotime(date('Y-m-d') . ' ' . $tiket['jam_selesai']);
            $sisaSampaiSelesai = max(0, $jamSelesaiTs - time());
            $sisaDetik = min($sisaDetik, $sisaSampaiSelesai);
        }

        if ($sisaDetik <= 0) {
            echo json_encode([
                'success' => false,
                'message' => 'Waktu pelaksanaan ujian telah berakhir.'
            ]);
            exit;
        }

        $idSesiCbt = (int)$sesiCbt['id'];
        $pdo->prepare("
            UPDATE cbt_sesi_ujian SET 
                status_sesi = 'Sedang Mengerjakan',
                sisa_waktu_detik = ?,
                is_frozen = 0,
                is_locked_admin = 0,
                ip_address = ?,
                user_agent = ?
            WHERE id = ?
        ")->execute([$sisaDetik, $ip, $ua, $idSesiCbt]);
    }

    // Update status di tabel akses master
    $tabelAkses = ($tipeAsesmen === 'sumatif') ? 'akses_asesmen_sumatif' : (($tipeAsesmen === 'diagnostik') ? 'akses_asesmen_diagnostik' : (($tipeAsesmen === 'remedial') ? 'akses_pengayaan_remedial' : 'akses_asesmen_formatif'));
    $pdo->prepare("UPDATE `$tabelAkses` SET status_akses = 'Sedang Mengerjakan', sisa_waktu_detik = ?, waktu_mulai = IFNULL(waktu_mulai, NOW()), ip_address = ?, user_agent = ? WHERE id = ?")
        ->execute([$sisaDetik, $ip, $ua, $tiket['id_akses']]);

    recordCbtEvent();

    $fotoRaw = $tiket['foto'] ?? '';
    $fotoUrl = null;
    if (!empty($fotoRaw)) {
        if (preg_match('/^https?:\/\//i', $fotoRaw)) {
            $fotoUrl = $fotoRaw;
        } else {
            $rootUrl = preg_replace('#/api/?.*$#', '', rtrim(BASE_URL, '/'));
            $fotoPath = ltrim($fotoRaw, '/');
            if (strpos($fotoPath, 'uploads/') === false && strpos($fotoPath, 'assets/') === false) {
                $fotoPath = 'uploads/siswa/' . $fotoPath;
            }
            $fotoUrl = $rootUrl . '/' . $fotoPath;
        }
    }

    $isPan = isAssessmentPAN($tipeAsesmen, $tiket['subtipe'] ?? '');
    $metodePenilaian = $isPan ? 'PAN' : 'PAP';
    $labelAcuan = $isPan ? 'Norma Rata-rata Kelas' : 'KKTP: ' . number_format((float)$tiket['kktp'], 0);

    echo json_encode([
        'success' => true,
        'message' => 'Login berhasil!',
        'session' => [
            'id_cbt_sesi'             => $idSesiCbt,
            'id_akses'                => (int)$tiket['id_akses'],
            'tipe_asesmen'            => $tipeAsesmen,
            'id_asesmen'              => (int)$tiket['id_asesmen'],
            'id_siswa'                => (int)$tiket['id_siswa'],
            'id_kelas_mata_pelajaran' => (int)$tiket['id_kelas_mata_pelajaran'],
            'nomor_peserta'           => $tiket['nomor_peserta'],
            'username_ujian'          => $tiket['username_ujian'],
            'nama_siswa'              => $tiket['nama_siswa'],
            'nis'                     => $tiket['nis'],
            'nisn'                    => $tiket['nisn'],
            'foto'                    => $fotoUrl ?: $fotoRaw,
            'nama_kelas'              => $tiket['nama_kelas'],
            'nama_pelajaran'          => $tiket['nama_pelajaran'],
            'judul_asesmen'           => $tiket['judul_asesmen'],
            'subtipe'                 => $tiket['subtipe'],
            'durasi_menit'            => $durasiMenit,
            'kktp'                    => (float)$tiket['kktp'],
            'metode_penilaian'        => $metodePenilaian,
            'label_acuan'             => $labelAcuan,
            'sisa_waktu_detik'        => $sisaDetik,
            'petunjuk_pengerjaan'     => $tiket['petunjuk_pengerjaan'] ?? null,
            'tanggal_pelaksanaan'     => $tiket['tanggal_pelaksanaan'] ?? null,
            'jam_mulai'               => $tiket['jam_mulai'] ?? null,
            'jam_selesai'             => $tiket['jam_selesai'] ?? null
        ]
    ]);
    exit;
}

// ------------------------------------------------------------------------------
// 2. AMBIL BUTIR SOAL & JAWABAN TERSIMPAN (GET QUESTIONS)
// ------------------------------------------------------------------------------
if ($action === 'get_questions') {
    $idSesiUjian = (int)($_REQUEST['id_cbt_sesi'] ?? 0);
    $tipeAsesmen = in_array($_REQUEST['tipe_asesmen'] ?? '', ['sumatif', 'formatif', 'diagnostik', 'remedial'], true) ? $_REQUEST['tipe_asesmen'] : 'sumatif';
    $idAsesmen   = (int)($_REQUEST['id_asesmen'] ?? 0);
    $idSiswa     = (int)($_REQUEST['id_siswa'] ?? 0);

    if (!$idAsesmen || !$idSiswa) {
        echo json_encode(['success' => false, 'message' => 'Parameter tidak lengkap.']);
        exit;
    }

    // Validasi Absensi Khusus Ujian Sumatif (Defense-in-depth)
    if ($tipeAsesmen === 'sumatif') {
        try {
            $stmtAbsenQ = $pdo->prepare("
                SELECT id, status_kehadiran 
                FROM absensi_asesmen_sumatif 
                WHERE id_asesmen_sumatif = ? AND id_siswa = ? 
                LIMIT 1
            ");
            $stmtAbsenQ->execute([$idAsesmen, $idSiswa]);
            $dataAbsenQ = $stmtAbsenQ->fetch(PDO::FETCH_ASSOC);

            if (!$dataAbsenQ || !in_array($dataAbsenQ['status_kehadiran'], ['Hadir', 'Terlambat'], true)) {
                $statusTeks = $dataAbsenQ ? $dataAbsenQ['status_kehadiran'] : 'Belum Absen';
                echo json_encode([
                    'success' => false,
                    'is_attendance_required' => true,
                    'message' => "Akses butir soal ditolak! Anda belum melakukan absensi atau status kehadiran ({$statusTeks}) tidak memenuhi syarat untuk ujian sumatif ini."
                ]);
                exit;
            }
        } catch (Exception $e) {
            error_log("Error validasi absensi get_questions: " . $e->getMessage());
        }
    }

    if ($tipeAsesmen === 'sumatif') {
        $tabelSoal = 'asesmen_sumatif_soal';
        $tabelOpsi = 'asesmen_sumatif_soal_opsi';
        $fkAsesmen = 'id_asesmen_sumatif';
    } elseif ($tipeAsesmen === 'formatif') {
        $tabelSoal = 'asesmen_formatif_soal';
        $tabelOpsi = 'asesmen_formatif_soal_opsi';
        $fkAsesmen = 'id_asesmen_formatif';
    } elseif ($tipeAsesmen === 'remedial') {
        $stmtChkRemQ = $pdo->prepare("
            SELECT apr.id
            FROM akses_pengayaan_remedial apr
            JOIN siswa s ON apr.id_siswa = s.id
            JOIN pengayaan_remedial pr ON apr.id_pengayaan_remedial = pr.id
            JOIN kelas_mata_pelajaran kmp ON pr.id_kelas_mata_pelajaran = kmp.id
            WHERE apr.id_pengayaan_remedial = ? AND apr.id_siswa = ? AND s.id_kelas = kmp.id_kelas
            LIMIT 1
        ");
        $stmtChkRemQ->execute([$idAsesmen, $idSiswa]);
        if (!$stmtChkRemQ->fetchColumn()) {
            echo json_encode([
                'success' => false,
                'message' => 'Akses butir soal ditolak! Program remedial/pengayaan ini tidak sesuai dengan kelas atau asesmen Anda.'
            ]);
            exit;
        }
        $tabelSoal = 'pengayaan_remedial_soal';
        $tabelOpsi = 'pengayaan_remedial_soal_opsi';
        $fkAsesmen = 'id_pengayaan_remedial';
    } else {
        $tabelSoal = 'asesmen_diagnostik_soal';
        $tabelOpsi = 'asesmen_diagnostik_soal_opsi';
        $fkAsesmen = 'id_asesmen_diagnostik';
    }

    // Ambil Butir Soal
    $stmtSoal = $pdo->prepare("
        SELECT *
        FROM `$tabelSoal`
        WHERE `$fkAsesmen` = ?
        ORDER BY nomor_soal ASC, id ASC
    ");
    $stmtSoal->execute([$idAsesmen]);
    $rawSoal = $stmtSoal->fetchAll(PDO::FETCH_ASSOC);

    // Ambil Opsi Pilihan Jawaban
    $stmtOpsi = $pdo->prepare("
        SELECT o.id, o.id_soal, o.kode_opsi, o.teks_opsi, o.gambar_opsi
        FROM `$tabelOpsi` o
        JOIN `$tabelSoal` s ON o.id_soal = s.id
        WHERE s.`$fkAsesmen` = ?
        ORDER BY o.kode_opsi ASC
    ");
    $stmtOpsi->execute([$idAsesmen]);
    $rawOpsi = $stmtOpsi->fetchAll(PDO::FETCH_ASSOC);

    $opsiBySoal = [];
    foreach ($rawOpsi as $op) {
        $opsiBySoal[$op['id_soal']][] = [
            'id'          => (int)$op['id'],
            'id_soal'     => (int)$op['id_soal'],
            'kode_opsi'   => $op['kode_opsi'],
            'teks_opsi'   => $op['teks_opsi'],
            'gambar_opsi' => $op['gambar_opsi']
        ];
    }

    // Ambil Jawaban Tersimpan (jika siswa pernah menjawab/resume)
    $stmtJwb = $pdo->prepare("
        SELECT id_soal, id_opsi_dipilih, kode_opsi_dipilih, jawaban_teks, is_ragu
        FROM cbt_jawaban_siswa
        WHERE tipe_asesmen = ? AND id_asesmen = ? AND id_siswa = ?
    ");
    $stmtJwb->execute([$tipeAsesmen, $idAsesmen, $idSiswa]);
    $rawJwb = $stmtJwb->fetchAll(PDO::FETCH_ASSOC);

    $jawabanMap = [];
    foreach ($rawJwb as $jw) {
        $jawabanMap[$jw['id_soal']] = [
            'id_opsi_dipilih'   => $jw['id_opsi_dipilih'] ? (int)$jw['id_opsi_dipilih'] : null,
            'kode_opsi_dipilih' => $jw['kode_opsi_dipilih'],
            'jawaban_teks'      => $jw['jawaban_teks'],
            'is_ragu'           => (int)$jw['is_ragu']
        ];
    }

    // Susun Struktur Soal Lengkap
    $soalFormatted = [];
    foreach ($rawSoal as $idx => $s) {
        $sId = (int)$s['id'];
        $imgStimulus = $s['gambar_stimulus'] ?? '';
        $imgUrl = null;
        if (!empty($imgStimulus)) {
            if (preg_match('/^https?:\/\//i', $imgStimulus)) {
                $imgUrl = $imgStimulus;
            } else {
                $rootUrl = preg_replace('#/api/?$#', '', rtrim(BASE_URL, '/'));
                $imgUrl = $rootUrl . '/' . ltrim($imgStimulus, '/');
            }
        }

        $soalFormatted[] = [
            'id'                  => $sId,
            'nomor_urut'          => $idx + 1,
            'nomor_soal'          => (int)$s['nomor_soal'],
            'tipe_soal'           => $s['tipe_soal'],
            'pertanyaan'          => $s['pertanyaan'],
            'gambar_stimulus'     => $imgStimulus ?: null,
            'gambar_stimulus_url' => $imgUrl,
            'link_video'          => !empty($s['link_video']) ? trim($s['link_video']) : null,
            'bobot_skor'          => (float)$s['bobot_skor'],
            'opsi'                => $opsiBySoal[$sId] ?? [],
            'jawaban_tersimpan'   => $jawabanMap[$sId] ?? null
        ];
    }

    echo json_encode([
        'success'    => true,
        'total_soal' => count($soalFormatted),
        'soal'       => $soalFormatted
    ]);
    exit;
}

/**
 * HELPER: SIMPAN SATU BUTIR JAWABAN SISWA KE cbt_jawaban_siswa DENGAN AMAN
 */
function saveSingleCbtAnswer($pdo, array $p) {
    $idSesiUjian = (int)($p['id_cbt_sesi'] ?? 0);
    $tipeAsesmen = in_array($p['tipe_asesmen'] ?? '', ['sumatif', 'formatif', 'diagnostik', 'remedial'], true) ? $p['tipe_asesmen'] : 'sumatif';
    $idAsesmen   = (int)($p['id_asesmen'] ?? 0);
    $idSiswa     = (int)($p['id_siswa'] ?? 0);
    $idSoal      = (int)($p['id_soal'] ?? 0);
    $nomorUrut   = max(1, (int)($p['nomor_urut'] ?? 1));
    $tipeSoalRaw = trim($p['tipe_soal'] ?? 'Pilihan Ganda');
    $idOpsi      = isset($p['id_opsi']) && $p['id_opsi'] !== '' && $p['id_opsi'] !== null ? (int)$p['id_opsi'] : null;
    $kodeOpsi    = trim($p['kode_opsi'] ?? '');
    $jawabanTeks = isset($p['jawaban_teks']) ? trim((string)$p['jawaban_teks']) : null;
    $isRagu      = (int)($p['is_ragu'] ?? 0);

    if (!$idSoal || !$idAsesmen || !$idSiswa) {
        return false;
    }

    // Pastikan id_sesi_ujian valid di tabel cbt_sesi_ujian agar tidak terkena Foreign Key Error 1452 (fk_cj_sesi)
    $validSesiId = null;
    if ($idSesiUjian > 0) {
        $stmtChkSesi = $pdo->prepare("SELECT id FROM cbt_sesi_ujian WHERE id = ? LIMIT 1");
        $stmtChkSesi->execute([$idSesiUjian]);
        $foundId = (int)$stmtChkSesi->fetchColumn();
        if ($foundId > 0) {
            $validSesiId = $foundId;
        }
    }
    if ($validSesiId === null) {
        $stmtFindSesi = $pdo->prepare("SELECT id FROM cbt_sesi_ujian WHERE tipe_asesmen = ? AND id_asesmen = ? AND id_siswa = ? ORDER BY id DESC LIMIT 1");
        $stmtFindSesi->execute([$tipeAsesmen, $idAsesmen, $idSiswa]);
        $foundId = (int)$stmtFindSesi->fetchColumn();
        if ($foundId > 0) {
            $validSesiId = $foundId;
        }
    }

    if ($tipeAsesmen === 'sumatif') {
        $tabelSoal   = 'asesmen_sumatif_soal';
        $tabelOpsi   = 'asesmen_sumatif_soal_opsi';
        $tabelRubrik = 'asesmen_sumatif_soal_rubrik';
    } elseif ($tipeAsesmen === 'formatif') {
        $tabelSoal   = 'asesmen_formatif_soal';
        $tabelOpsi   = 'asesmen_formatif_soal_opsi';
        $tabelRubrik = 'asesmen_formatif_soal_rubrik';
    } elseif ($tipeAsesmen === 'remedial') {
        $tabelSoal   = 'pengayaan_remedial_soal';
        $tabelOpsi   = 'pengayaan_remedial_soal_opsi';
        $tabelRubrik = 'pengayaan_remedial_soal_rubrik';
    } else {
        $tabelSoal   = 'asesmen_diagnostik_soal';
        $tabelOpsi   = 'asesmen_diagnostik_soal_opsi';
        $tabelRubrik = 'asesmen_diagnostik_soal_rubrik';
    }

    $stmtSoalMeta = $pdo->prepare("SELECT bobot_skor, tipe_soal FROM `$tabelSoal` WHERE id = ? LIMIT 1");
    $stmtSoalMeta->execute([$idSoal]);
    $soalMeta = $stmtSoalMeta->fetch(PDO::FETCH_ASSOC);
    $bobotMaks = (float)($soalMeta['bobot_skor'] ?? 10.00);
    if ($bobotMaks <= 0) $bobotMaks = 10.00;

    // Gunakan tipe_soal dari DB soal jika tersedia agar selalu sesuai ENUM
    $tipeSoal = !empty($soalMeta['tipe_soal']) ? $soalMeta['tipe_soal'] : $tipeSoalRaw;
    $allowedTipeSoal = ['Pilihan Ganda', 'Pilihan Ganda Kompleks', 'Essay / Uraian', 'Benar Salah', 'Menjodohkan', 'Tugas Terbimbing', 'Studi Kasus Khusus'];
    if (!in_array($tipeSoal, $allowedTipeSoal, true)) {
        $tsLow = strtolower($tipeSoal);
        if (strpos($tsLow, 'essay') !== false || strpos($tsLow, 'uraian') !== false) {
            $tipeSoal = 'Essay / Uraian';
        } elseif (strpos($tsLow, 'tugas') !== false) {
            $tipeSoal = 'Tugas Terbimbing';
        } elseif (strpos($tsLow, 'studi kasus') !== false) {
            $tipeSoal = 'Studi Kasus Khusus';
        } elseif (strpos($tsLow, 'kompleks') !== false) {
            $tipeSoal = 'Pilihan Ganda Kompleks';
        } elseif (strpos($tsLow, 'benar') !== false) {
            $tipeSoal = 'Benar Salah';
        } elseif (strpos($tsLow, 'jodoh') !== false) {
            $tipeSoal = 'Menjodohkan';
        } else {
            $tipeSoal = 'Pilihan Ganda';
        }
    }

    $isBenar = null;
    $skorPeroleh = null;
    $tipeSoalLower = strtolower($tipeSoal);
    $isTextBasedSoal = (
        strpos($tipeSoalLower, 'essay') !== false ||
        strpos($tipeSoalLower, 'uraian') !== false ||
        strpos($tipeSoalLower, 'tugas') !== false ||
        strpos($tipeSoalLower, 'studi kasus') !== false ||
        ($idOpsi === null && $jawabanTeks !== null && strpos($tipeSoalLower, 'pilihan ganda') === false)
    );

    if (!$isTextBasedSoal && ($idOpsi || !empty($kodeOpsi))) {
        if ($idOpsi) {
            $stmtCekOpsi = $pdo->prepare("SELECT id, is_kunci_jawaban, kode_opsi, bobot_nilai_opsi FROM `$tabelOpsi` WHERE id = ? LIMIT 1");
            $stmtCekOpsi->execute([$idOpsi]);
        } else {
            $stmtCekOpsi = $pdo->prepare("SELECT id, is_kunci_jawaban, kode_opsi, bobot_nilai_opsi FROM `$tabelOpsi` WHERE id_soal = ? AND UPPER(kode_opsi) = UPPER(?) LIMIT 1");
            $stmtCekOpsi->execute([$idSoal, $kodeOpsi]);
        }
        $opsiInfo = $stmtCekOpsi->fetch(PDO::FETCH_ASSOC);

        if ($opsiInfo) {
            $idOpsi = (int)$opsiInfo['id'];
            $isBenar = ((int)$opsiInfo['is_kunci_jawaban'] === 1) ? 1 : 0;
            $skorPeroleh = $isBenar ? $bobotMaks : 0.00;
            if (!empty($opsiInfo['kode_opsi'])) {
                $kodeOpsi = $opsiInfo['kode_opsi'];
            }
        }
    } elseif ($isTextBasedSoal) {
        // Proteksi agar perpindahan soal Essay di APK lama (setText("") memicu TextWatcher lama) tidak menghapus jawaban_teks yang sudah tersimpan
        if ($jawabanTeks === null || $jawabanTeks === '') {
            $stmtPrev = $pdo->prepare("SELECT jawaban_teks FROM cbt_jawaban_siswa WHERE tipe_asesmen = ? AND id_asesmen = ? AND id_soal = ? AND id_siswa = ? LIMIT 1");
            $stmtPrev->execute([$tipeAsesmen, $idAsesmen, $idSoal, $idSiswa]);
            $prevTeks = $stmtPrev->fetchColumn();
            if ($prevTeks !== false && trim((string)$prevTeks) !== '') {
                $jawabanTeks = trim((string)$prevTeks);
            }
        }

        $teksTrim = trim((string)$jawabanTeks);
        $catatanKoreksi = null;
        if ($teksTrim === '') {
            $skorPeroleh = 0.00;
            $isBenar = 0;
            $catatanKoreksi = "[Evaluasi Rubrik AI] Jawaban kosong / belum diisi siswa. (Skor 0.00)";
        } else {
            $stmtRub = $pdo->prepare("SELECT * FROM `$tabelRubrik` WHERE id_soal = ? LIMIT 1");
            $stmtRub->execute([$idSoal]);
            $rub = $stmtRub->fetch(PDO::FETCH_ASSOC) ?: [];

            // Evaluasi cerdas berbasis kecerdasan rubrik multi-tier (Maksimal, Sedang, Minimal, Nol)
            $evalResult = IlmunaEssayEvaluator::evaluate($teksTrim, $rub, $bobotMaks);
            $skorPeroleh = $evalResult['skor_diperoleh'];
            $isBenar = $evalResult['is_benar'];
            $catatanKoreksi = $evalResult['catatan_koreksi'];
        }
    }

    $sqlSave = "
        INSERT INTO cbt_jawaban_siswa 
        (id_sesi_ujian, tipe_asesmen, id_asesmen, id_soal, id_siswa, nomor_urut_tampil, tipe_soal, id_opsi_dipilih, kode_opsi_dipilih, jawaban_teks, is_ragu, is_benar, skor_bobot_maks, skor_diperoleh, catatan_koreksi_guru, waktu_simpan)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE 
            id_sesi_ujian = COALESCE(VALUES(id_sesi_ujian), id_sesi_ujian),
            tipe_soal = VALUES(tipe_soal),
            id_opsi_dipilih = COALESCE(VALUES(id_opsi_dipilih), id_opsi_dipilih),
            kode_opsi_dipilih = COALESCE(VALUES(kode_opsi_dipilih), kode_opsi_dipilih),
            jawaban_teks = CASE 
                WHEN VALUES(jawaban_teks) IS NOT NULL AND TRIM(VALUES(jawaban_teks)) != '' THEN VALUES(jawaban_teks)
                ELSE COALESCE(jawaban_teks, VALUES(jawaban_teks))
            END,
            is_ragu = VALUES(is_ragu),
            is_benar = COALESCE(VALUES(is_benar), is_benar),
            skor_bobot_maks = VALUES(skor_bobot_maks),
            skor_diperoleh = COALESCE(VALUES(skor_diperoleh), skor_diperoleh),
            catatan_koreksi_guru = CASE 
                WHEN VALUES(catatan_koreksi_guru) IS NOT NULL THEN VALUES(catatan_koreksi_guru)
                ELSE catatan_koreksi_guru
            END,
            waktu_simpan = NOW()
    ";
    $pdo->prepare($sqlSave)->execute([
        $validSesiId, $tipeAsesmen, $idAsesmen, $idSoal, $idSiswa, $nomorUrut, $tipeSoal,
        $idOpsi, ($kodeOpsi !== '' ? $kodeOpsi : null), $jawabanTeks, $isRagu, $isBenar, $bobotMaks, $skorPeroleh, $catatanKoreksi
    ]);

    return $validSesiId;
}

/**
 * HELPER: HITUNG SKOR DARI cbt_jawaban_siswa & SIMPAN LANGSUNG KE TABEL NILAI MASTER
 * (Termasuk nilai_pengayaan_remedial untuk remedial/pengayaan secara real-time maupun saat selesai)
 */
function recalculateAndSaveExamScore($pdo, $tipeAsesmen, $idAsesmen, $idSiswa, $kmpId = 0) {
    if (!$idAsesmen || !$idSiswa) return false;

    $prMeta = null;
    if ($tipeAsesmen === 'sumatif') {
        $tabelAsesmen = 'asesmen_sumatif';
        $kktp = (float)($pdo->query("SELECT kktp FROM asesmen_sumatif WHERE id = " . (int)$idAsesmen)->fetchColumn() ?: 75.00);
    } elseif ($tipeAsesmen === 'formatif') {
        $tabelAsesmen = 'asesmen_formatif';
        $kktp = (float)($pdo->query("SELECT kktp FROM asesmen_formatif WHERE id = " . (int)$idAsesmen)->fetchColumn() ?: 75.00);
    } elseif ($tipeAsesmen === 'remedial') {
        $tabelAsesmen = 'pengayaan_remedial';
        $stmtPrMeta = $pdo->prepare("SELECT id, id_kelas_mata_pelajaran, id_guru, id_modul_ajar, id_tp, id_itp, pertemuan_ke, kategori, judul_program, kktp_minimal FROM pengayaan_remedial WHERE id = ? LIMIT 1");
        $stmtPrMeta->execute([$idAsesmen]);
        $prMeta = $stmtPrMeta->fetch(PDO::FETCH_ASSOC) ?: [];
        $kktp = (float)($prMeta['kktp_minimal'] ?? 75.00);
        if ($kktp <= 0) $kktp = 75.00;
    } else {
        $tabelAsesmen = 'asesmen_diagnostik';
        $kktp = 75.00;
    }

    if ($kmpId <= 0) {
        $tbAkses = ($tipeAsesmen === 'sumatif') ? 'akses_asesmen_sumatif' : (($tipeAsesmen === 'diagnostik') ? 'akses_asesmen_diagnostik' : (($tipeAsesmen === 'remedial') ? 'akses_pengayaan_remedial' : 'akses_asesmen_formatif'));
        $colFk   = ($tipeAsesmen === 'sumatif') ? 'id_asesmen_sumatif' : (($tipeAsesmen === 'diagnostik') ? 'id_asesmen_diagnostik' : (($tipeAsesmen === 'remedial') ? 'id_pengayaan_remedial' : 'id_asesmen_formatif'));
        $stmtKmp = $pdo->prepare("SELECT id_kelas_mata_pelajaran FROM `$tbAkses` WHERE `$colFk` = ? AND id_siswa = ? LIMIT 1");
        $stmtKmp->execute([$idAsesmen, $idSiswa]);
        $kmpId = (int)$stmtKmp->fetchColumn();

        if ($kmpId <= 0) {
            $kmpId = (int)$pdo->query("SELECT id_kelas_mata_pelajaran FROM `$tabelAsesmen` WHERE id = " . (int)$idAsesmen)->fetchColumn();
        }

        if ($kmpId <= 0) {
            $stmtKmpFallback = $pdo->prepare("
                SELECT kmp.id
                FROM kelas_mata_pelajaran kmp
                JOIN siswa s ON s.id_kelas = kmp.id_kelas
                WHERE s.id = ?
                LIMIT 1
            ");
            $stmtKmpFallback->execute([$idSiswa]);
            $kmpId = (int)$stmtKmpFallback->fetchColumn();
        }
    }

    // 1. Evaluasi ulang jawaban esai / tugas berdasarkan Rubrik Semantik Multi-Tier
    $tbRubrikRecalc = ($tipeAsesmen === 'sumatif') ? 'asesmen_sumatif_soal_rubrik' : (($tipeAsesmen === 'formatif') ? 'asesmen_formatif_soal_rubrik' : (($tipeAsesmen === 'remedial') ? 'pengayaan_remedial_soal_rubrik' : 'asesmen_diagnostik_soal_rubrik'));
    $stmtEssayJawaban = $pdo->prepare("
        SELECT id, id_soal, jawaban_teks, skor_bobot_maks
        FROM cbt_jawaban_siswa
        WHERE tipe_asesmen = ? AND id_asesmen = ? AND id_siswa = ?
          AND (LOWER(tipe_soal) LIKE '%essay%' OR LOWER(tipe_soal) LIKE '%uraian%' OR LOWER(tipe_soal) LIKE '%tugas%' OR LOWER(tipe_soal) LIKE '%studi kasus%')
    ");
    $stmtEssayJawaban->execute([$tipeAsesmen, $idAsesmen, $idSiswa]);
    $essayRows = $stmtEssayJawaban->fetchAll(PDO::FETCH_ASSOC);

    foreach ($essayRows as $er) {
        $idJwb = (int)$er['id'];
        $idSoalEr = (int)$er['id_soal'];
        $teksEr = trim((string)$er['jawaban_teks']);
        $bobotMaksEr = (float)($er['skor_bobot_maks'] > 0 ? $er['skor_bobot_maks'] : 10.00);

        $stmtRubEr = $pdo->prepare("SELECT * FROM `$tbRubrikRecalc` WHERE id_soal = ? LIMIT 1");
        $stmtRubEr->execute([$idSoalEr]);
        $rubDataEr = $stmtRubEr->fetch(PDO::FETCH_ASSOC) ?: [];

        $evalEr = IlmunaEssayEvaluator::evaluate($teksEr, $rubDataEr, $bobotMaksEr);

        $stmtUpdEssay = $pdo->prepare("
            UPDATE cbt_jawaban_siswa
            SET skor_diperoleh = ?,
                is_benar = ?,
                catatan_koreksi_guru = ?
            WHERE id = ?
        ");
        $stmtUpdEssay->execute([
            $evalEr['skor_diperoleh'],
            $evalEr['is_benar'],
            $evalEr['catatan_koreksi'],
            $idJwb
        ]);
    }

    // 2. Hitung total skor dari jawaban (berdasarkan total bobot seluruh butir soal pada paket ujian)
    $tabelSoalFinish = ($tipeAsesmen === 'sumatif') ? 'asesmen_sumatif_soal' : (($tipeAsesmen === 'formatif') ? 'asesmen_formatif_soal' : (($tipeAsesmen === 'remedial') ? 'pengayaan_remedial_soal' : 'asesmen_diagnostik_soal'));
    $fkSoalFinish    = ($tipeAsesmen === 'sumatif') ? 'id_asesmen_sumatif' : (($tipeAsesmen === 'formatif') ? 'id_asesmen_formatif' : (($tipeAsesmen === 'remedial') ? 'id_pengayaan_remedial' : 'id_asesmen_diagnostik'));

    $stmtTotalPaket = $pdo->prepare("SELECT SUM(COALESCE(bobot_skor, 10)) FROM `$tabelSoalFinish` WHERE `$fkSoalFinish` = ?");
    $stmtTotalPaket->execute([$idAsesmen]);
    $totalBobotPaket = (float)$stmtTotalPaket->fetchColumn();

    $stmtSkorAll = $pdo->prepare("
        SELECT
            SUM(COALESCE(skor_diperoleh, 0)) AS total_skor_diperoleh,
            SUM(COALESCE(skor_bobot_maks, 10)) AS total_bobot_maksimal
        FROM cbt_jawaban_siswa
        WHERE tipe_asesmen = ? AND id_asesmen = ? AND id_siswa = ?
    ");
    $stmtSkorAll->execute([$tipeAsesmen, $idAsesmen, $idSiswa]);
    $dataSkor = $stmtSkorAll->fetch(PDO::FETCH_ASSOC);

    $skorDiperoleh = (float)($dataSkor['total_skor_diperoleh'] ?? 0.00);
    $totalMaksimal = ($totalBobotPaket > 0) ? $totalBobotPaket : (float)($dataSkor['total_bobot_maksimal'] ?? 100.00);

    $nilaiAkhir = ($totalMaksimal > 0) ? round(($skorDiperoleh / $totalMaksimal) * 100, 2) : 0.00;

    // Evaluasi Ambang Batas Kelulusan: PAP (KKTP) vs PAN (Rata-rata Kelas)
    $stmtSwKelas = $pdo->prepare("SELECT id_kelas FROM siswa WHERE id = ? LIMIT 1");
    $stmtSwKelas->execute([$idSiswa]);
    $idKelasSiswa = (int)$stmtSwKelas->fetchColumn();

    $isPanSumatif = false;
    $ambangKelulusan = $kktp;
    $catatanEvaluasi = 'Hasil pengerjaan mandiri melalui aplikasi Ilmuna Exam Browser CBT.';

    if ($tipeAsesmen === 'sumatif') {
        $stmtMetaSm = $pdo->prepare("SELECT kktp, jenis_sumatif, judul_asesmen FROM asesmen_sumatif WHERE id = ? LIMIT 1");
        $stmtMetaSm->execute([(int)$idAsesmen]);
        $smMeta = $stmtMetaSm->fetch(PDO::FETCH_ASSOC) ?: [];
        $jenisSumatif = $smMeta['jenis_sumatif'] ?? 'Sumatif Lingkup Materi';
        $isPanSumatif = isAssessmentPAN('sumatif', $jenisSumatif);

        if ($isPanSumatif) {
            // Asesmen STS & SAS: 100% PAN (Penilaian Acuan Norma - Rata-rata Kelas)
            $stmtAvgLain = $pdo->prepare("
                SELECT AVG(nas.nilai_angka) AS mean_lain, COUNT(*) AS count_lain
                FROM nilai_asesmen_sumatif nas
                JOIN siswa s ON nas.id_siswa = s.id
                WHERE nas.id_asesmen_sumatif = ? AND s.id_kelas = ? AND nas.id_siswa != ? AND nas.nilai_angka IS NOT NULL
            ");
            $stmtAvgLain->execute([(int)$idAsesmen, $idKelasSiswa, $idSiswa]);
            $avgLainData = $stmtAvgLain->fetch(PDO::FETCH_ASSOC);
            $countLain = (int)($avgLainData['count_lain'] ?? 0);
            $meanLain = (float)($avgLainData['mean_lain'] ?? 0);

            $totalSiswaSelesai = $countLain + 1;
            $rataRataKelas = round((($meanLain * $countLain) + $nilaiAkhir) / $totalSiswaSelesai, 2);
            if ($rataRataKelas <= 0) $rataRataKelas = $kktp;

            $ambangKelulusan = $rataRataKelas;
            $statusTuntas = ($nilaiAkhir >= $ambangKelulusan) ? 'Tuntas' : 'Belum Tuntas';
            $catatanEvaluasi = "Hasil penilaian CBT berbasis Penilaian Acuan Norma (PAN). Batas kelulusan rata-rata kelas: {$ambangKelulusan}. Nilai siswa: {$nilaiAkhir}.";
        } else {
            // Asesmen Sumatif Lingkup Materi: 100% PAP (KKTP)
            $ambangKelulusan = $kktp;
            $statusTuntas = ($nilaiAkhir >= $ambangKelulusan) ? 'Tuntas' : 'Belum Tuntas';
            $catatanEvaluasi = "Hasil asesmen sumatif lingkup materi berbasis Penilaian Acuan Patokan (PAP). Target KKTP: {$kktp}. Nilai siswa: {$nilaiAkhir}.";
        }
    } elseif ($tipeAsesmen === 'formatif') {
        // Asesmen Formatif: 100% PAP (KKTP)
        $ambangKelulusan = $kktp;
        $statusTuntas = ($nilaiAkhir >= $ambangKelulusan) ? 'Tuntas' : 'Belum Tuntas';
        $catatanEvaluasi = "Hasil asesmen formatif mandiri berbasis Penilaian Acuan Patokan (PAP). Target KKTP: {$kktp}. Nilai siswa: {$nilaiAkhir}.";
    } else {
        $statusTuntas = ($nilaiAkhir >= $kktp) ? 'Tuntas' : 'Belum Tuntas';
    }

    $predikat = 'C';
    if ($nilaiAkhir >= 90) $predikat = 'A';
    elseif ($nilaiAkhir >= 80) $predikat = 'B';
    elseif ($nilaiAkhir >= 70) $predikat = 'C';
    elseif ($nilaiAkhir >= 60) $predikat = 'D';
    else $predikat = 'E';

    // 3. Simpan nilai ke tabel nilai master
    if ($tipeAsesmen === 'sumatif') {
        $sqlNilai = "
            INSERT INTO nilai_asesmen_sumatif
            (id_siswa, id_kelas_mata_pelajaran, id_asesmen_sumatif, pertemuan_ke, nilai_angka, nilai_kktp, predikat, status_tuntas, catatan_evaluasi, waktu_penilaian)
            VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                nilai_angka = VALUES(nilai_angka),
                predikat = VALUES(predikat),
                status_tuntas = VALUES(status_tuntas),
                catatan_evaluasi = VALUES(catatan_evaluasi),
                waktu_penilaian = NOW()
        ";
        $pdo->prepare($sqlNilai)->execute([$idSiswa, $kmpId, $idAsesmen, $nilaiAkhir, $ambangKelulusan, $predikat, $statusTuntas, $catatanEvaluasi]);

        // Rekalkulasi & sinkronisasi norma kelas bagi siswa lain jika asesmen STS / SAS (PAN)
        if ($isPanSumatif && $idKelasSiswa > 0) {
            recalculateClassPANStatus($pdo, $idAsesmen, $idKelasSiswa);
        }
    } elseif ($tipeAsesmen === 'formatif') {
        $sqlNilai = "
            INSERT INTO nilai_asesmen_formatif
            (id_siswa, id_kelas_mata_pelajaran, id_asesmen_formatif, pertemuan_ke, nilai_angka, nilai_kktp, predikat, status_tuntas, catatan_evaluasi, waktu_penilaian)
            VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                nilai_angka = VALUES(nilai_angka),
                predikat = VALUES(predikat),
                status_tuntas = VALUES(status_tuntas),
                catatan_evaluasi = VALUES(catatan_evaluasi),
                waktu_penilaian = NOW()
        ";
        $pdo->prepare($sqlNilai)->execute([$idSiswa, $kmpId, $idAsesmen, $nilaiAkhir, $ambangKelulusan, $predikat, $statusTuntas, $catatanEvaluasi]);
    } elseif ($tipeAsesmen === 'remedial') {
        $kategoriPr = $prMeta['kategori'] ?? 'Remedial';
        if ($kategoriPr === 'Pengayaan') {
            $statusTuntasRem = ($nilaiAkhir >= $kktp) ? 'Pengayaan Berhasil' : 'Belum Tuntas';
        } else {
            $statusTuntasRem = ($nilaiAkhir >= $kktp) ? 'Tuntas Remedial' : 'Belum Tuntas';
        }
        $statusTuntas = $statusTuntasRem;

        // Ambil info tiket akses remedial untuk mendapatkan nilai_awal & relasi nilai sumber
        $stmtAprInfo = $pdo->prepare("
            SELECT id_nilai_sumatif, id_nilai_formatif, id_nilai_diagnostik,
                   snapshot_nilai_sumatif, snapshot_nilai_formatif, snapshot_nilai_diagnostik
            FROM akses_pengayaan_remedial
            WHERE id_pengayaan_remedial = ? AND id_siswa = ?
            LIMIT 1
        ");
        $stmtAprInfo->execute([$idAsesmen, $idSiswa]);
        $aprInfo = $stmtAprInfo->fetch(PDO::FETCH_ASSOC) ?: [];

        $nilaiAwalSnap = null;
        if (!empty($aprInfo['snapshot_nilai_sumatif']) && (float)$aprInfo['snapshot_nilai_sumatif'] > 0) {
            $nilaiAwalSnap = (float)$aprInfo['snapshot_nilai_sumatif'];
        } elseif (!empty($aprInfo['snapshot_nilai_formatif']) && (float)$aprInfo['snapshot_nilai_formatif'] > 0) {
            $nilaiAwalSnap = (float)$aprInfo['snapshot_nilai_formatif'];
        } elseif (!empty($aprInfo['snapshot_nilai_diagnostik']) && (float)$aprInfo['snapshot_nilai_diagnostik'] > 0) {
            $nilaiAwalSnap = (float)$aprInfo['snapshot_nilai_diagnostik'];
        }

        // Jika snapshot di akses_pengayaan_remedial kosong, cari nilai_angka dari tabel nilai asesmen di kelas_mata_pelajaran ini
        if ($nilaiAwalSnap === null && $kmpId > 0) {
            $stmtInitFmt = $pdo->prepare("SELECT nilai_angka FROM nilai_asesmen_formatif WHERE id_siswa = ? AND id_kelas_mata_pelajaran = ? ORDER BY waktu_penilaian DESC LIMIT 1");
            $stmtInitFmt->execute([$idSiswa, $kmpId]);
            $valFmt = $stmtInitFmt->fetchColumn();
            if ($valFmt !== false && (float)$valFmt > 0) {
                $nilaiAwalSnap = (float)$valFmt;
            } else {
                $stmtInitSum = $pdo->prepare("SELECT nilai_angka FROM nilai_asesmen_sumatif WHERE id_siswa = ? AND id_kelas_mata_pelajaran = ? ORDER BY waktu_penilaian DESC LIMIT 1");
                $stmtInitSum->execute([$idSiswa, $kmpId]);
                $valSum = $stmtInitSum->fetchColumn();
                if ($valSum !== false && (float)$valSum > 0) {
                    $nilaiAwalSnap = (float)$valSum;
                }
            }
        }

        // Cek apakah sudah ada baris di nilai_pengayaan_remedial untuk siswa & program ini
        $stmtCekNpr = $pdo->prepare("
            SELECT id, nilai_awal
            FROM nilai_pengayaan_remedial
            WHERE id_pengayaan_remedial = ? AND id_siswa = ?
            ORDER BY id DESC LIMIT 1
        ");
        $stmtCekNpr->execute([$idAsesmen, $idSiswa]);
        $existingNpr = $stmtCekNpr->fetch(PDO::FETCH_ASSOC);

        $pertemuanPr = max(1, (int)($prMeta['pertemuan_ke'] ?? 1));
        $catatanRem = 'Hasil pengerjaan ' . strtolower($kategoriPr) . ' via aplikasi Ilmuna Exam Browser CBT.';

        if ($existingNpr) {
            $finalNilaiAwal = (!empty($existingNpr['nilai_awal']) && (float)$existingNpr['nilai_awal'] > 0)
                ? (float)$existingNpr['nilai_awal']
                : $nilaiAwalSnap;
            $pdo->prepare("
                UPDATE nilai_pengayaan_remedial
                SET id_kelas_mata_pelajaran = COALESCE(NULLIF(?, 0), id_kelas_mata_pelajaran),
                    nilai_awal = ?,
                    nilai_akhir = ?,
                    nilai_kktp = ?,
                    predikat = ?,
                    status_tuntas = ?,
                    catatan_evaluasi = ?,
                    waktu_penilaian = NOW()
                WHERE id = ?
            ")->execute([
                $kmpId,
                $finalNilaiAwal,
                $nilaiAkhir,
                $kktp,
                $predikat,
                $statusTuntasRem,
                $catatanRem,
                $existingNpr['id']
            ]);
        } else {
            $pdo->prepare("
                INSERT INTO nilai_pengayaan_remedial
                (id_siswa, id_kelas_mata_pelajaran, id_pengayaan_remedial, id_guru, id_modul_ajar, id_tp, id_itp, pertemuan_ke, nilai_awal, nilai_akhir, nilai_kktp, predikat, status_tuntas, catatan_evaluasi, waktu_penilaian)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ")->execute([
                $idSiswa,
                $kmpId,
                $idAsesmen,
                $prMeta['id_guru'] ?? null,
                $prMeta['id_modul_ajar'] ?? null,
                $prMeta['id_tp'] ?? null,
                $prMeta['id_itp'] ?? null,
                $pertemuanPr,
                $nilaiAwalSnap,
                $nilaiAkhir,
                $kktp,
                $predikat,
                $statusTuntasRem,
                $catatanRem
            ]);
        }

        // Sinkronkan juga ke kolom nilai_remedial, nilai_akhir_remedial, dan status_tuntas pada nilai_asesmen_sumatif / nilai_asesmen_formatif terkait
        try {
            $isTuntasRem = ($nilaiAkhir >= $kktp);
            if (!empty($aprInfo['id_nilai_sumatif'])) {
                $pdo->prepare("
                    UPDATE nilai_asesmen_sumatif
                    SET nilai_remedial = ?,
                        nilai_akhir_remedial = CASE WHEN ? >= COALESCE(NULLIF(nilai_kktp, 0), ?) THEN COALESCE(NULLIF(nilai_kktp, 0), ?) ELSE nilai_akhir_remedial END,
                        status_remedial = CASE WHEN ? >= COALESCE(NULLIF(nilai_kktp, 0), ?) THEN 'Disetujui' ELSE 'Remedial Ulang' END,
                        status_tuntas = CASE WHEN ? >= COALESCE(NULLIF(nilai_kktp, 0), ?) THEN 'Tuntas' ELSE status_tuntas END
                    WHERE id = ? AND id_siswa = ?
                ")->execute([$nilaiAkhir, $nilaiAkhir, $kktp, $kktp, $nilaiAkhir, $kktp, $nilaiAkhir, $kktp, (int)$aprInfo['id_nilai_sumatif'], $idSiswa]);
            } elseif (!empty($aprInfo['id_nilai_formatif'])) {
                $pdo->prepare("
                    UPDATE nilai_asesmen_formatif
                    SET nilai_remedial = ?,
                        nilai_akhir_remedial = CASE WHEN ? >= COALESCE(NULLIF(nilai_kktp, 0), ?) THEN COALESCE(NULLIF(nilai_kktp, 0), ?) ELSE nilai_akhir_remedial END,
                        status_remedial = CASE WHEN ? >= COALESCE(NULLIF(nilai_kktp, 0), ?) THEN 'Disetujui' ELSE 'Remedial Ulang' END,
                        status_tuntas = CASE WHEN ? >= COALESCE(NULLIF(nilai_kktp, 0), ?) THEN 'Tuntas' ELSE status_tuntas END
                    WHERE id = ? AND id_siswa = ?
                ")->execute([$nilaiAkhir, $nilaiAkhir, $kktp, $kktp, $nilaiAkhir, $kktp, $nilaiAkhir, $kktp, (int)$aprInfo['id_nilai_formatif'], $idSiswa]);
            } elseif ($kmpId > 0) {
                // Fallback otomatis berdasarkan id_siswa dan id_kelas_mata_pelajaran jika id_nilai_* belum tertaut di tiket akses
                $pdo->prepare("
                    UPDATE nilai_asesmen_formatif
                    SET nilai_remedial = ?,
                        nilai_akhir_remedial = CASE WHEN ? >= COALESCE(NULLIF(nilai_kktp, 0), ?) THEN COALESCE(NULLIF(nilai_kktp, 0), ?) ELSE nilai_akhir_remedial END,
                        status_remedial = CASE WHEN ? >= COALESCE(NULLIF(nilai_kktp, 0), ?) THEN 'Disetujui' ELSE 'Remedial Ulang' END,
                        status_tuntas = CASE WHEN ? >= COALESCE(NULLIF(nilai_kktp, 0), ?) THEN 'Tuntas' ELSE status_tuntas END
                    WHERE id_siswa = ? AND id_kelas_mata_pelajaran = ? AND (nilai_angka < COALESCE(NULLIF(nilai_kktp, 0), ?) OR status_tuntas != 'Tuntas')
                ")->execute([$nilaiAkhir, $nilaiAkhir, $kktp, $kktp, $nilaiAkhir, $kktp, $nilaiAkhir, $kktp, $idSiswa, $kmpId, $kktp]);

                $pdo->prepare("
                    UPDATE nilai_asesmen_sumatif
                    SET nilai_remedial = ?,
                        nilai_akhir_remedial = CASE WHEN ? >= COALESCE(NULLIF(nilai_kktp, 0), ?) THEN COALESCE(NULLIF(nilai_kktp, 0), ?) ELSE nilai_akhir_remedial END,
                        status_remedial = CASE WHEN ? >= COALESCE(NULLIF(nilai_kktp, 0), ?) THEN 'Disetujui' ELSE 'Remedial Ulang' END,
                        status_tuntas = CASE WHEN ? >= COALESCE(NULLIF(nilai_kktp, 0), ?) THEN 'Tuntas' ELSE status_tuntas END
                    WHERE id_siswa = ? AND id_kelas_mata_pelajaran = ? AND (nilai_angka < COALESCE(NULLIF(nilai_kktp, 0), ?) OR status_tuntas != 'Tuntas')
                ")->execute([$nilaiAkhir, $nilaiAkhir, $kktp, $kktp, $nilaiAkhir, $kktp, $nilaiAkhir, $kktp, $idSiswa, $kmpId, $kktp]);
            }
        } catch (Throwable $ignoreSync) {}

        // Sinkronkan rekap_nilai_siswa jika kalkulator tersedia
        try {
            $calcPath = __DIR__ . '/../includes/rekap-nilai-calculator.php';
            if (is_file($calcPath)) {
                require_once $calcPath;
                if (function_exists('recalculateRekapNilaiSiswa') && $kmpId > 0) {
                    recalculateRekapNilaiSiswa($pdo, (int)$idSiswa, (int)$kmpId);
                }
            }
        } catch (Throwable $ignoreRekap) {}
    } else {
        $sqlNilai = "
            INSERT INTO nilai_asesmen_diagnostik
            (id_siswa, id_kelas_mata_pelajaran, id_asesmen_diagnostik, nilai_angka, catatan_evaluasi, waktu_penilaian)
            VALUES (?, ?, ?, ?, 'Hasil asesmen diagnostik awal via aplikasi Ilmuna Exam Browser CBT.', NOW())
            ON DUPLICATE KEY UPDATE
                nilai_angka = VALUES(nilai_angka),
                waktu_penilaian = NOW()
        ";
        $pdo->prepare($sqlNilai)->execute([$idSiswa, $kmpId, $idAsesmen, $nilaiAkhir]);
    }

    return [
        'nilai_angka'   => $nilaiAkhir,
        'predikat'      => $predikat,
        'status_tuntas' => $statusTuntas
    ];
}

// ------------------------------------------------------------------------------
// 3. SIMPAN JAWABAN SISWA (AUTOSAVE PER BUTIR SOAL)
// ------------------------------------------------------------------------------
if ($action === 'save_answer') {
    $idSesiUjian = (int)($_REQUEST['id_cbt_sesi'] ?? 0);
    $tipeAsesmen = in_array($_REQUEST['tipe_asesmen'] ?? '', ['sumatif', 'formatif', 'diagnostik', 'remedial'], true) ? $_REQUEST['tipe_asesmen'] : 'sumatif';
    $idAsesmen   = (int)($_REQUEST['id_asesmen'] ?? 0);
    $idSiswa     = (int)($_REQUEST['id_siswa'] ?? 0);
    $idSoal      = (int)($_REQUEST['id_soal'] ?? 0);
    $sisaDetik   = isset($_REQUEST['sisa_waktu_detik']) ? max(0, (int)$_REQUEST['sisa_waktu_detik']) : null;

    if (!$idSoal || !$idAsesmen || !$idSiswa) {
        echo json_encode(['success' => false, 'message' => 'Parameter tidak lengkap.']);
        exit;
    }

    try {
        $validSesiId = saveSingleCbtAnswer($pdo, $_REQUEST);

        // Langsung kalkulasi & simpan nilai secara real-time ke tabel nilai (termasuk nilai_pengayaan_remedial)
        // sehingga meskipun request autosave datang beriringan/setelah finish_exam, nilai di tabel nilai selalu akurat
        $liveGrade = recalculateAndSaveExamScore($pdo, $tipeAsesmen, $idAsesmen, $idSiswa);

        $targetSesiId = $validSesiId ?: $idSesiUjian;
        if ($sisaDetik !== null && $targetSesiId) {
            $pdo->prepare("UPDATE cbt_sesi_ujian SET sisa_waktu_detik = ?, updated_at = NOW() WHERE id = ?")->execute([$sisaDetik, $targetSesiId]);
            $tbAkses = ($tipeAsesmen === 'sumatif') ? 'akses_asesmen_sumatif' : (($tipeAsesmen === 'diagnostik') ? 'akses_asesmen_diagnostik' : (($tipeAsesmen === 'remedial') ? 'akses_pengayaan_remedial' : 'akses_asesmen_formatif'));
            $colFk   = ($tipeAsesmen === 'sumatif') ? 'id_asesmen_sumatif' : (($tipeAsesmen === 'diagnostik') ? 'id_asesmen_diagnostik' : (($tipeAsesmen === 'remedial') ? 'id_pengayaan_remedial' : 'id_asesmen_formatif'));
            $pdo->prepare("UPDATE `$tbAkses` SET sisa_waktu_detik = ?, updated_at = NOW() WHERE `$colFk` = ? AND id_siswa = ?")->execute([$sisaDetik, $idAsesmen, $idSiswa]);
        }

        echo json_encode([
            'success'       => true,
            'message'       => 'Jawaban berhasil disimpan.',
            'id_soal'       => $idSoal,
            'nilai_angka'   => $liveGrade['nilai_angka'] ?? null,
            'status_tuntas' => $liveGrade['status_tuntas'] ?? null
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal simpan: ' . $e->getMessage()]);
        exit;
    }
}

/**
 * FUNGSI TERPADU: OTOMATIS FINISH UJIAN & GENERATE NILAI KE TABEL MASTER
 */
function autoProcessFinishAndGrade($pdo, $idSesiUjian, $tipeAsesmen, $idAsesmen, $idSiswa, $kmpId = 0) {
    try {
        if (!$idAsesmen || !$idSiswa) return false;

        $resGrade = recalculateAndSaveExamScore($pdo, $tipeAsesmen, $idAsesmen, $idSiswa, $kmpId);
        if ($resGrade === false) {
            return false;
        }

        // Update status sesi CBT & status akses master ke Selesai
        $pdo->prepare("
            UPDATE cbt_sesi_ujian SET
                status_sesi = 'Selesai',
                is_frozen = 0,
                is_locked_admin = 0,
                sisa_waktu_detik = 0,
                waktu_selesai = NOW(),
                updated_at = NOW()
            WHERE (id = ? AND id > 0) OR (tipe_asesmen = ? AND id_asesmen = ? AND id_siswa = ?)
        ")->execute([$idSesiUjian, $tipeAsesmen, $idAsesmen, $idSiswa]);

        if ($tipeAsesmen === 'sumatif') {
            $tabelAkses = 'akses_asesmen_sumatif';
            $fkAsesmen  = 'id_asesmen_sumatif';
        } elseif ($tipeAsesmen === 'diagnostik') {
            $tabelAkses = 'akses_asesmen_diagnostik';
            $fkAsesmen  = 'id_asesmen_diagnostik';
        } elseif ($tipeAsesmen === 'remedial') {
            $tabelAkses = 'akses_pengayaan_remedial';
            $fkAsesmen  = 'id_pengayaan_remedial';
        } else {
            $tabelAkses = 'akses_asesmen_formatif';
            $fkAsesmen  = 'id_asesmen_formatif';
        }

        $pdo->prepare("
            UPDATE `$tabelAkses` SET
                status_akses = 'Selesai',
                sisa_waktu_detik = 0,
                waktu_selesai = NOW(),
                updated_at = NOW()
            WHERE `$fkAsesmen` = ? AND id_siswa = ?
        ")->execute([$idAsesmen, $idSiswa]);

        if (function_exists('recordCbtEvent')) {
            recordCbtEvent();
        }

        return $resGrade;
    } catch (Exception $e) {
        return false;
    }
}

// ------------------------------------------------------------------------------
// 4. SINKRONISASI DETIK TIMER & PATOKAN JAM SELESAI
// ------------------------------------------------------------------------------
if ($action === 'sync_timer') {
    $idSesiUjian = (int)($_REQUEST['id_cbt_sesi'] ?? 0);
    $sisaDetik   = max(0, (int)($_REQUEST['sisa_waktu_detik'] ?? 0));

    if ($idSesiUjian) {
        $stmtSesi = $pdo->prepare("
            SELECT s.id, s.tipe_asesmen, s.id_asesmen, s.id_siswa, s.status_sesi, s.is_frozen, s.is_locked_admin,
                   COALESCE(asum.jam_selesai, af.jam_selesai, ad.jam_selesai, pr.jam_selesai) AS jam_selesai,
                   COALESCE(asum.tanggal_pelaksanaan, af.tanggal_pelaksanaan, ad.tanggal_pelaksanaan, pr.tanggal_pelaksanaan) AS tanggal_pelaksanaan
            FROM cbt_sesi_ujian s
            LEFT JOIN asesmen_sumatif asum ON (s.tipe_asesmen = 'sumatif' AND s.id_asesmen = asum.id)
            LEFT JOIN asesmen_formatif af ON (s.tipe_asesmen = 'formatif' AND s.id_asesmen = af.id)
            LEFT JOIN asesmen_diagnostik ad ON (s.tipe_asesmen = 'diagnostik' AND s.id_asesmen = ad.id)
            LEFT JOIN pengayaan_remedial pr ON (s.tipe_asesmen = 'remedial' AND s.id_asesmen = pr.id)
            WHERE s.id = ? LIMIT 1
        ");
        $stmtSesi->execute([$idSesiUjian]);
        $sesi = $stmtSesi->fetch(PDO::FETCH_ASSOC);

        if ($sesi) {
            $isFrozenNow = ((int)($sesi['is_frozen'] ?? 0) === 1 || (int)($sesi['is_locked_admin'] ?? 0) === 1 || $sesi['status_sesi'] === 'Terkunci / Freeze');
            $isTimeExpired = ($sisaDetik <= 0);

            // Cek jika jam sekarang WIB sudah mencapai atau melewati jam_selesai pada hari H
            if (!$isTimeExpired && !empty($sesi['jam_selesai']) && !empty($sesi['tanggal_pelaksanaan'])) {
                $nowWib = date('Y-m-d H:i:s');
                $jamSelesaiWib = $sesi['tanggal_pelaksanaan'] . ' ' . $sesi['jam_selesai'];
                if ($nowWib >= $jamSelesaiWib) {
                    $isTimeExpired = true;
                    $sisaDetik = 0;
                }
            }

            // Jika waktu habis atau jam_selesai tercapai (baik sedang mengerjakan maupun sedang Freeze) -> OTOMATIS FINISH & GENERATE NILAI
            if ($isTimeExpired && $sesi['status_sesi'] !== 'Selesai') {
                $resGrade = autoProcessFinishAndGrade($pdo, $idSesiUjian, $sesi['tipe_asesmen'], (int)$sesi['id_asesmen'], (int)$sesi['id_siswa']);
                if (function_exists('recordCbtEvent')) {
                    recordCbtEvent();
                }
                echo json_encode([
                    'success'          => true,
                    'sisa_waktu_detik' => 0,
                    'status_sesi'      => 'Selesai',
                    'is_completed'     => true,
                    'nilai_angka'      => $resGrade['nilai_angka'] ?? 0.00,
                    'predikat'         => $resGrade['predikat'] ?? 'C',
                    'message'          => 'Waktu pengerjaan telah berakhir. Ujian otomatis dikumpulkan & dinilai.'
                ]);
                exit;
            }

            // Tetap perbarui sisa_waktu_detik di database meskipun status sedang Terkunci / Freeze agar timer terus berjalan
            $pdo->prepare("UPDATE cbt_sesi_ujian SET sisa_waktu_detik = ?, updated_at = NOW() WHERE id = ?")->execute([$sisaDetik, $idSesiUjian]);
            $tbAkses = ($sesi['tipe_asesmen'] === 'sumatif') ? 'akses_asesmen_sumatif' : (($sesi['tipe_asesmen'] === 'diagnostik') ? 'akses_asesmen_diagnostik' : (($sesi['tipe_asesmen'] === 'remedial') ? 'akses_pengayaan_remedial' : 'akses_asesmen_formatif'));
            $colFk   = ($sesi['tipe_asesmen'] === 'sumatif') ? 'id_asesmen_sumatif' : (($sesi['tipe_asesmen'] === 'diagnostik') ? 'id_asesmen_diagnostik' : (($sesi['tipe_asesmen'] === 'remedial') ? 'id_pengayaan_remedial' : 'id_asesmen_formatif'));
            $pdo->prepare("UPDATE `$tbAkses` SET sisa_waktu_detik = ?, updated_at = NOW() WHERE `$colFk` = ? AND id_siswa = ?")->execute([$sisaDetik, $sesi['id_asesmen'], $sesi['id_siswa']]);

            if ($isFrozenNow) {
                echo json_encode([
                    'success'          => true,
                    'sisa_waktu_detik' => $sisaDetik,
                    'is_frozen'        => true,
                    'status_sesi'      => $sesi['status_sesi']
                ]);
                exit;
            }
        }
    }

    echo json_encode(['success' => true, 'sisa_waktu_detik' => $sisaDetik]);
    exit;
}

// ------------------------------------------------------------------------------
// 5. KUMPULKAN & SELESAIKAN UJIAN (SUBMIT FINAL)
// ------------------------------------------------------------------------------
if ($action === 'finish_exam') {
    $idSesiUjian = (int)($_REQUEST['id_cbt_sesi'] ?? 0);
    $tipeAsesmen = in_array($_REQUEST['tipe_asesmen'] ?? '', ['sumatif', 'formatif', 'diagnostik', 'remedial'], true) ? $_REQUEST['tipe_asesmen'] : 'sumatif';
    $idAsesmen   = (int)($_REQUEST['id_asesmen'] ?? 0);
    $idSiswa     = (int)($_REQUEST['id_siswa'] ?? 0);
    $kmpId       = (int)($_REQUEST['id_kelas_mata_pelajaran'] ?? 0);

    // Jika aplikasi Android mengirimkan rekap seluruh jawaban (answers_json) saat submit,
    // simpan terlebih dahulu seluruh jawaban tersebut sebelum kalkulasi nilai akhir
    $answersJson = $_REQUEST['answers_json'] ?? '';
    if (!empty($answersJson)) {
        $decodedAnswers = json_decode($answersJson, true);
        if (is_array($decodedAnswers)) {
            foreach ($decodedAnswers as $ansItem) {
                if (!is_array($ansItem) || empty($ansItem['id_soal'])) continue;
                try {
                    saveSingleCbtAnswer($pdo, [
                        'id_cbt_sesi'  => $idSesiUjian,
                        'tipe_asesmen' => $tipeAsesmen,
                        'id_asesmen'   => $idAsesmen,
                        'id_siswa'     => $idSiswa,
                        'id_soal'      => (int)$ansItem['id_soal'],
                        'nomor_urut'   => (int)($ansItem['nomor_urut'] ?? 1),
                        'tipe_soal'    => $ansItem['tipe_soal'] ?? 'Pilihan Ganda',
                        'id_opsi'      => $ansItem['id_opsi'] ?? null,
                        'kode_opsi'    => $ansItem['kode_opsi'] ?? '',
                        'jawaban_teks' => $ansItem['jawaban_teks'] ?? null,
                        'is_ragu'      => (int)($ansItem['is_ragu'] ?? 0)
                    ]);
                } catch (Throwable $ignoreAns) {}
            }
        }
    }

    $resGrade = autoProcessFinishAndGrade($pdo, $idSesiUjian, $tipeAsesmen, $idAsesmen, $idSiswa, $kmpId);
    if (function_exists('recordCbtEvent')) {
        recordCbtEvent();
    }
    if ($resGrade !== false) {
        echo json_encode([
            'success'       => true,
            'message'       => 'Ujian berhasil diselesaikan!',
            'nilai_angka'   => $resGrade['nilai_angka'],
            'predikat'      => $resGrade['predikat'],
            'status_tuntas' => $resGrade['status_tuntas']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal mengumpulkan ujian.']);
    }
    exit;
}

// ------------------------------------------------------------------------------
// 6. FREEZE SESI UJIAN SAAT SISWA KELUAR APLIKASI
// ------------------------------------------------------------------------------
if ($action === 'freeze') {
    $idSesiUjian = (int)($_REQUEST['id_cbt_sesi'] ?? 0);
    $tipeAsesmen = in_array($_REQUEST['tipe_asesmen'] ?? '', ['sumatif', 'formatif', 'diagnostik', 'remedial'], true) ? $_REQUEST['tipe_asesmen'] : 'sumatif';
    $idAsesmen   = (int)($_REQUEST['id_asesmen'] ?? 0);
    $idSiswa     = (int)($_REQUEST['id_siswa'] ?? 0);
    $sisaDetik   = isset($_REQUEST['sisa_waktu_detik']) ? max(0, (int)$_REQUEST['sisa_waktu_detik']) : null;
    $alasan      = trim($_REQUEST['alasan'] ?? 'Keluar Aplikasi / Home (Peralihan Layar)');
    $deviceModel = trim($_REQUEST['device_model'] ?? 'Android Device');

    if ($idAsesmen && $idSiswa) {
        $upd = "
            UPDATE cbt_sesi_ujian SET 
                status_sesi = 'Terkunci / Freeze',
                is_frozen = 1,
                is_locked_admin = 1,
                waktu_freeze = NOW(),
                alasan_freeze = ?,
                total_pelanggaran = total_pelanggaran + 1,
                sisa_waktu_detik = COALESCE(?, sisa_waktu_detik),
                device_model = ?
            WHERE tipe_asesmen = ? AND id_asesmen = ? AND id_siswa = ? AND status_sesi != 'Selesai'
        ";
        $pdo->prepare($upd)->execute([$alasan, $sisaDetik, $deviceModel, $tipeAsesmen, $idAsesmen, $idSiswa]);

        $tabelAkses = ($tipeAsesmen === 'sumatif') ? 'akses_asesmen_sumatif' : (($tipeAsesmen === 'diagnostik') ? 'akses_asesmen_diagnostik' : (($tipeAsesmen === 'remedial') ? 'akses_pengayaan_remedial' : 'akses_asesmen_formatif'));
        $fkAsesmen  = ($tipeAsesmen === 'sumatif') ? 'id_asesmen_sumatif' : (($tipeAsesmen === 'diagnostik') ? 'id_asesmen_diagnostik' : (($tipeAsesmen === 'remedial') ? 'id_pengayaan_remedial' : 'id_asesmen_formatif'));
        $pdo->prepare("UPDATE `$tabelAkses` SET status_akses = 'Terkunci', sisa_waktu_detik = COALESCE(?, sisa_waktu_detik) WHERE `$fkAsesmen` = ? AND id_siswa = ? AND status_akses != 'Selesai'")
            ->execute([$sisaDetik, $idAsesmen, $idSiswa]);

        // Catat ke cbt_log_pelanggaran_exambro
        $insLog = "
            INSERT INTO cbt_log_pelanggaran_exambro 
            (id_sesi_ujian, tipe_asesmen, id_asesmen, id_siswa, jenis_pelanggaran, keterangan, sisa_waktu_detik, status_tindakan, waktu_kejadian)
            VALUES (?, ?, ?, ?, 'Keluar Aplikasi / Home', ?, ?, 'Freeze & Logout Otomatis', NOW())
        ";
        $pdo->prepare($insLog)->execute([$idSesiUjian ?: null, $tipeAsesmen, $idAsesmen, $idSiswa, $alasan, $sisaDetik]);
        recordCbtEvent();
    }

    echo json_encode(['success' => true, 'message' => 'Sesi berhasil dibekukan.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Action tidak dikenal.']);
