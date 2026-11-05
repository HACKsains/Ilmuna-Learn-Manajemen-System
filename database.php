<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — DATABASE ARCHITECTURE & MIGRATION ENGINE
 * ==============================================================================
 * Standar Kurikulum Merdeka & Sistem Pembelajaran Digital Terintegrasi (69 TABEL)
 * Single Source of Truth: skema-tabel-kelas.sql
 * ==============================================================================
 */

require_once __DIR__ . '/config.php';

/**
 * Daftar 91 Tabel Resmi Kurikulum Merdeka Ilmuna Learn
 */
function getOfficialTableList() {
    return [
        // 1. Struktur Organisasi Kelas, Sekolah, Guru & Siswa
        'kelas', 'siswa', 'guru', 'guru_mata_pelajaran', 'identitas_sekolah', 'app_settings',
        // 2. User & RBAC Multi-Role & Google SSO
        'users', 'roles', 'user_roles', 'google_link_verifications',
        // 3. Kurikulum CP/TP/ATP/ITP & Mata Pelajaran
        'mata_pelajaran', 'mapel', 'kelas_mata_pelajaran', 'elemen', 'cp', 'tp', 'atp', 'itp',
        // 4. Model & Sintaks Pedagogis
        'model_pembelajaran', 'sintaks_model_pembelajaran', 'metode_pembelajaran',
        // 5. Modul Ajar Digital & Materi KBM
        'modul_ajar', 'materi',
        // 6. Kalender PDP (Kaldik, PE, HE, Alokasi, Distribusi JTM)
        'kalender_pendidikan', 'pekan_efektif', 'hari_efektif', 'alokasi_hari_mengajar', 'distribusi_jtm_tp',
        // 7. PROTA & PROSEM
        'prota', 'prota_detail', 'prosem', 'prosem_detail', 'prosem_jadwal_mingguan',
        // 8. Titik Geofencing, Jadwal, Presensi & Mutaba'ah
        'titik_koordinat_sekolah', 'jadwal_pelajaran', 'presensi', 'absensi', 'mutabaah_logs',
        // 9. Perizinan Dual-Approval
        'perizinan',
        // 10. Portofolio & Penugasan Murid
        'tugas', 'tugas_kelompok', 'tugas_kelompok_anggota', 'pengumpulan_tugas', 'nilai_tugas',
        // 11. Asesmen Diagnostik Kesiapan Belajar
        'asesmen_diagnostik', 'asesmen_diagnostik_soal', 'asesmen_diagnostik_soal_opsi', 
        'asesmen_diagnostik_soal_rubrik', 'akses_asesmen_diagnostik', 'nilai_asesmen_diagnostik',
        // 12. Asesmen Formatif & Tiket Login Ujian Siswa
        'asesmen_formatif', 'asesmen_formatif_soal', 'asesmen_formatif_soal_opsi', 
        'asesmen_formatif_soal_rubrik', 'akses_asesmen_formatif', 'nilai_asesmen_formatif',
        // 13. Asesmen Sumatif & Kredensial CBT Siswa
        'asesmen_sumatif', 'asesmen_sumatif_kelas', 'asesmen_sumatif_soal', 'asesmen_sumatif_soal_opsi', 
        'asesmen_sumatif_soal_rubrik', 'akses_asesmen_sumatif', 'absensi_asesmen_sumatif', 'nilai_asesmen_sumatif',
        // 14. Lembar Kerja (LKPD) & Remedial/Pengayaan
        'lkpd', 'lkpd_soal', 'lkpd_soal_opsi', 'lkpd_soal_rubrik', 'nilai_lkpd',
        'pengayaan_remedial', 'pengayaan_remedial_soal', 'pengayaan_remedial_soal_opsi', 
        'pengayaan_remedial_soal_rubrik', 'akses_pengayaan_remedial', 'nilai_pengayaan_remedial',
        // 15. Rekap Rapor Akhir & Proyek P5 PPRA
        'rekap_nilai_siswa_mapel', 'proyek', 'proyek_kelompok', 'proyek_kelompok_anggota', 
        'pengumpulan_proyek', 'biaya_proyek',
        // 16. Exam Browser CBT Engine, Jawaban Siswa & Keamanan Kiosk
        'cbt_sesi_ujian', 'cbt_jawaban_siswa', 'cbt_log_pelanggaran_exambro', 'cbt_pengaturan_exambro',
        // 17. Asesmen Afektif (Observasi Langsung Sikap Siswa oleh Guru)
        'asesmen_afektif', 'asesmen_afektif_indikator', 'nilai_asesmen_afektif',
        // 18. Asesmen Psikomotorik (Penilaian Langsung Praktik Siswa oleh Guru)
        'asesmen_psikomotorik', 'asesmen_psikomotorik_rubrik', 'nilai_asesmen_psikomotorik'
    ];
}

/**
 * Daftar Klaster Tabel Resmi untuk Pemetaan & Tampilan Dasbor
 */
function getDatabaseClusters() {
    return [
        'Klaster 1: Struktur Organisasi Kelas, Sekolah, Guru & Siswa' => ['kelas', 'siswa', 'guru', 'guru_mata_pelajaran', 'identitas_sekolah', 'app_settings'],
        'Klaster 2: Pengguna, Multi-Role RBAC & Google SSO' => ['users', 'roles', 'user_roles', 'google_link_verifications'],
        'Klaster 3: Kurikulum Merdeka (Elemen, CP, TP, ATP, ITP)' => ['mata_pelajaran', 'mapel', 'kelas_mata_pelajaran', 'elemen', 'cp', 'tp', 'atp', 'itp'],
        'Klaster 4: Bank Model & Sintaks Pedagogis' => ['model_pembelajaran', 'sintaks_model_pembelajaran', 'metode_pembelajaran'],
        'Klaster 5: Modul Ajar Digital & Materi KBM' => ['modul_ajar', 'materi'],
        'Klaster 6: Kalender Akademik PDP (Kaldik, HE, PE, JTM)' => ['kalender_pendidikan', 'pekan_efektif', 'hari_efektif', 'alokasi_hari_mengajar', 'distribusi_jtm_tp'],
        'Klaster 7: Program Tahunan & Semester (PROTA/PROSEM)' => ['prota', 'prota_detail', 'prosem', 'prosem_detail', 'prosem_jadwal_mingguan'],
        'Klaster 8: Presensi Pintar, Mutaba\'ah & Geofencing GPS' => ['titik_koordinat_sekolah', 'jadwal_pelajaran', 'presensi', 'absensi', 'mutabaah_logs'],
        'Klaster 9: Perizinan Dual-Approval (Ketua Kelas & Guru)' => ['perizinan'],
        'Klaster 10: Portofolio & Penugasan Murid' => ['tugas', 'tugas_kelompok', 'tugas_kelompok_anggota', 'pengumpulan_tugas', 'nilai_tugas'],
        'Klaster 11: Asesmen Diagnostik Kesiapan Belajar' => ['asesmen_diagnostik', 'asesmen_diagnostik_soal', 'asesmen_diagnostik_soal_opsi', 'asesmen_diagnostik_soal_rubrik', 'akses_asesmen_diagnostik', 'nilai_asesmen_diagnostik'],
        'Klaster 12: Asesmen Formatif & Tiket Login Ujian Siswa' => ['asesmen_formatif', 'asesmen_formatif_soal', 'asesmen_formatif_soal_opsi', 'asesmen_formatif_soal_rubrik', 'akses_asesmen_formatif', 'nilai_asesmen_formatif'],
        'Klaster 13: Asesmen Sumatif & Kredensial CBT Siswa' => ['asesmen_sumatif', 'asesmen_sumatif_kelas', 'asesmen_sumatif_soal', 'asesmen_sumatif_soal_opsi', 'asesmen_sumatif_soal_rubrik', 'akses_asesmen_sumatif', 'absensi_asesmen_sumatif', 'nilai_asesmen_sumatif'],
        'Klaster 14: Lembar Kerja (LKPD) & Remedial/Pengayaan' => ['lkpd', 'lkpd_soal', 'lkpd_soal_opsi', 'lkpd_soal_rubrik', 'nilai_lkpd', 'pengayaan_remedial', 'pengayaan_remedial_soal', 'pengayaan_remedial_soal_opsi', 'pengayaan_remedial_soal_rubrik', 'akses_pengayaan_remedial', 'nilai_pengayaan_remedial'],
        'Klaster 15: Rekap Rapor Capaian & Proyek P5 PPRA' => ['rekap_nilai_siswa_mapel', 'proyek', 'proyek_kelompok', 'proyek_kelompok_anggota', 'pengumpulan_proyek', 'biaya_proyek'],
        'Klaster 16: Exam Browser CBT Engine, Jawaban Siswa & Keamanan Kiosk' => ['cbt_sesi_ujian', 'cbt_jawaban_siswa', 'cbt_log_pelanggaran_exambro', 'cbt_pengaturan_exambro'],
        'Klaster 17: Asesmen Afektif (Observasi Langsung Sikap Guru)' => ['asesmen_afektif', 'asesmen_afektif_indikator', 'nilai_asesmen_afektif'],
        'Klaster 18: Asesmen Psikomotorik (Penilaian Langsung Praktik Guru)' => ['asesmen_psikomotorik', 'asesmen_psikomotorik_rubrik', 'nilai_asesmen_psikomotorik']
    ];
}

/**
 * Jalankan Migrasi Otomatis dari skema-lengkap.sql atau skema-tabel-kelas.sql
 * 
 * @param PDO $pdo Koneksi Database aktif
 * @param bool $fresh Jika true, hapus tabel lama dan buat ulang bersih
 * @return array Hasil eksekusi migrasi
 */
function runDatabaseMigration($pdo, $fresh = false) {
    $candidates = [
        __DIR__ . '/uofruaju_learn_ilmuna.sql',
        __DIR__ . '/skema-lengkap.sql',
        __DIR__ . '/skema-tabel-kelas.sql',
        __DIR__ . '/skema-sekolah-ilmuna.sql'
    ];

    $sqlFile = null;
    foreach ($candidates as $cand) {
        if (file_exists($cand) && filesize($cand) > 1000) {
            $sqlFile = $cand;
            break;
        }
    }

    if (!$sqlFile) {
        throw new Exception("File master skema SQL tidak ditemukan pada direktori sistem.");
    }

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    // 1. Nonaktifkan pemeriksaan foreign key
    if ($driver === 'mysql') {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
        $pdo->exec("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';");
        try {
            $pdo->setAttribute(PDO::MYSQL_ATTR_MULTI_STATEMENTS, true);
        } catch (Throwable $t) {}
    } else {
        $pdo->exec("PRAGMA foreign_keys = OFF;");
    }

    // 2. Bersihkan tabel legacy prototype jika ada
    $legacyTables = [
        'announcements', 'assignments', 'attendance_details', 'attendances', 
        'class_members', 'classes', 'materials', 'mutabaah_logs', 'submissions'
    ];
    if ($driver === 'mysql') {
        foreach ($legacyTables as $lt) {
            try { $pdo->exec("DROP TABLE IF EXISTS `$lt`"); } catch (Throwable $e) {}
        }
    }

    // 3. Jika mode fresh, bersihkan seluruh tabel sebelum migrasi ulang
    if ($fresh && $driver === 'mysql') {
        $officialTables = getOfficialTableList();
        foreach (array_reverse($officialTables) as $ot) {
            try { $pdo->exec("DROP TABLE IF EXISTS `$ot`"); } catch (Throwable $e) {}
        }
    }

    // 4. Eksekusi skema SQL
    $sqlContent = file_get_contents($sqlFile);
    $executed = false;
    try {
        $pdo->exec($sqlContent);
        $executed = true;
    } catch (PDOException $e) {
        // Fallback: Parsing statement-by-statement jika direct multi-exec dibatasi hosting
        executeSqlStatementsFallback($pdo, $sqlContent);
        $executed = true;
    }

    // 5. Buat / perbarui view backward compatibility untuk halaman dashboard (classes, assignments, materials, dll)
    if ($driver === 'mysql') {
        createCompatibilityViews($pdo);
        ensureRemedialDatabaseTriggers($pdo);
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
    } else {
        $pdo->exec("PRAGMA foreign_keys = ON;");
    }

    // 6. Hitung statistik tabel aktif
    $stats = getDatabaseTables($pdo);

    return [
        'status' => 'success',
        'message' => 'Migrasi seluruh tabel Kurikulum Merdeka & data berhasil disinkronisasi.',
        'total_tables' => count($stats),
        'tables' => $stats
    ];
}

/**
 * Fallback statement-by-statement executor (Cepat & Aman Membaca String Multi-Baris)
 */
function executeSqlStatementsFallback($pdo, $sqlContent) {
    $lines = explode("\n", $sqlContent);
    $stmt = '';
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
            continue;
        }
        $stmt .= $line . "\n";
        if (str_ends_with($trimmed, ';')) {
            $sql = trim($stmt);
            if ($sql !== '') {
                try {
                    $pdo->exec($sql);
                } catch (Throwable $e) {
                    // Abaikan duplikasi atau error non-fatal pada fallback
                }
            }
            $stmt = '';
        }
    }
    if (trim($stmt) !== '') {
        try {
            $pdo->exec(trim($stmt));
        } catch (Throwable $e) {}
    }
}

/**
 * Buat VIEW backward compatibility agar kode/dashboard lama (classes, assignments, dll) tetap jalan mulus
 */
function createCompatibilityViews($pdo) {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver !== 'mysql') {
        return;
    }

    $views = [
        "CREATE OR REPLACE VIEW `classes` AS 
         SELECT 
             id, 
             nama_kelas AS name, 
             CASE 
                 WHEN nama_kelas LIKE 'X-%' OR nama_kelas LIKE 'X %' THEN 'X'
                 WHEN nama_kelas LIKE 'XI-%' OR nama_kelas LIKE 'XI %' THEN 'XI'
                 WHEN nama_kelas LIKE 'XII-%' OR nama_kelas LIKE 'XII %' THEN 'XII'
                 ELSE 'X'
             END AS grade_level, 
             tahun_pelajaran AS academic_year, 
             NULL AS ketua_kelas_id, 
             created_at 
         FROM `kelas`",
        "CREATE OR REPLACE VIEW `class_members` AS
         SELECT 
             s.id,
             s.id_kelas AS class_id,
             s.id AS user_id,
             s.created_at AS joined_at
         FROM `siswa` s",

        "CREATE OR REPLACE VIEW `materials` AS
         SELECT 
             m.id,
             m.judul_materi AS title,
             m.tipe_materi AS category,
             1 AS class_id,
             1 AS author_id,
             m.deskripsi_singkat AS summary,
             m.isi_materi AS content,
             m.file_materi AS attachment_path,
             m.link_materi AS youtube_url,
             'published' AS status,
             m.created_at
         FROM `materi` m",

        "CREATE OR REPLACE VIEW `assignments` AS
         SELECT 
             t.id,
             t.judul_tugas AS title,
             t.jenis_penugasan AS category,
             kmp.id_kelas AS class_id,
             t.id_guru AS author_id,
             t.deskripsi_instruksi AS description,
             t.tenggat_waktu AS due_date,
             t.nilai_maksimal AS max_score,
             t.status_tugas AS status,
             t.created_at
         FROM `tugas` t
         LEFT JOIN `kelas_mata_pelajaran` kmp ON t.id_kelas_mata_pelajaran = kmp.id",

        "CREATE OR REPLACE VIEW `submissions` AS
         SELECT 
             pt.id,
             pt.id_tugas AS assignment_id,
             pt.id_siswa AS student_id,
             pt.catatan_siswa AS submitted_text,
             pt.file_tugas AS file_path,
             nt.nilai AS score,
             nt.catatan_guru AS teacher_feedback,
             pt.waktu_pengumpulan AS submitted_at,
             nt.waktu_penilaian AS graded_at
         FROM `pengumpulan_tugas` pt
         LEFT JOIN `nilai_tugas` nt ON nt.id_pengumpulan_tugas = pt.id",

        "CREATE OR REPLACE VIEW `attendances` AS
         SELECT 
             COALESCE(a.id, 1) AS id,
             COALESCE(kmp.id_kelas, 1) AS class_id,
             1 AS recorded_by,
             COALESCE(DATE(a.waktu_absen), CURDATE()) AS attendance_date,
             CONCAT('Pertemuan ke-', a.pertemuan_ke) AS meeting_topic,
             COALESCE(a.keterangan, 'Presensi Digital Siswa') AS notes,
             COALESCE(a.waktu_absen, NOW()) AS created_at
         FROM `absensi` a
         LEFT JOIN `kelas_mata_pelajaran` kmp ON a.id_kelas_mata_pelajaran = kmp.id",

        "CREATE OR REPLACE VIEW `attendance_details` AS
         SELECT 
             a.id,
             a.id AS attendance_id,
             a.id_siswa AS student_id,
             CASE 
                 WHEN a.status = 'H' THEN 'hadir'
                 WHEN a.status = 'I' THEN 'izin'
                 WHEN a.status = 'S' THEN 'sakit'
                 ELSE 'alpa'
             END AS status,
             a.keterangan AS notes,
             a.waktu_absen AS marked_at
         FROM `absensi` a",

        "CREATE TABLE IF NOT EXISTS `mutabaah_logs` (
             `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
             `student_id` INT(11) NOT NULL,
             `log_date` DATE NOT NULL,
             `subuh` TINYINT(1) DEFAULT 1,
             `dzuhur` TINYINT(1) DEFAULT 1,
             `ashar` TINYINT(1) DEFAULT 1,
             `maghrib` TINYINT(1) DEFAULT 1,
             `isya` TINYINT(1) DEFAULT 1,
             `dhuha` TINYINT(1) DEFAULT 0,
             `tahajud` TINYINT(1) DEFAULT 0,
             `puasa_sunnah` TINYINT(1) DEFAULT 0,
             `tilawah_surah` VARCHAR(100) DEFAULT 'Al-Baqarah',
             `tilawah_ayat` VARCHAR(50) DEFAULT '1-10',
             `catatan` TEXT DEFAULT NULL,
             `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
             PRIMARY KEY (`id`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE OR REPLACE VIEW `announcements` AS
         SELECT 
             1 AS id,
             'Selamat Datang di Ilmuna Learn Kurikulum Merdeka' AS title,
             'Sistem Pembelajaran Digital Terintegrasi 69 Tabel PDP & SIAKAD.' AS content,
             1 AS class_id,
             'all' AS target_role,
             1 AS author_id,
             1 AS is_pinned,
             NOW() AS created_at"
    ];

    foreach ($views as $v) {
        try {
            $pdo->exec($v);
        } catch (Exception $e) {
            // Silently continue
        }
    }
}

/**
 * Ambil Daftar Semua Tabel Aktif Beserta Jumlah Baris
 */
function getDatabaseTables($pdo) {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $tableStats = [];

    if ($driver === 'mysql') {
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            try {
                $count = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
                $tableStats[$t] = (int) $count;
            } catch (Exception $e) {
                $tableStats[$t] = 0;
            }
        }
    } else {
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            try {
                $count = $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
                $tableStats[$t] = (int) $count;
            } catch (Exception $e) {
                $tableStats[$t] = 0;
            }
        }
    }

    return $tableStats;
}

/**
 * ==============================================================================
 * HELPER KREDENSIAL AKSES SISWA PADA ASESMEN FORMATIF & SUMATIF (APLIKASI TERPISAH)
 * ==============================================================================
 */

/**
 * Pastikan VIEW v_kelayakan_remedial dan TRIGGER database otomatis untuk Remedial/Pengayaan
 * selalu terpasang dan tersinkronisasi di database MySQL.
 */
function ensureRemedialDatabaseTriggers($pdo) {
    static $initialized = false;
    if ($initialized) {
        return;
    }
    $initialized = true;

    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver !== 'mysql') {
            return;
        }

        // 1. Perbarui VIEW v_kelayakan_remedial agar wajib sesuai kelas siswa (s.id_kelas = kmp.id_kelas) dan nilai < KKTP
        $pdo->exec("
            CREATE OR REPLACE VIEW `v_kelayakan_remedial` AS
            SELECT
              s.id AS id_siswa,
              kmp.id AS id_kelas_mata_pelajaran,
              COALESCE(ns.pertemuan_ke, nf.pertemuan_ke, nd.pertemuan_ke, npr.pertemuan_ke, 1) AS pertemuan_ke,
              nd.id AS id_nilai_diagnostik,
              nf.id AS id_nilai_formatif,
              ns.id AS id_nilai_sumatif,
              COALESCE(nd.nilai_angka, 0.00) AS nilai_diagnostik,
              COALESCE(nf.nilai_angka, 0.00) AS nilai_formatif,
              COALESCE(ns.nilai_angka, npr.nilai_awal, 0.00) AS nilai_sumatif,
              COALESCE(ns.nilai_kktp, nf.nilai_kktp, nd.nilai_kktp, npr.nilai_kktp, 75.00) AS kktp_acuan
            FROM `siswa` s
            JOIN `kelas_mata_pelajaran` kmp
              ON kmp.id_kelas = s.id_kelas
            LEFT JOIN `nilai_asesmen_diagnostik` nd
              ON nd.id_siswa = s.id AND nd.id_kelas_mata_pelajaran = kmp.id
            LEFT JOIN `nilai_asesmen_formatif` nf
              ON nf.id_siswa = s.id AND nf.id_kelas_mata_pelajaran = kmp.id
            LEFT JOIN `nilai_asesmen_sumatif` ns
              ON ns.id_siswa = s.id AND ns.id_kelas_mata_pelajaran = kmp.id
            LEFT JOIN `nilai_pengayaan_remedial` npr
              ON npr.id_siswa = s.id AND npr.id_kelas_mata_pelajaran = kmp.id
            WHERE (nd.id IS NOT NULL AND nd.nilai_angka < COALESCE(NULLIF(nd.nilai_kktp, 0), 75.00))
               OR (nf.id IS NOT NULL AND nf.nilai_angka < COALESCE(NULLIF(nf.nilai_kktp, 0), 75.00))
               OR (ns.id IS NOT NULL AND ns.nilai_angka < COALESCE(NULLIF(ns.nilai_kktp, 0), 75.00))
               OR (npr.id IS NOT NULL AND npr.nilai_awal < COALESCE(NULLIF(npr.nilai_kktp, 0), 75.00))
        ");

        // 1b. Bersihkan data lintas kelas yang tidak valid (siswa yang id_kelas-nya berbeda dengan id_kelas program remedial)
        $pdo->exec("
            DELETE cjs FROM `cbt_jawaban_siswa` cjs
            JOIN `siswa` s ON cjs.id_siswa = s.id
            JOIN `pengayaan_remedial` pr ON cjs.id_asesmen = pr.id
            JOIN `kelas_mata_pelajaran` kmp ON pr.id_kelas_mata_pelajaran = kmp.id
            WHERE cjs.tipe_asesmen = 'remedial' AND s.id_kelas != kmp.id_kelas
        ");
        $pdo->exec("
            DELETE clp FROM `cbt_log_pelanggaran_exambro` clp
            JOIN `siswa` s ON clp.id_siswa = s.id
            JOIN `pengayaan_remedial` pr ON clp.id_asesmen = pr.id
            JOIN `kelas_mata_pelajaran` kmp ON pr.id_kelas_mata_pelajaran = kmp.id
            WHERE clp.tipe_asesmen = 'remedial' AND s.id_kelas != kmp.id_kelas
        ");
        $pdo->exec("
            DELETE csu FROM `cbt_sesi_ujian` csu
            JOIN `siswa` s ON csu.id_siswa = s.id
            JOIN `pengayaan_remedial` pr ON csu.id_asesmen = pr.id
            JOIN `kelas_mata_pelajaran` kmp ON pr.id_kelas_mata_pelajaran = kmp.id
            WHERE csu.tipe_asesmen = 'remedial' AND s.id_kelas != kmp.id_kelas
        ");
        $pdo->exec("
            DELETE apr FROM `akses_pengayaan_remedial` apr
            JOIN `siswa` s ON apr.id_siswa = s.id
            JOIN `pengayaan_remedial` pr ON apr.id_pengayaan_remedial = pr.id
            JOIN `kelas_mata_pelajaran` kmp ON pr.id_kelas_mata_pelajaran = kmp.id
            WHERE s.id_kelas != kmp.id_kelas
        ");
        $pdo->exec("
            DELETE npr FROM `nilai_pengayaan_remedial` npr
            JOIN `siswa` s ON npr.id_siswa = s.id
            LEFT JOIN `pengayaan_remedial` pr ON npr.id_pengayaan_remedial = pr.id
            JOIN `kelas_mata_pelajaran` kmp ON COALESCE(pr.id_kelas_mata_pelajaran, npr.id_kelas_mata_pelajaran) = kmp.id
            WHERE s.id_kelas != kmp.id_kelas
        ");

        // 2. Cek apakah trigger versi terbaru (v2_strict_kelas) sudah terpasang
        $stmtChk = $pdo->query("SHOW TRIGGERS LIKE 'pengayaan_remedial'");
        $existingTriggers = $stmtChk ? $stmtChk->fetchAll(PDO::FETCH_ASSOC) : [];
        $hasProgramTrigger = false;
        foreach ($existingTriggers as $trg) {
            if (($trg['Trigger'] ?? '') === 'trg_auto_remedial_ins_program' && strpos((string)($trg['Statement'] ?? ''), 'v2_strict_kelas') !== false) {
                $hasProgramTrigger = true;
                break;
            }
        }

        if (!$hasProgramTrigger) {
            $triggerSqls = [
                "DROP TRIGGER IF EXISTS `trg_auto_remedial_ins_sumatif`",
                "CREATE TRIGGER `trg_auto_remedial_ins_sumatif` AFTER INSERT ON `nilai_asesmen_sumatif` FOR EACH ROW
                BEGIN
                    /* v2_strict_kelas */
                    IF (NEW.nilai_angka < COALESCE(NULLIF(NEW.nilai_kktp, 0), 75.00)) THEN
                        INSERT INTO `nilai_pengayaan_remedial`
                            (`id_siswa`, `id_kelas_mata_pelajaran`, `id_pengayaan_remedial`, `id_guru`, `id_modul_ajar`, `id_tp`, `id_itp`, `pertemuan_ke`, `nilai_awal`, `nilai_akhir`, `nilai_kktp`, `predikat`, `status_tuntas`, `catatan_evaluasi`, `waktu_penilaian`)
                        SELECT
                            NEW.id_siswa, pr.id_kelas_mata_pelajaran, pr.id, COALESCE(pr.id_guru, NEW.id_guru), COALESCE(pr.id_modul_ajar, NEW.id_modul_ajar), COALESCE(pr.id_tp, NEW.id_tp), COALESCE(pr.id_itp, NEW.id_itp), COALESCE(pr.pertemuan_ke, NEW.pertemuan_ke, 1),
                            NEW.nilai_angka, NEW.nilai_angka, COALESCE(NULLIF(pr.kktp_minimal, 0), NULLIF(NEW.nilai_kktp, 0), 75.00), 'D', 'Belum Tuntas', 'Terdaftar otomatis oleh trigger database karena nilai sumatif di bawah KKTP.', NOW()
                        FROM `pengayaan_remedial` pr
                        JOIN `kelas_mata_pelajaran` kmp ON pr.id_kelas_mata_pelajaran = kmp.id
                        JOIN `siswa` s ON s.id = NEW.id_siswa
                        LEFT JOIN `asesmen_sumatif` asum ON asum.id = NEW.id_asesmen_sumatif
                        WHERE pr.kategori = 'Remedial'
                          AND kmp.id_kelas = s.id_kelas
                          AND NEW.nilai_angka < COALESCE(NULLIF(pr.kktp_minimal, 0), NULLIF(NEW.nilai_kktp, 0), 75.00)
                          AND (
                              (LOWER(TRIM(pr.judul_program)) LIKE 'remedial - %' AND asum.judul_asesmen IS NOT NULL AND LOWER(TRIM(pr.judul_program)) = LOWER(TRIM(CONCAT('Remedial - ', asum.judul_asesmen))))
                              OR (LOWER(TRIM(pr.judul_program)) NOT LIKE 'remedial - %' AND pr.id_kelas_mata_pelajaran = NEW.id_kelas_mata_pelajaran)
                          )
                          AND NOT EXISTS (
                              SELECT 1 FROM `nilai_pengayaan_remedial` npr
                              WHERE npr.id_pengayaan_remedial = pr.id AND npr.id_siswa = NEW.id_siswa
                          );
                    END IF;
                END",

                "DROP TRIGGER IF EXISTS `trg_auto_remedial_upd_sumatif`",
                "CREATE TRIGGER `trg_auto_remedial_upd_sumatif` AFTER UPDATE ON `nilai_asesmen_sumatif` FOR EACH ROW
                BEGIN
                    /* v2_strict_kelas */
                    IF (NEW.nilai_angka < COALESCE(NULLIF(NEW.nilai_kktp, 0), 75.00)) THEN
                        INSERT INTO `nilai_pengayaan_remedial`
                            (`id_siswa`, `id_kelas_mata_pelajaran`, `id_pengayaan_remedial`, `id_guru`, `id_modul_ajar`, `id_tp`, `id_itp`, `pertemuan_ke`, `nilai_awal`, `nilai_akhir`, `nilai_kktp`, `predikat`, `status_tuntas`, `catatan_evaluasi`, `waktu_penilaian`)
                        SELECT
                            NEW.id_siswa, pr.id_kelas_mata_pelajaran, pr.id, COALESCE(pr.id_guru, NEW.id_guru), COALESCE(pr.id_modul_ajar, NEW.id_modul_ajar), COALESCE(pr.id_tp, NEW.id_tp), COALESCE(pr.id_itp, NEW.id_itp), COALESCE(pr.pertemuan_ke, NEW.pertemuan_ke, 1),
                            NEW.nilai_angka, NEW.nilai_angka, COALESCE(NULLIF(pr.kktp_minimal, 0), NULLIF(NEW.nilai_kktp, 0), 75.00), 'D', 'Belum Tuntas', 'Terdaftar otomatis oleh trigger database karena nilai sumatif di bawah KKTP.', NOW()
                        FROM `pengayaan_remedial` pr
                        JOIN `kelas_mata_pelajaran` kmp ON pr.id_kelas_mata_pelajaran = kmp.id
                        JOIN `siswa` s ON s.id = NEW.id_siswa
                        LEFT JOIN `asesmen_sumatif` asum ON asum.id = NEW.id_asesmen_sumatif
                        WHERE pr.kategori = 'Remedial'
                          AND kmp.id_kelas = s.id_kelas
                          AND NEW.nilai_angka < COALESCE(NULLIF(pr.kktp_minimal, 0), NULLIF(NEW.nilai_kktp, 0), 75.00)
                          AND (
                              (LOWER(TRIM(pr.judul_program)) LIKE 'remedial - %' AND asum.judul_asesmen IS NOT NULL AND LOWER(TRIM(pr.judul_program)) = LOWER(TRIM(CONCAT('Remedial - ', asum.judul_asesmen))))
                              OR (LOWER(TRIM(pr.judul_program)) NOT LIKE 'remedial - %' AND pr.id_kelas_mata_pelajaran = NEW.id_kelas_mata_pelajaran)
                          )
                          AND NOT EXISTS (
                              SELECT 1 FROM `nilai_pengayaan_remedial` npr
                              WHERE npr.id_pengayaan_remedial = pr.id AND npr.id_siswa = NEW.id_siswa
                          );
                    END IF;
                END",

                "DROP TRIGGER IF EXISTS `trg_auto_remedial_ins_formatif`",
                "CREATE TRIGGER `trg_auto_remedial_ins_formatif` AFTER INSERT ON `nilai_asesmen_formatif` FOR EACH ROW
                BEGIN
                    /* v2_strict_kelas */
                    IF (NEW.nilai_angka < COALESCE(NULLIF(NEW.nilai_kktp, 0), 75.00)) THEN
                        INSERT INTO `nilai_pengayaan_remedial`
                            (`id_siswa`, `id_kelas_mata_pelajaran`, `id_pengayaan_remedial`, `id_guru`, `id_modul_ajar`, `id_tp`, `id_itp`, `pertemuan_ke`, `nilai_awal`, `nilai_akhir`, `nilai_kktp`, `predikat`, `status_tuntas`, `catatan_evaluasi`, `waktu_penilaian`)
                        SELECT
                            NEW.id_siswa, pr.id_kelas_mata_pelajaran, pr.id, COALESCE(pr.id_guru, NEW.id_guru), COALESCE(pr.id_modul_ajar, NEW.id_modul_ajar), COALESCE(pr.id_tp, NEW.id_tp), COALESCE(pr.id_itp, NEW.id_itp), COALESCE(pr.pertemuan_ke, NEW.pertemuan_ke, 1),
                            NEW.nilai_angka, NEW.nilai_angka, COALESCE(NULLIF(pr.kktp_minimal, 0), NULLIF(NEW.nilai_kktp, 0), 75.00), 'D', 'Belum Tuntas', 'Terdaftar otomatis oleh trigger database karena nilai formatif di bawah KKTP.', NOW()
                        FROM `pengayaan_remedial` pr
                        JOIN `kelas_mata_pelajaran` kmp ON pr.id_kelas_mata_pelajaran = kmp.id
                        JOIN `siswa` s ON s.id = NEW.id_siswa
                        LEFT JOIN `asesmen_formatif` af ON af.id = NEW.id_asesmen_formatif
                        WHERE pr.kategori = 'Remedial'
                          AND kmp.id_kelas = s.id_kelas
                          AND NEW.nilai_angka < COALESCE(NULLIF(pr.kktp_minimal, 0), NULLIF(NEW.nilai_kktp, 0), 75.00)
                          AND (
                              (LOWER(TRIM(pr.judul_program)) LIKE 'remedial - %' AND af.judul_asesmen IS NOT NULL AND LOWER(TRIM(pr.judul_program)) = LOWER(TRIM(CONCAT('Remedial - ', af.judul_asesmen))))
                              OR (LOWER(TRIM(pr.judul_program)) NOT LIKE 'remedial - %' AND pr.id_kelas_mata_pelajaran = NEW.id_kelas_mata_pelajaran)
                          )
                          AND NOT EXISTS (
                              SELECT 1 FROM `nilai_pengayaan_remedial` npr
                              WHERE npr.id_pengayaan_remedial = pr.id AND npr.id_siswa = NEW.id_siswa
                          );
                    END IF;
                END",

                "DROP TRIGGER IF EXISTS `trg_auto_remedial_upd_formatif`",
                "CREATE TRIGGER `trg_auto_remedial_upd_formatif` AFTER UPDATE ON `nilai_asesmen_formatif` FOR EACH ROW
                BEGIN
                    /* v2_strict_kelas */
                    IF (NEW.nilai_angka < COALESCE(NULLIF(NEW.nilai_kktp, 0), 75.00)) THEN
                        INSERT INTO `nilai_pengayaan_remedial`
                            (`id_siswa`, `id_kelas_mata_pelajaran`, `id_pengayaan_remedial`, `id_guru`, `id_modul_ajar`, `id_tp`, `id_itp`, `pertemuan_ke`, `nilai_awal`, `nilai_akhir`, `nilai_kktp`, `predikat`, `status_tuntas`, `catatan_evaluasi`, `waktu_penilaian`)
                        SELECT
                            NEW.id_siswa, pr.id_kelas_mata_pelajaran, pr.id, COALESCE(pr.id_guru, NEW.id_guru), COALESCE(pr.id_modul_ajar, NEW.id_modul_ajar), COALESCE(pr.id_tp, NEW.id_tp), COALESCE(pr.id_itp, NEW.id_itp), COALESCE(pr.pertemuan_ke, NEW.pertemuan_ke, 1),
                            NEW.nilai_angka, NEW.nilai_angka, COALESCE(NULLIF(pr.kktp_minimal, 0), NULLIF(NEW.nilai_kktp, 0), 75.00), 'D', 'Belum Tuntas', 'Terdaftar otomatis oleh trigger database karena nilai formatif di bawah KKTP.', NOW()
                        FROM `pengayaan_remedial` pr
                        JOIN `kelas_mata_pelajaran` kmp ON pr.id_kelas_mata_pelajaran = kmp.id
                        JOIN `siswa` s ON s.id = NEW.id_siswa
                        LEFT JOIN `asesmen_formatif` af ON af.id = NEW.id_asesmen_formatif
                        WHERE pr.kategori = 'Remedial'
                          AND kmp.id_kelas = s.id_kelas
                          AND NEW.nilai_angka < COALESCE(NULLIF(pr.kktp_minimal, 0), NULLIF(NEW.nilai_kktp, 0), 75.00)
                          AND (
                              (LOWER(TRIM(pr.judul_program)) LIKE 'remedial - %' AND af.judul_asesmen IS NOT NULL AND LOWER(TRIM(pr.judul_program)) = LOWER(TRIM(CONCAT('Remedial - ', af.judul_asesmen))))
                              OR (LOWER(TRIM(pr.judul_program)) NOT LIKE 'remedial - %' AND pr.id_kelas_mata_pelajaran = NEW.id_kelas_mata_pelajaran)
                          )
                          AND NOT EXISTS (
                              SELECT 1 FROM `nilai_pengayaan_remedial` npr
                              WHERE npr.id_pengayaan_remedial = pr.id AND npr.id_siswa = NEW.id_siswa
                          );
                    END IF;
                END",

                "DROP TRIGGER IF EXISTS `trg_auto_remedial_ins_diagnostik`",
                "CREATE TRIGGER `trg_auto_remedial_ins_diagnostik` AFTER INSERT ON `nilai_asesmen_diagnostik` FOR EACH ROW
                BEGIN
                    /* v2_strict_kelas */
                    IF (NEW.nilai_angka < COALESCE(NULLIF(NEW.nilai_kktp, 0), 75.00)) THEN
                        INSERT INTO `nilai_pengayaan_remedial`
                            (`id_siswa`, `id_kelas_mata_pelajaran`, `id_pengayaan_remedial`, `id_guru`, `id_modul_ajar`, `id_tp`, `id_itp`, `pertemuan_ke`, `nilai_awal`, `nilai_akhir`, `nilai_kktp`, `predikat`, `status_tuntas`, `catatan_evaluasi`, `waktu_penilaian`)
                        SELECT
                            NEW.id_siswa, pr.id_kelas_mata_pelajaran, pr.id, COALESCE(pr.id_guru, NEW.id_guru), COALESCE(pr.id_modul_ajar, NEW.id_modul_ajar), COALESCE(pr.id_tp, NEW.id_tp), COALESCE(pr.id_itp, NEW.id_itp), COALESCE(pr.pertemuan_ke, NEW.pertemuan_ke, 1),
                            NEW.nilai_angka, NEW.nilai_angka, COALESCE(NULLIF(pr.kktp_minimal, 0), NULLIF(NEW.nilai_kktp, 0), 75.00), 'D', 'Belum Tuntas', 'Terdaftar otomatis oleh trigger database karena nilai diagnostik di bawah KKTP.', NOW()
                        FROM `pengayaan_remedial` pr
                        JOIN `kelas_mata_pelajaran` kmp ON pr.id_kelas_mata_pelajaran = kmp.id
                        JOIN `siswa` s ON s.id = NEW.id_siswa
                        LEFT JOIN `asesmen_diagnostik` ad ON ad.id = NEW.id_asesmen_diagnostik
                        WHERE pr.kategori = 'Remedial'
                          AND kmp.id_kelas = s.id_kelas
                          AND NEW.nilai_angka < COALESCE(NULLIF(pr.kktp_minimal, 0), NULLIF(NEW.nilai_kktp, 0), 75.00)
                          AND (
                              (LOWER(TRIM(pr.judul_program)) LIKE 'remedial - %' AND ad.judul_asesmen IS NOT NULL AND LOWER(TRIM(pr.judul_program)) = LOWER(TRIM(CONCAT('Remedial - ', ad.judul_asesmen))))
                              OR (LOWER(TRIM(pr.judul_program)) NOT LIKE 'remedial - %' AND pr.id_kelas_mata_pelajaran = NEW.id_kelas_mata_pelajaran)
                          )
                          AND NOT EXISTS (
                              SELECT 1 FROM `nilai_pengayaan_remedial` npr
                              WHERE npr.id_pengayaan_remedial = pr.id AND npr.id_siswa = NEW.id_siswa
                          );
                    END IF;
                END",

                "DROP TRIGGER IF EXISTS `trg_auto_remedial_upd_diagnostik`",
                "CREATE TRIGGER `trg_auto_remedial_upd_diagnostik` AFTER UPDATE ON `nilai_asesmen_diagnostik` FOR EACH ROW
                BEGIN
                    /* v2_strict_kelas */
                    IF (NEW.nilai_angka < COALESCE(NULLIF(NEW.nilai_kktp, 0), 75.00)) THEN
                        INSERT INTO `nilai_pengayaan_remedial`
                            (`id_siswa`, `id_kelas_mata_pelajaran`, `id_pengayaan_remedial`, `id_guru`, `id_modul_ajar`, `id_tp`, `id_itp`, `pertemuan_ke`, `nilai_awal`, `nilai_akhir`, `nilai_kktp`, `predikat`, `status_tuntas`, `catatan_evaluasi`, `waktu_penilaian`)
                        SELECT
                            NEW.id_siswa, pr.id_kelas_mata_pelajaran, pr.id, COALESCE(pr.id_guru, NEW.id_guru), COALESCE(pr.id_modul_ajar, NEW.id_modul_ajar), COALESCE(pr.id_tp, NEW.id_tp), COALESCE(pr.id_itp, NEW.id_itp), COALESCE(pr.pertemuan_ke, NEW.pertemuan_ke, 1),
                            NEW.nilai_angka, NEW.nilai_angka, COALESCE(NULLIF(pr.kktp_minimal, 0), NULLIF(NEW.nilai_kktp, 0), 75.00), 'D', 'Belum Tuntas', 'Terdaftar otomatis oleh trigger database karena nilai diagnostik di bawah KKTP.', NOW()
                        FROM `pengayaan_remedial` pr
                        JOIN `kelas_mata_pelajaran` kmp ON pr.id_kelas_mata_pelajaran = kmp.id
                        JOIN `siswa` s ON s.id = NEW.id_siswa
                        LEFT JOIN `asesmen_diagnostik` ad ON ad.id = NEW.id_asesmen_diagnostik
                        WHERE pr.kategori = 'Remedial'
                          AND kmp.id_kelas = s.id_kelas
                          AND NEW.nilai_angka < COALESCE(NULLIF(pr.kktp_minimal, 0), NULLIF(NEW.nilai_kktp, 0), 75.00)
                          AND (
                              (LOWER(TRIM(pr.judul_program)) LIKE 'remedial - %' AND ad.judul_asesmen IS NOT NULL AND LOWER(TRIM(pr.judul_program)) = LOWER(TRIM(CONCAT('Remedial - ', ad.judul_asesmen))))
                              OR (LOWER(TRIM(pr.judul_program)) NOT LIKE 'remedial - %' AND pr.id_kelas_mata_pelajaran = NEW.id_kelas_mata_pelajaran)
                          )
                          AND NOT EXISTS (
                              SELECT 1 FROM `nilai_pengayaan_remedial` npr
                              WHERE npr.id_pengayaan_remedial = pr.id AND npr.id_siswa = NEW.id_siswa
                          );
                    END IF;
                END",

                "DROP TRIGGER IF EXISTS `trg_auto_remedial_ins_program`",
                "CREATE TRIGGER `trg_auto_remedial_ins_program` AFTER INSERT ON `pengayaan_remedial` FOR EACH ROW
                BEGIN
                    /* v2_strict_kelas */
                    IF (NEW.kategori = 'Remedial') THEN
                        INSERT INTO `nilai_pengayaan_remedial`
                            (`id_siswa`, `id_kelas_mata_pelajaran`, `id_pengayaan_remedial`, `id_guru`, `id_modul_ajar`, `id_tp`, `id_itp`, `pertemuan_ke`, `nilai_awal`, `nilai_akhir`, `nilai_kktp`, `predikat`, `status_tuntas`, `catatan_evaluasi`, `waktu_penilaian`)
                        SELECT
                            src.id_siswa, NEW.id_kelas_mata_pelajaran, NEW.id, NEW.id_guru, NEW.id_modul_ajar, NEW.id_tp, NEW.id_itp, COALESCE(NEW.pertemuan_ke, 1),
                            MIN(src.nilai_angka), MIN(src.nilai_angka), COALESCE(NULLIF(NEW.kktp_minimal, 0), 75.00), 'D', 'Belum Tuntas', 'Terdaftar otomatis oleh trigger saat program remedial dibuat.', NOW()
                        FROM (
                            SELECT ns.id_siswa, ns.nilai_angka
                            FROM `nilai_asesmen_sumatif` ns
                            JOIN `siswa` s ON s.id = ns.id_siswa
                            LEFT JOIN `asesmen_sumatif` asum ON asum.id = ns.id_asesmen_sumatif
                            JOIN `kelas_mata_pelajaran` kmp_pr ON kmp_pr.id = NEW.id_kelas_mata_pelajaran
                            WHERE s.id_kelas = kmp_pr.id_kelas
                              AND ns.nilai_angka < COALESCE(NULLIF(NEW.kktp_minimal, 0), NULLIF(ns.nilai_kktp, 0), 75.00)
                              AND (
                                  (LOWER(TRIM(NEW.judul_program)) LIKE 'remedial - %' AND asum.judul_asesmen IS NOT NULL AND LOWER(TRIM(NEW.judul_program)) = LOWER(TRIM(CONCAT('Remedial - ', asum.judul_asesmen))))
                                  OR (LOWER(TRIM(NEW.judul_program)) NOT LIKE 'remedial - %' AND ns.id_kelas_mata_pelajaran = NEW.id_kelas_mata_pelajaran)
                              )
                            UNION ALL
                            SELECT nf.id_siswa, nf.nilai_angka
                            FROM `nilai_asesmen_formatif` nf
                            JOIN `siswa` s ON s.id = nf.id_siswa
                            LEFT JOIN `asesmen_formatif` af ON af.id = nf.id_asesmen_formatif
                            JOIN `kelas_mata_pelajaran` kmp_pr ON kmp_pr.id = NEW.id_kelas_mata_pelajaran
                            WHERE s.id_kelas = kmp_pr.id_kelas
                              AND nf.nilai_angka < COALESCE(NULLIF(NEW.kktp_minimal, 0), NULLIF(nf.nilai_kktp, 0), 75.00)
                              AND (
                                  (LOWER(TRIM(NEW.judul_program)) LIKE 'remedial - %' AND af.judul_asesmen IS NOT NULL AND LOWER(TRIM(NEW.judul_program)) = LOWER(TRIM(CONCAT('Remedial - ', af.judul_asesmen))))
                                  OR (LOWER(TRIM(NEW.judul_program)) NOT LIKE 'remedial - %' AND nf.id_kelas_mata_pelajaran = NEW.id_kelas_mata_pelajaran)
                              )
                            UNION ALL
                            SELECT nd.id_siswa, nd.nilai_angka
                            FROM `nilai_asesmen_diagnostik` nd
                            JOIN `siswa` s ON s.id = nd.id_siswa
                            LEFT JOIN `asesmen_diagnostik` ad ON ad.id = nd.id_asesmen_diagnostik
                            JOIN `kelas_mata_pelajaran` kmp_pr ON kmp_pr.id = NEW.id_kelas_mata_pelajaran
                            WHERE s.id_kelas = kmp_pr.id_kelas
                              AND nd.nilai_angka < COALESCE(NULLIF(NEW.kktp_minimal, 0), NULLIF(nd.nilai_kktp, 0), 75.00)
                              AND (
                                  (LOWER(TRIM(NEW.judul_program)) LIKE 'remedial - %' AND ad.judul_asesmen IS NOT NULL AND LOWER(TRIM(NEW.judul_program)) = LOWER(TRIM(CONCAT('Remedial - ', ad.judul_asesmen))))
                                  OR (LOWER(TRIM(NEW.judul_program)) NOT LIKE 'remedial - %' AND nd.id_kelas_mata_pelajaran = NEW.id_kelas_mata_pelajaran)
                              )
                        ) src
                        WHERE NOT EXISTS (
                            SELECT 1 FROM `nilai_pengayaan_remedial` npr
                            WHERE npr.id_pengayaan_remedial = NEW.id AND npr.id_siswa = src.id_siswa
                        )
                        GROUP BY src.id_siswa;
                    END IF;
                END",

                "DROP TRIGGER IF EXISTS `trg_auto_remedial_upd_program`",
                "CREATE TRIGGER `trg_auto_remedial_upd_program` AFTER UPDATE ON `pengayaan_remedial` FOR EACH ROW
                BEGIN
                    /* v2_strict_kelas */
                    IF (NEW.kategori = 'Remedial') THEN
                        INSERT INTO `nilai_pengayaan_remedial`
                            (`id_siswa`, `id_kelas_mata_pelajaran`, `id_pengayaan_remedial`, `id_guru`, `id_modul_ajar`, `id_tp`, `id_itp`, `pertemuan_ke`, `nilai_awal`, `nilai_akhir`, `nilai_kktp`, `predikat`, `status_tuntas`, `catatan_evaluasi`, `waktu_penilaian`)
                        SELECT
                            src.id_siswa, NEW.id_kelas_mata_pelajaran, NEW.id, NEW.id_guru, NEW.id_modul_ajar, NEW.id_tp, NEW.id_itp, COALESCE(NEW.pertemuan_ke, 1),
                            MIN(src.nilai_angka), MIN(src.nilai_angka), COALESCE(NULLIF(NEW.kktp_minimal, 0), 75.00), 'D', 'Belum Tuntas', 'Terdaftar otomatis oleh trigger saat program remedial diperbarui.', NOW()
                        FROM (
                            SELECT ns.id_siswa, ns.nilai_angka
                            FROM `nilai_asesmen_sumatif` ns
                            JOIN `siswa` s ON s.id = ns.id_siswa
                            LEFT JOIN `asesmen_sumatif` asum ON asum.id = ns.id_asesmen_sumatif
                            JOIN `kelas_mata_pelajaran` kmp_pr ON kmp_pr.id = NEW.id_kelas_mata_pelajaran
                            WHERE s.id_kelas = kmp_pr.id_kelas
                              AND ns.nilai_angka < COALESCE(NULLIF(NEW.kktp_minimal, 0), NULLIF(ns.nilai_kktp, 0), 75.00)
                              AND (
                                  (LOWER(TRIM(NEW.judul_program)) LIKE 'remedial - %' AND asum.judul_asesmen IS NOT NULL AND LOWER(TRIM(NEW.judul_program)) = LOWER(TRIM(CONCAT('Remedial - ', asum.judul_asesmen))))
                                  OR (LOWER(TRIM(NEW.judul_program)) NOT LIKE 'remedial - %' AND ns.id_kelas_mata_pelajaran = NEW.id_kelas_mata_pelajaran)
                              )
                            UNION ALL
                            SELECT nf.id_siswa, nf.nilai_angka
                            FROM `nilai_asesmen_formatif` nf
                            JOIN `siswa` s ON s.id = nf.id_siswa
                            LEFT JOIN `asesmen_formatif` af ON af.id = nf.id_asesmen_formatif
                            JOIN `kelas_mata_pelajaran` kmp_pr ON kmp_pr.id = NEW.id_kelas_mata_pelajaran
                            WHERE s.id_kelas = kmp_pr.id_kelas
                              AND nf.nilai_angka < COALESCE(NULLIF(NEW.kktp_minimal, 0), NULLIF(nf.nilai_kktp, 0), 75.00)
                              AND (
                                  (LOWER(TRIM(NEW.judul_program)) LIKE 'remedial - %' AND af.judul_asesmen IS NOT NULL AND LOWER(TRIM(NEW.judul_program)) = LOWER(TRIM(CONCAT('Remedial - ', af.judul_asesmen))))
                                  OR (LOWER(TRIM(NEW.judul_program)) NOT LIKE 'remedial - %' AND nf.id_kelas_mata_pelajaran = NEW.id_kelas_mata_pelajaran)
                              )
                            UNION ALL
                            SELECT nd.id_siswa, nd.nilai_angka
                            FROM `nilai_asesmen_diagnostik` nd
                            JOIN `siswa` s ON s.id = nd.id_siswa
                            LEFT JOIN `asesmen_diagnostik` ad ON ad.id = nd.id_asesmen_diagnostik
                            JOIN `kelas_mata_pelajaran` kmp_pr ON kmp_pr.id = NEW.id_kelas_mata_pelajaran
                            WHERE s.id_kelas = kmp_pr.id_kelas
                              AND nd.nilai_angka < COALESCE(NULLIF(NEW.kktp_minimal, 0), NULLIF(nd.nilai_kktp, 0), 75.00)
                              AND (
                                  (LOWER(TRIM(NEW.judul_program)) LIKE 'remedial - %' AND ad.judul_asesmen IS NOT NULL AND LOWER(TRIM(NEW.judul_program)) = LOWER(TRIM(CONCAT('Remedial - ', ad.judul_asesmen))))
                                  OR (LOWER(TRIM(NEW.judul_program)) NOT LIKE 'remedial - %' AND nd.id_kelas_mata_pelajaran = NEW.id_kelas_mata_pelajaran)
                              )
                        ) src
                        WHERE NOT EXISTS (
                            SELECT 1 FROM `nilai_pengayaan_remedial` npr
                            WHERE npr.id_pengayaan_remedial = NEW.id AND npr.id_siswa = src.id_siswa
                        )
                        GROUP BY src.id_siswa;
                    END IF;
                END"
            ];

            foreach ($triggerSqls as $tsql) {
                try {
                    $pdo->exec($tsql);
                } catch (Throwable $te) {
                    // Abaikan jika user DB tidak memiliki privilege TRIGGER pada shared hosting tertentu
                }
            }
        }

        // 3. Backfill otomatis untuk program pengayaan_remedial sesuai kelas siswa & asesmen acuan (nilai < KKTP)
        $pdo->exec("
            INSERT INTO `nilai_pengayaan_remedial`
                (`id_siswa`, `id_kelas_mata_pelajaran`, `id_pengayaan_remedial`, `id_guru`, `id_modul_ajar`, `id_tp`, `id_itp`, `pertemuan_ke`, `nilai_awal`, `nilai_akhir`, `nilai_kktp`, `predikat`, `status_tuntas`, `catatan_evaluasi`, `waktu_penilaian`)
            SELECT
                src.id_siswa, pr.id_kelas_mata_pelajaran, pr.id, pr.id_guru, pr.id_modul_ajar, pr.id_tp, pr.id_itp, COALESCE(pr.pertemuan_ke, 1),
                MIN(src.nilai_angka), MIN(src.nilai_angka), COALESCE(NULLIF(pr.kktp_minimal, 0), 75.00), 'D', 'Belum Tuntas', 'Sinkronisasi otomatis peserta remedial sesuai kelas & asesmen (< KKTP).', NOW()
            FROM `pengayaan_remedial` pr
            JOIN `kelas_mata_pelajaran` kmp_pr ON kmp_pr.id = pr.id_kelas_mata_pelajaran
            JOIN (
                SELECT ns.id_siswa, ns.id_kelas_mata_pelajaran, s.id_kelas, asum.judul_asesmen, ns.nilai_angka, ns.nilai_kktp
                FROM `nilai_asesmen_sumatif` ns
                JOIN `siswa` s ON s.id = ns.id_siswa
                LEFT JOIN `asesmen_sumatif` asum ON asum.id = ns.id_asesmen_sumatif
                UNION ALL
                SELECT nf.id_siswa, nf.id_kelas_mata_pelajaran, s.id_kelas, af.judul_asesmen, nf.nilai_angka, nf.nilai_kktp
                FROM `nilai_asesmen_formatif` nf
                JOIN `siswa` s ON s.id = nf.id_siswa
                LEFT JOIN `asesmen_formatif` af ON af.id = nf.id_asesmen_formatif
                UNION ALL
                SELECT nd.id_siswa, nd.id_kelas_mata_pelajaran, s.id_kelas, ad.judul_asesmen, nd.nilai_angka, nd.nilai_kktp
                FROM `nilai_asesmen_diagnostik` nd
                JOIN `siswa` s ON s.id = nd.id_siswa
                LEFT JOIN `asesmen_diagnostik` ad ON ad.id = nd.id_asesmen_diagnostik
            ) src
              ON src.id_kelas = kmp_pr.id_kelas
             AND src.nilai_angka < COALESCE(NULLIF(pr.kktp_minimal, 0), NULLIF(src.nilai_kktp, 0), 75.00)
             AND (
                 (LOWER(TRIM(pr.judul_program)) LIKE 'remedial - %' AND src.judul_asesmen IS NOT NULL AND LOWER(TRIM(pr.judul_program)) = LOWER(TRIM(CONCAT('Remedial - ', src.judul_asesmen))))
                 OR (LOWER(TRIM(pr.judul_program)) NOT LIKE 'remedial - %' AND src.id_kelas_mata_pelajaran = pr.id_kelas_mata_pelajaran)
             )
            WHERE pr.kategori = 'Remedial'
              AND NOT EXISTS (
                  SELECT 1 FROM `nilai_pengayaan_remedial` npr
                  WHERE npr.id_pengayaan_remedial = pr.id AND npr.id_siswa = src.id_siswa
              )
            GROUP BY pr.id, src.id_siswa
        ");
    } catch (Throwable $e) {
        // Silent fail agar tidak mengganggu eksekusi halaman
    }
}

/**
 * Menentukan apakah suatu asesmen menggunakan Penilaian Acuan Norma (PAN).
 * Aturan Paten Sistem Penilaian:
 * - Asesmen Formatif: 100% PAP (Penilaian Acuan Patokan, berpatokan pada KKTP) -> return false
 * - Asesmen Sumatif Lingkup Materi (SLM/LM): 100% PAP (berpatokan pada KKTP) -> return false
 * - Asesmen Sumatif Tengah Semester (STS): 100% PAN (Penilaian Acuan Norma, berpatokan pada rata-rata kelas) -> return true
 * - Asesmen Sumatif Akhir Semester (SAS / ASAS): 100% PAN (berpatokan pada rata-rata kelas) -> return true
 *
 * @param string $kategori
 * @param string|null $jenisAtauSubtipe
 * @return bool
 */
function isAssessmentPAN($kategori, $jenisAtauSubtipe): bool {
    $kategori = strtolower(trim((string)$kategori));
    $jenis = strtolower(trim((string)$jenisAtauSubtipe));

    if ($kategori === 'sumatif') {
        if (strpos($jenis, 'tengah semester') !== false || strpos($jenis, 'sts') !== false) {
            return true;
        }
        if (strpos($jenis, 'akhir semester') !== false || strpos($jenis, 'sas') !== false || strpos($jenis, 'asas') !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Menghitung Ambang Batas Kelulusan & Kriteria Asesmen (PAP vs PAN)
 * - Jika PAP (Formatif, Diagnostik, Sumatif Lingkup Materi):
 *   Ambang batas = KKTP asesmen / mapel.
 * - Jika PAN (Sumatif Tengah Semester, Sumatif Akhir Semester):
 *   Ambang batas = Rata-rata Nilai Kelas (Mean) dari rombel siswa pada asesmen ini.
 *   Jika belum ada data nilai sekelas, fallback ke KKTP.
 *
 * @param PDO $pdo
 * @param string $kategori 'sumatif' | 'formatif' | 'diagnostik' | 'remedial'
 * @param int $idAsesmen
 * @param int $idKelas
 * @param float $fallbackKktp
 * @return array
 */
function getAssessmentPassingCriteria($pdo, $kategori, $idAsesmen, $idKelas = 0, $fallbackKktp = 75.00): array {
    $kategori = strtolower(trim((string)$kategori));
    $idAsesmen = (int)$idAsesmen;
    $idKelas = (int)$idKelas;
    $fallbackKktp = (float)$fallbackKktp > 0 ? (float)$fallbackKktp : 75.00;

    $res = [
        'kategori'          => $kategori,
        'id_asesmen'        => $idAsesmen,
        'id_kelas'          => $idKelas,
        'metode'            => 'PAP',
        'kktp'              => $fallbackKktp,
        'rata_rata_kelas'   => null,
        'ambang_batas'      => $fallbackKktp,
        'keterangan_norma'  => 'PAP (Target KKTP: ' . number_format($fallbackKktp, 0) . ')'
    ];

    if ($idAsesmen <= 0) {
        return $res;
    }

    if ($kategori === 'sumatif') {
        $stmtMeta = $pdo->prepare("SELECT id, judul_asesmen, jenis_sumatif, kktp FROM asesmen_sumatif WHERE id = ? LIMIT 1");
        $stmtMeta->execute([$idAsesmen]);
        $asm = $stmtMeta->fetch(PDO::FETCH_ASSOC);
        if ($asm) {
            $kktp = (float)($asm['kktp'] ?? $fallbackKktp);
            if ($kktp <= 0) $kktp = $fallbackKktp;
            $res['kktp'] = $kktp;
            $res['ambang_batas'] = $kktp;

            if (isAssessmentPAN('sumatif', $asm['jenis_sumatif'])) {
                $res['metode'] = 'PAN';
                // Hitung rata-rata kelas (Norma)
                $sqlAvg = "
                    SELECT AVG(nas.nilai_angka) AS mean_kelas, COUNT(*) AS total_dinilai
                    FROM nilai_asesmen_sumatif nas
                    JOIN siswa s ON nas.id_siswa = s.id
                    WHERE nas.id_asesmen_sumatif = ? AND nas.nilai_angka IS NOT NULL
                ";
                $paramsAvg = [$idAsesmen];
                if ($idKelas > 0) {
                    $sqlAvg .= " AND s.id_kelas = ? ";
                    $paramsAvg[] = $idKelas;
                }
                $stmtAvg = $pdo->prepare($sqlAvg);
                $stmtAvg->execute($paramsAvg);
                $avgData = $stmtAvg->fetch(PDO::FETCH_ASSOC);

                if ($avgData && $avgData['mean_kelas'] !== null && (int)$avgData['total_dinilai'] > 0) {
                    $meanKelas = round((float)$avgData['mean_kelas'], 2);
                    $res['rata_rata_kelas'] = $meanKelas;
                    $res['ambang_batas'] = $meanKelas;
                    $res['keterangan_norma'] = 'PAN (Norma Kelas: ' . number_format($meanKelas, 1) . ')';
                } else {
                    $res['rata_rata_kelas'] = $kktp;
                    $res['ambang_batas'] = $kktp;
                    $res['keterangan_norma'] = 'PAN (Norma Awal / KKTP: ' . number_format($kktp, 0) . ')';
                }
            } else {
                $res['keterangan_norma'] = 'PAP (KKTP: ' . number_format($kktp, 0) . ')';
            }
        }
    } elseif ($kategori === 'formatif') {
        $kktp = $fallbackKktp;
        try {
            $stmtMeta = $pdo->prepare("
                SELECT COALESCE(mp.kktp, 75.00)
                FROM asesmen_formatif af
                JOIN kelas_mata_pelajaran kmp ON af.id_kelas_mata_pelajaran = kmp.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                WHERE af.id = ? LIMIT 1
            ");
            $stmtMeta->execute([$idAsesmen]);
            $kVal = $stmtMeta->fetchColumn();
            if ($kVal !== false && (float)$kVal > 0) {
                $kktp = (float)$kVal;
            }
        } catch (Throwable $e) {}
        $res['kktp'] = $kktp;
        $res['ambang_batas'] = $kktp;
        $res['keterangan_norma'] = 'PAP (KKTP: ' . number_format($kktp, 0) . ')';
    }

    return $res;
}

/**
 * Sinkronisasi & Rekalkulasi Status Kelulusan Seluruh Siswa Rombel Kelas pada Asesmen PAN (STS / SAS).
 * Memastikan jika rata-rata kelas bergerak, status tuntas seluruh siswa sekelas sinkron terhadap norma kelas.
 *
 * @param PDO $pdo
 * @param int $idAsesmenSumatif
 * @param int $idKelas
 * @return float|null Nilai rata-rata kelas terbaru
 */
function recalculateClassPANStatus($pdo, $idAsesmenSumatif, $idKelas): ?float {
    $idAsesmenSumatif = (int)$idAsesmenSumatif;
    $idKelas = (int)$idKelas;
    if ($idAsesmenSumatif <= 0 || $idKelas <= 0) return null;

    $stmtSm = $pdo->prepare("SELECT jenis_sumatif, kktp FROM asesmen_sumatif WHERE id = ? LIMIT 1");
    $stmtSm->execute([$idAsesmenSumatif]);
    $sm = $stmtSm->fetch(PDO::FETCH_ASSOC);
    if (!$sm || !isAssessmentPAN('sumatif', $sm['jenis_sumatif'])) {
        return null; // Bukan asesmen PAN, tidak perlu rekalkulasi norma kelas
    }

    $stmtAvg = $pdo->prepare("
        SELECT AVG(nas.nilai_angka) AS mean_kelas
        FROM nilai_asesmen_sumatif nas
        JOIN siswa s ON nas.id_siswa = s.id
        WHERE nas.id_asesmen_sumatif = ? AND s.id_kelas = ? AND nas.nilai_angka IS NOT NULL
    ");
    $stmtAvg->execute([$idAsesmenSumatif, $idKelas]);
    $meanVal = $stmtAvg->fetchColumn();
    if ($meanVal === false || $meanVal === null) return null;

    $meanKelas = round((float)$meanVal, 2);

    // Update status_tuntas seluruh siswa di kelas tersebut sesuai norma rata-rata kelas
    $stmtUpd = $pdo->prepare("
        UPDATE nilai_asesmen_sumatif nas
        JOIN siswa s ON nas.id_siswa = s.id
        SET nas.status_tuntas = CASE 
                WHEN nas.status_remedial = 'Disetujui' THEN 'Tuntas'
                WHEN nas.nilai_angka >= ? THEN 'Tuntas'
                ELSE 'Belum Tuntas'
            END,
            nas.catatan_evaluasi = CONCAT(
                'Hasil penilaian beracuan norma kelompok (PAN). Batas kelulusan rata-rata kelas: ', ?,
                CASE WHEN nas.nilai_angka >= ? THEN ' (Memenuhi Norma Kelas)' ELSE ' (Di bawah Norma Kelas - Perlu Remedial)' END
            )
        WHERE nas.id_asesmen_sumatif = ? AND s.id_kelas = ? AND nas.nilai_angka IS NOT NULL
    ");
    $stmtUpd->execute([$meanKelas, $meanKelas, $meanKelas, $idAsesmenSumatif, $idKelas]);

    return $meanKelas;
}

/**
 * Ambil daftar siswa yang memenuhi syarat untuk program Remedial / Pengayaan tertentu.
 * Wajib memenuhi:
 * 1. Siswa berada di kelas (id_kelas) yang sama dengan program pengayaan_remedial.
 * 2. Nilai berasal dari asesmen yang sesuai dengan program remedial/pengayaan tersebut.
 * 3. Nilai < Ambang Batas untuk Remedial (atau Nilai >= Ambang Batas untuk Pengayaan):
 *    - Asesmen Formatif & Sumatif LM: menggunakan PAP (KKTP).
 *    - Asesmen STS & SAS: menggunakan PAN (Rata-rata Kelas).
 *
 * @param PDO $pdo
 * @param int $idPengayaanRemedial
 * @param int|null $idSiswaFilter Jika diisi, hanya mengecek 1 siswa tersebut
 * @return array Daftar siswa yang memenuhi syarat beserta snapshot nilai
 */
function getEligibleRemedialStudents($pdo, $idPengayaanRemedial, $idSiswaFilter = null) {
    ensureRemedialDatabaseTriggers($pdo);

    $stmtPr = $pdo->prepare("
        SELECT pr.*, kmp.id_kelas, kmp.id_mata_pelajaran,
               COALESCE(NULLIF(pr.kktp_minimal, 0), 75.00) AS kktp_acuan
        FROM pengayaan_remedial pr
        JOIN kelas_mata_pelajaran kmp ON pr.id_kelas_mata_pelajaran = kmp.id
        WHERE pr.id = ?
        LIMIT 1
    ");
    $stmtPr->execute([(int)$idPengayaanRemedial]);
    $pr = $stmtPr->fetch(PDO::FETCH_ASSOC);
    if (!$pr) {
        return [];
    }

    $idPr        = (int)$pr['id'];
    $idKmp       = (int)$pr['id_kelas_mata_pelajaran'];
    $idKelas     = (int)$pr['id_kelas'];
    $idMapel     = (int)$pr['id_mata_pelajaran'];
    $pertemuanKe = (int)($pr['pertemuan_ke'] ?? 1);
    $kktpAcuan   = (float)($pr['kktp_acuan'] ?? 75.00);
    $isPengayaan = (strcasecmp(trim((string)($pr['kategori'] ?? 'Remedial')), 'Pengayaan') === 0);

    // Deteksi apakah judul program merujuk ke judul asesmen spesifik ("Remedial - [Judul]")
    $sourceJudul = null;
    if (preg_match('/^(?:Remedial|Pengayaan)\s*-\s*(.+)$/i', trim((string)($pr['judul_program'] ?? '')), $m)) {
        $sourceJudul = trim($m[1]);
    }
    $sourceJudulLower = $sourceJudul !== null ? strtolower($sourceJudul) : '';

    // WAJIB: Hanya ambil siswa yang berada di kelas sasaran program remedial/pengayaan ini (s.id_kelas = $idKelas)
    if ($idSiswaFilter !== null) {
        $stmtSiswa = $pdo->prepare("SELECT s.id, s.nis, s.nisn, s.nama_lengkap, s.id_kelas FROM siswa s WHERE s.id = ? AND s.id_kelas = ?");
        $stmtSiswa->execute([(int)$idSiswaFilter, $idKelas]);
    } else {
        $stmtSiswa = $pdo->prepare("
            SELECT s.id, s.nis, s.nisn, s.nama_lengkap, s.id_kelas
            FROM siswa s
            WHERE s.id_kelas = ?
            ORDER BY s.nama_lengkap ASC
        ");
        $stmtSiswa->execute([$idKelas]);
    }
    $siswaRows = $stmtSiswa->fetchAll(PDO::FETCH_ASSOC);
    if (empty($siswaRows)) {
        return [];
    }

    // Cache ambang batas PAN / PAP per id_asesmen_sumatif agar efisien
    $criteriaCache = [];

    // Fungsi pengecekan kelayakan berdasarkan nilai terhadap Ambang Batas (PAP = KKTP, PAN = Rata-rata Kelas)
    $checkIsFailed = function(array $row, float $defaultKktp) use ($pdo, $idKelas, &$criteriaCache): bool {
        if (!isset($row['nilai_angka']) || $row['nilai_angka'] === null || $row['nilai_angka'] === '') {
            return false;
        }
        $nilai = (float)$row['nilai_angka'];
        $jenis = $row['jenis_sumatif'] ?? '';
        $srcId = (int)($row['id_asesmen_src'] ?? 0);

        if (!empty($jenis) && isAssessmentPAN('sumatif', $jenis) && $srcId > 0) {
            if (!isset($criteriaCache[$srcId])) {
                $criteriaCache[$srcId] = getAssessmentPassingCriteria($pdo, 'sumatif', $srcId, $idKelas, $defaultKktp);
            }
            $threshold = (float)$criteriaCache[$srcId]['ambang_batas'];
            return ($nilai < $threshold);
        }

        $kktp = (!empty($row['nilai_kktp']) && (float)$row['nilai_kktp'] > 0) ? (float)$row['nilai_kktp'] : $defaultKktp;
        return ($nilai < $kktp);
    };

    $checkIsPassed = function(array $row, float $defaultKktp) use ($pdo, $idKelas, &$criteriaCache): bool {
        if (!isset($row['nilai_angka']) || $row['nilai_angka'] === null || $row['nilai_angka'] === '') {
            return false;
        }
        $nilai = (float)$row['nilai_angka'];
        $jenis = $row['jenis_sumatif'] ?? '';
        $srcId = (int)($row['id_asesmen_src'] ?? 0);

        if (!empty($jenis) && isAssessmentPAN('sumatif', $jenis) && $srcId > 0) {
            if (!isset($criteriaCache[$srcId])) {
                $criteriaCache[$srcId] = getAssessmentPassingCriteria($pdo, 'sumatif', $srcId, $idKelas, $defaultKktp);
            }
            $threshold = (float)$criteriaCache[$srcId]['ambang_batas'];
            return ($nilai >= $threshold);
        }

        $kktp = (!empty($row['nilai_kktp']) && (float)$row['nilai_kktp'] > 0) ? (float)$row['nilai_kktp'] : $defaultKktp;
        return ($nilai >= $kktp);
    };

    // Query helper untuk mengambil nilai siswa pada KMP / Mapel di kelasnya atau Asesmen Sumber terkait
    $stmtDiag = $pdo->prepare("
        SELECT nd.id, nd.id_asesmen_diagnostik AS id_asesmen_src, nd.nilai_angka, nd.nilai_kktp, nd.status_tuntas, nd.pertemuan_ke,
               ad.judul_asesmen, ad.pertemuan_ke AS asm_pertemuan
        FROM nilai_asesmen_diagnostik nd
        LEFT JOIN asesmen_diagnostik ad ON nd.id_asesmen_diagnostik = ad.id
        LEFT JOIN kelas_mata_pelajaran kmp_d ON COALESCE(ad.id_kelas_mata_pelajaran, nd.id_kelas_mata_pelajaran) = kmp_d.id
        WHERE nd.id_siswa = ?
          AND (nd.id_kelas_mata_pelajaran = ? OR ad.id_kelas_mata_pelajaran = ? OR (kmp_d.id_kelas = ? AND kmp_d.id_mata_pelajaran = ?) OR (? != '' AND LOWER(TRIM(ad.judul_asesmen)) = ?))
        ORDER BY nd.id DESC
    ");

    $stmtFmt = $pdo->prepare("
        SELECT nf.id, nf.id_asesmen_formatif AS id_asesmen_src, nf.nilai_angka, nf.nilai_kktp, nf.status_tuntas, nf.pertemuan_ke,
               af.judul_asesmen, af.pertemuan_ke AS asm_pertemuan
        FROM nilai_asesmen_formatif nf
        LEFT JOIN asesmen_formatif af ON nf.id_asesmen_formatif = af.id
        LEFT JOIN kelas_mata_pelajaran kmp_f ON COALESCE(af.id_kelas_mata_pelajaran, nf.id_kelas_mata_pelajaran) = kmp_f.id
        WHERE nf.id_siswa = ?
          AND (nf.id_kelas_mata_pelajaran = ? OR af.id_kelas_mata_pelajaran = ? OR (kmp_f.id_kelas = ? AND kmp_f.id_mata_pelajaran = ?) OR (? != '' AND LOWER(TRIM(af.judul_asesmen)) = ?))
        ORDER BY nf.id DESC
    ");

    $stmtSum = $pdo->prepare("
        SELECT ns.id, ns.id_asesmen_sumatif AS id_asesmen_src, ns.nilai_angka, ns.nilai_kktp, ns.status_tuntas, ns.pertemuan_ke,
               asum.judul_asesmen, asum.jenis_sumatif, asum.pertemuan_ke AS asm_pertemuan
        FROM nilai_asesmen_sumatif ns
        LEFT JOIN asesmen_sumatif asum ON ns.id_asesmen_sumatif = asum.id
        LEFT JOIN kelas_mata_pelajaran kmp_s ON COALESCE(asum.id_kelas_mata_pelajaran, ns.id_kelas_mata_pelajaran) = kmp_s.id
        WHERE ns.id_siswa = ?
          AND (ns.id_kelas_mata_pelajaran = ? OR asum.id_kelas_mata_pelajaran = ? OR (kmp_s.id_kelas = ? AND kmp_s.id_mata_pelajaran = ?) OR (? != '' AND LOWER(TRIM(asum.judul_asesmen)) = ?))
        ORDER BY ns.id DESC
    ");

    $stmtNpr = $pdo->prepare("
        SELECT id, id_pengayaan_remedial, nilai_awal, nilai_akhir, nilai_kktp, status_tuntas
        FROM nilai_pengayaan_remedial
        WHERE id_siswa = ? AND id_pengayaan_remedial = ?
        ORDER BY id DESC
    ");

    // Cek apakah asesmen sumber dengan judul $sourceJudul memang ada di database dan sudah memiliki nilai di kelas ini
    $anySourceTitleGradedInClass = false;
    if ($sourceJudulLower !== '') {
        $stmtChkSrc = $pdo->prepare("
            SELECT 1 FROM (
                SELECT ns.id_siswa FROM nilai_asesmen_sumatif ns JOIN siswa s ON ns.id_siswa = s.id JOIN asesmen_sumatif asum ON ns.id_asesmen_sumatif = asum.id WHERE s.id_kelas = ? AND LOWER(TRIM(asum.judul_asesmen)) = ?
                UNION ALL
                SELECT nf.id_siswa FROM nilai_asesmen_formatif nf JOIN siswa s ON nf.id_siswa = s.id JOIN asesmen_formatif af ON nf.id_asesmen_formatif = af.id WHERE s.id_kelas = ? AND LOWER(TRIM(af.judul_asesmen)) = ?
                UNION ALL
                SELECT nd.id_siswa FROM nilai_asesmen_diagnostik nd JOIN siswa s ON nd.id_siswa = s.id JOIN asesmen_diagnostik ad ON nd.id_asesmen_diagnostik = ad.id WHERE s.id_kelas = ? AND LOWER(TRIM(ad.judul_asesmen)) = ?
            ) t LIMIT 1
        ");
        $stmtChkSrc->execute([$idKelas, $sourceJudulLower, $idKelas, $sourceJudulLower, $idKelas, $sourceJudulLower]);
        $anySourceTitleGradedInClass = (bool)$stmtChkSrc->fetchColumn();
    }

    $pickBestScoreRow = function(array $rows, ?string $targetJudul, int $targetPertemuan, bool $preferFailed) use ($kktpAcuan, $checkIsFailed) {
        if (empty($rows)) return null;
        usort($rows, function($a, $b) use ($targetJudul, $targetPertemuan, $preferFailed, $kktpAcuan, $checkIsFailed) {
            $aTitleMatch = ($targetJudul !== null && strcasecmp(trim((string)($a['judul_asesmen'] ?? '')), $targetJudul) === 0) ? 1 : 0;
            $bTitleMatch = ($targetJudul !== null && strcasecmp(trim((string)($b['judul_asesmen'] ?? '')), $targetJudul) === 0) ? 1 : 0;
            if ($aTitleMatch !== $bTitleMatch) return $bTitleMatch <=> $aTitleMatch;

            $aFail = $checkIsFailed($a, $kktpAcuan) ? 1 : 0;
            $bFail = $checkIsFailed($b, $kktpAcuan) ? 1 : 0;
            if ($preferFailed && $aFail !== $bFail) return $bFail <=> $aFail;
            if (!$preferFailed && $aFail !== $bFail) return $aFail <=> $bFail;

            $aPert = ((int)($a['asm_pertemuan'] ?? $a['pertemuan_ke'] ?? 0) === $targetPertemuan) ? 1 : 0;
            $bPert = ((int)($b['asm_pertemuan'] ?? $b['pertemuan_ke'] ?? 0) === $targetPertemuan) ? 1 : 0;
            if ($aPert !== $bPert) return $bPert <=> $aPert;

            return ((int)$b['id']) <=> ((int)$a['id']);
        });
        return $rows[0];
    };

    $candidates = [];
    foreach ($siswaRows as $sw) {
        $sid = (int)$sw['id'];

        $stmtDiag->execute([$sid, $idKmp, $idKmp, $idKelas, $idMapel, $sourceJudulLower, $sourceJudulLower]);
        $diagRows = $stmtDiag->fetchAll(PDO::FETCH_ASSOC);

        $stmtFmt->execute([$sid, $idKmp, $idKmp, $idKelas, $idMapel, $sourceJudulLower, $sourceJudulLower]);
        $fmtRows = $stmtFmt->fetchAll(PDO::FETCH_ASSOC);

        $stmtSum->execute([$sid, $idKmp, $idKmp, $idKelas, $idMapel, $sourceJudulLower, $sourceJudulLower]);
        $sumRows = $stmtSum->fetchAll(PDO::FETCH_ASSOC);

        $stmtNpr->execute([$sid, $idPr]);
        $nprRows = $stmtNpr->fetchAll(PDO::FETCH_ASSOC);

        $allScoreRows = array_merge($diagRows, $fmtRows, $sumRows);

        $failedAny = false;
        $passedAny = false;
        $failedSourceTitle = false;
        $passedSourceTitle = false;

        foreach ($allScoreRows as $r) {
            $isF = $checkIsFailed($r, $kktpAcuan);
            $isP = $checkIsPassed($r, $kktpAcuan);
            if ($isF) $failedAny = true;
            if ($isP) $passedAny = true;

            if ($sourceJudul !== null && strcasecmp(trim((string)($r['judul_asesmen'] ?? '')), $sourceJudul) === 0) {
                if ($isF) $failedSourceTitle = true;
                if ($isP) $passedSourceTitle = true;
            }
        }

        $failedDirectNpr = false;
        $passedDirectNpr = false;
        foreach ($nprRows as $nr) {
            $nAwal = isset($nr['nilai_awal']) ? (float)$nr['nilai_awal'] : null;
            $nKktp = (!empty($nr['nilai_kktp']) && (float)$nr['nilai_kktp'] > 0) ? (float)$nr['nilai_kktp'] : $kktpAcuan;
            if ($nAwal !== null && $nAwal < $nKktp) {
                $failedDirectNpr = true;
            } elseif ($nAwal !== null && $nAwal >= $nKktp) {
                $passedDirectNpr = true;
            }
        }

        $bestDiag = $pickBestScoreRow($diagRows, $sourceJudul, $pertemuanKe, !$isPengayaan);
        $bestFmt  = $pickBestScoreRow($fmtRows, $sourceJudul, $pertemuanKe, !$isPengayaan);
        $bestSum  = $pickBestScoreRow($sumRows, $sourceJudul, $pertemuanKe, !$isPengayaan);
        $bestNpr  = $nprRows[0] ?? null;

        $candidates[] = [
            'siswa'              => $sw,
            'best_diag'          => $bestDiag,
            'best_fmt'           => $bestFmt,
            'best_sum'           => $bestSum,
            'best_npr'           => $bestNpr,
            'failed_any'         => $failedAny,
            'passed_any'         => $passedAny,
            'failed_source'      => $failedSourceTitle,
            'passed_source'      => $passedSourceTitle,
            'failed_direct_npr'  => $failedDirectNpr,
            'passed_direct_npr'  => $passedDirectNpr,
        ];
    }

    $eligibleList = [];
    foreach ($candidates as $c) {
        $isEligible = false;
        if ($isPengayaan) {
            if ($sourceJudul !== null && $anySourceTitleGradedInClass) {
                $isEligible = $c['passed_source'] || $c['passed_direct_npr'];
            } else {
                $isEligible = $c['passed_any'] || $c['passed_direct_npr'];
            }
        } else {
            // Remedial: WAJIB nilai < KKTP pada asesmen acuan (jika asesmen acuan spesifik sudah dinilai di kelas ini)
            if ($sourceJudul !== null && $anySourceTitleGradedInClass) {
                $isEligible = $c['failed_source'];
            } else {
                $isEligible = $c['failed_any'] || $c['failed_direct_npr'];
            }
        }

        if (!$isEligible) {
            continue;
        }

        $sw  = $c['siswa'];
        $bd  = $c['best_diag'];
        $bf  = $c['best_fmt'];
        $bs  = $c['best_sum'];
        $bn  = $c['best_npr'];

        $valDiag = isset($bd['nilai_angka']) ? (float)$bd['nilai_angka'] : 0.00;
        $valFmt  = isset($bf['nilai_angka']) ? (float)$bf['nilai_angka'] : 0.00;
        $valSum  = isset($bs['nilai_angka']) ? (float)$bs['nilai_angka'] : (isset($bn['nilai_awal']) ? (float)$bn['nilai_awal'] : 0.00);
        $nilaiAcuan = ($valSum > 0) ? $valSum : (($valFmt > 0) ? $valFmt : $valDiag);

        $eligibleList[] = [
            'id'                      => (int)$sw['id'],
            'id_siswa'                => (int)$sw['id'],
            'id_kelas'                => (int)$sw['id_kelas'],
            'nis'                     => $sw['nis'],
            'nisn'                    => $sw['nisn'],
            'nama_lengkap'            => $sw['nama_lengkap'],
            'id_kelas_mata_pelajaran' => $idKmp,
            'pertemuan_ke'            => $pertemuanKe,
            'id_nilai_diagnostik'     => isset($bd['id']) ? (int)$bd['id'] : null,
            'id_nilai_formatif'       => isset($bf['id']) ? (int)$bf['id'] : null,
            'id_nilai_sumatif'        => isset($bs['id']) ? (int)$bs['id'] : null,
            'nilai_diagnostik'        => $valDiag,
            'nilai_formatif'          => $valFmt,
            'nilai_sumatif'           => $valSum,
            'nilai_awal'              => $nilaiAcuan,
            'kktp_acuan'              => $kktpAcuan,
        ];
    }

    return $eligibleList;
}

/**
 * Buat akun remedial/pengayaan bagi siswa yang memenuhi syarat pada program terkait.
 * @return array ['success'=>bool,'message'=>string]
 */
function buatAksesRemedial($pdo, $idPengayaanRemedial, $idSiswa, $nomorPeserta, $username, $password, $tokenSesi = null) {
    $stmt = $pdo->prepare("
        SELECT pr.id, pr.id_kelas_mata_pelajaran, pr.pertemuan_ke, pr.kategori, kmp.id_kelas
        FROM pengayaan_remedial pr
        JOIN kelas_mata_pelajaran kmp ON pr.id_kelas_mata_pelajaran = kmp.id
        WHERE pr.id = ?
    ");
    $stmt->execute([$idPengayaanRemedial]);
    $prog = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$prog) {
        return ['success' => false, 'message' => 'Program remedial/pengayaan tidak ditemukan.'];
    }

    $eligibleRows = getEligibleRemedialStudents($pdo, (int)$idPengayaanRemedial, (int)$idSiswa);
    $k = $eligibleRows[0] ?? null;

    if (!$k) {
        return ['success' => false, 'message' => 'Siswa tidak berada di kelas program ini atau belum memenuhi syarat (nilai < KKTP) untuk mengikuti program remedial/pengayaan ini.'];
    }

    $ins = $pdo->prepare("
        INSERT INTO akses_pengayaan_remedial
            (id_pengayaan_remedial, id_siswa, id_kelas_mata_pelajaran, pertemuan_ke,
             id_nilai_diagnostik, id_nilai_formatif, id_nilai_sumatif,
             snapshot_nilai_diagnostik, snapshot_nilai_formatif, snapshot_nilai_sumatif,
             kktp_acuan, memenuhi_syarat, nomor_peserta, username_ujian, password_ujian, token_sesi)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?,?,?,?)
        ON DUPLICATE KEY UPDATE token_sesi = VALUES(token_sesi)
    ");
    $ins->execute([
        $idPengayaanRemedial,
        $idSiswa,
        $prog['id_kelas_mata_pelajaran'],
        $prog['pertemuan_ke'],
        !empty($k['id_nilai_diagnostik']) ? (int)$k['id_nilai_diagnostik'] : null,
        !empty($k['id_nilai_formatif']) ? (int)$k['id_nilai_formatif'] : null,
        !empty($k['id_nilai_sumatif']) ? (int)$k['id_nilai_sumatif'] : null,
        isset($k['nilai_diagnostik']) ? (float)$k['nilai_diagnostik'] : 0.00,
        isset($k['nilai_formatif']) ? (float)$k['nilai_formatif'] : 0.00,
        isset($k['nilai_sumatif']) ? (float)$k['nilai_sumatif'] : 0.00,
        isset($k['kktp_acuan']) ? (float)$k['kktp_acuan'] : 75.00,
        $nomorPeserta,
        $username,
        $password,
        $tokenSesi
    ]);
    return ['success' => true, 'message' => 'Akun remedial/pengayaan berhasil dibuat.'];
}

/**
 * Buat atau Daftarkan Kredensial Login Ujian Siswa
 * 
 * @param PDO $pdo
 * @param string $tipe 'formatif' atau 'sumatif'
 * @param int $idAsesmen ID asesmen_formatif atau asesmen_sumatif
 * @param int $idKmp ID kelas_mata_pelajaran
 * @param int $idSiswa ID siswa
 * @param string $nomorPeserta Nomor kartu ujian
 * @param string $username Username unik untuk login di aplikasi ujian
 * @param string $password Password / PIN akses ujian
 * @param string|null $tokenSesi Token sesi dari pengawas
 * @return int ID baris tabel akses yang dibuat / diupdate
 */
function buatAksesUjianSiswa($pdo, $tipe, $idAsesmen, $idKmp, $idSiswa, $nomorPeserta, $username, $password, $tokenSesi = null, $extra = []) {
    if ($tipe === 'sumatif') {
        $tabel = 'akses_asesmen_sumatif';
        $fkCol = 'id_asesmen_sumatif';
        $jenisSumatif = $extra['jenis_sumatif'] ?? 'Sumatif Lingkup Materi';
        $semester     = $extra['semester'] ?? 'Ganjil';
        $tahunPel     = $extra['tahun_pelajaran'] ?? '2026/2027';

        $sql = "INSERT INTO `$tabel` (
                    `$fkCol`, `id_siswa`, `id_kelas_mata_pelajaran`, `jenis_sumatif`, `semester`, `tahun_pelajaran`,
                    `nomor_peserta`, `username_ujian`, `password_ujian`, `token_sesi`, `status_akses`
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Belum Mulai')
                ON DUPLICATE KEY UPDATE 
                    `nomor_peserta` = VALUES(`nomor_peserta`),
                    `username_ujian` = VALUES(`username_ujian`),
                    `password_ujian` = VALUES(`password_ujian`),
                    `token_sesi` = VALUES(`token_sesi`),
                    `jenis_sumatif` = VALUES(`jenis_sumatif`),
                    `semester` = VALUES(`semester`),
                    `tahun_pelajaran` = VALUES(`tahun_pelajaran`)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$idAsesmen, $idSiswa, $idKmp, $jenisSumatif, $semester, $tahunPel, $nomorPeserta, $username, $password, $tokenSesi]);
    } elseif ($tipe === 'diagnostik') {
        $tabel = 'akses_asesmen_diagnostik';
        $fkCol = 'id_asesmen_diagnostik';
        $sql = "INSERT INTO `$tabel` (
                    `$fkCol`, `id_siswa`, `id_kelas_mata_pelajaran`, 
                    `nomor_peserta`, `username_ujian`, `password_ujian`, `token_sesi`, `status_akses`
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'Belum Mulai')
                ON DUPLICATE KEY UPDATE 
                    `nomor_peserta` = VALUES(`nomor_peserta`),
                    `username_ujian` = VALUES(`username_ujian`),
                    `password_ujian` = VALUES(`password_ujian`),
                    `token_sesi` = VALUES(`token_sesi`)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$idAsesmen, $idSiswa, $idKmp, $nomorPeserta, $username, $password, $tokenSesi]);
    } else {
        $tabel = 'akses_asesmen_formatif';
        $fkCol = 'id_asesmen_formatif';
        $sql = "INSERT INTO `$tabel` (
                    `$fkCol`, `id_siswa`, `id_kelas_mata_pelajaran`, 
                    `nomor_peserta`, `username_ujian`, `password_ujian`, `token_sesi`, `status_akses`
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'Belum Mulai')
                ON DUPLICATE KEY UPDATE 
                    `nomor_peserta` = VALUES(`nomor_peserta`),
                    `username_ujian` = VALUES(`username_ujian`),
                    `password_ujian` = VALUES(`password_ujian`),
                    `token_sesi` = VALUES(`token_sesi`)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$idAsesmen, $idSiswa, $idKmp, $nomorPeserta, $username, $password, $tokenSesi]);
    }
    
    return (int) ($pdo->lastInsertId() ?: $pdo->query("SELECT id FROM `$tabel` WHERE `$fkCol` = " . (int)$idAsesmen . " AND `id_siswa` = " . (int)$idSiswa)->fetchColumn());
}

/**
 * Verifikasi Kredensial Login Siswa di Aplikasi CBT / Asesmen Terpisah
 * 
 * @param PDO $pdo
 * @param string $tipe 'formatif', 'diagnostik', atau 'sumatif'
 * @param string $username
 * @param string $password
 * @param string|null $tokenSesi
 * @return array|false Data tiket ujian jika valid, false jika gagal
 */
function verifikasiAksesUjian($pdo, $tipe, $username, $password, $tokenSesi = null) {
    $judulSelect = 'asm.judul_asesmen';
    if ($tipe === 'sumatif') {
        $tabel = 'akses_asesmen_sumatif';
        $tabelAsesmen = 'asesmen_sumatif';
        $fkCol = 'id_asesmen_sumatif';
    } elseif ($tipe === 'diagnostik') {
        $tabel = 'akses_asesmen_diagnostik';
        $tabelAsesmen = 'asesmen_diagnostik';
        $fkCol = 'id_asesmen_diagnostik';
    } elseif ($tipe === 'remedial') {
        $tabel = 'akses_pengayaan_remedial';
        $tabelAsesmen = 'pengayaan_remedial';
        $fkCol = 'id_pengayaan_remedial';
        $judulSelect = 'asm.judul_program AS judul_asesmen';
    } else {
        $tabel = 'akses_asesmen_formatif';
        $tabelAsesmen = 'asesmen_formatif';
        $fkCol = 'id_asesmen_formatif';
    }

    $sql = "SELECT 
                a.*, 
                s.nisn, s.nis, s.nama_lengkap AS nama_siswa,
                $judulSelect,
                kmp.id_kelas, kmp.id_mata_pelajaran
            FROM `$tabel` a
            JOIN `siswa` s ON a.id_siswa = s.id
            JOIN `$tabelAsesmen` asm ON a.`$fkCol` = asm.id
            JOIN `kelas_mata_pelajaran` kmp ON a.id_kelas_mata_pelajaran = kmp.id
            WHERE a.username_ujian = ? AND a.password_ujian = ?
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$username, $password]);
    $akses = $stmt->fetch();

    if (!$akses) {
        return false;
    }

    // Jika asesmen mensyaratkan token sesi, verifikasi kecocokannya
    if (!empty($akses['token_sesi']) && $tokenSesi !== null && $akses['token_sesi'] !== $tokenSesi) {
        return false;
    }

    return $akses;
}

/**
 * Update Status Pengerjaan Ujian Siswa (Mulai, Berjalan, Selesai, Terkunci)
 */
function updateStatusAksesUjian($pdo, $tipe, $idAkses, $status, $sisaDetik = null, $ip = null, $userAgent = null) {
    if ($tipe === 'sumatif') {
        $tabel = 'akses_asesmen_sumatif';
    } elseif ($tipe === 'diagnostik') {
        $tabel = 'akses_asesmen_diagnostik';
    } elseif ($tipe === 'remedial') {
        $tabel = 'akses_pengayaan_remedial';
    } else {
        $tabel = 'akses_asesmen_formatif';
    }

    $fields = ["`status_akses` = ?"];
    $params = [$status];

    if ($status === 'Sedang Mengerjakan') {
        $fields[] = "`waktu_mulai` = IFNULL(`waktu_mulai`, NOW())";
    } elseif ($status === 'Selesai') {
        $fields[] = "`waktu_selesai` = NOW()";
    }

    if ($sisaDetik !== null) {
        $fields[] = "`sisa_waktu_detik` = ?";
        $params[] = $sisaDetik;
    }
    if ($ip !== null) {
        $fields[] = "`ip_address` = ?";
        $params[] = $ip;
    }
    if ($userAgent !== null) {
        $fields[] = "`user_agent` = ?";
        $params[] = $userAgent;
    }

    $params[] = $idAkses;
    $sql = "UPDATE `$tabel` SET " . implode(", ", $fields) . " WHERE `id` = ?";
    $stmt = $pdo->prepare($sql);
    return $stmt->execute($params);
}

// ==============================================================================
// 19. DASHBOARD MIGRATION & INSTALLER LANGSUNG (JIKA DIAKSES VIA PERAMBAN WEB)
// ==============================================================================
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'database.php') {
    $pdo = getDBConnection();
    $message = null;
    $error = null;

    if (($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'run_migration') || isset($_GET['auto'])) {
        try {
            $fresh = !empty($_POST['fresh']) || !empty($_GET['fresh']);
            $res = runDatabaseMigration($pdo, $fresh);
            $message = $res['message'];
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    $clusters = getDatabaseClusters();
    $tableStats = getDatabaseTables($pdo);
    $totalTables = count($tableStats);
    $officialList = getOfficialTableList();
    $totalOfficial = count($officialList);
    $viewsList = [
        'announcements', 'assignments', 'attendance_details', 'attendances', 
        'class_members', 'classes', 'kelas_mapel', 'materials', 'sekolah', 
        'submissions', 'v_absensi_asesmen_sumatif', 'v_kelayakan_remedial', 'v_monitoring_absensi_sumatif'
    ];

    $siteName = defined('APP_NAME') ? APP_NAME : 'Ilmuna Learn';
    $siteTag  = defined('APP_TAGLINE') ? APP_TAGLINE : 'Media Pembelajaran Digital Pendidikan Agama Islam';
    $baseUrl  = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
    $gununganPngUrl = $baseUrl . '/assets/img/gunungan.png';
    $logoPngUrl     = $baseUrl . '/assets/images/logo.png';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Architecture & Installer — <?= htmlspecialchars($siteName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
    <script src="https://code.iconify.design/iconify-icon/2.1.0/iconify-icon.min.js"></script>
    <style>
        :root {
            --navy: #1D3B5E;
            --navy-dark: #152C47;
            --teal: #0D7A87;
            --sky: #52B2DC;
            --green: #10B981;
            --amber: #F59E0B;
            --red: #EF4444;
            --bg: #F4F7FB;
            --card-bg: #FFFFFF;
            --border: rgba(226, 232, 240, 0.95);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }
        body {
            background-color: var(--bg);
            color: #1D3B5E;
            min-height: 100vh;
            padding: 36px 16px;
            background-image: 
                radial-gradient(at 0% 0%, rgba(13, 122, 135, 0.08) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(29, 59, 94, 0.10) 0px, transparent 50%);
        }

        .container {
            max-width: 1080px;
            margin: 0 auto;
        }

        .header-card {
            background: linear-gradient(135deg, var(--navy) 0%, #152C47 50%, var(--teal) 100%);
            border-radius: 28px;
            padding: 34px 36px;
            color: #FFF;
            box-shadow: 0 20px 45px -12px rgba(29, 59, 94, 0.35);
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
        }

        .header-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        }

        .brand-title {
            font-family: 'Outfit', sans-serif;
            font-size: 26px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand-logo {
            width: 38px;
            height: 38px;
            object-fit: contain;
            background: #FFF;
            border-radius: 12px;
            padding: 4px;
        }

        .header-desc {
            font-size: 14px;
            color: #CBD5E1;
            margin-top: 6px;
            max-width: 650px;
            line-height: 1.6;
        }

        .stat-badges {
            display: flex;
            gap: 12px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .stat-badge {
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 8px 16px;
            border-radius: 40px;
            font-size: 13px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .stat-badge.gold {
            background: rgba(245, 158, 11, 0.25);
            border-color: rgba(245, 158, 11, 0.5);
            color: #FDE68A;
        }

        .action-card {
            background: #FFFFFF;
            border: 1px solid var(--border);
            border-radius: 28px;
            padding: 28px 32px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.04);
            margin-bottom: 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        }

        .btn-migrate {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: linear-gradient(135deg, var(--teal) 0%, var(--navy) 100%);
            color: #FFFFFF;
            padding: 14px 28px;
            border-radius: 16px;
            font-weight: 700;
            font-size: 14.5px;
            border: none;
            cursor: pointer;
            box-shadow: 0 10px 24px -6px rgba(13, 122, 135, 0.45);
            transition: all 0.2s ease;
        }
        .btn-migrate:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 30px -6px rgba(13, 122, 135, 0.55);
        }

        .btn-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            background: #F1F5F9;
            color: var(--navy);
            text-decoration: none;
            border-radius: 14px;
            font-size: 13.5px;
            font-weight: 700;
            transition: all 0.2s ease;
        }
        .btn-link:hover {
            background: #E2E8F0;
        }

        .alert-box {
            padding: 16px 20px;
            border-radius: 16px;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .alert-success { background: #ECFDF5; border: 1px solid #A7F3D0; color: #065F46; }
        .alert-error { background: #FEF2F2; border: 1px solid #FECACA; color: #991B1B; }

        .cluster-box {
            background: #FFFFFF;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 20px 24px;
            margin-bottom: 16px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.02);
        }

        .cluster-title {
            font-family: 'Outfit', sans-serif;
            font-size: 16px;
            font-weight: 700;
            color: var(--navy);
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .tables-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 10px;
        }

        .table-pill {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            padding: 8px 12px;
            font-size: 12.5px;
        }

        .table-name {
            font-family: 'JetBrains Mono', monospace;
            font-weight: 600;
            color: var(--navy);
        }

        .table-count {
            background: rgba(13, 122, 135, 0.12);
            color: var(--teal);
            font-size: 11px;
            font-weight: 800;
            padding: 2px 8px;
            border-radius: 20px;
        }

        .table-count.zero {
            background: #F1F5F9;
            color: #94A3B8;
        }
    </style>
</head>
<body>

    <div class="container">
        <!-- HEADER -->
        <div class="header-card">
            <div class="header-top">
                <div class="brand-title">
                    <img src="<?= htmlspecialchars($logoPngUrl) ?>" alt="Logo" class="brand-logo" onerror="this.style.display='none'">
                    <span>Database Architecture & Migration Engine</span>
                </div>
            </div>
            <p class="header-desc">
                Portal Kurikulum Merdeka & Sistem Pembelajaran Digital Terintegrasi <?= htmlspecialchars($siteName) ?>.
                Mendukung auto-migration 91 tabel dasar lengkap, 13 views, serta ribuan data operasional akademik saat dideploy ke hosting.
            </p>
            <div class="stat-badges">
                <div class="stat-badge">
                    <iconify-icon icon="solar:database-bold" width="16"></iconify-icon>
                    <span><?= $totalOfficial ?> Tabel Resmi</span>
                </div>
                <div class="stat-badge gold">
                    <iconify-icon icon="solar:layers-bold" width="16"></iconify-icon>
                    <span>13 Views Kompatibilitas</span>
                </div>
                <div class="stat-badge">
                    <iconify-icon icon="solar:server-bold" width="16"></iconify-icon>
                    <span>Database: <?= htmlspecialchars(defined('DB_NAME') ? DB_NAME : 'uofruaju_learn_ilmuna') ?></span>
                </div>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert-box alert-success">
                <iconify-icon icon="solar:check-circle-bold" width="22"></iconify-icon>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert-box alert-error">
                <iconify-icon icon="solar:close-circle-bold" width="22"></iconify-icon>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <!-- ACTION BOX -->
        <div class="action-card">
            <div>
                <h3 style="font-size: 16px; font-weight: 800; color: var(--navy); margin-bottom: 4px;">Sinkronkan Skema & Data Hosting</h3>
                <p style="font-size: 13px; color: #64748B;">Eksekusi seluruh file skema SQL (`skema-lengkap.sql`) untuk membuat tabel baru dan mengisi data awal.</p>
            </div>
            <form method="POST" action="" style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap;">
                <input type="hidden" name="action" value="run_migration">
                <label style="font-size: 12.5px; font-weight: 600; color: #64748B; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                    <input type="checkbox" name="fresh" value="1">
                    <span>Hapus & Reset Bersih (Fresh)</span>
                </label>
                <button type="submit" class="btn-migrate">
                    <iconify-icon icon="solar:play-circle-bold" width="20"></iconify-icon>
                    <span>Jalankan Migrasi Database</span>
                </button>
                <a href="<?= htmlspecialchars($baseUrl ? ($baseUrl . '/login.php') : '/login.php') ?>" class="btn-link">
                    <iconify-icon icon="solar:login-2-bold" width="16"></iconify-icon>
                    <span>Login Portal</span>
                </a>
            </form>
        </div>

        <!-- CLUSTERS & TABLES LIST -->
        <?php foreach ($clusters as $clusterName => $tables): ?>
            <div class="cluster-box">
                <div class="cluster-title">
                    <iconify-icon icon="solar:folder-with-files-bold" width="18" style="color: var(--teal);"></iconify-icon>
                    <span><?= htmlspecialchars($clusterName) ?></span>
                </div>
                <div class="tables-grid">
                    <?php foreach ($tables as $t): ?>
                        <?php $c = $tableStats[$t] ?? null; ?>
                        <div class="table-pill">
                            <span class="table-name"><?= htmlspecialchars($t) ?></span>
                            <?php if ($c !== null && is_numeric($c)): ?>
                                <span class="table-count <?= ((int)$c === 0) ? 'zero' : '' ?>"><?= number_format((int)$c) ?> baris</span>
                            <?php else: ?>
                                <span class="table-count zero" style="background:#FEE2E2; color:#DC2626;">Belum Ada</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- VIEWS BOX -->
        <div class="cluster-box">
            <div class="cluster-title">
                <iconify-icon icon="solar:eye-bold" width="18" style="color: var(--sky);"></iconify-icon>
                <span>Views Sistem & Kompatibilitas SIAKAD (<?= count($viewsList) ?> Views)</span>
            </div>
            <div class="tables-grid">
                <?php foreach ($viewsList as $vw): ?>
                    <div class="table-pill">
                        <span class="table-name"><?= htmlspecialchars($vw) ?></span>
                        <span class="table-count" style="background: rgba(82, 178, 220, 0.15); color: #0284C7;">VIEW</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>

</body>
</html>
<?php
}

